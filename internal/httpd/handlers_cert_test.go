package httpd

import (
	"net/http"
	"net/url"
	"os"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/certs"
	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/store"
)

func TestCertificatePageSelfSigned(t *testing.T) {
	s := newTestServer(t)
	// Das TLS-Material existiert im Test nicht von selbst (EnsurePair läuft nur
	// in Run) — für die Seite legen wir es an.
	if _, err := certs.EnsurePair(s.cfg.Server.TLS.Cert, s.cfg.Server.TLS.Key, []string{"panel.example.test"}); err != nil {
		t.Fatal(err)
	}

	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	rec := get(t, s, "/certificate", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}
	body := rec.Body.String()
	for _, want := range []string{"TLS-Zertifikat", "selbstsigniert", "panel.example.test"} {
		if !strings.Contains(body, want) {
			t.Errorf("Seite enthält %q nicht", want)
		}
	}
}

// Auch ein Leser (readonly) darf den Zertifikatszustand sehen.
func TestCertificatePageReadOnly(t *testing.T) {
	s := newTestServer(t)
	if _, err := certs.EnsurePair(s.cfg.Server.TLS.Cert, s.cfg.Server.TLS.Key, nil); err != nil {
		t.Fatal(err)
	}
	user := addUser(t, s, "leser", store.RoleReadOnly)
	cookie, _ := login(t, s, user)

	if rec := get(t, s, "/certificate", cookie); rec.Code != http.StatusOK {
		t.Errorf("readonly: Status = %d, erwartet 200", rec.Code)
	}
}

// Fehlt die Datei, bleibt die Seite erreichbar und zeigt den Grund, statt mit
// 500 zu scheitern.
func TestCertificatePageMissingFileStillRenders(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	rec := get(t, s, "/certificate", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200 (Seite soll trotz fehlender Datei erreichbar bleiben)", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), "konnte nicht gelesen werden") {
		t.Error("der Lesefehler wird nicht angezeigt")
	}
}

// zertForm baut das Formular der Zertifikatsseite mit sinnvollen Vorgaben.
func zertForm(csrf string, felder map[string]string) url.Values {
	v := url.Values{"_csrf": {csrf}}
	for k, wert := range felder {
		v.Set(k, wert)
	}
	return v
}

// TestZertEinstellungenLandenInDerErgaenzung ist der Kern der Änderung: Bis
// hierher ließ sich der Bezug nur einstellen, indem jemand
// /etc/asylum/config.yaml von Hand anfasste — für ein Control Panel die
// falsche Antwort.
func TestZertEinstellungenLandenInDerErgaenzung(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/certificate", zertForm(csrf, map[string]string{
		"mode":    "acme",
		"domains": "panel.example.org",
		"email":   "admin@example.org",
	}), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}

	// Auf der Platte …
	raw, err := os.ReadFile(config.ManagedTLSPath(s.cfgPath))
	if err != nil {
		t.Fatalf("die Ergänzung wurde nicht geschrieben: %v", err)
	}
	for _, want := range []string{"mode: acme", "panel.example.org", "admin@example.org"} {
		if !strings.Contains(string(raw), want) {
			t.Errorf("in der Datei fehlt %q:\n%s", want, raw)
		}
	}

	// … und sofort im laufenden Dienst, ohne Neustart.
	if got := s.tlsSettings(); got.Mode != config.TLSModeACME || got.ACME.Email != "admin@example.org" {
		t.Errorf("die laufende Einstellung wurde nicht übernommen: %+v", got)
	}

	// Ein neu geladener Dienst sieht dasselbe.
	geladen, err := config.Load(s.cfgPath)
	if err != nil {
		t.Fatalf("die geschriebene Ergänzung lässt sich nicht laden: %v", err)
	}
	if geladen.Server.TLS.Mode != config.TLSModeACME {
		t.Errorf("nach dem Neuladen = %q", geladen.Server.TLS.Mode)
	}
}

// TestZertEinstellungenPruefung: Was die Oberfläche annimmt, muss auch beim
// nächsten Start des Dienstes durchgehen. Sonst speichert jemand eine
// Konfiguration, mit der der Daemon nicht mehr hochkommt — und dann ist die
// Seite weg, auf der er es zurücknehmen könnte.
func TestZertEinstellungenPruefung(t *testing.T) {
	faelle := []struct {
		name   string
		felder map[string]string
		imText string
	}{
		{
			name:   "ohne Kontaktadresse",
			felder: map[string]string{"mode": "acme", "domains": "panel.example.org"},
			imText: "Kontaktadresse",
		},
		{
			name:   "Adresse ist keine",
			felder: map[string]string{"mode": "acme", "email": "kein-at-zeichen"},
			imText: "E-Mail-Adresse",
		},
		{
			name: "Name ohne Punkt",
			felder: map[string]string{
				"mode": "acme", "email": "a@example.org", "domains": "localhost",
			},
			imText: "keinen Punkt",
		},
		{
			name: "DNS-Prüfung ohne Anbieter",
			felder: map[string]string{
				"mode": "acme", "email": "a@example.org", "challenge": "dns-01",
			},
			imText: "braucht einen Anbieter",
		},
		{
			name: "Hook ohne Pfade",
			felder: map[string]string{
				"mode": "acme", "email": "a@example.org", "provider": "hook",
			},
			imText: "Setzen-Skript",
		},
		{
			name: "Hook mit relativem Pfad",
			felder: map[string]string{
				"mode": "acme", "email": "a@example.org", "provider": "hook",
				"hook_set": "dns-set", "hook_clean": "/bin/true",
			},
			imText: "kein absoluter Pfad",
		},
		{
			name: "Cloudflare ohne Token",
			felder: map[string]string{
				"mode": "acme", "email": "a@example.org", "provider": "cloudflare",
			},
			imText: "API-Token",
		},
	}

	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			s := newTestServer(t)
			user := addUser(t, s, "philipp", store.RoleOwner)
			cookie, csrf := login(t, s, user)

			rec := post(t, s, "/certificate", zertForm(csrf, f.felder), cookie)
			if rec.Code != http.StatusBadRequest {
				t.Fatalf("Status = %d, erwartet 400", rec.Code)
			}
			if !strings.Contains(rec.Body.String(), f.imText) {
				t.Errorf("die Meldung nennt %q nicht", f.imText)
			}
			// Nichts darf geschrieben worden sein.
			if _, err := os.Stat(config.ManagedTLSPath(s.cfgPath)); err == nil {
				t.Error("trotz Fehler wurde eine Ergänzung geschrieben")
			}
			if s.tlsSettings().Mode != config.TLSModeSelfSigned {
				t.Error("trotz Fehler wurde die laufende Einstellung geändert")
			}
		})
	}
}

// Das Cloudflare-Token gehört nicht in die Konfiguration, sondern in eine
// eigene Datei mit 0600 — und es wird nie zurückgezeigt.
func TestCloudflareTokenLandetInEigenerDatei(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	const token = "cf-geheim-1234567890"
	rec := post(t, s, "/certificate", zertForm(csrf, map[string]string{
		"mode": "acme", "email": "a@example.org", "domains": "panel.example.org",
		"provider": "cloudflare", "cf_token": token,
	}), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}

	pfad := s.tlsSettings().ACME.DNS01.Cloudflare.APITokenFile
	if pfad == "" {
		t.Fatal("kein Pfad zur Tokendatei gesetzt")
	}
	info, err := os.Stat(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Errorf("Rechte = %o, erwartet 600", info.Mode().Perm())
	}
	raw, _ := os.ReadFile(pfad)
	if strings.TrimSpace(string(raw)) != token {
		t.Errorf("Inhalt = %q", raw)
	}

	// Weder in der Ergänzung noch auf der Seite darf das Token auftauchen.
	ergaenzung, _ := os.ReadFile(config.ManagedTLSPath(s.cfgPath))
	if strings.Contains(string(ergaenzung), token) {
		t.Error("das Token steht in der Konfigurationsergänzung")
	}
	if strings.Contains(get(t, s, "/certificate", cookie).Body.String(), token) {
		t.Error("das Token wird auf der Seite zurückgezeigt")
	}
}

// Ein leeres Tokenfeld darf einen funktionierenden Zugang nicht löschen —
// sonst zerstört jedes Speichern einer anderen Einstellung den DNS-Zugang.
func TestLeeresTokenfeldBehaeltDenZugang(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	felder := map[string]string{
		"mode": "acme", "email": "a@example.org", "domains": "panel.example.org",
		"provider": "cloudflare", "cf_token": "erstes-token",
	}
	if rec := post(t, s, "/certificate", zertForm(csrf, felder), cookie); rec.Code != http.StatusOK {
		t.Fatalf("Status = %d", rec.Code)
	}
	pfad := s.tlsSettings().ACME.DNS01.Cloudflare.APITokenFile

	// Zweites Speichern ohne Token, aber mit geänderter Domain.
	felder["cf_token"] = ""
	felder["domains"] = "anders.example.org"
	rec := post(t, s, "/certificate", zertForm(csrf, felder), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("zweites Speichern: Status = %d: %s", rec.Code, rec.Body.String())
	}
	if got := s.tlsSettings().ACME.DNS01.Cloudflare.APITokenFile; got != pfad {
		t.Errorf("Tokenpfad = %q, erwartet %q", got, pfad)
	}
	raw, _ := os.ReadFile(pfad)
	if strings.TrimSpace(string(raw)) != "erstes-token" {
		t.Errorf("das Token wurde überschrieben: %q", raw)
	}
}

// Zurück auf selbstsigniert muss immer gehen — auch ohne ausgefüllte
// ACME-Felder. Das ist der Rückweg, wenn der Bezug nicht klappt.
func TestZurueckAufSelbstsigniert(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	if rec := post(t, s, "/certificate", zertForm(csrf, map[string]string{
		"mode": "acme", "email": "a@example.org", "domains": "panel.example.org",
	}), cookie); rec.Code != http.StatusOK {
		t.Fatalf("Status = %d", rec.Code)
	}
	rec := post(t, s, "/certificate", zertForm(csrf, map[string]string{"mode": "selfsigned"}), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("zurück: Status = %d: %s", rec.Code, rec.Body.String())
	}
	if got := s.tlsSettings().Mode; got != config.TLSModeSelfSigned {
		t.Errorf("Modus = %q", got)
	}
}

// Lesende Rollen dürfen die Seite sehen, aber nichts ändern.
func TestZertEinstellungenNurMitSchreibrecht(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "leser", store.RoleReadOnly)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/certificate", zertForm(csrf, map[string]string{
		"mode": "acme", "email": "a@example.org",
	}), cookie)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Status = %d, erwartet 403", rec.Code)
	}
	if s.tlsSettings().Mode != config.TLSModeSelfSigned {
		t.Error("ein Leser konnte die Einstellungen ändern")
	}
}

// "Jetzt beziehen" ohne eingeschalteten Bezug soll erklären statt zu schweigen.
func TestBeziehenOhneACME(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/certificate/obtain", url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("Status = %d, erwartet 400", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), "nicht eingeschaltet") {
		t.Errorf("die Meldung erklärt nichts: %s", rec.Body.String())
	}
}

func TestParseDomains(t *testing.T) {
	got, err := parseDomains(" Panel.Example.ORG.\nzweite.example.org , dritte.example.org ")
	if err != nil {
		t.Fatal(err)
	}
	want := []string{"panel.example.org", "zweite.example.org", "dritte.example.org"}
	if len(got) != len(want) {
		t.Fatalf("= %v, erwartet %v", got, want)
	}
	for i := range want {
		if got[i] != want[i] {
			t.Errorf("[%d] = %q, erwartet %q", i, got[i], want[i])
		}
	}
	if leer, err := parseDomains("  \n "); err != nil || leer != nil {
		t.Errorf("leere Eingabe = %v, %v — erwartet nil, nil", leer, err)
	}
}
