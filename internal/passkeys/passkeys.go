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
	"math"
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
	//
	// ResidentKey "preferred": Ein auffindbarer (discoverable) Schlüssel liegt
	// mit seiner Kontozuordnung im Authenticator selbst. Nur damit funktioniert
	// die Zurücksetzung eines vergessenen Passworts, denn dort gibt es kein
	// Konto, das der Server vorher nennen könnte — der Browser bietet an, was er
	// für diese Domain hat. "required" wäre falsch: Ein Sicherheitsschlüssel mit
	// belegtem Speicher würde die Registrierung abweisen, und ein Passkey ohne
	// diese Eigenschaft ist als zweiter Faktor unverändert brauchbar.
	opts, data, err := m.wa.BeginRegistration(u,
		wa.WithExclusions(excludeList(u.Credentials)),
		wa.WithResidentKeyRequirement(protocol.ResidentKeyRequirementPreferred),
	)
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

// ---------------------------------------- Anmeldung ohne genanntes Konto ---

// ErrNoUserVerification meldet eine Assertion ohne Prüfung am Gerät — ohne PIN,
// Fingerabdruck oder Gesicht. Für den gewöhnlichen zweiten Faktor genügt der
// Besitz des Authenticators; für eine Zurücksetzung nicht.
var ErrNoUserVerification = errors.New("Passkey ohne Prüfung am Gerät bestätigt")

// BeginDiscoverableLogin eröffnet eine Zeremonie, in der der Browser selbst
// anbietet, welche Passkeys er für diese Domain hat. Anders als BeginLogin
// braucht sie kein Konto — und verrät deshalb auch keins.
//
// Die Prüfung am Gerät ist Pflicht (userVerification "required"). Damit besteht
// der Nachweis aus zwei Teilen: dem Besitz des Authenticators und dem Wissen
// oder Merkmal, das ihn entsperrt. Ein entwendetes, entsperrtes Notebook genügt
// nicht. Die Bibliothek prüft das Flag beim Abschluss; FinishDiscoverableLogin
// prüft es zusätzlich selbst.
func (m *Manager) BeginDiscoverableLogin() (*protocol.CredentialAssertion, string, error) {
	opts, data, err := m.wa.BeginDiscoverableLogin(wa.WithUserVerification(protocol.VerificationRequired))
	if err != nil {
		return nil, "", fmt.Errorf("anmeldung beginnen: %w", err)
	}
	// userID 0: Zu Beginn ist das Konto unbekannt — es steht erst in der Antwort
	// des Authenticators.
	token, err := m.store(0, data)
	if err != nil {
		return nil, "", err
	}
	return opts, token, nil
}

// FinishDiscoverableLogin prüft die Assertion. lookup übersetzt die Kennung aus
// der Antwort in ein Konto; sie ist dieselben acht Bytes, die WebAuthnID
// erzeugt.
//
// Zurückgegeben wird das aufgelöste Konto samt genutztem Credential.
func (m *Manager) FinishDiscoverableLogin(token string, body io.Reader, lookup func(userID int64) (User, error)) (User, *wa.Credential, error) {
	data, ok := m.takeDiscoverable(token)
	if !ok {
		return User{}, nil, ErrNoSession
	}
	parsed, err := protocol.ParseCredentialRequestResponseBody(body)
	if err != nil {
		return User{}, nil, fmt.Errorf("antwort lesen: %w", err)
	}

	var resolved User
	handler := func(_, userHandle []byte) (wa.User, error) {
		if len(userHandle) != 8 {
			return nil, fmt.Errorf("unerwartete Kontokennung (%d Byte)", len(userHandle))
		}
		id := binary.BigEndian.Uint64(userHandle)
		if id > math.MaxInt64 {
			return nil, errors.New("unerwartete Kontokennung")
		}
		u, err := lookup(int64(id))
		if err != nil {
			return nil, err
		}
		resolved = u
		return u, nil
	}

	cred, err := m.wa.ValidateDiscoverableLogin(handler, data, parsed)
	if err != nil {
		return User{}, nil, fmt.Errorf("anmeldung prüfen: %w", err)
	}
	// Gürtel und Hosenträger: Die Bibliothek prüft das Flag, weil die Zeremonie
	// mit "required" begonnen wurde. Diese Zusage steht hier trotzdem noch
	// einmal ausdrücklich im Code — sie ist der Unterschied zwischen einem und
	// zwei Faktoren.
	if !cred.Flags.UserVerified {
		return User{}, nil, ErrNoUserVerification
	}
	return resolved, cred, nil
}

// takeDiscoverable löst eine Zeremonie ein, die ohne Konto begonnen wurde.
func (m *Manager) takeDiscoverable(token string) (wa.SessionData, bool) {
	return m.take(token, 0)
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

// LoginUserID verrät, welchem Konto eine offene Anmelde-Challenge gehört, ohne
// sie zu verbrauchen. Die Anmeldung kennt beim Abschluss nur das Token aus dem
// Vorab-Cookie, nicht das Konto — erst damit lässt sich der Benutzer samt seiner
// Credentials laden und an FinishLogin übergeben, das die Challenge dann
// einlöst. Ein abgelaufenes Token zählt als nicht vorhanden.
func (m *Manager) LoginUserID(token string) (int64, bool) {
	m.mu.Lock()
	defer m.mu.Unlock()
	p, ok := m.sessions[token]
	if !ok || p.expires.Before(m.now()) {
		return 0, false
	}
	return p.userID, true
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
