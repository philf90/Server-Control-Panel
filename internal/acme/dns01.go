package acme

import (
	"context"
	"fmt"
	"log/slog"
	"net"
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
	report      reporter
	waitTimeout time.Duration
	pollEvery   time.Duration
	now         func() time.Time
	lookupTXT   func(ctx context.Context, name string) ([]string, error)
}

func newDNS01Solver(setter dnsSetter, log *slog.Logger, report reporter) *dns01Solver {
	return &dns01Solver{
		setter:      setter,
		log:         log,
		report:      report,
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
	// Der Inhalt des Records wird nicht gemeldet: Er ist zwar kein dauerhaftes
	// Geheimnis, aber er ist der Beweis, mit dem die Ausstellung erschlichen
	// werden könnte, solange er gilt. Der Name genügt zum Nachsehen.
	d.report.step("%s: TXT-Record gesetzt", record)
	d.waitForPropagation(ctx, record, value)
	return nil
}

func (d *dns01Solver) cleanup(ctx context.Context, domain, _, value string) error {
	record := dnsChallengePrefix + strings.TrimSuffix(domain, ".")
	err := d.setter.removeTXT(ctx, domain, record, value)
	if err != nil {
		// Kein Abbruch: Das Zertifikat ist zu diesem Zeitpunkt längst
		// ausgestellt. Ein liegengebliebener Record gehört aber gesagt, sonst
		// sucht ihn beim nächsten Mal niemand.
		d.report.step("%s: konnte nicht entfernt werden (%v) — bitte von Hand löschen", record, err)
		return err
	}
	d.report.step("%s: TXT-Record entfernt", record)
	return nil
}

// waitForPropagation wartet, bis der Record über das öffentliche DNS sichtbar
// ist. Das ist der langsamste Abschnitt des ganzen Bezugs — bis zu zwei
// Minuten — und war bis hierher der stummste. Deshalb meldet er alle paar
// Versuche, wie lange er schon wartet: Wer nichts sieht, hält einen laufenden
// Vorgang für einen hängenden.
func (d *dns01Solver) waitForPropagation(ctx context.Context, record, value string) {
	start := d.now()
	deadline := start.Add(d.waitTimeout)
	gemeldet := false

	for {
		if txts, err := d.lookupTXT(ctx, record); err == nil {
			for _, t := range txts {
				if t == value {
					d.report.step("%s: sichtbar nach %s", record, seit(start, d.now()))
					return
				}
			}
		}
		if d.now().After(deadline) {
			d.log.Warn("TXT-Record noch nicht sichtbar, ACME-Prüfung wird trotzdem angestoßen", "record", record)
			d.report.step("%s: nach %s noch nicht sichtbar — die Prüfung wird trotzdem angestoßen, "+
				"Let's Encrypt fragt mehrfach nach", record, seit(start, d.now()))
			return
		}
		if !gemeldet {
			// Nur einmal. Eine Zeile alle vier Sekunden wäre kein Fortschritt,
			// sondern eine Wand aus Text.
			d.report.step("%s: warte auf Sichtbarkeit im DNS (bis zu %s)", record, d.waitTimeout)
			gemeldet = true
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

// seit rundet die verstrichene Zeit auf Sekunden — Millisekunden sind hier
// Rauschen.
func seit(start, jetzt time.Time) time.Duration {
	return jetzt.Sub(start).Round(time.Second)
}
