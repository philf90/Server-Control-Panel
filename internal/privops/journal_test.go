package privops

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"
)

type stubRunner struct {
	res Result
	err error
}

func (s stubRunner) Run(context.Context, Command) (Result, error) { return s.res, s.err }

func TestJournalRingVerdraengtDenAeltesten(t *testing.T) {
	j := NewJournal()
	for i := 0; i < journalGroesse+5; i++ {
		j.Notiere(Notiz{Befehl: "cmd" + string(rune('a'+i%26)), Zeit: time.Now()})
	}
	if got := j.Anzahl(); got != journalGroesse {
		t.Fatalf("Anzahl = %d, erwartet %d", got, journalGroesse)
	}
	// Der jüngste Eintrag steht vorn.
	letzte := j.Letzte(3)
	if len(letzte) != 3 {
		t.Fatalf("Letzte(3) lieferte %d Einträge", len(letzte))
	}
	erwartet := "cmd" + string(rune('a'+(journalGroesse+4)%26))
	if letzte[0].Befehl != erwartet {
		t.Errorf("jüngster Befehl = %q, erwartet %q", letzte[0].Befehl, erwartet)
	}
}

func TestJournalLetzteBeiTeilweiseGefuelltemRing(t *testing.T) {
	j := NewJournal()
	j.Notiere(Notiz{Befehl: "eins"})
	j.Notiere(Notiz{Befehl: "zwei"})

	got := j.Letzte(10)
	if len(got) != 2 {
		t.Fatalf("Letzte(10) lieferte %d Einträge, erwartet 2", len(got))
	}
	if got[0].Befehl != "zwei" || got[1].Befehl != "eins" {
		t.Errorf("Reihenfolge falsch: %q, %q", got[0].Befehl, got[1].Befehl)
	}
}

// Ein nil-Journal darf nicht knallen: Wer einen eigenen Executor einsetzt,
// bekommt keines, und die Vorlagen fragen trotzdem danach.
func TestJournalNilIstBenutzbar(t *testing.T) {
	var j *Journal
	j.Notiere(Notiz{Befehl: "egal"})
	if got := j.Letzte(5); got != nil {
		t.Errorf("Letzte auf nil = %v, erwartet nil", got)
	}
	if got := j.Anzahl(); got != 0 {
		t.Errorf("Anzahl auf nil = %d, erwartet 0", got)
	}
}

func TestJournalRunnerZeichnetErgebnisAuf(t *testing.T) {
	j := NewJournal()
	r := MitJournal(stubRunner{res: Result{ExitCode: 1, Stderr: "Job for x.service failed\nsee journalctl"}}, j)

	if _, err := r.Run(context.Background(), Command{Name: "systemctl", Args: []string{"restart", "x.service"}}); err != nil {
		t.Fatalf("Run: %v", err)
	}

	n := j.Letzte(1)
	if len(n) != 1 {
		t.Fatal("nichts aufgezeichnet")
	}
	if n[0].Befehl != "systemctl restart x.service" {
		t.Errorf("Befehl = %q", n[0].Befehl)
	}
	if n[0].ExitCode != 1 || n[0].Gelungen() {
		t.Errorf("ExitCode = %d, Gelungen = %v", n[0].ExitCode, n[0].Gelungen())
	}
	if n[0].Meldung != "Job for x.service failed" {
		t.Errorf("Meldung = %q, erwartet die erste stderr-Zeile", n[0].Meldung)
	}
}

func TestJournalRunnerZeichnetAufrufFehlerAuf(t *testing.T) {
	j := NewJournal()
	r := MitJournal(stubRunner{err: errors.New("systemctl: Zeitüberschreitung nach 30s")}, j)

	_, _ = r.Run(context.Background(), Command{Name: "systemctl", Args: []string{"status"}})

	n := j.Letzte(1)
	if len(n) != 1 || n[0].Gelungen() {
		t.Fatalf("Fehlschlag nicht vermerkt: %+v", n)
	}
	if !strings.Contains(n[0].Fehler, "Zeitüberschreitung") {
		t.Errorf("Fehler = %q", n[0].Fehler)
	}
}

// Der entscheidende Punkt: Die Konsole darf kein Geheimnis zeigen. Stdin wird
// gar nicht erst betrachtet, und Argumente nach einer verdächtigen Option
// werden verdeckt.
func TestBefehlszeileVerdecktGeheimnisse(t *testing.T) {
	faelle := []struct {
		name string
		cmd  Command
		will string
	}{
		{
			name: "Stdin bleibt außen vor",
			cmd:  Command{Name: "passwd", Args: []string{"philipp"}, Stdin: "gehe1m!"},
			will: "passwd philipp",
		},
		{
			name: "Wert nach --token verdeckt",
			cmd:  Command{Name: "ufw", Args: []string{"--token", "abc123"}},
			will: "ufw --token •••",
		},
		{
			name: "Gleichheitsform verdeckt",
			cmd:  Command{Name: "ufw", Args: []string{"--api-key=abc123"}},
			will: "ufw --api-key=•••",
		},
		{
			name: "Freitext bleibt lesbar",
			cmd:  Command{Name: "ufw", Args: []string{"allow", "22/tcp", "comment", "SSH"}},
			will: "ufw allow 22/tcp comment SSH",
		},
	}
	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			if got := Befehlszeile(f.cmd); got != f.will {
				t.Errorf("Befehlszeile = %q, erwartet %q", got, f.will)
			}
		})
	}
}

func TestNotizDauerText(t *testing.T) {
	faelle := map[time.Duration]string{
		0:                       "0.0s",
		1900 * time.Millisecond: "1.9s",
		45 * time.Second:        "45s",
	}
	for d, will := range faelle {
		if got := (Notiz{Dauer: d}).DauerText(); got != will {
			t.Errorf("DauerText(%s) = %q, erwartet %q", d, got, will)
		}
	}
}
