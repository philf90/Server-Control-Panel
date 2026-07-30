package httpd

// Panel-Zugänge über /api/v1 — die Konten dieser Oberfläche, nicht die des
// Wirtsystems.
//
// Das gefährlichste Modul der Verwaltung, und das aus einem Grund, der nichts mit
// dem System zu tun hat: Hier vergibt jemand Zugang zu allem anderen. Vier Dinge
// halten den Weg eng, und alle vier sind aus der alten Oberfläche übernommen —
// nicht neu erfunden:
//
//  1. **Nur die Owner-Rolle.** Die Route liegt hinter apiOwner, bevor
//     apiSchreibend greift: Wer nicht Owner ist, soll das erfahren und nicht
//     „Token fehlt".
//  2. **Zurücksetzen verlangt das EIGENE Passwort des Owners.** Ein übernommenes
//     Owner-Cookie allein soll keine fremden Konten übernehmen können. Das ist
//     die einzige Stelle in dieser Schnittstelle, an der ein Passwort im
//     Anfragekörper steht — und es wird nie protokolliert.
//  3. **Das eigene Konto läuft nicht über diesen Weg.** Ein Owner, der sich
//     selbst ein Einmalpasswort vergibt, hat nichts gewonnen; sperren oder
//     löschen könnte er sich selbst aussperren. Dafür gibt es die Kontoseite.
//  4. **Das letzte Owner-Konto bleibt.** Sonst sperrt sich die Installation
//     dauerhaft aus ihrer eigenen Benutzerverwaltung aus.
//
// Und eine Regel, die diesem Modul eigen ist: store.User wird NIE serialisiert.
// Der Typ trägt PasswordHash und TOTPSecret, und beides in einer JSON-Antwort
// wäre der schwerste Fehler, den diese Fläche machen kann. Deshalb steht hier ein
// eigener Typ mit ausdrücklich aufgezählten Feldern — nicht ein Einbetten mit
// `json:"-"` an den heiklen Stellen. Der Unterschied ist, was passiert, wenn
// jemand dem Store ein Feld hinzufügt: Beim Einbetten wandert es mit, hier nicht.

import (
	"errors"
	"net/http"
	"strconv"
	"strings"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/store"
)

// apiPanelkonto ist ein Konto dieser Oberfläche.
type apiPanelkonto struct {
	ID       int64  `json:"id"`
	Name     string `json:"name"`
	Rolle    string `json:"rolle"`
	RolleWas string `json:"rolle_was"`
	// Zustand ist die Klasse für die Einfärbung, ZustandText das Wort daneben.
	Zustand     string `json:"zustand"`
	ZustandText string `json:"zustand_text"`
	Gesperrt    bool   `json:"gesperrt"`
	// ZweiterFaktor sagt, ob er eingerichtet IST — nicht, ob er verlangt wird.
	// Ein Konto, das noch nie angemeldet war, hat ein Geheimnis, aber keine
	// Bestätigung.
	ZweiterFaktor bool `json:"zweiter_faktor"`
	// Einmalpasswort heißt: Das Konto muss beim nächsten Anmelden wechseln. Es
	// ist ein Zwischenzustand, und er gehört sichtbar in die Liste — sonst
	// wundert sich der Owner, warum der Zugang „nicht geht".
	Einmalpasswort bool   `json:"einmalpasswort"`
	Passkeys       int    `json:"passkeys"`
	Angelegt       string `json:"angelegt"`
	// LetzteAnmeldung ist leer, wenn sich das Konto noch nie angemeldet hat. Das
	// ist eine Aussage und kein fehlender Wert.
	LetzteAnmeldung string `json:"letzte_anmeldung"`
	// Ich markiert das eigene Konto. Die Oberfläche zeigt daran, warum die
	// Handgriffe fehlen.
	Ich bool `json:"ich"`
	// LetzterOwner heißt: Dieses Konto darf nicht gelöscht werden, weil danach
	// niemand mehr Konten verwalten könnte.
	LetzterOwner bool     `json:"letzter_owner"`
	Aktionen     []string `json:"aktionen"`
}

// Die Handgriffe eines Panelkontos.
const (
	panelAktionSperren       = "sperren"
	panelAktionFreigeben     = "freigeben"
	panelAktionLoeschen      = "loeschen"
	panelAktionPasswort      = "passwort"
	panelAktionZweiterFaktor = "zweiter-faktor"
	panelAktionPasskeys      = "passkeys"
)

// rolleWas erklärt eine Rolle in einem Satzteil. „admin" sagt nicht, was es
// bedeutet — „darf alles verändern, aber keine Konten verwalten" schon.
func rolleWas(rolle string) string {
	switch rolle {
	case store.RoleOwner:
		return "darf alles, einschließlich Konten und Update"
	case store.RoleAdmin:
		return "darf alles verändern, aber keine Konten verwalten"
	default:
		return "darf ansehen, nichts verändern"
	}
}

func (s *Server) panelkontoAus(u store.User, ichID int64, letzterOwner bool, passkeys int) apiPanelkonto {
	k := apiPanelkonto{
		ID:             u.ID,
		Name:           u.Username,
		Rolle:          u.Role,
		RolleWas:       rolleWas(u.Role),
		Zustand:        "gut",
		ZustandText:    "aktiv",
		Gesperrt:       u.Disabled,
		ZweiterFaktor:  u.TOTPConfirmed,
		Einmalpasswort: u.MustChangePassword,
		Passkeys:       passkeys,
		Angelegt:       u.CreatedAt.Format("02.01.2006"),
		Ich:            u.ID == ichID,
		LetzterOwner:   letzterOwner,
		Aktionen:       []string{},
	}
	if u.LastLoginAt != nil {
		k.LetzteAnmeldung = u.LastLoginAt.Format("02.01.2006 15:04")
	}
	switch {
	case u.Disabled:
		k.Zustand, k.ZustandText = "schlecht", "gesperrt"
	case u.MustChangePassword:
		// Bernstein und nicht rot: Das Konto ist in Ordnung, es muss nur noch
		// einmal etwas tun.
		k.Zustand, k.ZustandText = "warn", "Einmalpasswort"
	case !u.TOTPConfirmed:
		k.Zustand, k.ZustandText = "warn", "Einrichtung offen"
	}

	// Das eigene Konto bekommt hier keine Handgriffe. Nicht weil es verboten
	// wäre, sondern weil es woanders hingehört: Wer sein eigenes Passwort ändern
	// will, tut das auf der Kontoseite, und sperren oder löschen wäre ein
	// Selbstausschluss.
	if k.Ich {
		return k
	}
	if u.Disabled {
		k.Aktionen = append(k.Aktionen, panelAktionFreigeben)
	} else {
		k.Aktionen = append(k.Aktionen, panelAktionSperren)
	}
	if !letzterOwner {
		k.Aktionen = append(k.Aktionen, panelAktionLoeschen)
	}
	k.Aktionen = append(k.Aktionen,
		panelAktionPasswort, panelAktionZweiterFaktor)
	if passkeys > 0 {
		k.Aktionen = append(k.Aktionen, panelAktionPasskeys)
	}
	return k
}

// apiPanelzugaenge ist die Antwort von GET /api/v1/panel-users.
type apiPanelzugaenge struct {
	Konten []apiPanelkonto `json:"konten"`
	// Ich ist die Kennung des eigenen Kontos. Die Oberfläche braucht sie, um
	// die eigene Zeile zu erkennen, ohne den Namen zu vergleichen.
	Ich     int64 `json:"ich"`
	Zaehler struct {
		Gesamt   int `json:"gesamt"`
		Owner    int `json:"owner"`
		Gesperrt int `json:"gesperrt"`
		// Offen zählt Konten, deren Einrichtung noch nicht durch ist —
		// Einmalpasswort oder fehlender zweiter Faktor. Die Zahl, die eine
		// Nachfrage nach sich zieht.
		Offen int `json:"offen"`
	} `json:"zaehler"`
	// Rollen sind die wählbaren Rollen mit ihrer Erklärung.
	Rollen []apiRolle `json:"rollen"`
	// PasskeysMoeglich sagt, ob dieses Panel überhaupt Passkeys kennt. Ist es
	// abgeschaltet, fehlt der Handgriff — statt eines Knopfes, der nichts findet.
	PasskeysMoeglich bool `json:"passkeys_moeglich"`
}

type apiRolle struct {
	Wert string `json:"wert"`
	Was  string `json:"was"`
}

func (s *Server) handleAPIPanelUsers(w http.ResponseWriter, r *http.Request) {
	konten, err := s.db.ListUsers(r.Context())
	if err != nil {
		s.log.Error("panelkonten lesen", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Die Kontenliste ist nicht lesbar: "+err.Error())
		return
	}
	ich, _ := userFrom(r.Context())

	owner := 0
	for _, u := range konten {
		if u.Role == store.RoleOwner {
			owner++
		}
	}

	antwort := apiPanelzugaenge{
		Konten:           make([]apiPanelkonto, 0, len(konten)),
		Ich:              ich.ID,
		PasskeysMoeglich: s.passkeys != nil,
		Rollen: []apiRolle{
			{Wert: store.RoleOwner, Was: rolleWas(store.RoleOwner)},
			{Wert: store.RoleAdmin, Was: rolleWas(store.RoleAdmin)},
			{Wert: store.RoleReadOnly, Was: rolleWas(store.RoleReadOnly)},
		},
	}

	for _, u := range konten {
		// Die Passkeys je Konto. Scheitert die Abfrage, steht dort 0 und der
		// Handgriff fehlt — eine Liste, die deswegen gar nicht kommt, wäre die
		// schlechtere Antwort.
		anzahl := 0
		if s.passkeys != nil {
			if stored, err := s.db.WebAuthnCredentialsByUser(r.Context(), u.ID); err == nil {
				anzahl = len(stored)
			} else {
				s.log.Warn("passkeys zählen", "konto", u.Username, "err", err)
			}
		}
		letzterOwner := u.Role == store.RoleOwner && owner <= 1
		antwort.Konten = append(antwort.Konten, s.panelkontoAus(u, ich.ID, letzterOwner, anzahl))
	}

	antwort.Zaehler.Gesamt = len(antwort.Konten)
	antwort.Zaehler.Owner = owner
	for _, k := range antwort.Konten {
		if k.Gesperrt {
			antwort.Zaehler.Gesperrt++
		}
		if k.Einmalpasswort || !k.ZweiterFaktor {
			antwort.Zaehler.Offen++
		}
	}

	s.apiJSON(w, http.StatusOK, antwort)
}

// ------------------------------------------------------------- Verändern ---

// apiPanelAnfrage ist der Körper der verändernden Endpunkte.
type apiPanelAnfrage struct {
	Name  string `json:"name"`
	Rolle string `json:"rolle"`
	// Gesperrt ist der GEWÜNSCHTE Zustand, kein Umschalter.
	Gesperrt bool `json:"gesperrt"`
	// EigenesPasswort ist das Passwort des OWNERS, nicht des Zielkontos. Es ist
	// die zweite Schranke vor einer Zurücksetzung: Ein übernommenes Cookie allein
	// soll keine fremden Konten übernehmen können.
	//
	// Es wird nie protokolliert und nie zurückgegeben. Im Audit-Log steht, DASS
	// zurückgesetzt wurde, nie womit.
	EigenesPasswort string `json:"eigenes_passwort"`

	Bestaetigt bool   `json:"bestaetigt"`
	Getippt    string `json:"getippt"`
}

// apiPanelAntwort ist die Antwort auf eine ausgeführte Handlung.
type apiPanelAntwort struct {
	Meldung string `json:"meldung"`
	// Konto ist der neu gelesene Zustand — leer nach dem Löschen.
	Konto *apiPanelkonto `json:"konto,omitempty"`
	// Einmalpasswort steht GENAU EINMAL hier und nirgends sonst: nicht im
	// Audit-Log, nicht im Protokoll, nicht in einer zweiten Antwort. Wer es
	// verliert, setzt erneut zurück. Es gibt keinen Mailversand im Panel und soll
	// auch keinen brauchen — auf welchem Weg der Owner es weitergibt, entscheidet
	// er.
	Einmalpasswort string `json:"einmalpasswort,omitempty"`
	// Konto ist bei einem neuen Zugang der Name, unter dem er sich anmeldet.
	NeuesKonto string `json:"neues_konto,omitempty"`
	Hinweis    string `json:"hinweis,omitempty"`
}

func (s *Server) apiPanelKoerper(w http.ResponseWriter, r *http.Request) (apiPanelAnfrage, bool) {
	var anfrage apiPanelAnfrage
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return anfrage, false
	}
	anfrage.Name = strings.TrimSpace(anfrage.Name)
	anfrage.Rolle = strings.TrimSpace(anfrage.Rolle)
	return anfrage, true
}

// apiZielkonto liest das Konto aus dem Pfad und lehnt das eigene ab.
//
// Das eigene Konto läuft nicht über diesen Weg — für alle drei Fälle: sperren
// wäre ein Selbstausschluss, löschen auch, und ein Owner, der sich selbst ein
// Einmalpasswort vergibt, hat nichts gewonnen. Dafür gibt es die Kontoseite.
func (s *Server) apiZielkonto(w http.ResponseWriter, r *http.Request) (store.User, bool) {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil {
		s.apiFehler(w, http.StatusBadRequest, "Ungültige Kennung.")
		return store.User{}, false
	}
	ziel, err := s.db.UserByID(r.Context(), id)
	if err != nil {
		if errors.Is(err, store.ErrNotFound) {
			s.apiFehler(w, http.StatusNotFound, "Dieses Konto gibt es nicht.")
			return store.User{}, false
		}
		s.log.Error("panelkonto laden", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Das Konto ist nicht lesbar.")
		return store.User{}, false
	}
	ich, _ := userFrom(r.Context())
	if ziel.ID == ich.ID {
		s.apiFehler(w, http.StatusBadRequest,
			"Das eigene Konto lässt sich hier nicht ändern — dafür gibt es die Kontoseite.")
		return store.User{}, false
	}
	return ziel, true
}

// apiOwnerPasswort prüft das eigene Passwort des Owners.
//
// Die zweite Schranke vor jeder Zurücksetzung. Sie steht hier und nicht in der
// Middleware, weil sie nur für die drei Zurücksetzungen gilt: Sperren und
// Löschen sind umkehrbar beziehungsweise durch Stufe 3 gedeckt, das Übernehmen
// eines fremden Kontos ist beides nicht.
func (s *Server) apiOwnerPasswort(w http.ResponseWriter, r *http.Request, anfrage apiPanelAnfrage, ziel store.User) bool {
	ich, _ := userFrom(r.Context())

	if anfrage.EigenesPasswort == "" {
		s.apiFehler(w, http.StatusForbidden,
			"Für diese Aktion ist Ihr eigenes Passwort nötig.")
		return false
	}
	gueltig, err := auth.VerifyPassword(anfrage.EigenesPasswort, ich.PasswordHash)
	if err != nil {
		s.log.Error("passwort prüfen", "err", err)
	}
	if !gueltig {
		// Protokolliert wird der Versuch, nie das Passwort.
		s.audit(r, "user.reset", ziel.Username, store.ResultDenied, "eigenes Passwort falsch")
		s.apiFehler(w, http.StatusForbidden, "Das eigene Passwort stimmt nicht.")
		return false
	}
	return true
}

// panelAntwort liest das Konto neu und antwortet.
func (s *Server) panelAntwort(w http.ResponseWriter, r *http.Request, id int64, antwort apiPanelAntwort) {
	if u, err := s.db.UserByID(r.Context(), id); err == nil {
		ich, _ := userFrom(r.Context())
		anzahl := 0
		if s.passkeys != nil {
			if stored, err := s.db.WebAuthnCredentialsByUser(r.Context(), id); err == nil {
				anzahl = len(stored)
			}
		}
		// letzterOwner neu bestimmen: Eine Rollenänderung gibt es hier nicht, aber
		// ein Löschen an anderer Stelle schon.
		letzter := false
		if u.Role == store.RoleOwner {
			if alle, err := s.db.ListUsers(r.Context()); err == nil {
				owner := 0
				for _, x := range alle {
					if x.Role == store.RoleOwner {
						owner++
					}
				}
				letzter = owner <= 1
			}
		}
		k := s.panelkontoAus(u, ich.ID, letzter, anzahl)
		antwort.Konto = &k
	}
	s.apiJSON(w, http.StatusOK, antwort)
}

// handleAPIPanelUserCreate legt ein Konto an — Stufe 1.
//
// Das Startpasswort erzeugt das Panel selbst und zeigt es genau einmal. Bis 0.3.0
// tippte der Owner es in ein Feld, und das hatte drei Nachteile: Es war so gut,
// wie er es an diesem Tag gerade gewählt hat, es stand als Klartext in seinem
// Formular, und es blieb gültig, bis das neue Konto von selbst auf die Idee kam,
// es zu wechseln.
func (s *Server) handleAPIPanelUserCreate(w http.ResponseWriter, r *http.Request) {
	anfrage, ok := s.apiPanelKoerper(w, r)
	if !ok {
		return
	}

	if !validUsername(anfrage.Name) {
		s.apiFehler(w, http.StatusBadRequest,
			"Der Anmeldename darf 3–32 Zeichen lang sein und nur Buchstaben, Ziffern, "+
				"Punkt, Bindestrich und Unterstrich enthalten.")
		return
	}
	if !store.ValidRole(anfrage.Rolle) {
		s.apiFehler(w, http.StatusBadRequest, "Unbekannte Rolle.")
		return
	}

	passwort, err := auth.NewTemporaryPassword()
	if err != nil {
		s.log.Error("startpasswort erzeugen", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Das Passwort konnte nicht erzeugt werden.")
		return
	}
	hash, err := auth.HashPassword(passwort)
	if err != nil {
		s.log.Error("passwort hashen", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Das Konto konnte nicht angelegt werden.")
		return
	}
	geheimnis, err := auth.GenerateTOTPSecret()
	if err != nil {
		s.log.Error("totp-geheimnis", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Das Konto konnte nicht angelegt werden.")
		return
	}

	id, err := s.db.CreateUser(r.Context(), store.User{
		Username: anfrage.Name, PasswordHash: hash, Role: anfrage.Rolle,
		TOTPSecret: geheimnis, TOTPConfirmed: false,
		MustChangePassword: true,
	})
	if err != nil {
		s.audit(r, "user.create", anfrage.Name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadRequest,
			"Das Konto konnte nicht angelegt werden — vermutlich ist der Name bereits vergeben.")
		return
	}
	// Im Audit-Log steht, DASS ein Einmalpasswort vergeben wurde — nie das
	// Passwort selbst.
	s.audit(r, "user.create", anfrage.Name, store.ResultOK,
		"Rolle "+anfrage.Rolle+", Einmalpasswort vergeben")

	s.panelAntwort(w, r, id, apiPanelAntwort{
		Meldung:        "Zugang " + anfrage.Name + " angelegt.",
		NeuesKonto:     anfrage.Name,
		Einmalpasswort: passwort,
		Hinweis: "Das Passwort steht nur jetzt hier. Bei der ersten Anmeldung richtet " +
			"das Konto den zweiten Faktor ein und ersetzt das Passwort.",
	})
}

// handleAPIPanelUserDisabled sperrt oder gibt frei — Stufe 2 beim Sperren.
func (s *Server) handleAPIPanelUserDisabled(w http.ResponseWriter, r *http.Request) {
	ziel, ok := s.apiZielkonto(w, r)
	if !ok {
		return
	}
	anfrage, ok := s.apiPanelKoerper(w, r)
	if !ok {
		return
	}

	if anfrage.Gesperrt {
		if !s.apiBestaetigt(w, apiAktionAnfrage{
			Bestaetigt: anfrage.Bestaetigt, Getippt: anfrage.Getippt,
		}, apiBestaetigung{
			Titel: "Zugang sperren",
			Frage: "Den Panel-Zugang " + ziel.Username + " sperren?",
			Punkte: []string{
				"Offene Sitzungen dieses Kontos werden sofort beendet.",
				"Passwort, zweiter Faktor und Passkeys bleiben erhalten.",
				"Die Sperre lässt sich hier jederzeit aufheben.",
			},
			Knopf: "sperren",
		}) {
			return
		}
	}

	if err := s.db.SetDisabled(r.Context(), ziel.ID, anfrage.Gesperrt); err != nil {
		s.log.Error("konto sperren", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Die Änderung ließ sich nicht speichern.")
		return
	}
	if anfrage.Gesperrt {
		// Ein gesperrtes Konto darf keine laufende Sitzung behalten. Ohne das
		// wäre die Sperre erst wirksam, wenn das Cookie von selbst abläuft.
		if err := s.db.DeleteUserSessions(r.Context(), ziel.ID); err != nil {
			s.log.Warn("sitzungen beenden", "err", err)
		}
	}

	aktion, meldung := "user.enable", "Zugang "+ziel.Username+" ist wieder freigegeben."
	if anfrage.Gesperrt {
		aktion, meldung = "user.disable", "Zugang "+ziel.Username+" ist gesperrt."
	}
	s.audit(r, aktion, ziel.Username, store.ResultOK, "")
	s.panelAntwort(w, r, ziel.ID, apiPanelAntwort{Meldung: meldung})
}

// handleAPIPanelUserDelete löscht ein Konto — Stufe 3 mit dem Anmeldenamen.
func (s *Server) handleAPIPanelUserDelete(w http.ResponseWriter, r *http.Request) {
	ziel, ok := s.apiZielkonto(w, r)
	if !ok {
		return
	}
	anfrage, ok := s.apiPanelKoerper(w, r)
	if !ok {
		return
	}

	// Das letzte Owner-Konto muss bestehen bleiben, sonst sperrt sich die
	// Installation dauerhaft aus ihrer eigenen Benutzerverwaltung aus. Geprüft
	// VOR der Rückfrage: Eine Frage, deren Bestätigung dann abgelehnt wird, wäre
	// eine Zumutung.
	if ziel.Role == store.RoleOwner {
		alle, err := s.db.ListUsers(r.Context())
		if err == nil {
			owner := 0
			for _, u := range alle {
				if u.Role == store.RoleOwner {
					owner++
				}
			}
			if owner <= 1 {
				s.apiFehler(w, http.StatusBadRequest,
					"Das letzte Owner-Konto lässt sich nicht löschen — danach könnte niemand "+
						"mehr Konten verwalten.")
				return
			}
		}
	}

	if !s.apiBestaetigt(w, apiAktionAnfrage{
		Bestaetigt: anfrage.Bestaetigt, Getippt: anfrage.Getippt,
	}, apiBestaetigung{
		Titel: "Panel-Zugang löschen",
		Frage: "Konto " + ziel.Username + " (" + ziel.Role + ") endgültig löschen?",
		Punkte: []string{
			"Offene Sitzungen dieses Kontos werden beendet.",
			"Passkeys, zweiter Faktor und Wiederherstellungscodes gehen mit.",
			"Wer nur vorübergehend keinen Zugang haben soll, wird gesperrt statt gelöscht — das lässt sich zurücknehmen.",
		},
		Knopf:         "endgültig löschen",
		Tippen:        ziel.Username,
		TippenHinweis: "Zum Bestätigen den Anmeldenamen eingeben: " + ziel.Username,
	}) {
		return
	}

	if err := s.db.DeleteUser(r.Context(), ziel.ID); err != nil {
		s.log.Error("konto löschen", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Das Konto ließ sich nicht löschen.")
		return
	}
	s.audit(r, "user.delete", ziel.Username, store.ResultOK, "")

	s.apiJSON(w, http.StatusOK, apiPanelAntwort{
		Meldung: "Konto " + ziel.Username + " gelöscht.",
	})
}

// handleAPIPanelUserResetPassword vergibt ein Einmalpasswort.
func (s *Server) handleAPIPanelUserResetPassword(w http.ResponseWriter, r *http.Request) {
	ziel, ok := s.apiZielkonto(w, r)
	if !ok {
		return
	}
	anfrage, ok := s.apiPanelKoerper(w, r)
	if !ok {
		return
	}
	if !s.apiOwnerPasswort(w, r, anfrage, ziel) {
		return
	}
	ctx := r.Context()

	passwort, err := auth.NewTemporaryPassword()
	if err != nil {
		s.log.Error("einmalpasswort erzeugen", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Das Passwort konnte nicht erzeugt werden.")
		return
	}
	hash, err := auth.HashPassword(passwort)
	if err != nil {
		s.log.Error("passwort hashen", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Das Passwort ließ sich nicht speichern.")
		return
	}
	if err := s.db.SetTemporaryPassword(ctx, ziel.ID, hash); err != nil {
		s.log.Error("einmalpasswort setzen", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Das Passwort ließ sich nicht speichern.")
		return
	}

	// Laufende Sitzungen beenden: Eine Zurücksetzung ist die Reaktion auf etwas,
	// das schiefgegangen ist. Was noch angemeldet war, soll es danach nicht mehr
	// sein.
	if err := s.db.DeleteUserSessions(ctx, ziel.ID); err != nil {
		s.log.Warn("sitzungen beenden", "err", err)
	}
	// Und eine Sperre aufheben: Wer ein neues Passwort bekommt, soll sich damit
	// auch anmelden können. Dasselbe tut der Rettungsweg auf der Kommandozeile.
	if err := s.db.SetDisabled(ctx, ziel.ID, false); err != nil {
		s.log.Warn("sperre aufheben", "err", err)
	}
	s.audit(r, "user.reset_password", ziel.Username, store.ResultOK,
		"Einmalpasswort vergeben, Sitzungen beendet")

	s.panelAntwort(w, r, ziel.ID, apiPanelAntwort{
		Meldung:        "Für " + ziel.Username + " ist ein Einmalpasswort vergeben.",
		Einmalpasswort: passwort,
		Hinweis: "Das Passwort steht nur jetzt hier. Offene Sitzungen des Kontos sind " +
			"beendet, eine Sperre ist aufgehoben; beim nächsten Anmelden wird das Passwort ersetzt.",
	})
}

// handleAPIPanelUserReset2FA macht den zweiten Faktor unbestätigt.
func (s *Server) handleAPIPanelUserReset2FA(w http.ResponseWriter, r *http.Request) {
	ziel, ok := s.apiZielkonto(w, r)
	if !ok {
		return
	}
	anfrage, ok := s.apiPanelKoerper(w, r)
	if !ok {
		return
	}
	if !s.apiOwnerPasswort(w, r, anfrage, ziel) {
		return
	}
	ctx := r.Context()

	geheimnis, err := auth.GenerateTOTPSecret()
	if err != nil {
		s.log.Error("totp-geheimnis erzeugen", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Der zweite Faktor ließ sich nicht vorbereiten.")
		return
	}
	if err := s.db.SetTOTP(ctx, ziel.ID, geheimnis, false); err != nil {
		s.log.Error("totp zurücksetzen", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Der zweite Faktor ließ sich nicht zurücksetzen.")
		return
	}
	// Die alten Wiederherstellungscodes gehörten zum alten Geheimnis. Blieben sie
	// liegen, wären sie ein zweiter Faktor, den niemand mehr überblickt.
	if err := s.db.ReplaceRecoveryCodes(ctx, ziel.ID, nil); err != nil {
		s.log.Warn("wiederherstellungscodes leeren", "err", err)
	}
	if err := s.db.DeleteUserSessions(ctx, ziel.ID); err != nil {
		s.log.Warn("sitzungen beenden", "err", err)
	}
	s.audit(r, "user.reset_2fa", ziel.Username, store.ResultOK, "Codeliste geleert, Sitzungen beendet")

	s.panelAntwort(w, r, ziel.ID, apiPanelAntwort{
		Meldung: "Der zweite Faktor von " + ziel.Username + " ist zurückgesetzt.",
		Hinweis: "Beim nächsten Anmelden wird er neu eingerichtet; das Passwort bleibt " +
			"unverändert. Die alten Wiederherstellungscodes gelten nicht mehr.",
	})
}

// handleAPIPanelUserResetPasskeys entfernt alle Passkeys eines Kontos — für
// verlorene Geräte.
func (s *Server) handleAPIPanelUserResetPasskeys(w http.ResponseWriter, r *http.Request) {
	ziel, ok := s.apiZielkonto(w, r)
	if !ok {
		return
	}
	anfrage, ok := s.apiPanelKoerper(w, r)
	if !ok {
		return
	}
	if !s.apiOwnerPasswort(w, r, anfrage, ziel) {
		return
	}

	n, err := s.db.DeleteWebAuthnCredentialsByUser(r.Context(), ziel.ID)
	if err != nil {
		s.log.Error("passkeys entfernen", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Die Passkeys ließen sich nicht entfernen.")
		return
	}
	s.audit(r, "user.reset_passkeys", ziel.Username, store.ResultOK, "")

	meldung := "Für " + ziel.Username + " war kein Passkey hinterlegt."
	switch {
	case n == 1:
		meldung = "Der Passkey von " + ziel.Username + " wurde entfernt."
	case n > 1:
		meldung = "Alle " + strconv.FormatInt(n, 10) + " Passkeys von " + ziel.Username + " wurden entfernt."
	}
	s.panelAntwort(w, r, ziel.ID, apiPanelAntwort{Meldung: meldung})
}
