package privops

// Tests für die Journalabfrage und den Follow-Strom.
//
// Der Kern: Beide bauen ihre Argumente aus derselben Funktion. Hätte der Strom
// eigene, könnte er mehr zeigen als die Abfrage vorher hergab — bei einer
// Stufenbeschränkung wäre das ein Leck durch die Hintertür, und niemandem fiele
// es auf, weil beide Wege einzeln richtig aussehen.

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"
)

// Ein Beispiel-Journaleintrag, wie journalctl --output=json ihn schreibt.
const journalZeile = `{"__REALTIME_TIMESTAMP":"1700000000000000",` +
	`"_SYSTEMD_UNIT":"ssh.service","PRIORITY":"3","MESSAGE":"Fehler beim Binden",` +
	`"_HOSTNAME":"vm"}`

func TestLogsUndFollowBauenDieselbenFilter(t *testing.T) {
	q := LogQuery{Unit: "ssh.service", Priority: 3, Since: "-1h", Limit: 50}

	abfrage := newFakeRunner()
	abfrage.responses["journalctl"] = Result{Stdout: journalZeile}
	if _, err := NewSystemWithRunner(abfrage).Logs(context.Background(), q); err != nil {
		t.Fatalf("Logs: %v", err)
	}

	strom := newFakeRunner()
	strom.responses["journalctl"] = Result{Stdout: journalZeile}
	if err := NewSystemWithRunner(strom).LogsFollow(context.Background(), q, func(LogEntry) {}); err != nil {
		t.Fatalf("LogsFollow: %v", err)
	}

	argsAbfrage := strings.Join(abfrage.lastCall().Args, " ")
	argsStrom := strings.Join(strom.lastCall().Args, " ")

	// Der Strom hat genau ein Argument mehr: --follow.
	if argsStrom != argsAbfrage+" --follow" {
		t.Errorf("die Argumente laufen auseinander:\n  Abfrage: %s\n  Strom:   %s",
			argsAbfrage, argsStrom)
	}
	for _, teil := range []string{"--unit ssh.service", "--priority 3", "--since -1h", "--lines 50"} {
		if !strings.Contains(argsAbfrage, teil) {
			t.Errorf("der Filter %q fehlt: %s", teil, argsAbfrage)
		}
	}
}

// Der Strom läuft ohne eigene Frist: Er endet, wenn der Aufrufer ihn beendet.
// Eine Frist von 30 Sekunden hätte ein Journal, das nach einer halben Minute
// stumm wird — und niemand hätte den Zusammenhang gesehen.
func TestLogsFollowOhneEigeneFrist(t *testing.T) {
	r := newFakeRunner()
	r.responses["journalctl"] = Result{Stdout: journalZeile}

	if err := NewSystemWithRunner(r).LogsFollow(
		context.Background(), LogQuery{Priority: -1}, func(LogEntry) {}); err != nil {
		t.Fatalf("LogsFollow: %v", err)
	}
	if !r.lastCall().OhneFrist {
		t.Error("der Follow-Aufruf trägt eine Frist — das Journal würde nach ihr verstummen")
	}
}

// Jede Zeile wird einzeln zerlegt und mit denselben Regeln wie die Abfrage.
// Zeilen, die sich nicht zerlegen lassen, werden übersprungen: journalctl
// schreibt bei einer Rotation gelegentlich eine Hinweiszeile dazwischen, und
// daran soll der Strom nicht abreißen.
func TestLogsFollowZerlegtJedeZeileEinzeln(t *testing.T) {
	r := newFakeRunner()
	r.responses["journalctl"] = Result{
		Stdout: strings.Join([]string{
			journalZeile,
			"-- Journal begins at Mon 2024-01-01 --", // kein JSON
			"",                                       // leer
			journalZeile,
		}, "\n"),
	}

	var bekommen []LogEntry
	if err := NewSystemWithRunner(r).LogsFollow(
		context.Background(), LogQuery{Priority: -1},
		func(e LogEntry) { bekommen = append(bekommen, e) }); err != nil {
		t.Fatalf("LogsFollow: %v", err)
	}

	if len(bekommen) != 2 {
		t.Fatalf("%d Einträge empfangen, erwartet 2 (die Hinweiszeile zählt nicht)", len(bekommen))
	}
	e := bekommen[0]
	if e.Unit != "ssh.service" || e.Priority != 3 || e.Message != "Fehler beim Binden" {
		t.Errorf("Eintrag falsch zerlegt: %+v", e)
	}
	if !e.At.Equal(time.UnixMicro(1700000000000000)) {
		t.Errorf("Zeitstempel = %v, erwartet die Mikrosekunden aus dem Journal", e.At)
	}
}

// Die Freitextsuche gilt auch im Strom — und mit derselben Regel wie in der
// Abfrage: ein einfacher Vergleich, kein regulärer Ausdruck. Ein Ausdruck wäre
// eine Suche, die jemand versehentlich teuer macht.
func TestLogsFollowFiltertDenFreitext(t *testing.T) {
	r := newFakeRunner()
	r.responses["journalctl"] = Result{
		Stdout: journalZeile + "\n" + strings.Replace(journalZeile,
			"Fehler beim Binden", "alles in Ordnung", 1),
	}

	var bekommen []LogEntry
	if err := NewSystemWithRunner(r).LogsFollow(
		context.Background(), LogQuery{Priority: -1, Search: "BINDEN"},
		func(e LogEntry) { bekommen = append(bekommen, e) }); err != nil {
		t.Fatalf("LogsFollow: %v", err)
	}

	if len(bekommen) != 1 {
		t.Fatalf("%d Einträge, erwartet 1", len(bekommen))
	}
	// Groß- und Kleinschreibung wird ignoriert — wie in der Abfrage.
	if bekommen[0].Message != "Fehler beim Binden" {
		t.Errorf("die falsche Zeile kam durch: %q", bekommen[0].Message)
	}
	// Die Suche läuft NICHT über journalctl --grep: Dort wäre die Eingabe ein
	// regulärer Ausdruck.
	if strings.Contains(strings.Join(r.lastCall().Args, " "), "--grep") {
		t.Error("die Suche läuft über --grep — die Eingabe wäre dann ein regulärer Ausdruck")
	}
}

// Eine unsinnige Zeitangabe wird abgewiesen, bevor journalctl läuft — in beiden
// Wegen. Sonst landete eine Eingabe des Nutzers ungeprüft in einem Argument.
func TestLogsFollowPrueftDieZeitangabe(t *testing.T) {
	r := newFakeRunner()
	s := NewSystemWithRunner(r)

	err := s.LogsFollow(context.Background(),
		LogQuery{Priority: -1, Since: "vorgestern nachmittags"}, func(LogEntry) {})
	if err == nil {
		t.Fatal("eine unsinnige Zeitangabe muss abgewiesen werden")
	}
	if len(r.calls) != 0 {
		t.Errorf("journalctl wurde trotzdem aufgerufen: %v", r.calls)
	}
}

// Eine unsinnige Unit ebenso: ValidateUnit steht vor dem Argument.
func TestLogsFollowPrueftDieUnit(t *testing.T) {
	r := newFakeRunner()
	if err := NewSystemWithRunner(r).LogsFollow(context.Background(),
		LogQuery{Priority: -1, Unit: "böse; rm -rf /"}, func(LogEntry) {}); err == nil {
		t.Fatal("eine unsinnige Unit muss abgewiesen werden")
	}
	if len(r.calls) != 0 {
		t.Errorf("journalctl wurde trotzdem aufgerufen: %v", r.calls)
	}
}

// Ohne Empfänger gibt es nichts zu tun — und ein nil-Aufruf im Stream wäre ein
// Absturz im Lesefaden, also an der unauffälligsten Stelle.
func TestLogsFollowOhneEmpfaenger(t *testing.T) {
	r := newFakeRunner()
	if err := NewSystemWithRunner(r).LogsFollow(
		context.Background(), LogQuery{Priority: -1}, nil); err == nil {
		t.Fatal("ein Aufruf ohne Empfänger muss abgewiesen werden")
	}
	if len(r.calls) != 0 {
		t.Errorf("journalctl wurde trotzdem aufgerufen: %v", r.calls)
	}
}

// Ein Fehler des Runners kommt durch. Bei einem abgebrochenen Kontext ist das
// context.Canceled — der Aufrufer erkennt daran das vorgesehene Ende.
func TestLogsFollowGibtDenFehlerWeiter(t *testing.T) {
	r := newFakeRunner()
	r.errs["journalctl"] = errors.New("journalctl: " + context.Canceled.Error())

	err := NewSystemWithRunner(r).LogsFollow(
		context.Background(), LogQuery{Priority: -1}, func(LogEntry) {})
	if err == nil {
		t.Fatal("der Fehler des Runners kommt nicht durch")
	}
	if !strings.Contains(err.Error(), "context canceled") {
		t.Errorf("unerwarteter Fehler: %v", err)
	}
}

// Ein Follow-Lauf, der von selbst mit einem Fehlercode endet, ist ein echter
// Fehler: journalctl bleibt sonst offen.
func TestLogsFollowMeldetFehlercode(t *testing.T) {
	r := newFakeRunner()
	r.responses["journalctl"] = Result{ExitCode: 1, Stderr: "No journal files were found."}

	err := NewSystemWithRunner(r).LogsFollow(
		context.Background(), LogQuery{Priority: -1}, func(LogEntry) {})
	if err == nil {
		t.Fatal("ein Fehlercode wird verschwiegen")
	}
	if !strings.Contains(err.Error(), "No journal files") {
		t.Errorf("die Meldung von journalctl fehlt: %v", err)
	}
}

// Ein Kommando ohne Frist läuft, bis der Kontext abbricht — und nicht in die
// Vorgabefrist. Geprüft am echten Runner, weil genau dort die Frist gesetzt wird.
func TestExecRunnerOhneFristEndetMitDemKontext(t *testing.T) {
	// Ein Kommando, das es auf jedem System gibt und das sofort endet, genügt:
	// Geprüft wird, dass OhneFrist keine Frist setzt und der Aufruf ohne
	// Zeitüberschreitung zurückkommt. Ein Kommando, das lange läuft, wäre ein
	// Test, der lange läuft.
	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	_, err := ExecRunner{}.Run(ctx, Command{Name: "id", Args: []string{"-u"}, OhneFrist: true})
	if err == nil {
		t.Skip("id ist auf diesem System nicht vorhanden oder lief trotz Abbruch")
	}
	// Der Fehler muss den Abbruch nennen und nicht eine Frist, die niemand
	// gesetzt hat.
	if strings.Contains(err.Error(), "Zeitüberschreitung nach 0s") {
		t.Errorf("der Fehler nennt eine Frist von null: %v", err)
	}
}

// Ein abgebrochener Kontext ist kein Fehler.
//
// Der Fall, der im Betrieb bei JEDEM geschlossenen Tab eintritt: Der Kontext
// bricht ab, CommandContext tötet journalctl, und der getötete Prozess
// hinterlässt einen Exit-Code ungleich null. Wird der als Ergebnis gelesen, sieht
// jedes normale Ende wie ein Scheitern aus — und die Oberfläche schreibt eine
// Fehlermeldung in den Strom, obwohl nur jemand die Seite verlassen hat.
func TestLogsFollowAbbruchIstKeinFehler(t *testing.T) {
	r := newFakeRunner()
	// So sieht ein getöteter Prozess aus: Exit-Code ungleich null, Meldung auf
	// stderr. Das ist genau die Antwort, die der echte Runner in diesem Fall gibt.
	r.responses["journalctl"] = Result{ExitCode: -1, Stderr: "signal: killed"}

	ctx, cancel := context.WithCancel(context.Background())
	cancel()

	if err := NewSystemWithRunner(r).LogsFollow(ctx, LogQuery{Priority: -1},
		func(LogEntry) {}); err != nil {
		t.Errorf("ein abgebrochener Kontext wird als Fehler gemeldet: %v", err)
	}
}
