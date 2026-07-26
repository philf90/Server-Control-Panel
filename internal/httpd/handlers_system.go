package httpd

import (
	"context"
	"fmt"
	"net/http"
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

	if err := s.ops.ServiceAction(r.Context(), unit, action); err != nil {
		s.audit(r, "service."+string(action), unit, store.ResultError, err.Error())
		s.renderServices(w, r, http.StatusBadRequest, "", err.Error())
		return
	}
	s.audit(r, "service."+string(action), unit, store.ResultOK, "")
	s.renderServices(w, r, http.StatusOK, fmt.Sprintf("%s: %s ausgeführt.", unit, action), "")
}

// ----------------------------------------------------------------- Pakete ---

const jobPackages = "packages"

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

func (s *Server) handlePackageRefresh(w http.ResponseWriter, r *http.Request) {
	if err := s.ops.PackageRefresh(r.Context()); err != nil {
		s.audit(r, "package.refresh", "", store.ResultError, err.Error())
		s.renderPackages(w, r, http.StatusBadGateway, "", "Paketlisten konnten nicht aktualisiert werden: "+err.Error())
		return
	}
	s.audit(r, "package.refresh", "", store.ResultOK, "")
	s.renderPackages(w, r, http.StatusOK, "Paketlisten aktualisiert.", "")
}

func (s *Server) handlePackageUpgrade(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	opts := privops.UpgradeOptions{OnlySecurity: r.PostFormValue("scope") == "security"}
	if name := strings.TrimSpace(r.PostFormValue("package")); name != "" {
		opts.Packages = []string{name}
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

	http.Redirect(w, r, "/packages", http.StatusSeeOther)
}

// handlePackageEvents streamt die Ausgabe des laufenden Paketvorgangs.
func (s *Server) handlePackageEvents(w http.ResponseWriter, r *http.Request) {
	j := s.jobs.get(jobPackages)
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
	page := s.base(r, "Firewall", "firewall").with(firewallPage{
		State:            state,
		Pending:          pending,
		RemainingSeconds: int(remaining.Seconds()),
	})
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

	if err := s.ops.FirewallApply(ctx, rules); err != nil {
		s.audit(r, "firewall.apply", "", store.ResultError, err.Error())
		s.renderFirewall(w, r, http.StatusBadRequest, "", err.Error())
		return
	}
	s.audit(r, "firewall.apply", "", store.ResultOK,
		fmt.Sprintf("%d Regeln, Bestätigung ausstehend", len(rules)))

	// Auf Probe: ohne Bestätigung wird der vorherige Stand wiederhergestellt.
	s.fwGuard.arm(before.Rules, func(ctx context.Context, previous []privops.FirewallRule) error {
		s.log.Warn("Firewall-Änderung nicht bestätigt — Rückbau läuft")
		err := s.ops.FirewallApply(ctx, previous)

		result, detail := store.ResultOK, "automatisch zurückgerollt (keine Bestätigung)"
		if err != nil {
			result, detail = store.ResultError, "Rückbau fehlgeschlagen: "+err.Error()
			s.log.Error("firewall-rückbau", "err", err)
		}
		if auditErr := s.db.AppendAudit(context.Background(), store.AuditEntry{
			At: time.Now(), Actor: "system", Action: "firewall.revert",
			Result: result, IP: "-", Detail: detail,
		}); auditErr != nil {
			s.log.Error("audit-eintrag", "err", auditErr)
		}
		return err
	})

	s.renderFirewall(w, r, http.StatusOK,
		"Die Regeln gelten auf Probe. Ohne Bestätigung innerhalb von 60 Sekunden "+
			"wird der vorherige Stand wiederhergestellt.", "")
}

func (s *Server) handleFirewallConfirm(w http.ResponseWriter, r *http.Request) {
	if !s.fwGuard.confirm() {
		s.renderFirewall(w, r, http.StatusBadRequest, "", "Es steht keine Bestätigung aus.")
		return
	}
	s.audit(r, "firewall.confirm", "", store.ResultOK, "")
	s.renderFirewall(w, r, http.StatusOK, "Die Regeln sind bestätigt und bleiben bestehen.", "")
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
		Name:       strings.TrimSpace(r.PostFormValue("name")),
		Comment:    strings.TrimSpace(r.PostFormValue("comment")),
		Shell:      strings.TrimSpace(r.PostFormValue("shell")),
		CreateHome: r.PostFormValue("create_home") == "1",
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
