package update

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/privops"
)

// Der Update-Ablauf wird hier gegen echte Dateien und echte Prozesse geprüft,
// nur systemd wird ersetzt. Ein Test mit ausschließlich vorgetäuschtem
// Dateisystem hätte die entscheidenden Fragen nicht beantwortet: Liegt nach
// einem Fehlschlag wirklich wieder die alte Fassung an ihrem Platz, und ist
// sie ausführbar?

// fakeBinary ist ein Skript, das sich wie `asylumd version` verhält.
func fakeBinary(v string) []byte {
	return []byte("#!/bin/sh\necho \"Project Asylum " + v + "\"\n")
}

// systemdStub ersetzt systemctl und hält fest, was verlangt wurde.
type systemdStub struct {
	mu sync.Mutex
	// onRestart läuft bei jedem restart; damit lässt sich der Neustart eines
	// Dienstes samt Auswirkung nachstellen.
	onRestart func()
	calls     []string
	failWith  string
	cgroup    string
}

func (s *systemdStub) Run(_ context.Context, cmd privops.Command) (privops.Result, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.calls = append(s.calls, cmd.Name+" "+strings.Join(cmd.Args, " "))

	switch {
	case len(cmd.Args) > 0 && cmd.Args[0] == "show":
		return privops.Result{Stdout: s.cgroup + "\n"}, nil
	case len(cmd.Args) > 0 && cmd.Args[0] == "restart":
		if s.failWith != "" {
			return privops.Result{Stderr: s.failWith, ExitCode: 1}, nil
		}
		if s.onRestart != nil {
			s.onRestart()
		}
		return privops.Result{}, nil
	}
	return privops.Result{}, nil
}

func (s *systemdStub) restarts() int {
	s.mu.Lock()
	defer s.mu.Unlock()
	n := 0
	for _, c := range s.calls {
		if strings.Contains(c, "restart") {
			n++
		}
	}
	return n
}

// healthStub spielt /healthz und meldet die Fassung, die ihm gesagt wird.
type healthStub struct {
	mu      sync.Mutex
	version string
	down    bool
	srv     *httptest.Server
}

func newHealthStub(t *testing.T, version string) *healthStub {
	t.Helper()
	h := &healthStub{version: version}
	h.srv = httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		h.mu.Lock()
		v, down := h.version, h.down
		h.mu.Unlock()
		if down {
			http.Error(w, "nicht bereit", http.StatusServiceUnavailable)
			return
		}
		_ = json.NewEncoder(w).Encode(healthPayload{Status: "ok", Version: v})
	}))
	t.Cleanup(h.srv.Close)
	return h
}

func (h *healthStub) set(v string) {
	h.mu.Lock()
	defer h.mu.Unlock()
	h.version = v
}

// testInstaller baut eine Installation in einem temporären Verzeichnis auf.
func testInstaller(t *testing.T, installed string) (*Installer, *systemdStub, *healthStub) {
	t.Helper()

	dir := t.TempDir()
	binary := filepath.Join(dir, "asylumd")
	if err := os.WriteFile(binary, fakeBinary(installed), 0o755); err != nil {
		t.Fatalf("Binary anlegen: %v", err)
	}

	health := newHealthStub(t, installed)
	sd := &systemdStub{}
	// Ein Neustart lässt den Dienst mit der Fassung hochkommen, die gerade
	// an ihrem Platz liegt — genau wie in Wirklichkeit.
	sd.onRestart = func() {
		out, err := exec.Command(binary, "version").Output() //nolint:gosec // Pfad aus t.TempDir()
		if err != nil {
			health.set("")
			return
		}
		fields := strings.Fields(strings.TrimSpace(string(out)))
		health.set(fields[len(fields)-1])
	}

	i := &Installer{
		BinaryPath:      binary,
		Service:         DefaultService,
		HealthURL:       health.srv.URL + "/healthz",
		Runner:          sd,
		HTTP:            health.srv.Client(),
		SkipCgroupCheck: true,
		HealthTimeout:   2 * time.Second,
		HealthInterval:  20 * time.Millisecond,
		Logf:            func(format string, args ...any) { t.Logf(format, args...) },
	}
	return i, sd, health
}

func installedVersion(t *testing.T, path string) string {
	t.Helper()
	out, err := exec.Command(path, "version").Output() //nolint:gosec // Pfad aus t.TempDir()
	if err != nil {
		t.Fatalf("%s ausführen: %v", path, err)
	}
	fields := strings.Fields(strings.TrimSpace(string(out)))
	return fields[len(fields)-1]
}

func TestApplyErfolg(t *testing.T) {
	i, sd, _ := testInstaller(t, "0.1.0")

	err := i.Apply(context.Background(), Package{Version: "0.2.0", Binary: fakeBinary("0.2.0")})
	if err != nil {
		t.Fatalf("Apply: %v", err)
	}

	if got := installedVersion(t, i.BinaryPath); got != "0.2.0" {
		t.Errorf("installiert ist %s, erwartet 0.2.0", got)
	}
	if got := installedVersion(t, i.backupPath()); got != "0.1.0" {
		t.Errorf("die Sicherung hält %s, erwartet 0.1.0", got)
	}
	if sd.restarts() != 1 {
		t.Errorf("%d Neustarts, erwartet 1", sd.restarts())
	}
	// Die Zwischendatei darf nicht liegen bleiben.
	if _, err := os.Stat(i.stagedPath()); !errors.Is(err, os.ErrNotExist) {
		t.Errorf("%s liegt noch da", i.stagedPath())
	}
	// Und das Ergebnis muss ausführbar sein.
	fi, err := os.Stat(i.BinaryPath)
	if err != nil {
		t.Fatal(err)
	}
	if fi.Mode().Perm()&0o111 == 0 {
		t.Errorf("Rechte %v — das Binary ist nicht ausführbar", fi.Mode().Perm())
	}
}

func TestApplyRollbackWennDienstNichtBereit(t *testing.T) {
	i, sd, health := testInstaller(t, "0.1.0")

	// Der Dienst kommt hoch, meldet aber hartnäckig die alte Fassung: der
	// Fall, in dem systemd den neuen Prozess sofort wieder ersetzt hat oder
	// eine andere Instanz antwortet.
	sd.onRestart = func() { health.set("0.1.0") }

	err := i.Apply(context.Background(), Package{Version: "0.2.0", Binary: fakeBinary("0.2.0")})
	if err == nil {
		t.Fatal("Fehler erwartet")
	}
	if !strings.Contains(err.Error(), "vorherige Fassung läuft wieder") {
		t.Errorf("Fehlermeldung nennt das Zurückspielen nicht: %v", err)
	}
	if got := installedVersion(t, i.BinaryPath); got != "0.1.0" {
		t.Errorf("nach dem Rollback liegt %s an seinem Platz, erwartet 0.1.0", got)
	}
	if sd.restarts() != 2 {
		t.Errorf("%d Neustarts, erwartet 2 (Update und Rollback)", sd.restarts())
	}
}

func TestApplyRollbackWennNeustartScheitert(t *testing.T) {
	i, sd, _ := testInstaller(t, "0.1.0")
	sd.failWith = "Job for asylumd.service failed"

	err := i.Apply(context.Background(), Package{Version: "0.2.0", Binary: fakeBinary("0.2.0")})
	if err == nil {
		t.Fatal("Fehler erwartet")
	}
	// Der Rollback-Neustart scheitert hier ebenfalls — das muss die Meldung
	// sagen, statt einen erfolgreichen Rückweg vorzugaukeln.
	if !strings.Contains(err.Error(), "Zurückspielen scheiterte") {
		t.Errorf("Fehlermeldung: %v", err)
	}
	// Die Datei selbst ist trotzdem zurückgespielt.
	if got := installedVersion(t, i.BinaryPath); got != "0.1.0" {
		t.Errorf("an seinem Platz liegt %s, erwartet 0.1.0", got)
	}
}

func TestApplyBrichtVorDemTauschAb(t *testing.T) {
	i, sd, _ := testInstaller(t, "0.1.0")

	// Das geladene Programm nennt eine andere Fassung als die Metadaten. Das
	// darf den laufenden Stand nicht anrühren.
	err := i.Apply(context.Background(), Package{Version: "0.2.0", Binary: fakeBinary("0.3.0")})
	if err == nil {
		t.Fatal("Fehler erwartet")
	}
	if !strings.Contains(err.Error(), "meldet Fassung") {
		t.Errorf("Fehlermeldung: %v", err)
	}
	if got := installedVersion(t, i.BinaryPath); got != "0.1.0" {
		t.Errorf("das installierte Binary wurde angetastet: %s", got)
	}
	if sd.restarts() != 0 {
		t.Errorf("%d Neustarts, erwartet 0", sd.restarts())
	}
	if _, err := os.Stat(i.backupPath()); !errors.Is(err, os.ErrNotExist) {
		t.Error("es wurde eine Sicherung angelegt, obwohl nichts getauscht wurde")
	}
}

func TestApplyUnausfuehrbaresProgramm(t *testing.T) {
	i, _, _ := testInstaller(t, "0.1.0")

	// Kein Skript, kein Programm — der Fall einer beschädigten oder für eine
	// fremde Architektur gebauten Datei.
	err := i.Apply(context.Background(), Package{Version: "0.2.0", Binary: []byte("kein Programm")})
	if err == nil {
		t.Fatal("Fehler erwartet")
	}
	if got := installedVersion(t, i.BinaryPath); got != "0.1.0" {
		t.Errorf("das installierte Binary wurde angetastet: %s", got)
	}
}

func TestRollbackOhneSicherung(t *testing.T) {
	i, _, _ := testInstaller(t, "0.1.0")
	if err := i.Rollback(context.Background()); err == nil {
		t.Fatal("Fehler erwartet")
	}
}

func TestRollbackAlsEigenerSchritt(t *testing.T) {
	i, sd, _ := testInstaller(t, "0.1.0")

	if err := i.Apply(context.Background(), Package{Version: "0.2.0", Binary: fakeBinary("0.2.0")}); err != nil {
		t.Fatalf("Apply: %v", err)
	}
	if err := i.Rollback(context.Background()); err != nil {
		t.Fatalf("Rollback: %v", err)
	}
	if got := installedVersion(t, i.BinaryPath); got != "0.1.0" {
		t.Errorf("nach dem Rollback liegt %s an seinem Platz", got)
	}
	if sd.restarts() != 2 {
		t.Errorf("%d Neustarts, erwartet 2", sd.restarts())
	}
}

func TestWaitHealthyMeldetGrund(t *testing.T) {
	i, _, health := testInstaller(t, "0.1.0")
	health.mu.Lock()
	health.down = true
	health.mu.Unlock()

	err := i.waitHealthy(context.Background(), "0.2.0")
	if err == nil {
		t.Fatal("Fehler erwartet")
	}
	if !strings.Contains(err.Error(), "nicht bereit") {
		t.Errorf("Fehlermeldung nennt den Grund nicht: %v", err)
	}
}

func TestWaitHealthyOhneAdresse(t *testing.T) {
	i := &Installer{}
	if err := i.waitHealthy(context.Background(), "0.2.0"); err == nil {
		t.Fatal("Fehler erwartet")
	}
}

func TestEnsureDetached(t *testing.T) {
	own := cgroupPath(mustReadCgroup(t))
	if own == "" {
		t.Skip("cgroup v2 ist hier nicht sichtbar")
	}

	i, sd, _ := testInstaller(t, "0.1.0")
	i.SkipCgroupCheck = false

	// systemd meldet dieselbe Kontrollgruppe, in der dieser Prozess läuft:
	// Ein Neustart würde ihn mitnehmen.
	sd.cgroup = own
	err := i.Apply(context.Background(), Package{Version: "0.2.0", Binary: fakeBinary("0.2.0")})
	if !errors.Is(err, ErrSameCgroup) {
		t.Fatalf("erwartet ErrSameCgroup, bekam %v", err)
	}
	if got := installedVersion(t, i.BinaryPath); got != "0.1.0" {
		t.Errorf("es wurde trotzdem getauscht: %s", got)
	}

	// Eine andere Kontrollgruppe ist der Normalfall und muss durchlaufen.
	sd.cgroup = "/system.slice/asylumd.service"
	if err := i.Apply(context.Background(), Package{Version: "0.2.0", Binary: fakeBinary("0.2.0")}); err != nil {
		t.Fatalf("Apply: %v", err)
	}
}

func mustReadCgroup(t *testing.T) string {
	t.Helper()
	b, err := os.ReadFile("/proc/self/cgroup")
	if err != nil {
		return ""
	}
	return string(b)
}

func TestCgroupPath(t *testing.T) {
	tests := map[string]string{
		"0::/system.slice/asylumd.service\n": "/system.slice/asylumd.service",
		"12:pids:/x\n0::/user.slice\n":       "/user.slice",
		"1:name=systemd:/nur-cgroup-v1\n":    "",
		"":                                   "",
	}
	for in, want := range tests {
		if got := cgroupPath(in); got != want {
			t.Errorf("cgroupPath(%q) = %q, erwartet %q", in, got, want)
		}
	}
}

func TestHealthURLFor(t *testing.T) {
	tests := []struct {
		bind string
		port int
		want string
	}{
		{"", 8443, "https://127.0.0.1:8443/healthz"},
		{"0.0.0.0", 8443, "https://127.0.0.1:8443/healthz"},
		{"127.0.0.1", 9000, "https://127.0.0.1:9000/healthz"},
		{"::", 8443, "https://[::1]:8443/healthz"},
		{"::1", 8443, "https://[::1]:8443/healthz"},
		{"10.0.0.5", 443, "https://10.0.0.5:443/healthz"},
	}
	for _, tc := range tests {
		if got := HealthURLFor(tc.bind, tc.port); got != tc.want {
			t.Errorf("HealthURLFor(%q, %d) = %q, erwartet %q", tc.bind, tc.port, got, tc.want)
		}
	}
}

func TestCurrentBinary(t *testing.T) {
	path, err := CurrentBinary()
	if err != nil {
		t.Fatalf("CurrentBinary: %v", err)
	}
	if !filepath.IsAbs(path) {
		t.Errorf("CurrentBinary() = %q, erwartet einen absoluten Pfad", path)
	}
}

func TestFirstLine(t *testing.T) {
	tests := map[string]string{
		"eine Zeile":       "eine Zeile",
		"erste\nzweite":    "erste",
		"  mit Rand  \nxx": "mit Rand",
		"":                 "",
	}
	for in, want := range tests {
		if got := firstLine(in); got != want {
			t.Errorf("firstLine(%q) = %q, erwartet %q", in, got, want)
		}
	}
}

func TestStageSchreibtAusfuehrbar(t *testing.T) {
	i, _, _ := testInstaller(t, "0.1.0")
	if err := i.Stage([]byte("inhalt")); err != nil {
		t.Fatalf("Stage: %v", err)
	}
	fi, err := os.Stat(i.stagedPath())
	if err != nil {
		t.Fatal(err)
	}
	if fi.Mode().Perm()&0o111 == 0 {
		t.Errorf("Rechte %v", fi.Mode().Perm())
	}
	got, err := os.ReadFile(i.stagedPath())
	if err != nil {
		t.Fatal(err)
	}
	if string(got) != "inhalt" {
		t.Errorf("Inhalt = %q", got)
	}
}

func TestStageInEinUnbeschreibbaresVerzeichnis(t *testing.T) {
	if os.Geteuid() == 0 {
		t.Skip("als root greifen die Schreibrechte nicht")
	}
	dir := t.TempDir()
	if err := os.Chmod(dir, 0o500); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = os.Chmod(dir, 0o700) })

	i := &Installer{BinaryPath: filepath.Join(dir, "asylumd")}
	if err := i.Stage([]byte("x")); err == nil {
		t.Fatal("Fehler erwartet")
	}
}

func TestRestartOhneRunner(t *testing.T) {
	i := &Installer{Service: DefaultService}
	if err := i.restart(context.Background()); err == nil {
		t.Fatal("Fehler erwartet")
	}
}

func TestRestartReichtFehlerDurch(t *testing.T) {
	sd := &systemdStub{}
	i := &Installer{Service: DefaultService, Runner: runnerFunc(func(context.Context, privops.Command) (privops.Result, error) {
		return privops.Result{}, fmt.Errorf("kein systemctl vorhanden")
	})}
	if err := i.restart(context.Background()); err == nil {
		t.Fatal("Fehler erwartet")
	}
	_ = sd
}

type runnerFunc func(context.Context, privops.Command) (privops.Result, error)

func (f runnerFunc) Run(ctx context.Context, c privops.Command) (privops.Result, error) {
	return f(ctx, c)
}

// ------------------------------------------------------ Datenbanksicherung ---

// TestApplySichertUndStelltDatenbankWiederHer prüft den Teil des Rückwegs, der
// leicht vergessen wird: Migrationen laufen nur vorwärts, ein zurückgespieltes
// älteres Binary träfe sonst auf ein Schema, das es nicht kennt.
func TestApplySichertUndStelltDatenbankWiederHer(t *testing.T) {
	i, sd, health := testInstaller(t, "0.1.0")

	dir := filepath.Dir(i.BinaryPath)
	i.DBPath = filepath.Join(dir, "asylum.db")
	i.SnapshotPath = filepath.Join(dir, "backups", "vor-0.2.0.db")

	if err := os.WriteFile(i.DBPath, []byte("Schema 1"), 0o640); err != nil {
		t.Fatal(err)
	}
	// Begleitdateien des WAL-Modus: Sie müssen beim Zurückspielen weichen.
	for _, suffix := range []string{"-wal", "-shm"} {
		if err := os.WriteFile(i.DBPath+suffix, []byte("alt"), 0o640); err != nil {
			t.Fatal(err)
		}
	}
	i.Snapshot = func(_ context.Context, path string) error {
		if err := os.MkdirAll(filepath.Dir(path), 0o750); err != nil {
			return err
		}
		data, err := os.ReadFile(i.DBPath)
		if err != nil {
			return err
		}
		return os.WriteFile(path, data, 0o640)
	}

	// Der Dienst kommt nicht mit der neuen Fassung hoch — es wird
	// zurückgerollt. Migriert wird nur von der neuen Fassung; die alte findet
	// nach dem Rückweg das alte Schema vor und lässt es in Ruhe.
	sd.onRestart = func() {
		if installedVersion(t, i.BinaryPath) == "0.2.0" {
			_ = os.WriteFile(i.DBPath, []byte("Schema 2"), 0o640)
		}
		health.set("0.1.0")
	}

	err := i.Apply(context.Background(), Package{Version: "0.2.0", Binary: fakeBinary("0.2.0")})
	if err == nil {
		t.Fatal("Fehler erwartet")
	}

	if _, err := os.Stat(i.SnapshotPath); err != nil {
		t.Errorf("es wurde kein Abzug angelegt: %v", err)
	}
	got, err := os.ReadFile(i.DBPath)
	if err != nil {
		t.Fatal(err)
	}
	if string(got) != "Schema 1" {
		t.Errorf("Datenbank = %q, erwartet den Stand vor dem Update", got)
	}
	for _, suffix := range []string{"-wal", "-shm"} {
		if _, err := os.Stat(i.DBPath + suffix); !errors.Is(err, os.ErrNotExist) {
			t.Errorf("%s liegt noch da — SQLite würde es über die zurückgespielte Datei legen", suffix)
		}
	}
}

func TestApplyOhneDatenbankSicherung(t *testing.T) {
	// Ohne eingerichtete Sicherung läuft alles wie zuvor.
	i, _, _ := testInstaller(t, "0.1.0")
	if err := i.Apply(context.Background(), Package{Version: "0.2.0", Binary: fakeBinary("0.2.0")}); err != nil {
		t.Fatalf("Apply: %v", err)
	}
}

func TestSnapshotFehlerBrichtVorDemTauschAb(t *testing.T) {
	i, sd, _ := testInstaller(t, "0.1.0")
	i.DBPath = filepath.Join(filepath.Dir(i.BinaryPath), "asylum.db")
	i.SnapshotPath = filepath.Join(filepath.Dir(i.BinaryPath), "abzug.db")
	i.Snapshot = func(context.Context, string) error {
		return errors.New("kein Platz auf dem Gerät")
	}

	err := i.Apply(context.Background(), Package{Version: "0.2.0", Binary: fakeBinary("0.2.0")})
	if err == nil {
		t.Fatal("Fehler erwartet")
	}
	if !strings.Contains(err.Error(), "kein Platz") {
		t.Errorf("Fehlermeldung: %v", err)
	}
	// Kein Tausch ohne Sicherung.
	if got := installedVersion(t, i.BinaryPath); got != "0.1.0" {
		t.Errorf("es wurde trotzdem getauscht: %s", got)
	}
	if sd.restarts() != 0 {
		t.Errorf("%d Neustarts, erwartet 0", sd.restarts())
	}
}

func TestRestoreDBOhneAbzug(t *testing.T) {
	i, _, _ := testInstaller(t, "0.1.0")
	i.DBPath = filepath.Join(filepath.Dir(i.BinaryPath), "asylum.db")
	i.SnapshotPath = filepath.Join(filepath.Dir(i.BinaryPath), "gibt-es-nicht.db")
	if err := os.WriteFile(i.DBPath, []byte("unberührt"), 0o640); err != nil {
		t.Fatal(err)
	}
	// Ohne Abzug bleibt die Datenbank stehen, statt dass etwas scheitert.
	if err := i.RestoreDB(); err != nil {
		t.Fatalf("RestoreDB: %v", err)
	}
	got, _ := os.ReadFile(i.DBPath)
	if string(got) != "unberührt" {
		t.Errorf("Datenbank = %q", got)
	}
}
