package httpd

import (
	"errors"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/store"
	"rsc.io/qr"
)

// Zwei Dinge gehören in die Hand des Kontoinhabers und nicht auf die
// Kommandozeile des Servers: der Wechsel des zweiten Faktors, wenn das Telefon
// getauscht wird, und der Überblick über die eigenen offenen Sitzungen.
//
// Das zweite ist mehr als Bequemlichkeit: Ein entwendetes Sitzungscookie
// hinterlässt sonst keine Spur, die dem Betroffenen auffiele. Erst die Liste
// mit Adresse und letzter Aktivität macht eine übernommene Sitzung sichtbar —
// und die Schaltfläche daneben beendet sie.

// pendingTOTPTTL ist die Frist, in der ein begonnener Wechsel abgeschlossen
// werden muss.
const pendingTOTPTTL = 15 * time.Minute

// pendingSecrets hält angefangene Wechsel des zweiten Faktors.
//
// Das neue Geheimnis darf erst dann in die Datenbank, wenn es bestätigt ist:
// Wer den Vorgang abbricht — weil die App abstürzt oder das Telefon leer ist —
// muss sich weiterhin mit dem alten Faktor anmelden können.
type pendingSecrets struct {
	mu     sync.Mutex
	byUser map[int64]pendingSecret
}

type pendingSecret struct {
	secret  string
	expires time.Time
}

func newPendingSecrets() *pendingSecrets {
	return &pendingSecrets{byUser: make(map[int64]pendingSecret)}
}

func (p *pendingSecrets) put(userID int64, secret string) {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.byUser[userID] = pendingSecret{secret: secret, expires: time.Now().Add(pendingTOTPTTL)}
}

func (p *pendingSecrets) get(userID int64) (string, bool) {
	p.mu.Lock()
	defer p.mu.Unlock()

	entry, ok := p.byUser[userID]
	if !ok {
		return "", false
	}
	if time.Now().After(entry.expires) {
		delete(p.byUser, userID)
		return "", false
	}
	return entry.secret, true
}

func (p *pendingSecrets) drop(userID int64) {
	p.mu.Lock()
	defer p.mu.Unlock()
	delete(p.byUser, userID)
}

// ------------------------------------------------- Zweiter Faktor wechseln ---

// handleTOTPChangeStart beginnt den Wechsel. Das aktuelle Passwort ist Pflicht:
// Ohne diese Rückfrage könnte eine übernommene Sitzung den zweiten Faktor
// austauschen und den rechtmäßigen Inhaber aussperren — also genau die Tür
// zumauern, die er zur Rückeroberung bräuchte.
func (s *Server) handleTOTPChangeStart(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	ok, err := auth.VerifyPassword(r.PostFormValue("current_password"), user.PasswordHash)
	if err != nil {
		s.log.Error("passwort prüfen", "err", err)
	}
	if !ok {
		s.audit(r, "2fa.change", user.Username, store.ResultDenied, "aktuelles Passwort falsch")
		s.renderAccount(w, r, http.StatusBadRequest, "", "Das aktuelle Passwort stimmt nicht.", nil)
		return
	}

	secret, err := auth.GenerateTOTPSecret()
	if err != nil {
		s.log.Error("totp-geheimnis erzeugen", "err", err)
		s.renderAccount(w, r, http.StatusInternalServerError, "", "Der Wechsel ließ sich nicht beginnen.", nil)
		return
	}
	s.pending.put(user.ID, secret)
	s.audit(r, "2fa.change", user.Username, store.ResultOK, "begonnen")

	s.renderTOTPChange(w, r, http.StatusOK, secret, "")
}

func (s *Server) renderTOTPChange(w http.ResponseWriter, r *http.Request, status int, secret, errMsg string) {
	user, _ := userFrom(r.Context())
	page := s.base(r, "Zweiten Faktor wechseln", "account").with(totpPage{
		Secret:          secret,
		SecretFormatted: auth.FormatSecret(secret),
		URI:             auth.TOTPProvisioningURI(secret, user.Username, totpIssuer),
	})
	if errMsg != "" {
		page = page.withError(errMsg)
	}
	s.renderPage(w, r, status, "totp-change", page)
}

// handleTOTPChangeQR liefert den QR-Code zum begonnenen Wechsel.
func (s *Server) handleTOTPChangeQR(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	secret, ok := s.pending.get(user.ID)
	if !ok {
		http.Error(w, "kein begonnener Wechsel", http.StatusForbidden)
		return
	}

	code, err := qr.Encode(auth.TOTPProvisioningURI(secret, user.Username, totpIssuer), qr.M)
	if err != nil {
		s.log.Error("qr-code", "err", err)
		http.Error(w, "QR-Code nicht erzeugbar", http.StatusInternalServerError)
		return
	}
	w.Header().Set("Content-Type", "image/png")
	w.Header().Set("Cache-Control", "no-store")
	_, _ = w.Write(code.PNG())
}

// handleTOTPChangeConfirm schließt den Wechsel ab.
func (s *Server) handleTOTPChangeConfirm(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	ctx := r.Context()

	secret, ok := s.pending.get(user.ID)
	if !ok {
		s.renderAccount(w, r, http.StatusBadRequest, "",
			"Der Wechsel ist abgelaufen. Bitte erneut beginnen.", nil)
		return
	}

	code := strings.TrimSpace(r.PostFormValue("code"))
	if !auth.VerifyTOTP(secret, code, time.Now()) {
		s.audit(r, "2fa.change", user.Username, store.ResultDenied, "Bestätigungscode falsch")
		s.renderTOTPChange(w, r, http.StatusBadRequest, secret,
			"Der Code stimmt nicht. Bitte den aktuellen Code aus der neuen App eingeben.")
		return
	}

	if err := s.db.SetTOTP(ctx, user.ID, secret, true); err != nil {
		s.log.Error("totp wechseln", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "Der zweite Faktor konnte nicht gespeichert werden.")
		return
	}
	s.pending.drop(user.ID)

	// Neue Wiederherstellungscodes: Die alten gehörten zum alten Faktor, und
	// wer sein Telefon wechselt, hat den alten Zettel selten noch griffbereit.
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

	// Andere Sitzungen beenden: Ein Wechsel des zweiten Faktors ist meist eine
	// Reaktion auf ein verlorenes Gerät. Was darauf noch angemeldet war, soll
	// es danach nicht mehr sein.
	var closed int64
	if sess, ok := sessionFrom(ctx); ok {
		if n, err := s.db.DeleteOtherUserSessions(ctx, user.ID, sess.ID); err != nil {
			s.log.Warn("andere sitzungen beenden", "err", err)
		} else {
			closed = n
		}
	}
	s.audit(r, "2fa.change", user.Username, store.ResultOK, "abgeschlossen, andere Sitzungen beendet")
	_ = closed

	s.renderPage(w, r, http.StatusOK, "codes",
		s.base(r, "Wiederherstellungscodes", "account").with(codesPage{Codes: codes, AfterChange: true}))
}

// ------------------------------------------------------- Eigene Sitzungen ---

// sessionView ist eine Sitzung, wie sie auf der Kontoseite erscheint.
type sessionView struct {
	ID         string
	Short      string
	IP         string
	UserAgent  string
	CreatedAt  time.Time
	LastSeenAt time.Time
	ExpiresAt  time.Time
	Current    bool
}

func (s *Server) sessionViews(r *http.Request) []sessionView {
	user, _ := userFrom(r.Context())
	sessions, err := s.db.ListUserSessions(r.Context(), user.ID)
	if err != nil {
		s.log.Warn("sitzungen lesen", "err", err)
		return nil
	}

	currentID := ""
	if sess, ok := sessionFrom(r.Context()); ok {
		currentID = sess.ID
	}

	out := make([]sessionView, 0, len(sessions))
	for _, sess := range sessions {
		out = append(out, sessionView{
			ID: sess.ID,
			// Die Kennung ist bereits ein Hash des Cookies und damit kein
			// Geheimnis; angezeigt wird trotzdem nur ein kurzes Stück, weil
			// niemand 64 Zeichen lesen will.
			Short:      shortID(sess.ID),
			IP:         sess.IP,
			UserAgent:  shortenUserAgent(sess.UserAgent),
			CreatedAt:  sess.CreatedAt,
			LastSeenAt: sess.LastSeenAt,
			ExpiresAt:  sess.ExpiresAt,
			Current:    sess.ID == currentID,
		})
	}
	return out
}

func shortID(id string) string {
	if len(id) > 12 {
		return id[:12]
	}
	return id
}

// shortenUserAgent macht aus der üblichen Bandwurmkennung etwas Lesbares.
// Eine genaue Auswertung wäre Ratearbeit; hier geht es nur darum, zwei
// Sitzungen auseinanderhalten zu können.
func shortenUserAgent(ua string) string {
	if ua == "" {
		return "unbekannt"
	}
	for _, name := range []string{"Firefox", "Edg", "Chrome", "Safari", "curl", "Go-http-client"} {
		if i := strings.Index(ua, name); i >= 0 {
			rest := ua[i:]
			if j := strings.IndexAny(rest, " ;)"); j > 0 {
				rest = rest[:j]
			}
			if name == "Edg" {
				rest = "Edge" + strings.TrimPrefix(rest, "Edg")
			}
			return rest
		}
	}
	if len(ua) > 40 {
		return ua[:40] + "…"
	}
	return ua
}

// handleSessionRevoke beendet eine einzelne Sitzung des angemeldeten Kontos.
func (s *Server) handleSessionRevoke(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	id := r.PostFormValue("session")

	if sess, ok := sessionFrom(r.Context()); ok && sess.ID == id {
		// Die eigene Sitzung zu beenden ist schlicht ein Abmelden — dafür gibt
		// es die vorgesehene Schaltfläche, die auch das Cookie entfernt.
		s.handleLogout(w, r)
		return
	}

	err := s.db.DeleteUserSession(r.Context(), user.ID, id)
	if errors.Is(err, store.ErrNotFound) {
		s.audit(r, "session.revoke", shortID(id), store.ResultDenied, "keine eigene Sitzung")
		s.renderAccount(w, r, http.StatusNotFound, "", "Diese Sitzung gibt es nicht mehr.", nil)
		return
	}
	if err != nil {
		s.log.Error("sitzung beenden", "err", err)
		s.renderAccount(w, r, http.StatusInternalServerError, "", "Die Sitzung ließ sich nicht beenden.", nil)
		return
	}
	s.audit(r, "session.revoke", shortID(id), store.ResultOK, "")
	s.renderAccount(w, r, http.StatusOK, "Die Sitzung wurde beendet.", "", nil)
}

// handleSessionRevokeOthers beendet alle Sitzungen außer der aktuellen.
func (s *Server) handleSessionRevokeOthers(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	sess, ok := sessionFrom(r.Context())
	if !ok {
		s.redirectToLogin(w, r)
		return
	}

	if !s.bestaetigt(w, r, bestaetigung{
		Titel:   "Sitzungen beenden",
		Frage:   "Alle anderen Sitzungen dieses Kontos beenden?",
		Punkte:  []string{"Diese Sitzung bleibt offen. Alle übrigen müssen sich neu anmelden."},
		Knopf:   "andere Sitzungen beenden",
		Abbruch: "/account",
	}) {
		return
	}

	n, err := s.db.DeleteOtherUserSessions(r.Context(), user.ID, sess.ID)
	if err != nil {
		s.log.Error("sitzungen beenden", "err", err)
		s.renderAccount(w, r, http.StatusInternalServerError, "", "Die Sitzungen ließen sich nicht beenden.", nil)
		return
	}
	s.audit(r, "session.revoke", user.Username, store.ResultOK, "alle anderen Sitzungen")

	msg := "Es war keine weitere Sitzung offen."
	if n == 1 {
		msg = "Eine weitere Sitzung wurde beendet."
	} else if n > 1 {
		msg = "Alle anderen Sitzungen wurden beendet."
	}
	s.renderAccount(w, r, http.StatusOK, msg, "", nil)
}
