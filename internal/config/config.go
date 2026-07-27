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
	"net/url"
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
	ACME    ACME    `yaml:"acme"`
}

// Server beschreibt den HTTPS-Listener des Panels.
type Server struct {
	Bind string `yaml:"bind"`
	Port int    `yaml:"port"`
	TLS  TLS    `yaml:"tls"`
}

// TLS-Modi.
const (
	// TLSModeSelfSigned: der Daemon erzeugt beim ersten Start ein
	// selbstsigniertes Paar (Vorgabe).
	TLSModeSelfSigned = "selfsigned"
	// TLSModeACME: das Zertifikat kommt von einer ACME-Instanz (Let's Encrypt).
	// Schlägt der Bezug fehl, fällt der Daemon aufs selbstsignierte Paar zurück.
	TLSModeACME = "acme"
)

// TLS steuert die Herkunft des Zertifikats. `mode: selfsigned` ist die Vorgabe;
// fehlen die Dateien, erzeugt der Daemon beim Start ein selbstsigniertes Paar.
type TLS struct {
	Mode string `yaml:"mode"`
	Cert string `yaml:"cert"`
	Key  string `yaml:"key"`
}

// ACME beschreibt den Bezug eines Zertifikats über das ACME-Protokoll
// (Let's Encrypt). Der Block wird nur ausgewertet, wenn `server.tls.mode: acme`
// gesetzt ist.
type ACME struct {
	// Email ist die Kontaktadresse des ACME-Kontos (Ablaufwarnungen).
	Email string `yaml:"email"`
	// Domains sind die Namen im Zertifikat. Leer = der vollqualifizierte
	// Rechnername (netinfo.FQDN()), zur Laufzeit ermittelt.
	Domains []string `yaml:"domains"`
	// DirectoryURL überschreibt das ACME-Verzeichnis, etwa für das
	// Staging-System beim Testen. Leer = Let's-Encrypt-Produktion.
	DirectoryURL string `yaml:"directory_url"`
	// Challenge: leer = automatisch (DNS-01, wenn ein Anbieter gesetzt ist,
	// sonst HTTP-01, falls Port 80 frei ist), oder ausdrücklich http-01/dns-01.
	Challenge string     `yaml:"challenge"`
	HTTP01    ACMEHTTP01 `yaml:"http01"`
	DNS01     ACMEDNS01  `yaml:"dns01"`
}

// ACMEHTTP01 steuert die HTTP-01-Prüfung über Port 80.
type ACMEHTTP01 struct {
	// OpenFirewall öffnet für die Dauer der Prüfung kurz Port 80 über ufw.
	OpenFirewall bool `yaml:"open_firewall"`
}

// ACMEDNS01 steuert die DNS-01-Prüfung.
type ACMEDNS01 struct {
	// Provider: hook (Betreiber-Skript) oder cloudflare (eingebaute HTTP-API).
	Provider   string         `yaml:"provider"`
	Hook       ACMEHook       `yaml:"hook"`
	Cloudflare ACMECloudflare `yaml:"cloudflare"`
}

// ACMEHook ruft ein vom Betreiber gestelltes Programm, das den TXT-Record
// setzt und wieder entfernt. So bleibt kein Anbieter im Binary.
type ACMEHook struct {
	Set   string `yaml:"set"`
	Clean string `yaml:"clean"`
}

// ACMECloudflare setzt den TXT-Record über die Cloudflare-API.
type ACMECloudflare struct {
	// APITokenFile ist der Pfad zu einer Datei mit dem API-Token (0600).
	// Bewusst kein Token im Klartext in der Konfiguration.
	APITokenFile string `yaml:"api_token_file"`
}

// DNS-01-Anbieter.
const (
	DNS01ProviderHook       = "hook"
	DNS01ProviderCloudflare = "cloudflare"
)

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

// Updates entspricht dem in docs/05-updates.md beschriebenen Block.
type Updates struct {
	Channel   string `yaml:"channel"`
	Check     string `yaml:"check"`
	AutoApply string `yaml:"auto_apply"`
	Window    string `yaml:"window"`
	// BaseURL ist der Ort der Update-Metadaten. Er ist einstellbar, damit ein
	// Betreiber einen eigenen Spiegel setzen kann; die Signaturprüfung bleibt
	// davon unberührt, der Schlüssel steckt im Binary.
	BaseURL string `yaml:"base_url"`
}

// Default liefert die Vorgabekonfiguration.
func Default() Config {
	return Config{
		Server: Server{
			Bind: "0.0.0.0",
			Port: 8443,
			TLS: TLS{
				Mode: TLSModeSelfSigned,
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
			BaseURL:   "https://repo.cloudsrv24.de",
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
	if v := os.Getenv("ASYLUM_UPDATE_CHANNEL"); v != "" {
		c.Updates.Channel = v
	}
	if v := os.Getenv("ASYLUM_UPDATE_BASE_URL"); v != "" {
		c.Updates.BaseURL = v
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
	// Es gibt genau zwei Kanäle, weil die Freigabepipeline genau zwei bedient.
	// Ein dritter Name in der Konfiguration wäre eine Zusage, die niemand
	// einlöst — der Fehler fiele erst beim Update auf.
	switch c.Updates.Channel {
	case "stable", "beta":
	default:
		return fmt.Errorf("updates.channel: %q ist unbekannt (stable|beta)", c.Updates.Channel)
	}
	if u, err := url.Parse(c.Updates.BaseURL); err != nil || u.Scheme != "https" || u.Host == "" {
		return fmt.Errorf("updates.base_url: %q ist keine https-Adresse", c.Updates.BaseURL)
	}
	switch c.Updates.AutoApply {
	case "none", "security", "patch", "all":
	default:
		return fmt.Errorf("updates.auto_apply: %q ist unbekannt (none|security|patch|all)", c.Updates.AutoApply)
	}

	// Leerer Modus gilt als selfsigned: Eine Konfiguration aus der Zeit vor
	// diesem Feld darf nicht ungültig werden.
	switch c.Server.TLS.Mode {
	case "", TLSModeSelfSigned:
	case TLSModeACME:
		if err := c.ACME.validate(); err != nil {
			return err
		}
	default:
		return fmt.Errorf("server.tls.mode: %q ist unbekannt (selfsigned|acme)", c.Server.TLS.Mode)
	}
	return nil
}

// validate prüft den ACME-Block. Aufgerufen nur, wenn der Modus acme ist —
// sonst darf der Block unvollständig bleiben, ohne den Start zu verhindern.
func (a ACME) validate() error {
	if a.Email == "" {
		return errors.New("acme.email darf im Modus acme nicht leer sein")
	}
	if a.DirectoryURL != "" {
		if u, err := url.Parse(a.DirectoryURL); err != nil || u.Scheme != "https" || u.Host == "" {
			return fmt.Errorf("acme.directory_url: %q ist keine https-Adresse", a.DirectoryURL)
		}
	}
	switch a.Challenge {
	case "", "http-01", "dns-01":
	default:
		return fmt.Errorf("acme.challenge: %q ist unbekannt (leer=automatisch|http-01|dns-01)", a.Challenge)
	}

	switch a.DNS01.Provider {
	case "":
		// Kein DNS-Anbieter konfiguriert. Nur ein Widerspruch, wenn dns-01
		// ausdrücklich verlangt ist — bei automatischer Wahl bleibt HTTP-01.
		if a.Challenge == "dns-01" {
			return errors.New("acme.challenge ist dns-01, aber acme.dns01.provider ist leer")
		}
	case DNS01ProviderHook:
		if a.DNS01.Hook.Set == "" || a.DNS01.Hook.Clean == "" {
			return errors.New("acme.dns01.hook: set und clean müssen gesetzt sein")
		}
	case DNS01ProviderCloudflare:
		if a.DNS01.Cloudflare.APITokenFile == "" {
			return errors.New("acme.dns01.cloudflare.api_token_file darf nicht leer sein")
		}
	default:
		return fmt.Errorf("acme.dns01.provider: %q ist unbekannt (hook|cloudflare)", a.DNS01.Provider)
	}
	return nil
}

// Addr liefert die Listen-Adresse für net.Listen.
func (c Config) Addr() string {
	return net.JoinHostPort(c.Server.Bind, strconv.Itoa(c.Server.Port))
}
