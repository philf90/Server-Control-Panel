package acme

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"os"
	"strings"
	"time"
)

// DNS-01-Anbieter. Die Werte entsprechen der Konfiguration; die Konstanten
// stehen hier eigenständig, damit das acme-Paket nicht von config abhängt.
const (
	providerHook       = "hook"
	providerCloudflare = "cloudflare"
)

const (
	dnsChallengePrefix = "_acme-challenge."
	defaultDNSWait     = 2 * time.Minute
	defaultDNSPoll     = 4 * time.Second
)

// dnsSetter setzt und entfernt den TXT-Record beim Anbieter.
type dnsSetter interface {
	setTXT(ctx context.Context, domain, record, value string) error
	removeTXT(ctx context.Context, domain, record, value string) error
}

// dns01Solver bedient die DNS-01-Prüfung: TXT-Record setzen, auf Sichtbarkeit
// warten, nach der Prüfung wieder entfernen. Der Wert kommt vom acme.Client.
//
// Das Warten auf Sichtbarkeit ist best effort: Wird der Record binnen der Frist
// nicht sichtbar, stößt der Manager die Prüfung trotzdem an — Let's Encrypt
// prüft ohnehin mehrfach. Ohne jedes Warten scheiterte die erste Ausstellung
// aber fast immer an der Ausbreitungszeit.
type dns01Solver struct {
	setter      dnsSetter
	log         *slog.Logger
	waitTimeout time.Duration
	pollEvery   time.Duration
	now         func() time.Time
	lookupTXT   func(ctx context.Context, name string) ([]string, error)
}

func newDNS01Solver(setter dnsSetter, log *slog.Logger) *dns01Solver {
	return &dns01Solver{
		setter:      setter,
		log:         log,
		waitTimeout: defaultDNSWait,
		pollEvery:   defaultDNSPoll,
		now:         time.Now,
		lookupTXT:   net.DefaultResolver.LookupTXT,
	}
}

func (d *dns01Solver) challengeType() string { return "dns-01" }

func (d *dns01Solver) present(ctx context.Context, domain, _, value string) error {
	record := dnsChallengePrefix + strings.TrimSuffix(domain, ".")
	if err := d.setter.setTXT(ctx, domain, record, value); err != nil {
		return fmt.Errorf("TXT-Record setzen: %w", err)
	}
	d.waitForPropagation(ctx, record, value)
	return nil
}

func (d *dns01Solver) cleanup(ctx context.Context, domain, _, value string) error {
	record := dnsChallengePrefix + strings.TrimSuffix(domain, ".")
	return d.setter.removeTXT(ctx, domain, record, value)
}

func (d *dns01Solver) waitForPropagation(ctx context.Context, record, value string) {
	deadline := d.now().Add(d.waitTimeout)
	for {
		if txts, err := d.lookupTXT(ctx, record); err == nil {
			for _, t := range txts {
				if t == value {
					return
				}
			}
		}
		if d.now().After(deadline) {
			d.log.Warn("TXT-Record noch nicht sichtbar, ACME-Prüfung wird trotzdem angestoßen", "record", record)
			return
		}
		t := time.NewTimer(d.pollEvery)
		select {
		case <-ctx.Done():
			t.Stop()
			return
		case <-t.C:
		}
	}
}

// newDNSSetter baut den anbieterspezifischen Setzer aus der Konfiguration.
func newDNSSetter(opts Options) (dnsSetter, error) {
	switch opts.DNS01Provider {
	case providerHook:
		if opts.HookSet == "" || opts.HookClean == "" {
			return nil, errors.New("dns-01 hook: set und clean müssen gesetzt sein")
		}
		return &hookSetter{set: opts.HookSet, clean: opts.HookClean}, nil
	case providerCloudflare:
		raw, err := os.ReadFile(opts.CloudflareTokenFile) //nolint:gosec // Pfad aus der Konfiguration
		if err != nil {
			return nil, fmt.Errorf("cloudflare-token lesen: %w", err)
		}
		token := strings.TrimSpace(string(raw))
		if token == "" {
			return nil, errors.New("cloudflare-token-datei ist leer")
		}
		return newCloudflareSetter(token), nil
	case "":
		return nil, errors.New("dns-01 verlangt einen Anbieter (hook|cloudflare)")
	default:
		return nil, fmt.Errorf("unbekannter dns-01-anbieter %q", opts.DNS01Provider)
	}
}
