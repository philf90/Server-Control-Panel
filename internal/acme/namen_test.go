package acme

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"log/slog"
	"math/big"
	"strings"
	"testing"
	"time"
)

func TestPruefeZertifikatsnameNimmtPlatzhalter(t *testing.T) {
	for _, name := range []string{
		"example.com",
		"panel.example.com",
		"*.example.com",
		"*.sub.example.com",
		"xn--bcher-kva.example.com", // Punycode ist gewöhnlicher Text
		"a-b.example.com",
		"1.2.3.example.com",
	} {
		if err := PruefeZertifikatsname(name); err != nil {
			t.Errorf("%q sollte zulässig sein: %v", name, err)
		}
	}
}

// Ein Platzhalter ist nur als ERSTE EBENE und nur als ganzes Label erlaubt.
// Alles andere lehnt Let's Encrypt ab — und ein abgelehnter Antrag zählt gegen
// die Ratengrenze. Hier fällt er im Formular auf statt beim CA-Server.
func TestPruefeZertifikatsnameLehntAb(t *testing.T) {
	for _, fall := range []struct{ name, warum string }{
		{"", "leer"},
		{"*.de", "Platzhalter auf einer ganzen Endung"},
		{"*.", "kein Name hinter dem Platzhalter"},
		{"*", "nur der Platzhalter"},
		{"*.*.example.com", "zwei Platzhalter"},
		{"www*.example.com", "Platzhalter als Teil eines Labels"},
		{"example.*.com", "Platzhalter in der Mitte"},
		{"beispiel..de", "leerer Bestandteil"},
		{"-beispiel.de", "Bindestrich am Anfang"},
		{"beispiel-.de", "Bindestrich am Ende"},
		{"beispiel.de; root /", "nginx-Anweisung"},
		{"beispiel de", "Leerzeichen"},
		{"beispiel_de.com", "Unterstrich"},
		{strings.Repeat("a", 64) + ".de", "Bestandteil zu lang"},
	} {
		if err := PruefeZertifikatsname(fall.name); err == nil {
			t.Errorf("%q (%s) wurde angenommen", fall.name, fall.warum)
		}
	}
}

func TestPruefeZertifikatsnamenVereinheitlicht(t *testing.T) {
	aus, err := PruefeZertifikatsnamen([]string{
		"  Panel.Example.COM  ", "*.EXAMPLE.com", "panel.example.com", "example.com.",
	})
	if err != nil {
		t.Fatalf("PruefeZertifikatsnamen: %v", err)
	}
	// Kleingeschrieben, ohne Rand, ohne den abschließenden Punkt — und ohne
	// Doppelte: Let's Encrypt zählt Namen, und ein doppelter zählt mit.
	will := []string{"panel.example.com", "*.example.com", "example.com"}
	if len(aus) != len(will) {
		t.Fatalf("%d Namen, erwartet %d: %v", len(aus), len(will), aus)
	}
	for i := range will {
		if aus[i] != will[i] {
			t.Errorf("Name %d = %q, erwartet %q", i, aus[i], will[i])
		}
	}
}

// Die Eigenschaft, die am häufigsten überrascht — und die deshalb geprüft und
// nicht angenommen gehört: Ein Platzhalter deckt die nackte Domain NICHT ab,
// und er deckt auch keine zweite Ebene ab.
func TestPlatzhalterDecktGenauEineEbene(t *testing.T) {
	cert := selbstgebaut(t, "*.example.com", "example.com")

	for _, fall := range []struct {
		name string
		will bool
	}{
		{"a.example.com", true},
		{"example.com", true},
		{"*.example.com", true}, // exakter Vergleich — das trägt covers()
		{"a.b.example.com", false},
		{"example.org", false},
	} {
		err := cert.Leaf.VerifyHostname(fall.name)
		if (err == nil) != fall.will {
			t.Errorf("VerifyHostname(%q) = %v, erwartet Treffer=%v", fall.name, err, fall.will)
		}
	}

	// Und genau darauf beruht covers(): Es fragt mit den ANGEFORDERTEN Namen,
	// und einer davon ist der Platzhalter selbst.
	if !covers(cert, []string{"*.example.com", "example.com"}) {
		t.Error("covers() erkennt das eigene Wildcard-Zertifikat nicht wieder — " +
			"dann würde bei jedem Lauf ein neues beantragt")
	}
	if covers(cert, []string{"*.example.com", "example.com", "a.b.example.com"}) {
		t.Error("covers() hält eine zweite Ebene für gedeckt")
	}
}

func TestFehlendeBasis(t *testing.T) {
	fehlt := FehlendeBasis([]string{"*.example.com"})
	if len(fehlt) != 1 || fehlt[0] != "example.com" {
		t.Errorf("erwartet example.com als fehlend, bekam %v", fehlt)
	}
	if fehlt := FehlendeBasis([]string{"*.example.com", "example.com"}); len(fehlt) != 0 {
		t.Errorf("steht die Basis dabei, fehlt nichts: %v", fehlt)
	}
	if fehlt := FehlendeBasis([]string{"panel.example.com"}); len(fehlt) != 0 {
		t.Errorf("ohne Platzhalter fehlt nichts: %v", fehlt)
	}
}

// Die dritte Regel in solverFactory: Ein Platzhalter erzwingt DNS-01, auch
// gegen eine ausdrückliche Einstellung. Ohne sie liefe der Bezug bis zum
// CA-Server und scheiterte dort an einer Meldung, die nicht sagt, was zu tun
// ist — und der Fehlversuch zählt mit.
func TestSolverFactoryErzwingtDNS01BeiPlatzhalter(t *testing.T) {
	log := slog.New(slog.DiscardHandler)

	factory, err := solverFactory(Options{
		Domains:       []string{"*.example.com", "example.com"},
		Challenge:     "http-01", // ausdrücklich — und trotzdem nicht erfüllbar
		HTTP01Addr:    "127.0.0.1:0",
		DNS01Provider: "hook",
		HookSet:       "/usr/local/bin/setze",
		HookClean:     "/usr/local/bin/raeume",
	}, log, reporter{})
	if err != nil {
		t.Fatalf("solverFactory: %v", err)
	}
	solver, err := factory(t.Context())
	if err != nil {
		t.Fatalf("Löser: %v", err)
	}
	if solver.challengeType() != "dns-01" {
		t.Errorf("Challenge-Typ = %q — ein Platzhalter verlässt sich nicht auf http-01",
			solver.challengeType())
	}
}

// Und ohne DNS-Anbieter ist das ein Fehler, kein Versuch.
func TestSolverFactoryLehntPlatzhalterOhneAnbieterAb(t *testing.T) {
	_, err := solverFactory(Options{
		Domains:    []string{"*.example.com"},
		HTTP01Addr: "127.0.0.1:0",
	}, slog.New(slog.DiscardHandler), reporter{})
	if err == nil {
		t.Fatal("ohne DNS-Anbieter lässt sich ein Platzhalter nicht beziehen")
	}
	// Die Meldung nennt den Namen, der die Regel auslöst — „ein Name verlangt
	// DNS-01" ohne den Namen befähigt zu keiner Entscheidung.
	if !strings.Contains(err.Error(), "*.example.com") {
		t.Errorf("die Meldung nennt den Namen nicht: %v", err)
	}
}

// Ohne Platzhalter bleibt alles, wie es war.
func TestSolverFactoryOhnePlatzhalterUnveraendert(t *testing.T) {
	factory, err := solverFactory(Options{
		Domains:    []string{"panel.example.com"},
		Challenge:  "http-01",
		HTTP01Addr: "127.0.0.1:0",
	}, slog.New(slog.DiscardHandler), reporter{})
	if err != nil {
		t.Fatalf("solverFactory: %v", err)
	}
	solver, err := factory(t.Context())
	if err != nil {
		t.Fatalf("Löser: %v", err)
	}
	defer func() {
		if c, ok := solver.(interface{ Close() error }); ok {
			_ = c.Close()
		}
	}()
	if solver.challengeType() != "http-01" {
		t.Errorf("Challenge-Typ = %q", solver.challengeType())
	}
}

// selbstgebaut liefert ein Zertifikat mit den gewünschten Namen — gebaut und
// wieder eingelesen, damit Leaf gesetzt ist wie bei einem echten.
func selbstgebaut(t *testing.T, namen ...string) tls.Certificate {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	tpl := &x509.Certificate{
		SerialNumber: big.NewInt(1),
		Subject:      pkix.Name{CommonName: namen[0]},
		NotBefore:    time.Now().Add(-time.Hour),
		NotAfter:     time.Now().Add(24 * time.Hour),
		DNSNames:     namen,
	}
	der, err := x509.CreateCertificate(rand.Reader, tpl, tpl, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	leaf, err := x509.ParseCertificate(der)
	if err != nil {
		t.Fatal(err)
	}
	return tls.Certificate{Certificate: [][]byte{der}, PrivateKey: key, Leaf: leaf}
}
