package privops

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestListSortiertVerzeichnisseNachVorn(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)
	arbeit := filepath.Join(wurzel, "schreibbar")

	schreibeDatei(t, filepath.Join(arbeit, "b.txt"), "bb")
	schreibeDatei(t, filepath.Join(arbeit, "a.txt"), "a")
	schreibeDatei(t, filepath.Join(arbeit, ".versteckt"), "x")
	if err := os.Mkdir(filepath.Join(arbeit, "zzz-ordner"), 0o755); err != nil {
		t.Fatal(err)
	}

	liste, err := fsys.List(ctx, arbeit, ListOptions{})
	if err != nil {
		t.Fatalf("List: %v", err)
	}
	if len(liste.Entries) != 3 {
		t.Fatalf("%d Einträge, erwartet 3 (versteckte bleiben aus)", len(liste.Entries))
	}
	if liste.Entries[0].Name != "zzz-ordner" {
		t.Errorf("erster Eintrag %q, erwartet das Verzeichnis", liste.Entries[0].Name)
	}
	if liste.Entries[1].Name != "a.txt" || liste.Entries[2].Name != "b.txt" {
		t.Errorf("Reihenfolge der Dateien falsch: %q, %q", liste.Entries[1].Name, liste.Entries[2].Name)
	}
	if liste.Parent != wurzel {
		t.Errorf("Parent %q, erwartet %q", liste.Parent, wurzel)
	}

	// Versteckte auf Wunsch, Sortierung nach Größe absteigend.
	liste, err = fsys.List(ctx, arbeit, ListOptions{ShowHidden: true, Sort: SortSize, Desc: true})
	if err != nil {
		t.Fatalf("List: %v", err)
	}
	if len(liste.Entries) != 4 {
		t.Fatalf("%d Einträge mit versteckten, erwartet 4", len(liste.Entries))
	}
	if liste.Entries[1].Name != "b.txt" {
		t.Errorf("nach Größe absteigend steht %q vorn, erwartet b.txt", liste.Entries[1].Name)
	}
}

func TestListBegrenztUndMeldetDas(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)
	arbeit := filepath.Join(wurzel, "schreibbar")
	for i := 0; i < 20; i++ {
		schreibeDatei(t, filepath.Join(arbeit, string(rune('a'+i))+".txt"), "x")
	}

	liste, err := fsys.List(ctx, arbeit, ListOptions{Limit: 5})
	if err != nil {
		t.Fatalf("List: %v", err)
	}
	if len(liste.Entries) != 5 {
		t.Errorf("%d Einträge, erwartet 5", len(liste.Entries))
	}
	if liste.Total != 20 {
		t.Errorf("Total %d, erwartet 20", liste.Total)
	}
	if !liste.Truncated {
		t.Error("Truncated ist nicht gesetzt — die Oberfläche würde Vollständigkeit behaupten")
	}
}

func TestReadTextErhaltZeilenenden(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)

	faelle := map[string]struct {
		roh            string
		crlf           bool
		ohneAbschluss  bool
		erwarteterText string
	}{
		"unix":            {"eins\nzwei\n", false, false, "eins\nzwei\n"},
		"windows":         {"eins\r\nzwei\r\n", true, false, "eins\nzwei\n"},
		"ohne Abschluss":  {"eins\nzwei", false, true, "eins\nzwei"},
		"windows ohne Ab": {"eins\r\nzwei", true, true, "eins\nzwei"},
	}

	for name, f := range faelle {
		t.Run(name, func(t *testing.T) {
			pfad := filepath.Join(wurzel, "schreibbar", "t.conf")
			schreibeDatei(t, pfad, f.roh)

			tf, err := fsys.ReadText(ctx, pfad, 0)
			if err != nil {
				t.Fatalf("ReadText: %v", err)
			}
			if tf.Content != f.erwarteterText {
				t.Errorf("Inhalt %q, erwartet %q", tf.Content, f.erwarteterText)
			}
			if tf.CRLF != f.crlf {
				t.Errorf("CRLF %v, erwartet %v", tf.CRLF, f.crlf)
			}
			if tf.NoFinalNewline != f.ohneAbschluss {
				t.Errorf("NoFinalNewline %v, erwartet %v", tf.NoFinalNewline, f.ohneAbschluss)
			}

			// Unverändert zurückschreiben muss dieselben Bytes ergeben. Ein
			// Editor, der aus 4000 CRLF-Zeilen stillschweigend LF macht, ist in
			// einem Panel nicht tragbar.
			if _, err := fsys.WriteText(ctx, pfad, []byte(tf.Content), WriteOptions{
				ExpectHash: tf.Hash, CRLF: tf.CRLF, NoFinalNewline: tf.NoFinalNewline,
			}); err != nil {
				t.Fatalf("WriteText: %v", err)
			}
			nachher, err := os.ReadFile(pfad)
			if err != nil {
				t.Fatal(err)
			}
			if string(nachher) != f.roh {
				t.Errorf("nach dem Rückschreiben %q, erwartet %q", nachher, f.roh)
			}
		})
	}
}

func TestReadTextLehntBinaerUndZuGrossesAb(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, func(p *FilesPolicy) { p.MaxEditSize = 64 })

	binaer := filepath.Join(wurzel, "schreibbar", "bild.png")
	if err := os.WriteFile(binaer, []byte{0x89, 'P', 'N', 'G', 0x00, 0x01}, 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := fsys.ReadText(ctx, binaer, 0); err == nil {
		t.Error("eine Datei mit Null-Byte wurde als Text angenommen")
	} else if !strings.Contains(err.Error(), "Null-Byte") {
		t.Errorf("Meldung nennt die Ursache nicht: %v", err)
	}

	kaputt := filepath.Join(wurzel, "schreibbar", "latin1.conf")
	if err := os.WriteFile(kaputt, []byte{'a', 0xff, 0xfe, 'b'}, 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := fsys.ReadText(ctx, kaputt, 0); err == nil {
		t.Error("eine Datei ohne gültiges UTF-8 wurde angenommen")
	}

	gross := filepath.Join(wurzel, "schreibbar", "gross.log")
	schreibeDatei(t, gross, strings.Repeat("x", 200))
	if _, err := fsys.ReadText(ctx, gross, 0); err == nil {
		t.Error("eine zu große Datei wurde angenommen")
	} else if !strings.Contains(err.Error(), "Herunterladen") {
		t.Errorf("die Meldung nennt den Ausweg nicht: %v", err)
	}
}

func TestWriteTextErkenntFremdeAenderung(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)
	pfad := filepath.Join(wurzel, "schreibbar", "config.yaml")
	schreibeDatei(t, pfad, "a: 1\n")

	tf, err := fsys.ReadText(ctx, pfad, 0)
	if err != nil {
		t.Fatal(err)
	}

	// Jemand anders ändert die Datei, während der Editor offen ist.
	schreibeDatei(t, pfad, "a: 2\n")

	if _, err := fsys.WriteText(ctx, pfad, []byte("a: 3\n"), WriteOptions{ExpectHash: tf.Hash}); !errors.Is(err, ErrConflict) {
		t.Fatalf("Fehler %v, erwartet ErrConflict", err)
	}
	// Die fremde Änderung steht unangetastet da.
	roh, err := os.ReadFile(pfad)
	if err != nil || string(roh) != "a: 2\n" {
		t.Fatalf("die fremde Änderung wurde überschrieben: %q", roh)
	}
}

func TestWriteTextSichertUndBehaeltRechte(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)
	pfad := filepath.Join(wurzel, "schreibbar", "sshd_config")
	schreibeDatei(t, pfad, "Port 22\n")
	if err := os.Chmod(pfad, 0o600); err != nil {
		t.Fatal(err)
	}

	tf, err := fsys.ReadText(ctx, pfad, 0)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := fsys.WriteText(ctx, pfad, []byte("Port 2222\n"), WriteOptions{ExpectHash: tf.Hash}); err != nil {
		t.Fatalf("WriteText: %v", err)
	}

	info, err := os.Stat(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Errorf("Rechte nach dem Speichern %v, erwartet -rw-------", info.Mode().Perm())
	}

	// Die Sicherung enthält den Vorzustand.
	var gefunden string
	err = filepath.WalkDir(filepath.Join(wurzel, "sicherungen"), func(p string, d os.DirEntry, err error) error {
		if err == nil && !d.IsDir() && filepath.Base(p) == "sshd_config" {
			gefunden = p
		}
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	if gefunden == "" {
		t.Fatal("keine Sicherung angelegt")
	}
	roh, err := os.ReadFile(gefunden)
	if err != nil || string(roh) != "Port 22\n" {
		t.Fatalf("die Sicherung enthält %q, erwartet den Vorzustand", roh)
	}
}

// TestAtomarSchreibenLaesstKeineReste: Bricht das Schreiben ab, darf weder eine
// halbe Datei noch eine Temp-Datei zurückbleiben.
func TestAtomarSchreibenLaesstKeineReste(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)
	arbeit := filepath.Join(wurzel, "schreibbar")

	_, err := fsys.Receive(ctx, arbeit, "abbruch.bin", &fehlerLeser{}, ReceiveOptions{})
	if err == nil {
		t.Fatal("der Abbruch wurde nicht gemeldet")
	}

	eintraege, err := os.ReadDir(arbeit)
	if err != nil {
		t.Fatal(err)
	}
	for _, e := range eintraege {
		t.Errorf("zurückgeblieben: %s", e.Name())
	}
}

type fehlerLeser struct{ gelesen int }

func (f *fehlerLeser) Read(p []byte) (int, error) {
	if f.gelesen == 0 {
		f.gelesen = copy(p, []byte("erster Block"))
		return f.gelesen, nil
	}
	return 0, errors.New("die Verbindung ist weg")
}

func TestMkdirTouchRename(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)
	arbeit := filepath.Join(wurzel, "schreibbar")

	if err := fsys.Mkdir(ctx, filepath.Join(arbeit, "neu")); err != nil {
		t.Fatalf("Mkdir: %v", err)
	}
	if err := fsys.Mkdir(ctx, filepath.Join(arbeit, "neu")); err == nil {
		t.Error("ein zweites Mkdir auf denselben Namen wurde angenommen")
	}
	if err := fsys.Touch(ctx, filepath.Join(arbeit, "neu", "datei.txt")); err != nil {
		t.Fatalf("Touch: %v", err)
	}
	if err := fsys.Rename(ctx, filepath.Join(arbeit, "neu", "datei.txt"), "anders.txt"); err != nil {
		t.Fatalf("Rename: %v", err)
	}
	if _, err := os.Stat(filepath.Join(arbeit, "neu", "anders.txt")); err != nil {
		t.Errorf("nach dem Umbenennen: %v", err)
	}

	// Ein Umbenennen, das den Pfad verlässt, ist keins.
	if err := fsys.Rename(ctx, filepath.Join(arbeit, "neu", "anders.txt"), "../../raus.txt"); err == nil {
		t.Error("ein Name mit Schrägstrich wurde als Name angenommen")
	}
}

func TestCopyDateiUndBaum(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)
	arbeit := filepath.Join(wurzel, "schreibbar")

	schreibeDatei(t, filepath.Join(arbeit, "quelle", "a.txt"), "aaa")
	schreibeDatei(t, filepath.Join(arbeit, "quelle", "tief", "b.txt"), "bbb")
	if err := os.Chmod(filepath.Join(arbeit, "quelle", "a.txt"), 0o640); err != nil {
		t.Fatal(err)
	}
	if err := os.Mkdir(filepath.Join(arbeit, "ziel"), 0o755); err != nil {
		t.Fatal(err)
	}

	// Eine einzelne Datei.
	if err := fsys.Copy(ctx, filepath.Join(arbeit, "quelle", "a.txt"), filepath.Join(arbeit, "ziel"), nil); err != nil {
		t.Fatalf("Copy Datei: %v", err)
	}
	roh, err := os.ReadFile(filepath.Join(arbeit, "ziel", "a.txt"))
	if err != nil || string(roh) != "aaa" {
		t.Fatalf("Kopie: %q, %v", roh, err)
	}
	info, err := os.Stat(filepath.Join(arbeit, "ziel", "a.txt"))
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o640 {
		t.Errorf("die Kopie hat %v, erwartet die Rechte des Originals", info.Mode().Perm())
	}

	// Ein ganzer Baum, mit Fortschrittsmeldungen.
	var schritte int
	if err := fsys.Copy(ctx, filepath.Join(arbeit, "quelle"), filepath.Join(arbeit, "ziel"),
		func(Step) { schritte++ }); err != nil {
		t.Fatalf("Copy Baum: %v", err)
	}
	roh, err = os.ReadFile(filepath.Join(arbeit, "ziel", "quelle", "tief", "b.txt"))
	if err != nil || string(roh) != "bbb" {
		t.Fatalf("Kopie im Baum: %q, %v", roh, err)
	}
	if schritte == 0 {
		t.Error("keine Fortschrittsmeldung — die Oberfläche hätte nichts zu zeigen")
	}

	// In sich selbst kopieren wäre eine Endlosschleife.
	if err := fsys.Copy(ctx, filepath.Join(arbeit, "quelle"), filepath.Join(arbeit, "quelle", "tief"), nil); err == nil {
		t.Error("das Kopieren in sich selbst wurde angenommen")
	}
}

func TestMoveInAnderesVerzeichnis(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)
	arbeit := filepath.Join(wurzel, "schreibbar")

	schreibeDatei(t, filepath.Join(arbeit, "a", "datei.txt"), "inhalt")
	if err := os.MkdirAll(filepath.Join(arbeit, "b"), 0o755); err != nil {
		t.Fatal(err)
	}

	if err := fsys.Move(ctx, filepath.Join(arbeit, "a", "datei.txt"), filepath.Join(arbeit, "b"), nil); err != nil {
		t.Fatalf("Move: %v", err)
	}
	if _, err := os.Stat(filepath.Join(arbeit, "a", "datei.txt")); !os.IsNotExist(err) {
		t.Error("die Quelle liegt noch da")
	}
	roh, err := os.ReadFile(filepath.Join(arbeit, "b", "datei.txt"))
	if err != nil || string(roh) != "inhalt" {
		t.Fatalf("am Ziel: %q, %v", roh, err)
	}
}

func TestRemoveBaumUndSchutzDerWurzel(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)
	arbeit := filepath.Join(wurzel, "schreibbar")

	schreibeDatei(t, filepath.Join(arbeit, "weg", "tief", "a.txt"), "x")
	schreibeDatei(t, filepath.Join(arbeit, "weg", "b.txt"), "y")

	var schritte int
	if err := fsys.Remove(ctx, filepath.Join(arbeit, "weg"), func(Step) { schritte++ }); err != nil {
		t.Fatalf("Remove: %v", err)
	}
	if _, err := os.Stat(filepath.Join(arbeit, "weg")); !os.IsNotExist(err) {
		t.Error("das Verzeichnis liegt noch da")
	}
	if schritte == 0 {
		t.Error("keine Fortschrittsmeldung")
	}

	// Eine Schreibwurzel selbst lässt sich nicht löschen.
	if err := fsys.Remove(ctx, arbeit, nil); err == nil {
		t.Error("die Schreibwurzel wurde gelöscht")
	}
}

// TestRemoveLehntGesperrtesDarunterAb ist die Zusage, dass ein rm -rf /etc
// nicht /etc/shadow mitnimmt.
func TestRemoveLehntGesperrtesDarunterAb(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, func(p *FilesPolicy) {
		p.DeniedPaths = []string{filepath.Join(p.ReadableRoots[0], "schreibbar", "baum", "*.key")}
	})
	arbeit := filepath.Join(wurzel, "schreibbar")

	schreibeDatei(t, filepath.Join(arbeit, "baum", "harmlos.txt"), "x")
	schreibeDatei(t, filepath.Join(arbeit, "baum", "geheim.key"), "privat")

	err := fsys.Remove(ctx, filepath.Join(arbeit, "baum"), nil)
	if !errors.Is(err, ErrDenied) {
		t.Fatalf("Fehler %v, erwartet ErrDenied", err)
	}
	if _, statErr := os.Stat(filepath.Join(arbeit, "baum", "geheim.key")); statErr != nil {
		t.Error("die gesperrte Datei ist weg")
	}
	if _, statErr := os.Stat(filepath.Join(arbeit, "baum", "harmlos.txt")); statErr != nil {
		t.Error("der Abbruch kam zu spät — es wurde schon gelöscht")
	}
}

func TestChmodEinzelnUndRekursiv(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)
	arbeit := filepath.Join(wurzel, "schreibbar")

	schreibeDatei(t, filepath.Join(arbeit, "baum", "a.txt"), "x")
	schreibeDatei(t, filepath.Join(arbeit, "baum", "tief", "b.txt"), "y")

	if err := fsys.Chmod(ctx, filepath.Join(arbeit, "baum", "a.txt"), 0o600, false); err != nil {
		t.Fatalf("Chmod: %v", err)
	}
	info, err := os.Stat(filepath.Join(arbeit, "baum", "a.txt"))
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Errorf("Rechte %v, erwartet -rw-------", info.Mode().Perm())
	}

	if err := fsys.Chmod(ctx, filepath.Join(arbeit, "baum"), 0o700, true); err != nil {
		t.Fatalf("Chmod rekursiv: %v", err)
	}
	for _, p := range []string{"baum", "baum/tief", "baum/tief/b.txt", "baum/a.txt"} {
		info, err := os.Stat(filepath.Join(arbeit, p))
		if err != nil {
			t.Fatal(err)
		}
		if info.Mode().Perm() != 0o700 {
			t.Errorf("%s hat %v, erwartet 0700", p, info.Mode().Perm())
		}
	}

	// Auf einem Verweis gibt es kein chmod — es würde dem Ziel gelten.
	if err := os.Symlink(filepath.Join(arbeit, "baum", "a.txt"), filepath.Join(arbeit, "zeiger")); err != nil {
		t.Fatal(err)
	}
	if err := fsys.Chmod(ctx, filepath.Join(arbeit, "zeiger"), 0o644, false); err == nil {
		t.Error("chmod auf einen Verweis wurde angenommen")
	}
}

func TestChownSetztEigentuemer(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("chown verlangt root")
	}
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)
	pfad := filepath.Join(wurzel, "schreibbar", "datei.txt")
	schreibeDatei(t, pfad, "x")

	// root existiert auf jedem System; die Gruppe heißt je nach Distribution
	// root oder wheel.
	if err := fsys.Chown(ctx, pfad, "root", "", false); err != nil {
		t.Fatalf("Chown: %v", err)
	}
	if err := fsys.Chown(ctx, pfad, "gibtesnicht", "", false); err == nil {
		t.Error("ein unbekannter Benutzer wurde angenommen")
	}
	if err := fsys.Chown(ctx, pfad, "", "", false); err == nil {
		t.Error("ein Chown ohne Angabe wurde angenommen")
	}
}

func TestMeasureZaehltUndErkenntGesperrtes(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, func(p *FilesPolicy) {
		p.DeniedPaths = []string{filepath.Join(p.ReadableRoots[0], "schreibbar", "baum", "*.key")}
	})
	arbeit := filepath.Join(wurzel, "schreibbar")

	schreibeDatei(t, filepath.Join(arbeit, "baum", "a.txt"), "12345")
	schreibeDatei(t, filepath.Join(arbeit, "baum", "tief", "b.txt"), "123")
	schreibeDatei(t, filepath.Join(arbeit, "baum", "geheim.key"), "x")
	if err := os.Symlink("a.txt", filepath.Join(arbeit, "baum", "zeiger")); err != nil {
		t.Fatal(err)
	}

	m, err := fsys.Measure(ctx, filepath.Join(arbeit, "baum"))
	if err != nil {
		t.Fatalf("Measure: %v", err)
	}
	if m.Files != 3 {
		t.Errorf("Files %d, erwartet 3", m.Files)
	}
	if m.Dirs != 2 {
		t.Errorf("Dirs %d, erwartet 2 (das Verzeichnis selbst und tief)", m.Dirs)
	}
	if m.Symlinks != 1 {
		t.Errorf("Symlinks %d, erwartet 1", m.Symlinks)
	}
	if m.Bytes != 9 {
		t.Errorf("Bytes %d, erwartet 9", m.Bytes)
	}
	if m.Sensitive != 1 {
		t.Errorf("Sensitive %d, erwartet 1", m.Sensitive)
	}

	// Eine einzelne Datei zählt als eine Datei.
	m, err = fsys.Measure(ctx, filepath.Join(arbeit, "baum", "a.txt"))
	if err != nil {
		t.Fatal(err)
	}
	if m.Files != 1 || m.Bytes != 5 {
		t.Errorf("Measure einer Datei: %+v", m)
	}
}

func TestSearchFindetUndBegrenzt(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)
	arbeit := filepath.Join(wurzel, "schreibbar")

	schreibeDatei(t, filepath.Join(arbeit, "a", "nginx.conf"), "x")
	schreibeDatei(t, filepath.Join(arbeit, "b", "tief", "NGINX-backup.conf"), "x")
	schreibeDatei(t, filepath.Join(arbeit, "b", "andere.txt"), "x")

	res, err := fsys.Search(ctx, arbeit, "nginx", 0)
	if err != nil {
		t.Fatalf("Search: %v", err)
	}
	if len(res.Entries) != 2 {
		t.Fatalf("%d Treffer, erwartet 2 (Groß- und Kleinschreibung egal)", len(res.Entries))
	}

	res, err = fsys.Search(ctx, arbeit, "conf", 1)
	if err != nil {
		t.Fatalf("Search: %v", err)
	}
	if len(res.Entries) != 1 || !res.Truncated || res.Reason == "" {
		t.Errorf("Begrenzung nicht gemeldet: %+v", res)
	}

	if _, err := fsys.Search(ctx, arbeit, "a", 0); err == nil {
		t.Error("ein einzelnes Zeichen als Suchbegriff wurde angenommen")
	}
}

func TestArchivePacktUndLaesstAus(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, func(p *FilesPolicy) {
		p.DeniedPaths = []string{filepath.Join(p.ReadableRoots[0], "schreibbar", "baum", "*.key")}
	})
	arbeit := filepath.Join(wurzel, "schreibbar")

	schreibeDatei(t, filepath.Join(arbeit, "baum", "a.txt"), "aaa")
	schreibeDatei(t, filepath.Join(arbeit, "baum", "tief", "b.txt"), "bbb")
	schreibeDatei(t, filepath.Join(arbeit, "baum", "geheim.key"), "privat")
	if err := os.Symlink("a.txt", filepath.Join(arbeit, "baum", "zeiger")); err != nil {
		t.Fatal(err)
	}

	var puffer bytes.Buffer
	res, err := fsys.Archive(ctx, filepath.Join(arbeit, "baum"), &puffer)
	if err != nil {
		t.Fatalf("Archive: %v", err)
	}
	if res.Skipped != 1 {
		t.Errorf("Skipped %d, erwartet 1 (die gesperrte Datei)", res.Skipped)
	}
	if res.Files != 2 {
		t.Errorf("Files %d, erwartet 2", res.Files)
	}

	gz, err := gzip.NewReader(&puffer)
	if err != nil {
		t.Fatal(err)
	}
	tr := tar.NewReader(gz)
	gefunden := map[string]string{}
	for {
		kopf, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			t.Fatalf("tar lesen: %v", err)
		}
		roh, err := io.ReadAll(tr)
		if err != nil {
			t.Fatal(err)
		}
		gefunden[kopf.Name] = string(roh)
	}

	if _, ok := gefunden["baum/geheim.key"]; ok {
		t.Error("die gesperrte Datei liegt im Archiv")
	}
	if gefunden["baum/a.txt"] != "aaa" {
		t.Errorf("baum/a.txt fehlt oder ist falsch: %q", gefunden["baum/a.txt"])
	}
	if gefunden["baum/tief/b.txt"] != "bbb" {
		t.Errorf("baum/tief/b.txt fehlt: %q", gefunden["baum/tief/b.txt"])
	}
	if _, ok := gefunden["baum/"]; !ok {
		t.Error("das Wurzelverzeichnis fehlt im Archiv — beim Entpacken läge alles verstreut")
	}
	if _, ok := gefunden["baum/zeiger"]; !ok {
		t.Error("der Verweis fehlt im Archiv")
	}
}

func TestReceiveNimmtAufUndBegrenzt(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, func(p *FilesPolicy) { p.MaxUpload = 32 })
	arbeit := filepath.Join(wurzel, "schreibbar")

	eintrag, err := fsys.Receive(ctx, arbeit, "hoch.txt", strings.NewReader("kurzer Inhalt"), ReceiveOptions{})
	if err != nil {
		t.Fatalf("Receive: %v", err)
	}
	if eintrag.Size != 13 {
		t.Errorf("Größe %d, erwartet 13", eintrag.Size)
	}

	// Ein zweites Mal ohne Overwrite ist ein Fehler, mit Overwrite eine
	// Sicherung.
	if _, err := fsys.Receive(ctx, arbeit, "hoch.txt", strings.NewReader("neu"), ReceiveOptions{}); err == nil {
		t.Error("eine bestehende Datei wurde ohne Overwrite ersetzt")
	}
	if _, err := fsys.Receive(ctx, arbeit, "hoch.txt", strings.NewReader("neu"), ReceiveOptions{Overwrite: true}); err != nil {
		t.Fatalf("Receive mit Overwrite: %v", err)
	}

	// Zu groß: abgelehnt, und nichts bleibt liegen.
	_, err = fsys.Receive(ctx, arbeit, "gross.bin", strings.NewReader(strings.Repeat("x", 100)), ReceiveOptions{})
	if !errors.Is(err, ErrTooLarge) {
		t.Fatalf("Fehler %v, erwartet ErrTooLarge", err)
	}
	if _, err := os.Stat(filepath.Join(arbeit, "gross.bin")); !os.IsNotExist(err) {
		t.Error("die abgelehnte Datei liegt trotzdem da")
	}
	eintraege, err := os.ReadDir(arbeit)
	if err != nil {
		t.Fatal(err)
	}
	for _, e := range eintraege {
		if strings.Contains(e.Name(), "asylum.tmp") {
			t.Errorf("Temp-Datei zurückgeblieben: %s", e.Name())
		}
	}
}

func TestFreeSpaceUndVerify(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, func(p *FilesPolicy) {
		p.WritableRoots = append(p.WritableRoots, filepath.Join(p.ReadableRoots[0], "gibtesnicht"))
	})

	frei, err := fsys.FreeSpace(ctx, filepath.Join(wurzel, "schreibbar"))
	if err != nil {
		t.Fatalf("FreeSpace: %v", err)
	}
	if frei == 0 {
		t.Error("FreeSpace meldet 0 — auf einem Testsystem unwahrscheinlich")
	}

	status := fsys.Verify(ctx)
	if len(status) != 2 {
		t.Fatalf("%d Wurzeln geprüft, erwartet 2", len(status))
	}
	if !status[0].Writable {
		t.Errorf("%s gilt als nicht beschreibbar: %s", status[0].Path, status[0].Reason)
	}
	if status[1].Exists || status[1].Writable || status[1].Reason == "" {
		t.Errorf("eine fehlende Wurzel wurde nicht als solche gemeldet: %+v", status[1])
	}
}

func TestOwnerCandidatesLiestSystemNamen(t *testing.T) {
	withFixtures(t) // zeigt passwdPath und groupPath auf Testdateien
	fsys, _ := testDateisystem(t, nil)

	users, groups, err := fsys.OwnerCandidates(context.Background())
	if err != nil {
		t.Fatalf("OwnerCandidates: %v", err)
	}
	if !enthaelt(users, "root") || !enthaelt(users, "philipp") {
		t.Errorf("Benutzer %v, erwartet root und philipp", users)
	}
	if !enthaelt(groups, "sudo") {
		t.Errorf("Gruppen %v, erwartet sudo", groups)
	}
}

func enthaelt(liste []string, want string) bool {
	for _, v := range liste {
		if v == want {
			return true
		}
	}
	return false
}

// TestDefaultFilesPolicyPasstZurUnit hält die Vorgaben und die systemd-Härtung
// zusammen: Was hier als beschreibbar gilt, muss die Unit auch zulassen.
// /usr und /boot stehen deshalb nicht in der Liste — ProtectSystem=true hängt
// sie nur lesbar ein.
func TestDefaultFilesPolicyPasstZurUnit(t *testing.T) {
	pol := DefaultFilesPolicy("/var/lib/asylum/backups")
	if len(pol.ReadableRoots) != 1 || pol.ReadableRoots[0] != "/" {
		t.Errorf("ReadableRoots %v, erwartet [/]", pol.ReadableRoots)
	}
	for _, verboten := range []string{"/usr", "/boot", "/efi", "/proc", "/sys", "/dev"} {
		if enthaelt(pol.WritableRoots, verboten) {
			t.Errorf("%s steht in den Schreibwurzeln", verboten)
		}
	}
	for _, erwartet := range []string{"/etc", "/home", "/root", "/srv", "/var"} {
		if !enthaelt(pol.WritableRoots, erwartet) {
			t.Errorf("%s fehlt in den Schreibwurzeln", erwartet)
		}
	}
	// Die Vorgabe muss eine gültige Politik sein.
	fsys, err := NewFileSystem(pol)
	if err != nil {
		t.Fatalf("die Vorgabe ist keine gültige Politik: %v", err)
	}
	fsys.Close()
}

func TestUebersetzeNenntDenAusweg(t *testing.T) {
	// Die Meldung für EROFS ist die wichtigste des Moduls: Sie trifft jede
	// Installation, die per Selbstupdate von einer Fassung mit alter
	// systemd-Härtung kommt.
	err := uebersetze(errors.New("x"))
	if err == nil {
		t.Fatal("ein unbekannter Fehler wurde verschluckt")
	}
	if got := uebersetze(os.ErrPermission); !strings.Contains(got.Error(), "verweigert") {
		t.Errorf("EPERM: %v", got)
	}
}
