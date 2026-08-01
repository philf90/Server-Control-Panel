package acme

import (
	"fmt"
	"strings"
)

// Prüfung der Namen, die in ein Zertifikat sollen.
//
// Ein eigener Prüfer neben dem in privops, und das sieht nach Doppelung aus.
// Es sind zwei Fragen:
//
//   - `privops.pruefeDomain` prüft die Namen für das **HTTP-01-Drop-in**. Dort
//     landen sie in einer nginx-Konfiguration (Injektionsstelle), und ein
//     Platzhalter ist dort sinnlos — HTTP-01 kann Wildcards nicht prüfen.
//   - Dieser Prüfer hier prüft die Namen für ein **Zertifikat**. Hier ist ein
//     Platzhalter der Regelfall, um den es geht, und die Gefahr eine andere:
//     Ein falscher Name kostet einen Fehlversuch beim CA-Server, und der zählt
//     sie.
//
// Zwei Prüfer mit zwei Begründungen sind besser als einer mit einem Schalter,
// der an einer der beiden Stellen falsch steht.

// IstWildcard sagt, ob ein Name ein Platzhalter ist.
//
// Öffentlich, weil die Entscheidung „dieser Bezug braucht DNS-01" außerhalb
// dieses Pakets nachvollziehbar sein muss — die Oberfläche sagt es dem
// Bedienenden, bevor er speichert.
func IstWildcard(name string) bool {
	return strings.HasPrefix(name, "*.")
}

// EnthaeltWildcard sagt, ob unter den Namen ein Platzhalter ist.
func EnthaeltWildcard(namen []string) bool {
	for _, n := range namen {
		if IstWildcard(n) {
			return true
		}
	}
	return false
}

// PruefeZertifikatsnamen prüft die Namensliste eines Zertifikats und gibt sie
// in Kleinschreibung zurück.
//
// Was hier NICHT geprüft wird: ob der Name auf diesen Server zeigt. Das kann
// nur die CA beantworten, und ihre Antwort ist die Prüfung selbst.
func PruefeZertifikatsnamen(namen []string) ([]string, error) {
	if len(namen) == 0 {
		return nil, fmt.Errorf("keine Domain für das Zertifikat")
	}
	aus := make([]string, 0, len(namen))
	gesehen := map[string]bool{}
	for _, roh := range namen {
		name := strings.ToLower(strings.TrimSpace(roh))
		name = strings.TrimSuffix(name, ".")
		if err := PruefeZertifikatsname(name); err != nil {
			return nil, err
		}
		// Doppelte Namen sind kein Fehler, aber sie gehören nicht zweimal in
		// den Antrag: Let's Encrypt zählt Namen, und ein doppelter zählt mit.
		if gesehen[name] {
			continue
		}
		gesehen[name] = true
		aus = append(aus, name)
	}
	return aus, nil
}

// PruefeZertifikatsname prüft einen einzelnen Namen.
//
// Ein Platzhalter ist erlaubt, aber nur in genau der ersten Ebene und nur als
// ganzes Label: `*.example.com` ja, `*.*.example.com` nein, `www*.example.com`
// nein. Das ist keine Strenge um der Strenge willen — es ist genau das, was
// RFC 6125 und Let's Encrypt annehmen, und alles darüber hinaus wäre ein
// Fehlversuch beim CA-Server statt einer Meldung im Formular.
func PruefeZertifikatsname(name string) error {
	if name == "" {
		return fmt.Errorf("leerer Domainname")
	}
	if len(name) > 253 {
		return fmt.Errorf("Domainname länger als 253 Zeichen")
	}

	rest := name
	if IstWildcard(name) {
		rest = name[2:]
		if rest == "" {
			return fmt.Errorf("%q hat keinen Namen hinter dem Platzhalter", name)
		}
		// Ein Platzhalter auf einer öffentlichen Endung wäre ein Antrag auf
		// „alles unter .de". Let's Encrypt lehnt ihn ab; hier fällt er schon
		// im Formular auf. Die Prüfung ist bewusst grob — die vollständige
		// Liste der öffentlichen Endungen wäre eine eigene Datenquelle mit
		// eigener Pflege, und die gehört nicht ins Binary.
		if !strings.Contains(rest, ".") {
			return fmt.Errorf("%q wäre ein Platzhalter für eine ganze Endung — "+
				"Let's Encrypt stellt das nicht aus", name)
		}
	}
	if strings.Contains(rest, "*") {
		return fmt.Errorf("%q: ein Platzhalter ist nur als erste Ebene erlaubt "+
			"(»*.example.com«)", name)
	}

	for _, teil := range strings.Split(rest, ".") {
		if teil == "" {
			return fmt.Errorf("%q hat einen leeren Bestandteil", name)
		}
		if len(teil) > 63 {
			return fmt.Errorf("%q: ein Bestandteil ist länger als 63 Zeichen", name)
		}
		if teil[0] == '-' || teil[len(teil)-1] == '-' {
			return fmt.Errorf("%q: ein Bestandteil beginnt oder endet mit Bindestrich", name)
		}
		for _, r := range teil {
			if (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') || r == '-' {
				continue
			}
			return fmt.Errorf("%q enthält das unzulässige Zeichen %q", name, string(r))
		}
	}
	return nil
}

// FehlendeBasis nennt die Namen, zu denen ein Platzhalter vorliegt, ohne dass
// der Name selbst im Antrag steht.
//
// `*.example.com` deckt `example.com` NICHT ab — das ist die Eigenschaft, die
// am häufigsten überrascht, und sie kostet ein zweites Zertifikat, wenn sie zu
// spät auffällt. Empirisch geprüft gegen crypto/x509, nicht angenommen.
//
// Rückgabe ist eine Auskunft und keine Ablehnung: Ein Zertifikat nur für
// `*.example.com` ist ein zulässiger Wunsch, wenn dort wirklich nichts auf der
// nackten Domain läuft.
func FehlendeBasis(namen []string) []string {
	vorhanden := map[string]bool{}
	for _, n := range namen {
		vorhanden[n] = true
	}
	var aus []string
	for _, n := range namen {
		if !IstWildcard(n) {
			continue
		}
		if basis := n[2:]; !vorhanden[basis] {
			aus = append(aus, basis)
		}
	}
	return aus
}
