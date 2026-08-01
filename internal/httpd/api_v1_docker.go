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

// ------------------------------------------------------------------ Bestand ---

// jobDockerPrune ist die Vorgangsart des Aufräumens.
//
// Ein Vorgang und keine Anfrage: "docker image prune --all" läuft auf einem
// Server mit fünfzig Gigabyte Images Minuten und schreibt dabei jede
// gelöschte Kennung. Eine Anfrage, die so lange offen bleibt, überlebt keinen
// Zwischenserver.
const jobDockerPrune = "docker-prune"

type apiImage struct {
	ID      string `json:"id"`
	Kurz    string `json:"kurz"`
	Name    string `json:"name"`
	Groesse string `json:"groesse"`
	Alter   string `json:"alter"`
	// Verwaist heißt: ohne Namen. Der Rest, der bei jedem Neubau übrig bleibt.
	Verwaist bool `json:"verwaist"`
	// InGebrauch heißt: Mindestens ein Container benutzt dieses Image. Die
	// Angabe kostet nichts — die Containerliste liegt ohnehin vor — und erspart
	// den Fehlversuch: Docker weigert sich, ein benutztes Image zu löschen, und
	// ein Knopf, der zuverlässig in diese Weigerung läuft, ist selbst der Fehler.
	InGebrauch bool `json:"in_gebrauch"`
}

type apiVolume struct {
	Name    string `json:"name"`
	Treiber string `json:"treiber"`
	Ort     string `json:"ort"`
	// InGebrauch: von einem Container eingehängt. Anders als beim Image ist das
	// hier die WICHTIGE Angabe — ein Volume zu löschen nimmt Daten mit, und ob
	// gerade etwas darauf schreibt, entscheidet, wie schlimm das ist.
	InGebrauch bool `json:"in_gebrauch"`
}

type apiNetz struct {
	ID      string `json:"id"`
	Kurz    string `json:"kurz"`
	Name    string `json:"name"`
	Treiber string `json:"treiber"`
	// Eingebaut sind bridge, host und none. Docker legt sie selbst an, sie
	// lassen sich nicht entfernen, und der Handgriff fehlt deshalb.
	Eingebaut bool `json:"eingebaut"`
}

type apiBestandsposten struct {
	Art        string `json:"art"`
	Anzahl     string `json:"anzahl"`
	Aktiv      string `json:"aktiv"`
	Groesse    string `json:"groesse"`
	Freigebbar string `json:"freigebbar"`
}

// apiBestand ist die Antwort von GET /api/v1/docker/bestand.
type apiBestand struct {
	Platte      []apiBestandsposten `json:"platte"`
	Images      []apiImage          `json:"images"`
	Volumes     []apiVolume         `json:"volumes"`
	Netze       []apiNetz           `json:"netze"`
	DarfAendern bool                `json:"darf_aendern"`
	Job         *apiJob             `json:"job"`
	Fehler      string              `json:"fehler,omitempty"`
}

// apiPruneAnfrage ist der Körper von POST /api/v1/docker/prune.
type apiPruneAnfrage struct {
	Art        string `json:"art"`
	Alle       bool   `json:"alle"`
	Bestaetigt bool   `json:"bestaetigt"`
	Getippt    string `json:"getippt"`
}

// handleAPIDockerBestand liefert Images, Volumes, Netze und den Platzbedarf.
//
// Vier Aufrufe in einem Endpunkt, und das ist hier richtig: Sie gehören zu einer
// Seite, keiner davon ist teuer, und getrennt hätte die Oberfläche vier
// Ladezustände nebeneinander. Ein Fehler an einer Quelle verwirft die anderen
// nicht — auf einem System ohne Baucache fehlt eben eine Zeile.
func (s *Server) handleAPIDockerBestand(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	antwort := apiBestand{
		Platte:      []apiBestandsposten{},
		Images:      []apiImage{},
		Volumes:     []apiVolume{},
		Netze:       []apiNetz{},
		DarfAendern: user.CanManageUsers(),
		Job:         s.jobAus(jobDockerPrune),
	}

	// Die Containerliste zuerst: Aus ihr kommt, was in Gebrauch ist. Scheitert
	// sie, fehlt nur diese Markierung — die Listen selbst stehen trotzdem.
	//
	// Die Einzelheiten kommen in EINEM Aufruf. Hier stand bis 0.5.1 ein N+1:
	// „docker ps" für die Liste und danach je Container ein eigenes
	// „docker inspect" — vierzig Prozesse auf einem Server mit vierzig
	// Containern, nacheinander, für ein Häkchen je Volume. Aufgefallen ist es
	// nicht, weil die Attrappe des Browsertests vier Container hat.
	//
	// Der Kontrast steht eine Zeile weiter oben: benutzteImages kommt aus der
	// Liste selbst und kostete nie etwas. Dieselbe Art Auskunft, ein Aufruf.
	benutzteImages := map[string]bool{}
	benutzteVolumes := map[string]bool{}
	if cs, err := s.ops.DockerContainers(r.Context()); err == nil {
		ids := make([]string, 0, len(cs))
		for _, c := range cs {
			benutzteImages[c.Image] = true
			ids = append(ids, c.ID)
		}
		if details, err := s.ops.DockerContainerDetails(r.Context(), ids); err == nil {
			for _, d := range details {
				for _, m := range d.Mounts {
					if m.Art == "volume" {
						benutzteVolumes[m.Quelle] = true
					}
				}
			}
		}
	}

	if posten, err := s.ops.DockerDiskUsage(r.Context()); err == nil {
		for _, p := range posten {
			antwort.Platte = append(antwort.Platte, apiBestandsposten{
				Art: p.Art, Anzahl: p.Anzahl, Aktiv: p.Aktiv,
				Groesse: p.Groesse, Freigebbar: p.Freigebbar,
			})
		}
	} else {
		antwort.Fehler = err.Error()
	}

	if images, err := s.ops.DockerImages(r.Context()); err == nil {
		for _, i := range images {
			name := i.Repo + ":" + i.Tag
			if i.Verwaist {
				name = ""
			}
			antwort.Images = append(antwort.Images, apiImage{
				ID: i.ID, Kurz: kurzeKennung(strings.TrimPrefix(i.ID, "sha256:")),
				Name: name, Groesse: i.Groesse, Alter: i.Erstellt,
				Verwaist: i.Verwaist, InGebrauch: benutzteImages[name],
			})
		}
	} else if antwort.Fehler == "" {
		antwort.Fehler = err.Error()
	}

	if vols, err := s.ops.DockerVolumes(r.Context()); err == nil {
		for _, v := range vols {
			antwort.Volumes = append(antwort.Volumes, apiVolume{
				Name: v.Name, Treiber: v.Treiber, Ort: v.Ort,
				InGebrauch: benutzteVolumes[v.Name],
			})
		}
	} else if antwort.Fehler == "" {
		antwort.Fehler = err.Error()
	}

	if netze, err := s.ops.DockerNetworks(r.Context()); err == nil {
		for _, n := range netze {
			antwort.Netze = append(antwort.Netze, apiNetz{
				ID: n.ID, Kurz: kurzeKennung(n.ID), Name: n.Name,
				Treiber: n.Treiber, Eingebaut: eingebautesNetz(n.Name),
			})
		}
	} else if antwort.Fehler == "" {
		antwort.Fehler = err.Error()
	}

	s.apiJSON(w, http.StatusOK, antwort)
}

// eingebautesNetz sagt, ob Docker das Netz selbst mitbringt.
//
// bridge, host und none entstehen mit dem Daemon und lassen sich nicht
// entfernen. Den Handgriff trotzdem anzubieten hieße, einen Knopf zu zeigen, der
// zuverlässig in eine Weigerung läuft.
func eingebautesNetz(name string) bool {
	switch name {
	case "bridge", "host", "none":
		return true
	default:
		return false
	}
}

// handleAPIDockerImageEntfernen entfernt ein Image. Stufe 2: Das Image lässt
// sich erneut ziehen, und was es kostet, ist Zeit und Bandbreite.
func (s *Server) handleAPIDockerImageEntfernen(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")

	var anfrage apiAktionAnfrage
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}
	if !s.apiBestaetigt(w, anfrage, apiBestaetigung{
		Titel: "Image entfernen",
		Frage: kurzeKennung(strings.TrimPrefix(id, "sha256:")) + " entfernen?",
		Punkte: []string{
			"Das Image wird aus der lokalen Ablage gelöscht.",
			"Es lässt sich erneut ziehen — das kostet Zeit und Bandbreite, keine Daten.",
			"Benutzt es noch ein Container, weigert sich Docker.",
		},
		Knopf: "entfernen",
	}) {
		return
	}

	if err := s.ops.DockerImageRemove(r.Context(), id); err != nil {
		s.audit(r, "docker.image.remove", id, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadGateway, err.Error())
		return
	}
	s.audit(r, "docker.image.remove", id, store.ResultOK, "")
	s.apiJSON(w, http.StatusOK, apiAktionAntwort{Meldung: "Image entfernt."})
}

// handleAPIDockerVolumeEntfernen entfernt einen Datenspeicher.
//
// Stufe 3 mit dem Volumenamen — die schärfste Einzelaktion dieses Moduls. Was
// darin liegt, ist danach weg, und kein Rückweg des Panels holt es zurück. Das
// unterscheidet ein Volume von allem anderen auf dieser Seite: Ein Image lässt
// sich ziehen, ein Netz neu anlegen, ein Container neu starten — Daten nicht.
func (s *Server) handleAPIDockerVolumeEntfernen(w http.ResponseWriter, r *http.Request) {
	name := r.PathValue("name")

	var anfrage apiAktionAnfrage
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}
	if !s.apiBestaetigt(w, anfrage, apiBestaetigung{
		Titel: "Volume entfernen",
		Frage: name + " endgültig entfernen?",
		Punkte: []string{
			"Alle Daten in diesem Volume werden gelöscht.",
			"Es gibt keinen Rückweg — weder im Panel noch über Docker.",
			"Hängt ein Container es ein, weigert sich Docker.",
		},
		Knopf:         "endgültig entfernen",
		Tippen:        name,
		TippenHinweis: "Zum Bestätigen den Namen des Volumes eingeben: " + name,
	}) {
		return
	}

	if err := s.ops.DockerVolumeRemove(r.Context(), name); err != nil {
		s.audit(r, "docker.volume.remove", name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadGateway, err.Error())
		return
	}
	s.audit(r, "docker.volume.remove", name, store.ResultOK, "")
	s.apiJSON(w, http.StatusOK, apiAktionAntwort{Meldung: name + " entfernt."})
}

// handleAPIDockerNetzEntfernen entfernt ein Netz. Stufe 2: Ein Netz lässt sich
// neu anlegen, und Compose tut das beim nächsten Start von selbst.
func (s *Server) handleAPIDockerNetzEntfernen(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")

	var anfrage apiAktionAnfrage
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}
	if !s.apiBestaetigt(w, anfrage, apiBestaetigung{
		Titel: "Netz entfernen",
		Frage: kurzeKennung(id) + " entfernen?",
		Punkte: []string{
			"Container, die daran hängen, verlieren die Verbindung untereinander.",
			"Ein Compose-Stack legt sein Netz beim nächsten Start neu an.",
		},
		Knopf: "entfernen",
	}) {
		return
	}

	if err := s.ops.DockerNetworkRemove(r.Context(), id); err != nil {
		s.audit(r, "docker.network.remove", id, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadGateway, err.Error())
		return
	}
	s.audit(r, "docker.network.remove", id, store.ResultOK, "")
	s.apiJSON(w, http.StatusOK, apiAktionAntwort{Meldung: "Netz entfernt."})
}

// handleAPIDockerPrune räumt eine Art auf.
//
// Die Rückfrage trägt die Zahlen aus "docker system df" — „alle 34 Images,
// 12,4 GB" statt „alle". Die Begründung steht in docs/14-bestaetigungen.md:
// „Alle Updates einspielen?" befähigt zu keiner Entscheidung, „alle 42" schon.
//
// Volumes sind die Ausnahme: Dort ist es Stufe 3 mit dem HOSTNAMEN, nicht mit
// einem Objektnamen. Der Grund ist die Reichweite — es trifft jedes ungenutzte
// Volume des Servers auf einmal, und der häufigste Fehler bei einer solchen
// Aktion ist nicht der falsche Knopf, sondern der falsche Server.
func (s *Server) handleAPIDockerPrune(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	var anfrage apiPruneAnfrage
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}
	art := privops.PruneArt(anfrage.Art)
	if !privops.ValidPruneArt(art) {
		s.apiFehler(w, http.StatusBadRequest, "unbekannte Aufräumart: "+anfrage.Art)
		return
	}

	// Die Zahlen für die Frage. Sie dürfen fehlen — dann fragt das Panel ohne
	// sie, statt die Aktion an einer Auskunft scheitern zu lassen.
	posten := ""
	if df, err := s.ops.DockerDiskUsage(r.Context()); err == nil {
		posten = freigebbarFuer(df, art)
	}

	b := pruneFrage(art, anfrage.Alle, posten)
	if art == privops.PruneVolumes {
		b.Tippen = s.rechnername()
		b.TippenHinweis = "Zum Bestätigen den Hostnamen eingeben: " + b.Tippen
	}
	if !s.apiBestaetigt(w, apiAktionAnfrage{
		Bestaetigt: anfrage.Bestaetigt, Getippt: anfrage.Getippt,
	}, b) {
		return
	}

	j, neu := s.jobs.start(jobDockerPrune, user.Username)
	if !neu {
		s.apiFehler(w, http.StatusConflict, "Es läuft bereits ein Aufräumvorgang.")
		return
	}
	s.audit(r, "docker.prune", string(art), store.ResultOK, "gestartet")

	alle := anfrage.Alle
	go func() { //nolint:gosec // eigener Kontext ist hier Absicht
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Minute)
		defer cancel()

		frei, err := s.ops.DockerPrune(ctx, art, alle, j.append)
		// Der freigegebene Platz ist die Antwort, wegen der jemand aufräumt. Er
		// steht als Anmerkung am Vorgang und nicht bloß irgendwo im Auszug —
		// dort müsste man ihn suchen.
		if err == nil && frei != "" {
			j.setNote(frei + " freigegeben")
		}
		j.finish(err)

		result, detail := store.ResultOK, "abgeschlossen: "+frei
		if err != nil {
			result, detail = store.ResultError, err.Error()
		}
		s.auditNachtraeglich(user.Username, "docker.prune", string(art), result, detail)
	}()

	s.gestartet(w, jobDockerPrune, "Aufräumen läuft.")
}

// freigebbarFuer sucht den passenden Posten aus "docker system df".
//
// Die Zuordnung steht hier und nicht in privops: Dockers Bezeichnungen
// ("Local Volumes", "Build Cache") sind seine, unsere Arten sind unsere, und
// eine Übersetzungstabelle gehört an die Grenze zwischen beiden.
func freigebbarFuer(posten []privops.Bestandsposten, art privops.PruneArt) string {
	gesucht := map[privops.PruneArt]string{
		privops.PruneImages:    "Images",
		privops.PruneContainer: "Containers",
		privops.PruneVolumes:   "Local Volumes",
		privops.PruneCache:     "Build Cache",
	}[art]
	if gesucht == "" {
		return ""
	}
	for _, p := range posten {
		if p.Art == gesucht {
			return p.Anzahl + " Einträge, davon " + p.Aktiv + " in Gebrauch · " +
				p.Freigebbar + " freigebbar"
		}
	}
	return ""
}

// pruneFrage formuliert die Rückfrage je Art.
func pruneFrage(art privops.PruneArt, alle bool, posten string) apiBestaetigung {
	b := apiBestaetigung{Knopf: "aufräumen"}
	switch art {
	case privops.PruneImages:
		b.Titel = "Images aufräumen"
		if alle {
			b.Frage = "Alle Images entfernen, die kein Container benutzt?"
			b.Punkte = []string{
				"Betroffen sind auch Images, die Sie für später bereitgelegt haben.",
				"Sie lassen sich erneut ziehen — das kostet Zeit und Bandbreite, keine Daten.",
			}
		} else {
			b.Frage = "Namenlose Images entfernen?"
			b.Punkte = []string{
				"Betroffen sind nur Reste ohne Namen, wie sie bei jedem Neubau anfallen.",
				"Images mit Namen bleiben liegen.",
			}
		}
	case privops.PruneContainer:
		b.Titel = "Gestoppte Container aufräumen"
		b.Frage = "Alle gestoppten Container entfernen?"
		b.Punkte = []string{
			"Laufende Container sind nicht betroffen.",
			"Daten in benannten Volumes bleiben erhalten; Daten im Container selbst sind weg.",
		}
	case privops.PruneVolumes:
		b.Titel = "Volumes aufräumen"
		b.Frage = "Alle Volumes entfernen, die kein Container einhängt?"
		b.Punkte = []string{
			"ALLE Daten in diesen Volumes werden gelöscht.",
			"Es gibt keinen Rückweg — weder im Panel noch über Docker.",
			"Ein Volume eines gestoppten Stacks gilt als ungenutzt und ist mit betroffen.",
		}
		b.Knopf = "endgültig aufräumen"
	case privops.PruneNetze:
		b.Titel = "Netze aufräumen"
		b.Frage = "Alle Netze entfernen, an denen kein Container hängt?"
		b.Punkte = []string{"Ein Compose-Stack legt sein Netz beim nächsten Start neu an."}
	case privops.PruneCache:
		b.Titel = "Baucache aufräumen"
		b.Frage = "Den Baucache leeren?"
		b.Punkte = []string{"Der nächste Bau dauert länger, weil er von vorn beginnt."}
	}
	if posten != "" {
		b.Punkte = append(b.Punkte, "Derzeit: "+posten+".")
	}
	return b
}
