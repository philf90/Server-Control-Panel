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

// TestDateimanagerBrowser fährt die Detailseite in einem echten Browser.
//
// Zwei Fragen, die kein Go-Test beantwortet:
//
//  1. Trägt die Zielauswahl beim Absenden den gewählten Pfad? Sie ersetzt ein
//     freies Textfeld: Die Struktur kommt aus /files/dirs, die Auswahlliste ohne
//     Skript verliert ihren Namen, und ein verstecktes Feld führt den Wert. Ob
//     dabei genau ein "target" ankommt, sagt nur der Browser.
//  2. Laufen Kästchen und Oktalziffer im Gleichschritt — in beide Richtungen?
//
// Bewusst hinter einer Umgebungsvariablen: braucht Node und Chromium.
//
//	ASYLUM_DATEIEN_E2E=1 \
//	  ASYLUM_NODE=/opt/node22/bin/node \
//	  ASYLUM_NODE_PATH=/opt/node22/lib/node_modules \
//	  ASYLUM_CHROMIUM=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
//	  go test ./internal/httpd -run TestDateimanagerBrowser -v
func TestDateimanagerBrowser(t *testing.T) {
	if os.Getenv("ASYLUM_DATEIEN_E2E") == "" {
		t.Skip("ohne ASYLUM_DATEIEN_E2E nichts zu tun (braucht Node und Chromium)")
	}
	chromium := os.Getenv("ASYLUM_CHROMIUM")
	if chromium == "" {
		t.Skip("ASYLUM_CHROMIUM (Pfad zum Browser) nicht gesetzt")
	}
	node := envOr("ASYLUM_NODE", "node")

	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	// Ein Baum mit Unterordnern, damit es in der Auswahl etwas zu wählen gibt.
	arbeit := filepath.Join(wurzel, "schreibbar")
	for _, d := range []string{"ziel-a", "ziel-b", "ziel-b/tiefer"} {
		if err := os.MkdirAll(filepath.Join(arbeit, d), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	datei := filepath.Join(arbeit, "verschiebbar.conf")
	lege(t, datei, "inhalt\n")
	if err := os.Chmod(datei, 0o644); err != nil {
		t.Fatal(err)
	}

	ts := httptest.NewServer(s.Handler())
	defer ts.Close()

	cmd := exec.Command(node, "testdata/dateimanager_e2e.js")
	cmd.Env = append(os.Environ(),
		"ASYLUM_E2E_URL="+ts.URL,
		"ASYLUM_E2E_COOKIE="+cookie.Name+"="+cookie.Value,
		"ASYLUM_E2E_PATH="+datei,
		"ASYLUM_E2E_DIR="+arbeit,
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

	var e struct {
		Verstoesse []string `json:"verstoesse"`
		Auswahl    struct {
			ListeHatNamen  bool     `json:"listeHatNamen"`
			ListeVersteckt bool     `json:"listeVersteckt"`
			FeldWert       string   `json:"feldWert"`
			FreieEingaben  int      `json:"freieEingaben"`
			Ordner         []string `json:"ordner"`
			Marken         []string `json:"marken"`
		} `json:"auswahl"`
		NachKlick *struct {
			FeldWert     string `json:"feldWert"`
			Gewaehlt     string `json:"gewaehlt"`
			Beschriftung string `json:"beschriftung"`
		} `json:"nachKlick"`
		Rechte struct {
			Vorher        string `json:"vorher"`
			KaestchenFrei bool   `json:"kaestchenFrei"`
			NachKasten    struct {
				Octal string `json:"octal"`
				Satz  string `json:"satz"`
			} `json:"nachKasten"`
			NachZiffer struct {
				UserR    bool   `json:"userR"`
				UserW    bool   `json:"userW"`
				UserX    bool   `json:"userX"`
				GroupR   bool   `json:"groupR"`
				OtherR   bool   `json:"otherR"`
				SatzAlle string `json:"satzAlle"`
			} `json:"nachZiffer"`
			NachSonder string `json:"nachSonder"`
		} `json:"rechte"`
		Menue struct {
			Zahl        int      `json:"zahl"`
			KnoepfeFrei int      `json:"knoepfeFrei"`
			Eintraege   []string `json:"eintraege"`
			Frei        bool     `json:"frei"`
			Hoehe       int      `json:"hoehe"`
			InDerKarte  bool     `json:"inDerKarte"`
		} `json:"menue"`
	}
	if err := json.Unmarshal([]byte(letzteZeile(string(ausgabe))), &e); err != nil {
		t.Fatalf("Ausgabe des Treibers unlesbar: %v", err)
	}

	if len(e.Verstoesse) > 0 {
		t.Errorf("die Content-Security-Policy hat etwas verworfen:\n  %s", strings.Join(e.Verstoesse, "\n  "))
	}

	// --- Zielauswahl ---------------------------------------------------------
	if e.Auswahl.FreieEingaben != 0 {
		t.Errorf("%d freie Textfelder in der Zielauswahl — es dürfen keine sein",
			e.Auswahl.FreieEingaben)
	}
	if e.Auswahl.ListeHatNamen {
		t.Error("die Auswahlliste ohne Skript wird mitgesendet — dann kämen zwei Werte für target an")
	}
	if !e.Auswahl.ListeVersteckt {
		t.Error("die Auswahlliste ohne Skript steht neben der Auswahl")
	}
	if e.Auswahl.FeldWert != arbeit {
		t.Errorf("Ziel beim Laden = %q, erwartet %q", e.Auswahl.FeldWert, arbeit)
	}
	// Zur Wahl stehen die Ordner, die es gibt — und nur die.
	gefunden := strings.Join(e.Auswahl.Ordner, " ")
	for _, want := range []string{"ziel-a", "ziel-b"} {
		if !strings.Contains(gefunden, want) {
			t.Errorf("der Ordner %q fehlt in der Auswahl: %v", want, e.Auswahl.Ordner)
		}
	}
	if len(e.Auswahl.Marken) == 0 {
		t.Error("die Schreibbereiche fehlen als Sprungmarken")
	}
	if e.NachKlick == nil {
		t.Fatal("es war kein Ordner zum Anklicken da")
	}
	if !strings.HasSuffix(e.NachKlick.FeldWert, e.NachKlick.Beschriftung) {
		t.Errorf("nach dem Wechsel in %q steht als Ziel %q",
			e.NachKlick.Beschriftung, e.NachKlick.FeldWert)
	}
	if e.NachKlick.Gewaehlt != e.NachKlick.FeldWert {
		t.Errorf("Anzeige (%q) und gesendeter Wert (%q) gehen auseinander",
			e.NachKlick.Gewaehlt, e.NachKlick.FeldWert)
	}

	// --- Rechteraster --------------------------------------------------------
	if !e.Rechte.KaestchenFrei {
		t.Error("die Kästchen sind gesperrt geblieben — rechte.js hat sie nicht freigeschaltet")
	}
	if e.Rechte.Vorher != "0644" {
		t.Errorf("Ziffer beim Laden = %q, erwartet 0644", e.Rechte.Vorher)
	}
	// Der Gruppe das Schreiben geben: 0644 → 0664.
	if e.Rechte.NachKasten.Octal != "0664" {
		t.Errorf("nach dem Kästchen = %q, erwartet 0664", e.Rechte.NachKasten.Octal)
	}
	if !strings.Contains(e.Rechte.NachKasten.Satz, "ändern") {
		t.Errorf("der Satz zur Gruppe nennt das neue Recht nicht: %q", e.Rechte.NachKasten.Satz)
	}
	// 0600 tippen: nur der Eigentümer, und zwar lesen und ändern.
	z := e.Rechte.NachZiffer
	if !z.UserR || !z.UserW || z.UserX || z.GroupR || z.OtherR {
		t.Errorf("0600 ergab ein anderes Raster: %+v", z)
	}
	if z.SatzAlle != "darf nichts" {
		t.Errorf("der Satz für alle anderen = %q, erwartet \"darf nichts\"", z.SatzAlle)
	}
	// Sticky-Bit: die erste Ziffer springt auf 1.
	if e.Rechte.NachSonder != "1600" {
		t.Errorf("nach dem Sticky-Bit = %q, erwartet 1600", e.Rechte.NachSonder)
	}

	// --- Zeilenmenü der Liste ------------------------------------------------
	if e.Menue.Zahl == 0 {
		t.Fatal("keine einzige Zeile mit Menü in der Liste")
	}
	if e.Menue.KnoepfeFrei != 0 {
		t.Errorf("%d Knöpfe stehen frei in der Aktionsspalte — sie gehören ins Menü",
			e.Menue.KnoepfeFrei)
	}
	// Aufgeklappt und trotzdem unsichtbar ist der Fehler, den nur ein Browser
	// findet: Karte und Scrollbehälter beschneiden, und vom Menü der letzten
	// Zeile blieb ein Streifen von zehn Pixeln.
	if !e.Menue.Frei {
		t.Errorf("das aufgeklappte Menü ist verdeckt oder abgeschnitten (Höhe %d)", e.Menue.Hoehe)
	}
	if !e.Menue.InDerKarte {
		t.Error("das Menü ragt über die Karte hinaus")
	}
	// Was drinsteht, muss vollständig sein: Bearbeiten und Herunterladen waren
	// vorher Knöpfe in der Zeile. Verdichten heißt nicht streichen.
	gefundenImMenue := strings.Join(e.Menue.Eintraege, " | ")
	for _, want := range []string{"bearbeiten", "herunterladen", "Details"} {
		if !strings.Contains(gefundenImMenue, want) {
			t.Errorf("%q fehlt im Menü: %s", want, gefundenImMenue)
		}
	}
}
