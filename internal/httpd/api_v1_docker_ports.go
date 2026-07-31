package httpd

// Portübersicht und Ereignisstrom — Schritt 6 aus docs/17-docker.md.
//
// Beides sind Adaptionen aus Arcane, und die Portseite ist hier stärker als
// dort: Asylum kennt die Firewall. Daraus entsteht die Auskunft, wegen der diese
// Seite überhaupt existiert — und sie ist unbequem.
//
// **Docker geht an ufw vorbei.** Wer einen Container mit „-p 8080:80"
// veröffentlicht, ist auf Port 8080 aus dem Internet erreichbar, auch wenn ufw
// eingeschaltet ist und diesen Port nicht kennt. Der Grund ist die Reihenfolge
// der iptables-Ketten: Docker trägt seine Weiterleitung in FORWARD ein, bevor
// die Kette von ufw drankommt. Auf einem VPS ist das die häufigste
// Fehlvorstellung überhaupt — „ich habe eine Firewall" und „der Port ist zu"
// sind zwei verschiedene Aussagen, und nur die erste stimmt.
//
// Die Seite sagt das ausdrücklich, statt einen grünen Haken zu zeigen, weil ufw
// läuft. Grundsatz IV: Was das Panel weiß, sagt es — auch wenn es die
// unangenehmere Auskunft ist.

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"sort"
	"strconv"
	"sync/atomic"
	"time"

	"github.com/philf90/asylum/internal/privops"
)

// Die Urteilsstufen der Portseite. Sie stehen als Konstanten, weil die
// Oberfläche danach färbt und ein Tippfehler sonst eine farblose Zeile ergäbe.
const (
	// PortNurLokal: auf 127.0.0.1 gebunden. Von außen kommt niemand heran.
	portNurLokal = "lokal"
	// PortOffenErlaubt: von überall erreichbar, und ufw hat eine Regel dafür.
	// Das ist ein bewusst geöffneter Port.
	portOffenErlaubt = "offen"
	// PortOffenUnbemerkt: von überall erreichbar, OHNE dass ufw ihn kennt. Der
	// Befund dieser Seite.
	portOffenUnbemerkt = "unbemerkt"
	// PortOhneFirewall: von überall erreichbar, und es gibt keine Firewall, die
	// etwas dazu sagen könnte.
	portOhneFirewall = "ohnewache"
)

// apiPort ist eine Zeile der Portübersicht.
type apiPort struct {
	WirtPort      int    `json:"wirt_port"`
	ContainerPort int    `json:"container_port"`
	Protokoll     string `json:"protokoll"`
	// Adresse ist die Wirtsadresse der Bindung. Sie steht roh da, weil der
	// Unterschied zwischen „0.0.0.0" und „127.0.0.1" die ganze Aussage ist.
	Adresse   string `json:"adresse"`
	Container string `json:"container"`
	Stack     string `json:"stack"`
	Dienst    string `json:"dienst"`
	Image     string `json:"image"`
	// Urteil ist eine der vier Stufen oben. Die Oberfläche färbt danach und
	// rechnet es nicht nach.
	Urteil string `json:"urteil"`
	// Stufe ist die Farbe: gut, info, warn, schlecht.
	Stufe string `json:"stufe"`
	// Kurz ist das Urteil in zwei Wörtern — das, was in der Spalte steht.
	// Satz ist die Begründung.
	//
	// Zwei Felder und nicht eines, und der Grund stand im Bildschirmfoto: Der
	// ganze Satz in der Zelle wurde am Tabellenrand abgeschnitten, ausgerechnet
	// bei dem Befund, wegen dessen es die Seite gibt. Dazu kam, dass er sich in
	// jeder Zeile derselben Art wiederholte. Die Begründung steht jetzt EINMAL
	// über der Tabelle und am Feld als Titel; in der Spalte steht das Urteil.
	Kurz string `json:"kurz"`
	Satz string `json:"satz"`
	// PanelPort markiert den Port, über den diese Seite gerade ausgeliefert
	// wird. Ihn zu schließen wäre der Selbstausschluss.
	PanelPort bool `json:"panel_port"`
}

// apiPortliste ist die Antwort von GET /api/v1/docker/ports.
type apiPortliste struct {
	Zeilen []apiPort `json:"zeilen"`
	// Unbemerkt ist die Zahl der Ports, die offen sind, ohne dass die Firewall
	// sie kennt. Sie steht getrennt, weil sie der Anlass der Seite ist.
	Unbemerkt int `json:"unbemerkt"`
	Offen     int `json:"offen"`
	Lokal     int `json:"lokal"`
	// FirewallAktiv sagt, ob überhaupt eine Firewall läuft. Ohne die Angabe
	// sähen „kein Befund" und „niemand hat nachgesehen" gleich aus.
	FirewallAktiv bool `json:"firewall_aktiv"`
	// Warnung ist der Satz zur Umgehung von ufw. Er steht nur da, wenn er
	// zutrifft — ein Satz, der immer dasteht, wird nicht gelesen.
	Warnung string `json:"warnung,omitempty"`
	Fehler  string `json:"fehler,omitempty"`
}

// handleAPIDockerPorts listet alle veröffentlichten Ports mit ihrem Urteil.
func (s *Server) handleAPIDockerPorts(w http.ResponseWriter, r *http.Request) {
	antwort := apiPortliste{Zeilen: []apiPort{}}

	container, err := s.ops.DockerContainers(r.Context())
	if err != nil {
		antwort.Fehler = err.Error()
		s.apiJSON(w, http.StatusOK, antwort)
		return
	}

	// Die Firewall darf fehlen, ohne die Liste zu kippen: Dann steht bei jedem
	// offenen Port „keine Firewall" statt eines Urteils, das niemand belegen
	// kann.
	var fw privops.FirewallState
	if st, err := s.ops.FirewallState(r.Context()); err == nil {
		fw = st
	}
	antwort.FirewallAktiv = fw.Active

	for _, c := range container {
		// Nur laufende Container: Ein gestoppter belegt keinen Port. Die
		// Ports-Spalte von "docker ps --all" trägt bei ihm trotzdem noch die
		// alte Angabe, und die als offenen Port zu zeigen wäre eine Unwahrheit.
		if c.Zustand != "running" {
			continue
		}
		for _, v := range parseVeroeffentlichungen(c.Ports) {
			z := apiPort{
				WirtPort: v.WirtPort, ContainerPort: v.ContainerPort,
				Protokoll: v.Protokoll, Adresse: v.Adresse,
				Container: c.Name, Stack: c.Stack, Dienst: c.Dienst, Image: c.Image,
				PanelPort: v.WirtPort == s.cfg.Server.Port,
			}
			z.Urteil, z.Stufe, z.Kurz, z.Satz = porturteil(v, fw)
			antwort.Zeilen = append(antwort.Zeilen, z)

			switch z.Urteil {
			case portNurLokal:
				antwort.Lokal++
			case portOffenUnbemerkt:
				antwort.Unbemerkt++
			default:
				antwort.Offen++
			}
		}
	}

	// Unbemerktes zuerst, dann nach Portnummer. Wer die Seite öffnet, sucht das,
	// was er nicht wusste; nach Nummer sortiert stünde es irgendwo in der Mitte.
	sort.SliceStable(antwort.Zeilen, func(i, j int) bool {
		a, b := antwort.Zeilen[i], antwort.Zeilen[j]
		if ra, rb := a.Urteil == portOffenUnbemerkt, b.Urteil == portOffenUnbemerkt; ra != rb {
			return ra
		}
		return a.WirtPort < b.WirtPort
	})

	if antwort.Unbemerkt > 0 && fw.Active {
		antwort.Warnung = "Diese Ports sind aus dem Netz erreichbar, obwohl ufw sie nicht " +
			"kennt. Das ist kein Fehler von ufw: Docker trägt seine Weiterleitungen vor " +
			"den Ketten der Firewall ein, und damit greift eine ufw-Regel für einen " +
			"veröffentlichten Containerport nicht. Wer den Port nicht von außen " +
			"anbieten will, veröffentlicht ihn auf 127.0.0.1 statt auf allen Adressen."
	}

	s.apiJSON(w, http.StatusOK, antwort)
}

// parseVeroeffentlichungen ist die Brücke zum Parser in privops.
//
// Eine eigene Zeile dafür, weil der Parser dort steht, wo auch die aufgezeichnete
// Ausgabe von "docker ps" steht — die Form der Zeichenkette ist eine Eigenschaft
// von Docker und keine dieser Schicht.
func parseVeroeffentlichungen(spalte string) []privops.Veroeffentlichung {
	return privops.ParsePorts(spalte)
}

// porturteil entscheidet, was ein Port bedeutet.
//
// Vier Ausgänge, und der dritte ist der Grund für die ganze Seite:
//
//  1. Auf eine bestimmte Adresse gebunden (meist 127.0.0.1) — von außen kommt
//     niemand heran, unabhängig von jeder Firewall.
//  2. Von überall erreichbar, und ufw hat eine Regel dafür — ein bewusst
//     geöffneter Port.
//  3. Von überall erreichbar, OHNE dass ufw ihn kennt. Der Port ist trotzdem
//     offen, weil Docker an ufw vorbeigeht. Genau hier irrt sich die
//     Vorstellung, die die meisten von ihrer Firewall haben.
//  4. Von überall erreichbar, und es gibt keine Firewall — dann gibt es auch
//     nichts zu vergleichen, und das ist eine eigene Aussage.
func porturteil(v privops.Veroeffentlichung, fw privops.FirewallState) (urteil, stufe, kurz, satz string) {
	if !privops.IstAlleAdressen(v.Adresse) {
		return portNurLokal, "gut", "nur lokal",
			"Nur auf " + v.Adresse + " gebunden — aus dem Netz nicht erreichbar."
	}
	if !fw.Active {
		return portOhneFirewall, "warn", "aus dem Netz",
			"Aus dem Netz erreichbar. Es läuft keine Firewall, die etwas dazu sagen könnte."
	}
	if firewallKenntPort(fw, v.WirtPort, v.Protokoll) {
		return portOffenErlaubt, "info", "aus dem Netz, erlaubt",
			"Aus dem Netz erreichbar. Die Firewall hat eine Regel für " +
				strconv.Itoa(v.WirtPort) + "/" + v.Protokoll + " — das ist so gewollt."
	}
	return portOffenUnbemerkt, "schlecht", "aus dem Netz, ohne Regel",
		"Aus dem Netz erreichbar, obwohl ufw diesen Port nicht kennt. " +
			"Docker geht an ufw vorbei — eine Regel dort ändert daran nichts."
}

// firewallKenntPort sucht eine passende Regel.
//
// Verglichen werden Port UND Protokoll. Eine Regel ohne Protokoll gilt für
// beide — so legt ufw sie an, wenn niemand eines nennt.
func firewallKenntPort(fw privops.FirewallState, port int, protokoll string) bool {
	for _, r := range fw.Rules {
		if r.Port != port {
			continue
		}
		if r.Protocol == "" || r.Protocol == protokoll {
			return true
		}
	}
	return false
}

// ---------------------------------------------------------- Ereignisstrom ---

// maxDockerEreignisFolger begrenzt, wie viele Verbindungen gleichzeitig
// zusehen. Jede hält einen eigenen docker-Prozess — dieselbe Schranke und
// dieselbe Begründung wie beim Containerprotokoll.
const maxDockerEreignisFolger = 4

// apiEreignis ist ein Ereignis für die Oberfläche.
//
// Die Zeit steht als fertige Zeichenkette und nicht als Zeitstempel: Sie wird
// nur angezeigt, und eine Formatierung im Browser wäre eine zweite Auslegung
// derselben Angabe. Dieselbe Entscheidung wie bei den Logzeilen.
type apiEreignis struct {
	Zeit   string `json:"zeit"`
	Art    string `json:"art"`
	Aktion string `json:"aktion"`
	Objekt string `json:"objekt"`
	Stack  string `json:"stack"`
	Dienst string `json:"dienst"`
	Zusatz string `json:"zusatz"`
	// Ernst markiert die Ereignisse, die man sucht: gestorbene Container,
	// getötete Prozesse, ungesunde Prüfungen, ein toter Daemon. Der Rest ist
	// Betriebsgeräusch.
	Ernst bool `json:"ernst"`
}

// handleAPIDockerEvents verfolgt den Ereignisstrom.
//
// Dasselbe Muster wie beim Containerprotokoll und aus denselben Gründen: Platz
// nehmen vor dem ersten Byte, Zeilen über einen Kanal zurück in diese Goroutine
// (ein ResponseWriter gehört einem Faden), Herzschlag gegen Zwischenserver,
// Verworfenes wird gemeldet statt verschwiegen.
func (s *Server) handleAPIDockerEvents(w http.ResponseWriter, r *http.Request) {
	for {
		aktuell := s.dockerFolger.Load()
		if aktuell >= maxDockerEreignisFolger {
			s.apiFehler(w, http.StatusTooManyRequests,
				"Es sehen schon zu viele Verbindungen dem Ereignisstrom zu. "+
					"Bitte einen anderen Tab schließen.")
			return
		}
		if s.dockerFolger.CompareAndSwap(aktuell, aktuell+1) {
			break
		}
	}
	defer s.dockerFolger.Add(-1)

	rc := http.NewResponseController(w)
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Accel-Buffering", "no")
	w.WriteHeader(http.StatusOK)
	if err := rc.Flush(); err != nil {
		return
	}

	ereignisse := make(chan apiEreignis, 256)
	verworfen := &atomic.Int64{}

	ctx := r.Context()
	fehler := make(chan error, 1)
	go func() {
		fehler <- s.ops.DockerEventsFollow(ctx, func(e privops.DockerEreignis) {
			select {
			case ereignisse <- ereignisAus(e):
			default:
				verworfen.Add(1)
			}
		})
		close(ereignisse)
	}()

	herzschlag := time.NewTicker(25 * time.Second)
	defer herzschlag.Stop()

	for {
		select {
		case <-ctx.Done():
			return

		case <-herzschlag.C:
			if _, err := w.Write([]byte(": still\n\n")); err != nil {
				return
			}
			if rc.Flush() != nil {
				return
			}

		case e, ok := <-ereignisse:
			if !ok {
				err := <-fehler
				if err != nil && !errors.Is(err, context.Canceled) {
					writeSSE(w, rc, "fehler", err.Error())
				}
				if n := verworfen.Load(); n > 0 {
					writeSSE(w, rc, "verworfen", strconv.FormatInt(n, 10))
				}
				writeSSE(w, rc, "ende", "")
				return
			}
			if !writeSSEObjekt(w, rc, "ereignis", e) {
				return
			}
		}
	}
}

// writeSSEObjekt schickt ein Objekt als Ereignis.
//
// Eigene Fassung neben writeSSE, weil dieses Ereignis kein Text ist, sondern
// sieben Felder. Sie als eine Zeile zu übertragen und im Browser wieder zu
// zerlegen hieße, ein zweites Format zu erfinden, das nur diese eine Stelle
// kennt.
func writeSSEObjekt(w http.ResponseWriter, rc *http.ResponseController, event string, wert any) bool {
	roh, err := json.Marshal(wert)
	if err != nil {
		return false
	}
	if _, err := fmt.Fprintf(w, "event: %s\ndata: %s\n\n", event, roh); err != nil {
		return false
	}
	return rc.Flush() == nil
}

// ereignisAus baut die Zeile für die Oberfläche.
func ereignisAus(e privops.DockerEreignis) apiEreignis {
	z := apiEreignis{
		Art: e.Art, Aktion: e.Aktion, Objekt: e.Objekt,
		Stack: e.Stack, Dienst: e.Dienst, Zusatz: e.Zusatz,
		Ernst: ernstesEreignis(e),
	}
	if !e.Zeit.IsZero() {
		z.Zeit = e.Zeit.Local().Format("15:04:05")
	}
	return z
}

// ernstesEreignis trennt den Befund vom Betriebsgeräusch.
//
// Ein Container, der startet, ist normal. Einer, der mit einem Code ungleich
// null stirbt, ist der Grund, warum jemand diese Fläche geöffnet hat — und in
// einem Strom, in dem jede Zeile gleich aussieht, findet er ihn nicht.
func ernstesEreignis(e privops.DockerEreignis) bool {
	switch e.Aktion {
	case "kill", "oom":
		return true
	case "die":
		// Mit Code 0 beendet ist ein aufgeräumter Container und kein Befund —
		// ein einmaliger Auftrag etwa. Dieselbe Unterscheidung wie in
		// containerStufe.
		return e.Zusatz != "" && e.Zusatz != "Exit 0"
	case "health_status":
		return e.Zusatz != "healthy"
	default:
		return false
	}
}
