package httpd

// Tests für /api/v1/services.
//
// Der Schwerpunkt liegt nicht auf „liefert JSON", sondern auf den drei Stellen,
// an denen ein Fehler nicht auffällt: der Reihenfolge (gescheitert zuerst), den
// Zählern, und der Rückfrage vor dem Stoppen. Die letzte ist die wichtigste —
// bis 0.3.0-rc.5 waren dreizehn Rückfragen im Projekt so gebaut, dass keine
// einzige gefragt hat, und ein Test hätte das gesehen.

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// postJSON schickt einen JSON-Körper mit dem Token in der Kopfzeile — den Weg,
// den die neue Oberfläche nimmt.
func postJSON(t *testing.T, s *Server, path, body string, cookie *http.Cookie, csrf string) *httptest.ResponseRecorder {
	t.Helper()
	req := httptest.NewRequest(http.MethodPost, path, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	if csrf != "" {
		req.Header.Set("X-CSRF-Token", csrf)
	}
	if cookie != nil {
		req.AddCookie(cookie)
	}
	rec := httptest.NewRecorder()
	s.Handler().ServeHTTP(rec, req)
	return rec
}

func angemeldet(t *testing.T, rolle string) (*Server, *http.Cookie, string) {
	t.Helper()
	s := newTestServer(t)
	user := addUser(t, s, "admin", rolle)
	cookie, csrf := login(t, s, user)
	return s, cookie, csrf
}

// Gescheiterte Dienste stehen oben. Das ist der Grund, warum jemand die Seite
// öffnet — sortierte systemctl alphabetisch, stünde nginx.service unter ssh und
// jemand müsste die Liste lesen, um zu sehen, was er längst weiß.
func TestAPIDiensteSortiertGescheitertZuerst(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)

	rec := get(t, s, "/api/v1/services", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}

	var antwort apiDienste
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	if len(antwort.Dienste) != 2 {
		t.Fatalf("%d Dienste, erwartet 2", len(antwort.Dienste))
	}
	if antwort.Dienste[0].Unit != "nginx.service" {
		t.Errorf("erster Dienst ist %q, erwartet nginx.service (gescheitert gehört nach oben)",
			antwort.Dienste[0].Unit)
	}
	if antwort.Dienste[0].Zustand != zustandGescheitert {
		t.Errorf("nginx.service hat Zustand %q, erwartet %q",
			antwort.Dienste[0].Zustand, zustandGescheitert)
	}
	// Der Name ohne .service — gekürzt auf dem Server, damit die Oberfläche es
	// nicht an zwei Stellen tut.
	if antwort.Dienste[0].Name != "nginx" {
		t.Errorf("Name ist %q, erwartet nginx", antwort.Dienste[0].Name)
	}
	if antwort.Zaehler.Gesamt != 2 || antwort.Zaehler.Gescheitert != 1 || antwort.Zaehler.Laeuft != 1 {
		t.Errorf("Zähler = %+v, erwartet 2 gesamt / 1 gescheitert / 1 laufend", antwort.Zaehler)
	}
}

// Die Zähler zählen dieselben Dienste, die auch die Liste einfärbt. Zählte der
// Browser selbst, ginge das auseinander, sobald sich die Regel für
// „gescheitert" ändert — und sie steht in privops, nicht hier.
func TestAPIDiensteZaehlerPasstZurListe(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)
	ops.services = []privops.Service{
		{Unit: "a.service", Active: "active", Sub: "running"},
		{Unit: "b.service", Active: "active", Sub: "failed"}, // failed steckt im Sub!
		{Unit: "c.service", Active: "inactive", Sub: "dead"},
		{Unit: "d.service", Active: "failed", Sub: "failed"},
	}

	var antwort apiDienste
	mussJSON(t, get(t, s, "/api/v1/services", cookie), &antwort)

	gezaehlt := map[string]int{}
	for _, d := range antwort.Dienste {
		gezaehlt[d.Zustand]++
	}
	if gezaehlt[zustandGescheitert] != antwort.Zaehler.Gescheitert {
		t.Errorf("%d gescheiterte Zeilen, aber Zähler sagt %d",
			gezaehlt[zustandGescheitert], antwort.Zaehler.Gescheitert)
	}
	if antwort.Zaehler.Gescheitert != 2 {
		t.Errorf("Zähler gescheitert = %d, erwartet 2 — ein failed im Sub zählt mit",
			antwort.Zaehler.Gescheitert)
	}
	if antwort.Zaehler.Laeuft != 1 || antwort.Zaehler.Aus != 1 {
		t.Errorf("Zähler = %+v, erwartet 1 laufend / 1 aus", antwort.Zaehler)
	}
}

// Die Aktionen im Inspektor passen zum Zustand. „starten" an einem laufenden
// Dienst anzubieten ist eine Frage, die die Oberfläche selbst beantworten kann.
func TestAPIDienstAktionenPassenZumZustand(t *testing.T) {
	faelle := []struct {
		name     string
		svc      privops.Service
		erwartet []string
		nicht    []string
	}{
		{
			name:     "laufend und eingeschaltet",
			svc:      privops.Service{Unit: "a.service", Active: "active", Sub: "running", Enabled: "enabled"},
			erwartet: []string{"restart", "reload", "stop", "disable"},
			nicht:    []string{"start", "enable"},
		},
		{
			name:     "gestoppt und ausgeschaltet",
			svc:      privops.Service{Unit: "b.service", Active: "inactive", Sub: "dead", Enabled: "disabled"},
			erwartet: []string{"start", "enable"},
			nicht:    []string{"stop", "restart", "disable"},
		},
		{
			// static hat kein [Install] — systemctl enable scheitert daran, und
			// ein Knopf, der immer einen Fehler liefert, ist schlimmer als keiner.
			name:     "static kennt kein Ein und Aus",
			svc:      privops.Service{Unit: "c.service", Active: "active", Sub: "running", Enabled: "static"},
			erwartet: []string{"stop"},
			nicht:    []string{"enable", "disable"},
		},
		{
			name:     "masked ebenso",
			svc:      privops.Service{Unit: "d.service", Active: "inactive", Sub: "dead", Enabled: "masked"},
			erwartet: []string{"start"},
			nicht:    []string{"enable", "disable"},
		},
	}

	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			hat := map[string]bool{}
			for _, a := range aktionenFuer(f.svc) {
				hat[a] = true
			}
			for _, a := range f.erwartet {
				if !hat[a] {
					t.Errorf("Aktion %q fehlt, angeboten: %v", a, aktionenFuer(f.svc))
				}
			}
			for _, a := range f.nicht {
				if hat[a] {
					t.Errorf("Aktion %q wird angeboten, sollte aber nicht", a)
				}
			}
		})
	}
}

// Das Detail trägt die Journalzeilen, und ernste Zeilen sind markiert. Wer einen
// gescheiterten Dienst ansieht, sucht genau diese Zeile.
func TestAPIDienstDetailMarkiertErnsteZeilen(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)
	ops.detail = privops.ServiceDetail{
		Service: privops.Service{
			Unit: "nginx.service", Active: "failed", Sub: "failed", Enabled: "enabled",
		},
		MainPID: 1042, Memory: 13 << 20, Tasks: 5,
		FragmentP: "/lib/systemd/system/nginx.service",
		RecentLogs: []privops.LogEntry{
			{At: time.Now(), Priority: 6, Message: "gestartet"},
			{At: time.Now(), Priority: 3, Message: "bind() fehlgeschlagen"},
		},
	}

	var detail apiDienstDetail
	mussJSON(t, get(t, s, "/api/v1/services/nginx.service", cookie), &detail)

	if len(detail.Logzeilen) != 2 {
		t.Fatalf("%d Journalzeilen, erwartet 2", len(detail.Logzeilen))
	}
	if detail.Logzeilen[0].Ernst {
		t.Error("eine Zeile mit Priorität 6 ist als ernst markiert")
	}
	if !detail.Logzeilen[1].Ernst {
		t.Error("eine Zeile mit Priorität 3 ist nicht als ernst markiert")
	}
	if detail.Speicher != "13.0 MiB" {
		t.Errorf("Speicher = %q, erwartet 13.0 MiB — dieselbe Formatierung wie in den Vorlagen",
			detail.Speicher)
	}
	if detail.HauptPID != 1042 || detail.Aufgaben != 5 {
		t.Errorf("PID/Aufgaben = %d/%d, erwartet 1042/5", detail.HauptPID, detail.Aufgaben)
	}
}

// Ohne Accounting liefert systemd keinen Speicherwert. Dann steht dort nichts —
// "0 B" wäre eine Aussage, die der Server nicht hat.
func TestAPIDienstDetailOhneSpeicherwert(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)
	ops.detail = privops.ServiceDetail{
		Service: privops.Service{Unit: "a.service", Active: "active", Sub: "running"},
	}

	var detail apiDienstDetail
	mussJSON(t, get(t, s, "/api/v1/services/a.service", cookie), &detail)

	if detail.Speicher != "" {
		t.Errorf("Speicher = %q, erwartet leer — ohne Accounting gibt es keinen Wert", detail.Speicher)
	}
	// Leeres Feld statt null: Die Oberfläche prüft die Länge, und ein fehlendes
	// Array wäre eine zweite Sonderregel für denselben Fall.
	if !strings.Contains(get(t, s, "/api/v1/services/a.service", cookie).Body.String(), `"logzeilen":[]`) {
		t.Error("logzeilen ist null statt eines leeren Feldes")
	}
}

// Der Kern: Stoppen führt ohne Bestätigung NICHTS aus. Die Rückfrage kommt als
// Text vom Server, damit sie einmal steht — dort, wo sie auch erzwungen wird.
func TestAPIDienstStoppenFragtZurueck(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)

	rec := postJSON(t, s, "/api/v1/services/nginx.service", `{"aktion":"stop"}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	for _, a := range ops.recorded() {
		if strings.HasPrefix(a, "service:stop") {
			t.Fatal("die Aktion wurde ohne Bestätigung ausgeführt — genau der Befund aus rc.5")
		}
	}

	var antwort apiBestaetigungAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	if antwort.Bestaetigung.Frage == "" || !strings.Contains(antwort.Bestaetigung.Frage, "nginx.service") {
		t.Errorf("die Frage nennt das Ziel nicht: %q", antwort.Bestaetigung.Frage)
	}
	if len(antwort.Bestaetigung.Punkte) == 0 {
		t.Error("die Rückfrage nennt keine Folgen — dann befähigt sie zu keiner Entscheidung")
	}
	// Stufe 2: ein zweiter Klick genügt, kein getipptes Wort.
	if antwort.Bestaetigung.Tippen != "" {
		t.Errorf("Stoppen verlangt ein getipptes Wort (%q) — laut docs/14 ist es Stufe 2",
			antwort.Bestaetigung.Tippen)
	}

	// Mit dem Feld läuft sie.
	rec = postJSON(t, s, "/api/v1/services/nginx.service",
		`{"aktion":"stop","bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("bestätigt: Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	if !enthaelt(ops.recorded(), "service:stop:nginx.service") {
		t.Errorf("die bestätigte Aktion lief nicht: %v", ops.recorded())
	}
}

// Was umkehrbar ist, fragt nicht. Ein Dialog vor jeder Kleinigkeit erzieht zum
// Wegklicken und entwertet die Rückfrage dort, wo sie zählt.
func TestAPIDienstNeustartFragtNicht(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)

	for _, aktion := range []string{"start", "restart", "reload", "enable", "disable"} {
		rec := postJSON(t, s, "/api/v1/services/ssh.service",
			`{"aktion":"`+aktion+`"}`, cookie, csrf)
		if rec.Code != http.StatusOK {
			t.Errorf("%s: Status = %d, erwartet 200: %s", aktion, rec.Code, rec.Body.String())
		}
		if !enthaelt(ops.recorded(), "service:"+aktion+":ssh.service") {
			t.Errorf("%s lief nicht: %v", aktion, ops.recorded())
		}
	}
}

// Die Antwort trägt den neu gelesenen Zustand. Ohne ihn zeigte die Oberfläche
// nach einem Neustart den alten — und das sieht genauso aus wie ein Neustart,
// der nicht geklappt hat.
func TestAPIDienstAktionAntwortetMitFrischemZustand(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)
	ops.detail = privops.ServiceDetail{
		Service: privops.Service{Unit: "ssh.service", Active: "active", Sub: "running", Enabled: "enabled"},
	}

	var antwort apiAktionAntwort
	mussJSON(t, postJSON(t, s, "/api/v1/services/ssh.service",
		`{"aktion":"restart"}`, cookie, csrf), &antwort)

	if antwort.Meldung == "" {
		t.Error("die Antwort sagt nicht, was passiert ist")
	}
	if antwort.Detail.Unit != "ssh.service" || antwort.Detail.Zustand != zustandLaeuft {
		t.Errorf("Detail = %+v, erwartet ssh.service im Zustand %q",
			antwort.Detail, zustandLaeuft)
	}
}

// Eine unbekannte Aktion wird abgewiesen, nicht durchgereicht. Es gibt keinen
// Weg, eine beliebige systemctl-Unteraktion an privops zu geben.
func TestAPIDienstUnbekannteAktionAbgewiesen(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)

	rec := postJSON(t, s, "/api/v1/services/ssh.service", `{"aktion":"mask"}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
	}
	if len(ops.recorded()) != 0 {
		t.Errorf("es wurde etwas ausgeführt: %v", ops.recorded())
	}
}

// Ohne Token läuft nichts — und die Antwort ist JSON, nicht eine HTML-Seite. Ein
// fetch, das HTML bekommt, meldet einen Parserfehler statt der Ursache.
func TestAPIDienstOhneTokenUndOhneSchreibrecht(t *testing.T) {
	t.Run("ohne Token", func(t *testing.T) {
		s, cookie, _ := angemeldet(t, store.RoleAdmin)
		ops := s.ops.(*fakeOps)

		rec := postJSON(t, s, "/api/v1/services/ssh.service", `{"aktion":"restart"}`, cookie, "")
		if rec.Code != http.StatusForbidden {
			t.Errorf("Status = %d, erwartet 403", rec.Code)
		}
		if ct := rec.Header().Get("Content-Type"); !strings.HasPrefix(ct, "application/json") {
			t.Errorf("Content-Type = %q, erwartet JSON", ct)
		}
		if len(ops.recorded()) != 0 {
			t.Errorf("es wurde etwas ausgeführt: %v", ops.recorded())
		}
	})

	t.Run("mit falschem Token", func(t *testing.T) {
		s, cookie, _ := angemeldet(t, store.RoleAdmin)
		rec := postJSON(t, s, "/api/v1/services/ssh.service",
			`{"aktion":"restart"}`, cookie, "falsch")
		if rec.Code != http.StatusForbidden {
			t.Errorf("Status = %d, erwartet 403", rec.Code)
		}
	})

	t.Run("nur Leserecht", func(t *testing.T) {
		s, cookie, csrf := angemeldet(t, store.RoleReadOnly)
		ops := s.ops.(*fakeOps)

		rec := postJSON(t, s, "/api/v1/services/ssh.service", `{"aktion":"restart"}`, cookie, csrf)
		if rec.Code != http.StatusForbidden {
			t.Errorf("Status = %d, erwartet 403: %s", rec.Code, rec.Body.String())
		}
		// Die Reihenfolge zählt: Wer kein Schreibrecht hat, soll das erfahren
		// und nicht „Token fehlt" — sonst lädt die Oberfläche neu, holt ein
		// frisches Token und bekommt denselben Fehler wieder.
		if !strings.Contains(rec.Body.String(), "Schreibrecht") {
			t.Errorf("die Meldung nennt nicht das fehlende Schreibrecht: %s", rec.Body.String())
		}
		if len(ops.recorded()) != 0 {
			t.Errorf("es wurde etwas ausgeführt: %v", ops.recorded())
		}
	})

	t.Run("gar nicht angemeldet", func(t *testing.T) {
		s := newTestServer(t)
		rec := postJSON(t, s, "/api/v1/services/ssh.service", `{"aktion":"restart"}`, nil, "")
		if rec.Code != http.StatusUnauthorized {
			t.Errorf("Status = %d, erwartet 401", rec.Code)
		}
		if ct := rec.Header().Get("Content-Type"); !strings.HasPrefix(ct, "application/json") {
			t.Errorf("Content-Type = %q, erwartet JSON — sonst sieht ein fetch einen Parserfehler", ct)
		}
	})
}

// Ein Tippfehler im Feldnamen darf nicht stillschweigend eine unbeantwortete
// Rückfrage ergeben, die der Aufrufer für beantwortet hält.
func TestAPIDienstUnbekanntesFeldAbgewiesen(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)

	rec := postJSON(t, s, "/api/v1/services/nginx.service",
		`{"aktion":"stop","bestaetig":true}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
	}
}

// Stufe 3 gibt es hier noch nicht, die Prüfung des getippten Wortes aber schon —
// sie ist der Teil, den die Module ab 0.5 brauchen (Ordner löschen, ufw aus).
func TestAPIBestaetigtPruefstDasGetippteWort(t *testing.T) {
	s := newTestServer(t)
	frage := apiBestaetigung{Titel: "Test", Frage: "wirklich?", Tippen: "vm"}

	faelle := []struct {
		name    string
		anfrage apiAktionAnfrage
		durch   bool
	}{
		{"ohne Bestätigung", apiAktionAnfrage{}, false},
		{"bestätigt, aber nichts getippt", apiAktionAnfrage{Bestaetigt: true}, false},
		{"falsches Wort", apiAktionAnfrage{Bestaetigt: true, Getippt: "andere"}, false},
		{"richtiges Wort", apiAktionAnfrage{Bestaetigt: true, Getippt: "vm"}, true},
		// EqualFold: Auf einem Telefon macht die Tastatur aus "vm" gern "Vm".
		{"andere Schreibung", apiAktionAnfrage{Bestaetigt: true, Getippt: "VM"}, true},
		{"mit Leerraum", apiAktionAnfrage{Bestaetigt: true, Getippt: "  vm "}, true},
	}

	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			rec := httptest.NewRecorder()
			if got := s.apiBestaetigt(rec, f.anfrage, frage); got != f.durch {
				t.Errorf("apiBestaetigt = %v, erwartet %v", got, f.durch)
			}
			if !f.durch && rec.Code != http.StatusConflict {
				t.Errorf("Status = %d, erwartet 409", rec.Code)
			}
		})
	}
}

// Ein falsch getipptes Wort schickt die Frage erneut — mit dem Grund darin.
// Ohne den stünde derselbe Dialog wieder da, und niemand wüsste, warum.
func TestAPIBestaetigtNenntDenGrundBeimZweitenAnlauf(t *testing.T) {
	s := newTestServer(t)
	rec := httptest.NewRecorder()
	s.apiBestaetigt(rec, apiAktionAnfrage{Bestaetigt: true, Getippt: "falsch"},
		apiBestaetigung{Titel: "Test", Frage: "wirklich?", Tippen: "vm"})

	var antwort apiBestaetigungAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	if antwort.Bestaetigung.Fehler == "" {
		t.Error("die erneute Frage nennt nicht, dass das Wort nicht passte")
	}
	if antwort.Bestaetigung.TippenHinweis == "" {
		t.Error("der Hinweis, was zu tippen ist, fehlt")
	}
}

// Solange beide Oberflächen laufen, müssen Verweise des Handlungsbedarfs in die
// Oberfläche zeigen, in der man gerade steht. Ein Signal, das aus der neuen
// hinausführt, kostet die Auswahl und den Weg zurück.
func TestAPISignaleVerweisenAufDieNeueOberflaeche(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)

	var antwort apiSignale
	mussJSON(t, get(t, s, "/api/v1/signals", cookie), &antwort)

	gefunden := false
	for _, sig := range antwort.Signale {
		if sig.AktionHref == "/services" {
			t.Errorf("das Signal %q verweist noch auf die alte Dienstseite", sig.Titel)
		}
		if sig.AktionHref == "/v2/dienste" {
			gefunden = true
		}
	}
	if !gefunden {
		t.Error("kein Signal verweist auf /v2/dienste — die Attrappe hat einen " +
			"gescheiterten Dienst, also muss es eines geben")
	}

	// Die alte Oberfläche behält ihre eigenen Verweise: Sie ist eingefroren,
	// und ein Verweis von dort in die neue wäre eine Änderung an ihr.
	alt := get(t, s, "/", cookie)
	if alt.Code == http.StatusOK && strings.Contains(alt.Body.String(), "/v2/dienste") {
		t.Error("die alte Übersicht verweist auf /v2/dienste — sie sollte unberührt bleiben")
	}
}

// mussJSON liest eine Antwort und bricht ab, wenn sie nicht 200 ist oder kein
// JSON trägt. Sonst steht dieselbe Prüfung in jedem Test dreimal.
func mussJSON(t *testing.T, rec *httptest.ResponseRecorder, ziel any) {
	t.Helper()
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	if err := json.Unmarshal(rec.Body.Bytes(), ziel); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v — %s", err, rec.Body.String())
	}
}

func enthaelt(liste []string, wert string) bool {
	for _, e := range liste {
		if e == wert {
			return true
		}
	}
	return false
}
