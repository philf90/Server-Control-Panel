package acme

import (
	"context"
	"crypto/sha1" //nolint:gosec // prüft die von OVH vorgeschriebene Signatur nach
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
	"time"
)

// netcup und OVH sind die beiden aufwendigsten Anbieter der Liste, und beide
// aus einem Grund, der sich testen lässt: netcup hat eine Sitzung und antwortet
// auf Fehler mit HTTP 200, OVH signiert jeden Aufruf.
//
// Derselbe Vorbehalt wie überall: Die Antwortformen stammen aus der
// Dokumentation, nicht aus einem Mitschnitt.

// --------------------------------------------------------------- netcup ---

// netcupAttrappe stellt den CCP-Endpunkt nach. Alle Aufrufe gehen an dieselbe
// Adresse; das Kommando steht im Körper.
type netcupAttrappe struct {
	aktionen []string
	param    []map[string]any
	// fehler bildet eine Aktion auf eine abschlägige Antwort ab.
	fehler map[string]string
	// daten bildet eine Aktion auf die Nutzdaten ab.
	daten map[string]string
}

func (a *netcupAttrappe) server(t *testing.T) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var anfrage struct {
			Action string         `json:"action"`
			Param  map[string]any `json:"param"`
		}
		b, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(b, &anfrage)
		a.aktionen = append(a.aktionen, anfrage.Action)
		a.param = append(a.param, anfrage.Param)

		// netcup antwortet IMMER mit 200 — auch im Fehlerfall.
		if meldung, kaputt := a.fehler[anfrage.Action]; kaputt {
			_, _ = fmt.Fprintf(w, `{"status":"error","statuscode":4001,"longmessage":%q}`, meldung)
			return
		}
		nutz := a.daten[anfrage.Action]
		if nutz == "" {
			nutz = "{}"
		}
		_, _ = fmt.Fprintf(w, `{"status":"success","statuscode":2000,"responsedata":%s}`, nutz)
	}))
	t.Cleanup(srv.Close)
	return srv
}

func netcupGegen(srv *httptest.Server) *netcupSetter {
	s := newNetcupSetter("12345", "schluessel", "passwort")
	s.basis = srv.URL
	return s
}

func TestNetcupLegtDenRecordAn(t *testing.T) {
	a := &netcupAttrappe{daten: map[string]string{
		"login":          `{"apisessionid":"sitzung-1"}`,
		"listallDomains": `{"domains":[{"domainname":"example.com"}]}`,
	}}
	s := netcupGegen(a.server(t))

	err := s.setTXT(context.Background(), "example.com", "_acme-challenge.example.com", "der-wert")
	if err != nil {
		t.Fatalf("setTXT: %v", err)
	}

	if len(a.aktionen) < 3 || a.aktionen[0] != "login" {
		t.Fatalf("erwartet zuerst login, bekam %v", a.aktionen)
	}
	var schreiben map[string]any
	for i, ak := range a.aktionen {
		if ak == "updateDnsRecords" {
			schreiben = a.param[i]
		}
	}
	if schreiben == nil {
		t.Fatalf("kein Schreibaufruf: %v", a.aktionen)
	}
	if schreiben["domainname"] != "example.com" {
		t.Errorf("Domain = %v", schreiben["domainname"])
	}
	// Die Sitzung geht bei jedem Aufruf mit — ohne sie lehnt netcup ab.
	if schreiben["apisessionid"] != "sitzung-1" {
		t.Errorf("Sitzung fehlt: %v", schreiben["apisessionid"])
	}

	// GENAU EIN Record geht hinaus. updateDnsRecords nimmt eine Liste, und wer
	// dort mehr hineinlegt als nötig, kann fremde Records überschreiben.
	satz, _ := schreiben["dnsrecordset"].(map[string]any)
	records, _ := satz["dnsrecords"].([]any)
	if len(records) != 1 {
		t.Fatalf("%d Records geschickt, erwartet genau 1: %+v", len(records), records)
	}
	eintrag, _ := records[0].(map[string]any)
	if eintrag["hostname"] != "_acme-challenge" || eintrag["destination"] != "der-wert" {
		t.Errorf("Record falsch: %+v", eintrag)
	}
	if _, loeschen := eintrag["deleterecord"]; loeschen {
		t.Errorf("beim Anlegen darf kein deleterecord stehen: %+v", eintrag)
	}
}

// Die wichtigste Prüfung für netcup: Ein Fehler kommt mit HTTP 200. Wer nur
// den Statuscode ansieht, hält jeden Fehlschlag für einen Erfolg — und der
// Bezug scheitert später an einer Stelle, die nichts damit zu tun hat.
func TestNetcupErkenntFehlerTrotzHTTP200(t *testing.T) {
	a := &netcupAttrappe{
		daten:  map[string]string{"login": `{"apisessionid":"sitzung-1"}`},
		fehler: map[string]string{"listallDomains": "Api session id not valid."},
	}
	s := netcupGegen(a.server(t))

	err := s.setTXT(context.Background(), "example.com", "_acme-challenge.example.com", "wert")
	if err == nil {
		t.Fatal("ein Fehler mit HTTP 200 muss trotzdem ein Fehler sein")
	}
	if !strings.Contains(err.Error(), "session id not valid") {
		t.Errorf("die Meldung von netcup fehlt: %v", err)
	}
}

// Die Sitzung wird wiederverwendet. Ein Login je Record wären bei einem
// Wildcard-Bezug vier Anmeldungen für vier Records.
func TestNetcupMeldetSichNurEinmalAn(t *testing.T) {
	a := &netcupAttrappe{daten: map[string]string{
		"login":          `{"apisessionid":"sitzung-1"}`,
		"listallDomains": `{"domains":[{"domainname":"example.com"}]}`,
	}}
	s := netcupGegen(a.server(t))
	ctx := context.Background()

	for range 3 {
		if err := s.setTXT(ctx, "example.com", "_acme-challenge.example.com", "wert"); err != nil {
			t.Fatal(err)
		}
	}
	var logins int
	for _, ak := range a.aktionen {
		if ak == "login" {
			logins++
		}
	}
	if logins != 1 {
		t.Errorf("%d Anmeldungen, erwartet 1", logins)
	}
}

// Gelöscht wird nach Name UND Wert, und nur mit der ID des eigenen Records.
func TestNetcupLoeschtNurDenEigenenRecord(t *testing.T) {
	a := &netcupAttrappe{daten: map[string]string{
		"login":          `{"apisessionid":"sitzung-1"}`,
		"listallDomains": `{"domains":[{"domainname":"example.com"}]}`,
		"infoDnsRecords": `{"dnsrecords":[
			{"id":"11","hostname":"_acme-challenge","type":"TXT","destination":"meiner"},
			{"id":"12","hostname":"_acme-challenge","type":"TXT","destination":"der andere"}
		]}`,
	}}
	s := netcupGegen(a.server(t))

	if err := s.removeTXT(context.Background(), "example.com", "_acme-challenge.example.com", "meiner"); err != nil {
		t.Fatalf("removeTXT: %v", err)
	}
	var eintrag map[string]any
	for i, ak := range a.aktionen {
		if ak != "updateDnsRecords" {
			continue
		}
		satz, _ := a.param[i]["dnsrecordset"].(map[string]any)
		records, _ := satz["dnsrecords"].([]any)
		eintrag, _ = records[0].(map[string]any)
	}
	if eintrag == nil {
		t.Fatalf("kein Löschaufruf: %v", a.aktionen)
	}
	if eintrag["id"] != "11" {
		t.Errorf("gelöscht werden sollte Record 11, geschickt wurde %v", eintrag["id"])
	}
	if eintrag["deleterecord"] != true {
		t.Errorf("deleterecord fehlt: %+v", eintrag)
	}
}

// Gibt es den Record nicht, ist das kein Fehler: cleanup läuft auch auf dem
// Weg heraus aus einem gescheiterten Bezug.
func TestNetcupAufraeumenOhneRecordIstKeinFehler(t *testing.T) {
	a := &netcupAttrappe{daten: map[string]string{
		"login":          `{"apisessionid":"sitzung-1"}`,
		"listallDomains": `{"domains":[{"domainname":"example.com"}]}`,
		"infoDnsRecords": `{"dnsrecords":[]}`,
	}}
	s := netcupGegen(a.server(t))

	if err := s.removeTXT(context.Background(), "example.com", "_acme-challenge.example.com", "weg"); err != nil {
		t.Errorf("ein fehlender Record darf kein Fehler sein: %v", err)
	}
	for _, ak := range a.aktionen {
		if ak == "updateDnsRecords" {
			t.Error("ohne zu löschenden Record darf nichts geschrieben werden")
		}
	}
}

// ------------------------------------------------------------------ OVH ---

type ovhAttrappe struct {
	anfragen []ovhAnfrage
	// zeit ist die Serverzeit, die /auth/time liefert.
	zeit int64
	// antworten bildet "METHODE /pfad" auf den Körper ab (Pfad als Präfix).
	antworten map[string]string
}

type ovhAnfrage struct {
	methode string
	pfad    string
	kopf    http.Header
	koerper string
}

func (a *ovhAttrappe) server(t *testing.T) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b, _ := io.ReadAll(r.Body)
		a.anfragen = append(a.anfragen, ovhAnfrage{
			methode: r.Method,
			pfad:    r.URL.Path,
			kopf:    r.Header.Clone(),
			koerper: string(b),
		})

		if strings.HasSuffix(r.URL.Path, "/auth/time") {
			_, _ = io.WriteString(w, strconv.FormatInt(a.zeit, 10))
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

func TestOVHSigniertUndSetztDenRecord(t *testing.T) {
	// Die Uhr des Servers geht zehn Minuten vor. Genau dieser Fall bringt jeden
	// Aufruf zum Scheitern, wenn der Versatz nicht ermittelt wird — und die
	// Fehlermeldung von OVH lautet dann „invalid signature" und zeigt in die
	// falsche Richtung.
	eigen := time.Now()
	a := &ovhAttrappe{
		zeit: eigen.Add(10 * time.Minute).Unix(),
		antworten: map[string]string{
			"GET /1.0/domain/zone": `["example.com"]`,
		},
	}
	srv := a.server(t)
	s := newOVHSetter(srv.URL+"/1.0", "appkey", "appgeheim", "consumer")
	s.jetzt = func() time.Time { return eigen }

	if err := s.setTXT(context.Background(), "example.com", "_acme-challenge.example.com", "der-wert"); err != nil {
		t.Fatalf("setTXT: %v", err)
	}

	var anlegen *ovhAnfrage
	for i := range a.anfragen {
		if a.anfragen[i].methode == http.MethodPost && strings.HasSuffix(a.anfragen[i].pfad, "/record") {
			anlegen = &a.anfragen[i]
		}
	}
	if anlegen == nil {
		t.Fatalf("kein Anlegen: %+v", a.anfragen)
	}

	// Der Zeitstempel folgt der SERVERZEIT, nicht der eigenen Uhr.
	stempel := anlegen.kopf.Get("X-Ovh-Timestamp")
	gesendet, err := strconv.ParseInt(stempel, 10, 64)
	if err != nil {
		t.Fatalf("Zeitstempel %q nicht lesbar", stempel)
	}
	if abstand := gesendet - eigen.Unix(); abstand < 9*60 {
		t.Errorf("der Zeitstempel folgt der eigenen Uhr statt der Serverzeit (Abstand %ds)", abstand)
	}

	// Und die Signatur ist genau die, die OVH nachrechnet. Die Reihenfolge der
	// Bestandteile ist vorgegeben; ein vertauschtes Paar ergibt eine gültig
	// aussehende Signatur, die abgelehnt wird.
	roh := strings.Join([]string{
		"appgeheim", "consumer", http.MethodPost,
		srv.URL + "/1.0/domain/zone/example.com/record",
		anlegen.koerper, stempel,
	}, "+")
	summe := sha1.Sum([]byte(roh)) //nolint:gosec // von der OVH-API vorgeschrieben
	will := "$1$" + fmt.Sprintf("%x", summe)
	if got := anlegen.kopf.Get("X-Ovh-Signature"); got != will {
		t.Errorf("Signatur = %q, erwartet %q", got, will)
	}
	if anlegen.kopf.Get("X-Ovh-Application") != "appkey" {
		t.Errorf("Anwendungsschlüssel fehlt: %q", anlegen.kopf.Get("X-Ovh-Application"))
	}
}

// Ohne refresh bleibt der Record in der API stehen und geht nie ins DNS. Der
// Aufruf glückt, der Record steht in der Oberfläche, und die Prüfung findet
// trotzdem nichts — die Eigenheit, die bei OVH am meisten Zeit kostet.
func TestOVHRuftRefreshAuf(t *testing.T) {
	a := &ovhAttrappe{zeit: time.Now().Unix(), antworten: map[string]string{
		"GET /1.0/domain/zone": `["example.com"]`,
	}}
	srv := a.server(t)
	s := newOVHSetter(srv.URL+"/1.0", "k", "g", "c")

	if err := s.setTXT(context.Background(), "example.com", "_acme-challenge.example.com", "wert"); err != nil {
		t.Fatal(err)
	}
	var refresh bool
	for _, an := range a.anfragen {
		if an.methode == http.MethodPost && strings.HasSuffix(an.pfad, "/refresh") {
			refresh = true
		}
	}
	if !refresh {
		t.Error("nach dem Anlegen fehlt der refresh — der Record geht nie ins DNS")
	}
}

// Die Serverzeit wird EINMAL geholt. Eine Abfrage je Aufruf wäre die doppelte
// Anzahl Anfragen für eine Zahl, die sich nicht ändert.
func TestOVHEichtDieUhrNurEinmal(t *testing.T) {
	a := &ovhAttrappe{zeit: time.Now().Unix(), antworten: map[string]string{
		"GET /1.0/domain/zone": `["example.com"]`,
	}}
	srv := a.server(t)
	s := newOVHSetter(srv.URL+"/1.0", "k", "g", "c")
	ctx := context.Background()

	for range 3 {
		if err := s.setTXT(ctx, "example.com", "_acme-challenge.example.com", "wert"); err != nil {
			t.Fatal(err)
		}
	}
	var eichungen int
	for _, an := range a.anfragen {
		if strings.HasSuffix(an.pfad, "/auth/time") {
			eichungen++
		}
	}
	if eichungen != 1 {
		t.Errorf("%d Zeitabfragen, erwartet 1", eichungen)
	}
}

func TestOVHLoeschtNurDenEigenenWert(t *testing.T) {
	a := &ovhAttrappe{zeit: time.Now().Unix(), antworten: map[string]string{
		"GET /1.0/domain/zone/example.com/record/11": `{"target":"meiner"}`,
		"GET /1.0/domain/zone/example.com/record/12": `{"target":"der andere"}`,
		"GET /1.0/domain/zone/example.com/record":    `[11,12]`,
		"GET /1.0/domain/zone":                       `["example.com"]`,
	}}
	srv := a.server(t)
	s := newOVHSetter(srv.URL+"/1.0", "k", "g", "c")

	if err := s.removeTXT(context.Background(), "example.com", "_acme-challenge.example.com", "meiner"); err != nil {
		t.Fatalf("removeTXT: %v", err)
	}
	var geloescht []string
	for _, an := range a.anfragen {
		if an.methode == http.MethodDelete {
			geloescht = append(geloescht, an.pfad)
		}
	}
	if len(geloescht) != 1 || !strings.HasSuffix(geloescht[0], "/record/11") {
		t.Errorf("gelöscht wurde %v, erwartet genau Record 11", geloescht)
	}
}

// Der Endpunkt ist Teil der Zugangsdaten und wird gegen eine feste Liste
// geprüft. Ein freies Feld wäre die Stelle, an der die Zugangsdaten dieses
// Kontos an einen fremden Server gingen.
func TestOVHPrueftDenEndpunkt(t *testing.T) {
	_, err := newDNSSetter(Options{
		DNS01Provider: providerOVH,
		ZugangsDatei: zugangsdatei(t, `
endpoint = https://boese.example.org/
application_key = k
application_secret = g
consumer_key = c
`, 0o600),
	})
	if err == nil {
		t.Fatal("eine beliebige Adresse darf nicht als Endpunkt durchgehen")
	}
	if !strings.Contains(err.Error(), "ovh-eu") {
		t.Errorf("die Meldung nennt die gültigen Endpunkte nicht: %v", err)
	}
}

// Ohne Angabe gilt die europäische Welt — der Fall, den fast alle haben.
func TestOVHVorgabeIstEuropa(t *testing.T) {
	setter, err := newDNSSetter(Options{
		DNS01Provider: providerOVH,
		ZugangsDatei: zugangsdatei(t, `
application_key = k
application_secret = g
consumer_key = c
`, 0o600),
	})
	if err != nil {
		t.Fatalf("newDNSSetter: %v", err)
	}
	o, ok := setter.(*ovhSetter)
	if !ok {
		t.Fatalf("erwartet ovhSetter, bekam %T", setter)
	}
	if o.basis != ovhEndpunkte["ovh-eu"] {
		t.Errorf("Basis = %q, erwartet %q", o.basis, ovhEndpunkte["ovh-eu"])
	}
}

func TestNetcupUndOVHVerlangenIhreFelder(t *testing.T) {
	for _, fall := range []struct{ anbieter, fehlt string }{
		{providerNetcup, "apipassword"},
		{providerOVH, "consumer_key"},
	} {
		_, err := newDNSSetter(Options{
			DNS01Provider: fall.anbieter,
			ZugangsDatei:  zugangsdatei(t, "customer = 1\napikey = k\napplication_key = k\napplication_secret = g\n", 0o600),
		})
		if err == nil {
			t.Errorf("%s ohne %s muss scheitern", fall.anbieter, fall.fehlt)
			continue
		}
		if !strings.Contains(err.Error(), fall.fehlt) {
			t.Errorf("%s: die Meldung nennt %q nicht: %v", fall.anbieter, fall.fehlt, err)
		}
	}
}
