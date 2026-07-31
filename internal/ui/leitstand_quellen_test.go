package ui

import (
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"testing"
)

// Prüfungen an den Quellen der neuen Oberfläche.
//
// Sie liegen unter web/ und werden nicht eingebettet — geprüft wird deshalb vom
// Dateisystem aus, relativ zu diesem Paket. Fehlt das Verzeichnis, überspringt
// der Test: Ein Go-Build braucht web/ nicht, und dieselbe Prüfung soll nicht die
// Bedingung erfinden, die das Projekt gerade vermeidet.
//
// Was hier geprüft wird, sind die Lektionen der Freigabekandidaten. Sie waren
// alle unsichtbar, bis jemand die Seite auf einem Telefon oder mit aktiver
// Richtlinie geöffnet hat — im Markup sahen sie richtig aus.

const webQuellen = "../../web/src"

func svelteDateien(t *testing.T) map[string]string {
	t.Helper()

	if _, err := os.Stat(webQuellen); err != nil {
		t.Skipf("%s nicht vorhanden — nichts zu prüfen", webQuellen)
	}

	aus := make(map[string]string)
	err := filepath.WalkDir(webQuellen, func(pfad string, d os.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.IsDir() || !strings.HasSuffix(pfad, ".svelte") {
			return nil
		}
		roh, err := os.ReadFile(pfad) //nolint:gosec // Pfad aus dem Projektbaum, nicht aus einer Anfrage
		if err != nil {
			return err
		}
		aus[pfad] = string(roh)
		return nil
	})
	if err != nil {
		t.Fatalf("web/src lesen: %v", err)
	}
	if len(aus) == 0 {
		t.Fatal("keine .svelte-Dateien gefunden — stimmt der Pfad noch?")
	}
	return aus
}

// ohneStilblock entfernt <style>…</style>, damit eine CSS-Eigenschaft nicht als
// Inline-Stil im Markup gilt.
func ohneStilblock(quelle string) string {
	return regexp.MustCompile(`(?s)<style>.*?</style>`).ReplaceAllString(quelle, "")
}

// Die Richtlinie des Panels verwirft Inline-Stile. Genau daran hingen die
// Auslastungsbalken der alten Übersicht dauerhaft auf 100 %: Ihre Breite kam aus
// einem style-Attribut, das der Browser wegwarf — und weil das Markup richtig
// aussah, fiel es erst im Betrieb auf (rc.5). Breite und Anteil gehören deshalb
// in ein Attribut, das kein Stil ist, etwa value am <progress>.
func TestNeueOberflaecheOhneInlineStile(t *testing.T) {
	for pfad, quelle := range svelteDateien(t) {
		markup := ohneStilblock(quelle)
		if i := strings.Index(markup, `style="`); i >= 0 {
			zeile := strings.Count(markup[:i], "\n") + 1
			t.Errorf("%s:%d trägt ein style-Attribut — die Richtlinie verwirft es", pfad, zeile)
		}
	}
}

// Unter 600 Pixeln wird jede Zeile zu einer Karte, und die Beschriftung kommt
// aus data-spalte. Fehlt sie an einer Zelle, steht in der Karte ein Wert ohne
// Namen — dieselbe Lektion wie bei den Kartentabellen der alten Oberfläche.
//
// Ausgenommen sind Zellen mit colspan: Die überspannen die ganze Zeile und
// tragen einen Satz, keinen Wert zu einer Spalte.
func TestJedeZelleTraegtIhreSpaltenbeschriftung(t *testing.T) {
	zelle := regexp.MustCompile(`<td\b[^>]*>`)

	for pfad, quelle := range svelteDateien(t) {
		for _, treffer := range zelle.FindAllString(ohneStilblock(quelle), -1) {
			if strings.Contains(treffer, "colspan") {
				continue
			}
			if !strings.Contains(treffer, "data-spalte") {
				t.Errorf("%s: Zelle ohne data-spalte — in der Kartenansicht "+
					"stünde hier ein Wert ohne Namen: %s", pfad, treffer)
			}
		}
	}
}

// Ein colspan muss alle Spalten decken. Steht er zu niedrig, rutscht die
// Leerzeile in die erste Spalte und die Tabelle sieht kaputt aus; steht er zu
// hoch, zieht er die Tabelle auf. Die alte Oberfläche hat dafür einen eigenen
// Test, weil es dort schon einmal falsch war.
// Geprüft wird je TABELLE und nicht je Datei.
//
// Der erste Anlauf nahm die Kopfzeile der ersten Tabelle einer Datei und maß
// alle colspans darin daran. Das ging, solange jede Datei genau eine Tabelle
// hatte — eine Eigenschaft, die sich so ergeben hatte und keine Regel war. Mit
// der Bestandsansicht (vier Tabellen mit fünf, vier, vier und drei Spalten)
// meldete der Test drei Fehler, von denen keiner einer war. Die Absicht bleibt
// dieselbe, nur der Zuschnitt ist genauer.
func TestKolspanDecktAlleSpaltenDerNeuenOberflaeche(t *testing.T) {
	tabelle := regexp.MustCompile(`(?s)<table\b.*?</table>`)
	kopfzeile := regexp.MustCompile(`(?s)<thead>.*?</thead>`)
	spalte := regexp.MustCompile(`<th\b`)
	kolspan := regexp.MustCompile(`colspan="(\d+)"`)

	for pfad, quelle := range svelteDateien(t) {
		markup := ohneStilblock(quelle)
		for _, tab := range tabelle.FindAllString(markup, -1) {
			kopf := kopfzeile.FindString(tab)
			if kopf == "" {
				continue
			}
			spalten := len(spalte.FindAllString(kopf, -1))
			if spalten == 0 {
				continue
			}
			for _, treffer := range kolspan.FindAllStringSubmatch(tab, -1) {
				n, err := strconv.Atoi(treffer[1])
				if err != nil {
					t.Errorf("%s: colspan %q ist keine Zahl", pfad, treffer[1])
					continue
				}
				if n != spalten {
					t.Errorf("%s: colspan=%d, die Tabelle hat aber %d Spalten", pfad, n, spalten)
				}
			}
		}
	}
}

// Zahlen dürfen nicht umbrechen: „5,5 GiB" auf zwei Zeilen ist keine Zahl mehr.
// Die Regel steckt in app.css an .zahlenspalte; dieser Test hält fest, dass sie
// dort bleibt, wenn jemand das Stylesheet aufräumt.
func TestZahlenspalteBleibtUmbruchgeschuetzt(t *testing.T) {
	roh, err := os.ReadFile(filepath.Join(webQuellen, "app.css"))
	if err != nil {
		t.Skipf("app.css nicht lesbar: %v", err)
	}
	css := string(roh)

	regel := regexp.MustCompile(`(?s)\.zahlenspalte\s*\{(.*?)\}`).FindStringSubmatch(css)
	if regel == nil {
		t.Fatal("keine Regel für .zahlenspalte im Stylesheet")
	}
	if !strings.Contains(regel[1], "nowrap") {
		t.Error("Zahlenspalten sind nicht gegen Umbruch geschützt")
	}
	if !strings.Contains(regel[1], "tabular-nums") {
		t.Error("Zahlenspalten haben keine Tabellenziffern — die Zahl springt beim " +
			"Fortschreiben seitlich, weil die 1 schmaler ist als die 8")
	}
}

// Die Kacheln stehen in einem Gitter mit minmax(0, 1fr) und nicht 1fr: Eine
// lange Zahl zieht sonst ihre Spur auf, und die Kacheln werden verschieden
// breit. Bei der alten Übersicht war es eine IPv6-Adresse, die das auslöste.
func TestKachelgitterBegrenztSeineSpuren(t *testing.T) {
	quellen := svelteDateien(t)
	uebersicht := ""
	for pfad, quelle := range quellen {
		if strings.HasSuffix(pfad, "Uebersicht.svelte") {
			uebersicht = quelle
		}
	}
	if uebersicht == "" {
		t.Skip("Uebersicht.svelte nicht gefunden")
	}

	gitter := regexp.MustCompile(`grid-template-columns:\s*([^;]+);`)
	for _, treffer := range gitter.FindAllStringSubmatch(uebersicht, -1) {
		wert := strings.TrimSpace(treffer[1])
		// Eine feste Spaltenzahl ohne minmax(0, …) ist der Fehler.
		if strings.Contains(wert, "1fr") && !strings.Contains(wert, "minmax(0") {
			t.Errorf("Gitter %q begrenzt seine Spuren nicht — eine lange Zahl zieht sie auf", wert)
		}
	}
}

// Rückfragen kommen vom Server, nicht aus dem Browser.
//
// Das ist die Lektion aus 0.3.0-rc.5, und sie ist teuer bezahlt: Dreizehn
// Rückfragen standen in onsubmit-Attributen, die Richtlinie des Panels verwarf
// jede einzelne, und die Aktion lief nach einem Klick. Ein window.confirm hätte
// dort funktioniert — aber es ist eine Rückfrage, die nur der Browser kennt: Ein
// selbstgebautes POST kommt daran vorbei, und der Text der Frage stünde an einer
// zweiten Stelle neben dem Handler, der sie erzwingt.
//
// Der Weg des Projekts ist deshalb: 409 mit dem Text der Frage, Dialog, erneutes
// POST mit `bestaetigt`. Dieser Test hält fest, dass niemand die Abkürzung nimmt.
func TestKeineRueckfrageImBrowser(t *testing.T) {
	verboten := []struct{ muster, warum string }{
		{`window.confirm(`, "eine Rückfrage, die nur der Browser kennt — ein POST kommt daran vorbei"},
		{`confirm(`, "siehe window.confirm: Die Rückfrage gehört in den Handler"},
		{`window.alert(`, "eine Meldung, die die Seite anhält, statt sie an ihrer Stelle zu zeigen"},
	}

	for pfad, quelle := range svelteDateien(t) {
		for _, v := range verboten {
			if strings.Contains(quelle, v.muster) {
				t.Errorf("%s enthält %s — %s", pfad, v.muster, v.warum)
			}
		}
	}
}

// Der Rückfrage-Dialog ist ein echtes <dialog> mit ::backdrop.
//
// Ein <div> mit Schleier nachzubauen heißt, Fokusfang, oberste Ebene und Escape
// selbst zu bauen — und genau dabei geht die Tastaturbedienung still verloren.
// Der Test prüft, dass die Komponente das Element benutzt und nicht ersetzt.
func TestRueckfrageBenutztDasDialogElement(t *testing.T) {
	quellen := svelteDateien(t)
	var rueckfrage, pfad string
	for p, quelle := range quellen {
		if strings.HasSuffix(p, "Rueckfrage.svelte") {
			rueckfrage, pfad = quelle, p
		}
	}
	if rueckfrage == "" {
		t.Skip("Rueckfrage.svelte nicht gefunden")
	}

	if !strings.Contains(rueckfrage, "<dialog") {
		t.Errorf("%s baut keinen <dialog> — Fokusfang und Escape müssten dann von Hand kommen", pfad)
	}
	if !strings.Contains(rueckfrage, "showModal()") {
		t.Errorf("%s öffnet den Dialog nicht mit showModal() — ohne das liegt er nicht "+
			"in der obersten Ebene und fängt den Fokus nicht", pfad)
	}
	// Escape muss abgefangen werden: Der Browser schließt den Dialog sonst,
	// während die Komponente eingehängt bleibt — der Knopf wirkt danach kaputt.
	if !strings.Contains(rueckfrage, "oncancel") {
		t.Errorf("%s behandelt Escape nicht (oncancel) — der Dialog wäre danach "+
			"geschlossen, die Komponente aber noch da", pfad)
	}
}

// TestSPAPfadeStimmenMitDemRouterZusammen hält zwei Listen zusammen.
//
// Seit dem Umschalten ist `GET /` der allgemeine Rückfall des Multiplexers. Damit
// nicht jede erdachte Adresse mit 200 beantwortet wird, prüft der Server den
// ersten Pfadteil gegen `spaSeiten` (internal/httpd/handlers_v2.go). Dieselbe
// Liste steht im Router der Oberfläche (web/src/lib/weg.svelte.ts) — als
// `gebauteSeiten` und `angekuendigt`.
//
// Zwei Listen laufen auseinander, und die Folge wäre unangenehm asymmetrisch: Ein
// neues Modul, das nur im Browser steht, bekommt beim Neuladen einen 404 — die
// Seite funktioniert, solange man sie anklickt, und ist nach F5 weg. Genau die
// Sorte Fehler, die niemand beim Bauen bemerkt.
func TestSPAPfadeStimmenMitDemRouterZusammen(t *testing.T) {
	router := lesen(t, "../../web/src/lib/weg.svelte.ts")
	server := lesen(t, "../httpd/handlers_v2.go")

	// Die Kennungen aus den beiden Abbildungen des Routers.
	kennungen := map[string]bool{}
	for _, block := range []string{"gebauteSeiten", "angekuendigt"} {
		i := strings.Index(router, block+": Record<string, string> = {")
		if i < 0 {
			i = strings.Index(router, block+": Record<string, Seite> = {")
		}
		if i < 0 {
			t.Fatalf("die Abbildung %s steht nicht in weg.svelte.ts", block)
		}
		// Hinter die öffnende Klammer: Sonst wäre die Deklarationszeile selbst der
		// erste „Eintrag", und der Test prüfte den Namen der Abbildung.
		auf := strings.Index(router[i:], "{")
		if auf < 0 {
			t.Fatalf("die Abbildung %s hat keine öffnende Klammer", block)
		}
		rest := router[i+auf+1:]
		ende := strings.Index(rest, "}")
		if ende < 0 {
			t.Fatalf("die Abbildung %s ist nicht geschlossen", block)
		}
		for _, zeile := range strings.Split(rest[:ende], "\n") {
			zeile = strings.TrimSpace(zeile)
			schluessel, _, gefunden := strings.Cut(zeile, ":")
			if !gefunden || strings.HasPrefix(zeile, "//") || schluessel == "" {
				continue
			}
			if strings.Contains(schluessel, " ") || strings.Contains(schluessel, "=") {
				continue
			}
			kennungen[strings.Trim(schluessel, `"'`)] = true
		}
	}
	if len(kennungen) < 10 {
		t.Fatalf("nur %d Kennungen aus dem Router gelesen — die Zerlegung passt nicht "+
			"mehr zur Datei", len(kennungen))
	}

	// Jede davon muss der Server kennen, sonst ist die Seite nach einem Neuladen
	// weg.
	for kennung := range kennungen {
		if !strings.Contains(server, `"`+kennung+`":`) {
			t.Errorf("der Router kennt die Seite %q, spaSeiten in handlers_v2.go nicht — "+
				"ein Neuladen dieser Seite endet mit 404", kennung)
		}
	}
}

// lesen holt eine Quelldatei. Eigene Hilfe, weil svelteDateien nur .svelte
// einsammelt und hier eine .ts und eine .go gebraucht werden.
func lesen(t *testing.T, pfad string) string {
	t.Helper()
	roh, err := os.ReadFile(pfad)
	if err != nil {
		t.Fatalf("%s: %v", pfad, err)
	}
	return string(roh)
}
