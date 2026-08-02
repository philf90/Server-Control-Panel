package ui

import (
	"go/ast"
	"go/parser"
	"go/token"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

// Die Wortwahl der Oberfläche, mechanisch geprüft.
//
// Die Vorgabe steht in docs/19-sprache-der-oberflaeche.md: Texte des Panels sind
// technisch, und das etablierte englische Fachwort ist dem gesuchten deutschen
// vorzuziehen. Dieser Test ist die Sperrklinke dazu.
//
// Warum überhaupt eine Prüfung und nicht bloß eine Regel im Beitragsleitfaden:
// Diese Wörter sind einzeln harmlos und fallen im Diff nicht auf. Sie kommen
// nicht als Entscheidung ins Projekt, sondern beim Formulieren — man schreibt
// „einspielen", weil der Satz sonst zweimal „installieren" enthielte. Erst in
// der Summe entsteht daraus eine Oberfläche, die klingt wie ein Handbuch von
// 1994. Genau so ist es passiert: „nginx einspielen" stand auf einem Knopf,
// bis es jemandem auf einem Bildschirmfoto auffiel.
//
// Geprüft werden Zeichenketten, nicht Bezeichner und nicht Kommentare:
//
//   - In web/src/lib/texte.ts die Werte, nicht die Schlüssel. `einspielenTitel`
//     als Schlüssel ist kein Text, den jemand liest, und ihn umzubenennen wäre
//     Bewegung ohne Gewinn — beim JSON-Feld `einspielbar` wäre es sogar eine
//     Änderung an der Schnittstelle.
//   - In Go nur ast.BasicLit vom Typ STRING. Der Parser trennt sauber, was ein
//     Kommentar mit Begründung ist und was ein Satz, der im Browser landet.
//     Das ist der Grund, warum hier go/ast steht und kein grep: Die Kommentare
//     dieses Projekts erzählen die Geschichte der Wörter mit, und eine
//     Textsuche verböte ihnen, das alte Wort überhaupt zu nennen.
//
// Neue Einträge gehören zusammen mit der Zeile in docs/19 hierher. Ein Wort auf
// dieser Liste ist kein Stilurteil über die deutsche Sprache — es ist ein Wort,
// das im Panel schon einmal falsch stand.
var verbrauchteWoerter = []struct {
	muster         string // als regulärer Ausdruck, mit Wortgrenzen wo nötig
	stattVerwenden string
}{
	{`(?i)\beinspiel`, `"installieren" — das Wort kommt von Tonbändern`},
	{`(?i)\beingespielt\b`, `"installiert"`},
	{`\bFassung(en)?\b`, `"Version"`},
	{`\bRückweg(e|s)?\b`, `"Rollback" oder "zurücksetzen"`},
	{`\bFläche(n)?\b`, `"Bereich" — "Oberfläche" bleibt davon unberührt`},
	{`\bHandgriff(e|s)?\b`, `"Aktion"`},
	{`\bAnmeldeschale\b`, `"Login-Shell"`},
	{`\bKrumen\b`, `"Pfadleiste"`},
	{`\bBaucache\b`, `"Build-Cache"`},
	{`\bWirts(pfad|dateisystem)\b`, `"Host-Pfad" bzw. "Dateisystem des Hosts"`},
	{`\bGegenstelle\b`, `"Upstream-Adresse"`},
	{`\bSpitzenreiter\b`, `eine Angabe, wonach sortiert wird`},
	{`\bwegräum`, `"entfernen"`},
	{`\bgeglückt\b`, `"erfolgreich"`},
	{`\bPlatte\b`, `"Datenträger" — auf einem VPS liegt keine Platte`},
}

// gefundeneWoerter liefert die Treffer der Liste in einem Text.
func gefundeneWoerter(text string) []string {
	var aus []string
	for _, w := range verbrauchteWoerter {
		if regexp.MustCompile(w.muster).MatchString(text) {
			aus = append(aus, w.muster+" → "+w.stattVerwenden)
		}
	}
	return aus
}

// texteZeilen liefert die Zeilen aus texte.ts, die einen sichtbaren Text tragen.
//
// Die Trennung ist grob und darf es sein: Alles rechts des ersten Doppelpunkts,
// aber nur, wenn dort ein Anführungszeichen oder ein Backtick steht. Eine Zeile
// ohne Zeichenkette ist ein Schlüssel, eine Klammer oder ein Kommentar.
func texteZeilen(t *testing.T) map[int]string {
	t.Helper()

	pfad := filepath.Join(webQuellen, "lib", "texte.ts")
	roh, err := os.ReadFile(pfad) //nolint:gosec // Pfad aus dem Projektbaum, nicht aus einer Anfrage
	if err != nil {
		t.Skipf("%s nicht lesbar — nichts zu prüfen: %v", pfad, err)
	}

	aus := map[int]string{}
	for i, zeile := range strings.Split(string(roh), "\n") {
		beschnitten := strings.TrimSpace(zeile)
		if strings.HasPrefix(beschnitten, "//") || strings.HasPrefix(beschnitten, "*") {
			continue
		}
		// Der Wert beginnt beim ersten Anführungszeichen oder Backtick. Steht
		// keines in der Zeile, trägt sie keinen Text.
		start := strings.IndexAny(zeile, "\"`")
		if start < 0 {
			continue
		}
		aus[i+1] = zeile[start:]
	}
	return aus
}

// Die Oberfläche selbst.
func TestOberflaechenTexteBleibenTechnisch(t *testing.T) {
	if _, err := os.Stat(webQuellen); err != nil {
		t.Skipf("%s nicht vorhanden — nichts zu prüfen", webQuellen)
	}

	for zeile, text := range texteZeilen(t) {
		for _, treffer := range gefundeneWoerter(text) {
			t.Errorf("web/src/lib/texte.ts:%d trägt ein verbrauchtes Wort (%s):\n\t%s",
				zeile, treffer, strings.TrimSpace(text))
		}
	}
}

// goPakete sind die Pakete, deren Zeichenketten in der Oberfläche landen.
//
// internal/store und internal/config stehen nicht dabei: Ihre Texte sind
// Migrationsnamen und Schlüssel einer Konfigurationsdatei, kein Fließtext für
// einen Menschen.
var goPakete = []string{
	"../httpd",
	"../privops",
	"../update",
	"../acme",
}

// Und die Sätze, die der Server formuliert.
//
// Sie sind der größere Teil: Anmerkungen zum Zustand, Rückfragen, Fehlertexte
// und die Zeilen, die während eines Vorgangs in die Platte laufen. Der Satz aus
// dem Bildschirmfoto, der diese Prüfung ausgelöst hat, stand nicht in texte.ts,
// sondern in api_v1_webserver.go.
func TestServerTexteBleibenTechnisch(t *testing.T) {
	for _, paket := range goPakete {
		if _, err := os.Stat(paket); err != nil {
			t.Skipf("%s nicht vorhanden — nichts zu prüfen", paket)
		}

		// Datei für Datei statt parser.ParseDir: Das ist seit Go 1.25 abgekündigt,
		// und der Ersatz (golang.org/x/tools/go/packages) wäre eine neue direkte
		// Abhängigkeit für einen Verzeichnisdurchlauf, den Glob auch kann.
		quellen, err := filepath.Glob(filepath.Join(paket, "*.go"))
		if err != nil {
			t.Fatalf("%s durchsehen: %v", paket, err)
		}
		if len(quellen) == 0 {
			t.Fatalf("keine Go-Dateien in %s — stimmt der Pfad noch?", paket)
		}

		fset := token.NewFileSet()
		dateien := map[string]*ast.File{}
		for _, name := range quellen {
			// Testdateien bleiben außen vor: Sie zitieren die alten Wörter in
			// ihren eigenen Namen und Meldungen, und das ist richtig so — ein
			// Test darf benennen, wogegen er geschrieben ist.
			if strings.HasSuffix(name, "_test.go") {
				continue
			}
			datei, err := parser.ParseFile(fset, name, nil, 0)
			if err != nil {
				t.Fatalf("%s parsen: %v", name, err)
			}
			dateien[name] = datei
		}

		for name, datei := range dateien {
			// Struct-Tags sind Zeichenketten und trotzdem kein Text: In
			// `json:"einspielbar"` steht der Name eines Feldes der
			// Schnittstelle. Ihn umzubenennen wäre eine Änderung an der API
			// für einen Wortgeschmack — und die Oberfläche liest ihn nie vor.
			tags := map[token.Pos]bool{}
			ast.Inspect(datei, func(n ast.Node) bool {
				if f, ok := n.(*ast.Field); ok && f.Tag != nil {
					tags[f.Tag.Pos()] = true
				}
				return true
			})

			ast.Inspect(datei, func(n ast.Node) bool {
				lit, ok := n.(*ast.BasicLit)
				if !ok || lit.Kind != token.STRING || tags[lit.Pos()] {
					return true
				}
				for _, treffer := range gefundeneWoerter(lit.Value) {
					t.Errorf("%s:%d trägt ein verbrauchtes Wort (%s):\n\t%s",
						filepath.Base(name), fset.Position(lit.Pos()).Line, treffer, lit.Value)
				}
				return true
			})
		}
	}
}
