package httpd

import (
	"net/http"

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
	page := s.base(r, "Passwort festlegen").with(struct{}{})
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
	if err := auth.CheckPasswordPolicy(user.Username, next); err != nil {
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
