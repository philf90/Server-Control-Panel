package main

import (
	"bufio"
	"context"
	"errors"
	"flag"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
	"github.com/philf90/asylum/internal/update"
	"github.com/philf90/asylum/internal/version"
)

// Das Selbstupdate läuft bewusst als eigenständiger Prozess und nicht im
// Daemon: Der Neustart beendet den Daemon, und ein Vorgang, der sich selbst
// abschießt, kann seinen eigenen Fehlschlag nicht mehr zurücknehmen. Das Panel
// stößt deshalb genau dieses Kommando über systemd-run an.

// updateLogTimeout begrenzt den gesamten Lauf.
const updateRunTimeout = 20 * time.Minute

func cmdUpdate(args []string) error {
	fs := flag.NewFlagSet("update", flag.ContinueOnError)
	cfgPath := fs.String("config", defaultConfigPath(), "Pfad zur Konfigurationsdatei")
	channel := fs.String("channel", "", "Kanal (stable|beta); Vorgabe aus der Konfiguration")
	want := fs.String("version", "", "genau diese Fassung erwarten")
	checkOnly := fs.Bool("check", false, "nur nachsehen, nichts installieren")
	assumeYes := fs.Bool("assume-yes", false, "ohne Rückfrage installieren")
	logPath := fs.String("log", "", "Ablauf zusätzlich in diese Datei schreiben")
	if err := fs.Parse(args); err != nil {
		return err
	}

	cfg, err := config.Load(*cfgPath)
	if err != nil {
		return err
	}
	ch := *channel
	if ch == "" {
		ch = cfg.Updates.Channel
	}
	if !update.ValidChannel(ch) {
		return fmt.Errorf("unbekannter Kanal %q (stable|beta)", ch)
	}

	out, closeLog, err := openLog(*logPath)
	if err != nil {
		return err
	}
	defer closeLog()
	logf := logger(out)

	ctx, cancel := context.WithTimeout(context.Background(), updateRunTimeout)
	defer cancel()

	key, err := update.ProjectKey()
	if err != nil {
		return err
	}
	client := update.NewClient()
	client.BaseURL = cfg.Updates.BaseURL

	logf("laufende Fassung: %s, Kanal %s", version.Version, ch)
	rel, err := client.Latest(ctx, ch)
	if err != nil {
		return err
	}
	logf("im Kanal steht %s (%s)", rel.Version, rel.ReleasedAt.Format("2006-01-02"))

	// --version pinnt den Lauf auf genau die Fassung, die der Auslöser gesehen
	// hat. Ohne die Prüfung könnte zwischen Anzeige im Panel und Ausführung im
	// Hintergrund eine andere veröffentlicht worden sein.
	if *want != "" && *want != rel.Version {
		return fmt.Errorf("angefordert war %s, im Kanal %s steht aber %s", *want, ch, rel.Version)
	}

	if !update.Newer(version.Version, rel.Version) {
		logf("nichts zu tun — %s ist bereits aktuell", version.Version)
		return nil
	}
	if rel.MinUpgradableFrom != "" && update.Newer(version.Version, rel.MinUpgradableFrom) {
		return fmt.Errorf(
			"ein direkter Sprung von %s auf %s wird nicht unterstützt; zuerst auf %s aktualisieren",
			version.Version, rel.Version, rel.MinUpgradableFrom)
	}

	if *checkOnly {
		_, _ = fmt.Fprintf(out, "\n  Fassung %s steht bereit.\n  Änderungen: %s\n\n", rel.Version, rel.NotesURL)
		return nil
	}
	if !*assumeYes {
		ok, err := confirm(fmt.Sprintf("Auf %s aktualisieren? Das Panel startet dabei neu [j/N]: ", rel.Version))
		if err != nil {
			return err
		}
		if !ok {
			return errors.New("abgebrochen")
		}
	}

	logf("archiv wird geladen und geprüft")
	pkg, err := client.Fetch(ctx, rel, update.Platform(), key)
	if err != nil {
		return err
	}
	logf("signatur in Ordnung: %s", pkg.TrustedComment)

	inst, err := newInstaller(cfg, logf)
	if err != nil {
		return err
	}
	closeSnap, err := attachSnapshot(inst, cfg, rel.Version)
	if err != nil {
		return err
	}
	defer closeSnap()
	if err := inst.Apply(ctx, pkg); err != nil {
		return err
	}

	logf("Update abgeschlossen: %s → %s", version.Version, pkg.Version)
	return nil
}

func cmdRollback(args []string) error {
	fs := flag.NewFlagSet("rollback", flag.ContinueOnError)
	cfgPath := fs.String("config", defaultConfigPath(), "Pfad zur Konfigurationsdatei")
	assumeYes := fs.Bool("assume-yes", false, "ohne Rückfrage zurückspielen")
	logPath := fs.String("log", "", "Ablauf zusätzlich in diese Datei schreiben")
	restoreDB := fs.Bool("restore-db", false,
		"zusätzlich den Datenbankabzug von vor dem Update einspielen (verwirft alles seither)")
	if err := fs.Parse(args); err != nil {
		return err
	}

	cfg, err := config.Load(*cfgPath)
	if err != nil {
		return err
	}
	out, closeLog, err := openLog(*logPath)
	if err != nil {
		return err
	}
	defer closeLog()
	logf := logger(out)

	inst, err := newInstaller(cfg, logf)
	if err != nil {
		return err
	}
	previous, err := update.VersionOfBinary(context.Background(), inst.BinaryPath+".vorher")
	if err != nil {
		return fmt.Errorf("es liegt keine brauchbare Sicherung bereit: %w", err)
	}

	if !*assumeYes {
		ok, err := confirm(fmt.Sprintf("Zurück auf %s? Das Panel startet dabei neu [j/N]: ", previous))
		if err != nil {
			return err
		}
		if !ok {
			return errors.New("abgebrochen")
		}
	}

	ctx, cancel := context.WithTimeout(context.Background(), updateRunTimeout)
	defer cancel()

	if *restoreDB {
		// Nur auf ausdrückliche Anweisung: Der Abzug stammt vom letzten
		// Update. Liegt das Tage zurück, wirft sein Einspielen alles weg, was
		// seither angefallen ist — Audit-Einträge, Konten, Einstellungen.
		snap := latestSnapshot(cfg.Paths.Data)
		if snap == "" {
			return errors.New("es liegt kein Datenbankabzug bereit")
		}
		logf("Datenbankabzug %s wird eingespielt", filepath.Base(snap))
		inst.DBPath = filepath.Join(cfg.Paths.Data, "asylum.db")
		inst.SnapshotPath = snap
		if err := inst.RestoreDB(); err != nil {
			return err
		}
	}

	logf("zurück auf %s", previous)
	if err := inst.Rollback(ctx); err != nil {
		return err
	}
	if err := inst.WaitHealthy(ctx, previous); err != nil {
		return err
	}
	logf("Fassung %s läuft wieder", previous)
	return nil
}

// latestSnapshot sucht den jüngsten Datenbankabzug.
func latestSnapshot(dataDir string) string {
	entries, err := os.ReadDir(filepath.Join(dataDir, "backups"))
	if err != nil {
		return ""
	}
	var newest string
	var newestTime time.Time
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".db") {
			continue
		}
		info, err := e.Info()
		if err != nil {
			continue
		}
		if newest == "" || info.ModTime().After(newestTime) {
			newest, newestTime = filepath.Join(dataDir, "backups", e.Name()), info.ModTime()
		}
	}
	return newest
}

// attachSnapshot rüstet den Installer mit der Datenbanksicherung aus.
//
// Die Datenbank wird dafür ein zweites Mal geöffnet — dieser Prozess ist nicht
// der Daemon, der sie hält. SQLite trägt das im WAL-Modus problemlos; der
// Abzug entsteht über VACUUM INTO und ist damit auch dann stimmig, wenn der
// Daemon nebenher schreibt.
func attachSnapshot(inst *update.Installer, cfg config.Config, toVersion string) (func(), error) {
	dbPath := filepath.Join(cfg.Paths.Data, "asylum.db")
	if _, err := os.Stat(dbPath); err != nil {
		// Vor der Ersteinrichtung gibt es nichts zu sichern.
		return func() {}, nil //nolint:nilerr // siehe Kommentar
	}

	db, err := store.Open(dbPath)
	if err != nil {
		return nil, fmt.Errorf("datenbank für die Sicherung öffnen: %w", err)
	}
	inst.DBPath = dbPath
	inst.SnapshotPath = filepath.Join(cfg.Paths.Data, "backups", "vor-"+toVersion+".db")
	inst.Snapshot = db.Snapshot
	return func() { _ = db.Close() }, nil
}

// logger schreibt jede Zeile mit Uhrzeit. Die Ausgabe landet gleichzeitig auf
// stdout und in der Protokolldatei; ein Schreibfehler auf einem dieser beiden
// Wege ist kein Grund, ein laufendes Update abzubrechen.
func logger(out io.Writer) func(string, ...any) {
	return func(format string, a ...any) {
		_, _ = fmt.Fprintf(out, "%s  %s\n", time.Now().Format("15:04:05"), fmt.Sprintf(format, a...))
	}
}

// newInstaller stellt den Installer aus der Konfiguration zusammen.
func newInstaller(cfg config.Config, logf func(string, ...any)) (*update.Installer, error) {
	binary, err := update.CurrentBinary()
	if err != nil {
		return nil, err
	}
	return &update.Installer{
		BinaryPath: binary,
		Service:    update.DefaultService,
		HealthURL:  update.HealthURLFor(cfg.Server.Bind, cfg.Server.Port),
		Runner:     privops.ExecRunner{},
		Logf:       logf,
	}, nil
}

// openLog liefert das Ausgabeziel. Mit --log geht alles zusätzlich in eine
// Datei — das Panel liest sie nach seinem eigenen Neustart wieder aus, denn
// die Verbindung zum Browser reißt beim Neustart naturgemäß ab.
func openLog(path string) (io.Writer, func(), error) {
	if path == "" {
		return os.Stdout, func() {}, nil
	}
	if !filepath.IsAbs(path) {
		return nil, nil, fmt.Errorf("--log braucht einen absoluten Pfad, nicht %q", path)
	}
	f, err := os.OpenFile(path, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o640) //nolint:gosec // Pfad aus der Kommandozeile des Administrators
	if err != nil {
		return nil, nil, fmt.Errorf("%s öffnen: %w", path, err)
	}
	return io.MultiWriter(os.Stdout, f), func() { _ = f.Close() }, nil
}

// confirm stellt eine Ja/Nein-Frage. Ohne Terminal gilt die Frage als
// verneint: Ein Skript, das keine Antwort geben kann, soll nichts auslösen.
func confirm(prompt string) (bool, error) {
	fmt.Print(prompt)
	line, err := bufio.NewReader(os.Stdin).ReadString('\n')
	if err != nil && line == "" {
		return false, nil //nolint:nilerr // keine Eingabe möglich = keine Zustimmung
	}
	switch strings.ToLower(strings.TrimSpace(line)) {
	case "j", "ja", "y", "yes":
		return true, nil
	}
	return false, nil
}
