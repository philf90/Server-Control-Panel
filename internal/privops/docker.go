package privops

import (
	"context"
	"encoding/json"
	"errors"
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
