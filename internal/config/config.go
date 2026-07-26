// Package config lädt und validiert die Konfiguration aus /etc/asylum/config.yaml.
//
// Grundsatz: Die Datei ist optional. Ohne sie startet der Daemon mit sinnvollen
// Vorgaben. Umgebungsvariablen überschreiben die Datei, damit der Installer und
// cloud-init ohne Textmanipulation an YAML auskommen.
package config

import (
	"errors"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"gopkg.in/yaml.v3"
)

// DefaultPath ist der Ort, an dem der Installer die Konfiguration ablegt.
const DefaultPath = "/etc/asylum/config.yaml"

// Config bildet die gesamte Konfigurationsdatei ab.
type Config struct {
	Server  Server  `yaml:"server"`
	Paths   Paths   `yaml:"paths"`
	Log     Log     `yaml:"log"`
	Updates Updates `yaml:"updates"`
}

// Server beschreibt den HTTPS-Listener des Panels.
type Server struct {
	Bind string `yaml:"bind"`
	Port int    `yaml:"port"`
	TLS  TLS    `yaml:"tls"`
}

// TLS verweist auf das Zertifikat. Fehlen die Dateien, erzeugt der Daemon beim
// Start ein selbstsigniertes Paar.
type TLS struct {
	Cert string `yaml:"cert"`
	Key  string `yaml:"key"`
}

// Paths bündelt die Datenverzeichnisse.
type Paths struct {
	Data string `yaml:"data"`
	Log  string `yaml:"log"`
}

// Log steuert die Ausgabe nach stderr; journald übernimmt den Rest.
type Log struct {
	Level  string `yaml:"level"`
	Format string `yaml:"format"`
}

// Updates entspricht dem in docs/05-updates.md beschriebenen Block. In M0 wird
// er eingelesen und validiert, aber noch nicht ausgewertet.
type Updates struct {
	Channel   string `yaml:"channel"`
	Check     string `yaml:"check"`
	AutoApply string `yaml:"auto_apply"`
	Window    string `yaml:"window"`
}

// Default liefert die Vorgabekonfiguration.
func Default() Config {
	return Config{
		Server: Server{
			Bind: "0.0.0.0",
			Port: 8443,
			TLS: TLS{
				Cert: "/etc/asylum/tls/server.crt",
				Key:  "/etc/asylum/tls/server.key",
			},
		},
		Paths: Paths{
			Data: "/var/lib/asylum",
			Log:  "/var/log/asylum",
		},
		Log: Log{
			Level:  "info",
			Format: "text",
		},
		Updates: Updates{
			Channel:   "stable",
			Check:     "daily",
			AutoApply: "security",
			Window:    "03:00-05:00",
		},
	}
}

// Load liest die Konfigurationsdatei, legt die Umgebungsvariablen darüber und
// validiert das Ergebnis. Eine fehlende Datei ist kein Fehler.
func Load(path string) (Config, error) {
	cfg := Default()

	if path != "" {
		raw, err := os.ReadFile(path) //nolint:gosec // Pfad stammt aus der Konfiguration, nicht aus einer Anfrage
		switch {
		case err == nil:
			dec := yaml.NewDecoder(strings.NewReader(string(raw)))
			dec.KnownFields(true)
			if err := dec.Decode(&cfg); err != nil {
				return Config{}, fmt.Errorf("%s: %w", path, err)
			}
		case errors.Is(err, os.ErrNotExist):
			// Vorgaben behalten.
		default:
			return Config{}, fmt.Errorf("%s: %w", path, err)
		}
	}

	if err := cfg.applyEnv(); err != nil {
		return Config{}, err
	}
	if err := cfg.Validate(); err != nil {
		return Config{}, err
	}
	return cfg, nil
}

// applyEnv wertet die ASYLUM_*-Variablen aus, die auch der Installer setzt.
func (c *Config) applyEnv() error {
	if v := os.Getenv("ASYLUM_BIND"); v != "" {
		c.Server.Bind = v
	}
	if v := os.Getenv("ASYLUM_PORT"); v != "" {
		port, err := strconv.Atoi(v)
		if err != nil {
			return fmt.Errorf("ASYLUM_PORT=%q ist keine Zahl", v)
		}
		c.Server.Port = port
	}
	if v := os.Getenv("ASYLUM_TLS_CERT"); v != "" {
		c.Server.TLS.Cert = v
	}
	if v := os.Getenv("ASYLUM_TLS_KEY"); v != "" {
		c.Server.TLS.Key = v
	}
	if v := os.Getenv("ASYLUM_DATA_DIR"); v != "" {
		c.Paths.Data = v
	}
	if v := os.Getenv("ASYLUM_LOG_LEVEL"); v != "" {
		c.Log.Level = v
	}
	return nil
}

// Validate prüft die Werte, bevor irgendetwas geöffnet oder angelegt wird.
func (c Config) Validate() error {
	if c.Server.Port < 1 || c.Server.Port > 65535 {
		return fmt.Errorf("server.port: %d liegt außerhalb von 1–65535", c.Server.Port)
	}
	if c.Server.Bind != "" && net.ParseIP(c.Server.Bind) == nil {
		return fmt.Errorf("server.bind: %q ist keine IP-Adresse", c.Server.Bind)
	}
	for name, p := range map[string]string{
		"server.tls.cert": c.Server.TLS.Cert,
		"server.tls.key":  c.Server.TLS.Key,
		"paths.data":      c.Paths.Data,
		"paths.log":       c.Paths.Log,
	} {
		if p == "" {
			return fmt.Errorf("%s darf nicht leer sein", name)
		}
		if !filepath.IsAbs(p) {
			return fmt.Errorf("%s: %q muss ein absoluter Pfad sein", name, p)
		}
	}
	switch c.Log.Level {
	case "debug", "info", "warn", "error":
	default:
		return fmt.Errorf("log.level: %q ist unbekannt (debug|info|warn|error)", c.Log.Level)
	}
	switch c.Log.Format {
	case "text", "json":
	default:
		return fmt.Errorf("log.format: %q ist unbekannt (text|json)", c.Log.Format)
	}
	switch c.Updates.Channel {
	case "stable", "beta", "nightly":
	default:
		return fmt.Errorf("updates.channel: %q ist unbekannt (stable|beta|nightly)", c.Updates.Channel)
	}
	switch c.Updates.AutoApply {
	case "none", "security", "patch", "all":
	default:
		return fmt.Errorf("updates.auto_apply: %q ist unbekannt (none|security|patch|all)", c.Updates.AutoApply)
	}
	return nil
}

// Addr liefert die Listen-Adresse für net.Listen.
func (c Config) Addr() string {
	return net.JoinHostPort(c.Server.Bind, strconv.Itoa(c.Server.Port))
}
