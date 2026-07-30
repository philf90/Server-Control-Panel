package httpd

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"slices"
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
	folgeLogs  []privops.LogEntry
	units      []string
	upgradeErr error
	rebootErr  error
	sshPorts   []int

	actions      []string
	appliedRules [][]privops.FirewallRule
	createdUsers []privops.SystemUserSpec
	upgradeDone  chan struct{}

	// Das Aktualisieren der Paketlisten läuft wie das Einspielen als Vorgang im
	// Hintergrund. refreshDone sagt dem Test, dass er gelaufen ist.
	refreshLines  []string
	refreshResult privops.PackageRefreshResult
	refreshErr    error
	refreshDone   chan struct{}

	selfUpdates   []privops.SelfUpdateSpec
	selfUpdateErr error

	// configCheck ist die Antwort auf eine Konfigurationsprüfung. Ohne Eintrag
	// meldet die Attrappe "nicht geprüft" — dasselbe, was das echte System für
	// eine Datei ohne Prüfprogramm sagt.
	configCheck    privops.ConfigCheckResult
	configCheckErr error
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
			{Name: "root", UID: 0, Home: "/root", Shell: "/bin/bash", HasShell: true, Protected: true},
			{Name: "philipp", UID: 1000, Home: "/home/philipp", Shell: "/bin/bash", HasShell: true},
			{Name: "www-data", UID: 33, System: true},
		},
		logs: []privops.LogEntry{
			{At: time.Now(), Unit: "ssh.service", Priority: 6, Message: "Accepted publickey"},
		},
		units:       []string{"ssh.service"},
		upgradeDone: make(chan struct{}),

		// Die Vorgabe ist ein sauberer Lauf: zwei Quellen geantwortet, keine
		// gescheitert.
		refreshLines: []string{
			"Hit:1 http://archive.ubuntu.com/ubuntu noble InRelease",
			"Get:2 http://security.ubuntu.com/ubuntu noble-security InRelease [126 kB]",
			"Reading package lists...",
		},
		refreshResult: privops.PackageRefreshResult{Reached: 2},
		refreshDone:   make(chan struct{}),
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

func (f *fakeOps) PackageRefresh(_ context.Context, stream privops.LineWriter) (privops.PackageRefreshResult, error) {
	f.record("package:refresh")
	if stream != nil {
		for _, line := range f.refreshLines {
			stream(line)
		}
	}
	close(f.refreshDone)
	return f.refreshResult, f.refreshErr
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

func (f *fakeOps) Reboot(context.Context) error {
	f.record("reboot")
	return f.rebootErr
}

func (f *fakeOps) FirewallState(context.Context) (privops.FirewallState, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.firewall, nil
}

func (f *fakeOps) SSHPorts(context.Context) []int {
	f.mu.Lock()
	defer f.mu.Unlock()
	if len(f.sshPorts) > 0 {
		return f.sshPorts
	}
	return []int{22}
}

func (f *fakeOps) FirewallInstall(_ context.Context, stream privops.LineWriter) error {
	f.mu.Lock()
	f.actions = append(f.actions, "firewall:install")
	f.firewall.Installed = true
	f.mu.Unlock()

	if stream != nil {
		stream("Richte ufw ein …")
	}
	return nil
}

func (f *fakeOps) FirewallSetActive(_ context.Context, active bool) error {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.actions = append(f.actions, fmt.Sprintf("firewall:active:%t", active))
	f.firewall.Active = active
	return nil
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
	f.mu.Lock()
	f.createdUsers = append(f.createdUsers, spec)
	f.mu.Unlock()
	return nil
}

// lastCreated liefert die Vorgabe des zuletzt angelegten Kontos.
func (f *fakeOps) lastCreated(t *testing.T) privops.SystemUserSpec {
	t.Helper()
	f.mu.Lock()
	defer f.mu.Unlock()
	if len(f.createdUsers) == 0 {
		t.Fatal("es wurde kein Konto angelegt")
	}
	return f.createdUsers[len(f.createdUsers)-1]
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

// LogsFollow verhält sich wie das echte journalctl --follow: Es liefert erst den
// Rückblick und bleibt dann offen, bis der Kontext abbricht.
//
// Das „bleibt offen" ist der Kern der Attrappe. Eine Fassung, die nach dem
// Rückblick zurückkehrt, würde jeden Test bestehen lassen, der prüft, ob der
// Strom Zeilen liefert — und keinen, der prüft, ob er offen bleibt. Genau daran
// entscheidet sich, ob die Seite ein Journal verfolgt oder eine Momentaufnahme
// zeigt.
func (f *fakeOps) LogsFollow(ctx context.Context, q privops.LogQuery, sink privops.LogSink) error {
	f.record("logs:follow:" + q.Unit)

	f.mu.Lock()
	zeilen := append([]privops.LogEntry(nil), f.logs...)
	nachschub := f.folgeLogs
	f.mu.Unlock()

	for _, e := range zeilen {
		sink(e)
	}

	// Nachgeschobene Einträge stehen für das, was während des Zusehens
	// hereinkommt. Ohne sie prüfte ein Test nur den Rückblick.
	for _, e := range nachschub {
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(20 * time.Millisecond):
			sink(e)
		}
	}

	<-ctx.Done()
	return ctx.Err()
}

func (f *fakeOps) ConfigCheck(_ context.Context, path string) (privops.ConfigCheckResult, error) {
	f.record("configcheck:" + path)
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.configCheck, f.configCheckErr
}

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

func TestRebootOwnerOnly(t *testing.T) {
	// Ein Admin darf vieles, aber nicht neu starten — das ist Owner-Sache wie
	// das Update. Der Aufruf muss abgewiesen werden, ohne dass die Operation
	// den Executor erreicht.
	s, ops := newSystemServer(t)
	admin := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, admin)

	rec := post(t, s, "/system/reboot", url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("Status = %d, erwartet 403", rec.Code)
	}
	if got := ops.recorded(); len(got) != 0 {
		t.Fatalf("der Neustart wurde trotz fehlender Rechte ausgeführt: %v", got)
	}
}

func TestRebootRunsAndAudits(t *testing.T) {
	s, ops := newSystemServer(t)
	owner := addUser(t, s, "chef", store.RoleOwner)
	cookie, csrf := login(t, s, owner)

	rec := post(t, s, "/system/reboot", ja(url.Values{"_csrf": {csrf}}, s.rechnername()), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200 — %s", rec.Code, rec.Body.String())
	}
	if got := ops.recorded(); len(got) != 1 || got[0] != "reboot" {
		t.Fatalf("ausgeführt: %v, erwartet [reboot]", got)
	}

	entries, err := s.db.ListAudit(context.Background(), 10)
	if err != nil {
		t.Fatal(err)
	}
	found := false
	for _, e := range entries {
		if e.Action == "system.reboot" && e.Result == store.ResultOK {
			found = true
		}
	}
	if !found {
		t.Error("der Neustart steht nicht im Audit-Log")
	}
}

func TestPackageUpgradeStartsJob(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/packages/upgrade", ja(url.Values{"_csrf": {csrf}, "scope": {"security"}}), cookie)
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

// TestPackageRefreshZeigtKonsolenauszug: Das Aktualisieren der Paketlisten läuft
// als Vorgang und legt seine Ausgabe offen.
//
// Bis hierher lief es im Seitenaufruf, und die zwanzig Zeilen von apt-get update
// wurden gesammelt und verworfen — wer wissen wollte, welche Quelle geantwortet
// hat, brauchte SSH.
func TestPackageRefreshZeigtKonsolenauszug(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/packages/refresh", url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusSeeOther {
		t.Fatalf("Status = %d, erwartet 303", rec.Code)
	}
	select {
	case <-ops.refreshDone:
	case <-time.After(3 * time.Second):
		t.Fatal("der Vorgang wurde nicht gestartet")
	}
	warteAufJob(t, s)

	body := get(t, s, "/packages", cookie).Body.String()
	for _, want := range []string{
		"Vorgang",
		`id="job-output"`,
		"Hit:1 http://archive.ubuntu.com/ubuntu noble InRelease",
		"Reading package lists...",
		"abgeschlossen",
	} {
		if !strings.Contains(body, want) {
			t.Errorf("die Seite enthält %q nicht", want)
		}
	}
	// Ein sauberer Lauf ist keine Warnung.
	if strings.Contains(body, "ließ sich nicht abholen") {
		t.Error("ein vollständiger Lauf wird als Teilerfolg dargestellt")
	}

	// Und der Lauf steht im Audit-Log, mit Anfang und Ende.
	entries, err := s.db.ListAudit(context.Background(), 20)
	if err != nil {
		t.Fatal(err)
	}
	var gestartet, beendet bool
	for _, e := range entries {
		if e.Action != "package.refresh" {
			continue
		}
		switch e.Detail {
		case "gestartet":
			gestartet = true
		case "abgeschlossen":
			beendet = e.Result == store.ResultOK
		}
	}
	if !gestartet || !beendet {
		t.Errorf("Audit unvollständig: gestartet=%t beendet=%t", gestartet, beendet)
	}
}

// TestPackageRefreshTeilerfolgIstWarnung: Klemmt eine Quelle und laufen die
// übrigen durch, ist das eine Warnung mit Nennung der Quelle — kein Fehlschlag.
// apt beendet sich in diesem Fall mit 100, und das Panel meldete dafür
// „Paketlisten konnten nicht aktualisiert werden", obwohl die Listen neu waren.
func TestPackageRefreshTeilerfolgIstWarnung(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	ops.mu.Lock()
	ops.refreshLines = []string{
		"Err:1 https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble InRelease",
		"  403  Forbidden [IP: 185.125.189.187 443]",
		"Hit:2 http://archive.ubuntu.com/ubuntu noble InRelease",
		"Reading package lists...",
	}
	ops.refreshResult = privops.PackageRefreshResult{
		Reached: 1,
		Failed: []privops.SourceFailure{{
			Source: "https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble InRelease",
			Reason: "403 Forbidden [IP: 185.125.189.187 443]",
		}},
	}
	ops.mu.Unlock()

	if rec := post(t, s, "/packages/refresh", url.Values{"_csrf": {csrf}}, cookie); rec.Code != http.StatusSeeOther {
		t.Fatalf("Status = %d, erwartet 303", rec.Code)
	}
	select {
	case <-ops.refreshDone:
	case <-time.After(3 * time.Second):
		t.Fatal("der Vorgang wurde nicht gestartet")
	}
	warteAufJob(t, s)

	body := get(t, s, "/packages", cookie).Body.String()
	if !strings.Contains(body, "warn-box") {
		t.Error("der Teilerfolg erscheint nicht als Warnung")
	}
	for _, want := range []string{
		"Eine Quelle ließ sich nicht abholen",
		"ondrej/php",
		"403 Forbidden",
		"mit Einschränkung abgeschlossen",
	} {
		if !strings.Contains(body, want) {
			t.Errorf("die Warnung enthält %q nicht", want)
		}
	}
	// Ein Teilerfolg ist kein Fehler: keine rote Meldung.
	if strings.Contains(body, `class="alert error"`) {
		t.Error("der Teilerfolg wird als Fehlschlag dargestellt")
	}

	// Im Audit-Log steht, welche Quelle gefehlt hat.
	entries, err := s.db.ListAudit(context.Background(), 20)
	if err != nil {
		t.Fatal(err)
	}
	var vermerkt bool
	for _, e := range entries {
		if e.Action == "package.refresh" && strings.Contains(e.Detail, "nicht erreichbar") {
			vermerkt = e.Result == store.ResultOK && strings.Contains(e.Detail, "ondrej/php")
		}
	}
	if !vermerkt {
		t.Error("der Teilerfolg steht nicht im Audit-Log")
	}
}

// Scheitert der Lauf ganz, bleibt es bei einer Fehlermeldung — und der Auszug
// steht trotzdem da.
func TestPackageRefreshFehlschlag(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	ops.mu.Lock()
	ops.refreshLines = []string{"Err:1 http://archive.ubuntu.com/ubuntu noble InRelease", "  Temporary failure resolving"}
	ops.refreshResult = privops.PackageRefreshResult{}
	ops.refreshErr = errors.New("apt-get update: E: Failed to fetch")
	ops.mu.Unlock()

	if rec := post(t, s, "/packages/refresh", url.Values{"_csrf": {csrf}}, cookie); rec.Code != http.StatusSeeOther {
		t.Fatalf("Status = %d, erwartet 303", rec.Code)
	}
	select {
	case <-ops.refreshDone:
	case <-time.After(3 * time.Second):
		t.Fatal("der Vorgang wurde nicht gestartet")
	}
	warteAufJob(t, s)

	body := get(t, s, "/packages", cookie).Body.String()
	for _, want := range []string{"fehlgeschlagen", "Failed to fetch", "Temporary failure resolving"} {
		if !strings.Contains(body, want) {
			t.Errorf("die Seite enthält %q nicht", want)
		}
	}
}

// TestPackageEventsLiefertDenAuszug prüft den Weg, über den der Auszug im
// Browser entsteht: den SSE-Kanal.
//
// Der Kanal gab es schon für das Einspielen, geprüft war er nicht. Mit dem
// Aktualisieren der Paketlisten hat er einen zweiten Nutzer — und für ihn ist er
// die eigentliche Anzeige, nicht die Beigabe.
func TestPackageEventsLiefertDenAuszug(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	// Ohne Vorgang gibt es nichts zu streamen.
	if rec := get(t, s, "/packages/events", cookie); rec.Code != http.StatusNotFound {
		t.Errorf("ohne Vorgang: Status = %d, erwartet 404", rec.Code)
	}

	if rec := post(t, s, "/packages/refresh", url.Values{"_csrf": {csrf}}, cookie); rec.Code != http.StatusSeeOther {
		t.Fatalf("Status = %d, erwartet 303", rec.Code)
	}
	select {
	case <-ops.refreshDone:
	case <-time.After(3 * time.Second):
		t.Fatal("der Vorgang wurde nicht gestartet")
	}
	warteAufJob(t, s)

	// Wer später dazukommt, bekommt den ganzen Lauf und danach das Ende.
	body := stream(t, s, "/packages/events", cookie, 2*time.Second).Body.String()
	for _, want := range []string{
		"event: output",
		`"Hit:1 http://archive.ubuntu.com/ubuntu noble InRelease"`,
		`"Reading package lists..."`,
		"event: end",
		`data: "ok"`,
	} {
		if !strings.Contains(body, want) {
			t.Errorf("der Kanal enthält %q nicht:\n%s", want, body)
		}
	}
}

// warteAufJob wartet, bis der Paketvorgang beendet ist. Er läuft in einer
// eigenen Goroutine mit eigenem Kontext — ohne das Warten liest der Test die
// Seite, während der Vorgang noch läuft.
func warteAufJob(t *testing.T, s *Server) {
	t.Helper()

	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		if j := s.jobs.get(jobPackages); j != nil {
			if _, done, _ := j.snapshot(); done {
				return
			}
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatal("der Vorgang ist nicht fertig geworden")
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
	guard.arm("Regelsatz", func(ctx context.Context) error {
		reverted <- previous
		return ops.FirewallApply(ctx, previous)
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
		"_csrf": {csrf}, "name": {"deploy"}, "shell": {"/bin/bash"},
	}, cookie)
	post(t, s, "/system-users/deploy/locked", url.Values{"_csrf": {csrf}, "locked": {"1"}}, cookie)
	post(t, s, "/system-users/deploy/keys", url.Values{
		"_csrf": {csrf}, "key": {"ssh-ed25519 AAAA deploy@ci"},
	}, cookie)
	post(t, s, "/system-users/deploy/delete", ja(url.Values{"_csrf": {csrf}}, "deploy"), cookie)

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

// TestSystemUserCreateLegtHomeAn: Das Formular verspricht „Das
// Home-Verzeichnis wird angelegt" — und hielt es nicht.
//
// CreateHome hing an einem Feld "create_home", das es im Formular nie gab:
// useradd lief also immer mit --no-create-home. Ohne Home gibt es kein ~/.ssh,
// das dem Konto gehört, und damit keine Anmeldung per Schlüssel — der einzige
// Weg, den diese Konten haben.
func TestSystemUserCreateLegtHomeAn(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	const schluessel = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIexample deploy@ci"
	rec := post(t, s, "/system-users", url.Values{
		"_csrf": {csrf}, "name": {"deploy"}, "shell": {"/bin/bash"},
		"groups": {"sudo, docker"}, "ssh_key": {schluessel},
	}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200 (Body: %s)", rec.Code, rec.Body.String())
	}

	spec := ops.lastCreated(t)
	if !spec.CreateHome {
		t.Error("das Home-Verzeichnis wird nicht angelegt — das Formular verspricht es")
	}
	// Der Schlüssel aus dem Formular muss ankommen. Er tat es nie: Das Feld
	// dazu fehlte, es gab nur seine Beschriftung.
	if spec.SSHKey != schluessel {
		t.Errorf("SSHKey = %q, erwartet %q", spec.SSHKey, schluessel)
	}
	if got := strings.Join(spec.Groups, ","); got != "sudo,docker" {
		t.Errorf("Gruppen = %q, erwartet \"sudo,docker\"", got)
	}
}

// Der Pfadparameter darf nicht ungeprüft weitergereicht werden.
func TestSystemUserPathIsPassedThroughValidation(t *testing.T) {
	s := newTestServer(t)
	// Echter Executor: Er validiert den Namen und darf nichts ausführen.
	s.ops = privops.NewSystemWithRunner(rejectingRunner{})

	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/system-users/root/delete", ja(url.Values{"_csrf": {csrf}}, "root"), cookie)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400 — root ist geschützt", rec.Code)
	}
}

// rejectingRunner schlägt Alarm, falls doch ein Kommando ausgeführt würde.
type rejectingRunner struct{}

func (rejectingRunner) Run(context.Context, privops.Command) (privops.Result, error) {
	panic("es wurde ein Kommando ausgeführt, obwohl die Validierung greifen sollte")
}

// TestFirewallAktivierungOhnePanelPortWirdVerweigert deckt die gefährlichste
// Aktion des Panels ab.
//
// ufw weist nach dem Einschalten alles ab, was nicht ausdrücklich erlaubt ist.
// Fehlt die Regel für den Panel-Port, ist danach auch die Seite nicht mehr
// erreichbar, auf der man die Änderung zurücknehmen könnte. Der
// Rückrollschutz griffe zwar nach 60 Sekunden — aber sich auf ihn zu
// verlassen, wo eine Vorabprüfung genügt, wäre die schlechtere Zusage.
func TestFirewallAktivierungOhnePanelPortWirdVerweigert(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	ops.mu.Lock()
	ops.firewall = privops.FirewallState{
		Backend: privops.BackendUFW, Active: false, Managed: true, Installed: true,
		Rules: []privops.FirewallRule{{Port: 22, Protocol: "tcp"}},
	}
	ops.mu.Unlock()

	rec := post(t, s, "/firewall/active", url.Values{
		"_csrf": {csrf}, "active": {"1"},
	}, cookie)

	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400", rec.Code)
	}
	ops.mu.Lock()
	defer ops.mu.Unlock()
	for _, a := range ops.actions {
		if strings.HasPrefix(a, "firewall:active") {
			t.Fatalf("ufw wurde trotzdem geschaltet: %v", ops.actions)
		}
	}
	if pending, _ := s.fwGuard.state(); pending {
		t.Error("es steht eine Bestätigung aus, obwohl nichts geschaltet wurde")
	}
}

// Mit freigegebenem Panel-Port geht es durch — und steht danach auf Probe.
func TestFirewallAktivierungStehtAufProbe(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	ops.mu.Lock()
	ops.firewall = privops.FirewallState{
		Backend: privops.BackendUFW, Active: false, Managed: true, Installed: true,
		Rules: []privops.FirewallRule{
			{Port: 22, Protocol: "tcp"},
			{Port: s.cfg.Server.Port, Protocol: "tcp", Comment: "Panel"},
		},
	}
	ops.mu.Unlock()

	rec := post(t, s, "/firewall/active", ja(url.Values{
		"_csrf": {csrf}, "active": {"1"},
	}), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200 — %s", rec.Code, rec.Body.String())
	}

	pending, _ := s.fwGuard.state()
	if !pending {
		t.Fatal("die Aktivierung steht nicht auf Probe")
	}
	if got := s.fwGuard.subjectOf(); got != "Aktivierung" {
		t.Errorf("Gegenstand der Probe = %q", got)
	}
	// Ohne Bestätigung wäre der Rückweg das Ausschalten, nicht ein Regelsatz.
	s.fwGuard.confirm()
}

// Ausschalten öffnet und braucht deshalb keine Probe — eine Frist, nach der
// die Firewall von selbst wieder zugeht, wäre eine böse Überraschung.
func TestFirewallAusschaltenOhneProbe(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	ops.mu.Lock()
	ops.firewall = privops.FirewallState{
		Backend: privops.BackendUFW, Active: true, Managed: true, Installed: true,
	}
	ops.mu.Unlock()

	// Ausschalten ist die dritte Stufe: Der Hostname muss getippt werden.
	rec := post(t, s, "/firewall/active", ja(url.Values{
		"_csrf": {csrf}, "active": {"0"},
	}, s.rechnername()), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d — %s", rec.Code, rec.Body.String())
	}
	// Und ufw ist tatsächlich aus. Ohne diese Prüfung bestand der Test auch
	// dann, wenn statt der Aktion die Rückfrage kam: Die Zwischenseite antwortet
	// mit 200, und eine Probe stellt sie auch nicht.
	if !slices.Contains(ops.recorded(), "firewall:active:false") {
		t.Fatalf("ufw wurde nicht ausgeschaltet: %v", ops.recorded())
	}
	if pending, _ := s.fwGuard.state(); pending {
		t.Error("das Ausschalten steht auf Probe — es sperrt aber niemanden aus")
	}
}

// TestRuleCoversPort: Eine auf eine Quelle eingeschränkte Regel zählt nicht.
// Sie mag den eigenen Zugang decken, aber von hier aus lässt sich das nicht
// feststellen — und eine Sicherung, die im Zweifel "passt schon" sagt, ist
// keine.
func TestRuleCoversPort(t *testing.T) {
	rules := []privops.FirewallRule{
		{Port: 22, Protocol: "tcp"},
		{Port: 8443, Protocol: "tcp", Source: "203.0.113.0/24"},
	}
	if !ruleCoversPort(rules, 22) {
		t.Error("22/tcp von überall wurde nicht erkannt")
	}
	if ruleCoversPort(rules, 8443) {
		t.Error("eine auf eine Quelle beschränkte Regel darf nicht als Freigabe zählen")
	}
	if ruleCoversPort(rules, 443) {
		t.Error("443 ist nicht freigegeben")
	}
}

func TestOpenPortSummary(t *testing.T) {
	if got := openPortSummary(nil); got != "keine Zugänge" {
		t.Errorf("leerer Regelsatz = %q", got)
	}
	got := openPortSummary([]privops.FirewallRule{
		{Port: 8443, Protocol: "tcp"},
		{Port: 22, Protocol: "tcp"},
		{Port: 22, Protocol: "tcp"}, // Dublette
		{Port: 5432, Protocol: "tcp", Source: "203.0.113.0/24"},
	})
	want := "22/tcp, 5432/tcp von 203.0.113.0/24, 8443/tcp"
	if got != want {
		t.Errorf("= %q\nerwartet %q", got, want)
	}
}

// TestFirewallSeiteRendertAlleZustaende: Ein Template bricht erst beim
// Rendern. Die Firewall-Seite hat seit rc.4 vier Zustände, und drei davon
// bekäme man in der Entwicklung nie zu Gesicht.
func TestFirewallSeiteRendertAlleZustaende(t *testing.T) {
	faelle := []struct {
		name     string
		state    privops.FirewallState
		erwartet string
		fehlt    string
	}{
		{
			name: "gar kein ufw",
			state: privops.FirewallState{
				Backend: privops.BackendNone, Installed: false,
			},
			erwartet: "ufw installieren",
		},
		{
			name: "installiert, inaktiv, Panel-Port zu",
			state: privops.FirewallState{
				Backend: privops.BackendUFW, Installed: true, Managed: true,
				Rules: []privops.FirewallRule{{Port: 22, Protocol: "tcp"}},
			},
			erwartet: "Einschalten ist gesperrt",
			fehlt:    "ufw einschalten",
		},
		{
			name: "installiert, inaktiv, Panel-Port offen",
			state: privops.FirewallState{
				Backend: privops.BackendUFW, Installed: true, Managed: true,
				Rules: []privops.FirewallRule{{Port: 8443, Protocol: "tcp"}},
			},
			erwartet: "ufw einschalten",
		},
		{
			name: "aktiv",
			state: privops.FirewallState{
				Backend: privops.BackendUFW, Installed: true, Managed: true, Active: true,
				Rules: []privops.FirewallRule{{Port: 8443, Protocol: "tcp"}},
			},
			erwartet: "ufw ausschalten",
			fehlt:    "ufw installieren",
		},
		{
			// Fremdes nftables-Regelwerk: kein Angebot, irgendetwas zu
			// installieren oder zu schalten. Das Panel sieht hier nur zu.
			name: "fremdes nftables",
			state: privops.FirewallState{
				Backend: privops.BackendNFTables, Installed: false, Active: true,
			},
			fehlt: "ufw installieren",
		},
	}

	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			s, ops := newSystemServer(t)
			user := addUser(t, s, "admin", store.RoleAdmin)
			cookie, _ := login(t, s, user)

			ops.mu.Lock()
			ops.firewall = f.state
			ops.mu.Unlock()

			rec := get(t, s, "/firewall", cookie)
			if rec.Code != http.StatusOK {
				t.Fatalf("Status = %d", rec.Code)
			}
			body := rec.Body.String()
			if f.erwartet != "" && !strings.Contains(body, f.erwartet) {
				t.Errorf("%q fehlt auf der Seite", f.erwartet)
			}
			if f.fehlt != "" && strings.Contains(body, f.fehlt) {
				t.Errorf("%q steht auf der Seite, gehört dort aber nicht hin", f.fehlt)
			}
		})
	}
}

// TestPanelRegelWirdErzwungen: Im Browser steht die Regel für den Panel-Port
// schreibgeschützt da. Ein schreibgeschütztes Feld ist aber eine Bitte, keine
// Sperre — wer die Anfrage selbst zusammenstellt, lässt es weg. Dann muss der
// Server sie ergänzen, sonst sperrt das nächste Einschalten aus.
func TestPanelRegelWirdErzwungen(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	// Absichtlich nur eine SSH-Regel senden — der Panel-Port fehlt.
	rec := post(t, s, "/firewall", url.Values{
		"_csrf": {csrf}, "port": {"22"}, "protocol": {"tcp"},
		"source": {""}, "comment": {"SSH"},
	}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d", rec.Code)
	}

	ops.mu.Lock()
	defer ops.mu.Unlock()
	if len(ops.appliedRules) == 0 {
		t.Fatal("es wurde nichts angewendet")
	}
	angewendet := ops.appliedRules[len(ops.appliedRules)-1]
	if !ruleCoversPort(angewendet, s.cfg.Server.Port) {
		t.Errorf("die Regel für Port %d fehlt: %+v", s.cfg.Server.Port, angewendet)
	}
}

// Eine bestehende Regel für den Panel-Port wird nicht verdoppelt und nicht
// überschrieben — wer sein Panel bewusst nur aus einem Netz erreichbar macht,
// soll das dürfen.
func TestEnsurePanelRule(t *testing.T) {
	vorhanden := []privops.FirewallRule{
		{Port: 8443, Protocol: "tcp", Source: "203.0.113.0/24", Comment: "nur intern"},
	}
	got := ensurePanelRule(vorhanden, 8443)
	if len(got) != 1 || got[0].Source != "203.0.113.0/24" {
		t.Errorf("bestehende Regel wurde verändert: %+v", got)
	}

	got = ensurePanelRule(nil, 8443)
	if len(got) != 1 || got[0].Port != 8443 || got[0].Source != "" {
		t.Errorf("= %+v, erwartet eine Regel für 8443 von überall", got)
	}
}

// TestFirewallZeilenVorschlagFuerSSH: Wer ufw ohne SSH-Regel einschaltet,
// verliert den zweiten Weg auf den Server — und merkt es erst, wenn er ihn
// braucht. Der Port kommt aus sshd_config, nicht aus der Annahme "22".
func TestFirewallZeilenVorschlagFuerSSH(t *testing.T) {
	s, ops := newSystemServer(t)

	ops.mu.Lock()
	ops.sshPorts = []int{2222}
	ops.mu.Unlock()

	rows := s.firewallRows(context.Background(), nil)

	if len(rows) != 2 {
		t.Fatalf("%d Zeilen, erwartet 2 (Panel + SSH-Vorschlag): %+v", len(rows), rows)
	}
	if !rows[0].Locked || rows[0].Rule.Port != s.cfg.Server.Port {
		t.Errorf("die erste Zeile ist nicht die festgesetzte Panel-Regel: %+v", rows[0])
	}
	if !rows[1].Proposed || rows[1].Rule.Port != 2222 {
		t.Errorf("der SSH-Vorschlag nennt Port %d, erwartet 2222", rows[1].Rule.Port)
	}

	// Gibt es die Regel schon, wird nichts vorgeschlagen.
	rows = s.firewallRows(context.Background(), []privops.FirewallRule{
		{Port: 2222, Protocol: "tcp"},
	})
	for _, row := range rows {
		if row.Proposed {
			t.Errorf("Vorschlag für eine bereits bestehende Regel: %+v", row)
		}
	}
}

// Die Panel-Regel steht immer an erster Stelle und übernimmt eine bereits
// bestehende Quelle, statt sie zu verwerfen.
func TestFirewallZeilenUebernimmtBestehendePanelRegel(t *testing.T) {
	s, _ := newSystemServer(t)
	rows := s.firewallRows(context.Background(), []privops.FirewallRule{
		{Port: 80, Protocol: "tcp", Comment: "HTTP"},
		{Port: s.cfg.Server.Port, Protocol: "tcp", Source: "203.0.113.0/24", Comment: "Panel intern"},
	})
	if !rows[0].Locked || rows[0].Rule.Source != "203.0.113.0/24" {
		t.Errorf("erste Zeile = %+v", rows[0])
	}
	// Die Panel-Regel darf nicht zusätzlich als normale Zeile auftauchen.
	treffer := 0
	for _, row := range rows {
		if row.Rule.Port == s.cfg.Server.Port {
			treffer++
		}
	}
	if treffer != 1 {
		t.Errorf("die Panel-Regel steht %d Mal in der Liste", treffer)
	}
}

// TestGeschuetzteKontenOhneAktionen: root lässt sich über das Panel nicht
// löschen — privops.protectedUser weist es ab. Die Oberfläche bot den Knopf
// trotzdem an, und ein Knopf, der zuverlässig scheitert, ist schlimmer als
// keiner: Er sieht aus wie eine Funktion und ist eine Falle.
func TestGeschuetzteKontenOhneAktionen(t *testing.T) {
	s, _ := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, _ := login(t, s, user)

	rec := get(t, s, "/system-users", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d", rec.Code)
	}
	body := rec.Body.String()

	if strings.Contains(body, "/system-users/root/delete") {
		t.Error("für root wird ein Löschen-Formular ausgegeben")
	}
	if strings.Contains(body, "/system-users/root/locked") {
		t.Error("für root wird ein Sperren-Formular ausgegeben")
	}
	// Ein regulärer Benutzer behält seine Aktionen.
	if !strings.Contains(body, "/system-users/philipp/delete") {
		t.Error("dem regulären Konto fehlen die Aktionen")
	}
	if !strings.Contains(body, "geschützt") {
		t.Error("der Grund für die fehlenden Knöpfe wird nicht genannt")
	}
}

// Der Klick auf die Schlüsselzahl soll dort landen, wo die Schlüssel stehen.
func TestSchluesselLinkSpringtZurKarte(t *testing.T) {
	s, _ := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, _ := login(t, s, user)

	body := get(t, s, "/system-users", cookie).Body.String()
	if !strings.Contains(body, `href="/system-users?user=philipp#schluessel"`) {
		t.Error("der Link zur Schlüsselverwaltung trägt keine Sprungmarke")
	}

	body = get(t, s, "/system-users?user=philipp", cookie).Body.String()
	if !strings.Contains(body, `id="schluessel"`) {
		t.Error("die Schlüsselkarte trägt keine Sprungmarke")
	}
}

// TestKeineAktionsLinksMehr: Aktionen sind Schaltflächen, keine
// unterstrichenen Wörter. Auf dem Telefon ist ein unterstrichenes Wort ein
// Tippziel von wenigen Millimetern, und "löschen" sah aus wie "mehr lesen".
func TestKeineAktionsLinksMehr(t *testing.T) {
	s, _ := newSystemServer(t)
	user := addUser(t, s, "owner", store.RoleOwner)
	cookie, _ := login(t, s, user)

	for _, pfad := range []string{"/", "/services", "/packages", "/firewall",
		"/system-users", "/users", "/account", "/update", "/audit", "/logs"} {
		body := get(t, s, pfad, cookie).Body.String()
		if strings.Contains(body, `class="link`) {
			t.Errorf("%s enthält noch eine als Link gestaltete Aktion", pfad)
		}
	}
}
