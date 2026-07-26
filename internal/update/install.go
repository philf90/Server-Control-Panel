package update

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/privops"
)

// Dateinamen neben dem laufenden Binary.
const (
	stagedSuffix = ".neu"    // während des Downloads
	backupSuffix = ".vorher" // die Fassung, zu der zurückgekehrt wird
)

// Zeitgrenzen des Neustarts.
const (
	restartTimeout = 90 * time.Second
	healthTimeout  = 60 * time.Second
	healthInterval = 500 * time.Millisecond
)

// ErrSameCgroup meldet den Versuch, das Update aus dem Dienst heraus zu fahren.
var ErrSameCgroup = errors.New("das Update läuft in der Kontrollgruppe des Dienstes")

// Installer tauscht das Binary aus und bringt den Dienst wieder hoch.
//
// Der Ablauf ist bewusst so gebaut, dass zu jedem Zeitpunkt ein lauffähiges
// Binary an seinem Platz liegt: Erst wird daneben geschrieben, dann geprüft,
// dann in einem einzigen rename(2) getauscht. Ein Stromausfall mittendrin
// hinterlässt entweder die alte oder die neue Fassung, niemals eine halbe.
type Installer struct {
	// BinaryPath ist der aufgelöste Pfad des installierten Programms.
	BinaryPath string
	// Service ist die systemd-Unit.
	Service string
	// HealthURL ist der Endpunkt, an dem der Neustart gemessen wird.
	HealthURL string
	// Runner führt systemctl aus.
	Runner privops.Runner
	// Logf nimmt den Fortschritt entgegen.
	Logf func(format string, args ...any)
	// HTTP prüft die Gesundheit. Leer: ein Client für die Schleife.
	HTTP *http.Client
	// SkipCgroupCheck hebt die Schutzprüfung auf; nur für Tests.
	SkipCgroupCheck bool
	// HealthTimeout und HealthInterval steuern die Bereitschaftsprüfung.
	// Null bedeutet: die Vorgaben oben.
	HealthTimeout  time.Duration
	HealthInterval time.Duration

	// DBPath und SnapshotPath sichern die Datenbank vor dem Austausch.
	//
	// Migrationen laufen nur vorwärts. Ohne Abzug träfe die zurückgespielte
	// ältere Fassung auf ein Schema, das sie nicht kennt. Bleiben beide leer,
	// wird kein Abzug angelegt — dann tauscht der Rückweg allein das Binary.
	DBPath       string
	SnapshotPath string
	// Snapshot legt den Abzug an. Leer: os-seitiges Kopieren entfällt, der
	// Aufrufer stellt die Funktion bereit (der Daemon über store.Snapshot).
	Snapshot func(ctx context.Context, path string) error
}

func (i *Installer) healthLimits() (timeout, interval time.Duration) {
	timeout, interval = i.HealthTimeout, i.HealthInterval
	if timeout <= 0 {
		timeout = healthTimeout
	}
	if interval <= 0 {
		interval = healthInterval
	}
	return timeout, interval
}

// DefaultService ist der Unit-Name.
const DefaultService = "asylumd"

// CurrentBinary liefert den Pfad des laufenden Programms mit aufgelösten
// Symlinks. Ohne das Auflösen würde /usr/local/bin/asylum ersetzt — der
// Symlink, nicht das Programm.
func CurrentBinary() (string, error) {
	exe, err := os.Executable()
	if err != nil {
		return "", fmt.Errorf("eigenen Pfad bestimmen: %w", err)
	}
	resolved, err := filepath.EvalSymlinks(exe)
	if err != nil {
		return exe, nil //nolint:nilerr // ohne auflösbaren Symlink bleibt der Pfad brauchbar
	}
	return resolved, nil
}

func (i *Installer) logf(format string, args ...any) {
	if i.Logf != nil {
		i.Logf(format, args...)
	}
}

func (i *Installer) stagedPath() string { return i.BinaryPath + stagedSuffix }
func (i *Installer) backupPath() string { return i.BinaryPath + backupSuffix }

// Apply führt das vollständige Update durch und kehrt bei jedem Fehler nach
// dem Tausch zur vorherigen Fassung zurück.
func (i *Installer) Apply(ctx context.Context, pkg Package) error {
	if !i.SkipCgroupCheck {
		if err := i.ensureDetached(ctx); err != nil {
			return err
		}
	}

	if err := i.Stage(pkg.Binary); err != nil {
		return err
	}
	defer func() { _ = os.Remove(i.stagedPath()) }()

	i.logf("geladene Fassung wird geprüft")
	if err := probeVersion(ctx, i.stagedPath(), pkg.Version); err != nil {
		return err
	}

	// Der Abzug entsteht vor dem Austausch und nach der Prüfung: Erst hier
	// steht fest, dass überhaupt getauscht wird.
	if err := i.snapshotDB(ctx); err != nil {
		return err
	}

	i.logf("Binary wird ausgetauscht")
	if err := i.Swap(); err != nil {
		return err
	}

	i.logf("Dienst %s wird neu gestartet", i.Service)
	if err := i.restart(ctx); err != nil {
		return i.rollbackAfter(ctx, fmt.Errorf("neustart: %w", err))
	}

	i.logf("es wird auf die Bereitschaft gewartet")
	if err := i.waitHealthy(ctx, pkg.Version); err != nil {
		return i.rollbackAfter(ctx, err)
	}

	i.logf("Fassung %s läuft", pkg.Version)
	return nil
}

// Stage schreibt das neue Binary neben das alte — auf dasselbe Dateisystem,
// damit der spätere Tausch ein rename(2) sein kann und kein Kopiervorgang mit
// halbfertigem Zwischenzustand.
func (i *Installer) Stage(bin []byte) error {
	dir := filepath.Dir(i.BinaryPath)
	// 0755 statt der sonst üblichen 0600: Ein Programm, das niemand ausführen
	// darf, ist kein Programm. Es liegt im Verzeichnis des bisherigen Binaries
	// und erbt dessen Zugriffsschutz.
	f, err := os.OpenFile(i.stagedPath(), os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o755) //nolint:gosec // ausführbare Datei, siehe Kommentar
	if err != nil {
		return fmt.Errorf("%s anlegen: %w", i.stagedPath(), err)
	}
	if _, err := f.Write(bin); err != nil {
		_ = f.Close()
		_ = os.Remove(i.stagedPath())
		return fmt.Errorf("%s schreiben: %w", i.stagedPath(), err)
	}
	// Ohne fsync könnte nach einem Stromausfall eine Datei mit dem richtigen
	// Namen und leerem Inhalt zurückbleiben.
	if err := f.Sync(); err != nil {
		_ = f.Close()
		return fmt.Errorf("%s schreiben: %w", i.stagedPath(), err)
	}
	if err := f.Close(); err != nil {
		return fmt.Errorf("%s schließen: %w", i.stagedPath(), err)
	}
	// Ein umask könnte das Ausführungsrecht genommen haben.
	if err := os.Chmod(i.stagedPath(), 0o755); err != nil { //nolint:gosec // ausführbare Datei
		return fmt.Errorf("%s ausführbar machen: %w", i.stagedPath(), err)
	}
	return syncDir(dir)
}

// Swap sichert die laufende Fassung und schiebt die neue an ihren Platz.
func (i *Installer) Swap() error {
	// Der bisherige Stand wird kopiert, nicht verschoben: Zwischen Verschieben
	// und Umbenennen gäbe es einen Moment ohne Binary an seinem Platz.
	if err := copyFile(i.BinaryPath, i.backupPath()); err != nil {
		return fmt.Errorf("sicherung anlegen: %w", err)
	}
	if err := os.Rename(i.stagedPath(), i.BinaryPath); err != nil {
		return fmt.Errorf("binary austauschen: %w", err)
	}
	return syncDir(filepath.Dir(i.BinaryPath))
}

// snapshotDB sichert die Datenbank, sofern eingerichtet.
func (i *Installer) snapshotDB(ctx context.Context) error {
	if i.SnapshotPath == "" || i.Snapshot == nil {
		return nil
	}
	i.logf("Datenbank wird gesichert: %s", i.SnapshotPath)
	if err := i.Snapshot(ctx, i.SnapshotPath); err != nil {
		return fmt.Errorf("datenbank sichern: %w", err)
	}
	return nil
}

// RestoreDB spielt den Abzug von Hand zurück. Siehe restoreDB.
func (i *Installer) RestoreDB() error { return i.restoreDB() }

// restoreDB spielt den Abzug zurück.
//
// Das gehört ausschließlich in den selbsttätigen Rückweg, der Sekunden nach
// dem Austausch greift. Ein von Hand ausgelöster Rollback Tage später darf die
// Datenbank nicht anfassen — dort stünde der Verlust aller seither
// angefallenen Daten gegen ein Schemaproblem, das meist keines ist.
func (i *Installer) restoreDB() error {
	if i.DBPath == "" || i.SnapshotPath == "" {
		return nil
	}
	if _, err := os.Stat(i.SnapshotPath); err != nil {
		return nil //nolint:nilerr // ohne Abzug bleibt die Datenbank, wie sie ist
	}
	i.logf("Datenbank wird auf den Stand vor dem Update zurückgesetzt")

	// Die Begleitdateien des WAL-Modus müssen mit weg: Bleiben sie stehen,
	// legt SQLite sie über die zurückgespielte Datei und macht den Rückweg
	// damit wirkungslos.
	for _, suffix := range []string{"-wal", "-shm"} {
		if err := os.Remove(i.DBPath + suffix); err != nil && !os.IsNotExist(err) {
			return fmt.Errorf("%s%s entfernen: %w", i.DBPath, suffix, err)
		}
	}
	if err := copyData(i.SnapshotPath, i.DBPath); err != nil {
		return fmt.Errorf("datenbank zurückspielen: %w", err)
	}
	return syncDir(filepath.Dir(i.DBPath))
}

// Rollback stellt die gesicherte Fassung wieder her und startet den Dienst neu.
func (i *Installer) Rollback(ctx context.Context) error {
	backup := i.backupPath()
	if _, err := os.Stat(backup); err != nil {
		return fmt.Errorf("es liegt keine Sicherung unter %s", backup)
	}
	// Über eine Zwischendatei, damit auch hier ein rename(2) den Tausch macht.
	tmp := i.stagedPath()
	if err := copyFile(backup, tmp); err != nil {
		return err
	}
	if err := os.Rename(tmp, i.BinaryPath); err != nil {
		return fmt.Errorf("vorherige Fassung zurückspielen: %w", err)
	}
	if err := syncDir(filepath.Dir(i.BinaryPath)); err != nil {
		return err
	}
	i.logf("vorherige Fassung liegt wieder an ihrem Platz")
	return i.restart(ctx)
}

// rollbackAfter macht den Tausch rückgängig und meldet beide Fehler.
func (i *Installer) rollbackAfter(ctx context.Context, cause error) error {
	i.logf("Update fehlgeschlagen: %v", cause)
	i.logf("die vorherige Fassung wird zurückgespielt")

	// Eigener Kontext: Wenn der Aufrufer abgebrochen hat, ist der Rückweg
	// trotzdem zu Ende zu gehen. Ein abgebrochenes Rollback wäre das
	// schlechteste aller Ergebnisse.
	rbCtx, cancel := context.WithTimeout(context.WithoutCancel(ctx), restartTimeout+30*time.Second)
	defer cancel()

	// Erst die Datenbank, dann das Binary: Der Dienst startet am Ende des
	// Rollbacks und soll die alte Fassung auf dem passenden Schema vorfinden.
	if err := i.restoreDB(); err != nil {
		i.logf("Achtung: %v", err)
	}
	if err := i.Rollback(rbCtx); err != nil {
		return fmt.Errorf("%w — und das Zurückspielen scheiterte ebenfalls: %w", cause, err)
	}
	return fmt.Errorf("%w — die vorherige Fassung läuft wieder", cause)
}

// restart startet den Dienst über systemd neu.
func (i *Installer) restart(ctx context.Context) error {
	if i.Runner == nil {
		return errors.New("kein Runner gesetzt")
	}
	res, err := i.Runner.Run(ctx, privops.Command{
		Name:    "systemctl",
		Args:    []string{"restart", i.Service + ".service"},
		Timeout: restartTimeout,
	})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return fmt.Errorf("systemctl restart endete mit %d: %s",
			res.ExitCode, firstLine(res.Stderr))
	}
	return nil
}

// WaitHealthy wartet, bis der Dienst die erwartete Fassung meldet.
func (i *Installer) WaitHealthy(ctx context.Context, wantVersion string) error {
	return i.waitHealthy(ctx, wantVersion)
}

// waitHealthy fragt /healthz, bis die erwartete Fassung antwortet.
//
// Geprüft wird nicht nur, *dass* jemand antwortet, sondern *wer*: Ohne den
// Abgleich der Versionsnummer würde ein Dienst, der nach dem Fehlschlag mit
// der alten Fassung wieder hochkommt, als erfolgreiches Update durchgehen.
func (i *Installer) waitHealthy(ctx context.Context, wantVersion string) error {
	if i.HealthURL == "" {
		return errors.New("keine Adresse für die Bereitschaftsprüfung")
	}
	timeout, interval := i.healthLimits()
	deadline := time.Now().Add(timeout)
	var last error

	for {
		got, err := i.health(ctx)
		switch {
		case err != nil:
			last = err
		case got == wantVersion:
			return nil
		default:
			last = fmt.Errorf("es antwortet Fassung %q, erwartet %q", got, wantVersion)
		}

		if time.Now().After(deadline) {
			return fmt.Errorf("der Dienst meldet sich binnen %s nicht bereit: %w",
				timeout, last)
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(interval):
		}
	}
}

type healthPayload struct {
	Status  string `json:"status"`
	Version string `json:"version"`
}

func (i *Installer) health(ctx context.Context) (string, error) {
	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, i.HealthURL, nil)
	if err != nil {
		return "", err
	}
	resp, err := i.healthClient().Do(req)
	if err != nil {
		return "", err
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("healthz antwortet mit %s", resp.Status)
	}
	body, err := io.ReadAll(io.LimitReader(resp.Body, 8<<10))
	if err != nil {
		return "", err
	}
	var payload healthPayload
	if err := json.Unmarshal(body, &payload); err != nil {
		return "", fmt.Errorf("healthz liefert kein brauchbares JSON: %w", err)
	}
	if payload.Status != "ok" {
		return "", fmt.Errorf("healthz meldet %q", payload.Status)
	}
	return payload.Version, nil
}

func (i *Installer) healthClient() *http.Client {
	if i.HTTP != nil {
		return i.HTTP
	}
	return &http.Client{
		Timeout: 5 * time.Second,
		Transport: &http.Transport{
			// Das Panel hat im Regelfall ein selbstsigniertes Zertifikat.
			// Hier wird kein fremder Gegenüber authentifiziert, sondern der
			// eigene Prozess auf dem Loopback nach seiner Version gefragt —
			// und die Antwort gilt nur, wenn sie die Fassung nennt, die
			// gerade selbst auf die Platte geschrieben wurde.
			TLSClientConfig: &tls.Config{InsecureSkipVerify: true}, //nolint:gosec // siehe Kommentar
		},
	}
}

// HealthURLFor baut die Adresse für die Bereitschaftsprüfung. Bei einer
// Bindung auf alle Schnittstellen wird das Loopback verwendet — der Weg über
// eine öffentliche Adresse wäre unnötig und könnte an einer Firewallregel
// scheitern, die das Panel selbst gesetzt hat.
func HealthURLFor(bind string, port int) string {
	host := strings.Trim(bind, "[]")
	switch host {
	case "", "0.0.0.0":
		host = "127.0.0.1"
	case "::":
		host = "::1"
	}
	// JoinHostPort setzt die Klammern für IPv6 selbst.
	return "https://" + net.JoinHostPort(host, strconv.Itoa(port)) + "/healthz"
}

// ensureDetached verhindert den häufigsten Fehler beim Selbstupdate: Läuft der
// Vorgang in der Kontrollgruppe des Dienstes, beendet systemd ihn mitten im
// Neustart — genau zwischen Tausch und Bereitschaftsprüfung, also ohne jede
// Möglichkeit zum Zurückspielen.
func (i *Installer) ensureDetached(ctx context.Context) error {
	own, err := os.ReadFile("/proc/self/cgroup")
	if err != nil {
		// Ohne procfs lässt sich nichts feststellen; das ist kein Grund,
		// ein angefordertes Update zu verweigern.
		return nil //nolint:nilerr // siehe Kommentar
	}
	ownGroup := cgroupPath(string(own))
	if ownGroup == "" || i.Runner == nil {
		return nil
	}

	res, err := i.Runner.Run(ctx, privops.Command{
		Name: "systemctl",
		Args: []string{"show", i.Service + ".service", "--property=ControlGroup", "--value"},
	})
	if err != nil || res.ExitCode != 0 {
		return nil //nolint:nilerr // ohne Auskunft von systemd wird nicht blockiert
	}
	serviceGroup := strings.TrimSpace(res.Stdout)
	if serviceGroup == "" || serviceGroup != ownGroup {
		return nil
	}
	return fmt.Errorf(
		"%w (%s) — der Neustart würde diesen Vorgang mit beenden; "+
			"das Panel startet das Update deshalb über systemd-run in einer eigenen Unit",
		ErrSameCgroup, ownGroup)
}

// cgroupPath liest den Pfad aus /proc/self/cgroup (cgroup v2: "0::/pfad").
func cgroupPath(content string) string {
	for line := range strings.SplitSeq(content, "\n") {
		if after, ok := strings.CutPrefix(strings.TrimSpace(line), "0::"); ok {
			return after
		}
	}
	return ""
}

// VersionOfBinary ruft ein Programm auf und liest die Fassung aus seiner
// eigenen Auskunft. Eine falsche Architektur, ein beschädigter Download oder
// ein fehlendes Ausführungsrecht fallen hier auf.
func VersionOfBinary(ctx context.Context, path string) (string, error) {
	ctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()

	out, err := runSelf(ctx, path, "version")
	if err != nil {
		return "", fmt.Errorf("%s ließ sich nicht ausführen: %w", filepath.Base(path), err)
	}
	fields := strings.Fields(firstLine(out))
	if len(fields) == 0 {
		return "", fmt.Errorf("%s meldet keine Fassung", filepath.Base(path))
	}
	return fields[len(fields)-1], nil
}

// probeVersion prüft vor dem Tausch, dass das geladene Programm die erwartete
// Fassung ist.
func probeVersion(ctx context.Context, path, want string) error {
	got, err := VersionOfBinary(ctx, path)
	if err != nil {
		return fmt.Errorf("das geladene Programm ließ sich nicht prüfen: %w", err)
	}
	if got != want {
		return fmt.Errorf("das geladene Programm meldet Fassung %q, erwartet %q", got, want)
	}
	return nil
}

// runSelf ruft ein Programm auf, das unmittelbar zuvor selbst geschrieben
// wurde. Der Pfad stammt nicht aus einer Eingabe, sondern aus BinaryPath —
// deshalb steht dieser Aufruf hier und nicht in der Allowlist von privops, die
// feste Systemprogramme absichert.
func runSelf(ctx context.Context, path string, args ...string) (string, error) {
	cmd := exec.CommandContext(ctx, path, args...) //nolint:gosec // Pfad selbst geschrieben, keine Shell
	cmd.Env = []string{"PATH=/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL=C", "LANG=C"}
	out, err := cmd.CombinedOutput()
	return string(out), err
}

func firstLine(s string) string {
	if i := strings.IndexByte(s, '\n'); i >= 0 {
		return strings.TrimSpace(s[:i])
	}
	return strings.TrimSpace(s)
}

// copyData kopiert eine Datendatei ohne Ausführungsrecht.
func copyData(src, dst string) error { return copyWithMode(src, dst, 0o640) }

// copyFile kopiert ein Programm samt Ausführungsrecht.
func copyFile(src, dst string) error { return copyWithMode(src, dst, 0o755) }

// copyWithMode kopiert und sorgt dafür, dass die Daten auf der Platte stehen,
// bevor weitergemacht wird.
func copyWithMode(src, dst string, mode os.FileMode) error {
	// Die Pfade entstehen aus BinaryPath, nicht aus einer Anfrage.
	in, err := os.Open(src) //nolint:gosec // Pfad aus der eigenen Installation
	if err != nil {
		return err
	}
	defer func() { _ = in.Close() }()

	out, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, mode) //nolint:gosec // Rechte vom Aufrufer, siehe copyFile/copyData
	if err != nil {
		return err
	}
	if _, err := io.Copy(out, in); err != nil {
		_ = out.Close()
		return err
	}
	if err := out.Sync(); err != nil {
		_ = out.Close()
		return err
	}
	return out.Close()
}

// syncDir schreibt den Verzeichniseintrag auf die Platte. Ohne diesen Schritt
// kann ein rename(2) einen Systemabsturz nicht überleben.
func syncDir(dir string) error {
	d, err := os.Open(dir) //nolint:gosec // Verzeichnis der eigenen Installation
	if err != nil {
		return err
	}
	defer func() { _ = d.Close() }()
	if err := d.Sync(); err != nil {
		return fmt.Errorf("%s synchronisieren: %w", dir, err)
	}
	return nil
}
