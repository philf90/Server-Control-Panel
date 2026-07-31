package httpd

import (
	"encoding/json"
	"errors"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/passkeys"
	"github.com/philf90/asylum/internal/store"
)

// Vergessenes Passwort — Selbstbedienung über den Passkey.
//
// Vorher gab es dafür nichts. Ein Wiederherstellungscode hilft nicht: Er wird
// nur eingelöst, wenn das Passwort stimmt (siehe checkSecondFactor, und das aus
// gutem Grund). Blieb allein "asylum reset-password" auf der Kommandozeile —
// was bei einer Installation mit einem einzigen Konto bedeutet: Wer sein
// Passwort vergisst, braucht SSH.
//
// Der Passkey ist der bessere Nachweis, den es dafür gibt. Er ist an den
// Ursprung gebunden, also nicht abphishbar, und die Zeremonie verlangt hier
// zwingend die Prüfung am Gerät — PIN, Fingerabdruck oder Gesicht. Damit besteht
// der Nachweis aus Besitz **und** einem zweiten Teil und steht dem Paar aus
// Passwort und Einmalcode nicht nach.
//
// Bewusst kein Konto in der Anfrage: Die Zeremonie läuft über auffindbare
// Passkeys (siehe passkeys.BeginDiscoverableLogin). Der Browser bietet an, was
// er für diese Domain hat; der Server nennt niemanden. Damit lässt sich über
// diesen Weg auch nicht erraten, welche Anmeldenamen es gibt.
//
// Kein Mailversand: Das Panel verschickt keine Post und soll dafür keine
// brauchen. Ein Rettungsweg über ein Postfach würde das Postfach zum
// Hauptschlüssel des Servers machen und stillschweigend versagen, wenn der
// Versand auf einer frischen Maschine im Spam landet.

const (
	// resetCookie trägt das eingelöste Ticket zwischen dem bestätigten Passkey
	// und dem gesetzten Passwort.
	resetCookie = "asylum_reset"
	// resetTicketTTL ist die Frist, in der das neue Passwort gesetzt sein muss.
	resetTicketTTL = 10 * time.Minute
)

// resetTickets hält die Nachweise, die zwischen Passkey und neuem Passwort
// stehen.
//
// Serverseitig und einmalig einlösbar: Das Cookie allein ist damit kein
// Berechtigungsnachweis, den man aufbewahren könnte. Neustart des Dienstes
// verwirft alle offenen Vorgänge — richtig so, denn ein Nachweis über einen
// Neustart hinweg gültig zu halten wäre mehr Zusage als nötig.
type resetTickets struct {
	mu      sync.Mutex
	byToken map[string]resetTicket
	// now ist auslagerbar, damit Tests das Ablaufen ohne echtes Warten prüfen.
	now func() time.Time
}

type resetTicket struct {
	userID   int64
	username string
	expires  time.Time
}

func newResetTickets() *resetTickets {
	return &resetTickets{byToken: make(map[string]resetTicket), now: time.Now}
}

func (t *resetTickets) put(userID int64, username string) (string, error) {
	token, err := auth.NewToken()
	if err != nil {
		return "", err
	}
	t.mu.Lock()
	defer t.mu.Unlock()
	t.gcLocked()
	t.byToken[token] = resetTicket{userID: userID, username: username, expires: t.now().Add(resetTicketTTL)}
	return token, nil
}

// peek liest ein Ticket, ohne es zu verbrauchen — für die Anzeige des
// Formulars.
func (t *resetTickets) peek(token string) (resetTicket, bool) {
	t.mu.Lock()
	defer t.mu.Unlock()
	tk, ok := t.byToken[token]
	if !ok || tk.expires.Before(t.now()) {
		return resetTicket{}, false
	}
	return tk, true
}

// take löst ein Ticket ein. Jedes gilt genau einmal: Ein zweites Passwort zu
// setzen soll einen neuen Nachweis am Gerät kosten.
func (t *resetTickets) take(token string) (resetTicket, bool) {
	t.mu.Lock()
	defer t.mu.Unlock()
	tk, ok := t.byToken[token]
	if !ok {
		return resetTicket{}, false
	}
	delete(t.byToken, token)
	if tk.expires.Before(t.now()) {
		return resetTicket{}, false
	}
	return tk, true
}

func (t *resetTickets) gcLocked() {
	now := t.now()
	for k, tk := range t.byToken {
		if tk.expires.Before(now) {
			delete(t.byToken, k)
		}
	}
}

// ------------------------------------------------------------- Handler ---

// handleForgotForm zeigt den Einstieg. Ohne eingeschaltete Passkeys nennt die
// Seite den Weg über die Kommandozeile — das ist der Anker, der immer bleibt.
func (s *Server) handleForgotForm(w http.ResponseWriter, r *http.Request) {
	if _, ok := userFrom(r.Context()); ok {
		// Auf die KONTOSEITE der neuen Oberfläche. Wer über diesen Weg
		// hereinkommt, hat gerade sein Passwort neu gesetzt und soll dort landen,
		// wo der zweite Faktor und die Passkeys stehen — nicht auf der
		// eingefrorenen alten Fläche.
		http.Redirect(w, r, "/konto", http.StatusSeeOther)
		return
	}
	s.renderPage(w, r, http.StatusOK, "forgot",
		s.base(r, "Passwort vergessen").with(forgotPage{WebAuthnOn: s.passkeys != nil}))
}

// handleForgotBegin liefert die Optionen für die Zeremonie. Kein Konto, kein
// Passwort — der Schutz ist die Ratenbegrenzung und die Bindung der Assertion an
// den Ursprung.
func (s *Server) handleForgotBegin(w http.ResponseWriter, r *http.Request) {
	if s.passkeys == nil {
		s.writeJSONError(w, http.StatusNotFound, "Passkeys sind nicht eingeschaltet.")
		return
	}
	opts, token, err := s.passkeys.BeginDiscoverableLogin()
	if err != nil {
		s.log.Error("passkey-zurücksetzung beginnen", "err", err)
		s.writeJSONError(w, http.StatusInternalServerError, "Der Vorgang ließ sich nicht beginnen.")
		return
	}
	s.writeJSON(w, http.StatusOK, map[string]any{"token": token, "publicKey": opts.Response})
}

// handleForgotFinish prüft die Assertion und stellt bei Erfolg das Ticket aus.
func (s *Server) handleForgotFinish(w http.ResponseWriter, r *http.Request) {
	if s.passkeys == nil {
		s.writeJSONError(w, http.StatusNotFound, "Passkeys sind nicht eingeschaltet.")
		return
	}
	if err := r.ParseForm(); err != nil {
		s.writeJSONError(w, http.StatusBadRequest, "Formulardaten unlesbar.")
		return
	}
	ipKey := "ip:" + clientIP(r)

	// Das Konto steht erst in der Antwort des Authenticators. Bis dahin ist
	// nichts bekannt, das man protokollieren könnte.
	var found store.User
	_, cred, err := s.passkeys.FinishDiscoverableLogin(
		r.PostFormValue("token"),
		strings.NewReader(r.PostFormValue("credential")),
		func(userID int64) (passkeys.User, error) {
			u, err := s.db.UserByID(r.Context(), userID)
			if err != nil {
				return passkeys.User{}, err
			}
			if u.Disabled {
				// Ein gesperrtes Konto soll sich nicht selbst befreien.
				return passkeys.User{}, errors.New("Konto gesperrt")
			}
			found = u
			pu, _, err := s.passkeyUser(r, u)
			if err != nil {
				return passkeys.User{}, err
			}
			return pu, nil
		})
	if err != nil {
		s.limiter.Fail(ipKey)
		detail := "Passkey abgelehnt"
		if errors.Is(err, passkeys.ErrNoUserVerification) {
			detail = "Passkey ohne Prüfung am Gerät"
		}
		s.audit(r, "password.reset", found.Username, store.ResultDenied, detail)
		s.log.Warn("passkey-zurücksetzung abgelehnt", "err", err)
		s.writeJSONError(w, http.StatusUnauthorized,
			"Der Passkey wurde nicht angenommen. Er muss auf dem Gerät bestätigt werden — mit PIN, Fingerabdruck oder Gesicht.")
		return
	}
	// Sign-Count fortschreiben wie bei einer gewöhnlichen Anmeldung.
	if data, err := json.Marshal(cred); err == nil {
		if err := s.db.UpdateWebAuthnCredentialUse(r.Context(), passkeys.CredentialID(*cred), data, time.Now()); err != nil {
			s.log.Warn("passkey fortschreiben", "err", err)
		}
	}

	token, err := s.resets.put(found.ID, found.Username)
	if err != nil {
		s.log.Error("ticket erzeugen", "err", err)
		s.writeJSONError(w, http.StatusInternalServerError, "Der Vorgang ließ sich nicht abschließen.")
		return
	}
	http.SetCookie(w, &http.Cookie{
		Name:     resetCookie,
		Value:    token,
		Path:     "/",
		HttpOnly: true,
		Secure:   true,
		// SameSite=Strict ist hier der CSRF-Schutz: Es gibt noch keine Sitzung
		// und damit kein Sitzungs-Token, das man doppelt einreichen könnte. Das
		// Cookie geht bei keiner fremden Verlinkung mit, also kann keine andere
		// Seite den Vorgang abschließen.
		SameSite: http.SameSiteStrictMode,
		MaxAge:   int(resetTicketTTL.Seconds()),
	})
	s.limiter.Reset(ipKey)
	s.auditAs(found.Username, r, "password.reset", found.Username, store.ResultOK,
		"Passkey mit Prüfung am Gerät bestätigt")

	s.writeJSON(w, http.StatusOK, map[string]any{"ok": true, "redirect": "/login/forgot/new"})
}

// handleForgotNewForm zeigt das Formular für das neue Passwort.
func (s *Server) handleForgotNewForm(w http.ResponseWriter, r *http.Request) {
	ticket, ok := s.forgotTicket(r)
	if !ok {
		s.clearResetCookie(w)
		s.renderPage(w, r, http.StatusForbidden, "forgot",
			s.base(r, "Passwort vergessen").
				withError("Der Vorgang ist abgelaufen. Bitte erneut beginnen.").
				with(forgotPage{WebAuthnOn: s.passkeys != nil}))
		return
	}
	s.renderForgotNew(w, r, http.StatusOK, ticket.username, "")
}

func (s *Server) renderForgotNew(w http.ResponseWriter, r *http.Request, status int, username, errMsg string) {
	page := s.base(r, "Neues Passwort").with(forgotNewPage{Username: username})
	if errMsg != "" {
		page = page.withError(errMsg)
	}
	s.renderPage(w, r, status, "forgot-new", page)
}

// handleForgotNew setzt das neue Passwort. Erst hier wird das Ticket verbraucht:
// Ein Tippfehler bei der Wiederholung soll nicht bedeuten, dass der Passkey
// erneut vorgezeigt werden muss.
func (s *Server) handleForgotNew(w http.ResponseWriter, r *http.Request) {
	ticket, ok := s.forgotTicket(r)
	if !ok {
		s.clearResetCookie(w)
		s.renderPage(w, r, http.StatusForbidden, "forgot",
			s.base(r, "Passwort vergessen").
				withError("Der Vorgang ist abgelaufen. Bitte erneut beginnen.").
				with(forgotPage{WebAuthnOn: s.passkeys != nil}))
		return
	}
	if err := r.ParseForm(); err != nil {
		s.renderError(w, r, http.StatusBadRequest, "Formulardaten unlesbar.")
		return
	}

	next := r.PostFormValue("new_password")
	confirm := r.PostFormValue("new_password_confirm")
	if next != confirm {
		s.renderForgotNew(w, r, http.StatusBadRequest, ticket.username,
			"Die beiden Passwörter stimmen nicht überein.")
		return
	}
	if err := auth.CheckPasswordPolicy(ticket.username, next); err != nil {
		s.renderForgotNew(w, r, http.StatusBadRequest, ticket.username, err.Error())
		return
	}

	// Jetzt erst einlösen — und nur, wenn das Einlösen gelingt. Zwei
	// gleichzeitige Anfragen mit demselben Ticket lässt der Speicher nicht
	// beide durch.
	c, err := r.Cookie(resetCookie)
	if err != nil {
		s.clearResetCookie(w)
		s.renderError(w, r, http.StatusForbidden, "Der Vorgang ist abgelaufen.")
		return
	}
	if _, ok := s.resets.take(c.Value); !ok {
		s.clearResetCookie(w)
		s.renderError(w, r, http.StatusForbidden, "Der Vorgang ist abgelaufen. Bitte erneut beginnen.")
		return
	}
	s.clearResetCookie(w)

	ctx := r.Context()
	hash, err := auth.HashPassword(next)
	if err != nil {
		s.log.Error("passwort hashen", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "Das Passwort konnte nicht gespeichert werden.")
		return
	}
	if err := s.db.SetPassword(ctx, ticket.userID, hash); err != nil {
		s.log.Error("passwort speichern", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "Das Passwort konnte nicht gespeichert werden.")
		return
	}
	// Alle Sitzungen beenden: Wer sein Passwort neu setzt, weil er es vergessen
	// hat, weiß nicht, was noch offen ist.
	if err := s.db.DeleteUserSessions(ctx, ticket.userID); err != nil {
		s.log.Warn("sitzungen beenden", "err", err)
	}
	s.auditAs(ticket.username, r, "password.reset", ticket.username, store.ResultOK,
		"neues Passwort gesetzt, alle Sitzungen beendet")

	s.renderPage(w, r, http.StatusOK, "login",
		s.base(r, "Anmeldung").
			withFlash("Das Passwort wurde geändert. Alle Sitzungen sind beendet — bitte neu anmelden.").
			with(loginPage{Username: ticket.username, WebAuthnOn: s.passkeys != nil}))
}

// forgotTicket liest das Ticket aus dem Cookie, ohne es zu verbrauchen.
func (s *Server) forgotTicket(r *http.Request) (resetTicket, bool) {
	c, err := r.Cookie(resetCookie)
	if err != nil || c.Value == "" {
		return resetTicket{}, false
	}
	return s.resets.peek(c.Value)
}

func (s *Server) clearResetCookie(w http.ResponseWriter) {
	http.SetCookie(w, &http.Cookie{
		Name:     resetCookie,
		Value:    "",
		Path:     "/",
		HttpOnly: true,
		Secure:   true,
		SameSite: http.SameSiteStrictMode,
		MaxAge:   -1,
	})
}
