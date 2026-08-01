package acme

import "testing"

// Die Vorlage darf mehr enthalten als die Pflichtfelder — OVHs `endpoint` ist
// optional und gehört trotzdem ins Eingabefeld. Umgekehrt darf ein Pflichtfeld
// NIE aus der Vorlage fehlen: Sonst füllt die Oberfläche ein Feld vor, das der
// Server danach als unvollständig ablehnt.
func TestVorlageEnthaeltAllePflichtfelder(t *testing.T) {
	for _, a := range Anbieterliste() {
		vorlage := map[string]bool{}
		for _, f := range a.Eingabevorlage() {
			vorlage[f] = true
		}
		for _, pflicht := range a.Felder {
			if !vorlage[pflicht] {
				t.Errorf("%s: das Pflichtfeld %q fehlt in der Eingabevorlage %v",
					a.Name, pflicht, a.Eingabevorlage())
			}
		}
	}
}

// Und ohne eigene Vorlage sind es die Pflichtfelder.
func TestEingabevorlageFaelltAufDieFelderZurueck(t *testing.T) {
	a := Anbieter{Felder: []string{"eins", "zwei"}}
	if got := a.Eingabevorlage(); len(got) != 2 || got[0] != "eins" {
		t.Errorf("Eingabevorlage = %v", got)
	}
}
