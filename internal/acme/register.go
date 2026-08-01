package acme

import (
	"errors"
	"fmt"
	"sort"
	"strings"
)

// Das Register der DNS-01-Anbieter.
//
// Bis 0.5 war das ein switch mit zwei Fällen. Mit sieben wird daraus ein
// Register, und das ist mehr als Kosmetik: Ein Anbieter besteht jetzt aus
// genau drei Angaben — Name, was seine Zugangsdatei enthalten muss, und wie er
// daraus einen Setzer baut. Damit lässt sich die Liste an einer Stelle lesen,
// die Oberfläche kann sie erzeugen, statt sie zweitens zu führen, und ein
// vergessener Anbieter fällt beim Übersetzen auf statt im Betrieb.
//
// Was NICHT ins Register kommt: `hook`. Er hat keine Zugangsdatei, sondern zwei
// Programmpfade, und er ist der Ausweg für alle rund 150 Anbieter, die hier
// nicht stehen. Er bleibt deshalb ein eigener Fall.

// Anbieter beschreibt einen DNS-01-Anbieter.
type Anbieter struct {
	// Name ist der Wert in der Konfiguration.
	Name string
	// Titel ist der Name für Menschen.
	Titel string
	// Felder sind die Einträge, die die Zugangsdatei tragen muss. Leer heißt:
	// Die Datei enthält genau ein Geheimnis und darf auch nur daraus bestehen.
	Felder []string
	// Hinweis erklärt in einem Satz, woher die Zugangsdaten kommen. Er steht
	// hier und nicht in der Oberfläche, damit es eine Auslegung gibt.
	Hinweis string
	// baue erzeugt den Setzer aus der geprüften Zugangsdatei.
	baue func(z *Zugang) (dnsSetter, error)
}

// anbieter ist das Register. Reihenfolge egal — sortiert wird beim Aufzählen.
var anbieter = map[string]Anbieter{}

// registriere trägt einen Anbieter ein. Ein doppelter Name ist ein
// Programmierfehler und fällt beim Start auf, nicht im Betrieb.
func registriere(a Anbieter) {
	if _, doppelt := anbieter[a.Name]; doppelt {
		panic("dns-01-anbieter doppelt registriert: " + a.Name)
	}
	anbieter[a.Name] = a
}

// Anbieterliste nennt die eingebauten Anbieter, nach Namen sortiert.
//
// Für die Oberfläche und für Fehlermeldungen: Beide sollen dieselbe Liste
// nennen wie das Register, statt eine zweite zu führen, die irgendwann eine
// andere ist.
func Anbieterliste() []Anbieter {
	aus := make([]Anbieter, 0, len(anbieter))
	for _, a := range anbieter {
		aus = append(aus, a)
	}
	sort.Slice(aus, func(i, j int) bool { return aus[i].Name < aus[j].Name })
	return aus
}

// AnbieterBekannt sagt, ob ein Name im Register steht. `hook` zählt mit — er
// ist ein gültiger Anbieter, nur kein registrierter.
func AnbieterBekannt(name string) bool {
	if name == providerHook {
		return true
	}
	_, ok := anbieter[name]
	return ok
}

// AnbieterNamen liefert alle gültigen Werte für acme.dns01.provider, mit hook
// an erster Stelle — er ist der, der immer geht.
func AnbieterNamen() []string {
	aus := []string{providerHook}
	for _, a := range Anbieterliste() {
		aus = append(aus, a.Name)
	}
	return aus
}

// newDNSSetter baut den anbieterspezifischen Setzer aus der Konfiguration.
func newDNSSetter(opts Options) (dnsSetter, error) {
	switch opts.DNS01Provider {
	case "":
		return nil, fmt.Errorf("dns-01 verlangt einen Anbieter (%s)",
			strings.Join(AnbieterNamen(), "|"))
	case providerHook:
		// Der Hook hat keine Zugangsdatei, sondern zwei Programmpfade. Deshalb
		// steht er nicht im Register.
		if opts.HookSet == "" || opts.HookClean == "" {
			return nil, errors.New("dns-01 hook: set und clean müssen gesetzt sein")
		}
		return &hookSetter{set: opts.HookSet, clean: opts.HookClean}, nil
	}

	a, ok := anbieter[opts.DNS01Provider]
	if !ok {
		return nil, fmt.Errorf("unbekannter dns-01-anbieter %q (bekannt: %s)",
			opts.DNS01Provider, strings.Join(AnbieterNamen(), ", "))
	}
	if opts.ZugangsDatei == "" {
		return nil, fmt.Errorf("dns-01 %s: es ist keine Zugangsdatei angegeben", a.Name)
	}
	z, err := LadeZugang(opts.ZugangsDatei)
	if err != nil {
		return nil, fmt.Errorf("dns-01 %s: %w", a.Name, err)
	}
	if len(a.Felder) > 0 {
		if err := z.Pflicht(a.Felder...); err != nil {
			return nil, fmt.Errorf("dns-01 %s: %w", a.Name, err)
		}
	}
	return a.baue(z)
}
