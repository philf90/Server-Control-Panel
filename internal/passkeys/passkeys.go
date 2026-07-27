// Package passkeys kapselt die WebAuthn-Zeremonien (Registrierung und
// Anmeldung) hinter einer kleinen, auf das Panel zugeschnittenen Oberfläche.
//
// Die Krypto selbst kommt aus github.com/go-webauthn/webauthn — WebAuthn ist zu
// umfangreich (CBOR, COSE, mehrere Attestierungsformate, Signaturprüfung über
// verschiedene Verfahren), um es für einen sicherheitskritischen Pfad selbst zu
// schreiben. Dieses Paket liefert den Adapter: den Benutzertyp, den die
// Bibliothek erwartet, einen kurzlebigen Speicher für die Challenge zwischen
// Begin und Finish, und die Umrechnung in das, was der Store ablegt.
package passkeys

import (
	"crypto/rand"
	"encoding/base64"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"sync"
	"time"

	"github.com/go-webauthn/webauthn/protocol"
	wa "github.com/go-webauthn/webauthn/webauthn"
)

// Fehler, die der Aufrufer unterscheiden können muss.
var (
	// ErrNoSession: zum vorgelegten Token gibt es keine offene Zeremonie mehr —
	// abgelaufen, schon eingelöst oder nie dagewesen.
	ErrNoSession = errors.New("keine offene WebAuthn-Zeremonie")
)

// Config sind die Angaben, die WebAuthn an die Herkunft bindet.
type Config struct {
	// RPID ist die registrierbare Domain (ohne Schema, ohne Port). Ein Passkey
	// ist genau an sie gebunden.
	RPID string
	// DisplayName steht im Anmeldedialog des Browsers.
	DisplayName string
	// Origins sind die erlaubten vollständigen Ursprünge, z. B.
	// https://panel.example.org:8443.
	Origins []string
}

// Manager führt die Zeremonien und hält die offenen Challenges.
type Manager struct {
	wa  *wa.WebAuthn
	ttl time.Duration

	mu       sync.Mutex
	sessions map[string]*pending
	// now ist auslagerbar, damit Tests das Ablaufen ohne echtes Warten prüfen.
	now func() time.Time
}

type pending struct {
	userID  int64
	data    wa.SessionData
	expires time.Time
}

// New baut den Manager aus der Konfiguration.
func New(cfg Config) (*Manager, error) {
	w, err := wa.New(&wa.Config{
		RPID:          cfg.RPID,
		RPDisplayName: cfg.DisplayName,
		RPOrigins:     cfg.Origins,
	})
	if err != nil {
		return nil, fmt.Errorf("webauthn einrichten: %w", err)
	}
	return &Manager{
		wa:       w,
		ttl:      2 * time.Minute,
		sessions: make(map[string]*pending),
		now:      time.Now,
	}, nil
}

// User ist die Sicht der Bibliothek auf ein Konto. WebAuthnID ist bewusst die
// (undurchsichtige, stabile) Datenbank-Kennung und nicht der Anmeldename: Die
// Spezifikation verlangt, dass Entscheidungen an dieser Kennung hängen, nicht am
// Namen, der sich ändern darf.
type User struct {
	ID          int64
	Name        string
	DisplayName string
	Credentials []wa.Credential
}

// WebAuthnID kodiert die Konto-ID als acht Bytes.
func (u User) WebAuthnID() []byte {
	b := make([]byte, 8)
	//nolint:gosec // G115: u.ID ist eine positive Autoincrement-Kennung, kein Überlauf möglich
	binary.BigEndian.PutUint64(b, uint64(u.ID))
	return b
}

func (u User) WebAuthnName() string { return u.Name }

func (u User) WebAuthnDisplayName() string {
	if u.DisplayName != "" {
		return u.DisplayName
	}
	return u.Name
}

func (u User) WebAuthnCredentials() []wa.Credential { return u.Credentials }

// BeginRegistration eröffnet die Registrierung eines neuen Authenticators. Der
// Rückgabewert ist das Options-Objekt für navigator.credentials.create und ein
// Token, unter dem die Challenge bis zum Finish liegt.
func (m *Manager) BeginRegistration(u User) (*protocol.CredentialCreation, string, error) {
	// excludeCredentials aus den vorhandenen Passkeys: derselbe Authenticator
	// soll sich nicht zweimal registrieren.
	opts, data, err := m.wa.BeginRegistration(u, wa.WithExclusions(excludeList(u.Credentials)))
	if err != nil {
		return nil, "", fmt.Errorf("registrierung beginnen: %w", err)
	}
	token, err := m.store(u.ID, data)
	if err != nil {
		return nil, "", err
	}
	return opts, token, nil
}

// FinishRegistration prüft die Antwort des Authenticators und liefert das fertige
// Credential zum Ablegen. body ist der JSON-Rumpf der Browser-Antwort.
func (m *Manager) FinishRegistration(u User, token string, body io.Reader) (*wa.Credential, error) {
	data, ok := m.take(token, u.ID)
	if !ok {
		return nil, ErrNoSession
	}
	parsed, err := protocol.ParseCredentialCreationResponseBody(body)
	if err != nil {
		return nil, fmt.Errorf("antwort lesen: %w", err)
	}
	cred, err := m.wa.CreateCredential(u, data, parsed)
	if err != nil {
		return nil, fmt.Errorf("registrierung prüfen: %w", err)
	}
	return cred, nil
}

// BeginLogin eröffnet die Anmeldung mit einem vorhandenen Passkey.
func (m *Manager) BeginLogin(u User) (*protocol.CredentialAssertion, string, error) {
	opts, data, err := m.wa.BeginLogin(u)
	if err != nil {
		return nil, "", fmt.Errorf("anmeldung beginnen: %w", err)
	}
	token, err := m.store(u.ID, data)
	if err != nil {
		return nil, "", err
	}
	return opts, token, nil
}

// FinishLogin prüft die Assertion und liefert das genutzte Credential zurück —
// mit fortgeschriebenem Sign-Count und einem etwaigen Klon-Hinweis, den der
// Aufrufer auswerten sollte.
func (m *Manager) FinishLogin(u User, token string, body io.Reader) (*wa.Credential, error) {
	data, ok := m.take(token, u.ID)
	if !ok {
		return nil, ErrNoSession
	}
	parsed, err := protocol.ParseCredentialRequestResponseBody(body)
	if err != nil {
		return nil, fmt.Errorf("antwort lesen: %w", err)
	}
	cred, err := m.wa.ValidateLogin(u, data, parsed)
	if err != nil {
		return nil, fmt.Errorf("anmeldung prüfen: %w", err)
	}
	return cred, nil
}

// CredentialID liefert die base64url-Kennung eines Credentials — der Wert, unter
// dem der Store es führt.
func CredentialID(c wa.Credential) string {
	return base64.RawURLEncoding.EncodeToString(c.ID)
}

func excludeList(creds []wa.Credential) []protocol.CredentialDescriptor {
	out := make([]protocol.CredentialDescriptor, 0, len(creds))
	for _, c := range creds {
		out = append(out, c.Descriptor())
	}
	return out
}

// store legt die Challenge unter einem frischen Token ab und räumt dabei
// abgelaufene Einträge weg.
func (m *Manager) store(userID int64, data *wa.SessionData) (string, error) {
	token, err := newToken()
	if err != nil {
		return "", err
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	m.gcLocked()
	m.sessions[token] = &pending{userID: userID, data: *data, expires: m.now().Add(m.ttl)}
	return token, nil
}

// take holt eine Challenge heraus und entfernt sie — jedes Token gilt genau
// einmal. Ein abgelaufenes oder einem anderen Konto gehörendes Token zählt als
// nicht vorhanden.
func (m *Manager) take(token string, userID int64) (wa.SessionData, bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	p, ok := m.sessions[token]
	if !ok {
		return wa.SessionData{}, false
	}
	delete(m.sessions, token)
	if p.userID != userID || p.expires.Before(m.now()) {
		return wa.SessionData{}, false
	}
	return p.data, true
}

func (m *Manager) gcLocked() {
	now := m.now()
	for k, p := range m.sessions {
		if p.expires.Before(now) {
			delete(m.sessions, k)
		}
	}
}

func newToken() (string, error) {
	b := make([]byte, 32)
	if _, err := rand.Read(b); err != nil {
		return "", fmt.Errorf("token erzeugen: %w", err)
	}
	return base64.RawURLEncoding.EncodeToString(b), nil
}
