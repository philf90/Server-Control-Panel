package httpd

import (
	"context"
	"fmt"
	"net/http"
	"net/url"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// ---------------------------------------------------------------- Dienste ---

func (s *Server) handleServices(w http.ResponseWriter, r *http.Request) {
	s.renderServices(w, r, http.StatusOK, "", "")
}

func (s *Server) renderServices(w http.ResponseWriter, r *http.Request, status int, flash, errMsg string) {
	filter := privops.ServiceFilter{
		Search:     r.URL.Query().Get("q"),
		OnlyFailed: r.URL.Query().Get("state") == "failed",
		OnlyActive: r.URL.Query().Get("state") == "active",
	}

	services, err := s.ops.Services(r.Context(), filter)
	if err != nil {
		s.log.Error("dienste lesen", "err", err)
		if errMsg == "" {
			errMsg = "Die Dienstliste ist nicht verfügbar: " + err.Error()
		}
	}

	failed := 0
	for _, svc := range services {
		if svc.Failed() {
			failed++
		}
	}

	page := s.base(r, "Dienste", "services").with(servicesPage{
		Services: services,
		Filter:   filter,
		Failed:   failed,
		State:    r.URL.Query().Get("state"),
	})
	if flash != "" {
		page = page.withFlash(flash)
	}
	if errMsg != "" {
		page = page.withError(errMsg)
	}
	s.renderPage(w, r, status, "services", page)
}

func (s *Server) handleServiceDetail(w http.ResponseWriter, r *http.Request) {
	unit := r.PathValue("unit")

	detail, err := s.ops.Service(r.Context(), unit)
	if err != nil {
		s.renderError(w, r, http.StatusNotFound, "Die Unit ist nicht verfügbar: "+err.Error())
		return
	}
	s.renderPage(w, r, http.StatusOK, "service",
		s.base(r, detail.Unit, "services").with(serviceDetailPage{Detail: detail}))
}

func (s *Server) handleServiceAction(w http.ResponseWriter, r *http.Request) {
	unit := r.PathValue("unit")
	action := privops.ServiceAction(r.PostFormValue("action"))

	// Nur das Stoppen fragt zurück. Starten und Neustarten sind umkehrbar — ein
	// Dialog davor erzieht zum Wegklicken und entwertet die Rückfrage dort, wo
	// sie zählt. Die Prüfung steht im Handler und nicht in der Liste: Die
	// Detailseite eines Dienstes hat denselben Knopf, und sie hatte nie eine
	// Rückfrage.
	if action == "stop" {
		if !s.bestaetigt(w, r, bestaetigung{
			Titel: "Dienst stoppen",
			Frage: unit + " stoppen?",
			Punkte: []string{
				"Was der Dienst bereitstellt, ist danach nicht mehr erreichbar.",
				"Der Autostart bleibt unberührt: Nach einem Neustart des Servers läuft er wieder.",
			},
			Knopf:   "stoppen",
			Abbruch: "/alt/services/" + unit,
			Felder:  []bestaetigungFeld{{Name: "action", Wert: "stop"}},
		}) {
			return
		}
	}

	if err := s.ops.ServiceAction(r.Context(), unit, action); err != nil {
		s.audit(r, "service."+string(action), unit, store.ResultError, err.Error())
		s.renderServices(w, r, http.StatusBadRequest, "", err.Error())
		return
	}
	s.audit(r, "service."+string(action), unit, store.ResultOK, "")
	s.renderServices(w, r, http.StatusOK, fmt.Sprintf("%s: %s ausgeführt.", unit, action), "")
}

// ----------------------------------------------------------------- Pakete ---

const (
	jobPackages        = "packages"
	jobFirewallInstall = "firewall-install"
)

func (s *Server) handlePackages(w http.ResponseWriter, r *http.Request) {
	s.renderPackages(w, r, http.StatusOK, "", "")
}

func (s *Server) renderPackages(w http.ResponseWriter, r *http.Request, status int, flash, errMsg string) {
	ctx := r.Context()

	packages, err := s.ops.PackageUpgradable(ctx)
	if err != nil {
		s.log.Error("pakete lesen", "err", err)
		if errMsg == "" {
			errMsg = "Die Paketliste ist nicht verfügbar: " + err.Error()
		}
	}
	reboot, err := s.ops.RebootRequired(ctx)
	if err != nil {
		s.log.Warn("reboot-required lesen", "err", err)
	}

	security := 0
	for _, p := range packages {
		if p.Security {
			security++
		}
	}

	content := packagesPage{
		Packages: packages,
		Security: security,
		Reboot:   reboot,
	}
	if j := s.jobs.get(jobPackages); j != nil {
		lines, done, jobErr := j.snapshot()
		content.JobLines = lines
		content.JobRunning = !done
		content.JobDone = done
		content.JobNote = j.noteOf()
		if jobErr != nil {
			content.JobError = jobErr.Error()
		}
	}

	page := s.base(r, "Pakete", "packages").with(content)
	if flash != "" {
		page = page.withFlash(flash)
	}
	if errMsg != "" {
		page = page.withError(errMsg)
	}
	s.renderPage(w, r, status, "packages", page)
}

// handlePackageRefresh holt die Paketlisten neu — als Vorgang mit Live-Ausgabe.
//
// Bis hierher lief das im Seitenaufruf und ohne Ausgabe: Die zwanzig Zeilen von
// apt-get update wurden gesammelt und verworfen, übrig blieb im Fehlerfall die
// erste stderr-Zeile. Wer wissen wollte, welche Quelle klemmt, musste sich
// über SSH anmelden.
//
// Derselbe Vorgangsschlüssel wie das Einspielen: Zwei apt-Läufe blockieren sich
// an der dpkg-Sperre, das soll die Oberfläche verhindern und nicht ausprobieren.
func (s *Server) handlePackageRefresh(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	j, started := s.jobs.start(jobPackages, user.Username)
	if !started {
		s.renderPackages(w, r, http.StatusConflict, "", "Es läuft bereits ein Paketvorgang.")
		return
	}
	s.audit(r, "package.refresh", "", store.ResultOK, "gestartet")

	// Eigener Kontext, wie beim Einspielen: Der Vorgang soll nicht daran
	// hängen, ob der Tab offen bleibt. Die Frist liegt über der des Kommandos
	// selbst (5 Minuten), damit sie erst greift, wenn dort etwas festhängt.
	go func() { //nolint:gosec // eigener Kontext ist hier Absicht, siehe Kommentar oben
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
		defer cancel()

		res, err := s.ops.PackageRefresh(ctx, j.append)

		result, detail := store.ResultOK, "abgeschlossen"
		switch {
		case err != nil:
			result, detail = store.ResultError, err.Error()
		case res.Partial():
			// Teilerfolg: Die Listen sind neu, aber nicht vollständig. Das
			// gehört in die Oberfläche und ins Audit-Log — verschwiegen wäre es
			// eine Zusage, die niemand halten kann.
			j.setNote(refreshHinweis(res))
			detail = fmt.Sprintf("%d Quelle(n) nicht erreichbar: %s",
				len(res.Failed), quellenListe(res.Failed))
		}
		j.finish(err)

		if auditErr := s.db.AppendAudit(context.Background(), store.AuditEntry{
			At: time.Now(), Actor: user.Username, Action: "package.refresh",
			Result: result, IP: "-", Detail: detail,
		}); auditErr != nil {
			s.log.Error("audit-eintrag", "err", auditErr)
		}
	}()

	http.Redirect(w, r, "/alt/packages", http.StatusSeeOther)
}

// refreshHinweis formuliert den Teilerfolg für die Oberfläche.
//
// Ohne Zahl der geglückten Quellen: apt zählt Indexdateien, nicht Quellen, und
// eine hergeleitete Zahl wäre eine Behauptung. Genannt wird, was fehlt.
func refreshHinweis(res privops.PackageRefreshResult) string {
	einleitung := "Eine Quelle ließ sich nicht abholen"
	if len(res.Failed) > 1 {
		einleitung = fmt.Sprintf("%d Quellen ließen sich nicht abholen", len(res.Failed))
	}
	return einleitung + ": " + quellenListe(res.Failed) +
		". Die übrigen Listen sind auf dem neuen Stand — die Aufstellung unten " +
		"kann deshalb unvollständig sein. Einzelheiten stehen im Auszug."
}

// quellenListe nennt die gescheiterten Quellen mit ihrem Grund.
func quellenListe(failed []privops.SourceFailure) string {
	teile := make([]string, 0, len(failed))
	for _, f := range failed {
		if f.Reason != "" {
			teile = append(teile, f.Source+" ("+f.Reason+")")
			continue
		}
		teile = append(teile, f.Source)
	}
	return strings.Join(teile, " · ")
}

// handleReboot startet den Server neu. Das ist die einschneidendste Aktion des
// Panels — deshalb nur für Owner (in den Routen) und mit CSRF. Ein Erfolg
// zerreißt die Verbindung binnen Sekunden; die Meldung, die wir noch senden,
// ist eher Höflichkeit als Zusicherung.
func (s *Server) handleReboot(w http.ResponseWriter, r *http.Request) {
	// Dritte Stufe, und das getippte Wort ist der Hostname: Wer zwei Server im
	// Browser offen hat, tippt so nicht den falschen neu. Der Name steht im
	// Seitenkopf und in der Fußzeile — er ist keine Prüfung, sondern ein
	// Innehalten mit Blick auf das richtige Feld.
	host := s.rechnername()
	if !s.bestaetigt(w, r, bestaetigung{
		Titel: "Server neu starten",
		Frage: "Den Server " + host + " jetzt neu starten?",
		Punkte: []string{
			"Alle Dienste werden beendet und danach wieder gestartet.",
			"Diese Sitzung bricht ab und kommt erst nach dem Hochfahren zurück.",
			"Wie lange das dauert, hängt am Server — das Panel kann es nicht sagen.",
		},
		Knopf:         "jetzt neu starten",
		Tippen:        host,
		TippenHinweis: "Zum Bestätigen den Hostnamen eingeben: " + host,
		Abbruch:       "/alt/packages",
	}) {
		return
	}

	if err := s.ops.Reboot(r.Context()); err != nil {
		s.audit(r, "system.reboot", "", store.ResultError, err.Error())
		s.renderPackages(w, r, http.StatusBadGateway, "", "Der Neustart konnte nicht angestoßen werden: "+err.Error())
		return
	}
	s.audit(r, "system.reboot", "", store.ResultOK, "")
	s.renderPackages(w, r, http.StatusOK, "Der Neustart wurde angestoßen. Die Verbindung bricht gleich ab und kommt nach dem Hochfahren zurück.", "")
}

func (s *Server) handlePackageUpgrade(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	opts := privops.UpgradeOptions{OnlySecurity: r.PostFormValue("scope") == "security"}
	if name := strings.TrimSpace(r.PostFormValue("package")); name != "" {
		opts.Packages = []string{name}
	}

	// Ein einzelnes Paket einzuspielen ist ein gezielter Klick in seiner Zeile —
	// dafür braucht es keine Rückfrage. „Alle Updates einspielen" kann Dutzende
	// Pakete und Dienste-Neustarts bedeuten; wie viele, weiß die Seite.
	if len(opts.Packages) == 0 {
		liste, _ := s.ops.PackageUpgradable(r.Context())
		frage := "Alle verfügbaren Updates einspielen?"
		if n := len(liste); n > 0 {
			frage = fmt.Sprintf("Alle %d verfügbaren Updates einspielen?", n)
		}
		if !s.bestaetigt(w, r, bestaetigung{
			Titel: "Updates einspielen",
			Frage: frage,
			Punkte: []string{
				"Betroffene Dienste werden dabei neu gestartet.",
				"Der Vorgang läuft im Hintergrund weiter, auch wenn Sie die Seite verlassen.",
				"Manche Pakete verlangen danach einen Neustart des Servers.",
			},
			Knopf:   "Updates einspielen",
			Abbruch: "/alt/packages",
			Felder:  []bestaetigungFeld{{Name: "scope", Wert: r.PostFormValue("scope")}},
		}) {
			return
		}
	}

	j, started := s.jobs.start(jobPackages, user.Username)
	if !started {
		s.renderPackages(w, r, http.StatusConflict, "", "Es läuft bereits ein Paketvorgang.")
		return
	}

	target := "alle"
	switch {
	case opts.OnlySecurity:
		target = "nur Sicherheitsupdates"
	case len(opts.Packages) > 0:
		target = opts.Packages[0]
	}
	s.audit(r, "package.upgrade", target, store.ResultOK, "gestartet")

	// Eigener Kontext: Der Vorgang überlebt das Ende der Anfrage. Ein
	// abgebrochenes apt-get hinterlässt ein halb konfiguriertes System.
	go func() { //nolint:gosec // eigener Kontext ist hier Absicht, siehe Kommentar oben
		ctx, cancel := context.WithTimeout(context.Background(), 60*time.Minute)
		defer cancel()

		err := s.ops.PackageUpgrade(ctx, opts, j.append)
		j.finish(err)

		result, detail := store.ResultOK, "abgeschlossen"
		if err != nil {
			result, detail = store.ResultError, err.Error()
		}
		if auditErr := s.db.AppendAudit(context.Background(), store.AuditEntry{
			At: time.Now(), Actor: user.Username, Action: "package.upgrade",
			Target: target, Result: result, IP: "-", Detail: detail,
		}); auditErr != nil {
			s.log.Error("audit-eintrag", "err", auditErr)
		}
	}()

	http.Redirect(w, r, "/alt/packages", http.StatusSeeOther)
}

// handlePackageEvents streamt die Ausgabe des laufenden Paketvorgangs.
func (s *Server) handlePackageEvents(w http.ResponseWriter, r *http.Request) {
	s.streamJob(w, r, jobPackages)
}

// handleFirewallEvents streamt die Ausgabe der ufw-Installation.
func (s *Server) handleFirewallEvents(w http.ResponseWriter, r *http.Request) {
	s.streamJob(w, r, jobFirewallInstall)
}

// streamJob schickt den bisherigen und den folgenden Ausstoß eines Vorgangs
// als Server-Sent Events.
func (s *Server) streamJob(w http.ResponseWriter, r *http.Request, kind string) {
	j := s.jobs.get(kind)
	if j == nil {
		http.Error(w, "kein Vorgang", http.StatusNotFound)
		return
	}

	rc := http.NewResponseController(w)
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Accel-Buffering", "no")
	w.WriteHeader(http.StatusOK)
	if err := rc.Flush(); err != nil {
		return
	}

	// Erst den bisherigen Stand, dann die neuen Zeilen — wer später dazukommt,
	// sieht den ganzen Lauf.
	lines, done, jobErr := j.snapshot()
	for _, line := range lines {
		if !writeSSE(w, rc, "output", line) {
			return
		}
	}
	if done {
		writeJobEnd(w, rc, jobErr)
		return
	}

	ch, alreadyDone := j.subscribe()
	if alreadyDone {
		_, _, jobErr := j.snapshot()
		writeJobEnd(w, rc, jobErr)
		return
	}
	defer j.unsubscribe(ch)

	ctx := r.Context()
	for {
		select {
		case <-ctx.Done():
			return
		case line, ok := <-ch:
			if !ok {
				_, _, jobErr := j.snapshot()
				writeJobEnd(w, rc, jobErr)
				return
			}
			if !writeSSE(w, rc, "output", line) {
				return
			}
		}
	}
}

func writeJobEnd(w http.ResponseWriter, rc *http.ResponseController, err error) {
	msg := "ok"
	if err != nil {
		msg = err.Error()
	}
	writeSSE(w, rc, "end", msg)
}

// --------------------------------------------------------------- Firewall ---

func (s *Server) handleFirewall(w http.ResponseWriter, r *http.Request) {
	s.renderFirewall(w, r, http.StatusOK, "", "")
}

func (s *Server) renderFirewall(w http.ResponseWriter, r *http.Request, status int, flash, errMsg string) {
	state, err := s.ops.FirewallState(r.Context())
	if err != nil {
		s.log.Error("firewall lesen", "err", err)
		if errMsg == "" {
			errMsg = "Der Firewall-Zustand ist nicht verfügbar: " + err.Error()
		}
	}

	pending, remaining := s.fwGuard.state()
	content := firewallPage{
		State:            state,
		Pending:          pending,
		PendingSubject:   s.fwGuard.subjectOf(),
		RemainingSeconds: int(remaining.Seconds()),
		PanelPort:        s.cfg.Server.Port,
		PanelPortOpen:    ruleCoversPort(state.Rules, s.cfg.Server.Port),
		OpenPorts:        openPortSummary(state.Rules),
		Rows:             s.firewallRows(r.Context(), state.Rules),
	}
	if j := s.jobs.get(jobFirewallInstall); j != nil {
		lines, done, jobErr := j.snapshot()
		content.JobLines = lines
		content.JobRunning = !done
		content.JobDone = done
		if jobErr != nil {
			content.JobError = jobErr.Error()
		}
	}

	page := s.base(r, "Firewall", "firewall").with(content)
	if flash != "" {
		page = page.withFlash(flash)
	}
	if errMsg != "" {
		page = page.withError(errMsg)
	}
	s.renderPage(w, r, status, "firewall", page)
}

func (s *Server) handleFirewallApply(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()

	before, err := s.ops.FirewallState(ctx)
	if err != nil {
		s.renderFirewall(w, r, http.StatusBadGateway, "", err.Error())
		return
	}

	rules, err := parseRuleForm(r)
	if err != nil {
		s.renderFirewall(w, r, http.StatusBadRequest, "", err.Error())
		return
	}

	// Die Regel für den eigenen Port wird nicht dem Formular überlassen. Im
	// Browser steht sie schreibgeschützt da, aber ein schreibgeschütztes Feld
	// ist eine Bitte, keine Sperre — wer die Anfrage selbst zusammenstellt,
	// lässt sie einfach weg. Hier wird sie ergänzt, falls sie fehlt.
	rules = ensurePanelRule(rules, s.cfg.Server.Port)

	if err := s.ops.FirewallApply(ctx, rules); err != nil {
		s.audit(r, "firewall.apply", "", store.ResultError, err.Error())
		s.renderFirewall(w, r, http.StatusBadRequest, "", err.Error())
		return
	}
	s.audit(r, "firewall.apply", "", store.ResultOK,
		fmt.Sprintf("%d Regeln, Bestätigung ausstehend", len(rules)))

	// Auf Probe: ohne Bestätigung wird der vorherige Stand wiederhergestellt.
	previous := before.Rules
	s.fwGuard.arm("Regelsatz", func(ctx context.Context) error {
		s.log.Warn("Firewall-Änderung nicht bestätigt — Rückbau läuft")
		return s.revertFirewall(ctx, "firewall.revert", s.ops.FirewallApply(ctx, previous))
	})

	s.renderFirewall(w, r, http.StatusOK,
		"Die Regeln gelten auf Probe. Ohne Bestätigung innerhalb von 60 Sekunden "+
			"wird der vorherige Stand wiederhergestellt.", "")
}

// revertFirewall schreibt das Ergebnis einer Rücknahme ins Audit-Log.
//
// Der Rückbau läuft, wenn niemand mehr zusieht — im schlimmsten Fall, weil das
// Panel nicht mehr erreichbar ist. Wenn er dann auch noch scheitert, ist der
// Audit-Eintrag die einzige Spur, die davon übrig bleibt.
func (s *Server) revertFirewall(_ context.Context, action string, err error) error {
	result, detail := store.ResultOK, "automatisch zurückgerollt (keine Bestätigung)"
	if err != nil {
		result, detail = store.ResultError, "Rückbau fehlgeschlagen: "+err.Error()
		s.log.Error("firewall-rückbau", "err", err)
	}
	if auditErr := s.db.AppendAudit(context.Background(), store.AuditEntry{
		At: time.Now(), Actor: "system", Action: action,
		Result: result, IP: "-", Detail: detail,
	}); auditErr != nil {
		s.log.Error("audit-eintrag", "err", auditErr)
	}
	return err
}

// handleFirewallActivate schaltet ufw ein oder aus.
//
// Das Einschalten ist die gefährlichste Aktion, die dieses Panel kennt: ufw
// weist danach alles ab, was nicht ausdrücklich erlaubt ist — auch die
// Verbindung, über die gerade geklickt wurde. Bestehende Verbindungen
// überleben dank Conntrack meist den Moment, der nächste Seitenaufruf aber
// nicht mehr. Deshalb zwei Sicherungen: Ohne freigegebenen Panel-Port wird die
// Aktivierung verweigert, und danach gilt sie auf Probe.
func (s *Server) handleFirewallActivate(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	activate := r.PostFormValue("active") == "1"

	state, err := s.ops.FirewallState(ctx)
	if err != nil {
		s.renderFirewall(w, r, http.StatusBadGateway, "", err.Error())
		return
	}
	if !state.Installed {
		s.renderFirewall(w, r, http.StatusBadRequest, "",
			"ufw ist nicht installiert.")
		return
	}

	if activate {
		if !ruleCoversPort(state.Rules, s.cfg.Server.Port) {
			s.audit(r, "firewall.activate", "", store.ResultError, "Panel-Port nicht freigegeben")
			s.renderFirewall(w, r, http.StatusBadRequest, "", fmt.Sprintf(
				"Für Port %d gibt es keine Regel — das Panel wäre nach dem Einschalten "+
					"nicht mehr erreichbar, auch nicht zum Bestätigen. Legen Sie die Regel "+
					"zuerst an.", s.cfg.Server.Port))
			return
		}
		// Einschalten sperrt aus, wenn eine Regel fehlt — deshalb steht in der
		// Frage, was erreichbar bleibt. Eine dritte Stufe braucht es hier nicht:
		// Die Probezeit von 60 Sekunden nimmt den Fehler von selbst zurück.
		if !s.bestaetigt(w, r, bestaetigung{
			Titel: "ufw einschalten",
			Frage: "ufw einschalten? Erreichbar bleibt danach nur: " + openPortSummary(state.Rules) + ".",
			Punkte: []string{
				"Alles andere wird abgewiesen — auch Zugänge, die gerade offen sind.",
				"Die Regeln gelten zunächst auf Probe: Ohne Bestätigung binnen 60 Sekunden schaltet sich ufw wieder aus.",
				"Bestätigen Sie, solange diese Verbindung noch steht.",
			},
			Knopf:   "ufw einschalten",
			Abbruch: "/alt/firewall",
			Felder:  []bestaetigungFeld{{Name: "active", Wert: "1"}},
		}) {
			return
		}
	} else {
		// Dritte Stufe mit dem Hostnamen: Ausschalten öffnet den Server für
		// jede eingehende Verbindung, und dieser Zustand nimmt sich nicht von
		// selbst zurück.
		host := s.rechnername()
		if !s.bestaetigt(w, r, bestaetigung{
			Titel: "ufw ausschalten",
			Frage: "ufw auf " + host + " ausschalten?",
			Punkte: []string{
				"Der Server nimmt danach jede eingehende Verbindung an — auf allen Ports, von überall.",
				"Der Regelsatz bleibt gespeichert und gilt wieder, sobald ufw eingeschaltet wird.",
				"Anders als beim Einschalten gibt es hier keine Probezeit: Der Zustand bleibt, bis jemand ihn ändert.",
			},
			Knopf:         "ufw ausschalten",
			Tippen:        host,
			TippenHinweis: "Zum Bestätigen den Hostnamen eingeben: " + host,
			Abbruch:       "/alt/firewall",
			Felder:        []bestaetigungFeld{{Name: "active", Wert: "0"}},
		}) {
			return
		}
	}

	if err := s.ops.FirewallSetActive(ctx, activate); err != nil {
		s.audit(r, "firewall.activate", "", store.ResultError, err.Error())
		s.renderFirewall(w, r, http.StatusBadGateway, "", err.Error())
		return
	}

	if !activate {
		// Ausschalten öffnet, es sperrt nicht aus. Keine Probe nötig.
		s.audit(r, "firewall.activate", "", store.ResultOK, "ufw ausgeschaltet")
		s.renderFirewall(w, r, http.StatusOK,
			"ufw ist ausgeschaltet. Der Server nimmt wieder jede eingehende Verbindung an.", "")
		return
	}

	s.audit(r, "firewall.activate", "", store.ResultOK, "ufw eingeschaltet, Bestätigung ausstehend")
	s.fwGuard.arm("Aktivierung", func(ctx context.Context) error {
		s.log.Warn("Firewall-Aktivierung nicht bestätigt — ufw wird wieder ausgeschaltet")
		return s.revertFirewall(ctx, "firewall.revert", s.ops.FirewallSetActive(ctx, false))
	})

	s.renderFirewall(w, r, http.StatusOK, fmt.Sprintf(
		"ufw ist auf Probe eingeschaltet. Erreichbar bleiben nur: %s. Ohne Bestätigung "+
			"innerhalb von 60 Sekunden wird ufw wieder ausgeschaltet.",
		openPortSummary(state.Rules)), "")
}

// handleFirewallInstall installiert ufw als Hintergrundvorgang.
func (s *Server) handleFirewallInstall(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	j, started := s.jobs.start(jobFirewallInstall, user.Username)
	if !started {
		s.renderFirewall(w, r, http.StatusConflict, "", "Die Installation läuft bereits.")
		return
	}
	s.audit(r, "firewall.install", "ufw", store.ResultOK, "gestartet")

	// Wie beim Paket-Update: eigener Kontext, damit ein abgebrochener
	// Seitenaufruf kein halb konfiguriertes dpkg hinterlässt.
	go func() { //nolint:gosec // eigener Kontext ist hier Absicht
		ctx, cancel := context.WithTimeout(context.Background(), 15*time.Minute)
		defer cancel()

		err := s.ops.FirewallInstall(ctx, j.append)
		j.finish(err)

		result, detail := store.ResultOK, "abgeschlossen"
		if err != nil {
			result, detail = store.ResultError, err.Error()
		}
		if auditErr := s.db.AppendAudit(context.Background(), store.AuditEntry{
			At: time.Now(), Actor: user.Username, Action: "firewall.install",
			Target: "ufw", Result: result, IP: "-", Detail: detail,
		}); auditErr != nil {
			s.log.Error("audit-eintrag", "err", auditErr)
		}
	}()

	http.Redirect(w, r, "/alt/firewall", http.StatusSeeOther)
}

func (s *Server) handleFirewallConfirm(w http.ResponseWriter, r *http.Request) {
	if !s.fwGuard.confirm() {
		s.renderFirewall(w, r, http.StatusBadRequest, "", "Es steht keine Bestätigung aus.")
		return
	}
	s.audit(r, "firewall.confirm", "", store.ResultOK, "")
	s.renderFirewall(w, r, http.StatusOK, "Die Änderung ist bestätigt und bleibt bestehen.", "")
}

// ruleCoversPort sagt, ob der Regelsatz einen Port von überall her freigibt.
//
// Eine auf eine Quelle eingeschränkte Regel zählt hier bewusst nicht: Sie mag
// den eigenen Zugang decken, aber das lässt sich von hier aus nicht
// feststellen — und eine Sicherung, die im Zweifel "passt schon" sagt, ist
// keine.
func ruleCoversPort(rules []privops.FirewallRule, port int) bool {
	for _, r := range rules {
		if r.Port == port && r.Source == "" {
			return true
		}
	}
	return false
}

// openPortSummary listet auf, was nach dem Einschalten erreichbar bleibt.
func openPortSummary(rules []privops.FirewallRule) string {
	if len(rules) == 0 {
		return "keine Zugänge"
	}
	seen := make(map[string]struct{}, len(rules))
	parts := make([]string, 0, len(rules))
	for _, r := range rules {
		label := fmt.Sprintf("%d/%s", r.Port, r.Protocol)
		if r.Source != "" {
			label += " von " + r.Source
		}
		if _, dup := seen[label]; dup {
			continue
		}
		seen[label] = struct{}{}
		parts = append(parts, label)
	}
	sort.Strings(parts)
	return strings.Join(parts, ", ")
}

// parseRuleForm liest den Regelsatz aus dem Formular.
//
// Übergeben wird immer die vollständige gewünschte Liste, nicht eine einzelne
// Änderung. Damit ist der Zustand nach dem Absenden eindeutig, auch wenn zwei
// Personen gleichzeitig arbeiten.
func parseRuleForm(r *http.Request) ([]privops.FirewallRule, error) {
	ports := r.PostForm["port"]
	protocols := r.PostForm["protocol"]
	sources := r.PostForm["source"]
	comments := r.PostForm["comment"]

	rules := make([]privops.FirewallRule, 0, len(ports))
	for i, raw := range ports {
		raw = strings.TrimSpace(raw)
		if raw == "" {
			continue
		}
		port, err := strconv.Atoi(raw)
		if err != nil {
			return nil, fmt.Errorf("%q ist keine Portnummer", raw)
		}
		rule := privops.FirewallRule{Port: port, Protocol: "tcp"}
		if i < len(protocols) {
			rule.Protocol = protocols[i]
		}
		if i < len(sources) {
			rule.Source = strings.TrimSpace(sources[i])
		}
		if i < len(comments) {
			rule.Comment = strings.TrimSpace(comments[i])
		}
		if err := privops.ValidateRule(rule); err != nil {
			return nil, err
		}
		rules = append(rules, rule)
	}
	return rules, nil
}

// ------------------------------------------------------------ Systembenutzer ---

func (s *Server) handleSystemUsers(w http.ResponseWriter, r *http.Request) {
	s.renderSystemUsers(w, r, http.StatusOK, "", "")
}

func (s *Server) renderSystemUsers(w http.ResponseWriter, r *http.Request, status int, flash, errMsg string) {
	ctx := r.Context()

	users, err := s.ops.SystemUsers(ctx)
	if err != nil {
		s.log.Error("systembenutzer lesen", "err", err)
		if errMsg == "" {
			errMsg = "Die Benutzerliste ist nicht verfügbar: " + err.Error()
		}
	}

	content := sysUsersPage{Users: users, Selected: r.URL.Query().Get("user")}
	if content.Selected != "" {
		keys, err := s.ops.AuthorizedKeys(ctx, content.Selected)
		if err != nil && errMsg == "" {
			errMsg = "Die SSH-Schlüssel sind nicht lesbar: " + err.Error()
		}
		content.Keys = keys
	}

	page := s.base(r, "Systembenutzer", "sysusers").with(content)
	if flash != "" {
		page = page.withFlash(flash)
	}
	if errMsg != "" {
		page = page.withError(errMsg)
	}
	s.renderPage(w, r, status, "sysusers", page)
}

func (s *Server) handleSystemUserCreate(w http.ResponseWriter, r *http.Request) {
	spec := privops.SystemUserSpec{
		Name:    strings.TrimSpace(r.PostFormValue("name")),
		Comment: strings.TrimSpace(r.PostFormValue("comment")),
		Shell:   strings.TrimSpace(r.PostFormValue("shell")),
		// Das Home-Verzeichnis wird immer angelegt.
		//
		// Vorher hing das an einem Formularfeld "create_home", das es nie gab:
		// useradd lief also stets mit --no-create-home, während das Formular
		// „Das Home-Verzeichnis wird angelegt" versprach. Ohne Home gibt es kein
		// ~/.ssh, das dem Konto gehört — und damit keine Anmeldung per Schlüssel,
		// den einzigen Weg, den diese Konten haben (sshd besteht auf einem Home,
		// das nur dem Konto selbst zugänglich ist).
		CreateHome: true,
		SSHKey:     strings.TrimSpace(r.PostFormValue("ssh_key")),
	}
	if groups := strings.TrimSpace(r.PostFormValue("groups")); groups != "" {
		for _, g := range strings.Split(groups, ",") {
			if g = strings.TrimSpace(g); g != "" {
				spec.Groups = append(spec.Groups, g)
			}
		}
	}

	if err := s.ops.SystemUserCreate(r.Context(), spec); err != nil {
		s.audit(r, "sysuser.create", spec.Name, store.ResultError, err.Error())
		s.renderSystemUsers(w, r, http.StatusBadRequest, "", err.Error())
		return
	}
	s.audit(r, "sysuser.create", spec.Name, store.ResultOK, "")
	s.renderSystemUsers(w, r, http.StatusOK,
		"Konto "+spec.Name+" angelegt. Es hat kein Passwort — die Anmeldung läuft über den SSH-Schlüssel.", "")
}

func (s *Server) handleSystemUserLock(w http.ResponseWriter, r *http.Request) {
	name := r.PathValue("name")
	lock := r.PostFormValue("locked") == "1"

	if err := s.ops.SystemUserSetLocked(r.Context(), name, lock); err != nil {
		s.audit(r, "sysuser.lock", name, store.ResultError, err.Error())
		s.renderSystemUsers(w, r, http.StatusBadRequest, "", err.Error())
		return
	}

	action, flash := "sysuser.unlock", "Konto "+name+" ist entsperrt."
	if lock {
		action, flash = "sysuser.lock", "Konto "+name+" ist gesperrt."
	}
	s.audit(r, action, name, store.ResultOK, "")
	s.renderSystemUsers(w, r, http.StatusOK, flash, "")
}

func (s *Server) handleSystemUserDelete(w http.ResponseWriter, r *http.Request) {
	name := r.PathValue("name")
	removeHome := r.PostFormValue("remove_home") == "1"

	folgen := []string{
		"Der Zugang über SSH mit diesem Konto ist danach nicht mehr möglich.",
		"Dateien, die dem Konto gehören, bleiben liegen — sie tragen danach nur noch eine Zahl als Eigentümer.",
	}
	if removeHome {
		folgen = append(folgen, "Das Home-Verzeichnis wird mit gelöscht.")
	} else {
		folgen = append(folgen, "Das Home-Verzeichnis bleibt erhalten.")
	}
	if !s.bestaetigt(w, r, bestaetigung{
		Titel:   "Systemkonto löschen",
		Frage:   "Das Systemkonto " + name + " endgültig löschen?",
		Punkte:  folgen,
		Knopf:   "endgültig löschen",
		Tippen:  name,
		Abbruch: "/alt/system-users",
		Felder:  []bestaetigungFeld{{Name: "remove_home", Wert: r.PostFormValue("remove_home")}},
	}) {
		return
	}

	if err := s.ops.SystemUserDelete(r.Context(), name, removeHome); err != nil {
		s.audit(r, "sysuser.delete", name, store.ResultError, err.Error())
		s.renderSystemUsers(w, r, http.StatusBadRequest, "", err.Error())
		return
	}
	detail := "Home behalten"
	if removeHome {
		detail = "Home entfernt"
	}
	s.audit(r, "sysuser.delete", name, store.ResultOK, detail)
	s.renderSystemUsers(w, r, http.StatusOK, "Konto "+name+" gelöscht ("+detail+").", "")
}

func (s *Server) handleSSHKeyAdd(w http.ResponseWriter, r *http.Request) {
	name := r.PathValue("name")
	key := strings.TrimSpace(r.PostFormValue("key"))

	if err := s.ops.AuthorizedKeyAdd(r.Context(), name, key); err != nil {
		s.audit(r, "sshkey.add", name, store.ResultError, err.Error())
		s.redirectSystemUser(w, r, name, "", err.Error())
		return
	}
	s.audit(r, "sshkey.add", name, store.ResultOK, "")
	s.redirectSystemUser(w, r, name, "Schlüssel hinterlegt.", "")
}

func (s *Server) handleSSHKeyRemove(w http.ResponseWriter, r *http.Request) {
	name := r.PathValue("name")
	fingerprint := r.PostFormValue("fingerprint")

	// Der Fingerprint steht in der Frage: Wer drei Schlüssel hinterlegt hat,
	// entscheidet sonst blind. Er ist zugleich das einzige Feld, das beim zweiten
	// POST wieder mitmuss — der Kontoname steht im Pfad.
	if !s.bestaetigt(w, r, bestaetigung{
		Titel: "SSH-Schlüssel entfernen",
		Frage: "Diesen Schlüssel von " + name + " entfernen?",
		Punkte: []string{
			fingerprint,
			"Wer nur diesen Schlüssel hat, kommt danach über SSH nicht mehr auf den Server.",
		},
		Knopf:   "entfernen",
		Abbruch: "/alt/system-users?user=" + url.QueryEscape(name),
		Felder:  []bestaetigungFeld{{Name: "fingerprint", Wert: fingerprint}},
	}) {
		return
	}

	if err := s.ops.AuthorizedKeyRemove(r.Context(), name, fingerprint); err != nil {
		s.audit(r, "sshkey.remove", name, store.ResultError, err.Error())
		s.redirectSystemUser(w, r, name, "", err.Error())
		return
	}
	s.audit(r, "sshkey.remove", name, store.ResultOK, fingerprint)
	s.redirectSystemUser(w, r, name, "Schlüssel entfernt.", "")
}

// redirectSystemUser rendert die Benutzerseite mit dem gewählten Konto.
func (s *Server) redirectSystemUser(w http.ResponseWriter, r *http.Request, name, flash, errMsg string) {
	q := r.URL.Query()
	q.Set("user", name)
	r.URL.RawQuery = q.Encode()

	status := http.StatusOK
	if errMsg != "" {
		status = http.StatusBadRequest
	}
	s.renderSystemUsers(w, r, status, flash, errMsg)
}

// ------------------------------------------------------------------- Logs ---

func (s *Server) handleLogs(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()

	query := privops.LogQuery{
		Unit:     q.Get("unit"),
		Priority: -1,
		Since:    q.Get("since"),
		Search:   q.Get("q"),
		Limit:    200,
	}
	if raw := q.Get("priority"); raw != "" {
		if p, err := strconv.Atoi(raw); err == nil {
			query.Priority = p
		}
	}
	if raw := q.Get("limit"); raw != "" {
		if n, err := strconv.Atoi(raw); err == nil {
			query.Limit = n
		}
	}

	content := logsPage{Query: query}
	var errMsg string

	entries, err := s.ops.Logs(r.Context(), query)
	if err != nil {
		s.log.Error("logs lesen", "err", err)
		errMsg = "Das Journal ist nicht verfügbar: " + err.Error()
	}
	content.Entries = entries

	if units, err := s.ops.LogUnits(r.Context()); err == nil {
		content.Units = units
	}

	page := s.base(r, "Logs", "logs").with(content)
	if errMsg != "" {
		page = page.withError(errMsg)
	}
	s.renderPage(w, r, http.StatusOK, "logs", page)
}

// ensurePanelRule sorgt dafür, dass der Port des Panels freigegeben bleibt.
//
// Ohne diese Regel sperrt das nächste "ufw enable" den Bedienenden aus, und
// zwar aus der Seite heraus, auf der er das zurücknehmen könnte. Eine bereits
// vorhandene Regel für diesen Port bleibt unangetastet — auch eine auf eine
// Quelle eingeschränkte: Wer sein Panel bewusst nur aus dem eigenen Netz
// erreichbar macht, soll das dürfen.
func ensurePanelRule(rules []privops.FirewallRule, panelPort int) []privops.FirewallRule {
	for _, r := range rules {
		if r.Port == panelPort {
			return rules
		}
	}
	return append(rules, privops.FirewallRule{
		Port: panelPort, Protocol: "tcp", Comment: panelRuleComment,
	})
}

// panelRuleComment kennzeichnet die Regel, die das Panel selbst braucht.
const panelRuleComment = "Asylum-Panel"

// firewallRows baut die Zeilen des Formulars: die Regel des Panels zuerst und
// festgesetzt, danach die bestehenden, zuletzt Vorschläge für SSH.
//
// Der Vorschlag ist kein Selbstzweck. Wer ufw ohne SSH-Regel einschaltet,
// verliert den zweiten Weg auf den Server — und merkt es erst, wenn er ihn
// braucht. Der Port kommt aus sshd_config, nicht aus der Annahme "22".
func (s *Server) firewallRows(ctx context.Context, rules []privops.FirewallRule) []firewallRow {
	panelPort := s.cfg.Server.Port

	rows := make([]firewallRow, 0, len(rules)+2)
	rest := make([]privops.FirewallRule, 0, len(rules))

	panelRule := privops.FirewallRule{Port: panelPort, Protocol: "tcp", Comment: panelRuleComment}
	for _, r := range rules {
		if r.Port == panelPort && r.Protocol == "tcp" {
			panelRule = r
			continue
		}
		rest = append(rest, r)
	}
	rows = append(rows, firewallRow{
		Rule:   panelRule,
		Locked: true,
		Note:   "Über diesen Port erreichen Sie das Panel. Die Regel lässt sich nicht entfernen.",
	})

	belegt := func(port int) bool {
		for _, r := range rest {
			if r.Port == port {
				return true
			}
		}
		return false
	}

	for _, r := range rest {
		rows = append(rows, firewallRow{Rule: r})
	}

	for _, port := range s.ops.SSHPorts(ctx) {
		if port == panelPort || belegt(port) {
			continue
		}
		rows = append(rows, firewallRow{
			Rule:     privops.FirewallRule{Port: port, Protocol: "tcp", Comment: "SSH"},
			Proposed: true,
			Note:     "Vorschlag: Auf diesem Port lauscht sshd laut Konfiguration. Ohne die Regel wäre SSH nach dem Einschalten zu.",
		})
	}
	return rows
}
