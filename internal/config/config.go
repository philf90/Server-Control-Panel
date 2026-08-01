// Package config lädt und validiert die Konfiguration aus /etc/asylum/config.yaml.
//
// Grundsatz: Die Datei ist optional. Ohne sie startet der Daemon mit sinnvollen
// Vorgaben. Umgebungsvariablen überschreiben die Datei, damit der Installer und
// cloud-init ohne Textmanipulation an YAML auskommen.
package config

import (
	"errors"
	"fmt"
	"io"
	"net"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"gopkg.in/yaml.v3"

	// Die Abhängigkeit geht bewusst in DIESE Richtung. internal/acme kennt
	// internal/config nicht und soll es nicht kennen — deshalb stehen die
	// Anbieternamen dort als eigene Konstanten (siehe dns01.go). Umgekehrt darf
	// config das acme-Paket kennen: Es prüft ohnehin schon ACME-Semantik
	// (Anbieternamen, Challenge-Typen), und die Namensprüfung zweimal zu
	// schreiben wäre die schlechtere der beiden Antworten.
	"github.com/philf90/asylum/internal/acme"
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
	Auth    Auth    `yaml:"auth"`
	Files   Files   `yaml:"files"`

	// SourcePath ist die Datei, aus der geladen wurde. Kein YAML-Feld: Das
	// Panel braucht den Pfad, um seine Ergänzung daneben zu schreiben, und
	// niemand soll ihn in der Datei selbst setzen können.
	SourcePath string `yaml:"-"`
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
	// Provider ist der Name des Anbieters: `hook` (Betreiber-Skript) oder einer
	// der eingebauten. Welche das sind, sagt acme.AnbieterNamen() — die Liste
	// steht im Register des acme-Pakets und nicht ein zweites Mal hier.
	Provider string   `yaml:"provider"`
	Hook     ACMEHook `yaml:"hook"`

	// CredentialsFile ist der Pfad zur Zugangsdatei des Anbieters (0600).
	//
	// EIN Feld für alle eingebauten Anbieter, und das ist eine Entscheidung mit
	// Grund: Sieben Anbieter hätten sonst sieben Blöcke mit zusammen zwanzig
	// Feldern, davon die Hälfte Geheimnisse. So steht in der Konfiguration nie
	// eines — sie liegt in /etc, wird gesichert und in Fehlerberichte kopiert.
	// Was in der Datei stehen muss, sagt der Anbieter (acme.Anbieterliste()).
	CredentialsFile string `yaml:"credentials_file"`

	// Cloudflare ist der Weg von 0.5 und bleibt lesbar, damit eine vorhandene
	// Konfiguration weiterläuft. Neu geschrieben wird credentials_file; steht
	// beides da, gewinnt das neue Feld.
	//
	// Deprecated: acme.dns01.credentials_file benutzen.
	Cloudflare ACMECloudflare `yaml:"cloudflare"`
}

// ZugangsDatei liefert den Pfad zur Zugangsdatei — mit dem alten
// Cloudflare-Feld als Rückfall.
//
// Der Rückfall steht hier und nicht beim Aufrufer: Sonst müsste jede Stelle,
// die den Pfad braucht, an den Übergang denken, und eine davon vergäße ihn.
func (d ACMEDNS01) ZugangsDatei() string {
	if d.CredentialsFile != "" {
		return d.CredentialsFile
	}
	return d.Cloudflare.APITokenFile
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
		Auth: Auth{
			WebAuthn: WebAuthn{
				DisplayName: "Project Asylum",
			},
		},
	}
}

// Auth bündelt die Einstellungen der Anmeldung, die über Passwort und TOTP
// hinausgehen.
type Auth struct {
	WebAuthn WebAuthn `yaml:"webauthn"`
}

// WebAuthn steuert die Passkeys. Sie sind ein zusätzlicher zweiter Faktor neben
// TOTP.
type WebAuthn struct {
	// Enabled schaltet den Passkey-Pfad frei. Drei Zustände: nicht gesetzt
	// bedeutet automatisch — Passkeys sind an, sobald ein auflösbarer Name als
	// RP-ID feststeht (aus Zertifikat, ACME-Domain oder FQDN). true erzwingt
	// sie (und verlangt einen Namen), false schaltet sie aus. So braucht der
	// Normalfall keinen Eintrag.
	Enabled *bool `yaml:"enabled"`
	// RPID ist die registrierbare Domain, an die ein Passkey gebunden wird.
	// Leer heißt: zur Laufzeit aus dem vollqualifizierten Rechnernamen bzw. den
	// Zertifikatsnamen ableiten. Über eine IP funktioniert WebAuthn nicht.
	RPID string `yaml:"rp_id"`
	// DisplayName steht im Anmeldedialog des Browsers.
	DisplayName string `yaml:"display_name"`
	// Origins sind die erlaubten vollständigen Ursprünge. Leer heißt: zur
	// Laufzeit aus RPID und Panel-Port bilden. Wer hinter einem Reverse-Proxy
	// unter einem anderen Ursprung erreichbar ist, trägt ihn hier ein.
	Origins []string `yaml:"origins"`
}

// Files steuert den Dateimanager.
//
// Ohne Eintrag gilt: Lesen im gesamten Dateisystem, Schreiben in den Bereichen,
// die die systemd-Unit zulässt (/usr und /boot bleiben über ProtectSystem=true
// schreibgeschützt). Wer das einschränken will, trägt eigene Wurzeln ein; wer
// den Dateimanager gar nicht möchte, setzt `enabled: false` — das entfernt
// Routen und Rechte, nicht nur den Menüpunkt.
type Files struct {
	// Enabled: nicht gesetzt bedeutet an. false schaltet das Modul vollständig
	// ab.
	Enabled *bool `yaml:"enabled"`
	// ReadableRoots sind die Bäume, die überhaupt sichtbar sind. Leer = "/".
	ReadableRoots []string `yaml:"readable_roots"`
	// WritableRoots sind die Bäume, in denen geändert werden darf. Leer nimmt
	// die Vorgabe; eine leere Liste ausdrücklich zu setzen ist über
	// `writable_roots: []` möglich und macht den Dateimanager nur lesend.
	WritableRoots []string `yaml:"writable_roots"`
	// DeniedPaths ergänzt die eingebaute Sperrliste (Muster nach
	// filepath.Match). Verkleinern lässt sie sich nicht.
	DeniedPaths []string `yaml:"denied_paths"`
	// FollowSymlinks erlaubt Inhalte durch einen Verweis hindurch. Vorgabe aus.
	FollowSymlinks bool `yaml:"follow_symlinks"`
	// MaxUpload und MaxEditSize als Größenangabe mit Einheit, etwa "2GiB".
	MaxUpload   string `yaml:"max_upload"`
	MaxEditSize string `yaml:"max_edit_size"`
}

// On sagt, ob der Dateimanager eingeschaltet ist.
func (f Files) On() bool { return f.Enabled == nil || *f.Enabled }

// Limits liefert die Größengrenzen in Bytes. Leere Angaben bleiben 0; der
// Aufrufer setzt dann seine Vorgabe ein.
func (f Files) Limits() (upload, edit int64, err error) {
	if upload, err = ParseSize(f.MaxUpload); err != nil {
		return 0, 0, fmt.Errorf("files.max_upload: %w", err)
	}
	if edit, err = ParseSize(f.MaxEditSize); err != nil {
		return 0, 0, fmt.Errorf("files.max_edit_size: %w", err)
	}
	return upload, edit, nil
}

// validate prüft den Files-Block.
func (f Files) validate() error {
	for name, liste := range map[string][]string{
		"files.readable_roots": f.ReadableRoots,
		"files.writable_roots": f.WritableRoots,
	} {
		for _, p := range liste {
			if !filepath.IsAbs(p) {
				return fmt.Errorf("%s: %q muss ein absoluter Pfad sein", name, p)
			}
			if p != filepath.Clean(p) {
				return fmt.Errorf("%s: %q ist nicht in Normalform (erwartet %q)", name, p, filepath.Clean(p))
			}
		}
	}
	for _, p := range f.DeniedPaths {
		if !filepath.IsAbs(p) {
			return fmt.Errorf("files.denied_paths: %q muss ein absoluter Pfad sein", p)
		}
	}
	_, _, err := f.Limits()
	return err
}

// ParseSize liest eine Größenangabe wie "2GiB", "512MiB" oder "1048576".
//
// Bewusst nur Zweierpotenz-Einheiten: Eine Grenze, die als "2GB" dasteht und
// intern 2 GiB bedeutet, wäre eine kleine Unwahrheit an einer Stelle, an der es
// auf Zahlen ankommt.
func ParseSize(s string) (int64, error) {
	s = strings.TrimSpace(s)
	if s == "" {
		return 0, nil
	}
	einheiten := []struct {
		suffix string
		faktor int64
	}{
		{"GiB", 1 << 30}, {"MiB", 1 << 20}, {"KiB", 1 << 10}, {"B", 1},
	}
	zahl, faktor := s, int64(1)
	for _, e := range einheiten {
		if rest, ok := strings.CutSuffix(s, e.suffix); ok {
			zahl, faktor = strings.TrimSpace(rest), e.faktor
			break
		}
	}
	v, err := strconv.ParseInt(zahl, 10, 64)
	if err != nil {
		return 0, fmt.Errorf("%q ist keine Größenangabe (erlaubt: Zahl, KiB, MiB, GiB)", s)
	}
	if v < 0 {
		return 0, fmt.Errorf("%q ist negativ", s)
	}
	if v > (1<<62)/faktor {
		return 0, fmt.Errorf("%q ist zu groß", s)
	}
	return v * faktor, nil
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
			// io.EOF heißt hier: Die Datei ist leer. Das ist kein Fehler,
			// sondern eine Konfiguration, die es bei den Vorgaben belässt —
			// eine versehentlich geleerte Datei sonst mit "EOF" abzulehnen
			// wäre eine Fehlermeldung, aus der niemand schlau wird.
			if err := dec.Decode(&cfg); err != nil && !errors.Is(err, io.EOF) {
				return Config{}, fmt.Errorf("%s: %w", path, err)
			}
		case errors.Is(err, os.ErrNotExist):
			// Vorgaben behalten.
		default:
			return Config{}, fmt.Errorf("%s: %w", path, err)
		}
	}

	// Ergänzungen nach der Hauptdatei: Was die Oberfläche einstellt, liegt
	// dort und überschreibt die Vorgabe des Betreibers bewusst — er hat es ja
	// im Panel so eingestellt.
	if err := loadDropins(&cfg, ConfDir(path)); err != nil {
		return Config{}, err
	}

	if err := cfg.applyEnv(); err != nil {
		return Config{}, err
	}
	if err := cfg.Validate(); err != nil {
		return Config{}, err
	}
	cfg.SourcePath = path
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
	if err := c.Auth.WebAuthn.validate(); err != nil {
		return err
	}
	return c.Files.validate()
}

// validate prüft den WebAuthn-Block. RPID und Origins dürfen leer bleiben (dann
// werden sie zur Laufzeit abgeleitet); was aber dasteht, muss stimmen — ein
// falscher Ursprung fiele sonst erst bei der ersten Anmeldung auf.
func (w WebAuthn) validate() error {
	for _, o := range w.Origins {
		u, err := url.Parse(o)
		if err != nil || u.Scheme != "https" || u.Host == "" {
			return fmt.Errorf("auth.webauthn.origins: %q ist keine https-Adresse", o)
		}
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
	// Die Namen. Bis 0.6 wurden sie hier GAR NICHT geprüft — ein Tippfehler
	// fiel erst beim CA-Server auf, und der zählt Fehlversuche gegen die
	// Ratengrenze. Geprüft wird die Form, nicht die Erreichbarkeit: Ob der Name
	// auf diesen Server zeigt, kann nur die Prüfung selbst beantworten.
	for _, d := range a.Domains {
		if err := acme.PruefeZertifikatsname(strings.ToLower(strings.TrimSpace(d))); err != nil {
			return fmt.Errorf("acme.domains: %w", err)
		}
	}
	// Ein Platzhalter verlangt DNS-01 — Let's Encrypt prüft ihn nur über das
	// DNS. Das hier abzufangen ist besser als es im Manager zu tun: Eine
	// Konfiguration, die im Betrieb nie funktionieren kann, soll den Start
	// nicht überstehen.
	if acme.EnthaeltWildcard(a.Domains) {
		if a.Challenge == "http-01" {
			return errors.New("acme.challenge ist http-01, aber unter acme.domains " +
				"steht ein Platzhalter — Let's Encrypt prüft Platzhalter nur über DNS-01")
		}
		if a.DNS01.Provider == "" {
			return errors.New("unter acme.domains steht ein Platzhalter, aber " +
				"acme.dns01.provider ist leer — Platzhalter verlangen DNS-01")
		}
	}

	switch a.Challenge {
	case "", "http-01", "dns-01":
	default:
		return fmt.Errorf("acme.challenge: %q ist unbekannt (leer=automatisch|http-01|dns-01)", a.Challenge)
	}

	switch {
	case a.DNS01.Provider == "":
		// Kein DNS-Anbieter konfiguriert. Nur ein Widerspruch, wenn dns-01
		// ausdrücklich verlangt ist — bei automatischer Wahl bleibt HTTP-01.
		if a.Challenge == "dns-01" {
			return errors.New("acme.challenge ist dns-01, aber acme.dns01.provider ist leer")
		}
	case a.DNS01.Provider == DNS01ProviderHook:
		if a.DNS01.Hook.Set == "" || a.DNS01.Hook.Clean == "" {
			return errors.New("acme.dns01.hook: set und clean müssen gesetzt sein")
		}
	case !acme.AnbieterBekannt(a.DNS01.Provider):
		// Die Liste kommt aus dem Register und steht nicht zweimal. Sie ist Teil
		// der Meldung, weil „unbekannt" ohne die Alternativen zu keiner
		// Entscheidung befähigt.
		return fmt.Errorf("acme.dns01.provider: %q ist unbekannt (%s)",
			a.DNS01.Provider, strings.Join(acme.AnbieterNamen(), "|"))
	case a.DNS01.ZugangsDatei() == "":
		return fmt.Errorf("acme.dns01.credentials_file darf für den Anbieter %q nicht leer sein",
			a.DNS01.Provider)
	}
	return nil
}

// Addr liefert die Listen-Adresse für net.Listen.
func (c Config) Addr() string {
	return net.JoinHostPort(c.Server.Bind, strconv.Itoa(c.Server.Port))
}
