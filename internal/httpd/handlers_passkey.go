package httpd

import (
	"encoding/json"
	"net/http"
	"time"

	wa "github.com/go-webauthn/webauthn/webauthn"

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

// passkeyName liefert die Beschriftung eines Passkeys und die Zahl der übrigen
// desselben Kontos — beides für die Rückfrage vor dem Entfernen.
//
// Findet sich der Eintrag nicht, bleibt es bei einem allgemeinen Namen: Die
// Rückfrage soll auch dann erscheinen. Ob es den Passkey gibt und ob er zu
// diesem Konto gehört, entscheidet ohnehin das Löschen selbst.
func (s *Server) passkeyName(r *http.Request, userID, id int64) (string, int) {
	stored, err := s.db.WebAuthnCredentialsByUser(r.Context(), userID)
	if err != nil {
		s.log.Warn("passkeys laden", "err", err)
		return "dieses Gerät", 0
	}
	name := "dieses Gerät"
	uebrig := 0
	for _, c := range stored {
		if c.ID == id {
			if c.Label != "" {
				name = c.Label
			}
			continue
		}
		uebrig++
	}
	return name, uebrig
}

func (s *Server) writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func (s *Server) writeJSONError(w http.ResponseWriter, status int, msg string) {
	s.writeJSON(w, status, map[string]any{"error": msg})
}
