package httpd

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"github.com/philf90/asylum/internal/acme"
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

// zugangAblegen legt neu eingegebene Zugangsdaten ab und liefert den Pfad.
//
// Sie stehen bewusst nicht in der Konfigurationsdatei, sondern in einer eigenen
// Datei mit 0600 — die Konfiguration ist für die Gruppe des Dienstes lesbar,
// und ein DNS-Zugang stellt Zertifikate für die ganze Zone aus. Wird nichts
// eingegeben, bleibt der bereits hinterlegte Zugang bestehen: Ein leeres Feld
// darf einen funktionierenden Zugang nicht löschen.
//
// Eine Datei je Anbieter, benannt nach ihm. Damit überschreibt ein Wechsel von
// Cloudflare zu Hetzner den Cloudflare-Zugang nicht — wer zurückwechselt, muss
// ihn nicht neu eintippen. Der NAME kommt aus dem Register und nie aus der
// Anfrage; sonst wäre das Feld ein Weg, jede Datei des Servers zu überschreiben.
func (s *Server) zugangAblegen(anbieter, eingabe string, alt config.TLSSettings) (string, error) {
	if !acme.AnbieterBekannt(anbieter) {
		return "", fmt.Errorf("unbekannter DNS-Anbieter %q", anbieter)
	}
	pfad := filepath.Join(s.cfg.Paths.Data, "acme", anbieter+".zugang")

	if eingabe == "" {
		if vorhanden := alt.ACME.DNS01.ZugangsDatei(); fileExists(vorhanden) {
			return vorhanden, nil
		}
		return "", fmt.Errorf("für %s werden Zugangsdaten gebraucht", anbieter)
	}

	if err := os.MkdirAll(filepath.Dir(pfad), 0o700); err != nil {
		return "", err
	}
	// gosec meldet hier G703 (Pfaddurchquerung), weil ein Wert aus dem Formular
	// in den Aufruf fließt. Das ist der Inhalt, nicht der Pfad: "pfad" besteht
	// aus drei festen Bestandteilen und enthält nichts Eingegebenes. Genau
	// darum steht das Token in einer eigenen Datei — den Namen bestimmt das
	// Panel, nicht der Eingebende.
	if err := os.WriteFile(pfad, []byte(eingabe+"\n"), 0o600); err != nil { //nolint:gosec // siehe oben: eingegeben ist der Inhalt, nicht der Pfad
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
