package privops

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"
)

// TestTimerListErgaenztDieEigenschaften: list-timers liefert Namen und
// Zeitpunkte, aber keine Beschreibung und kein OnCalendar. Ohne den zweiten
// Aufruf stünde in der Liste ein Unit-Name und sonst nichts — und die Frage
// „was tut dieser Timer" bliebe unbeantwortet.
func TestTimerListErgaenztDieEigenschaften(t *testing.T) {
	r := newFakeRunner()
	r.responses["systemctl list-timers"] = Result{Stdout: `[
		{"unit":"apt-daily.timer","activates":"apt-daily.service",
		 "next":1753900000000000,"last":1753800000000000},
		{"unit":"fstrim.timer","activates":"fstrim.service","next":0,"last":0}
	]`}
	r.responses["systemctl show --no-pager --property=Id,Description,ActiveState,UnitFileState,Unit,Persistent,TimersCalendar -- apt-daily.timer"] = Result{
		Stdout: `Id=apt-daily.timer
Description=Daily apt download activities
ActiveState=active
UnitFileState=enabled
Unit=apt-daily.service
Persistent=yes
TimersCalendar={ OnCalendar=*-*-* 6,18:00:00 ; next_elapse=... }
`}
	r.responses["systemctl show --no-pager --property=Id,Description,ActiveState,UnitFileState,Unit,Persistent,TimersCalendar -- fstrim.timer"] = Result{
		Stdout: `Id=fstrim.timer
Description=Discard unused blocks once a week
ActiveState=inactive
UnitFileState=disabled
Unit=fstrim.service
Persistent=no
`}

	timers, err := NewSystemWithRunner(r).TimerList(context.Background())
	if err != nil {
		t.Fatalf("TimerList: %v", err)
	}
	if len(timers) != 2 {
		t.Fatalf("%d Timer, erwartet 2", len(timers))
	}

	apt := timers[0]
	if apt.Beschreibung != "Daily apt download activities" {
		t.Errorf("Beschreibung = %q", apt.Beschreibung)
	}
	if apt.Loest != "apt-daily.service" {
		t.Errorf("Loest = %q", apt.Loest)
	}
	// Aktiv und Enabled sind zwei Fragen: Läuft er, und kommt er nach einem
	// Neustart wieder? Ein Feld für beides wäre eine Auskunft weniger.
	if apt.Aktiv != "active" || apt.Enabled != "enabled" {
		t.Errorf("Aktiv = %q, Enabled = %q", apt.Aktiv, apt.Enabled)
	}
	if !apt.Persistent {
		t.Error("Persistent = false — „Persistent=yes\" wurde nicht gelesen")
	}
	if !strings.Contains(apt.Plan, "6,18:00:00") {
		t.Errorf("Plan = %q", apt.Plan)
	}
	if apt.Naechster == nil || apt.Letzter == nil {
		t.Fatalf("Zeitpunkte fehlen: naechster = %v, letzter = %v", apt.Naechster, apt.Letzter)
	}
	if got := apt.Naechster.Unix(); got != 1753900000 {
		t.Errorf("nächster Lauf = %d — die Mikrosekunden wurden falsch gerechnet", got)
	}

	// Der zweite Timer hat keine Zeitpunkte, und das ist ein eigener Zustand: Ein
	// abgeschalteter Timer hat keinen nächsten Lauf, ein nie gelaufener keinen
	// letzten.
	fstrim := timers[1]
	if fstrim.Naechster != nil || fstrim.Letzter != nil {
		t.Errorf("aus 0 wurde ein Zeitpunkt: %v / %v", fstrim.Naechster, fstrim.Letzter)
	}
	if fstrim.Persistent {
		t.Error("Persistent = true bei „Persistent=no\"")
	}
}

// TestTimerListEigenschaftsfehlerVerwirftDieListeNicht: Wenn systemctl show für
// einen Timer scheitert, stehen Name und Zeitpunkte trotzdem schon da. Die Liste
// zu verwerfen hieße, wegen einer fehlenden Beschreibung die ganze Auskunft
// wegzuwerfen.
func TestTimerListEigenschaftsfehlerVerwirftDieListeNicht(t *testing.T) {
	r := newFakeRunner()
	r.responses["systemctl list-timers"] = Result{
		Stdout: `[{"unit":"apt-daily.timer","activates":"apt-daily.service","next":1753900000000000,"last":0}]`,
	}
	// Kein Eintrag für „systemctl show" — der Läufer antwortet mit ExitCode 0 und
	// leerem stdout, also ohne Eigenschaften.

	timers, err := NewSystemWithRunner(r).TimerList(context.Background())
	if err != nil {
		t.Fatalf("TimerList: %v", err)
	}
	if len(timers) != 1 {
		t.Fatalf("%d Timer, erwartet 1", len(timers))
	}
	if timers[0].Unit != "apt-daily.timer" {
		t.Errorf("Unit = %q", timers[0].Unit)
	}
	if timers[0].Naechster == nil {
		t.Error("der Zeitpunkt fehlt, obwohl list-timers ihn geliefert hat")
	}
}

// TestTimerListLeer: Ein System ohne Timer ist kein Fehler, und die leere Liste
// muss eine leere Liste sein — nicht null. Sonst steht in der Oberfläche „keine
// Auskunft" statt „keine Timer".
func TestTimerListLeer(t *testing.T) {
	for _, ausgabe := range []string{"", "null", "[]", "  \n"} {
		r := newFakeRunner()
		r.responses["systemctl list-timers"] = Result{Stdout: ausgabe}

		timers, err := NewSystemWithRunner(r).TimerList(context.Background())
		if err != nil {
			t.Fatalf("TimerList(%q): %v", ausgabe, err)
		}
		if timers == nil {
			t.Errorf("TimerList(%q) = nil, erwartet eine leere Liste", ausgabe)
		}
		if len(timers) != 0 {
			t.Errorf("TimerList(%q): %d Timer", ausgabe, len(timers))
		}
	}
}

// TestTimerListUnlesbareAusgabe: Was nicht als JSON zu lesen ist, wird als
// Fehler gemeldet und nicht als leere Liste. Eine leere Liste hieße „keine
// Timer", und das wäre eine falsche Auskunft über das System.
func TestTimerListUnlesbareAusgabe(t *testing.T) {
	r := newFakeRunner()
	r.responses["systemctl list-timers"] = Result{Stdout: "NEXT LEFT LAST PASSED UNIT ACTIVATES"}

	if _, err := NewSystemWithRunner(r).TimerList(context.Background()); err == nil {
		t.Fatal("eine unlesbare Ausgabe wurde als leere Liste durchgelassen")
	}
}

// TestTimerRunsFragtDenDienst ist die inhaltliche Zusage dieser Funktion: Der
// Timer glückt immer, sobald er auslöst — was schiefgehen kann, geht im Dienst
// schief. Wer „letzter Lauf gescheitert" sucht, sucht dessen Exit-Code.
func TestTimerRunsFragtDenDienst(t *testing.T) {
	r := newFakeRunner()
	r.responses["systemctl show --no-pager --property=Id,Result,ExecMainStatus,ActiveState,SubState -- sicherung.service"] = Result{
		Stdout: `Id=sicherung.service
Result=exit-code
ExecMainStatus=2
ActiveState=failed
SubState=failed
`}

	lauf, err := NewSystemWithRunner(r).TimerRuns(context.Background(), "sicherung.service")
	if err != nil {
		t.Fatalf("TimerRuns: %v", err)
	}
	if lauf.Unit != "sicherung.service" {
		t.Errorf("Unit = %q", lauf.Unit)
	}
	if lauf.Ergebnis != "exit-code" {
		t.Errorf("Ergebnis = %q — das systemd-Wort wird roh übernommen", lauf.Ergebnis)
	}
	if lauf.ExitCode != 2 {
		t.Errorf("ExitCode = %d, erwartet 2", lauf.ExitCode)
	}
	if lauf.Geglueckt {
		t.Error("ein Lauf mit Result=exit-code gilt als geglückt")
	}
	if lauf.Zeilen == nil {
		t.Error("Zeilen = nil — die Oberfläche unterscheidet nicht zwischen null und leer")
	}

	// Gefragt wurde nach dem Dienst, nicht nach dem Timer. Geprüft über alle
	// Aufrufe, nicht über den letzten: Nach systemctl show kommt noch das
	// Journal, und der letzte Aufruf ist deshalb journalctl.
	for _, aufruf := range r.calls {
		for _, arg := range aufruf.Args {
			if strings.HasSuffix(arg, ".timer") {
				t.Errorf("TimerRuns fragt den Timer statt den Dienst: %s %v",
					aufruf.Name, aufruf.Args)
			}
		}
	}
}

// TestTimerRunsNieGelaufen: Ein leeres Result heißt „noch nie gelaufen". Das ist
// nicht geglückt und nicht gescheitert, und ein Exit-Code von 0 wäre dafür eine
// Lüge — deshalb −1 für „nicht bekannt".
func TestTimerRunsNieGelaufen(t *testing.T) {
	r := newFakeRunner()
	r.responses["systemctl show"] = Result{Stdout: "Id=neu.service\nResult=\nActiveState=inactive\n"}

	lauf, err := NewSystemWithRunner(r).TimerRuns(context.Background(), "neu.service")
	if err != nil {
		t.Fatalf("TimerRuns: %v", err)
	}
	if lauf.Geglueckt {
		t.Error("ein nie gelaufener Dienst gilt als geglückt")
	}
	if lauf.ExitCode != -1 {
		t.Errorf("ExitCode = %d, erwartet -1 für „nicht bekannt\"", lauf.ExitCode)
	}
}

// TestTimerRunsUnbekannteUnit: systemctl show antwortet für eine Unit, die es
// nicht gibt, mit Erfolg und fast leerer Ausgabe. Als „nie gelaufen"
// darzustellen wäre die falsche Auskunft — die Unit gibt es nicht.
func TestTimerRunsUnbekannteUnit(t *testing.T) {
	r := newFakeRunner()
	r.responses["systemctl show"] = Result{Stdout: "ActiveState=inactive\nSubState=dead\n"}

	if _, err := NewSystemWithRunner(r).TimerRuns(context.Background(), "gibtesnicht.service"); err == nil {
		t.Fatal("eine unbekannte Unit wurde als Lauf ohne Ergebnis dargestellt")
	}
}

// TestTimerRunsPrueftDenUnitnamen: Derselbe Riegel wie bei allen Unit-Aufrufen.
// Ein Name mit Schrägstrich oder Leerzeichen darf nicht an systemctl gehen.
func TestTimerRunsPrueftDenUnitnamen(t *testing.T) {
	r := newFakeRunner()
	sys := NewSystemWithRunner(r)

	for _, unit := range []string{"", "../etc/passwd", "a b.service", "ssh.service extra"} {
		if _, err := sys.TimerRuns(context.Background(), unit); err == nil {
			t.Errorf("der Unitname %q wurde angenommen", unit)
		}
	}
	if len(r.calls) != 0 {
		t.Errorf("%d Aufrufe trotz abgewiesener Namen: %+v", len(r.calls), r.calls)
	}
}

// TestTimerListFehlerWirdWeitergegeben: Auf einem System ohne systemd scheitert
// der Aufruf, und das gehört gesagt statt als leere Liste ausgegeben.
func TestTimerListFehlerWirdWeitergegeben(t *testing.T) {
	r := newFakeRunner()
	r.errs["systemctl"] = errors.New("systemctl nicht vorhanden")

	if _, err := NewSystemWithRunner(r).TimerList(context.Background()); err == nil {
		t.Fatal("der Fehler wurde verschluckt")
	}
}

// TestMikrosekundenZeit prüft die Wandlung einzeln, weil hier zwei Werte
// „kein Zeitpunkt" bedeuten und beide als Datum plausibel aussähen: 0 wäre der
// 1. Januar 1970, der Höchstwert ein Jahr in ferner Zukunft.
func TestMikrosekundenZeit(t *testing.T) {
	if got := mikrosekundenZeit(float64(0)); got != nil {
		t.Errorf("0 wurde zu %v", got)
	}
	if got := mikrosekundenZeit("0"); got != nil {
		t.Errorf("\"0\" wurde zu %v", got)
	}
	// Der Wert, den systemd für „nicht bestimmbar" einträgt.
	if got := mikrosekundenZeit("18446744073709551615"); got != nil {
		t.Errorf("der Höchstwert wurde zu %v", got)
	}
	if got := mikrosekundenZeit(nil); got != nil {
		t.Errorf("nil wurde zu %v", got)
	}
	if got := mikrosekundenZeit("keine Zahl"); got != nil {
		t.Errorf("Text wurde zu %v", got)
	}

	// Und der gewöhnliche Fall, in beiden Schreibweisen: Ältere systemd-Fassungen
	// geben die Zahl als Text.
	erwartet := time.UnixMicro(1753900000000000)
	for _, v := range []any{float64(1753900000000000), "1753900000000000"} {
		got := mikrosekundenZeit(v)
		if got == nil {
			t.Fatalf("%v wurde zu nil", v)
		}
		if !got.Equal(erwartet) {
			t.Errorf("%v wurde zu %v, erwartet %v", v, got, erwartet)
		}
	}
}

// TestParseEigenschaften: systemctl show gibt KEY=VALUE, und ein Wert darf ein
// Gleichheitszeichen enthalten — TimersCalendar tut das immer.
func TestParseEigenschaften(t *testing.T) {
	eig := parseEigenschaften(`Id=apt-daily.timer
TimersCalendar={ OnCalendar=*-*-* 6,18:00:00 ; next_elapse=Wed 2026-07-30 18:00:00 UTC }
Description=
kaputte Zeile ohne Gleichheitszeichen

ActiveState=active
`)
	if eig["Id"] != "apt-daily.timer" {
		t.Errorf("Id = %q", eig["Id"])
	}
	if !strings.Contains(eig["TimersCalendar"], "next_elapse=") {
		t.Errorf("der Wert wurde am zweiten Gleichheitszeichen abgeschnitten: %q", eig["TimersCalendar"])
	}
	if _, da := eig["Description"]; !da {
		t.Error("ein leerer Wert fehlt in der Karte")
	}
	if eig["ActiveState"] != "active" {
		t.Errorf("die Zeile nach der kaputten wurde nicht gelesen: %q", eig["ActiveState"])
	}
}
