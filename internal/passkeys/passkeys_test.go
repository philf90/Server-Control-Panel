package passkeys

import (
	"errors"
	"strings"
	"testing"
	"time"

	wa "github.com/go-webauthn/webauthn/webauthn"
)

func testManager(t *testing.T) *Manager {
	t.Helper()
	m, err := New(Config{
		RPID:        "panel.example.org",
		DisplayName: "Project Asylum",
		Origins:     []string{"https://panel.example.org:8443"},
	})
	if err != nil {
		t.Fatalf("New: %v", err)
	}
	return m
}

func TestNewRejectsEmptyConfig(t *testing.T) {
	if _, err := New(Config{}); err == nil {
		t.Error("New ohne RPID/Origins sollte scheitern")
	}
}

func TestUserAdapter(t *testing.T) {
	u := User{ID: 258, Name: "philipp"}
	id := u.WebAuthnID()
	if len(id) != 8 {
		t.Fatalf("WebAuthnID = %d Bytes, erwartet 8", len(id))
	}
	// 258 = 0x0102 → die beiden letzten Bytes.
	if id[6] != 0x01 || id[7] != 0x02 {
		t.Errorf("WebAuthnID kodiert die ID falsch: %v", id)
	}
	if u.WebAuthnName() != "philipp" || u.WebAuthnDisplayName() != "philipp" {
		t.Error("Name/DisplayName falsch")
	}
	// DisplayName fällt auf den Namen zurück, ist aber überschreibbar.
	u.DisplayName = "Philipp F."
	if u.WebAuthnDisplayName() != "Philipp F." {
		t.Error("DisplayName-Override greift nicht")
	}
	if len(u.WebAuthnCredentials()) != 0 {
		t.Error("ohne Passkeys sollte die Liste leer sein")
	}
}

func TestSessionStoreConsumesOnce(t *testing.T) {
	m := testManager(t)
	token, err := m.store(7, &wa.SessionData{})
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := m.take(token, 7); !ok {
		t.Fatal("erstes take sollte gelingen")
	}
	// Ein zweites Mal darf dasselbe Token nicht mehr gelten.
	if _, ok := m.take(token, 7); ok {
		t.Error("das Token wurde ein zweites Mal angenommen")
	}
}

func TestSessionStoreRejectsWrongUserAndExpiry(t *testing.T) {
	m := testManager(t)
	clock := time.Unix(1_000_000, 0)
	m.now = func() time.Time { return clock }

	token, _ := m.store(7, &wa.SessionData{})
	// Falsches Konto: das Token gehört Konto 7, nicht 8.
	if _, ok := m.take(token, 8); ok {
		t.Error("ein fremdes Konto konnte die Challenge einlösen")
	}

	// take verbraucht auch bei Ablehnung — neu anlegen und ablaufen lassen.
	token, _ = m.store(7, &wa.SessionData{})
	clock = clock.Add(3 * time.Minute) // ttl ist 2 Minuten
	if _, ok := m.take(token, 7); ok {
		t.Error("eine abgelaufene Challenge wurde angenommen")
	}
}

func TestSessionGarbageCollectsExpired(t *testing.T) {
	m := testManager(t)
	clock := time.Unix(1_000_000, 0)
	m.now = func() time.Time { return clock }

	if _, err := m.store(1, &wa.SessionData{}); err != nil {
		t.Fatal(err)
	}
	clock = clock.Add(10 * time.Minute)
	// Das Anlegen einer neuen Challenge räumt die abgelaufene mit weg.
	if _, err := m.store(2, &wa.SessionData{}); err != nil {
		t.Fatal(err)
	}
	m.mu.Lock()
	n := len(m.sessions)
	m.mu.Unlock()
	if n != 1 {
		t.Errorf("nach GC sind %d Challenges übrig, erwartet 1", n)
	}
}

func TestFinishWithUnknownTokenIsNoSession(t *testing.T) {
	m := testManager(t)
	u := User{ID: 1, Name: "x"}
	if _, err := m.FinishRegistration(u, "gibtsnicht", strings.NewReader("{}")); !errors.Is(err, ErrNoSession) {
		t.Errorf("FinishRegistration = %v, erwartet ErrNoSession", err)
	}
	if _, err := m.FinishLogin(u, "gibtsnicht", strings.NewReader("{}")); !errors.Is(err, ErrNoSession) {
		t.Errorf("FinishLogin = %v, erwartet ErrNoSession", err)
	}
}

func TestBeginRegistrationYieldsOptionsAndToken(t *testing.T) {
	m := testManager(t)
	u := User{ID: 42, Name: "philipp"}
	opts, token, err := m.BeginRegistration(u)
	if err != nil {
		t.Fatalf("BeginRegistration: %v", err)
	}
	if token == "" {
		t.Error("kein Token")
	}
	if opts == nil || opts.Response.RelyingParty.ID != "panel.example.org" {
		t.Errorf("Optionen tragen die falsche RP-ID: %+v", opts)
	}
	// Die Challenge liegt jetzt vor und lässt sich genau einmal einlösen.
	if _, ok := m.take(token, 42); !ok {
		t.Error("die Challenge wurde nicht hinterlegt")
	}
}
