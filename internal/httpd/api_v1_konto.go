package httpd

// Das eigene Konto über /api/v1 — Passwort, zweiter Faktor, Wiederherstellungs-
// codes und die offenen Sitzungen. Die Passkeys stehen in api_v1_konto_passkeys.go.
//
// Der Unterschied zu den Panel-Zugängen ist nicht die Rolle, sondern die Person:
// Dort verwaltet jemand FREMDE Konten und braucht dafür die Owner-Rolle; hier
// verwaltet jeder sein EIGENES, und das darf jede Rolle. Was in beiden Modulen
// gleich bleibt, ist die zweite Schranke — jede Änderung an einem Anmeldeweg
// verlangt das aktuelle Passwort. Der Grund ist derselbe: Eine übernommene
// Sitzung soll den rechtmäßigen Inhaber nicht aussperren können, indem sie ihm
// die Tür zumauert, die er zur Rückeroberung bräuchte.
//
// Zwei Eigenheiten hat dieses Modul, und beide kommen daher, dass der Handelnde
// gleichzeitig das Ziel ist:
//
//  1. **Die eigene Sitzung wird MITgeschlossen und neu aufgebaut.** Wer sein
//     Passwort ändert, beendet alle Sitzungen des Kontos — auch die, in der er
//     gerade sitzt. Die Antwort trägt deshalb ein frisches CSRF-Token, und der
//     Cookie ist ausgetauscht, bevor sie den Server verlässt. Ohne das wäre die
//     Oberfläche nach der eigenen Passwortänderung abgemeldet, und die
//     naheliegende Deutung wäre „hat nicht funktioniert".
//  2. **Ein begonnener Wechsel des zweiten Faktors liegt NICHT in der
//     Datenbank.** Er liegt in s.pending, mit einer Frist von 15 Minuten. Wer
//     abbricht — weil die App abstürzt oder das Telefon leer ist —, muss sich
//     weiterhin mit dem alten Faktor anmelden können. Dieselbe Ablage benutzt
//     die alte Oberfläche; die neue kommt mit einem Weg dazu, den es dort nicht
//     gab: Sie kann den Wechsel ausdrücklich abbrechen, statt die Frist
//     abzuwarten.

import (
	"errors"
	"net/http"
	"strings"
	"time"

	"rsc.io/qr"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/store"
)

// ------------------------------------------------------------------ Lesen ---

// apiEigenesKonto ist die Antwort von GET /api/v1/account.
type apiEigenesKonto struct {
	Name  string `json:"name"`
	Rolle string `json:"rolle"`
	// RolleWas erklärt die Rolle in einem Satzteil — derselbe Text wie bei den
	// Panel-Zugängen, damit dasselbe Wort nicht an zwei Stellen zwei
	// Bedeutungen bekommt.
	RolleWas string `json:"rolle_was"`
	Angelegt string `json:"angelegt"`
	// ZweiterFaktor ist hier immer true: Ohne bestätigten zweiten Faktor kommt
	// niemand über die Einrichtungsseite hinaus. Das Feld steht trotzdem da,
	// weil die Oberfläche nichts behaupten soll, was sie nicht gelesen hat.
	ZweiterFaktor bool `json:"zweiter_faktor"`
	// CodesOffen ist die Zahl der noch unbenutzten Wiederherstellungscodes. Sie
	// ist der Grund, warum dieser Wert überhaupt angezeigt wird: Bei 0 ist der
	// Weg zurück ins Konto verstellt, wenn das Telefon abhandenkommt.
	CodesOffen int `json:"codes_offen"`
	// Wechselzwang heißt: Das aktuelle Passwort kommt aus einer Zurücksetzung.
	Wechselzwang bool `json:"wechselzwang"`

	Sitzungen []apiSitzungszeile `json:"sitzungen"`
	// Andere ist die Zahl der Sitzungen außer dieser. Als eigene Zahl, weil der
	// Knopf „alle anderen beenden" sie braucht und die Oberfläche sie sonst aus
	// der Liste rechnen müsste.
	Andere int `json:"andere"`

	PasskeysMoeglich bool         `json:"passkeys_moeglich"`
	Passkeys         []apiPasskey `json:"passkeys"`

	// Wechsel steht, wenn ein Wechsel des zweiten Faktors angefangen ist. Nach
	// einem Neuladen der Seite ist das die einzige Auskunft darüber — der Zustand
	// liegt auf dem Server, nicht im Browser.
	Wechsel *apiZweiterFaktorWechsel `json:"wechsel,omitempty"`
}

// apiSitzungszeile ist eine offene Sitzung des eigenen Kontos.
//
// Die Liste ist mehr als Bequemlichkeit: Ein entwendetes Sitzungscookie
// hinterlässt sonst keine Spur, die dem Betroffenen auffiele. Erst Adresse und
// letzte Aktivität machen eine übernommene Sitzung sichtbar.
type apiSitzungszeile struct {
	// ID ist bereits der Hash des Cookies und damit kein Geheimnis. Kurz
	// ausgegeben wird sie trotzdem — niemand liest 64 Zeichen.
	ID       string `json:"id"`
	Kurz     string `json:"kurz"`
	IP       string `json:"ip"`
	Programm string `json:"programm"`
	Seit     string `json:"seit"`
	Zuletzt  string `json:"zuletzt"`
	Laeuft   string `json:"laeuft_ab"`
	// Diese markiert die Sitzung, in der die Anfrage steht. Sie zu beenden ist
	// ein Abmelden, und die Oberfläche sagt das auch so.
	Diese bool `json:"diese"`
}

// apiZweiterFaktorWechsel ist ein angefangener Wechsel.
type apiZweiterFaktorWechsel struct {
	// Geheimnis und GeheimnisText: einmal für die App, einmal für das Auge. Das
	// Geheimnis steht hier, weil nicht jeder einen QR-Code abfotografieren kann —
	// dieselbe Wahl trifft die alte Oberfläche.
	Geheimnis     string `json:"geheimnis"`
	GeheimnisText string `json:"geheimnis_text"`
	URI           string `json:"uri"`
	// QR ist der Pfad zum Bild, nicht das Bild. Ein data:-URI wäre erlaubt
	// (img-src 'self' data:), aber dann stünde das Geheimnis ein zweites Mal in
	// der Antwort — als Bild, das jeder Zwischenspeicher mitnimmt.
	QR string `json:"qr"`
	// LaeuftAb sagt, wie lange der Wechsel noch gilt. Ohne die Angabe wirkt ein
	// abgelaufener Wechsel wie ein Fehler des Panels.
	LaeuftAb string `json:"laeuft_ab"`
}

func (s *Server) handleAPIKonto(w http.ResponseWriter, r *http.Request) {
	s.apiJSON(w, http.StatusOK, s.eigenesKonto(r))
}

// eigenesKonto baut die Antwort. Eigene Funktion, weil jede verändernde Handlung
// sie mitschickt: Die Oberfläche soll nach einem Handgriff nicht ein zweites Mal
// fragen müssen, was jetzt gilt.
func (s *Server) eigenesKonto(r *http.Request) apiEigenesKonto {
	user, _ := userFrom(r.Context())
	ctx := r.Context()

	offen, err := s.db.CountUnusedRecoveryCodes(ctx, user.ID)
	if err != nil {
		s.log.Warn("wiederherstellungscodes zählen", "err", err)
	}

	antwort := apiEigenesKonto{
		Name:             user.Username,
		Rolle:            user.Role,
		RolleWas:         rolleWas(user.Role),
		Angelegt:         user.CreatedAt.Format("02.01.2006"),
		ZweiterFaktor:    user.TOTPConfirmed,
		CodesOffen:       offen,
		Wechselzwang:     user.MustChangePassword,
		Sitzungen:        []apiSitzungszeile{},
		PasskeysMoeglich: s.passkeys != nil,
		Passkeys:         []apiPasskey{},
	}

	dieseID := ""
	if sess, ok := sessionFrom(ctx); ok {
		dieseID = sess.ID
	}
	if sitzungen, err := s.db.ListUserSessions(ctx, user.ID); err != nil {
		s.log.Warn("sitzungen lesen", "err", err)
	} else {
		for _, sess := range sitzungen {
			diese := sess.ID == dieseID
			if !diese {
				antwort.Andere++
			}
			antwort.Sitzungen = append(antwort.Sitzungen, apiSitzungszeile{
				ID:       sess.ID,
				Kurz:     shortID(sess.ID),
				IP:       sess.IP,
				Programm: shortenUserAgent(sess.UserAgent),
				Seit:     sess.CreatedAt.Format("02.01.2006 15:04"),
				Zuletzt:  sess.LastSeenAt.Format("02.01.2006 15:04"),
				Laeuft:   sess.ExpiresAt.Format("02.01.2006 15:04"),
				Diese:    diese,
			})
		}
	}

	if s.passkeys != nil {
		if stored, err := s.db.WebAuthnCredentialsByUser(ctx, user.ID); err != nil {
			s.log.Warn("passkeys laden", "err", err)
		} else {
			for _, v := range passkeyViews(stored) {
				antwort.Passkeys = append(antwort.Passkeys, apiPasskeyAus(v))
			}
		}
	}

	if geheimnis, bis, ok := s.pending.mitFrist(user.ID); ok {
		antwort.Wechsel = &apiZweiterFaktorWechsel{
			Geheimnis:     geheimnis,
			GeheimnisText: auth.FormatSecret(geheimnis),
			URI:           auth.TOTPProvisioningURI(geheimnis, user.Username, totpIssuer),
			QR:            "/api/v1/account/2fa/qr.png",
			LaeuftAb:      bis.Format("15:04"),
		}
	}
	return antwort
}

// ------------------------------------------------------------- Verändern ---

// apiEigenAuftrag ist der Körper der verändernden Endpunkte.
type apiEigenAuftrag struct {
	// Passwort ist das AKTUELLE Passwort — die zweite Schranke vor jeder
	// Änderung an einem Anmeldeweg. Es wird nie protokolliert und nie
	// zurückgegeben.
	Passwort string `json:"passwort"`
	Neu      string `json:"neu"`
	// NeuWiederholt ist die zweite Eingabe. Verglichen wird auf dem Server, weil
	// ein Vergleich nur im Browser eine Bedienhilfe ist und keine Prüfung.
	NeuWiederholt string `json:"neu_wiederholt"`
	// Code ist der Bestätigungscode aus der Authenticator-App.
	Code string `json:"code"`
	// Sitzung ist die Kennung einer zu beendenden Sitzung.
	Sitzung string `json:"sitzung"`
	// Name ist die Beschriftung eines Passkeys.
	Name string `json:"name"`

	Bestaetigt bool   `json:"bestaetigt"`
	Getippt    string `json:"getippt"`
}

// apiEigenAntwort ist die Antwort auf eine ausgeführte Handlung.
type apiEigenAntwort struct {
	Meldung string `json:"meldung"`
	// Konto ist der neu gelesene Zustand. Immer dabei, damit die Oberfläche nach
	// einem Handgriff nicht ein zweites Mal fragen muss.
	Konto *apiEigenesKonto `json:"konto,omitempty"`
	// Codes sind neue Wiederherstellungscodes. Sie stehen GENAU EINMAL hier —
	// nicht im Audit-Protokoll, nicht in einer zweiten Antwort.
	Codes []string `json:"codes,omitempty"`
	// CSRF ist ein frisches Sitzungstoken. Gesetzt, wenn die Handlung die eigene
	// Sitzung erneuert hat: Nach einer Passwortänderung sind alle Sitzungen des
	// Kontos beendet, auch die eigene, und das alte Token gilt nicht mehr. Ohne
	// dieses Feld schlüge der nächste schreibende Aufruf mit „Sitzungstoken passt
	// nicht" fehl — nach einer Handlung, die geglückt ist.
	CSRF string `json:"csrf,omitempty"`
	// Abgemeldet heißt: Diese Sitzung ist beendet. Die Oberfläche führt dann zur
	// Anmeldung, statt eine Liste zu zeigen, die es nicht mehr gibt.
	Abgemeldet bool   `json:"abgemeldet,omitempty"`
	Hinweis    string `json:"hinweis,omitempty"`
}

func (s *Server) apiEigenKoerper(w http.ResponseWriter, r *http.Request) (apiEigenAuftrag, bool) {
	var auftrag apiEigenAuftrag
	if !s.apiJSONKoerper(w, r, &auftrag) {
		return auftrag, false
	}
	auftrag.Code = strings.TrimSpace(auftrag.Code)
	auftrag.Name = strings.TrimSpace(auftrag.Name)
	return auftrag, true
}

// apiEigenesPasswort prüft das aktuelle Passwort des Handelnden.
//
// Die Schranke vor jeder Änderung an einem Anmeldeweg: Passwort wechseln,
// zweiten Faktor wechseln, Passkey hinterlegen. Nicht in der Middleware, weil
// sie nicht für alles gilt — eine Sitzung zu beenden oder Codes neu zu erzeugen
// nimmt niemandem den Zugang.
func (s *Server) apiEigenesPasswort(w http.ResponseWriter, r *http.Request, passwort, was string) bool {
	user, _ := userFrom(r.Context())
	if passwort == "" {
		s.apiFehler(w, http.StatusForbidden, "Für diese Änderung ist Ihr aktuelles Passwort nötig.")
		return false
	}
	gueltig, err := auth.VerifyPassword(passwort, user.PasswordHash)
	if err != nil {
		s.log.Error("passwort prüfen", "err", err)
	}
	if !gueltig {
		// Protokolliert wird der Versuch, nie das Passwort.
		s.audit(r, was, user.Username, store.ResultDenied, "aktuelles Passwort falsch")
		s.apiFehler(w, http.StatusForbidden, "Das aktuelle Passwort stimmt nicht.")
		return false
	}
	return true
}

// eigenFertig antwortet mit dem neu gelesenen Zustand.
func (s *Server) eigenFertig(w http.ResponseWriter, r *http.Request, antwort apiEigenAntwort) {
	konto := s.eigenesKonto(r)
	antwort.Konto = &konto
	s.apiJSON(w, http.StatusOK, antwort)
}

// handleAPIKontoPasswort ändert das eigene Passwort.
//
// Alle Sitzungen des Kontos werden beendet — auch die eigene, denn wer sein
// Passwort ändert, will üblicherweise genau das erreichen. Danach wird die eigene
// neu aufgebaut: Der Cookie ist ausgetauscht, bevor die Antwort den Server
// verlässt, und das frische CSRF-Token steht darin. Ohne beides wäre die
// Oberfläche nach der geglückten Änderung abgemeldet.
func (s *Server) handleAPIKontoPasswort(w http.ResponseWriter, r *http.Request) {
	auftrag, ok := s.apiEigenKoerper(w, r)
	if !ok {
		return
	}
	if !s.apiEigenesPasswort(w, r, auftrag.Passwort, "password.change") {
		return
	}
	user, _ := userFrom(r.Context())
	ctx := r.Context()

	if auftrag.Neu != auftrag.NeuWiederholt {
		s.apiFehler(w, http.StatusBadRequest, "Die beiden neuen Passwörter stimmen nicht überein.")
		return
	}
	if err := auth.CheckPasswordPolicy(user.Username, auftrag.Neu); err != nil {
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}

	hash, err := auth.HashPassword(auftrag.Neu)
	if err != nil {
		s.log.Error("passwort hashen", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Das Passwort ließ sich nicht speichern.")
		return
	}
	if err := s.db.SetPassword(ctx, user.ID, hash); err != nil {
		s.log.Error("passwort speichern", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Das Passwort ließ sich nicht speichern.")
		return
	}
	if err := s.db.DeleteUserSessions(ctx, user.ID); err != nil {
		s.log.Warn("sitzungen beenden", "err", err)
	}
	s.audit(r, "password.change", user.Username, store.ResultOK, "alle Sitzungen beendet")

	// Die eigene Sitzung neu aufbauen. Scheitert das, ist die Änderung trotzdem
	// gültig — die Antwort sagt dann, dass neu angemeldet werden muss, statt
	// einen Fehler zu melden, der nach einem Fehlschlag der Änderung aussieht.
	if err := s.startSession(w, r, user); err != nil {
		s.log.Error("sitzung erneuern", "err", err)
		s.apiJSON(w, http.StatusOK, apiEigenAntwort{
			Meldung:    "Das Passwort ist geändert.",
			Abgemeldet: true,
			Hinweis:    "Alle Sitzungen sind beendet. Bitte mit dem neuen Passwort neu anmelden.",
		})
		return
	}

	// Das frische Token aus der NEUEN Sitzung. Es steht nicht im Kontext dieser
	// Anfrage — der trägt die alte, gerade gelöschte Sitzung.
	neuesToken := ""
	if sitzungen, err := s.db.ListUserSessions(ctx, user.ID); err == nil && len(sitzungen) == 1 {
		neuesToken = sitzungen[0].CSRFToken
	}

	s.eigenFertig(w, r, apiEigenAntwort{
		Meldung: "Das Passwort ist geändert.",
		CSRF:    neuesToken,
		Hinweis: "Alle anderen Sitzungen sind beendet. Diese bleibt offen.",
	})
}

// handleAPIKontoCodes erzeugt neue Wiederherstellungscodes — Stufe 2.
//
// Die Rückfrage steht hier, obwohl nichts kaputtgeht: Neue Codes machen die
// alten ungültig. Wer die alte Liste ausgedruckt hat und den Knopf im
// Vorbeigehen trifft, hält danach Papier ohne Wert in der Hand — und merkt es
// erst, wenn er sie braucht.
func (s *Server) handleAPIKontoCodes(w http.ResponseWriter, r *http.Request) {
	auftrag, ok := s.apiEigenKoerper(w, r)
	if !ok {
		return
	}
	if !s.apiBestaetigt(w, apiAktionAnfrage{
		Bestaetigt: auftrag.Bestaetigt, Getippt: auftrag.Getippt,
	}, apiBestaetigung{
		Titel: "Neue Wiederherstellungscodes",
		Frage: "Neue Wiederherstellungscodes erzeugen? Die bisherigen gelten danach nicht mehr.",
		Punkte: []string{
			"Die neuen Codes werden genau einmal angezeigt.",
			"Eine ausgedruckte oder abgelegte alte Liste ist danach wertlos.",
		},
		Knopf: "neue Codes erzeugen",
	}) {
		return
	}
	user, _ := userFrom(r.Context())

	codes, hashes, err := auth.NewRecoveryCodes()
	if err != nil {
		s.log.Error("wiederherstellungscodes", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Die Codes ließen sich nicht erzeugen.")
		return
	}
	if err := s.db.ReplaceRecoveryCodes(r.Context(), user.ID, hashes); err != nil {
		s.log.Error("wiederherstellungscodes speichern", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Die Codes ließen sich nicht speichern.")
		return
	}
	s.audit(r, "recovery_codes.regenerated", user.Username, store.ResultOK, "")

	s.eigenFertig(w, r, apiEigenAntwort{
		Meldung: "Neue Codes erzeugt. Die alten gelten nicht mehr.",
		Codes:   codes,
		Hinweis: "Diese Liste erscheint nur jetzt. Jeder Code gilt einmal.",
	})
}

// ------------------------------------------------- Zweiter Faktor wechseln ---

// handleAPIKontoZweiterFaktorStart beginnt den Wechsel.
//
// Das neue Geheimnis geht NICHT in die Datenbank, sondern in s.pending mit einer
// Frist von 15 Minuten: Wer abbricht, muss sich weiterhin mit dem alten Faktor
// anmelden können.
func (s *Server) handleAPIKontoZweiterFaktorStart(w http.ResponseWriter, r *http.Request) {
	auftrag, ok := s.apiEigenKoerper(w, r)
	if !ok {
		return
	}
	if !s.apiEigenesPasswort(w, r, auftrag.Passwort, "2fa.change") {
		return
	}
	user, _ := userFrom(r.Context())

	geheimnis, err := auth.GenerateTOTPSecret()
	if err != nil {
		s.log.Error("totp-geheimnis erzeugen", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Der Wechsel ließ sich nicht beginnen.")
		return
	}
	s.pending.put(user.ID, geheimnis)
	s.audit(r, "2fa.change", user.Username, store.ResultOK, "begonnen")

	s.eigenFertig(w, r, apiEigenAntwort{
		Meldung: "Der Wechsel ist begonnen. Der alte Faktor gilt weiter, bis der neue bestätigt ist.",
	})
}

// handleAPIKontoZweiterFaktorQR liefert den QR-Code zum begonnenen Wechsel.
//
// Ein Bild und kein JSON: Das ist der einzige Endpunkt dieses Moduls, der keine
// Schnittstellenantwort gibt. Der Grund ist die Richtlinie — `img-src 'self'`
// erlaubt genau das, und ein data:-URI im JSON hätte das Geheimnis ein zweites
// Mal in der Antwort stehen lassen.
func (s *Server) handleAPIKontoZweiterFaktorQR(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	geheimnis, ok := s.pending.get(user.ID)
	if !ok {
		s.apiFehler(w, http.StatusForbidden, "Es ist kein Wechsel begonnen.")
		return
	}
	code, err := qr.Encode(auth.TOTPProvisioningURI(geheimnis, user.Username, totpIssuer), qr.M)
	if err != nil {
		s.log.Error("qr-code", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Der QR-Code ließ sich nicht erzeugen.")
		return
	}
	w.Header().Set("Content-Type", "image/png")
	w.Header().Set("Cache-Control", "no-store")
	_, _ = w.Write(code.PNG())
}

// handleAPIKontoZweiterFaktorConfirm schließt den Wechsel ab.
func (s *Server) handleAPIKontoZweiterFaktorConfirm(w http.ResponseWriter, r *http.Request) {
	auftrag, ok := s.apiEigenKoerper(w, r)
	if !ok {
		return
	}
	user, _ := userFrom(r.Context())
	ctx := r.Context()

	geheimnis, ok := s.pending.get(user.ID)
	if !ok {
		s.apiFehler(w, http.StatusBadRequest, "Der Wechsel ist abgelaufen. Bitte erneut beginnen.")
		return
	}
	if !auth.VerifyTOTP(geheimnis, auftrag.Code, time.Now()) {
		s.audit(r, "2fa.change", user.Username, store.ResultDenied, "Bestätigungscode falsch")
		s.apiFehler(w, http.StatusBadRequest,
			"Der Code stimmt nicht. Bitte den aktuellen Code aus der neuen App eingeben.")
		return
	}

	if err := s.db.SetTOTP(ctx, user.ID, geheimnis, true); err != nil {
		s.log.Error("totp wechseln", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Der zweite Faktor ließ sich nicht speichern.")
		return
	}
	s.pending.drop(user.ID)

	// Neue Wiederherstellungscodes: Die alten gehörten zum alten Faktor, und wer
	// sein Telefon wechselt, hat den alten Zettel selten noch griffbereit.
	codes, hashes, err := auth.NewRecoveryCodes()
	if err != nil {
		s.log.Error("wiederherstellungscodes", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Die Wiederherstellungscodes ließen sich nicht erzeugen.")
		return
	}
	if err := s.db.ReplaceRecoveryCodes(ctx, user.ID, hashes); err != nil {
		s.log.Error("wiederherstellungscodes speichern", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Die Wiederherstellungscodes ließen sich nicht speichern.")
		return
	}

	// Andere Sitzungen beenden: Ein Wechsel des zweiten Faktors ist meist eine
	// Reaktion auf ein verlorenes Gerät. Was darauf noch angemeldet war, soll es
	// danach nicht mehr sein.
	if sess, ok := sessionFrom(ctx); ok {
		if _, err := s.db.DeleteOtherUserSessions(ctx, user.ID, sess.ID); err != nil {
			s.log.Warn("andere sitzungen beenden", "err", err)
		}
	}
	s.audit(r, "2fa.change", user.Username, store.ResultOK, "abgeschlossen, andere Sitzungen beendet")

	s.eigenFertig(w, r, apiEigenAntwort{
		Meldung: "Der zweite Faktor ist gewechselt.",
		Codes:   codes,
		Hinweis: "Die alten Wiederherstellungscodes gelten nicht mehr — hier ist die neue Liste. " +
			"Alle anderen Sitzungen sind beendet.",
	})
}

// handleAPIKontoZweiterFaktorAbbruch verwirft einen begonnenen Wechsel.
//
// Diesen Weg gab es in der alten Oberfläche nicht: Dort verließ man die Seite,
// und das Geheimnis lief nach 15 Minuten ab. In einer Einzelseiten-Anwendung ist
// „die Seite verlassen" kein Vorgang mehr — der halbe Wechsel stünde nach jedem
// Wechsel des Moduls wieder da. Wer abbricht, soll das ausdrücklich tun können.
func (s *Server) handleAPIKontoZweiterFaktorAbbruch(w http.ResponseWriter, r *http.Request) {
	if _, ok := s.apiEigenKoerper(w, r); !ok {
		return
	}
	user, _ := userFrom(r.Context())
	if _, offen := s.pending.get(user.ID); !offen {
		s.apiFehler(w, http.StatusBadRequest, "Es ist kein Wechsel begonnen.")
		return
	}
	s.pending.drop(user.ID)
	s.audit(r, "2fa.change", user.Username, store.ResultDenied, "abgebrochen")

	s.eigenFertig(w, r, apiEigenAntwort{
		Meldung: "Der Wechsel ist abgebrochen. Der bisherige zweite Faktor gilt weiter.",
	})
}

// -------------------------------------------------------- Eigene Sitzungen ---

// handleAPIKontoSitzungBeenden beendet eine einzelne Sitzung.
func (s *Server) handleAPIKontoSitzungBeenden(w http.ResponseWriter, r *http.Request) {
	auftrag, ok := s.apiEigenKoerper(w, r)
	if !ok {
		return
	}
	user, _ := userFrom(r.Context())

	// Die eigene Sitzung zu beenden ist schlicht ein Abmelden. Der Weg dorthin
	// unterscheidet sich aber: Die alte Oberfläche gab an handleLogout weiter, der
	// eine Weiterleitung schickt — eine Weiterleitung auf eine HTML-Seite ist für
	// einen fetch keine Antwort, sondern eine, die er als Erfolg missversteht.
	if sess, ok := sessionFrom(r.Context()); ok && sess.ID == auftrag.Sitzung {
		s.endSession(w, r)
		s.audit(r, "logout", user.Username, store.ResultOK, "eigene Sitzung beendet")
		s.apiJSON(w, http.StatusOK, apiEigenAntwort{
			Meldung:    "Diese Sitzung ist beendet.",
			Abgemeldet: true,
		})
		return
	}

	err := s.db.DeleteUserSession(r.Context(), user.ID, auftrag.Sitzung)
	if errors.Is(err, store.ErrNotFound) {
		// Nicht gefunden heißt hier auch: gehört einem anderen Konto. Die Abfrage
		// bindet die Kennung an den eigenen Benutzer, und das ist die eigentliche
		// Schranke dieses Endpunkts — eine fremde Sitzungskennung beendet nichts.
		s.audit(r, "session.revoke", shortID(auftrag.Sitzung), store.ResultDenied, "keine eigene Sitzung")
		s.apiFehler(w, http.StatusNotFound, "Diese Sitzung gibt es nicht mehr.")
		return
	}
	if err != nil {
		s.log.Error("sitzung beenden", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Die Sitzung ließ sich nicht beenden.")
		return
	}
	s.audit(r, "session.revoke", shortID(auftrag.Sitzung), store.ResultOK, "")

	s.eigenFertig(w, r, apiEigenAntwort{Meldung: "Die Sitzung ist beendet."})
}

// handleAPIKontoSitzungenBeenden beendet alle Sitzungen außer dieser — Stufe 2.
func (s *Server) handleAPIKontoSitzungenBeenden(w http.ResponseWriter, r *http.Request) {
	auftrag, ok := s.apiEigenKoerper(w, r)
	if !ok {
		return
	}
	user, _ := userFrom(r.Context())
	sess, ok := sessionFrom(r.Context())
	if !ok {
		s.apiFehler(w, http.StatusUnauthorized, "nicht angemeldet")
		return
	}

	if !s.apiBestaetigt(w, apiAktionAnfrage{
		Bestaetigt: auftrag.Bestaetigt, Getippt: auftrag.Getippt,
	}, apiBestaetigung{
		Titel:  "Sitzungen beenden",
		Frage:  "Alle anderen Sitzungen dieses Kontos beenden?",
		Punkte: []string{"Diese Sitzung bleibt offen. Alle übrigen müssen sich neu anmelden."},
		Knopf:  "andere Sitzungen beenden",
	}) {
		return
	}

	n, err := s.db.DeleteOtherUserSessions(r.Context(), user.ID, sess.ID)
	if err != nil {
		s.log.Error("sitzungen beenden", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Die Sitzungen ließen sich nicht beenden.")
		return
	}
	s.audit(r, "session.revoke", user.Username, store.ResultOK, "alle anderen Sitzungen")

	meldung := "Es war keine weitere Sitzung offen."
	switch {
	case n == 1:
		meldung = "Eine weitere Sitzung wurde beendet."
	case n > 1:
		meldung = "Alle anderen Sitzungen wurden beendet."
	}
	s.eigenFertig(w, r, apiEigenAntwort{Meldung: meldung})
}
