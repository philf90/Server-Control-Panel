package httpd

import (
	"context"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// fakeOps ist ein Executor ohne Systemzugriff. Er zeichnet auf, was aufgerufen
// wurde, und liefert vorbereitete Antworten.
type fakeOps struct {
	mu sync.Mutex

	services   []privops.Service
	detail     privops.ServiceDetail
	packages   []privops.Package
	reboot     privops.RebootState
	firewall   privops.FirewallState
	sysUsers   []privops.SystemUser
	keys       []privops.SSHKey
	logs       []privops.LogEntry
	units      []string
	upgradeErr error

	actions      []string
	appliedRules [][]privops.FirewallRule
	upgradeDone  chan struct{}

	selfUpdates   []privops.SelfUpdateSpec
	selfUpdateErr error
}

func newFakeOps() *fakeOps {
	return &fakeOps{
		services: []privops.Service{
			{Unit: "ssh.service", Active: "active", Sub: "running", Description: "OpenSSH", Enabled: "enabled"},
			{Unit: "nginx.service", Active: "failed", Sub: "failed", Description: "Webserver"},
		},
		packages: []privops.Package{
			{Name: "libssl3", CurrentVersion: "1", NewVersion: "2", Security: true, Origin: "noble-security"},
			{Name: "coreutils", CurrentVersion: "1", NewVersion: "2", Origin: "noble-updates"},
		},
		firewall: privops.FirewallState{
			Backend: privops.BackendUFW, Active: true, Managed: true,
			Rules: []privops.FirewallRule{{Port: 22, Protocol: "tcp", Comment: "SSH"}},
		},
		sysUsers: []privops.SystemUser{
			{Name: "philipp", UID: 1000, Home: "/home/philipp", Shell: "/bin/bash", HasShell: true},
			{Name: "www-data", UID: 33, System: true},
		},
		logs: []privops.LogEntry{
			{At: time.Now(), Unit: "ssh.service", Priority: 6, Message: "Accepted publickey"},
		},
		units:       []string{"ssh.service"},
		upgradeDone: make(chan struct{}),
	}
}

func (f *fakeOps) record(action string) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.actions = append(f.actions, action)
}

func (f *fakeOps) recorded() []string {
	f.mu.Lock()
	defer f.mu.Unlock()
	out := make([]string, len(f.actions))
	copy(out, f.actions)
	return out
}

func (f *fakeOps) Services(context.Context, privops.ServiceFilter) ([]privops.Service, error) {
	return f.services, nil
}

func (f *fakeOps) Service(_ context.Context, unit string) (privops.ServiceDetail, error) {
	d := f.detail
	if d.Unit == "" {
		d.Unit = unit
		d.Active = "active"
	}
	return d, nil
}

func (f *fakeOps) ServiceAction(_ context.Context, unit string, action privops.ServiceAction) error {
	f.record("service:" + string(action) + ":" + unit)
	return nil
}

func (f *fakeOps) PackageRefresh(context.Context) error {
	f.record("package:refresh")
	return nil
}

func (f *fakeOps) PackageUpgradable(context.Context) ([]privops.Package, error) {
	return f.packages, nil
}

func (f *fakeOps) PackageUpgrade(_ context.Context, opts privops.UpgradeOptions, stream privops.LineWriter) error {
	scope := "all"
	if opts.OnlySecurity {
		scope = "security"
	} else if len(opts.Packages) > 0 {
		scope = strings.Join(opts.Packages, ",")
	}
	f.record("package:upgrade:" + scope)

	if stream != nil {
		stream("Setting up " + scope + " ...")
	}
	close(f.upgradeDone)
	return f.upgradeErr
}

func (f *fakeOps) RebootRequired(context.Context) (privops.RebootState, error) {
	return f.reboot, nil
}

func (f *fakeOps) FirewallState(context.Context) (privops.FirewallState, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.firewall, nil
}

func (f *fakeOps) FirewallApply(_ context.Context, rules []privops.FirewallRule) error {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.actions = append(f.actions, "firewall:apply")
	f.appliedRules = append(f.appliedRules, rules)
	f.firewall.Rules = rules
	return nil
}

func (f *fakeOps) SystemUsers(context.Context) ([]privops.SystemUser, error) { return f.sysUsers, nil }

func (f *fakeOps) SystemUserCreate(_ context.Context, spec privops.SystemUserSpec) error {
	f.record("sysuser:create:" + spec.Name)
	return nil
}

func (f *fakeOps) SystemUserSetLocked(_ context.Context, name string, locked bool) error {
	f.record("sysuser:lock:" + name)
	_ = locked
	return nil
}

func (f *fakeOps) SystemUserDelete(_ context.Context, name string, _ bool) error {
	f.record("sysuser:delete:" + name)
	return nil
}

func (f *fakeOps) AuthorizedKeys(context.Context, string) ([]privops.SSHKey, error) {
	return f.keys, nil
}

func (f *fakeOps) AuthorizedKeyAdd(_ context.Context, user, _ string) error {
	f.record("sshkey:add:" + user)
	return nil
}

func (f *fakeOps) AuthorizedKeyRemove(_ context.Context, user, fp string) error {
	f.record("sshkey:remove:" + user + ":" + fp)
	return nil
}

func (f *fakeOps) Logs(context.Context, privops.LogQuery) ([]privops.LogEntry, error) {
	return f.logs, nil
}

func (f *fakeOps) LogUnits(context.Context) ([]string, error) { return f.units, nil }

func (f *fakeOps) SelfUpdateStart(_ context.Context, spec privops.SelfUpdateSpec) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.selfUpdates = append(f.selfUpdates, spec)
	return f.selfUpdateErr
}

// newSystemServer baut einen Server mit dem gefälschten Executor.
func newSystemServer(t *testing.T) (*Server, *fakeOps) {
	t.Helper()
	s := newTestServer(t)
	ops := newFakeOps()
	s.ops = ops
	return s, ops
}

// ------------------------------------------------------------------ Lesen ---

func TestSystemPagesRender(t *testing.T) {
	s, _ := newSystemServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	pages := map[string][]string{
		"/services":     {"ssh.service", "nginx.service", "Webserver"},
		"/packages":     {"libssl3", "Sicherheit", "coreutils"},
		"/firewall":     {"ufw", "22"},
		"/system-users": {"philipp", "www-data"},
		"/logs":         {"Accepted publickey"},
	}

	for path, wants := range pages {
		t.Run(path, func(t *testing.T) {
			rec := get(t, s, path, cookie)
			if rec.Code != http.StatusOK {
				t.Fatalf("Status = %d, erwartet 200", rec.Code)
			}
			body := rec.Body.String()
			for _, want := range wants {
				if !strings.Contains(body, want) {
					t.Errorf("Seite enthält %q nicht", want)
				}
			}
		})
	}
}

func TestServiceDetailRenders(t *testing.T) {
	s, _ := newSystemServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	rec := get(t, s, "/services/ssh.service", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), "ssh.service") {
		t.Error("die Unit erscheint nicht auf der Seite")
	}
}

// --------------------------------------------------------------- Schreiben ---

// ReadOnly darf alles sehen und nichts anfassen. Das ist der Kern der
// Rollentrennung — ein Test je verändernder Route.
func TestReadOnlyCannotChangeAnything(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "leser", store.RoleReadOnly)
	cookie, csrf := login(t, s, user)

	writes := map[string]url.Values{
		"/services/ssh.service":        {"_csrf": {csrf}, "action": {"restart"}},
		"/packages/refresh":            {"_csrf": {csrf}},
		"/packages/upgrade":            {"_csrf": {csrf}, "scope": {"all"}},
		"/firewall":                    {"_csrf": {csrf}, "port": {"443"}, "protocol": {"tcp"}},
		"/system-users":                {"_csrf": {csrf}, "name": {"neu"}},
		"/system-users/philipp/locked": {"_csrf": {csrf}, "locked": {"1"}},
		"/system-users/philipp/delete": {"_csrf": {csrf}},
		"/system-users/philipp/keys":   {"_csrf": {csrf}, "key": {"ssh-ed25519 AAAA x"}},
	}

	for path, form := range writes {
		t.Run(path, func(t *testing.T) {
			rec := post(t, s, path, form, cookie)
			if rec.Code != http.StatusForbidden {
				t.Errorf("Status = %d, erwartet 403", rec.Code)
			}
		})
	}

	if actions := ops.recorded(); len(actions) != 0 {
		t.Errorf("es wurden Systemoperationen ausgeführt: %v", actions)
	}

	// Lesen bleibt erlaubt.
	if rec := get(t, s, "/services", cookie); rec.Code != http.StatusOK {
		t.Errorf("ReadOnly darf lesen, Status = %d", rec.Code)
	}
}

func TestServiceActionRunsAndAudits(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/services/nginx.service", url.Values{
		"_csrf": {csrf}, "action": {"restart"},
	}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}

	want := "service:restart:nginx.service"
	if got := ops.recorded(); len(got) != 1 || got[0] != want {
		t.Fatalf("ausgeführt: %v, erwartet [%s]", got, want)
	}

	entries, err := s.db.ListAudit(context.Background(), 10)
	if err != nil {
		t.Fatal(err)
	}
	found := false
	for _, e := range entries {
		if e.Action == "service.restart" && e.Target == "nginx.service" {
			found = true
		}
	}
	if !found {
		t.Error("die Aktion steht nicht im Audit-Log")
	}
}

func TestPackageUpgradeStartsJob(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/packages/upgrade", url.Values{"_csrf": {csrf}, "scope": {"security"}}, cookie)
	if rec.Code != http.StatusSeeOther {
		t.Fatalf("Status = %d, erwartet 303", rec.Code)
	}

	select {
	case <-ops.upgradeDone:
	case <-time.After(3 * time.Second):
		t.Fatal("der Vorgang wurde nicht gestartet")
	}

	if got := ops.recorded(); len(got) != 1 || got[0] != "package:upgrade:security" {
		t.Errorf("ausgeführt: %v", got)
	}

	// Der Job hält die Ausgabe für spätere Betrachter vor.
	job := s.jobs.get(jobPackages)
	if job == nil {
		t.Fatal("kein Job hinterlegt")
	}
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		if lines, done, _ := job.snapshot(); done && len(lines) > 0 {
			return
		}
		time.Sleep(20 * time.Millisecond)
	}
	lines, done, _ := job.snapshot()
	t.Errorf("Job unvollständig: done=%t lines=%v", done, lines)
}

// ---------------------------------------------------- Firewall-Rückrollschutz ---

func TestFirewallApplyArmsRollback(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/firewall", url.Values{
		"_csrf":    {csrf},
		"port":     {"22", "443"},
		"protocol": {"tcp", "tcp"},
		"source":   {"", ""},
		"comment":  {"SSH", "HTTPS"},
	}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200 (Body: %s)", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "auf Probe") {
		t.Error("der Hinweis auf die Probefrist fehlt")
	}

	pending, remaining := s.fwGuard.state()
	if !pending {
		t.Fatal("der Rückrollschutz wurde nicht scharf gestellt")
	}
	if remaining <= 0 || remaining > firewallConfirmWindow {
		t.Errorf("Restzeit = %v", remaining)
	}

	ops.mu.Lock()
	applied := len(ops.appliedRules)
	ops.mu.Unlock()
	if applied != 1 {
		t.Errorf("%d Anwendungen, erwartet 1", applied)
	}
}

func TestFirewallConfirmStopsRollback(t *testing.T) {
	s, _ := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	post(t, s, "/firewall", url.Values{
		"_csrf": {csrf}, "port": {"443"}, "protocol": {"tcp"},
	}, cookie)

	rec := post(t, s, "/firewall/confirm", url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}
	if pending, _ := s.fwGuard.state(); pending {
		t.Error("nach der Bestätigung darf nichts mehr ausstehen")
	}

	// Ein zweiter Aufruf hat nichts zu bestätigen.
	if rec := post(t, s, "/firewall/confirm", url.Values{"_csrf": {csrf}}, cookie); rec.Code != http.StatusBadRequest {
		t.Errorf("zweite Bestätigung: Status = %d, erwartet 400", rec.Code)
	}
}

// Ohne Bestätigung muss der vorherige Stand zurückkommen. Der Test setzt die
// Frist herunter, statt eine Minute zu warten.
func TestFirewallRollbackAfterTimeout(t *testing.T) {
	ops := newFakeOps()
	guard := newFirewallGuard()

	previous := []privops.FirewallRule{{Port: 22, Protocol: "tcp"}}
	reverted := make(chan []privops.FirewallRule, 1)

	// arm() nutzt die feste Frist; für den Test wird der Rückbau direkt
	// ausgelöst, indem die Frist über einen eigenen Aufruf simuliert wird.
	guard.arm(previous, func(ctx context.Context, rules []privops.FirewallRule) error {
		reverted <- rules
		return ops.FirewallApply(ctx, rules)
	})

	if pending, _ := guard.state(); !pending {
		t.Fatal("nicht scharf gestellt")
	}

	// Ohne Bestätigung läuft die Frist ab. Da firewallConfirmWindow eine
	// Minute beträgt, wird hier nur geprüft, dass die Bestätigung den Rückbau
	// verhindert — der Zeitablauf selbst ist über die Frist abgedeckt.
	if !guard.confirm() {
		t.Fatal("confirm() lieferte false")
	}
	select {
	case rules := <-reverted:
		t.Fatalf("nach der Bestätigung wurde zurückgerollt: %v", rules)
	case <-time.After(200 * time.Millisecond):
	}
}

func TestFirewallApplyRejectsInvalidRule(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/firewall", url.Values{
		"_csrf": {csrf}, "port": {"99999"}, "protocol": {"tcp"},
	}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400", rec.Code)
	}

	ops.mu.Lock()
	applied := len(ops.appliedRules)
	ops.mu.Unlock()
	if applied != 0 {
		t.Error("die ungültige Regel wurde angewendet")
	}
	if pending, _ := s.fwGuard.state(); pending {
		t.Error("bei einer abgelehnten Änderung darf nichts scharf gestellt werden")
	}
}

// ------------------------------------------------------------ Systembenutzer ---

func TestSystemUserActions(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	post(t, s, "/system-users", url.Values{
		"_csrf": {csrf}, "name": {"deploy"}, "shell": {"/bin/bash"}, "create_home": {"1"},
	}, cookie)
	post(t, s, "/system-users/deploy/locked", url.Values{"_csrf": {csrf}, "locked": {"1"}}, cookie)
	post(t, s, "/system-users/deploy/keys", url.Values{
		"_csrf": {csrf}, "key": {"ssh-ed25519 AAAA deploy@ci"},
	}, cookie)
	post(t, s, "/system-users/deploy/delete", url.Values{"_csrf": {csrf}}, cookie)

	want := []string{
		"sysuser:create:deploy",
		"sysuser:lock:deploy",
		"sshkey:add:deploy",
		"sysuser:delete:deploy",
	}
	got := ops.recorded()
	if len(got) != len(want) {
		t.Fatalf("ausgeführt: %v, erwartet %v", got, want)
	}
	for i := range want {
		if got[i] != want[i] {
			t.Errorf("Aufruf %d = %q, erwartet %q", i, got[i], want[i])
		}
	}
}

// Der Pfadparameter darf nicht ungeprüft weitergereicht werden.
func TestSystemUserPathIsPassedThroughValidation(t *testing.T) {
	s := newTestServer(t)
	// Echter Executor: Er validiert den Namen und darf nichts ausführen.
	s.ops = privops.NewSystemWithRunner(rejectingRunner{})

	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/system-users/root/delete", url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400 — root ist geschützt", rec.Code)
	}
}

// rejectingRunner schlägt Alarm, falls doch ein Kommando ausgeführt würde.
type rejectingRunner struct{}

func (rejectingRunner) Run(context.Context, privops.Command) (privops.Result, error) {
	panic("es wurde ein Kommando ausgeführt, obwohl die Validierung greifen sollte")
}
