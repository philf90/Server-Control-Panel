package privops

import (
	"context"
	"errors"
	"strings"
	"testing"
)

// Aufgezeichnete Ausgaben.
//
// Gekürzt auf die Felder, die der Parser liest — vollständige Ausgaben von
// "docker version" sind über hundert Zeilen und würden den Test unlesbar
// machen, ohne ihm etwas zu geben. Die Form (verschachtelte Objekte Client und
// Server, Feldname "Version" mit großem V) ist die, die Docker liefert.
const (
	dockerVersionOut = `{"Client":{"Version":"27.5.1","ApiVersion":"1.47","GoVersion":"go1.22.2","Os":"linux","Arch":"amd64"},` +
		`"Server":{"Platform":{"Name":"Docker Engine - Community"},"Version":"27.5.1","ApiVersion":"1.47","Os":"linux","Arch":"amd64"}}`

	// Bei totem Daemon füllt Docker den Server-Teil nicht und hängt eine
	// Fehlerzeile an die Ausgabe. Der Exit-Code ist 1.
	dockerVersionOhneDaemonOut = `{"Client":{"Version":"27.5.1","ApiVersion":"1.47","GoVersion":"go1.22.2","Os":"linux","Arch":"amd64"},` +
		`"Server":{}}
errors pretty printing info`
)

// setzeDocker legt die Antworten für einen vollständigen DockerState-Durchlauf
// hin. Die drei Aufrufe stehen hier zusammen, damit ein Test nur noch das
// abweichen lässt, worum es ihm geht.
func setzeDocker(f *fakeRunner, version, compose, paket Result) {
	f.responses["docker version --format {{json .}}"] = version
	f.responses["docker compose version --short"] = compose
	f.responses["dpkg-query -W -f=${db:Status-Status} docker.io"] = paket
	f.responses["dpkg-query -W -f=${db:Status-Status} docker-ce"] = Result{ExitCode: 1}
}

func TestDockerStateVollstaendig(t *testing.T) {
	f := newFakeRunner()
	setzeDocker(f,
		Result{Stdout: dockerVersionOut},
		Result{Stdout: "2.32.4\n"},
		Result{Stdout: "installed"},
	)
	s := NewSystemWithRunner(f)

	st, err := s.DockerState(context.Background())
	if err != nil {
		t.Fatalf("DockerState: %v", err)
	}
	if !st.Installiert {
		t.Error("Docker sollte als installiert gelten")
	}
	if !st.DaemonLaeuft {
		t.Error("der Daemon sollte als laufend gelten")
	}
	if st.ClientVersion != "27.5.1" || st.ServerVersion != "27.5.1" {
		t.Errorf("Fassungen falsch gelesen: Client %q, Server %q", st.ClientVersion, st.ServerVersion)
	}
	if !st.ComposeVerfuegbar || st.ComposeVersion != "2.32.4" {
		t.Errorf("Compose falsch gelesen: %v %q", st.ComposeVerfuegbar, st.ComposeVersion)
	}
	if st.Paket != "docker.io" {
		t.Errorf("Paket = %q, erwartet docker.io", st.Paket)
	}
}

// TestDockerStateOhneBinary hält den Normalfall fest: Ein Server ohne Docker ist
// kein Fehler. Gäbe DockerState hier einen Fehler zurück, zeigte die Seite eine
// Fehlermeldung statt des Angebots, Docker einzuspielen.
func TestDockerStateOhneBinary(t *testing.T) {
	f := newFakeRunner()
	// Derselbe Fehler, den resolve für ein Programm meldet, das in der Allowlist
	// steht, aber auf dem System fehlt — ausdrücklich NICHT ErrNotAllowed: Das
	// wäre ein Programmierfehler und muss durchschlagen.
	f.errs["docker"] = errors.New("docker ist auf diesem System nicht vorhanden")
	s := NewSystemWithRunner(f)

	st, err := s.DockerState(context.Background())
	if err != nil {
		t.Fatalf("ein fehlendes Docker ist kein Fehlerfall, bekam: %v", err)
	}
	if st.Installiert || st.DaemonLaeuft || st.ComposeVerfuegbar {
		t.Errorf("ohne Binary darf nichts als vorhanden gelten: %+v", st)
	}
	// Nach dem ersten Fehlschlag darf nichts weiter gefragt werden: Compose und
	// dpkg-query zu einem Programm, das es nicht gibt, sind zwei Aufrufe für
	// nichts — auf der meistbesuchten Seite zählt das.
	if len(f.calls) != 1 {
		t.Errorf("erwartet genau ein Kommando, gelaufen sind %d", len(f.calls))
	}
}

// TestDockerStateOhneDaemon ist der Fall, für den es die Trennung überhaupt
// gibt: Docker ist da, antwortet aber nicht. Hier hilft ein Dienststart und kein
// apt-Lauf — welcher Satz daraus wird, entscheidet die HTTP-Schicht
// (dockerAnmerkung); hier zählt nur, dass die beiden Zustände unterscheidbar
// herauskommen.
func TestDockerStateOhneDaemon(t *testing.T) {
	f := newFakeRunner()
	setzeDocker(f,
		Result{Stdout: dockerVersionOhneDaemonOut, ExitCode: 1},
		Result{Stdout: "2.32.4\n"},
		Result{Stdout: "installed"},
	)
	s := NewSystemWithRunner(f)

	st, err := s.DockerState(context.Background())
	if err != nil {
		t.Fatalf("DockerState: %v", err)
	}
	if !st.Installiert {
		t.Error("Docker ist installiert, der Daemon antwortet nur nicht")
	}
	if st.DaemonLaeuft {
		t.Error("ohne Serverfassung und mit Exit 1 darf der Daemon nicht als laufend gelten")
	}
	if st.ClientVersion != "27.5.1" {
		t.Errorf("die Clientfassung steht in der Ausgabe und sollte gelesen werden, ist %q", st.ClientVersion)
	}
}

func TestDockerStateOhneCompose(t *testing.T) {
	f := newFakeRunner()
	setzeDocker(f,
		Result{Stdout: dockerVersionOut},
		Result{ExitCode: 125}, // "docker compose" kennt das Unterkommando nicht
		Result{Stdout: "installed"},
	)
	s := NewSystemWithRunner(f)

	st, err := s.DockerState(context.Background())
	if err != nil {
		t.Fatalf("DockerState: %v", err)
	}
	if !st.DaemonLaeuft {
		t.Error("ein fehlendes Compose sagt nichts über den Daemon")
	}
	if st.ComposeVerfuegbar {
		t.Error("Compose sollte als fehlend gelten")
	}
}

// TestDockerStateErkenntDockerCE ist der Bestandsserver-Fall: Docker kommt aus
// Dockers eigenem Repository. Es zu erkennen ist der Unterschied zwischen einer
// richtigen Auskunft und dem Angebot, ein vorhandenes Docker zu installieren.
func TestDockerStateErkenntDockerCE(t *testing.T) {
	f := newFakeRunner()
	setzeDocker(f,
		Result{Stdout: dockerVersionOut},
		Result{Stdout: "2.32.4\n"},
		Result{ExitCode: 1}, // docker.io ist nicht installiert
	)
	f.responses["dpkg-query -W -f=${db:Status-Status} docker-ce"] = Result{Stdout: "installed"}
	s := NewSystemWithRunner(f)

	st, err := s.DockerState(context.Background())
	if err != nil {
		t.Fatalf("DockerState: %v", err)
	}
	if st.Paket != dockerCEPaket {
		t.Errorf("Paket = %q, erwartet %q", st.Paket, dockerCEPaket)
	}
	if !st.Installiert {
		t.Error("docker-ce ist auch Docker")
	}
}

// TestDockerInstallSpieltNurBenannteEin hält fest, was diese Operation darf: ein
// Paket, das im Quelltext steht. Der Test prüft die Argumente, weil dort die
// Zusage steckt — "-- docker.io" am Ende schließt aus, dass ein Paketname als
// Option gelesen wird.
func TestDockerInstallSpieltNurBenannteEin(t *testing.T) {
	f := newFakeRunner()
	f.responses["apt-get install"] = Result{Stdout: "Richte docker.io ein …\n"}
	// Nach dem Lauf ist Compose da — dann darf kein zweiter Aufruf folgen.
	f.responses["docker compose version --short"] = Result{Stdout: "2.32.4\n"}
	s := NewSystemWithRunner(f)

	var zeilen []string
	if err := s.DockerInstall(context.Background(), func(l string) { zeilen = append(zeilen, l) }); err != nil {
		t.Fatalf("DockerInstall: %v", err)
	}

	var installs []Command
	for _, c := range f.calls {
		if c.Name == "apt-get" {
			installs = append(installs, c)
		}
	}
	if len(installs) != 1 {
		t.Fatalf("erwartet genau einen apt-Lauf, gelaufen sind %d", len(installs))
	}
	args := strings.Join(installs[0].Args, " ")
	if !strings.Contains(args, "-- "+dockerPaket) {
		t.Errorf("der Paketname muss hinter -- stehen: %q", args)
	}
	if strings.Contains(args, composePaket) {
		t.Errorf("Compose war vorhanden und darf nicht mit eingespielt werden: %q", args)
	}
	if len(zeilen) == 0 {
		t.Error("die Ausgabe des Laufs sollte durchgereicht werden")
	}
}

// TestDockerInstallZiehtComposeNach ist die andere Hälfte: Bringt docker.io
// Compose nicht mit, holt das Panel es. Ohne Compose gibt es keine Stacks, und
// Stacks sind der Zweck dieses Moduls.
func TestDockerInstallZiehtComposeNach(t *testing.T) {
	f := newFakeRunner()
	f.responses["apt-get install"] = Result{}
	f.responses["docker compose version --short"] = Result{ExitCode: 125}
	s := NewSystemWithRunner(f)

	if err := s.DockerInstall(context.Background(), nil); err != nil {
		t.Fatalf("DockerInstall: %v", err)
	}

	var pakete []string
	for _, c := range f.calls {
		if c.Name == "apt-get" {
			pakete = append(pakete, c.Args[len(c.Args)-1])
		}
	}
	if len(pakete) != 2 || pakete[0] != dockerPaket || pakete[1] != composePaket {
		t.Errorf("erwartet %v, eingespielt wurde %v", []string{dockerPaket, composePaket}, pakete)
	}
}

func TestDockerInstallMeldetFehlschlag(t *testing.T) {
	f := newFakeRunner()
	f.responses["apt-get install"] = Result{ExitCode: 100, Stderr: "E: Paket docker.io kann nicht gefunden werden"}
	s := NewSystemWithRunner(f)

	err := s.DockerInstall(context.Background(), nil)
	if err == nil {
		t.Fatal("ein gescheiterter apt-Lauf muss einen Fehler ergeben")
	}
	if !strings.Contains(err.Error(), dockerPaket) {
		t.Errorf("der Fehler sollte das Paket nennen: %v", err)
	}
}

// TestParseDockerVersionVertraegtBeiwerk: Docker hängt bei totem Daemon eine
// Fehlerzeile an die Ausgabe. Ein Parser, der daran scheitert, verlöre die
// Clientfassung — also genau die Auskunft, die in diesem Fall noch da ist.
func TestParseDockerVersionVertraegtBeiwerk(t *testing.T) {
	client, server := parseDockerVersion(dockerVersionOhneDaemonOut)
	if client != "27.5.1" {
		t.Errorf("Client = %q, erwartet 27.5.1", client)
	}
	if server != "" {
		t.Errorf("Server sollte leer sein, ist %q", server)
	}

	if c, s := parseDockerVersion("kein JSON"); c != "" || s != "" {
		t.Errorf("unlesbare Ausgabe sollte leere Fassungen ergeben, ergab %q/%q", c, s)
	}
}
