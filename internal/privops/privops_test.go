package privops

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// fakeRunner speist aufgezeichnete Kommandoausgaben ein und merkt sich die
// Aufrufe. Damit sind die Operationen prüfbar, ohne das System anzufassen.
type fakeRunner struct {
	responses map[string]Result
	errs      map[string]error
	calls     []Command
}

func newFakeRunner() *fakeRunner {
	return &fakeRunner{
		responses: make(map[string]Result),
		errs:      make(map[string]error),
	}
}

// key bildet Kommando und Argumente auf einen Schlüssel ab. Ein Eintrag nur
// unter dem Kommandonamen gilt als Rückfallebene.
func (f *fakeRunner) Run(_ context.Context, cmd Command) (Result, error) {
	f.calls = append(f.calls, cmd)

	full := cmd.Name + " " + strings.Join(cmd.Args, " ")
	if err, ok := f.errs[cmd.Name]; ok {
		return Result{}, err
	}
	if res, ok := f.responses[full]; ok {
		return res, nil
	}
	for prefix, res := range f.responses {
		if strings.HasPrefix(full, prefix) {
			if cmd.Stream != nil {
				for _, line := range strings.Split(res.Stdout, "\n") {
					cmd.Stream(line)
				}
			}
			return res, nil
		}
	}
	return Result{}, nil
}

func (f *fakeRunner) lastCall() Command {
	if len(f.calls) == 0 {
		return Command{}
	}
	return f.calls[len(f.calls)-1]
}

// ------------------------------------------------------------ Kommandopfad ---

func TestExecRunnerRejectsUnknownCommand(t *testing.T) {
	_, err := ExecRunner{}.Run(context.Background(), Command{Name: "rm", Args: []string{"-rf", "/"}})
	if err == nil {
		t.Fatal("ein Kommando außerhalb der Allowlist muss abgewiesen werden")
	}
	if !strings.Contains(err.Error(), "nicht erlaubt") {
		t.Errorf("unerwartete Fehlermeldung: %v", err)
	}
}

// Der Kern des Sicherheitsversprechens: Argumente landen nie in einer Shell.
func TestExecRunnerDoesNotUseShell(t *testing.T) {
	if !isExecutable("/usr/bin/id") {
		t.Skip("id nicht vorhanden")
	}
	marker := filepath.Join(t.TempDir(), "pwned")

	// Enthielte der Aufruf eine Shell, würde der Teil nach dem Semikolon
	// ausgeführt und die Datei entstehen.
	res, err := ExecRunner{}.Run(context.Background(), Command{
		Name: "id",
		Args: []string{"--name; touch " + marker},
	})
	if err != nil {
		t.Fatalf("Run: %v", err)
	}
	if res.ExitCode == 0 {
		t.Error("das erfundene Argument hätte einen Fehler ergeben müssen")
	}
	if _, err := os.Stat(marker); err == nil {
		t.Fatal("das Argument wurde von einer Shell interpretiert")
	}
}

func TestExecRunnerCapturesExitCode(t *testing.T) {
	if !isExecutable("/usr/bin/id") {
		t.Skip("id nicht vorhanden")
	}
	res, err := ExecRunner{}.Run(context.Background(), Command{
		Name: "id",
		Args: []string{"--gibtsnicht"},
	})
	if err != nil {
		t.Fatalf("ein Exit-Code ungleich null ist kein Programmfehler: %v", err)
	}
	if res.ExitCode == 0 {
		t.Error("Exit-Code wurde nicht durchgereicht")
	}
	if res.Stderr == "" {
		t.Error("stderr wurde nicht eingesammelt")
	}
}

func TestExecRunnerTimeout(t *testing.T) {
	if !isExecutable("/usr/bin/id") {
		t.Skip("id nicht vorhanden")
	}
	// Ein bereits abgelaufener Kontext muss sofort greifen.
	ctx, cancel := context.WithTimeout(context.Background(), time.Nanosecond)
	defer cancel()
	time.Sleep(time.Millisecond)

	if _, err := (ExecRunner{}).Run(ctx, Command{Name: "id", Timeout: time.Second}); err == nil {
		t.Error("abgelaufener Kontext muss zum Fehler führen")
	}
}

func TestLimitedBufferCaps(t *testing.T) {
	b := &limitedBuffer{limit: 10}
	n, err := b.Write([]byte(strings.Repeat("x", 100)))
	if err != nil || n != 100 {
		t.Fatalf("Write = %d, %v — es muss die volle Länge gemeldet werden", n, err)
	}

	got := b.String()
	if !strings.HasPrefix(got, strings.Repeat("x", 10)) {
		t.Errorf("Inhalt = %q", got)
	}
	if !strings.Contains(got, "gekürzt") {
		t.Error("die Kürzung wird nicht kenntlich gemacht")
	}
}

// ------------------------------------------------------------- Validierung ---

func TestValidateUnit(t *testing.T) {
	valid := []string{"nginx.service", "ssh.service", "getty@tty1.service", "apt-daily.timer", "multi-user.target"}
	invalid := []string{
		"", "nginx", "nginx.service; rm -rf /", "../../etc/passwd",
		"-x.service", "nginx.service ", "nginx.exe", strings.Repeat("a", 300) + ".service",
	}

	for _, u := range valid {
		if err := ValidateUnit(u); err != nil {
			t.Errorf("%q wurde abgelehnt: %v", u, err)
		}
	}
	for _, u := range invalid {
		if err := ValidateUnit(u); err == nil {
			t.Errorf("%q wurde angenommen", u)
		}
	}
}

func TestValidatePackage(t *testing.T) {
	valid := []string{"nginx", "libssl3", "g++-12", "python3.11", "linux-image-generic"}
	invalid := []string{"", "-nginx", "nginx; rm", "NGINX", "n", "../etc", "pkg name"}

	for _, p := range valid {
		if err := ValidatePackage(p); err != nil {
			t.Errorf("%q wurde abgelehnt: %v", p, err)
		}
	}
	for _, p := range invalid {
		if err := ValidatePackage(p); err == nil {
			t.Errorf("%q wurde angenommen", p)
		}
	}
}

func TestValidateSystemUser(t *testing.T) {
	valid := []string{"philipp", "www-data", "_apt", "u1"}
	invalid := []string{"", "Philipp", "1philipp", "root; rm", "-x", strings.Repeat("a", 40), "phil ipp"}

	for _, u := range valid {
		if err := ValidateSystemUser(u); err != nil {
			t.Errorf("%q wurde abgelehnt: %v", u, err)
		}
	}
	for _, u := range invalid {
		if err := ValidateSystemUser(u); err == nil {
			t.Errorf("%q wurde angenommen", u)
		}
	}
}

func TestValidateRule(t *testing.T) {
	if err := ValidateRule(FirewallRule{Port: 443, Protocol: "tcp"}); err != nil {
		t.Errorf("gültige Regel abgelehnt: %v", err)
	}
	if err := ValidateRule(FirewallRule{Port: 443, Protocol: "tcp", Source: "203.0.113.0/24"}); err != nil {
		t.Errorf("gültige Regel mit Quelle abgelehnt: %v", err)
	}

	invalid := []FirewallRule{
		{Port: 0, Protocol: "tcp"},
		{Port: 70000, Protocol: "tcp"},
		{Port: 443, Protocol: "sctp"},
		{Port: 443, Protocol: "tcp", Source: "kein-netz"},
		{Port: 443, Protocol: "tcp", Comment: "mehrzeilig\nzweite Zeile"},
		{Port: 443, Protocol: "tcp", Comment: strings.Repeat("x", 200)},
	}
	for _, r := range invalid {
		if err := ValidateRule(r); err == nil {
			t.Errorf("ungültige Regel angenommen: %+v", r)
		}
	}
}

func TestValidateComment(t *testing.T) {
	if err := ValidateComment("Webserver HTTPS"); err != nil {
		t.Errorf("harmloser Kommentar abgelehnt: %v", err)
	}
	// Ein Doppelpunkt würde die Feldstruktur von /etc/passwd zerlegen.
	if err := ValidateComment("Vorname: Philipp"); err == nil {
		t.Error("Doppelpunkt wurde angenommen")
	}
}

func TestValidateSince(t *testing.T) {
	for _, ok := range []string{"today", "yesterday", "-1h", "-24h", "2026-07-26", "2026-07-26 12:00:00"} {
		if err := validateSince(ok); err != nil {
			t.Errorf("%q wurde abgelehnt: %v", ok, err)
		}
	}
	for _, bad := range []string{"gestern", "-1y", "$(date)", "2026-13-45"} {
		if err := validateSince(bad); err == nil {
			t.Errorf("%q wurde angenommen", bad)
		}
	}
}

func TestValidServiceAction(t *testing.T) {
	for _, a := range []ServiceAction{ServiceStart, ServiceStop, ServiceRestart, ServiceReload, ServiceEnable, ServiceDisable} {
		if !ValidServiceAction(a) {
			t.Errorf("%q wurde abgelehnt", a)
		}
	}
	for _, a := range []ServiceAction{"", "mask", "isolate", "kill", "daemon-reload"} {
		if ValidServiceAction(a) {
			t.Errorf("%q wurde angenommen", a)
		}
	}
}
