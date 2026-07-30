package httpd

import (
	"encoding/json"
	"net/http/httptest"
	"os"
	"os/exec"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/store"
)

// ergebnisLeitstand ist die Ausgabe des Browsertreibers.
type ergebnisLeitstand struct {
	Verstoesse []string `json:"verstoesse"`
	Fehler     []string `json:"fehler"`
	Fehlend    []string `json:"fehlend"`
	Montiert   struct {
		Kinder       int    `json:"kinder"`
		Kacheln      int    `json:"kacheln"`
		Schale       int    `json:"schale"`
		Statusband   int    `json:"statusband"`
		Seitenleiste int    `json:"seitenleiste"`
		Protokoll    int    `json:"protokoll"`
		KartenFarbe  string `json:"kartenFarbe"`
	} `json:"montiert"`
	Uebersicht struct {
		UrteilText        string `json:"urteilText"`
		UrteilUnbekannt   bool   `json:"urteilUnbekannt"`
		Punkte            int    `json:"punkte"`
		PunkteMitGriff    int    `json:"punkteMitGriff"`
		Tabellen          int    `json:"tabellen"`
		DateisystemZeilen int    `json:"dateisystemZeilen"`
	} `json:"uebersicht"`
	TitelSitz []struct {
		Name          string `json:"name"`
		Gefunden      bool   `json:"gefunden"`
		GleicheKante  bool   `json:"gleicheKante"`
		TitelDarueber bool   `json:"titelDarueber"`
	} `json:"titelSitz"`
	RahmenSitz []struct {
		InhaltBreite float64 `json:"inhaltBreite"`
		RahmenBreite float64 `json:"rahmenBreite"`
		Scrollbar    string  `json:"scrollbar"`
	} `json:"rahmenSitz"`
	Palette struct {
		Schritte          []string `json:"schritte"`
		FokusImFeld       bool     `json:"fokusImFeld"`
		ZieleGesamt       int      `json:"zieleGesamt"`
		TrefferNginx      []string `json:"trefferNginx"`
		TrefferOhneUmlaut []string `json:"trefferOhneUmlaut"`
		LeerZustand       string   `json:"leerZustand"`
		NachEscape        bool     `json:"nachEscape"`
		ZweiteGewaehlt    bool     `json:"zweiteGewaehlt"`
		KlickInnenHaelt   bool     `json:"klickInnenHaelt"`
		KlickDaneben      bool     `json:"klickDanebenSchliesst"`
	} `json:"palette"`
	Zweige struct {
		Vorher  int `json:"vorher"`
		Nachher int `json:"nachher"`
	} `json:"zweige"`
	Schmal struct {
		KoerperBreite float64 `json:"koerperBreite"`
		FensterBreite float64 `json:"fensterBreite"`
		Beschriftung  string  `json:"beschriftung"`
	} `json:"schmal"`
	Strich *struct {
		SVGBreite    float64 `json:"svgBreite"`
		Effekt       string  `json:"effekt"`
		PunktEffekt  string  `json:"punktEffekt"`
		Strichbreite string  `json:"strichbreite"`
	} `json:"strich"`
	Live     bool   `json:"live"`
	Ablesung string `json:"ablesung"`
}

// TestLeitstandBrowser fährt die neue Oberfläche in einem echten Browser.
//
// Vier Fragen, die kein Go-Test beantwortet:
//
//  1. Montiert die Anwendung? Ein Go-Test sieht die Hülle mit einem leeren
//     <div id="app">. Ob Svelte darin etwas erzeugt — oder ob ein
//     Laufzeitfehler im Bundle die Seite leer lässt —, sagt nur der Browser.
//  2. Verwirft die Richtlinie etwas? Das Bundle ist eine externe Datei und ein
//     Modulskript, das Stylesheet ebenso. An genau dieser Stelle ist das
//     Projekt zweimal gescheitert: die Auslastungsbalken in rc.5 und CodeMirror
//     im Dateimanager. Beides sah im Go-Test richtig aus.
//  3. Ist der Strich der Verläufe gleichmäßig? Die Kachel ist neu gebaut, die
//     Falle dieselbe wie in 0.2.0: 100 viewBox-Einheiten werden waagerecht
//     stärker gestreckt als senkrecht.
//  4. Trägt der Live-Kanal? Die Zahl kommt beim Aufbau aus der Schnittstelle
//     und wird danach aus dem SSE-Strom fortgeschrieben.
//  5. Werden Tabellen unter 600 Pixeln zu Karten? Der Seitenkörper darf nicht
//     waagerecht scrollen — die Lektion aus rc.4, gemessen und nicht vermutet.
//  6. Klappen die weiteren Einhängepunkte einer Platte auf?
//
// Bewusst hinter einer Umgebungsvariablen: Der Test braucht Node und Chromium
// und läuft nicht in jeder CI. Aufruf:
//
//	ASYLUM_LEITSTAND_E2E=1 \
//	  ASYLUM_NODE=/opt/node22/bin/node \
//	  ASYLUM_NODE_PATH=/opt/node22/lib/node_modules \
//	  ASYLUM_CHROMIUM=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
//	  go test ./internal/httpd -run TestLeitstandBrowser -v
func TestLeitstandBrowser(t *testing.T) {
	if os.Getenv("ASYLUM_LEITSTAND_E2E") == "" {
		t.Skip("ohne ASYLUM_LEITSTAND_E2E nichts zu tun (braucht Node und Chromium)")
	}
	chromium := os.Getenv("ASYLUM_CHROMIUM")
	if chromium == "" {
		t.Skip("ASYLUM_CHROMIUM (Pfad zum Browser) nicht gesetzt")
	}
	node := envOr("ASYLUM_NODE", "node")

	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)
	fuelleUebersicht(s)

	ts := httptest.NewServer(s.Handler())
	defer ts.Close()

	cmd := exec.Command(node, "testdata/leitstand_e2e.js")
	cmd.Env = append(os.Environ(),
		"ASYLUM_E2E_URL="+ts.URL,
		"ASYLUM_E2E_COOKIE="+cookie.Name+"="+cookie.Value,
		"ASYLUM_CHROMIUM="+chromium,
	)
	if p := os.Getenv("ASYLUM_NODE_PATH"); p != "" {
		cmd.Env = append(cmd.Env, "NODE_PATH="+p)
	}
	if p := os.Getenv("ASYLUM_E2E_SHOTS"); p != "" {
		cmd.Env = append(cmd.Env, "ASYLUM_E2E_SHOTS="+p)
	}

	ausgabe, err := cmd.CombinedOutput()
	t.Logf("Treiber:\n%s", ausgabe)
	if err != nil {
		t.Fatalf("Browsertreiber: %v", err)
	}

	var e ergebnisLeitstand
	letzte := letzteZeile(string(ausgabe))
	if err := json.Unmarshal([]byte(letzte), &e); err != nil {
		t.Fatalf("Ausgabe des Treibers unlesbar: %v — %q", err, letzte)
	}

	// 1. Kein Laufzeitfehler und nichts verworfen.
	if len(e.Fehler) > 0 {
		t.Errorf("die Anwendung hat einen Laufzeitfehler geworfen:\n  %s",
			strings.Join(e.Fehler, "\n  "))
	}
	if len(e.Verstoesse) > 0 {
		t.Errorf("die Content-Security-Policy hat etwas verworfen:\n  %s",
			strings.Join(e.Verstoesse, "\n  "))
	}

	// 2. Die Schale steht vollständig.
	if e.Montiert.Kinder == 0 {
		t.Fatal("#app ist leer — die Anwendung hat nicht montiert")
	}
	if e.Montiert.Kacheln != 4 {
		t.Errorf("%d Telemetrie-Kacheln, erwartet 4", e.Montiert.Kacheln)
	}
	for name, anzahl := range map[string]int{
		"Schale":       e.Montiert.Schale,
		"Statusband":   e.Montiert.Statusband,
		"Seitenleiste": e.Montiert.Seitenleiste,
		"Protokoll":    e.Montiert.Protokoll,
	} {
		if anzahl != 1 {
			t.Errorf("%s ist %d Mal da, erwartet einmal", name, anzahl)
		}
	}
	// Kommt das Stylesheet nicht durch die Richtlinie, ist die Kachel weiß.
	// Ein Go-Test sähe davon nichts, weil das Markup stimmt.
	if farbe := e.Montiert.KartenFarbe; !strings.HasPrefix(farbe, "rgb(19, 22, 27") {
		t.Errorf("die Kachel hat die Farbe %q — das Stylesheet ist nicht angekommen", farbe)
	}

	// 3. Der Strich ist gleichmäßig.
	if e.Strich == nil {
		t.Fatal("kein Verlauf gezeichnet, obwohl der Ring gefüllt ist")
	}
	if e.Strich.SVGBreite < 150 {
		t.Fatalf("die Kachel ist nur %.0f Pixel breit — dann greift die Messung nicht",
			e.Strich.SVGBreite)
	}
	if streckung := e.Strich.SVGBreite / 100; streckung < 1.5 {
		t.Errorf("die Kachel streckt nur um %.1f — die Messung prüft dann nichts", streckung)
	}
	if e.Strich.Effekt != "non-scaling-stroke" {
		t.Errorf("vector-effect der Linie = %q — die Strichstärke wird mit der Breite gestreckt",
			e.Strich.Effekt)
	}
	if e.Strich.PunktEffekt != "non-scaling-stroke" {
		t.Errorf("vector-effect des Endpunkts = %q — er käme als liegende Ellipse heraus",
			e.Strich.PunktEffekt)
	}

	// 4. Der Live-Kanal trägt, und die Ablesung antwortet.
	if !e.Live {
		t.Error("der Live-Kanal wurde nicht offen gemeldet — SSE kommt nicht an")
	}
	if e.Ablesung == "" {
		t.Error("der Zeiger über dem Verlauf zeigt keinen Messwert")
	}

	// 5. Urteil und Handlungsbedarf. Das Test-Doppel führt einen ausgefallenen
	//    Dienst, es muss also ein Punkt erscheinen — und ein gescheiterter
	//    Abruf darf nicht als „alles in Ordnung" durchgehen.
	if e.Uebersicht.UrteilUnbekannt {
		t.Errorf("die Erhebung ist gescheitert: %q", e.Uebersicht.UrteilText)
	}
	if !strings.Contains(e.Uebersicht.UrteilText, "Aufmerksamkeit") {
		t.Errorf("Urteil %q nennt keinen Handlungsbedarf, obwohl ein Dienst ausgefallen ist",
			e.Uebersicht.UrteilText)
	}
	if e.Uebersicht.Punkte == 0 {
		t.Error("die Liste des Handlungsbedarfs ist leer")
	}
	// Grundsatz II: Jede Zahl ist ein Griff. Ein Punkt ohne Weg dorthin ist eine
	// Meldung, die man nur zur Kenntnis nehmen kann.
	if e.Uebersicht.PunkteMitGriff != e.Uebersicht.Punkte {
		t.Errorf("%d von %d Punkten tragen einen Weg zur Behebung",
			e.Uebersicht.PunkteMitGriff, e.Uebersicht.Punkte)
	}
	if e.Uebersicht.Tabellen != 2 {
		t.Errorf("%d Tabellen, erwartet 2 (Dateisysteme und Prozesse)", e.Uebersicht.Tabellen)
	}

	// Jeder Tabellentitel sitzt über seiner Tabelle. Der Fehler, gegen den das
	// geschrieben ist: Zwei Wurzelelemente je Komponente sind im Gitter zwei
	// Zellen — der Titel stand links, die Tabelle rechts. Der DOM-Test war grün,
	// weil beide Elemente da waren.
	if len(e.TitelSitz) == 0 {
		t.Error("kein Tabellentitel gefunden")
	}
	for _, sitz := range e.TitelSitz {
		if !sitz.Gefunden {
			t.Errorf("Titel %q gehört zu keiner Tabelle in derselben Wurzel", sitz.Name)
			continue
		}
		if !sitz.GleicheKante {
			t.Errorf("Titel %q hat nicht die linke Kante seiner Tabelle — er steht daneben, nicht darüber", sitz.Name)
		}
		if !sitz.TitelDarueber {
			t.Errorf("Titel %q sitzt nicht über seiner Tabelle", sitz.Name)
		}
	}

	// Keine Tabelle wird stillschweigend beschnitten. Wenn der Inhalt breiter ist
	// als der Rahmen, muss der Rahmen scrollen — sonst fehlt eine Spalte, ohne
	// dass es jemand merkt. Genau das war der Fall: overflow: hidden am Rahmen.
	for i, r := range e.RahmenSitz {
		if r.InhaltBreite > r.RahmenBreite+1 && r.Scrollbar == "hidden" {
			t.Errorf("Tabelle %d: Inhalt %.0f px in einem Rahmen von %.0f px mit overflow-x: hidden — "+
				"die letzte Spalte ist abgeschnitten, und nichts sagt es",
				i, r.InhaltBreite, r.RahmenBreite)
		}
	}

	// 6. Die weiteren Einhängepunkte klappen auf.
	if e.Zweige.Vorher != 0 {
		t.Errorf("%d Zweigzeilen sind offen, bevor jemand geklickt hat", e.Zweige.Vorher)
	}
	if e.Zweige.Nachher == 0 {
		t.Error("nach dem Klick erscheinen keine weiteren Einhängepunkte")
	}

	// Die Befehlspalette — der offene Punkt aus docs/15-neuordnung.md.
	if len(e.Palette.Schritte) != 2 {
		t.Errorf("die Palette ließ sich nicht auf beiden Wegen öffnen: %v", e.Palette.Schritte)
	}
	if !e.Palette.FokusImFeld {
		t.Error("nach dem Öffnen liegt der Fokus nicht im Suchfeld — man müsste erst hinklicken")
	}
	if e.Palette.ZieleGesamt != 15 {
		t.Errorf("%d Ziele in der Palette, erwartet 15 (dieselben wie in der Seitenleiste)",
			e.Palette.ZieleGesamt)
	}
	// Der Unterschied zwischen einer Suche und einer Liste: ein Wort, das im
	// Namen nicht vorkommt.
	if len(e.Palette.TrefferNginx) == 0 || !strings.Contains(e.Palette.TrefferNginx[0], "Webserver") {
		t.Errorf("die Suche nach \"nginx\" findet den Webserver nicht: %v", e.Palette.TrefferNginx)
	}
	// Wer den Umlaut weglässt, soll finden, was er meint — sonst ist die Suche
	// eine Rechtschreibprüfung.
	if len(e.Palette.TrefferOhneUmlaut) == 0 ||
		!strings.Contains(e.Palette.TrefferOhneUmlaut[0], "bersicht") {
		t.Errorf("die Suche nach \"ubersicht\" ohne Umlaut findet nichts: %v",
			e.Palette.TrefferOhneUmlaut)
	}
	if e.Palette.LeerZustand == "" {
		t.Error("ohne Treffer sagt die Palette nichts — ein leerer Kasten sieht wie ein Fehler aus")
	}
	if !e.Palette.NachEscape {
		t.Error("Escape schließt die Palette nicht")
	}
	if !e.Palette.ZweiteGewaehlt {
		t.Error("der Pfeil nach unten wandert nicht — die Palette ist nur mit der Maus bedienbar")
	}
	// Der Schleier horcht auf Klicks, die Palette liegt darin. Wird das Ziel des
	// Klicks nicht geprüft, schließt jeder Klick ins Suchfeld die Palette wieder.
	if !e.Palette.KlickInnenHaelt {
		t.Error("ein Klick in die Palette schließt sie — man kommt nicht ins Suchfeld")
	}
	if !e.Palette.KlickDaneben {
		t.Error("ein Klick neben die Palette schließt sie nicht — der einzige Ausweg wäre Escape")
	}

	// 7. Schmal: keine waagerechte Scrollerei, Beschriftung sichtbar.
	if e.Schmal.FensterBreite == 0 {
		t.Fatal("die Fensterbreite wurde nicht gemessen")
	}
	// Ein Pixel Toleranz für Rundung; mehr wäre echtes Überlaufen.
	if e.Schmal.KoerperBreite > e.Schmal.FensterBreite+1 {
		t.Errorf("der Seitenkörper ist %.0f Pixel breit bei %.0f Pixeln Fenster — "+
			"er scrollt waagerecht, und genau das war der Befund aus rc.3",
			e.Schmal.KoerperBreite, e.Schmal.FensterBreite)
	}
	if e.Schmal.Beschriftung == "" || e.Schmal.Beschriftung == "none" {
		t.Errorf("die Zellen zeigen im Schmalmodus keine Spaltenbeschriftung (%q) — "+
			"in der Kartenansicht stünde dort ein Wert ohne Namen", e.Schmal.Beschriftung)
	}
}
