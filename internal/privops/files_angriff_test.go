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

// Der Angriffsdurchgang des Dateimanagers.
//
// Die übrigen Tests prüfen, dass das Modul tut, was es soll. Dieser prüft, dass
// es nicht tut, was es nicht soll — und zwar gegen die Wege, die ein Angreifer
// tatsächlich versuchen würde. Jeder Fall hier war einmal eine Überlegung
// „könnte man damit …?"; die Antwort steht als Test daneben, damit sie nicht
// beim nächsten Umbau verloren geht.

// TestAngriffPfadausbruch: Alles, was hier durchkäme, wäre Lesezugriff auf
// beliebige Dateien des Servers.
func TestAngriffPfadausbruch(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)
	schreibeDatei(t, filepath.Join(wurzel, "schreibbar", "harmlos.txt"), "x")

	// Ein Ziel außerhalb, das es wirklich gibt — sonst wäre ein "gibt es nicht"
	// die Antwort und der Test würde nichts beweisen.
	draussen := filepath.Join(t.TempDir(), "beute.txt")
	schreibeDatei(t, draussen, "geheim")

	versuche := []string{
		draussen,
		filepath.Join(wurzel, "..", filepath.Base(filepath.Dir(draussen)), "beute.txt"),
		wurzel + "/schreibbar/../../" + filepath.Base(filepath.Dir(draussen)) + "/beute.txt",
		wurzel + "/./schreibbar/./../../etc/passwd",
		wurzel + "//..//..//etc//passwd",
		"/etc/passwd",
		"/etc/./passwd",
		"//etc/passwd",
		"/proc/self/environ",
		"/proc/self/root/etc/shadow",
		"/sys/kernel/uevent_helper",
		"/dev/mem",
	}
	for _, p := range versuche {
		t.Run(p, func(t *testing.T) {
			if _, _, err := fsys.Open(ctx, p); err == nil {
				t.Fatalf("%q ließ sich öffnen", p)
			}
			if _, err := fsys.List(ctx, p, ListOptions{}); err == nil {
				t.Errorf("%q ließ sich auflisten", p)
			}
			if _, err := fsys.ReadText(ctx, p, 0); err == nil {
				t.Errorf("%q ließ sich lesen", p)
			}
		})
	}
}

// TestAngriffVerweisketteAufGesperrtes: Ein Verweis, der über mehrere Stufen auf
// eine gesperrte Datei zeigt, darf keine Abkürzung sein — auch dann nicht, wenn
// Verweisen ausdrücklich gefolgt wird.
func TestAngriffVerweisketteAufGesperrtes(t *testing.T) {
	ctx := context.Background()
	var wurzel string
	fsys, w := testDateisystem(t, func(p *FilesPolicy) {
		wurzel = p.ReadableRoots[0]
		p.FollowSymlinks = true
		p.DeniedPaths = []string{filepath.Join(wurzel, "geheim", "*")}
	})
	wurzel = w

	schreibeDatei(t, filepath.Join(wurzel, "geheim", "schluessel"), "privat")
	arbeit := filepath.Join(wurzel, "schreibbar")

	// Eine Kette: a → b → c → das Geheimnis.
	if err := os.Symlink(filepath.Join(wurzel, "geheim", "schluessel"), filepath.Join(arbeit, "c")); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(filepath.Join(arbeit, "c"), filepath.Join(arbeit, "b")); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(filepath.Join(arbeit, "b"), filepath.Join(arbeit, "a")); err != nil {
		t.Fatal(err)
	}

	for _, name := range []string{"a", "b", "c"} {
		if _, _, err := fsys.Open(ctx, filepath.Join(arbeit, name)); !errors.Is(err, ErrDenied) {
			t.Errorf("%s: Fehler %v, erwartet ErrDenied", name, err)
		}
	}

	// Auch ein Verweis auf ein Verzeichnis führt nicht hinein.
	if err := os.Symlink(filepath.Join(wurzel, "geheim"), filepath.Join(arbeit, "tuer")); err != nil {
		t.Fatal(err)
	}
	if _, err := fsys.ReadText(ctx, filepath.Join(arbeit, "tuer", "schluessel"), 0); err == nil {
		t.Error("der Weg über einen Verzeichnisverweis führte in den gesperrten Bereich")
	}
}

// TestAngriffVerweisSchleife: Zwei Verweise, die aufeinander zeigen, dürfen den
// Dienst nicht in eine Endlosschleife schicken.
func TestAngriffVerweisSchleife(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, func(p *FilesPolicy) { p.FollowSymlinks = true })
	arbeit := filepath.Join(wurzel, "schreibbar")

	if err := os.Symlink(filepath.Join(arbeit, "zwei"), filepath.Join(arbeit, "eins")); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(filepath.Join(arbeit, "eins"), filepath.Join(arbeit, "zwei")); err != nil {
		t.Fatal(err)
	}

	fertig := make(chan error, 1)
	go func() {
		_, _, err := fsys.Open(ctx, filepath.Join(arbeit, "eins"))
		fertig <- err
	}()
	select {
	case err := <-fertig:
		if err == nil {
			t.Fatal("die Schleife wurde geöffnet")
		}
	case <-ctx.Done():
		t.Fatal("Zeitüberschreitung — die Auflösung läuft im Kreis")
	}
}

// TestAngriffHardlinkAufGesperrtes ist der Fall, an dem ein Mustervergleich für
// sich genommen scheitert: Ein Hardlink ist dieselbe Datei unter einem anderen
// Namen. Deshalb prüft die Wache zusätzlich Gerät und Inode.
func TestAngriffHardlinkAufGesperrtes(t *testing.T) {
	ctx := context.Background()
	var wurzel string
	fsys, w := testDateisystem(t, func(p *FilesPolicy) {
		wurzel = p.ReadableRoots[0]
		p.DeniedPaths = []string{filepath.Join(wurzel, "geheim.txt")}
	})
	wurzel = w

	geheim := filepath.Join(wurzel, "geheim.txt")
	schreibeDatei(t, geheim, "privater Schlüssel")

	// Der Umweg: derselbe Inode unter einem Namen, den kein Muster trifft.
	umweg := filepath.Join(wurzel, "schreibbar", "harmlos.txt")
	if err := os.Link(geheim, umweg); err != nil {
		t.Skipf("Hardlink nicht möglich: %v", err)
	}

	if _, _, err := fsys.Open(ctx, umweg); !errors.Is(err, ErrDenied) {
		t.Fatalf("der Hardlink ließ sich öffnen (Fehler %v) — die Sperrliste prüft nur Pfade", err)
	}
	if _, err := fsys.ReadText(ctx, umweg, 0); !errors.Is(err, ErrDenied) {
		t.Errorf("ReadText über den Hardlink: %v", err)
	}
}

// TestAngriffNamenMitSteuerzeichen: Namen, die eine Anzeige täuschen oder eine
// Zeichenkette abschneiden, werden abgewiesen — nicht bereinigt. Eine
// stillschweigende Bereinigung wäre schlimmer: Der Benutzer sieht dann einen
// anderen Namen als den, der entsteht.
func TestAngriffNamenMitSteuerzeichen(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)
	arbeit := filepath.Join(wurzel, "schreibbar")

	namen := []string{
		"harmlos\x00.txt",         // NUL beendet die Zeichenkette in jedem syscall
		"rechnung\u202egpj.exe",   // sieht in der Liste aus wie rechnung.exe.jpg
		"zwei\nzeilen",            // zerlegt Protokolle und Anzeigen
		"tab\there",               //
		"\u2066irreführend\u2069", // isolierte Schreibrichtung
		strings.Repeat("a", 256),  // über NAME_MAX
	}
	for _, name := range namen {
		if err := fsys.Mkdir(ctx, filepath.Join(arbeit, name)); err == nil {
			t.Errorf("der Name %q wurde angenommen", name)
		}
		if err := fsys.Touch(ctx, filepath.Join(arbeit, name)); err == nil {
			t.Errorf("der Name %q wurde als Datei angenommen", name)
		}
	}

	// Und es ist nichts entstanden.
	eintraege, err := os.ReadDir(arbeit)
	if err != nil {
		t.Fatal(err)
	}
	if len(eintraege) != 0 {
		for _, e := range eintraege {
			t.Errorf("entstanden: %q", e.Name())
		}
	}
}

// TestAngriffNichtRegulaeres: Was beim Öffnen blockiert oder unendlich liefert,
// wird nicht geöffnet.
func TestAngriffNichtRegulaeres(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)
	arbeit := filepath.Join(wurzel, "schreibbar")

	rohr := filepath.Join(arbeit, "rohr")
	if err := syscall.Mkfifo(rohr, 0o644); err != nil {
		t.Skipf("mkfifo nicht möglich: %v", err)
	}

	// Ohne Zeitfenster: Ein Test, der hier hängt, ist selbst der Beweis.
	fertig := make(chan error, 1)
	go func() {
		_, _, err := fsys.Open(ctx, rohr)
		fertig <- err
	}()
	select {
	case err := <-fertig:
		if !errors.Is(err, ErrNotRegular) {
			t.Errorf("Fehler %v, erwartet ErrNotRegular", err)
		}
	case <-ctx.Done():
		t.Fatal("das Öffnen der FIFO blockiert — genau das soll O_NONBLOCK verhindern")
	}
}

// TestAngriffSchreibenAusserhalb: Jede verändernde Operation gegen jeden Weg
// hinaus. Eine einzige, die durchkommt, wäre Schreibzugriff auf den ganzen
// Server.
func TestAngriffSchreibenAusserhalb(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)

	// Außerhalb der Lesewurzel und außerhalb der Schreibwurzel.
	fremd := filepath.Join(t.TempDir(), "fremd.txt")
	schreibeDatei(t, fremd, "unberührt")
	nurlesbar := filepath.Join(wurzel, "nurlesbar", "datei.txt")
	schreibeDatei(t, nurlesbar, "unberührt")

	for _, ziel := range []string{fremd, nurlesbar, "/etc/passwd", "/etc/shadow"} {
		t.Run(ziel, func(t *testing.T) {
			pruefungen := map[string]error{
				"WriteText": func() error {
					_, err := fsys.WriteText(ctx, ziel, []byte("verändert"), WriteOptions{})
					return err
				}(),
				"Remove": fsys.Remove(ctx, ziel, nil),
				"Chmod":  fsys.Chmod(ctx, ziel, 0o777, false),
				"Rename": fsys.Rename(ctx, ziel, "anders.txt"),
				"Receive": func() error {
					_, err := fsys.Receive(ctx, filepath.Dir(ziel), "hoch.txt", strings.NewReader("x"), ReceiveOptions{})
					return err
				}(),
			}
			for name, err := range pruefungen {
				if err == nil {
					t.Errorf("%s auf %s war erlaubt", name, ziel)
				}
			}
		})
	}

	// Die Inhalte sind unverändert.
	for _, p := range []string{fremd, nurlesbar} {
		roh, err := os.ReadFile(p)
		if err != nil || string(roh) != "unberührt" {
			t.Errorf("%s: %q, %v", p, roh, err)
		}
	}
}

// TestAngriffRekursivMitGesperrtemDarunter: Der Abbruch kommt vor dem ersten
// unlink, nicht nach dem ersten Treffer.
func TestAngriffRekursivMitGesperrtemDarunter(t *testing.T) {
	ctx := context.Background()
	var wurzel string
	fsys, w := testDateisystem(t, func(p *FilesPolicy) {
		wurzel = p.ReadableRoots[0]
		p.DeniedPaths = []string{filepath.Join(wurzel, "schreibbar", "baum", "tief", "*.key")}
	})
	wurzel = w
	baum := filepath.Join(wurzel, "schreibbar", "baum")

	// Der gesperrte Eintrag liegt bewusst tief und alphabetisch hinten: Ein Lauf,
	// der erst löscht und dann prüft, hätte davor schon Schaden angerichtet.
	for i, name := range []string{"a.txt", "b.txt", "c.txt"} {
		schreibeDatei(t, filepath.Join(baum, name), strings.Repeat("x", i+1))
	}
	schreibeDatei(t, filepath.Join(baum, "tief", "zzz.key"), "privat")

	if err := fsys.Remove(ctx, baum, nil); !errors.Is(err, ErrDenied) {
		t.Fatalf("Remove: %v, erwartet ErrDenied", err)
	}
	if err := fsys.Chmod(ctx, baum, 0o777, true); !errors.Is(err, ErrDenied) {
		t.Errorf("Chmod rekursiv: %v, erwartet ErrDenied", err)
	}

	// Nichts fehlt.
	for _, name := range []string{"a.txt", "b.txt", "c.txt", "tief/zzz.key"} {
		if _, err := os.Stat(filepath.Join(baum, name)); err != nil {
			t.Errorf("%s wurde trotz Ablehnung angefasst: %v", name, err)
		}
	}
	// Und die Rechte sind unverändert.
	info, err := os.Stat(filepath.Join(baum, "a.txt"))
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() == 0o777 {
		t.Error("die Rechte wurden trotz Ablehnung gesetzt")
	}
}

// TestAngriffArchivLaesstGesperrtesAus: Der Weg über das Archiv ist der
// bequemste, um einen ganzen Baum mitzunehmen — auch das Gesperrte darin.
func TestAngriffArchivLaesstGesperrtesAus(t *testing.T) {
	ctx := context.Background()
	var wurzel string
	fsys, w := testDateisystem(t, func(p *FilesPolicy) {
		wurzel = p.ReadableRoots[0]
		p.DeniedPaths = []string{filepath.Join(wurzel, "**", "*.key"), filepath.Join(wurzel, "schreibbar", "baum", "*.key")}
	})
	wurzel = w
	baum := filepath.Join(wurzel, "schreibbar", "baum")

	schreibeDatei(t, filepath.Join(baum, "harmlos.txt"), "sichtbar")
	schreibeDatei(t, filepath.Join(baum, "geheim.key"), "PRIVATER-SCHLUESSEL")

	var puffer strings.Builder
	res, err := fsys.Archive(ctx, baum, &schreiber{&puffer})
	if err != nil {
		t.Fatalf("Archive: %v", err)
	}
	if res.Skipped == 0 {
		t.Error("nichts wurde ausgelassen")
	}
	// Der Inhalt ist gzip-komprimiert; der Klartext des Geheimnisses darf darin
	// nicht auftauchen. Bei so kurzen Eingaben genügt die Suche im Rohstrom als
	// Rauchprobe — der Name steht im tar-Kopf unkomprimiert nur dann, wenn er
	// aufgenommen wurde.
	if strings.Contains(puffer.String(), "PRIVATER-SCHLUESSEL") {
		t.Error("der Klartext des Geheimnisses steht im Archiv")
	}
}

// schreiber macht aus einem strings.Builder einen io.Writer für Archive.
type schreiber struct{ b *strings.Builder }

func (s *schreiber) Write(p []byte) (int, error) { return s.b.Write(p) }

// TestAngriffUploadName: Der gemeldete Dateiname kommt vom Browser und ist
// Eingabe wie jede andere.
func TestAngriffUploadName(t *testing.T) {
	boesartig := []string{
		"../../etc/cron.d/hintertuer",
		"..\\..\\windows\\system32\\x",
		"/etc/cron.d/hintertuer",
		"....//....//etc/passwd",
		"datei\x00.txt",
		"..",
		".",
		"",
		"/",
		"nur/pfad/",
	}
	for _, name := range boesartig {
		got, err := UploadName(name)
		if err != nil {
			continue // abgewiesen, richtig
		}
		if strings.ContainsAny(got, `/\`) || got == "." || got == ".." || got == "" {
			t.Errorf("UploadName(%q) = %q — daraus wird ein Pfad", name, got)
		}
	}

	// Der harmlose Fall bleibt harmlos.
	if got, err := UploadName("bericht.pdf"); err != nil || got != "bericht.pdf" {
		t.Errorf("UploadName(bericht.pdf) = %q, %v", got, err)
	}
}

// TestAngriffGrenzenWerdenGemeldet: Eine Grenze, die stillschweigend greift, ist
// eine Falschaussage — die Oberfläche behauptet dann Vollständigkeit, die sie
// nicht hat.
func TestAngriffGrenzenWerdenGemeldet(t *testing.T) {
	ctx := context.Background()
	fsys, wurzel := testDateisystem(t, nil)
	arbeit := filepath.Join(wurzel, "schreibbar")

	for i := 0; i < 30; i++ {
		schreibeDatei(t, filepath.Join(arbeit, "d"+strings.Repeat("x", i%5)+string(rune('a'+i))+".txt"), "x")
	}

	liste, err := fsys.List(ctx, arbeit, ListOptions{Limit: 5})
	if err != nil {
		t.Fatal(err)
	}
	if !liste.Truncated || liste.Total <= len(liste.Entries) {
		t.Errorf("die Begrenzung wurde nicht gemeldet: %+v", liste)
	}

	res, err := fsys.Search(ctx, arbeit, "txt", 3)
	if err != nil {
		t.Fatal(err)
	}
	if !res.Truncated || res.Reason == "" {
		t.Errorf("die Begrenzung der Suche wurde nicht gemeldet: %+v", res)
	}
}
