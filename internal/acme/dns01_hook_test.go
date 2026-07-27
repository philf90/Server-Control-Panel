package acme

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// writeHook legt ein Skript an, das seine Argumente und die ACME-Umgebung in
// eine Datei schreibt, damit der Test prüfen kann, womit der Hook gerufen wurde.
func writeHook(t *testing.T, outFile string) string {
	t.Helper()
	script := "#!/bin/sh\n" +
		"echo \"args: $*\" >> " + outFile + "\n" +
		"echo \"env: $ASYLUM_ACME_ACTION $ASYLUM_ACME_DOMAIN $ASYLUM_ACME_RECORD $ASYLUM_ACME_VALUE\" >> " + outFile + "\n"
	path := filepath.Join(t.TempDir(), "hook.sh")
	if err := os.WriteFile(path, []byte(script), 0o700); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestHookSetterInvokesScript(t *testing.T) {
	out := filepath.Join(t.TempDir(), "calls.log")
	hook := writeHook(t, out)
	setter := &hookSetter{set: hook, clean: hook}

	if err := setter.setTXT(context.Background(), "panel.example.test", "_acme-challenge.panel.example.test", "der-wert"); err != nil {
		t.Fatal(err)
	}
	if err := setter.removeTXT(context.Background(), "panel.example.test", "_acme-challenge.panel.example.test", "der-wert"); err != nil {
		t.Fatal(err)
	}

	raw, err := os.ReadFile(out)
	if err != nil {
		t.Fatal(err)
	}
	log := string(raw)
	for _, want := range []string{
		"args: set _acme-challenge.panel.example.test der-wert",
		"args: clean _acme-challenge.panel.example.test der-wert",
		"env: set panel.example.test _acme-challenge.panel.example.test der-wert",
		"env: clean panel.example.test _acme-challenge.panel.example.test der-wert",
	} {
		if !strings.Contains(log, want) {
			t.Errorf("Hook-Protokoll enthält %q nicht.\nProtokoll:\n%s", want, log)
		}
	}
}

func TestHookSetterReportsFailure(t *testing.T) {
	setter := &hookSetter{set: "/bin/false", clean: "/bin/false"}
	if err := setter.setTXT(context.Background(), "d", "r", "v"); err == nil {
		t.Error("ein fehlschlagender Hook sollte einen Fehler liefern")
	}
}
