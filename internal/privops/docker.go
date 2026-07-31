package privops

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// Docker: Zustand der Container-Laufzeit und ihre Installation.
//
// Warum die Kommandozeile und nicht der Socket. Der Weg über die Docker-API
// wäre bequemer und ist der, den vergleichbare Panels gehen. Hier steht er aus
// zwei Gründen nicht zur Wahl:
//
//  1. Eine Socket-Bibliothek ist ein schwerer Baustein im Abhängigkeitsbudget,
//     und sie bringt ihre eigene Fassungskopplung an den Docker-Daemon mit.
//  2. Wichtiger: Grundsatz IV — das Panel verschweigt nichts. Jede Aktion
//     dieses Moduls ist eine Kommandozeile, die im Konsolen-Echo steht und die
//     jemand nachtippen kann. Über einen Socket wäre sie ein Methodenaufruf,
//     den niemand sieht.
//
// Was das kostet, gehört dazu: Die Auskünfte kommen als Text und müssen
// geparst werden. Deshalb überall "--format", und deshalb sind die Parser
// gegen aufgezeichnete echte Ausgaben geprüft und nicht gegen die Vorstellung
// davon, wie Docker antwortet.
//
// Die Sicherheitsbetrachtung des Moduls steht in docs/17-docker.md. Der eine
// Satz, aus dem alles Weitere folgt: Wer den Docker-Socket hat, hat die
// Maschine — "-v /:/host" genügt. Das Modul reicht ihn deshalb nie durch.

const (
	// dockerPaket ist das Paket, das das Panel einspielt.
	//
	// docker.io aus den Quellen der Distribution und NICHT docker-ce: Letzteres
	// verlangt, Dockers eigenes apt-Repository einzubinden, und damit stünde ein
	// fremder Stack neben apt. Das ist ein Nicht-Ziel des Projekts
	// (docs/03-funktionsumfang.md). Wer docker-ce schon hat, behält es — erkannt
	// wird Docker am Binary und nicht am Paketnamen.
	dockerPaket = "docker.io"
	// composePaket liefert "docker compose" auf Debian und Ubuntu. Es ist ein
	// eigenes Paket, und ohne es gibt es keine Stacks.
	composePaket = "docker-compose-v2"
	// dockerCEPaket wird nur gelesen, nie installiert — siehe dockerPaket.
	dockerCEPaket = "docker-ce"
)

// DockerState ist der Zustand der Container-Laufzeit.
//
// Der Typ trennt vier Fragen, die eine Oberfläche auseinanderhalten muss, weil
// zu jeder ein anderer Handgriff gehört: Ist Docker da? Antwortet es? Gibt es
// Compose? Und wenn etwas fehlt — was hilft? Dieselbe Überlegung wie bei
// FirewallState: "nicht installiert" und "installiert, aber tot" dürfen nicht
// gleich aussehen, sonst bekommt der eine Fall den Rat des anderen.
type DockerState struct {
	// Installiert heißt: Das Programm docker ist vorhanden.
	//
	// Ermittelt am Binary und nicht am Paketnamen. Auf Bestandsservern kommt
	// Docker häufig als docker-ce aus Dockers eigenem Repository; ein Panel, das
	// nur nach docker.io fragt, hielte eine laufende Installation für fehlend
	// und böte an, sie zu installieren.
	Installiert bool `json:"installiert"`
	// Paket ist das Paket, aus dem docker stammt, soweit dpkg es weiß —
	// "docker.io", "docker-ce" oder leer bei einer Installation an apt vorbei.
	// Reine Auskunft: Sie entscheidet nichts, sie erklärt.
	Paket string `json:"paket"`
	// DaemonLaeuft heißt: docker antwortet. Ein installiertes Docker mit totem
	// Daemon braucht keinen apt-Lauf, sondern einen Dienststart.
	DaemonLaeuft  bool   `json:"daemon_laeuft"`
	ClientVersion string `json:"client_version"`
	ServerVersion string `json:"server_version"`
	// ComposeVerfuegbar heißt: "docker compose" ist da. Es kann fehlen, während
	// docker läuft — und die Stacks hängen daran, nicht die Container.
	ComposeVerfuegbar bool   `json:"compose_verfuegbar"`
	ComposeVersion    string `json:"compose_version"`
}

// Kein Feld für den Rat, was zu tun ist — anders als bei FirewallState.Notice.
//
// Der Satz „Docker fehlt, das Panel kann es einspielen" ist keine Auskunft über
// das System, sondern eine Empfehlung an den Bedienenden, und die gehört in die
// Schicht, die auch den Knopf dazu entscheidet (dockerAnmerkung in
// internal/httpd/api_v1_docker.go). Der Weg dorthin führte über einen echten
// Befund: Solange der Satz hier entstand, konnte eine Attrappe ihn weglassen —
// und die Seite zeigte den Zustand „nicht installiert" ohne ein einziges Wort
// dazu. Ein Feld, das man vergessen kann, wird vergessen.

// DockerState ermittelt den Zustand der Container-Laufzeit.
//
// Drei billige Aufrufe, und keiner davon darf die Auskunft insgesamt kippen:
// Fehlt das Binary, ist das kein Fehler, sondern der Normalfall auf einem
// frischen Server — und die Antwort darauf ist ein Angebot.
func (s *System) DockerState(ctx context.Context) (DockerState, error) {
	res, err := s.run(ctx, Command{
		Name: "docker",
		Args: []string{"version", "--format", "{{json .}}"},
	})
	if err != nil {
		// Ein Kommando außerhalb der Allowlist wäre ein Programmierfehler und
		// gehört gemeldet. Alles andere heißt: docker ist nicht da.
		if errors.Is(err, ErrNotAllowed) {
			return DockerState{}, err
		}
		return DockerState{}, nil //nolint:nilerr // fehlendes Docker ist kein Fehlerfall
	}

	st := DockerState{Installiert: true}
	st.ClientVersion, st.ServerVersion = parseDockerVersion(res.Stdout)

	// Der Daemon gilt als laufend, wenn docker mit Code 0 endet UND eine
	// Serverfassung nennt. Beides zusammen, weil beides für sich trügt: Bei
	// totem Daemon endet docker mit 1, gibt den Client-Teil aber trotzdem aus —
	// und ein Ausgabeformat, das sich einmal ändert, soll nicht dazu führen,
	// dass ein laufender Daemon als tot gilt.
	st.DaemonLaeuft = res.ExitCode == 0 && st.ServerVersion != ""

	st.ComposeVerfuegbar, st.ComposeVersion = s.dockerCompose(ctx)
	st.Paket = s.dockerPaketname(ctx)
	return st, nil
}

// dockerCompose fragt, ob "docker compose" da ist.
//
// Der Aufruf ist rein clientseitig: Er glückt auch bei totem Daemon. Das ist
// gewollt — die Frage "kann dieses Panel Stacks verwalten" hängt nicht daran,
// ob gerade ein Container läuft.
func (s *System) dockerCompose(ctx context.Context) (bool, string) {
	res, err := s.run(ctx, Command{
		Name: "docker",
		Args: []string{"compose", "version", "--short"},
	})
	if err != nil || res.ExitCode != 0 {
		return false, ""
	}
	return true, strings.TrimSpace(res.Stdout)
}

// dockerPaketname sucht das Paket, aus dem docker stammt.
//
// Zwei Kandidaten, in dieser Reihenfolge; findet sich keiner, kam Docker an apt
// vorbei (Skript von get.docker.com, statisches Binary). Auch das ist eine
// Antwort und keine Lücke — sie sagt, dass ein apt-Lauf hier nichts ausrichtet.
func (s *System) dockerPaketname(ctx context.Context) string {
	for _, name := range []string{dockerPaket, dockerCEPaket} {
		res, err := s.run(ctx, Command{
			Name: "dpkg-query",
			Args: []string{"-W", "-f=${db:Status-Status}", name},
		})
		if err != nil {
			// Ohne dpkg-query ist das kein Debian-System. Keine Auskunft.
			return ""
		}
		if res.ExitCode == 0 && strings.TrimSpace(res.Stdout) == "installed" {
			return name
		}
	}
	return ""
}

// DockerInstall spielt Docker ein.
//
// Wie FirewallInstall bewusst kein allgemeines "installiere Paket X": Der
// Paketweg des Panels trägt aus gutem Grund "--only-upgrade" und kann darüber
// nichts Neues ins System bringen. Diese Operation kennt genau zwei Pakete, und
// ihre Namen stehen im Quelltext statt im Formular.
//
// Compose wird nur nachgezogen, wenn es hinterher fehlt: Auf neueren Fassungen
// von Debian und Ubuntu bringt docker.io es als Abhängigkeit mit, und ein
// zweiter apt-Lauf für ein bereits vorhandenes Paket ist eine Minute Wartezeit
// für nichts.
func (s *System) DockerInstall(ctx context.Context, stream LineWriter) error {
	if err := s.aptInstall(ctx, stream, dockerPaket); err != nil {
		return err
	}
	if ok, _ := s.dockerCompose(ctx); ok {
		return nil
	}
	return s.aptInstall(ctx, stream, composePaket)
}

// ------------------------------------------------------------------ Parser ---

// parseDockerVersion liest Client- und Serverfassung aus "docker version".
//
// Beispielausgabe von "docker version --format {{json .}}" (gekürzt, Docker
// 27 auf Ubuntu 24.04):
//
//	{"Client":{"Version":"27.5.1","ApiVersion":"1.47",…},
//	 "Server":{"Version":"27.5.1","Components":[…],…}}
//
// Bei totem Daemon fehlt der Server-Teil oder steht leer da, und docker endet
// mit Code 1. Der Parser ist deshalb absichtlich nachsichtig: Er liest, was da
// ist, und meldet keinen Fehler für das, was fehlt. Ob der Daemon läuft,
// entscheidet der Aufrufer aus Fassung UND Exit-Code zusammen.
func parseDockerVersion(out string) (client, server string) {
	var v struct {
		Client struct {
			Version string `json:"Version"`
		} `json:"Client"`
		Server struct {
			Version string `json:"Version"`
		} `json:"Server"`
	}
	// Docker schreibt bei totem Daemon eine Fehlerzeile hinter das JSON. Nur
	// bis zur letzten schließenden Klammer lesen, statt am Rest zu scheitern.
	if i := strings.LastIndex(out, "}"); i >= 0 {
		out = out[:i+1]
	}
	if err := json.Unmarshal([]byte(strings.TrimSpace(out)), &v); err != nil {
		return "", ""
	}
	return v.Client.Version, v.Server.Version
}

// ---------------------------------------------------------------- Container ---

// Container ist eine Zeile der Containerliste.
//
// Bewusst NICHT enthalten: die Umgebungsvariablen. Sie tragen auf jedem zweiten
// Server ein Datenbankpasswort oder einen API-Schlüssel, und eine Liste, die
// beim Aufklappen Geheimnisse zeigt, ist eine Liste, die man nicht mehr
// vorführen kann. Der Detailtyp nennt nur ihre Anzahl.
type Container struct {
	ID   string `json:"id"`
	Name string `json:"name"`
	// Image ist der Name, unter dem der Container gestartet wurde.
	Image string `json:"image"`
	// Zustand ist das Wort von Docker: created, running, paused, restarting,
	// exited, dead, removing. Roh übernommen — eine Übersetzung an dieser Stelle
	// verlöre den Unterschied zwischen "exited" und "dead".
	Zustand string `json:"zustand"`
	// Status ist Dockers Satz dazu ("Up 3 hours", "Exited (137) 2 days ago"). Er
	// trägt die Angabe, die kein Zustandswort hat: seit wann, und mit welchem
	// Code beendet.
	Status string `json:"status"`
	// Gesundheit ist healthy, unhealthy, starting — oder leer, wenn das Image
	// keine Prüfung mitbringt. Leer ist NICHT gesund: Es heißt, dass niemand
	// nachsieht.
	Gesundheit string `json:"gesundheit"`
	Erstellt   string `json:"erstellt"`
	Ports      string `json:"ports"`
	// Stack und Dienst kommen aus den Compose-Labels. Sie sind der Grund, warum
	// die Liste schon in diesem Schritt danach gruppieren kann, obwohl das
	// Stack-Modul erst später kommt: Die Angabe steht am Container.
	Stack  string `json:"stack"`
	Dienst string `json:"dienst"`
}

// ContainerDetail ist ein Container mit dem, was ein Aufruf von docker inspect
// zusätzlich hergibt.
type ContainerDetail struct {
	Container
	Befehl string `json:"befehl"`
	// Neustartregel ist die RestartPolicy: no, always, unless-stopped,
	// on-failure. Sie beantwortet "kommt der nach einem Neustart wieder".
	Neustartregel string `json:"neustartregel"`
	// ExitCode gilt nur für beendete Container; -1 heißt "läuft noch".
	ExitCode int `json:"exit_code"`
	// Privilegiert ist die Angabe, die auf dieser Seite am meisten zählt: Ein
	// privilegierter Container ist root auf dem Wirt. Er wird angezeigt, auch
	// wenn das Panel ihn nie selbst angelegt hätte — Grundsatz IV.
	Privilegiert bool             `json:"privilegiert"`
	Benutzer     string           `json:"benutzer"`
	Mounts       []ContainerMount `json:"mounts"`
	Netze        []string         `json:"netze"`
	// Umgebung ist die ANZAHL der Umgebungsvariablen, nicht ihr Inhalt. Siehe
	// den Kopf von Container.
	Umgebung int `json:"umgebung"`
}

// ContainerMount ist ein eingehängter Pfad.
type ContainerMount struct {
	// Art ist "bind" oder "volume". Der Unterschied ist der zwischen "ein Pfad
	// des Wirts liegt im Container" und "Docker verwaltet den Speicher".
	Art       string `json:"art"`
	Quelle    string `json:"quelle"`
	Ziel      string `json:"ziel"`
	Schreibar bool   `json:"schreibbar"`
}

// ContainerStats sind die Laufzeitwerte eines Containers.
type ContainerStats struct {
	ID      string `json:"id"`
	Name    string `json:"name"`
	CPU     string `json:"cpu"`
	Speiche string `json:"speicher"`
	SpeiPro string `json:"speicher_prozent"`
	Netz    string `json:"netz"`
	Platte  string `json:"platte"`
	PIDs    string `json:"pids"`
}

// ContainerAction ist eine erlaubte Handlung an einem Container.
//
// Ein eigener Typ und keine Zeichenkette: Der Wert wandert bis in eine
// Kommandozeile, und die Allowlist gehört an die Stelle, an der er entsteht —
// nicht in eine Prüfung, die jemand beim nächsten Endpunkt vergisst.
type ContainerAction string

const (
	ContainerStart   ContainerAction = "start"
	ContainerStop    ContainerAction = "stop"
	ContainerRestart ContainerAction = "restart"
	ContainerPause   ContainerAction = "pause"
	ContainerUnpause ContainerAction = "unpause"
)

// ValidContainerAction sagt, ob die Handlung erlaubt ist.
func ValidContainerAction(a ContainerAction) bool {
	switch a {
	case ContainerStart, ContainerStop, ContainerRestart, ContainerPause, ContainerUnpause:
		return true
	default:
		return false
	}
}

// DockerContainers listet alle Container, laufende wie beendete.
//
// Mit --all, und das ist keine Bequemlichkeit: Ein Container, der heute Nacht
// mit Code 137 gestorben ist, ist die interessanteste Zeile der Liste. Ohne
// --all wäre er unsichtbar, und die Seite behauptete, alles sei in Ordnung.
func (s *System) DockerContainers(ctx context.Context) ([]Container, error) {
	res, err := s.run(ctx, Command{
		Name: "docker",
		Args: []string{"ps", "--all", "--no-trunc", "--format", "{{json .}}"},
	})
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("docker ps: %s", ersteAusgabezeile(res))
	}
	return parseDockerPS(res.Stdout), nil
}

// DockerContainer liest die Einzelheiten eines Containers.
func (s *System) DockerContainer(ctx context.Context, id string) (ContainerDetail, error) {
	if err := ValidateContainerID(id); err != nil {
		return ContainerDetail{}, err
	}
	res, err := s.run(ctx, Command{
		Name: "docker",
		Args: []string{"inspect", "--format", "{{json .}}", "--", id},
	})
	if err != nil {
		return ContainerDetail{}, err
	}
	if res.ExitCode != 0 {
		return ContainerDetail{}, fmt.Errorf("docker inspect %s: %s", id, ersteAusgabezeile(res))
	}
	return parseDockerInspect(res.Stdout)
}

// DockerContainerAction schaltet einen Container.
func (s *System) DockerContainerAction(ctx context.Context, id string, a ContainerAction) error {
	if err := ValidateContainerID(id); err != nil {
		return err
	}
	if !ValidContainerAction(a) {
		return fmt.Errorf("unbekannte Containeraktion: %q", a)
	}
	// Stoppen darf länger dauern: Docker schickt SIGTERM und wartet zehn
	// Sekunden, bevor es SIGKILL nachschiebt. Mit der Vorgabefrist von dreißig
	// Sekunden wäre ein Container mit langem Herunterfahren ein Fehlschlag, der
	// keiner ist.
	frist := defaultTimeout
	if a == ContainerStop || a == ContainerRestart {
		frist = 2 * defaultTimeout
	}
	res, err := s.run(ctx, Command{
		Name:    "docker",
		Args:    []string{string(a), "--", id},
		Timeout: frist,
	})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return fmt.Errorf("docker %s %s: %s", a, id, ersteAusgabezeile(res))
	}
	return nil
}

// DockerContainerRemove entfernt einen Container.
//
// erzwingen stoppt einen laufenden Container vorher. Es steht als eigener
// Parameter und nicht als stiller Vorgabewert, weil der Unterschied für den
// Bedienenden zählt: Das eine räumt auf, das andere beendet einen laufenden
// Dienst. Die Rückfragestufe hängt daran.
func (s *System) DockerContainerRemove(ctx context.Context, id string, erzwingen bool) error {
	if err := ValidateContainerID(id); err != nil {
		return err
	}
	args := []string{"rm"}
	if erzwingen {
		args = append(args, "--force")
	}
	args = append(args, "--", id)

	res, err := s.run(ctx, Command{Name: "docker", Args: args, Timeout: 2 * defaultTimeout})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return fmt.Errorf("docker rm %s: %s", id, ersteAusgabezeile(res))
	}
	return nil
}

// DockerContainerLogs liest die letzten Zeilen eines Containers.
//
// Mit Zeitstempeln, weil eine Logzeile ohne Zeit auf die Frage "seit wann"
// nicht antwortet — und das ist die Frage, mit der man ein Log öffnet.
func (s *System) DockerContainerLogs(ctx context.Context, id string, zeilen int) ([]string, error) {
	if err := ValidateContainerID(id); err != nil {
		return nil, err
	}
	if zeilen <= 0 || zeilen > 2000 {
		zeilen = 200
	}
	res, err := s.run(ctx, Command{
		Name: "docker",
		Args: []string{"logs", "--timestamps", "--tail", strconv.Itoa(zeilen), "--", id},
	})
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("docker logs %s: %s", id, ersteAusgabezeile(res))
	}
	// Docker schreibt die Ausgabe des Containers auf stdout UND stderr, je
	// nachdem, wohin das Programm im Container schrieb. Beide gehören dazu: Ein
	// Fehlerprotokoll, das die Seite unterschlägt, ist genau das, was jemand
	// sucht, der diese Seite öffnet.
	return zeilenAus(res.Stdout, res.Stderr), nil
}

// DockerContainerLogsFollow verfolgt das Protokoll eines Containers.
//
// Wie LogsFollow ohne eigene Frist: Der Kontext des Betrachters ist die Frist.
// Die Argumente entstehen NICHT aus einer zweiten Quelle — dieselbe Zeilenzahl
// wie bei der Abfrage, damit der Strom nicht mehr zeigen kann als die Abfrage
// vorher hergab.
func (s *System) DockerContainerLogsFollow(ctx context.Context, id string, zeilen int, sink LineWriter) error {
	if sink == nil {
		return errors.New("DockerContainerLogsFollow ohne Empfänger")
	}
	if err := ValidateContainerID(id); err != nil {
		return err
	}
	if zeilen <= 0 || zeilen > 2000 {
		zeilen = 200
	}

	res, err := s.run(ctx, Command{
		Name:      "docker",
		Args:      []string{"logs", "--timestamps", "--follow", "--tail", strconv.Itoa(zeilen), "--", id},
		OhneFrist: true,
		Stream:    sink,
	})
	// Der Abbruch des Kontexts ist das vorgesehene Ende — derselbe Fall wie beim
	// Journal: Der Betrachter hat die Seite verlassen. Er wird zuerst geprüft,
	// weil ein getöteter Prozess beides hinterlässt, Exit-Code und Fehler.
	if ctx.Err() != nil {
		return nil //nolint:nilerr // der Abbruch IST das Ende
	}
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return fmt.Errorf("docker logs --follow %s: %s", id, ersteAusgabezeile(res))
	}
	return nil
}

// DockerStats liest die Laufzeitwerte aller laufenden Container.
//
// Mit --no-stream und nicht als Dauerstrom: Ein laufendes "docker stats" hält
// einen Prozess und liest im Sekundentakt aus dem Kernel. Die Seite fragt
// stattdessen nach, wenn jemand hinsieht — dieselbe Entscheidung wie beim
// Updatestand, und aus demselben Grund: Ein Panel soll den Server nicht
// beschäftigen, weil ein Tab offen steht.
func (s *System) DockerStats(ctx context.Context) ([]ContainerStats, error) {
	res, err := s.run(ctx, Command{
		Name:    "docker",
		Args:    []string{"stats", "--no-stream", "--format", "{{json .}}"},
		Timeout: 2 * defaultTimeout,
	})
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("docker stats: %s", ersteAusgabezeile(res))
	}
	return parseDockerStats(res.Stdout), nil
}

// ------------------------------------------------------- Prüfung und Parser ---

// containerIDMuster ist die Form einer Containerkennung oder eines Namens.
//
// Docker erlaubt für Namen [a-zA-Z0-9][a-zA-Z0-9_.-]+, Kennungen sind
// Hexadezimalzahlen. Beides deckt dasselbe Muster ab. Die Prüfung steht hier
// und nicht im Handler, weil sie sonst zweimal stünde — und die zweite Fassung
// wäre die, die jemand beim nächsten Endpunkt vergisst.
var containerIDMuster = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$`)

// imageRefMuster ist die Form einer Image-Kennung oder -Bezeichnung.
//
// Weiter gefasst als containerIDMuster, und der Grund steckt in den echten
// Werten: Eine Kennung ist "sha256:aaa…", eine Bezeichnung "nginx:alpine" oder
// "ghcr.io/betreiber/dienst:1.2", ein Digest "nginx@sha256:…". Doppelpunkt,
// Schrägstrich und Klammeraffe gehören also dazu — und genau das hat der erste
// Anlauf übersehen: Er hat die Containerprüfung wiederverwendet, und die lehnte
// jede Image-Kennung ab.
//
// Was NICHT dazugehört, ist der Punkt: Leerzeichen, Semikolon, Zeilenumbruch und
// ein führender Bindestrich bleiben draußen. Zusammen mit dem "--" vor dem
// Operanden sind das zwei Riegel gegen dieselbe Sache.
var imageRefMuster = regexp.MustCompile(`^[a-zA-Z0-9][a-zA-Z0-9_.:/@-]{0,255}$`)

// ValidateImageRef prüft eine Image-Kennung oder -Bezeichnung.
func ValidateImageRef(ref string) error {
	if ref == "" {
		return errors.New("kein Image angegeben")
	}
	if !imageRefMuster.MatchString(ref) {
		return fmt.Errorf("ungültige Image-Kennung: %q", ref)
	}
	return nil
}

// ValidateContainerID prüft eine Containerkennung oder einen Containernamen.
func ValidateContainerID(id string) error {
	if id == "" {
		return errors.New("kein Container angegeben")
	}
	if !containerIDMuster.MatchString(id) {
		return fmt.Errorf("ungültige Containerkennung: %q", id)
	}
	return nil
}

// ersteAusgabezeile holt die sprechendste Zeile aus einem Fehlschlag.
//
// Docker schreibt seine Fehler auf stderr ("Error response from daemon: No such
// container: x"), gelegentlich aber auch auf stdout. Beides ansehen, damit die
// Meldung nicht "endete mit Code 1" lautet, während die Ursache danebensteht.
func ersteAusgabezeile(res Result) string {
	for _, s := range []string{res.Stderr, res.Stdout} {
		for _, zeile := range strings.Split(s, "\n") {
			if z := strings.TrimSpace(zeile); z != "" {
				return z
			}
		}
	}
	return "kein Hinweis in der Ausgabe"
}

// zeilenAus fügt stdout und stderr zu einer Liste zusammen.
func zeilenAus(teile ...string) []string {
	// Leeres Feld statt null: Ein Container ohne Ausgabe soll in der Antwort eine
	// leere Liste sein und nicht "keine Auskunft".
	out := []string{}
	for _, t := range teile {
		for _, zeile := range strings.Split(t, "\n") {
			if strings.TrimRight(zeile, "\r") != "" {
				out = append(out, strings.TrimRight(zeile, "\r"))
			}
		}
	}
	return out
}

// parseDockerPS liest die Ausgabe von "docker ps --format {{json .}}".
//
// Beispielzeile (Docker 27, gekürzt — eine Zeile je Container, KEIN Feld):
//
//	{"Command":"\"/entrypoint.sh\"","CreatedAt":"2026-07-30 10:11:12 +0000 UTC",
//	 "ID":"3f2b…","Image":"nginx:alpine",
//	 "Labels":"com.docker.compose.project=web,com.docker.compose.service=proxy",
//	 "Names":"web-proxy-1","Ports":"0.0.0.0:8080->80/tcp",
//	 "State":"running","Status":"Up 3 hours (healthy)"}
//
// Das Format ist NDJSON und kein Feld — eine Zeile je Container. Eine Zeile, die
// sich nicht zerlegen lässt, wird übersprungen statt die Liste zu verwerfen:
// Docker schreibt bei Warnungen gelegentlich eine Textzeile dazwischen, und
// daran soll die Anzeige aller übrigen Container nicht scheitern.
func parseDockerPS(out string) []Container {
	container := []Container{}
	for _, zeile := range strings.Split(out, "\n") {
		zeile = strings.TrimSpace(zeile)
		if zeile == "" || !strings.HasPrefix(zeile, "{") {
			continue
		}
		var roh struct {
			ID        string `json:"ID"`
			Names     string `json:"Names"`
			Image     string `json:"Image"`
			State     string `json:"State"`
			Status    string `json:"Status"`
			Ports     string `json:"Ports"`
			CreatedAt string `json:"CreatedAt"`
			Labels    string `json:"Labels"`
		}
		if json.Unmarshal([]byte(zeile), &roh) != nil {
			continue
		}
		c := Container{
			ID:         roh.ID,
			Name:       ersterName(roh.Names),
			Image:      roh.Image,
			Zustand:    roh.State,
			Status:     roh.Status,
			Ports:      roh.Ports,
			Erstellt:   roh.CreatedAt,
			Gesundheit: gesundheitAus(roh.Status),
		}
		labels := labelsAus(roh.Labels)
		c.Stack = labels["com.docker.compose.project"]
		c.Dienst = labels["com.docker.compose.service"]
		container = append(container, c)
	}
	return container
}

// ersterName nimmt den ersten von mehreren Namen.
//
// Ein Container kann mehrere Aliase tragen; docker ps gibt sie durch Komma
// getrennt aus. Für die Liste zählt der erste — alle anzuzeigen machte die
// Spalte unlesbar, und der erste ist der, unter dem er angelegt wurde.
func ersterName(names string) string {
	if i := strings.IndexByte(names, ','); i >= 0 {
		return names[:i]
	}
	return names
}

// gesundheitAus liest den Gesundheitszustand aus Dockers Statussatz.
//
// Er steht dort in Klammern ("Up 3 hours (healthy)") und in keinem eigenen Feld
// von "docker ps". Fehlt die Klammer, bringt das Image keine Prüfung mit — und
// das ist NICHT dasselbe wie gesund: Es heißt, dass niemand nachsieht.
func gesundheitAus(status string) string {
	for _, wort := range []string{"healthy", "unhealthy", "health: starting", "starting"} {
		if strings.Contains(status, "("+wort+")") {
			if wort == "health: starting" {
				return "starting"
			}
			return wort
		}
	}
	return ""
}

// labelsAus zerlegt Dockers Label-Zeichenkette ("a=1,b=2").
func labelsAus(s string) map[string]string {
	out := map[string]string{}
	for _, paar := range strings.Split(s, ",") {
		if k, v, ok := strings.Cut(paar, "="); ok {
			out[strings.TrimSpace(k)] = v
		}
	}
	return out
}

// parseDockerInspect liest die Ausgabe von "docker inspect --format {{json .}}".
//
// Mit --format kommt EIN Objekt heraus und kein Feld — ohne das Format setzt
// docker inspect seine Antwort in ein Feld, und der Parser müsste beide Formen
// kennen. Eine Form ist besser als zwei.
func parseDockerInspect(out string) (ContainerDetail, error) {
	var roh struct {
		ID      string `json:"Id"`
		Name    string `json:"Name"`
		Created string `json:"Created"`
		State   struct {
			Status   string `json:"Status"`
			ExitCode int    `json:"ExitCode"`
			Health   struct {
				Status string `json:"Status"`
			} `json:"Health"`
		} `json:"State"`
		Config struct {
			Image  string            `json:"Image"`
			Cmd    []string          `json:"Cmd"`
			Env    []string          `json:"Env"`
			User   string            `json:"User"`
			Labels map[string]string `json:"Labels"`
		} `json:"Config"`
		HostConfig struct {
			Privileged    bool `json:"Privileged"`
			RestartPolicy struct {
				Name string `json:"Name"`
			} `json:"RestartPolicy"`
		} `json:"HostConfig"`
		Mounts []struct {
			Type        string `json:"Type"`
			Source      string `json:"Source"`
			Name        string `json:"Name"`
			Destination string `json:"Destination"`
			RW          bool   `json:"RW"`
		} `json:"Mounts"`
		NetworkSettings struct {
			Networks map[string]struct{} `json:"Networks"`
		} `json:"NetworkSettings"`
	}
	if err := json.Unmarshal([]byte(strings.TrimSpace(out)), &roh); err != nil {
		return ContainerDetail{}, fmt.Errorf("docker inspect: Ausgabe nicht lesbar: %w", err)
	}

	d := ContainerDetail{
		Container: Container{
			ID:         roh.ID,
			Name:       strings.TrimPrefix(roh.Name, "/"),
			Image:      roh.Config.Image,
			Zustand:    roh.State.Status,
			Gesundheit: roh.State.Health.Status,
			Erstellt:   roh.Created,
			Stack:      roh.Config.Labels["com.docker.compose.project"],
			Dienst:     roh.Config.Labels["com.docker.compose.service"],
		},
		Befehl:        strings.Join(roh.Config.Cmd, " "),
		Neustartregel: roh.HostConfig.RestartPolicy.Name,
		Privilegiert:  roh.HostConfig.Privileged,
		Benutzer:      roh.Config.User,
		Umgebung:      len(roh.Config.Env),
		ExitCode:      -1,
		Mounts:        []ContainerMount{},
		Netze:         []string{},
	}
	// Der Exit-Code gilt nur für einen beendeten Container. Bei einem laufenden
	// steht dort 0, und "0" wäre die Behauptung, er sei sauber beendet worden.
	if roh.State.Status != "running" && roh.State.Status != "paused" {
		d.ExitCode = roh.State.ExitCode
	}
	for _, m := range roh.Mounts {
		quelle := m.Source
		if m.Type == "volume" && m.Name != "" {
			quelle = m.Name
		}
		d.Mounts = append(d.Mounts, ContainerMount{
			Art: m.Type, Quelle: quelle, Ziel: m.Destination, Schreibar: m.RW,
		})
	}
	for name := range roh.NetworkSettings.Networks {
		d.Netze = append(d.Netze, name)
	}
	sort.Strings(d.Netze)
	return d, nil
}

// parseDockerStats liest die Ausgabe von "docker stats --no-stream".
//
// Beispielzeile (Docker 27):
//
//	{"BlockIO":"0B / 0B","CPUPerc":"0.02%","Container":"3f2b…","ID":"3f2b…",
//	 "MemPerc":"1.31%","MemUsage":"20.5MiB / 1.5GiB","Name":"web-proxy-1",
//	 "NetIO":"1.2kB / 830B","PIDs":"5"}
//
// Die Werte kommen als fertig formatierte Zeichenketten und werden so
// übernommen. Sie zu zerlegen und neu zu formatieren hieße, Dockers Rundung
// nachzubauen — mit dem einzigen Ergebnis, dass die Zahl im Panel eine andere
// wäre als die auf der Kommandozeile.
func parseDockerStats(out string) []ContainerStats {
	stats := []ContainerStats{}
	for _, zeile := range strings.Split(out, "\n") {
		zeile = strings.TrimSpace(zeile)
		if zeile == "" || !strings.HasPrefix(zeile, "{") {
			continue
		}
		var roh struct {
			ID       string `json:"ID"`
			Name     string `json:"Name"`
			CPUPerc  string `json:"CPUPerc"`
			MemUsage string `json:"MemUsage"`
			MemPerc  string `json:"MemPerc"`
			NetIO    string `json:"NetIO"`
			BlockIO  string `json:"BlockIO"`
			PIDs     string `json:"PIDs"`
		}
		if json.Unmarshal([]byte(zeile), &roh) != nil {
			continue
		}
		stats = append(stats, ContainerStats{
			ID: roh.ID, Name: roh.Name, CPU: roh.CPUPerc,
			Speiche: roh.MemUsage, SpeiPro: roh.MemPerc,
			Netz: roh.NetIO, Platte: roh.BlockIO, PIDs: roh.PIDs,
		})
	}
	return stats
}

// ------------------------------------------------------------------ Bestand ---

// Image ist ein Image in der lokalen Ablage.
type Image struct {
	ID   string `json:"id"`
	Repo string `json:"repo"`
	Tag  string `json:"tag"`
	// Groesse und Erstellt kommen fertig formatiert von Docker. Sie zu zerlegen
	// und neu zu formatieren hieße, Dockers Rundung nachzubauen — mit dem
	// einzigen Ergebnis, dass die Zahl im Panel eine andere wäre als die auf der
	// Kommandozeile.
	Groesse  string `json:"groesse"`
	Erstellt string `json:"erstellt"`
	// Verwaist heißt: ohne Namen (<none>:<none>). Solche Images entstehen bei
	// jedem Neubau und sind der übliche Grund, warum eine Platte volläuft.
	Verwaist bool `json:"verwaist"`
}

// Volume ist ein von Docker verwalteter Datenspeicher.
type Volume struct {
	Name    string `json:"name"`
	Treiber string `json:"treiber"`
	Ort     string `json:"ort"`
}

// Netz ist ein Docker-Netzwerk.
type Netz struct {
	ID      string `json:"id"`
	Name    string `json:"name"`
	Treiber string `json:"treiber"`
	Bereich string `json:"bereich"`
}

// Bestandsposten ist eine Zeile aus "docker system df".
//
// Alle Felder sind Zeichenketten, weil Docker sie so ausgibt ("3.2GB",
// "1.5GB (46%)"). Der Wert, auf den es ankommt, ist Freigebbar: Er beantwortet
// die Frage, mit der jemand diese Seite öffnet — was bringt das Aufräumen.
type Bestandsposten struct {
	Art        string `json:"art"`
	Anzahl     string `json:"anzahl"`
	Aktiv      string `json:"aktiv"`
	Groesse    string `json:"groesse"`
	Freigebbar string `json:"freigebbar"`
}

// PruneArt benennt, was aufgeräumt wird.
//
// Ein eigener Typ mit Allowlist, weil der Wert bis in die Kommandozeile wandert.
// "system" fehlt bewusst: "docker system prune" räumt in einem Zug Container,
// Netze, Images und den Baucache auf, und eine Aktion, deren Umfang der
// Bedienende nicht überblickt, kann keine sinnvolle Rückfrage tragen.
type PruneArt string

const (
	PruneImages    PruneArt = "images"
	PruneContainer PruneArt = "container"
	PruneVolumes   PruneArt = "volumes"
	PruneNetze     PruneArt = "netze"
	PruneCache     PruneArt = "cache"
)

// ValidPruneArt sagt, ob die Art erlaubt ist.
func ValidPruneArt(a PruneArt) bool {
	switch a {
	case PruneImages, PruneContainer, PruneVolumes, PruneNetze, PruneCache:
		return true
	default:
		return false
	}
}

// DockerImages listet die lokalen Images.
func (s *System) DockerImages(ctx context.Context) ([]Image, error) {
	res, err := s.run(ctx, Command{
		Name: "docker",
		Args: []string{"image", "ls", "--all", "--no-trunc", "--format", "{{json .}}"},
	})
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("docker image ls: %s", ersteAusgabezeile(res))
	}
	return parseDockerImages(res.Stdout), nil
}

// DockerImageRemove entfernt ein Image.
func (s *System) DockerImageRemove(ctx context.Context, id string) error {
	if err := ValidateImageRef(id); err != nil {
		return err
	}
	// Ohne --force: Ist das Image in Gebrauch, soll Docker das sagen und nicht
	// der Container mitgerissen werden. Die Oberfläche bietet den Handgriff bei
	// einem benutzten Image ohnehin nicht an — aber ein selbstgebautes POST
	// kommt an der Liste vorbei, und hier ist die Stelle, an der es zählt.
	res, err := s.run(ctx, Command{
		Name: "docker", Args: []string{"image", "rm", "--", id},
		Timeout: 2 * defaultTimeout,
	})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return fmt.Errorf("docker image rm %s: %s", id, ersteAusgabezeile(res))
	}
	return nil
}

// DockerVolumes listet die Datenspeicher.
func (s *System) DockerVolumes(ctx context.Context) ([]Volume, error) {
	res, err := s.run(ctx, Command{
		Name: "docker",
		Args: []string{"volume", "ls", "--format", "{{json .}}"},
	})
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("docker volume ls: %s", ersteAusgabezeile(res))
	}
	return parseDockerVolumes(res.Stdout), nil
}

// DockerVolumeRemove entfernt einen Datenspeicher.
//
// Die schärfste Einzelaktion dieses Moduls: Was darin liegt, ist danach weg, und
// kein Rückweg des Panels holt es zurück. Die Rückfragestufe steht in
// docs/17-docker.md; hier gibt es keine Abkürzung über --force.
func (s *System) DockerVolumeRemove(ctx context.Context, name string) error {
	if err := ValidateContainerID(name); err != nil {
		return err
	}
	res, err := s.run(ctx, Command{
		Name: "docker", Args: []string{"volume", "rm", "--", name},
		Timeout: 2 * defaultTimeout,
	})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return fmt.Errorf("docker volume rm %s: %s", name, ersteAusgabezeile(res))
	}
	return nil
}

// DockerNetworks listet die Netze.
func (s *System) DockerNetworks(ctx context.Context) ([]Netz, error) {
	res, err := s.run(ctx, Command{
		Name: "docker",
		Args: []string{"network", "ls", "--no-trunc", "--format", "{{json .}}"},
	})
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("docker network ls: %s", ersteAusgabezeile(res))
	}
	return parseDockerNetze(res.Stdout), nil
}

// DockerNetworkRemove entfernt ein Netz.
func (s *System) DockerNetworkRemove(ctx context.Context, id string) error {
	if err := ValidateContainerID(id); err != nil {
		return err
	}
	res, err := s.run(ctx, Command{
		Name: "docker", Args: []string{"network", "rm", "--", id},
	})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return fmt.Errorf("docker network rm %s: %s", id, ersteAusgabezeile(res))
	}
	return nil
}

// DockerDiskUsage liest, was Docker auf der Platte belegt.
func (s *System) DockerDiskUsage(ctx context.Context) ([]Bestandsposten, error) {
	res, err := s.run(ctx, Command{
		Name:    "docker",
		Args:    []string{"system", "df", "--format", "{{json .}}"},
		Timeout: 2 * defaultTimeout,
	})
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("docker system df: %s", ersteAusgabezeile(res))
	}
	return parseDockerDF(res.Stdout), nil
}

// DockerPrune räumt eine Art auf und meldet, was das gebracht hat.
//
// alleUnbenutzten gilt nur für Images und den Baucache und ist der
// Unterschied zwischen "räum die namenlosen Reste weg" und "wirf alles raus,
// was gerade kein Container benutzt". Der zweite Fall zieht auch Images, die
// jemand für morgen bereitgelegt hat — deshalb steht er als eigener Parameter
// und nicht als stille Vorgabe.
func (s *System) DockerPrune(ctx context.Context, art PruneArt, alleUnbenutzten bool, stream LineWriter) (string, error) {
	if !ValidPruneArt(art) {
		return "", fmt.Errorf("unbekannte Aufräumart: %q", art)
	}

	var args []string
	switch art {
	case PruneImages:
		args = []string{"image", "prune", "--force"}
		if alleUnbenutzten {
			args = append(args, "--all")
		}
	case PruneContainer:
		args = []string{"container", "prune", "--force"}
	case PruneVolumes:
		args = []string{"volume", "prune", "--force"}
	case PruneNetze:
		args = []string{"network", "prune", "--force"}
	case PruneCache:
		args = []string{"builder", "prune", "--force"}
		if alleUnbenutzten {
			args = append(args, "--all")
		}
	}

	res, err := s.run(ctx, Command{
		Name: "docker", Args: args, Timeout: longTimeout, Stream: stream,
	})
	if err != nil {
		return "", err
	}
	if res.ExitCode != 0 {
		return "", fmt.Errorf("docker %s prune: %s", art, ersteAusgabezeile(res))
	}
	return freigegebenAus(res.Stdout), nil
}

// parseDockerImages liest "docker image ls --format {{json .}}".
//
// Beispielzeile (Docker 27):
//
//	{"Containers":"N/A","CreatedAt":"2026-07-20 09:00:00 +0000 UTC",
//	 "CreatedSince":"11 days ago","Digest":"<none>","ID":"sha256:abc…",
//	 "Repository":"nginx","Size":"48.9MB","Tag":"alpine"}
//
// Ein Image ohne Namen trägt in beiden Feldern "<none>" — das ist der Rest, der
// bei jedem Neubau übrig bleibt, und der übliche Grund für eine volle Platte.
func parseDockerImages(out string) []Image {
	images := []Image{}
	for _, zeile := range strings.Split(out, "\n") {
		zeile = strings.TrimSpace(zeile)
		if zeile == "" || !strings.HasPrefix(zeile, "{") {
			continue
		}
		var roh struct {
			ID           string `json:"ID"`
			Repository   string `json:"Repository"`
			Tag          string `json:"Tag"`
			Size         string `json:"Size"`
			CreatedSince string `json:"CreatedSince"`
		}
		if json.Unmarshal([]byte(zeile), &roh) != nil {
			continue
		}
		images = append(images, Image{
			ID:       roh.ID,
			Repo:     roh.Repository,
			Tag:      roh.Tag,
			Groesse:  roh.Size,
			Erstellt: roh.CreatedSince,
			Verwaist: roh.Repository == "<none>" || roh.Repository == "",
		})
	}
	return images
}

// parseDockerVolumes liest "docker volume ls --format {{json .}}".
//
//	{"Driver":"local","Labels":"","Links":"N/A","Mountpoint":"/var/lib/docker/volumes/web_daten/_data",
//	 "Name":"web_daten","Scope":"local","Size":"N/A"}
func parseDockerVolumes(out string) []Volume {
	volumes := []Volume{}
	for _, zeile := range strings.Split(out, "\n") {
		zeile = strings.TrimSpace(zeile)
		if zeile == "" || !strings.HasPrefix(zeile, "{") {
			continue
		}
		var roh struct {
			Name       string `json:"Name"`
			Driver     string `json:"Driver"`
			Mountpoint string `json:"Mountpoint"`
		}
		if json.Unmarshal([]byte(zeile), &roh) != nil {
			continue
		}
		volumes = append(volumes, Volume{Name: roh.Name, Treiber: roh.Driver, Ort: roh.Mountpoint})
	}
	return volumes
}

// parseDockerNetze liest "docker network ls --format {{json .}}".
//
//	{"CreatedAt":"2026-07-30 10:00:00 +0000 UTC","Driver":"bridge","ID":"1a2b…",
//	 "IPv6":"false","Internal":"false","Labels":"","Name":"web_default","Scope":"local"}
func parseDockerNetze(out string) []Netz {
	netze := []Netz{}
	for _, zeile := range strings.Split(out, "\n") {
		zeile = strings.TrimSpace(zeile)
		if zeile == "" || !strings.HasPrefix(zeile, "{") {
			continue
		}
		var roh struct {
			ID     string `json:"ID"`
			Name   string `json:"Name"`
			Driver string `json:"Driver"`
			Scope  string `json:"Scope"`
		}
		if json.Unmarshal([]byte(zeile), &roh) != nil {
			continue
		}
		netze = append(netze, Netz{ID: roh.ID, Name: roh.Name, Treiber: roh.Driver, Bereich: roh.Scope})
	}
	return netze
}

// parseDockerDF liest "docker system df --format {{json .}}".
//
//	{"Active":"5","Reclaimable":"1.5GB (46%)","Size":"3.2GB","TotalCount":"12","Type":"Images"}
//	{"Active":"4","Reclaimable":"12MB (100%)","Size":"12MB","TotalCount":"7","Type":"Containers"}
//	{"Active":"2","Reclaimable":"800MB (80%)","Size":"1GB","TotalCount":"5","Type":"Local Volumes"}
//	{"Active":"0","Reclaimable":"2.1GB","Size":"2.1GB","TotalCount":"31","Type":"Build Cache"}
func parseDockerDF(out string) []Bestandsposten {
	posten := []Bestandsposten{}
	for _, zeile := range strings.Split(out, "\n") {
		zeile = strings.TrimSpace(zeile)
		if zeile == "" || !strings.HasPrefix(zeile, "{") {
			continue
		}
		var roh struct {
			Type        string `json:"Type"`
			TotalCount  string `json:"TotalCount"`
			Active      string `json:"Active"`
			Size        string `json:"Size"`
			Reclaimable string `json:"Reclaimable"`
		}
		if json.Unmarshal([]byte(zeile), &roh) != nil {
			continue
		}
		posten = append(posten, Bestandsposten{
			Art: roh.Type, Anzahl: roh.TotalCount, Aktiv: roh.Active,
			Groesse: roh.Size, Freigebbar: roh.Reclaimable,
		})
	}
	return posten
}

// freigegebenAus holt den freigegebenen Platz aus der Ausgabe von prune.
//
// Docker schließt jeden prune-Lauf mit "Total reclaimed space: 1.234GB" ab. Das
// ist die Antwort, wegen der jemand aufräumt — sie zu verschweigen und nur
// "erledigt" zu melden hieße, die einzige interessante Zahl wegzuwerfen.
//
// Findet sich die Zeile nicht (andere Sprache, geändertes Format), kommt eine
// leere Zeichenkette zurück und die Oberfläche sagt nichts dazu. Eine erfundene
// Null wäre schlechter als keine Angabe.
func freigegebenAus(out string) string {
	for _, zeile := range strings.Split(out, "\n") {
		zeile = strings.TrimSpace(zeile)
		if rest, ok := strings.CutPrefix(zeile, "Total reclaimed space:"); ok {
			return strings.TrimSpace(rest)
		}
	}
	return ""
}
