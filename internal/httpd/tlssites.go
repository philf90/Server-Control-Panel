package httpd

// TLS je Site (Schritt 7 der Stufe 0.6, docs/18-webserver.md).
//
// Der Satz aus docs/16 §2, um dessentwillen es diese Stufe gibt: „Der häufigste
// Handgriff nach dem Aufsetzen eines Dienstes ist ‚mach ihn unter einem Namen
// mit TLS erreichbar'." Hier wird er eingelöst.
//
// # Ein Manager je Site, ein Konto für alle
//
// Jede Site mit TLS bekommt einen eigenen acme.Manager: eigene Domains, eigene
// Kennung, eigenes Verzeichnis unter acme/sites/<kennung>, eigene
// Erneuerungsschleife. Was sie sich TEILEN, ist der Kontoschlüssel — ein eigener
// je Site wäre ein eigenes ACME-Konto, und zwanzig Sites wären zwanzig Konten
// bei Let's Encrypt. Dieselbe Entscheidung wie in Schritt 3.
//
// Geteilt sind auch die Einstellungen: E-Mail, Verzeichnis-URL, Prüfmethode und
// der DNS-Anbieter kommen aus der TLS-Konfiguration des PANELS. Das ist keine
// Sparsamkeit, sondern die einzige ehrliche Antwort auf die Frage, woher eine
// Site ihr ACME-Konto nehmen soll — es gibt genau eines.
//
// # Ohne ACME fürs Panel kein ACME für Sites
//
// Steht das Panel auf „selbstsigniert", gibt es kein Konto, keine
// Prüfmethode und keine E-Mail. Dann entsteht auch für eine Site kein
// Zertifikat, und die Fläche sagt das — statt einen Knopf anzubieten, der
// zuverlässig scheitert.
//
// # Der Abgleich läuft auf Änderungen, nicht auf einem Takt
//
// Welche Sites TLS wollen, steht in ihren Dateien. Der Abgleich läuft deshalb
// nach jeder Änderung an einer Site und beim Start — nicht in einer Schleife.
// Die ERNEUERUNG dagegen läuft in jedem Manager für sich weiter; sie ist der
// Grund, warum die Manager laufen bleiben und nicht je Bezug entstehen.

import (
	"context"
	"errors"
	"path/filepath"
	"sort"
	"sync"
	"time"

	"github.com/philf90/asylum/internal/acme"
	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/privops"
)

// siteZertLauf ist ein laufender Manager samt seinem Abschalter.
type siteZertLauf struct {
	cancel  context.CancelFunc
	done    chan struct{}
	mgr     *acme.Manager
	domains []string
}

// siteZertStand ist das, was die Oberfläche über einen Bezug wissen muss und
// nicht aus dem Zertifikat lesen kann.
type siteZertStand struct {
	// Versuch ist der Zeitpunkt des letzten Bezugsversuchs, Fehler seine
	// Meldung. Beides gehört zusammen: „kein Zertifikat" ohne den Grund ist die
	// Auskunft, mit der niemand etwas anfangen kann.
	Versuch time.Time
	Fehler  string
	Laeuft  bool
}

// siteZerts hält die Manager der Sites.
type siteZerts struct {
	mu      sync.Mutex
	laeufe  map[string]*siteZertLauf
	staende map[string]siteZertStand
	baseCtx context.Context //nolint:containedctx // Lebensdauer des Dienstes, wie bei tlsControl
}

func neueSiteZerts() *siteZerts {
	return &siteZerts{
		laeufe:  map[string]*siteZertLauf{},
		staende: map[string]siteZertStand{},
	}
}

// stand liest den Stand einer Site.
func (z *siteZerts) stand(kennung string) siteZertStand {
	z.mu.Lock()
	defer z.mu.Unlock()
	return z.staende[kennung]
}

// setzeStand schreibt ihn.
func (z *siteZerts) setzeStand(kennung string, st siteZertStand) {
	z.mu.Lock()
	defer z.mu.Unlock()
	z.staende[kennung] = st
}

// laufend nennt die Kennungen mit laufendem Manager, sortiert.
func (z *siteZerts) laufend() []string {
	z.mu.Lock()
	defer z.mu.Unlock()
	aus := make([]string, 0, len(z.laeufe))
	for k := range z.laeufe {
		aus = append(aus, k)
	}
	sort.Strings(aus)
	return aus
}

// siteZertsAbgleichen bringt die laufenden Manager mit den Sites in Einklang.
//
// Fehlerfrei im Sinne von „bricht nicht ab": Was sich nicht bauen lässt, landet
// als Meldung im Stand der betroffenen Site. Ein Abgleich, der beim ersten
// Fehler stehen bleibt, ließe die übrigen Sites ohne Zertifikat — und der Grund
// stünde nirgends.
func (s *Server) siteZertsAbgleichen(ctx context.Context) {
	s.siteZerts.mu.Lock()
	base := s.siteZerts.baseCtx
	s.siteZerts.mu.Unlock()
	if base == nil || base.Err() != nil {
		return // vor Run oder nach dem Herunterfahren
	}

	set := s.tlsSettings()
	gewollt := map[string][]string{}

	// Ohne ACME fürs Panel gibt es kein Konto — dann wird gar nicht erst
	// gesammelt, und der Abgleich hält unten alles an.
	if set.Mode == config.TLSModeACME {
		bestand, err := s.ops.SiteList(ctx)
		if err == nil && bestand.Gelesen {
			for _, si := range bestand.Sites {
				// Nur verwaltete, nur mit TLS-Wunsch, nur eingeschaltete: Für
				// eine abgeschaltete Site ein Zertifikat zu erneuern hieße, eine
				// Prüfung anzustoßen, die niemand beantwortet.
				if !si.Verwaltet || !si.TLS || si.Aus {
					continue
				}
				namen := acmefaehig(si.Domains)
				if len(namen) == 0 {
					s.siteZerts.setzeStand(si.Name, siteZertStand{
						Versuch: time.Now(),
						Fehler: "Diese Site hat keinen Domainnamen, für den sich ein " +
							"Zertifikat beziehen ließe.",
					})
					continue
				}
				gewollt[si.Name] = namen
			}
		} else if err != nil {
			s.log.Warn("TLS je Site: Sites nicht lesbar", "err", err)
		}
	}

	s.siteZerts.mu.Lock()
	vorhanden := make(map[string]*siteZertLauf, len(s.siteZerts.laeufe))
	for k, v := range s.siteZerts.laeufe {
		vorhanden[k] = v
	}
	s.siteZerts.mu.Unlock()

	// Erst anhalten, was wegfällt oder sich geändert hat. Zwei Manager auf
	// demselben Verzeichnis schrieben sich gegenseitig das Zertifikat um —
	// derselbe Grund, aus dem restartACME zuerst beendet und dann startet.
	for kennung, lauf := range vorhanden {
		namen, will := gewollt[kennung]
		if will && gleicheNamen(lauf.domains, namen) {
			delete(gewollt, kennung) // läuft schon richtig
			continue
		}
		s.siteZertAnhalten(kennung)
	}

	for kennung, namen := range gewollt {
		s.siteZertStarten(base, kennung, namen, set)
	}
}

// siteZertStarten baut einen Manager für eine Site und lässt ihn laufen.
func (s *Server) siteZertStarten(base context.Context, kennung string, domains []string, set config.TLSSettings) {
	mgr, err := s.newSiteACMEManager(base, kennung, domains, set)
	if err != nil {
		s.log.Warn("TLS je Site: Manager nicht gebaut", "site", kennung, "err", err)
		s.siteZerts.setzeStand(kennung, siteZertStand{Versuch: time.Now(), Fehler: err.Error()})
		return
	}

	ctx, cancel := context.WithCancel(base)
	fertig := make(chan struct{})
	s.siteZerts.mu.Lock()
	s.siteZerts.laeufe[kennung] = &siteZertLauf{
		cancel: cancel, done: fertig, mgr: mgr, domains: domains,
	}
	s.siteZerts.mu.Unlock()

	go func() {
		defer close(fertig)
		mgr.Start(ctx)
	}()
	s.log.Info("TLS je Site aktiv", "site", kennung, "domains", domains)
}

// siteZertAnhalten beendet den Manager einer Site und wartet auf sein Ende.
func (s *Server) siteZertAnhalten(kennung string) {
	s.siteZerts.mu.Lock()
	lauf := s.siteZerts.laeufe[kennung]
	delete(s.siteZerts.laeufe, kennung)
	s.siteZerts.mu.Unlock()

	if lauf == nil {
		return
	}
	lauf.cancel()
	<-lauf.done
}

// siteZertsAnhalten beendet alle. Beim Herunterfahren des Dienstes.
func (s *Server) siteZertsAnhalten() {
	for _, kennung := range s.siteZerts.laufend() {
		s.siteZertAnhalten(kennung)
	}
}

// newSiteACMEManager baut den Manager einer Site.
//
// Die Einstellungen kommen vom Panel, die Kennung und die Domains von der Site.
// Das Fortschrittsprotokoll bekommt die Site NICHT: certProgress schreibt in die
// Vorgangsplatte der Zertifikatsseite, und ein Bezug im Hintergrund, der dort
// erscheint, sähe aus wie einer, den jemand gerade ausgelöst hat.
func (s *Server) newSiteACMEManager(ctx context.Context, kennung string, domains []string, set config.TLSSettings) (*acme.Manager, error) {
	if set.Mode != config.TLSModeACME {
		return nil, errors.New("für das Panel selbst ist der automatische Bezug " +
			"abgeschaltet — ohne ihn gibt es kein ACME-Konto, aus dem eine Site " +
			"ihr Zertifikat beziehen könnte")
	}
	return acme.New(acme.Options{
		Dir:     filepath.Join(s.cfg.Paths.Data, "acme"),
		Kennung: kennung,
		Email:   set.ACME.Email,
		Domains: domains,

		DirectoryURL: set.ACME.DirectoryURL,
		Challenge:    set.ACME.Challenge,
		// KEIN HTTP01Addr: Eine Site kann Port 80 nicht selbst binden — dort
		// hört nginx. Für sie gibt es nur den Weg durch nginx hindurch oder
		// DNS-01. Bleibt der Webroot leer und ist DNS-01 nicht eingerichtet,
		// scheitert der Bezug mit genau dieser Meldung, und das ist die
		// richtige: Ein eigener Listener neben nginx nähme dem Webserver den
		// Port weg.
		Webroot:       s.acmeWebroot(ctx, domains),
		DNS01Provider: set.ACME.DNS01.Provider,
		HookSet:       set.ACME.DNS01.Hook.Set,
		HookClean:     set.ACME.DNS01.Hook.Clean,
		ZugangsDatei:  set.ACME.DNS01.ZugangsDatei(),
		Progress:      siteFortschritt{s: s, kennung: kennung},
	}, s.certHolder, s.log)
}

// siteFortschritt schreibt den Ausgang eines Bezugs in den Stand der Site.
//
// Eigene Bauart neben certProgress: Der Bezug einer Site läuft im Hintergrund
// und gehört an die Site, nicht in die Vorgangsplatte der Zertifikatsseite.
type siteFortschritt struct {
	s       *Server
	kennung string
}

func (p siteFortschritt) Begin([]string) {
	st := p.s.siteZerts.stand(p.kennung)
	st.Laeuft = true
	p.s.siteZerts.setzeStand(p.kennung, st)
}

func (p siteFortschritt) Step(string) {}

func (p siteFortschritt) End(err error) {
	st := siteZertStand{Versuch: time.Now()}
	if err != nil {
		st.Fehler = err.Error()
		p.s.log.Warn("TLS je Site: Bezug gescheitert", "site", p.kennung, "err", err)
	} else {
		p.s.log.Info("TLS je Site: Zertifikat bezogen", "site", p.kennung)
	}
	p.s.siteZerts.setzeStand(p.kennung, st)

	// Nach dem ERSTEN erfolgreichen Bezug muss die Site neu geschrieben werden:
	// Sie steht bis dahin ohne 443-Block da, weil es kein Zertifikat gab. Das
	// erledigt der Handler, der den Bezug angestoßen hat — hier steht nur der
	// Hinweis, damit niemand ihn im Hintergrund erwartet.
}

// acmefaehig lässt die Namen übrig, für die sich ein Zertifikat beziehen lässt.
//
// Der Platzhalter fällt heraus, wenn er nicht über DNS-01 geprüft werden kann —
// das entscheidet aber der Manager, nicht diese Funktion. Was hier fällt, sind
// nur die Namen, die nginx kennt und ACME nicht: der Vorgabeblock ohne
// server_name und der reine Platzhalter.
func acmefaehig(domains []string) []string {
	aus := make([]string, 0, len(domains))
	for _, d := range domains {
		if d == "" || d == "_" || d == "*" {
			continue
		}
		aus = append(aus, d)
	}
	return aus
}

// gleicheNamen vergleicht zwei Namenslisten ohne Rücksicht auf die Reihenfolge.
func gleicheNamen(a, b []string) bool {
	if len(a) != len(b) {
		return false
	}
	x := append([]string{}, a...)
	y := append([]string{}, b...)
	sort.Strings(x)
	sort.Strings(y)
	for i := range x {
		if x[i] != y[i] {
			return false
		}
	}
	return true
}

// siteZertJetzt stößt den Bezug für eine Site sofort an.
//
// Er läuft im Vordergrund der Anfrage und nicht als Vorgang: Ein Bezug dauert
// bei HTTP-01 Sekunden und bei DNS-01 bis zu ein paar Minuten. Für den langen
// Fall gibt es den Hintergrundmanager, der es ohnehin selbst versucht — dieser
// Weg ist der für „jetzt, und sag mir das Ergebnis".
func (s *Server) siteZertJetzt(ctx context.Context, kennung string) error {
	s.siteZerts.mu.Lock()
	lauf := s.siteZerts.laeufe[kennung]
	s.siteZerts.mu.Unlock()

	if lauf == nil {
		// Kein Manager heißt: Diese Site will kein TLS, ist abgeschaltet, hat
		// keinen brauchbaren Namen — oder das Panel steht auf selbstsigniert.
		// Der Stand nennt den Grund, wenn es einen gibt.
		if st := s.siteZerts.stand(kennung); st.Fehler != "" {
			return errors.New(st.Fehler)
		}
		return errors.New("für diese Site ist kein Zertifikatsbezug eingerichtet: " +
			"Sie muss verwaltet, eingeschaltet und auf TLS gestellt sein, und für " +
			"das Panel selbst muss der automatische Bezug laufen")
	}
	return lauf.mgr.ObtainNow(ctx)
}

// siteZertNamen nennt die Domains, für die diese Site ein Zertifikat bezieht.
func (s *Server) siteZertNamen(kennung string) []string {
	s.siteZerts.mu.Lock()
	defer s.siteZerts.mu.Unlock()
	if lauf := s.siteZerts.laeufe[kennung]; lauf != nil {
		return lauf.domains
	}
	return nil
}

// verwalteteSitesMitTLS ist eine kleine Hilfe für die Auskunftsfläche.
func verwalteteSitesMitTLS(sites []privops.Site) []privops.Site {
	var aus []privops.Site
	for _, si := range sites {
		if si.Verwaltet && si.TLS {
			aus = append(aus, si)
		}
	}
	return aus
}
