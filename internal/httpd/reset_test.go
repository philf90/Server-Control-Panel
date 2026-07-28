package httpd

import (
	"context"
	"encoding/json"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/store"
)

// resetForm baut die Formulardaten des Abschnitts „Zugang zurücksetzen".
func resetForm(target store.User, csrf, ownerPassword string) url.Values {
	return url.Values{
		"_csrf":          {csrf},
		"target":         {strconv.FormatInt(target.ID, 10)},
		"owner_password": {ownerPassword},
	}
}

// einmalpasswortAus liest das genau einmal angezeigte Passwort aus der Antwort.
var codeInHTML = regexp.MustCompile(`<code>([a-z0-9-]{19})</code>`)

func einmalpasswortAus(t *testing.T, body string) string {
	t.Helper()
	m := codeInHTML.FindStringSubmatch(body)
	if m == nil {
		t.Fatalf("kein Einmalpasswort in der Antwort:\n%s", body)
	}
	return m[1]
}

func mustUser(t *testing.T, s *Server, id int64) store.User {
	t.Helper()
	u, err := s.db.UserByID(context.Background(), id)
	if err != nil {
		t.Fatalf("UserByID: %v", err)
	}
	return u
}

// ------------------------------------------------ Zurücksetzen durch Owner ---

func TestOwnerResetPassword(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "owner", store.RoleOwner)
	ziel := addUser(t, s, "kollege", store.RoleAdmin)
	cookie, csrf := login(t, s, owner)

	// Das Zielkonto hat eine offene Sitzung und ist gesperrt — beides soll die
	// Zurücksetzung aufräumen.
	zielCookie, _ := login(t, s, ziel)
	if err := s.db.SetDisabled(context.Background(), ziel.ID, true); err != nil {
		t.Fatal(err)
	}

	rec := post(t, s, "/users/reset-password", resetForm(ziel, csrf, testPassword), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}

	password := einmalpasswortAus(t, rec.Body.String())
	nach := mustUser(t, s, ziel.ID)

	ok, err := auth.VerifyPassword(password, nach.PasswordHash)
	if err != nil || !ok {
		t.Errorf("das angezeigte Passwort passt nicht zum gespeicherten Hash (ok=%v, err=%v)", ok, err)
	}
	if !nach.MustChangePassword {
		t.Error("der Wechselzwang wurde nicht gesetzt")
	}
	if nach.Disabled {
		t.Error("die Sperre wurde nicht aufgehoben")
	}
	// Die Sitzung des Zielkontos ist beendet: Der Zugriff führt zur Anmeldung.
	if rec := get(t, s, "/account", zielCookie); rec.Code != http.StatusSeeOther {
		t.Errorf("alte Sitzung des Zielkontos lebt weiter (Status %d)", rec.Code)
	}
	// Der zweite Faktor bleibt unberührt — das ist eine eigene Aktion.
	if !nach.TOTPConfirmed {
		t.Error("der zweite Faktor wurde mit zurückgesetzt")
	}
}

func TestOwnerResetNeedsOwnPassword(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "owner", store.RoleOwner)
	ziel := addUser(t, s, "kollege", store.RoleAdmin)
	cookie, csrf := login(t, s, owner)

	rec := post(t, s, "/users/reset-password", resetForm(ziel, csrf, "falsch falsch falsch"), cookie)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("Status = %d, erwartet 400", rec.Code)
	}
	if nach := mustUser(t, s, ziel.ID); nach.PasswordHash != ziel.PasswordHash || nach.MustChangePassword {
		t.Error("das Zielkonto wurde trotz falschem Owner-Passwort verändert")
	}
}

func TestOwnerResetRefusesOwnAccount(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "owner", store.RoleOwner)
	cookie, csrf := login(t, s, owner)

	rec := post(t, s, "/users/reset-password", resetForm(owner, csrf, testPassword), cookie)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("Status = %d, erwartet 400", rec.Code)
	}
	if mustUser(t, s, owner.ID).MustChangePassword {
		t.Error("der Owner hat sich selbst zurückgesetzt")
	}
}

func TestOwnerResetDeniedForAdmin(t *testing.T) {
	s := newTestServer(t)
	admin := addUser(t, s, "admin", store.RoleAdmin)
	ziel := addUser(t, s, "kollege", store.RoleReadOnly)
	cookie, csrf := login(t, s, admin)

	for _, pfad := range []string{"/users/reset-password", "/users/reset-2fa", "/users/reset-passkeys"} {
		rec := post(t, s, pfad, resetForm(ziel, csrf, testPassword), cookie)
		if rec.Code != http.StatusForbidden {
			t.Errorf("%s: Status = %d, erwartet 403", pfad, rec.Code)
		}
	}
	if mustUser(t, s, ziel.ID).MustChangePassword {
		t.Error("ein Admin konnte ein fremdes Konto zurücksetzen")
	}
}

func TestOwnerReset2FA(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "owner", store.RoleOwner)
	ziel := addUser(t, s, "kollege", store.RoleAdmin)
	cookie, csrf := login(t, s, owner)

	ctx := context.Background()
	_, hashes, err := auth.NewRecoveryCodes()
	if err != nil {
		t.Fatal(err)
	}
	if err := s.db.ReplaceRecoveryCodes(ctx, ziel.ID, hashes); err != nil {
		t.Fatal(err)
	}

	rec := post(t, s, "/users/reset-2fa", resetForm(ziel, csrf, testPassword), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}

	nach := mustUser(t, s, ziel.ID)
	if nach.TOTPConfirmed {
		t.Error("der zweite Faktor gilt weiterhin als bestätigt")
	}
	if nach.TOTPSecret == ziel.TOTPSecret {
		t.Error("das TOTP-Geheimnis wurde nicht ersetzt")
	}
	// Das Passwort bleibt: Wer sein Telefon verliert, hat sein Passwort nicht
	// vergessen.
	if nach.PasswordHash != ziel.PasswordHash || nach.MustChangePassword {
		t.Error("das Passwort wurde mit angetastet")
	}
	left, err := s.db.CountUnusedRecoveryCodes(ctx, ziel.ID)
	if err != nil {
		t.Fatal(err)
	}
	if left != 0 {
		t.Errorf("es blieben %d Wiederherstellungscodes übrig", left)
	}
}

func TestOwnerResetPasskeys(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "owner", store.RoleOwner)
	ziel := addUser(t, s, "kollege", store.RoleAdmin)
	cookie, csrf := login(t, s, owner)

	ctx := context.Background()
	for _, id := range []string{"aaa", "bbb"} {
		if _, err := s.db.AddWebAuthnCredential(ctx, store.WebAuthnCredential{
			UserID: ziel.ID, CredentialID: id, Label: id, Data: []byte(`{}`),
		}); err != nil {
			t.Fatal(err)
		}
	}

	rec := post(t, s, "/users/reset-passkeys", resetForm(ziel, csrf, testPassword), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}

	n, err := s.db.CountWebAuthnCredentials(ctx, ziel.ID)
	if err != nil {
		t.Fatal(err)
	}
	if n != 0 {
		t.Errorf("es blieben %d Passkeys übrig", n)
	}
}

// TestResetSectionHidesOwnAccount stellt sicher, dass das eigene Konto nicht in
// der Auswahl steht — die Ablehnung im Handler ist der zweite Riegel, nicht der
// erste.
func TestResetSectionHidesOwnAccount(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "einzeln", store.RoleOwner)
	cookie, _ := login(t, s, owner)

	body := get(t, s, "/users", cookie).Body.String()
	if regexp.MustCompile(`<option value="` + strconv.FormatInt(owner.ID, 10) + `"`).MatchString(body) {
		t.Error("das eigene Konto steht in der Auswahl zum Zurücksetzen")
	}
	// Ohne weiteres Konto entfällt der Abschnitt ganz.
	if regexp.MustCompile(`id="zuruecksetzen"`).MatchString(body) {
		t.Error("der Abschnitt erscheint, obwohl es kein anderes Konto gibt")
	}
}

// ------------------------------------------------------ Erzwungener Wechsel ---

func TestForcedChangeBlocksPanel(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "kollege", store.RoleOwner)
	cookie, _ := login(t, s, user)

	hash, err := auth.HashPassword("Einmalpasswort xyz")
	if err != nil {
		t.Fatal(err)
	}
	if err := s.db.SetTemporaryPassword(context.Background(), user.ID, hash); err != nil {
		t.Fatal(err)
	}

	// Jede geschützte Seite führt auf die Wechselseite, nicht ins Panel.
	for _, pfad := range []string{"/", "/services", "/users", "/account"} {
		rec := get(t, s, pfad, cookie)
		if rec.Code != http.StatusSeeOther {
			t.Errorf("%s: Status = %d, erwartet 303", pfad, rec.Code)
			continue
		}
		if ort := rec.Header().Get("Location"); ort != "/account/password-change" {
			t.Errorf("%s: Weiterleitung nach %q, erwartet /account/password-change", pfad, ort)
		}
	}
	// Die Wechselseite selbst ist erreichbar — sonst drehte sich die
	// Weiterleitung im Kreis.
	if rec := get(t, s, "/account/password-change", cookie); rec.Code != http.StatusOK {
		t.Errorf("Wechselseite: Status = %d, erwartet 200", rec.Code)
	}
}

func TestForcedChangeSetsNewPassword(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "kollege", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	const einmal = "Einmalpasswort xyz"
	hash, err := auth.HashPassword(einmal)
	if err != nil {
		t.Fatal(err)
	}
	if err := s.db.SetTemporaryPassword(context.Background(), user.ID, hash); err != nil {
		t.Fatal(err)
	}

	// Das vergebene Passwort noch einmal zu verwenden genügt nicht.
	rec := post(t, s, "/account/password-change", url.Values{
		"_csrf": {csrf}, "current_password": {einmal},
		"new_password": {einmal}, "new_password_confirm": {einmal},
	}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("gleiches Passwort: Status = %d, erwartet 400", rec.Code)
	}
	if !mustUser(t, s, user.ID).MustChangePassword {
		t.Fatal("der Wechselzwang fiel weg, ohne dass ein neues Passwort gesetzt wurde")
	}

	// Ein falsches „aktuelles" Passwort ebenso nicht.
	rec = post(t, s, "/account/password-change", url.Values{
		"_csrf": {csrf}, "current_password": {"ganz was anderes"},
		"new_password": {"neues langes Passwort"}, "new_password_confirm": {"neues langes Passwort"},
	}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("falsches Einmalpasswort: Status = %d, erwartet 400", rec.Code)
	}

	// Und nun richtig.
	const neu = "mein eigenes langes Passwort"
	rec = post(t, s, "/account/password-change", url.Values{
		"_csrf": {csrf}, "current_password": {einmal},
		"new_password": {neu}, "new_password_confirm": {neu},
	}, cookie)
	if rec.Code != http.StatusSeeOther {
		t.Fatalf("Status = %d, erwartet 303: %s", rec.Code, rec.Body.String())
	}

	nach := mustUser(t, s, user.ID)
	if nach.MustChangePassword {
		t.Error("der Wechselzwang blieb bestehen")
	}
	ok, err := auth.VerifyPassword(neu, nach.PasswordHash)
	if err != nil || !ok {
		t.Errorf("das neue Passwort gilt nicht (ok=%v, err=%v)", ok, err)
	}
}

// ----------------------------------------------------- Passwort vergessen ---

func TestResetTickets(t *testing.T) {
	tickets := newResetTickets()
	jetzt := time.Now()
	tickets.now = func() time.Time { return jetzt }

	token, err := tickets.put(7, "philipp")
	if err != nil {
		t.Fatal(err)
	}

	// peek verbraucht nicht.
	if _, ok := tickets.peek(token); !ok {
		t.Fatal("peek findet das frische Ticket nicht")
	}
	if _, ok := tickets.peek(token); !ok {
		t.Fatal("peek hat das Ticket verbraucht")
	}

	tk, ok := tickets.take(token)
	if !ok || tk.userID != 7 || tk.username != "philipp" {
		t.Fatalf("take = %+v, %v", tk, ok)
	}
	// Genau einmal.
	if _, ok := tickets.take(token); ok {
		t.Error("dasselbe Ticket ließ sich zweimal einlösen")
	}

	// Abgelaufen zählt als nicht vorhanden.
	token, err = tickets.put(7, "philipp")
	if err != nil {
		t.Fatal(err)
	}
	jetzt = jetzt.Add(resetTicketTTL + time.Second)
	if _, ok := tickets.peek(token); ok {
		t.Error("ein abgelaufenes Ticket gilt noch")
	}
	if _, ok := tickets.take(token); ok {
		t.Error("ein abgelaufenes Ticket ließ sich einlösen")
	}
}

func TestForgotPageNamesSSHWithoutPasskeys(t *testing.T) {
	s := newTestServer(t) // Passkeys aus
	rec := get(t, s, "/login/forgot", nil)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}
	body := rec.Body.String()
	if !strings.Contains(body, "asylum reset-password") {
		t.Error("die Seite nennt den Weg über die Kommandozeile nicht")
	}
	if strings.Contains(body, "passkey-reset.js") {
		t.Error("das Passkey-Skript wird geladen, obwohl Passkeys aus sind")
	}
}

func TestForgotBeginNeedsPasskeys(t *testing.T) {
	s := newTestServer(t)
	rec := post(t, s, "/login/forgot/begin", url.Values{}, nil)
	if rec.Code != http.StatusNotFound {
		t.Fatalf("Status = %d, erwartet 404", rec.Code)
	}
}

// TestForgotBeginNamesNoAccount ist der Kern des Entwurfs: Der erste Schritt
// nennt kein Konto und verrät deshalb auch keins.
func TestForgotBeginNamesNoAccount(t *testing.T) {
	s := newTestServer(t)
	enablePasskeys(t, s)
	addUser(t, s, "philipp", store.RoleOwner)

	rec := post(t, s, "/login/forgot/begin", url.Values{}, nil)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}

	var antwort struct {
		Token     string `json:"token"`
		PublicKey struct {
			Challenge        string `json:"challenge"`
			UserVerification string `json:"userVerification"`
			AllowCredentials []any  `json:"allowCredentials"`
		} `json:"publicKey"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	if antwort.Token == "" || antwort.PublicKey.Challenge == "" {
		t.Fatalf("unvollständige Antwort: %+v", antwort)
	}
	// Ohne allowCredentials gibt es keine Kennung, aus der sich ein Konto
	// ableiten ließe.
	if len(antwort.PublicKey.AllowCredentials) != 0 {
		t.Errorf("die Antwort nennt %d Credentials", len(antwort.PublicKey.AllowCredentials))
	}
	// Die Prüfung am Gerät ist Pflicht — daran hängt, dass der Nachweis aus zwei
	// Teilen besteht.
	if antwort.PublicKey.UserVerification != "required" {
		t.Errorf("userVerification = %q, erwartet \"required\"", antwort.PublicKey.UserVerification)
	}
	// Der Anmeldename tauchte nirgends auf.
	if strings.Contains(rec.Body.String(), "philipp") {
		t.Error("die Antwort nennt einen Anmeldenamen")
	}
}

func TestForgotNewWithoutTicket(t *testing.T) {
	s := newTestServer(t)
	enablePasskeys(t, s)

	if rec := get(t, s, "/login/forgot/new", nil); rec.Code != http.StatusForbidden {
		t.Errorf("GET ohne Ticket: Status = %d, erwartet 403", rec.Code)
	}
	rec := post(t, s, "/login/forgot/new", url.Values{
		"new_password": {"neues langes Passwort"}, "new_password_confirm": {"neues langes Passwort"},
	}, nil)
	if rec.Code != http.StatusForbidden {
		t.Errorf("POST ohne Ticket: Status = %d, erwartet 403", rec.Code)
	}
}

// TestForgotNewSetsPassword prüft den Schritt nach dem bestätigten Passkey. Das
// Ticket wird direkt eingesetzt — die Zeremonie selbst deckt der
// Browserdurchlauf ab (passkey_e2e_test.go).
func TestForgotNewSetsPassword(t *testing.T) {
	s := newTestServer(t)
	enablePasskeys(t, s)
	user := addUser(t, s, "philipp", store.RoleOwner)
	altCookie, _ := login(t, s, user)

	token, err := s.resets.put(user.ID, user.Username)
	if err != nil {
		t.Fatal(err)
	}
	ticket := &http.Cookie{Name: resetCookie, Value: token}

	// Das Formular nennt das Konto, damit niemand im Zweifel bleibt, wessen
	// Passwort er gerade setzt.
	if body := get(t, s, "/login/forgot/new", ticket).Body.String(); !strings.Contains(body, "philipp") {
		t.Error("das Formular nennt das Konto nicht")
	}

	// Ein zu kurzes Passwort scheitert, ohne das Ticket zu verbrauchen.
	rec := post(t, s, "/login/forgot/new", url.Values{
		"new_password": {"kurz"}, "new_password_confirm": {"kurz"},
	}, ticket)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("kurzes Passwort: Status = %d, erwartet 400", rec.Code)
	}
	if _, ok := s.resets.peek(token); !ok {
		t.Fatal("das Ticket wurde durch einen Tippfehler verbraucht")
	}

	const neu = "ein frisches langes Passwort"
	rec = post(t, s, "/login/forgot/new", url.Values{
		"new_password": {neu}, "new_password_confirm": {neu},
	}, ticket)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}

	nach := mustUser(t, s, user.ID)
	ok, err := auth.VerifyPassword(neu, nach.PasswordHash)
	if err != nil || !ok {
		t.Errorf("das neue Passwort gilt nicht (ok=%v, err=%v)", ok, err)
	}
	// Kein Wechselzwang: Das Passwort hat der Inhaber selbst gewählt.
	if nach.MustChangePassword {
		t.Error("nach der Selbstbedienung steht ein Wechselzwang an")
	}
	// Alle Sitzungen sind beendet.
	if rec := get(t, s, "/account", altCookie); rec.Code != http.StatusSeeOther {
		t.Errorf("alte Sitzung lebt weiter (Status %d)", rec.Code)
	}
	// Das Ticket gilt genau einmal.
	if _, ok := s.resets.peek(token); ok {
		t.Error("das Ticket ist nach dem Setzen noch gültig")
	}
}
