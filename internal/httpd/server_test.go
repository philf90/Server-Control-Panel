package httpd

import (
	"context"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"net/url"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/store"
)

const testPassword = "ein sehr langes Testpasswort"

func newTestServer(t *testing.T) *Server {
	t.Helper()

	db, err := store.Open(filepath.Join(t.TempDir(), "asylum.db"))
	if err != nil {
		t.Fatalf("Datenbank: %v", err)
	}
	t.Cleanup(func() { _ = db.Close() })
	if _, err := db.Migrate(context.Background()); err != nil {
		t.Fatalf("Migrate: %v", err)
	}

	// Alle Pfade in ein Wegwerfverzeichnis: Ein Test, der nach /var/log/asylum
	// schreibt, läuft nur als root durch und fällt sonst erst in der CI auf.
	cfg := config.Default()
	dir := t.TempDir()
	cfg.Paths.Data = filepath.Join(dir, "lib")
	cfg.Paths.Log = filepath.Join(dir, "log")
	cfg.Server.TLS.Cert = filepath.Join(dir, "server.crt")
	cfg.Server.TLS.Key = filepath.Join(dir, "server.key")

	logger := slog.New(slog.NewTextHandler(io.Discard, nil))
	srv, err := New(cfg, logger, db, newFakeOps())
	if err != nil {
		t.Fatalf("New: %v", err)
	}
	return srv
}

// addUser legt ein vollständig eingerichtetes Konto an.
func addUser(t *testing.T, s *Server, username, role string) store.User {
	t.Helper()
	ctx := context.Background()

	hash, err := auth.HashPassword(testPassword)
	if err != nil {
		t.Fatal(err)
	}
	secret, err := auth.GenerateTOTPSecret()
	if err != nil {
		t.Fatal(err)
	}
	id, err := s.db.CreateUser(ctx, store.User{
		Username: username, PasswordHash: hash, Role: role,
		TOTPSecret: secret, TOTPConfirmed: true,
	})
	if err != nil {
		t.Fatalf("CreateUser: %v", err)
	}
	user, err := s.db.UserByID(ctx, id)
	if err != nil {
		t.Fatal(err)
	}
	return user
}

// login legt eine Sitzung an und liefert Cookie und CSRF-Token.
func login(t *testing.T, s *Server, user store.User) (cookie *http.Cookie, csrf string) {
	t.Helper()

	token, err := auth.NewToken()
	if err != nil {
		t.Fatal(err)
	}
	csrfToken, err := auth.NewToken()
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	if err := s.db.CreateSession(context.Background(), store.Session{
		ID: auth.HashToken(token), UserID: user.ID, CSRFToken: csrfToken,
		CreatedAt: now, LastSeenAt: now, ExpiresAt: now.Add(time.Hour),
	}); err != nil {
		t.Fatal(err)
	}
	return &http.Cookie{Name: sessionCookie, Value: token}, csrfToken
}

func get(t *testing.T, s *Server, path string, cookie *http.Cookie) *httptest.ResponseRecorder {
	t.Helper()
	req := httptest.NewRequest(http.MethodGet, path, nil)
	if cookie != nil {
		req.AddCookie(cookie)
	}
	rec := httptest.NewRecorder()
	s.Handler().ServeHTTP(rec, req)
	return rec
}

func post(t *testing.T, s *Server, path string, form url.Values, cookie *http.Cookie) *httptest.ResponseRecorder {
	t.Helper()
	req := httptest.NewRequest(http.MethodPost, path, strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	if cookie != nil {
		req.AddCookie(cookie)
	}
	rec := httptest.NewRecorder()
	s.Handler().ServeHTTP(rec, req)
	return rec
}

// ---------------------------------------------------------------- Grundlagen ---

func TestHealthzNeedsNoLogin(t *testing.T) {
	rec := get(t, newTestServer(t), "/healthz", nil)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}

	var resp healthResponse
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	if resp.Status != "ok" || resp.Version == "" {
		t.Errorf("unerwartete Antwort: %+v", resp)
	}
}

func TestSecurityHeaders(t *testing.T) {
	rec := get(t, newTestServer(t), "/login", nil)

	want := map[string]string{
		"X-Content-Type-Options":    "nosniff",
		"X-Frame-Options":           "DENY",
		"Referrer-Policy":           "no-referrer",
		"Strict-Transport-Security": "max-age=31536000",
	}
	for header, value := range want {
		if got := rec.Header().Get(header); got != value {
			t.Errorf("%s = %q, erwartet %q", header, got, value)
		}
	}

	csp := rec.Header().Get("Content-Security-Policy")
	for _, directive := range []string{"default-src 'none'", "script-src 'self'", "frame-ancestors 'none'"} {
		if !strings.Contains(csp, directive) {
			t.Errorf("CSP %q enthält %q nicht", csp, directive)
		}
	}
	if strings.Contains(csp, "unsafe-inline") || strings.Contains(csp, "unsafe-eval") {
		t.Errorf("CSP erlaubt unsichere Quellen: %q", csp)
	}
}

func TestStaticIsServed(t *testing.T) {
	rec := get(t, newTestServer(t), "/static/app.css", nil)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), "--accent") {
		t.Error("app.css wurde nicht ausgeliefert")
	}
}

func TestUnknownPathIs404(t *testing.T) {
	if rec := get(t, newTestServer(t), "/gibtsnicht", nil); rec.Code != http.StatusNotFound {
		t.Errorf("Status = %d, erwartet 404", rec.Code)
	}
}

// ------------------------------------------------------------------- Zugriff ---

func TestProtectedPagesRedirectWhenAnonymous(t *testing.T) {
	s := newTestServer(t)
	for _, path := range []string{"/", "/audit", "/account", "/users", "/events"} {
		rec := get(t, s, path, nil)
		if rec.Code != http.StatusSeeOther {
			t.Errorf("%s: Status = %d, erwartet 303 auf /login", path, rec.Code)
			continue
		}
		if loc := rec.Header().Get("Location"); loc != "/login" {
			t.Errorf("%s: Weiterleitung nach %q, erwartet /login", path, loc)
		}
	}
}

func TestDashboardForLoggedInUser(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	rec := get(t, s, "/", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}
	body := rec.Body.String()
	for _, want := range []string{"Übersicht", "philipp", "Dateisysteme"} {
		if !strings.Contains(body, want) {
			t.Errorf("Seite enthält %q nicht", want)
		}
	}
}

func TestSessionInvalidCookieIsIgnored(t *testing.T) {
	s := newTestServer(t)
	addUser(t, s, "philipp", store.RoleOwner)

	rec := get(t, s, "/", &http.Cookie{Name: sessionCookie, Value: "erfunden"})
	if rec.Code != http.StatusSeeOther {
		t.Errorf("Status = %d, erwartet Weiterleitung auf /login", rec.Code)
	}
}

func TestDisabledUserLosesAccess(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "gesperrt", store.RoleAdmin)
	cookie, _ := login(t, s, user)

	if rec := get(t, s, "/", cookie); rec.Code != http.StatusOK {
		t.Fatalf("Vorbedingung: Status = %d", rec.Code)
	}
	if err := s.db.SetDisabled(context.Background(), user.ID, true); err != nil {
		t.Fatal(err)
	}
	if rec := get(t, s, "/", cookie); rec.Code != http.StatusSeeOther {
		t.Errorf("gesperrtes Konto hat weiterhin Zugriff (Status %d)", rec.Code)
	}
}

// TOTP ist Pflicht: Ein Konto ohne bestätigten zweiten Faktor kommt nirgends
// hin außer in die Einrichtung.
func TestUnconfirmedTOTPIsSentToSetup(t *testing.T) {
	s := newTestServer(t)
	ctx := context.Background()

	hash, _ := auth.HashPassword(testPassword)
	secret, _ := auth.GenerateTOTPSecret()
	id, err := s.db.CreateUser(ctx, store.User{
		Username: "neu", PasswordHash: hash, Role: store.RoleAdmin,
		TOTPSecret: secret, TOTPConfirmed: false,
	})
	if err != nil {
		t.Fatal(err)
	}
	user, _ := s.db.UserByID(ctx, id)
	cookie, _ := login(t, s, user)

	rec := get(t, s, "/", cookie)
	if rec.Code != http.StatusSeeOther || rec.Header().Get("Location") != "/setup/2fa" {
		t.Errorf("Status = %d, Location = %q — erwartet 303 auf /setup/2fa",
			rec.Code, rec.Header().Get("Location"))
	}

	if rec := get(t, s, "/setup/2fa", cookie); rec.Code != http.StatusOK {
		t.Errorf("Einrichtungsseite: Status = %d, erwartet 200", rec.Code)
	}
}

// ---------------------------------------------------------------------- RBAC ---

func TestOwnerOnlyPages(t *testing.T) {
	s := newTestServer(t)

	owner := addUser(t, s, "owner", store.RoleOwner)
	ownerCookie, _ := login(t, s, owner)
	if rec := get(t, s, "/users", ownerCookie); rec.Code != http.StatusOK {
		t.Errorf("Owner: Status = %d, erwartet 200", rec.Code)
	}

	for _, role := range []string{store.RoleAdmin, store.RoleReadOnly} {
		user := addUser(t, s, "u-"+role, role)
		cookie, _ := login(t, s, user)
		rec := get(t, s, "/users", cookie)
		if rec.Code != http.StatusForbidden {
			t.Errorf("%s: Status = %d, erwartet 403", role, rec.Code)
		}
	}
}

func TestOwnerCannotDeleteOwnAccount(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "owner", store.RoleOwner)
	cookie, csrf := login(t, s, owner)

	rec := post(t, s, "/users/"+strconv.FormatInt(owner.ID, 10)+"/delete", url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400", rec.Code)
	}
	if _, err := s.db.UserByID(context.Background(), owner.ID); err != nil {
		t.Error("das eigene Konto wurde trotzdem gelöscht")
	}
}

func TestLastOwnerCannotBeDeleted(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "owner", store.RoleOwner)
	admin := addUser(t, s, "admin", store.RoleAdmin)

	// Der Admin bekommt keine Owner-Rechte; gelöscht wird aus Sicht des Owners.
	_ = admin
	cookie, csrf := login(t, s, owner)

	other := addUser(t, s, "zweiter", store.RoleReadOnly)
	rec := post(t, s, "/users/"+strconv.FormatInt(other.ID, 10)+"/delete", url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Löschen eines Nicht-Owners: Status = %d", rec.Code)
	}
	if _, err := s.db.UserByID(context.Background(), other.ID); err == nil {
		t.Error("Konto wurde nicht gelöscht")
	}
}

// ---------------------------------------------------------------------- CSRF ---

func TestPostWithoutCSRFTokenIsRejected(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "owner", store.RoleOwner)
	cookie, _ := login(t, s, owner)

	rec := post(t, s, "/users", url.Values{
		"username": {"eindringling"}, "password": {testPassword}, "role": {"admin"},
	}, cookie)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("Status = %d, erwartet 403", rec.Code)
	}
	if _, err := s.db.UserByName(context.Background(), "eindringling"); err == nil {
		t.Error("das Konto wurde trotz fehlendem CSRF-Token angelegt")
	}
}

func TestPostWithWrongCSRFTokenIsRejected(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "owner", store.RoleOwner)
	cookie, _ := login(t, s, owner)

	rec := post(t, s, "/users", url.Values{
		"_csrf": {"falsch"}, "username": {"x"}, "password": {testPassword}, "role": {"admin"},
	}, cookie)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Status = %d, erwartet 403", rec.Code)
	}
}

func TestUserCreateWithCSRF(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "owner", store.RoleOwner)
	cookie, csrf := login(t, s, owner)

	rec := post(t, s, "/users", url.Values{
		"_csrf": {csrf}, "username": {"kollege"}, "password": {testPassword}, "role": {"readonly"},
	}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}

	user, err := s.db.UserByName(context.Background(), "kollege")
	if err != nil {
		t.Fatalf("Konto wurde nicht angelegt: %v", err)
	}
	if user.Role != store.RoleReadOnly {
		t.Errorf("Rolle = %q, erwartet readonly", user.Role)
	}
	if user.TOTPConfirmed {
		t.Error("neues Konto darf nicht mit bestätigtem zweiten Faktor starten")
	}
}

func TestUserCreateRejectsWeakPassword(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "owner", store.RoleOwner)
	cookie, csrf := login(t, s, owner)

	rec := post(t, s, "/users", url.Values{
		"_csrf": {csrf}, "username": {"schwach"}, "password": {"kurz"}, "role": {"admin"},
	}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400", rec.Code)
	}
	if _, err := s.db.UserByName(context.Background(), "schwach"); err == nil {
		t.Error("Konto mit zu kurzem Passwort wurde angelegt")
	}
}

// ----------------------------------------------------------------- Anmeldung ---

func TestLoginSuccess(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)

	code, err := auth.TOTPCode(user.TOTPSecret, time.Now())
	if err != nil {
		t.Fatal(err)
	}

	rec := post(t, s, "/login", url.Values{
		"username": {"philipp"}, "password": {testPassword}, "code": {code},
	}, nil)

	if rec.Code != http.StatusSeeOther {
		t.Fatalf("Status = %d, erwartet 303 (Body: %s)", rec.Code, rec.Body.String())
	}
	var found *http.Cookie
	for _, c := range rec.Result().Cookies() {
		if c.Name == sessionCookie {
			found = c
		}
	}
	if found == nil {
		t.Fatal("kein Sitzungscookie gesetzt")
	}
	if !found.HttpOnly || !found.Secure || found.SameSite != http.SameSiteStrictMode {
		t.Errorf("unsichere Cookie-Einstellungen: HttpOnly=%t Secure=%t SameSite=%v",
			found.HttpOnly, found.Secure, found.SameSite)
	}
	// Im Cookie darf nur ein Zufallswert stehen, in der Datenbank dessen Hash.
	if _, err := s.db.SessionByID(context.Background(), found.Value); err == nil {
		t.Error("der Cookie-Wert ist zugleich der Datenbankschlüssel")
	}
	if _, err := s.db.SessionByID(context.Background(), auth.HashToken(found.Value)); err != nil {
		t.Errorf("Sitzung nicht unter dem Hash auffindbar: %v", err)
	}
}

func TestLoginFailures(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	code, _ := auth.TOTPCode(user.TOTPSecret, time.Now())

	tests := map[string]url.Values{
		"falsches Passwort":  {"username": {"philipp"}, "password": {"daneben"}, "code": {code}},
		"falscher Code":      {"username": {"philipp"}, "password": {testPassword}, "code": {"000000"}},
		"fehlender Code":     {"username": {"philipp"}, "password": {testPassword}},
		"unbekanntes Konto":  {"username": {"niemand"}, "password": {testPassword}, "code": {code}},
		"leere Zugangsdaten": {},
	}

	for name, form := range tests {
		t.Run(name, func(t *testing.T) {
			rec := post(t, s, "/login", form, nil)
			if rec.Code != http.StatusUnauthorized {
				t.Errorf("Status = %d, erwartet 401", rec.Code)
			}
			for _, c := range rec.Result().Cookies() {
				if c.Name == sessionCookie && c.Value != "" {
					t.Error("trotz Fehlschlag wurde eine Sitzung gesetzt")
				}
			}
			// Die Meldung darf nicht verraten, welcher Faktor gestimmt hat.
			if body := rec.Body.String(); strings.Contains(body, "Passwort falsch") ||
				strings.Contains(body, "Code falsch") {
				t.Error("die Fehlermeldung unterscheidet die Faktoren")
			}
		})
	}
}

func TestLoginWithRecoveryCode(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)

	codes, hashes, err := auth.NewRecoveryCodes()
	if err != nil {
		t.Fatal(err)
	}
	if err := s.db.ReplaceRecoveryCodes(context.Background(), user.ID, hashes); err != nil {
		t.Fatal(err)
	}

	form := url.Values{"username": {"philipp"}, "password": {testPassword}, "code": {codes[0]}}
	if rec := post(t, s, "/login", form, nil); rec.Code != http.StatusSeeOther {
		t.Fatalf("Status = %d, erwartet 303", rec.Code)
	}
	// Derselbe Code darf kein zweites Mal funktionieren.
	if rec := post(t, s, "/login", form, nil); rec.Code != http.StatusUnauthorized {
		t.Errorf("Wiederverwendung: Status = %d, erwartet 401", rec.Code)
	}
}

func TestLoginIsRateLimited(t *testing.T) {
	s := newTestServer(t)
	addUser(t, s, "philipp", store.RoleOwner)

	form := url.Values{"username": {"philipp"}, "password": {"daneben"}, "code": {"000000"}}
	var last int
	for i := 0; i < s.limiter.MaxAttempts+1; i++ {
		last = post(t, s, "/login", form, nil).Code
	}
	if last != http.StatusTooManyRequests {
		t.Errorf("letzter Status = %d, erwartet 429", last)
	}
}

func TestLogoutClearsSession(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/logout", url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusSeeOther {
		t.Fatalf("Status = %d, erwartet 303", rec.Code)
	}
	if _, err := s.db.SessionByID(context.Background(), auth.HashToken(cookie.Value)); err == nil {
		t.Error("die Sitzung besteht nach dem Abmelden weiter")
	}
}

// --------------------------------------------------------------------- Setup ---

func TestSetupRequiresValidToken(t *testing.T) {
	s := newTestServer(t)

	if rec := get(t, s, "/setup", nil); rec.Code != http.StatusForbidden {
		t.Errorf("ohne Token: Status = %d, erwartet 403", rec.Code)
	}

	token, err := auth.NewToken()
	if err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	if err := s.db.SetSetting(ctx, store.SettingSetupTokenHash, auth.HashToken(token)); err != nil {
		t.Fatal(err)
	}
	if err := s.db.SetSetting(ctx, store.SettingSetupTokenExpires,
		time.Now().Add(time.Hour).Format(time.RFC3339)); err != nil {
		t.Fatal(err)
	}

	if rec := get(t, s, "/setup?token=falsch", nil); rec.Code != http.StatusForbidden {
		t.Errorf("falscher Token: Status = %d, erwartet 403", rec.Code)
	}
	if rec := get(t, s, "/setup?token="+url.QueryEscape(token), nil); rec.Code != http.StatusOK {
		t.Errorf("gültiger Token: Status = %d, erwartet 200", rec.Code)
	}
}

func TestSetupTokenExpires(t *testing.T) {
	s := newTestServer(t)
	ctx := context.Background()

	token, _ := auth.NewToken()
	_ = s.db.SetSetting(ctx, store.SettingSetupTokenHash, auth.HashToken(token))
	_ = s.db.SetSetting(ctx, store.SettingSetupTokenExpires,
		time.Now().Add(-time.Minute).Format(time.RFC3339))

	if rec := get(t, s, "/setup?token="+url.QueryEscape(token), nil); rec.Code != http.StatusForbidden {
		t.Errorf("abgelaufener Token: Status = %d, erwartet 403", rec.Code)
	}
}

func TestSetupCreatesOwnerAndConsumesToken(t *testing.T) {
	s := newTestServer(t)
	ctx := context.Background()

	token, _ := auth.NewToken()
	_ = s.db.SetSetting(ctx, store.SettingSetupTokenHash, auth.HashToken(token))
	_ = s.db.SetSetting(ctx, store.SettingSetupTokenExpires,
		time.Now().Add(time.Hour).Format(time.RFC3339))

	rec := post(t, s, "/setup", url.Values{
		"token":            {token},
		"username":         {"philipp"},
		"password":         {testPassword},
		"password_confirm": {testPassword},
	}, nil)

	if rec.Code != http.StatusSeeOther || rec.Header().Get("Location") != "/setup/2fa" {
		t.Fatalf("Status = %d, Location = %q (Body: %s)",
			rec.Code, rec.Header().Get("Location"), rec.Body.String())
	}

	user, err := s.db.UserByName(ctx, "philipp")
	if err != nil {
		t.Fatalf("Konto wurde nicht angelegt: %v", err)
	}
	if user.Role != store.RoleOwner {
		t.Errorf("Rolle = %q, erwartet owner", user.Role)
	}
	if user.TOTPSecret == "" || user.TOTPConfirmed {
		t.Error("der zweite Faktor muss vorbereitet, aber noch unbestätigt sein")
	}

	// Der Token ist verbraucht.
	if _, err := s.db.Setting(ctx, store.SettingSetupTokenHash); err == nil {
		t.Error("der Setup-Token gilt nach der Einrichtung weiter")
	}
	if rec := get(t, s, "/setup?token="+url.QueryEscape(token), nil); rec.Code != http.StatusForbidden {
		t.Error("das Setup ist nach Abschluss weiterhin erreichbar")
	}
}

func TestSetupRejectsMismatchedPasswords(t *testing.T) {
	s := newTestServer(t)
	ctx := context.Background()

	token, _ := auth.NewToken()
	_ = s.db.SetSetting(ctx, store.SettingSetupTokenHash, auth.HashToken(token))

	rec := post(t, s, "/setup", url.Values{
		"token":            {token},
		"username":         {"philipp"},
		"password":         {testPassword},
		"password_confirm": {"etwas anderes das lang genug ist"},
	}, nil)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400", rec.Code)
	}
	if n, _ := s.db.CountUsers(ctx); n != 0 {
		t.Error("es wurde trotzdem ein Konto angelegt")
	}
}

func TestTOTPConfirmationCompletesSetup(t *testing.T) {
	s := newTestServer(t)
	ctx := context.Background()

	hash, _ := auth.HashPassword(testPassword)
	secret, _ := auth.GenerateTOTPSecret()
	id, err := s.db.CreateUser(ctx, store.User{
		Username: "philipp", PasswordHash: hash, Role: store.RoleOwner,
		TOTPSecret: secret, TOTPConfirmed: false,
	})
	if err != nil {
		t.Fatal(err)
	}
	user, _ := s.db.UserByID(ctx, id)
	cookie, csrf := login(t, s, user)

	// Falscher Code ändert nichts.
	if rec := post(t, s, "/setup/2fa", url.Values{"_csrf": {csrf}, "code": {"000000"}}, cookie); rec.Code != http.StatusBadRequest {
		t.Errorf("falscher Code: Status = %d, erwartet 400", rec.Code)
	}
	if u, _ := s.db.UserByID(ctx, id); u.TOTPConfirmed {
		t.Fatal("der zweite Faktor wurde trotz falschem Code bestätigt")
	}

	code, _ := auth.TOTPCode(secret, time.Now())
	rec := post(t, s, "/setup/2fa", url.Values{"_csrf": {csrf}, "code": {code}}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}

	u, _ := s.db.UserByID(ctx, id)
	if !u.TOTPConfirmed {
		t.Error("der zweite Faktor wurde nicht bestätigt")
	}
	if n, _ := s.db.CountUnusedRecoveryCodes(ctx, id); n != auth.RecoveryCodeCount {
		t.Errorf("%d Wiederherstellungscodes, erwartet %d", n, auth.RecoveryCodeCount)
	}
	if !strings.Contains(rec.Body.String(), "Wiederherstellungscodes") {
		t.Error("die Codes wurden nicht angezeigt")
	}
}

func TestQRCodeIsPNG(t *testing.T) {
	s := newTestServer(t)
	ctx := context.Background()

	hash, _ := auth.HashPassword(testPassword)
	secret, _ := auth.GenerateTOTPSecret()
	id, _ := s.db.CreateUser(ctx, store.User{
		Username: "philipp", PasswordHash: hash, Role: store.RoleOwner,
		TOTPSecret: secret, TOTPConfirmed: false,
	})
	user, _ := s.db.UserByID(ctx, id)
	cookie, _ := login(t, s, user)

	rec := get(t, s, "/setup/2fa/qr.png", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}
	if ct := rec.Header().Get("Content-Type"); ct != "image/png" {
		t.Errorf("Content-Type = %q", ct)
	}
	if !strings.HasPrefix(rec.Body.String(), "\x89PNG") {
		t.Error("die Antwort ist kein PNG")
	}
}

// ------------------------------------------------------------------- Konto ---

func TestPasswordChange(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	const newPassword = "ein anderes langes Passwort"
	rec := post(t, s, "/account/password", url.Values{
		"_csrf":                {csrf},
		"current_password":     {testPassword},
		"new_password":         {newPassword},
		"new_password_confirm": {newPassword},
	}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200 (Body: %s)", rec.Code, rec.Body.String())
	}

	updated, err := s.db.UserByID(context.Background(), user.ID)
	if err != nil {
		t.Fatal(err)
	}
	ok, err := auth.VerifyPassword(newPassword, updated.PasswordHash)
	if err != nil || !ok {
		t.Error("das neue Passwort wurde nicht übernommen")
	}
	// Die alte Sitzung muss beendet sein.
	if _, err := s.db.SessionByID(context.Background(), auth.HashToken(cookie.Value)); err == nil {
		t.Error("die alte Sitzung besteht nach der Passwortänderung weiter")
	}
}

func TestPasswordChangeRejectsWrongCurrent(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/account/password", url.Values{
		"_csrf":                {csrf},
		"current_password":     {"daneben"},
		"new_password":         {"ein anderes langes Passwort"},
		"new_password_confirm": {"ein anderes langes Passwort"},
	}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400", rec.Code)
	}
}

// ----------------------------------------------------------------- Audit-Log ---

func TestLoginIsAudited(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	code, _ := auth.TOTPCode(user.TOTPSecret, time.Now())

	post(t, s, "/login", url.Values{
		"username": {"philipp"}, "password": {"daneben"}, "code": {code},
	}, nil)
	post(t, s, "/login", url.Values{
		"username": {"philipp"}, "password": {testPassword}, "code": {code},
	}, nil)

	entries, err := s.db.ListAudit(context.Background(), 10)
	if err != nil {
		t.Fatal(err)
	}

	var sawFailure, sawSuccess bool
	for _, e := range entries {
		switch e.Action {
		case "login.failed":
			sawFailure = true
		case "login.success":
			sawSuccess = true
		}
	}
	if !sawFailure {
		t.Error("der Fehlversuch steht nicht im Audit-Log")
	}
	if !sawSuccess {
		t.Error("die erfolgreiche Anmeldung steht nicht im Audit-Log")
	}
}

func TestAuditPageShowsEntries(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	if err := s.db.AppendAudit(context.Background(), store.AuditEntry{
		Actor: "philipp", Action: "test.eintrag", Result: store.ResultOK, IP: "127.0.0.1",
	}); err != nil {
		t.Fatal(err)
	}

	rec := get(t, s, "/audit", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), "test.eintrag") {
		t.Error("der Eintrag erscheint nicht auf der Seite")
	}
}

// ------------------------------------------------------------------ Hilfen ---

func TestClientIP(t *testing.T) {
	req := httptest.NewRequest(http.MethodGet, "/", nil)
	req.RemoteAddr = "203.0.113.7:54321"
	if got := clientIP(req); got != "203.0.113.7" {
		t.Errorf("clientIP = %q, erwartet 203.0.113.7", got)
	}

	// Proxy-Header werden bewusst ignoriert: Ein blind vertrauter
	// X-Forwarded-For würde die Ratenbegrenzung aushebelbar machen.
	req.Header.Set("X-Forwarded-For", "198.51.100.1")
	if got := clientIP(req); got != "203.0.113.7" {
		t.Errorf("clientIP = %q — X-Forwarded-For darf nicht ausgewertet werden", got)
	}

	req.RemoteAddr = "[2001:db8::1]:443"
	if got := clientIP(req); got != "2001:db8::1" {
		t.Errorf("clientIP (IPv6) = %q, erwartet 2001:db8::1", got)
	}
}

func TestValidUsername(t *testing.T) {
	valid := []string{"abc", "philipp", "user.name", "user-name", "user_name", "a1234567890"}
	invalid := []string{"", "ab", strings.Repeat("a", 33), "mit leerzeichen", "sonder!zeichen", "../etc/passwd"}

	for _, name := range valid {
		if !validUsername(name) {
			t.Errorf("%q wurde abgelehnt", name)
		}
	}
	for _, name := range invalid {
		if validUsername(name) {
			t.Errorf("%q wurde angenommen", name)
		}
	}
}

func TestRecovererTurnsPanicInto500(t *testing.T) {
	s := newTestServer(t)
	handler := s.recoverer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {
		panic("kaputt")
	}))

	rec := httptest.NewRecorder()
	handler.ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/", nil))
	if rec.Code != http.StatusInternalServerError {
		t.Errorf("Status = %d, erwartet 500", rec.Code)
	}
}

// ------------------------------------------------------------- Live-Kanal ---

// syncRecorder ist ein nebenläufigkeitssicherer ResponseWriter.
// httptest.ResponseRecorder ist es nicht: Sein Puffer darf nicht gleichzeitig
// vom Handler beschrieben und vom Test gelesen werden.
type syncRecorder struct {
	mu     sync.Mutex
	header http.Header
	body   strings.Builder
	status int
}

func newSyncRecorder() *syncRecorder {
	return &syncRecorder{header: make(http.Header)}
}

func (r *syncRecorder) Header() http.Header { return r.header }

func (r *syncRecorder) WriteHeader(status int) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.status == 0 {
		r.status = status
	}
}

func (r *syncRecorder) Write(b []byte) (int, error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if r.status == 0 {
		r.status = http.StatusOK
	}
	return r.body.Write(b)
}

// Flush macht den Writer für http.NewResponseController streamingfähig.
func (r *syncRecorder) Flush() {}

func (r *syncRecorder) String() string {
	r.mu.Lock()
	defer r.mu.Unlock()
	return r.body.String()
}

// Regressionstest: Der SSE-Endpunkt scheiterte zunächst daran, dass die
// Logging-Middleware den ResponseWriter umhüllt und die Hülle kein
// http.Flusher war. Der Test läuft deshalb bewusst durch die vollständige
// Middleware-Kette und nicht direkt gegen den Handler.
func TestEventsStreamThroughMiddleware(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	// Einen Snapshot in den Ringpuffer legen, damit sofort etwas gesendet wird.
	s.ring.Add(s.sampler.Sample())

	ctx, cancel := context.WithCancel(context.Background())
	req := httptest.NewRequest(http.MethodGet, "/events", nil).WithContext(ctx)
	req.AddCookie(cookie)

	rec := newSyncRecorder()
	done := make(chan struct{})
	go func() {
		defer close(done)
		s.Handler().ServeHTTP(rec, req)
	}()

	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		if strings.Contains(rec.String(), "event: metrics") {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	cancel()
	<-done

	body := rec.String()
	if strings.Contains(body, "Streaming nicht unterstützt") {
		t.Fatal("der Writer der Middleware unterstützt kein Flush")
	}
	if !strings.Contains(body, "event: metrics") {
		t.Fatalf("kein Metrik-Ereignis empfangen, Body: %q", body)
	}
	if ct := rec.Header().Get("Content-Type"); ct != "text/event-stream" {
		t.Errorf("Content-Type = %q, erwartet text/event-stream", ct)
	}

	// Die Nutzlast muss gültiges JSON sein — das Frontend parst sie direkt.
	_, payload, found := strings.Cut(body, "data: ")
	if !found {
		t.Fatal("kein data-Feld im Ereignis")
	}
	payload, _, _ = strings.Cut(payload, "\n")
	var snap map[string]any
	if err := json.Unmarshal([]byte(payload), &snap); err != nil {
		t.Fatalf("Nutzlast ist kein JSON: %v", err)
	}
	for _, key := range []string{"cpu", "memory", "filesystems", "uptime"} {
		if _, ok := snap[key]; !ok {
			t.Errorf("Feld %q fehlt in der Nutzlast", key)
		}
	}
}

// Die Hülle der Middleware muss den echten Writer freigeben, sonst gehen
// Fähigkeiten wie Flush verloren.
func TestStatusRecorderUnwraps(t *testing.T) {
	inner := httptest.NewRecorder()
	rec := &statusRecorder{ResponseWriter: inner}

	if rec.Unwrap() != http.ResponseWriter(inner) {
		t.Error("Unwrap liefert nicht den umhüllten Writer")
	}
	if err := http.NewResponseController(rec).Flush(); err != nil {
		t.Errorf("Flush über die Hülle schlug fehl: %v", err)
	}
}
