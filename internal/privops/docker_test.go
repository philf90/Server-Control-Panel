package privops

import (
	"context"
	"errors"
	"fmt"
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

// ---------------------------------------------------------------- Container ---

// Aufgezeichnete Ausgaben. NDJSON — eine Zeile je Container, kein Feld.
const (
	dockerPSOut = `{"Command":"\"/docker-entrypoint.sh\"","CreatedAt":"2026-07-30 10:11:12 +0000 UTC","ID":"3f2b8c1d9a4e","Image":"nginx:alpine","Labels":"com.docker.compose.project=web,com.docker.compose.service=proxy","Names":"web-proxy-1","Ports":"0.0.0.0:8080-\u003e80/tcp","State":"running","Status":"Up 3 hours (healthy)"}
{"Command":"\"postgres\"","CreatedAt":"2026-07-28 08:00:00 +0000 UTC","ID":"aa11bb22cc33","Image":"postgres:16","Labels":"com.docker.compose.project=web,com.docker.compose.service=db","Names":"web-db-1","Ports":"","State":"exited","Status":"Exited (137) 2 days ago"}
{"Command":"\"/bin/sh\"","CreatedAt":"2026-07-31 09:00:00 +0000 UTC","ID":"ff99ee88dd77","Image":"alpine","Labels":"","Names":"handarbeit,alt-name","Ports":"","State":"running","Status":"Up 20 minutes"}`

	// Docker schreibt bei einer Warnung gelegentlich eine Textzeile dazwischen.
	dockerPSMitWarnungOut = `WARNING: bridge network not found
{"ID":"3f2b8c1d9a4e","Image":"nginx:alpine","Names":"web-proxy-1","State":"running","Status":"Up 3 hours","Labels":""}`

	dockerInspectOut = `{"Id":"3f2b8c1d9a4e","Name":"/web-proxy-1","Created":"2026-07-30T10:11:12.5Z",
	 "State":{"Status":"exited","ExitCode":137,"Health":{"Status":"unhealthy"}},
	 "Config":{"Image":"nginx:alpine","Cmd":["nginx","-g","daemon off;"],
	           "Env":["PATH=/usr/local/sbin","DB_PASSWORD=geheim","TZ=Europe/Berlin"],
	           "User":"nginx","Labels":{"com.docker.compose.project":"web","com.docker.compose.service":"proxy"}},
	 "HostConfig":{"Privileged":true,"RestartPolicy":{"Name":"unless-stopped"}},
	 "Mounts":[{"Type":"bind","Source":"/srv/web","Destination":"/usr/share/nginx/html","RW":false},
	           {"Type":"volume","Name":"web_daten","Source":"/var/lib/docker/volumes/web_daten/_data","Destination":"/daten","RW":true}],
	 "NetworkSettings":{"Networks":{"web_default":{},"bridge":{}}}}`

	dockerStatsOut = `{"BlockIO":"0B / 0B","CPUPerc":"0.02%","Container":"3f2b8c1d9a4e","ID":"3f2b8c1d9a4e","MemPerc":"1.31%","MemUsage":"20.5MiB / 1.5GiB","Name":"web-proxy-1","NetIO":"1.2kB / 830B","PIDs":"5"}
{"BlockIO":"1.2MB / 0B","CPUPerc":"0.00%","Container":"ff99ee88dd77","ID":"ff99ee88dd77","MemPerc":"0.10%","MemUsage":"1.5MiB / 1.5GiB","Name":"handarbeit","NetIO":"0B / 0B","PIDs":"1"}`
)

func TestParseDockerPS(t *testing.T) {
	liste := parseDockerPS(dockerPSOut)
	if len(liste) != 3 {
		t.Fatalf("erwartet 3 Container, gelesen %d", len(liste))
	}

	erst := liste[0]
	if erst.Name != "web-proxy-1" || erst.Image != "nginx:alpine" || erst.Zustand != "running" {
		t.Errorf("erste Zeile falsch gelesen: %+v", erst)
	}
	// Die Gesundheit steht in Klammern im Statussatz und in keinem eigenen Feld.
	if erst.Gesundheit != "healthy" {
		t.Errorf("Gesundheit = %q, erwartet healthy", erst.Gesundheit)
	}
	if erst.Stack != "web" || erst.Dienst != "proxy" {
		t.Errorf("Compose-Labels falsch gelesen: Stack %q, Dienst %q", erst.Stack, erst.Dienst)
	}
	// Dockers {{json}} benutzt Gos Kodierer, und der maskiert <, > und & als
	// \u003c und dergleichen. Die Aufzeichnung trägt die Maskierung deshalb so,
	// wie Docker sie schreibt; json.Unmarshal löst sie auf. Stünde hier ein
	// rohes ">", prüfte der Test einen Fall, den es nicht gibt.
	if erst.Ports != "0.0.0.0:8080->80/tcp" {
		t.Errorf("Ports = %q — die Maskierung aus Dockers JSON ist nicht aufgelöst", erst.Ports)
	}

	// Ein beendeter Container ist die interessanteste Zeile der Liste. Ohne
	// --all wäre er gar nicht da.
	if liste[1].Zustand != "exited" || !strings.Contains(liste[1].Status, "137") {
		t.Errorf("beendeter Container falsch gelesen: %+v", liste[1])
	}
	// Ohne Health-Check bleibt die Gesundheit LEER und ist nicht "gesund":
	// Es heißt, dass niemand nachsieht.
	if liste[2].Gesundheit != "" {
		t.Errorf("ohne Prüfung sollte die Gesundheit leer sein, ist %q", liste[2].Gesundheit)
	}
	// Mehrere Namen: der erste zählt.
	if liste[2].Name != "handarbeit" {
		t.Errorf("Name = %q, erwartet den ersten von mehreren", liste[2].Name)
	}
	if liste[2].Stack != "" {
		t.Errorf("ein Container ohne Compose-Labels hat keinen Stack, hat aber %q", liste[2].Stack)
	}
}

// Eine Zeile, die kein JSON ist, darf die übrigen nicht mitnehmen: Docker
// schreibt Warnungen zwischen die Ausgabe, und daran soll die Anzeige aller
// anderen Container nicht scheitern.
func TestParseDockerPSUeberspringtFremdeZeilen(t *testing.T) {
	liste := parseDockerPS(dockerPSMitWarnungOut)
	if len(liste) != 1 {
		t.Fatalf("erwartet 1 Container, gelesen %d", len(liste))
	}
	if liste[0].Name != "web-proxy-1" {
		t.Errorf("falsche Zeile gelesen: %+v", liste[0])
	}
}

func TestParseDockerInspect(t *testing.T) {
	d, err := parseDockerInspect(dockerInspectOut)
	if err != nil {
		t.Fatalf("parseDockerInspect: %v", err)
	}
	if d.Name != "web-proxy-1" {
		t.Errorf("Name = %q — der führende Schrägstrich gehört weg", d.Name)
	}
	if d.Neustartregel != "unless-stopped" {
		t.Errorf("Neustartregel = %q", d.Neustartregel)
	}
	// Der Exit-Code gilt nur für einen beendeten Container.
	if d.ExitCode != 137 {
		t.Errorf("ExitCode = %d, erwartet 137", d.ExitCode)
	}
	if !d.Privilegiert {
		t.Error("privileged: true muss durchkommen — es ist die Angabe, die auf dieser Seite am meisten zählt")
	}
	if d.Befehl != "nginx -g daemon off;" {
		t.Errorf("Befehl = %q", d.Befehl)
	}

	// Der Kern: die Umgebung wird GEZÄHLT und nicht ausgeliefert. In der
	// Beispielausgabe steht ein Datenbankpasswort — es darf nirgends im
	// Ergebnis auftauchen.
	if d.Umgebung != 3 {
		t.Errorf("Umgebung = %d, erwartet die Anzahl 3", d.Umgebung)
	}
	if strings.Contains(fmt.Sprintf("%+v", d), "geheim") {
		t.Error("der Wert einer Umgebungsvariablen steht im Ergebnis — genau das darf nicht passieren")
	}

	if len(d.Mounts) != 2 {
		t.Fatalf("erwartet 2 Mounts, gelesen %d", len(d.Mounts))
	}
	if d.Mounts[0].Art != "bind" || d.Mounts[0].Quelle != "/srv/web" || d.Mounts[0].Schreibar {
		t.Errorf("Bind-Mount falsch gelesen: %+v", d.Mounts[0])
	}
	// Bei einem Volume ist der Name die brauchbare Angabe, nicht der Pfad unter
	// /var/lib/docker — den kennt niemand auswendig, und er ändert sich.
	if d.Mounts[1].Quelle != "web_daten" {
		t.Errorf("Volume sollte unter seinem Namen stehen, steht als %q", d.Mounts[1].Quelle)
	}
	if len(d.Netze) != 2 || d.Netze[0] != "bridge" {
		t.Errorf("Netze falsch gelesen (und sortiert): %v", d.Netze)
	}
}

func TestParseDockerInspectMeldetUnlesbares(t *testing.T) {
	if _, err := parseDockerInspect("kein JSON"); err == nil {
		t.Error("eine unlesbare Ausgabe muss einen Fehler ergeben und keinen leeren Container")
	}
}

func TestParseDockerStats(t *testing.T) {
	stats := parseDockerStats(dockerStatsOut)
	if len(stats) != 2 {
		t.Fatalf("erwartet 2 Zeilen, gelesen %d", len(stats))
	}
	if stats[0].CPU != "0.02%" || stats[0].Speiche != "20.5MiB / 1.5GiB" {
		t.Errorf("Werte falsch gelesen: %+v", stats[0])
	}
	if stats[0].Name != "web-proxy-1" {
		t.Errorf("Name = %q", stats[0].Name)
	}
}

// Image-Kennungen sind anders gebaut als Containernamen: "sha256:aaa",
// "nginx:alpine", "ghcr.io/o/n:1.2", "nginx@sha256:…". Der erste Anlauf hat für
// beides dieselbe Prüfung benutzt — und die lehnte jede Image-Kennung ab.
func TestValidateImageRef(t *testing.T) {
	gut := []string{
		"sha256:aaa111", "nginx", "nginx:alpine", "ghcr.io/betreiber/dienst:1.2",
		"nginx@sha256:abcdef", "registry.example.com:5000/img:1",
	}
	for _, ref := range gut {
		if err := ValidateImageRef(ref); err != nil {
			t.Errorf("%q sollte gültig sein: %v", ref, err)
		}
	}
	schlecht := []string{"", "-rf", "--all", "a b", "a;b", "a|b", "a\nb", "a`b`", strings.Repeat("x", 300)}
	for _, ref := range schlecht {
		if err := ValidateImageRef(ref); err == nil {
			t.Errorf("%q sollte abgelehnt werden", ref)
		}
	}
}

func TestValidateContainerID(t *testing.T) {
	gut := []string{"3f2b8c1d9a4e", "web-proxy-1", "a", "A_b.c-d"}
	for _, id := range gut {
		if err := ValidateContainerID(id); err != nil {
			t.Errorf("%q sollte gültig sein: %v", id, err)
		}
	}
	// Der Grund für die Prüfung: Ein Wert, der wie eine Option aussieht oder
	// Steuerzeichen trägt, darf nicht bis zur Kommandozeile kommen — auch wenn
	// das "--" davor ihn ohnehin als Operanden festnagelt. Zwei Riegel.
	schlecht := []string{"", "-rf", "--all", "a b", "a;b", "a/b", "a\nb", "../etc", strings.Repeat("x", 200)}
	for _, id := range schlecht {
		if err := ValidateContainerID(id); err == nil {
			t.Errorf("%q sollte abgelehnt werden", id)
		}
	}
}

// Die Aktion ist ein eigener Typ mit Allowlist. Der Test hält fest, dass ein
// erfundener Wert nicht bis zum Kommando kommt — und dass dann NICHTS gelaufen
// ist, nicht bloß ein Fehler zurückkam.
func TestDockerContainerActionLehntUnbekanntesAb(t *testing.T) {
	f := newFakeRunner()
	s := NewSystemWithRunner(f)

	if err := s.DockerContainerAction(context.Background(), "web-proxy-1", "exec"); err == nil {
		t.Error("eine unbekannte Aktion muss abgelehnt werden")
	}
	if err := s.DockerContainerAction(context.Background(), "-rf", ContainerStop); err == nil {
		t.Error("eine ungültige Kennung muss abgelehnt werden")
	}
	if len(f.calls) != 0 {
		t.Errorf("es darf kein Kommando gelaufen sein, gelaufen sind %d", len(f.calls))
	}
}

func TestDockerContainerActionRuftDockerAuf(t *testing.T) {
	f := newFakeRunner()
	f.responses["docker stop"] = Result{}
	s := NewSystemWithRunner(f)

	if err := s.DockerContainerAction(context.Background(), "web-proxy-1", ContainerStop); err != nil {
		t.Fatalf("DockerContainerAction: %v", err)
	}
	args := f.lastCall().Args
	if len(args) < 3 || args[0] != "stop" || args[len(args)-2] != "--" || args[len(args)-1] != "web-proxy-1" {
		t.Errorf("Argumente = %v — der Name muss hinter -- stehen", args)
	}
	// Stoppen darf länger dauern als eine Statusabfrage: Docker wartet nach
	// SIGTERM zehn Sekunden, bevor es SIGKILL nachschiebt.
	if f.lastCall().Timeout <= defaultTimeout {
		t.Errorf("Frist = %s, erwartet mehr als die Vorgabe", f.lastCall().Timeout)
	}
}

func TestDockerContainerRemoveErzwingtNurAufWunsch(t *testing.T) {
	f := newFakeRunner()
	f.responses["docker rm"] = Result{}
	s := NewSystemWithRunner(f)

	if err := s.DockerContainerRemove(context.Background(), "web-db-1", false); err != nil {
		t.Fatalf("DockerContainerRemove: %v", err)
	}
	if strings.Contains(strings.Join(f.lastCall().Args, " "), "--force") {
		t.Error("ohne erzwingen darf kein --force gesetzt sein — sonst beendet ein Aufräumen einen laufenden Dienst")
	}

	if err := s.DockerContainerRemove(context.Background(), "web-db-1", true); err != nil {
		t.Fatalf("DockerContainerRemove(erzwingen): %v", err)
	}
	if !strings.Contains(strings.Join(f.lastCall().Args, " "), "--force") {
		t.Error("mit erzwingen fehlt --force")
	}
}

// Ein Fehlschlag muss die Meldung von Docker tragen und nicht nur einen Code.
// "endete mit Code 1" ist die Auskunft, nach der man sucht, wenn die Ursache
// danebenstand.
func TestDockerContainerActionReichtDockersMeldungDurch(t *testing.T) {
	f := newFakeRunner()
	f.responses["docker start"] = Result{
		ExitCode: 1,
		Stderr:   "Error response from daemon: No such container: weg\n",
	}
	s := NewSystemWithRunner(f)

	err := s.DockerContainerAction(context.Background(), "weg", ContainerStart)
	if err == nil {
		t.Fatal("ein Exit-Code ungleich null muss einen Fehler ergeben")
	}
	if !strings.Contains(err.Error(), "No such container") {
		t.Errorf("die Meldung von Docker fehlt: %v", err)
	}
}

func TestDockerContainerLogsBegrenztDieZeilen(t *testing.T) {
	f := newFakeRunner()
	f.responses["docker logs"] = Result{Stdout: "2026-07-31T10:00:00Z start\n2026-07-31T10:00:01Z bereit\n"}
	s := NewSystemWithRunner(f)

	zeilen, err := s.DockerContainerLogs(context.Background(), "web-proxy-1", 1_000_000)
	if err != nil {
		t.Fatalf("DockerContainerLogs: %v", err)
	}
	if len(zeilen) != 2 {
		t.Errorf("erwartet 2 Zeilen, bekam %d: %v", len(zeilen), zeilen)
	}
	args := strings.Join(f.lastCall().Args, " ")
	if !strings.Contains(args, "--tail 200") {
		t.Errorf("eine unsinnige Zeilenzahl muss auf die Vorgabe fallen: %q", args)
	}
	if !strings.Contains(args, "--timestamps") {
		t.Error("ohne Zeitstempel beantwortet ein Protokoll die Frage nicht, mit der man es öffnet")
	}
}

// Container schreiben auf stdout UND stderr, je nachdem, wohin das Programm im
// Container schrieb. Ein Panel, das stderr unterschlägt, verschweigt genau das,
// wonach jemand sucht.
func TestDockerContainerLogsNimmtBeideStroeme(t *testing.T) {
	f := newFakeRunner()
	f.responses["docker logs"] = Result{
		Stdout: "2026-07-31T10:00:00Z bereit\n",
		Stderr: "2026-07-31T10:00:02Z FEHLER: keine Verbindung\n",
	}
	s := NewSystemWithRunner(f)

	zeilen, err := s.DockerContainerLogs(context.Background(), "web-proxy-1", 100)
	if err != nil {
		t.Fatalf("DockerContainerLogs: %v", err)
	}
	if len(zeilen) != 2 {
		t.Fatalf("erwartet 2 Zeilen, bekam %v", zeilen)
	}
	if !strings.Contains(strings.Join(zeilen, "\n"), "FEHLER") {
		t.Error("die Zeile von stderr fehlt")
	}
}

// ------------------------------------------------------------------ Bestand ---

const (
	dockerImagesOut = `{"Containers":"N/A","CreatedSince":"11 days ago","Digest":"<none>","ID":"sha256:aaa","Repository":"nginx","Size":"48.9MB","Tag":"alpine"}
{"Containers":"N/A","CreatedSince":"3 days ago","Digest":"<none>","ID":"sha256:bbb","Repository":"<none>","Size":"1.02GB","Tag":"<none>"}`

	dockerVolumesOut = `{"Driver":"local","Labels":"","Mountpoint":"/var/lib/docker/volumes/web_daten/_data","Name":"web_daten","Scope":"local"}
{"Driver":"local","Labels":"","Mountpoint":"/var/lib/docker/volumes/tmp99/_data","Name":"tmp99","Scope":"local"}`

	dockerNetzeOut = `{"CreatedAt":"2026-07-30 10:00:00 +0000 UTC","Driver":"bridge","ID":"1a2b3c","Name":"bridge","Scope":"local"}
{"CreatedAt":"2026-07-30 10:05:00 +0000 UTC","Driver":"bridge","ID":"4d5e6f","Name":"web_default","Scope":"local"}`

	dockerDFOut = `{"Active":"5","Reclaimable":"1.5GB (46%)","Size":"3.2GB","TotalCount":"12","Type":"Images"}
{"Active":"4","Reclaimable":"12MB (100%)","Size":"12MB","TotalCount":"7","Type":"Containers"}
{"Active":"2","Reclaimable":"800MB (80%)","Size":"1GB","TotalCount":"5","Type":"Local Volumes"}
{"Active":"0","Reclaimable":"2.1GB","Size":"2.1GB","TotalCount":"31","Type":"Build Cache"}`

	dockerPruneOut = `Deleted Images:
deleted: sha256:bbb
untagged: alt:1.0

Total reclaimed space: 1.234GB
`
)

func TestParseDockerImages(t *testing.T) {
	liste := parseDockerImages(dockerImagesOut)
	if len(liste) != 2 {
		t.Fatalf("erwartet 2 Images, gelesen %d", len(liste))
	}
	if liste[0].Repo != "nginx" || liste[0].Tag != "alpine" || liste[0].Groesse != "48.9MB" {
		t.Errorf("erste Zeile falsch gelesen: %+v", liste[0])
	}
	if liste[0].Verwaist {
		t.Error("ein benanntes Image ist nicht verwaist")
	}
	// Der zweite ist der Rest, der bei jedem Neubau übrig bleibt — und der
	// übliche Grund, warum eine Platte volläuft. Ihn zu erkennen ist der Zweck
	// dieses Feldes.
	if !liste[1].Verwaist {
		t.Errorf("ein Image ohne Namen ist verwaist: %+v", liste[1])
	}
}

func TestParseDockerVolumesUndNetze(t *testing.T) {
	vols := parseDockerVolumes(dockerVolumesOut)
	if len(vols) != 2 || vols[0].Name != "web_daten" || vols[0].Treiber != "local" {
		t.Errorf("Volumes falsch gelesen: %+v", vols)
	}
	if vols[0].Ort == "" {
		t.Error("der Ort gehört dazu: Er ist der Weg, über SSH an die Daten zu kommen")
	}

	netze := parseDockerNetze(dockerNetzeOut)
	if len(netze) != 2 || netze[1].Name != "web_default" || netze[1].Treiber != "bridge" {
		t.Errorf("Netze falsch gelesen: %+v", netze)
	}
}

func TestParseDockerDF(t *testing.T) {
	posten := parseDockerDF(dockerDFOut)
	if len(posten) != 4 {
		t.Fatalf("erwartet 4 Posten, gelesen %d", len(posten))
	}
	if posten[0].Art != "Images" || posten[0].Freigebbar != "1.5GB (46%)" {
		t.Errorf("erster Posten falsch gelesen: %+v", posten[0])
	}
	// Der Baucache steht bei einem Server, der baut, oft an erster Stelle der
	// Platzfresser — er darf nicht fehlen.
	if posten[3].Art != "Build Cache" || posten[3].Groesse != "2.1GB" {
		t.Errorf("der Baucache fehlt oder ist falsch: %+v", posten[3])
	}
}

// Der freigegebene Platz ist die Antwort, wegen der jemand aufräumt. Fehlt die
// Zeile, kommt LEER zurück und nicht "0 B" — eine erfundene Null wäre schlechter
// als keine Angabe.
func TestFreigegebenAus(t *testing.T) {
	if got := freigegebenAus(dockerPruneOut); got != "1.234GB" {
		t.Errorf("freigegeben = %q, erwartet 1.234GB", got)
	}
	if got := freigegebenAus("nichts zu tun\n"); got != "" {
		t.Errorf("ohne die Zeile erwartet leer, bekam %q", got)
	}
}

func TestDockerPruneBautDieRichtigenArgumente(t *testing.T) {
	f := newFakeRunner()
	f.responses["docker"] = Result{Stdout: dockerPruneOut}
	s := NewSystemWithRunner(f)

	frei, err := s.DockerPrune(context.Background(), PruneImages, false, nil)
	if err != nil {
		t.Fatalf("DockerPrune: %v", err)
	}
	if frei != "1.234GB" {
		t.Errorf("freigegeben = %q", frei)
	}
	args := strings.Join(f.lastCall().Args, " ")
	if args != "image prune --force" {
		t.Errorf("Argumente = %q", args)
	}

	// --all ist der Unterschied zwischen „räum die Reste weg" und „wirf alles
	// raus, was gerade kein Container benutzt". Es darf nur auf Wunsch dabei sein.
	if _, err := s.DockerPrune(context.Background(), PruneImages, true, nil); err != nil {
		t.Fatalf("DockerPrune(alle): %v", err)
	}
	if !strings.Contains(strings.Join(f.lastCall().Args, " "), "--all") {
		t.Errorf("--all fehlt: %v", f.lastCall().Args)
	}

	// Für Volumes und Netze gibt es kein --all: Dort räumt prune ohnehin alles
	// Unbenutzte weg, und ein zusätzliches Flag wäre eine Zusage ohne Wirkung.
	if _, err := s.DockerPrune(context.Background(), PruneVolumes, true, nil); err != nil {
		t.Fatalf("DockerPrune(volumes): %v", err)
	}
	if strings.Contains(strings.Join(f.lastCall().Args, " "), "--all") {
		t.Errorf("volume prune kennt kein --all: %v", f.lastCall().Args)
	}
}

func TestDockerPruneLehntUnbekannteArtAb(t *testing.T) {
	f := newFakeRunner()
	s := NewSystemWithRunner(f)

	// "system" fehlt bewusst in der Allowlist: "docker system prune" räumt in
	// einem Zug alles auf, und eine Aktion, deren Umfang niemand überblickt,
	// kann keine sinnvolle Rückfrage tragen.
	if _, err := s.DockerPrune(context.Background(), "system", false, nil); err == nil {
		t.Error("eine unbekannte Art muss abgelehnt werden")
	}
	if len(f.calls) != 0 {
		t.Errorf("es darf kein Kommando gelaufen sein, gelaufen sind %d", len(f.calls))
	}
}

// Ein Image wird OHNE --force entfernt: Ist es in Gebrauch, soll Docker das
// sagen und nicht der Container mitgerissen werden.
func TestDockerImageRemoveOhneForce(t *testing.T) {
	f := newFakeRunner()
	f.responses["docker image rm"] = Result{}
	s := NewSystemWithRunner(f)

	if err := s.DockerImageRemove(context.Background(), "sha256:aaa"); err != nil {
		t.Fatalf("DockerImageRemove: %v", err)
	}
	args := strings.Join(f.lastCall().Args, " ")
	if strings.Contains(args, "--force") {
		t.Errorf("kein --force erwartet: %q", args)
	}
	if !strings.Contains(args, "-- sha256:aaa") {
		t.Errorf("die Kennung muss hinter -- stehen: %q", args)
	}
}

func TestDockerBestandLehntUngueltigeKennungAb(t *testing.T) {
	f := newFakeRunner()
	s := NewSystemWithRunner(f)
	ctx := context.Background()

	if err := s.DockerImageRemove(ctx, "--all"); err == nil {
		t.Error("eine Kennung, die wie eine Option aussieht, muss abgelehnt werden")
	}
	if err := s.DockerImageRemove(ctx, "nginx; rm -rf /"); err == nil {
		t.Error("eine Kennung mit Shell-Zeichen muss abgelehnt werden")
	}
	if err := s.DockerVolumeRemove(ctx, "a b"); err == nil {
		t.Error("ein Name mit Leerzeichen muss abgelehnt werden")
	}
	if err := s.DockerNetworkRemove(ctx, ""); err == nil {
		t.Error("eine leere Kennung muss abgelehnt werden")
	}
	if len(f.calls) != 0 {
		t.Errorf("es darf kein Kommando gelaufen sein, gelaufen sind %d", len(f.calls))
	}
}
