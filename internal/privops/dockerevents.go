package privops

import (
	"context"
	"encoding/json"
	"errors"
	"strconv"
	"strings"
	"time"
)

// Der Ereignisstrom von Docker.
//
// Schritt 6 aus docs/17-docker.md, übernommen aus Arcane: Er beantwortet die
// Frage „warum ist der Container um 3 Uhr neu gestartet". Ein Container, den man
// morgens mit einer Laufzeit von vier Stunden vorfindet, hat irgendwann in der
// Nacht neu gestartet — der Zustand sagt, DASS es geschah, und der
// Ereignisstrom sagt, wann und warum (OOM, Neustartregel, jemand hat gedrückt).
//
// Die Mechanik ist dieselbe wie beim Journal und beim Containerprotokoll: kein
// eigener Zeitrahmen, der Kontext des Betrachters ist die Frist. Neu ist nur die
// Gestalt der Zeilen — Docker liefert hier JSON und keinen Text, und was die
// Oberfläche daraus zeigt, ist ein Satz aus drei Feldern statt einer Rohzeile.

// DockerEreignis ist ein Eintrag des Ereignisstroms.
type DockerEreignis struct {
	// Zeit ist der Zeitpunkt, wie ihn Docker meldet.
	Zeit time.Time `json:"zeit"`
	// Art ist der Gegenstand: container, image, network, volume, daemon.
	Art string `json:"art"`
	// Aktion ist, was geschah: start, die, kill, health_status, pull …
	Aktion string `json:"aktion"`
	// Objekt ist der sprechende Name — der Containername, wenn es einen gibt,
	// sonst die gekürzte Kennung. Eine Kennung ohne Namen ist für den
	// Betrachter kein Objekt, sondern eine Zeichenfolge.
	Objekt string `json:"objekt"`
	// Stack und Dienst kommen aus den Compose-Labels, soweit vorhanden.
	Stack  string `json:"stack"`
	Dienst string `json:"dienst"`
	// Zusatz trägt, was zur Aktion gehört und sonst verloren ginge: der
	// Exit-Code bei "die", das Ergebnis bei "health_status", das Signal bei
	// "kill". Genau diese Angaben sind der Grund, warum jemand den Strom öffnet.
	Zusatz string `json:"zusatz"`
}

// DockerEventsFollow verfolgt den Ereignisstrom.
//
// Ohne eigene Frist (OhneFrist): Der Kontext des Betrachters ist die Frist,
// derselbe Vertrag wie bei LogsFollow und DockerContainerLogsFollow.
//
// **Gefiltert wird auf dem Wirt und nicht im Browser.** "docker events" kennt
// --filter, und ein ungefilterter Strom auf einem Server mit vierzig Containern
// schreibt bei jedem Gesundheitscheck eine Zeile — das sind Hunderte je Minute,
// von denen keine jemanden interessiert. Übertragen wird deshalb nur, was ein
// Mensch lesen will.
func (s *System) DockerEventsFollow(ctx context.Context, sink func(DockerEreignis)) error {
	if sink == nil {
		return errors.New("DockerEventsFollow ohne Empfänger")
	}

	// --since: Docker liefert damit die Ereignisse der letzten zehn Minuten mit,
	// bevor es auf das Warten umschaltet. Ohne die Angabe bliebe die Fläche leer,
	// bis von selbst etwas geschieht — auf einem ruhigen Server können das
	// Stunden sein, und eine leere Fläche sieht aus wie eine kaputte.
	args := []string{"events", "--format", "{{json .}}", "--since", "10m"}
	for _, art := range []string{"container", "image", "volume", "network", "daemon"} {
		args = append(args, "--filter", "type="+art)
	}

	res, err := s.run(ctx, Command{
		Name:      "docker",
		Args:      args,
		OhneFrist: true,
		Stream: func(zeile string) {
			if e, ok := parseDockerEreignis(zeile); ok {
				sink(e)
			}
		},
	})
	// Der Abbruch des Kontexts ist das vorgesehene Ende — der Betrachter hat die
	// Seite verlassen. Er wird zuerst geprüft, weil ein getöteter Prozess beides
	// hinterlässt, Exit-Code und Fehler.
	if ctx.Err() != nil {
		return nil //nolint:nilerr // der Abbruch IST das Ende
	}
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return errors.New("docker events: " + ersteAusgabezeile(res))
	}
	return nil
}

// rohesEreignis ist die Zeile, wie Docker sie schreibt.
//
// Aufgezeichnete Zeile (Docker 27, "docker events --format {{json .}}"):
//
//	{"status":"start","id":"aaa…","from":"nginx:alpine","Type":"container",
//	 "Action":"start","Actor":{"ID":"aaa…","Attributes":{"image":"nginx:alpine",
//	 "name":"web-proxy-1","com.docker.compose.project":"web",
//	 "com.docker.compose.service":"proxy"}},
//	 "scope":"local","time":1753948800,"timeNano":1753948800123456789}
//
// Die alten Felder "status" und "id" stehen neben den neuen "Action" und
// "Actor.ID" — Docker schleppt beide mit. Gelesen werden die neuen mit Rückfall
// auf die alten: Eine ältere Fassung, die nur "status" schreibt, soll nicht eine
// leere Spalte ergeben.
type rohesEreignis struct {
	Status string `json:"status"`
	ID     string `json:"id"`
	Type   string `json:"Type"`
	Action string `json:"Action"`
	Actor  struct {
		ID         string            `json:"ID"`
		Attributes map[string]string `json:"Attributes"`
	} `json:"Actor"`
	Time     int64 `json:"time"`
	TimeNano int64 `json:"timeNano"`
}

// parseDockerEreignis liest eine Zeile des Stroms.
//
// Eine Zeile, die sich nicht zerlegen lässt, wird übersprungen statt den Strom
// zu beenden: Docker schreibt bei Warnungen gelegentlich Text dazwischen, und
// daran soll die Anzeige aller übrigen Ereignisse nicht scheitern.
func parseDockerEreignis(zeile string) (DockerEreignis, bool) {
	zeile = strings.TrimSpace(zeile)
	if zeile == "" || !strings.HasPrefix(zeile, "{") {
		return DockerEreignis{}, false
	}
	var roh rohesEreignis
	if json.Unmarshal([]byte(zeile), &roh) != nil {
		return DockerEreignis{}, false
	}

	e := DockerEreignis{
		Art:    roh.Type,
		Aktion: roh.Action,
		Zeit:   ereigniszeit(roh),
	}
	if e.Aktion == "" {
		e.Aktion = roh.Status
	}
	if e.Art == "" {
		e.Art = "container"
	}
	// „health_status: healthy" kommt als eine Aktion. Der Doppelpunkt trennt
	// die Aktion von ihrem Ergebnis, und getrennt lesen sich beide besser.
	if aktion, ergebnis, ok := strings.Cut(e.Aktion, ":"); ok {
		e.Aktion = strings.TrimSpace(aktion)
		e.Zusatz = strings.TrimSpace(ergebnis)
	}

	attr := roh.Actor.Attributes
	e.Stack = attr["com.docker.compose.project"]
	e.Dienst = attr["com.docker.compose.service"]

	switch {
	case attr["name"] != "":
		e.Objekt = attr["name"]
	case roh.Actor.ID != "":
		e.Objekt = kurzeKennungPrivops(roh.Actor.ID)
	default:
		e.Objekt = kurzeKennungPrivops(roh.ID)
	}

	// Der Exit-Code bei "die" ist die Angabe, wegen der man den Strom öffnet:
	// Er sagt den Unterschied zwischen „ordentlich beendet" und „vom Kernel
	// erschlagen".
	if code := attr["exitCode"]; code != "" && e.Zusatz == "" {
		e.Zusatz = "Exit " + code
	}
	if signal := attr["signal"]; signal != "" && e.Zusatz == "" {
		e.Zusatz = "Signal " + signal
	}
	return e, true
}

// ereigniszeit nimmt die genauere der beiden Zeitangaben.
func ereigniszeit(roh rohesEreignis) time.Time {
	if roh.TimeNano > 0 {
		return time.Unix(0, roh.TimeNano)
	}
	if roh.Time > 0 {
		return time.Unix(roh.Time, 0)
	}
	return time.Time{}
}

// kurzeKennungPrivops kürzt auf die zwölf Stellen, die Docker selbst zeigt.
//
// Eine zweite Fassung neben der in httpd, und das ist Absicht: privops soll für
// seine eigene Auskunft nicht von der Darstellungsschicht abhängen. Die
// Alternative wäre, die Kennung roh zu liefern und die Kürzung oben zu machen —
// dann stünde in einem Ereignis eine 64-stellige Zeichenfolge, wo daneben ein
// Name steht.
func kurzeKennungPrivops(id string) string {
	id = strings.TrimPrefix(id, "sha256:")
	if len(id) > 12 {
		return id[:12]
	}
	return id
}

// ------------------------------------------------------------------ Ports ---

// Veroeffentlichung ist ein Port, den ein Container auf dem Wirt belegt.
type Veroeffentlichung struct {
	// Adresse ist die Wirtsadresse: "0.0.0.0" heißt von überall erreichbar,
	// "127.0.0.1" nur lokal. Der Unterschied ist die ganze Aussage dieser Liste.
	Adresse string `json:"adresse"`
	// WirtPort ist der Port auf dem Server, ContainerPort der im Container.
	WirtPort      int    `json:"wirt_port"`
	ContainerPort int    `json:"container_port"`
	Protokoll     string `json:"protokoll"`
	Roh           string `json:"roh"`
}

// parseDockerPorts liest die Ports-Spalte von "docker ps".
//
// Aufgezeichnete Formen (Docker 27):
//
//	0.0.0.0:8080->80/tcp, :::8080->80/tcp
//	127.0.0.1:5432->5432/tcp
//	0.0.0.0:32768->6379/tcp
//	80/tcp                          (nur EXPOSE, nicht veröffentlicht)
//
// Ein Eintrag ohne "->" ist nicht veröffentlicht: Er sagt nur, worauf der
// Container selbst hört, und ist vom Wirt aus nicht erreichbar. Er gehört
// deshalb NICHT in diese Liste — eine Portübersicht, in der Ports stehen, die
// keiner erreicht, ist keine.
//
// Die IPv6-Doppelung ("0.0.0.0:8080->80/tcp, :::8080->80/tcp") ist derselbe
// Port zweimal. Sie wird zusammengefasst, weil sie eine Veröffentlichung ist
// und keine zwei — zwei Zeilen dafür wären eine Verdopplung, die niemand
// erklären kann.
func parseDockerPorts(spalte string) []Veroeffentlichung {
	out := []Veroeffentlichung{}
	gesehen := map[string]int{} // Schlüssel -> Stelle in out

	for _, teil := range strings.Split(spalte, ",") {
		teil = strings.TrimSpace(teil)
		if teil == "" {
			continue
		}
		links, rechts, ok := strings.Cut(teil, "->")
		if !ok {
			// Nur EXPOSE, keine Veröffentlichung.
			continue
		}

		containerPort, protokoll := portUndProtokoll(rechts)
		if containerPort == 0 {
			continue
		}
		adresse, wirtPort := adresseUndPort(links)
		if wirtPort == 0 {
			continue
		}

		schluessel := strconv.Itoa(wirtPort) + "/" + protokoll
		if i, da := gesehen[schluessel]; da {
			// Dieselbe Veröffentlichung über die andere Adressfamilie. Die
			// OFFENERE Bindung gewinnt: Ist eine der beiden von überall
			// erreichbar, ist der Port von überall erreichbar, und das ist die
			// Aussage, die zählt. Steht schon eine offene da, bleibt sie —
			// „0.0.0.0" und „::" sagen dasselbe, und die zuerst gelesene ist die
			// vertrautere.
			if istAlleAdressen(adresse) && !istAlleAdressen(out[i].Adresse) {
				out[i].Adresse = adresse
			}
			out[i].Roh += ", " + teil
			continue
		}
		gesehen[schluessel] = len(out)
		out = append(out, Veroeffentlichung{
			Adresse: adresse, WirtPort: wirtPort,
			ContainerPort: containerPort, Protokoll: protokoll, Roh: teil,
		})
	}
	return out
}

// ParsePorts ist die öffentliche Fassung von parseDockerPorts.
//
// Sie steht hier und nicht in httpd, weil die Form der Zeichenkette eine
// Eigenschaft von Docker ist: Sie gehört neben die aufgezeichnete Ausgabe und
// neben ihre Tests, nicht in die Schicht, die daraus eine Tabelle macht.
func ParsePorts(spalte string) []Veroeffentlichung { return parseDockerPorts(spalte) }

// IstAlleAdressen sagt, ob eine Bindung von außen erreichbar ist.
func IstAlleAdressen(adresse string) bool { return istAlleAdressen(adresse) }

// adresseUndPort zerlegt "0.0.0.0:8080", "127.0.0.1:5432", ":::8080" und "8080".
func adresseUndPort(s string) (string, int) {
	s = strings.TrimSpace(s)
	i := strings.LastIndexByte(s, ':')
	if i < 0 {
		return "0.0.0.0", zahlAus(s)
	}
	adresse := strings.Trim(s[:i], "[]")
	if adresse == "" || adresse == "::" {
		// "::" ist die IPv6-Entsprechung von 0.0.0.0 — von überall erreichbar.
		adresse = "::"
	}
	return adresse, zahlAus(s[i+1:])
}

// portUndProtokoll zerlegt "80/tcp".
func portUndProtokoll(s string) (int, string) {
	s = strings.TrimSpace(s)
	port, protokoll, ok := strings.Cut(s, "/")
	if !ok {
		return zahlAus(s), "tcp"
	}
	return zahlAus(port), strings.ToLower(protokoll)
}

// istAlleAdressen sagt, ob eine Bindung von außen erreichbar ist.
//
// Das ist die Unterscheidung, um die es auf der Portseite geht: „0.0.0.0" und
// „::" heißen „jede Schnittstelle", also auch die zum Internet. Alles andere ist
// eine bestimmte Adresse — meistens 127.0.0.1, und dann kommt niemand von außen
// heran.
func istAlleAdressen(adresse string) bool {
	switch adresse {
	case "0.0.0.0", "::", "*", "":
		return true
	default:
		return false
	}
}
