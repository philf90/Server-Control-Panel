package httpd

import (
	"errors"
	"net/http"
	"strconv"
	"strings"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/store"
)

func (s *Server) handleDashboard(w http.ResponseWriter, r *http.Request) {
	snap, ok := s.ring.Last()
	if !ok {
		// Kurz nach dem Start liegt noch nichts im Ringpuffer; der Live-Kanal
		// füllt die Seite binnen zwei Sekunden.
		snap.UptimeText = "wird ermittelt"
	}
	s.renderPage(w, r, http.StatusOK, "dashboard",
		s.base(r, "Übersicht", "dashboard").with(dashboardPage{Snapshot: snap, HasData: ok}))
}

func (s *Server) handleAudit(w http.ResponseWriter, r *http.Request) {
	limit := 100
	if raw := r.URL.Query().Get("limit"); raw != "" {
		if n, err := strconv.Atoi(raw); err == nil {
			limit = n
		}
	}
	entries, err := s.db.ListAudit(r.Context(), limit)
	if err != nil {
		s.log.Error("audit lesen", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "Das Audit-Log konnte nicht gelesen werden.")
		return
	}
	s.renderPage(w, r, http.StatusOK, "audit",
		s.base(r, "Audit-Log", "audit").with(auditPage{Entries: entries}))
}

func (s *Server) handleAccount(w http.ResponseWriter, r *http.Request) {
	s.renderAccount(w, r, http.StatusOK, "", "", nil)
}

func (s *Server) renderAccount(w http.ResponseWriter, r *http.Request, status int, flash, errMsg string, newCodes []string) {
	user, _ := userFrom(r.Context())
	left, err := s.db.CountUnusedRecoveryCodes(r.Context(), user.ID)
	if err != nil {
		s.log.Warn("wiederherstellungscodes zählen", "err", err)
	}

	page := s.base(r, "Konto", "account").with(accountPage{
		RecoveryCodesLeft: left,
		NewCodes:          newCodes,
	})
	if flash != "" {
		page = page.withFlash(flash)
	}
	if errMsg != "" {
		page = page.withError(errMsg)
	}
	s.renderPage(w, r, status, "account", page)
}

func (s *Server) handlePasswordChange(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	ctx := r.Context()

	current := r.PostFormValue("current_password")
	next := r.PostFormValue("new_password")
	confirm := r.PostFormValue("new_password_confirm")

	ok, err := auth.VerifyPassword(current, user.PasswordHash)
	if err != nil {
		s.log.Error("passwort prüfen", "err", err)
	}
	if !ok {
		s.audit(r, "password.change", user.Username, store.ResultDenied, "aktuelles Passwort falsch")
		s.renderAccount(w, r, http.StatusBadRequest, "", "Das aktuelle Passwort stimmt nicht.", nil)
		return
	}
	if next != confirm {
		s.renderAccount(w, r, http.StatusBadRequest, "", "Die beiden neuen Passwörter stimmen nicht überein.", nil)
		return
	}
	if err := auth.CheckPasswordPolicy(next); err != nil {
		s.renderAccount(w, r, http.StatusBadRequest, "", err.Error(), nil)
		return
	}

	hash, err := auth.HashPassword(next)
	if err != nil {
		s.log.Error("passwort hashen", "err", err)
		s.renderAccount(w, r, http.StatusInternalServerError, "", "Das Passwort konnte nicht gespeichert werden.", nil)
		return
	}
	if err := s.db.SetPassword(ctx, user.ID, hash); err != nil {
		s.log.Error("passwort speichern", "err", err)
		s.renderAccount(w, r, http.StatusInternalServerError, "", "Das Passwort konnte nicht gespeichert werden.", nil)
		return
	}

	// Alle anderen Sitzungen beenden: Wer das Passwort ändert, will
	// üblicherweise genau das erreichen. Die eigene Sitzung wird danach neu
	// aufgebaut.
	if err := s.db.DeleteUserSessions(ctx, user.ID); err != nil {
		s.log.Warn("sitzungen beenden", "err", err)
	}
	s.audit(r, "password.change", user.Username, store.ResultOK, "alle Sitzungen beendet")

	if err := s.startSession(w, r, user); err != nil {
		s.log.Error("sitzung erneuern", "err", err)
		http.Redirect(w, r, "/login", http.StatusSeeOther)
		return
	}
	s.renderAccount(w, r, http.StatusOK, "Das Passwort wurde geändert. Alle anderen Sitzungen sind beendet.", "", nil)
}

func (s *Server) handleRecoveryCodes(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	codes, hashes, err := auth.NewRecoveryCodes()
	if err != nil {
		s.log.Error("wiederherstellungscodes", "err", err)
		s.renderAccount(w, r, http.StatusInternalServerError, "", "Die Codes konnten nicht erzeugt werden.", nil)
		return
	}
	if err := s.db.ReplaceRecoveryCodes(r.Context(), user.ID, hashes); err != nil {
		s.log.Error("wiederherstellungscodes speichern", "err", err)
		s.renderAccount(w, r, http.StatusInternalServerError, "", "Die Codes konnten nicht gespeichert werden.", nil)
		return
	}
	s.audit(r, "recovery_codes.regenerated", user.Username, store.ResultOK, "")

	s.renderAccount(w, r, http.StatusOK,
		"Neue Codes erzeugt. Die alten gelten nicht mehr.", "", codes)
}

// ------------------------------------------------------ Benutzerverwaltung ---

func (s *Server) handleUsers(w http.ResponseWriter, r *http.Request) {
	s.renderUsers(w, r, http.StatusOK, "", "")
}

func (s *Server) renderUsers(w http.ResponseWriter, r *http.Request, status int, flash, errMsg string) {
	users, err := s.db.ListUsers(r.Context())
	if err != nil {
		s.log.Error("benutzer lesen", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "Die Benutzerliste konnte nicht gelesen werden.")
		return
	}
	page := s.base(r, "Benutzer", "users").with(usersPage{Users: users})
	if flash != "" {
		page = page.withFlash(flash)
	}
	if errMsg != "" {
		page = page.withError(errMsg)
	}
	s.renderPage(w, r, status, "users", page)
}

func (s *Server) handleUserCreate(w http.ResponseWriter, r *http.Request) {
	username := strings.TrimSpace(r.PostFormValue("username"))
	password := r.PostFormValue("password")
	role := r.PostFormValue("role")

	if !validUsername(username) {
		s.renderUsers(w, r, http.StatusBadRequest, "",
			"Der Anmeldename darf 3–32 Zeichen lang sein und nur Buchstaben, Ziffern, Punkt, Bindestrich und Unterstrich enthalten.")
		return
	}
	if !store.ValidRole(role) {
		s.renderUsers(w, r, http.StatusBadRequest, "", "Unbekannte Rolle.")
		return
	}
	if err := auth.CheckPasswordPolicy(password); err != nil {
		s.renderUsers(w, r, http.StatusBadRequest, "", err.Error())
		return
	}

	hash, err := auth.HashPassword(password)
	if err != nil {
		s.log.Error("passwort hashen", "err", err)
		s.renderUsers(w, r, http.StatusInternalServerError, "", "Das Konto konnte nicht angelegt werden.")
		return
	}
	secret, err := auth.GenerateTOTPSecret()
	if err != nil {
		s.log.Error("totp-geheimnis", "err", err)
		s.renderUsers(w, r, http.StatusInternalServerError, "", "Das Konto konnte nicht angelegt werden.")
		return
	}

	// Der zweite Faktor wird beim ersten Anmelden des neuen Kontos
	// eingerichtet — requireAuth lässt vorher nichts anderes zu.
	_, err = s.db.CreateUser(r.Context(), store.User{
		Username: username, PasswordHash: hash, Role: role,
		TOTPSecret: secret, TOTPConfirmed: false,
	})
	if err != nil {
		s.audit(r, "user.create", username, store.ResultError, err.Error())
		s.renderUsers(w, r, http.StatusBadRequest, "",
			"Das Konto konnte nicht angelegt werden — vermutlich ist der Name bereits vergeben.")
		return
	}
	s.audit(r, "user.create", username, store.ResultOK, "Rolle "+role)
	s.renderUsers(w, r, http.StatusOK,
		"Konto "+username+" angelegt. Beim ersten Anmelden wird der zweite Faktor eingerichtet.", "")
}

func (s *Server) handleUserDisable(w http.ResponseWriter, r *http.Request) {
	target, ok := s.targetUser(w, r)
	if !ok {
		return
	}
	actor, _ := userFrom(r.Context())
	if target.ID == actor.ID {
		s.renderUsers(w, r, http.StatusBadRequest, "", "Das eigene Konto lässt sich nicht sperren.")
		return
	}

	disable := r.PostFormValue("disabled") == "1"
	if err := s.db.SetDisabled(r.Context(), target.ID, disable); err != nil {
		s.log.Error("konto sperren", "err", err)
		s.renderUsers(w, r, http.StatusInternalServerError, "", "Die Änderung konnte nicht gespeichert werden.")
		return
	}
	if disable {
		// Ein gesperrtes Konto darf keine laufende Sitzung behalten.
		if err := s.db.DeleteUserSessions(r.Context(), target.ID); err != nil {
			s.log.Warn("sitzungen beenden", "err", err)
		}
	}

	action, flash := "user.enable", "Konto "+target.Username+" ist wieder freigegeben."
	if disable {
		action, flash = "user.disable", "Konto "+target.Username+" ist gesperrt."
	}
	s.audit(r, action, target.Username, store.ResultOK, "")
	s.renderUsers(w, r, http.StatusOK, flash, "")
}

func (s *Server) handleUserDelete(w http.ResponseWriter, r *http.Request) {
	target, ok := s.targetUser(w, r)
	if !ok {
		return
	}
	actor, _ := userFrom(r.Context())
	if target.ID == actor.ID {
		s.renderUsers(w, r, http.StatusBadRequest, "", "Das eigene Konto lässt sich nicht löschen.")
		return
	}

	// Das letzte Owner-Konto muss bestehen bleiben, sonst sperrt sich die
	// Installation dauerhaft aus der Benutzerverwaltung aus.
	if target.Role == store.RoleOwner {
		users, err := s.db.ListUsers(r.Context())
		if err == nil {
			owners := 0
			for _, u := range users {
				if u.Role == store.RoleOwner {
					owners++
				}
			}
			if owners <= 1 {
				s.renderUsers(w, r, http.StatusBadRequest, "", "Das letzte Owner-Konto lässt sich nicht löschen.")
				return
			}
		}
	}

	if _, err := s.db.SQL().ExecContext(r.Context(), `DELETE FROM users WHERE id = ?`, target.ID); err != nil {
		s.log.Error("konto löschen", "err", err)
		s.renderUsers(w, r, http.StatusInternalServerError, "", "Das Konto konnte nicht gelöscht werden.")
		return
	}
	s.audit(r, "user.delete", target.Username, store.ResultOK, "")
	s.renderUsers(w, r, http.StatusOK, "Konto "+target.Username+" gelöscht.", "")
}

func (s *Server) targetUser(w http.ResponseWriter, r *http.Request) (store.User, bool) {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil {
		s.renderError(w, r, http.StatusBadRequest, "Ungültige Kennung.")
		return store.User{}, false
	}
	user, err := s.db.UserByID(r.Context(), id)
	if err != nil {
		if errors.Is(err, store.ErrNotFound) {
			s.renderError(w, r, http.StatusNotFound, "Konto nicht gefunden.")
			return store.User{}, false
		}
		s.log.Error("benutzer laden", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "Das Konto konnte nicht geladen werden.")
		return store.User{}, false
	}
	return user, true
}
