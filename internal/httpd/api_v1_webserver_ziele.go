package httpd

// Zielvorschläge für eine Site (Schritt 6 der Stufe 0.6, docs/18-webserver.md §9).
//
// Die Zugabe, die es ohne Stufe 0.5 nicht gäbe: Das Panel kennt die laufenden
// Container samt ihren veröffentlichten Ports. „Reverse-Proxy auf" wird damit
// eine Auswahl statt eines Textfeldes — und das ist mehr als Bequemlichkeit.
// Wer die Adresse tippt, tippt sie ab, und ein abgetippter Port ist der
// häufigste Grund für eine Site, die 502 antwortet.
//
// # Der unbequeme Teil, der dazugehört
//
// Ein Container, der auf 0.0.0.0 veröffentlicht, ist aus dem Netz erreichbar —
// direkt, unter der IP des Servers und der Portnummer. Einen Reverse-Proxy
// davorzustellen ÄNDERT DAS NICHT. Wer glaubt, er habe den Dienst damit „hinter
// nginx gelegt", hat ihn zweimal veröffentlicht: einmal unter seiner Domain mit
// TLS und einmal nackt auf dem Port.
//
// Dieselbe Auskunft steht schon auf der Portübersicht (api_v1_docker_ports.go),
// dort mit dem Firewall-Abgleich. Hier steht sie an der Stelle, an der jemand
// gerade den Fehler macht — und das ist der bessere Ort für sie.

import (
	"fmt"
	"net/http"
	"sort"

	"github.com/philf90/asylum/internal/privops"
)

// apiZielvorschlag ist ein anklickbares Ziel.
type apiZielvorschlag struct {
	// Zielart und Ziel füllen die Felder des Formulars unverändert aus.
	Zielart string `json:"zielart"`
	Ziel    string `json:"ziel"`
	// Titel ist die Überschrift in der Auswahl, Detail die Zeile darunter.
	Titel  string `json:"titel"`
	Detail string `json:"detail"`
	// Warnung nennt, was an diesem Ziel unangenehm ist. Leer heißt: nichts.
	Warnung string `json:"warnung"`
}

// apiZiele ist die Antwort von GET /api/v1/webserver/ziele.
type apiZiele struct {
	Vorschlaege []apiZielvorschlag `json:"vorschlaege"`
	// Anmerkung ist der Satz zur Lage — vor allem der zu 0.0.0.0.
	Anmerkung string `json:"anmerkung"`
	// Fehler steht als Feld und nicht als Statuscode: Ohne Docker gibt es keine
	// Vorschläge, und das ist kein Grund, das Formular nicht zu zeigen.
	Fehler string `json:"fehler,omitempty"`
}

// handleAPIWebserverZiele sammelt, worauf eine Site zeigen kann.
func (s *Server) handleAPIWebserverZiele(w http.ResponseWriter, r *http.Request) {
	antwort := apiZiele{Vorschlaege: []apiZielvorschlag{}}

	// PHP zuerst und ohne Docker: Ein FPM-Socket auf der Platte ist der Beleg
	// dafür, dass ein Prozess läuft. Er kostet keinen Aufruf und fehlt auf einem
	// Server ohne PHP einfach.
	for _, sock := range s.ops.PHPSockets(r.Context()) {
		antwort.Vorschlaege = append(antwort.Vorschlaege, apiZielvorschlag{
			Zielart: "php",
			// Das Ziel eines PHP-Vorschlags ist der SOCKET und nicht das
			// Verzeichnis: Welches Verzeichnis ausgeliefert wird, weiß nur der
			// Betreiber. Die Oberfläche setzt den Socket ein und lässt das
			// Verzeichnis, wie es ist.
			Ziel:   sock,
			Titel:  "PHP-FPM",
			Detail: sock,
		})
	}

	container, err := s.ops.DockerContainers(r.Context())
	if err != nil {
		// Kein Docker, kein Fehler: Das Formular funktioniert ohne Vorschläge,
		// man tippt die Adresse dann eben.
		antwort.Fehler = err.Error()
		s.apiJSON(w, http.StatusOK, antwort)
		return
	}

	var offene int
	for _, c := range container {
		// Nur laufende: Ein gestoppter Container beantwortet nichts, und ihn
		// vorzuschlagen hieße, eine Site zu bauen, die 502 antwortet.
		if c.Zustand != "running" {
			continue
		}
		for _, v := range privops.ParsePorts(c.Ports) {
			if v.Protokoll != "tcp" {
				continue
			}
			// Der Panel-Port fällt hier heraus und nicht erst im Prüfer: Ein
			// Vorschlag, den der Prüfer danach ablehnt, ist eine Falle.
			if v.WirtPort == s.cfg.Server.Port {
				continue
			}
			ziel := fmt.Sprintf("http://%s:%d", proxyAdresse(v.Adresse), v.WirtPort)
			vorschlag := apiZielvorschlag{
				Zielart: "proxy",
				Ziel:    ziel,
				Titel:   c.Name,
				Detail:  zielDetail(c, v),
			}
			if !nurLokal(v.Adresse) {
				offene++
				// Kurz, weil der ganze Satz darüber steht: Hier geht es nur
				// darum, WELCHER Vorschlag es betrifft. Die Begründung zweimal
				// unter zwei Zeilen zu wiederholen macht sie nicht deutlicher,
				// sondern zu Rauschen, das man überliest.
				vorschlag.Warnung = "auf allen Adressen veröffentlicht"
			}
			antwort.Vorschlaege = append(antwort.Vorschlaege, vorschlag)
		}
	}

	// Nach Titel und dann nach Ziel: Ein Container mit zwei Ports steht damit
	// beisammen, und die Reihenfolge hängt nicht an der von Docker.
	sort.SliceStable(antwort.Vorschlaege, func(i, j int) bool {
		a, b := antwort.Vorschlaege[i], antwort.Vorschlaege[j]
		if a.Titel != b.Titel {
			return a.Titel < b.Titel
		}
		return a.Ziel < b.Ziel
	})

	if offene > 0 {
		antwort.Anmerkung = "Einige dieser Dienste sind auf allen Adressen " +
			"veröffentlicht und damit schon jetzt aus dem Netz erreichbar; ein " +
			"Reverse-Proxy davor ändert das nicht. Wer sie nur noch über die Domain " +
			"anbieten will, veröffentlicht sie auf 127.0.0.1 — sonst bleibt der Port " +
			"daneben offen."
	}

	s.apiJSON(w, http.StatusOK, antwort)
}

// proxyAdresse macht aus der Veröffentlichungsadresse die Adresse, die in
// proxy_pass gehört.
//
// 0.0.0.0 und :: werden zu 127.0.0.1: Ein proxy_pass auf 0.0.0.0 ist keine
// Zieladresse, sondern eine Bindungsangabe — nginx müsste raten, wen es meint.
// Der Dienst ist über die Loopback-Adresse ohnehin erreichbar, wenn er auf allen
// hört, und der Weg über sie verlässt den Rechner nicht.
func proxyAdresse(adresse string) string {
	switch adresse {
	case "", "0.0.0.0", "::", "[::]", "*":
		return "127.0.0.1"
	case "::1", "[::1]":
		return "[::1]"
	default:
		return adresse
	}
}

// nurLokal sagt, ob diese Veröffentlichung den Rechner nicht verlässt.
func nurLokal(adresse string) bool {
	switch adresse {
	case "127.0.0.1", "localhost", "::1", "[::1]":
		return true
	default:
		return false
	}
}

// zielDetail baut die Zeile unter dem Namen.
func zielDetail(c privops.Container, v privops.Veroeffentlichung) string {
	detail := fmt.Sprintf("%s:%d → %d", v.Adresse, v.WirtPort, v.ContainerPort)
	if c.Stack != "" {
		detail += " · " + c.Stack
		if c.Dienst != "" {
			detail += " / " + c.Dienst
		}
	}
	return detail
}
