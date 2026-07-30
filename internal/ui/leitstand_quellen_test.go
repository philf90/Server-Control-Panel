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
func TestKolspanDecktAlleSpaltenDerNeuenOberflaeche(t *testing.T) {
	kopfzeile := regexp.MustCompile(`(?s)<thead>.*?</thead>`)
	spalte := regexp.MustCompile(`<th\b`)
	kolspan := regexp.MustCompile(`colspan="(\d+)"`)

	for pfad, quelle := range svelteDateien(t) {
		markup := ohneStilblock(quelle)
		kopf := kopfzeile.FindString(markup)
		if kopf == "" {
			continue
		}
		spalten := len(spalte.FindAllString(kopf, -1))
		if spalten == 0 {
			continue
		}
		for _, treffer := range kolspan.FindAllStringSubmatch(markup, -1) {
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
