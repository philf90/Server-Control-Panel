package acme

import (
	"context"
	"errors"
	"sync"
	"testing"
	"time"
)

// fakeSetter merkt sich Aufrufe und die zuletzt gesetzten Werte.
type fakeSetter struct {
	mu        sync.Mutex
	set       int
	removed   int
	lastRec   string
	lastVal   string
	setErr    error
	removeErr error
}

func (f *fakeSetter) setTXT(_ context.Context, _, record, value string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.set++
	f.lastRec, f.lastVal = record, value
	return f.setErr
}

func (f *fakeSetter) removeTXT(_ context.Context, _, record, _ string) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.removed++
	f.lastRec = record
	return f.removeErr
}

func newTestDNSSolver(setter dnsSetter) *dns01Solver {
	s := newDNS01Solver(setter, discardLogger(), reporter{})
	s.waitTimeout = 20 * time.Millisecond
	s.pollEvery = 1 * time.Millisecond
	return s
}

func TestDNS01PresentSetsRecordAndWaits(t *testing.T) {
	setter := &fakeSetter{}
	s := newTestDNSSolver(setter)
	// TXT sofort sichtbar → kein Warten.
	s.lookupTXT = func(context.Context, string) ([]string, error) {
		return []string{"anderer-wert", "der-txt-wert"}, nil
	}

	if err := s.present(context.Background(), "panel.example.test", "tok", "der-txt-wert"); err != nil {
		t.Fatal(err)
	}
	if setter.set != 1 {
		t.Errorf("setTXT %d-mal, erwartet 1", setter.set)
	}
	if setter.lastRec != "_acme-challenge.panel.example.test" {
		t.Errorf("Record = %q", setter.lastRec)
	}
	if setter.lastVal != "der-txt-wert" {
		t.Errorf("Wert = %q", setter.lastVal)
	}
}

func TestDNS01PresentProceedsOnTimeout(t *testing.T) {
	setter := &fakeSetter{}
	s := newTestDNSSolver(setter)
	// Nie sichtbar → nach der kurzen Frist trotzdem fortfahren, ohne Fehler.
	s.lookupTXT = func(context.Context, string) ([]string, error) {
		return nil, errors.New("nxdomain")
	}
	if err := s.present(context.Background(), "panel.example.test", "tok", "wert"); err != nil {
		t.Fatalf("present sollte trotz Ausbreitungs-Timeout ohne Fehler zurückkehren: %v", err)
	}
	if setter.set != 1 {
		t.Errorf("setTXT %d-mal, erwartet 1", setter.set)
	}
}

func TestDNS01PresentFailsWhenSetterFails(t *testing.T) {
	setter := &fakeSetter{setErr: errors.New("api down")}
	s := newTestDNSSolver(setter)
	if err := s.present(context.Background(), "panel.example.test", "tok", "wert"); err == nil {
		t.Error("present sollte scheitern, wenn der Setzer scheitert")
	}
}

func TestDNS01Cleanup(t *testing.T) {
	setter := &fakeSetter{}
	s := newTestDNSSolver(setter)
	if err := s.cleanup(context.Background(), "panel.example.test", "tok", "wert"); err != nil {
		t.Fatal(err)
	}
	if setter.removed != 1 {
		t.Errorf("removeTXT %d-mal, erwartet 1", setter.removed)
	}
}

func TestSolverFactoryDNS01Selection(t *testing.T) {
	// Automatisch mit konfiguriertem Anbieter → dns-01.
	f, err := solverFactory(Options{DNS01Provider: providerHook, HookSet: "/bin/true", HookClean: "/bin/true"}, discardLogger(), reporter{})
	if err != nil {
		t.Fatalf("automatische Wahl mit Anbieter: %v", err)
	}
	solver, err := f(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if solver.challengeType() != "dns-01" {
		t.Errorf("challengeType = %q, erwartet dns-01", solver.challengeType())
	}

	// Ausdrücklich dns-01 ohne Anbieter → Fehler.
	if _, err := solverFactory(Options{Challenge: "dns-01"}, discardLogger(), reporter{}); err == nil {
		t.Error("dns-01 ohne Anbieter sollte einen Fehler ergeben")
	}

	// Automatisch ohne Anbieter → http-01 (kein Fehler beim Bau der Factory).
	if _, err := solverFactory(Options{}, discardLogger(), reporter{}); err != nil {
		t.Errorf("automatische Wahl ohne Anbieter sollte http-01 ergeben: %v", err)
	}
}

func TestNewDNSSetter(t *testing.T) {
	if _, err := newDNSSetter(Options{DNS01Provider: providerHook, HookSet: "/s"}); err == nil {
		t.Error("Hook ohne clean sollte scheitern")
	}
	if _, err := newDNSSetter(Options{DNS01Provider: "route53"}); err == nil {
		t.Error("unbekannter Anbieter sollte scheitern")
	}
	if _, err := newDNSSetter(Options{DNS01Provider: providerCloudflare, ZugangsDatei: "/gibt/es/nicht"}); err == nil {
		t.Error("fehlende Zugangsdatei sollte scheitern")
	}
	if _, err := newDNSSetter(Options{DNS01Provider: providerCloudflare}); err == nil {
		t.Error("Anbieter ohne Zugangsdatei sollte scheitern")
	}
}
