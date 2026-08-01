package httpd

// Tests der Webserverfläche.
//
// Der Schwerpunkt liegt nicht auf der Auskunft, sondern auf der Sperre: Der
// Installationsknopf ist die einzige Aktion dieses Moduls, die einen laufenden
// Server umbringen kann (docs/18-webserver.md E1). Geprüft wird deshalb überall
// die WIRKUNG und nicht der Statuscode — ein 409, nach dem trotzdem apt lief,
// wäre genau der Fehler, den ein Test über den Statuscode nicht sieht.

import (
	"encoding/json"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// webserverServer baut einen Server mit angemeldetem Konto der gewünschten
// Rolle und einer Attrappe, deren Webserverzustand der Test setzt.
func webserverServer(t *testing.T, rolle string, st privops.WebServerState) (*Server, *fakeOps, *http.Cookie, string) {
	t.Helper()
	s, ops := newSystemServer(t)
	ops.web = st
	user := addUser(t, s, "konto", rolle)
	cookie, csrf := login(t, s, user)
	return s, ops, cookie, csrf
}

func webserverLesen(t *testing.T, s *Server, cookie *http.Cookie) apiWebserver {
	t.Helper()
	rec := get(t, s, "/api/v1/webserver", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	var antwort apiWebserver
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	return antwort
}

// nichtsGelaufen prüft, dass die Attrappe keine Webserveroperation ausgeführt
// hat. Die eigentliche Zusage jedes Sperrtests dieser Datei.
func nichtsGelaufen(t *testing.T, ops *fakeOps) {
	t.Helper()
	for _, a := range ops.recorded() {
		if strings.HasPrefix(a, "webserver:") {
			t.Errorf("es darf nichts gelaufen sein, gelaufen ist %q", a)
		}
	}
}

// Der leere Server: kein nginx, nichts auf den Ports. Nur hier gibt es einen
// Knopf.
func TestAPIWebserverBietetEinspielenAufFreiemServer(t *testing.T) {
	s, _, cookie, _ := webserverServer(t, store.RoleOwner, privops.WebServerState{
		LauscherGeprueft: true,
	})

	antwort := webserverLesen(t, s, cookie)
	if !antwort.Einspielbar {
		t.Error("auf einem freien Server soll das Panel nginx anbieten")
	}
	if !strings.Contains(antwort.Anmerkung, "nicht installiert") {
		t.Errorf("die Anmerkung soll den Zustand nennen: %q", antwort.Anmerkung)
	}
}

// Der Fall, um den es in E1 geht: Es läuft ein Webserver, nur nicht der, den
// das Panel verwaltet. Dann gibt es keinen Knopf — und der Grund steht dabei.
func TestAPIWebserverBietetNichtsAnWennEinFremderServerLaeuft(t *testing.T) {
	for _, fall := range []struct {
		name    string
		prozess string
	}{
		{"Caddy", "caddy"},
		{"Apache", "apache2"},
		{"Container", "docker-proxy"},
	} {
		t.Run(fall.name, func(t *testing.T) {
			s, _, cookie, _ := webserverServer(t, store.RoleOwner, privops.WebServerState{
				LauscherGeprueft: true,
				Lauscher: []privops.Lauscher{
					{Port: 80, Adresse: "0.0.0.0", Prozess: fall.prozess, PID: 651},
				},
			})

			antwort := webserverLesen(t, s, cookie)
			if antwort.Einspielbar {
				t.Fatalf("%s hält Port 80 — es darf kein Angebot geben", fall.prozess)
			}
			if len(antwort.Fremd) != 1 || antwort.Fremd[0] != fall.prozess {
				t.Errorf("der fremde Server gehört benannt: %+v", antwort.Fremd)
			}
			// Grundsatz IV: Das Panel verschweigt nichts. Wer keinen Knopf
			// bekommt, muss lesen können, warum.
			if !strings.Contains(antwort.Anmerkung, fall.prozess) {
				t.Errorf("die Anmerkung soll den Server nennen: %q", antwort.Anmerkung)
			}
			if !strings.Contains(antwort.Anmerkung, "Port 80") {
				t.Errorf("die Anmerkung soll den Port nennen: %q", antwort.Anmerkung)
			}
		})
	}
}

// „Nicht geprüft" ist kein „frei". Die Zeile, die man vergisst — und sie ist
// die einzige, bei der ein Fehler beide Male gutgeht, bis er einmal nicht
// gutgeht.
func TestAPIWebserverBietetNichtsAnWennDieBelegungUnbekanntIst(t *testing.T) {
	s, _, cookie, _ := webserverServer(t, store.RoleOwner, privops.WebServerState{
		LauscherGeprueft: false,
	})

	antwort := webserverLesen(t, s, cookie)
	if antwort.Einspielbar {
		t.Fatal("ohne Auskunft über die Ports darf nichts angeboten werden")
	}
	if antwort.PortsGeprueft {
		t.Error("die Auskunft muss sagen, dass sie keine ist")
	}
	if !strings.Contains(antwort.Anmerkung, "nicht feststellen") {
		t.Errorf("die Anmerkung soll die Lücke benennen: %q", antwort.Anmerkung)
	}
}

// Und dieselbe Lage am Handler: Der POST muss ablehnen, und zwar wirkungslos.
func TestAPIWebserverInstallLehntBeiBelegtemPortAb(t *testing.T) {
	for _, fall := range []struct {
		name string
		st   privops.WebServerState
	}{
		{"fremder Server", privops.WebServerState{
			LauscherGeprueft: true,
			Lauscher:         []privops.Lauscher{{Port: 80, Adresse: "0.0.0.0", Prozess: "caddy"}},
		}},
		{"Belegung unbekannt", privops.WebServerState{LauscherGeprueft: false}},
		{"nginx schon da", privops.WebServerState{
			Installiert: true, Version: "1.24.0", LauscherGeprueft: true,
		}},
	} {
		t.Run(fall.name, func(t *testing.T) {
			s, ops, cookie, csrf := webserverServer(t, store.RoleOwner, fall.st)

			rec := postJSON(t, s, "/api/v1/webserver/install", "{}", cookie, csrf)
			if rec.Code != http.StatusConflict {
				t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
			}
			nichtsGelaufen(t, ops)
		})
	}
}

// Die Sperre sitzt im Handler und nicht in der Oberfläche: Zwischen dem Laden
// der Seite und dem Klick liegt beliebig viel Zeit. Hier fängt der Server erst
// nichts, und dann steht plötzlich ein Caddy auf Port 80 — der Knopf war
// richtig beschriftet und ist trotzdem falsch geworden.
func TestAPIWebserverInstallLiestDenZustandNeu(t *testing.T) {
	s, ops, cookie, csrf := webserverServer(t, store.RoleOwner, privops.WebServerState{
		LauscherGeprueft: true,
	})

	// Die Seite sah einen freien Server.
	if !webserverLesen(t, s, cookie).Einspielbar {
		t.Fatal("Ausgangslage: der Server ist frei")
	}

	// Und danach startet jemand einen Webserver.
	ops.mu.Lock()
	ops.web = privops.WebServerState{
		LauscherGeprueft: true,
		Lauscher:         []privops.Lauscher{{Port: 443, Adresse: "[::]", Prozess: "caddy"}},
	}
	ops.mu.Unlock()

	rec := postJSON(t, s, "/api/v1/webserver/install", "{}", cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "443") {
		t.Errorf("die Ablehnung soll den belegten Port nennen: %s", rec.Body.String())
	}
	nichtsGelaufen(t, ops)
}

func TestAPIWebserverInstallVerlangtOwner(t *testing.T) {
	s, ops, cookie, csrf := webserverServer(t, store.RoleAdmin, privops.WebServerState{
		LauscherGeprueft: true,
	})

	rec := postJSON(t, s, "/api/v1/webserver/install", "{}", cookie, csrf)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("Status = %d, erwartet 403: %s", rec.Code, rec.Body.String())
	}
	nichtsGelaufen(t, ops)
}

// Die Rechteauskunft der Antwort muss zur Schranke des Servers passen. Liefen
// beide auseinander, zeigte die Oberfläche einem Admin-Konto einen Knopf, der
// zuverlässig 403 ergibt.
func TestAPIWebserverNenntAdminKeinAenderungsrecht(t *testing.T) {
	s, _, cookie, _ := webserverServer(t, store.RoleAdmin, privops.WebServerState{
		LauscherGeprueft: true,
	})

	if webserverLesen(t, s, cookie).DarfAendern {
		t.Error("ein Admin-Konto darf den Webserver nicht einspielen")
	}
}

func TestAPIWebserverInstallVerlangtToken(t *testing.T) {
	s, ops, cookie, _ := webserverServer(t, store.RoleOwner, privops.WebServerState{
		LauscherGeprueft: true,
	})

	rec := postJSON(t, s, "/api/v1/webserver/install", "{}", cookie, "")
	if rec.Code != http.StatusForbidden {
		t.Fatalf("Status = %d, erwartet 403: %s", rec.Code, rec.Body.String())
	}
	nichtsGelaufen(t, ops)
}

// Der vollständige Weg auf einem freien Server: Der POST ist sofort zurück und
// trägt den Vorgang mit, damit sich die Oberfläche gleich anhängen kann.
func TestAPIWebserverInstallStartetVorgang(t *testing.T) {
	s, ops, cookie, csrf := webserverServer(t, store.RoleOwner, privops.WebServerState{
		LauscherGeprueft: true,
	})
	ops.webInstallDone = make(chan struct{})

	rec := postJSON(t, s, "/api/v1/webserver/install", "{}", cookie, csrf)
	if rec.Code != http.StatusAccepted {
		t.Fatalf("Status = %d, erwartet 202: %s", rec.Code, rec.Body.String())
	}
	var gestartet apiVorgangGestartet
	if err := json.Unmarshal(rec.Body.Bytes(), &gestartet); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	if gestartet.Job.Art != jobWebserverInstall {
		t.Errorf("Vorgangsart = %q, erwartet %q", gestartet.Job.Art, jobWebserverInstall)
	}

	select {
	case <-ops.webInstallDone:
	case <-time.After(2 * time.Second):
		t.Fatal("die Installation lief nicht")
	}

	// Danach ist nginx da — und die Seite bietet nichts mehr an.
	antwort := webserverLesen(t, s, cookie)
	if antwort.Einspielbar {
		t.Error("nach dem Lauf gibt es nichts mehr einzuspielen")
	}
	if !antwort.Installiert || antwort.Version == "" {
		t.Errorf("der Zustand nach dem Lauf kam nicht durch: %+v", antwort)
	}
	// Und der Vorgang steht im Audit — zweimal: gestartet über s.audit, beendet
	// über auditNachtraeglich. Ein Lauf, der nur seinen Anfang protokolliert,
	// lässt offen, ob er geglückt ist.
	eintraege, err := s.db.ListAudit(t.Context(), 20)
	if err != nil {
		t.Fatal(err)
	}
	var anfang, ende bool
	for _, e := range eintraege {
		if e.Action != "webserver.install" {
			continue
		}
		if strings.Contains(e.Detail, "gestartet") {
			anfang = true
		}
		if strings.Contains(e.Detail, "abgeschlossen") {
			ende = true
		}
	}
	if !anfang || !ende {
		t.Errorf("Audit unvollständig: Anfang %v, Ende %v", anfang, ende)
	}
}

// Ein installiertes, aber gestopptes nginx ist ein Dienstproblem und kein
// apt-Problem. Der Rat muss dorthin zeigen, wo der Handgriff sitzt.
func TestAPIWebserverVerweistBeiGestopptemDienstAufDieDienste(t *testing.T) {
	s, _, cookie, _ := webserverServer(t, store.RoleOwner, privops.WebServerState{
		Installiert: true, Version: "1.24.0", Paket: "nginx-core",
		DienstAktiv: false, LauscherGeprueft: true,
	})

	antwort := webserverLesen(t, s, cookie)
	if antwort.Einspielbar {
		t.Error("ein apt-Lauf hilft einem gestoppten Dienst nicht")
	}
	if !strings.Contains(antwort.Anmerkung, "Dienste") {
		t.Errorf("die Anmerkung soll auf die Dienstseite zeigen: %q", antwort.Anmerkung)
	}
}

// Der Regelfall: nginx läuft und hält seine Ports. Dann gibt es nichts
// anzumerken — eine Anmerkung im Normalzustand wäre Lärm.
func TestAPIWebserverSchweigtImNormalzustand(t *testing.T) {
	s, _, cookie, _ := webserverServer(t, store.RoleOwner, privops.WebServerState{
		Installiert: true, Version: "1.24.0", Paket: "nginx-core", DienstAktiv: true,
		LauscherGeprueft: true,
		Lauscher: []privops.Lauscher{
			{Port: 80, Adresse: "0.0.0.0", Prozess: "nginx", PID: 1234},
			{Port: 443, Adresse: "0.0.0.0", Prozess: "nginx", PID: 1234},
		},
	})

	antwort := webserverLesen(t, s, cookie)
	if antwort.Anmerkung != "" {
		t.Errorf("im Normalzustand gehört geschwiegen: %q", antwort.Anmerkung)
	}
	if len(antwort.Fremd) != 0 {
		t.Errorf("nginx ist sich selbst nicht fremd: %+v", antwort.Fremd)
	}
	if len(antwort.Lauscher) != 2 || !antwort.Lauscher[0].Eigen {
		t.Errorf("die Belegung gehört durchgereicht und als eigen markiert: %+v", antwort.Lauscher)
	}
}

// Ein Lauscher, den ss nicht benennen konnte, zählt als fremd. Die sichere
// Richtung des Zweifels: Lieber kein Knopf, als ein Knopf über einem Server,
// von dem das Panel nur weiß, dass er da ist.
func TestAPIWebserverZaehltUnbenanntenLauscherAlsFremd(t *testing.T) {
	s, ops, cookie, csrf := webserverServer(t, store.RoleOwner, privops.WebServerState{
		LauscherGeprueft: true,
		Lauscher:         []privops.Lauscher{{Port: 80, Adresse: "0.0.0.0"}},
	})

	if webserverLesen(t, s, cookie).Einspielbar {
		t.Error("ein unbenannter Lauscher ist trotzdem ein Lauscher")
	}
	rec := postJSON(t, s, "/api/v1/webserver/install", "{}", cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	nichtsGelaufen(t, ops)
}
