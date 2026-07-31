package httpd

import (
	"encoding/json"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/store"
)

// TestBestaetigenBrowser fährt die Rückfrage vor dem Löschen in einem echten
// Browser.
//
// Der Grund für diesen Test ist der Befund, der das ganze Vorhaben ausgelöst
// hat: Dreizehn Formulare trugen ein onsubmit="return confirm(…)", und keines
// hat je gefragt — die CSP verwirft Inline-Handler. Ein Go-Test sieht das
// Attribut im Markup und ist zufrieden; dass ein Dialog erscheint, sagt nur der
// Browser.
//
// Bewusst hinter einer Umgebungsvariablen: braucht Node und Chromium.
//
//	ASYLUM_BESTAETIGEN_E2E=1 \
//	  ASYLUM_NODE=/opt/node22/bin/node \
//	  ASYLUM_NODE_PATH=/opt/node22/lib/node_modules \
//	  ASYLUM_CHROMIUM=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
//	  go test ./internal/httpd -run TestBestaetigenBrowser -v
func TestBestaetigenBrowser(t *testing.T) {
	if os.Getenv("ASYLUM_BESTAETIGEN_E2E") == "" {
		t.Skip("ohne ASYLUM_BESTAETIGEN_E2E nichts zu tun (braucht Node und Chromium)")
	}
	chromium := os.Getenv("ASYLUM_CHROMIUM")
	if chromium == "" {
		t.Skip("ASYLUM_CHROMIUM (Pfad zum Browser) nicht gesetzt")
	}
	node := envOr("ASYLUM_NODE", "node")

	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	// Ein zweites Konto, damit die Seite Panel-Zugänge das Formular zum
	// Zurücksetzen zeigt — daran hängt der dritte Teil des Treibers.
	addUser(t, s, "kollege", store.RoleAdmin)
	cookie, _ := login(t, s, user)

	arbeit := filepath.Join(wurzel, "schreibbar")
	datei := filepath.Join(arbeit, "weg.conf")
	lege(t, datei, "inhalt\n")
	ordner := filepath.Join(arbeit, "baum")
	lege(t, filepath.Join(ordner, "tief", "a.txt"), "x")

	ts := httptest.NewServer(s.Handler())
	defer ts.Close()

	cmd := exec.Command(node, "testdata/bestaetigen_e2e.js")
	cmd.Env = append(os.Environ(),
		"ASYLUM_E2E_URL="+ts.URL,
		"ASYLUM_E2E_COOKIE="+cookie.Name+"="+cookie.Value,
		"ASYLUM_E2E_DATEI="+datei,
		"ASYLUM_E2E_ORDNER="+ordner,
		"ASYLUM_E2E_PW="+testPassword,
		"ASYLUM_CHROMIUM="+chromium,
	)
	if p := os.Getenv("ASYLUM_NODE_PATH"); p != "" {
		cmd.Env = append(cmd.Env, "NODE_PATH="+p)
	}

	ausgabe, err := cmd.CombinedOutput()
	t.Logf("Treiber:\n%s", ausgabe)
	if err != nil {
		t.Fatalf("Browsertreiber: %v", err)
	}

	type stand struct {
		Offen            bool   `json:"offen"`
		Frage            string `json:"frage"`
		TippfeldSichtbar bool   `json:"tippfeldSichtbar"`
		KnopfGesperrt    bool   `json:"knopfGesperrt"`
		Modal            bool   `json:"modal"`
		URLUnveraendert  bool   `json:"urlUnveraendert"`
		Hinweis          string `json:"hinweis"`
		EintragStatus    int    `json:"eintragStatus"`
	}
	var e struct {
		Verstoesse       []string `json:"verstoesse"`
		NativeDialoge    int      `json:"nativeDialoge"`
		Datei            stand    `json:"datei"`
		NachAbbruch      stand    `json:"nachAbbruch"`
		NachEscape       stand    `json:"nachEscape"`
		NachBestaetigung struct {
			GelandetAuf string `json:"gelandetAuf"`
		} `json:"nachBestaetigung"`
		Ordner struct {
			Leer        stand  `json:"leer"`
			Falsch      stand  `json:"falsch"`
			GrossKlein  stand  `json:"grossKlein"`
			GelandetAuf string `json:"gelandetAuf"`
		} `json:"ordner"`
		Knopf struct {
			Offen       bool   `json:"offen"`
			Titel       string `json:"titel"`
			Frage       string `json:"frage"`
			GelandetAuf string `json:"gelandetAuf"`
			Meldung     string `json:"meldung"`
		} `json:"knopf"`
	}
	if err := json.Unmarshal([]byte(letzteZeile(string(ausgabe))), &e); err != nil {
		t.Fatalf("Ausgabe des Treibers unlesbar: %v", err)
	}

	if len(e.Verstoesse) > 0 {
		t.Errorf("die Content-Security-Policy hat etwas verworfen:\n  %s", strings.Join(e.Verstoesse, "\n  "))
	}
	if e.NativeDialoge > 0 {
		t.Errorf("%d Mal window.confirm — die Rückfrage soll ein <dialog> sein, "+
			"schon damit sie ein Eingabefeld tragen kann", e.NativeDialoge)
	}

	// --- Eine Datei: zweite Stufe --------------------------------------------
	if !e.Datei.Offen {
		t.Fatal("nach dem Klick auf löschen erschien kein Dialog — genau das war der Befund")
	}
	if !e.Datei.Modal {
		t.Error("der Dialog ist nicht modal — dahinter ließe sich weiterklicken")
	}
	if !strings.Contains(e.Datei.Frage, "weg.conf") {
		t.Errorf("die Frage nennt den Eintrag nicht: %q", e.Datei.Frage)
	}
	if e.Datei.TippfeldSichtbar {
		t.Error("für eine einzelne Datei wird ein getipptes Wort verlangt — falsche Stufe")
	}
	if e.Datei.KnopfGesperrt {
		t.Error("der bestätigende Knopf ist gesperrt, obwohl nichts zu tippen ist")
	}
	if !e.Datei.URLUnveraendert {
		t.Error("das Formular wurde trotz Dialog abgeschickt")
	}

	// Abbrechen und Escape sind Abbrüche: keine Navigation, kein POST. Die Datei
	// liegt danach noch da — das ist die Prüfung, die zählt.
	for name, s := range map[string]stand{"abbrechen": e.NachAbbruch, "Escape": e.NachEscape} {
		if s.Offen {
			t.Errorf("%s hat den Dialog nicht geschlossen", name)
		}
		if !s.URLUnveraendert {
			t.Errorf("%s hat das Formular abgeschickt", name)
		}
	}
	// Dass die Datei die beiden Abbrüche überlebt hat, fragt der Treiber selbst
	// ab (er löscht sie im nächsten Schritt): Die Detailseite antwortet nur
	// solange mit 200, wie es den Eintrag gibt.
	if e.NachEscape.EintragStatus != 200 {
		t.Errorf("nach zwei Abbrüchen antwortet die Detailseite mit %d — die Datei ist weg",
			e.NachEscape.EintragStatus)
	}

	// Bestätigen: Der Handler führt aus und schickt in die Liste.
	if e.NachBestaetigung.GelandetAuf != "/alt/files/delete" {
		t.Errorf("nach dem Bestätigen gelandet auf %q", e.NachBestaetigung.GelandetAuf)
	}
	if _, err := os.Stat(datei); !os.IsNotExist(err) {
		t.Error("die bestätigte Löschung ist nicht angekommen")
	}

	// --- Ein Ordner mit Inhalt: dritte Stufe ---------------------------------
	if !e.Ordner.Leer.TippfeldSichtbar {
		t.Error("für einen Ordner mit Inhalt fehlt das Eingabefeld")
	}
	if !e.Ordner.Leer.KnopfGesperrt {
		t.Error("der Knopf ist offen, bevor etwas getippt wurde")
	}
	if !strings.Contains(e.Ordner.Leer.Hinweis, "baum") {
		t.Errorf("der Hinweis sagt nicht, was zu tippen ist: %q", e.Ordner.Leer.Hinweis)
	}
	if !e.Ordner.Falsch.KnopfGesperrt {
		t.Error("ein falsches Wort schaltet den Knopf frei")
	}
	if e.Ordner.GrossKlein.KnopfGesperrt {
		t.Error("BAUM statt baum sperrt den Knopf — die Schreibweise ist nicht der Zweck")
	}
	if _, err := os.Stat(ordner); !os.IsNotExist(err) {
		t.Error("der Ordner ist nach der bestätigten Löschung noch da")
	}

	// --- Die Angabe am Knopf -------------------------------------------------
	//
	// Drei Knöpfe, ein Formular, drei formactions: Der Dialog muss den Knopf
	// lesen, und der bestätigte Klick muss beim Ziel dieses Knopfes landen. Ein
	// form.submit() nähme stattdessen das Ziel des Formulars — dann wäre statt der
	// Passkeys das Passwort zurückgesetzt, und niemand hätte es gemerkt.
	if !e.Knopf.Offen {
		t.Fatal("der Knopf mit eigener Angabe zeigt keinen Dialog")
	}
	if e.Knopf.Titel != "Passkeys entfernen" {
		t.Errorf("der Dialog trägt den Titel %q — er liest die Angabe des Formulars statt die des Knopfes", e.Knopf.Titel)
	}
	if !strings.Contains(e.Knopf.Frage, "Passkeys") {
		t.Errorf("die Frage passt nicht zum Knopf: %q", e.Knopf.Frage)
	}
	if e.Knopf.GelandetAuf != "/alt/users/reset-passkeys" {
		t.Errorf("gelandet auf %q — das formaction des Knopfes ging verloren", e.Knopf.GelandetAuf)
	}
	if !strings.Contains(e.Knopf.Meldung, "Passkey") {
		t.Errorf("die Meldung nach dem Bestätigen: %q", e.Knopf.Meldung)
	}
}
