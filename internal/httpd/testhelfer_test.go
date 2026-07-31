package httpd

// Testhelfer, die mehrere Module brauchen.
//
// Sie standen bis zum Abbau der alten Oberfläche in deren Testdateien —
// newFilesServer und lege in files_test.go, metadataServer und testChannelJSON
// in update_test.go, fuelleUebersicht im Browsertest der alten Übersicht. Mit
// dem Wegfall jener Dateien wären sie mitgegangen, obwohl die Tests der neuen
// Fläche sie weiter brauchen.
//
// Hier stehen sie neutral: Jeder von ihnen richtet eine Umgebung ein oder liest
// einen Wert ab und prüft nichts. Was geprüft wird, steht in den Testdateien der
// Module.

import (
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/metrics"
	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/version"
)

// newFilesServer baut einen Server, dessen Dateimanager auf ein
// Wegwerfverzeichnis zeigt.
//
// Der echte Dateimanager zeigt auf "/". Ein Test dagegen würde entweder das
// System des Entwicklers anfassen oder von dessen Inhalt abhängen — beides
// wäre für eine Prüfung unbrauchbar.
func newFilesServer(t *testing.T) (*Server, string) {
	t.Helper()
	return newFilesServerMit(t, nil)
}

// newFilesServerMit erlaubt es, die Politik anzupassen — etwa um eine Sperre
// tiefer im Baum zu prüfen.
func newFilesServerMit(t *testing.T, anpassen func(*privops.FilesPolicy)) (*Server, string) {
	t.Helper()
	s := newTestServer(t)

	wurzel, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(wurzel, "schreibbar"), 0o755); err != nil {
		t.Fatal(err)
	}

	pol := privops.FilesPolicy{
		ReadableRoots: []string{wurzel},
		WritableRoots: []string{filepath.Join(wurzel, "schreibbar")},
		DeniedPaths:   []string{filepath.Join(wurzel, "*.geheim")},
		BackupDir:     filepath.Join(wurzel, "sicherungen"),
	}
	if anpassen != nil {
		anpassen(&pol)
	}
	fsys, err := privops.NewFileSystem(pol)
	if err != nil {
		t.Fatalf("NewFileSystem: %v", err)
	}
	t.Cleanup(fsys.Close)
	s.files = fsys
	return s, wurzel
}

func lege(t *testing.T, pfad, inhalt string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(pfad), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(pfad, []byte(inhalt), 0o644); err != nil {
		t.Fatal(err)
	}
}

// metadataServer liefert eine Kanaldatei über HTTPS.
func metadataServer(t *testing.T, body string) *httptest.Server {
	t.Helper()
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasPrefix(r.URL.Path, "/updates/") {
			http.NotFound(w, r)
			return
		}
		_, _ = w.Write([]byte(body))
	}))
	t.Cleanup(srv.Close)
	return srv
}

// updateServer verdrahtet den Server mit einem eigenen Metadatenserver.
func updateServer(t *testing.T, body string) (*Server, *fakeOps) {
	t.Helper()
	// Ein selbst gebautes Binary meldet "dev" und bekommt bewusst kein
	// Update angeboten — für den Test muss also eine echte Fassung gesetzt
	// sein. Siehe TestUpdateEntwicklungsfassung für den umgekehrten Fall.
	setVersion(t, "0.1.0")

	s, ops := newSystemServer(t)
	meta := metadataServer(t, body)
	s.cfg.Updates.BaseURL = meta.URL
	s.cfg.Updates.Channel = "stable"
	// Der Client des Testservers kennt dessen Zertifikat. So bleibt die
	// Prüfung im Produktivcode unangetastet.
	s.updHTTP = meta.Client()
	return s, ops
}

// setVersion setzt die Fassung für die Dauer eines Tests.
func setVersion(t *testing.T, v string) {
	t.Helper()
	previous := version.Version
	version.Version = v
	t.Cleanup(func() { version.Version = previous })
}

const testChannelJSON = `{
  "version": "9.9.9",
  "released_at": "2026-07-26T12:00:00Z",
  "min_upgradable_from": "0.0.1",
  "notes_url": "https://example.invalid/notes",
  "severity": "security",
  "artifacts": {
    "linux_amd64": {"url": "https://example.invalid/a.tar.gz",
      "sha256": "0000000000000000000000000000000000000000000000000000000000000000"},
    "linux_arm64": {"url": "https://example.invalid/b.tar.gz",
      "sha256": "0000000000000000000000000000000000000000000000000000000000000000"}
  }
}`

// fuelleUebersicht legt Ringpuffer und jüngste Messung so an, wie ein Server mit
// Docker und der ausgelieferten systemd-Unit sie liefert.
func fuelleUebersicht(s *Server) {
	const giB = 1 << 30
	jetzt := time.Now()

	for i := 0; i < 40; i++ {
		s.ring.Add(metrics.Snapshot{
			At:     jetzt.Add(time.Duration(i-40) * 30 * time.Minute),
			CPU:    metrics.CPU{Total: 4 + float64(i%9)},
			Memory: metrics.Memory{UsedPct: 30 + float64(i%3)},
			Load:   [3]float64{0.1 + float64(i%5)/50, 0, 0},
			Interfaces: []metrics.Interface{
				{Name: "docker0"},
				{Name: "enp1s0", Physical: true, Primary: true,
					RXRate: float64(i%7) * 1024, TXRate: float64(i%4) * 512},
			},
		})
	}

	s.setLatest(metrics.Snapshot{
		At:         jetzt,
		CPU:        metrics.CPU{Total: 6.4, IOWait: 0.4},
		Memory:     metrics.Memory{Total: 4 * giB, Used: giB, UsedPct: 25},
		Load:       [3]float64{0.18, 0.12, 0.09},
		UptimeText: "8 T 4 Std 0 Min",
		Filesystems: []metrics.Filesystem{
			{
				Mount: "/", Device: "/dev/vda3", Type: "ext4",
				AlsoAt: []string{"/etc/asylum", "/tmp", "/var/lib/asylum"},
				Total:  40 * giB, Used: 6 * giB, UsedPct: 15, InodesPct: 3.2,
			},
			{Mount: "/boot", Device: "/dev/vda2", Type: "ext3", Total: giB, Used: giB / 5, UsedPct: 21.8},
		},
		Interfaces: []metrics.Interface{
			{Name: "docker0", Addrs: []string{"172.17.0.1/16"}},
			{Name: "enp1s0", Addrs: []string{"203.0.113.10/24"}, Physical: true, Primary: true,
				RXRate: 12480, TXRate: 3620},
		},
	})
}

func letzteZeile(s string) string {
	zeilen := strings.Split(strings.TrimSpace(s), "\n")
	return zeilen[len(zeilen)-1]
}
