package httpd

import (
	"context"
	"encoding/json"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"testing"

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
	if body := get(t, s, "/account", cookie).Body.String(); strings.Contains(body, "Passkey hinzufügen") {
		t.Error("der Passkey-Abschnitt erscheint, obwohl die Funktion aus ist")
	}

	enablePasskeys(t, s)
	body := get(t, s, "/account", cookie).Body.String()
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
	rec := post(t, s, "/account/passkeys/register/begin", url.Values{"_csrf": {csrf}, "password": {testPassword}}, cookie)
	if rec.Code != http.StatusNotFound {
		t.Fatalf("bei ausgeschalteter Funktion Status = %d, erwartet 404", rec.Code)
	}

	enablePasskeys(t, s)

	// Falsches Passwort → 403, kein Token.
	rec = post(t, s, "/account/passkeys/register/begin", url.Values{"_csrf": {csrf}, "password": {"falsch"}}, cookie)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("bei falschem Passwort Status = %d, erwartet 403", rec.Code)
	}

	// Richtiges Passwort → 200 mit Token und Optionen, die die RP-ID tragen.
	rec = post(t, s, "/account/passkeys/register/begin", url.Values{"_csrf": {csrf}, "password": {testPassword}}, cookie)
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
	rec := post(t, s, "/account/passkeys/"+strconv.FormatInt(id, 10)+"/rename",
		url.Values{"_csrf": {csrf}, "label": {"Titan-Stick"}}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("rename Status = %d", rec.Code)
	}
	list, _ := s.db.WebAuthnCredentialsByUser(context.Background(), user.ID)
	if len(list) != 1 || list[0].Label != "Titan-Stick" {
		t.Fatalf("Label nach rename: %+v", list)
	}

	// Entfernen.
	rec = post(t, s, "/account/passkeys/"+strconv.FormatInt(id, 10)+"/delete",
		url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("delete Status = %d", rec.Code)
	}
	if n, _ := s.db.CountWebAuthnCredentials(context.Background(), user.ID); n != 0 {
		t.Errorf("nach delete sind noch %d Passkeys da", n)
	}
}

// Ein Passkey darf nur mit gültigem CSRF-Token angelegt werden — sonst wäre der
// zweite Faktor über eine fremde Seite hinzufügbar.
func TestPasskeyRegisterBeginRejectsBadCSRF(t *testing.T) {
	s := newTestServer(t)
	enablePasskeys(t, s)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	rec := post(t, s, "/account/passkeys/register/begin",
		url.Values{"_csrf": {"falsch"}, "password": {testPassword}}, cookie)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Status = %d, erwartet 403 bei falschem CSRF", rec.Code)
	}
}
