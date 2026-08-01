package httpd

// Sites über /api/v1 — lesend (Schritt 4 der Stufe 0.6).
//
// Was diese Fläche leisten muss, steht in docs/18-webserver.md: Auf einem
// Bestandsserver ist die Seite ab hier nicht leer. Sie zeigt, was nginx
// wirklich ausliefert — auch das, was das Panel nie geschrieben hat und nie
// anfassen wird.
//
// Die Trennung verwaltet/fremd ist dabei keine Nebensache, sondern die Zusage
// des Moduls: Dieselbe Trennung wie bei nftables, bei fremden Crontabs und bei
// fremden Compose-Projekten. Was das Panel nicht geschrieben hat, zeigt es an
// und lässt es in Ruhe.

import (
	"net/http"
	"strings"

	"github.com/philf90/asylum/internal/privops"
)

// apiSite ist eine Zeile der Sitesliste.
type apiSite struct {
	Name    string   `json:"name"`
	Datei   string   `json:"datei"`
	Domains []string `json:"domains"`
	Zielart string   `json:"zielart"`
	Ziel    string   `json:"ziel"`
	Ports   []int    `json:"ports"`
	TLS     bool     `json:"tls"`
	// Herkunft ist "verwaltet" oder "fremd" — das Wort, das die Liste zeigt.
	// Es kommt vom Server, damit es eine Auslegung gibt.
	Herkunft string `json:"herkunft"`
	// Zielsatz ist das Ziel als lesbarer Satz („Reverse-Proxy auf …"). Auch das
	// entsteht auf dem Server: Sonst baute die Oberfläche eine zweite
	// Zuordnung von Zielart zu Wort.
	Zielsatz  string `json:"zielsatz"`
	Anmerkung string `json:"anmerkung"`
}

// apiSites ist die Antwort von GET /api/v1/webserver/sites.
type apiSites struct {
	Sites []apiSite `json:"sites"`
	// Gelesen sagt, ob die Konfiguration überhaupt gelesen werden konnte.
	// FALSE heißt „unbekannt" und nicht „keine Sites" — der Unterschied
	// entscheidet, ob die Fläche zum Anlegen auffordert oder zum Reparieren.
	Gelesen bool `json:"gelesen"`
	Zaehler struct {
		Alle      int `json:"alle"`
		Verwaltet int `json:"verwaltet"`
		Fremd     int `json:"fremd"`
	} `json:"zaehler"`
	// Anmerkung ist der Satz zur Lage, vom Server formuliert.
	Anmerkung   string `json:"anmerkung"`
	DarfAendern bool   `json:"darf_aendern"`
	Fehler      string `json:"fehler,omitempty"`
}

// handleAPIWebserverSites liefert die Serverblöcke des Webservers.
func (s *Server) handleAPIWebserverSites(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	antwort := apiSites{DarfAendern: user.CanManageUsers()}

	bestand, err := s.ops.SiteList(r.Context())
	if err != nil {
		antwort.Fehler = err.Error()
		s.apiJSON(w, http.StatusOK, antwort)
		return
	}

	antwort.Gelesen = bestand.Gelesen
	antwort.Fehler = bestand.Fehler
	for _, si := range bestand.Sites {
		antwort.Sites = append(antwort.Sites, apiSite{
			Name:      si.Name,
			Datei:     si.Datei,
			Domains:   si.Domains,
			Zielart:   si.Zielart,
			Ziel:      si.Ziel,
			Ports:     si.Ports,
			TLS:       si.TLS,
			Herkunft:  herkunftWort(si.Verwaltet),
			Zielsatz:  zielsatz(si),
			Anmerkung: si.Anmerkung,
		})
		antwort.Zaehler.Alle++
		if si.Verwaltet {
			antwort.Zaehler.Verwaltet++
		} else {
			antwort.Zaehler.Fremd++
		}
	}
	antwort.Anmerkung = sitesAnmerkung(bestand)

	s.apiJSON(w, http.StatusOK, antwort)
}

func herkunftWort(verwaltet bool) string {
	if verwaltet {
		return "verwaltet"
	}
	return "fremd"
}

// zielsatz macht aus Zielart und Ziel einen lesbaren Satz.
//
// Auf dem Server und nicht in der Oberfläche: Sonst gäbe es die Zuordnung
// zweimal, und die zweite wäre irgendwann eine andere. Dieselbe Überlegung wie
// bei den Zustandsstufen der Containerliste.
func zielsatz(si privops.Site) string {
	switch si.Zielart {
	case "proxy":
		return "Reverse-Proxy auf " + si.Ziel
	case "statisch":
		return "Dateien aus " + si.Ziel
	case "umleitung":
		return "Umleitung auf " + si.Ziel
	default:
		return "kein einfaches Ziel erkennbar"
	}
}

// sitesAnmerkung formuliert die Lage.
//
// Der wichtigste Fall steht zuerst: „nicht lesbar". Er sieht in einer leeren
// Liste genauso aus wie „keine Sites", und die beiden verlangen entgegengesetzte
// Handgriffe — reparieren gegen anlegen.
func sitesAnmerkung(b privops.SiteBestand) string {
	if !b.Gelesen {
		return "Die Konfiguration des Webservers ließ sich nicht lesen. Diese Liste " +
			"ist deshalb LEER und nicht vollständig — es kann Sites geben, die " +
			"hier fehlen. `nginx -T` läuft nur, wenn die Konfiguration gültig ist."
	}
	if len(b.Sites) == 0 {
		return "Der Webserver liefert derzeit keinen Serverblock aus."
	}

	var fremd int
	for _, si := range b.Sites {
		if !si.Verwaltet {
			fremd++
		}
	}
	if fremd == 0 {
		return ""
	}
	// Der Satz, der die Zusage des Moduls trägt. Er steht nur, wenn es
	// tatsächlich fremde gibt — ein Satz, der immer dasteht, wird nicht
	// gelesen.
	return "Was das Panel nicht selbst geschrieben hat, zeigt es an und fasst es " +
		"nicht an. Fremde Serverblöcke lassen sich über die Dateien bearbeiten."
}

// handleAPIWebserverSite liefert den Inhalt einer verwalteten Site.
//
// Nur verwaltete. Eine fremde auszuliefern wäre kein Sicherheitsproblem — der
// Dateimanager kann sie ohnehin, und die Rechte dort sind dieselben —, aber es
// wäre die Zusage, sie auch bearbeiten zu können. Die gibt dieses Modul nicht.
func (s *Server) handleAPIWebserverSite(w http.ResponseWriter, r *http.Request) {
	name := strings.TrimSpace(r.PathValue("name"))
	inhalt, err := s.ops.SiteDatei(r.Context(), name)
	if err != nil {
		s.apiFehler(w, http.StatusNotFound,
			"Diese Site wird vom Panel nicht verwaltet: "+err.Error())
		return
	}
	s.apiJSON(w, http.StatusOK, map[string]string{"name": name, "inhalt": inhalt})
}
