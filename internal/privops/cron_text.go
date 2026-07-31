package privops

// Ein Zeitplan in Worten.
//
// "17 3 * * 1-5" ist für den, der es täglich liest, sofort klar und für alle
// anderen nicht. Ein falsch gelesener Zeitplan ist der häufigste Grund, warum
// jemand einen Eintrag für kaputt hält — er lief, nur zu einer anderen Zeit als
// gedacht. Deshalb steht der Satz daneben, und deshalb steht er hier im Server:
// Dieselbe Auskunft in der Oberfläche zu bauen wäre eine zweite Auslegung
// derselben fünf Felder, und zwei Auslegungen laufen auseinander.
//
// Der Satz ist eine LESEHILFE und kein Ersatz für das Feld. Der rohe Zeitplan
// steht immer daneben; wo die Worte nicht reichen (verschachtelte Listen mit
// Schrittweiten), sagt der Satz das offen statt zu raten.

import (
	"fmt"
	"strconv"
	"strings"
)

// sonderwortText ist der Satz zu den Kurzschreibweisen. @reboot ist der
// interessante Fall: Er hat keine Uhrzeit, und wer ihn für „täglich" hält,
// wartet vergeblich.
var sonderwortText = map[string]string{
	"@reboot":   "beim Hochfahren",
	"@yearly":   "jährlich am 1. Januar um 00:00",
	"@annually": "jährlich am 1. Januar um 00:00",
	"@monthly":  "monatlich am 1. um 00:00",
	"@weekly":   "wöchentlich sonntags um 00:00",
	"@daily":    "täglich um 00:00",
	"@midnight": "täglich um 00:00",
	"@hourly":   "stündlich zur Minute 0",
}

var wochentage = []string{"sonntags", "montags", "dienstags", "mittwochs",
	"donnerstags", "freitags", "samstags"}

var monate = []string{"", "Januar", "Februar", "März", "April", "Mai", "Juni",
	"Juli", "August", "September", "Oktober", "November", "Dezember"}

// ScheduleText schreibt einen Cron-Zeitplan in Worten.
//
// Rückgabe leer heißt: nicht in Worte zu fassen. Der Aufrufer zeigt dann nur das
// rohe Feld — das ist ehrlicher als ein Satz, der etwas anderes behauptet als der
// Zeitplan tut.
func ScheduleText(schedule string) string {
	s := strings.TrimSpace(schedule)
	if s == "" {
		return ""
	}
	if strings.HasPrefix(s, "@") {
		return sonderwortText[strings.ToLower(s)]
	}

	felder := strings.Fields(s)
	if len(felder) != 5 {
		return ""
	}
	minute, stunde, tag, monat, wochentag := felder[0], felder[1], felder[2], felder[3], felder[4]

	// Die Uhrzeit ist der Kern. Ohne sie lohnt der Satz nicht: „an jedem Tag"
	// ohne Zeitangabe sagt weniger als das rohe Feld.
	zeit, ok := uhrzeitText(minute, stunde)
	if !ok {
		return ""
	}

	// Der Tagesteil. Tag-des-Monats UND Wochentag zusammen ist in cron ein ODER,
	// nicht ein UND — eine Falle, die auch Erfahrene trifft. Statt sie in einen
	// Satz zu pressen, sagt der Text es ausdrücklich.
	tagGesetzt := tag != "*"
	wochentagGesetzt := wochentag != "*" && wochentag != "?"
	// Die beiden Teile werden vor dem Zusammensetzen einzeln geprüft. Ein „am " +
	// leerer Text ergäbe „am  um 04:00" — die leere Rückgabe von tagText wäre
	// hinter dem Vorwort verschwunden, und der Satz behauptete einen Tag, den er
	// nicht kennt.
	var tagWorte, wochentagWorte string
	if tagGesetzt {
		if tagWorte = tagText(tag); tagWorte == "" {
			return ""
		}
	}
	if wochentagGesetzt {
		if wochentagWorte = wochentagText(wochentag); wochentagWorte == "" {
			return ""
		}
	}

	var tagesteil string
	switch {
	case tagGesetzt && wochentagGesetzt:
		return fmt.Sprintf("%s — am %s ODER %s (cron verknüpft Monatstag und "+
			"Wochentag mit ODER, nicht mit UND)", zeit, tagWorte, wochentagWorte)
	case wochentagGesetzt:
		tagesteil = wochentagWorte
	case tagGesetzt:
		tagesteil = "am " + tagWorte
	default:
		tagesteil = "täglich"
	}

	satz := tagesteil + " " + zeit
	if monat != "*" {
		mt := monatText(monat)
		if mt == "" {
			return ""
		}
		satz += ", " + mt
	}
	return satz
}

// uhrzeitText baut den Zeitteil des Satzes.
func uhrzeitText(minute, stunde string) (string, bool) {
	// Der häufigste Fall überhaupt: beide Felder eine Zahl.
	if m, ok := einzelzahl(minute); ok {
		if h, ok := einzelzahl(stunde); ok {
			return fmt.Sprintf("um %02d:%02d", h, m), true
		}
		if schritt, ok := sternSchritt(stunde); ok {
			return fmt.Sprintf("alle %d Stunden zur Minute %d", schritt, m), true
		}
		if stunde == "*" {
			return fmt.Sprintf("stündlich zur Minute %d", m), true
		}
		if liste, ok := zahlenliste(stunde, 0, 23); ok {
			return fmt.Sprintf("um %s Uhr (Minute %d)", zahlenAufzaehlung(liste), m), true
		}
		return "", false
	}

	// Alle n Minuten.
	if schritt, ok := sternSchritt(minute); ok {
		switch {
		case stunde == "*":
			return fmt.Sprintf("alle %d Minuten", schritt), true
		case istEinzelzahl(stunde):
			h, _ := einzelzahl(stunde)
			return fmt.Sprintf("alle %d Minuten in der Stunde %d", schritt, h), true
		}
		if von, bis, ok := bereich(stunde, 0, 23); ok {
			return fmt.Sprintf("alle %d Minuten zwischen %02d:00 und %02d:59",
				schritt, von, bis), true
		}
		return "", false
	}

	if minute == "*" && stunde == "*" {
		return "jede Minute", true
	}
	return "", false
}

// tagText beschreibt das Monatstagsfeld.
func tagText(tag string) string {
	if n, ok := einzelzahl(tag); ok {
		return strconv.Itoa(n) + "."
	}
	if liste, ok := zahlenliste(tag, 1, 31); ok {
		var teile []string
		for _, n := range liste {
			teile = append(teile, strconv.Itoa(n)+".")
		}
		return strings.Join(teile, ", ")
	}
	if schritt, ok := sternSchritt(tag); ok {
		return fmt.Sprintf("jeden %d. Tag", schritt)
	}
	return ""
}

// wochentagText beschreibt das Wochentagsfeld. 0 und 7 sind beide Sonntag.
func wochentagText(feld string) string {
	if von, bis, ok := bereich(feld, 0, 7); ok {
		// Der häufigste Bereich hat einen eigenen Namen: Wer 1-5 liest, meint
		// Werktage, und „montags bis freitags" ist der Satz dazu.
		if von == 1 && bis == 5 {
			return "an Werktagen"
		}
		return wochentagName(von) + " bis " + wochentagName(bis)
	}
	if liste, ok := zahlenliste(feld, 0, 7); ok {
		var teile []string
		for _, n := range liste {
			teile = append(teile, wochentagName(n))
		}
		if len(teile) == 1 {
			return teile[0]
		}
		return strings.Join(teile[:len(teile)-1], ", ") + " und " + teile[len(teile)-1]
	}
	return ""
}

func wochentagName(n int) string {
	if n == 7 {
		n = 0
	}
	if n < 0 || n > 6 {
		return ""
	}
	return wochentage[n]
}

// monatText beschreibt das Monatsfeld.
func monatText(feld string) string {
	if liste, ok := zahlenliste(feld, 1, 12); ok {
		var teile []string
		for _, n := range liste {
			teile = append(teile, monate[n])
		}
		if len(teile) == 1 {
			return "nur im " + teile[0]
		}
		return "nur im " + strings.Join(teile[:len(teile)-1], ", ") + " und " + teile[len(teile)-1]
	}
	if von, bis, ok := bereich(feld, 1, 12); ok {
		return "von " + monate[von] + " bis " + monate[bis]
	}
	return ""
}

// ------------------------------------------------------------- Feldlesehilfen ---

func einzelzahl(feld string) (int, bool) {
	n, err := strconv.Atoi(feld)
	if err != nil {
		return 0, false
	}
	return n, true
}

func istEinzelzahl(feld string) bool {
	_, ok := einzelzahl(feld)
	return ok
}

// sternSchritt erkennt "*/n".
func sternSchritt(feld string) (int, bool) {
	rest, gefunden := strings.CutPrefix(feld, "*/")
	if !gefunden {
		return 0, false
	}
	n, err := strconv.Atoi(rest)
	if err != nil || n < 1 {
		return 0, false
	}
	return n, true
}

// bereich erkennt "a-b" mit Zahlen oder Namen.
func bereich(feld string, min, max int) (int, int, bool) {
	if strings.ContainsAny(feld, ",/") {
		return 0, 0, false
	}
	von, bis, gefunden := strings.Cut(feld, "-")
	if !gefunden {
		return 0, 0, false
	}
	a, ok := zahlOderName(von, min, max)
	if !ok {
		return 0, 0, false
	}
	b, ok := zahlOderName(bis, min, max)
	if !ok {
		return 0, 0, false
	}
	return a, b, true
}

// zahlenliste erkennt "a", "a,b,c" mit Zahlen oder Namen — ohne Bereiche und
// ohne Schrittweiten, denn dafür gibt es eigene Fälle.
func zahlenliste(feld string, min, max int) ([]int, bool) {
	if strings.ContainsAny(feld, "-/*") {
		return nil, false
	}
	var out []int
	for teil := range strings.SplitSeq(feld, ",") {
		n, ok := zahlOderName(teil, min, max)
		if !ok {
			return nil, false
		}
		out = append(out, n)
	}
	if len(out) == 0 {
		return nil, false
	}
	return out, true
}

func zahlOderName(s string, min, max int) (int, bool) {
	if n, ok := cronNamen[strings.ToLower(s)]; ok && max <= 12 {
		return n, true
	}
	n, err := strconv.Atoi(s)
	if err != nil || n < min || n > max {
		return 0, false
	}
	return n, true
}

func zahlenAufzaehlung(liste []int) string {
	var teile []string
	for _, n := range liste {
		teile = append(teile, strconv.Itoa(n))
	}
	if len(teile) == 1 {
		return teile[0]
	}
	return strings.Join(teile[:len(teile)-1], ", ") + " und " + teile[len(teile)-1]
}
