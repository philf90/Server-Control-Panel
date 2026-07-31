package httpd

// Tests für den Token-Anmeldeweg.
//
// Das ist die Datei, an der dieser Beitrag hängt. Ein API-Token ist ein zweiter
// Weg an dieselben Endpunkte, und ein zweiter Weg ist die Stelle, an der eine
// Prüfung des ersten fehlt. Geprüft wird deshalb weniger, dass ein Token
// funktioniert, als dass er an den richtigen Stellen NICHT funktioniert:
//
//  1. **Kein Rückfall auf das Cookie.** Ein unbrauchbarer Authorization-Kopf
//     endet mit 401 — er darf nicht die CSRF-Prüfung abschalten und dann die
//     mitgeschickte Sitzung benutzen. Das ist der eigentliche Angriff, gegen den
//     die ganze Datei tokenauth.go gebaut ist.
//  2. **Drei Familien sind gesperrt** (tokens, panel-users, account), egal was in
//     den Scopes steht: Ein Token darf seinen eigenen Widerruf nicht verhindern.
//  3. **Nur unter /api/.** Ein Token kommt an keine gerenderte Seite.
//  4. **Ein Token kann nie mehr als seine Rolle**, und mit read_only weniger.
//  5. **Der Token steht im Protokoll.** Sonst sagt es „philipp hat gestoppt",
//     während es ein Skript war.

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/store"
)

// legeToken legt einen Token an und gibt den Klartext zurück — genau einmal, wie
// im echten Ablauf.
func legeToken(t *testing.T, s *Server, user store.User, anpassen func(*store.APIToken)) string {
	t.Helper()
	klartext, hash, prefix, err := NeuerAPIToken()
	if err != nil {
		t.Fatal(err)
	}
	tok := store.APIToken{
		Hash: hash, Prefix: prefix, Name: "Testskript",
		UserID: user.ID, CreatedAt: time.Now(),
	}
	if anpassen != nil {
		anpassen(&tok)
	}
	if _, err := s.db.CreateAPIToken(t.Context(), tok); err != nil {
		t.Fatal(err)
	}
	return klartext
}

// mitToken stellt eine Anfrage mit Bearer-Token und ohne Cookie.
func mitToken(t *testing.T, s *Server, methode, pfad, koerper, token string) *httptest.ResponseRecorder {
	t.Helper()
	var leser *strings.Reader
	if koerper != "" {
		leser = strings.NewReader(koerper)
	} else {
		leser = strings.NewReader("")
	}
	req := httptest.NewRequest(methode, pfad, leser)
	if koerper != "" {
		req.Header.Set("Content-Type", "application/json")
	}
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
	}
	rec := httptest.NewRecorder()
	s.Handler().ServeHTTP(rec, req)
	return rec
}

// TestTokenLiestUndSchreibt: Der gewöhnliche Weg. Bemerkenswert daran ist, was
// FEHLT — kein Cookie und kein X-CSRF-Token, und der schreibende Aufruf läuft
// trotzdem. Die Begründung steht im Kopf von tokenauth.go: Was CSRF schützt, ist
// eine umgebende Berechtigung, und ein Token ist keine.
func TestTokenLiestUndSchreibt(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	token := legeToken(t, s, user, nil)

	rec := mitToken(t, s, http.MethodGet, "/api/v1/services", "", token)
	if rec.Code != http.StatusOK {
		t.Fatalf("Lesen mit Token = %d: %s", rec.Code, rec.Body.String())
	}

	rec = mitToken(t, s, http.MethodPost, "/api/v1/services/nginx.service",
		`{"aktion":"restart","bestaetigt":true}`, token)
	if rec.Code != http.StatusOK {
		t.Fatalf("Schreiben mit Token = %d, erwartet 200 ohne CSRF-Kopf: %s",
			rec.Code, rec.Body.String())
	}
	ops := zeitplanOps(t, s)
	if !enthaelt(ops.recorded(), "service:restart:nginx.service") {
		t.Errorf("die Aktion lief nicht: %v", ops.recorded())
	}
}

// TestTokenKeinRueckfallAufCookie ist der wichtigste Test dieser Datei.
//
// Ein Angreifer, der eine Kopfzeile setzen kann, aber keinen Token kennt, würde
// einen unsinnigen schicken: Fiele der Server dann auf das mitgeschickte Cookie
// zurück, hätte er die CSRF-Prüfung abgeschaltet und wäre mit der fremden
// Sitzung im Panel. Der Kopf muss deshalb 401 bedeuten und nicht „dann eben das
// Cookie".
func TestTokenKeinRueckfallAufCookie(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	ops := zeitplanOps(t, s)

	// Zur Gegenprobe: Mit Cookie UND Sitzungstoken läuft der Aufruf.
	if rec := postJSON(t, s, "/api/v1/services/nginx.service",
		`{"aktion":"restart","bestaetigt":true}`, cookie, csrf); rec.Code != http.StatusOK {
		t.Fatalf("die Gegenprobe mit Sitzung scheitert schon: %d", rec.Code)
	}
	vorher := len(ops.recorded())

	// Und jetzt derselbe Aufruf mit gültigem Cookie, aber unsinnigem
	// Authorization-Kopf und OHNE Sitzungstoken.
	faelle := map[string]string{
		"erfundener Bearer-Token": "Bearer asy_dieserTokenExistiertNicht",
		"Bearer ohne Wert":        "Bearer ",
		"leerer Bearer":           "Bearer",
		"anderes Verfahren":       "Basic cGhpbGlwcDpnZWhlaW0=",
		"Unsinn":                  "irgendwas",
	}
	for name, kopf := range faelle {
		req := httptest.NewRequest(http.MethodPost, "/api/v1/services/nginx.service",
			strings.NewReader(`{"aktion":"stop","bestaetigt":true}`))
		req.Header.Set("Content-Type", "application/json")
		req.Header.Set("Authorization", kopf)
		req.AddCookie(cookie)
		rec := httptest.NewRecorder()
		s.Handler().ServeHTTP(rec, req)

		if rec.Code == http.StatusOK {
			t.Errorf("%s: Status 200 — der Kopf hat die CSRF-Prüfung abgeschaltet "+
				"und die Sitzung benutzt", name)
			continue
		}
		if rec.Code != http.StatusUnauthorized && rec.Code != http.StatusTooManyRequests {
			t.Errorf("%s: Status = %d, erwartet 401 (oder 429 nach mehreren "+
				"Versuchen): %s", name, rec.Code, rec.Body.String())
		}
	}

	if len(ops.recorded()) != vorher {
		t.Errorf("es liefen Aktionen: %v", ops.recorded()[vorher:])
	}
}

// TestTokenIgnoriertDasCookie: Ist ein gültiger Token da, gilt SEINE Identität —
// nicht die des Cookies. Zwei Identitäten in einer Anfrage wären die Rechte der
// einen mit den Schranken der anderen.
func TestTokenIgnoriertDasCookie(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "eigner", store.RoleOwner)
	leser := addUser(t, s, "leserin", store.RoleReadOnly)
	ownerCookie, _ := login(t, s, owner)

	// Der Token gehört dem NUR-LESE-Konto, das Cookie dem Owner.
	token := legeToken(t, s, leser, nil)

	req := httptest.NewRequest(http.MethodPost, "/api/v1/services/nginx.service",
		strings.NewReader(`{"aktion":"stop","bestaetigt":true}`))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+token)
	req.AddCookie(ownerCookie)
	rec := httptest.NewRecorder()
	s.Handler().ServeHTTP(rec, req)

	if rec.Code != http.StatusForbidden {
		t.Errorf("Status = %d, erwartet 403 — es gilt die Rolle des Tokens, nicht "+
			"die des Cookies: %s", rec.Code, rec.Body.String())
	}
	if ops := zeitplanOps(t, s); len(ops.recorded()) != 0 {
		t.Errorf("die Aktion lief: %v", ops.recorded())
	}
}

// TestTokenGesperrteFamilien: Ein Token darf keine Tokens anlegen, keine Zugänge
// verwalten und den eigenen Anmeldeweg nicht ändern — auch dann nicht, wenn
// jemand die Familie in die Scopes schreibt. Der erste Fall ist der wichtige: Ein
// Widerruf, den ein entwendeter Token durch einen frischen umgehen kann, ist
// keiner.
func TestTokenGesperrteFamilien(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	// Absichtlich MIT den gesperrten Familien in den Scopes: Die Sperre steht im
	// Code und nicht in der Datenbank, und genau das gehört geprüft.
	token := legeToken(t, s, user, func(tok *store.APIToken) {
		tok.Scopes = []string{"tokens", "panel-users", "account", "services"}
	})

	faelle := []struct{ methode, pfad, koerper string }{
		{http.MethodGet, "/api/v1/tokens", ""},
		{http.MethodPost, "/api/v1/tokens", `{"name":"neu"}`},
		{http.MethodGet, "/api/v1/panel-users", ""},
		{http.MethodPost, "/api/v1/panel-users", `{"name":"neu","rolle":"owner"}`},
		{http.MethodGet, "/api/v1/account", ""},
		{http.MethodPost, "/api/v1/account/password", `{"aktuell":"x","neu":"y"}`},
	}
	for _, f := range faelle {
		rec := mitToken(t, s, f.methode, f.pfad, f.koerper, token)
		if rec.Code != http.StatusForbidden {
			t.Errorf("%s %s: Status = %d, erwartet 403: %s",
				f.methode, f.pfad, rec.Code, rec.Body.String())
		}
		if !strings.Contains(rec.Body.String(), "gesperrt") {
			t.Errorf("%s %s: die Meldung nennt den Grund nicht: %s",
				f.methode, f.pfad, rec.Body.String())
		}
	}

	// Gegenprobe: Die erlaubte Familie in derselben Scope-Liste geht.
	if rec := mitToken(t, s, http.MethodGet, "/api/v1/services", "", token); rec.Code != http.StatusOK {
		t.Errorf("die erlaubte Familie = %d: %s", rec.Code, rec.Body.String())
	}
}

// TestTokenNurUnterAPI: Ein Token kommt an keine gerenderte Seite. Ohne diese
// Grenze fände apiFamilie für /services die leere Familie, die Sperrliste griffe
// nicht — und ein Token käme an jede Seite der alten Oberfläche.
func TestTokenNurUnterAPI(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	token := legeToken(t, s, user, nil)

	for _, pfad := range []string{"/", "/dienste", "/zugaenge", "/konto", "/", "/dateien"} {
		rec := mitToken(t, s, http.MethodGet, pfad, "", token)
		if rec.Code != http.StatusForbidden {
			t.Errorf("%s: Status = %d, erwartet 403", pfad, rec.Code)
		}
		if !strings.Contains(rec.Body.String(), "/api/") {
			t.Errorf("%s: die Meldung sagt nicht, wofür ein Token gilt: %s",
				pfad, rec.Body.String())
		}
	}
}

// TestTokenNurLesen: read_only senkt die Rolle für diesen einen Zugang. Ein
// Owner-Token mit read_only darf lesen und nichts sonst.
func TestTokenNurLesen(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	token := legeToken(t, s, user, func(tok *store.APIToken) { tok.ReadOnly = true })

	if rec := mitToken(t, s, http.MethodGet, "/api/v1/services", "", token); rec.Code != http.StatusOK {
		t.Fatalf("Lesen = %d: %s", rec.Code, rec.Body.String())
	}

	rec := mitToken(t, s, http.MethodPost, "/api/v1/services/nginx.service",
		`{"aktion":"stop","bestaetigt":true}`, token)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Schreiben mit Nur-Lese-Token = %d, erwartet 403: %s",
			rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "nur lesen") {
		t.Errorf("die Meldung nennt den Grund nicht: %s", rec.Body.String())
	}
	if ops := zeitplanOps(t, s); len(ops.recorded()) != 0 {
		t.Errorf("die Aktion lief: %v", ops.recorded())
	}
}

// TestTokenScopes: Eine gesetzte Scope-Liste ist eine Allowlist. Leer heißt
// „alle erlaubten" — das ist die Vorgabe und kein Sonderfall.
func TestTokenScopes(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)

	eng := legeToken(t, s, user, func(tok *store.APIToken) {
		tok.Scopes = []string{"overview", "services"}
	})
	if rec := mitToken(t, s, http.MethodGet, "/api/v1/services", "", eng); rec.Code != http.StatusOK {
		t.Errorf("erlaubte Familie = %d: %s", rec.Code, rec.Body.String())
	}
	rec := mitToken(t, s, http.MethodGet, "/api/v1/packages", "", eng)
	if rec.Code != http.StatusForbidden {
		t.Errorf("nicht erlaubte Familie = %d, erwartet 403: %s", rec.Code, rec.Body.String())
	}
	// Die Meldung nennt, was erlaubt IST. „Nicht erlaubt" allein zwingt zum
	// Nachsehen in der Oberfläche.
	if !strings.Contains(rec.Body.String(), "overview") {
		t.Errorf("die Meldung nennt die erlaubten Familien nicht: %s", rec.Body.String())
	}

	weit := legeToken(t, s, user, nil)
	for _, pfad := range []string{"/api/v1/services", "/api/v1/packages", "/api/v1/audit"} {
		if rec := mitToken(t, s, http.MethodGet, pfad, "", weit); rec.Code != http.StatusOK {
			t.Errorf("%s mit leerer Scope-Liste = %d: %s", pfad, rec.Code, rec.Body.String())
		}
	}
}

// TestTokenUnbekannteFamilie: Die Familienliste ist eine Allowlist. Eine neue
// Fläche ist damit nicht stillschweigend für Tokens offen — wer sie öffnen will,
// trägt sie ein und denkt dabei darüber nach.
func TestTokenUnbekannteFamilie(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	token := legeToken(t, s, user, nil)

	rec := mitToken(t, s, http.MethodGet, "/api/v1/gibtsnochnicht", "", token)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Status = %d, erwartet 403 für eine unbekannte Familie", rec.Code)
	}
}

// TestTokenAbgelaufen: Die Meldung nennt das Datum. „Nicht berechtigt" wäre die
// Antwort, an der jemand eine Stunde sucht.
func TestTokenAbgelaufen(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	gestern := time.Now().Add(-24 * time.Hour)
	token := legeToken(t, s, user, func(tok *store.APIToken) { tok.ExpiresAt = &gestern })

	rec := mitToken(t, s, http.MethodGet, "/api/v1/services", "", token)
	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("Status = %d, erwartet 401: %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "abgelaufen") {
		t.Errorf("die Meldung nennt den Grund nicht: %s", rec.Body.String())
	}
	// Die Kopfzeile nach RFC 7235 — sie sagt einem Client, welches Verfahren
	// erwartet wird.
	if rec.Header().Get("WWW-Authenticate") == "" {
		t.Error("es fehlt der WWW-Authenticate-Kopf")
	}
}

// TestTokenGesperrtesKonto: Ein Token ist an ein Konto gebunden und erbt dessen
// Zustand. Ein gesperrtes Konto mit gültigem Token wäre eine Sperre, die nichts
// sperrt.
func TestTokenGesperrtesKonto(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	token := legeToken(t, s, user, nil)

	if err := s.db.SetDisabled(t.Context(), user.ID, true); err != nil {
		t.Fatal(err)
	}
	rec := mitToken(t, s, http.MethodGet, "/api/v1/services", "", token)
	if rec.Code != http.StatusUnauthorized {
		t.Errorf("Status = %d, erwartet 401 für ein gesperrtes Konto: %s",
			rec.Code, rec.Body.String())
	}
}

// TestTokenWechselzwang: Ein Konto mit Einmalpasswort aus einer Zurücksetzung
// kommt bis zur Wechselseite und nicht weiter. Ein Token daran wäre die
// dauerhafte Berechtigung, die der Wechselzwang gerade verhindern soll.
func TestTokenWechselzwang(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	token := legeToken(t, s, user, nil)

	// SetTemporaryPassword setzt den Wechselzwang — dasselbe, was eine
	// Zurücksetzung durch den Owner tut.
	if err := s.db.SetTemporaryPassword(t.Context(), user.ID, "hash-egal"); err != nil {
		t.Fatal(err)
	}
	rec := mitToken(t, s, http.MethodGet, "/api/v1/services", "", token)
	if rec.Code != http.StatusUnauthorized {
		t.Errorf("Status = %d, erwartet 401: %s", rec.Code, rec.Body.String())
	}
}

// TestTokenRolleBleibtObergrenze: Ein Token kann nie mehr als das Konto, dem er
// gehört. Ein Nur-Lese-Konto mit einem Token ohne read_only bleibt ein
// Nur-Lese-Konto — sonst wäre der Token ein Weg, die eigene Rolle zu erweitern.
func TestTokenRolleBleibtObergrenze(t *testing.T) {
	s := newTestServer(t)
	leser := addUser(t, s, "leserin", store.RoleReadOnly)
	token := legeToken(t, s, leser, nil)

	if rec := mitToken(t, s, http.MethodGet, "/api/v1/services", "", token); rec.Code != http.StatusOK {
		t.Fatalf("Lesen = %d", rec.Code)
	}
	rec := mitToken(t, s, http.MethodPost, "/api/v1/services/nginx.service",
		`{"aktion":"stop","bestaetigt":true}`, token)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Status = %d, erwartet 403 — die Rolle ist die Obergrenze: %s",
			rec.Code, rec.Body.String())
	}
}

// TestTokenNutzungUndProtokoll: Zwei Dinge auf einmal, weil sie dieselbe Anfrage
// betreffen — „zuletzt benutzt" wird fortgeschrieben, und im Protokoll steht,
// dass ein Token gehandelt hat. Ohne das Zweite sagt das Protokoll „philipp hat
// den Dienst gestoppt", während es ein Skript war.
func TestTokenNutzungUndProtokoll(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	token := legeToken(t, s, user, func(tok *store.APIToken) { tok.Name = "Nachtwache" })

	rec := mitToken(t, s, http.MethodPost, "/api/v1/services/nginx.service",
		`{"aktion":"stop","bestaetigt":true}`, token)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}

	// „zuletzt benutzt" steht.
	tokens, err := s.db.ListAPITokens(t.Context())
	if err != nil || len(tokens) != 1 {
		t.Fatalf("Tokens = %v, %v", tokens, err)
	}
	if tokens[0].LastUsedAt == nil {
		t.Error("„zuletzt benutzt\" wurde nicht fortgeschrieben")
	}
	if tokens[0].LastUsedIP == "" {
		t.Error("die Adresse der letzten Nutzung fehlt")
	}

	// Und der Token steht im Protokoll — in den Einzelheiten, nicht im
	// Akteursfeld: Daran hängen die Filter, und zwei Schreibweisen eines Kontos
	// wären dort zwei Akteure.
	eintraege, err := s.db.ListAudit(t.Context(), 20)
	if err != nil {
		t.Fatal(err)
	}
	var gefunden bool
	for _, e := range eintraege {
		// Die Aktion heißt „service.stop" — der Handler setzt sie aus dem Wort
		// der Aktion zusammen.
		if e.Action != "service.stop" {
			continue
		}
		gefunden = true
		if e.Actor != "philipp" {
			t.Errorf("Akteur = %q, erwartet den Kontonamen", e.Actor)
		}
		if !strings.Contains(e.Detail, "token=Nachtwache") {
			t.Errorf("die Einzelheiten nennen den Token nicht: %q", e.Detail)
		}
	}
	if !gefunden {
		t.Error("kein Protokolleintrag zur Aktion")
	}
}

// TestTokenAbweisungWirdProtokolliert: Ein Token, der reihenweise abgewiesen
// wird, ist ein kaputtes Skript oder ein Versuch. Beides gehört ins Journal, und
// der Token selbst steht dort nie.
func TestTokenAbweisungWirdProtokolliert(t *testing.T) {
	s := newTestServer(t)
	addUser(t, s, "philipp", store.RoleOwner)

	rec := mitToken(t, s, http.MethodGet, "/api/v1/services", "", "asy_erfunden")
	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("Status = %d", rec.Code)
	}

	eintraege, err := s.db.ListAudit(t.Context(), 20)
	if err != nil {
		t.Fatal(err)
	}
	var gefunden bool
	for _, e := range eintraege {
		if e.Action == "token.rejected" {
			gefunden = true
			if strings.Contains(e.Detail, "asy_erfunden") ||
				strings.Contains(e.Target, "asy_erfunden") {
				t.Errorf("der Token steht im Protokoll: %+v", e)
			}
		}
	}
	if !gefunden {
		t.Error("die Abweisung wurde nicht protokolliert")
	}
}

// TestTokenRatenbegrenzung: Wer Tokens durchprobiert, wird gebremst. Ohne das ist
// ein Token ein Geheimnis, gegen das man beliebig oft raten darf.
func TestTokenRatenbegrenzung(t *testing.T) {
	s := newTestServer(t)
	addUser(t, s, "philipp", store.RoleOwner)

	var gebremst bool
	for i := 0; i < 12; i++ {
		rec := mitToken(t, s, http.MethodGet, "/api/v1/services", "", "asy_versuch")
		if rec.Code == http.StatusTooManyRequests {
			gebremst = true
			if rec.Header().Get("Retry-After") == "" {
				t.Error("es fehlt der Retry-After-Kopf")
			}
			break
		}
	}
	if !gebremst {
		t.Error("zwölf Fehlversuche ohne Bremse")
	}
}

// TestTokenHashIstNichtDerToken: In der Datenbank liegt nie der Token. Ein Abzug
// erlaubt damit keine Anmeldung — dieselbe Zusage wie bei den Sitzungen.
func TestTokenHashIstNichtDerToken(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	klartext := legeToken(t, s, user, nil)

	tokens, err := s.db.ListAPITokens(t.Context())
	if err != nil || len(tokens) != 1 {
		t.Fatalf("Tokens = %v, %v", tokens, err)
	}
	tok := tokens[0]
	if tok.Hash == klartext {
		t.Fatal("der Klartext steht in der Datenbank")
	}
	if tok.Hash != auth.HashToken(klartext) {
		t.Error("der gespeicherte Wert ist nicht der Hash des Klartexts")
	}
	// Der sichtbare Anfang ist ein Anfang und nicht der Token.
	if len(tok.Prefix) >= len(klartext) {
		t.Errorf("der Prefix ist so lang wie der Token: %q", tok.Prefix)
	}
	if !strings.Contains(klartext, tok.Prefix) {
		t.Error("der Prefix ist nicht der Anfang des Tokens — dann taugt er nicht " +
			"zum Wiedererkennen")
	}
	if !strings.HasPrefix(klartext, tokenPraefix) {
		t.Errorf("der Token trägt kein erkennbares Präfix: %q", klartext[:8])
	}
}

// TestApiFamilie prüft die Zerlegung einzeln — an ihr hängt die ganze
// Scope-Prüfung, und ein Textvergleich auf Präfixe hätte /files-geheim
// mitgenommen.
func TestApiFamilie(t *testing.T) {
	faelle := map[string]string{
		"/api/v1/services":                "services",
		"/api/v1/services/ssh.service":    "services",
		"/api/v1/files/text":              "files",
		"/api/v1/panel-users/3/delete":    "panel-users",
		"/api/v1/schedules/cron/x/delete": "schedules",
		"/api/v1/tokens":                  "tokens",
		"/api/v1/":                        "",
		"/api/v1":                         "",
		"/dienste":                        "",
		"/":                               "",
		"/api/services":                   "",
	}
	for pfad, erwartet := range faelle {
		if got := apiFamilie(pfad); got != erwartet {
			t.Errorf("apiFamilie(%q) = %q, erwartet %q", pfad, got, erwartet)
		}
	}
}

// TestTokenSitzungsauskunft: /api/v1/session sagt einem Skript, als wer es
// unterwegs ist. Ohne diese Auskunft muss es raten, ob sein Token noch gilt.
func TestTokenSitzungsauskunft(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	token := legeToken(t, s, user, nil)

	rec := mitToken(t, s, http.MethodGet, "/api/v1/session", "", token)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var sitzung apiSitzung
	if err := json.Unmarshal(rec.Body.Bytes(), &sitzung); err != nil {
		t.Fatal(err)
	}
	if sitzung.Benutzer != "philipp" {
		t.Errorf("Benutzer = %q", sitzung.Benutzer)
	}
	// Kein Sitzungstoken: Es gibt keine Sitzung, und ein Token braucht keines.
	if sitzung.CSRF != "" {
		t.Error("die Antwort trägt ein Sitzungstoken, obwohl es keine Sitzung gibt")
	}
	// Dafür seine eigenen Grenzen — ein Skript soll sie erfragen können, statt
	// sie durch einen 403 zu erfahren.
	if sitzung.Token != "Testskript" {
		t.Errorf("Token = %q", sitzung.Token)
	}

	// Und ein Nur-Lese-Token meldet darf_schreiben = false, auch wenn die Rolle
	// Owner ist: Sonst baut ein Skript einen Knopf, den der Server verweigert.
	nurLesen := legeToken(t, s, user, func(tok *store.APIToken) {
		tok.ReadOnly = true
		tok.Hash = auth.HashToken("asy_zweiter")
	})
	_ = nurLesen
	rec = mitToken(t, s, http.MethodGet, "/api/v1/session", "", "asy_zweiter")
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var zweite apiSitzung
	if err := json.Unmarshal(rec.Body.Bytes(), &zweite); err != nil {
		t.Fatal(err)
	}
	if zweite.DarfSchreiben {
		t.Error("ein Nur-Lese-Token meldet darf_schreiben = true")
	}
	if !zweite.NurLesen {
		t.Error("nur_lesen fehlt in der Auskunft")
	}
}
