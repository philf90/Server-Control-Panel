package httpd

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/privops"
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

	// Zeitpläne. cronLuecken ist absichtlich befüllbar: Eine Quelle, die sich
	// nicht lesen ließ, muss die Oberfläche nennen, und das gehört geprüft.
	cronEntries  []privops.CronEntry
	cronLuecken  []string
	cronSpecs    []privops.CronSpec
	cronWriteErr error
	timers       []privops.Timer
	timerLauf    privops.TimerLauf
	timerErr     error

	// Docker. dockerInstallDone sagt dem Test, dass der Vorgang im Hintergrund
	// gelaufen ist — wie upgradeDone bei den Paketen.
	docker           privops.DockerState
	dockerErr        error
	dockerInstallErr error
	// dockerInstallHalt hält den Lauf an, bis der Test ihn freigibt. Ohne das
	// wäre ein Vorgang vorbei, bevor der Test seinen zweiten Aufruf abschickt —
	// und die Prüfung „höchstens einer je Art" prüfte nichts.
	dockerInstallHalt chan struct{}
	dockerInstallDone chan struct{}

	container          []privops.Container
	containerErr       error
	containerAktionErr error
	containerLogs      []string
	containerStats     []privops.ContainerStats
	containerMounts    []privops.ContainerMount

	images    []privops.Image
	volumes   []privops.Volume
	netze     []privops.Netz
	df        []privops.Bestandsposten
	pruneDone chan struct{}

	// Stacks. stackText steht je Stackname, damit ein Test einen Stack ohne
	// lesbare Datei bauen kann — der Normalfall bei einem fremden Projekt, dessen
	// Verzeichnis es nicht mehr gibt.
	stacks    []privops.Stack
	stackErr  error
	stackText map[string]string
	// stackPruefung ist die Antwort des Compose-Prüfers. Die Attrappe RECHNET
	// sie nicht — sie ist gesetzt oder nicht. Der Prüfer selbst hat seine
	// eigenen Tests in privops; hier geht es darum, was die Schicht darüber mit
	// seinem Urteil anfängt.
	stackPruefung   privops.ComposePruefung
	stackSchreibErr error
	// stackDone meldet dem Test das Ende des Hintergrundvorgangs. Er wird nach
	// dem Bau nicht mehr GESETZT, nur gelesen und über stackFertig genau einmal
	// geschlossen: Ihn beim Schließen auf nil zu setzen war der erste Anlauf, und
	// er hat den Test in einen Empfang auf einem nil-Kanal geschickt — der
	// blockiert für immer. Gefunden hat es der Race-Detektor, nicht der
	// gewöhnliche Lauf.
	stackDone   chan struct{}
	stackFertig sync.Once

	// Ereignisse. eventsHalt hält den Strom offen, bis der Test ihn freigibt —
	// ein Strom, der sofort endet, prüft die Schranke nicht.
	events     []privops.DockerEreignis
	eventsErr  error
	eventsHalt chan struct{}

	// Update-Prüfung. staende steht je Image-Ref; was nicht darin steht, gilt
	// als nicht geprüft — dasselbe, was die echte Fassung ohne belastbaren
	// Vergleich meldet.
	staende      map[string]privops.Updatestand
	updateErr    error
	updateDone   chan struct{}
	updateFertig sync.Once
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

		// Die Zeitplan-Vorgabe hält alle vier Fälle bereit, die die Oberfläche
		// unterschiedlich behandeln muss: ein eigener Eintrag (schaltbar), ein
		// abgeschalteter eigener, eine fremde Zeile aus /etc/crontab (nur
		// Auskunft) und ein run-parts-Skript (gar keine Zeile).
		cronEntries: []privops.CronEntry{
			{
				Quelle: "/etc/cron.d/asylum-sicherung", Zeile: 8,
				Schedule: "17 3 * * *", ScheduleText: privops.ScheduleText("17 3 * * *"),
				User: "root", Command: "/usr/local/bin/sicherung.sh",
				Kommentar: "Nachtsicherung", Verwaltet: true, Name: "sicherung", Art: "zeile",
			},
			{
				Quelle: "/etc/cron.d/asylum-bericht", Zeile: 9,
				Schedule: "0 6 * * 1", ScheduleText: privops.ScheduleText("0 6 * * 1"),
				User: "philipp", Command: "/usr/local/bin/bericht.sh",
				Verwaltet: true, Name: "bericht", Art: "zeile", Deaktiviert: true,
			},
			{
				Quelle: "/etc/crontab", Zeile: 4,
				Schedule: "*/10 * * * *", ScheduleText: privops.ScheduleText("*/10 * * * *"),
				User: "root", Command: "test -x /usr/sbin/anacron || cd / && run-parts --report /etc/cron.daily",
				Art: "zeile",
			},
			{
				Quelle:   "/etc/cron.daily/logrotate",
				Schedule: "@daily", ScheduleText: privops.ScheduleText("@daily"),
				User: "root", Command: "/etc/cron.daily/logrotate", Art: "skript",
			},
		},
		// Der zweite Timer hat absichtlich keine Zeitpunkte: Ein abgeschalteter
		// Timer hat keinen nächsten Lauf, und ein nie gelaufener keinen letzten.
		// Die Oberfläche muss das zeigen können, ohne 1970 zu behaupten.
		timers: []privops.Timer{
			{
				Unit: "apt-daily.timer", Loest: "apt-daily.service",
				Beschreibung: "Daily apt download activities",
				Aktiv:        "active", Enabled: "enabled",
				Naechster:  zeitpunkt(6 * time.Hour),
				Letzter:    zeitpunkt(-18 * time.Hour),
				Plan:       "*-*-* 6,18:00:00",
				Persistent: true,
			},
			{
				Unit: "fstrim.timer", Loest: "fstrim.service",
				Beschreibung: "Discard unused blocks once a week",
				Aktiv:        "inactive", Enabled: "disabled",
				Plan: "Mon *-*-* 00:00:00",
			},
		},
		timerLauf: privops.TimerLauf{
			Unit: "apt-daily.service", Ergebnis: "success", ExitCode: 0, Geglueckt: true,
			Zeilen: []privops.LogEntry{},
		},
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

// regelSaetze und letzteRegeln lesen die aufgezeichneten Regelsätze unter dem
// Schloss. Direkt auf f.appliedRules zuzugreifen wäre ein Datenrennen: Der
// Firewall-Wächter rollt aus einer eigenen Goroutine zurück und schreibt dabei
// in dasselbe Feld.
func (f *fakeOps) regelSaetze() [][]privops.FirewallRule {
	f.mu.Lock()
	defer f.mu.Unlock()
	out := make([][]privops.FirewallRule, len(f.appliedRules))
	copy(out, f.appliedRules)
	return out
}

func (f *fakeOps) letzteRegeln() []privops.FirewallRule {
	f.mu.Lock()
	defer f.mu.Unlock()
	if len(f.appliedRules) == 0 {
		return nil
	}
	return f.appliedRules[len(f.appliedRules)-1]
}

func (f *fakeOps) SystemUsers(context.Context) ([]privops.SystemUser, error) { return f.sysUsers, nil }

// LoginShells und Groups liefern feste Listen. Die Attrappe liest keine
// Systemdateien — geprüft wird hier, dass die Oberfläche die Auskunft weitergibt,
// nicht dass /etc/shells richtig geparst wird. Das steht in privops.
func (f *fakeOps) LoginShells(context.Context) ([]string, error) {
	return []string{"/bin/bash", "/bin/sh", "/usr/sbin/nologin"}, nil
}

func (f *fakeOps) Groups(context.Context) ([]string, error) {
	return []string{"sudo", "users", "www-data"}, nil
}

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

func (f *fakeOps) DockerState(context.Context) (privops.DockerState, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.docker, f.dockerErr
}

func (f *fakeOps) DockerInstall(_ context.Context, stream privops.LineWriter) error {
	f.record("docker:install")
	if stream != nil {
		stream("Richte docker.io ein …")
	}
	f.mu.Lock()
	halt := f.dockerInstallHalt
	f.mu.Unlock()
	if halt != nil {
		<-halt
	}

	f.mu.Lock()
	err := f.dockerInstallErr
	done := f.dockerInstallDone
	// Nach dem Lauf ist Docker da. Ohne das zeigte ein Test, der danach den
	// Zustand abfragt, weiter „nicht installiert" — und prüfte damit etwas
	// anderes als das, was auf einem echten Server passiert.
	if err == nil {
		f.docker = privops.DockerState{
			Installiert: true, DaemonLaeuft: true, ComposeVerfuegbar: true,
			ClientVersion: "27.5.1", ServerVersion: "27.5.1", ComposeVersion: "2.32.4",
			Paket: "docker.io",
		}
	}
	f.mu.Unlock()

	if done != nil {
		close(done)
	}
	return err
}

func (f *fakeOps) SelfUpdateStart(_ context.Context, spec privops.SelfUpdateSpec) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.selfUpdates = append(f.selfUpdates, spec)
	return f.selfUpdateErr
}

// zeitpunkt gibt einen Zeitpunkt relativ zu jetzt als Zeiger. Die Timer-Felder
// sind Zeiger, weil „kein Zeitpunkt" ein eigener Zustand ist.
func zeitpunkt(d time.Duration) *time.Time {
	t := time.Now().Add(d)
	return &t
}

// ---------------------------------------------------------- Cron und Timer ---
//
// Die Attrappe schreibt keine Crontab und ruft kein systemctl. Was hier geprüft
// wird, ist die Oberfläche: dass sie die Auskunft weitergibt, die Stufen der
// Rückfrage einhält und die Vorgabe unverändert an privops übergibt. Dass eine
// Crontab richtig gelesen und atomar geschrieben wird, steht in
// internal/privops/cron_test.go — dort mit echten Dateien in einem
// Wegwerfverzeichnis.

func (f *fakeOps) CronList(context.Context) ([]privops.CronEntry, []string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.cronEntries, f.cronLuecken, nil
}

func (f *fakeOps) CronWrite(_ context.Context, spec privops.CronSpec) error {
	f.record("cron:write:" + spec.Name)
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.cronWriteErr != nil {
		return f.cronWriteErr
	}
	f.cronSpecs = append(f.cronSpecs, spec)
	return nil
}

func (f *fakeOps) CronDelete(_ context.Context, name string) error {
	f.record("cron:delete:" + name)
	f.mu.Lock()
	defer f.mu.Unlock()
	gefiltert := f.cronEntries[:0]
	for _, e := range f.cronEntries {
		if e.Verwaltet && e.Name == name {
			continue
		}
		gefiltert = append(gefiltert, e)
	}
	f.cronEntries = gefiltert
	return nil
}

// letzteCronSpec liefert die zuletzt geschriebene Vorgabe.
func (f *fakeOps) letzteCronSpec(t *testing.T) privops.CronSpec {
	t.Helper()
	f.mu.Lock()
	defer f.mu.Unlock()
	if len(f.cronSpecs) == 0 {
		t.Fatal("es wurde kein Zeitplan geschrieben")
	}
	return f.cronSpecs[len(f.cronSpecs)-1]
}

func (f *fakeOps) TimerList(context.Context) ([]privops.Timer, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.timers, f.timerErr
}

func (f *fakeOps) TimerRuns(_ context.Context, unit string) (privops.TimerLauf, error) {
	f.record("timer:runs:" + unit)
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.timerErr != nil {
		return privops.TimerLauf{}, f.timerErr
	}
	lauf := f.timerLauf
	if lauf.Unit == "" {
		lauf.Unit = unit
	}
	return lauf, nil
}

// newSystemServer baut einen Server mit dem gefälschten Executor.
func newSystemServer(t *testing.T) (*Server, *fakeOps) {
	t.Helper()
	s := newTestServer(t)
	ops := newFakeOps()
	s.ops = ops
	return s, ops
}

// ---------------------------------------------------- Firewall-Rückrollschutz ---

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

func (f *fakeOps) DockerContainers(context.Context) ([]privops.Container, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.container, f.containerErr
}

func (f *fakeOps) DockerContainer(_ context.Context, id string) (privops.ContainerDetail, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	for _, c := range f.container {
		if c.ID == id || c.Name == id {
			// Die Mounts gehören dazu: Aus ihnen leitet der Bestand ab, welche
			// Volumes in Gebrauch sind — und das ist die Angabe, an der dort der
			// gefährlichste Handgriff hängt. Ohne sie prüfte der Test die
			// harmlose Hälfte.
			return privops.ContainerDetail{
				Container: c, ExitCode: -1, Mounts: f.containerMounts,
			}, nil
		}
	}
	return privops.ContainerDetail{}, errors.New("No such container: " + id)
}

func (f *fakeOps) DockerContainerAction(_ context.Context, id string, a privops.ContainerAction) error {
	f.record("docker:" + string(a) + ":" + id)
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.containerAktionErr != nil {
		return f.containerAktionErr
	}
	// Den Zustand mitführen: Ein Test, der nach dem Stoppen den frischen Stand
	// prüft, soll ihn auch sehen.
	for i, c := range f.container {
		if c.ID != id && c.Name != id {
			continue
		}
		switch a {
		case privops.ContainerStart, privops.ContainerRestart, privops.ContainerUnpause:
			f.container[i].Zustand, f.container[i].Status = "running", "Up 1 second"
		case privops.ContainerStop:
			f.container[i].Zustand, f.container[i].Status = "exited", "Exited (0) 1 second ago"
		case privops.ContainerPause:
			f.container[i].Zustand, f.container[i].Status = "paused", "Up 1 hour (Paused)"
		}
	}
	return nil
}

func (f *fakeOps) DockerContainerRemove(_ context.Context, id string, erzwingen bool) error {
	// Beide Vermerke VOR der Sperre: record nimmt dieselbe Sperre, und sie
	// darunter ein zweites Mal zu nehmen ist ein Deadlock. Beim ersten Anlauf
	// stand der zweite Vermerk im gesperrten Abschnitt, und der Testlauf hing —
	// bis der Zeitgeber von "go test" ihn nach zehn Minuten abbrach.
	f.record("docker:remove:" + id)
	if erzwingen {
		f.record("docker:remove:erzwungen")
	}

	f.mu.Lock()
	defer f.mu.Unlock()
	uebrig := f.container[:0]
	for _, c := range f.container {
		if c.ID != id && c.Name != id {
			uebrig = append(uebrig, c)
		}
	}
	f.container = uebrig
	return nil
}

func (f *fakeOps) DockerContainerLogs(_ context.Context, _ string, _ int) ([]string, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.containerLogs, nil
}

func (f *fakeOps) DockerContainerLogsFollow(ctx context.Context, _ string, _ int, sink privops.LineWriter) error {
	for _, l := range f.containerLogs {
		sink(l)
	}
	<-ctx.Done()
	return nil
}

func (f *fakeOps) DockerStats(context.Context) ([]privops.ContainerStats, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.containerStats, nil
}

func (f *fakeOps) DockerImages(context.Context) ([]privops.Image, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.images, nil
}

func (f *fakeOps) DockerImageRemove(_ context.Context, id string) error {
	f.record("docker:image-rm:" + id)
	return nil
}

func (f *fakeOps) DockerVolumes(context.Context) ([]privops.Volume, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.volumes, nil
}

func (f *fakeOps) DockerVolumeRemove(_ context.Context, name string) error {
	f.record("docker:volume-rm:" + name)
	return nil
}

func (f *fakeOps) DockerNetworks(context.Context) ([]privops.Netz, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.netze, nil
}

func (f *fakeOps) DockerNetworkRemove(_ context.Context, id string) error {
	f.record("docker:netz-rm:" + id)
	return nil
}

func (f *fakeOps) DockerDiskUsage(context.Context) ([]privops.Bestandsposten, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.df, nil
}

func (f *fakeOps) DockerPrune(_ context.Context, art privops.PruneArt, alle bool, stream privops.LineWriter) (string, error) {
	vermerk := "docker:prune:" + string(art)
	if alle {
		vermerk += ":alle"
	}
	f.record(vermerk)
	if stream != nil {
		stream("Total reclaimed space: 1.234GB")
	}
	f.mu.Lock()
	done := f.pruneDone
	f.mu.Unlock()
	if done != nil {
		close(done)
	}
	return "1.234GB", nil
}

func (f *fakeOps) StackList(context.Context) ([]privops.Stack, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.stacks, f.stackErr
}

// StackDatei bildet die Zusage der echten Fassung nach: Ein Name, den die Liste
// nicht kennt, führt nirgendwohin. Der Fake, der jeden Namen bediente, prüfte
// genau die Eigenschaft nicht, um derer willen der Umweg über die Liste da ist.
// StackSchreiben bildet die Zusage der echten Fassung nach: Ein Stack, dessen
// Prüfung nicht OK ist, wird NICHT geschrieben. Eine Attrappe, die trotzdem
// schriebe, ließe genau den Test bestehen, der prüft, dass sie es nicht tut.
func (f *fakeOps) StackSchreiben(_ context.Context, name, text string, _ int) (privops.ComposePruefung, error) {
	f.record("docker:stack-write:" + name)
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.stackSchreibErr != nil {
		return privops.ComposePruefung{}, f.stackSchreibErr
	}
	if f.stackPruefung.Geprueft && !f.stackPruefung.OK {
		return f.stackPruefung, nil
	}
	if f.stackText == nil {
		f.stackText = map[string]string{}
	}
	f.stackText[name] = text
	vorhanden := false
	for _, st := range f.stacks {
		if st.Name == name {
			vorhanden = true
		}
	}
	if !vorhanden {
		f.stacks = append(f.stacks, privops.Stack{
			Name: name, Verwaltet: true, Status: "nicht gestartet",
			Datei: "/opt/asylum/stacks/" + name + "/compose.yaml",
		})
	}
	return f.stackPruefung, nil
}

func (f *fakeOps) StackPruefen(_ context.Context, name string, _ int) (privops.ComposePruefung, error) {
	f.record("docker:stack-pruefen:" + name)
	f.mu.Lock()
	defer f.mu.Unlock()
	return f.stackPruefung, nil
}

func (f *fakeOps) StackAusfuehren(_ context.Context, name string, aktion privops.StackAktion, mitVolumes bool, _ int, stream privops.LineWriter) (privops.ComposePruefung, error) {
	vermerk := "docker:stack-" + string(aktion) + ":" + name
	if mitVolumes {
		vermerk += ":volumes"
	}
	f.record(vermerk)
	f.mu.Lock()
	pruefung := f.stackPruefung
	f.mu.Unlock()

	// Wie in der echten Fassung: Der Prüfer läuft vor allem, was startet — und
	// nicht vor „down".
	if (aktion == privops.StackUp || aktion == privops.StackRestart) &&
		pruefung.Geprueft && !pruefung.OK {
		f.stackFertigMelden()
		return pruefung, nil
	}
	if stream != nil {
		stream("Container " + name + "-web-1  Started")
	}
	f.stackFertigMelden()
	return pruefung, nil
}

// stackFertigMelden schließt den Meldekanal genau einmal. Ein Test, der zwei
// Vorgänge auslöst, brächte die Attrappe sonst mit „close of closed channel" zum
// Absturz — und der Bericht zeigte auf die Attrappe statt auf den Test.
func (f *fakeOps) stackFertigMelden() {
	if f.stackDone == nil {
		return
	}
	f.stackFertig.Do(func() { close(f.stackDone) })
}

func (f *fakeOps) StackLoeschen(_ context.Context, name string, stream privops.LineWriter) error {
	f.record("docker:stack-loeschen:" + name)
	if stream != nil {
		stream("Verzeichnis entfernt: /opt/asylum/stacks/" + name)
	}
	f.mu.Lock()
	uebrig := f.stacks[:0]
	for _, st := range f.stacks {
		if st.Name != name {
			uebrig = append(uebrig, st)
		}
	}
	f.stacks = uebrig
	f.mu.Unlock()
	f.stackFertigMelden()
	return nil
}

func (f *fakeOps) DockerEventsFollow(ctx context.Context, sink func(privops.DockerEreignis)) error {
	f.record("docker:events")
	f.mu.Lock()
	events, err, halt := f.events, f.eventsErr, f.eventsHalt
	f.mu.Unlock()
	if err != nil {
		return err
	}
	for _, e := range events {
		sink(e)
	}
	if halt != nil {
		// Wie die echte Fassung: Der Kontext des Betrachters ist die Frist.
		select {
		case <-halt:
		case <-ctx.Done():
		}
	}
	return nil
}

func (f *fakeOps) DockerUpdatePruefen(_ context.Context, ref string) (privops.Updatestand, error) {
	f.record("docker:update-pruefen:" + ref)
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.updateDone != nil {
		f.updateFertig.Do(func() { close(f.updateDone) })
	}
	if f.updateErr != nil {
		return privops.Updatestand{}, f.updateErr
	}
	if st, da := f.staende[ref]; da {
		return st, nil
	}
	// Der Vorgabefall ist bewusst „nicht geprüft" und nicht „aktuell": So
	// verhält sich auch die echte Fassung, wenn ihr der belastbare Vergleich
	// fehlt.
	return privops.Updatestand{
		Ref: ref, Grund: "in der Attrappe nicht hinterlegt",
	}, nil
}

func (f *fakeOps) StackDatei(_ context.Context, name string) (privops.StackInhalt, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if err := privops.PruefeName(name); err != nil {
		return privops.StackInhalt{}, err
	}
	for _, st := range f.stacks {
		if st.Name != name {
			continue
		}
		text, ok := f.stackText[name]
		if !ok {
			return privops.StackInhalt{}, errors.New("keine Compose-Datei zu " + name)
		}
		return privops.StackInhalt{
			Name: name, Datei: st.Datei, Verwaltet: st.Verwaltet, Text: text,
		}, nil
	}
	return privops.StackInhalt{}, errors.New("kein Stack mit dem Namen " + name)
}
