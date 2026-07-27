package httpd

import (
	"net/http"
	"os"
	"time"

	"github.com/philf90/asylum/internal/acme"
	"github.com/philf90/asylum/internal/certs"
	"github.com/philf90/asylum/internal/config"
)

// handleCertificate zeigt den Zustand des ausgelieferten TLS-Zertifikats. Nur
// lesend — bezogen und erneuert wird im Hintergrund. Der Daemon hält das
// aktive Zertifikat im Speicher; diese Seite liest die Datei, die er auch
// ausliefern würde: das ACME-Zertifikat, falls vorhanden, sonst das
// selbstsignierte.
func (s *Server) handleCertificate(w http.ResponseWriter, r *http.Request) {
	mode := s.cfg.Server.TLS.Mode
	if mode == "" {
		mode = config.TLSModeSelfSigned
	}

	path := s.cfg.Server.TLS.Cert
	source := "selbstsigniert"
	if mode == config.TLSModeACME {
		acmeCert := acme.CertPath(s.cfg.Paths.Data)
		if _, err := os.Stat(acmeCert); err == nil {
			path, source = acmeCert, "ACME (Let's Encrypt)"
		} else {
			source = "selbstsigniert (Rückfall — noch kein ACME-Zertifikat bezogen)"
		}
	}

	page := certPage{
		Mode:      mode,
		Source:    source,
		Domains:   s.cfg.ACME.Domains,
		Challenge: s.cfg.ACME.Challenge,
		Provider:  s.cfg.ACME.DNS01.Provider,
	}
	if info, err := certs.Describe(path); err != nil {
		page.ReadError = err.Error()
	} else {
		page.Info = info
		page.DaysLeft = int(time.Until(info.NotAfter).Hours() / 24)
	}

	s.renderPage(w, r, http.StatusOK, "certificate",
		s.base(r, "Zertifikat", "certificate").with(page))
}
