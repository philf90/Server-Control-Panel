package httpd

import (
	"context"
	"errors"
	"path/filepath"
	"sync"
	"time"

	"github.com/philf90/asylum/internal/acme"
	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/netinfo"
)

// tlsControl hält die TLS-Einstellungen, die sich zur Laufzeit ändern lassen,
// und beaufsichtigt den ACME-Vorgang.
//
// Die übrige Konfiguration bleibt unveränderlich: Sie wird beim Start gelesen
// und von überall gelesen, ohne Sperre. Was die Oberfläche ändern kann, gehört
// deshalb hierher und nicht in s.cfg — sonst wäre jede Einstellung ein
// Wettlauf zwischen dem Bedienenden und jeder laufenden Anfrage.
//
// Der Vorgang selbst wird nicht neu gestartet, indem der Dienst neu startet.
// Ein Panel, das sich für eine Einstellung selbst abschießt, ist genau dann
// weg, wenn man sehen will, ob die Einstellung stimmt.
type tlsControl struct {
	mu       sync.RWMutex
	settings config.TLSSettings

	// baseCtx bestimmt die Lebensdauer aller Vorgänge. Vor Run ist er leer;
	// dann wird nichts gestartet — etwa in Tests.
	baseCtx context.Context //nolint:containedctx // Lebensdauer des Dienstes, kein Anfragekontext
	cancel  context.CancelFunc
	done    chan struct{}
	mgr     *acme.Manager

	// last hält den letzten Bezugsversuch für die Anzeige.
	last tlsAttempt
}

// tlsAttempt ist der letzte Bezugsversuch, so wie ihn die Seite zeigt.
type tlsAttempt struct {
	Running bool
	At      time.Time
	Err     string
}

func newTLSControl(s config.TLSSettings) *tlsControl {
	return &tlsControl{settings: s}
}

func (c *tlsControl) get() config.TLSSettings {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.settings
}

func (c *tlsControl) attempt() tlsAttempt {
	c.mu.RLock()
	defer c.mu.RUnlock()
	return c.last
}

func (c *tlsControl) setAttempt(a tlsAttempt) {
	c.mu.Lock()
	c.last = a
	c.mu.Unlock()
}

// tlsSettings liefert die geltenden Einstellungen.
func (s *Server) tlsSettings() config.TLSSettings { return s.tls.get() }

// acmeDomains bestimmt die Namen für das Zertifikat. Leer heißt: der
// vollqualifizierte Rechnername, zur Laufzeit ermittelt.
func acmeDomains(set config.TLSSettings) []string {
	if len(set.ACME.Domains) > 0 {
		return set.ACME.Domains
	}
	if fqdn := netinfo.FQDN(); fqdn != "" {
		return []string{fqdn}
	}
	return nil
}

// newACMEManager baut den Manager aus den geltenden Einstellungen. Fehlt eine
// auflösbare Domain, gibt es keinen sinnvollen Namen für ein Zertifikat — dann
// bleibt es beim selbstsignierten Paar.
func (s *Server) newACMEManager(set config.TLSSettings) (*acme.Manager, error) {
	domains := acmeDomains(set)
	if len(domains) == 0 {
		return nil, errors.New("keine Domain ermittelbar (Feld leer und Rechnername nicht auflösbar)")
	}
	return acme.New(acme.Options{
		Dir:                 filepath.Join(s.cfg.Paths.Data, "acme"),
		Email:               set.ACME.Email,
		Domains:             domains,
		DirectoryURL:        set.ACME.DirectoryURL,
		Challenge:           set.ACME.Challenge,
		HTTP01Addr:          ":80",
		DNS01Provider:       set.ACME.DNS01.Provider,
		HookSet:             set.ACME.DNS01.Hook.Set,
		HookClean:           set.ACME.DNS01.Hook.Clean,
		CloudflareTokenFile: set.ACME.DNS01.Cloudflare.APITokenFile,
	}, s.certHolder, s.log)
}

// startACME hält den Hintergrundvorgang am Leben. Ein laufender wird zuerst
// beendet und abgewartet: Zwei Manager auf demselben Verzeichnis schrieben
// sich gegenseitig das Zertifikat um.
//
// baseCtx ist die Lebensdauer des Dienstes. Ist er schon abgelaufen, wird
// nichts mehr gestartet.
func (s *Server) startACME(baseCtx context.Context) {
	s.tls.mu.Lock()
	s.tls.baseCtx = baseCtx
	s.tls.mu.Unlock()
	s.restartACME()
}

// restartACME beendet einen laufenden Vorgang und startet ihn mit den
// geltenden Einstellungen neu.
func (s *Server) restartACME() {
	s.tls.mu.Lock()
	cancel, done, base, set := s.tls.cancel, s.tls.done, s.tls.baseCtx, s.tls.settings
	s.tls.cancel, s.tls.done, s.tls.mgr = nil, nil, nil
	s.tls.mu.Unlock()

	if cancel != nil {
		cancel()
		<-done
	}
	if base == nil || base.Err() != nil {
		return // vor Run oder nach dem Herunterfahren
	}
	if set.Mode != config.TLSModeACME {
		s.log.Info("TLS: selbstsigniertes Zertifikat")
		return
	}

	mgr, err := s.newACMEManager(set)
	if err != nil {
		s.log.Warn("ACME nicht aktiv, selbstsigniertes Zertifikat bleibt", "err", err)
		s.tls.setAttempt(tlsAttempt{At: time.Now(), Err: err.Error()})
		return
	}

	ctx, cancel := context.WithCancel(base)
	fertig := make(chan struct{})

	s.tls.mu.Lock()
	s.tls.cancel, s.tls.done, s.tls.mgr = cancel, fertig, mgr
	s.tls.mu.Unlock()

	go func() {
		defer close(fertig)
		mgr.Start(ctx)
	}()
	s.log.Info("ACME aktiv", "domains", mgr.Domains())
}

// stopACME beendet den Vorgang und wartet auf sein Ende.
func (s *Server) stopACME() {
	s.tls.mu.Lock()
	cancel, done := s.tls.cancel, s.tls.done
	s.tls.cancel, s.tls.done, s.tls.mgr = nil, nil, nil
	s.tls.mu.Unlock()

	if cancel != nil {
		cancel()
		<-done
	}
}

// applyTLSSettings schreibt die Einstellungen, übernimmt sie und startet den
// Vorgang neu.
//
// Reihenfolge mit Absicht: Erst auf die Platte, dann in den Speicher. Wäre es
// umgekehrt, liefe der Dienst nach einem gescheiterten Schreibvorgang mit
// Einstellungen, die ein Neustart wieder verwirft — und niemand wüsste, warum
// es nach dem nächsten Update anders aussieht.
func (s *Server) applyTLSSettings(set config.TLSSettings) error {
	if err := config.WriteManagedTLS(s.cfgPath, set); err != nil {
		return err
	}
	s.tls.mu.Lock()
	s.tls.settings = set
	s.tls.mu.Unlock()

	s.restartACME()
	return nil
}

// obtainNow stößt einen sofortigen Bezug an. Er läuft im Hintergrund weiter,
// auch wenn der Browser die Seite verlässt: Ein DNS-01-Durchlauf kann Minuten
// dauern, und ein abgebrochener Vorgang hinterließe einen halb angelegten
// ACME-Auftrag.
func (s *Server) obtainNow() error {
	s.tls.mu.RLock()
	mgr, laeuft := s.tls.mgr, s.tls.last.Running
	s.tls.mu.RUnlock()

	if laeuft {
		return errors.New("es läuft bereits ein Bezug")
	}
	if mgr == nil {
		return errors.New("der automatische Bezug ist nicht eingeschaltet")
	}

	s.tls.setAttempt(tlsAttempt{Running: true, At: time.Now()})
	go func() {
		ctx, cancel := context.WithTimeout(context.Background(), obtainTimeout)
		defer cancel()

		err := mgr.ObtainNow(ctx)
		a := tlsAttempt{At: time.Now()}
		if err != nil {
			a.Err = err.Error()
			s.log.Warn("Zertifikatsbezug fehlgeschlagen", "err", err)
		} else {
			s.log.Info("Zertifikat bezogen", "domains", mgr.Domains())
		}
		s.tls.setAttempt(a)
	}()
	return nil
}

// obtainTimeout begrenzt einen angestoßenen Bezug. DNS-01 wartet auf die
// Verbreitung des TXT-Eintrags; fünf Minuten sind großzügig, aber endlich.
const obtainTimeout = 5 * time.Minute
