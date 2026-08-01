package httpd

import (
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

// Prüfung des Modells hinter dem Compose-Formular (web/src/lib/composeform.ts).
//
// Warum dieser Test in Go steht und nicht in einem JavaScript-Testrahmen: Das
// Projekt hat keinen, und einen einzuführen hieße, eine Werkzeugkette samt
// Fassungspflege für eine Datei aufzumachen. Der Weg hier benutzt, was ohnehin
// da ist — rolldown aus web/node_modules, dieselbe Kette, die auch die
// Oberfläche baut — und fügt keine einzige Abhängigkeit hinzu.
//
// Er überspringt sich selbst, wenn node oder node_modules fehlen. Das ist
// dieselbe Regel wie beim Browsertest (leitstand_e2e_test.go): Ein Go-Build
// braucht die Node-Kette nicht, und ein Test, der sie erzwingt, machte sie zur
// Bedingung. In der CI läuft er im Job „UI-Bundle reproduzierbar", wo
// node_modules nach npm ci vorhanden ist.
//
// Was hier geprüft wird, ist die Frage, an der ein Formular über einer
// Konfigurationsdatei scheitert: Was macht es mit dem, was es NICHT bearbeitet?
// Kommentare, Einrückung, Zeilenlänge, die Schreibweise von environment, und
// die Felder, die es gar nicht kennt. Über die Oberfläche wäre das nur mittelbar
// zu sehen — man sähe, dass etwas im Editor steht, nicht, was aus der Datei
// geworden ist.

// ergebnisComposeform ist die Ausgabe des Prüfskripts.
type ergebnisComposeform struct {
	Verstoesse []string `json:"verstoesse"`
}

func TestComposeformModell(t *testing.T) {
	node, err := exec.LookPath("node")
	if err != nil {
		t.Skip("node nicht vorhanden — das Modell wird nicht geprüft")
	}

	// rolldown ist der Bündler von Vite 8 und liegt nach npm ci in
	// web/node_modules. Fehlt er, ist die Node-Kette nicht eingerichtet.
	rolldown, err := filepath.Abs("../../web/node_modules/.bin/rolldown")
	if err != nil {
		t.Fatalf("Pfad zu rolldown: %v", err)
	}
	if _, err := os.Stat(rolldown); err != nil {
		t.Skip("web/node_modules fehlt — 'npm ci' im Verzeichnis web/ richtet es ein")
	}

	quelle, err := filepath.Abs("../../web/src/lib/composeform.ts")
	if err != nil {
		t.Fatalf("Pfad zur Quelle: %v", err)
	}
	buendel := filepath.Join(t.TempDir(), "composeform.mjs")

	bau := exec.Command(rolldown, quelle, "-o", buendel, "--format", "esm")
	bau.Dir = filepath.Dir(filepath.Dir(quelle)) // web/src — für die Auflösung von "yaml"
	if aus, err := bau.CombinedOutput(); err != nil {
		t.Fatalf("Bündeln von composeform.ts: %v\n%s", err, aus)
	}

	pruefung, err := filepath.Abs("testdata/composeform_test.mjs")
	if err != nil {
		t.Fatalf("Pfad zur Prüfung: %v", err)
	}
	lauf := exec.Command(node, pruefung, buendel)
	aus, err := lauf.CombinedOutput()
	if err != nil {
		t.Fatalf("Prüfskript: %v\n%s", err, aus)
	}

	// Die letzte Zeile ist das Ergebnis; alles davor wäre eine Warnung von Node
	// und gehört in die Fehlermeldung, nicht in den Parser.
	zeilen := strings.Split(strings.TrimSpace(string(aus)), "\n")
	var ergebnis ergebnisComposeform
	if err := json.Unmarshal([]byte(zeilen[len(zeilen)-1]), &ergebnis); err != nil {
		t.Fatalf("Ausgabe des Prüfskripts lesen: %v\n%s", err, aus)
	}

	for _, v := range ergebnis.Verstoesse {
		t.Errorf("Modell des Compose-Formulars: %s", v)
	}
}
