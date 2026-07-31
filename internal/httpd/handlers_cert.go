package httpd

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"github.com/philf90/asylum/internal/config"
)

// stagingDirectory ist das Testverzeichnis von Let's Encrypt. Es stellt
// Zertifikate aus, denen kein Browser traut — dafür sind seine Grenzen weit.
// Wer einen DNS-Hook oder einen Anbieterzugang einrichtet, sollte hier
// anfangen, statt die Produktionsgrenzen zu verbrauchen.
const stagingDirectory = "https://acme-staging-v02.api.letsencrypt.org/directory"

func fileExists(path string) bool {
	if path == "" {
		return false
	}
	_, err := os.Stat(path)
	return err == nil
}

// cloudflareToken legt ein neu eingegebenes Token ab und liefert den Pfad.
//
// Das Token steht bewusst nicht in der Konfigurationsdatei, sondern in einer
// eigenen Datei mit 0600 — die Konfiguration ist für die Gruppe des Dienstes
// lesbar, ein API-Schlüssel hat dort nichts zu suchen. Wird nichts eingegeben,
// bleibt das bereits hinterlegte Token bestehen: Ein leeres Feld darf einen
// funktionierenden Zugang nicht löschen.
func (s *Server) cloudflareToken(token string, alt config.TLSSettings) (string, error) {
	pfad := filepath.Join(s.cfg.Paths.Data, "acme", "cloudflare.token")

	if token == "" {
		if fileExists(alt.ACME.DNS01.Cloudflare.APITokenFile) {
			return alt.ACME.DNS01.Cloudflare.APITokenFile, nil
		}
		return "", fmt.Errorf("für Cloudflare wird ein API-Token gebraucht")
	}

	if err := os.MkdirAll(filepath.Dir(pfad), 0o700); err != nil {
		return "", err
	}
	// gosec meldet hier G703 (Pfaddurchquerung), weil ein Wert aus dem Formular
	// in den Aufruf fließt. Das ist der Inhalt, nicht der Pfad: "pfad" besteht
	// aus drei festen Bestandteilen und enthält nichts Eingegebenes. Genau
	// darum steht das Token in einer eigenen Datei — den Namen bestimmt das
	// Panel, nicht der Eingebende.
	if err := os.WriteFile(pfad, []byte(token+"\n"), 0o600); err != nil { //nolint:gosec // siehe oben: eingegeben ist der Inhalt, nicht der Pfad
		return "", err
	}
	return pfad, nil
}

// pruefeHook nimmt nur absolute Pfade auf vorhandene, ausführbare Dateien.
//
// Ein Hook ist ein Programm, das der Daemon als root startet. Ein relativer
// Pfad hinge davon ab, in welchem Verzeichnis der Dienst gerade läuft, und ein
// Tippfehler fiele erst beim Bezug auf — Minuten später, in einem Logeintrag.
// Zurück kommt der normalisierte Pfad — er wird so gespeichert, wie er geprüft
// wurde.
func pruefeHook(rolle, pfad string) (string, error) {
	if pfad == "" {
		return "", fmt.Errorf("der Pfad zum %s-Skript fehlt", rolle)
	}
	if !filepath.IsAbs(pfad) {
		return "", fmt.Errorf("%s: %q ist kein absoluter Pfad", rolle, pfad)
	}
	// Normalisieren, bevor der Pfad das erste Mal benutzt wird: "/opt/../etc/x"
	// und "/etc/x" sind dieselbe Datei, sollen aber nicht als zwei verschiedene
	// Einstellungen in der Konfiguration landen.
	pfad = filepath.Clean(pfad)

	info, err := os.Stat(pfad)
	if err != nil {
		return "", fmt.Errorf("%s: %q ist nicht vorhanden", rolle, pfad)
	}
	if info.IsDir() || info.Mode().Perm()&0o111 == 0 {
		return "", fmt.Errorf("%s: %q ist nicht ausführbar", rolle, pfad)
	}
	return pfad, nil
}

// parseDomains liest die Namen, einen je Zeile. Leer ist zulässig und
// bedeutet: der vollqualifizierte Rechnername.
func parseDomains(raw string) ([]string, error) {
	var out []string
	for feld := range strings.FieldsSeq(strings.ReplaceAll(raw, ",", " ")) {
		name := strings.TrimSuffix(strings.ToLower(feld), ".")
		if err := pruefeDomain(name); err != nil {
			return nil, err
		}
		out = append(out, name)
	}
	if len(out) > 100 {
		return nil, fmt.Errorf("mehr als 100 Namen — das lehnt auch Let's Encrypt ab")
	}
	return out, nil
}

// pruefeDomain prüft einen Namen so weit, wie es ohne Auflösung geht.
func pruefeDomain(name string) error {
	if name == "" {
		return fmt.Errorf("leerer Name")
	}
	if len(name) > 253 {
		return fmt.Errorf("%q ist länger als 253 Zeichen", name)
	}
	if !strings.Contains(name, ".") {
		return fmt.Errorf("%q hat keinen Punkt — Let's Encrypt stellt nur für "+
			"vollqualifizierte Namen aus", name)
	}
	for label := range strings.SplitSeq(name, ".") {
		if label == "" || len(label) > 63 {
			return fmt.Errorf("%q enthält einen leeren oder zu langen Namensteil", name)
		}
		for _, r := range label {
			ok := (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') || r == '-' || r == '*'
			if !ok {
				return fmt.Errorf("%q enthält das Zeichen %q", name, r)
			}
		}
	}
	return nil
}
