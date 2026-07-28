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
	// Ohne diese Regel klappt die Navigation schmal nicht mehr zu. Seit der
	// Umstellung auf die Seitenleiste blendet der Umschalter .side-body ein
	// und aus, nicht mehr eine .menu-Liste.
	if !strings.Contains(css, ".nav-toggle:not(:checked) ~ .side-body") {
		t.Error("die Umschaltung der Navigation fehlt")
	}
}

// TestJedesLabelHatSeinFeld: Eine Beschriftung mit for="x" braucht ein Feld mit
// id="x" in derselben Vorlage.
//
// Der Anlass ist ein Befund auf der Seite Systembenutzer: Dort stand hinter dem
// </form> ein <label for="ssh_key"> für Screenreader — das Feld dazu gab es
// nicht. Der Handler nahm "ssh_key" seit dem ersten Tag an und hätte den
// Schlüssel beim Anlegen abgelegt; erreichbar war die Angabe nie, und der
// Hinweistext beschrieb eine Eingabe, die niemand machen konnte.
//
// Breit sieht so etwas richtig aus: Ein Label, das ins Leere zeigt, ist
// unsichtbar. Deshalb dieser Test.
func TestJedesLabelHatSeinFeld(t *testing.T) {
	forRe := regexp.MustCompile(`\bfor="([^"]+)"`)
	idRe := regexp.MustCompile(`\bid="([^"]+)"`)

	dateien, err := templateFS.ReadDir("templates")
	if err != nil {
		t.Fatal(err)
	}
	geprueft := 0
	for _, d := range dateien {
		raw, err := templateFS.ReadFile("templates/" + d.Name())
		if err != nil {
			t.Fatal(err)
		}
		inhalt := string(raw)

		ids := make(map[string]bool)
		for _, m := range idRe.FindAllStringSubmatch(inhalt, -1) {
			ids[m[1]] = true
		}
		for _, m := range forRe.FindAllStringSubmatch(inhalt, -1) {
			geprueft++
			if !ids[m[1]] {
				t.Errorf("%s: <label for=%q> hat kein Feld mit dieser id — "+
					"die Beschriftung zeigt ins Leere, und wer sie liest, sucht eine "+
					"Eingabe, die es nicht gibt", d.Name(), m[1])
			}
		}
	}
	if geprueft == 0 {
		t.Error("keine einzige Beschriftung mit for-Attribut gefunden — der Test prüft nichts")
	}
	t.Logf("%d Beschriftungen geprüft", geprueft)
}

// TestSprungzieleExistieren: Ein Anker auf "#x" braucht ein Element mit id="x"
// in derselben Vorlage. Dieselbe Art Fehler wie eine Beschriftung ins Leere —
// nur merkt man sie beim Klicken, weil nichts geschieht.
func TestSprungzieleExistieren(t *testing.T) {
	ankerRe := regexp.MustCompile(`href="[^"]*#([a-zA-Z0-9_-]+)"`)
	idRe := regexp.MustCompile(`\bid="([^"]+)"`)

	dateien, err := templateFS.ReadDir("templates")
	if err != nil {
		t.Fatal(err)
	}
	geprueft := 0
	for _, d := range dateien {
		raw, err := templateFS.ReadFile("templates/" + d.Name())
		if err != nil {
			t.Fatal(err)
		}
		inhalt := string(raw)

		ids := make(map[string]bool)
		for _, m := range idRe.FindAllStringSubmatch(inhalt, -1) {
			ids[m[1]] = true
		}
		for _, m := range ankerRe.FindAllStringSubmatch(inhalt, -1) {
			geprueft++
			if !ids[m[1]] {
				t.Errorf("%s: der Anker #%s hat kein Ziel in dieser Vorlage", d.Name(), m[1])
			}
		}
	}
	if geprueft == 0 {
		t.Error("kein einziger Anker gefunden — der Test prüft nichts")
	}
	t.Logf("%d Anker geprüft", geprueft)
}

// TestZeilenformularFuelltDieBreite hält fest, warum .row-form kein Raster mehr
// ist: Mit "repeat(auto-fit, minmax(12rem, 1fr))" bekam die Spalte des Knopfes
// denselben Anteil wie ein Eingabefeld, und rechts neben dem Knopf blieben gut
// 130 Pixel leer — die Karte sah aus, als sei sie zu breit für ihren Inhalt.
func TestZeilenformularFuelltDieBreite(t *testing.T) {
	raw, err := staticFS.ReadFile("static/app.css")
	if err != nil {
		t.Fatal(err)
	}
	css := string(raw)

	if strings.Contains(css, "grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr))") {
		t.Error(".row-form verteilt wieder gleich große Spuren — die Spur des Knopfes bleibt halb leer")
	}
	if !strings.Contains(css, ".row-form > .submit { flex: 0 0 auto; }") {
		t.Error("der Knopf nimmt nicht mehr nur seine eigene Breite")
	}
	// Ohne min-width: 0 schiebt ein Feld mit langem Vorgabewert die Zeile über
	// die Karte hinaus — ein Flex-Element schrumpft von sich aus nicht unter
	// seine inhaltsbedingte Mindestbreite.
	if !strings.Contains(css, ".row-form > * { flex: 1 1 12rem; min-width: 0; }") {
		t.Error("die Felder teilen sich den Rest der Zeile nicht mehr")
	}
}

// TestSparklineOhneVerzerrtenStrich hält fest, warum die Verläufe in den
// Telemetriekacheln unsauber aussahen.
//
// Der viewBox ist 100 Einheiten breit und wird mit preserveAspectRatio="none"
// auf die Kachelbreite von rund 270 Pixeln gezogen — waagerecht mit Faktor 2,7,
// senkrecht mit 1. Ohne vector-effect: non-scaling-stroke wird die Strichstärke
// mitgezogen: Steile Stücke waren über 4 Pixel breit, flache blieben bei 1,6,
// und der Endpunkt (damals ein <circle>) kam als liegende Ellipse heraus. Der
// Verlauf sah aus, als würde er auslaufen.
func TestSparklineOhneVerzerrtenStrich(t *testing.T) {
	raw, err := staticFS.ReadFile("static/app.css")
	if err != nil {
		t.Fatal(err)
	}
	css := string(raw)

	if !strings.Contains(css, "vector-effect: non-scaling-stroke") {
		t.Error("app.css zieht die Strichstärke der Verläufe wieder mit der Breite mit")
	}

	dashboard, err := templateFS.ReadFile("templates/dashboard.html")
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(dashboard), "<circle") {
		t.Error("der Endpunkt ist wieder ein <circle> — die waagerechte Streckung macht daraus eine Ellipse")
	}
	// Ohne die Messpunkte im data-Attribut gäbe es keinen Wert unter dem
	// Zeiger: Ein Inline-Skript, das sie mitbrächte, verwirft die CSP.
	if !strings.Contains(string(dashboard), "data-spark=") {
		t.Error("die Messpunkte der Verläufe fehlen im Markup")
	}
}

// TestDateisystemeSindAufklappbar: Die weiteren Einhängepunkte einer Platte sind
// eigene Zeilen, eingeklappt. Der Umschalter ist eine Checkbox, damit das ohne
// JavaScript geht — und weil die Folgezeilen keine Geschwister der Checkbox
// sind, braucht es :has() auf dem gemeinsamen <tbody>.
func TestDateisystemeSindAufklappbar(t *testing.T) {
	raw, err := staticFS.ReadFile("static/app.css")
	if err != nil {
		t.Fatal(err)
	}
	css := string(raw)

	for _, regel := range []string{
		".fstab tr.fs-sub { display: none; }",
		"tbody:has(.fs-switch:checked)",
	} {
		if !strings.Contains(css, regel) {
			t.Errorf("app.css fehlt %q — die Liste klappt nicht mehr auf", regel)
		}
	}
	// Ein <tbody> je Platte heißt: Die letzte Zeile einer Gruppe darf ihre
	// Trennlinie nicht verlieren, sonst sehen zwei Platten wie eine aus.
	if !strings.Contains(css, "tbody:last-of-type tr:last-child td { border-bottom: none; }") {
		t.Error("die Trennlinie zwischen zwei Dateisystemgruppen fehlt")
	}

	dashboard, err := templateFS.ReadFile("templates/dashboard.html")
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(dashboard), "auch-an") {
		t.Error("der Hinweis mit title-Attribut ist zurück — er kann keine Zahlen tragen")
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
//
// Seit dem Leitstand trägt die Übersicht CPU und Arbeitsspeicher als
// Telemetriekacheln mit Verlauf, nicht mehr als Balken; ein Balken bleibt bei
// den Dateisystemen, wo die Auslastung je Zeile zählt. Entscheidend ist nicht
// die Anzahl, sondern dass der Balken seinen Wert in einem Attribut trägt und
// keine Inline-Breite gesetzt wird.
func TestBalkenOhneInlineBreite(t *testing.T) {
	raw, err := templateFS.ReadFile("templates/dashboard.html")
	if err != nil {
		t.Fatal(err)
	}
	dashboard := string(raw)

	if strings.Count(dashboard, "<progress") < 1 {
		t.Error("kein <progress>-Balken in der Übersicht — die Auslastung der Dateisysteme fehlt")
	}
	// $fs statt . seit dem Aufklappen: Die Zeile steht in zwei verschachtelten
	// Bereichen, weil die weiteren Einhängepunkte einer Platte deren Zahlen
	// zeigen.
	if !strings.Contains(dashboard, "value=\"{{pct $fs.UsedPct}}\"") {
		t.Error("der Dateisystembalken trägt seinen Wert nicht mehr in einem value-Attribut")
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

// TestJedesNeuePasswortfeldZeigtDieRichtlinie: Wo ein neues Passwort gewählt
// wird, müssen die Bedingungen dabeistehen.
//
// Vier Seiten verlangen eines — Ersteinrichtung, Kontoseite, erzwungener
// Wechsel und der Weg über einen Passkey. Vorher stand unter dem Feld ein Satz
// ("Mindestens 12 Zeichen"), und die übrigen Regeln erfuhr man erst durch eine
// Ablehnung. Der Test hält fest, dass keine dieser Seiten die Anzeige verliert:
// Eine Regel, die man nicht lesen kann, ist eine Falle.
func TestJedesNeuePasswortfeldZeigtDieRichtlinie(t *testing.T) {
	seiten := []string{"setup.html", "account.html", "password-change.html", "forgot-new.html"}

	for _, name := range seiten {
		raw, err := templateFS.ReadFile("templates/" + name)
		if err != nil {
			t.Fatal(err)
		}
		inhalt := string(raw)

		if !strings.Contains(inhalt, `type="password"`) {
			t.Errorf("%s hat kein Passwortfeld mehr — dann gehört sie nicht in diese Liste", name)
			continue
		}
		if !strings.Contains(inhalt, `{{template "passwortpruefung"`) {
			t.Errorf("%s zeigt die Passwortrichtlinie nicht", name)
		}
		// Die alte Fassung nannte die Mindestlänge im Fließtext. Bleibt sie
		// stehen, gibt es zwei Quellen für dieselbe Zahl.
		if strings.Contains(inhalt, "Mindestens 12 Zeichen") {
			t.Errorf("%s schreibt die Mindestlänge im Text fest — sie kommt aus internal/auth", name)
		}
	}
}

// Die Prüfliste ist eine Übersetzung von auth.CheckPasswordPolicy. Die Zahlen
// dürfen im Skript nicht ein zweites Mal stehen, sonst laufen Anzeige und
// Prüfung auseinander.
func TestPasswortSkriptSchreibtKeineZahlenFest(t *testing.T) {
	raw, err := staticFS.ReadFile("static/passwort.js")
	if err != nil {
		t.Fatal(err)
	}
	js := string(raw)

	// Die Werte kommen aus den data-Attributen, die aus auth.Policy() gerendert
	// werden (dataset.pwMin / dataset.pwMax).
	if !strings.Contains(js, "pwMin") {
		t.Error("passwort.js liest die Mindestlänge nicht aus dem Markup")
	}
	if !strings.Contains(js, "pwMax") {
		t.Error("passwort.js liest die Obergrenze nicht aus dem Markup")
	}
	if strings.Contains(js, "1024") || strings.Contains(js, ">= 12") {
		t.Error("passwort.js nennt eine Zahl der Richtlinie selbst — sie gehört ins Markup")
	}
}
