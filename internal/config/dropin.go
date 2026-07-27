package config

import (
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
	"strings"

	"gopkg.in/yaml.v3"
)

// Ergänzungsdateien: Was das Panel selbst einstellt, gehört nicht in die Datei
// des Betreibers.
//
// Bis hierher ließ sich der Zertifikatsbezug nur einstellen, indem jemand
// /etc/asylum/config.yaml von Hand anfasste — für ein Control Panel eine
// merkwürdige Zumutung. Die Werte über die Oberfläche in dieselbe Datei zu
// schreiben, hieße allerdings, sie neu zu erzeugen: Kommentare, Reihenfolge und
// eigene Anmerkungen des Betreibers wären weg.
//
// Deshalb ein Verzeichnis conf.d neben der Hauptdatei, aus dem alle *.yaml in
// Namensreihenfolge nach der Hauptdatei gelesen werden. Das Panel schreibt dort
// genau eine Datei und fasst keine andere an. Wer lieber von Hand arbeitet,
// legt seine eigene daneben — eine mit höherer Nummer gewinnt.
const (
	// ConfDirName ist das Verzeichnis der Ergänzungen, neben der Hauptdatei.
	ConfDirName = "conf.d"
	// ManagedTLSFile ist die einzige Datei, die das Panel selbst schreibt.
	ManagedTLSFile = "10-tls.yaml"
)

// ConfDir liefert das Ergänzungsverzeichnis zu einer Konfigurationsdatei.
func ConfDir(configPath string) string {
	if configPath == "" {
		configPath = DefaultPath
	}
	return filepath.Join(filepath.Dir(configPath), ConfDirName)
}

// ManagedTLSPath liefert den Pfad der vom Panel verwalteten Ergänzung.
func ManagedTLSPath(configPath string) string {
	return filepath.Join(ConfDir(configPath), ManagedTLSFile)
}

// loadDropins legt die Ergänzungen über die bereits geladene Konfiguration.
//
// Ein fehlendes Verzeichnis ist kein Fehler — die meisten Installationen haben
// keines. Eine unlesbare oder fehlerhafte Datei darin ist einer: Sie
// stillschweigend zu übergehen hieße, mit anderen Einstellungen zu laufen als
// angezeigt, und das ist bei TLS die schlechteste aller Antworten.
func loadDropins(cfg *Config, dir string) error {
	eintraege, err := os.ReadDir(dir)
	if os.IsNotExist(err) {
		return nil
	}
	if err != nil {
		return fmt.Errorf("%s: %w", dir, err)
	}

	namen := make([]string, 0, len(eintraege))
	for _, e := range eintraege {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".yaml") {
			continue
		}
		namen = append(namen, e.Name())
	}
	sort.Strings(namen)

	for _, name := range namen {
		pfad := filepath.Join(dir, name)
		raw, err := os.ReadFile(pfad) //nolint:gosec // Pfad aus dem Konfigurationsverzeichnis
		if err != nil {
			return fmt.Errorf("%s: %w", pfad, err)
		}
		dec := yaml.NewDecoder(strings.NewReader(string(raw)))
		dec.KnownFields(true)
		// Eine leere Ergänzung ist zulässig und ändert nichts.
		if err := dec.Decode(cfg); err != nil && !errors.Is(err, io.EOF) {
			return fmt.Errorf("%s: %w", pfad, err)
		}
	}
	return nil
}

// TLSSettings ist der Ausschnitt der Konfiguration, den die Oberfläche setzt.
type TLSSettings struct {
	Mode string
	ACME ACME
}

// TLSSettingsOf schneidet die Einstellungen aus einer geladenen Konfiguration.
func TLSSettingsOf(c Config) TLSSettings {
	mode := c.Server.TLS.Mode
	if mode == "" {
		mode = TLSModeSelfSigned
	}
	return TLSSettings{Mode: mode, ACME: c.ACME}
}

// managedDoc ist die Struktur der geschriebenen Datei. Bewusst ein eigener Typ
// und nicht die ganze Config: Die Ergänzung enthält nur, was die Oberfläche
// verwaltet. Alles andere bleibt Sache der Hauptdatei.
type managedDoc struct {
	Server managedServer `yaml:"server"`
	ACME   ACME          `yaml:"acme"`
}

type managedServer struct {
	TLS managedTLS `yaml:"tls"`
}

type managedTLS struct {
	Mode string `yaml:"mode"`
}

const managedHeader = `# Vom Panel verwaltet — Zertifikat-Seite in der Oberfläche.
#
# Diese Datei wird bei jedem Speichern vollständig neu geschrieben. Von Hand
# geänderte Werte gehen dabei verloren. Für eigene Ergänzungen legen Sie eine
# weitere Datei in diesem Verzeichnis an; die mit dem höheren Namen gewinnt.
#
# Sie ergänzt ../config.yaml und ersetzt sie nicht.
`

// WriteManagedTLS schreibt die Ergänzung atomar.
//
// Erst in eine Nachbardatei, dann umbenennen: Ein abgebrochener Schreibvorgang
// hinterlässt sonst eine halbe YAML-Datei, und der Dienst startet beim nächsten
// Mal nicht mehr — ausgerechnet wegen einer Einstellung, die die Erreichbarkeit
// verbessern sollte.
func WriteManagedTLS(configPath string, s TLSSettings) error {
	dir := ConfDir(configPath)
	if err := os.MkdirAll(dir, 0o750); err != nil {
		return fmt.Errorf("%s: %w", dir, err)
	}

	body, err := yaml.Marshal(managedDoc{
		Server: managedServer{TLS: managedTLS{Mode: s.Mode}},
		ACME:   s.ACME,
	})
	if err != nil {
		return err
	}

	ziel := filepath.Join(dir, ManagedTLSFile)
	tmp, err := os.CreateTemp(dir, ManagedTLSFile+".*")
	if err != nil {
		return err
	}
	name := tmp.Name()
	defer func() { _ = os.Remove(name) }() // greift nur, wenn das Umbenennen ausblieb

	if _, err := tmp.WriteString(managedHeader + string(body)); err != nil {
		_ = tmp.Close()
		return err
	}
	if err := tmp.Sync(); err != nil {
		_ = tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	// Dieselben Rechte wie die Hauptdatei, die der Installer mit
	// root:asylum 0640 anlegt: lesbar für die Gruppe, unter der der Dienst in
	// einer späteren Ausbaustufe unprivilegiert laufen soll, sonst für
	// niemanden. Geheimnisse stehen hier keine drin — das Cloudflare-Token
	// liegt in einer eigenen Datei mit 0600.
	if err := os.Chmod(name, 0o640); err != nil { //nolint:gosec // bewusst wie die Hauptkonfiguration, siehe oben
		return err
	}
	return os.Rename(name, ziel)
}
