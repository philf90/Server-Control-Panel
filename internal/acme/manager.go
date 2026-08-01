package acme

import (
	"context"
	"crypto/tls"
	"errors"
	"fmt"
	"log/slog"
	"path/filepath"
	"strings"
	"sync"
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

	// Kennung benennt das Zertifikat, wenn es nicht das des Panels ist.
	//
	// Leer heißt: das Panel — dann liegt das Zertifikat wie bisher unmittelbar
	// in Dir, und der Halter bekommt es über Set. Ist eine Kennung gesetzt,
	// liegt es in Dir/sites/<kennung>, und der Halter bekommt es über SetzeSite.
	//
	// Der KONTOSCHLÜSSEL bleibt in beiden Fällen in Dir und wird geteilt. Das
	// ist keine Bequemlichkeit: Ein eigener Schlüssel je Site wäre ein eigenes
	// ACME-Konto, und Kontoanmeldungen zählen gegen die Grenzen von Let's
	// Encrypt. Zwanzig Sites wären zwanzig Konten.
	Kennung string

	// Webroot ist ein Verzeichnis, aus dem ein Webserver
	// /.well-known/acme-challenge/ ausliefert. Ist es gesetzt, legt HTTP-01 die
	// Token dort als Dateien ab, statt selbst auf Port 80 zu lauschen —
	// HTTP01Addr bleibt dann ungenutzt.
	//
	// Der Wert kommt nicht aus der Konfiguration des Betreibers, sondern aus
	// dem Zustand des Servers: Verwaltet das Panel ein laufendes nginx, hat es
	// den Weg dorthin selbst gelegt und trägt ihn hier ein. Steht hier nichts,
	// lauscht das Panel wie bisher selbst. Siehe docs/18-webserver.md §3.
	Webroot string

	// DNS-01: Anbieter und dessen Zugang. Ist ein Anbieter gesetzt, wählt die
	// automatische Challenge-Bestimmung DNS-01 statt HTTP-01; ein Platzhalter
	// im Namen macht sie zur Pflicht.
	DNS01Provider string // "" | hook | einer aus dem Register (register.go)
	// HookSet und HookClean gehören zum Anbieter „hook" — zwei Programmpfade
	// statt einer Zugangsdatei, weil ein Hook kein Geheimnis des Panels ist.
	HookSet   string
	HookClean string
	// ZugangsDatei ist der Pfad zur Datei mit den Zugangsdaten des Anbieters.
	// Ein Feld für alle: In der Konfiguration steht damit nie ein Geheimnis,
	// und die Rechte der Datei lassen sich prüfen. Siehe zugang.go.
	ZugangsDatei string

	// Progress bekommt den Verlauf jedes Bezugs gemeldet — auch den der
	// nächtlichen Erneuerung, nicht nur den eines angestoßenen. Darf nil sein.
	Progress Progress
}

// Manager spielt ein vorhandenes Zertifikat ein, besorgt bei Bedarf ein neues
// und erneuert vor Ablauf. Er schreibt ausschließlich in den Halter — schlägt
// etwas fehl, bleibt dort das selbstsignierte Zertifikat.
type Manager struct {
	// dir ist das ACME-Wurzelverzeichnis (Kontoschlüssel).
	dir string
	// zertDir ist das Verzeichnis DIESES Zertifikats — bei einer Site ein
	// Unterverzeichnis, beim Panel dasselbe wie dir.
	zertDir     string
	kennung     string
	domains     []string
	holder      *certs.Holder
	issuer      issuer
	renewBefore time.Duration
	log         *slog.Logger
	now         func() time.Time
	report      reporter

	// obtainMu lässt nur einen Bezug zur Zeit zu. Die Hintergrunderneuerung und
	// der Knopf "Jetzt beziehen" laufen in verschiedenen Goroutinen; ohne die
	// Sperre schrieben beide in dasselbe Verzeichnis, und ihre Fortschrittszeilen
	// liefen ineinander.
	obtainMu sync.Mutex
}

// New baut den Manager aus der Konfiguration.
func New(opts Options, holder *certs.Holder, log *slog.Logger) (*Manager, error) {
	if len(opts.Domains) == 0 {
		return nil, errors.New("keine Domain für ACME")
	}
	// Die Namen werden hier geprüft und vereinheitlicht, bevor irgendetwas
	// anderes sie ansieht — VOR der Wahl des Lösers. Ein Tippfehler soll als
	// Tippfehler gemeldet werden und nicht als „kein DNS-Anbieter
	// eingerichtet", und er soll auffallen, bevor ein Fehlversuch beim
	// CA-Server zählt.
	if opts.Kennung != "" {
		if err := PruefeKennung(opts.Kennung); err != nil {
			return nil, err
		}
	}
	domains, err := PruefeZertifikatsnamen(opts.Domains)
	if err != nil {
		return nil, err
	}
	opts.Domains = domains

	rep := reporter{p: opts.Progress}
	factory, err := solverFactory(opts, log, rep)
	if err != nil {
		return nil, err
	}
	if fehlt := FehlendeBasis(domains); len(fehlt) > 0 {
		// Eine Auskunft, keine Ablehnung: Ein Zertifikat nur für
		// *.example.com ist ein zulässiger Wunsch. Aber es ist fast nie der
		// gemeinte, und wer es erst am Browserfehler merkt, hat ein zweites
		// Zertifikat gebraucht.
		log.Info("ACME: der Platzhalter deckt die nackte Domain nicht ab",
			"fehlt", strings.Join(fehlt, ", "))
		rep.step("Hinweis: ein Platzhalter deckt %s nicht ab — dafür braucht es "+
			"den Namen zusätzlich im Zertifikat", strings.Join(fehlt, ", "))
	}
	return &Manager{
		dir:     opts.Dir,
		zertDir: zertVerzeichnis(opts.Dir, opts.Kennung),
		kennung: opts.Kennung,
		domains: domains,
		holder:  holder,
		issuer: &acmeIssuer{
			dir:          opts.Dir,
			email:        opts.Email,
			directoryURL: opts.DirectoryURL,
			newSolver:    factory,
			log:          log,
			report:       rep,
		},
		renewBefore: defaultRenewBefore,
		log:         log,
		now:         time.Now,
		report:      rep,
	}, nil
}

// solverFactory wählt den Challenge-Löser.
//
// Drei Regeln, und die erste sticht die beiden anderen:
//
//  1. **Ist ein Platzhalter unter den Namen, ist DNS-01 Pflicht.** Let's
//     Encrypt bietet für ein Wildcard-Zertifikat gar keine HTTP-01-Challenge
//     an. Ohne diese Regel liefe der Bezug bis zum CA-Server und scheiterte
//     dort an „der Server bietet keine http-01-Challenge" — eine Meldung, die
//     nicht sagt, was zu tun ist. Sie sticht auch eine ausdrückliche
//     Einstellung `challenge: http-01`: Die ist dann schlicht nicht erfüllbar,
//     und ein Fehlversuch zählt bei Let's Encrypt mit.
//  2. Automatisch (leere Challenge): DNS-01, wenn ein Anbieter konfiguriert
//     ist — das löst den Fall, dass auf Port 80 schon ein Webserver läuft.
//  3. Sonst HTTP-01.
func solverFactory(opts Options, log *slog.Logger, report reporter) (func(context.Context) (challengeSolver, error), error) {
	challenge := opts.Challenge
	if challenge == "" {
		if opts.DNS01Provider != "" {
			challenge = "dns-01"
		} else {
			challenge = "http-01"
		}
	}
	if EnthaeltWildcard(opts.Domains) {
		if opts.DNS01Provider == "" {
			return nil, fmt.Errorf("%s verlangt DNS-01 (Let's Encrypt prüft Platzhalter "+
				"nur über das DNS), aber es ist kein DNS-Anbieter eingerichtet",
				ersterWildcard(opts.Domains))
		}
		if challenge == "http-01" {
			log.Warn("ACME: Platzhalter im Namen — die Prüfung läuft über DNS-01 "+
				"statt über die eingestellte http-01", "domain", ersterWildcard(opts.Domains))
		}
		challenge = "dns-01"
	}
	switch challenge {
	case "http-01":
		// Der Weg durch den Webserver hindurch geht vor. Er belegt keinen Port
		// und ist damit der einzige, der neben einem laufenden nginx noch
		// funktioniert — und genau dieses nginx kann das Panel seit 0.6 selbst
		// eingespielt haben.
		if opts.Webroot != "" {
			solver := newWebrootSolver(opts.Webroot)
			return func(context.Context) (challengeSolver, error) {
				return solver, nil
			}, nil
		}
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
			return newDNS01Solver(setter, log, report), nil
		}, nil
	default:
		return nil, fmt.Errorf("unbekannte challenge %q", challenge)
	}
}

// ersterWildcard nennt den ersten Platzhalter — für eine Meldung, die sagt,
// welcher Name die Regel auslöst. „Ein Name verlangt DNS-01" ohne den Namen
// befähigt zu keiner Entscheidung.
func ersterWildcard(namen []string) string {
	for _, n := range namen {
		if IstWildcard(n) {
			return n
		}
	}
	return ""
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
	if cert, err := loadCert(m.zertDir); err == nil {
		// Restlaufzeit allein genügt nicht. Wer die Domains ändert — seit die
		// Einstellungen in der Oberfläche liegen, ein Klick — hätte sonst bis
		// zu 60 Tage lang weiter das Zertifikat für die alten Namen
		// ausgeliefert, und der Browser hätte zu Recht gewarnt.
		if rem := m.remaining(cert); rem > m.renewBefore && covers(cert, m.domains) {
			if err := m.einsetzen(cert); err != nil {
				m.log.Warn("vorhandenes Zertifikat ließ sich nicht einsetzen", "err", err)
			} else {
				return rem - m.renewBefore
			}
		}
	}

	cert, err := m.runObtain(ctx)
	if err != nil {
		m.log.Warn("ACME-Bezug fehlgeschlagen, selbstsigniertes Zertifikat bleibt",
			"domains", m.domains, "err", err)
		return retryInterval
	}
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
	cert, err := m.runObtain(ctx)
	if err != nil {
		return err
	}
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

// runObtain ist der einzige Weg zu einem neuen Zertifikat. Beide Auslöser —
// die Erneuerung im Hintergrund und der Knopf in der Oberfläche — gehen hier
// durch, damit der Verlauf in beiden Fällen gemeldet wird. Eine Erneuerung, die
// nachts um drei von selbst läuft, hinterlässt so am Morgen einen lesbaren
// Ablauf statt einer einzigen Logzeile.
func (m *Manager) runObtain(ctx context.Context) (tls.Certificate, error) {
	m.obtainMu.Lock()
	defer m.obtainMu.Unlock()

	m.report.begin(m.domains)
	cert, err := m.obtainStoreLoad(ctx)
	m.report.end(err)
	return cert, err
}

func (m *Manager) obtainStoreLoad(ctx context.Context) (tls.Certificate, error) {
	certPEM, keyPEM, err := m.issuer.obtain(ctx, m.domains)
	if err != nil {
		return tls.Certificate{}, err
	}
	if err := saveCert(m.zertDir, certPEM, keyPEM); err != nil {
		return tls.Certificate{}, fmt.Errorf("zertifikat ablegen: %w", err)
	}
	cert, err := loadCert(m.zertDir)
	if err != nil {
		return tls.Certificate{}, fmt.Errorf("frisch bezogenes Zertifikat nicht ladbar: %w", err)
	}

	// Erst hier wird ausgeliefert. Bis zu dieser Zeile hat der Browser noch das
	// alte Zertifikat bekommen — das ist der Sinn des Halters.
	if err := m.einsetzen(cert); err != nil {
		return tls.Certificate{}, fmt.Errorf("zertifikat einsetzen: %w", err)
	}
	if cert.Leaf != nil {
		m.report.step("Zertifikat eingesetzt, gültig bis %s",
			cert.Leaf.NotAfter.Format("2006-01-02 15:04 MST"))
	}
	return cert, nil
}

// remaining liefert die Restlaufzeit des Zertifikats. Fehlt das geparste Leaf,
// gilt es als abgelaufen (Restzeit 0), damit erneuert statt vertraut wird.
func (m *Manager) remaining(cert tls.Certificate) time.Duration {
	if cert.Leaf == nil {
		return 0
	}
	return cert.Leaf.NotAfter.Sub(m.now())
}

// PruefeKennung prüft den Namen, unter dem ein Zertifikat abgelegt wird.
//
// Er wird zu einem VERZEICHNISNAMEN, und mit den Sites kommt er aus einem
// Formular. Eine Allowlist der zulässigen Form und keine Sperrliste — dieselbe
// Haltung wie beim Challenge-Token und beim Domainnamen.
//
// Der Fall, der das nötig macht, ist nicht theoretisch: `filepath.Base("..")`
// ist `".."`, und `filepath.Join(wurzel, "sites", "..")` kürzt sich auf das
// WURZELVERZEICHNIS. Eine Site mit der Kennung „.." hätte damit das Zertifikat
// des Panels überschrieben — genau der Fehler, gegen den der Halter seine
// Vorrangregel hat. Gefunden hat das ein Test, der es versucht hat.
func PruefeKennung(kennung string) error {
	if kennung == "" {
		return errors.New("leere Kennung")
	}
	if len(kennung) > 64 {
		return errors.New("Kennung länger als 64 Zeichen")
	}
	for i, r := range kennung {
		switch {
		case r >= 'a' && r <= 'z', r >= '0' && r <= '9':
			continue
		case (r == '-' || r == '_') && i > 0:
			continue
		}
		return fmt.Errorf("unzulässiges Zeichen %q in der Kennung %q "+
			"(erlaubt: a–z, 0–9, - und _)", string(r), kennung)
	}
	return nil
}

// zertVerzeichnis liefert den Ort des Zertifikats.
//
// Ohne Kennung ist es das Wurzelverzeichnis — genau wie bis 0.5, damit eine
// vorhandene Installation ihr Zertifikat dort wiederfindet, wo es liegt. Ein
// Umzug wäre ein Bezug mehr für nichts.
//
// Die Kennung ist an dieser Stelle bereits von PruefeKennung angenommen. Die
// Absicherung hier steht trotzdem — sie ist die zweite Linie, und sie kostet
// eine Zeile.
func zertVerzeichnis(wurzel, kennung string) string {
	if kennung == "" {
		return wurzel
	}
	name := filepath.Base(filepath.Clean(kennung))
	if name == "." || name == ".." || name == string(filepath.Separator) {
		// Nicht stillschweigend auf das Wurzelverzeichnis fallen: Dort liegt das
		// Zertifikat des Panels. Ein Name, der hier landet, hat die Prüfung nicht
		// durchlaufen — dann ist ein unbrauchbares Verzeichnis die richtige
		// Antwort, kein gefährliches.
		name = "_ungueltig"
	}
	return filepath.Join(wurzel, "sites", name)
}

// einsetzen legt das Zertifikat in den Halter — als Panelzertifikat oder unter
// seiner Kennung.
//
// Die Unterscheidung steht an einer Stelle. Zwei Aufrufer mit je eigener
// Fallunterscheidung wären die Gelegenheit, an der ein Site-Zertifikat
// versehentlich als Panelzertifikat landet — und das nähme dem Panel seine
// eigene Oberfläche.
func (m *Manager) einsetzen(cert tls.Certificate) error {
	if m.kennung == "" {
		m.holder.Set(cert)
		return nil
	}
	return m.holder.SetzeSite(m.kennung, cert)
}
