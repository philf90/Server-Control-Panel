package ui

import (
	"regexp"
	"strings"
	"testing"
)

// Die Tests der server-gerenderten Seiten — der Seiten VOR dem Panel.
//
// Diese Datei hieß bis zum Abbau der alten Oberfläche responsiv_test.go und
// prüfte deren Tabellen, Aufklapper, Verläufe und Rückfragen: Kartenmodus mit
// data-label, colspan gegen die Kopfzeile, Sprungziele, das Zeilenmenü der
// Dateiliste, das Bestätigungsskript. Alles davon prüfte Vorlagen, die es nicht
// mehr gibt.
//
// Die Prüfungen selbst sind nicht verloren: Für die neue Fläche stehen sie in
// leitstand_quellen_test.go und prüfen dort dieselben Eigenschaften an den
// Svelte-Quellen — keine Inline-Stile, jede Zelle mit ihrer Beschriftung,
// colspan gegen die Kopfzeile, kein confirm() im Browser. Was hier bleibt, gilt
// den neun verbliebenen Vorlagen und den vier verbliebenen statischen Dateien.

// TestJedesLabelHatSeinFeld: Eine Beschriftung mit for="x" braucht ein Feld mit
// id="x" in derselben Vorlage.
//
// Der Anlass war ein Befund auf der Seite Systembenutzer: Dort stand hinter dem
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

// TestKeineInlineStyles: Die Content-Security-Policy des Panels erlaubt kein
// style-Attribut ("style-src 'self'" ohne unsafe-inline). Der Browser verwirft
// so ein Attribut stillschweigend — die Seite sieht dann nicht kaputt aus,
// sondern nur falsch.
//
// Genau das war bei den Auslastungsbalken der Fall: style="width:38%" kam nie
// an, die Balken standen immer auf 100 %. Der Balken der Passwortstärke ist der
// letzte, der geblieben ist, und er trägt seinen Wert in einem Attribut.
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

// TestKeineInlineHandler: Kein onsubmit, kein onclick, nirgends.
//
// Der Anlass ist der Befund, der die Rückfragen ausgelöst hat: Dreizehn
// Formulare trugen ein onsubmit="return confirm(…)". Die
// Content-Security-Policy des Panels ist `script-src 'self'` ohne
// 'unsafe-inline'; Chromium verwirft ein solches Attribut, bevor es einmal
// läuft. Im Browser nachgemessen: kein Dialog, ein Klick, Konto weg.
//
// Dieselbe Falle wie beim style-Attribut — es steht im Markup, sieht richtig
// aus, und der Browser wirft es still weg. Deshalb dieser Test statt eines guten
// Vorsatzes.
func TestKeineInlineHandler(t *testing.T) {
	handlerRe := regexp.MustCompile(`\son[a-z]+\s*=\s*["']`)

	dateien, err := templateFS.ReadDir("templates")
	if err != nil {
		t.Fatal(err)
	}
	for _, d := range dateien {
		raw, err := templateFS.ReadFile("templates/" + d.Name())
		if err != nil {
			t.Fatal(err)
		}
		for _, treffer := range handlerRe.FindAllString(string(raw), -1) {
			t.Errorf("%s enthält einen Inline-Handler (%s) — die CSP verwirft ihn, "+
				"die Rückfrage kommt beim Nutzer nie an", d.Name(), strings.TrimSpace(treffer))
		}
	}
}

// TestJedesNeuePasswortfeldZeigtDieRichtlinie: Wo ein neues Passwort gewählt
// wird, müssen die Bedingungen dabeistehen.
//
// Drei Seiten verlangen eines — Ersteinrichtung, erzwungener Wechsel und der Weg
// über einen Passkey. Es waren vier: Die Kontoseite ist in die neue Oberfläche
// gewandert und holt die Richtlinie über die Schnittstelle. Vorher stand unter
// dem Feld ein Satz ("Mindestens 12 Zeichen"), und die übrigen Regeln erfuhr man
// erst durch eine Ablehnung. Der Test hält fest, dass keine dieser Seiten die
// Anzeige verliert: Eine Regel, die man nicht lesen kann, ist eine Falle.
func TestJedesNeuePasswortfeldZeigtDieRichtlinie(t *testing.T) {
	seiten := []string{"setup.html", "password-change.html", "forgot-new.html"}

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

// TestJedeVorlageGehoertVorDasPanel ist die Wache über den Abbau.
//
// Die server-gerenderte Fläche ist nach dem Abbau abgeschlossen: neun Vorlagen
// für die Wege vor der Anmeldung. Käme eine zehnte hinzu, wäre das der Beginn
// einer zweiten Oberfläche neben der Einzelseiten-Anwendung — und zwar
// versehentlich, weil ein server-gerendertes Formular der bequemere Weg ist,
// wenn man ihn noch kennt. Wer eine Vorlage hinzufügt, muss diesen Test
// anfassen und dabei begründen, warum die Seite nicht in die SPA gehört.
//
// Der Grund, aus dem diese neun bleiben, ist immer derselbe: Sie müssen ohne
// JavaScript funktionieren. Alles hinter der Anmeldung darf ein Bundle
// voraussetzen; der Weg zur Anmeldung darf es nicht.
func TestJedeVorlageGehoertVorDasPanel(t *testing.T) {
	erwartet := map[string]string{
		"partials.html":        "Rahmen und Passwortprüfung",
		"login.html":           "Anmeldung, auch mit Passkey",
		"setup.html":           "Ersteinrichtung des ersten Kontos",
		"totp.html":            "zweiter Faktor einrichten",
		"codes.html":           "Wiederherstellungscodes, genau einmal",
		"forgot.html":          "Passwort vergessen, Nachweis per Passkey",
		"forgot-new.html":      "neues Passwort nach dem Nachweis",
		"password-change.html": "erzwungener Wechsel eines Einmalpassworts",
		"error.html":           "Fehlerseite, auch für Download und Archiv",
	}

	dateien, err := templateFS.ReadDir("templates")
	if err != nil {
		t.Fatal(err)
	}
	gefunden := make(map[string]bool, len(dateien))
	for _, d := range dateien {
		gefunden[d.Name()] = true
		if _, ok := erwartet[d.Name()]; !ok {
			t.Errorf("%s ist eine neue server-gerenderte Vorlage. Gehört die Seite "+
				"wirklich vor die Anmeldung — muss sie ohne JavaScript gehen? Wenn ja, "+
				"trage sie hier mit ihrem Grund ein. Wenn nein, gehört sie nach "+
				"web/src/seiten/.", d.Name())
		}
	}
	for name, grund := range erwartet {
		if !gefunden[name] {
			t.Errorf("%s fehlt (%s) — die Liste in diesem Test ist veraltet", name, grund)
		}
	}
}

// TestJedeStatischeDateiGehoertVorDasPanel: dieselbe Wache für /static.
//
// Hier lagen einmal sechzehn Dateien und ein Verzeichnis mit dem
// CodeMirror-Bundle. Zwölf Skripte bedienten Seiten des alten Panels und sind
// mit ihnen gegangen, das Bundle hat die neue Oberfläche in ihrem eigenen
// mitgebracht, und app.css schrumpfte von 2259 auf gut dreihundert Zeilen. Was
// bleibt, hängt an den Seiten vor der Anmeldung.
func TestJedeStatischeDateiGehoertVorDasPanel(t *testing.T) {
	erwartet := map[string]string{
		"app.css":          "Stylesheet der neun Vorlagen",
		"passkey-login.js": "Anmeldung mit Passkey (login.html)",
		"passkey-reset.js": "Passwort vergessen mit Passkey (forgot.html)",
		"passwort.js":      "Prüfliste der Passwortrichtlinie (partials.html)",
	}

	dateien, err := staticFS.ReadDir("static")
	if err != nil {
		t.Fatal(err)
	}
	gefunden := make(map[string]bool, len(dateien))
	for _, d := range dateien {
		gefunden[d.Name()] = true
		if _, ok := erwartet[d.Name()]; !ok {
			t.Errorf("%s ist neu unter /static. Die Fläche hinter der Anmeldung bringt "+
				"ihre Mittel im Bundle mit (web/, gebaut nach internal/ui/dist) — "+
				"hierher gehört nur, was eine Seite VOR der Anmeldung braucht.", d.Name())
		}
	}
	for name, grund := range erwartet {
		if !gefunden[name] {
			t.Errorf("%s fehlt (%s) — die Liste in diesem Test ist veraltet", name, grund)
		}
	}
}

// TestKeinVerweisAufDieAlteOberflaeche: In den Vorlagen und statischen Dateien
// darf kein Pfad der alten Fläche mehr stehen.
//
// Beim Umschalten lag sie eine Fassung lang unter /alt/. Diese Routen gibt es
// nicht mehr — ein Verweis darauf führte auf eine 404, und zwar von einer Seite
// aus, auf der jemand gerade sein Passwort ändert. Genau dort ist ein toter
// Link am teuersten.
func TestKeinVerweisAufDieAlteOberflaeche(t *testing.T) {
	for _, ordner := range []string{"templates", "static"} {
		lesen := templateFS.ReadFile
		verzeichnis := templateFS.ReadDir
		if ordner == "static" {
			lesen, verzeichnis = staticFS.ReadFile, staticFS.ReadDir
		}
		dateien, err := verzeichnis(ordner)
		if err != nil {
			t.Fatal(err)
		}
		for _, d := range dateien {
			raw, err := lesen(ordner + "/" + d.Name())
			if err != nil {
				t.Fatal(err)
			}
			if strings.Contains(string(raw), "/alt/") {
				t.Errorf("%s/%s verweist auf /alt/ — diese Route gibt es nicht mehr",
					ordner, d.Name())
			}
		}
	}
}
