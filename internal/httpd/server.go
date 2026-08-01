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
	"os"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/certs"
	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/metrics"
	"github.com/philf90/asylum/internal/netinfo"
	"github.com/philf90/asylum/internal/passkeys"
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
	// latest ist die zuletzt genommene Messung. Sie ist nicht dasselbe wie der
	// letzte Ringpuffer-Eintrag: Der Ring hält den Verlauf und bekommt nur
	// alle 30 Sekunden etwas, die Übersicht braucht aber sofort Zahlen.
	latestMu sync.RWMutex
	latest   metrics.Snapshot
	hasLast  bool

	ops privops.Executor
	// journal hält die zuletzt ausgeführten Systembefehle für die Konsole am
	// unteren Rand jeder Seite. Es ist nur gefüllt, wenn der Server seinen
	// Executor selbst gebaut hat — wer einen eigenen einsetzt (Tests), bekommt
	// eine leere Konsole statt eines falschen Bildes.
	journal *privops.Journal
	// files ist der Dateimanager. Nil, wenn das Modul abgeschaltet ist oder
	// seine Politik nicht aufgeht — dann gibt es weder Routen noch Menüpunkt.
	files privops.Files
	// filesGeprueft hält das Ergebnis der Selbstprüfung der Schreibbereiche.
	// Einmal je Prozess: Die Prüfung schreibt in jedem Bereich eine Datei und
	// wieder weg, und das gehört nicht in jeden Seitenaufruf.
	filesPruefOnce sync.Once
	filesPruefung  []privops.RootStatus
	jobs           *jobs
	// fwGuard hält die Firewalländerung auf Probe, siteGuard die Änderung an
	// einer Site. Ein eigener Wächter je Bereich: Ein geteilter hieße, dass eine
	// bestätigte Firewalländerung eine unbestätigte Site mitbestätigt. Siehe
	// probe.go.
	fwGuard   *probenWaechter
	siteGuard *probenWaechter
	// siteZerts hält die ACME-Manager der Sites — einen je Site mit TLS, alle
	// auf demselben Konto. Siehe tlssites.go.
	siteZerts *siteZerts
	// logFolger zählt die offenen Journalströme. Jeder hält einen eigenen
	// journalctl-Prozess, weil jeder seinen eigenen Filter hat — anders als bei
	// einem Vorgang, den alle Zuschauer teilen. Siehe maxLogFolger.
	logFolger atomic.Int32
	// dockerFolger zählt die offenen Containerprotokolle. Eigene Zählung neben
	// logFolger, weil beide verschiedene Prozesse halten: Ein offenes
	// Containerprotokoll soll nicht den Blick ins Journal versperren.
	dockerFolger atomic.Int32
	upd          *updateState
	pending      *pendingSecrets
	// resets hält die per Passkey bestätigten Nachweise für ein vergessenes
	// Passwort. Siehe handlers_forgot.go.
	resets *resetTickets

	// passkeys führt die WebAuthn-Zeremonien. Nil, wenn Passkeys nicht
	// eingeschaltet oder mangels auflösbarem Namen nicht möglich sind — dann
	// blendet die Kontoseite den Abschnitt aus.
	passkeys *passkeys.Manager

	// updHTTP ersetzt den HTTP-Client der Update-Abfrage. Im Betrieb ist er
	// leer und update.NewClient bestimmt ihn; Tests setzen hier den Client
	// ihres eigenen Metadatenservers ein, statt die Zertifikatsprüfung im
	// Produktivcode abschaltbar zu machen.
	updHTTP *http.Client

	// certHolder trägt das aktive TLS-Zertifikat und erlaubt den Austausch zur
	// Laufzeit (Grundlage für die ACME-Erneuerung ohne Neustart). In Run gesetzt.
	certHolder *certs.Holder

	// tls hält die Einstellungen, die über die Oberfläche änderbar sind, und
	// beaufsichtigt den ACME-Vorgang. Siehe tlsctl.go.
	tls *tlsControl
	// cfgPath ist der Pfad der Konfigurationsdatei. Gebraucht wird er, um die
	// vom Panel verwaltete Ergänzung daneben zu schreiben.
	cfgPath string
}

// New baut den Server auf. Templates werden hier geparst, damit ein Fehler
// beim Start auffällt und nicht erst beim ersten Aufruf.
func New(cfg config.Config, logger *slog.Logger, db *store.DB, ops privops.Executor) (*Server, error) {
	tmpl, err := ui.Templates()
	if err != nil {
		return nil, fmt.Errorf("templates: %w", err)
	}
	// Das Journal hängt am Runner, nicht am Executor: So wird jeder Aufruf
	// erfasst, ohne dass ihn jede einzelne Operation melden müsste. Wer einen
	// eigenen Executor mitgibt, bekommt keines — sein Runner ist uns unbekannt,
	// und ein halb gefülltes Journal wäre irreführender als ein leeres.
	var journal *privops.Journal
	if ops == nil {
		journal = privops.NewJournal()
		ops = privops.NewSystemMitJournal(journal)
	}
	pk := buildPasskeys(cfg, logger)
	dateien := buildFiles(cfg, logger)
	return &Server{
		cfg:       cfg,
		cfgPath:   cfg.SourcePath,
		tls:       newTLSControl(config.TLSSettingsOf(cfg)),
		log:       logger,
		db:        db,
		tmpl:      tmpl,
		started:   time.Now(),
		sampler:   metrics.NewSampler(),
		ring:      metrics.NewRing(historyEntries),
		limiter:   auth.NewLimiter(),
		hub:       newHub(),
		ops:       ops,
		journal:   journal,
		jobs:      newJobs(),
		fwGuard:   neuerProbenWaechter(firewallConfirmWindow),
		siteGuard: neuerProbenWaechter(siteProbeFenster),
		siteZerts: neueSiteZerts(),
		upd:       newUpdateState(),
		pending:   newPendingSecrets(),
		resets:    newResetTickets(),
		passkeys:  pk,
		files:     dateien,
	}, nil
}

// buildFiles richtet den Dateimanager ein.
//
// Ohne Eintrag in der Konfiguration gilt die Vorgabe: Lesen überall, Schreiben
// in den Bereichen, die die systemd-Unit zulässt. `files.enabled: false`
// schaltet das Modul ab — dann entstehen weder Routen noch Menüpunkt.
//
// Eine widersprüchliche Politik schaltet ebenfalls ab, mit Meldung im
// Protokoll. Der Dienst startet trotzdem: Ein Panel, das wegen einer
// Einstellung des Dateimanagers nicht mehr erreichbar ist, wäre die schlechtere
// Antwort — dann käme man an genau die Einstellung nicht mehr heran.
func buildFiles(cfg config.Config, log *slog.Logger) privops.Files {
	if !cfg.Files.On() {
		log.Info("Dateimanager abgeschaltet (files.enabled: false)")
		return nil
	}

	pol := privops.DefaultFilesPolicy(filepath.Join(cfg.Paths.Data, "backups"))
	if len(cfg.Files.ReadableRoots) > 0 {
		pol.ReadableRoots = cfg.Files.ReadableRoots
	}
	// Unterschied zwischen "nicht gesetzt" und "ausdrücklich leer": Ein
	// `writable_roots: []` macht den Dateimanager bewusst nur lesend, und das
	// darf die Vorgabe nicht überschreiben.
	if cfg.Files.WritableRoots != nil {
		pol.WritableRoots = cfg.Files.WritableRoots
	}
	pol.DeniedPaths = cfg.Files.DeniedPaths
	pol.FollowSymlinks = cfg.Files.FollowSymlinks

	upload, edit, err := cfg.Files.Limits()
	if err != nil {
		// Kann hier nicht mehr auftreten (Validate hat es geprüft), wäre aber
		// stillschweigend zu übergehen der falsche Umgang damit.
		log.Error("Dateimanager: Größengrenzen unlesbar", "err", err)
		return nil
	}
	if upload > 0 {
		pol.MaxUpload = upload
	}
	if edit > 0 {
		pol.MaxEditSize = edit
	}

	// Schreibwurzeln, die es auf diesem System nicht gibt, fallen heraus: Nicht
	// jede Installation hat /srv oder /media, und eine Wurzel, die auf nichts
	// zeigt, wäre nur ein irreführender Eintrag in jeder Fehlermeldung.
	if cfg.Files.WritableRoots == nil {
		vorhanden := make([]string, 0, len(pol.WritableRoots))
		for _, w := range pol.WritableRoots {
			if info, err := os.Stat(w); err == nil && info.IsDir() {
				vorhanden = append(vorhanden, w)
			}
		}
		pol.WritableRoots = vorhanden
	}

	fsys, err := privops.NewFileSystem(pol)
	if err != nil {
		log.Error("Dateimanager konnte nicht eingerichtet werden — das Modul bleibt aus", "err", err)
		return nil
	}
	log.Info("Dateimanager aktiv",
		"lesbar", strings.Join(pol.ReadableRoots, ","),
		"schreibbar", strings.Join(pol.WritableRoots, ","))
	return fsys
}

// buildPasskeys richtet den WebAuthn-Manager ein. Ohne Zutun: Passkeys sind an,
// sobald sich ein auflösbarer Name als RP-ID ableiten lässt — aus der
// ausdrücklichen Angabe, den ACME-Domains, dem aktiven Zertifikat oder dem
// vollqualifizierten Rechnernamen. So muss für den Normalfall nichts in der
// Konfiguration stehen; spätestens mit einem Zertifikat auf einen echten Namen
// erscheint der Passkey-Abschnitt von selbst. `enabled: false` schaltet aus,
// `enabled: true` erzwingt und warnt, wenn kein Name feststeht.
func buildPasskeys(cfg config.Config, log *slog.Logger) *passkeys.Manager {
	w := cfg.Auth.WebAuthn
	if w.Enabled != nil && !*w.Enabled {
		return nil // ausdrücklich aus
	}

	rpID := deriveRPID(cfg)
	if rpID == "" {
		if w.Enabled != nil && *w.Enabled {
			log.Warn("Passkeys sind eingeschaltet, aber es steht kein auflösbarer Name fest — " +
				"bitte auth.webauthn.rp_id auf die Domain des Panels setzen")
		}
		return nil
	}

	origins := w.Origins
	if len(origins) == 0 {
		origin := "https://" + rpID
		if cfg.Server.Port != 443 {
			origin = fmt.Sprintf("https://%s:%d", rpID, cfg.Server.Port)
		}
		origins = []string{origin}
	}

	name := w.DisplayName
	if name == "" {
		name = "Project Asylum"
	}
	m, err := passkeys.New(passkeys.Config{RPID: rpID, DisplayName: name, Origins: origins})
	if err != nil {
		log.Error("Passkeys konnten nicht eingerichtet werden", "err", err)
		return nil
	}
	log.Info("Passkeys aktiv", "rp_id", rpID)
	return m
}

// deriveRPID sucht den besten Namen für die RP-ID der Reihe nach: ausdrückliche
// Angabe, ACME-Domains, die Namen im aktiven Zertifikat, zuletzt der
// vollqualifizierte Rechnername. Geliefert wird nur ein für WebAuthn
// brauchbarer Name.
func deriveRPID(cfg config.Config) string {
	if n := usableRPID(cfg.Auth.WebAuthn.RPID); n != "" {
		return n
	}
	for _, d := range cfg.ACME.Domains {
		if n := usableRPID(d); n != "" {
			return n
		}
	}
	if info, err := certs.Describe(cfg.Server.TLS.Cert); err == nil {
		for _, d := range info.DNSNames {
			if n := usableRPID(d); n != "" {
				return n
			}
		}
	}
	return usableRPID(netinfo.FQDN())
}

// usableRPID gibt den Namen zurück, wenn er sich als RP-ID eignet, sonst "".
// WebAuthn verlangt eine registrierbare Domain: keine IP, und ohne Punkt nur
// das für die Entwicklung erlaubte localhost.
func usableRPID(name string) string {
	name = strings.TrimSpace(name)
	if name == "" || net.ParseIP(name) != nil {
		return ""
	}
	if !strings.Contains(name, ".") && name != "localhost" {
		return ""
	}
	return name
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
	// Das Zertifikat kommt über GetCertificate aus einem Halter statt aus einer
	// festen Certificates-Liste. Beim selbstsignierten Betrieb ist das
	// gleichbedeutend; der Halter ist die Stelle, an der die spätere
	// ACME-Erneuerung das Zertifikat ohne Neustart austauscht.
	s.certHolder = certs.NewHolder(keyPair)

	srv := &http.Server{
		Addr:              s.cfg.Addr(),
		Handler:           s.Handler(),
		ReadHeaderTimeout: readHeaderTimeout,
		ReadTimeout:       readTimeout,
		IdleTimeout:       idleTimeout,
		ErrorLog:          slog.NewLogLogger(s.log.Handler(), slog.LevelWarn),
		TLSConfig: &tls.Config{
			GetCertificate: s.certHolder.GetCertificate,
			MinVersion:     tls.VersionTLS12,
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

	// Der Zertifikatsbezug läuft unter eigener Aufsicht: Er lässt sich zur
	// Laufzeit neu starten, wenn jemand die Einstellungen in der Oberfläche
	// ändert. Startet er nicht, bleibt das selbstsignierte Paar — das Panel
	// ist erreichbar, notfalls mit Warnung.
	s.startACME(bgCtx)
	defer s.stopACME()

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
	// Absolute Größen — Speicher, Dateisysteme, Prozesse — stehen aber schon
	// hier, und die gehören sofort auf die Seite.
	s.setLatest(s.sampler.Sample())

	tick := 0
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			snap := s.sampler.Sample()
			s.setLatest(snap)
			s.hub.broadcast(snap)
			tick++
			if tick%historyEvery == 0 {
				s.ring.Add(snap)
			}
		}
	}
}

// Hier stand bis zum Abbau der alten Oberfläche ein zweiter Erhebungsweg: Der
// Messtakt zog alle fünf Minuten den Handlungsbedarf nach und legte ihn in einem
// Cache ab, damit die Warnpunkte an der Symbolschiene auf JEDER Seite stehen
// konnten, ohne dass jeder Seitenaufruf an einem systemctl und einem apt hängt.
//
// Mit der Schiene ist der einzige Leser des Caches gegangen. Was blieb, wäre eine
// Erhebung im Hintergrund für niemanden — alle fünf Minuten ein systemctl und ein
// apt, damit ein Feld beschrieben wird, das keine Antwort mehr liest. Deshalb ist
// der ganze Weg weg und nicht nur sein Aufruf.
//
// Den Handlungsbedarf erhebt jetzt allein /api/v1/signals, und zwar frisch bei
// jeder Anfrage. Das ist der teurere Weg pro Aufruf und der ehrlichere: Die neue
// Übersicht fragt danach, wenn sie ihn zeigt, und was sie zeigt, ist dann von
// jetzt und nicht von vor fünf Minuten.

// setLatest merkt sich die jüngste Messung.
func (s *Server) setLatest(snap metrics.Snapshot) {
	s.latestMu.Lock()
	defer s.latestMu.Unlock()
	s.latest = snap
	s.hasLast = true
}

// lastSnapshot liefert die jüngste Messung für den Seitenaufbau.
func (s *Server) lastSnapshot() (metrics.Snapshot, bool) {
	s.latestMu.RLock()
	defer s.latestMu.RUnlock()
	if s.hasLast {
		return s.latest, true
	}
	// Vor der ersten Messung: was im Ring liegt, ist besser als nichts.
	return s.ring.Last()
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
