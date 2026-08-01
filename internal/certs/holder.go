package certs

import (
	"crypto/tls"
	"crypto/x509"
	"errors"
	"fmt"
	"sort"
	"strings"
	"sync"
)

// Holder hält die aktiven TLS-Zertifikate hinter einem Lock und erlaubt, sie zur
// Laufzeit auszutauschen.
//
// Das ist die Grundlage dafür, dass eine ACME-Erneuerung das Zertifikat wechseln
// kann, ohne den Prozess neu zu starten: tls.Config.GetCertificate fragt bei
// jedem Handshake den Halter, nicht eine beim Start eingefrorene Liste.
//
// # Warum der Halter seit 0.6 mehr als eines hält
//
// Bis 0.5 hielt er genau eines — das des Panels — und ignorierte den
// ClientHello. Mit dem Webservermodul kommen Zertifikate je Site dazu
// (docs/18-webserver.md §4), und dann muss die Auswahl über SNI laufen.
//
// # Die Regel, an der alles hängt
//
// **Das Panel verliert sein eigenes Zertifikat nie.** Deckt das Panelzertifikat
// den angefragten Namen ab, gewinnt es — vor jeder Site. Ohne diese Reihenfolge
// könnte eine Site auf den Namen des Panels angelegt werden und dessen TLS
// übernehmen; wer das versehentlich tut, sperrt sich aus der Oberfläche aus, mit
// der er es zurücknehmen müsste.
//
// Und ein unbekannter Name bekommt das Panelzertifikat statt eines
// Verbindungsabbruchs. Ein Browser zeigt dann eine Warnung, die man lesen und
// verstehen kann; ein Abbruch sieht aus wie ein toter Server.
type Holder struct {
	mu sync.RWMutex
	// panel ist das Zertifikat des Panels — und immer der Rückfall.
	panel *tls.Certificate
	// sites bildet eine Kennung (den Namen der Site) auf ihr Zertifikat ab.
	// Die Kennung ist NICHT der Domainname: Eine Site kann mehrere Namen
	// führen, und ein Wildcard bedient viele.
	sites map[string]*tls.Certificate
	// namen ist der abgeleitete Index über die SANs aller Site-Zertifikate,
	// einschließlich der Platzhalter in ihrer Rohform ("*.example.com").
	//
	// Abgeleitet und nicht gepflegt: Er wird bei jeder Änderung neu gebaut. Bei
	// einer Handvoll Sites ist das nichts, und es kann nicht auseinanderlaufen.
	namen map[string]*tls.Certificate
}

// NewHolder legt einen Halter mit dem Zertifikat des Panels an.
func NewHolder(cert tls.Certificate) *Holder {
	h := &Holder{sites: map[string]*tls.Certificate{}, namen: map[string]*tls.Certificate{}}
	h.Set(cert)
	return h
}

// Set tauscht das Zertifikat DES PANELS atomar aus. Jeder Handshake danach
// verwendet es.
func (h *Holder) Set(cert tls.Certificate) {
	c := cert
	// Das Blatt einlesen, falls der Aufrufer es nicht getan hat: Ohne geparstes
	// Blatt kennt der Halter die Namen des Zertifikats nicht und könnte die
	// Vorrangregel oben nicht anwenden. Ein Fehler dabei ist kein Grund, das
	// Zertifikat abzulehnen — es ausliefern kann der Halter trotzdem.
	blattEinlesen(&c)
	h.mu.Lock()
	h.panel = &c
	h.mu.Unlock()
}

// SetzeSite hinterlegt das Zertifikat einer Site unter ihrer Kennung.
//
// Ein vorhandenes unter derselben Kennung wird ersetzt — das ist der Fall der
// Erneuerung, und er soll keinen zweiten Eintrag hinterlassen.
//
// Der Fehler kommt, wenn das Zertifikat keine Namen trägt: Ein solches ließe
// sich nie auswählen, und es stillschweigend abzulegen hieße, einen Eintrag zu
// führen, der nie zum Zug kommt.
func (h *Holder) SetzeSite(kennung string, cert tls.Certificate) error {
	if kennung == "" {
		return errors.New("Zertifikat ohne Kennung")
	}
	c := cert
	blattEinlesen(&c)
	if c.Leaf == nil {
		return fmt.Errorf("Zertifikat für %q ließ sich nicht lesen", kennung)
	}
	if len(zertNamen(c.Leaf)) == 0 {
		return fmt.Errorf("Zertifikat für %q trägt keine Namen", kennung)
	}

	h.mu.Lock()
	defer h.mu.Unlock()
	h.sites[kennung] = &c
	h.indexNeu()
	return nil
}

// EntferneSite nimmt das Zertifikat einer Site aus dem Halter.
func (h *Holder) EntferneSite(kennung string) {
	h.mu.Lock()
	defer h.mu.Unlock()
	delete(h.sites, kennung)
	h.indexNeu()
}

// Kennungen nennt die hinterlegten Sites, sortiert. Auskunft für die Oberfläche.
func (h *Holder) Kennungen() []string {
	h.mu.RLock()
	defer h.mu.RUnlock()
	aus := make([]string, 0, len(h.sites))
	for k := range h.sites {
		aus = append(aus, k)
	}
	sort.Strings(aus)
	return aus
}

// GetCertificate passt auf die Signatur von tls.Config.GetCertificate.
//
// Die Reihenfolge ist die Sicherung dieses Typs und steht deshalb hier noch
// einmal:
//
//  1. Deckt das Panelzertifikat den Namen ab → Panelzertifikat.
//  2. Passt eine Site → deren Zertifikat.
//  3. Sonst → Panelzertifikat.
//
// Ohne Namen im ClientHello (ein Aufruf über die IP-Adresse) fällt man sofort
// auf 3.
func (h *Holder) GetCertificate(hello *tls.ClientHelloInfo) (*tls.Certificate, error) {
	h.mu.RLock()
	defer h.mu.RUnlock()

	if h.panel == nil && len(h.sites) == 0 {
		return nil, errors.New("kein TLS-Zertifikat im Halter")
	}

	name := ""
	if hello != nil {
		name = strings.ToLower(strings.TrimSuffix(hello.ServerName, "."))
	}
	if name != "" {
		// 1. Das Panel zuerst. Es soll seinen eigenen Namen nie an eine Site
		//    verlieren — siehe der Kopf dieses Typs.
		if h.panel != nil && h.panel.Leaf != nil && h.panel.Leaf.VerifyHostname(name) == nil {
			return h.panel, nil
		}
		// 2. Eine Site.
		if c := h.sucheSite(name); c != nil {
			return c, nil
		}
	}

	// 3. Der Rückfall.
	if h.panel != nil {
		return h.panel, nil
	}
	return nil, errors.New("kein TLS-Zertifikat für diesen Namen und keines für das Panel")
}

// sucheSite findet das Zertifikat zu einem Namen. Unter gehaltenem Lock.
//
// Erst der genaue Name, dann der Platzhalter der ELTERNEBENE — und nur dieser
// einen. `*.example.com` deckt `a.example.com` ab und `a.b.example.com` nicht;
// das ist keine Feinheit, sondern die Regel aus RFC 6125, und jeder Browser
// hält sich daran. Wer hier großzügiger sucht, liefert ein Zertifikat aus, dem
// die Gegenseite gleich darauf widerspricht.
func (h *Holder) sucheSite(name string) *tls.Certificate {
	if c := h.namen[name]; c != nil {
		return c
	}
	if i := strings.Index(name, "."); i >= 0 && i+1 < len(name) {
		if c := h.namen["*."+name[i+1:]]; c != nil {
			return c
		}
	}
	return nil
}

// indexNeu baut den Namensindex aus den Site-Zertifikaten. Unter gehaltenem Lock.
//
// Bei mehreren Zertifikaten für denselben Namen gewinnt das mit der
// alphabetisch ersten Kennung. Eine Regel muss es geben, und diese hat den
// Vorzug, vorhersagbar zu sein: Ohne sie hinge das Ergebnis an der Reihenfolge
// einer Map, also am Zufall, und ein Server, der bei jedem Neustart ein anderes
// Zertifikat ausliefert, ist nicht zu beurteilen.
func (h *Holder) indexNeu() {
	h.namen = map[string]*tls.Certificate{}

	kennungen := make([]string, 0, len(h.sites))
	for k := range h.sites {
		kennungen = append(kennungen, k)
	}
	sort.Strings(kennungen)

	for _, k := range kennungen {
		c := h.sites[k]
		if c == nil || c.Leaf == nil {
			continue
		}
		for _, n := range zertNamen(c.Leaf) {
			if _, schon := h.namen[n]; schon {
				continue
			}
			h.namen[n] = c
		}
	}
}

// zertNamen liefert die Namen eines Zertifikats in Kleinschreibung —
// einschließlich der Platzhalter in ihrer Rohform.
//
// Nur die SANs, nicht der CommonName: Er ist seit RFC 2818 abgekündigt, und
// kein heutiger Browser sieht ihn noch an. Ihn hier zu berücksichtigen hieße,
// ein Zertifikat auszuwählen, das die Gegenseite danach ablehnt.
func zertNamen(leaf *x509.Certificate) []string {
	aus := make([]string, 0, len(leaf.DNSNames))
	for _, n := range leaf.DNSNames {
		if n = strings.ToLower(strings.TrimSuffix(n, ".")); n != "" {
			aus = append(aus, n)
		}
	}
	return aus
}

// blattEinlesen setzt Leaf, falls es fehlt.
//
// tls.X509KeyPair lässt Leaf leer, und die meisten Aufrufer im Projekt gehen
// diesen Weg. Ohne geparstes Blatt kennt der Halter die Namen nicht — dann
// bliebe von der Auswahl nur der Rückfall übrig, und zwar stillschweigend.
func blattEinlesen(c *tls.Certificate) {
	if c.Leaf != nil || len(c.Certificate) == 0 {
		return
	}
	if leaf, err := x509.ParseCertificate(c.Certificate[0]); err == nil {
		c.Leaf = leaf
	}
}
