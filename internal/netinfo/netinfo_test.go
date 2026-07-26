package netinfo

import (
	"context"
	"errors"
	"testing"
)

// stubResolver antwortet aus einer Tabelle statt aus dem Netz.
type stubResolver struct {
	hosts map[string][]string // Name → Adressen
	ptr   map[string][]string // Adresse → Namen
	err   error
}

func (s stubResolver) LookupHost(_ context.Context, host string) ([]string, error) {
	if s.err != nil {
		return nil, s.err
	}
	addrs, ok := s.hosts[host]
	if !ok {
		return nil, errors.New("kein Eintrag")
	}
	return addrs, nil
}

func (s stubResolver) LookupAddr(_ context.Context, addr string) ([]string, error) {
	names, ok := s.ptr[addr]
	if !ok {
		return nil, errors.New("kein PTR")
	}
	return names, nil
}

// withStubs setzt Hostname und Resolver für die Dauer eines Tests.
func withStubs(t *testing.T, name string, nameErr error, r stubResolver) {
	t.Helper()
	altHostname, altResolver := hostname, resolver
	t.Cleanup(func() { hostname, resolver = altHostname, altResolver })
	hostname = func() (string, error) { return name, nameErr }
	resolver = r
}

// TestFQDNErgaenztDieDomainendung ist der Fall aus der Praxis: Der Kernel kennt
// nur "cloudsrv24", in /etc/hosts steht "203.0.113.7 cloudsrv24.de cloudsrv24".
// Der ausgegebene Setup-Link nannte bis hierher den kurzen Namen und war damit
// von jedem anderen Rechner aus unbrauchbar.
func TestFQDNErgaenztDieDomainendung(t *testing.T) {
	withStubs(t, "cloudsrv24", nil, stubResolver{
		hosts: map[string][]string{"cloudsrv24": {"203.0.113.7"}},
		ptr:   map[string][]string{"203.0.113.7": {"cloudsrv24.de."}},
	})
	if got := FQDN(); got != "cloudsrv24.de" {
		t.Errorf("FQDN() = %q, erwartet %q", got, "cloudsrv24.de")
	}
}

func TestFQDNLaesstVollqualifiziertesInRuhe(t *testing.T) {
	// Kein Resolver hinterlegt: Wird er befragt, fällt das als Fehler auf.
	withStubs(t, "panel.example.org", nil, stubResolver{err: errors.New("darf nicht gefragt werden")})
	if got := FQDN(); got != "panel.example.org" {
		t.Errorf("FQDN() = %q", got)
	}
}

func TestFQDNTrenntDenAbschliessendenPunktAb(t *testing.T) {
	withStubs(t, "panel.example.org.", nil, stubResolver{})
	if got := FQDN(); got != "panel.example.org" {
		t.Errorf("FQDN() = %q, der Punkt am Ende gehört nicht in eine URL", got)
	}
}

// TestFQDNIgnoriertFremdePTREintraege: Viele Anbieter setzen einen generischen
// PTR auf die IP. Der löst zwar auf, ist aber weder der Name des Servers noch
// im Zertifikat enthalten — im Link wäre er eine falsche Fährte.
func TestFQDNIgnoriertFremdePTREintraege(t *testing.T) {
	withStubs(t, "cloudsrv24", nil, stubResolver{
		hosts: map[string][]string{"cloudsrv24": {"203.0.113.7"}},
		ptr: map[string][]string{
			"203.0.113.7": {"static-203-0-113-7.hoster.example.net."},
		},
	})
	if got := FQDN(); got != "cloudsrv24" {
		t.Errorf("FQDN() = %q, erwartet den kurzen Namen statt des Anbieternamens", got)
	}
}

// TestFQDNNimmtDenPassendenEintrag: Zeigt der PTR mehrere Namen, zählt der, der
// den Rechnernamen verlängert.
func TestFQDNNimmtDenPassendenEintrag(t *testing.T) {
	withStubs(t, "cloudsrv24", nil, stubResolver{
		hosts: map[string][]string{"cloudsrv24": {"198.51.100.9", "203.0.113.7"}},
		ptr: map[string][]string{
			"198.51.100.9": {"mail.example.net."},
			"203.0.113.7":  {"vpn.example.net.", "CloudSrv24.de."},
		},
	})
	// Groß-/Kleinschreibung ist in DNS ohne Bedeutung.
	if got := FQDN(); got != "CloudSrv24.de" {
		t.Errorf("FQDN() = %q", got)
	}
}

// TestFQDNFaelltAufDenKurzenNamenZurueck: Ohne erreichbaren Resolver darf die
// Ersteinrichtung nicht scheitern. Ein kurzer Name ist schlechter als ein
// vollständiger, aber besser als eine Fehlermeldung.
func TestFQDNFaelltAufDenKurzenNamenZurueck(t *testing.T) {
	withStubs(t, "cloudsrv24", nil, stubResolver{err: errors.New("Netz weg")})
	if got := FQDN(); got != "cloudsrv24" {
		t.Errorf("FQDN() = %q, erwartet %q", got, "cloudsrv24")
	}
}

func TestFQDNOhneHostnamen(t *testing.T) {
	withStubs(t, "", errors.New("kaputt"), stubResolver{})
	if got := FQDN(); got != "" {
		t.Errorf("FQDN() = %q, erwartet einen leeren String", got)
	}
}

func TestExtendsHostname(t *testing.T) {
	faelle := []struct {
		short, name string
		want        string
		ok          bool
	}{
		{"cloudsrv24", "cloudsrv24.de.", "cloudsrv24.de", true},
		{"cloudsrv24", "CLOUDSRV24.de", "CLOUDSRV24.de", true},
		{"cloudsrv24", "cloudsrv24", "", false},        // ohne Punkt kein Gewinn
		{"cloudsrv24", "cloudsrv24x.de", "", false},    // nur ein Präfix
		{"cloudsrv24", "a.cloudsrv24.de", "", false},   // anderer Rechner
		{"cloudsrv24", "static.hoster.net", "", false}, // fremder PTR
		{"cloudsrv24", "", "", false},                  // leere Antwort
		{"cloudsrv24", ".", "", false},                 // Wurzel
	}
	for _, f := range faelle {
		got, ok := extendsHostname(f.short, f.name)
		if got != f.want || ok != f.ok {
			t.Errorf("extendsHostname(%q, %q) = %q, %v — erwartet %q, %v",
				f.short, f.name, got, ok, f.want, f.ok)
		}
	}
}

// TestAddressesOhneLoopback prüft gegen die echten Schnittstellen des
// Testrechners. Welche Adressen das sind, steht nicht fest — dass Loopback
// nicht dabei ist, schon.
func TestAddressesOhneLoopback(t *testing.T) {
	for _, addr := range Addresses() {
		switch addr {
		case "127.0.0.1", "::1":
			t.Errorf("%s ist eine Loopback-Adresse und taugt nicht als Ausweichadresse", addr)
		}
	}
}
