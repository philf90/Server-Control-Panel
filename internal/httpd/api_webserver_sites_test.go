package httpd

import (
	"encoding/json"
	"net/http"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

func sitesLesen(t *testing.T, s *Server, cookie *http.Cookie) apiSites {
	t.Helper()
	rec := get(t, s, "/api/v1/webserver/sites", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	var antwort apiSites
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	return antwort
}

// bestandMitSites baut einen Server, dessen Attrappe die gewünschten Sites
// liefert.
func sitesServer(t *testing.T, rolle string, bestand privops.SiteBestand) (*Server, *fakeOps, *http.Cookie, string) {
	t.Helper()
	s, ops := newSystemServer(t)
	ops.sites = bestand
	user := addUser(t, s, "konto", rolle)
	cookie, csrf := login(t, s, user)
	return s, ops, cookie, csrf
}

func TestAPISitesTrenntVerwaltetVonFremd(t *testing.T) {
	s, _, cookie, _ := sitesServer(t, store.RoleOwner, privops.SiteBestand{
		Gelesen: true,
		Sites: []privops.Site{
			{
				Name: "shop", Datei: "/etc/nginx/conf.d/asylum-shop.conf",
				Domains: []string{"shop.example.com"}, Zielart: "proxy",
				Ziel: "http://127.0.0.1:3000", Ports: []int{443}, TLS: true,
				Verwaltet: true,
			},
			{
				Name: "alt.example.com", Datei: "/etc/nginx/sites-enabled/alt",
				Domains: []string{"alt.example.com"}, Zielart: "statisch",
				Ziel: "/var/www/alt", Ports: []int{80},
			},
		},
	})

	antwort := sitesLesen(t, s, cookie)
	if !antwort.Gelesen {
		t.Fatal("die Konfiguration galt als nicht lesbar")
	}
	if antwort.Zaehler.Alle != 2 || antwort.Zaehler.Verwaltet != 1 || antwort.Zaehler.Fremd != 1 {
		t.Errorf("Zähler falsch: %+v", antwort.Zaehler)
	}
	if antwort.Sites[0].Herkunft != "verwaltet" || antwort.Sites[1].Herkunft != "fremd" {
		t.Errorf("Herkunft falsch: %q / %q", antwort.Sites[0].Herkunft, antwort.Sites[1].Herkunft)
	}
	// Der Zielsatz kommt vom Server. Sonst baute die Oberfläche eine zweite
	// Zuordnung von Zielart zu Wort, und die wäre irgendwann eine andere.
	if !strings.Contains(antwort.Sites[0].Zielsatz, "Reverse-Proxy") {
		t.Errorf("Zielsatz = %q", antwort.Sites[0].Zielsatz)
	}
	if !strings.Contains(antwort.Sites[1].Zielsatz, "Dateien aus") {
		t.Errorf("Zielsatz = %q", antwort.Sites[1].Zielsatz)
	}
	// Und die Zusage des Moduls steht da, weil es fremde gibt.
	if !strings.Contains(antwort.Anmerkung, "nicht an") {
		t.Errorf("die Zusage zu fremden Blöcken fehlt: %q", antwort.Anmerkung)
	}
}

// Der wichtigste Fall der ganzen Fläche: „nicht lesbar" sieht in einer leeren
// Liste genauso aus wie „keine Sites" — und die beiden verlangen
// entgegengesetzte Handgriffe. Reparieren gegen anlegen.
func TestAPISitesTrenntNichtLesbarVonLeer(t *testing.T) {
	s, _, cookie, _ := sitesServer(t, store.RoleOwner, privops.SiteBestand{
		Gelesen: false,
		Fehler:  "nginx: [emerg] unknown directive \"srver_name\"",
	})

	antwort := sitesLesen(t, s, cookie)
	if antwort.Gelesen {
		t.Error("eine unlesbare Konfiguration darf nicht als gelesen gelten")
	}
	if !strings.Contains(antwort.Anmerkung, "nicht vollständig") {
		t.Errorf("die Anmerkung sagt nicht, dass die Liste unvollständig ist: %q",
			antwort.Anmerkung)
	}
	if !strings.Contains(antwort.Fehler, "srver_name") {
		t.Errorf("die Meldung von nginx fehlt: %q", antwort.Fehler)
	}
}

// Und der leere Server ist etwas anderes: kein Fehler, sondern ein Zustand.
func TestAPISitesLeerIstKeinFehler(t *testing.T) {
	s, _, cookie, _ := sitesServer(t, store.RoleOwner, privops.SiteBestand{Gelesen: true})

	antwort := sitesLesen(t, s, cookie)
	if !antwort.Gelesen {
		t.Error("ein leerer Server ist gelesen")
	}
	if antwort.Fehler != "" {
		t.Errorf("kein Fehler erwartet: %q", antwort.Fehler)
	}
	if strings.Contains(antwort.Anmerkung, "nicht vollständig") {
		t.Errorf("bei einem leeren Server darf nicht von Unvollständigkeit die Rede sein: %q",
			antwort.Anmerkung)
	}
}

// Gibt es nur eigene Sites, schweigt die Anmerkung. Ein Satz, der immer
// dasteht, wird nicht gelesen — und dann wird auch der nicht gelesen, der
// zählt.
func TestAPISitesSchweigtOhneFremde(t *testing.T) {
	s, _, cookie, _ := sitesServer(t, store.RoleOwner, privops.SiteBestand{
		Gelesen: true,
		Sites: []privops.Site{
			{Name: "shop", Datei: "/etc/nginx/conf.d/asylum-shop.conf", Verwaltet: true},
		},
	})

	if a := sitesLesen(t, s, cookie).Anmerkung; a != "" {
		t.Errorf("ohne fremde Blöcke gehört geschwiegen: %q", a)
	}
}

// Lesen darf jede Rolle — wer sehen darf, welche Dienste laufen, darf sehen,
// welche Sites ausgeliefert werden. Ändern nicht.
func TestAPISitesLesenDarfJedeRolle(t *testing.T) {
	s, _, cookie, _ := sitesServer(t, store.RoleReadOnly, privops.SiteBestand{Gelesen: true})

	antwort := sitesLesen(t, s, cookie)
	if antwort.DarfAendern {
		t.Error("ein Konto mit Leserecht darf Sites nicht ändern")
	}
}

// Eine fremde Site liefert das Panel nicht aus. Das ist kein
// Sicherheitsproblem — der Dateimanager kann sie ohnehin —, aber es wäre die
// Zusage, sie auch bearbeiten zu können.
func TestAPISiteDateiNurVerwaltete(t *testing.T) {
	s, ops, cookie, _ := sitesServer(t, store.RoleOwner, privops.SiteBestand{Gelesen: true})
	ops.siteDateien = map[string]string{"shop": "server { server_name shop.example.com; }"}

	rec := get(t, s, "/api/v1/webserver/sites/shop", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "shop.example.com") {
		t.Errorf("der Inhalt fehlt: %s", rec.Body.String())
	}

	rec = get(t, s, "/api/v1/webserver/sites/fremd", cookie)
	if rec.Code != http.StatusNotFound {
		t.Errorf("Status = %d, erwartet 404 für eine nicht verwaltete Site", rec.Code)
	}
}
