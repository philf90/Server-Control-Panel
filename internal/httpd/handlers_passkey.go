package httpd

import (
	"encoding/json"
	"net/http"
	"strconv"
	"strings"
	"time"

	wa "github.com/go-webauthn/webauthn/webauthn"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/passkeys"
	"github.com/philf90/asylum/internal/store"
)

// passkeyView ist die Anzeigefassung eines registrierten Passkeys.
type passkeyView struct {
	ID         int64
	Label      string
	CreatedAt  time.Time
	LastUsedAt *time.Time
	// Synced sagt, ob der Schlüssel geräteübergreifend gesichert ist
	// (Cloud-Passkey) oder an ein Gerät gebunden — aus dem BackupEligible-Flag.
	Synced bool
}

// passkeyUser baut die WebAuthn-Sicht eines Kontos samt seiner gespeicherten
// Credentials. Ein Datensatz, dessen JSON sich nicht lesen lässt, wird
// übersprungen statt die ganze Anmeldung scheitern zu lassen.
func (s *Server) passkeyUser(r *http.Request, user store.User) (passkeys.User, []store.WebAuthnCredential, error) {
	stored, err := s.db.WebAuthnCredentialsByUser(r.Context(), user.ID)
	if err != nil {
		return passkeys.User{}, nil, err
	}
	creds := make([]wa.Credential, 0, len(stored))
	for _, c := range stored {
		var cred wa.Credential
		if err := json.Unmarshal(c.Data, &cred); err != nil {
			s.log.Warn("passkey lesen", "id", c.ID, "err", err)
			continue
		}
		creds = append(creds, cred)
	}
	return passkeys.User{
		ID:          user.ID,
		Name:        user.Username,
		DisplayName: user.Username,
		Credentials: creds,
	}, stored, nil
}

// passkeyViews wandelt die gespeicherten Credentials in Anzeigezeilen.
func passkeyViews(stored []store.WebAuthnCredential) []passkeyView {
	out := make([]passkeyView, 0, len(stored))
	for _, c := range stored {
		v := passkeyView{ID: c.ID, Label: c.Label, CreatedAt: c.CreatedAt, LastUsedAt: c.LastUsedAt}
		if v.Label == "" {
			v.Label = "Passkey"
		}
		var cred wa.Credential
		if json.Unmarshal(c.Data, &cred) == nil {
			v.Synced = cred.Flags.BackupEligible
		}
		out = append(out, v)
	}
	return out
}

// handlePasskeyRegisterBegin eröffnet die Registrierung. Das aktuelle Passwort
// wird verlangt, damit eine übernommene Sitzung nicht unbemerkt einen dauerhaften
// Schlüssel hinterlegen kann; die Bestätigung am Gerät allein genügt dafür nicht.
func (s *Server) handlePasskeyRegisterBegin(w http.ResponseWriter, r *http.Request) {
	if s.passkeys == nil {
		s.writeJSONError(w, http.StatusNotFound, "Passkeys sind nicht eingeschaltet.")
		return
	}
	user, _ := userFrom(r.Context())

	ok, err := auth.VerifyPassword(r.PostFormValue("password"), user.PasswordHash)
	if err != nil {
		s.log.Error("passwort prüfen", "err", err)
	}
	if !ok {
		s.audit(r, "passkey.register", user.Username, store.ResultDenied, "Passwort falsch")
		s.writeJSONError(w, http.StatusForbidden, "Das aktuelle Passwort stimmt nicht.")
		return
	}

	pu, _, err := s.passkeyUser(r, user)
	if err != nil {
		s.log.Error("passkeys laden", "err", err)
		s.writeJSONError(w, http.StatusInternalServerError, "Die Registrierung ließ sich nicht beginnen.")
		return
	}

	opts, token, err := s.passkeys.BeginRegistration(pu)
	if err != nil {
		s.log.Error("passkey-registrierung beginnen", "err", err)
		s.writeJSONError(w, http.StatusInternalServerError, "Die Registrierung ließ sich nicht beginnen.")
		return
	}
	s.writeJSON(w, http.StatusOK, map[string]any{
		"token":     token,
		"publicKey": opts.Response,
	})
}

// handlePasskeyRegisterFinish prüft die Antwort des Authenticators und legt den
// Passkey an. Antwortet mit JSON; die Seite lädt bei Erfolg selbst neu.
func (s *Server) handlePasskeyRegisterFinish(w http.ResponseWriter, r *http.Request) {
	if s.passkeys == nil {
		s.writeJSONError(w, http.StatusNotFound, "Passkeys sind nicht eingeschaltet.")
		return
	}
	user, _ := userFrom(r.Context())

	token := r.PostFormValue("token")
	label := strings.TrimSpace(r.PostFormValue("label"))
	if label == "" {
		label = "Passkey"
	}
	if len(label) > 60 {
		label = label[:60]
	}

	pu, _, err := s.passkeyUser(r, user)
	if err != nil {
		s.writeJSONError(w, http.StatusInternalServerError, "Die Registrierung ließ sich nicht abschließen.")
		return
	}

	cred, err := s.passkeys.FinishRegistration(pu, token, strings.NewReader(r.PostFormValue("credential")))
	if err != nil {
		s.audit(r, "passkey.register", user.Username, store.ResultError, err.Error())
		s.writeJSONError(w, http.StatusBadRequest, "Der Passkey ließ sich nicht bestätigen. Bitte erneut versuchen.")
		return
	}

	data, err := json.Marshal(cred)
	if err != nil {
		s.writeJSONError(w, http.StatusInternalServerError, "Der Passkey ließ sich nicht speichern.")
		return
	}
	_, err = s.db.AddWebAuthnCredential(r.Context(), store.WebAuthnCredential{
		UserID:       user.ID,
		CredentialID: passkeys.CredentialID(*cred),
		Label:        label,
		Data:         data,
	})
	if err != nil {
		// Dasselbe Gerät ein zweites Mal — die UNIQUE-Bedingung greift.
		s.audit(r, "passkey.register", user.Username, store.ResultError, "bereits registriert")
		s.writeJSONError(w, http.StatusConflict, "Dieser Passkey ist bereits hinterlegt.")
		return
	}
	s.audit(r, "passkey.register", user.Username, store.ResultOK, label)
	s.writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (s *Server) handlePasskeyRename(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil {
		s.renderError(w, r, http.StatusBadRequest, "Ungültige Kennung.")
		return
	}
	label := strings.TrimSpace(r.PostFormValue("label"))
	if label == "" {
		label = "Passkey"
	}
	if len(label) > 60 {
		label = label[:60]
	}
	if err := s.db.RenameWebAuthnCredential(r.Context(), id, user.ID, label); err != nil {
		s.renderAccount(w, r, http.StatusBadRequest, "", "Der Passkey ließ sich nicht umbenennen.", nil)
		return
	}
	s.audit(r, "passkey.rename", user.Username, store.ResultOK, label)
	s.renderAccount(w, r, http.StatusOK, "Der Passkey wurde umbenannt.", "", nil)
}

func (s *Server) handlePasskeyDelete(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil {
		s.renderError(w, r, http.StatusBadRequest, "Ungültige Kennung.")
		return
	}
	if err := s.db.DeleteWebAuthnCredential(r.Context(), id, user.ID); err != nil {
		s.renderAccount(w, r, http.StatusBadRequest, "", "Der Passkey ließ sich nicht entfernen.", nil)
		return
	}
	s.audit(r, "passkey.remove", user.Username, store.ResultOK, "")
	s.renderAccount(w, r, http.StatusOK, "Der Passkey wurde entfernt.", "", nil)
}

func (s *Server) writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func (s *Server) writeJSONError(w http.ResponseWriter, status int, msg string) {
	s.writeJSON(w, status, map[string]any{"error": msg})
}
