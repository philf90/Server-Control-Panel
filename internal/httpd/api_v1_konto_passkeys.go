package httpd

// Passkeys des eigenen Kontos über /api/v1.
//
// Vier Endpunkte, und einer davon ist anders als alles andere in dieser
// Schnittstelle: Die Registrierung läuft über ZWEI Aufrufe, weil zwischen ihnen
// der Browser mit dem Gerät sprechen muss. Der Server gibt bei „begin" die
// Zeremonie-Optionen und ein Ticket heraus, unter dem die Challenge liegt; bei
// „finish" prüft go-webauthn die Antwort des Authenticators gegen dieselbe
// Challenge. Ohne das Ticket wäre die Challenge im Browser, und eine Challenge,
// die der Prüfer vom Geprüften bekommt, prüft nichts.
//
// Was sich gegenüber der alten Oberfläche ändert, ist NUR die Verpackung: dort
// Formularfelder (application/x-www-form-urlencoded), hier JSON. Die Zeremonie
// selbst, die Prüfung, die Ablage und die Rückfragen sind dieselben — und der
// Grund dafür ist, dass sie im Browser mit einem virtuellen Authenticator geprüft
// sind (passkey_e2e_test.go) und dieser Nachweis nicht durch eine zweite
// Umsetzung derselben Sache entwertet werden soll. Was hier steht, ruft die
// gleichen Methoden von internal/passkeys auf.
//
// Die Umrechnung base64url ↔ ArrayBuffer bleibt Sache des Browsers: WebAuthn
// arbeitet mit Puffern, JSON kann keine tragen. Das ist keine Eigenheit dieses
// Panels, sondern der Schnittstelle.

import (
	"encoding/json"
	"net/http"
	"strconv"
	"strings"

	"github.com/philf90/asylum/internal/passkeys"
	"github.com/philf90/asylum/internal/store"
)

// apiPasskey ist ein hinterlegter Passkey.
type apiPasskey struct {
	ID   int64  `json:"id"`
	Name string `json:"name"`
	// Synced sagt, ob der Schlüssel geräteübergreifend gesichert ist
	// (Cloud-Passkey) oder an ein Gerät gebunden. Der Unterschied gehört in die
	// Anzeige: Ein gerätegebundener Schlüssel ist mit dem Gerät verloren.
	Synced   bool   `json:"synced"`
	Angelegt string `json:"angelegt"`
	// Zuletzt ist leer, wenn der Passkey noch nie zur Anmeldung benutzt wurde.
	// Das ist eine Aussage und kein fehlender Wert — ein hinterlegter Schlüssel,
	// mit dem sich noch niemand angemeldet hat, ist ungeprüft.
	Zuletzt string `json:"zuletzt"`
}

func apiPasskeyAus(v passkeyView) apiPasskey {
	p := apiPasskey{
		ID:       v.ID,
		Name:     v.Label,
		Synced:   v.Synced,
		Angelegt: v.CreatedAt.Format("02.01.2006"),
	}
	if v.LastUsedAt != nil {
		p.Zuletzt = v.LastUsedAt.Format("02.01.2006 15:04")
	}
	return p
}

// apiPasskeyBeginn ist der Körper von POST …/passkeys/register/begin.
type apiPasskeyBeginn struct {
	// Passwort ist das aktuelle Passwort. Es steht hier, damit eine übernommene
	// Sitzung nicht unbemerkt einen DAUERHAFTEN Anmeldeweg hinterlegen kann — die
	// Bestätigung am Gerät allein genügt dafür nicht, denn sie beweist nur, dass
	// jemand am Gerät sitzt.
	Passwort string `json:"passwort"`
	Name     string `json:"name"`
}

// apiPasskeyBeginnAntwort trägt die Zeremonie-Optionen.
type apiPasskeyBeginnAntwort struct {
	// Ticket verweist auf die serverseitig hinterlegte Challenge.
	Ticket string `json:"ticket"`
	// Optionen sind die Optionen für navigator.credentials.create — durchgereicht,
	// wie go-webauthn sie baut. Als json.RawMessage und nicht als eigener Typ:
	// Eine Nachbildung der Struktur hier wäre eine zweite Stelle, die bei jeder
	// Erweiterung des Standards nachgezogen werden müsste, und sie würde
	// Felder verschlucken, die der Browser braucht.
	Optionen json.RawMessage `json:"optionen"`
}

// apiPasskeyAbschluss ist der Körper von POST …/passkeys/register/finish.
type apiPasskeyAbschluss struct {
	Ticket string `json:"ticket"`
	Name   string `json:"name"`
	// Nachweis ist die Antwort des Authenticators, wie der Browser sie liefert.
	// Ebenfalls durchgereicht: Was hier ankommt, prüft go-webauthn, nicht dieses
	// Paket.
	Nachweis json.RawMessage `json:"nachweis"`
}

// passkeyLabel kürzt und vereinheitlicht die Beschriftung.
func passkeyLabel(name string) string {
	name = strings.TrimSpace(name)
	if name == "" {
		return "Passkey"
	}
	// Auf Zeichen und nicht auf Bytes kürzen: Ein Schnitt mitten in einem Umlaut
	// ergibt ein ungültiges Zeichen, und die alte Fassung schnitt auf Bytes.
	zeichen := []rune(name)
	if len(zeichen) > 60 {
		return string(zeichen[:60])
	}
	return name
}

// handleAPIPasskeyBegin eröffnet die Registrierung.
func (s *Server) handleAPIPasskeyBegin(w http.ResponseWriter, r *http.Request) {
	if s.passkeys == nil {
		s.apiFehler(w, http.StatusNotFound, "Passkeys sind in dieser Installation nicht eingeschaltet.")
		return
	}
	var anfrage apiPasskeyBeginn
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}
	if !s.apiEigenesPasswort(w, r, anfrage.Passwort, "passkey.register") {
		return
	}
	user, _ := userFrom(r.Context())

	pu, _, err := s.passkeyUser(r, user)
	if err != nil {
		s.log.Error("passkeys laden", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Die Registrierung ließ sich nicht beginnen.")
		return
	}
	opts, ticket, err := s.passkeys.BeginRegistration(pu)
	if err != nil {
		s.log.Error("passkey-registrierung beginnen", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Die Registrierung ließ sich nicht beginnen.")
		return
	}
	roh, err := json.Marshal(opts.Response)
	if err != nil {
		s.log.Error("zeremonie-optionen", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Die Registrierung ließ sich nicht beginnen.")
		return
	}

	s.apiJSON(w, http.StatusOK, apiPasskeyBeginnAntwort{Ticket: ticket, Optionen: roh})
}

// handleAPIPasskeyFinish prüft die Antwort des Geräts und legt den Passkey an.
func (s *Server) handleAPIPasskeyFinish(w http.ResponseWriter, r *http.Request) {
	if s.passkeys == nil {
		s.apiFehler(w, http.StatusNotFound, "Passkeys sind in dieser Installation nicht eingeschaltet.")
		return
	}
	var anfrage apiPasskeyAbschluss
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}
	user, _ := userFrom(r.Context())
	name := passkeyLabel(anfrage.Name)

	pu, _, err := s.passkeyUser(r, user)
	if err != nil {
		s.apiFehler(w, http.StatusInternalServerError, "Die Registrierung ließ sich nicht abschließen.")
		return
	}
	cred, err := s.passkeys.FinishRegistration(pu, anfrage.Ticket, strings.NewReader(string(anfrage.Nachweis)))
	if err != nil {
		// Der Grund steht im Protokoll und nicht in der Antwort: Er nennt
		// Einzelheiten der Zeremonie, die dem Bediener nichts sagen und einem
		// Angreifer etwas.
		s.audit(r, "passkey.register", user.Username, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadRequest,
			"Der Passkey ließ sich nicht bestätigen. Bitte erneut versuchen.")
		return
	}

	data, err := json.Marshal(cred)
	if err != nil {
		s.apiFehler(w, http.StatusInternalServerError, "Der Passkey ließ sich nicht speichern.")
		return
	}
	if _, err := s.db.AddWebAuthnCredential(r.Context(), store.WebAuthnCredential{
		UserID:       user.ID,
		CredentialID: passkeys.CredentialID(*cred),
		Label:        name,
		Data:         data,
	}); err != nil {
		// Dasselbe Gerät ein zweites Mal — die UNIQUE-Bedingung greift.
		s.audit(r, "passkey.register", user.Username, store.ResultError, "bereits registriert")
		s.apiFehler(w, http.StatusConflict, "Dieser Passkey ist bereits hinterlegt.")
		return
	}
	s.audit(r, "passkey.register", user.Username, store.ResultOK, name)

	s.eigenFertig(w, r, apiEigenAntwort{
		Meldung: "Der Passkey „" + name + "\" ist hinterlegt.",
		Hinweis: "Passkeys ersetzen das Passwort bei der Anmeldung. Der zweite Faktor bleibt, " +
			"wie er ist.",
	})
}

// handleAPIPasskeyRename benennt einen Passkey um.
//
// Kein Passwort und keine Rückfrage: Eine Beschriftung ist eine Notiz für den
// Inhaber und kein Anmeldeweg. Sie zu ändern nimmt niemandem etwas.
func (s *Server) handleAPIPasskeyRename(w http.ResponseWriter, r *http.Request) {
	auftrag, ok := s.apiEigenKoerper(w, r)
	if !ok {
		return
	}
	id, ok := s.apiPasskeyID(w, r)
	if !ok {
		return
	}
	user, _ := userFrom(r.Context())
	name := passkeyLabel(auftrag.Name)

	// Die Abfrage bindet die Kennung an den eigenen Benutzer. Das ist die
	// Schranke dieses Endpunkts: Eine fremde Kennung benennt nichts um.
	if err := s.db.RenameWebAuthnCredential(r.Context(), id, user.ID, name); err != nil {
		s.apiFehler(w, http.StatusBadRequest, "Der Passkey ließ sich nicht umbenennen.")
		return
	}
	s.audit(r, "passkey.rename", user.Username, store.ResultOK, name)

	s.eigenFertig(w, r, apiEigenAntwort{Meldung: "Der Passkey heißt jetzt „" + name + "\"."})
}

// handleAPIPasskeyDelete entfernt einen Passkey — Stufe 2 mit seinem Namen.
func (s *Server) handleAPIPasskeyDelete(w http.ResponseWriter, r *http.Request) {
	auftrag, ok := s.apiEigenKoerper(w, r)
	if !ok {
		return
	}
	id, ok := s.apiPasskeyID(w, r)
	if !ok {
		return
	}
	user, _ := userFrom(r.Context())

	// Der NAME steht in der Frage und nicht die Kennung: In einer Liste von drei
	// Geräten ist „Passkey entfernen?" keine Auskunft darüber, welches gemeint
	// ist. Wie viele es sonst noch gibt, gehört dazu — beim letzten fällt ein
	// Anmeldeweg weg.
	name, uebrig := s.passkeyName(r, user.ID, id)
	folgen := []string{"Die Anmeldung über dieses Gerät ist danach nicht mehr möglich."}
	if uebrig == 0 {
		folgen = append(folgen,
			"Es ist der letzte hinterlegte Passkey dieses Kontos. Die Anmeldung läuft "+
				"danach wieder über Passwort und zweiten Faktor.")
	}
	if !s.apiBestaetigt(w, apiAktionAnfrage{
		Bestaetigt: auftrag.Bestaetigt, Getippt: auftrag.Getippt,
	}, apiBestaetigung{
		Titel:  "Passkey entfernen",
		Frage:  "Passkey „" + name + "\" entfernen?",
		Punkte: folgen,
		Knopf:  "entfernen",
	}) {
		return
	}

	if err := s.db.DeleteWebAuthnCredential(r.Context(), id, user.ID); err != nil {
		s.apiFehler(w, http.StatusBadRequest, "Der Passkey ließ sich nicht entfernen.")
		return
	}
	s.audit(r, "passkey.remove", user.Username, store.ResultOK, name)

	s.eigenFertig(w, r, apiEigenAntwort{Meldung: "Der Passkey „" + name + "\" ist entfernt."})
}

func (s *Server) apiPasskeyID(w http.ResponseWriter, r *http.Request) (int64, bool) {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil {
		s.apiFehler(w, http.StatusBadRequest, "Ungültige Kennung.")
		return 0, false
	}
	return id, true
}
