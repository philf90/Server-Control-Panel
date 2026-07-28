package httpd

import (
	"errors"
	"net/http"
	"strconv"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/store"
)

// Zurücksetzen eines fremden Zugangs — und der Wechselzwang, der danach greift.
//
// Bis hierher gab es für ein vergessenes Passwort oder ein verlorenes Telefon
// genau einen Weg: "asylum reset-password" auf der Kommandozeile des Servers.
// Das ist ein verlässlicher Anker, aber ein schlechter Alltag. Wer einen Zugang
// für eine zweite Person eingerichtet hat, muss für deren neues Telefon nicht
// über SSH gehen.
//
// Drei Dinge halten den Weg eng:
//
//   - Nur die Owner-Rolle, und der Owner muss sein **eigenes** Passwort
//     mitgeben. Ein übernommenes Owner-Cookie allein soll keine fremden Konten
//     übernehmen können — dieselbe Rückfrage wie beim Wechsel des zweiten
//     Faktors.
//   - Das eigene Konto läuft nicht über diesen Weg. Dafür gibt es die
//     Kontoseite; ein Owner, der sich selbst zurücksetzt, hätte nichts gewonnen.
//   - Das vergebene Passwort ist ein Einmalpasswort. Der Owner kennt es für
//     einen Moment, das Konto muss es bei der nächsten Anmeldung ersetzen.

// resetActor prüft, was allen drei Aktionen gemeinsam vorausgeht, und liefert
// das Zielkonto.
func (s *Server) resetActor(w http.ResponseWriter, r *http.Request) (store.User, bool) {
	id, err := strconv.ParseInt(r.PostFormValue("target"), 10, 64)
	if err != nil {
		s.renderUsers(w, r, http.StatusBadRequest, "", "Es wurde kein Konto ausgewählt.")
		return store.User{}, false
	}
	target, err := s.db.UserByID(r.Context(), id)
	if err != nil {
		if errors.Is(err, store.ErrNotFound) {
			s.renderUsers(w, r, http.StatusNotFound, "", "Konto nicht gefunden.")
			return store.User{}, false
		}
		s.log.Error("benutzer laden", "err", err)
		s.renderUsers(w, r, http.StatusInternalServerError, "", "Das Konto konnte nicht geladen werden.")
		return store.User{}, false
	}
	actor, _ := userFrom(r.Context())
	if target.ID == actor.ID {
		s.renderUsers(w, r, http.StatusBadRequest, "",
			"Das eigene Konto lässt sich hier nicht zurücksetzen — dafür gibt es die Kontoseite.")
		return store.User{}, false
	}

	valid, verr := auth.VerifyPassword(r.PostFormValue("owner_password"), actor.PasswordHash)
	if verr != nil {
		s.log.Error("passwort prüfen", "err", verr)
	}
	if !valid {
		s.audit(r, "user.reset", target.Username, store.ResultDenied, "eigenes Passwort falsch")
		s.renderUsers(w, r, http.StatusBadRequest, "", "Das eigene Passwort stimmt nicht.")
		return store.User{}, false
	}
	return target, true
}

// handleUserResetPassword vergibt ein Einmalpasswort und zeigt es genau einmal
// an — wie die Wiederherstellungscodes. Es gibt keinen Mailversand im Panel und
// soll auch keinen brauchen: Auf welchem Weg der Owner das Passwort weitergibt,
// entscheidet er.
func (s *Server) handleUserResetPassword(w http.ResponseWriter, r *http.Request) {
	target, ok := s.resetActor(w, r)
	if !ok {
		return
	}
	ctx := r.Context()

	password, err := auth.NewTemporaryPassword()
	if err != nil {
		s.log.Error("einmalpasswort erzeugen", "err", err)
		s.renderUsers(w, r, http.StatusInternalServerError, "", "Das Passwort konnte nicht erzeugt werden.")
		return
	}
	hash, err := auth.HashPassword(password)
	if err != nil {
		s.log.Error("passwort hashen", "err", err)
		s.renderUsers(w, r, http.StatusInternalServerError, "", "Das Passwort konnte nicht gespeichert werden.")
		return
	}
	if err := s.db.SetTemporaryPassword(ctx, target.ID, hash); err != nil {
		s.log.Error("einmalpasswort setzen", "err", err)
		s.renderUsers(w, r, http.StatusInternalServerError, "", "Das Passwort konnte nicht gespeichert werden.")
		return
	}

	// Laufende Sitzungen des Zielkontos beenden: Eine Zurücksetzung ist die
	// Reaktion auf etwas, das schiefgegangen ist. Was noch angemeldet war, soll
	// es danach nicht mehr sein.
	if err := s.db.DeleteUserSessions(ctx, target.ID); err != nil {
		s.log.Warn("sitzungen beenden", "err", err)
	}
	// Eine Sperre aufheben: Wer ein neues Passwort bekommt, soll sich damit auch
	// anmelden können. Dasselbe tut der Rettungsweg auf der Kommandozeile.
	if err := s.db.SetDisabled(ctx, target.ID, false); err != nil {
		s.log.Warn("sperre aufheben", "err", err)
	}
	s.audit(r, "user.reset_password", target.Username, store.ResultOK,
		"Einmalpasswort vergeben, Sitzungen beendet")

	s.renderPage(w, r, http.StatusOK, "reset",
		s.base(r, "Zugang zurückgesetzt", "users").with(resetPage{
			Username: target.Username,
			Password: password,
		}))
}

// handleUserReset2FA macht den zweiten Faktor unbestätigt und leert die
// Codeliste. Beim nächsten Anmelden führt der Weg durch die Einrichtung —
// genau das, was "asylum reset-password" ohne --keep-2fa tut.
func (s *Server) handleUserReset2FA(w http.ResponseWriter, r *http.Request) {
	target, ok := s.resetActor(w, r)
	if !ok {
		return
	}
	ctx := r.Context()

	secret, err := auth.GenerateTOTPSecret()
	if err != nil {
		s.log.Error("totp-geheimnis erzeugen", "err", err)
		s.renderUsers(w, r, http.StatusInternalServerError, "", "Der zweite Faktor konnte nicht vorbereitet werden.")
		return
	}
	if err := s.db.SetTOTP(ctx, target.ID, secret, false); err != nil {
		s.log.Error("totp zurücksetzen", "err", err)
		s.renderUsers(w, r, http.StatusInternalServerError, "", "Der zweite Faktor konnte nicht zurückgesetzt werden.")
		return
	}
	// Die alten Wiederherstellungscodes gehörten zum alten Geheimnis. Blieben
	// sie liegen, wären sie ein zweiter Faktor, den niemand mehr überblickt.
	if err := s.db.ReplaceRecoveryCodes(ctx, target.ID, nil); err != nil {
		s.log.Warn("wiederherstellungscodes leeren", "err", err)
	}
	if err := s.db.DeleteUserSessions(ctx, target.ID); err != nil {
		s.log.Warn("sitzungen beenden", "err", err)
	}
	s.audit(r, "user.reset_2fa", target.Username, store.ResultOK, "Codeliste geleert, Sitzungen beendet")

	s.renderUsers(w, r, http.StatusOK,
		"Der zweite Faktor von "+target.Username+" ist zurückgesetzt. "+
			"Beim nächsten Anmelden wird er neu eingerichtet; das Passwort bleibt unverändert.", "")
}

// handleUserResetPasskeys entfernt alle Passkeys eines Kontos — für verlorene
// Geräte. Entspricht "asylum passkey remove --all".
func (s *Server) handleUserResetPasskeys(w http.ResponseWriter, r *http.Request) {
	target, ok := s.resetActor(w, r)
	if !ok {
		return
	}

	n, err := s.db.DeleteWebAuthnCredentialsByUser(r.Context(), target.ID)
	if err != nil {
		s.log.Error("passkeys entfernen", "err", err)
		s.renderUsers(w, r, http.StatusInternalServerError, "", "Die Passkeys konnten nicht entfernt werden.")
		return
	}
	s.audit(r, "user.reset_passkeys", target.Username, store.ResultOK, "")

	msg := "Für " + target.Username + " war kein Passkey hinterlegt."
	if n == 1 {
		msg = "Der Passkey von " + target.Username + " wurde entfernt."
	} else if n > 1 {
		msg = "Alle Passkeys von " + target.Username + " wurden entfernt."
	}
	s.renderUsers(w, r, http.StatusOK, msg, "")
}

// ------------------------------------------------- Erzwungener Wechsel ---

// handlePasswordChangeForcedForm ist die Seite, auf der ein Konto mit
// Einmalpasswort landet. Sie ist die einzige, die es erreicht, solange das
// Passwort nicht ersetzt ist.
func (s *Server) handlePasswordChangeForcedForm(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	if !user.MustChangePassword {
		// Nichts zu tun — etwa nach einem Neuladen der Seite.
		http.Redirect(w, r, "/", http.StatusSeeOther)
		return
	}
	s.renderForcedChange(w, r, http.StatusOK, "")
}

func (s *Server) renderForcedChange(w http.ResponseWriter, r *http.Request, status int, errMsg string) {
	page := s.base(r, "Passwort festlegen", "").with(struct{}{})
	if errMsg != "" {
		page = page.withError(errMsg)
	}
	s.renderPage(w, r, status, "password-change", page)
}

// handlePasswordChangeForced nimmt das neue Passwort an.
//
// Das Einmalpasswort wird noch einmal verlangt. Das ist keine Schikane: Jede
// Passwortänderung im Panel fragt nach dem aktuellen, und wer hier steht, hat
// es gerade eingegeben.
func (s *Server) handlePasswordChangeForced(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	if !user.MustChangePassword {
		http.Redirect(w, r, "/", http.StatusSeeOther)
		return
	}
	ctx := r.Context()

	current := r.PostFormValue("current_password")
	next := r.PostFormValue("new_password")
	confirm := r.PostFormValue("new_password_confirm")

	ok, err := auth.VerifyPassword(current, user.PasswordHash)
	if err != nil {
		s.log.Error("passwort prüfen", "err", err)
	}
	if !ok {
		s.audit(r, "password.change", user.Username, store.ResultDenied, "Einmalpasswort falsch")
		s.renderForcedChange(w, r, http.StatusBadRequest, "Das vergebene Passwort stimmt nicht.")
		return
	}
	if next != confirm {
		s.renderForcedChange(w, r, http.StatusBadRequest, "Die beiden neuen Passwörter stimmen nicht überein.")
		return
	}
	if next == current {
		s.renderForcedChange(w, r, http.StatusBadRequest,
			"Das neue Passwort muss sich vom vergebenen unterscheiden.")
		return
	}
	if err := auth.CheckPasswordPolicy(next); err != nil {
		s.renderForcedChange(w, r, http.StatusBadRequest, err.Error())
		return
	}

	hash, err := auth.HashPassword(next)
	if err != nil {
		s.log.Error("passwort hashen", "err", err)
		s.renderForcedChange(w, r, http.StatusInternalServerError, "Das Passwort konnte nicht gespeichert werden.")
		return
	}
	// SetPassword löscht den Wechselzwang mit.
	if err := s.db.SetPassword(ctx, user.ID, hash); err != nil {
		s.log.Error("passwort speichern", "err", err)
		s.renderForcedChange(w, r, http.StatusInternalServerError, "Das Passwort konnte nicht gespeichert werden.")
		return
	}
	if err := s.db.DeleteUserSessions(ctx, user.ID); err != nil {
		s.log.Warn("sitzungen beenden", "err", err)
	}
	s.audit(r, "password.change", user.Username, store.ResultOK,
		"Einmalpasswort ersetzt, alle Sitzungen beendet")

	if err := s.startSession(w, r, user); err != nil {
		s.log.Error("sitzung erneuern", "err", err)
		http.Redirect(w, r, "/login", http.StatusSeeOther)
		return
	}
	// Der Kontext trägt noch den alten Stand; für die Anzeige zählt nur, dass
	// der Zwang weg ist.
	http.Redirect(w, r, "/", http.StatusSeeOther)
}
