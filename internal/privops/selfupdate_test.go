package privops

import (
	"context"
	"strings"
	"testing"
)

func TestSelfUpdateStartBautDenAufruf(t *testing.T) {
	var got Command
	sys := NewSystemWithRunner(runnerFunc(func(_ context.Context, c Command) (Result, error) {
		got = c
		return Result{}, nil
	}))

	err := sys.SelfUpdateStart(context.Background(), SelfUpdateSpec{
		Binary:  "/usr/local/lib/asylum/asylumd",
		Unit:    "asylum-update-1234",
		Channel: "stable",
		Version: "0.2.0",
		LogFile: "/var/log/asylum/update.log",
	})
	if err != nil {
		t.Fatalf("SelfUpdateStart: %v", err)
	}

	if got.Name != "systemd-run" {
		t.Errorf("Kommando = %q", got.Name)
	}
	line := strings.Join(got.Args, " ")
	for _, want := range []string{
		"--unit=asylum-update-1234",
		"--collect",
		"--property=Type=oneshot",
		"/usr/local/lib/asylum/asylumd",
		"update",
		"--assume-yes",
		"--channel=stable",
		"--version=0.2.0",
		"--log=/var/log/asylum/update.log",
	} {
		if !strings.Contains(line, want) {
			t.Errorf("im Aufruf fehlt %q: %s", want, line)
		}
	}
	// Der Programmpfad muss vor den Argumenten des Programms stehen, sonst
	// liest systemd-run sie als eigene Optionen.
	if idxBinary, idxUpdate := indexOf(got.Args, "/usr/local/lib/asylum/asylumd"), indexOf(got.Args, "update"); idxBinary > idxUpdate {
		t.Errorf("das Programm steht hinter seinen Argumenten: %v", got.Args)
	}
}

func TestSelfUpdateStartRollback(t *testing.T) {
	var got Command
	sys := NewSystemWithRunner(runnerFunc(func(_ context.Context, c Command) (Result, error) {
		got = c
		return Result{}, nil
	}))

	err := sys.SelfUpdateStart(context.Background(), SelfUpdateSpec{
		Binary:   "/usr/lib/asylum/asylumd",
		Unit:     "asylum-rollback-1",
		Rollback: true,
	})
	if err != nil {
		t.Fatalf("SelfUpdateStart: %v", err)
	}
	line := strings.Join(got.Args, " ")
	if !strings.Contains(line, "rollback") {
		t.Errorf("kein rollback im Aufruf: %s", line)
	}
	if strings.Contains(line, "--channel") {
		t.Errorf("ein Rollback braucht keinen Kanal: %s", line)
	}
}

func TestSelfUpdateSpecAbweisungen(t *testing.T) {
	valid := SelfUpdateSpec{
		Binary:  "/usr/lib/asylum/asylumd",
		Unit:    "asylum-update-1",
		Channel: "stable",
	}

	tests := map[string]func(s *SelfUpdateSpec){
		"relativer Programmpfad":  func(s *SelfUpdateSpec) { s.Binary = "asylumd" },
		"leerer Programmpfad":     func(s *SelfUpdateSpec) { s.Binary = "" },
		"Unit mit Leerzeichen":    func(s *SelfUpdateSpec) { s.Unit = "asylum update" },
		"Unit mit Schrägstrich":   func(s *SelfUpdateSpec) { s.Unit = "../etc/passwd" },
		"leere Unit":              func(s *SelfUpdateSpec) { s.Unit = "" },
		"unbekannter Kanal":       func(s *SelfUpdateSpec) { s.Channel = "nightly" },
		"leerer Kanal":            func(s *SelfUpdateSpec) { s.Channel = "" },
		"Version mit Option":      func(s *SelfUpdateSpec) { s.Version = "0.2.0 --config=/tmp/x" },
		"Version mit Schrägstich": func(s *SelfUpdateSpec) { s.Version = "../../etc" },
		"relatives Protokoll":     func(s *SelfUpdateSpec) { s.LogFile = "update.log" },
	}

	sys := NewSystemWithRunner(runnerFunc(func(context.Context, Command) (Result, error) {
		t.Error("bei unzulässiger Angabe darf kein Kommando laufen")
		return Result{}, nil
	}))

	for name, breakIt := range tests {
		t.Run(name, func(t *testing.T) {
			spec := valid
			breakIt(&spec)
			if err := sys.SelfUpdateStart(context.Background(), spec); err == nil {
				t.Fatal("Fehler erwartet")
			}
		})
	}
}

func TestSelfUpdateStartMeldetFehlschlag(t *testing.T) {
	sys := NewSystemWithRunner(runnerFunc(func(context.Context, Command) (Result, error) {
		return Result{ExitCode: 1, Stderr: "Unit asylum-update-1.service already exists."}, nil
	}))
	err := sys.SelfUpdateStart(context.Background(), SelfUpdateSpec{
		Binary: "/usr/lib/asylum/asylumd", Unit: "asylum-update-1", Channel: "stable",
	})
	if err == nil {
		t.Fatal("Fehler erwartet")
	}
	if !strings.Contains(err.Error(), "already exists") {
		t.Errorf("die Meldung von systemd-run fehlt: %v", err)
	}
}

func TestSystemdRunInDerAllowlist(t *testing.T) {
	if _, ok := allowedCommands["systemd-run"]; !ok {
		t.Fatal("systemd-run fehlt in der Allowlist — der Aufruf würde abgewiesen")
	}
}

func indexOf(list []string, want string) int {
	for i, v := range list {
		if v == want {
			return i
		}
	}
	return -1
}

// runnerFunc macht aus einer Funktion einen Runner.
type runnerFunc func(context.Context, Command) (Result, error)

func (f runnerFunc) Run(ctx context.Context, c Command) (Result, error) { return f(ctx, c) }
