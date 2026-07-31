package httpd

import (
	"context"
	"encoding/json"
	"net/http"
	"net/url"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/store"
)

func mustUser(t *testing.T, s *Server, id int64) store.User {
	t.Helper()
	u, err := s.db.UserByID(context.Background(), id)
	if err != nil {
		t.Fatalf("UserByID: %v", err)
	}
	return u
}

// ------------------------------------------------ Zurücksetzen durch Owner ---

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
	for _, pfad := range []string{"/", "/dienste", "/zugaenge", "/konto"} {
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
	if rec := get(t, s, "/konto", altCookie); rec.Code != http.StatusSeeOther {
		t.Errorf("alte Sitzung lebt weiter (Status %d)", rec.Code)
	}
	// Das Ticket gilt genau einmal.
	if _, ok := s.resets.peek(token); ok {
		t.Error("das Ticket ist nach dem Setzen noch gültig")
	}
}
