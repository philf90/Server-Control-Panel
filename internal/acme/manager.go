package acme

import (
	"context"
	"crypto/tls"
	"errors"
	"fmt"
	"log/slog"
	"time"

	"github.com/philf90/asylum/internal/certs"
)

const (
	// defaultRenewBefore: Erneuern, sobald weniger als 30 Tage übrig sind.
	// Let's-Encrypt-Zertifikate laufen 90 Tage.
	defaultRenewBefore = 30 * 24 * time.Hour
	// retryInterval: Nach einem Fehlversuch wird nicht sofort erneut angefragt —
	// das liefe in die Rate-Limits. Eine Stunde ist ein Kompromiss zwischen
	// „schnell wieder da" und „schont die Grenze".
	retryInterval = time.Hour
)

// issuer besorgt ein Zertifikat für die Domains. Die echte Umsetzung spricht
// ACME; Tests setzen eine Attrappe ein, damit die Ablaufsteuerung ohne echten
// CA prüfbar ist.
type issuer interface {
	obtain(ctx context.Context, domains []string) (certPEM, keyPEM []byte, err error)
}

// Options bündelt, was der Manager aus der Konfiguration braucht.
type Options struct {
	Dir          string   // Ablage für Kontoschlüssel und Zertifikat
	Email        string   // Kontakt des ACME-Kontos
	Domains      []string // Namen im Zertifikat
	DirectoryURL string   // leer = LE-Produktion; für Tests das Staging
	Challenge    string   // "" (automatisch) | http-01 | dns-01
	HTTP01Addr   string   // Bindeadresse für HTTP-01, Vorgabe ":80"

	// DNS-01: Anbieter und dessen Zugang. Ist ein Anbieter gesetzt, wählt die
	// automatische Challenge-Bestimmung DNS-01 statt HTTP-01.
	DNS01Provider       string // "" | hook | cloudflare
	HookSet             string
	HookClean           string
	CloudflareTokenFile string
}

// Manager spielt ein vorhandenes Zertifikat ein, besorgt bei Bedarf ein neues
// und erneuert vor Ablauf. Er schreibt ausschließlich in den Halter — schlägt
// etwas fehl, bleibt dort das selbstsignierte Zertifikat.
type Manager struct {
	dir         string
	domains     []string
	holder      *certs.Holder
	issuer      issuer
	renewBefore time.Duration
	log         *slog.Logger
	now         func() time.Time
}

// New baut den Manager aus der Konfiguration.
func New(opts Options, holder *certs.Holder, log *slog.Logger) (*Manager, error) {
	if len(opts.Domains) == 0 {
		return nil, errors.New("keine Domain für ACME")
	}
	factory, err := solverFactory(opts, log)
	if err != nil {
		return nil, err
	}
	return &Manager{
		dir:     opts.Dir,
		domains: opts.Domains,
		holder:  holder,
		issuer: &acmeIssuer{
			dir:          opts.Dir,
			email:        opts.Email,
			directoryURL: opts.DirectoryURL,
			newSolver:    factory,
			log:          log,
		},
		renewBefore: defaultRenewBefore,
		log:         log,
		now:         time.Now,
	}, nil
}

// solverFactory wählt den Challenge-Löser. Automatisch (leere Challenge) wählt
// DNS-01, wenn ein DNS-Anbieter konfiguriert ist, sonst HTTP-01 — das löst den
// Fall, dass auf Port 80 schon ein Webserver läuft.
func solverFactory(opts Options, log *slog.Logger) (func(context.Context) (challengeSolver, error), error) {
	challenge := opts.Challenge
	if challenge == "" {
		if opts.DNS01Provider != "" {
			challenge = "dns-01"
		} else {
			challenge = "http-01"
		}
	}
	switch challenge {
	case "http-01":
		addr := opts.HTTP01Addr
		if addr == "" {
			addr = ":80"
		}
		return func(ctx context.Context) (challengeSolver, error) {
			return newHTTP01Solver(ctx, addr)
		}, nil
	case "dns-01":
		setter, err := newDNSSetter(opts)
		if err != nil {
			return nil, err
		}
		return func(context.Context) (challengeSolver, error) {
			return newDNS01Solver(setter, log), nil
		}, nil
	default:
		return nil, fmt.Errorf("unbekannte challenge %q", challenge)
	}
}

// Start blockiert, bis ctx abgebrochen wird. Es spielt das Zertifikat ein,
// erneuert vor Ablauf und wartet dazwischen.
func (m *Manager) Start(ctx context.Context) {
	for {
		wait := m.ensure(ctx)
		t := time.NewTimer(wait)
		select {
		case <-ctx.Done():
			t.Stop()
			return
		case <-t.C:
		}
	}
}

// ensure stellt sicher, dass ein gültiges Zertifikat im Halter liegt, und
// liefert die Wartezeit bis zur nächsten Prüfung.
func (m *Manager) ensure(ctx context.Context) time.Duration {
	if cert, err := loadCert(m.dir); err == nil {
		// Restlaufzeit allein genügt nicht. Wer die Domains ändert — seit die
		// Einstellungen in der Oberfläche liegen, ein Klick — hätte sonst bis
		// zu 60 Tage lang weiter das Zertifikat für die alten Namen
		// ausgeliefert, und der Browser hätte zu Recht gewarnt.
		if rem := m.remaining(cert); rem > m.renewBefore && covers(cert, m.domains) {
			m.holder.Set(cert)
			return rem - m.renewBefore
		}
	}

	if err := m.obtainAndStore(ctx); err != nil {
		m.log.Warn("ACME-Bezug fehlgeschlagen, selbstsigniertes Zertifikat bleibt",
			"domains", m.domains, "err", err)
		return retryInterval
	}

	cert, err := loadCert(m.dir)
	if err != nil {
		m.log.Warn("frisch bezogenes Zertifikat nicht ladbar", "err", err)
		return retryInterval
	}
	m.holder.Set(cert)
	m.log.Info("ACME-Zertifikat aktiv", "domains", m.domains, "ablauf", cert.Leaf.NotAfter)

	rem := m.remaining(cert)
	if rem <= m.renewBefore {
		// Sollte bei 90-Tage-Zertifikat und 30-Tage-Schwelle nicht vorkommen.
		// Nicht sofort erneut anfragen — das liefe in die Rate-Limits.
		m.log.Warn("frisches Zertifikat liegt bereits unter der Erneuerungsschwelle", "rem", rem)
		return 24 * time.Hour
	}
	return rem - m.renewBefore
}

// ObtainNow besorgt sofort ein Zertifikat, ohne auf ein vorhandenes zu sehen.
//
// Der Knopf "Jetzt beziehen" in der Oberfläche braucht das: Nach einer
// Änderung an Anbieter oder Zugangsdaten will man wissen, ob es klappt — und
// nicht bis zur nächsten Erneuerung warten. Die Rate-Limits der CA liegen
// damit in der Hand des Bedienenden; die Oberfläche sagt das dazu.
func (m *Manager) ObtainNow(ctx context.Context) error {
	if err := m.obtainAndStore(ctx); err != nil {
		return err
	}
	cert, err := loadCert(m.dir)
	if err != nil {
		return fmt.Errorf("frisch bezogenes Zertifikat nicht ladbar: %w", err)
	}
	m.holder.Set(cert)
	m.log.Info("ACME-Zertifikat bezogen", "domains", m.domains, "ablauf", cert.Leaf.NotAfter)
	return nil
}

// Domains liefert die Namen, für die dieser Manager arbeitet.
func (m *Manager) Domains() []string { return m.domains }

// covers sagt, ob das Zertifikat alle geforderten Namen abdeckt.
func covers(cert tls.Certificate, domains []string) bool {
	if cert.Leaf == nil {
		return false
	}
	for _, d := range domains {
		if cert.Leaf.VerifyHostname(d) != nil {
			return false
		}
	}
	return true
}

func (m *Manager) obtainAndStore(ctx context.Context) error {
	certPEM, keyPEM, err := m.issuer.obtain(ctx, m.domains)
	if err != nil {
		return err
	}
	return saveCert(m.dir, certPEM, keyPEM)
}

// remaining liefert die Restlaufzeit des Zertifikats. Fehlt das geparste Leaf,
// gilt es als abgelaufen (Restzeit 0), damit erneuert statt vertraut wird.
func (m *Manager) remaining(cert tls.Certificate) time.Duration {
	if cert.Leaf == nil {
		return 0
	}
	return cert.Leaf.NotAfter.Sub(m.now())
}
