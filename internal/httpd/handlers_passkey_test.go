package httpd

import (
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strconv"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/passkeys"
	"github.com/philf90/asylum/internal/store"
)

// enablePasskeys schaltet die WebAuthn-Funktion auf einem Testserver ein.
func enablePasskeys(t *testing.T, s *Server) {
	t.Helper()
	m, err := passkeys.New(passkeys.Config{
		RPID: "localhost", DisplayName: "Test", Origins: []string{"https://localhost:8443"},
	})
	if err != nil {
		t.Fatalf("passkeys.New: %v", err)
	}
	s.passkeys = m
}

func TestPasskeySectionOnlyWhenEnabled(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	// Vorgabe: aus — der Abschnitt fehlt.
	if body := get(t, s, "/alt/account", cookie).Body.String(); strings.Contains(body, "Passkey hinzufügen") {
		t.Error("der Passkey-Abschnitt erscheint, obwohl die Funktion aus ist")
	}

	enablePasskeys(t, s)
	body := get(t, s, "/alt/account", cookie).Body.String()
	for _, want := range []string{"Passkeys", "Passkey hinzufügen", "passkey-register.js"} {
		if !strings.Contains(body, want) {
			t.Errorf("die Kontoseite enthält %q nicht", want)
		}
	}
}

func TestPasskeyRegisterBeginNeedsFeatureAndPassword(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	// Funktion aus → 404.
	rec := post(t, s, "/alt/account/passkeys/register/begin", url.Values{"_csrf": {csrf}, "password": {testPassword}}, cookie)
	if rec.Code != http.StatusNotFound {
		t.Fatalf("bei ausgeschalteter Funktion Status = %d, erwartet 404", rec.Code)
	}

	enablePasskeys(t, s)

	// Falsches Passwort → 403, kein Token.
	rec = post(t, s, "/alt/account/passkeys/register/begin", url.Values{"_csrf": {csrf}, "password": {"falsch"}}, cookie)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("bei falschem Passwort Status = %d, erwartet 403", rec.Code)
	}

	// Richtiges Passwort → 200 mit Token und Optionen, die die RP-ID tragen.
	rec = post(t, s, "/alt/account/passkeys/register/begin", url.Values{"_csrf": {csrf}, "password": {testPassword}}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200; Body: %s", rec.Code, rec.Body.String())
	}
	var body struct {
		Token     string `json:"token"`
		PublicKey struct {
			RP struct {
				ID string `json:"id"`
			} `json:"rp"`
			Challenge string `json:"challenge"`
		} `json:"publicKey"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("Antwort unlesbar: %v", err)
	}
	if body.Token == "" {
		t.Error("kein Token in der Antwort")
	}
	if body.PublicKey.RP.ID != "localhost" {
		t.Errorf("RP-ID = %q, erwartet localhost", body.PublicKey.RP.ID)
	}
	if body.PublicKey.Challenge == "" {
		t.Error("keine Challenge in den Optionen")
	}
}

func TestPasskeyRenameAndDelete(t *testing.T) {
	s := newTestServer(t)
	enablePasskeys(t, s)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	id, err := s.db.AddWebAuthnCredential(context.Background(), store.WebAuthnCredential{
		UserID: user.ID, CredentialID: "cred-1", Label: "alt", Data: []byte("{}"),
	})
	if err != nil {
		t.Fatal(err)
	}

	// Umbenennen.
	rec := post(t, s, "/alt/account/passkeys/"+strconv.FormatInt(id, 10)+"/rename",
		url.Values{"_csrf": {csrf}, "label": {"Titan-Stick"}}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("rename Status = %d", rec.Code)
	}
	list, _ := s.db.WebAuthnCredentialsByUser(context.Background(), user.ID)
	if len(list) != 1 || list[0].Label != "Titan-Stick" {
		t.Fatalf("Label nach rename: %+v", list)
	}

	// Entfernen.
	rec = post(t, s, "/alt/account/passkeys/"+strconv.FormatInt(id, 10)+"/delete",
		ja(url.Values{"_csrf": {csrf}}), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("delete Status = %d", rec.Code)
	}
	if n, _ := s.db.CountWebAuthnCredentials(context.Background(), user.ID); n != 0 {
		t.Errorf("nach delete sind noch %d Passkeys da", n)
	}
}

func TestBuildPasskeysDerivation(t *testing.T) {
	log := slog.New(slog.NewTextHandler(io.Discard, nil))
	ptr := func(b bool) *bool { return &b }

	base := func() config.Config {
		c := config.Default()
		c.Server.Port = 8443
		return c
	}

	// Ausdrücklich aus → kein Manager, auch mit gutem Namen.
	c := base()
	c.Auth.WebAuthn.Enabled = ptr(false)
	c.Auth.WebAuthn.RPID = "panel.example.org"
	if buildPasskeys(c, log) != nil {
		t.Error("enabled=false sollte Passkeys ausschalten")
	}

	// Automatisch (nicht gesetzt) mit ableitbarem Namen aus den ACME-Domains.
	c = base()
	c.ACME.Domains = []string{"panel.example.org"}
	if buildPasskeys(c, log) == nil {
		t.Error("automatisch: mit ACME-Domain sollten Passkeys an sein")
	}

	// Ausdrücklich an mit gesetztem Namen → an.
	c = base()
	c.Auth.WebAuthn.Enabled = ptr(true)
	c.Auth.WebAuthn.RPID = "panel.example.org"
	if buildPasskeys(c, log) == nil {
		t.Error("enabled=true mit rp_id sollte Passkeys anschalten")
	}

	// deriveRPID nimmt die ACME-Domain vor allem anderen — die Reihenfolge ist
	// zusicherbar, unabhängig vom FQDN der Testmaschine.
	c = base()
	c.Auth.WebAuthn.RPID = "gesetzt.example.org"
	c.ACME.Domains = []string{"acme.example.org"}
	if got := deriveRPID(c); got != "gesetzt.example.org" {
		t.Errorf("deriveRPID = %q, erwartet die ausdrückliche Angabe", got)
	}

	// usableRPID trägt die Regel, welcher Name als RP-ID taugt — hier
	// deterministisch geprüft, ohne Abhängigkeit vom FQDN.
	for name, want := range map[string]string{
		"203.0.113.10": "", // IP
		"::1":          "", // IPv6
		"vm":           "", // ohne Punkt
		"":             "", // leer
		"localhost":    "localhost",
		"panel.x.org":  "panel.x.org",
	} {
		if got := usableRPID(name); got != want {
			t.Errorf("usableRPID(%q) = %q, erwartet %q", name, got, want)
		}
	}
}

// beginPasskeyLogin führt den ersten Schritt aus und gibt das Vorab-Cookie
// zurück, mit dem der zweite Schritt geprüft werden kann.
func beginPasskeyLogin(t *testing.T, s *Server, username string) *http.Cookie {
	t.Helper()
	rec := post(t, s, "/login/passkey/begin", url.Values{"username": {username}, "password": {testPassword}}, nil)
	if rec.Code != http.StatusOK {
		t.Fatalf("begin: Status = %d, Body %s", rec.Code, rec.Body.String())
	}
	for _, c := range rec.Result().Cookies() {
		if c.Name == preauthCookie && c.Value != "" {
			return &http.Cookie{Name: preauthCookie, Value: c.Value}
		}
	}
	t.Fatal("kein Vorab-Cookie aus begin")
	return nil
}

func hasSessionCookie(rec *httptest.ResponseRecorder) bool {
	for _, c := range rec.Result().Cookies() {
		if c.Name == sessionCookie && c.Value != "" {
			return true
		}
	}
	return false
}

// Eine manipulierte oder unsinnige Assertion darf keine Sitzung ergeben, und das
// Vorab-Token gilt danach nicht mehr — kein Replay.
func TestPasskeyLoginRejectsBadAssertion(t *testing.T) {
	s := newTestServer(t)
	enablePasskeys(t, s)
	user := addUser(t, s, "philipp", store.RoleOwner)
	seedCredential(t, s, user.ID, "cred-1")

	preauth := beginPasskeyLogin(t, s, "philipp")

	rec := post(t, s, "/login/passkey/finish", url.Values{"credential": {`{"kaputt":true}`}}, preauth)
	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("Status = %d, erwartet 401", rec.Code)
	}
	if hasSessionCookie(rec) {
		t.Error("trotz abgelehnter Assertion wurde eine Sitzung angelegt")
	}

	// Dasselbe Vorab-Token ein zweites Mal: Die Challenge ist verbraucht.
	rec = post(t, s, "/login/passkey/finish", url.Values{"credential": {`{"kaputt":true}`}}, preauth)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Replay: Status = %d, erwartet 400", rec.Code)
	}

	// Im Audit steht der Fehlversuch.
	entries, _ := s.db.ListAudit(context.Background(), 20)
	found := false
	for _, e := range entries {
		if e.Action == "login.failed" && e.Result == store.ResultDenied {
			found = true
		}
	}
	if !found {
		t.Error("der abgelehnte Passkey-Versuch steht nicht im Audit-Log")
	}
}

// Eine unsinnige Registrierungsantwort legt keinen Passkey an.
func TestPasskeyRegisterRejectsGarbage(t *testing.T) {
	s := newTestServer(t)
	enablePasskeys(t, s)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	// Gültiges Token holen.
	rec := post(t, s, "/alt/account/passkeys/register/begin", url.Values{"_csrf": {csrf}, "password": {testPassword}}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("begin: %d", rec.Code)
	}
	var body struct {
		Token string `json:"token"`
	}
	_ = json.Unmarshal(rec.Body.Bytes(), &body)

	rec = post(t, s, "/alt/account/passkeys/register/finish",
		url.Values{"_csrf": {csrf}, "token": {body.Token}, "label": {"X"}, "credential": {`{"kaputt":true}`}}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("finish: Status = %d, erwartet 400", rec.Code)
	}
	if n, _ := s.db.CountWebAuthnCredentials(context.Background(), user.ID); n != 0 {
		t.Errorf("trotz kaputter Antwort wurde ein Passkey angelegt (%d)", n)
	}
}

// Wiederholte falsche Passwörter über den Passkey-Beginn lösen dieselbe
// Kontosperre aus wie der gewöhnliche Login — der Endpunkt ist kein Schlupfloch
// am Ratenlimit vorbei.
func TestPasskeyLoginBeginRateLimited(t *testing.T) {
	s := newTestServer(t)
	enablePasskeys(t, s)
	addUser(t, s, "philipp", store.RoleOwner)

	var last int
	for i := 0; i < 6; i++ {
		rec := post(t, s, "/login/passkey/begin", url.Values{"username": {"philipp"}, "password": {"falsch"}}, nil)
		last = rec.Code
	}
	if last != http.StatusTooManyRequests {
		t.Errorf("nach wiederholten Fehlversuchen Status = %d, erwartet 429", last)
	}
}

// seedCredential legt einen Passkey mit gerade genug Inhalt an, dass BeginLogin
// ihn in die erlaubten Credentials aufnimmt.
func seedCredential(t *testing.T, s *Server, userID int64, credID string) {
	t.Helper()
	// "id" ist im go-webauthn-Credential ein []byte und wird von encoding/json
	// base64-dekodiert.
	if _, err := s.db.AddWebAuthnCredential(context.Background(), store.WebAuthnCredential{
		UserID: userID, CredentialID: credID, Label: "Testschlüssel", Data: []byte(`{"id":"AQIDBA=="}`),
	}); err != nil {
		t.Fatal(err)
	}
}

func TestPasskeyLoginBeginBranches(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)

	// Funktion aus → 404.
	rec := post(t, s, "/login/passkey/begin", url.Values{"username": {"philipp"}, "password": {testPassword}}, nil)
	if rec.Code != http.StatusNotFound {
		t.Fatalf("aus: Status = %d, erwartet 404", rec.Code)
	}

	enablePasskeys(t, s)

	// Falsches Passwort → 401.
	rec = post(t, s, "/login/passkey/begin", url.Values{"username": {"philipp"}, "password": {"falsch"}}, nil)
	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("falsches Passwort: Status = %d, erwartet 401", rec.Code)
	}

	// Richtiges Passwort, aber kein Passkey → 409.
	rec = post(t, s, "/login/passkey/begin", url.Values{"username": {"philipp"}, "password": {testPassword}}, nil)
	if rec.Code != http.StatusConflict {
		t.Fatalf("ohne Passkey: Status = %d, erwartet 409", rec.Code)
	}

	// Mit Passkey → 200, Optionen und Vorab-Cookie.
	seedCredential(t, s, user.ID, "cred-1")
	rec = post(t, s, "/login/passkey/begin", url.Values{"username": {"philipp"}, "password": {testPassword}}, nil)
	if rec.Code != http.StatusOK {
		t.Fatalf("mit Passkey: Status = %d, Body %s", rec.Code, rec.Body.String())
	}
	var preauth string
	for _, c := range rec.Result().Cookies() {
		if c.Name == preauthCookie {
			preauth = c.Value
		}
	}
	if preauth == "" {
		t.Error("kein Vorab-Cookie gesetzt")
	}
	var body struct {
		PublicKey struct {
			Challenge        string `json:"challenge"`
			AllowCredentials []any  `json:"allowCredentials"`
		} `json:"publicKey"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("Antwort unlesbar: %v", err)
	}
	if body.PublicKey.Challenge == "" {
		t.Error("keine Challenge")
	}
	if len(body.PublicKey.AllowCredentials) != 1 {
		t.Errorf("allowCredentials = %d, erwartet 1", len(body.PublicKey.AllowCredentials))
	}
}

func TestPasskeyLoginFinishNeedsCookie(t *testing.T) {
	s := newTestServer(t)
	enablePasskeys(t, s)
	addUser(t, s, "philipp", store.RoleOwner)

	// Kein Vorab-Cookie → 400.
	rec := post(t, s, "/login/passkey/finish", url.Values{"credential": {"{}"}}, nil)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("ohne Cookie: Status = %d, erwartet 400", rec.Code)
	}

	// Unbekanntes Token → 400 (die Challenge gibt es nicht).
	rr := post(t, s, "/login/passkey/finish", url.Values{"credential": {"{}"}},
		&http.Cookie{Name: preauthCookie, Value: "gibtsnicht"})
	if rr.Code != http.StatusBadRequest {
		t.Fatalf("unbekanntes Token: Status = %d, erwartet 400", rr.Code)
	}
}

// Ein Passkey darf nur mit gültigem CSRF-Token angelegt werden — sonst wäre der
// zweite Faktor über eine fremde Seite hinzufügbar.
func TestPasskeyRegisterBeginRejectsBadCSRF(t *testing.T) {
	s := newTestServer(t)
	enablePasskeys(t, s)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	rec := post(t, s, "/alt/account/passkeys/register/begin",
		url.Values{"_csrf": {"falsch"}, "password": {testPassword}}, cookie)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Status = %d, erwartet 403 bei falschem CSRF", rec.Code)
	}
}
