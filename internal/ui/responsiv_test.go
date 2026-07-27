package ui

import (
	"regexp"
	"strings"
	"testing"
)

// Auf schmalen Bildschirmen wird jede Zeile einer Tabelle mit der Klasse
// "cards" zu einer Karte, und jede Zelle holt ihre Beschriftung aus
// data-label. Fehlt sie, steht dort ein nackter Wert ohne Angabe, wozu er
// gehört — "/dev/vda3" allein sagt nichts.
//
// Das fällt am Schreibtisch niemandem auf: Breit sieht die Tabelle richtig
// aus. Deshalb dieser Test statt eines guten Vorsatzes.

var (
	tabellenRe = regexp.MustCompile(`(?s)<table[^>]*class="[^"]*\bcards\b[^"]*"[^>]*>.*?</table>`)
	tbodyRe    = regexp.MustCompile(`(?s)<tbody[^>]*>.*?</tbody>`)
	theadRe    = regexp.MustCompile(`(?s)<thead>.*?</thead>`)
	zeileRe    = regexp.MustCompile(`(?s)<tr[^>]*>.*?</tr>`)
	zelleRe    = regexp.MustCompile(`<td[^>]*>`)
	spalteRe   = regexp.MustCompile(`(?s)<th[^>]*>(.*?)</th>`)
	markupRe   = regexp.MustCompile(`<[^>]+>|\{\{.*?\}\}`)
)

func TestKartentabellenHabenBeschriftungen(t *testing.T) {
	dateien, err := templateFS.ReadDir("templates")
	if err != nil {
		t.Fatal(err)
	}

	gepruefte := 0
	for _, d := range dateien {
		raw, err := templateFS.ReadFile("templates/" + d.Name())
		if err != nil {
			t.Fatal(err)
		}

		for _, tabelle := range tabellenRe.FindAllString(string(raw), -1) {
			gepruefte++

			// Wie viele Spalten hat die Kopfzeile, und welche davon sind
			// überhaupt benannt? Eine leere Überschrift (die Aktionsspalte)
			// braucht keine Beschriftung.
			var benannt []bool
			for _, m := range spalteRe.FindAllStringSubmatch(theadRe.FindString(tabelle), -1) {
				text := strings.TrimSpace(markupRe.ReplaceAllString(m[1], ""))
				benannt = append(benannt, text != "")
			}
			if len(benannt) == 0 {
				t.Errorf("%s: eine Tabelle mit der Klasse \"cards\" ohne Kopfzeile — "+
					"dann gibt es nichts, woraus die Beschriftungen kommen könnten", d.Name())
				continue
			}

			body := tbodyRe.FindString(tabelle)
			for _, zeile := range zeileRe.FindAllString(body, -1) {
				for i, zelle := range zelleRe.FindAllString(zeile, -1) {
					switch {
					case strings.Contains(zelle, "colspan"):
						// Eine Zeile, die nur "keine Einträge" sagt.
						continue
					case i >= len(benannt) || !benannt[i]:
						continue
					case strings.Contains(zelle, "data-label="):
						continue
					}
					t.Errorf("%s: Zelle %d in %q hat kein data-label — "+
						"auf dem Telefon steht der Wert dann ohne Bezeichnung da",
						d.Name(), i+1, strings.TrimSpace(zelle))
				}
			}
		}
	}

	if gepruefte == 0 {
		t.Error("keine einzige Tabelle mit der Klasse \"cards\" gefunden — " +
			"entweder ist der Test kaputt oder das schmale Layout ist es")
	}
	t.Logf("%d Kartentabellen geprüft", gepruefte)
}

// TestStylesheetHatBreakpoints hält den Befund fest, der rc.4 ausgelöst hat:
// Bis rc.3 gab es genau einen @media-Block, und der galt dem Dunkelmodus.
func TestStylesheetHatBreakpoints(t *testing.T) {
	raw, err := staticFS.ReadFile("static/app.css")
	if err != nil {
		t.Fatal(err)
	}
	css := string(raw)

	for _, breite := range []string{"max-width: 900px"} {
		if !strings.Contains(css, breite) {
			t.Errorf("app.css hat keinen Breakpoint für %q", breite)
		}
	}
	// Ohne diese Regel klappt die Navigation schmal nicht mehr zu.
	if !strings.Contains(css, ".nav-toggle:not(:checked) ~ .menu") {
		t.Error("die Umschaltung der Navigation fehlt")
	}
}

// TestKeineInlineStyles: Die Content-Security-Policy des Panels erlaubt kein
// style-Attribut ("style-src 'self'" ohne unsafe-inline). Der Browser verwirft
// so ein Attribut stillschweigend — die Seite sieht dann nicht kaputt aus,
// sondern nur falsch.
//
// Genau das war bei den Auslastungsbalken der Fall: style="width:38%" kam nie
// an, die Balken standen immer auf 100 %. Bei CPU und Arbeitsspeicher fiel es
// nicht auf, weil live.js die Breite kurz darauf über das CSSOM nachzog; die
// Balken der Dateisysteme zog niemand nach.
func TestKeineInlineStyles(t *testing.T) {
	dateien, err := templateFS.ReadDir("templates")
	if err != nil {
		t.Fatal(err)
	}
	for _, d := range dateien {
		raw, err := templateFS.ReadFile("templates/" + d.Name())
		if err != nil {
			t.Fatal(err)
		}
		if strings.Contains(string(raw), "style=\"") || strings.Contains(string(raw), "style='") {
			t.Errorf("%s enthält ein style-Attribut — die CSP verwirft es, "+
				"die Angabe kommt beim Nutzer nie an", d.Name())
		}
	}
}

// TestBalkenOhneInlineBreite hält fest, wodurch der Inline-Style ersetzt
// wurde: ein <progress>, das seinen Wert in einem Attribut trägt.
func TestBalkenOhneInlineBreite(t *testing.T) {
	raw, err := templateFS.ReadFile("templates/dashboard.html")
	if err != nil {
		t.Fatal(err)
	}
	dashboard := string(raw)

	if strings.Count(dashboard, "<progress") != 3 {
		t.Errorf("%d Balken, erwartet 3 (CPU, Arbeitsspeicher, Dateisysteme)",
			strings.Count(dashboard, "<progress"))
	}
	if strings.Contains(dashboard, "data-live-width") {
		t.Error("data-live-width setzte eine Breite — der Balken trägt jetzt einen Wert")
	}

	css, err := staticFS.ReadFile("static/app.css")
	if err != nil {
		t.Fatal(err)
	}
	// Ohne die herstellereigenen Pseudoelemente bleibt der Balken ungefärbt.
	for _, teil := range []string{"::-webkit-progress-value", "::-moz-progress-bar"} {
		if !strings.Contains(string(css), teil) {
			t.Errorf("app.css färbt %s nicht ein", teil)
		}
	}
}
