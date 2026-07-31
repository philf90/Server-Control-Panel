package httpd

import (
	"context"
	"errors"
	"net/http"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/store"
	"rsc.io/qr"
)

// issuer erscheint in der Authenticator-App.
const totpIssuer = "Project Asylum"

// ---------------------------------------------------------------- Anmeldung ---

func (s *Server) handleLoginForm(w http.ResponseWriter, r *http.Request) {
	if _, ok := userFrom(r.Context()); ok {
		http.Redirect(w, r, "/", http.StatusSeeOther)
		return
	}
	// Ohne Konto führt der Weg über das Setup, nicht über die Anmeldung.
	if n, err := s.db.CountUsers(r.Context()); err == nil && n == 0 {
		s.renderPage(w, r, http.StatusOK, "login",
			s.base(r, "Anmeldung").
				withError("Es ist noch kein Konto eingerichtet. Auf dem Server ausführen: sudo asylum setup-token").
				with(loginPage{}))
		return
	}
	s.renderPage(w, r, http.StatusOK, "login",
		s.base(r, "Anmeldung").with(loginPage{WebAuthnOn: s.passkeys != nil}))
}

func (s *Server) handleLogin(w http.ResponseWriter, r *http.Request) {
	if err := r.ParseForm(); err != nil {
		s.renderError(w, r, http.StatusBadRequest, "Formulardaten unlesbar.")
		return
	}
	username := strings.TrimSpace(r.PostFormValue("username"))
	password := r.PostFormValue("password")
	code := strings.TrimSpace(r.PostFormValue("code"))

	ipKey := "ip:" + clientIP(r)
	userKey := "user:" + strings.ToLower(username)

	if allowed, retryAfter := s.limiter.Allowed(userKey); !allowed {
		s.audit(r, "login.throttled", username, store.ResultDenied, "Kontosperre aktiv")
		s.failLogin(w, r, username, "Zu viele Fehlversuche. Bitte in "+retryAfter.String()+" erneut versuchen.")
		return
	}

	ctx := r.Context()
	user, err := s.db.UserByName(ctx, username)
	if err != nil {
		if !errors.Is(err, store.ErrNotFound) {
			s.log.Error("benutzer laden", "err", err)
		}
		// Auch bei unbekanntem Benutzer eine Hash-Berechnung ausführen, damit
		// die Antwortzeit nicht verrät, ob es das Konto gibt.
		_, _ = auth.VerifyPassword(password, dummyHash)
		s.limiter.Fail(ipKey)
		s.limiter.Fail(userKey)
		s.audit(r, "login.failed", username, store.ResultDenied, "unbekanntes Konto")
		s.failLogin(w, r, username, "")
		return
	}

	passwordOK, err := auth.VerifyPassword(password, user.PasswordHash)
	if err != nil {
		s.log.Error("passwort prüfen", "err", err)
	}
	factor := s.checkSecondFactor(ctx, user, code, passwordOK)

	if !passwordOK || !factor.ok || user.Disabled {
		s.limiter.Fail(ipKey)
		s.limiter.Fail(userKey)
		detail := "falsches Passwort"
		switch {
		case user.Disabled:
			detail = "Konto gesperrt"
		case passwordOK && factor.reused:
			detail = "der Code des zweiten Faktors war bereits verbraucht"
		case passwordOK && !factor.ok:
			detail = "zweiter Faktor falsch"
		}
		s.audit(r, "login.failed", username, store.ResultDenied, detail)
		// Nach außen immer dieselbe Meldung: Welcher Faktor gestimmt hat, geht
		// niemanden etwas an, der ihn nicht kennt.
		s.failLogin(w, r, username, "")
		return
	}

	// Jetzt erst gilt der Code als verbraucht. Die Bedingung im UPDATE
	// entscheidet zugleich ein Wettrennen: Melden sich zwei Anfragen
	// gleichzeitig mit demselben Code an, kommt genau eine durch.
	if factor.counter > 0 {
		if err := s.db.SetTOTPCounter(ctx, user.ID, factor.counter); err != nil {
			if errors.Is(err, store.ErrNotFound) {
				s.limiter.Fail(ipKey)
				s.limiter.Fail(userKey)
				s.audit(r, "login.failed", username, store.ResultDenied,
					"der Code des zweiten Faktors war bereits verbraucht")
				s.failLogin(w, r, username, "")
				return
			}
			s.log.Error("totp-zähler speichern", "err", err)
			s.renderError(w, r, http.StatusInternalServerError, "Die Anmeldung konnte nicht abgeschlossen werden.")
			return
		}
	}

	s.limiter.Reset(ipKey)
	s.limiter.Reset(userKey)

	if err := s.startSession(w, r, user); err != nil {
		s.log.Error("sitzung anlegen", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "Die Sitzung konnte nicht angelegt werden.")
		return
	}
	if err := s.db.TouchLogin(ctx, user.ID); err != nil {
		s.log.Warn("letzte Anmeldung vermerken", "err", err)
	}
	s.auditAs(user.Username, r, "login.success", user.Username, store.ResultOK, "")

	http.Redirect(w, r, "/", http.StatusSeeOther)
}

// dummyHash dient dem Zeitausgleich bei unbekannten Konten. Das Passwort dazu
// ist nicht bekannt und soll es auch nicht sein.
const dummyHash = "$argon2id$v=19$m=32768,t=3,p=2$" +
	"AAAAAAAAAAAAAAAAAAAAAA$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"

// secondFactor beschreibt das Ergebnis der Prüfung des zweiten Faktors.
type secondFactor struct {
	ok bool
	// counter ist das getroffene TOTP-Zeitfenster. Es wird erst nach einer
	// insgesamt erfolgreichen Anmeldung festgeschrieben.
	counter uint64
	// viaRecoveryCode heißt: ein Wiederherstellungscode wurde eingelöst und
	// ist damit bereits verbraucht.
	viaRecoveryCode bool
	// reused heißt: Der Code war einmal gültig, ist aber schon eingelöst.
	// Das gehört so ins Audit-Log — es deutet auf Mitlesen hin, nicht auf
	// ein Vertippen.
	reused bool
}

// checkSecondFactor prüft TOTP oder — falls das Format passt — einen
// Wiederherstellungscode.
//
// Der TOTP-Zähler wird hier **nicht** fortgeschrieben. RFC 6238 §5.2 verlangt,
// dass ein Code nicht erneut gilt, "after the successful validation has been
// issued" — also nach einer geglückten Anmeldung, nicht nach jedem Versuch.
// Würde schon ein Fehlversuch den Code verbrauchen, müsste jeder, der sich beim
// Passwort vertippt, eine halbe Minute auf den nächsten warten.
//
// passwordOK steuert, ob überhaupt ein Wiederherstellungscode eingelöst wird:
// Dessen Prüfung verbraucht ihn unwiderruflich, und das darf nicht passieren,
// solange das Passwort nicht stimmt. Sonst könnte jeder mit der Codeliste, aber
// ohne Passwort, die Vorräte des Kontos aufbrauchen.
func (s *Server) checkSecondFactor(ctx context.Context, user store.User, code string, passwordOK bool) secondFactor {
	if !user.TOTPConfirmed {
		// Konto mitten in der Einrichtung: Der zweite Faktor wird gleich
		// danach erzwungen (requireAuth leitet auf /setup/2fa).
		return secondFactor{ok: true}
	}
	if code == "" {
		return secondFactor{}
	}

	switch check := auth.CheckTOTP(user.TOTPSecret, code, time.Now(), user.TOTPLastCounter); {
	case check.Valid:
		return secondFactor{ok: true, counter: check.Counter}
	case check.Reused:
		return secondFactor{reused: true}
	}

	if !passwordOK {
		return secondFactor{}
	}
	normalized := auth.NormalizeRecoveryCode(code)
	if len(normalized) < 8 {
		return secondFactor{}
	}
	if err := s.db.UseRecoveryCode(ctx, user.ID, auth.HashToken(normalized)); err == nil {
		s.log.Warn("Anmeldung mit Wiederherstellungscode", "user", user.Username)
		return secondFactor{ok: true, viaRecoveryCode: true}
	}
	return secondFactor{}
}

func (s *Server) failLogin(w http.ResponseWriter, r *http.Request, username, message string) {
	if message == "" {
		message = "Anmeldung fehlgeschlagen."
	}
	s.renderPage(w, r, http.StatusUnauthorized, "login",
		s.base(r, "Anmeldung").withError(message).with(loginPage{Username: username, WebAuthnOn: s.passkeys != nil}))
}

func (s *Server) handleLogout(w http.ResponseWriter, r *http.Request) {
	if u, ok := userFrom(r.Context()); ok {
		s.audit(r, "logout", u.Username, store.ResultOK, "")
	}
	s.endSession(w, r)
	http.Redirect(w, r, "/login", http.StatusSeeOther)
}

// ------------------------------------------------------------------- Setup ---

func (s *Server) handleSetupForm(w http.ResponseWriter, r *http.Request) {
	token := r.URL.Query().Get("token")
	if err := s.checkSetupToken(r.Context(), token); err != nil {
		s.renderError(w, r, http.StatusForbidden, err.Error())
		return
	}
	s.renderPage(w, r, http.StatusOK, "setup", s.base(r, "Ersteinrichtung").with(setupPage{Token: token}))
}

func (s *Server) handleSetup(w http.ResponseWriter, r *http.Request) {
	if err := r.ParseForm(); err != nil {
		s.renderError(w, r, http.StatusBadRequest, "Formulardaten unlesbar.")
		return
	}
	ctx := r.Context()
	token := r.PostFormValue("token")

	if err := s.checkSetupToken(ctx, token); err != nil {
		s.limiter.Fail("ip:" + clientIP(r))
		s.audit(r, "setup.denied", "", store.ResultDenied, err.Error())
		s.renderError(w, r, http.StatusForbidden, err.Error())
		return
	}

	username := strings.TrimSpace(r.PostFormValue("username"))
	password := r.PostFormValue("password")
	confirm := r.PostFormValue("password_confirm")

	fail := func(msg string) {
		s.renderPage(w, r, http.StatusBadRequest, "setup",
			s.base(r, "Ersteinrichtung").withError(msg).with(setupPage{Token: token}))
	}

	if !validUsername(username) {
		fail("Der Anmeldename darf 3–32 Zeichen lang sein und nur Buchstaben, Ziffern, Punkt, Bindestrich und Unterstrich enthalten.")
		return
	}
	if password != confirm {
		fail("Die beiden Passwörter stimmen nicht überein.")
		return
	}
	if err := auth.CheckPasswordPolicy(username, password); err != nil {
		fail(err.Error())
		return
	}

	hash, err := auth.HashPassword(password)
	if err != nil {
		s.log.Error("passwort hashen", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "Das Passwort konnte nicht gespeichert werden.")
		return
	}
	secret, err := auth.GenerateTOTPSecret()
	if err != nil {
		s.log.Error("totp-geheimnis", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "Der zweite Faktor konnte nicht vorbereitet werden.")
		return
	}

	user := store.User{
		Username: username, PasswordHash: hash, Role: store.RoleOwner,
		TOTPSecret: secret, TOTPConfirmed: false,
	}
	id, err := s.db.CreateUser(ctx, user)
	if err != nil {
		s.log.Error("benutzer anlegen", "err", err)
		fail("Das Konto konnte nicht angelegt werden.")
		return
	}
	user.ID = id

	// Der Token ist verbraucht, sobald das Konto steht.
	if err := s.db.DeleteSetting(ctx, store.SettingSetupTokenHash); err != nil {
		s.log.Warn("setup-token entfernen", "err", err)
	}
	_ = s.db.DeleteSetting(ctx, store.SettingSetupTokenExpires)

	if err := s.startSession(w, r, user); err != nil {
		s.log.Error("sitzung anlegen", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "Die Sitzung konnte nicht angelegt werden.")
		return
	}
	s.auditAs(username, r, "setup.completed", username, store.ResultOK, "Owner-Konto angelegt")

	http.Redirect(w, r, "/setup/2fa", http.StatusSeeOther)
}

// checkSetupToken prüft Vorhandensein, Gültigkeit und Ablauf des Tokens.
func (s *Server) checkSetupToken(ctx context.Context, token string) error {
	n, err := s.db.CountUsers(ctx)
	if err != nil {
		return errors.New("Der Zustand des Panels konnte nicht ermittelt werden.")
	}
	if n > 0 {
		return errors.New("Die Ersteinrichtung ist bereits abgeschlossen.")
	}
	if token == "" {
		return errors.New("Es fehlt der Setup-Token. Auf dem Server ausführen: sudo asylum setup-token")
	}

	stored, err := s.db.Setting(ctx, store.SettingSetupTokenHash)
	if err != nil {
		return errors.New("Es ist kein Setup-Token hinterlegt. Auf dem Server ausführen: sudo asylum setup-token")
	}
	expiresRaw, err := s.db.Setting(ctx, store.SettingSetupTokenExpires)
	if err == nil {
		if expires, err := time.Parse(time.RFC3339, expiresRaw); err == nil && time.Now().After(expires) {
			return errors.New("Der Setup-Token ist abgelaufen. Auf dem Server ausführen: sudo asylum setup-token")
		}
	}
	if auth.HashToken(token) != stored {
		return errors.New("Der Setup-Token ist ungültig.")
	}
	return nil
}

// --------------------------------------------------------- Zweiter Faktor ---

func (s *Server) handleTOTPForm(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	if user.TOTPConfirmed {
		http.Redirect(w, r, "/", http.StatusSeeOther)
		return
	}
	s.renderPage(w, r, http.StatusOK, "totp", s.base(r, "Zwei-Faktor einrichten").with(totpPage{
		Secret:          user.TOTPSecret,
		SecretFormatted: auth.FormatSecret(user.TOTPSecret),
		URI:             auth.TOTPProvisioningURI(user.TOTPSecret, user.Username, totpIssuer),
	}))
}

// handleTOTPQR rendert den Provisioning-Code als PNG. Der QR-Code entsteht
// lokal — das Geheimnis verlässt den Server nicht in Richtung eines fremden
// Dienstes.
func (s *Server) handleTOTPQR(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	if user.TOTPConfirmed {
		http.Error(w, "bereits eingerichtet", http.StatusForbidden)
		return
	}

	uri := auth.TOTPProvisioningURI(user.TOTPSecret, user.Username, totpIssuer)
	code, err := qr.Encode(uri, qr.M)
	if err != nil {
		s.log.Error("qr-code", "err", err)
		http.Error(w, "QR-Code nicht erzeugbar", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "image/png")
	w.Header().Set("Cache-Control", "no-store")
	_, _ = w.Write(code.PNG())
}

func (s *Server) handleTOTPConfirm(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	if user.TOTPConfirmed {
		http.Redirect(w, r, "/", http.StatusSeeOther)
		return
	}

	code := strings.TrimSpace(r.PostFormValue("code"))
	if !auth.VerifyTOTP(user.TOTPSecret, code, time.Now()) {
		s.audit(r, "2fa.failed", user.Username, store.ResultDenied, "Bestätigungscode falsch")
		s.renderPage(w, r, http.StatusBadRequest, "totp",
			s.base(r, "Zwei-Faktor einrichten").
				withError("Der Code stimmt nicht. Bitte die Uhrzeit des Servers prüfen und den aktuellen Code eingeben.").
				with(totpPage{
					Secret:          user.TOTPSecret,
					SecretFormatted: auth.FormatSecret(user.TOTPSecret),
					URI:             auth.TOTPProvisioningURI(user.TOTPSecret, user.Username, totpIssuer),
				}))
		return
	}

	ctx := r.Context()
	if err := s.db.SetTOTP(ctx, user.ID, user.TOTPSecret, true); err != nil {
		s.log.Error("totp bestätigen", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "Der zweite Faktor konnte nicht gespeichert werden.")
		return
	}

	codes, hashes, err := auth.NewRecoveryCodes()
	if err != nil {
		s.log.Error("wiederherstellungscodes", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "Die Wiederherstellungscodes konnten nicht erzeugt werden.")
		return
	}
	if err := s.db.ReplaceRecoveryCodes(ctx, user.ID, hashes); err != nil {
		s.log.Error("wiederherstellungscodes speichern", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "Die Wiederherstellungscodes konnten nicht gespeichert werden.")
		return
	}
	s.audit(r, "2fa.enabled", user.Username, store.ResultOK, "")

	// Die Codes werden genau hier ein einziges Mal im Klartext gezeigt.
	s.renderPage(w, r, http.StatusOK, "codes",
		s.base(r, "Wiederherstellungscodes").with(codesPage{Codes: codes}))
}

// auditAs schreibt einen Eintrag für einen Benutzer, der noch nicht im Kontext
// steht — etwa direkt bei der Anmeldung.
func (s *Server) auditAs(actor string, r *http.Request, action, target, result, detail string) {
	entry := store.AuditEntry{
		At: time.Now(), Actor: actor, Action: action,
		Target: target, Result: result, IP: clientIP(r), Detail: detail,
	}
	if err := s.db.AppendAudit(context.Background(), entry); err != nil {
		s.log.Error("audit-eintrag", "action", action, "err", err)
	}
}

// validUsername hält Anmeldenamen frei von Zeichen, die in Logs, URLs oder
// Shell-Aufrufen Ärger machen.
func validUsername(name string) bool {
	if len(name) < 3 || len(name) > 32 {
		return false
	}
	for _, r := range name {
		switch {
		case r >= 'a' && r <= 'z', r >= 'A' && r <= 'Z', r >= '0' && r <= '9':
		case r == '.' || r == '-' || r == '_':
		default:
			return false
		}
	}
	return true
}
