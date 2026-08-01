package acme

import "strings"

// Gemeinsame Kleinigkeiten der DNS-Anbieter.
//
// Sie stehen zusammen, weil sie sonst in jedem Anbieter noch einmal stünden —
// und weil zwei von ihnen Fehler abfangen, die man genau einmal macht und dann
// nie wieder findet.

// relativZu macht aus einem vollständigen Recordnamen den zur Zone relativen.
//
// Die meisten APIs wollen `_acme-challenge` und nicht
// `_acme-challenge.example.com`. Wer den vollen Namen schickt, bekommt einen
// Record namens `_acme-challenge.example.com.example.com` — er entsteht ohne
// Fehlermeldung, und die Prüfung findet ihn trotzdem nie. Das ist die Sorte
// Fehler, die man an der API nicht sieht, sondern nur am Fehlschlag danach.
//
// Ist der Name die Zone selbst, ist der relative Name "@" — so schreibt man den
// Ursprung einer Zone seit jeher, und alle hier bedienten APIs verstehen ihn.
func relativZu(record, zone string) string {
	name := strings.TrimSuffix(strings.ToLower(record), ".")
	z := strings.TrimSuffix(strings.ToLower(zone), ".")
	switch {
	case name == z:
		return "@"
	case strings.HasSuffix(name, "."+z):
		return strings.TrimSuffix(name, "."+z)
	default:
		// Passt nicht zur Zone — dann unverändert lassen und die API
		// entscheiden. Hier zu raten wäre schlimmer als die Fehlermeldung des
		// Anbieters.
		return name
	}
}

// gleicherTXTWert vergleicht zwei TXT-Werte.
//
// Manche APIs geben den Wert mit Anführungszeichen zurück, so wie er in der
// Zonendatei steht (`"abc123"`), andere ohne. Beim LÖSCHEN entscheidet dieser
// Vergleich, ob der eigene Record gefunden wird — und ein nicht gefundener
// Record bleibt stehen. Bei einem Wildcard-Zertifikat mit zwei
// Autorisierungen unter demselben Namen ist das kein Schönheitsfehler: Der
// nächste Bezug findet dann fremde Werte vor.
func gleicherTXTWert(a, b string) bool {
	return strings.Trim(a, `"`) == strings.Trim(b, `"`)
}

// gekuerzt hält eine Fehlermeldung lesbar.
//
// Ein Anbieter antwortet mit einer kurzen JSON-Meldung; ein Proxy oder eine
// Zwischenstelle davor womöglich mit einer ganzen HTML-Fehlerseite. Die gehört
// nicht ungekürzt in eine Zeile, die jemand lesen soll.
func gekuerzt(roh []byte) string {
	text := strings.TrimSpace(string(roh))
	if len(text) > 300 {
		return text[:300] + "…"
	}
	return text
}
