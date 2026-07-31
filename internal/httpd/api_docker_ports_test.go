package httpd

import (
	"encoding/json"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// Portübersicht und Ereignisstrom — Schritt 6 aus docs/17-docker.md.
//
// Der Kern ist EIN Urteil: Ein Container, der auf 0.0.0.0 veröffentlicht, ist
// aus dem Netz erreichbar — auch wenn ufw läuft und den Port nicht kennt. Docker
// trägt seine Weiterleitungen vor den Ketten der Firewall ein. Ein Panel, das
// hier „blockiert" meldete, wäre schlimmer als eines ohne diese Seite.

// portServer stellt Container mit Veröffentlichungen und eine Firewall bereit.
func portServer(t *testing.T, ufwAktiv bool, regeln []privops.FirewallRule) (*Server, *fakeOps, *http.Cookie) {
	t.Helper()
	s, ops, cookie, _ := dockerServer(t, store.RoleOwner, privops.DockerState{
		Installiert: true, DaemonLaeuft: true, ComposeVerfuegbar: true,
	})
	ops.container = []privops.Container{
		{
			ID: "aaaa11112222", Name: "web-proxy-1", Image: "nginx:alpine", Zustand: "running",
			Ports: "0.0.0.0:8080->80/tcp, :::8080->80/tcp", Stack: "web", Dienst: "proxy",
		},
		{
			ID: "bbbb11112222", Name: "web-db-1", Image: "postgres:16", Zustand: "running",
			Ports: "127.0.0.1:5432->5432/tcp", Stack: "web", Dienst: "db",
		},
		{
			ID: "cccc11112222", Name: "cache", Image: "redis", Zustand: "running",
			Ports: "0.0.0.0:6379->6379/tcp",
		},
		// Gestoppt: belegt keinen Port. Die Ports-Spalte trägt bei ihm trotzdem
		// noch die alte Angabe.
		{
			ID: "dddd11112222", Name: "alt", Image: "alpine", Zustand: "exited",
			Ports: "0.0.0.0:9999->9999/tcp",
		},
	}
	ops.firewall = privops.FirewallState{
		Backend: privops.BackendUFW, Active: ufwAktiv, Installed: true, Managed: true,
		Rules: regeln,
	}
	return s, ops, cookie
}

func portliste(t *testing.T, s *Server, cookie *http.Cookie) apiPortliste {
	t.Helper()
	rec := get(t, s, "/api/v1/docker/ports", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	var antwort apiPortliste
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	return antwort
}

// Der Kern der Seite: Ein Port, den ufw nicht kennt, ist trotzdem offen. Das
// muss dastehen, und zwar als Befund und nicht als Beruhigung.
func TestAPIPortsMeldetWasUfwNichtKennt(t *testing.T) {
	s, _, cookie := portServer(t, true, []privops.FirewallRule{
		{Port: 22, Protocol: "tcp"},
		{Port: 8080, Protocol: "tcp", Comment: "Reverse-Proxy"},
	})

	antwort := portliste(t, s, cookie)
	if len(antwort.Zeilen) != 3 {
		t.Fatalf("erwartet 3 Ports, gelesen %d: %+v", len(antwort.Zeilen), antwort.Zeilen)
	}

	nach := map[int]apiPort{}
	for _, z := range antwort.Zeilen {
		nach[z.WirtPort] = z
	}

	// 6379 ist offen und ufw kennt ihn nicht — der Befund.
	if nach[6379].Urteil != portOffenUnbemerkt {
		t.Errorf("6379 ist offen ohne ufw-Regel, Urteil = %q", nach[6379].Urteil)
	}
	if nach[6379].Stufe != "schlecht" {
		t.Errorf("der Befund muss auffallen, Stufe = %q", nach[6379].Stufe)
	}
	// Der Satz muss die Ursache nennen. „Nicht in der Firewall" allein führte zu
	// dem Schluss, eine ufw-Regel würde helfen — sie hilft nicht.
	if !strings.Contains(nach[6379].Satz, "Docker geht an ufw vorbei") {
		t.Errorf("der Satz erklärt die Ursache nicht: %q", nach[6379].Satz)
	}
	// Und daneben das kurze Urteil für die Spalte. Der ganze Satz in einer Zelle
	// wurde am Tabellenrand abgeschnitten — gesehen hat das ein Bildschirmfoto,
	// kein Test.
	if nach[6379].Kurz == "" || len(nach[6379].Kurz) > 40 {
		t.Errorf("das kurze Urteil fehlt oder ist zu lang: %q", nach[6379].Kurz)
	}

	// 8080 ist offen MIT Regel — gewollt, kein Befund.
	if nach[8080].Urteil != portOffenErlaubt {
		t.Errorf("8080 hat eine ufw-Regel, Urteil = %q", nach[8080].Urteil)
	}
	// 5432 ist nur lokal gebunden — von außen unerreichbar, unabhängig von ufw.
	if nach[5432].Urteil != portNurLokal || nach[5432].Stufe != "gut" {
		t.Errorf("5432 ist auf 127.0.0.1 gebunden: %+v", nach[5432])
	}

	if antwort.Unbemerkt != 1 || antwort.Offen != 1 || antwort.Lokal != 1 {
		t.Errorf("Zähler falsch: unbemerkt=%d offen=%d lokal=%d",
			antwort.Unbemerkt, antwort.Offen, antwort.Lokal)
	}
	// Der erklärende Satz steht über der Liste, sobald es etwas zu erklären gibt.
	if antwort.Warnung == "" {
		t.Error("die Erklärung zur Umgehung von ufw fehlt")
	}
	// Und das Auffällige steht oben.
	if antwort.Zeilen[0].WirtPort != 6379 {
		t.Errorf("der unbemerkte Port steht nicht oben: %d", antwort.Zeilen[0].WirtPort)
	}
}

// Ein gestoppter Container belegt keinen Port. Seine alte Angabe als offenen
// Port zu zeigen wäre eine Unwahrheit — und zwar eine beunruhigende.
func TestAPIPortsZaehltNurLaufende(t *testing.T) {
	s, _, cookie := portServer(t, true, nil)

	for _, z := range portliste(t, s, cookie).Zeilen {
		if z.WirtPort == 9999 {
			t.Errorf("der Port eines gestoppten Containers steht in der Liste: %+v", z)
		}
	}
}

// Ohne Firewall gibt es nichts zu vergleichen. Das ist eine eigene Aussage und
// nicht dasselbe wie „ufw kennt den Port nicht" — im einen Fall irrt sich
// jemand über seine Firewall, im anderen hat er keine.
func TestAPIPortsUnterscheidetOhneFirewallVonUnbemerkt(t *testing.T) {
	s, _, cookie := portServer(t, false, nil)

	antwort := portliste(t, s, cookie)
	if antwort.FirewallAktiv {
		t.Error("die Firewall ist aus, die Antwort behauptet das Gegenteil")
	}
	for _, z := range antwort.Zeilen {
		if z.WirtPort == 6379 && z.Urteil != portOhneFirewall {
			t.Errorf("ohne Firewall ist das Urteil ein anderes: %+v", z)
		}
	}
	// Und die Erklärung zur Umgehung steht NICHT da: Sie handelt von ufw, und
	// hier läuft keins. Ein Satz, der nicht zutrifft, ist Lärm.
	if antwort.Warnung != "" {
		t.Errorf("ohne laufende Firewall gehört der ufw-Satz nicht dahin: %q", antwort.Warnung)
	}
}

// Eine ufw-Regel ohne Protokoll gilt für tcp und udp — so legt ufw sie an, wenn
// niemand eines nennt.
func TestPorturteilNimmtRegelOhneProtokoll(t *testing.T) {
	fw := privops.FirewallState{Active: true, Rules: []privops.FirewallRule{{Port: 8080}}}
	v := privops.Veroeffentlichung{Adresse: "0.0.0.0", WirtPort: 8080, Protokoll: "tcp"}
	if urteil, _, _, _ := porturteil(v, fw); urteil != portOffenErlaubt {
		t.Errorf("Urteil = %q, erwartet %q", urteil, portOffenErlaubt)
	}
	// Ein anderer Port trifft nicht zu.
	v.WirtPort = 8081
	if urteil, _, _, _ := porturteil(v, fw); urteil != portOffenUnbemerkt {
		t.Errorf("Urteil = %q, erwartet %q", urteil, portOffenUnbemerkt)
	}
}

// Der Port des Panels wird markiert: Ihn zu schließen wäre der Selbstausschluss,
// und die Oberfläche soll das sagen können.
func TestAPIPortsMarkiertDenPanelPort(t *testing.T) {
	s, ops, cookie := portServer(t, true, nil)
	ops.container = append(ops.container, privops.Container{
		ID: "eeee11112222", Name: "proxy", Image: "traefik", Zustand: "running",
		Ports: "0.0.0.0:8443->8443/tcp",
	})

	gefunden := false
	for _, z := range portliste(t, s, cookie).Zeilen {
		if z.WirtPort == 8443 {
			gefunden = true
			if !z.PanelPort {
				t.Errorf("der Panel-Port ist nicht markiert: %+v", z)
			}
		}
	}
	if !gefunden {
		t.Fatal("der Port 8443 fehlt in der Liste")
	}
}

// Scheitert die Containerliste, steht der Fehler als Feld — die Seite zeigt ihn
// an, statt leer zu bleiben.
func TestAPIPortsMeldetFehlerAlsFeld(t *testing.T) {
	s, ops, cookie := portServer(t, true, nil)
	ops.containerErr = errDockerAttrappe

	antwort := portliste(t, s, cookie)
	if antwort.Fehler == "" {
		t.Error("der Fehler fehlt in der Antwort")
	}
	if antwort.Zeilen == nil {
		t.Error("leeres Feld statt null, auch im Fehlerfall")
	}
}

// ---------------------------------------------------------- Ereignisstrom ---

// Der Strom liefert Ereignisse als Objekte und markiert die ernsten. In einem
// Strom, in dem jede Zeile gleich aussieht, findet niemand den Befund.
func TestAPIDockerEventsStrom(t *testing.T) {
	s, ops, cookie := portServer(t, true, nil)
	ops.events = []privops.DockerEreignis{
		{Zeit: time.Now(), Art: "container", Aktion: "start", Objekt: "web-proxy-1", Stack: "web", Dienst: "proxy"},
		{Zeit: time.Now(), Art: "container", Aktion: "die", Objekt: "web-db-1", Zusatz: "Exit 137"},
		{Zeit: time.Now(), Art: "container", Aktion: "die", Objekt: "auftrag", Zusatz: "Exit 0"},
		{Zeit: time.Now(), Art: "image", Aktion: "pull", Objekt: "nginx:alpine"},
	}

	rec := get(t, s, "/api/v1/docker/events", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}
	koerper := rec.Body.String()
	if !strings.Contains(koerper, "event: ereignis") {
		t.Fatalf("keine Ereignisse im Strom: %s", koerper)
	}

	var gelesen []apiEreignis
	for _, zeile := range strings.Split(koerper, "\n") {
		roh, ok := strings.CutPrefix(zeile, "data: ")
		if !ok {
			continue
		}
		var e apiEreignis
		if json.Unmarshal([]byte(roh), &e) == nil && e.Aktion != "" {
			gelesen = append(gelesen, e)
		}
	}
	if len(gelesen) != 4 {
		t.Fatalf("erwartet 4 Ereignisse, gelesen %d", len(gelesen))
	}

	// Der Kern: Ein Container, der mit 137 stirbt, ist der Befund; einer mit 0
	// ist ein aufgeräumter Auftrag. Dieselbe Unterscheidung wie in
	// containerStufe — zwei Fassungen liefen auseinander.
	if gelesen[0].Ernst {
		t.Errorf("ein Start ist kein Befund: %+v", gelesen[0])
	}
	if !gelesen[1].Ernst {
		t.Errorf("Exit 137 ist ein Befund: %+v", gelesen[1])
	}
	if gelesen[2].Ernst {
		t.Errorf("Exit 0 ist ein aufgeräumter Container und kein Befund: %+v", gelesen[2])
	}
	// Die Zeit steht fertig da: Sie wird nur angezeigt, und eine Formatierung
	// im Browser wäre eine zweite Auslegung derselben Angabe.
	if gelesen[0].Zeit == "" {
		t.Error("die Zeit fehlt")
	}
	// Der Strom endet ordentlich, damit der Browser nicht sofort neu aufbaut.
	if !strings.Contains(koerper, "event: ende") {
		t.Error("der Strom endet ohne Abschlussereignis")
	}
}

// Die Schranke: Jeder Strom hält einen eigenen docker-Prozess. Der fünfte
// bekommt 429 und keine halb offene Verbindung.
func TestAPIDockerEventsSchranke(t *testing.T) {
	s, ops, cookie := portServer(t, true, nil)
	ops.eventsHalt = make(chan struct{})
	defer close(ops.eventsHalt)

	// Die Zähler stehen an einer Stelle mit dem Containerprotokoll — ein Strom
	// ist ein Strom. Vier belegen, der fünfte prallt ab.
	s.dockerFolger.Store(maxDockerEreignisFolger)
	rec := get(t, s, "/api/v1/docker/events", cookie)
	if rec.Code != http.StatusTooManyRequests {
		t.Errorf("Status = %d, erwartet 429", rec.Code)
	}
	// Und der Zähler ist danach unverändert: Ein abgewiesener Strom darf keinen
	// Platz belegen.
	if got := s.dockerFolger.Load(); got != maxDockerEreignisFolger {
		t.Errorf("der Zähler steht auf %d, erwartet %d", got, maxDockerEreignisFolger)
	}
}

// Lesen darf jede Rolle — wer sehen darf, welche Container laufen, darf sehen,
// welche Ports offen sind.
func TestAPIPortsLesenFuerAlleRollen(t *testing.T) {
	s, ops, cookie, _ := dockerServer(t, store.RoleReadOnly, privops.DockerState{
		Installiert: true, DaemonLaeuft: true,
	})
	ops.container = []privops.Container{
		{ID: "a", Name: "web", Zustand: "running", Ports: "0.0.0.0:80->80/tcp"},
	}

	if len(portliste(t, s, cookie).Zeilen) != 1 {
		t.Error("auch ein Lesekonto sieht die Portübersicht")
	}
}
