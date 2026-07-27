package httpd

import (
	"errors"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

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

// pruefeHook nimmt nur absolute Pfade auf ausführbare Dateien an und gibt sie
// normalisiert zurück. Ein Hook läuft als root; ein relativer Pfad hinge davon
// ab, in welchem Verzeichnis der Dienst gerade steht, und ein Tippfehler fiele
// erst beim Bezug auf — Minuten später, in einer Logzeile.
func TestPruefeHook(t *testing.T) {
	dir := t.TempDir()

	skript := filepath.Join(dir, "setze.sh")
	if err := os.WriteFile(skript, []byte("#!/bin/sh\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	ohneRecht := filepath.Join(dir, "ohne-recht.sh")
	if err := os.WriteFile(ohneRecht, []byte("#!/bin/sh\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	// Der Umweg über ".." ist derselbe Pfad und muss auch so gespeichert
	// werden — sonst stehen zwei Schreibweisen für dieselbe Datei in der
	// Konfiguration.
	umweg := filepath.Join(dir, "unterordner", "..", "setze.sh")
	got, err := pruefeHook("Setzen", umweg)
	if err != nil {
		t.Fatalf("%q: %v", umweg, err)
	}
	if got != skript {
		t.Errorf("= %q, erwartet den normalisierten Pfad %q", got, skript)
	}

	for _, f := range []struct {
		name string
		pfad string
		will string
	}{
		{"leer", "", "fehlt"},
		{"relativ", "setze.sh", "kein absoluter Pfad"},
		{"nicht vorhanden", filepath.Join(dir, "gibtesnicht.sh"), "nicht vorhanden"},
		{"Verzeichnis", dir, "nicht ausführbar"},
		{"ohne Ausführungsrecht", ohneRecht, "nicht ausführbar"},
	} {
		t.Run(f.name, func(t *testing.T) {
			pfad, err := pruefeHook("Setzen", f.pfad)
			if err == nil {
				t.Fatalf("%q wurde angenommen", f.pfad)
			}
			if pfad != "" {
				t.Errorf("trotz Fehler kam ein Pfad zurück: %q", pfad)
			}
			if !strings.Contains(err.Error(), f.will) {
				t.Errorf("Meldung = %q, erwartet einen Hinweis auf %q", err, f.will)
			}
		})
	}
}

// Ein leeres Token-Feld darf einen hinterlegten Zugang nicht löschen: Die
// Oberfläche zeigt das Token nie zurück, also ist "leer" die Normalanzeige.
// Würde Speichern es dabei verwerfen, bräche die Erneuerung beim nächsten Mal.
func TestCloudflareTokenBleibtBeiLeeremFeld(t *testing.T) {
	dir := t.TempDir()
	s := &Server{cfg: config.Config{Paths: config.Paths{Data: dir}}}

	pfad, err := s.cloudflareToken("geheim-123", config.TLSSettings{})
	if err != nil {
		t.Fatal(err)
	}
	raw, err := os.ReadFile(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if strings.TrimSpace(string(raw)) != "geheim-123" {
		t.Errorf("Inhalt = %q", raw)
	}
	info, err := os.Stat(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Errorf("Rechte = %o, erwartet 600 — ein API-Schlüssel", info.Mode().Perm())
	}

	alt := config.TLSSettings{}
	alt.ACME.DNS01.Cloudflare.APITokenFile = pfad
	wieder, err := s.cloudflareToken("", alt)
	if err != nil {
		t.Fatalf("leeres Feld hat den Zugang verworfen: %v", err)
	}
	if wieder != pfad {
		t.Errorf("= %q, erwartet den bestehenden Pfad %q", wieder, pfad)
	}

	// Ohne hinterlegtes und ohne eingegebenes Token muss es eine Meldung
	// geben, statt eine Einstellung zu speichern, die nie funktioniert.
	if _, err := s.cloudflareToken("", config.TLSSettings{}); err == nil {
		t.Error("kein Token und kein Fehler")
	}
}

// Der Verlauf ist die Antwort auf das eigentliche Ärgernis: Ein Bezug dauert
// bis zu fünf Minuten, und bis hierher stand die Seite dabei still. Was
// certProgress meldet, muss auf der Seite ankommen und über /certificate/events
// weiterlaufen.
func TestZertVerlaufErscheintAufDerSeite(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	// Ohne Vorgang gibt es nichts zu zeigen und nichts zu streamen.
	if body := get(t, s, "/certificate", cookie).Body.String(); strings.Contains(body, "Verlauf des Bezugs") {
		t.Error("ein Verlauf wird angezeigt, obwohl nie einer lief")
	}
	if rec := get(t, s, "/certificate/events", cookie); rec.Code != http.StatusNotFound {
		t.Errorf("Strom ohne Vorgang: Status = %d, erwartet 404", rec.Code)
	}

	p := certProgress{s: s}
	p.Begin([]string{"panel.example.org"})
	p.Step("_acme-challenge.panel.example.org: TXT-Record gesetzt")

	body := get(t, s, "/certificate", cookie).Body.String()
	for _, will := range []string{
		"Verlauf des Bezugs",
		"Bezug für: panel.example.org",
		"TXT-Record gesetzt",
		`data-events="/certificate/events"`,
		"/static/job.js", // nur solange er läuft
		"läuft …",
	} {
		if !strings.Contains(body, will) {
			t.Errorf("auf der Seite fehlt %q", will)
		}
	}

	// Wer später dazukommt, bekommt den ganzen bisherigen Lauf. Der Strom
	// bleibt danach offen — deshalb mit Frist, sonst wartete der Test bis zum
	// Ende des Vorgangs, den hier niemand beendet.
	rec := stream(t, s, "/certificate/events", cookie, 200*time.Millisecond)
	if rec.Code != http.StatusOK {
		t.Fatalf("Strom: Status = %d", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), "TXT-Record gesetzt") {
		t.Errorf("der Strom liefert den bisherigen Lauf nicht: %s", rec.Body.String())
	}

	// Eine Zeile, die während des Mitlesens entsteht, muss ankommen — das ist
	// der ganze Zweck der Übung.
	go func() {
		time.Sleep(20 * time.Millisecond)
		p.Step("panel.example.org: bestätigt")
	}()
	rec = stream(t, s, "/certificate/events", cookie, 300*time.Millisecond)
	if !strings.Contains(rec.Body.String(), "bestätigt") {
		t.Errorf("eine später entstandene Zeile kam nicht an: %s", rec.Body.String())
	}

	p.End(nil)
	body = get(t, s, "/certificate", cookie).Body.String()
	if !strings.Contains(body, "abgeschlossen") || strings.Contains(body, "läuft …") {
		t.Error("der abgeschlossene Vorgang steht weiter auf \"läuft\"")
	}
	// Ist nichts mehr zu erwarten, hat das Nachladeskript nichts zu tun.
	if strings.Contains(body, "/static/job.js") {
		t.Error("das Live-Skript wird auch nach dem Ende noch eingebunden")
	}
}

// Ein Fehlschlag muss als Fehlschlag dastehen — sonst sieht ein Bezug, bei dem
// nichts herauskam, aus wie ein gelungener.
func TestZertVerlaufZeigtFehlschlag(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	p := certProgress{s: s}
	p.Begin([]string{"panel.example.org"})
	p.End(errors.New("autorisierung fehlgeschlagen für panel.example.org"))

	body := get(t, s, "/certificate", cookie).Body.String()
	for _, will := range []string{"fehlgeschlagen", "autorisierung fehlgeschlagen"} {
		if !strings.Contains(body, will) {
			t.Errorf("auf der Seite fehlt %q", will)
		}
	}
	if a := s.tls.attempt(); a.Running || a.Err == "" {
		t.Errorf("Zustand nach dem Fehlschlag: %+v", a)
	}
}

// Der Auslöser gehört an den Vorgang: Eine Erneuerung, die von selbst lief,
// darf nicht den Namen dessen tragen, der zuletzt gedrückt hat.
func TestZertVerlaufNenntDenAusloeser(t *testing.T) {
	s := newTestServer(t)
	p := certProgress{s: s}

	s.tls.setActor("philipp")
	p.Begin([]string{"panel.example.org"})
	if j := s.jobs.get(jobCertificate); j == nil || j.actor != "philipp" {
		t.Fatalf("Auslöser = %v, erwartet philipp", j)
	}
	p.End(nil)

	// Kein neuer Auslöser: der nächste Lauf ist ein selbsttätiger.
	p.Begin([]string{"panel.example.org"})
	if j := s.jobs.get(jobCertificate); j == nil || j.actor != "automatisch" {
		t.Fatalf("Auslöser = %v, erwartet automatisch", j)
	}
}

// obtainNow legt den Vorgang an, bevor es zurückkehrt. Täte es das erst im
// Hintergrund, zeigte die Antwortseite noch den vorigen Lauf — genau der
// Zustand, den diese Anzeige beheben soll.
func TestObtainNowLegtDenVorgangSofortAn(t *testing.T) {
	s := newTestServer(t)

	// Ohne eingeschalteten ACME-Betrieb gibt es keinen Manager und damit auch
	// keinen Vorgang; die Meldung muss das sagen.
	err := s.obtainNow("philipp")
	if err == nil {
		t.Fatal("ohne eingeschalteten Bezug kam kein Fehler")
	}
	if !strings.Contains(err.Error(), "nicht eingeschaltet") {
		t.Errorf("Meldung = %q", err)
	}
	if s.jobs.get(jobCertificate) != nil {
		t.Error("es wurde ein Vorgang angelegt, obwohl nichts läuft")
	}
}
