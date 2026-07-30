package httpd

// Tests für /api/v1/logs und den Journalstrom.
//
// Der Journalstrom ist etwas anderes als ein Vorgang, und die Tests prüfen
// genau diesen Unterschied: Er hat kein Ende, das der Server bestimmt, also muss
// er offen bleiben, bis der Betrachter geht; er kostet je Zuschauer einen
// Prozess, also muss es eine Obergrenze geben; und er darf nicht mehr zeigen,
// als die Abfrage vorher hergab — sonst wäre eine Stufenbeschränkung beim
// Umschalten auf „verfolgen" wirkungslos.

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// Die neuesten Zeilen oben: Wer ein Journal öffnet, sucht das Letzte.
// journalctl liefert aufsteigend, also dreht der Server um.
func TestAPILogsNeuesteZuerst(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)
	basis := time.Now().Add(-time.Hour)
	ops.logs = []privops.LogEntry{
		{At: basis, Unit: "a.service", Priority: 6, Message: "alt"},
		{At: basis.Add(time.Minute), Unit: "b.service", Priority: 3, Message: "neu"},
	}

	var antwort apiLogs
	mussJSON(t, get(t, s, "/api/v1/logs", cookie), &antwort)

	if len(antwort.Zeilen) != 2 {
		t.Fatalf("%d Zeilen, erwartet 2", len(antwort.Zeilen))
	}
	if antwort.Zeilen[0].Nachricht != "neu" {
		t.Errorf("erste Zeile ist %q, erwartet die neueste", antwort.Zeilen[0].Nachricht)
	}
	// Die Grenze für „ernst" zieht der Server, damit sie einmal steht.
	if !antwort.Zeilen[0].Ernst {
		t.Error("eine Zeile mit Priorität 3 ist nicht als ernst markiert")
	}
	if antwort.Zeilen[1].Ernst {
		t.Error("eine Zeile mit Priorität 6 ist als ernst markiert")
	}
	// Die Anzeigezeit hat eine feste Breite; ohne sie müsste der Browser
	// formatieren und käme auf eine andere Fassung als der Dienst-Inspektor.
	if antwort.Zeilen[0].Zeit == "" {
		t.Error("die Anzeigezeit fehlt")
	}
	if antwort.Zeilen[0].Stufe == "" {
		t.Error("der Stufenname fehlt — eine Zahl allein sagt niemandem etwas")
	}
}

// Die Antwort sagt, was der Server verstanden hat. Wer eine Grenze
// überschreitet, deren Deckel er nicht kennt, sieht sonst eine Liste mit 200
// Zeilen, die nach 100000 gefragt wurde — und hält das Journal für leer.
func TestAPILogsAntwortNenntDieVerstandeneAbfrage(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)

	var antwort apiLogs
	mussJSON(t, get(t, s,
		"/api/v1/logs?unit=ssh.service&priority=3&since=-1h&q=fehler&limit=100000",
		cookie), &antwort)

	if antwort.Abfrage.Unit != "ssh.service" || antwort.Abfrage.Stufe != 3 ||
		antwort.Abfrage.Seit != "-1h" || antwort.Abfrage.Suche != "fehler" {
		t.Errorf("Abfrage = %+v, erwartet die übergebenen Filter", antwort.Abfrage)
	}
	if antwort.Abfrage.Anzahl != 1000 {
		t.Errorf("Anzahl = %d, erwartet den Deckel 1000", antwort.Abfrage.Anzahl)
	}
}

// Unsinnige Werte fallen auf die Vorgabe zurück, statt die Anfrage abzuweisen:
// Ein Filter aus einem geteilten Verweis, der inzwischen nicht mehr passt, soll
// eine Liste zeigen und keine Fehlerseite.
func TestAPILogsUnsinnigeFilterFallenZurueck(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)

	faelle := []struct {
		adresse string
		stufe   int
		anzahl  int
	}{
		{"/api/v1/logs?priority=99", -1, 200},
		{"/api/v1/logs?priority=abc", -1, 200},
		{"/api/v1/logs?priority=-5", -1, 200},
		{"/api/v1/logs?limit=0", -1, 200},
		{"/api/v1/logs?limit=-3", -1, 200},
	}
	for _, f := range faelle {
		var antwort apiLogs
		mussJSON(t, get(t, s, f.adresse, cookie), &antwort)
		if antwort.Abfrage.Stufe != f.stufe || antwort.Abfrage.Anzahl != f.anzahl {
			t.Errorf("%s: Abfrage = %+v, erwartet Stufe %d / Anzahl %d",
				f.adresse, antwort.Abfrage, f.stufe, f.anzahl)
		}
	}
}

// Leere Felder sind Felder. Und die Unit-Liste darf einzeln scheitern: Sie ist
// die Auswahl im Filter, kein Teil des Ergebnisses.
func TestAPILogsLeereFelderSindFelder(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)
	ops.logs = nil
	ops.units = nil

	rumpf := get(t, s, "/api/v1/logs", cookie).Body.String()
	for _, feld := range []string{`"zeilen":[]`, `"units":[]`} {
		if !strings.Contains(rumpf, feld) {
			t.Errorf("%s fehlt: %s", feld, rumpf)
		}
	}
}

// Der Strom liefert den Rückblick und bleibt dann offen. Das „bleibt offen" ist
// der Unterschied zu einem Vorgang: Ein Journal endet nicht von selbst.
func TestAPILogsStromBleibtOffen(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)
	ops.logs = []privops.LogEntry{
		{At: time.Now(), Unit: "a.service", Priority: 6, Message: "rueckblick"},
	}
	ops.folgeLogs = []privops.LogEntry{
		{At: time.Now(), Unit: "a.service", Priority: 3, Message: "waehrenddessen"},
	}

	// Die Frist ist hier der Betrachter, der die Seite verlässt — im Betrieb der
	// geschlossene Tab. Ein Strom, der von selbst endet, würde diesen Test
	// bestehen und im Betrieb nach dem Rückblick abbrechen.
	rec := stream(t, s, "/api/v1/logs/follow", cookie, 500*time.Millisecond)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}
	if ct := rec.Header().Get("Content-Type"); !strings.HasPrefix(ct, "text/event-stream") {
		t.Errorf("Content-Type = %q, erwartet text/event-stream", ct)
	}

	rumpf := rec.Body.String()
	if !strings.Contains(rumpf, "rueckblick") {
		t.Errorf("der Rückblick fehlt im Strom: %q", rumpf)
	}
	if !strings.Contains(rumpf, "waehrenddessen") {
		t.Errorf("die während des Zusehens hereingekommene Zeile fehlt: %q", rumpf)
	}
	if !strings.Contains(rumpf, "event: zeile") {
		t.Errorf("die Zeilen kommen nicht als Ereignis %q: %q", "zeile", rumpf)
	}
	// Kein „ende", weil der Strom nicht von selbst endete — der Betrachter ging.
	// Stünde hier ein Ende, hätte die Attrappe nach dem Rückblick zurückgekehrt.
	if strings.Contains(rumpf, "event: ende") {
		t.Error("der Strom hat sich selbst beendet — ein Journal endet nicht von selbst")
	}

	// Und der Zähler ist danach wieder frei: Der defer im Handler muss greifen,
	// auch wenn die Verbindung abbricht. Ohne das wäre die Obergrenze nach vier
	// geschlossenen Tabs für immer erreicht.
	if n := s.logFolger.Load(); n != 0 {
		t.Errorf("nach dem Abbruch sind noch %d Folger gezählt, erwartet 0", n)
	}
}

// Die Filter gelten auch für den Strom. Zeigte er mehr als die Abfrage, wäre
// eine Stufenbeschränkung beim Umschalten auf „verfolgen" wirkungslos.
func TestAPILogsStromUebernimmtDieFilter(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)

	stream(t, s, "/api/v1/logs/follow?unit=ssh.service&priority=3", cookie,
		200*time.Millisecond)

	if !enthaelt(ops.recorded(), "logs:follow:ssh.service") {
		t.Errorf("die Unit wurde nicht an privops weitergegeben: %v", ops.recorded())
	}
}

// Höchstens vier Zuschauer. Jeder hält einen journalctl-Prozess, weil jeder
// seinen eigenen Filter hat — anders als bei einem Vorgang, den alle teilen.
func TestAPILogsObergrenzeDerZuschauer(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)

	// maxLogFolger Ströme parallel offen halten, dann einen weiteren versuchen.
	var wg sync.WaitGroup
	for i := 0; i < maxLogFolger; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			stream(t, s, "/api/v1/logs/follow", cookie, 700*time.Millisecond)
		}()
	}

	// Warten, bis alle vier gezählt sind — auf eine Dauer zu hoffen wäre auf
	// einer langsamen Maschine ein Test, der manchmal durchgeht.
	frist := time.Now().Add(3 * time.Second)
	for s.logFolger.Load() < maxLogFolger && time.Now().Before(frist) {
		time.Sleep(10 * time.Millisecond)
	}
	if n := s.logFolger.Load(); n != maxLogFolger {
		t.Fatalf("%d Folger gezählt, erwartet %d", n, maxLogFolger)
	}

	rec := get(t, s, "/api/v1/logs/follow", cookie)
	if rec.Code != http.StatusTooManyRequests {
		t.Errorf("Status = %d, erwartet 429", rec.Code)
	}
	if ct := rec.Header().Get("Content-Type"); !strings.HasPrefix(ct, "application/json") {
		t.Errorf("Content-Type = %q, erwartet JSON", ct)
	}

	// Und die Abfrage sagt es der Oberfläche, damit sie den Knopf gleich richtig
	// zeigt statt ihn anzubieten und abgewiesen zu werden.
	var antwort apiLogs
	mussJSON(t, get(t, s, "/api/v1/logs", cookie), &antwort)
	if antwort.FolgerFrei {
		t.Error("die Abfrage meldet freie Plätze, obwohl die Obergrenze erreicht ist")
	}

	wg.Wait()
	if n := s.logFolger.Load(); n != 0 {
		t.Errorf("nach dem Ende sind noch %d Folger gezählt, erwartet 0", n)
	}
}

// Lesen darf jede Rolle — dieselbe Grenze wie bei GET /logs in der alten
// Oberfläche. Ein Journal zu lesen verändert nichts.
func TestAPILogsAuchMitLeserecht(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleReadOnly)

	if rec := get(t, s, "/api/v1/logs", cookie); rec.Code != http.StatusOK {
		t.Errorf("Abfrage: Status = %d, erwartet 200", rec.Code)
	}
	rec := stream(t, s, "/api/v1/logs/follow", cookie, 150*time.Millisecond)
	if rec.Code != http.StatusOK {
		t.Errorf("Strom: Status = %d, erwartet 200", rec.Code)
	}
}

// Ohne Anmeldung nichts, und die Antwort ist JSON.
func TestAPILogsBrauchtAnmeldung(t *testing.T) {
	s := newTestServer(t)

	for _, pfad := range []string{"/api/v1/logs", "/api/v1/logs/follow"} {
		rec := get(t, s, pfad, nil)
		if rec.Code != http.StatusUnauthorized {
			t.Errorf("%s: Status = %d, erwartet 401", pfad, rec.Code)
		}
		if ct := rec.Header().Get("Content-Type"); !strings.HasPrefix(ct, "application/json") {
			t.Errorf("%s: Content-Type = %q, erwartet JSON", pfad, ct)
		}
	}
}

// Der Ereignisstrom trägt einen Herzschlag als Kommentar. Ein Reverse-Proxy
// schließt eine stille Verbindung nach einer Minute, und ein ruhiges Journal ist
// genau das: still.
//
// Geprüft wird das Format und nicht der Takt: 25 Sekunden abzuwarten wäre ein
// Test, den niemand laufen lässt. Dass der Ticker läuft, sagt der Code; dass ein
// Kommentar kein Ereignis auslöst, ist die Eigenschaft, auf die es ankommt.
func TestSSEHerzschlagIstEinKommentar(t *testing.T) {
	rec := httptest.NewRecorder()
	rc := http.NewResponseController(rec)
	if _, err := rec.Write([]byte(": still\n\n")); err != nil {
		t.Fatalf("schreiben: %v", err)
	}
	_ = rc.Flush()

	rumpf := rec.Body.String()
	if !strings.HasPrefix(rumpf, ":") {
		t.Errorf("der Herzschlag ist kein Kommentar: %q", rumpf)
	}
	if strings.Contains(rumpf, "event:") || strings.Contains(rumpf, "data:") {
		t.Errorf("der Herzschlag löst ein Ereignis aus: %q", rumpf)
	}
}
