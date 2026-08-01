package acme

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// Hetzner und DigitalOcean teilen sich Bauart und Prüfstand: REST mit Token,
// Zone abfragen, Record anlegen, Record nach Name UND Wert löschen.
//
// Derselbe Vorbehalt wie bei allen Anbietern: Die Antwortformen stammen aus der
// Dokumentation, nicht aus einem Mitschnitt. Was hier geprüft wird, ist, was
// das Panel SCHICKT — und das ist die Hälfte, die ein Mitschnitt nicht besser
// machen würde.

// restAttrappe zeichnet die Anfragen auf und antwortet nach Wegweiser.
type restAttrappe struct {
	anfragen []restAnfrage
	// antworten bildet "METHODE /pfad" auf den Körper ab. Der Pfad wird als
	// Präfix verglichen, damit Abfrageparameter nicht mit aufgezählt werden
	// müssen.
	antworten map[string]string
	status    int
}

type restAnfrage struct {
	methode string
	pfad    string
	abfrage string
	kopf    http.Header
	koerper map[string]any
}

func (a *restAttrappe) server(t *testing.T) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b, _ := io.ReadAll(r.Body)
		an := restAnfrage{
			methode: r.Method,
			pfad:    r.URL.Path,
			abfrage: r.URL.RawQuery,
			kopf:    r.Header.Clone(),
		}
		_ = json.Unmarshal(b, &an.koerper)
		a.anfragen = append(a.anfragen, an)

		if a.status != 0 {
			w.WriteHeader(a.status)
			_, _ = io.WriteString(w, `{"message":"kaputt"}`)
			return
		}
		if koerper, ok := laengsterTreffer(a.antworten, r.Method, r.URL.Path); ok {
			_, _ = io.WriteString(w, koerper)
			return
		}
		_, _ = io.WriteString(w, `{}`)
	}))
	t.Cleanup(srv.Close)
	return srv
}

func (a *restAttrappe) finde(methode, pfad string) *restAnfrage {
	for i := range a.anfragen {
		if a.anfragen[i].methode == methode && strings.HasPrefix(a.anfragen[i].pfad, pfad) {
			return &a.anfragen[i]
		}
	}
	return nil
}

// ------------------------------------------------------------- Hetzner ---

func TestHetznerLegtDenRecordAn(t *testing.T) {
	a := &restAttrappe{antworten: map[string]string{
		"GET /zones": `{"zones":[{"id":"z1","name":"example.com"}]}`,
	}}
	s := newHetznerSetter("geheim")
	s.basis = a.server(t).URL

	err := s.setTXT(context.Background(), "example.com", "_acme-challenge.example.com", "der-wert")
	if err != nil {
		t.Fatalf("setTXT: %v", err)
	}

	an := a.finde(http.MethodPost, "/records")
	if an == nil {
		t.Fatalf("kein Anlegen: %+v", a.anfragen)
	}
	if an.kopf.Get("Auth-API-Token") != "geheim" {
		t.Errorf("Token fehlt in der Kopfzeile: %q", an.kopf.Get("Auth-API-Token"))
	}
	// Der Name RELATIV zur Zone. Ein absoluter Name landet sonst als
	// "_acme-challenge.example.com.example.com" — der Record entsteht ohne
	// Fehlermeldung, und die Prüfung findet ihn trotzdem nie.
	if an.koerper["name"] != "_acme-challenge" {
		t.Errorf("Name = %v, erwartet _acme-challenge (relativ zur Zone)", an.koerper["name"])
	}
	if an.koerper["value"] != "der-wert" || an.koerper["zone_id"] != "z1" {
		t.Errorf("Anfrage falsch: %+v", an.koerper)
	}
}

// Gelöscht wird nach Name UND Wert. Bei einem Wildcard-Zertifikat stehen zwei
// TXT-Records unter demselben Namen, und der zweite gehört noch zur laufenden
// Prüfung — wer ihn mitlöscht, bringt den eigenen Bezug zum Scheitern.
func TestHetznerLoeschtNurDenEigenenWert(t *testing.T) {
	a := &restAttrappe{antworten: map[string]string{
		"GET /zones": `{"zones":[{"id":"z1","name":"example.com"}]}`,
		"GET /records": `{"records":[
			{"id":"r1","type":"TXT","name":"_acme-challenge","value":"meiner"},
			{"id":"r2","type":"TXT","name":"_acme-challenge","value":"der andere"},
			{"id":"r3","type":"A","name":"_acme-challenge","value":"meiner"}
		]}`,
	}}
	s := newHetznerSetter("geheim")
	s.basis = a.server(t).URL

	if err := s.removeTXT(context.Background(), "example.com", "_acme-challenge.example.com", "meiner"); err != nil {
		t.Fatalf("removeTXT: %v", err)
	}

	var geloescht []string
	for _, an := range a.anfragen {
		if an.methode == http.MethodDelete {
			geloescht = append(geloescht, strings.TrimPrefix(an.pfad, "/records/"))
		}
	}
	if len(geloescht) != 1 || geloescht[0] != "r1" {
		t.Errorf("gelöscht wurde %v, erwartet genau r1", geloescht)
	}
}

// Manche APIs geben den TXT-Wert mit Anführungszeichen zurück, so wie er in der
// Zonendatei steht. Wird das nicht beachtet, findet das Löschen den eigenen
// Record nie — und er bleibt stehen.
func TestHetznerFindetDenWertAuchInAnfuehrungszeichen(t *testing.T) {
	a := &restAttrappe{antworten: map[string]string{
		"GET /zones":   `{"zones":[{"id":"z1","name":"example.com"}]}`,
		"GET /records": `{"records":[{"id":"r1","type":"TXT","name":"_acme-challenge","value":"\"meiner\""}]}`,
	}}
	s := newHetznerSetter("geheim")
	s.basis = a.server(t).URL

	if err := s.removeTXT(context.Background(), "example.com", "_acme-challenge.example.com", "meiner"); err != nil {
		t.Fatalf("removeTXT: %v", err)
	}
	if a.finde(http.MethodDelete, "/records/") == nil {
		t.Error("der Record mit Anführungszeichen wurde nicht wiedererkannt")
	}
}

func TestHetznerZoneVonSpezifischNachAllgemein(t *testing.T) {
	// Die Zone example.com gibt es, sub.example.com nicht. Die Suche muss
	// deshalb weitergehen statt beim ersten Fehlschlag aufzugeben.
	a := &restAttrappe{antworten: map[string]string{
		"GET /zones": `{"zones":[]}`,
	}}
	srv := a.server(t)
	s := newHetznerSetter("geheim")
	s.basis = srv.URL

	_, err := s.zone(context.Background(), "a.b.example.com")
	if err == nil {
		t.Fatal("ohne passende Zone muss ein Fehler kommen")
	}
	// Vier Kandidaten: a.b.example.com, b.example.com, example.com — und nicht
	// „com", weil eine einzelne Endung keine Zone eines Kontos ist.
	if len(a.anfragen) != 3 {
		t.Errorf("%d Zonenabfragen, erwartet 3: %+v", len(a.anfragen), a.anfragen)
	}
}

func TestHetznerMeldetFehlerDerAPI(t *testing.T) {
	a := &restAttrappe{status: http.StatusUnauthorized}
	s := newHetznerSetter("falsch")
	s.basis = a.server(t).URL

	err := s.setTXT(context.Background(), "example.com", "_acme-challenge.example.com", "wert")
	if err == nil {
		t.Fatal("401 muss ein Fehler sein")
	}
	if !strings.Contains(err.Error(), "401") {
		t.Errorf("die Meldung nennt den Status nicht: %v", err)
	}
}

// -------------------------------------------------------- DigitalOcean ---

func TestDigitalOceanLegtDenRecordAn(t *testing.T) {
	a := &restAttrappe{antworten: map[string]string{
		"GET /domains": `{"domains":[{"name":"example.com"}],"links":{}}`,
	}}
	s := newDigitalOceanSetter("geheim")
	s.basis = a.server(t).URL

	err := s.setTXT(context.Background(), "example.com", "_acme-challenge.example.com", "der-wert")
	if err != nil {
		t.Fatalf("setTXT: %v", err)
	}

	an := a.finde(http.MethodPost, "/domains/example.com/records")
	if an == nil {
		t.Fatalf("kein Anlegen: %+v", a.anfragen)
	}
	if an.kopf.Get("Authorization") != "Bearer geheim" {
		t.Errorf("Token fehlt: %q", an.kopf.Get("Authorization"))
	}
	if an.koerper["name"] != "_acme-challenge" || an.koerper["data"] != "der-wert" {
		t.Errorf("Anfrage falsch: %+v", an.koerper)
	}
}

// Die Domainliste ist seitenweise. Wer nur die erste Seite liest, findet die
// Domain auf Seite zwei nie und meldet „nicht gefunden" für etwas, das da ist.
func TestDigitalOceanBlaettertDieDomainlisteDurch(t *testing.T) {
	seiten := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasPrefix(r.URL.Path, "/domains") || r.Method != http.MethodGet {
			_, _ = io.WriteString(w, `{}`)
			return
		}
		seiten++
		if seiten == 1 {
			// Erste Seite: eine andere Domain, und ein Verweis auf die nächste.
			_, _ = io.WriteString(w, `{"domains":[{"name":"andere.org"}],
				"links":{"pages":{"next":"https://api.digitalocean.com/v2/domains?page=2"}}}`)
			return
		}
		_, _ = io.WriteString(w, `{"domains":[{"name":"example.com"}],"links":{}}`)
	}))
	t.Cleanup(srv.Close)

	s := newDigitalOceanSetter("geheim")
	s.basis = srv.URL

	zone, err := s.zone(context.Background(), "_acme-challenge.example.com")
	if err != nil {
		t.Fatalf("zone: %v", err)
	}
	if zone != "example.com" {
		t.Errorf("Zone = %q", zone)
	}
	if seiten != 2 {
		t.Errorf("%d Seiten gelesen, erwartet 2", seiten)
	}
}

// Die längste passende Domain gewinnt: Führt jemand sowohl example.com als
// auch sub.example.com, gehört der Record in die spezifischere.
func TestDigitalOceanNimmtDieLaengstePassendeDomain(t *testing.T) {
	a := &restAttrappe{antworten: map[string]string{
		"GET /domains": `{"domains":[{"name":"example.com"},{"name":"sub.example.com"}],"links":{}}`,
	}}
	s := newDigitalOceanSetter("geheim")
	s.basis = a.server(t).URL

	zone, err := s.zone(context.Background(), "_acme-challenge.sub.example.com")
	if err != nil {
		t.Fatalf("zone: %v", err)
	}
	if zone != "sub.example.com" {
		t.Errorf("Zone = %q, erwartet sub.example.com", zone)
	}
}

func TestDigitalOceanLoeschtNurDenEigenenWert(t *testing.T) {
	a := &restAttrappe{antworten: map[string]string{
		"GET /domains/example.com/records": `{"domain_records":[
			{"id":1,"type":"TXT","name":"_acme-challenge","data":"meiner"},
			{"id":2,"type":"TXT","name":"_acme-challenge","data":"der andere"}
		]}`,
		"GET /domains": `{"domains":[{"name":"example.com"}],"links":{}}`,
	}}
	s := newDigitalOceanSetter("geheim")
	s.basis = a.server(t).URL

	if err := s.removeTXT(context.Background(), "example.com", "_acme-challenge.example.com", "meiner"); err != nil {
		t.Fatalf("removeTXT: %v", err)
	}

	var geloescht []string
	for _, an := range a.anfragen {
		if an.methode == http.MethodDelete {
			geloescht = append(geloescht, an.pfad)
		}
	}
	if len(geloescht) != 1 || !strings.HasSuffix(geloescht[0], "/records/1") {
		t.Errorf("gelöscht wurde %v, erwartet genau Record 1", geloescht)
	}
}

// ------------------------------------------------------------ gemeinsam ---

func TestRelativZu(t *testing.T) {
	for _, fall := range []struct{ record, zone, will string }{
		{"_acme-challenge.example.com", "example.com", "_acme-challenge"},
		{"_acme-challenge.a.b.example.com", "example.com", "_acme-challenge.a.b"},
		{"example.com", "example.com", "@"},
		{"_acme-challenge.example.com.", "example.com", "_acme-challenge"},
		{"_ACME-Challenge.Example.COM", "example.com", "_acme-challenge"},
		// Passt nicht zur Zone: unverändert lassen und die API entscheiden
		// lassen. Hier zu raten wäre schlimmer als ihre Fehlermeldung.
		{"_acme-challenge.fremd.org", "example.com", "_acme-challenge.fremd.org"},
	} {
		if got := relativZu(fall.record, fall.zone); got != fall.will {
			t.Errorf("relativZu(%q, %q) = %q, erwartet %q",
				fall.record, fall.zone, got, fall.will)
		}
	}
}

func TestGleicherTXTWert(t *testing.T) {
	if !gleicherTXTWert(`"abc"`, "abc") {
		t.Error("Anführungszeichen dürfen den Vergleich nicht kippen")
	}
	if gleicherTXTWert("abc", "abd") {
		t.Error("verschiedene Werte dürfen nicht gleich sein")
	}
}

func TestRestAnbieterImRegister(t *testing.T) {
	for _, name := range []string{providerHetzner, providerDigitalOcean} {
		if !AnbieterBekannt(name) {
			t.Errorf("%q fehlt im Register", name)
		}
		setter, err := newDNSSetter(Options{
			DNS01Provider: name,
			ZugangsDatei:  zugangsdatei(t, "der-token\n", 0o600),
		})
		if err != nil {
			t.Errorf("%q ließ sich nicht bauen: %v", name, err)
		}
		if setter == nil {
			t.Errorf("%q lieferte keinen Setzer", name)
		}
	}
}

// laengsterTreffer wählt aus einer Wegweisertabelle den Eintrag mit dem
// LÄNGSTEN passenden Pfad.
//
// Ohne das entscheidet die Reihenfolge einer Map — also der Zufall —, ob
// "GET /domains" oder "GET /domains/example.com/records" antwortet. Ein Test,
// der mal grün und mal rot ist, prüft nichts; und einer, der zufällig grün
// bleibt, prüft etwas anderes als gedacht. Gefunden hat das der OVH-Test, dem
// die Zonenliste als Record-Antwort untergeschoben wurde.
func laengsterTreffer(antworten map[string]string, methode, pfad string) (string, bool) {
	beste, gefunden := "", ""
	for wegweiser, koerper := range antworten {
		m, p, _ := strings.Cut(wegweiser, " ")
		if m != methode || !strings.HasPrefix(pfad, p) {
			continue
		}
		if len(p) > len(beste) {
			beste, gefunden = p, koerper
		}
	}
	return gefunden, beste != ""
}
