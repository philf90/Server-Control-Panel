package httpd

import (
	"encoding/json"
	"errors"
	"net/http"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/passkeys"
	"github.com/philf90/asylum/internal/store"
)

// preauthCookie trägt das Token einer halb fertigen Anmeldung: Das Passwort ist
// geprüft, der Passkey steht noch aus. Es gewährt keinen Zugriff — die zugehörige
// Challenge liegt serverseitig, und ohne gültige Assertion entsteht keine
// Sitzung. HttpOnly, damit kein Skript es liest; kurz haltbar.
const preauthCookie = "asylum_preauth"

// preauthTTL begrenzt, wie lange zwischen Passwort und Passkey liegen darf.
const preauthTTL = 2 * time.Minute

// handlePasskeyLoginBegin ist der erste Schritt der Anmeldung mit Passkey:
// Benutzername und Passwort werden geprüft wie beim gewöhnlichen Login, dann
// liefert der Server die Assertion-Optionen und legt die Challenge unter einem
// Vorab-Cookie ab. Kein CSRF (es gibt noch keine Sitzung); der Schutz ist
// dieselbe Ratenbegrenzung wie bei /login.
func (s *Server) handlePasskeyLoginBegin(w http.ResponseWriter, r *http.Request) {
	if s.passkeys == nil {
		s.writeJSONError(w, http.StatusNotFound, "Passkeys sind nicht eingeschaltet.")
		return
	}
	if err := r.ParseForm(); err != nil {
		s.writeJSONError(w, http.StatusBadRequest, "Formulardaten unlesbar.")
		return
	}
	username := strings.TrimSpace(r.PostFormValue("username"))
	password := r.PostFormValue("password")

	ipKey := "ip:" + clientIP(r)
	userKey := "user:" + strings.ToLower(username)
	if allowed, retryAfter := s.limiter.Allowed(userKey); !allowed {
		s.audit(r, "login.throttled", username, store.ResultDenied, "Kontosperre aktiv")
		s.writeJSONError(w, http.StatusTooManyRequests, "Zu viele Fehlversuche. Bitte in "+retryAfter.String()+" erneut versuchen.")
		return
	}

	user, err := s.db.UserByName(r.Context(), username)
	if err != nil {
		if !errors.Is(err, store.ErrNotFound) {
			s.log.Error("benutzer laden", "err", err)
		}
		// Gleiche Zeit wie bei einem echten Konto, damit die Antwort nicht
		// verrät, ob es den Namen gibt.
		_, _ = auth.VerifyPassword(password, dummyHash)
		s.failPasskeyLogin(w, r, ipKey, userKey, username, "unbekanntes Konto")
		return
	}

	ok, err := auth.VerifyPassword(password, user.PasswordHash)
	if err != nil {
		s.log.Error("passwort prüfen", "err", err)
	}
	if !ok || user.Disabled {
		detail := "falsches Passwort"
		if user.Disabled {
			detail = "Konto gesperrt"
		}
		s.failPasskeyLogin(w, r, ipKey, userKey, username, detail)
		return
	}

	pu, stored, err := s.passkeyUser(r, user)
	if err != nil {
		s.log.Error("passkeys laden", "err", err)
		s.writeJSONError(w, http.StatusInternalServerError, "Die Anmeldung ließ sich nicht beginnen.")
		return
	}
	if len(stored) == 0 {
		// Passwort stimmt, aber kein Passkey hinterlegt — der Angreifer hätte an
		// dieser Stelle ohnehin das Passwort. Die Oberfläche fällt auf den Code
		// zurück.
		s.writeJSONError(w, http.StatusConflict, "Für dieses Konto ist kein Passkey hinterlegt. Bitte den Code der Authenticator-App verwenden.")
		return
	}

	opts, token, err := s.passkeys.BeginLogin(pu)
	if err != nil {
		s.log.Error("passkey-anmeldung beginnen", "err", err)
		s.writeJSONError(w, http.StatusInternalServerError, "Die Anmeldung ließ sich nicht beginnen.")
		return
	}
	http.SetCookie(w, &http.Cookie{
		Name:     preauthCookie,
		Value:    token,
		Path:     "/",
		HttpOnly: true,
		Secure:   true,
		SameSite: http.SameSiteStrictMode,
		MaxAge:   int(preauthTTL.Seconds()),
	})
	s.writeJSON(w, http.StatusOK, map[string]any{"publicKey": opts.Response})
}

// handlePasskeyLoginFinish schließt die Anmeldung ab: Die Assertion wird gegen
// die serverseitige Challenge geprüft; erst danach entsteht eine Sitzung.
func (s *Server) handlePasskeyLoginFinish(w http.ResponseWriter, r *http.Request) {
	if s.passkeys == nil {
		s.writeJSONError(w, http.StatusNotFound, "Passkeys sind nicht eingeschaltet.")
		return
	}
	c, err := r.Cookie(preauthCookie)
	if err != nil || c.Value == "" {
		s.writeJSONError(w, http.StatusBadRequest, "Die Anmeldung ist abgelaufen. Bitte erneut beginnen.")
		return
	}
	token := c.Value
	s.clearPreauthCookie(w)

	uid, ok := s.passkeys.LoginUserID(token)
	if !ok {
		s.writeJSONError(w, http.StatusBadRequest, "Die Anmeldung ist abgelaufen. Bitte erneut beginnen.")
		return
	}
	user, err := s.db.UserByID(r.Context(), uid)
	if err != nil {
		s.writeJSONError(w, http.StatusBadRequest, "Die Anmeldung ist abgelaufen. Bitte erneut beginnen.")
		return
	}

	ipKey := "ip:" + clientIP(r)
	userKey := "user:" + strings.ToLower(user.Username)
	if allowed, _ := s.limiter.Allowed(userKey); !allowed {
		s.writeJSONError(w, http.StatusTooManyRequests, "Zu viele Fehlversuche.")
		return
	}

	pu, _, err := s.passkeyUser(r, user)
	if err != nil {
		s.writeJSONError(w, http.StatusInternalServerError, "Die Anmeldung ließ sich nicht abschließen.")
		return
	}

	cred, err := s.passkeys.FinishLogin(pu, token, strings.NewReader(r.PostFormValue("credential")))
	if err != nil || user.Disabled {
		s.limiter.Fail(ipKey)
		s.limiter.Fail(userKey)
		s.audit(r, "login.failed", user.Username, store.ResultDenied, "Passkey abgelehnt")
		s.writeJSONError(w, http.StatusUnauthorized, "Der Passkey wurde nicht angenommen.")
		return
	}

	// Sign-Count und Zeitpunkt fortschreiben. Ein Klon-Hinweis wird vermerkt,
	// verhindert die Anmeldung aber nicht: Ein zu Recht wiederhergestellter
	// Authenticator soll niemanden aussperren.
	detail := "via Passkey"
	if cred.Authenticator.CloneWarning {
		detail = "via Passkey (Klon-Hinweis)"
		s.log.Warn("passkey klon-hinweis", "user", user.Username)
	}
	if data, err := json.Marshal(cred); err == nil {
		if err := s.db.UpdateWebAuthnCredentialUse(r.Context(), passkeys.CredentialID(*cred), data, time.Now()); err != nil {
			s.log.Warn("passkey fortschreiben", "err", err)
		}
	}

	s.limiter.Reset(ipKey)
	s.limiter.Reset(userKey)

	if err := s.startSession(w, r, user); err != nil {
		s.log.Error("sitzung anlegen", "err", err)
		s.writeJSONError(w, http.StatusInternalServerError, "Die Sitzung konnte nicht angelegt werden.")
		return
	}
	if err := s.db.TouchLogin(r.Context(), user.ID); err != nil {
		s.log.Warn("letzte Anmeldung vermerken", "err", err)
	}
	s.auditAs(user.Username, r, "login.success", user.Username, store.ResultOK, detail)
	s.writeJSON(w, http.StatusOK, map[string]any{"ok": true, "redirect": "/"})
}

// failPasskeyLogin behandelt einen gescheiterten ersten Schritt einheitlich:
// Zähler hoch, Audit, und nach außen eine nichtssagende Meldung.
func (s *Server) failPasskeyLogin(w http.ResponseWriter, r *http.Request, ipKey, userKey, username, detail string) {
	s.limiter.Fail(ipKey)
	s.limiter.Fail(userKey)
	s.audit(r, "login.failed", username, store.ResultDenied, detail)
	s.writeJSONError(w, http.StatusUnauthorized, "Anmeldung fehlgeschlagen.")
}

func (s *Server) clearPreauthCookie(w http.ResponseWriter) {
	http.SetCookie(w, &http.Cookie{
		Name:     preauthCookie,
		Value:    "",
		Path:     "/",
		HttpOnly: true,
		Secure:   true,
		SameSite: http.SameSiteStrictMode,
		MaxAge:   -1,
	})
}
