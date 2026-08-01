package acme

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
	"time"
)

// Aufgezeichnete Form der Antwort auf get_domains.
//
// Die Struktur (Domains als Objekt unter "subdomains", darunter "records" mit
// "praefix") ist aus der API-Dokumentation und aus zwei fremden Umsetzungen
// abgeleitet, nicht aus einem Mitschnitt eines echten Kontos. Der Vorbehalt
// gehört hierher: Wenn IPv64 etwas anderes liefert, findet das kein Test,
// sondern der erste Bezug gegen das Staging-Verzeichnis.
//
// Enthalten sind beide Fälle, die die vorhandenen Umsetzungen NICHT beide
// hinbekommen: eine IPv64-Subdomain mit drei Labels und eine eigene Domain mit
// zweien.
const ipv64DomainsOut = `{
  "subdomains": {
    "meins.ipv64.net": {"updates": 3, "wildcard": 0, "records": [
      {"praefix": "", "type": "A", "content": "192.0.2.1", "ttl": 60}
    ]},
    "example.com": {"updates": 0, "wildcard": 1, "records": [
      {"praefix": "www", "type": "A", "content": "192.0.2.2", "ttl": 60}
    ]},
    "sub.example.com": {"updates": 0, "records": []}
  },
  "info": "success",
  "status": "200 Success"
}`

// ipv64Attrappe stellt die API nach und zeichnet auf, was ankam.
type ipv64Attrappe struct {
	anfragen []ipv64Anfrage
	status   int
	antwort  string
}

type ipv64Anfrage struct {
	methode string
	abfrage string
	auth    string
	felder  url.Values
}

func (a *ipv64Attrappe) server(t *testing.T) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		b, _ := io.ReadAll(r.Body)
		felder, _ := url.ParseQuery(string(b))
		a.anfragen = append(a.anfragen, ipv64Anfrage{
			methode: r.Method,
			abfrage: r.URL.RawQuery,
			auth:    r.Header.Get("Authorization"),
			felder:  felder,
		})

		if a.status != 0 {
			w.WriteHeader(a.status)
			_, _ = io.WriteString(w, a.antwort)
			return
		}
		if r.URL.RawQuery == "get_domains" {
			_, _ = io.WriteString(w, ipv64DomainsOut)
			return
		}
		_, _ = io.WriteString(w, `{"info":"success","status":"200 Success"}`)
	}))
	t.Cleanup(srv.Close)
	return srv
}

// ipv64Gegen baut einen Setzer gegen die Attrappe — ohne echtes Warten.
func ipv64Gegen(srv *httptest.Server) *ipv64Setter {
	s := newIPv64Setter("geheimer-key")
	s.basis = srv.URL
	// Der Mindestabstand ist im Betrieb richtig und im Test nur Wartezeit. Er
	// wird nicht abgeschaltet, sondern nachgehalten: geschlafen wird in eine
	// Zählung statt in die Uhr.
	s.schlafen = func(context.Context, time.Duration) {}
	return s
}

// Der Befund aus Zoraxy #351, von beiden Seiten: Eine IPv64-Subdomain mit drei
// Labels UND eine eigene Domain mit zweien müssen richtig getrennt werden.
// lego schafft nur die erste, das certbot-Plugin nur die zweite.
func TestIPv64TrenntZoneUndPraefix(t *testing.T) {
	a := &ipv64Attrappe{}
	s := ipv64Gegen(a.server(t))
	ctx := context.Background()

	for _, fall := range []struct{ record, zone, praefix string }{
		// Eigene Domain, zwei Labels — hier scheitert lego.
		{"_acme-challenge.example.com", "example.com", "_acme-challenge"},
		// IPv64-Subdomain, drei Labels — hier scheitert das certbot-Plugin,
		// weil es „ipv64.net" als Zone nähme.
		{"_acme-challenge.meins.ipv64.net", "meins.ipv64.net", "_acme-challenge"},
		// Die LÄNGSTE passende Zone gewinnt: sub.example.com steht auch im
		// Konto, und dorthin gehört der Record.
		{"_acme-challenge.sub.example.com", "sub.example.com", "_acme-challenge"},
		// Tiefer verschachtelt.
		{"_acme-challenge.a.b.example.com", "example.com", "_acme-challenge.a.b"},
		// Und der Name der Zone selbst.
		{"example.com", "example.com", ""},
	} {
		t.Run(fall.record, func(t *testing.T) {
			zone, praefix, err := s.teile(ctx, fall.record)
			if err != nil {
				t.Fatalf("teile: %v", err)
			}
			if zone != fall.zone || praefix != fall.praefix {
				t.Errorf("Zone=%q Präfix=%q, erwartet Zone=%q Präfix=%q",
					zone, praefix, fall.zone, fall.praefix)
			}
		})
	}
}

// Eine Domain, die dem Konto nicht gehört, ist ein Fehler — und die Meldung
// nennt, was stattdessen da ist. „Keine passende Domain" ohne die Liste
// schickt jemanden ins Konto zum Nachsehen.
func TestIPv64MeldetUnbekannteDomain(t *testing.T) {
	a := &ipv64Attrappe{}
	s := ipv64Gegen(a.server(t))

	_, _, err := s.teile(context.Background(), "_acme-challenge.fremd.org")
	if err == nil {
		t.Fatal("eine fremde Domain muss ein Fehler sein")
	}
	if !strings.Contains(err.Error(), "example.com") {
		t.Errorf("die Meldung nennt die vorhandenen Domains nicht: %v", err)
	}
}

func TestIPv64SetztUndLoeschtDenRecord(t *testing.T) {
	a := &ipv64Attrappe{}
	s := ipv64Gegen(a.server(t))
	ctx := context.Background()

	if err := s.setTXT(ctx, "example.com", "_acme-challenge.example.com", "der-wert"); err != nil {
		t.Fatalf("setTXT: %v", err)
	}
	if err := s.removeTXT(ctx, "example.com", "_acme-challenge.example.com", "der-wert"); err != nil {
		t.Fatalf("removeTXT: %v", err)
	}

	if len(a.anfragen) != 3 {
		t.Fatalf("%d Anfragen, erwartet 3 (Domains, Anlegen, Löschen): %+v", len(a.anfragen), a.anfragen)
	}
	for i, an := range a.anfragen {
		if an.auth != "Bearer geheimer-key" {
			t.Errorf("Anfrage %d ohne Bearer-Token: %q", i, an.auth)
		}
	}

	anlegen := a.anfragen[1]
	if anlegen.methode != http.MethodPost {
		t.Errorf("Anlegen mit %s statt POST", anlegen.methode)
	}
	for feld, will := range map[string]string{
		"add_record": "example.com",
		"praefix":    "_acme-challenge",
		"type":       "TXT",
		"content":    "der-wert",
	} {
		if got := anlegen.felder.Get(feld); got != will {
			t.Errorf("Anlegen: %s = %q, erwartet %q", feld, got, will)
		}
	}

	loeschen := a.anfragen[2]
	if loeschen.methode != http.MethodDelete {
		t.Errorf("Löschen mit %s statt DELETE", loeschen.methode)
	}
	// Gelöscht wird nach INHALT und nicht pauschal: Bei einem
	// Wildcard-Zertifikat stehen zwei TXT-Records unter demselben Namen, und
	// der zweite gehört noch zur laufenden Prüfung.
	if got := loeschen.felder.Get("content"); got != "der-wert" {
		t.Errorf("Löschen: content = %q", got)
	}
	if got := loeschen.felder.Get("del_record"); got != "example.com" {
		t.Errorf("Löschen: del_record = %q", got)
	}
}

// Die Domainliste wird EINMAL geholt und nicht je Record. Bei 64 Anfragen am
// Tag ist das kein Feinschliff — ein Wildcard-Bezug hat vier Recordoperationen,
// und vier zusätzliche Abfragen wären ein Sechzehntel des Tagesbudgets für
// nichts.
func TestIPv64HoltDieDomainlisteNurEinmal(t *testing.T) {
	a := &ipv64Attrappe{}
	s := ipv64Gegen(a.server(t))
	ctx := context.Background()

	for range 4 {
		if err := s.setTXT(ctx, "example.com", "_acme-challenge.example.com", "wert"); err != nil {
			t.Fatal(err)
		}
	}

	var abfragen int
	for _, an := range a.anfragen {
		if an.abfrage == "get_domains" {
			abfragen++
		}
	}
	if abfragen != 1 {
		t.Errorf("%d Abfragen der Domainliste, erwartet 1", abfragen)
	}
}

// Und nach Ablauf der Frist wird sie neu geholt — eine im Konto neu angelegte
// Domain soll nicht bis zum Neustart des Panels unsichtbar bleiben.
func TestIPv64HoltDieDomainlisteNachAblaufNeu(t *testing.T) {
	a := &ipv64Attrappe{}
	s := ipv64Gegen(a.server(t))
	uhr := time.Now()
	s.jetzt = func() time.Time { return uhr }
	ctx := context.Background()

	if _, err := s.holeZonen(ctx); err != nil {
		t.Fatal(err)
	}
	uhr = uhr.Add(ipv64ZonenFrist + time.Minute)
	if _, err := s.holeZonen(ctx); err != nil {
		t.Fatal(err)
	}
	if len(a.anfragen) != 2 {
		t.Errorf("%d Abfragen, erwartet 2 (die zweite nach Ablauf der Frist)", len(a.anfragen))
	}
}

// Das Tagesbudget. Der Manager wiederholt einen gescheiterten Bezug stündlich;
// ohne Budget wäre ein falsch eingerichtetes Konto nach einem halben Tag für
// einen ganzen gesperrt — und dann ist auch der richtig eingerichtete Zustand
// nicht mehr erreichbar.
func TestIPv64HaeltDasTagesbudgetEin(t *testing.T) {
	a := &ipv64Attrappe{}
	s := ipv64Gegen(a.server(t))
	uhr := time.Now()
	s.jetzt = func() time.Time { return uhr }
	ctx := context.Background()

	var letzter error
	for i := range ipv64Tagesbudget + 5 {
		letzter = s.aufruf(ctx, http.MethodPost, url.Values{"add_record": {"example.com"}})
		if letzter != nil && i < ipv64Tagesbudget {
			t.Fatalf("Anfrage %d wurde zu früh abgelehnt: %v", i, letzter)
		}
	}
	if letzter == nil {
		t.Fatal("über dem Tagesbudget muss abgelehnt werden")
	}
	// Die Meldung nennt den Zeitpunkt — „Grenze erreicht" allein befähigt zu
	// keiner Entscheidung.
	if !strings.Contains(letzter.Error(), "Uhr") {
		t.Errorf("die Meldung nennt nicht, wann es weitergeht: %v", letzter)
	}
	if len(a.anfragen) != ipv64Tagesbudget {
		t.Errorf("%d Anfragen gingen hinaus, erwartet höchstens %d",
			len(a.anfragen), ipv64Tagesbudget)
	}

	// Nach 24 Stunden ist wieder Platz.
	uhr = uhr.Add(24*time.Hour + time.Minute)
	if err := s.aufruf(ctx, http.MethodPost, url.Values{"add_record": {"example.com"}}); err != nil {
		t.Errorf("nach 24 Stunden muss es weitergehen: %v", err)
	}
}

// Die Ratengrenze des Dienstes bekommt einen eigenen Satz. „429" allein sieht
// aus wie ein vorübergehendes Zucken, und wer das glaubt, versucht es gleich
// wieder — genau das, was hier nicht passieren soll.
func TestIPv64ErklaertDieRatengrenze(t *testing.T) {
	a := &ipv64Attrappe{status: http.StatusTooManyRequests, antwort: `{"info":"rate limit"}`}
	s := ipv64Gegen(a.server(t))

	err := s.setTXT(context.Background(), "example.com", "_acme-challenge.example.com", "wert")
	if err == nil {
		t.Fatal("429 muss ein Fehler sein")
	}
	if !strings.Contains(err.Error(), "24 Stunden") {
		t.Errorf("die Meldung erklärt die Grenze nicht: %v", err)
	}
}

// Der Mindestabstand hält „5 Anfragen in 10 Sekunden" ein. Geprüft wird, DASS
// gewartet wird, nicht wie lange — die Zeit selbst ist eine Konstante.
func TestIPv64HaeltDenAbstandEin(t *testing.T) {
	a := &ipv64Attrappe{}
	s := ipv64Gegen(a.server(t))
	uhr := time.Now()
	s.jetzt = func() time.Time { return uhr }

	var pausen []time.Duration
	s.schlafen = func(_ context.Context, d time.Duration) { pausen = append(pausen, d) }
	ctx := context.Background()

	for range 3 {
		if err := s.aufruf(ctx, http.MethodPost, url.Values{"add_record": {"example.com"}}); err != nil {
			t.Fatal(err)
		}
	}
	// Die erste Anfrage wartet nicht, die beiden folgenden schon.
	if len(pausen) != 2 {
		t.Fatalf("%d Pausen, erwartet 2: %v", len(pausen), pausen)
	}
	// Die Uhr steht im Test still. Jede Anfrage bucht sich deshalb ihren
	// eigenen Platz hinter der vorigen, und die Pausen wachsen: 2,5 s und 5 s.
	// Im Betrieb läuft die Uhr mit, und dann schrumpfen sie auf das, was
	// wirklich fehlt. Geprüft wird die Staffelung — dass die dritte Anfrage
	// nicht denselben Platz nimmt wie die zweite.
	if pausen[0] != ipv64Abstand {
		t.Errorf("erste Pause = %v, erwartet %v", pausen[0], ipv64Abstand)
	}
	if pausen[1] != 2*ipv64Abstand {
		t.Errorf("zweite Pause = %v, erwartet %v — sonst warten beide auf "+
			"denselben Platz und stürmen dann gemeinsam los", pausen[1], 2*ipv64Abstand)
	}
}

func TestIPv64BautAusDerZugangsdatei(t *testing.T) {
	for _, inhalt := range []string{"nur-der-key\n", "api_key = nur-der-key\n"} {
		setter, err := newDNSSetter(Options{
			DNS01Provider: providerIPv64,
			ZugangsDatei:  zugangsdatei(t, inhalt, 0o600),
		})
		if err != nil {
			t.Fatalf("newDNSSetter(%q): %v", inhalt, err)
		}
		s, ok := setter.(*ipv64Setter)
		if !ok {
			t.Fatalf("erwartet ipv64Setter, bekam %T", setter)
		}
		if s.key != "nur-der-key" {
			t.Errorf("Key = %q", s.key)
		}
	}
}
