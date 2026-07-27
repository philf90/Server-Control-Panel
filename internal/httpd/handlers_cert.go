package httpd

import (
	"fmt"
	"net/http"
	"net/mail"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/acme"
	"github.com/philf90/asylum/internal/certs"
	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/store"
)

// stagingDirectory ist das Testverzeichnis von Let's Encrypt. Es stellt
// Zertifikate aus, denen kein Browser traut — dafür sind seine Grenzen weit.
// Wer einen DNS-Hook oder einen Anbieterzugang einrichtet, sollte hier
// anfangen, statt die Produktionsgrenzen zu verbrauchen.
const stagingDirectory = "https://acme-staging-v02.api.letsencrypt.org/directory"

func (s *Server) handleCertificate(w http.ResponseWriter, r *http.Request) {
	s.renderCertificate(w, r, http.StatusOK, "", "")
}

// renderCertificate zeigt den Zustand des ausgelieferten Zertifikats und die
// Einstellungen, mit denen es bezogen wird.
//
// Bis rc.2 stand hier nur ein Hinweis, man möge server.tls.mode in der
// Konfigurationsdatei setzen. Für ein Control Panel ist das die falsche
// Antwort: Wer eine Datei auf dem Server bearbeiten kann, braucht die Seite
// nicht.
func (s *Server) renderCertificate(w http.ResponseWriter, r *http.Request, status int, flash, errMsg string) {
	set := s.tlsSettings()

	path := s.cfg.Server.TLS.Cert
	source := "selbstsigniert"
	if set.Mode == config.TLSModeACME {
		acmeCert := acme.CertPath(s.cfg.Paths.Data)
		if _, err := os.Stat(acmeCert); err == nil {
			path, source = acmeCert, "ACME (Let's Encrypt)"
		} else {
			source = "selbstsigniert (Rückfall — noch kein Zertifikat bezogen)"
		}
	}

	page := certPage{
		Mode:             set.Mode,
		Source:           source,
		Set:              set,
		DomainsText:      strings.Join(set.ACME.Domains, "\n"),
		EffectiveDomains: acmeDomains(set),
		Staging:          set.ACME.DirectoryURL == stagingDirectory,
		TokenHinterlegt:  fileExists(set.ACME.DNS01.Cloudflare.APITokenFile),
		Attempt:          s.tls.attempt(),
		ManagedFile:      config.ManagedTLSPath(s.cfgPath),
	}
	if info, err := certs.Describe(path); err != nil {
		page.ReadError = err.Error()
	} else {
		page.Info = info
		page.DaysLeft = int(time.Until(info.NotAfter).Hours() / 24)
	}

	p := s.base(r, "Zertifikat", "certificate").with(page)
	if flash != "" {
		p = p.withFlash(flash)
	}
	if errMsg != "" {
		p = p.withError(errMsg)
	}
	s.renderPage(w, r, status, "certificate", p)
}

func fileExists(path string) bool {
	if path == "" {
		return false
	}
	_, err := os.Stat(path)
	return err == nil
}

// handleCertificateSettings übernimmt das Formular.
func (s *Server) handleCertificateSettings(w http.ResponseWriter, r *http.Request) {
	set, err := s.parseTLSForm(r)
	if err != nil {
		s.audit(r, "tls.settings", "", store.ResultError, err.Error())
		s.renderCertificate(w, r, http.StatusBadRequest, "", err.Error())
		return
	}

	// Dieselbe Prüfung wie beim Start des Dienstes. Ohne sie ließe sich eine
	// Konfiguration speichern, mit der der Daemon beim nächsten Start nicht
	// mehr hochkommt — und dann ist die Seite weg, auf der man es zurücknimmt.
	probe := s.cfg
	probe.Server.TLS.Mode = set.Mode
	probe.ACME = set.ACME
	if err := probe.Validate(); err != nil {
		s.audit(r, "tls.settings", "", store.ResultError, err.Error())
		s.renderCertificate(w, r, http.StatusBadRequest, "", err.Error())
		return
	}

	if err := s.applyTLSSettings(set); err != nil {
		s.audit(r, "tls.settings", "", store.ResultError, err.Error())
		s.renderCertificate(w, r, http.StatusInternalServerError, "",
			"Die Einstellungen konnten nicht gespeichert werden: "+err.Error())
		return
	}

	s.audit(r, "tls.settings", set.Mode, store.ResultOK,
		fmt.Sprintf("domains=%s challenge=%q provider=%q staging=%t",
			strings.Join(acmeDomains(set), ","), set.ACME.Challenge,
			set.ACME.DNS01.Provider, set.ACME.DirectoryURL == stagingDirectory))

	flash := "Die Einstellungen sind gespeichert."
	if set.Mode == config.TLSModeACME {
		flash += " Der Bezug läuft im Hintergrund; bis dahin bleibt das bisherige Zertifikat aktiv."
	}
	s.renderCertificate(w, r, http.StatusOK, flash, "")
}

// handleCertificateObtain stößt einen sofortigen Bezug an.
func (s *Server) handleCertificateObtain(w http.ResponseWriter, r *http.Request) {
	if err := s.obtainNow(); err != nil {
		s.audit(r, "tls.obtain", "", store.ResultError, err.Error())
		s.renderCertificate(w, r, http.StatusBadRequest, "", err.Error())
		return
	}
	s.audit(r, "tls.obtain", strings.Join(acmeDomains(s.tlsSettings()), ","), store.ResultOK, "gestartet")
	s.renderCertificate(w, r, http.StatusOK,
		"Der Bezug läuft. Laden Sie die Seite in einer Minute neu — das Ergebnis steht hier und im Audit-Log.", "")
}

// parseTLSForm liest und prüft das Formular.
//
// Geprüft wird hier und nicht erst in der Konfigurationsprüfung, weil die
// Meldungen dort auf YAML-Feldnamen zeigen ("acme.dns01.hook") — auf einer
// Seite, die keine YAML-Datei zeigt, hilft das niemandem.
func (s *Server) parseTLSForm(r *http.Request) (config.TLSSettings, error) {
	alt := s.tlsSettings()
	set := config.TLSSettings{Mode: config.TLSModeSelfSigned}

	if r.PostFormValue("mode") != config.TLSModeACME {
		return set, nil
	}
	set.Mode = config.TLSModeACME

	domains, err := parseDomains(r.PostFormValue("domains"))
	if err != nil {
		return set, err
	}
	set.ACME.Domains = domains

	email := strings.TrimSpace(r.PostFormValue("email"))
	if email == "" {
		return set, fmt.Errorf("für Let's Encrypt wird eine Kontaktadresse gebraucht — " +
			"dorthin geht die Warnung, wenn eine Erneuerung ausbleibt")
	}
	if _, err := mail.ParseAddress(email); err != nil {
		return set, fmt.Errorf("%q ist keine E-Mail-Adresse", email)
	}
	set.ACME.Email = email

	if r.PostFormValue("staging") == "1" {
		set.ACME.DirectoryURL = stagingDirectory
	}

	switch c := r.PostFormValue("challenge"); c {
	case "", "http-01", "dns-01":
		set.ACME.Challenge = c
	default:
		return set, fmt.Errorf("unbekannte Prüfmethode %q", c)
	}
	// http01.open_firewall wird bewusst nicht aus dem Formular gesetzt: Die
	// Einstellung ist vorgesehen, aber noch ohne Wirkung (siehe
	// docs/10-tls-acme.md). Ein Kästchen, das nichts tut, ist schlimmer als
	// keines — es sieht aus wie eine Zusage. Ein in der Konfigurationsdatei
	// gesetzter Wert bleibt unangetastet.
	set.ACME.HTTP01 = alt.ACME.HTTP01

	provider := r.PostFormValue("provider")
	switch provider {
	case "":
		if set.ACME.Challenge == "dns-01" {
			return set, fmt.Errorf("die Prüfung über DNS braucht einen Anbieter — " +
				"wählen Sie Hook oder Cloudflare, oder stellen Sie die Prüfmethode zurück")
		}
	case config.DNS01ProviderHook:
		hookSet := strings.TrimSpace(r.PostFormValue("hook_set"))
		hookClean := strings.TrimSpace(r.PostFormValue("hook_clean"))
		if err := pruefeHook("Setzen", hookSet); err != nil {
			return set, err
		}
		if err := pruefeHook("Aufräumen", hookClean); err != nil {
			return set, err
		}
		set.ACME.DNS01.Hook.Set = hookSet
		set.ACME.DNS01.Hook.Clean = hookClean
	case config.DNS01ProviderCloudflare:
		datei, err := s.cloudflareToken(r, alt)
		if err != nil {
			return set, err
		}
		set.ACME.DNS01.Cloudflare.APITokenFile = datei
	default:
		return set, fmt.Errorf("unbekannter DNS-Anbieter %q", provider)
	}
	set.ACME.DNS01.Provider = provider

	return set, nil
}

// cloudflareToken legt ein neu eingegebenes Token ab und liefert den Pfad.
//
// Das Token steht bewusst nicht in der Konfigurationsdatei, sondern in einer
// eigenen Datei mit 0600 — die Konfiguration ist für die Gruppe des Dienstes
// lesbar, ein API-Schlüssel hat dort nichts zu suchen. Wird nichts eingegeben,
// bleibt das bereits hinterlegte Token bestehen: Ein leeres Feld darf einen
// funktionierenden Zugang nicht löschen.
func (s *Server) cloudflareToken(r *http.Request, alt config.TLSSettings) (string, error) {
	pfad := filepath.Join(s.cfg.Paths.Data, "acme", "cloudflare.token")

	token := strings.TrimSpace(r.PostFormValue("cf_token"))
	if token == "" {
		if fileExists(alt.ACME.DNS01.Cloudflare.APITokenFile) {
			return alt.ACME.DNS01.Cloudflare.APITokenFile, nil
		}
		return "", fmt.Errorf("für Cloudflare wird ein API-Token gebraucht")
	}

	if err := os.MkdirAll(filepath.Dir(pfad), 0o700); err != nil {
		return "", err
	}
	if err := os.WriteFile(pfad, []byte(token+"\n"), 0o600); err != nil {
		return "", err
	}
	return pfad, nil
}

// pruefeHook nimmt nur absolute Pfade auf vorhandene, ausführbare Dateien.
//
// Ein Hook ist ein Programm, das der Daemon als root startet. Ein relativer
// Pfad hinge davon ab, in welchem Verzeichnis der Dienst gerade läuft, und ein
// Tippfehler fiele erst beim Bezug auf — Minuten später, in einem Logeintrag.
func pruefeHook(rolle, pfad string) error {
	if pfad == "" {
		return fmt.Errorf("der Pfad zum %s-Skript fehlt", rolle)
	}
	if !filepath.IsAbs(pfad) {
		return fmt.Errorf("%s: %q ist kein absoluter Pfad", rolle, pfad)
	}
	info, err := os.Stat(pfad)
	if err != nil {
		return fmt.Errorf("%s: %q ist nicht vorhanden", rolle, pfad)
	}
	if info.IsDir() || info.Mode().Perm()&0o111 == 0 {
		return fmt.Errorf("%s: %q ist nicht ausführbar", rolle, pfad)
	}
	return nil
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
