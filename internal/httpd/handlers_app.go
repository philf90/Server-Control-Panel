package httpd

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/metrics"
	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
	"github.com/philf90/asylum/internal/ui"
)

func (s *Server) handleDashboard(w http.ResponseWriter, r *http.Request) {
	// Bewusst die jüngste Messung und nicht der letzte Ringpuffer-Eintrag:
	// Der Ring bekommt nur alle 30 Sekunden etwas. Daraus zu rendern hieße,
	// dass eine frische Installation — und jeder Neustart nach einem Update —
	// eine halbe Minute lang "keine Daten" zeigt. Der Live-Kanal füllt zwar
	// die Zahlen nach, aber nicht die Tabellen; die entstehen serverseitig.
	snap, ok := s.lastSnapshot()
	if !ok {
		snap.UptimeText = "wird ermittelt"
	}

	signals := s.dashboardSignals(r.Context(), snap)
	verdict := dashVerdict{
		Level: "ok",
		Title: "Alles läuft normal",
		Sub:   "Keine offenen Punkte.",
	}
	if n := len(signals); n == 1 {
		verdict = dashVerdict{Level: "warn", Title: "1 Ding braucht Aufmerksamkeit", Sub: "Alles übrige läuft normal."}
	} else if n > 1 {
		verdict = dashVerdict{Level: "warn", Title: fmt.Sprintf("%d Dinge brauchen Aufmerksamkeit", n), Sub: "Alles übrige läuft normal."}
	}

	// Nicht die erste Schnittstelle der Liste, sondern die, über die dieser
	// Rechner am Netz hängt. Die Auswahl steht in internal/metrics.
	iface, hasIface := snap.PrimaryInterface()

	s.renderPage(w, r, http.StatusOK, "dashboard",
		s.base(r, "Übersicht", "dashboard").with(dashboardPage{
			Snapshot: snap,
			HasData:  ok,
			Verdict:  verdict,
			Signals:  signals,
			Sparks:   s.dashboardSparks(),
			Net:      iface,
			HasNet:   hasIface,
		}))
}

// dashboardSignals sammelt die Punkte für „Handlungsbedarf". Bewusst nur aus
// günstigen Quellen und mit kurzem Timeout: Die Übersicht ist die meistbesuchte
// Seite und darf nicht an einem hängenden systemctl kleben bleiben. Jeder
// Fehler wird verschluckt — dann fehlt eben ein Signal, die Seite steht aber.
func (s *Server) dashboardSignals(ctx context.Context, snap metrics.Snapshot) []dashSignal {
	ctx, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()

	var out []dashSignal

	// Fehlgeschlagene Dienste.
	if svcs, err := s.ops.Services(ctx, privops.ServiceFilter{}); err == nil {
		var failed []string
		for _, sv := range svcs {
			if sv.Failed() {
				failed = append(failed, sv.Unit)
			}
		}
		switch {
		case len(failed) == 1:
			out = append(out, dashSignal{
				Level: "crit", Tag: "Dienst", Title: failed[0] + " ist ausgefallen",
				Detail:      "Der Dienst läuft nicht mehr. Auf der Dienste-Seite lässt er sich neu starten.",
				ActionLabel: "Dienste öffnen", ActionHref: "/services", Primary: true,
			})
		case len(failed) > 1:
			out = append(out, dashSignal{
				Level: "crit", Tag: "Dienste", Title: fmt.Sprintf("%d Dienste sind ausgefallen", len(failed)),
				Detail:      strings.Join(failed, " · "),
				ActionLabel: "Dienste öffnen", ActionHref: "/services", Primary: true,
			})
		}
	}

	// Plattendruck — aus der bereits vorliegenden Messung, ohne zusätzlichen Aufruf.
	for _, fs := range snap.Filesystems {
		switch {
		case fs.UsedPct >= 95:
			out = append(out, dashSignal{
				Level: "crit", Tag: "Speicher", Title: fmt.Sprintf("%s ist zu %.0f %% belegt", fs.Mount, fs.UsedPct),
				Detail: "Es wird eng — hier drohen Schreibfehler.", ActionLabel: "Pakete öffnen", ActionHref: "/packages",
			})
		case fs.UsedPct >= 85:
			out = append(out, dashSignal{
				Level: "warn", Tag: "Speicher", Title: fmt.Sprintf("%s ist zu %.0f %% belegt", fs.Mount, fs.UsedPct),
				Detail: "Bei einem größeren Update könnte der Platz knapp werden.", ActionLabel: "Pakete öffnen", ActionHref: "/packages",
			})
		}
	}

	// Neustart nötig.
	if rb, err := s.ops.RebootRequired(ctx); err == nil && rb.Required {
		detail := "Ein Kernel- oder Bibliotheks-Update wartet auf einen Neustart."
		if len(rb.Packages) > 0 {
			detail = "Ausgelöst durch: " + strings.Join(rb.Packages, ", ")
		}
		out = append(out, dashSignal{
			Level: "warn", Tag: "System", Title: "Ein Neustart steht aus", Detail: detail,
			ActionLabel: "Zu den Paketen", ActionHref: "/packages",
		})
	}

	return out
}

// dashboardSparks baut die Verläufe der letzten 24 Stunden aus dem Ringpuffer.
//
// Der Netzverlauf zählt nur die Hauptschnittstelle. Vorher war er die Summe
// über alle — mit Docker also der echten Karte plus einer Brücke, die
// stillsteht. Was dabei herauskam, gehörte zu keiner Zahl auf der Kachel.
func (s *Server) dashboardSparks() dashSparks {
	all := s.ring.All()
	at := make([]time.Time, 0, len(all))
	cpu := make([]float64, 0, len(all))
	mem := make([]float64, 0, len(all))
	load := make([]float64, 0, len(all))
	net := make([]float64, 0, len(all))
	for _, sn := range all {
		at = append(at, sn.At)
		cpu = append(cpu, sn.CPU.Total)
		mem = append(mem, sn.Memory.UsedPct)
		load = append(load, sn.Load[0])
		var n float64
		if ifc, ok := sn.PrimaryInterface(); ok {
			n = ifc.RXRate + ifc.TXRate
		}
		net = append(net, n)
	}
	return dashSparks{
		CPU:  buildSpark(at, cpu, prozentText, 5),
		Mem:  buildSpark(at, mem, prozentText, 5),
		Load: buildSpark(at, load, lastText, 0.5),
		Net:  buildSpark(at, net, durchsatzText, 4096),
	}
}

// Die Texte der Messpunkte entstehen hier und nicht im Browser: Einheit,
// Rundung und Sprache stehen im Panel an einer Stelle, und das Skript für den
// Mouseover bleibt eine Anzeige ohne eigene Rechnung.
func prozentText(v float64) string { return fmt.Sprintf("%.1f %%", v) }

// Die Last wird auf zwei Stellen genannt. Die große Zahl der Kachel zeigt eine;
// im Verlauf eines ruhigen Servers wäre damit jeder Punkt "0.0".
func lastText(v float64) string { return fmt.Sprintf("%.2f", v) }

// "gesamt", weil der Verlauf empfangen und gesendet zusammenfasst, die große
// Zahl der Kachel aber nur das Empfangene nennt. Ohne das Wort sähe der
// Messpunkt neben der Kachel wie ein Widerspruch aus.
func durchsatzText(v float64) string { return ui.FormatRate(v) + " gesamt" }

const (
	sparkBreite = 100.0
	sparkHoehe  = 34.0
	sparkRand   = 2.0
	// sparkPunkte begrenzt die Zahl der gezeichneten Stützstellen.
	//
	// Der Ringpuffer hält 24 Stunden in 30-Sekunden-Auflösung, also bis zu 2880
	// Messungen. Auf 100 Einheiten Breite liegen die 0,03 Einheiten
	// auseinander — bei rund 270 Pixeln Kachelbreite etwa zehn Punkte je Pixel.
	// Daraus wird kein Verlauf, sondern ein Band: Der Strich überzeichnet sich
	// selbst und sieht ausgelaufen aus. 60 Stützstellen sind je eine für 24
	// Minuten und in einer Kachel dieser Größe noch unterscheidbar.
	sparkPunkte = 60
)

// sparkPunkt ist eine Stützstelle des Verlaufs, wie sie der Mouseover braucht:
// die Stelle im Feld und der Text dazu.
type sparkPunkt struct {
	X float64 `json:"x"`
	Y float64 `json:"y"`
	T string  `json:"t"`
	V string  `json:"v"`
}

// buildSpark erzeugt den SVG-Pfad eines Verlaufs in einem 100×34-Feld, den
// Endpunkt und die Messpunkte für den Mouseover. Weniger als zwei Werte ergeben
// keinen Verlauf (Has=false) — dann zeigt die Kachel nur die Zahl.
//
// minSpan ist die kleinste Spanne, über die skaliert wird. Ohne sie zieht die
// Min-Max-Skalierung jeden Bruchteil auf die volle Kachelhöhe: Eine CPU, die
// zwischen 0,1 und 0,3 Prozent pendelt, sah aus wie ein Gebirge, und ein
// ruhiger Server wirkte unruhig. Mit ihr bleibt flach, was flach ist.
func buildSpark(at []time.Time, vals []float64, text func(float64) string, minSpan float64) spark {
	if len(vals) < 2 || len(at) != len(vals) {
		return spark{}
	}
	at, vals = verdichten(at, vals, sparkPunkte)

	minV, maxV := vals[0], vals[0]
	for _, v := range vals {
		if v < minV {
			minV = v
		}
		if v > maxV {
			maxV = v
		}
	}
	if maxV-minV < minSpan {
		mitte := (minV + maxV) / 2
		minV, maxV = mitte-minSpan/2, mitte+minSpan/2
		// Keine Messgröße dieser Seite ist negativ. Liegt die Spanne im
		// Nullbereich, wird sie dort verankert, statt Platz unter der Null zu
		// verschenken.
		if minV < 0 {
			minV, maxV = 0, minSpan
		}
	}
	span := maxV - minV
	if span <= 0 {
		span = 1
	}

	dx := (sparkBreite - 2*sparkRand) / float64(len(vals)-1)
	var b strings.Builder
	punkte := make([]sparkPunkt, 0, len(vals))
	for i, v := range vals {
		x := einstellig(sparkRand + float64(i)*dx)
		y := einstellig(sparkHoehe - sparkRand - ((v-minV)/span)*(sparkHoehe-2*sparkRand))
		if i == 0 {
			fmt.Fprintf(&b, "M%.1f %.1f", x, y)
		} else {
			fmt.Fprintf(&b, " L%.1f %.1f", x, y)
		}
		punkte = append(punkte, sparkPunkt{X: x, Y: y, T: at[i].Local().Format("15:04"), V: text(v)})
	}

	roh, err := json.Marshal(punkte)
	if err != nil {
		// Kann mit diesen Feldern nicht scheitern; dann eben ohne Mouseover.
		roh = []byte("[]")
	}
	letzter := punkte[len(punkte)-1]
	return spark{
		Path: b.String(),
		// Der Endpunkt ist ein Segment der Länge null mit runder Kappe. Ein
		// <circle> würde von derselben waagerechten Streckung getroffen wie der
		// Strich und käme als liegende Ellipse heraus; ein Strich mit
		// non-scaling-stroke ergibt einen runden Punkt.
		Dot:    fmt.Sprintf("M%.1f %.1f L%.1f %.1f", letzter.X, letzter.Y, letzter.X, letzter.Y),
		Points: string(roh),
		Has:    true,
	}
}

// verdichten mittelt einen Verlauf auf höchstens so viele Stützstellen. Der
// Zeitstempel einer Stützstelle ist der aus der Mitte ihres Abschnitts.
func verdichten(at []time.Time, vals []float64, stuetzstellen int) ([]time.Time, []float64) {
	if stuetzstellen < 2 || len(vals) <= stuetzstellen {
		return at, vals
	}
	ausAt := make([]time.Time, 0, stuetzstellen)
	ausV := make([]float64, 0, stuetzstellen)
	for i := 0; i < stuetzstellen; i++ {
		von := i * len(vals) / stuetzstellen
		bis := (i + 1) * len(vals) / stuetzstellen
		if bis <= von {
			continue
		}
		var summe float64
		for _, v := range vals[von:bis] {
			summe += v
		}
		ausV = append(ausV, summe/float64(bis-von))
		ausAt = append(ausAt, at[von+(bis-von)/2])
	}
	return ausAt, ausV
}

// einstellig rundet auf eine Nachkommastelle — dieselbe Genauigkeit, mit der
// der Pfad geschrieben wird. Sonst nennt das JSON für den Mouseover eine
// Stelle, die im Bild nicht existiert.
func einstellig(v float64) float64 { return math.Round(v*10) / 10 }

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

	sessions := s.sessionViews(r)
	others := 0
	for _, sess := range sessions {
		if !sess.Current {
			others++
		}
	}

	var passkeyList []passkeyView
	if s.passkeys != nil {
		if stored, err := s.db.WebAuthnCredentialsByUser(r.Context(), user.ID); err != nil {
			s.log.Warn("passkeys laden", "err", err)
		} else {
			passkeyList = passkeyViews(stored)
		}
	}

	page := s.base(r, "Mein Konto", "account").with(accountPage{
		RecoveryCodesLeft: left,
		NewCodes:          newCodes,
		Sessions:          sessions,
		OtherSessions:     others,
		WebAuthnOn:        s.passkeys != nil,
		Passkeys:          passkeyList,
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
	if err := auth.CheckPasswordPolicy(user.Username, next); err != nil {
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

	// Neue Codes machen die alten ungültig. Wer die alte Liste ausgedruckt hat
	// und den Knopf im Vorbeigehen trifft, hält danach Papier ohne Wert in der
	// Hand — und merkt es erst, wenn er sie braucht.
	if !s.bestaetigt(w, r, bestaetigung{
		Titel: "Neue Wiederherstellungscodes",
		Frage: "Neue Wiederherstellungscodes erzeugen? Die bisherigen gelten danach nicht mehr.",
		Punkte: []string{
			"Die neuen Codes werden genau einmal angezeigt.",
			"Eine ausgedruckte oder abgelegte alte Liste ist danach wertlos.",
		},
		Knopf:   "neue Codes erzeugen",
		Abbruch: "/account",
	}) {
		return
	}

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
	// Das eigene Konto gehört nicht in die Auswahl zum Zurücksetzen: Ein Owner,
	// der sich selbst ein Einmalpasswort vergibt, hat nichts gewonnen.
	actor, _ := userFrom(r.Context())
	others := make([]store.User, 0, len(users))
	for _, u := range users {
		if u.ID != actor.ID {
			others = append(others, u)
		}
	}
	// Vorauswahl aus dem Sprunglink der Tabellenzeile. Ein unbrauchbarer Wert
	// bedeutet schlicht keine Vorauswahl.
	var resetID int64
	if raw := r.URL.Query().Get("reset"); raw != "" {
		if id, err := strconv.ParseInt(raw, 10, 64); err == nil {
			resetID = id
		}
	}

	page := s.base(r, "Panel-Zugänge", "users").with(usersPage{
		Users:   users,
		Others:  others,
		ResetID: resetID,
	})
	if flash != "" {
		page = page.withFlash(flash)
	}
	if errMsg != "" {
		page = page.withError(errMsg)
	}
	s.renderPage(w, r, status, "users", page)
}

// handleUserCreate legt ein Panel-Konto an. Anzugeben sind nur Anmeldename und
// Rolle.
//
// Das Startpasswort erzeugt das Panel selbst und zeigt es genau einmal — derselbe
// Weg wie beim Zurücksetzen eines Zugangs und bei den Wiederherstellungscodes.
// Bis hierher tippte der Owner es in ein Feld, und das hatte drei Nachteile: Es
// war so gut, wie er es an diesem Tag gerade gewählt hat, es stand als Klartext
// in seinem Formular (und je nach Browser in dessen Speicher), und es blieb
// gültig, bis das neue Konto von selbst auf die Idee kam, es zu wechseln. Das
// erzeugte Passwort ist zufällig, entspricht der Richtlinie und ist ein
// Einmalpasswort: Bei der ersten Anmeldung verlangt das Panel den Wechsel.
func (s *Server) handleUserCreate(w http.ResponseWriter, r *http.Request) {
	username := strings.TrimSpace(r.PostFormValue("username"))
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

	password, err := auth.NewTemporaryPassword()
	if err != nil {
		s.log.Error("startpasswort erzeugen", "err", err)
		s.renderUsers(w, r, http.StatusInternalServerError, "", "Das Passwort konnte nicht erzeugt werden.")
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
	// eingerichtet, danach folgt der Passwortwechsel — requireSetupDone und
	// requireAuth lassen vorher nichts anderes zu.
	_, err = s.db.CreateUser(r.Context(), store.User{
		Username: username, PasswordHash: hash, Role: role,
		TOTPSecret: secret, TOTPConfirmed: false,
		MustChangePassword: true,
	})
	if err != nil {
		s.audit(r, "user.create", username, store.ResultError, err.Error())
		s.renderUsers(w, r, http.StatusBadRequest, "",
			"Das Konto konnte nicht angelegt werden — vermutlich ist der Name bereits vergeben.")
		return
	}
	// Im Audit-Log steht, dass ein Einmalpasswort vergeben wurde — nie das
	// Passwort selbst.
	s.audit(r, "user.create", username, store.ResultOK, "Rolle "+role+", Einmalpasswort vergeben")

	s.renderPage(w, r, http.StatusOK, "reset",
		s.base(r, "Zugang angelegt", "users").with(resetPage{
			Username: username,
			Password: password,
			Created:  true,
		}))
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

	// Dritte Stufe: Der Anmeldename muss getippt werden. Ein Konto zu löschen
	// ist unumkehrbar, und in einer Liste von Zeilen mit gleich aussehenden
	// Knöpfen trifft man leicht die falsche.
	if !s.bestaetigt(w, r, bestaetigung{
		Titel: "Panel-Zugang löschen",
		Frage: "Konto " + target.Username + " (" + target.Role + ") endgültig löschen?",
		Punkte: []string{
			"Offene Sitzungen dieses Kontos werden beendet.",
			"Passkeys, zweiter Faktor und Wiederherstellungscodes gehen mit.",
			"Wer nur vorübergehend keinen Zugang haben soll, wird gesperrt statt gelöscht — das lässt sich zurücknehmen.",
		},
		Knopf:   "endgültig löschen",
		Tippen:  target.Username,
		Abbruch: "/users",
	}) {
		return
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
