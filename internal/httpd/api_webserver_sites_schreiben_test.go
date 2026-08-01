package httpd

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// Tests der Schreibrouten. Der Schwerpunkt liegt auf dem, was NICHT passieren
// darf — dieselbe Haltung wie bei der Firewall, und aus demselben Grund: Dieses
// Modul kann die Oberfläche vom Netz nehmen, mit der man es zurücknehmen müsste.

// entwurfKoerper ist ein gültiger Anfragekörper. Die Fälle ändern daran genau
// ein Feld, sonst stünde in jedem Test die Frage, woher die Ablehnung kommt.
const entwurfKoerper = `{"domains":["shop.example.com"],"zielart":"proxy",` +
	`"ziel":"http://127.0.0.1:3000","bestaetigt":true}`

func siteSchreibServer(t *testing.T) (*Server, *fakeOps, *http.Cookie, string) {
	t.Helper()
	s, ops := newSystemServer(t)
	ops.sites = privops.SiteBestand{Gelesen: true}
	user := addUser(t, s, "chef", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	return s, ops, cookie, csrf
}

// jsonAnfrage schickt eine Anfrage mit beliebiger Methode — postJSON gibt es
// schon, PUT und DELETE brauchen dieselbe Behandlung.
func jsonAnfrage(t *testing.T, s *Server, methode, pfad, koerper string,
	cookie *http.Cookie, csrf string) *httptest.ResponseRecorder {
	t.Helper()
	req := httptest.NewRequest(methode, pfad, strings.NewReader(koerper))
	req.Header.Set("Content-Type", "application/json")
	if csrf != "" {
		req.Header.Set("X-CSRF-Token", csrf)
	}
	if cookie != nil {
		req.AddCookie(cookie)
	}
	rec := httptest.NewRecorder()
	s.Handler().ServeHTTP(rec, req)
	return rec
}

func putJSON(t *testing.T, s *Server, pfad, koerper string,
	cookie *http.Cookie, csrf string) *httptest.ResponseRecorder {
	t.Helper()
	return jsonAnfrage(t, s, http.MethodPut, pfad, koerper, cookie, csrf)
}

func deleteJSON(t *testing.T, s *Server, pfad, koerper string,
	cookie *http.Cookie, csrf string) *httptest.ResponseRecorder {
	t.Helper()
	return jsonAnfrage(t, s, http.MethodDelete, pfad, koerper, cookie, csrf)
}

// warteAuf pollt eine Bedingung, statt eine feste Zeit zu schlafen. Ein Schlaf
// ist entweder zu kurz (sporadisch rot) oder zu lang (der Test dauert) — meist
// beides auf verschiedenen Maschinen.
func warteAuf(t *testing.T, bedingung func() bool, wennNicht string) {
	t.Helper()
	frist := time.Now().Add(3 * time.Second)
	for time.Now().Before(frist) {
		if bedingung() {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatal(wennNicht)
}

func TestAPISiteSchreibenLegtAnUndStelltDieProbeScharf(t *testing.T) {
	s, ops, cookie, csrf := siteSchreibServer(t)

	rec := putJSON(t, s, "/api/v1/webserver/sites/shop", entwurfKoerper, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	if len(ops.siteEntwuerfe) != 1 {
		t.Fatalf("SiteApply lief %d-mal, erwartet einmal", len(ops.siteEntwuerfe))
	}
	if ops.siteEntwuerfe[0].Name != "shop" {
		t.Errorf("Name = %q, erwartet shop (aus dem Pfad, nicht aus dem Körper)",
			ops.siteEntwuerfe[0].Name)
	}

	var a apiSiteAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &a); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	if !a.Probe.Offen {
		t.Fatal("nach dem Schreiben steht keine Probe aus — eine Änderung, die " +
			"niemand bestätigen muss, hat keinen Rückweg")
	}
	if !strings.Contains(a.Probe.Gegenstand, "shop") {
		t.Errorf("der Gegenstand der Probe nennt die Site nicht: %q", a.Probe.Gegenstand)
	}
	if a.Fassung == "" {
		t.Error("die neue Fassung fehlt — die Oberfläche schriebe beim nächsten Mal blind")
	}

	// Und die Probe steht auch in der LISTE: Wer neu lädt, während die Frist
	// läuft, muss den Countdown vorfinden.
	if !sitesLesen(t, s, cookie).Probe.Offen {
		t.Error("die laufende Probe steht nicht in der Sitesliste")
	}
}

// Ohne Bestätigung läuft nichts. Der Statuscode ist 409, und geprüft wird die
// WIRKUNG — dass nichts geschrieben wurde — und nicht nur die Zahl.
func TestAPISiteSchreibenFragtZurueck(t *testing.T) {
	s, ops, cookie, csrf := siteSchreibServer(t)

	koerper := `{"domains":["shop.example.com"],"zielart":"proxy","ziel":"http://127.0.0.1:3000"}`
	rec := putJSON(t, s, "/api/v1/webserver/sites/shop", koerper, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	if len(ops.siteEntwuerfe) != 0 {
		t.Error("trotz fehlender Bestätigung wurde geschrieben")
	}

	var b apiBestaetigungAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &b); err != nil {
		t.Fatalf("Rückfrage nicht lesbar: %v", err)
	}
	if b.Bestaetigung.Tippen != "" {
		t.Errorf("der gerade Fall ist Stufe 2 und verlangt kein getipptes Wort: %q",
			b.Bestaetigung.Tippen)
	}
	// Der Punkt, um dessentwillen es die Probe gibt, muss in der Frage stehen.
	if !strings.Contains(strings.Join(b.Bestaetigung.Punkte, " "), "Probe") {
		t.Errorf("die Rückfrage erwähnt die Probe nicht: %v", b.Bestaetigung.Punkte)
	}
}

// Ein root außerhalb der üblichen Wurzeln ist Stufe 3: Es ist der legitime und
// häufige Fall — und zugleich der Weg, über den eine Site fremde Daten
// ausliefert.
func TestAPISiteSchreibenVerlangtDenDomainnamenBeiFremdemVerzeichnis(t *testing.T) {
	s, ops, cookie, csrf := siteSchreibServer(t)

	koerper := `{"domains":["shop.example.com"],"zielart":"statisch","ziel":"/opt/kram"}`
	rec := putJSON(t, s, "/api/v1/webserver/sites/shop", koerper, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	var b apiBestaetigungAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &b); err != nil {
		t.Fatalf("Rückfrage nicht lesbar: %v", err)
	}
	if b.Bestaetigung.Tippen != "shop.example.com" {
		t.Errorf("Tippen = %q, erwartet den Domainnamen", b.Bestaetigung.Tippen)
	}
	// Die Begründung steht VORN: Sie ist der Grund, warum hier getippt werden
	// muss, und eine Begründung unter drei anderen Zeilen liest niemand.
	if len(b.Bestaetigung.Punkte) == 0 || !strings.Contains(b.Bestaetigung.Punkte[0], "außerhalb") {
		t.Errorf("die Warnung steht nicht an erster Stelle: %v", b.Bestaetigung.Punkte)
	}

	// Ein falsches Wort wirkt nicht.
	falsch := `{"domains":["shop.example.com"],"zielart":"statisch","ziel":"/opt/kram",` +
		`"bestaetigt":true,"getippt":"irgendwas"}`
	rec = putJSON(t, s, "/api/v1/webserver/sites/shop", falsch, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Errorf("Status = %d, erwartet 409 bei falschem Wort", rec.Code)
	}
	if len(ops.siteEntwuerfe) != 0 {
		t.Fatal("ein falsch getipptes Wort hat trotzdem geschrieben")
	}

	// Das richtige schon.
	richtig := `{"domains":["shop.example.com"],"zielart":"statisch","ziel":"/opt/kram",` +
		`"bestaetigt":true,"getippt":"shop.example.com"}`
	if rec = putJSON(t, s, "/api/v1/webserver/sites/shop", richtig, cookie, csrf); rec.Code != http.StatusOK {
		t.Fatalf("Status = %d bei richtigem Wort: %s", rec.Code, rec.Body.String())
	}
	if len(ops.siteEntwuerfe) != 1 {
		t.Error("das richtige Wort hat nicht geschrieben")
	}
}

// Der Prüfer sitzt VOR der Rückfrage: Was er ablehnt, bekommt gar keine Frage
// gestellt — eine Rückfrage zu etwas, das ohnehin nicht geht, ist eine
// Einladung, es zu bestätigen.
func TestAPISiteSchreibenLehntVorDerRueckfrageAb(t *testing.T) {
	s, ops, cookie, csrf := siteSchreibServer(t)

	koerper := `{"domains":["shop.example.com"],"zielart":"statisch","ziel":"/etc"}`
	rec := putJSON(t, s, "/api/v1/webserver/sites/shop", koerper, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
	}
	if len(ops.siteEntwuerfe) != 0 {
		t.Error("trotz Ablehnung wurde geschrieben")
	}

	var a apiSiteAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &a); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	if a.Pruefung == nil || len(a.Pruefung.Ablehnungen) == 0 {
		t.Fatal("die Ablehnung wird nicht begründet")
	}
	if a.Pruefung.Ablehnungen[0].Feld != "ziel" {
		t.Errorf("die Ablehnung zeigt auf %q, erwartet ziel", a.Pruefung.Ablehnungen[0].Feld)
	}
}

// Zwei Fenster, zwei Bearbeitungen. Die zweite darf die erste nicht
// stillschweigend überschreiben.
func TestAPISiteSchreibenMeldetDenFassungskonflikt(t *testing.T) {
	s, ops, cookie, csrf := siteSchreibServer(t)
	ops.siteSchreibErr = privops.ErrSiteFassung

	rec := putJSON(t, s, "/api/v1/webserver/sites/shop", entwurfKoerper, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "neu laden") {
		t.Errorf("die Meldung sagt nicht, was zu tun ist: %s", rec.Body.String())
	}
	// Und keine Probe: Es hat sich nichts geändert, also gibt es nichts
	// zurückzunehmen. Eine offene Frist ohne Änderung wäre ein Countdown auf
	// nichts.
	if sitesLesen(t, s, cookie).Probe.Offen {
		t.Error("nach einem Konflikt läuft eine Probe")
	}
}

// Löschen ist Stufe 3 und ausdrücklich OHNE Probe: Ein Rückweg, der die halbe
// Sache wiederherstellt, ist schlechter als keiner.
func TestAPISiteLoeschenVerlangtDenNamenUndLaesstKeineProbe(t *testing.T) {
	s, ops, cookie, csrf := siteSchreibServer(t)

	rec := deleteJSON(t, s, "/api/v1/webserver/sites/shop", `{}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	var b apiBestaetigungAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &b); err != nil {
		t.Fatalf("Rückfrage nicht lesbar: %v", err)
	}
	if b.Bestaetigung.Tippen != "shop" {
		t.Errorf("Tippen = %q, erwartet den Namen der Site", b.Bestaetigung.Tippen)
	}
	if len(ops.siteGeloescht) != 0 {
		t.Fatal("ohne Bestätigung wurde gelöscht")
	}

	rec = deleteJSON(t, s, "/api/v1/webserver/sites/shop",
		`{"bestaetigt":true,"getippt":"shop"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	if len(ops.siteGeloescht) != 1 {
		t.Fatal("die Site wurde nicht gelöscht")
	}
	if sitesLesen(t, s, cookie).Probe.Offen {
		t.Error("nach dem Löschen läuft eine Probe — sie verspräche einen Rückweg, " +
			"den es nicht gibt")
	}
}

// Abschalten ist Stufe 2, einschalten Stufe 1. Der Unterschied ist die Wirkung:
// Abschalten nimmt eine Domain vom Netz, einschalten bringt zurück, was der
// Betreiber selbst angelegt hat.
func TestAPISiteSchaltenStufen(t *testing.T) {
	s, ops, cookie, csrf := siteSchreibServer(t)

	rec := postJSON(t, s, "/api/v1/webserver/sites/shop/schalten", `{"an":false}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Abschalten ohne Bestätigung: Status = %d, erwartet 409", rec.Code)
	}
	if len(ops.siteGeschaltet) != 0 {
		t.Fatal("ohne Bestätigung wurde abgeschaltet")
	}

	rec = postJSON(t, s, "/api/v1/webserver/sites/shop/schalten", `{"an":true}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Einschalten: Status = %d, erwartet 200 ohne Rückfrage: %s", rec.Code, rec.Body.String())
	}
	if len(ops.siteGeschaltet) != 1 || ops.siteGeschaltet[0] != "shop=an" {
		t.Errorf("geschaltet = %v", ops.siteGeschaltet)
	}
}

// Die Probe nimmt zurück, wenn niemand bestätigt — und das Bestätigen
// verhindert es. Beides an der echten Verdrahtung geprüft und nicht am Wächter
// allein: Der ist in probe_test.go abgedeckt; hier geht es darum, dass dieser
// Handler ihn scharf stellt und dass SiteRestore die richtige Rücknahme bekommt.
func TestAPISiteProbeNimmtOhneBestaetigungZurueck(t *testing.T) {
	s, ops, cookie, csrf := siteSchreibServer(t)
	s.siteGuard = neuerProbenWaechter(80 * time.Millisecond)

	if rec := putJSON(t, s, "/api/v1/webserver/sites/shop", entwurfKoerper, cookie, csrf); rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}

	warteAuf(t, func() bool {
		ops.mu.Lock()
		defer ops.mu.Unlock()
		return len(ops.siteRestore) == 1
	}, "der Rückbau lief nicht — eine unbestätigte Site bliebe dauerhaft stehen")

	ops.mu.Lock()
	datei := ops.siteRestore[0].Datei
	ops.mu.Unlock()
	if !strings.Contains(datei, "asylum-shop.conf") {
		t.Errorf("die Rücknahme betrifft %q, erwartet die Datei der Site", datei)
	}
}

func TestAPISiteProbeBestaetigenVerhindertDenRueckbau(t *testing.T) {
	s, ops, cookie, csrf := siteSchreibServer(t)
	s.siteGuard = neuerProbenWaechter(200 * time.Millisecond)

	if rec := putJSON(t, s, "/api/v1/webserver/sites/shop", entwurfKoerper, cookie, csrf); rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	rec := postJSON(t, s, "/api/v1/webserver/sites/bestaetigen", `{}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Bestätigen: Status = %d: %s", rec.Code, rec.Body.String())
	}

	time.Sleep(500 * time.Millisecond)
	ops.mu.Lock()
	defer ops.mu.Unlock()
	if len(ops.siteRestore) != 0 {
		t.Error("der Rückbau lief, obwohl bestätigt wurde")
	}
}

// Eine zweite Bestätigung gilt für nichts.
func TestAPISiteBestaetigenOhneProbe(t *testing.T) {
	s, _, cookie, csrf := siteSchreibServer(t)

	rec := postJSON(t, s, "/api/v1/webserver/sites/bestaetigen", `{}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Errorf("Status = %d, erwartet 409 ohne laufende Probe", rec.Code)
	}
}

// Schreiben ist der Owner-Rolle vorbehalten — dieselbe Begründung wie bei Docker
// und den Zeitplänen: Eine Site ist Konfiguration, die als root gelesen wird.
func TestAPISiteSchreibenVerlangtOwner(t *testing.T) {
	s, ops := newSystemServer(t)
	ops.sites = privops.SiteBestand{Gelesen: true}
	user := addUser(t, s, "gehilfe", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	faelle := []struct {
		methode, pfad, koerper string
	}{
		{"PUT", "/api/v1/webserver/sites/shop", entwurfKoerper},
		{"DELETE", "/api/v1/webserver/sites/shop", `{"bestaetigt":true,"getippt":"shop"}`},
		{"POST", "/api/v1/webserver/sites/shop/schalten", `{"an":true}`},
		{"POST", "/api/v1/webserver/sites/bestaetigen", `{}`},
	}
	for _, f := range faelle {
		rec := jsonAnfrage(t, s, f.methode, f.pfad, f.koerper, cookie, csrf)
		if rec.Code != http.StatusForbidden {
			t.Errorf("%s %s: Status = %d, erwartet 403", f.methode, f.pfad, rec.Code)
		}
	}
	if len(ops.siteEntwuerfe) != 0 || len(ops.siteGeloescht) != 0 || len(ops.siteGeschaltet) != 0 {
		t.Error("ein Admin-Konto hat trotzdem etwas verändert")
	}
}

// Ein unbekanntes Feld ist ein Tippfehler und keine Freiheit: Sonst wäre ein
// falsch geschriebenes „bestaetigt" stillschweigend ein fehlendes.
func TestAPISiteSchreibenLehntUnbekanntesFeldAb(t *testing.T) {
	s, ops, cookie, csrf := siteSchreibServer(t)

	koerper := `{"domains":["shop.example.com"],"zielart":"proxy",` +
		`"ziel":"http://127.0.0.1:3000","bestaetig":true}`
	rec := putJSON(t, s, "/api/v1/webserver/sites/shop", koerper, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
	}
	if len(ops.siteEntwuerfe) != 0 {
		t.Error("trotz unlesbarem Körper wurde geschrieben")
	}
}

// Die Zertifikatspfade kommen NICHT aus der Anfrage. Wer sie setzen dürfte,
// könnte nginx eine beliebige Datei als Schlüssel unterschieben.
func TestAPISiteSchreibenNimmtKeineZertifikatspfadeEntgegen(t *testing.T) {
	s, _, cookie, csrf := siteSchreibServer(t)

	koerper := `{"domains":["shop.example.com"],"zielart":"proxy",` +
		`"ziel":"http://127.0.0.1:3000","bestaetigt":true,"zertifikat":"/etc/shadow"}`
	rec := putJSON(t, s, "/api/v1/webserver/sites/shop", koerper, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400 — das Feld darf es gar nicht geben: %s",
			rec.Code, rec.Body.String())
	}
}

// Ohne bezogenes Zertifikat entsteht kein 443-Block. Ein ssl_certificate ins
// Leere lässt nginx gar nicht erst starten, und dann ist nicht diese eine Site
// weg, sondern jede.
func TestAPISiteSchreibenOhneZertifikatKeineTLSPfade(t *testing.T) {
	s, ops, cookie, csrf := siteSchreibServer(t)

	koerper := `{"domains":["shop.example.com"],"zielart":"proxy",` +
		`"ziel":"http://127.0.0.1:3000","tls":true,"bestaetigt":true}`
	if rec := putJSON(t, s, "/api/v1/webserver/sites/shop", koerper, cookie, csrf); rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	if len(ops.siteEntwuerfe) != 1 {
		t.Fatal("nichts geschrieben")
	}
	e := ops.siteEntwuerfe[0]
	if !e.TLS {
		t.Error("der Wunsch nach TLS ging verloren")
	}
	if e.Zertifikat != "" || e.Schluessel != "" {
		t.Errorf("ohne bezogenes Zertifikat wurden Pfade gesetzt: %q / %q",
			e.Zertifikat, e.Schluessel)
	}
}

// Der Prüfer bekommt die Lage dieses Servers — Panel-Port und Datenverzeichnis.
// Ohne das Datenverzeichnis in der Sperrliste könnte eine Site die Datenbank des
// Panels ausliefern.
func TestAPISiteLageNenntPanelPortUndDatenverzeichnis(t *testing.T) {
	s, _, _, _ := siteSchreibServer(t)

	lage := s.siteLage()
	if lage.PanelPort != s.cfg.Server.Port {
		t.Errorf("PanelPort = %d, erwartet %d", lage.PanelPort, s.cfg.Server.Port)
	}
	if len(lage.GesperrtePfade) == 0 || lage.GesperrtePfade[0] != s.cfg.Paths.Data {
		t.Errorf("das Datenverzeichnis fehlt in der Sperrliste: %v", lage.GesperrtePfade)
	}
	// Die Gegenprobe an der echten Prüfung: Eine Site, die aus dem
	// Datenverzeichnis ausliefert, muss abgelehnt werden.
	p := privops.PruefeSiteEntwurf(privops.SiteEntwurf{
		Name: "leck", Domains: []string{"leck.example.com"},
		Zielart: "statisch", Ziel: s.cfg.Paths.Data,
	}, lage)
	if p.OK() {
		t.Error("eine Site auf dem Datenverzeichnis des Panels wurde angenommen")
	}
}

// Die Fehlerarten von privops kommen unterschieden an. Ohne errors.Is wären
// Ablehnung und Konflikt beide „irgendein Fehler", und die Oberfläche zeigte
// für den einen die Meldung des anderen.
func TestAPISiteSchreibenUnterscheidetDieFehlerarten(t *testing.T) {
	faelle := []struct {
		err    error
		status int
	}{
		{privops.ErrSiteAbgelehnt, http.StatusBadRequest},
		{privops.ErrSiteFassung, http.StatusConflict},
		{errors.New("nginx antwortet nicht"), http.StatusBadGateway},
	}
	for _, f := range faelle {
		s, ops, cookie, csrf := siteSchreibServer(t)
		ops.siteSchreibErr = f.err

		rec := putJSON(t, s, "/api/v1/webserver/sites/shop", entwurfKoerper, cookie, csrf)
		if rec.Code != f.status {
			t.Errorf("%v: Status = %d, erwartet %d (%s)", f.err, rec.Code, f.status, rec.Body.String())
		}
	}
}
