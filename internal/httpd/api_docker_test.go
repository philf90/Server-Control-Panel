package httpd

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
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

// ---------------------------------------------------------------- Container ---

// beispielContainer deckt die vier Lagen ab, die die Liste unterschiedlich
// behandeln muss: laufend und gesund, laufend und UNGESUND (der Fall, den man
// am leichtesten übersieht), mit Fehlercode beendet, sauber beendet.
func beispielContainer() []privops.Container {
	return []privops.Container{
		{
			ID: "aaaa11112222", Name: "web-proxy-1", Image: "nginx:alpine",
			Zustand: "running", Status: "Up 3 hours (healthy)", Gesundheit: "healthy",
			Ports: "0.0.0.0:8080->80/tcp", Stack: "web", Dienst: "proxy",
		},
		{
			ID: "bbbb11112222", Name: "web-api-1", Image: "api:1.4",
			Zustand: "running", Status: "Up 2 hours (unhealthy)", Gesundheit: "unhealthy",
			Stack: "web", Dienst: "api",
		},
		{
			ID: "cccc11112222", Name: "web-db-1", Image: "postgres:16",
			Zustand: "exited", Status: "Exited (137) 2 days ago", Stack: "web", Dienst: "db",
		},
		{
			ID: "dddd11112222", Name: "auftrag", Image: "alpine",
			Zustand: "exited", Status: "Exited (0) 5 minutes ago",
		},
	}
}

func containerServer(t *testing.T, rolle string) (*Server, *fakeOps, *http.Cookie, string) {
	t.Helper()
	s, ops, cookie, csrf := dockerServer(t, rolle, privops.DockerState{
		Installiert: true, DaemonLaeuft: true, ComposeVerfuegbar: true,
	})
	ops.container = beispielContainer()
	ops.containerLogs = []string{"2026-07-31T10:00:00Z bereit", "2026-07-31T10:00:02Z Anfrage"}
	return s, ops, cookie, csrf
}

// rueckfrageAus liest die Rückfrage aus einer 409-Antwort.
//
// Eigene Hilfe statt mussJSON: Das verlangt Status 200 und ist damit für genau
// die Antwort ungeeignet, um die es hier geht. Beim ersten Anlauf stand hier
// mussJSON, und der Test scheiterte mit „erwartet 200" an einer Stelle, an der
// 409 richtig war — die Meldung zeigte auf den Handler statt auf den Test.
func rueckfrageAus(t *testing.T, rec *httptest.ResponseRecorder) apiBestaetigungAntwort {
	t.Helper()
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	var frage apiBestaetigungAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &frage); err != nil {
		t.Fatalf("Rückfrage nicht lesbar: %v — %s", err, rec.Body.String())
	}
	return frage
}

func containerListe(t *testing.T, s *Server, cookie *http.Cookie) apiContainerListe {
	t.Helper()
	rec := get(t, s, "/api/v1/docker/containers", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	var antwort apiContainerListe
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	return antwort
}

// Auffälliges zuerst. Wer die Seite öffnet, sucht das, was nicht stimmt —
// alphabetisch sortiert stünde es irgendwo in der Mitte.
func TestAPIDockerContainerSortiertAuffaelligesZuerst(t *testing.T) {
	s, _, cookie, _ := containerServer(t, store.RoleOwner)

	liste := containerListe(t, s, cookie)
	if len(liste.Zeilen) != 4 {
		t.Fatalf("erwartet 4 Zeilen, bekam %d", len(liste.Zeilen))
	}
	namen := []string{liste.Zeilen[0].Name, liste.Zeilen[1].Name}
	if !enthaelt(namen, "web-api-1") || !enthaelt(namen, "web-db-1") {
		t.Errorf("die auffälligen Container stehen nicht oben: %v", namen)
	}
	// Ein laufender, aber ungesunder Container ist der schlimmere Fall: Er steht
	// auf „läuft" und tut trotzdem nicht, wofür er da ist.
	if liste.Zeilen[0].ZustandStufe != "schlecht" {
		t.Errorf("die oberste Zeile ist %q/%q, erwartet die Stufe schlecht",
			liste.Zeilen[0].Name, liste.Zeilen[0].ZustandStufe)
	}
}

// Mit Code 0 beendet ist ein aufgeräumter Container und kein Befund — ein
// einmaliger Auftrag etwa. Ihn als Problem zu zählen hieße, auf jedem Server mit
// Wartungsjobs dauerhaft einen roten Punkt zu zeigen.
func TestAPIDockerContainerZaehltSauberBeendeteNichtAlsBefund(t *testing.T) {
	s, _, cookie, _ := containerServer(t, store.RoleOwner)

	liste := containerListe(t, s, cookie)
	if liste.Zaehler.Alle != 4 || liste.Zaehler.Laufend != 2 || liste.Zaehler.Gestoppt != 2 {
		t.Errorf("Zähler falsch: %+v", liste.Zaehler)
	}
	if liste.Zaehler.Auffaellig != 2 {
		t.Errorf("Auffällig = %d, erwartet 2 (ungesund und Code 137)", liste.Zaehler.Auffaellig)
	}
	for _, z := range liste.Zeilen {
		if z.Name == "auftrag" && z.Auffaellig {
			t.Error("ein mit Code 0 beendeter Container ist kein Befund")
		}
	}
}

// Die Handgriffe kommen vom Server und passen zum Zustand. Ein Knopf „starten"
// an einem laufenden Container läuft in einen Fehler — und dann ist der Knopf
// schon der Fehler.
func TestAPIDockerContainerNenntPassendeHandgriffe(t *testing.T) {
	s, _, cookie, _ := containerServer(t, store.RoleOwner)

	for _, z := range containerListe(t, s, cookie).Zeilen {
		switch z.Zustand {
		case "running":
			if enthaelt(z.Aktionen, "start") {
				t.Errorf("%s läuft und bekommt trotzdem „starten"+`"`, z.Name)
			}
			if !enthaelt(z.Aktionen, "stop") {
				t.Errorf("%s läuft, „stoppen"+`" fehlt aber`, z.Name)
			}
		case "exited":
			if enthaelt(z.Aktionen, "stop") {
				t.Errorf("%s ist beendet und bekommt trotzdem „stoppen"+`"`, z.Name)
			}
			if !enthaelt(z.Aktionen, "start") {
				t.Errorf("%s ist beendet, „starten"+`" fehlt aber`, z.Name)
			}
		}
		if !enthaelt(z.Aktionen, "remove") {
			t.Errorf("%s: „entfernen"+`" sollte immer gehen`, z.Name)
		}
	}
}

// Der Kern des Detailtyps: Die Umgebungsvariablen werden GEZÄHLT und nicht
// ausgeliefert. Sie tragen auf jedem zweiten Server ein Datenbankpasswort.
func TestAPIDockerContainerDetailLiefertKeineUmgebungswerte(t *testing.T) {
	s, ops, cookie, _ := containerServer(t, store.RoleOwner)
	_ = ops

	rec := get(t, s, "/api/v1/docker/containers/aaaa11112222", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	rumpf := rec.Body.String()
	if strings.Contains(rumpf, "\"env\"") || strings.Contains(rumpf, "PASSWORD") {
		t.Error("die Antwort trägt Umgebungswerte — sie darf es nie")
	}

	var d apiContainerDetail
	mussJSON(t, rec, &d)
	if len(d.Zeilen) == 0 {
		t.Error("der Protokollauszug sollte mit dem Detail kommen: Wer klickt, will wissen, was der Container sagt")
	}
	if d.Kurz != "aaaa11112222"[:12] {
		t.Errorf("Kurz = %q — der Server kürzt, damit Liste und Inspektor dieselbe Zahl zeigen", d.Kurz)
	}
}

func TestAPIDockerContainerDetailMeldetUnbekannten(t *testing.T) {
	s, _, cookie, _ := containerServer(t, store.RoleOwner)

	rec := get(t, s, "/api/v1/docker/containers/gibtesnicht", cookie)
	if rec.Code != http.StatusBadGateway {
		t.Errorf("Status = %d, erwartet 502: %s", rec.Code, rec.Body.String())
	}
}

// Stoppen ist Stufe 2: Ohne Bestätigung passiert NICHTS. Geprüft wird die
// Wirkung und nicht der Statuscode — ein 409, nach dem der Container trotzdem
// steht, wäre die gefährlichste Art, diesen Test zu bestehen.
func TestAPIDockerContainerStopFragtUndTutNichts(t *testing.T) {
	s, ops, cookie, csrf := containerServer(t, store.RoleOwner)

	rec := postJSON(t, s, "/api/v1/docker/containers/aaaa11112222",
		`{"aktion":"stop","bestaetigt":false,"getippt":""}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	frage := rueckfrageAus(t, rec)
	if frage.Bestaetigung.Frage == "" || frage.Bestaetigung.Tippen != "" {
		t.Errorf("erwartet Stufe 2 mit Frage und ohne Tippfeld: %+v", frage.Bestaetigung)
	}
	for _, a := range ops.recorded() {
		if strings.HasPrefix(a, "docker:stop") {
			t.Error("ohne Bestätigung darf nichts gelaufen sein")
		}
	}

	rec = postJSON(t, s, "/api/v1/docker/containers/aaaa11112222",
		`{"aktion":"stop","bestaetigt":true,"getippt":""}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	// Die Antwort trägt den NEU gelesenen Zustand. Ohne das zeigte die
	// Oberfläche in der Lücke den alten — was nach einem Stopp genauso aussieht
	// wie ein Stopp, der nicht geklappt hat.
	var antwort struct {
		Meldung string             `json:"meldung"`
		Detail  apiContainerDetail `json:"detail"`
	}
	mussJSON(t, rec, &antwort)
	if antwort.Detail.Zustand != "exited" {
		t.Errorf("der frische Zustand fehlt in der Antwort: %+v", antwort.Detail)
	}
}

// Starten ist Stufe 1: kein Dialog. Eine Rückfrage vor jedem Handgriff
// entwertet die Rückfragen, auf die es ankommt.
func TestAPIDockerContainerStartOhneRueckfrage(t *testing.T) {
	s, ops, cookie, csrf := containerServer(t, store.RoleOwner)

	rec := postJSON(t, s, "/api/v1/docker/containers/cccc11112222",
		`{"aktion":"start","bestaetigt":false,"getippt":""}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	if !enthaelt(ops.recorded(), "docker:start:cccc11112222") {
		t.Errorf("der Container wurde nicht gestartet: %v", ops.recorded())
	}
}

// Einen LAUFENDEN Container zu entfernen ist Stufe 3 mit dem Namen: Es beendet
// einen Dienst UND löscht ihn in einem Zug.
func TestAPIDockerContainerEntfernenLaufendIstStufeDrei(t *testing.T) {
	s, ops, cookie, csrf := containerServer(t, store.RoleOwner)

	rec := postJSON(t, s, "/api/v1/docker/containers/aaaa11112222",
		`{"aktion":"remove","bestaetigt":false,"getippt":""}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	frage := rueckfrageAus(t, rec)
	if frage.Bestaetigung.Tippen != "web-proxy-1" {
		t.Errorf("Tippen = %q, erwartet den Containernamen", frage.Bestaetigung.Tippen)
	}

	// Ein falsches Wort wirkt nicht.
	rec = postJSON(t, s, "/api/v1/docker/containers/aaaa11112222",
		`{"aktion":"remove","bestaetigt":true,"getippt":"web-proxy"}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Errorf("ein falsches Wort muss die Frage wiederholen, Status = %d", rec.Code)
	}
	if enthaelt(ops.recorded(), "docker:remove:aaaa11112222") {
		t.Fatal("mit falschem Wort darf nichts gelaufen sein")
	}

	rec = postJSON(t, s, "/api/v1/docker/containers/aaaa11112222",
		`{"aktion":"remove","bestaetigt":true,"getippt":"web-proxy-1"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	// Ein laufender Container wird vorher gestoppt — das ist der Unterschied,
	// wegen dem diese Aktion die schärfere Stufe hat.
	if !enthaelt(ops.recorded(), "docker:remove:erzwungen") {
		t.Errorf("ein laufender Container muss mit --force entfernt werden: %v", ops.recorded())
	}
}

// Einen gestoppten Container zu entfernen ist Stufe 2: Es räumt auf.
func TestAPIDockerContainerEntfernenGestopptIstStufeZwei(t *testing.T) {
	s, ops, cookie, csrf := containerServer(t, store.RoleOwner)

	rec := postJSON(t, s, "/api/v1/docker/containers/cccc11112222",
		`{"aktion":"remove","bestaetigt":false,"getippt":""}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	frage := rueckfrageAus(t, rec)
	if frage.Bestaetigung.Tippen != "" {
		t.Errorf("ein gestoppter Container braucht kein getipptes Wort: %+v", frage.Bestaetigung)
	}

	rec = postJSON(t, s, "/api/v1/docker/containers/cccc11112222",
		`{"aktion":"remove","bestaetigt":true,"getippt":""}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	if enthaelt(ops.recorded(), "docker:remove:erzwungen") {
		t.Error("ein gestoppter Container braucht kein --force")
	}
}

func TestAPIDockerContainerAktionLehntUnbekanntesAb(t *testing.T) {
	s, ops, cookie, csrf := containerServer(t, store.RoleOwner)

	rec := postJSON(t, s, "/api/v1/docker/containers/aaaa11112222",
		`{"aktion":"exec","bestaetigt":true,"getippt":""}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
	}
	for _, a := range ops.recorded() {
		if strings.HasPrefix(a, "docker:exec") {
			t.Error("eine unbekannte Aktion darf nichts auslösen")
		}
	}
}

// Ein Admin-Konto darf sehen, aber nicht schalten. Die Schranke steht auf dem
// Server; die Liste sagt es zusätzlich, damit die Oberfläche keine Knöpfe zeigt,
// die zuverlässig 403 ergeben.
func TestAPIDockerContainerAktionVerlangtOwner(t *testing.T) {
	s, ops, cookie, csrf := containerServer(t, store.RoleAdmin)

	if containerListe(t, s, cookie).DarfAendern {
		t.Error("ein Admin-Konto darf Container nicht schalten")
	}
	rec := postJSON(t, s, "/api/v1/docker/containers/aaaa11112222",
		`{"aktion":"stop","bestaetigt":true,"getippt":""}`, cookie, csrf)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("Status = %d, erwartet 403: %s", rec.Code, rec.Body.String())
	}
	if len(ops.recorded()) != 0 {
		t.Errorf("es darf nichts gelaufen sein: %v", ops.recorded())
	}
}

// Lesen darf jede Rolle — wer sehen darf, welche Dienste laufen, darf sehen,
// welche Container laufen.
func TestAPIDockerContainerListeFuerLeserecht(t *testing.T) {
	s, _, cookie, _ := containerServer(t, store.RoleReadOnly)

	liste := containerListe(t, s, cookie)
	if len(liste.Zeilen) == 0 {
		t.Error("ein Konto mit Leserecht muss die Liste sehen")
	}
	if liste.DarfAendern {
		t.Error("ein Konto mit Leserecht darf nicht schalten")
	}
}

// Ein Fehler der Systemgrenze leert die Seite nicht.
func TestAPIDockerContainerReichtFehlerAlsFeldDurch(t *testing.T) {
	s, ops, cookie, _ := containerServer(t, store.RoleOwner)
	ops.containerErr = errDockerAttrappe

	liste := containerListe(t, s, cookie)
	if liste.Fehler == "" {
		t.Error("der Fehler sollte in der Antwort stehen")
	}
	if liste.Zeilen == nil {
		t.Error("leeres Feld statt null — sonst muss die Oberfläche zwei Fälle unterscheiden")
	}
}
