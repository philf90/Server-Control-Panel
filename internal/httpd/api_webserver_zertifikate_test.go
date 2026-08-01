package httpd

import (
	"encoding/json"
	"net/http"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// Tests der Zertifikatsfläche je Site. Der Schwerpunkt liegt auf der dritten
// Frage, wegen der es die Fläche gibt: Wenn kein Zertifikat da ist — WARUM
// nicht? „Kein Zertifikat" ohne den Grund ist die Auskunft, mit der niemand
// etwas anfangen kann.

func zertServer(t *testing.T, modus string, sites []privops.Site) (*Server, *fakeOps, *http.Cookie, string) {
	t.Helper()
	s, ops := newSystemServer(t)
	ops.sites = privops.SiteBestand{Gelesen: true, Sites: sites}
	set := s.tlsSettings()
	set.Mode = modus
	s.tls.settings = set
	user := addUser(t, s, "chef", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	return s, ops, cookie, csrf
}

func zertLesen(t *testing.T, s *Server, cookie *http.Cookie) apiSiteZerts {
	t.Helper()
	rec := get(t, s, "/api/v1/webserver/zertifikate", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var a apiSiteZerts
	if err := json.Unmarshal(rec.Body.Bytes(), &a); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	return a
}

// Gelistet wird, was TLS will — nicht jede Site. Eine Zeile „kein Zertifikat"
// an einer Site, die nie eines wollte, wäre eine erfundene Baustelle.
func TestAPISiteZertsListetNurTLSSites(t *testing.T) {
	s, _, cookie, _ := zertServer(t, config.TLSModeACME, []privops.Site{
		{Name: "shop", Verwaltet: true, TLS: true, Domains: []string{"shop.example.com"}},
		{Name: "ohne", Verwaltet: true},
		{Name: "fremd", TLS: true, Domains: []string{"fremd.example.com"}},
	})

	a := zertLesen(t, s, cookie)
	if len(a.Zertifikate) != 1 || a.Zertifikate[0].Site != "shop" {
		t.Fatalf("erwartet genau die TLS-Site: %+v", a.Zertifikate)
	}
}

// Ohne ACME fürs Panel gibt es kein Konto — und damit für keine Site ein
// Zertifikat. Die Fläche sagt das und wo es umzustellen ist, statt einen Knopf
// anzubieten, der zuverlässig scheitert.
func TestAPISiteZertsOhneACMEErklaertWarum(t *testing.T) {
	s, _, cookie, _ := zertServer(t, config.TLSModeSelfSigned, []privops.Site{
		{Name: "shop", Verwaltet: true, TLS: true, Domains: []string{"shop.example.com"}},
	})

	a := zertLesen(t, s, cookie)
	if a.ACMEAktiv {
		t.Error("bei selbstsigniertem Panel gilt ACME als aktiv")
	}
	if !strings.Contains(a.Anmerkung, "Konto") {
		t.Errorf("die Anmerkung nennt den Grund nicht: %q", a.Anmerkung)
	}
	if len(a.Zertifikate) != 1 {
		t.Fatalf("die Site fehlt trotzdem: %+v", a.Zertifikate)
	}
	if a.Zertifikate[0].Stufe != "info" {
		t.Errorf("Stufe = %q — eine abgeschaltete Einstellung ist kein Fehler der Site",
			a.Zertifikate[0].Stufe)
	}
	if !strings.Contains(a.Zertifikate[0].Satz, "abgeschaltet") {
		t.Errorf("der Satz nennt den Grund nicht: %q", a.Zertifikate[0].Satz)
	}
}

// Der wichtigste Fall: kein Zertifikat UND ein Grund. Ohne ihn sucht jemand an
// vier verschiedenen Stellen.
func TestAPISiteZertsNenntDenGrundDesScheiterns(t *testing.T) {
	s, _, cookie, _ := zertServer(t, config.TLSModeACME, []privops.Site{
		{Name: "shop", Verwaltet: true, TLS: true, Domains: []string{"shop.example.com"}},
	})
	s.siteZerts.setzeStand("shop", siteZertStand{
		Fehler: "DNS-01: die Zone antwortet nicht",
	})

	a := zertLesen(t, s, cookie)
	if len(a.Zertifikate) != 1 {
		t.Fatalf("%d Zeilen", len(a.Zertifikate))
	}
	z := a.Zertifikate[0]
	if z.Stufe != "schlecht" {
		t.Errorf("Stufe = %q, erwartet schlecht", z.Stufe)
	}
	if !strings.Contains(z.Satz, "Zone antwortet nicht") {
		t.Errorf("die Meldung des Bezugs fehlt im Satz: %q", z.Satz)
	}
	// Und die Anmerkung sagt, welche zwei Wege es überhaupt gibt.
	if !strings.Contains(a.Anmerkung, "DNS-01") {
		t.Errorf("die Anmerkung nennt die Prüfwege nicht: %q", a.Anmerkung)
	}
}

// Ohne Fehler und ohne Zertifikat ist die Lage eine andere: Der erste Bezug
// läuft noch. Das ist eine Warnung und kein Fehler.
func TestAPISiteZertsFrischAngelegtIstKeinFehler(t *testing.T) {
	s, _, cookie, _ := zertServer(t, config.TLSModeACME, []privops.Site{
		{Name: "neu", Verwaltet: true, TLS: true, Domains: []string{"neu.example.com"}},
	})

	z := zertLesen(t, s, cookie).Zertifikate[0]
	if z.Stufe != "warn" {
		t.Errorf("Stufe = %q, erwartet warn — eine frisch angelegte Site ist kein Fehler", z.Stufe)
	}
	if z.Vorhanden {
		t.Error("ohne Halter gilt ein Zertifikat als vorhanden")
	}
}

// Der Bezug auf Knopfdruck läuft nur, wenn er etwas ausrichten kann — und sagt
// sonst, was fehlt, statt stillschweigend nichts zu tun.
func TestAPISiteZertBeziehenOhneManagerErklaertSich(t *testing.T) {
	s, _, cookie, csrf := zertServer(t, config.TLSModeACME, []privops.Site{
		{Name: "shop", Verwaltet: true, TLS: true, Domains: []string{"shop.example.com"}},
	})

	rec := postJSON(t, s, "/api/v1/webserver/sites/shop/zertifikat", `{}`, cookie, csrf)
	if rec.Code != http.StatusBadGateway {
		t.Fatalf("Status = %d, erwartet 502: %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "eingeschaltet") {
		t.Errorf("die Meldung nennt die Voraussetzungen nicht: %s", rec.Body.String())
	}
}

// Beziehen ist der Owner-Rolle vorbehalten: Es spricht mit einer Prüfstelle und
// legt eine Datei ab.
func TestAPISiteZertBeziehenVerlangtOwner(t *testing.T) {
	s, ops := newSystemServer(t)
	ops.sites = privops.SiteBestand{Gelesen: true}
	cookie, csrf := login(t, s, addUser(t, s, "gehilfe", store.RoleAdmin))

	rec := postJSON(t, s, "/api/v1/webserver/sites/shop/zertifikat", `{}`, cookie, csrf)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Status = %d, erwartet 403", rec.Code)
	}
}

// Lesen darf jede Rolle — dieselbe Auskunft wie auf der Zertifikatsseite des
// Panels.
func TestAPISiteZertsLesenDarfJedeRolle(t *testing.T) {
	s, ops := newSystemServer(t)
	ops.sites = privops.SiteBestand{Gelesen: true}
	cookie, _ := login(t, s, addUser(t, s, "gast", store.RoleReadOnly))

	a := zertLesen(t, s, cookie)
	if a.DarfAendern {
		t.Error("ein Konto mit Leserecht darf beziehen")
	}
}

// Die Urteilsstufen. Sie sind eine reine Funktion und deshalb ohne Server
// prüfbar — die Grenzen sind die Stelle, an der sich später jemand vertut.
func TestZertUrteilStufen(t *testing.T) {
	faelle := []struct {
		name  string
		z     apiSiteZert
		stufe string
	}{
		{"abgelaufen", apiSiteZert{Vorhanden: true, Resttage: -1}, "schlecht"},
		{"heute abgelaufen", apiSiteZert{Vorhanden: true, Resttage: 0}, "schlecht"},
		{"knapp", apiSiteZert{Vorhanden: true, Resttage: 14}, "schlecht"},
		{"in Erneuerung", apiSiteZert{Vorhanden: true, Resttage: 15}, "warn"},
		{"kurz vor der Erneuerung", apiSiteZert{Vorhanden: true, Resttage: 29}, "warn"},
		{"gut", apiSiteZert{Vorhanden: true, Resttage: 30}, "gut"},
		{"läuft gerade", apiSiteZert{Laeuft: true}, "info"},
		{"gescheitert", apiSiteZert{Fehler: "kaputt"}, "schlecht"},
		{"noch keins", apiSiteZert{}, "warn"},
	}
	for _, f := range faelle {
		stufe, satz := zertUrteil(f.z, true)
		if stufe != f.stufe {
			t.Errorf("%s: Stufe = %q, erwartet %q (%s)", f.name, stufe, f.stufe, satz)
		}
		if satz == "" {
			t.Errorf("%s: kein Satz — eine Stufe ohne Begründung ist eine Farbe", f.name)
		}
	}

	// Ein laufender Bezug schlägt den Fehler des vorigen Versuchs: Was gerade
	// passiert, ist die neuere Auskunft.
	if stufe, _ := zertUrteil(apiSiteZert{Laeuft: true, Fehler: "alt"}, true); stufe != "info" {
		t.Errorf("Stufe = %q — ein laufender Bezug ist kein Fehler", stufe)
	}
}

// gleicheNamen entscheidet, ob ein Manager weiterlaufen darf. Eine falsche
// Antwort hier hieße: entweder ein Manager mit veralteten Domains oder ein
// Neustart bei jedem Abgleich.
func TestGleicheNamen(t *testing.T) {
	if !gleicheNamen([]string{"a", "b"}, []string{"b", "a"}) {
		t.Error("dieselben Namen in anderer Reihenfolge gelten als verschieden")
	}
	if gleicheNamen([]string{"a"}, []string{"a", "b"}) {
		t.Error("eine hinzugekommene Domain fällt nicht auf")
	}
	if gleicheNamen([]string{"a", "b"}, []string{"a", "c"}) {
		t.Error("eine getauschte Domain fällt nicht auf")
	}
}

// acmefaehig wirft die Namen weg, die nginx kennt und ACME nicht.
func TestAcmefaehig(t *testing.T) {
	aus := acmefaehig([]string{"shop.example.com", "_", "", "*"})
	if len(aus) != 1 || aus[0] != "shop.example.com" {
		t.Errorf("acmefaehig = %v", aus)
	}
}
