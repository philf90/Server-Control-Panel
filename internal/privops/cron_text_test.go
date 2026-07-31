package privops

import (
	"strings"
	"testing"
)

// TestScheduleText prüft die Lesehilfe an den Zeitplänen, die wirklich
// vorkommen. Der Satz ist die Antwort auf den häufigsten Irrtum überhaupt: Ein
// Eintrag lief, nur zu einer anderen Zeit als gedacht.
func TestScheduleText(t *testing.T) {
	faelle := []struct {
		plan, satz string
	}{
		// Der Normalfall: zwei Zahlen, jeden Tag.
		{"17 3 * * *", "täglich um 03:17"},
		{"0 0 * * *", "täglich um 00:00"},
		// Werktage haben einen eigenen Namen. Wer "1-5" liest, meint sie.
		{"30 6 * * 1-5", "an Werktagen um 06:30"},
		{"0 22 * * 0", "sonntags um 22:00"},
		{"0 22 * * 7", "sonntags um 22:00"},
		{"0 9 * * 1,3,5", "montags, mittwochs und freitags um 09:00"},
		{"0 9 * * mon", "montags um 09:00"},
		{"0 9 * * mon-fri", "an Werktagen um 09:00"},
		// Schrittweiten.
		{"*/5 * * * *", "täglich alle 5 Minuten"},
		{"*/15 8-17 * * *", "täglich alle 15 Minuten zwischen 08:00 und 17:59"},
		{"0 */6 * * *", "täglich alle 6 Stunden zur Minute 0"},
		{"5 * * * *", "täglich stündlich zur Minute 5"},
		{"* * * * *", "täglich jede Minute"},
		// Monatstag und Monat.
		{"0 4 1 * *", "am 1. um 04:00"},
		{"0 4 1,15 * *", "am 1., 15. um 04:00"},
		{"0 4 1 1 *", "am 1. um 04:00, nur im Januar"},
		{"0 4 1 1,7 *", "am 1. um 04:00, nur im Januar und Juli"},
		// Sonderworte.
		{"@daily", "täglich um 00:00"},
		{"@weekly", "wöchentlich sonntags um 00:00"},
		{"@reboot", "beim Hochfahren"},
		{"@hourly", "stündlich zur Minute 0"},
	}
	for _, f := range faelle {
		if got := ScheduleText(f.plan); got != f.satz {
			t.Errorf("ScheduleText(%q)\n  = %q\n  erwartet %q", f.plan, got, f.satz)
		}
	}
}

// TestScheduleTextNenntDieOderFalle: Monatstag UND Wochentag zusammen ist in
// cron ein ODER, nicht ein UND. "0 0 1 * 1" läuft am 1. jedes Monats UND jeden
// Montag — eine Falle, die auch Erfahrene trifft. Der Satz muss sie benennen,
// sonst ist er schlimmer als kein Satz.
func TestScheduleTextNenntDieOderFalle(t *testing.T) {
	satz := ScheduleText("0 0 1 * 1")
	if satz == "" {
		t.Fatal("kein Satz für einen Zeitplan mit Monatstag und Wochentag")
	}
	if !strings.Contains(satz, "ODER") {
		t.Errorf("der Satz verschweigt die ODER-Verknüpfung: %q", satz)
	}
	if strings.Contains(satz, "UND ") && !strings.Contains(satz, "nicht mit UND") {
		t.Errorf("der Satz behauptet eine UND-Verknüpfung: %q", satz)
	}
}

// TestScheduleTextSchweigtLieber: Wo die Worte nicht reichen, ist die leere
// Rückgabe die Zusage — der Aufrufer zeigt dann nur das rohe Feld. Ein Satz, der
// etwas anderes behauptet als der Zeitplan tut, ist schlimmer als gar keiner.
func TestScheduleTextSchweigtLieber(t *testing.T) {
	stumm := []string{
		"",                   // nichts angegeben
		"17 3 * *",           // vier Felder
		"17 3 * * * *",       // sechs Felder (Sekunden — das kann cron nicht)
		"@immerdann",         // erfundenes Sonderwort
		"0-30/2 1-5/2 * * *", // verschachtelte Bereiche mit Schrittweiten
		"0 4 1-15/2 * *",     // Monatstagsbereich mit Schrittweite
		"unsinn",
	}
	for _, plan := range stumm {
		if satz := ScheduleText(plan); satz != "" {
			t.Errorf("ScheduleText(%q) = %q — erwartet Schweigen", plan, satz)
		}
	}
}

// TestScheduleTextStimmtMitDerPruefungZusammen: Jeder Zeitplan, den ScheduleText
// in Worte fasst, muss ValidateSchedule bestehen. Andernfalls würde die
// Oberfläche einen Satz zu einem Plan zeigen, den sie gleich darauf abweist —
// oder, schlimmer, einer der beiden hätte die Felder anders gelesen als der
// andere.
func TestScheduleTextStimmtMitDerPruefungZusammen(t *testing.T) {
	plaene := []string{
		"17 3 * * *", "30 6 * * 1-5", "*/5 * * * *", "*/15 8-17 * * *",
		"0 */6 * * *", "0 4 1,15 * *", "0 4 1 1,7 *", "0 9 * * mon-fri",
		"0 22 * * 7", "@daily", "@reboot",
	}
	for _, plan := range plaene {
		if ScheduleText(plan) == "" {
			t.Errorf("%q: kein Satz, obwohl der Plan gewöhnlich ist", plan)
		}
		if err := ValidateSchedule(plan); err != nil {
			t.Errorf("%q: ScheduleText erklärt ihn, ValidateSchedule weist ihn ab: %v", plan, err)
		}
	}
}
