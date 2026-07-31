package httpd

import (
	"encoding/json"
	"errors"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

var errDockerAttrappe = errors.New("dockerd antwortet nicht")

// warteBis pollt, statt eine feste Zeit zu schlafen. Ein Vorgang läuft in einer
// eigenen Goroutine, und wie lange die braucht, weiß niemand — ein fester
// Schlaf ist entweder zu kurz (der Test flackert) oder zu lang (der Lauf dauert).
func warteBis(t *testing.T, bedingung func() bool) {
	t.Helper()
	frist := time.Now().Add(3 * time.Second)
	for time.Now().Before(frist) {
		if bedingung() {
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatal("die erwartete Lage trat nicht ein")
}

// dockerServer baut einen Server mit angemeldetem Konto der gewünschten Rolle
// und einer Attrappe, deren Docker-Zustand der Test setzt.
func dockerServer(t *testing.T, rolle string, st privops.DockerState) (*Server, *fakeOps, *http.Cookie, string) {
	t.Helper()
	s, ops := newSystemServer(t)
	ops.docker = st
	user := addUser(t, s, "konto", rolle)
	cookie, csrf := login(t, s, user)
	return s, ops, cookie, csrf
}

func dockerLesen(t *testing.T, s *Server, cookie *http.Cookie) apiDocker {
	t.Helper()
	rec := get(t, s, "/api/v1/docker", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	var antwort apiDocker
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	return antwort
}

// Der Regelfall: Docker läuft. Dann gibt es nichts anzubieten und nichts
// anzumerken — eine Anmerkung im Normalzustand wäre Lärm.
func TestAPIDockerMeldetLaufendeLaufzeit(t *testing.T) {
	s, _, cookie, _ := dockerServer(t, store.RoleOwner, privops.DockerState{
		Installiert: true, DaemonLaeuft: true, ComposeVerfuegbar: true,
		ClientVersion: "27.5.1", ServerVersion: "27.5.1", ComposeVersion: "2.32.4",
		Paket: "docker.io",
	})

	antwort := dockerLesen(t, s, cookie)
	if !antwort.Installiert || !antwort.DaemonLaeuft || !antwort.ComposeVerfuegbar {
		t.Errorf("der Zustand kam nicht durch: %+v", antwort)
	}
	if antwort.ServerVersion != "27.5.1" || antwort.ComposeVersion != "2.32.4" {
		t.Errorf("Fassungen fehlen: %+v", antwort)
	}
	if antwort.Einspielbar {
		t.Error("ein laufendes Docker braucht keinen apt-Lauf")
	}
	if antwort.Anmerkung != "" {
		t.Errorf("Anmerkung sollte leer sein, ist %q", antwort.Anmerkung)
	}
}

// Fehlt Docker, ist das kein Fehler, sondern ein Angebot. Der Test hält beides
// fest: Status 200 und ein Knopf, den die Oberfläche zeigen darf.
func TestAPIDockerBietetInstallationAn(t *testing.T) {
	s, _, cookie, _ := dockerServer(t, store.RoleOwner, privops.DockerState{})

	antwort := dockerLesen(t, s, cookie)
	if antwort.Installiert {
		t.Error("Docker sollte als fehlend gelten")
	}
	if !antwort.Einspielbar {
		t.Error("ohne Docker muss das Panel die Installation anbieten")
	}
	if antwort.Anmerkung == "" {
		t.Error("der Zustand braucht eine Erklärung")
	}
}

// Der Fall, für den die Trennung da ist: Docker ist installiert, antwortet aber
// nicht. Ein apt-Lauf hilft hier nichts — und deshalb darf der Knopf nicht
// erscheinen. Ein Knopf, der zuverlässig nichts bewirkt, ist schlimmer als
// keiner: Er verschiebt die Suche nach der Ursache um einen Fehlversuch.
func TestAPIDockerBietetOhneDaemonKeineInstallationAn(t *testing.T) {
	s, _, cookie, _ := dockerServer(t, store.RoleOwner, privops.DockerState{
		Installiert: true, ClientVersion: "27.5.1",
	})

	antwort := dockerLesen(t, s, cookie)
	if antwort.Einspielbar {
		t.Error("bei totem Daemon hilft kein apt-Lauf")
	}
	if antwort.Anmerkung == "" {
		t.Error("gerade dieser Zustand braucht die Erklärung")
	}
}

// Fehlt nur Compose, ist der apt-Lauf wieder die richtige Antwort — Stacks
// hängen daran.
func TestAPIDockerBietetComposeNachAn(t *testing.T) {
	s, _, cookie, _ := dockerServer(t, store.RoleOwner, privops.DockerState{
		Installiert: true, DaemonLaeuft: true, ServerVersion: "27.5.1",
	})

	if !dockerLesen(t, s, cookie).Einspielbar {
		t.Error("ein fehlendes Compose lässt sich einspielen")
	}
}

// Ein Fehler der Systemgrenze leert die Seite nicht: Er steht als Feld daneben.
func TestAPIDockerReichtFehlerAlsFeldDurch(t *testing.T) {
	s, ops, cookie, _ := dockerServer(t, store.RoleOwner, privops.DockerState{})
	ops.dockerErr = errDockerAttrappe

	antwort := dockerLesen(t, s, cookie)
	if antwort.Fehler == "" {
		t.Error("der Fehler sollte in der Antwort stehen")
	}
	if !antwort.DarfAendern {
		t.Error("die Rechteauskunft gilt auch dann, wenn der Zustand fehlt")
	}
}

// Jeder Zustand, in dem etwas fehlt, bekommt einen eigenen Satz — und keine
// zwei davon sind derselbe. Das ist der Test zu dem Befund, den der Browser
// gebracht hat: Solange der Satz aus privops kam, konnte er ganz fehlen, und die
// Seite zeigte drei Karten ohne ein Wort dazu.
func TestDockerAnmerkungNenntJeLageDenHandgriff(t *testing.T) {
	faelle := []struct {
		name string
		st   privops.DockerState
		wort string
		leer bool
	}{
		{name: "fehlt", st: privops.DockerState{}, wort: "einspielen"},
		{
			name: "daemon tot",
			st:   privops.DockerState{Installiert: true},
			wort: "docker.service",
		},
		{
			name: "compose fehlt",
			st:   privops.DockerState{Installiert: true, DaemonLaeuft: true},
			wort: "compose",
		},
		{
			name: "alles da",
			st: privops.DockerState{
				Installiert: true, DaemonLaeuft: true, ComposeVerfuegbar: true,
			},
			leer: true,
		},
	}

	gesehen := map[string]bool{}
	for _, f := range faelle {
		satz := dockerAnmerkung(f.st)
		if f.leer {
			// Im Regelfall schweigt das Panel. Ein Satz, der immer dasteht, wird
			// nicht gelesen — und dann wird auch der gelesen nicht, der zählt.
			if satz != "" {
				t.Errorf("%s: erwartet keinen Satz, bekam %q", f.name, satz)
			}
			continue
		}
		if satz == "" {
			t.Errorf("%s: es fehlt der Satz, der sagt, was zu tun ist", f.name)
			continue
		}
		if !strings.Contains(strings.ToLower(satz), f.wort) {
			t.Errorf("%s: der Satz nennt den Handgriff nicht (%q fehlt in %q)", f.name, f.wort, satz)
		}
		if gesehen[satz] {
			t.Errorf("%s: derselbe Satz wie in einer anderen Lage — dann trägt er nichts bei", f.name)
		}
		gesehen[satz] = true
	}
}

// Die Rollenschranke, und zwar auf dem Server. Ein Compose-Stack ist
// Codeausführung als root — Schreibrecht allein genügt dafür nicht.
func TestAPIDockerInstallVerlangtOwner(t *testing.T) {
	s, ops, cookie, csrf := dockerServer(t, store.RoleAdmin, privops.DockerState{})

	rec := postJSON(t, s, "/api/v1/docker/install", "{}", cookie, csrf)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("Status = %d, erwartet 403: %s", rec.Code, rec.Body.String())
	}
	for _, a := range ops.recorded() {
		if strings.HasPrefix(a, "docker:") {
			t.Errorf("es darf nichts gelaufen sein, gelaufen ist %q", a)
		}
	}
}

// Die Rechteauskunft der Antwort muss zur Schranke des Servers passen. Liefen
// beide auseinander, zeigte die Oberfläche einem Admin-Konto einen Knopf, der
// zuverlässig 403 ergibt.
func TestAPIDockerNenntAdminKeinAenderungsrecht(t *testing.T) {
	s, _, cookie, _ := dockerServer(t, store.RoleAdmin, privops.DockerState{Installiert: true})

	if dockerLesen(t, s, cookie).DarfAendern {
		t.Error("ein Admin-Konto darf Docker nicht bedienen")
	}
}

func TestAPIDockerInstallVerlangtToken(t *testing.T) {
	s, ops, cookie, _ := dockerServer(t, store.RoleOwner, privops.DockerState{})

	rec := postJSON(t, s, "/api/v1/docker/install", "{}", cookie, "")
	if rec.Code != http.StatusForbidden {
		t.Fatalf("Status = %d, erwartet 403: %s", rec.Code, rec.Body.String())
	}
	if len(ops.recorded()) != 0 {
		t.Error("ohne Token darf nichts gelaufen sein")
	}
}

// Der vollständige Weg: Der POST ist sofort zurück (202) und trägt den Vorgang
// mit, damit sich die Oberfläche gleich anhängen kann. Erst abzufragen wäre eine
// Runde später — und bei einem schnellen Vorgang käme „läuft nicht" zurück.
func TestAPIDockerInstallStartetVorgang(t *testing.T) {
	s, ops, cookie, csrf := dockerServer(t, store.RoleOwner, privops.DockerState{})
	ops.dockerInstallDone = make(chan struct{})

	rec := postJSON(t, s, "/api/v1/docker/install", "{}", cookie, csrf)
	if rec.Code != http.StatusAccepted {
		t.Fatalf("Status = %d, erwartet 202: %s", rec.Code, rec.Body.String())
	}

	var gestartet apiVorgangGestartet
	if err := json.Unmarshal(rec.Body.Bytes(), &gestartet); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	if gestartet.Job.Art != jobDockerInstall {
		t.Errorf("Vorgangsart = %q, erwartet %q", gestartet.Job.Art, jobDockerInstall)
	}

	select {
	case <-ops.dockerInstallDone:
	case <-time.After(2 * time.Second):
		t.Fatal("der Vorgang ist nicht gelaufen")
	}

	// Nach dem Lauf steht der neue Zustand in derselben Ressource. Das ist der
	// Punkt, an dem sich zeigt, ob die Seite hinterher das Richtige zeigt.
	warteBis(t, func() bool {
		j := dockerLesen(t, s, cookie).Job
		return j != nil && !j.Laeuft
	})
	antwort := dockerLesen(t, s, cookie)
	if !antwort.Installiert || antwort.Einspielbar {
		t.Errorf("nach der Installation sollte Docker stehen: %+v", antwort)
	}
	if antwort.Job == nil || antwort.Job.Laeuft {
		t.Errorf("der Vorgang sollte beendet sein: %+v", antwort.Job)
	}
}

// Zwei apt-Läufe gleichzeitig blockieren sich an der dpkg-Sperre. Das soll die
// Schnittstelle verhindern und nicht ausprobieren.
func TestAPIDockerInstallNurEinerZurZeit(t *testing.T) {
	s, ops, cookie, csrf := dockerServer(t, store.RoleOwner, privops.DockerState{})
	// Der erste Lauf hängt, bis der Test ihn freigibt — sonst wäre er vorbei,
	// bevor der zweite kommt, und der Test prüfte nichts.
	sperre := make(chan struct{})
	ops.dockerInstallHalt = sperre
	defer close(sperre)

	if rec := postJSON(t, s, "/api/v1/docker/install", "{}", cookie, csrf); rec.Code != http.StatusAccepted {
		t.Fatalf("erster Aufruf: Status = %d", rec.Code)
	}
	warteBis(t, func() bool {
		j := dockerLesen(t, s, cookie).Job
		return j != nil && j.Laeuft
	})

	rec := postJSON(t, s, "/api/v1/docker/install", "{}", cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Errorf("zweiter Aufruf: Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	if laeufe := zaehleInstalls(ops); laeufe != 1 {
		t.Errorf("es darf genau ein Lauf gestartet sein, gestartet sind %d", laeufe)
	}
}

func zaehleInstalls(ops *fakeOps) int {
	n := 0
	for _, a := range ops.recorded() {
		if a == "docker:install" {
			n++
		}
	}
	return n
}
