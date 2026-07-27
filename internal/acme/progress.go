package acme

import "fmt"

// Progress nimmt den Verlauf eines Bezugs entgegen, Schritt für Schritt.
//
// Warum eine eigene Schnittstelle und nicht der vorhandene slog.Logger: Dessen
// Zeilen sind für journalctl geschrieben — knapp, in Schlüssel-Wert-Form, an
// Betreiber gerichtet, die ohnehin auf der Maschine sind. Wer im Panel auf
// "Jetzt beziehen" drückt, braucht etwas anderes: ganze Sätze, in der
// Reihenfolge des Ablaufs, und vor allem ein Lebenszeichen währenddessen. Ein
// DNS-01-Durchlauf wartet bis zu zwei Minuten auf die Ausbreitung des
// TXT-Records und danach unbestimmt lange auf Let's Encrypt; ohne Meldungen
// steht die Seite fünf Minuten still, und ein Fehlschlag kommt als ein einziger
// zusammengefalteter Satz zurück, aus dem nicht hervorgeht, ob der DNS-Anbieter,
// die Ausbreitung oder die CA das Problem war.
//
// Was hier nie hineingehört: der Challenge-Wert, der Kontoschlüssel und die
// Zugangsdaten des DNS-Anbieters. Die Zeilen gehen in den Browser und bleiben
// im Puffer stehen. Ein Test wacht darüber (TestProgressOhneGeheimnisse).
type Progress interface {
	// Begin meldet den Anfang eines Bezugs für diese Namen.
	Begin(domains []string)
	// Step meldet einen abgeschlossenen Zwischenschritt.
	Step(text string)
	// End meldet das Ende. err ist nil, wenn das Zertifikat liegt.
	End(err error)
}

// reporter ist die Fassung für den Aufrufer: gleiche Bedeutung, aber ohne
// Prüfung auf nil an jeder einzelnen Stelle. Ohne Progress ist der Bezug ein
// stiller Vorgang wie zuvor — der Manager läuft auch ohne Oberfläche, etwa
// beim ersten Start des Dienstes.
type reporter struct{ p Progress }

func (r reporter) begin(domains []string) {
	if r.p != nil {
		r.p.Begin(domains)
	}
}

func (r reporter) step(format string, args ...any) {
	if r.p != nil {
		r.p.Step(fmt.Sprintf(format, args...))
	}
}

func (r reporter) end(err error) {
	if r.p != nil {
		r.p.End(err)
	}
}
