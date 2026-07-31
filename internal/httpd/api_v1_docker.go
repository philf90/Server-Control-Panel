package httpd

// Docker über /api/v1.
//
// Die Stufe 0.5 aus docs/16-neukonzeption.md §5, ausgeführt in docs/17-docker.md.
// Dies ist der erste Schritt: der Zustand der Laufzeit und ihre Installation.
// Container, Stacks und Bestand folgen — die Seite sagt das, statt eine leere
// Fläche zu zeigen.
//
// Zwei Festlegungen, die für das ganze Modul gelten und deshalb hier stehen:
//
//  1. **Schreiben verlangt die Owner-Rolle**, nicht bloß Schreibrecht. Der Grund
//     ist derselbe wie bei den Zeitplänen, nur schärfer: Wer den Docker-Socket
//     hat, hat die Maschine — ein Container mit "-v /:/host" ist root auf dem
//     Wirt. Ein Admin-Konto, das Dienste neu starten darf, soll nicht nebenbei
//     die Rechtetrennung des Servers aufheben können.
//  2. **Die Rechteauskunft steht in der Antwort des Moduls** (DarfAendern) und
//     nicht in der Sitzung. „Darf schreiben" ist die falsche Frage — sie
//     stimmte für Dienste und wäre hier zu weit. Eine zweite Auslegung der
//     Rolle in der Oberfläche wäre die Stelle, an der beide auseinanderlaufen.
//
// Warum die Installation ein Vorgang ist und keine Anfrage: apt-get läuft
// Minuten und schreibt dabei Zeilen, die man sehen will. Dasselbe Muster wie
// bei ufw seit rc.4 — und dieselbe Haltung: Fehlt das Werkzeug, bietet das
// Panel es an, statt eine Kommandozeile zum Abtippen zu drucken.

import (
	"context"
	"errors"
	"net/http"
	"sort"
	"strconv"
	"strings"
	"sync/atomic"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// jobDockerInstall ist die Vorgangsart der Installation.
const jobDockerInstall = "docker-install"

// apiDocker ist die Antwort von GET /api/v1/docker.
//
// Sie trägt den vollständigen Zustand der Seite in einem Aufruf, einschließlich
// eines laufenden Vorgangs: Wer die Seite neu lädt, während apt arbeitet, muss
// den Auszug vorfinden und nicht einen Knopf, der behauptet, es sei nichts los.
type apiDocker struct {
	Installiert bool `json:"installiert"`
	// Paket nennt, woher Docker stammt — "docker.io", "docker-ce" oder leer bei
	// einer Installation an apt vorbei. Grundsatz IV: Was das Panel weiß, sagt es.
	Paket             string `json:"paket"`
	DaemonLaeuft      bool   `json:"daemon_laeuft"`
	ClientVersion     string `json:"client_version"`
	ServerVersion     string `json:"server_version"`
	ComposeVerfuegbar bool   `json:"compose_verfuegbar"`
	ComposeVersion    string `json:"compose_version"`
	// Anmerkung ist der nächste Handgriff zum Zustand, vom Server formuliert.
	Anmerkung string `json:"anmerkung"`
	// Einspielbar sagt, ob das Panel hier etwas ausrichten kann. Nicht dasselbe
	// wie "nicht installiert": Bei totem Daemon oder bei Docker an apt vorbei
	// hilft kein apt-Lauf, und ein Knopf, der zuverlässig nichts bewirkt, ist
	// schlimmer als keiner.
	Einspielbar bool `json:"einspielbar"`
	// DarfAendern sagt, ob diese Sitzung das Modul bedienen darf — Owner-Rolle.
	DarfAendern bool    `json:"darf_aendern"`
	Job         *apiJob `json:"job"`
	// Fehler steht als Feld und nicht als Statuscode: Der Rest der Auskunft gilt
	// weiter, und eine Seite, die wegen einer Teilauskunft ganz leer bleibt,
	// verschweigt mehr als sie erklärt.
	Fehler string `json:"fehler,omitempty"`
}

// handleAPIDocker liefert den Zustand der Container-Laufzeit.
func (s *Server) handleAPIDocker(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	antwort := apiDocker{
		DarfAendern: user.CanManageUsers(),
		Job:         s.jobAus(jobDockerInstall),
	}

	st, err := s.ops.DockerState(r.Context())
	if err != nil {
		antwort.Fehler = err.Error()
		s.apiJSON(w, http.StatusOK, antwort)
		return
	}

	antwort.Installiert = st.Installiert
	antwort.Paket = st.Paket
	antwort.DaemonLaeuft = st.DaemonLaeuft
	antwort.ClientVersion = st.ClientVersion
	antwort.ServerVersion = st.ServerVersion
	antwort.ComposeVerfuegbar = st.ComposeVerfuegbar
	antwort.ComposeVersion = st.ComposeVersion
	antwort.Anmerkung = dockerAnmerkung(st)

	// Einspielen hilft in genau zwei Lagen: Docker fehlt ganz, oder es ist da
	// und Compose fehlt. Läuft der Daemon nicht, ist das ein Dienstproblem —
	// dann gehört der Weg auf die Dienstseite und nicht auf einen apt-Lauf.
	antwort.Einspielbar = !st.Installiert || (st.DaemonLaeuft && !st.ComposeVerfuegbar)

	s.apiJSON(w, http.StatusOK, antwort)
}

// dockerAnmerkung formuliert den nächsten Handgriff zum Zustand.
//
// Der Satz steht hier und nicht in privops, obwohl FirewallState seinen eigenen
// mitbringt. Der Unterschied: „ufw ist installiert, aber die Installation ist
// unvollständig" ist eine Auskunft über das System, „das Panel kann es
// einspielen" ist eine Empfehlung an den Bedienenden. Empfehlung und Knopf
// gehören in dieselbe Schicht — sonst kann eines von beiden fehlen, ohne dass
// es auffällt.
//
// Genau das ist passiert: Solange der Satz aus privops kam, zeigte die Seite im
// Zustand „nicht installiert" drei Karten und kein einziges Wort dazu. Gefunden
// hat das der Browsertest, kein Go-Test — der prüfte das Feld, das er selbst
// gesetzt hatte.
//
// Die Reihenfolge ist die der Handgriffe und nicht die der Schwere: Was zuerst
// fehlt, wird zuerst genannt.
func dockerAnmerkung(st privops.DockerState) string {
	switch {
	case !st.Installiert:
		return "Docker ist auf diesem Server nicht installiert. Das Panel kann es aus " +
			"den Paketquellen der Distribution einspielen."
	case !st.DaemonLaeuft:
		return "Docker ist installiert, aber der Daemon antwortet nicht. Unter Dienste " +
			"lässt sich docker.service starten — ein apt-Lauf hilft hier nicht."
	case !st.ComposeVerfuegbar:
		return "Docker läuft, aber \"docker compose\" fehlt. Stacks brauchen es; " +
			"das Panel kann es nachziehen."
	default:
		return ""
	}
}

// handleAPIDockerInstall spielt Docker ein — als Vorgang, wie ufw.
//
// Ohne Rückfrage: Ein Paket aus den Quellen der Distribution zu installieren
// nimmt nichts weg und sperrt niemanden aus. Stufe 1 nach
// docs/14-bestaetigungen.md. Die Rückfragen dieses Moduls kommen mit den
// Aktionen, die etwas beenden oder löschen.
func (s *Server) handleAPIDockerInstall(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	j, neu := s.jobs.start(jobDockerInstall, user.Username)
	if !neu {
		s.apiFehler(w, http.StatusConflict, "Die Installation läuft bereits.")
		return
	}
	s.audit(r, "docker.install", "docker", store.ResultOK, "gestartet")

	// Eigener Kontext, wie bei jedem apt-Lauf: Ein abgebrochener Seitenaufruf
	// darf kein halb konfiguriertes dpkg hinterlassen.
	go func() { //nolint:gosec // eigener Kontext ist hier Absicht
		ctx, cancel := context.WithTimeout(context.Background(), 20*time.Minute)
		defer cancel()

		err := s.ops.DockerInstall(ctx, j.append)
		j.finish(err)

		result, detail := store.ResultOK, "abgeschlossen"
		if err != nil {
			result, detail = store.ResultError, err.Error()
		}
		s.auditNachtraeglich(user.Username, "docker.install", "docker", result, detail)
	}()

	s.gestartet(w, jobDockerInstall, "Docker wird eingespielt.")
}

// ---------------------------------------------------------------- Container ---

// maxDockerLogFolger begrenzt die gleichzeitig offenen Containerprotokolle.
//
// Dieselbe Überlegung wie beim Journal (maxLogFolger): Jeder Betrachter hält
// einen eigenen "docker logs --follow"-Prozess, weil jeder einem anderen
// Container zusieht. Vier reichen für ein Panel mit ein paar Bedienenden. Die
// Zählung ist von der des Journals getrennt — ein offenes Containerprotokoll
// soll nicht den Blick ins Journal versperren und umgekehrt.
const maxDockerLogFolger = 4

// apiContainer ist eine Zeile der Containerliste.
type apiContainer struct {
	ID   string `json:"id"`
	Name string `json:"name"`
	// Kurz ist die auf zwölf Stellen gekürzte Kennung. Der Server kürzt, damit
	// die Liste und der Inspektor dieselbe Zahl zeigen — kürzte der Browser,
	// wäre es zweimal dieselbe Regel und einmal falsch.
	Kurz    string `json:"kurz"`
	Image   string `json:"image"`
	Zustand string `json:"zustand"`
	Status  string `json:"status"`
	// ZustandStufe ist gut, warn, schlecht oder info — die Farbe, die die
	// Oberfläche zeigt. Sie kommt vom Server, damit es eine Auslegung gibt und
	// nicht je Modul eine eigene.
	ZustandStufe string `json:"zustand_stufe"`
	Gesundheit   string `json:"gesundheit"`
	Ports        string `json:"ports"`
	Stack        string `json:"stack"`
	Dienst       string `json:"dienst"`
	// Auffaellig heißt: Dieser Container braucht Aufmerksamkeit — ungesund,
	// mit Fehlercode beendet, in einer Neustartschleife. Der Server rechnet es
	// aus, weil dieselbe Regel auch den Handlungsbedarf der Übersicht speist.
	Auffaellig bool `json:"auffaellig"`
	// Aktionen sind die Handgriffe, die zum Zustand passen. Bedienhilfe, keine
	// Rechteprüfung — verbindlich ist der Handler. Der Grund, sie zu rechnen:
	// Ein Knopf „starten" an einem laufenden Container läuft in einen Fehler,
	// und dann ist der Knopf schon der Fehler.
	Aktionen []string `json:"aktionen"`
}

// apiContainerZaehler sind die Filter über der Liste. Grundsatz II: jede Zahl
// ist ein Griff.
type apiContainerZaehler struct {
	Alle       int `json:"alle"`
	Laufend    int `json:"laufend"`
	Gestoppt   int `json:"gestoppt"`
	Auffaellig int `json:"auffaellig"`
}

// apiContainerDetail ist die Antwort auf die Auswahl einer Zeile.
type apiContainerDetail struct {
	apiContainer
	Befehl        string `json:"befehl"`
	Neustartregel string `json:"neustartregel"`
	ExitCode      int    `json:"exit_code"`
	Privilegiert  bool   `json:"privilegiert"`
	Benutzer      string `json:"benutzer"`
	Erstellt      string `json:"erstellt"`
	// Umgebung ist die ANZAHL der Umgebungsvariablen. Ihre Werte gibt es nicht,
	// und zwar an keiner Stelle dieser Schnittstelle: Sie tragen auf jedem
	// zweiten Server ein Datenbankpasswort. Wer sie braucht, hat SSH — dasselbe
	// Argument wie bei der Sperrliste des Dateimanagers.
	Umgebung int               `json:"umgebung"`
	Mounts   []apiMount        `json:"mounts"`
	Netze    []string          `json:"netze"`
	Stats    *apiContainerStat `json:"stats"`
	// Zeilen ist der Auszug des Protokolls. Er kommt mit dem Detail und nicht
	// als zweiter Aufruf: Wer einen Container anklickt, will wissen, was er
	// sagt.
	Zeilen []string `json:"zeilen"`
	// FolgerFrei sagt, ob noch ein Strom offen sein darf. Ohne die Auskunft
	// zeigte die Oberfläche einen Knopf, der zuverlässig 429 ergibt.
	FolgerFrei bool   `json:"folger_frei"`
	Fehler     string `json:"fehler,omitempty"`
}

type apiMount struct {
	Art       string `json:"art"`
	Quelle    string `json:"quelle"`
	Ziel      string `json:"ziel"`
	Schreibar bool   `json:"schreibbar"`
	// AusserhalbStack markiert einen Bind-Mount, der Wirtspfade in den Container
	// holt. Er ist erlaubt und häufig — aber er ist der Weg, über den ein
	// Container an fremde Daten kommt, und deshalb steht er sichtbar da.
	Bind bool `json:"bind"`
}

type apiContainerStat struct {
	CPU     string `json:"cpu"`
	Speiche string `json:"speicher"`
	SpeiPro string `json:"speicher_prozent"`
	Netz    string `json:"netz"`
	Platte  string `json:"platte"`
	PIDs    string `json:"pids"`
}

// apiContainerListe ist die Antwort von GET /api/v1/docker/containers.
type apiContainerListe struct {
	Zeilen  []apiContainer      `json:"zeilen"`
	Zaehler apiContainerZaehler `json:"zaehler"`
	// DarfAendern: Owner-Rolle. Siehe den Kopf dieser Datei.
	DarfAendern bool   `json:"darf_aendern"`
	Fehler      string `json:"fehler,omitempty"`
}

// containerAus baut die Listenzeile aus der Auskunft von privops.
func containerAus(c privops.Container) apiContainer {
	z := apiContainer{
		ID:         c.ID,
		Kurz:       kurzeKennung(c.ID),
		Name:       c.Name,
		Image:      c.Image,
		Zustand:    c.Zustand,
		Status:     c.Status,
		Gesundheit: c.Gesundheit,
		Ports:      c.Ports,
		Stack:      c.Stack,
		Dienst:     c.Dienst,
	}
	z.ZustandStufe, z.Auffaellig = containerStufe(c)
	z.Aktionen = containerAktionen(c.Zustand)
	return z
}

// kurzeKennung kürzt auf die zwölf Stellen, die Docker selbst zeigt.
func kurzeKennung(id string) string {
	if len(id) > 12 {
		return id[:12]
	}
	return id
}

// containerStufe entscheidet Farbe und Auffälligkeit.
//
// Die Regel steht an EINER Stelle, weil sie zweimal gebraucht wird: hier für die
// Liste und in dashboardSignals für den Handlungsbedarf der Übersicht. Zwei
// Fassungen liefen auseinander, und dann meldete die Übersicht einen Befund,
// den die Containerliste nicht kennt.
func containerStufe(c privops.Container) (stufe string, auffaellig bool) {
	switch {
	case c.Gesundheit == "unhealthy":
		// Ein laufender, aber ungesunder Container ist der Fall, den man am
		// leichtesten übersieht: Er steht auf „läuft" und tut trotzdem nicht,
		// wofür er da ist.
		return "schlecht", true
	case c.Zustand == "restarting":
		return "schlecht", true
	case c.Zustand == "dead":
		return "schlecht", true
	case c.Zustand == "exited":
		// Mit Code 0 beendet ist ein aufgeräumter Container und kein Befund —
		// ein einmaliger Auftrag etwa. Alles andere ist einer.
		if strings.Contains(c.Status, "Exited (0)") {
			return "info", false
		}
		return "warn", true
	case c.Zustand == "paused":
		return "warn", false
	case c.Zustand == "running":
		return "gut", false
	default:
		return "info", false
	}
}

// containerAktionen nennt die Handgriffe, die zum Zustand passen.
func containerAktionen(zustand string) []string {
	switch zustand {
	case "running":
		return []string{"stop", "restart", "pause", "remove"}
	case "paused":
		return []string{"unpause", "stop", "remove"}
	case "restarting":
		return []string{"stop", "remove"}
	default:
		// created, exited, dead: Was nicht läuft, lässt sich starten und weg.
		return []string{"start", "remove"}
	}
}

// handleAPIDockerContainers listet die Container.
//
// Vollständig und ungefiltert: Gefiltert wird im Browser. Ein Server hat
// selten mehr als ein paar Dutzend Container, und beim Tippen ist das Ergebnis
// dann sofort da, statt einmal je Buchstabe über docker zu gehen. Was der
// Server rechnet, rechnet der Browser nicht nach: Zustandsstufe, Zähler,
// Sortierung und die passenden Handgriffe stehen in der Antwort.
func (s *Server) handleAPIDockerContainers(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	antwort := apiContainerListe{
		Zeilen:      []apiContainer{},
		DarfAendern: user.CanManageUsers(),
	}

	liste, err := s.ops.DockerContainers(r.Context())
	if err != nil {
		antwort.Fehler = err.Error()
		s.apiJSON(w, http.StatusOK, antwort)
		return
	}

	for _, c := range liste {
		z := containerAus(c)
		antwort.Zeilen = append(antwort.Zeilen, z)
		antwort.Zaehler.Alle++
		switch z.Zustand {
		case "running":
			antwort.Zaehler.Laufend++
		case "paused", "restarting":
			// Weder laufend noch gestoppt: Ein angehaltener Container belegt
			// seinen Platz weiter, ein neustartender ist unterwegs. Sie in einen
			// der beiden Zähler zu werfen machte die Zahl daneben unwahr.
		default:
			antwort.Zaehler.Gestoppt++
		}
		if z.Auffaellig {
			antwort.Zaehler.Auffaellig++
		}
	}

	// Auffälliges zuerst, dann Laufendes, dann der Rest — innerhalb nach Stack
	// und Name. Wer die Seite öffnet, sucht das, was nicht stimmt; alphabetisch
	// sortiert stünde es irgendwo in der Mitte.
	sort.SliceStable(antwort.Zeilen, func(i, j int) bool {
		a, b := antwort.Zeilen[i], antwort.Zeilen[j]
		if a.Auffaellig != b.Auffaellig {
			return a.Auffaellig
		}
		if ra, rb := a.Zustand == "running", b.Zustand == "running"; ra != rb {
			return ra
		}
		if a.Stack != b.Stack {
			return a.Stack < b.Stack
		}
		return a.Name < b.Name
	})

	s.apiJSON(w, http.StatusOK, antwort)
}

// handleAPIDockerContainer liefert die Einzelheiten eines Containers.
func (s *Server) handleAPIDockerContainer(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	detail, err := s.containerDetail(r.Context(), id)
	if err != nil {
		s.apiFehler(w, http.StatusBadGateway, err.Error())
		return
	}
	s.apiJSON(w, http.StatusOK, detail)
}

// containerDetail liest Detail, Protokollauszug und Laufzeitwerte.
//
// Drei Aufrufe, und zwei davon dürfen scheitern, ohne die Auskunft zu kippen:
// Ein Container ohne Protokoll ist kein Fehler, und Statistiken gibt es nur für
// laufende. Nur das Detail selbst ist verbindlich — ohne es gibt es nichts
// anzuzeigen.
func (s *Server) containerDetail(ctx context.Context, id string) (apiContainerDetail, error) {
	d, err := s.ops.DockerContainer(ctx, id)
	if err != nil {
		return apiContainerDetail{}, err
	}

	antwort := apiContainerDetail{
		apiContainer:  containerAus(d.Container),
		Befehl:        d.Befehl,
		Neustartregel: d.Neustartregel,
		ExitCode:      d.ExitCode,
		Privilegiert:  d.Privilegiert,
		Benutzer:      d.Benutzer,
		Erstellt:      d.Erstellt,
		Umgebung:      d.Umgebung,
		Mounts:        []apiMount{},
		Netze:         d.Netze,
		Zeilen:        []string{},
		FolgerFrei:    s.dockerFolger.Load() < maxDockerLogFolger,
	}
	if antwort.Netze == nil {
		antwort.Netze = []string{}
	}
	for _, m := range d.Mounts {
		antwort.Mounts = append(antwort.Mounts, apiMount{
			Art: m.Art, Quelle: m.Quelle, Ziel: m.Ziel,
			Schreibar: m.Schreibar, Bind: m.Art == "bind",
		})
	}

	if zeilen, err := s.ops.DockerContainerLogs(ctx, id, 200); err == nil {
		antwort.Zeilen = zeilen
	}
	// Statistiken nur für Laufendes: Für einen beendeten Container gibt docker
	// stats nichts aus, und ein Aufruf dafür wäre eine Sekunde für nichts.
	if d.Zustand == "running" {
		if alle, err := s.ops.DockerStats(ctx); err == nil {
			for _, st := range alle {
				if st.ID == d.ID || st.Name == d.Name {
					antwort.Stats = &apiContainerStat{
						CPU: st.CPU, Speiche: st.Speiche, SpeiPro: st.SpeiPro,
						Netz: st.Netz, Platte: st.Platte, PIDs: st.PIDs,
					}
					break
				}
			}
		}
	}
	return antwort, nil
}

// handleAPIDockerContainerAktion schaltet oder entfernt einen Container.
//
// Die Rückfragestufen nach docs/14-bestaetigungen.md und docs/17-docker.md:
//
//   - start, restart, pause, unpause: Stufe 1. Umkehrbar mit einem zweiten Klick.
//   - stop: Stufe 2. Was der Container bereitstellt, ist danach nicht erreichbar.
//   - remove eines gestoppten: Stufe 2. Das Image bleibt, ein neuer Container
//     ist ein Handgriff.
//   - remove eines LAUFENDEN: Stufe 3 mit dem Containernamen. Es beendet einen
//     laufenden Dienst UND entfernt ihn, und beides zusammen ist keine Aktion,
//     die man versehentlich auslöst.
func (s *Server) handleAPIDockerContainerAktion(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")

	var anfrage apiAktionAnfrage
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}

	// Den Zustand VOR der Rückfrage lesen: Die Frage soll wissen, ob der
	// Container läuft — davon hängt ihre Stufe ab. Lesen ist erlaubt, solange
	// nichts verändert wurde (Kontrakt von apiBestaetigt).
	vorher, err := s.ops.DockerContainer(r.Context(), id)
	if err != nil {
		s.apiFehler(w, http.StatusBadGateway, err.Error())
		return
	}
	laeuft := vorher.Zustand == "running" || vorher.Zustand == "paused" || vorher.Zustand == "restarting"
	name := vorher.Name
	if name == "" {
		name = kurzeKennung(id)
	}

	if anfrage.Aktion == "remove" {
		if !s.dockerEntfernenBestaetigt(w, anfrage, name, laeuft) {
			return
		}
		if err := s.ops.DockerContainerRemove(r.Context(), id, laeuft); err != nil {
			s.audit(r, "docker.container.remove", name, store.ResultError, err.Error())
			s.apiFehler(w, http.StatusBadGateway, err.Error())
			return
		}
		s.audit(r, "docker.container.remove", name, store.ResultOK, "")
		// Ein entfernter Container hat kein Detail mehr. Die Antwort trägt
		// deshalb die Meldung und keinen Zustand — die Oberfläche schließt den
		// Inspektor und lädt die Liste neu.
		s.apiJSON(w, http.StatusOK, apiAktionAntwort{Meldung: name + " entfernt."})
		return
	}

	aktion := privops.ContainerAction(anfrage.Aktion)
	if !privops.ValidContainerAction(aktion) {
		s.apiFehler(w, http.StatusBadRequest, "unbekannte Aktion: "+anfrage.Aktion)
		return
	}

	if aktion == privops.ContainerStop {
		if !s.apiBestaetigt(w, anfrage, apiBestaetigung{
			Titel: "Container stoppen",
			Frage: name + " stoppen?",
			Punkte: []string{
				"Was der Container bereitstellt, ist danach nicht mehr erreichbar.",
				"Der Container bleibt bestehen und lässt sich wieder starten.",
			},
			Knopf: "stoppen",
		}) {
			return
		}
	}

	if err := s.ops.DockerContainerAction(r.Context(), id, aktion); err != nil {
		s.audit(r, "docker.container."+anfrage.Aktion, name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadGateway, err.Error())
		return
	}
	s.audit(r, "docker.container."+anfrage.Aktion, name, store.ResultOK, "")

	// Die Antwort trägt den NEU gelesenen Zustand. Ohne das müsste die
	// Oberfläche eine zweite Anfrage stellen und zeigte in der Lücke den alten —
	// was nach einem Neustart genauso aussieht wie ein Neustart, der nicht
	// geklappt hat.
	detail, err := s.containerDetail(r.Context(), id)
	if err != nil {
		s.apiJSON(w, http.StatusOK, apiAktionAntwort{Meldung: name + ": " + anfrage.Aktion + " ausgeführt."})
		return
	}
	s.apiJSON(w, http.StatusOK, struct {
		Meldung string             `json:"meldung"`
		Detail  apiContainerDetail `json:"detail"`
	}{Meldung: name + ": " + anfrage.Aktion + " ausgeführt.", Detail: detail})
}

// dockerEntfernenBestaetigt stellt die Frage zum Entfernen.
//
// Zwei Stufen an einer Aktion, und der Unterschied ist nicht Geschmack: Einen
// gestoppten Container zu entfernen räumt auf. Einen laufenden zu entfernen
// beendet einen Dienst und löscht ihn in einem Zug — dafür das getippte Wort.
func (s *Server) dockerEntfernenBestaetigt(w http.ResponseWriter, anfrage apiAktionAnfrage, name string, laeuft bool) bool {
	b := apiBestaetigung{
		Titel: "Container entfernen",
		Frage: name + " entfernen?",
		Punkte: []string{
			"Der Container wird gelöscht. Das Image bleibt liegen.",
			"Daten in benannten Volumes bleiben erhalten; Daten im Container selbst sind weg.",
		},
		Knopf: "entfernen",
	}
	if laeuft {
		b.Frage = name + " läuft. Trotzdem entfernen?"
		b.Punkte = append([]string{
			"Der Container wird zuerst gestoppt — was er bereitstellt, ist sofort nicht mehr erreichbar.",
		}, b.Punkte...)
		b.Knopf = "stoppen und entfernen"
		b.Tippen = name
		b.TippenHinweis = "Zum Bestätigen den Containernamen eingeben: " + name
	}
	return s.apiBestaetigt(w, anfrage, b)
}

// handleAPIDockerContainerLogs verfolgt das Protokoll eines Containers.
//
// Dasselbe Muster wie beim Journal (handleAPILogsFollow) und aus denselben
// Gründen: Platz nehmen vor dem ersten Byte, Zeilen über einen Kanal zurück in
// diese Goroutine (ein ResponseWriter gehört einem Faden), Herzschlag gegen
// Zwischenserver, Verworfenes wird gemeldet statt verschwiegen.
func (s *Server) handleAPIDockerContainerLogs(w http.ResponseWriter, r *http.Request) {
	for {
		aktuell := s.dockerFolger.Load()
		if aktuell >= maxDockerLogFolger {
			s.apiFehler(w, http.StatusTooManyRequests,
				"Es sehen schon zu viele Verbindungen Containerprotokollen zu. "+
					"Bitte einen anderen Tab schließen.")
			return
		}
		if s.dockerFolger.CompareAndSwap(aktuell, aktuell+1) {
			break
		}
	}
	defer s.dockerFolger.Add(-1)

	id := r.PathValue("id")

	rc := http.NewResponseController(w)
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Accel-Buffering", "no")
	w.WriteHeader(http.StatusOK)
	if err := rc.Flush(); err != nil {
		return
	}

	zeilen := make(chan string, 256)
	verworfen := &atomic.Int64{}

	ctx := r.Context()
	fehler := make(chan error, 1)
	go func() {
		fehler <- s.ops.DockerContainerLogsFollow(ctx, id, 200, func(l string) {
			select {
			case zeilen <- l:
			default:
				verworfen.Add(1)
			}
		})
		close(zeilen)
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

		case l, ok := <-zeilen:
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
			if !writeSSE(w, rc, "zeile", l) {
				return
			}
		}
	}
}
