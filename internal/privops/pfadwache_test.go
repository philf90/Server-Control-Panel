package privops

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
)

// testDateisystem baut einen Dateimanager über einem Testverzeichnis.
//
// Der Pfad wird durch EvalSymlinks geschickt, bevor er Wurzel wird: Auf
// manchen Systemen ist das Temp-Verzeichnis selbst ein Verweis, und dann
// stimmten die erwarteten Pfade in den Prüfungen nicht mit den aufgelösten
// überein.
func testDateisystem(t *testing.T, anpassen func(*FilesPolicy)) (*FileSystem, string) {
	t.Helper()
	basis, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	for _, d := range []string{"schreibbar", "nurlesbar"} {
		if err := os.MkdirAll(filepath.Join(basis, d), 0o755); err != nil {
			t.Fatal(err)
		}
	}

	pol := FilesPolicy{
		ReadableRoots: []string{basis},
		WritableRoots: []string{filepath.Join(basis, "schreibbar")},
		BackupDir:     filepath.Join(basis, "sicherungen"),
		MaxEditSize:   1 << 20,
		MaxUpload:     1 << 20,
	}
	if anpassen != nil {
		anpassen(&pol)
	}
	fsys, err := NewFileSystem(pol)
	if err != nil {
		t.Fatalf("NewFileSystem: %v", err)
	}
	t.Cleanup(fsys.Close)
	return fsys, basis
}

func schreibeDatei(t *testing.T, pfad, inhalt string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(pfad), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(pfad, []byte(inhalt), 0o644); err != nil {
		t.Fatal(err)
	}
}

// TestPfadwacheWeistAusbruchAb ist die wichtigste Prüfung des Moduls: Alles,
// was hier durchkäme, wäre ein Weg zu beliebigen Dateien des Servers.
func TestPfadwacheWeistAusbruchAb(t *testing.T) {
	fsys, basis := testDateisystem(t, nil)
	schreibeDatei(t, filepath.Join(basis, "schreibbar", "datei.txt"), "inhalt\n")

	// Ein Verweis, der aus der Wurzel herausführt.
	if err := os.Symlink("/etc", filepath.Join(basis, "raus")); err != nil {
		t.Fatal(err)
	}
	// Ein Verweis auf eine gesperrte Datei.
	if err := os.Symlink("/etc/shadow", filepath.Join(basis, "schatten")); err != nil {
		t.Fatal(err)
	}

	faelle := []struct {
		name  string
		pfad  string
		teil  string // Teil der erwarteten Meldung
		denie bool   // erwartet ErrDenied
	}{
		{"relativ", "etc/passwd", "kein absoluter Pfad", false},
		{"punktpunkt", basis + "/schreibbar/../../../etc/passwd", "außerhalb", true},
		{"außerhalb", "/etc/passwd", "außerhalb", true},
		{"wurzel selbst", "/", "außerhalb", true},
		{"nul-byte", basis + "/schreibbar/da\x00tei", "Steuerzeichen", false},
		{"zeilenumbruch", basis + "/schreibbar/da\ntei", "Steuerzeichen", false},
		{"schreibrichtung", basis + "/schreibbar/da\u202etei", "Steuerzeichen", false},
		{"zu lang", "/" + strings.Repeat("a", maxPfadLaenge), "zu lang", false},
		{"proc", "/proc/self/environ", "außerhalb", true},
		{"verweis nach draußen", filepath.Join(basis, "raus", "passwd"), "Verweis aus dem freigegebenen Bereich", true},
		{"leer", "", "kein Pfad", false},
	}

	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			_, err := fsys.wache.pruefen(f.pfad, zInhalt)
			if err == nil {
				t.Fatalf("%q wurde angenommen, erwartet war eine Ablehnung", f.pfad)
			}
			if !strings.Contains(err.Error(), f.teil) {
				t.Errorf("Meldung %q enthält %q nicht", err, f.teil)
			}
			if f.denie && !errors.Is(err, ErrDenied) {
				t.Errorf("Fehler ist nicht ErrDenied: %v", err)
			}
		})
	}
}

// TestSperrlisteGreiftAufBeidenFassungen prüft die eingebaute Liste gegen
// echte Pfade — ohne sie anzufassen. Die Muster sind der Kern der Zusage, dass
// eine übernommene Sitzung nicht die Geheimnisse des Servers herunterladen kann.
func TestSperrlisteGreiftAufBeidenFassungen(t *testing.T) {
	w, err := neuePfadwache(FilesPolicy{
		ReadableRoots: []string{"/"},
		DeniedPaths:   []string{"/eigenes/*.geheim"},
	})
	if err != nil {
		t.Fatal(err)
	}

	gesperrt := []string{
		"/etc/shadow",
		"/etc/shadow-",
		"/etc/gshadow",
		"/etc/ssh/ssh_host_ed25519_key",
		"/etc/ssh/ssh_host_rsa_key",
		"/etc/asylum/tls/server.key",
		"/var/lib/asylum/asylum.db",
		"/var/lib/asylum/asylum.db-wal",
		"/var/lib/asylum/asylum.db-shm",
		"/var/lib/asylum/releases",
		"/var/lib/asylum/releases/0.2.0/asylumd", // unterhalb eines gesperrten Verzeichnisses
		"/var/lib/asylum/acme/konto.key",
		"/root/.ssh/id_ed25519",
		"/home/philipp/.ssh/id_rsa",
		"/eigenes/datei.geheim",
	}
	for _, p := range gesperrt {
		if ok, grund := w.sensibel(p, p); !ok {
			t.Errorf("%s gilt als unbedenklich", p)
		} else if grund == "" {
			t.Errorf("%s ist gesperrt, aber ohne Begründung", p)
		}
	}

	frei := []string{
		"/etc/passwd",
		"/etc/ssh/sshd_config",
		"/etc/asylum/config.yaml",
		"/etc/asylum/tls/server.crt",
		"/var/lib/asylum",
		"/home/philipp/.ssh/authorized_keys",
		// Öffentliche Schlüssel sind öffentlich; eine Sperre darauf schützt
		// nichts und verwirrt nur.
		"/home/philipp/.ssh/id_rsa.pub",
		"/root/.ssh/id_ed25519.pub",
		"/eigenes/datei.txt",
	}
	for _, p := range frei {
		if ok, _ := w.sensibel(p, p); ok {
			t.Errorf("%s gilt als gesperrt", p)
		}
	}
}

func TestVerweisWirdNichtGefolgt(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)

	schreibeDatei(t, filepath.Join(wurzel, "schreibbar", "echt.txt"), "geheim\n")
	if err := os.Symlink(filepath.Join(wurzel, "schreibbar", "echt.txt"), filepath.Join(wurzel, "schreibbar", "zeiger")); err != nil {
		t.Fatal(err)
	}

	// Metadaten gibt es: Der Verweis wird angezeigt, mit Ziel.
	eintrag, err := fsys.Stat(ctx, filepath.Join(wurzel, "schreibbar", "zeiger"))
	if err != nil {
		t.Fatalf("Stat: %v", err)
	}
	if eintrag.Kind != KindSymlink {
		t.Errorf("Art %q, erwartet %q", eintrag.Kind, KindSymlink)
	}
	if eintrag.LinkTarget == "" {
		t.Error("das Ziel des Verweises fehlt in der Anzeige")
	}

	// Der Inhalt nicht.
	if _, _, err := fsys.Open(ctx, filepath.Join(wurzel, "schreibbar", "zeiger")); err == nil {
		t.Fatal("der Verweis wurde geöffnet, obwohl das Panel Verweisen nicht folgt")
	} else if !errors.Is(err, ErrDenied) {
		t.Errorf("Fehler ist nicht ErrDenied: %v", err)
	}

	// Mit eingeschaltetem Folgen schon — und dann greift die Sperrliste auch
	// auf dem Ziel.
	folgend, _ := testDateisystem(t, func(p *FilesPolicy) {
		p.ReadableRoots = []string{wurzel}
		p.WritableRoots = []string{filepath.Join(wurzel, "schreibbar")}
		p.FollowSymlinks = true
	})
	leser, _, err := folgend.Open(ctx, filepath.Join(wurzel, "schreibbar", "zeiger"))
	if err != nil {
		t.Fatalf("mit FollowSymlinks: %v", err)
	}
	_ = leser.Close()
}

// TestGesperrtesBleibtSichtbarAberUnlesbar prüft die Zusage der Oberfläche:
// Der Eintrag erscheint mit Schloss und Begründung, sein Inhalt nie.
func TestGesperrtesBleibtSichtbarAberUnlesbar(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, func(p *FilesPolicy) {
		p.DeniedPaths = []string{filepath.Join(p.ReadableRoots[0], "schreibbar", "*.geheim")}
	})

	geheim := filepath.Join(wurzel, "schreibbar", "schluessel.geheim")
	schreibeDatei(t, geheim, "privater Schlüssel\n")

	liste, err := fsys.List(ctx, filepath.Join(wurzel, "schreibbar"), ListOptions{})
	if err != nil {
		t.Fatalf("List: %v", err)
	}
	var gefunden bool
	for _, e := range liste.Entries {
		if e.Name == "schluessel.geheim" {
			gefunden = true
			if !e.Sensitive {
				t.Error("der Eintrag ist nicht als gesperrt markiert")
			}
			if e.SensitiveReason == "" {
				t.Error("die Begründung fehlt")
			}
			if e.Readable() {
				t.Error("Readable() sagt ja bei einem gesperrten Eintrag")
			}
		}
	}
	if !gefunden {
		t.Fatal("der gesperrte Eintrag fehlt in der Liste — er soll sichtbar sein, nur nicht lesbar")
	}

	// Metadaten ja, Inhalt nein, Änderung nein.
	if _, err := fsys.Stat(ctx, geheim); err != nil {
		t.Errorf("Stat auf einen gesperrten Pfad: %v", err)
	}
	for name, tun := range map[string]func() error{
		"Open": func() error { _, _, err := fsys.Open(ctx, geheim); return err },
		"ReadText": func() error {
			_, err := fsys.ReadText(ctx, geheim, 0)
			return err
		},
		"WriteText": func() error {
			_, err := fsys.WriteText(ctx, geheim, []byte("neu"), WriteOptions{})
			return err
		},
		"Remove": func() error { return fsys.Remove(ctx, geheim, nil) },
		"Chmod":  func() error { return fsys.Chmod(ctx, geheim, 0o600, false) },
	} {
		if err := tun(); !errors.Is(err, ErrDenied) {
			t.Errorf("%s: Fehler ist nicht ErrDenied: %v", name, err)
		}
	}
}

// TestSchreibwurzelWirdErzwungen: Lesen überall, Ändern nur dort, wo es erlaubt
// ist.
func TestSchreibwurzelWirdErzwungen(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)

	nurlesbar := filepath.Join(wurzel, "nurlesbar", "datei.txt")
	schreibeDatei(t, nurlesbar, "unberührt\n")

	// Lesen geht.
	if _, err := fsys.ReadText(ctx, nurlesbar, 0); err != nil {
		t.Fatalf("ReadText außerhalb der Schreibwurzel: %v", err)
	}
	// Ändern nicht.
	for name, tun := range map[string]func() error{
		"WriteText": func() error {
			_, err := fsys.WriteText(ctx, nurlesbar, []byte("verändert"), WriteOptions{})
			return err
		},
		"Remove": func() error { return fsys.Remove(ctx, nurlesbar, nil) },
		"Rename": func() error { return fsys.Rename(ctx, nurlesbar, "anders.txt") },
		"Mkdir":  func() error { return fsys.Mkdir(ctx, filepath.Join(wurzel, "nurlesbar", "neu")) },
		"Touch":  func() error { return fsys.Touch(ctx, filepath.Join(wurzel, "nurlesbar", "neu.txt")) },
	} {
		if err := tun(); !errors.Is(err, ErrDenied) {
			t.Errorf("%s: Fehler ist nicht ErrDenied: %v", name, err)
		}
	}

	// Der Inhalt ist unverändert.
	roh, err := os.ReadFile(nurlesbar)
	if err != nil || string(roh) != "unberührt\n" {
		t.Fatalf("die Datei wurde angefasst: %q, %v", roh, err)
	}
}

// TestNichtRegulaeresBleibtMetadaten: Eine FIFO wird angezeigt, aber nie
// geöffnet — ein open() darauf blockiert unbegrenzt.
func TestNichtRegulaeresBleibtMetadaten(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)

	rohr := filepath.Join(wurzel, "schreibbar", "rohr")
	if err := syscall.Mkfifo(rohr, 0o644); err != nil {
		t.Skipf("mkfifo nicht möglich: %v", err)
	}

	eintrag, err := fsys.Stat(ctx, rohr)
	if err != nil {
		t.Fatalf("Stat: %v", err)
	}
	if eintrag.Kind != KindOther {
		t.Errorf("Art %q, erwartet %q", eintrag.Kind, KindOther)
	}
	if eintrag.Readable() {
		t.Error("Readable() sagt ja bei einer FIFO")
	}

	if _, _, err := fsys.Open(ctx, rohr); !errors.Is(err, ErrNotRegular) {
		t.Errorf("Open auf eine FIFO: %v, erwartet ErrNotRegular", err)
	}
	// Löschen dagegen ist in Ordnung: Es geht um den Eintrag, nicht um Inhalt.
	if err := fsys.Remove(ctx, rohr, nil); err != nil {
		t.Errorf("Remove einer FIFO: %v", err)
	}
}

func TestNamePruefung(t *testing.T) {
	faelle := map[string]bool{
		"datei.txt":              true,
		"mit Leerzeichen.conf":   true,
		"ümläute-und-ß":          true,
		"":                       false,
		".":                      false,
		"..":                     false,
		"unter/strich":           false,
		"mit\x00nul":             false,
		"mit\nzeile":             false,
		"rechts\u202eslinks":     false,
		strings.Repeat("a", 255): true,
		strings.Repeat("a", 256): false,
	}
	for name, ok := range faelle {
		err := pruefeName(name)
		if ok && err != nil {
			t.Errorf("%q wurde abgelehnt: %v", name, err)
		}
		if !ok && err == nil {
			t.Errorf("%q wurde angenommen", name)
		}
	}
}

func TestSchreibwurzelAusserhalbLesewurzelWirdAbgelehnt(t *testing.T) {
	_, err := NewFileSystem(FilesPolicy{
		ReadableRoots: []string{"/etc"},
		WritableRoots: []string{"/home"},
	})
	if err == nil {
		t.Fatal("eine Schreibwurzel außerhalb der Lesewurzeln wurde angenommen")
	}
	if !strings.Contains(err.Error(), "readable_roots") {
		t.Errorf("die Meldung nennt die Ursache nicht: %v", err)
	}
}

func TestPseudoWurzelWirdAbgelehnt(t *testing.T) {
	for _, w := range []string{"/proc", "/sys/kernel", "/dev"} {
		if _, err := NewFileSystem(FilesPolicy{ReadableRoots: []string{w}}); err == nil {
			t.Errorf("%s wurde als Wurzel angenommen", w)
		}
	}
}

func TestParseUndFormatMode(t *testing.T) {
	faelle := map[string]string{
		"644":  "0644",
		"0644": "0644",
		"755":  "0755",
		"4755": "4755",
		"2775": "2775",
		"1777": "1777",
		"0000": "0000",
	}
	for ein, aus := range faelle {
		m, err := ParseMode(ein)
		if err != nil {
			t.Fatalf("ParseMode(%q): %v", ein, err)
		}
		if got := FormatMode(m); got != aus {
			t.Errorf("FormatMode(ParseMode(%q)) = %q, erwartet %q", ein, got, aus)
		}
	}
	for _, ein := range []string{"", "7", "77", "77777", "888", "6a4", "-644"} {
		if _, err := ParseMode(ein); err == nil {
			t.Errorf("ParseMode(%q) wurde angenommen", ein)
		}
	}
}

func TestUploadName(t *testing.T) {
	faelle := map[string]string{
		"datei.txt":                     "datei.txt",
		"/home/max/datei.txt":           "datei.txt",
		`C:\Users\Max\Desktop\bild.png`: "bild.png",
		"./unterordner/datei.txt":       "datei.txt",
	}
	for ein, aus := range faelle {
		got, err := UploadName(ein)
		if err != nil {
			t.Fatalf("UploadName(%q): %v", ein, err)
		}
		if got != aus {
			t.Errorf("UploadName(%q) = %q, erwartet %q", ein, got, aus)
		}
	}
	for _, ein := range []string{"", "..", "/", "mit\x00nul", "."} {
		if _, err := UploadName(ein); err == nil {
			t.Errorf("UploadName(%q) wurde angenommen", ein)
		}
	}
}
