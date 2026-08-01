package httpd

// Tests für /api/v1/certificate.
//
// Fünf Stellen sind hier prüfenswert:
//
//  1. **Der Zwischenzustand.** Eingestellt „acme", aber noch kein Zertifikat
//     bezogen: Ausgeliefert wird weiter das selbstsignierte. Wer das nicht
//     erklärt bekommt, sucht den Fehler an der falschen Stelle.
//  2. **Das Token kommt nie zurück** und steht nie im Protokoll — nur die
//     Auskunft, DASS eines hinterlegt ist.
//  3. **Ein leeres Tokenfeld löscht kein hinterlegtes Token.**
//  4. **Die Prüfung greift vor dem Speichern**, mit derselben Prüfung, die der
//     Dienst beim Start fährt.
//  5. **Der Rückschritt auf selbstsigniert fragt zurück** — danach warnt jeder
//     Browser.

import (
	"encoding/json"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/certs"
	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/store"
)

func holeZertifikat(t *testing.T, s *Server, cookie *http.Cookie) apiZertifikat {
	t.Helper()
	rec := get(t, s, "/api/v1/certificate", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var a apiZertifikat
	if err := json.Unmarshal(rec.Body.Bytes(), &a); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	return a
}

// mitZertifikat legt das selbstsignierte Paar an — im Test entsteht es nicht von
// selbst, EnsurePair läuft nur in Run.
func mitZertifikat(t *testing.T, s *Server, namen ...string) {
	t.Helper()
	if _, err := certs.EnsurePair(s.cfg.Server.TLS.Cert, s.cfg.Server.TLS.Key, namen); err != nil {
		t.Fatal(err)
	}
}

func TestAPIZertifikatSelbstsigniert(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleReadOnly)
	mitZertifikat(t, s, "panel.example.test")

	a := holeZertifikat(t, s, cookie)

	if a.Modus != config.TLSModeSelfSigned {
		t.Errorf("Modus = %q", a.Modus)
	}
	if !a.Selbstsigniert {
		t.Error("das selbstsignierte Zertifikat ist nicht als solches gekennzeichnet")
	}
	// Selbstsigniert ist eine Warnung und niemals „gut": Es funktioniert, aber
	// jeder Browser widerspricht.
	if a.Zustand != "warn" {
		t.Errorf("Zustand = %q, erwartet warn — selbstsigniert ist nie „gut\"", a.Zustand)
	}
	if a.ZustandText == "" {
		t.Error("der Zustand steht ohne Wort da")
	}
	if len(a.Namen) == 0 || a.Namen[0] != "panel.example.test" {
		t.Errorf("Namen = %v", a.Namen)
	}
	if a.Fingerprint == "" || a.GueltigBis == "" {
		t.Errorf("Zertifikatsangaben fehlen: %+v", a)
	}
	if a.TageUebrig <= 0 {
		t.Errorf("TageUebrig = %d, erwartet eine positive Zahl", a.TageUebrig)
	}
	// Die Datei, in der die Einstellungen landen, wird genannt — das Panel
	// versteckt nichts.
	if a.VerwalteteDatei == "" {
		t.Error("die verwaltete Datei wird nicht genannt")
	}
	// Die Wahlmöglichkeiten kommen mit ihrer Erklärung.
	//
	// Die Anbieter werden NICHT gezählt, sondern benannt: Ihre Liste kommt seit
	// 0.6 aus dem Register des acme-Pakets und wächst mit jedem neuen. Eine
	// feste Zahl hier wäre ein Test, der bei jeder Erweiterung fehlschlägt,
	// ohne dass etwas kaputt wäre — und der deshalb irgendwann nur noch
	// hochgezählt statt gelesen wird.
	if len(a.Pruefmethoden) != 3 {
		t.Errorf("%d Prüfmethoden, erwartet 3", len(a.Pruefmethoden))
	}
	gefunden := map[string]bool{}
	for _, w := range a.Anbieters {
		gefunden[w.Wert] = true
	}
	for _, muss := range []string{"", "hook", "cloudflare", "acme-dns"} {
		if !gefunden[muss] {
			t.Errorf("der Anbieter %q fehlt in der Auswahl: %+v", muss, a.Anbieters)
		}
	}
	for _, w := range append(a.Pruefmethoden, a.Anbieters...) {
		if w.Name == "" || w.Was == "" {
			t.Errorf("Wahl %q ohne Namen oder Erklärung: %+v", w.Wert, w)
		}
	}
}

// Lesen darf jede Rolle, ändern nur mit Schreibrecht.
func TestAPIZertifikatRechte(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleReadOnly)
	mitZertifikat(t, s)

	if rec := get(t, s, "/api/v1/certificate", cookie); rec.Code != http.StatusOK {
		t.Errorf("readonly lesend = %d, erwartet 200", rec.Code)
	}
	rec := postJSON(t, s, "/api/v1/certificate", `{"modus":"selfsigned"}`, cookie, csrf)
	if rec.Code != http.StatusForbidden {
		t.Errorf("readonly schreibend = %d, erwartet 403", rec.Code)
	}
	rec = postJSON(t, s, "/api/v1/certificate/obtain", `{}`, cookie, csrf)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Bezug als readonly = %d, erwartet 403", rec.Code)
	}
}

// Fehlt die Datei, bleibt die Fläche erreichbar und sagt den Grund: Die
// Einstellungen sind gerade dann interessant.
func TestAPIZertifikatFehlendeDatei(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleOwner)

	a := holeZertifikat(t, s, cookie)
	if a.Lesefehler == "" {
		t.Error("der Grund fehlt")
	}
	if a.Zustand != "schlecht" {
		t.Errorf("Zustand = %q, erwartet schlecht", a.Zustand)
	}
	if a.VerwalteteDatei == "" {
		t.Error("ohne lesbares Zertifikat fehlen auch die Einstellungen")
	}
}

// Der Zwischenzustand: eingestellt „acme", aber noch nichts bezogen. Ausgeliefert
// wird weiter das selbstsignierte, und das muss dastehen.
func TestAPIZertifikatACMEOhneBezug(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)
	mitZertifikat(t, s)

	rec := postJSON(t, s, "/api/v1/certificate", `{
		"modus":"acme","email":"admin@example.test",
		"namenstext":"panel.example.test","pruefmethode":"http-01"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}

	a := holeZertifikat(t, s, cookie)
	if a.Modus != config.TLSModeACME {
		t.Errorf("Modus = %q, erwartet acme", a.Modus)
	}
	if !strings.Contains(a.Quelle, "Rückfall") {
		t.Errorf("Quelle = %q — der Zwischenzustand „eingestellt, aber noch nichts "+
			"bezogen\" ist nicht benannt", a.Quelle)
	}
	if !a.Selbstsigniert {
		t.Error("ausgeliefert wird weiter das selbstsignierte Zertifikat, das steht nicht da")
	}
	if a.Email != "admin@example.test" {
		t.Errorf("Email = %q", a.Email)
	}
	if len(a.GeltendeNamen) != 1 || a.GeltendeNamen[0] != "panel.example.test" {
		t.Errorf("GeltendeNamen = %v", a.GeltendeNamen)
	}
	// Und die Einstellungen stehen in der Ergänzung, nicht in der Hauptdatei.
	roh, err := os.ReadFile(config.ManagedTLSPath(s.cfgPath))
	if err != nil {
		t.Fatalf("die Ergänzung fehlt: %v", err)
	}
	if !strings.Contains(string(roh), "panel.example.test") {
		t.Errorf("die Ergänzung enthält die Namen nicht:\n%s", roh)
	}
}

// Die Prüfung greift VOR dem Speichern, und ihre Meldungen nennen keine
// YAML-Feldnamen: In einer Oberfläche, die keine YAML-Datei zeigt, hilft
// „acme.dns01.hook" niemandem.
func TestAPIZertifikatPruefung(t *testing.T) {
	faelle := []struct {
		was     string
		koerper string
		imText  string
	}{
		{"ohne Kontaktadresse", `{"modus":"acme","namenstext":"panel.example.test"}`,
			"Kontaktadresse"},
		{"keine Adresse", `{"modus":"acme","email":"keine adresse",
			"namenstext":"panel.example.test"}`, "E-Mail"},
		{"Name ohne Punkt", `{"modus":"acme","email":"a@example.test","namenstext":"panel"}`,
			"Punkt"},
		{"unbekannte Prüfmethode", `{"modus":"acme","email":"a@example.test",
			"pruefmethode":"tls-alpn-01"}`, "Prüfmethode"},
		{"DNS ohne Anbieter", `{"modus":"acme","email":"a@example.test",
			"pruefmethode":"dns-01"}`, "Anbieter"},
		{"Hook ohne Pfade", `{"modus":"acme","email":"a@example.test",
			"pruefmethode":"dns-01","anbieter":"hook"}`, "Setzen"},
		{"Hook mit relativem Pfad", `{"modus":"acme","email":"a@example.test",
			"pruefmethode":"dns-01","anbieter":"hook",
			"hook_setzen":"skript.sh","hook_aufraeumen":"/bin/true"}`, "absoluter Pfad"},
		{"Cloudflare ohne Zugang", `{"modus":"acme","email":"a@example.test",
			"pruefmethode":"dns-01","anbieter":"cloudflare"}`, "Zugangsdaten"},
		{"acme-dns ohne Zugang", `{"modus":"acme","email":"a@example.test",
			"pruefmethode":"dns-01","anbieter":"acme-dns"}`, "Zugangsdaten"},
		{"unbekannter Anbieter", `{"modus":"acme","email":"a@example.test",
			"anbieter":"route53"}`, "Anbieter"},
	}
	for _, f := range faelle {
		t.Run(f.was, func(t *testing.T) {
			s, cookie, csrf := angemeldet(t, store.RoleOwner)
			mitZertifikat(t, s)

			rec := postJSON(t, s, "/api/v1/certificate", f.koerper, cookie, csrf)
			if rec.Code != http.StatusBadRequest {
				t.Fatalf("Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
			}
			if !strings.Contains(rec.Body.String(), f.imText) {
				t.Errorf("die Meldung nennt %q nicht: %s", f.imText, rec.Body.String())
			}
			// Keine YAML-Feldnamen in der Meldung.
			if strings.Contains(rec.Body.String(), "acme.") {
				t.Errorf("die Meldung zeigt auf einen YAML-Feldnamen: %s", rec.Body.String())
			}
			// Und nichts wurde gespeichert.
			if s.tlsSettings().Mode == config.TLSModeACME {
				t.Error("die Einstellungen wurden trotz Ablehnung übernommen")
			}
		})
	}
}

// Das Token landet in einer eigenen Datei mit 0600, kommt nie zurück und steht
// nie im Protokoll.
func TestAPIZertifikatTokenBleibtGeheim(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)
	mitZertifikat(t, s)
	const token = "cf-geheim-1234567890"

	rec := postJSON(t, s, "/api/v1/certificate", `{
		"modus":"acme","email":"a@example.test","namenstext":"panel.example.test",
		"pruefmethode":"dns-01","anbieter":"cloudflare","token":"`+token+`"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	if strings.Contains(rec.Body.String(), token) {
		t.Fatal("das Token steht in der Antwort")
	}

	// In einer eigenen Datei mit 0600 — die Konfiguration ist für die Gruppe des
	// Dienstes lesbar, ein API-Schlüssel hat dort nichts zu suchen.
	// Eine Datei je Anbieter, benannt nach ihm: Ein Wechsel zu einem anderen
	// überschreibt den vorhandenen Zugang damit nicht.
	pfad := filepath.Join(s.cfg.Paths.Data, "acme", "cloudflare.zugang")
	info, err := os.Stat(pfad)
	if err != nil {
		t.Fatalf("die Tokendatei fehlt: %v", err)
	}
	if perm := info.Mode().Perm(); perm != 0o600 {
		t.Errorf("Rechte der Tokendatei = %04o, erwartet 0600", perm)
	}
	inhalt, err := os.ReadFile(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if strings.TrimSpace(string(inhalt)) != token {
		t.Error("das Token wurde nicht gespeichert")
	}
	// Nicht in der Ergänzung.
	ergaenzung, err := os.ReadFile(config.ManagedTLSPath(s.cfgPath))
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(ergaenzung), token) {
		t.Error("das Token steht in der Konfigurationsergänzung")
	}
	// Nicht im Protokoll.
	for _, e := range mustAudit(t, s) {
		if strings.Contains(e.Detail, token) {
			t.Error("das Token steht im Audit-Protokoll")
		}
	}

	// Die Auskunft, DASS eines hinterlegt ist, gibt es — sonst müsste man es bei
	// jedem Speichern neu eingeben oder raten.
	a := holeZertifikat(t, s, cookie)
	if !a.TokenHinterlegt {
		t.Error("das hinterlegte Token wird nicht gemeldet")
	}

	// Und ein leeres Feld löscht es nicht.
	rec = postJSON(t, s, "/api/v1/certificate", `{
		"modus":"acme","email":"a@example.test","namenstext":"panel.example.test",
		"pruefmethode":"dns-01","anbieter":"cloudflare"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("zweites Speichern = %d: %s", rec.Code, rec.Body.String())
	}
	if !holeZertifikat(t, s, cookie).TokenHinterlegt {
		t.Error("ein leeres Tokenfeld hat den Zugang gelöscht — wer die Seite öffnet " +
			"und speichert, verliert dann einen funktionierenden Zugang")
	}
	nachher, err := os.ReadFile(pfad)
	if err != nil || strings.TrimSpace(string(nachher)) != token {
		t.Errorf("das Token hat sich geändert: %q (%v)", nachher, err)
	}
}

// Der Rückschritt auf selbstsigniert fragt zurück: Danach warnt jeder Browser.
func TestAPIZertifikatRueckschrittFragtZurueck(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)
	mitZertifikat(t, s)

	// Erst auf ACME — das fragt NICHT: Es verbessert etwas, und bis der Bezug
	// glückt, bleibt alles wie es war.
	rec := postJSON(t, s, "/api/v1/certificate", `{
		"modus":"acme","email":"a@example.test","namenstext":"panel.example.test",
		"pruefmethode":"http-01"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Wechsel auf ACME = %d, erwartet 200 ohne Rückfrage: %s",
			rec.Code, rec.Body.String())
	}

	// Und zurück: Das fragt.
	rec = postJSON(t, s, "/api/v1/certificate", `{"modus":"selfsigned"}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Rückschritt ohne Bestätigung = %d, erwartet 409: %s",
			rec.Code, rec.Body.String())
	}
	frage := frageVon(t, rec.Body.Bytes())
	if len(frage.Punkte) == 0 {
		t.Error("die Frage nennt keine Folgen")
	}
	warnt := false
	for _, p := range frage.Punkte {
		if strings.Contains(p, "warn") {
			warnt = true
		}
	}
	if !warnt {
		t.Errorf("die Frage sagt nicht, dass danach jeder Browser warnt: %v", frage.Punkte)
	}
	if frage.Tippen != "" {
		t.Errorf("der Rückschritt verlangt Getipptes (%q) — er ist umkehrbar, Stufe 2 genügt",
			frage.Tippen)
	}
	// Ohne Bestätigung ist nichts geschehen.
	if s.tlsSettings().Mode != config.TLSModeACME {
		t.Fatal("die Einstellung wurde ohne Bestätigung geändert")
	}

	rec = postJSON(t, s, "/api/v1/certificate",
		`{"modus":"selfsigned","bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("mit Bestätigung = %d: %s", rec.Code, rec.Body.String())
	}
	if s.tlsSettings().Mode != config.TLSModeSelfSigned {
		t.Error("die Einstellung wurde nicht geändert")
	}
}

// Ohne eingeschalteten ACME-Modus gibt es nichts zu beziehen — und die Antwort
// sagt das, statt einen Vorgang zu starten, der sofort scheitert.
func TestAPIZertifikatBezugOhneACME(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)
	mitZertifikat(t, s)

	rec := postJSON(t, s, "/api/v1/certificate/obtain", `{}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "nicht eingeschaltet") {
		t.Errorf("der Grund fehlt: %s", rec.Body.String())
	}
	// Und der Fehlversuch steht im Protokoll.
	gefunden := false
	for _, e := range mustAudit(t, s) {
		if e.Action == "tls.obtain" && e.Result == store.ResultError {
			gefunden = true
		}
	}
	if !gefunden {
		t.Error("der Fehlversuch steht nicht im Audit-Protokoll")
	}
}

// Der Zustand fasst Beglaubigung und Restlaufzeit in einem Wort zusammen. Die
// Grenzen sind eine Entscheidung und deshalb geprüft.
func TestZertifikatZustand(t *testing.T) {
	faelle := []struct {
		selbstsigniert bool
		tage           int
		zustand        string
	}{
		{false, 60, "gut"},
		{true, 60, "warn"},
		{false, 13, "warn"},
		{true, 13, "warn"},
		{false, -1, "schlecht"},
		{true, -1, "schlecht"},
	}
	for _, f := range faelle {
		zustand, text := zertifikatZustand(f.selbstsigniert, f.tage)
		if zustand != f.zustand {
			t.Errorf("selbstsigniert=%t tage=%d → %q, erwartet %q",
				f.selbstsigniert, f.tage, zustand, f.zustand)
		}
		if text == "" {
			t.Errorf("selbstsigniert=%t tage=%d → Zustand ohne Wort", f.selbstsigniert, f.tage)
		}
	}
}
