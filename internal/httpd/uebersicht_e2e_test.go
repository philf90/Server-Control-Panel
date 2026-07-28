package httpd

import (
	"encoding/json"
	"math"
	"net/http/httptest"
	"os"
	"os/exec"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/metrics"
	"github.com/philf90/asylum/internal/store"
)

// TestUebersichtBrowser fährt die Übersicht in einem echten Browser.
//
// Drei Fragen, die kein Go-Test beantwortet:
//
//  1. Ist der Strich der Sparklines gleichmäßig? Der viewBox wird waagerecht
//     stärker gestreckt als senkrecht; ohne vector-effect: non-scaling-stroke
//     zieht der Browser die Strichstärke mit. Gemessen wird am Endpunkt: Ein
//     runder Punkt ist so breit wie hoch.
//  2. Zeigt der Mouseover den Messwert? Die Stelle des Kastens setzt spark.js
//     über das CSSOM. Ob die Content-Security-Policy das durchlässt, sagt nur
//     der Browser — das Projekt ist an dieser Stelle schon einmal gescheitert
//     (Auslastungsbalken in rc.5).
//  3. Klappen die weiteren Einhängepunkte auf? Der Umschalter kommt ohne
//     JavaScript aus und braucht dafür :has() im Stylesheet.
//
// Bewusst hinter einer Umgebungsvariablen: Der Test braucht Node und Chromium
// und läuft nicht in jeder CI. Aufruf:
//
//	ASYLUM_UEBERSICHT_E2E=1 \
//	  ASYLUM_NODE=/opt/node22/bin/node \
//	  ASYLUM_NODE_PATH=/opt/node22/lib/node_modules \
//	  ASYLUM_CHROMIUM=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
//	  go test ./internal/httpd -run TestUebersichtBrowser -v
func TestUebersichtBrowser(t *testing.T) {
	if os.Getenv("ASYLUM_UEBERSICHT_E2E") == "" {
		t.Skip("ohne ASYLUM_UEBERSICHT_E2E nichts zu tun (braucht Node und Chromium)")
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

	cmd := exec.Command(node, "testdata/uebersicht_e2e.js")
	cmd.Env = append(os.Environ(),
		"ASYLUM_E2E_URL="+ts.URL,
		"ASYLUM_E2E_COOKIE="+cookie.Name+"="+cookie.Value,
		"ASYLUM_CHROMIUM="+chromium,
	)
	if p := os.Getenv("ASYLUM_NODE_PATH"); p != "" {
		cmd.Env = append(cmd.Env, "NODE_PATH="+p)
	}
	// ASYLUM_E2E_SHOTS legt Bilder der geprüften Stellen ab. Messwerte sagen,
	// dass es stimmt; ein Bild sagt, wie es aussieht.
	if p := os.Getenv("ASYLUM_E2E_SHOTS"); p != "" {
		cmd.Env = append(cmd.Env, "ASYLUM_E2E_SHOTS="+p)
	}

	ausgabe, err := cmd.CombinedOutput()
	t.Logf("Treiber:\n%s", ausgabe)
	if err != nil {
		t.Fatalf("Browsertreiber: %v", err)
	}

	var e ergebnisUebersicht
	letzte := letzteZeile(string(ausgabe))
	if err := json.Unmarshal([]byte(letzte), &e); err != nil {
		t.Fatalf("Ausgabe des Treibers unlesbar: %v — %q", err, letzte)
	}

	// 1. Die Richtlinie hat nichts verworfen.
	if len(e.Verstoesse) > 0 {
		t.Errorf("die Content-Security-Policy hat etwas verworfen:\n  %s",
			strings.Join(e.Verstoesse, "\n  "))
	}

	// 2. Der Strich ist gleichmäßig.
	if e.Strich.SVGBreite < 150 {
		t.Fatalf("die Kachel ist nur %.0f Pixel breit — dann greift die Messung nicht",
			e.Strich.SVGBreite)
	}
	// Die Streckung ist der Grund für die ganze Vorsicht: 100 Einheiten auf
	// gemessene Breite bei unveränderter Höhe.
	if streckung := e.Strich.SVGBreite / 100; streckung < 1.5 {
		t.Errorf("die Kachel streckt nur um %.1f — die Messung prüft dann nichts", streckung)
	}
	if e.Strich.Effekt != "non-scaling-stroke" {
		t.Errorf("vector-effect = %q — die Strichstärke wird mit der Breite gestreckt", e.Strich.Effekt)
	}
	if e.Strich.Punkt.Anzahl == 0 {
		t.Error("der Endpunkt wird nicht gemalt — malt der Browser ein Segment der Länge null nicht?")
	}
	if breit, hoch := e.Strich.Punkt.Breite, e.Strich.Punkt.Hoehe; math.Abs(float64(breit-hoch)) > 1 {
		t.Errorf("der gemalte Endpunkt ist %d × %d Pixel — ein runder Punkt ist so breit wie hoch. "+
			"Vor dieser Änderung (ein <circle>) waren es 16 × 10: die waagerechte Streckung "+
			"steckt wieder in der Strichstärke", breit, hoch)
	}
	// 60 Stützstellen sind 59 L-Segmente; hier stehen weniger Messungen im
	// Ringpuffer, aber niemals mehr als die Grenze.
	if e.Strich.Stuetzstellen > sparkPunkte {
		t.Errorf("%d Stützstellen im Pfad, höchstens %d erlaubt", e.Strich.Stuetzstellen, sparkPunkte)
	}

	// 3. Der Messwert steht unter dem Zeiger, mit Uhrzeit, und bleibt in der Kachel.
	if !e.Tip.Mitte.Sichtbar {
		t.Error("der Messwertkasten erscheint nicht")
	}
	if !strings.Contains(e.Tip.Mitte.Wert, "%") {
		t.Errorf("der Messwert lautet %q — erwartet wurde eine Prozentangabe", e.Tip.Mitte.Wert)
	}
	if !strings.Contains(e.Tip.Mitte.Zeit, ":") {
		t.Errorf("die Uhrzeit lautet %q", e.Tip.Mitte.Zeit)
	}
	if e.Tip.Mitte.Fuehrung == "" {
		t.Error("die Führungslinie wurde nicht gesetzt")
	}
	if e.Tip.Links.UeberstandLinks > 1 {
		t.Errorf("am linken Rand ragt der Kasten %d Pixel aus der Kachel", e.Tip.Links.UeberstandLinks)
	}
	if !e.Tip.NachherVersteckt {
		t.Error("der Kasten bleibt stehen, nachdem der Zeiger die Kachel verlassen hat")
	}

	// 4. Die weiteren Einhängepunkte klappen auf — und sind vorher eingeklappt.
	if e.Dateisysteme.ZuGeklappt {
		t.Error("die weiteren Einhängepunkte stehen von Anfang an offen")
	}
	if !e.Dateisysteme.AufGeklappt {
		t.Error("die weiteren Einhängepunkte klappen nicht auf — greift :has() nicht?")
	}
	if e.Dateisysteme.Anzahl != 3 {
		t.Errorf("%d aufgeklappte Zeilen, erwartet 3", e.Dateisysteme.Anzahl)
	}
	// Die erste aufgeklappte Zeile trägt Pfad und Zahlen, nicht nur den Pfad —
	// genau das konnte das title-Attribut nicht.
	for _, teil := range []string{"/etc/asylum", "/dev/vda3", "ext4", "40.0 GiB", "15.0 %"} {
		if !strings.Contains(e.Dateisysteme.Unterzeile, teil) {
			t.Errorf("die aufgeklappte Zeile enthält %q nicht: %q", teil, e.Dateisysteme.Unterzeile)
		}
	}

	// 5. Die Netzwerkkachel nennt die echte Schnittstelle.
	if !strings.Contains(e.Netz, "enp1s0") || strings.Contains(e.Netz, "docker0") {
		t.Errorf("die Netzwerkkachel lautet %q — erwartet wurde enp1s0 ohne docker0", e.Netz)
	}
}

// ergebnisUebersicht ist das, was der Browsertreiber als letzte Zeile ausgibt.
type ergebnisUebersicht struct {
	Verstoesse []string `json:"verstoesse"`
	Strich     struct {
		SVGBreite     float64 `json:"svgBreite"`
		SVGHoehe      float64 `json:"svgHoehe"`
		Effekt        string  `json:"effekt"`
		Staerke       string  `json:"staerke"`
		Stuetzstellen int     `json:"stuetzstellen"`
		// Punkt ist der gemalte Endpunkt, aus dem Bildschirmfoto vermessen.
		Punkt struct {
			Anzahl int `json:"anzahl"`
			Breite int `json:"breite"`
			Hoehe  int `json:"hoehe"`
		} `json:"punkt"`
	} `json:"strich"`
	Tip struct {
		Mitte            messwertkasten `json:"mitte"`
		Links            messwertkasten `json:"links"`
		NachherVersteckt bool           `json:"nachherVersteckt"`
	} `json:"tip"`
	Dateisysteme struct {
		ZuGeklappt  bool   `json:"zuGeklappt"`
		AufGeklappt bool   `json:"aufGeklappt"`
		Anzahl      int    `json:"anzahl"`
		Unterzeile  string `json:"unterzeile"`
	} `json:"dateisysteme"`
	Netz string `json:"netz"`
}

type messwertkasten struct {
	Text             string `json:"text"`
	Wert             string `json:"wert"`
	Zeit             string `json:"zeit"`
	Sichtbar         bool   `json:"sichtbar"`
	UeberstandLinks  int    `json:"ueberstandLinks"`
	UeberstandRechts int    `json:"ueberstandRechts"`
	Fuehrung         string `json:"fuehrung"`
}

// fuelleUebersicht legt Ringpuffer und jüngste Messung so an, wie ein Server mit
// Docker und der ausgelieferten systemd-Unit sie liefert.
func fuelleUebersicht(s *Server) {
	const giB = 1 << 30
	jetzt := time.Now()

	for i := 0; i < 40; i++ {
		s.ring.Add(metrics.Snapshot{
			At:     jetzt.Add(time.Duration(i-40) * 30 * time.Minute),
			CPU:    metrics.CPU{Total: 4 + float64(i%9)},
			Memory: metrics.Memory{UsedPct: 30 + float64(i%3)},
			Load:   [3]float64{0.1 + float64(i%5)/50, 0, 0},
			Interfaces: []metrics.Interface{
				{Name: "docker0"},
				{Name: "enp1s0", Physical: true, Primary: true,
					RXRate: float64(i%7) * 1024, TXRate: float64(i%4) * 512},
			},
		})
	}

	s.setLatest(metrics.Snapshot{
		At:         jetzt,
		CPU:        metrics.CPU{Total: 6.4, IOWait: 0.4},
		Memory:     metrics.Memory{Total: 4 * giB, Used: giB, UsedPct: 25},
		Load:       [3]float64{0.18, 0.12, 0.09},
		UptimeText: "8 T 4 Std 0 Min",
		Filesystems: []metrics.Filesystem{
			{
				Mount: "/", Device: "/dev/vda3", Type: "ext4",
				AlsoAt: []string{"/etc/asylum", "/tmp", "/var/lib/asylum"},
				Total:  40 * giB, Used: 6 * giB, UsedPct: 15, InodesPct: 3.2,
			},
			{Mount: "/boot", Device: "/dev/vda2", Type: "ext3", Total: giB, Used: giB / 5, UsedPct: 21.8},
		},
		Interfaces: []metrics.Interface{
			{Name: "docker0", Addrs: []string{"172.17.0.1/16"}},
			{Name: "enp1s0", Addrs: []string{"203.0.113.10/24"}, Physical: true, Primary: true,
				RXRate: 12480, TXRate: 3620},
		},
	})
}
