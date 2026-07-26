// Package httpd stellt den HTTPS-Listener und die Weboberfläche bereit.
package httpd

import (
	"context"
	"crypto/tls"
	"errors"
	"fmt"
	"html/template"
	"log/slog"
	"net"
	"net/http"
	"sync"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/certs"
	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/metrics"
	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
	"github.com/philf90/asylum/internal/systemd"
	"github.com/philf90/asylum/internal/ui"
)

const (
	readHeaderTimeout = 10 * time.Second
	readTimeout       = 30 * time.Second
	// Kein WriteTimeout: Server-Sent Events sind langlebige Antworten, die ein
	// pauschales Schreiblimit nach kurzer Zeit abschneiden würde. Gegen
	// Slowloris schützen ReadHeaderTimeout und IdleTimeout.
	idleTimeout     = 120 * time.Second
	shutdownTimeout = 15 * time.Second

	// Live-Takt der Oberfläche und Ablagetakt des Ringpuffers.
	liveInterval   = 2 * time.Second
	historyEvery   = 15          // jeder 15. Live-Tick landet im Ringpuffer (= 30 s)
	historyEntries = 2 * 60 * 24 // 24 h in 30-s-Auflösung
)

// Server bündelt alles, was die Weboberfläche braucht.
type Server struct {
	cfg     config.Config
	log     *slog.Logger
	db      *store.DB
	tmpl    *template.Template
	started time.Time

	sampler *metrics.Sampler
	ring    *metrics.Ring
	limiter *auth.Limiter
	hub     *hub

	// ops ist der einzige Weg zu privilegierten Systemoperationen.
	ops     privops.Executor
	jobs    *jobs
	fwGuard *firewallGuard
	upd     *updateState
	pending *pendingSecrets

	// updHTTP ersetzt den HTTP-Client der Update-Abfrage. Im Betrieb ist er
	// leer und update.NewClient bestimmt ihn; Tests setzen hier den Client
	// ihres eigenen Metadatenservers ein, statt die Zertifikatsprüfung im
	// Produktivcode abschaltbar zu machen.
	updHTTP *http.Client
}

// New baut den Server auf. Templates werden hier geparst, damit ein Fehler
// beim Start auffällt und nicht erst beim ersten Aufruf.
func New(cfg config.Config, logger *slog.Logger, db *store.DB, ops privops.Executor) (*Server, error) {
	tmpl, err := ui.Templates()
	if err != nil {
		return nil, fmt.Errorf("templates: %w", err)
	}
	if ops == nil {
		ops = privops.NewSystem()
	}
	return &Server{
		cfg:     cfg,
		log:     logger,
		db:      db,
		tmpl:    tmpl,
		started: time.Now(),
		sampler: metrics.NewSampler(),
		ring:    metrics.NewRing(historyEntries),
		limiter: auth.NewLimiter(),
		hub:     newHub(),
		ops:     ops,
		jobs:    newJobs(),
		fwGuard: newFirewallGuard(),
		upd:     newUpdateState(),
		pending: newPendingSecrets(),
	}, nil
}

// Run startet den Listener und blockiert, bis ctx abgebrochen wird.
func (s *Server) Run(ctx context.Context) error {
	certPath := s.cfg.Server.TLS.Cert
	keyPath := s.cfg.Server.TLS.Key

	created, err := certs.EnsurePair(certPath, keyPath, nil)
	if err != nil {
		return fmt.Errorf("tls: %w", err)
	}
	if created {
		s.log.Info("selbstsigniertes Zertifikat erzeugt", "cert", certPath)
	}
	if fp, err := certs.Fingerprint(certPath); err == nil {
		s.log.Info("TLS-Fingerprint", "sha256", fp)
	}

	keyPair, err := tls.LoadX509KeyPair(certPath, keyPath)
	if err != nil {
		return fmt.Errorf("tls-material laden: %w", err)
	}

	srv := &http.Server{
		Addr:              s.cfg.Addr(),
		Handler:           s.Handler(),
		ReadHeaderTimeout: readHeaderTimeout,
		ReadTimeout:       readTimeout,
		IdleTimeout:       idleTimeout,
		ErrorLog:          slog.NewLogLogger(s.log.Handler(), slog.LevelWarn),
		TLSConfig: &tls.Config{
			Certificates: []tls.Certificate{keyPair},
			MinVersion:   tls.VersionTLS12,
			CurvePreferences: []tls.CurveID{
				tls.X25519, tls.CurveP256,
			},
			CipherSuites: []uint16{
				// Nur für TLS 1.2 relevant; 1.3 wählt eigenständig.
				tls.TLS_ECDHE_ECDSA_WITH_AES_128_GCM_SHA256,
				tls.TLS_ECDHE_ECDSA_WITH_AES_256_GCM_SHA384,
				tls.TLS_ECDHE_ECDSA_WITH_CHACHA20_POLY1305,
				tls.TLS_ECDHE_RSA_WITH_AES_128_GCM_SHA256,
				tls.TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384,
				tls.TLS_ECDHE_RSA_WITH_CHACHA20_POLY1305,
			},
		},
	}

	// ListenConfig statt net.Listen: Damit bricht auch das Öffnen des Sockets
	// ab, wenn der Start abgebrochen wird.
	var lc net.ListenConfig
	ln, err := lc.Listen(ctx, "tcp", srv.Addr)
	if err != nil {
		return fmt.Errorf("listen %s: %w", srv.Addr, err)
	}

	var wg sync.WaitGroup
	bgCtx, stopBackground := context.WithCancel(ctx)
	defer stopBackground()

	wg.Add(2)
	go func() { defer wg.Done(); s.sampleLoop(bgCtx) }()
	go func() { defer wg.Done(); s.housekeeping(bgCtx) }()

	errCh := make(chan error, 1)
	go func() {
		s.log.Info("Panel erreichbar", "url", fmt.Sprintf("https://%s/", srv.Addr))
		if err := srv.ServeTLS(ln, "", ""); err != nil && !errors.Is(err, http.ErrServerClosed) {
			errCh <- err
			return
		}
		errCh <- nil
	}()

	s.logSetupHint(ctx)

	_ = systemd.Ready()
	_ = systemd.Status(fmt.Sprintf("hört auf %s", srv.Addr))
	stopWatchdog := s.startWatchdog(ctx)
	defer stopWatchdog()

	select {
	case err := <-errCh:
		return err
	case <-ctx.Done():
	}

	_ = systemd.Stopping()
	s.log.Info("Shutdown angefordert, laufende Anfragen werden beendet")
	stopBackground()

	shutdownCtx, cancel := context.WithTimeout(context.Background(), shutdownTimeout)
	defer cancel()
	if err := srv.Shutdown(shutdownCtx); err != nil {
		// Nach dem Timeout hart schließen, sonst hängt der Dienst am
		// systemd-Stop-Timeout und wird gekillt.
		_ = srv.Close()
		return fmt.Errorf("shutdown: %w", err)
	}
	wg.Wait()
	return <-errCh
}

// sampleLoop erhebt die Metriken zentral: ein Sampler für alle Betrachter.
// Würde jede SSE-Verbindung selbst messen, käme es zu Wettläufen um die
// Vorwerte der Delta-Berechnung und zu unnötiger Last.
func (s *Server) sampleLoop(ctx context.Context) {
	ticker := time.NewTicker(liveInterval)
	defer ticker.Stop()

	// Erster Aufruf setzt nur die Vorwerte; Deltas gibt es ab dem zweiten.
	s.sampler.Sample()

	tick := 0
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			snap := s.sampler.Sample()
			s.hub.broadcast(snap)
			tick++
			if tick%historyEvery == 0 {
				s.ring.Add(snap)
			}
		}
	}
}

// housekeeping räumt regelmäßig auf.
func (s *Server) housekeeping(ctx context.Context) {
	ticker := time.NewTicker(10 * time.Minute)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			if n, err := s.db.PurgeExpiredSessions(ctx); err != nil {
				s.log.Warn("abgelaufene Sitzungen aufräumen", "err", err)
			} else if n > 0 {
				s.log.Debug("abgelaufene Sitzungen entfernt", "anzahl", n)
			}
			s.limiter.Cleanup()
		}
	}
}

// logSetupHint weist beim Start darauf hin, wenn noch kein Konto existiert.
func (s *Server) logSetupHint(ctx context.Context) {
	n, err := s.db.CountUsers(ctx)
	if err != nil {
		s.log.Error("benutzer zählen", "err", err)
		return
	}
	if n == 0 {
		s.log.Warn("noch kein Konto eingerichtet — Setup-Token erzeugen mit: asylum setup-token")
	}
}

// startWatchdog pingt systemd, solange der Kontext lebt.
func (s *Server) startWatchdog(ctx context.Context) (stop func()) {
	interval := systemd.WatchdogInterval()
	if interval <= 0 {
		return func() {}
	}
	ctx, cancel := context.WithCancel(ctx)
	go func() {
		ticker := time.NewTicker(interval)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				if err := systemd.WatchdogPing(); err != nil {
					s.log.Warn("watchdog-ping fehlgeschlagen", "err", err)
				}
			}
		}
	}()
	return cancel
}

// hub verteilt Snapshots an alle offenen SSE-Verbindungen.
type hub struct {
	mu   sync.RWMutex
	subs map[chan metrics.Snapshot]struct{}
}

func newHub() *hub {
	return &hub{subs: make(map[chan metrics.Snapshot]struct{})}
}

func (h *hub) subscribe() chan metrics.Snapshot {
	ch := make(chan metrics.Snapshot, 1)
	h.mu.Lock()
	h.subs[ch] = struct{}{}
	h.mu.Unlock()
	return ch
}

func (h *hub) unsubscribe(ch chan metrics.Snapshot) {
	h.mu.Lock()
	delete(h.subs, ch)
	h.mu.Unlock()
	close(ch)
}

// broadcast schickt nicht-blockierend: Ein langsamer Client überspringt einen
// Takt, statt den Sampler für alle anderen aufzuhalten.
func (h *hub) broadcast(snap metrics.Snapshot) {
	h.mu.RLock()
	defer h.mu.RUnlock()

	for ch := range h.subs {
		select {
		case ch <- snap:
		default:
		}
	}
}
