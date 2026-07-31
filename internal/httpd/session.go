package httpd

import (
	"context"
	"crypto/subtle"
	"errors"
	"net/http"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/store"
)

const (
	sessionCookie = "asylum_session"

	// Absolute Obergrenze einer Sitzung: Nach zwölf Stunden ist erneute
	// Anmeldung fällig, egal wie aktiv jemand war.
	sessionAbsoluteTTL = 12 * time.Hour
	// Leerlauf-Grenze: zwei Stunden ohne Anfrage beenden die Sitzung.
	sessionIdleTTL = 2 * time.Hour
)

type ctxKey int

const (
	ctxUser ctxKey = iota
	ctxSession
)

// userFrom liefert den angemeldeten Benutzer aus dem Kontext.
func userFrom(ctx context.Context) (store.User, bool) {
	u, ok := ctx.Value(ctxUser).(store.User)
	return u, ok
}

// sessionFrom liefert die Sitzung aus dem Kontext.
func sessionFrom(ctx context.Context) (store.Session, bool) {
	s, ok := ctx.Value(ctxSession).(store.Session)
	return s, ok
}

// startSession legt eine Sitzung an und setzt das Cookie.
func (s *Server) startSession(w http.ResponseWriter, r *http.Request, user store.User) error {
	token, err := auth.NewToken()
	if err != nil {
		return err
	}
	csrf, err := auth.NewToken()
	if err != nil {
		return err
	}

	now := time.Now()
	sess := store.Session{
		ID:         auth.HashToken(token),
		UserID:     user.ID,
		CSRFToken:  csrf,
		CreatedAt:  now,
		LastSeenAt: now,
		ExpiresAt:  now.Add(sessionIdleTTL),
		IP:         clientIP(r),
		UserAgent:  truncate(r.UserAgent(), 200),
	}
	if err := s.db.CreateSession(r.Context(), sess); err != nil {
		return err
	}

	http.SetCookie(w, &http.Cookie{
		Name:  sessionCookie,
		Value: token,
		Path:  "/",
		// Secure: Das Panel ist ausschließlich über HTTPS erreichbar.
		// SameSite=Strict: Das Cookie geht bei keiner fremden Verlinkung mit,
		// was zusammen mit dem CSRF-Token einen zweiten Riegel darstellt.
		HttpOnly: true,
		Secure:   true,
		SameSite: http.SameSiteStrictMode,
		MaxAge:   int(sessionAbsoluteTTL.Seconds()),
	})
	return nil
}

// endSession meldet die aktuelle Sitzung ab.
func (s *Server) endSession(w http.ResponseWriter, r *http.Request) {
	if c, err := r.Cookie(sessionCookie); err == nil {
		_ = s.db.DeleteSession(r.Context(), auth.HashToken(c.Value))
	}
	http.SetCookie(w, &http.Cookie{
		Name:     sessionCookie,
		Value:    "",
		Path:     "/",
		HttpOnly: true,
		Secure:   true,
		SameSite: http.SameSiteStrictMode,
		MaxAge:   -1,
	})
}

// loadSession legt Benutzer und Sitzung in den Kontext, sofern angemeldet.
// Nicht angemeldete Anfragen laufen unverändert weiter — das Abweisen
// übernimmt requireAuth.
func (s *Server) loadSession(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Hat loadToken schon einen Token aufgelöst, wird das Cookie NICHT
		// gelesen. Eine Anfrage hat einen Anmeldeweg und nicht zwei: Sonst stünde
		// hier der Benutzer des Cookies im Kontext, während die Grenzen des
		// Tokens gelten — zwei Identitäten in einer Anfrage, und die Rechte der
		// einen mit den Schranken der anderen.
		if _, ok := tokenFrom(r.Context()); ok {
			next.ServeHTTP(w, r)
			return
		}

		cookie, err := r.Cookie(sessionCookie)
		if err != nil {
			next.ServeHTTP(w, r)
			return
		}

		ctx := r.Context()
		sess, err := s.db.SessionByID(ctx, auth.HashToken(cookie.Value))
		if err != nil {
			if !errors.Is(err, store.ErrNotFound) {
				s.log.Error("sitzung laden", "err", err)
			}
			next.ServeHTTP(w, r)
			return
		}

		user, err := s.db.UserByID(ctx, sess.UserID)
		if err != nil || user.Disabled {
			_ = s.db.DeleteSession(ctx, sess.ID)
			next.ServeHTTP(w, r)
			return
		}

		// Leerlauf-Fenster verlängern, aber nie über die absolute Grenze
		// hinaus.
		now := time.Now()
		absolute := sess.CreatedAt.Add(sessionAbsoluteTTL)
		newExpiry := now.Add(sessionIdleTTL)
		if newExpiry.After(absolute) {
			newExpiry = absolute
		}
		if newExpiry.After(now) && newExpiry.Sub(sess.ExpiresAt) > time.Minute {
			if err := s.db.TouchSession(ctx, sess.ID, newExpiry); err != nil {
				s.log.Warn("sitzung verlängern", "err", err)
			}
			sess.ExpiresAt = newExpiry
		}

		ctx = context.WithValue(ctx, ctxUser, user)
		ctx = context.WithValue(ctx, ctxSession, sess)
		next.ServeHTTP(w, r.WithContext(ctx))
	})
}

// requireAuth weist nicht angemeldete Anfragen ab und erzwingt beides, was vor
// dem eigentlichen Panel zu erledigen ist: die Zwei-Faktor-Einrichtung und den
// Wechsel eines Einmalpassworts.
func (s *Server) requireAuth(next http.Handler) http.Handler {
	return s.requireSetupDone(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		user, _ := userFrom(r.Context())
		// Ein Einmalpasswort aus einer Zurücksetzung trägt genau bis hierher.
		// Der Owner, der es vergeben hat, kennt es — es darf keine dauerhafte
		// Zugangsberechtigung daraus werden.
		if user.MustChangePassword {
			http.Redirect(w, r, "/account/password-change", http.StatusSeeOther)
			return
		}
		next.ServeHTTP(w, r)
	}))
}

// requireSetupDone verlangt eine Anmeldung mit abgeschlossener
// Zwei-Faktor-Einrichtung, aber ohne den Wechselzwang zu prüfen. Genau das
// braucht die Wechselseite selbst: Ginge sie durch requireAuth, würde sie auf
// sich selbst weiterleiten.
func (s *Server) requireSetupDone(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		user, ok := userFrom(r.Context())
		if !ok {
			s.redirectToLogin(w, r)
			return
		}
		// Ein Konto ohne bestätigtes TOTP darf nichts außer die Einrichtung.
		if !user.TOTPConfirmed {
			http.Redirect(w, r, "/setup/2fa", http.StatusSeeOther)
			return
		}
		next.ServeHTTP(w, r)
	})
}

// requireWrite lässt nur Rollen mit Schreibrecht durch.
func (s *Server) requireWrite(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		user, ok := userFrom(r.Context())
		if !ok || !user.CanWrite() {
			s.audit(r, "access.denied", r.URL.Path, store.ResultDenied, "Schreibrecht fehlt")
			s.renderError(w, r, http.StatusForbidden, "Diese Aktion erfordert Schreibrechte.")
			return
		}
		next.ServeHTTP(w, r)
	})
}

// verifyCSRF prüft bei allen verändernden Anfragen das Double-Submit-Token.
func (s *Server) verifyCSRF(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.Method {
		case http.MethodGet, http.MethodHead, http.MethodOptions:
			next.ServeHTTP(w, r)
			return
		}

		if _, ok := sessionFrom(r.Context()); !ok {
			s.renderError(w, r, http.StatusForbidden, "Keine gültige Sitzung.")
			return
		}
		if err := r.ParseForm(); err != nil {
			s.renderError(w, r, http.StatusBadRequest, "Formulardaten unlesbar.")
			return
		}
		if !s.csrfPasst(r, r.PostFormValue("_csrf")) {
			s.audit(r, "csrf.rejected", r.URL.Path, store.ResultDenied, "")
			s.renderError(w, r, http.StatusForbidden, "Das Formular ist abgelaufen. Bitte die Seite neu laden.")
			return
		}
		next.ServeHTTP(w, r)
	})
}

// csrfPasst vergleicht einen Token mit dem der Sitzung.
//
// Ausgelagert, weil ein Endpunkt den Token nicht aus einem geparsten Formular
// nehmen kann: Der Upload liest den Körper als Strom, und r.PostFormValue würde
// ihn vorher vollständig in Speicher und Temp-Dateien ziehen. Dort wird der
// Token aus dem ersten Multipart-Teil oder aus einer Kopfzeile geholt und
// hierher gegeben. Siehe handlers_files_upload.go.
func (s *Server) csrfPasst(r *http.Request, got string) bool {
	sess, ok := sessionFrom(r.Context())
	if !ok {
		return false
	}
	return subtle.ConstantTimeCompare([]byte(got), []byte(sess.CSRFToken)) == 1
}

func (s *Server) redirectToLogin(w http.ResponseWriter, r *http.Request) {
	// Bei einer abgelaufenen Sitzung im Hintergrund-Request (SSE, fetch) wäre
	// eine Weiterleitung auf HTML sinnlos — dort zählt der Statuscode.
	if r.Header.Get("Accept") == "text/event-stream" {
		http.Error(w, "nicht angemeldet", http.StatusUnauthorized)
		return
	}
	// Dasselbe gilt für die JSON-Schnittstelle, und dort strenger: Ein fetch,
	// das auf eine Anmeldeseite umgeleitet wird, bekommt HTML und meldet einen
	// Parserfehler — die eigentliche Ursache wäre damit verdeckt. Erkannt wird
	// der Fall am Pfad und nicht am Accept-Kopf: Den setzt jede Kundin selbst,
	// den Pfad bestimmt die Anwendung.
	if strings.HasPrefix(r.URL.Path, "/api/") {
		s.apiFehler(w, http.StatusUnauthorized, "nicht angemeldet")
		return
	}
	http.Redirect(w, r, "/login", http.StatusSeeOther)
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}
