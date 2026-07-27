package config

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestLoadMissingFileUsesDefaults(t *testing.T) {
	cfg, err := Load(filepath.Join(t.TempDir(), "gibtsnicht.yaml"))
	if err != nil {
		t.Fatalf("fehlende Datei darf kein Fehler sein: %v", err)
	}
	if got, want := cfg.Server.Port, 8443; got != want {
		t.Errorf("Port = %d, erwartet %d", got, want)
	}
	if got, want := cfg.Updates.AutoApply, "security"; got != want {
		t.Errorf("AutoApply = %q, erwartet %q", got, want)
	}
}

func TestLoadFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config.yaml")
	body := `
server:
  bind: "127.0.0.1"
  port: 9443
log:
  level: debug
updates:
  channel: beta
`
	if err := os.WriteFile(path, []byte(body), 0o600); err != nil {
		t.Fatal(err)
	}

	cfg, err := Load(path)
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if cfg.Server.Bind != "127.0.0.1" || cfg.Server.Port != 9443 {
		t.Errorf("Adresse = %s, erwartet 127.0.0.1:9443", cfg.Addr())
	}
	if cfg.Log.Level != "debug" || cfg.Updates.Channel != "beta" {
		t.Errorf("Datei nicht vollständig übernommen: %+v", cfg)
	}
	// Nicht gesetzte Felder müssen ihre Vorgabe behalten.
	if cfg.Server.TLS.Cert != "/etc/asylum/tls/server.crt" {
		t.Errorf("TLS-Vorgabe überschrieben: %q", cfg.Server.TLS.Cert)
	}
}

func TestLoadRejectsUnknownFields(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config.yaml")
	if err := os.WriteFile(path, []byte("server:\n  prot: 8443\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := Load(path); err == nil {
		t.Fatal("Tippfehler im Schlüssel muss einen Fehler geben, nicht still ignoriert werden")
	}
}

func TestEnvOverridesFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "config.yaml")
	if err := os.WriteFile(path, []byte("server:\n  port: 9443\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	t.Setenv("ASYLUM_PORT", "10443")
	t.Setenv("ASYLUM_BIND", "127.0.0.1")

	cfg, err := Load(path)
	if err != nil {
		t.Fatalf("Load: %v", err)
	}
	if got, want := cfg.Addr(), "127.0.0.1:10443"; got != want {
		t.Errorf("Addr() = %q, erwartet %q", got, want)
	}
}

func TestEnvPortMustBeNumeric(t *testing.T) {
	t.Setenv("ASYLUM_PORT", "achttausend")
	if _, err := Load(""); err == nil {
		t.Fatal("nicht-numerischer Port muss abgelehnt werden")
	}
}

func TestValidate(t *testing.T) {
	tests := map[string]struct {
		mutate  func(*Config)
		wantErr string
	}{
		"Port zu groß":        {func(c *Config) { c.Server.Port = 70000 }, "server.port"},
		"Port null":           {func(c *Config) { c.Server.Port = 0 }, "server.port"},
		"Bind kein IP":        {func(c *Config) { c.Server.Bind = "example.org" }, "server.bind"},
		"relativer Pfad":      {func(c *Config) { c.Paths.Data = "var/lib/asylum" }, "paths.data"},
		"leerer Pfad":         {func(c *Config) { c.Server.TLS.Key = "" }, "server.tls.key"},
		"Log-Level unbekannt": {func(c *Config) { c.Log.Level = "laut" }, "log.level"},
		"Kanal unbekannt":     {func(c *Config) { c.Updates.Channel = "experimentell" }, "updates.channel"},
		"AutoApply unbekannt": {func(c *Config) { c.Updates.AutoApply = "immer" }, "updates.auto_apply"},
	}

	for name, tc := range tests {
		t.Run(name, func(t *testing.T) {
			cfg := Default()
			tc.mutate(&cfg)
			err := cfg.Validate()
			if err == nil {
				t.Fatalf("erwarteter Fehler zu %s blieb aus", tc.wantErr)
			}
			if !strings.Contains(err.Error(), tc.wantErr) {
				t.Errorf("Fehlermeldung %q nennt %q nicht", err, tc.wantErr)
			}
		})
	}
}

func TestDefaultIsValid(t *testing.T) {
	if err := Default().Validate(); err != nil {
		t.Fatalf("Vorgabekonfiguration muss gültig sein: %v", err)
	}
}

func TestAddrEmptyBindListensEverywhere(t *testing.T) {
	cfg := Default()
	cfg.Server.Bind = ""
	if err := cfg.Validate(); err != nil {
		t.Fatalf("leeres bind muss erlaubt sein: %v", err)
	}
	if got, want := cfg.Addr(), ":8443"; got != want {
		t.Errorf("Addr() = %q, erwartet %q", got, want)
	}
}

// Die ASYLUM_*-Variablen sind die Schnittstelle des Installers: Er schreibt
// keine Konfigurationsdatei, sondern setzt sie in der systemd-Unit. Kommt eine
// davon nicht an, läuft der Dienst mit einer Vorgabe weiter, die niemand
// gewählt hat — etwa mit dem falschen Datenverzeichnis.
func TestUmgebungsvariablenDesInstallers(t *testing.T) {
	t.Setenv("ASYLUM_TLS_CERT", "/eigen/panel.crt")
	t.Setenv("ASYLUM_TLS_KEY", "/eigen/panel.key")
	t.Setenv("ASYLUM_DATA_DIR", "/srv/asylum")
	t.Setenv("ASYLUM_LOG_LEVEL", "debug")
	t.Setenv("ASYLUM_UPDATE_CHANNEL", "beta")
	t.Setenv("ASYLUM_UPDATE_BASE_URL", "https://updates.example.org")

	cfg, err := Load(schreibeConfig(t, ""))
	if err != nil {
		t.Fatal(err)
	}
	for _, f := range []struct {
		name string
		ist  string
		soll string
	}{
		{"server.tls.cert", cfg.Server.TLS.Cert, "/eigen/panel.crt"},
		{"server.tls.key", cfg.Server.TLS.Key, "/eigen/panel.key"},
		{"paths.data", cfg.Paths.Data, "/srv/asylum"},
		{"log.level", cfg.Log.Level, "debug"},
		{"updates.channel", cfg.Updates.Channel, "beta"},
		{"updates.base_url", cfg.Updates.BaseURL, "https://updates.example.org"},
	} {
		if f.ist != f.soll {
			t.Errorf("%s = %q, erwartet %q", f.name, f.ist, f.soll)
		}
	}
}
