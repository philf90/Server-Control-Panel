package acme

import (
	"context"
	"log/slog"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestWebrootSolverLegtDieAntwortAb(t *testing.T) {
	dir := t.TempDir()
	s := newWebrootSolver(dir)
	ctx := context.Background()

	if s.challengeType() != "http-01" {
		t.Errorf("Challenge-Typ = %q", s.challengeType())
	}
	if err := s.present(ctx, "panel.example.com", "tokenABC-123_x", "antwort.wert"); err != nil {
		t.Fatalf("present: %v", err)
	}

	// Der Pfad ist nicht frei wählbar: Er muss der Adresse entsprechen, unter
	// der die CA fragt.
	pfad := filepath.Join(dir, ".well-known", "acme-challenge", "tokenABC-123_x")
	b, err := os.ReadFile(pfad)
	if err != nil {
		t.Fatalf("die Antwort liegt nicht an der erwarteten Stelle: %v", err)
	}
	if string(b) != "antwort.wert" {
		t.Errorf("Inhalt = %q", b)
	}
	// Lesbar für den Webserver, der als www-data läuft.
	fi, err := os.Stat(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if fi.Mode().Perm()&0o044 == 0 {
		t.Errorf("die Datei ist für den Webserver nicht lesbar: %v", fi.Mode().Perm())
	}

	if err := s.cleanup(ctx, "panel.example.com", "tokenABC-123_x", "antwort.wert"); err != nil {
		t.Fatalf("cleanup: %v", err)
	}
	if _, err := os.Stat(pfad); !os.IsNotExist(err) {
		t.Error("die Antwort liegt nach dem Aufräumen noch da")
	}
}

// cleanup läuft auch auf dem Weg heraus aus einem gescheiterten Bezug, und dann
// kann present nie gelaufen sein. Ein fehlender Eintrag ist deshalb kein Fehler.
func TestWebrootSolverAufraeumenOhneVorherigesAblegen(t *testing.T) {
	s := newWebrootSolver(t.TempDir())
	if err := s.cleanup(context.Background(), "panel.example.com", "token", "wert"); err != nil {
		t.Errorf("ein fehlender Eintrag ist kein Fehler: %v", err)
	}
}

// Die sicherheitskritische Prüfung. Der Token kommt vom ACME-Server, also von
// außen, und er wird hier zu einem Dateinamen. Das Panel läuft als root.
func TestWebrootSolverLehntGebasteltenTokenAb(t *testing.T) {
	dir := t.TempDir()
	// Ein Ziel außerhalb des Verzeichnisses, das nachweislich unberührt bleibt.
	daneben := filepath.Join(dir, "..", "beute.conf")

	for _, token := range []string{
		"../../etc/nginx/conf.d/boes.conf",
		"..%2f..%2fetc",
		"../beute.conf",
		"unter/verzeichnis",
		"mit punkt.txt",
		".",
		"..",
		"",
		"token\x00abbruch",
		strings.Repeat("a", maxTokenLaenge+1),
	} {
		t.Run(token, func(t *testing.T) {
			s := newWebrootSolver(dir)
			ctx := context.Background()

			if err := s.present(ctx, "panel.example.com", token, "wert"); err == nil {
				t.Fatalf("der Token %q wurde angenommen", token)
			}
			// Und dieselbe Grenze auf dem Rückweg: Ein cleanup, das den Pfad
			// nicht prüft, wäre ein Löschen an beliebiger Stelle.
			if err := s.cleanup(ctx, "panel.example.com", token, "wert"); err == nil {
				t.Errorf("cleanup nahm den Token %q an", token)
			}
			if _, err := os.Stat(daneben); !os.IsNotExist(err) {
				t.Errorf("außerhalb des Verzeichnisses wurde geschrieben: %s", daneben)
			}
		})
	}
}

// Die Wahl trifft der Zustand, nicht der Betreiber: Ist ein Webroot gelegt,
// lauscht das Panel NICHT mehr selbst auf Port 80.
func TestSolverFactoryWaehltDenWebroot(t *testing.T) {
	log := slog.New(slog.DiscardHandler)

	factory, err := solverFactory(Options{
		Challenge:  "http-01",
		HTTP01Addr: ":80",
		Webroot:    t.TempDir(),
	}, log, reporter{})
	if err != nil {
		t.Fatalf("solverFactory: %v", err)
	}
	solver, err := factory(context.Background())
	if err != nil {
		t.Fatalf("Löser: %v", err)
	}
	if _, ok := solver.(*webrootSolver); !ok {
		t.Fatalf("erwartet der Weg durch den Webserver, bekam %T — mit gesetztem "+
			"Webroot darf das Panel Port 80 nicht selbst binden", solver)
	}
}

// Und ohne Webroot bleibt es beim eigenen Listener. Geprüft wird der TYP und
// nicht ein geöffneter Port: Beide melden „http-01", und ein Test, der nur den
// Challenge-Typ ansieht, könnte die beiden nie unterscheiden.
func TestSolverFactoryOhneWebrootLauschtSelbst(t *testing.T) {
	log := slog.New(slog.DiscardHandler)

	factory, err := solverFactory(Options{
		Challenge: "http-01",
		// Port 0: Das Betriebssystem sucht einen freien. Der Test soll nicht
		// daran scheitern, dass auf dieser Maschine etwas auf 80 hört — und
		// erst recht nicht selbst Port 80 belegen.
		HTTP01Addr: "127.0.0.1:0",
	}, log, reporter{})
	if err != nil {
		t.Fatalf("solverFactory: %v", err)
	}
	solver, err := factory(context.Background())
	if err != nil {
		t.Fatalf("Löser: %v", err)
	}
	defer func() {
		if c, ok := solver.(interface{ Close() error }); ok {
			_ = c.Close()
		}
	}()
	if _, ok := solver.(*http01Solver); !ok {
		t.Fatalf("ohne Webroot erwartet der eigene Listener, bekam %T", solver)
	}
}

// DNS-01 bleibt unberührt: Ein gesetzter Webroot ist eine Aussage über Port 80
// und sagt nichts über den Weg über das DNS.
func TestSolverFactoryWebrootAendertDNS01Nicht(t *testing.T) {
	log := slog.New(slog.DiscardHandler)

	factory, err := solverFactory(Options{
		Challenge:     "dns-01",
		DNS01Provider: "hook",
		HookSet:       "/usr/local/bin/setze",
		HookClean:     "/usr/local/bin/raeume",
		Webroot:       t.TempDir(),
	}, log, reporter{})
	if err != nil {
		t.Fatalf("solverFactory: %v", err)
	}
	solver, err := factory(context.Background())
	if err != nil {
		t.Fatalf("Löser: %v", err)
	}
	if solver.challengeType() != "dns-01" {
		t.Errorf("Challenge-Typ = %q, erwartet dns-01", solver.challengeType())
	}
}
