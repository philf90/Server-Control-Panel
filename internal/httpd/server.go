// Package httpd stellt den HTTPS-Listener des Panels bereit.
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
	"time"

	"github.com/philf90/asylum/internal/certs"
	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/systemd"
	"github.com/philf90/asylum/internal/ui"
)

const (
	readHeaderTimeout = 10 * time.Second
	readTimeout       = 30 * time.Second
	writeTimeout      = 60 * time.Second
	idleTimeout       = 120 * time.Second
	shutdownTimeout   = 15 * time.Second
)

// Server bündelt Konfiguration, Logger und den HTTP-Server.
type Server struct {
	cfg     config.Config
	log     *slog.Logger
	tmpl    *template.Template
	started time.Time
}

// New baut den Server auf. Templates werden hier geparst, damit ein Fehler
// beim Start auffällt und nicht erst beim ersten Aufruf.
func New(cfg config.Config, logger *slog.Logger) (*Server, error) {
	tmpl, err := ui.Templates()
	if err != nil {
		return nil, fmt.Errorf("templates: %w", err)
	}
	return &Server{
		cfg:     cfg,
		log:     logger,
		tmpl:    tmpl,
		started: time.Now(),
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
		WriteTimeout:      writeTimeout,
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

	ln, err := net.Listen("tcp", srv.Addr)
	if err != nil {
		return fmt.Errorf("listen %s: %w", srv.Addr, err)
	}

	errCh := make(chan error, 1)
	go func() {
		s.log.Info("Panel erreichbar", "url", fmt.Sprintf("https://%s/", srv.Addr))
		if err := srv.ServeTLS(ln, "", ""); err != nil && !errors.Is(err, http.ErrServerClosed) {
			errCh <- err
			return
		}
		errCh <- nil
	}()

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

	shutdownCtx, cancel := context.WithTimeout(context.Background(), shutdownTimeout)
	defer cancel()
	if err := srv.Shutdown(shutdownCtx); err != nil {
		// Nach dem Timeout hart schließen, sonst hängt der Dienst am
		// systemd-Stop-Timeout und wird gekillt.
		_ = srv.Close()
		return fmt.Errorf("shutdown: %w", err)
	}
	return <-errCh
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
