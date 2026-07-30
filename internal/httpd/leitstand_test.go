package httpd

import (
	"encoding/json"
	"io/fs"
	"net/http"
	"regexp"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/metrics"
	"github.com/philf90/asylum/internal/store"
	"github.com/philf90/asylum/internal/ui"
)

// Die neue Oberfläche ist ein gebautes Bundle. Der Bau ist nicht Teil des
// Go-Testlaufs, das Ergebnis aber Teil des Binaries — diese Tests prüfen
// deshalb das eingebettete Ergebnis und die Wege, die es ausliefern.

// Die Richtlinie des Panels erlaubt nur `script-src 'self'`. Vite fügt den
// Polyfill für modulepreload als INLINE-Skript ein, sobald es dynamische
// Importe gibt — dann verwirft der Browser ihn, und die Seite bleibt leer. In
// web/vite.config.js ist er ausdrücklich abgeschaltet; dieser Test hält fest,
// dass die Einstellung wirkt. Genau an dieser Klasse von Fehlern ist der
// CodeMirror-Editor schon einmal gescheitert, und dort fiel es erst im Browser
// auf.
func TestNeueOberflaecheOhneInlineSkriptUndStil(t *testing.T) {
	dist, err := ui.Dist()
	if err != nil {
		t.Fatalf("Dist(): %v", err)
	}
	roh, err := fs.ReadFile(dist, "index.html")
	if err != nil {
		t.Fatalf("index.html: %v", err)
	}
	huelle := string(roh)

	// Jedes <script> muss leer sein — Inhalt wäre ein Inline-Skript. Geprüft
	// wird der Rumpf zwischen den Marken, nicht "irgendwas nach dem >": Sonst
	// zählt das schließende </script> selbst als Inhalt (dieser Test hat damit
	// zuerst falsch angeschlagen).
	for _, block := range regexp.MustCompile(`(?s)<script[^>]*>(.*?)</script>`).FindAllStringSubmatch(huelle, -1) {
		if strings.TrimSpace(block[1]) != "" {
			t.Errorf("die Hülle enthält ein Inline-Skript — die CSP würde es verwerfen: %q", block[1])
		}
	}
	if strings.Contains(huelle, "style=") {
		t.Error("die Hülle enthält ein style-Attribut — die CSP würde es verwerfen")
	}
	if !strings.Contains(huelle, `src="/v2/assets/`) {
		t.Error("die Hülle verweist nicht auf /v2/assets/ — base in vite.config.js passt nicht zur Route")
	}
}

// Die Assets tragen den Inhaltshash im Namen; deshalb dürfen sie dauerhaft im
// Zwischenspeicher liegen. Die Hülle darf es nicht: Sie nennt die gehashten
// Namen, und eine behaltene Hülle zeigte nach einem Update auf Dateien, die es
// nicht mehr gibt — die Seite bliebe weiß, bis jemand von Hand neu lädt.
func TestV2HuelleUnzwischenspeicherbarAssetsDauerhaft(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, _ := login(t, s, user)

	huelle := get(t, s, "/v2/", cookie)
	if huelle.Code != http.StatusOK {
		t.Fatalf("/v2/ antwortet %d", huelle.Code)
	}
	if cc := huelle.Header().Get("Cache-Control"); cc != "no-store" {
		t.Errorf("die Hülle trägt Cache-Control %q, erwartet no-store", cc)
	}

	// Den Namen des Assets aus der Hülle lesen, statt ihn zu erfinden: Er
	// ändert sich mit jedem Bau.
	treffer := regexp.MustCompile(`/v2/(assets/[^"]+\.js)`).FindStringSubmatch(huelle.Body.String())
	if treffer == nil {
		t.Fatal("in der Hülle steht kein Asset-Pfad")
	}

	asset := get(t, s, "/v2/"+treffer[1], cookie)
	if asset.Code != http.StatusOK {
		t.Fatalf("%s antwortet %d", treffer[1], asset.Code)
	}
	if cc := asset.Header().Get("Cache-Control"); !strings.Contains(cc, "immutable") {
		t.Errorf("das Asset trägt Cache-Control %q, erwartet immutable", cc)
	}
}

// Ein Pfad, den die Wegewahl im Browser auflöst, muss dieselbe Hülle bekommen
// wie /v2/ — sonst zeigt ein neu geladener Unterpfad einen 404 statt der
// Anwendung.
func TestV2UnbekannterPfadLiefertDieHuelle(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, _ := login(t, s, user)

	antwort := get(t, s, "/v2/dienste/nginx.service", cookie)
	if antwort.Code != http.StatusOK {
		t.Fatalf("Unterpfad antwortet %d, erwartet 200", antwort.Code)
	}
	if !strings.Contains(antwort.Body.String(), `id="app"`) {
		t.Error("der Unterpfad liefert nicht die Hülle der Anwendung")
	}
}

// Die neue Oberfläche ist nicht öffentlich. Sie hängt an derselben Sitzung wie
// alles andere; ohne Anmeldung darf sie nicht einmal ihre Hülle zeigen.
func TestV2UndAPIVerlangenAnmeldung(t *testing.T) {
	s := newTestServer(t)

	for _, pfad := range []string{"/v2/", "/api/v1/session", "/api/v1/overview", "/api/v1/metrics/history"} {
		t.Run(pfad, func(t *testing.T) {
			antwort := get(t, s, pfad, nil)
			if antwort.Code == http.StatusOK {
				t.Fatalf("%s antwortet ohne Anmeldung mit 200", pfad)
			}
		})
	}
}

// Der Fall, für den redirectToLogin ergänzt wurde: Eine abgelaufene Sitzung
// beantwortete jede Anfrage mit einer Weiterleitung auf die Anmeldeseite. Für
// ein fetch heißt das HTML statt JSON — die Oberfläche meldet dann einen
// Parserfehler und verdeckt damit die eigentliche Ursache. Unter /api/ muss der
// Statuscode die Auskunft tragen.
func TestAPIAntwortetOhneSitzungMitJSON(t *testing.T) {
	s := newTestServer(t)

	antwort := get(t, s, "/api/v1/overview", nil)
	if antwort.Code != http.StatusUnauthorized {
		t.Errorf("Status %d, erwartet 401", antwort.Code)
	}
	if typ := antwort.Header().Get("Content-Type"); !strings.HasPrefix(typ, "application/json") {
		t.Errorf("Content-Type %q, erwartet application/json", typ)
	}
	var rumpf map[string]string
	if err := json.Unmarshal(antwort.Body.Bytes(), &rumpf); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v — Rumpf: %s", err, antwort.Body.String())
	}
	if rumpf["fehler"] == "" {
		t.Error("die Antwort nennt keinen Fehler")
	}
}

// Das CSRF-Token steht nicht in einem Cookie, sondern in der Sitzungszeile. Die
// alte Oberfläche bekam es in jede gerenderte Seite; eine SPA bekommt kein
// gerendertes HTML und braucht diesen Endpunkt, sonst kann sie nichts schreiben.
func TestAPISitzungLiefertRolleUndCSRF(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "leser", store.RoleReadOnly)
	cookie, csrf := login(t, s, user)

	antwort := get(t, s, "/api/v1/session", cookie)
	if antwort.Code != http.StatusOK {
		t.Fatalf("Status %d", antwort.Code)
	}

	var got apiSitzung
	if err := json.Unmarshal(antwort.Body.Bytes(), &got); err != nil {
		t.Fatalf("kein JSON: %v", err)
	}
	if got.Benutzer != "leser" {
		t.Errorf("Benutzer %q, erwartet leser", got.Benutzer)
	}
	if got.Rolle != store.RoleReadOnly {
		t.Errorf("Rolle %q, erwartet %q", got.Rolle, store.RoleReadOnly)
	}
	// Eine Rolle ohne Schreibrecht muss das auch sagen — die Oberfläche blendet
	// danach ihre Knöpfe aus. Erzwungen wird es weiterhin serverseitig.
	if got.DarfSchreiben {
		t.Error("ReadOnly wird als schreibberechtigt gemeldet")
	}
	if got.CSRF == "" {
		t.Fatal("kein CSRF-Token in der Antwort")
	}
	if got.CSRF != csrf {
		t.Error("das Token der Schnittstelle ist nicht das der Sitzung")
	}
}

// Die Kachelwerte kommen fertig formatiert vom Server: Einheit, Rundung und
// Sprache stehen damit an einer Stelle. Der Live-Kanal überträgt daneben rohe
// Zahlen — laufen die beiden Formatierungen auseinander, springt die Zahl beim
// ersten Live-Ereignis sichtbar um.
func TestAPIUebersichtLiefertFormatierteWerte(t *testing.T) {
	s := newTestServer(t)
	s.setLatest(metrics.Snapshot{
		At:         time.Now(),
		UptimeText: "8 Tage, 4 Std",
		CPU:        metrics.CPU{Total: 6.42},
		Memory:     metrics.Memory{UsedPct: 30.14, Used: 1 << 30, Total: 4 << 30},
		Load:       [3]float64{0.25, 0.1, 0.1},
		Interfaces: []metrics.Interface{
			{Name: "docker0", RXRate: 0},
			{Name: "eth0", RXRate: 2048, TXRate: 1024, Primary: true},
		},
	})

	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, _ := login(t, s, user)

	var got apiUebersicht
	antwort := get(t, s, "/api/v1/overview", cookie)
	if err := json.Unmarshal(antwort.Body.Bytes(), &got); err != nil {
		t.Fatalf("kein JSON: %v", err)
	}

	if got.Werte.CPU.Wert != "6.4" || got.Werte.CPU.Einheit != "%" {
		t.Errorf("CPU %+v, erwartet {6.4 %%}", got.Werte.CPU)
	}
	// Eine Stelle, nicht zwei: lastText rundet auf zwei und gehört zu den
	// Stützstellen des Verlaufs. Auf der Kachel wären zwei Stellen eine
	// Genauigkeit, die die Zahl nicht hat — dieser Test hat den Fehler gefunden.
	if got.Werte.Load.Wert != "0.2" {
		t.Errorf("Last %q, erwartet \"0.2\"", got.Werte.Load.Wert)
	}
	if got.Werte.Load.Einheit != "" {
		t.Errorf("die Last trägt Einheit %q — sie hat keine", got.Werte.Load.Einheit)
	}
	// Die Netzkachel nennt die Schnittstelle mit der Standardroute, nicht die
	// erste der Liste. Auf jedem Server mit Docker wäre das docker0 — die
	// Kachel stand dauerhaft auf 0 B/s, und der Name daneben machte die falsche
	// Angabe glaubwürdig.
	if got.NetzName != "eth0" {
		t.Errorf("Netzname %q, erwartet eth0", got.NetzName)
	}
	if got.Werte.Netz.Wert != "2.0" || got.Werte.Netz.Einheit != "KiB/s" {
		t.Errorf("Durchsatz %+v, erwartet {2.0 KiB/s}", got.Werte.Netz)
	}
	if got.Snapshot == nil {
		t.Error("die Messung fehlt in der Antwort")
	}
}

// Verlauf und Ablesung entstehen aus einer Rechnung. spark trägt die
// Stützstellen zweimal — einmal als JSON-Zeichenkette für das data-Attribut der
// alten Oberfläche, einmal als Feld für die Schnittstelle. Zwei Felder aus
// einer Rechnung sind in Ordnung; zwei Rechnungen wären es nicht, und dieser
// Test hält den Unterschied fest.
func TestAPIVerlaufPasstZurAltenBerechnung(t *testing.T) {
	s := newTestServer(t)
	jetzt := time.Now()
	for i := range 40 {
		s.ring.Add(metrics.Snapshot{
			At:     jetzt.Add(time.Duration(i) * 30 * time.Second),
			CPU:    metrics.CPU{Total: float64(i % 7)},
			Memory: metrics.Memory{UsedPct: 20 + float64(i%3)},
			Load:   [3]float64{float64(i%4) / 2, 0, 0},
		})
	}

	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, _ := login(t, s, user)

	var got apiVerlaeufe
	antwort := get(t, s, "/api/v1/metrics/history", cookie)
	if err := json.Unmarshal(antwort.Body.Bytes(), &got); err != nil {
		t.Fatalf("kein JSON: %v", err)
	}

	erwartet := s.dashboardSparks()
	if got.CPU.Path != erwartet.CPU.Path {
		t.Error("der Pfad der Schnittstelle weicht von dem der Vorlage ab")
	}
	if !got.CPU.Has {
		t.Fatal("kein Verlauf, obwohl der Ring gefüllt ist")
	}
	if len(got.CPU.Points) != len(erwartet.CPU.Punkte) {
		t.Errorf("%d Stützstellen, erwartet %d", len(got.CPU.Points), len(erwartet.CPU.Punkte))
	}
	// Die Texte der Stützstellen stehen fertig darin — das Skript im Browser
	// sucht den nächsten Punkt, es rechnet nicht.
	for _, p := range got.CPU.Points {
		if p.V == "" || p.T == "" {
			t.Fatalf("Stützstelle ohne Text: %+v", p)
		}
	}
}

// Ohne Messungen gibt es keinen Verlauf — dann muss die Antwort ein leeres Feld
// tragen und nicht null. Die Oberfläche prüft `has`; ein fehlendes Array wäre
// eine zweite Sonderregel für denselben Fall, und die Klasse von Fehlern zeigt
// sich erst zur Laufzeit.
func TestAPIVerlaufOhneMessungenLiefertLeeresFeld(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, _ := login(t, s, user)

	roh := get(t, s, "/api/v1/metrics/history", cookie).Body.String()
	if strings.Contains(roh, `"points":null`) {
		t.Error("die Stützstellen sind null statt eines leeren Feldes")
	}

	var got apiVerlaeufe
	if err := json.Unmarshal([]byte(roh), &got); err != nil {
		t.Fatalf("kein JSON: %v", err)
	}
	if got.CPU.Has {
		t.Error("ohne Messungen wird ein Verlauf behauptet")
	}
	if got.CPU.Points == nil {
		t.Error("Points ist nil — erwartet ein leeres Feld")
	}
}
