package httpd

import (
	"encoding/json"
	"net/http"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// Compose-Stacks, lesend — Schritt 4 aus docs/17-docker.md.
//
// Geprüft wird, was der Server ENTSCHEIDET und was die Oberfläche daraus nicht
// selbst rechnen soll: die Stufe (halb oben ist der auffällige Fall), die
// Zähler, die Reihenfolge, die Aufteilung verwaltet/fremd — und die Zusage, dass
// ein Name aus der Anfrage nie zu einem Pfad wird.

func beispielStacks() []privops.Stack {
	return []privops.Stack{
		{
			Name: "web", Verwaltet: true, Datei: "/opt/asylum/stacks/web/compose.yaml",
			Status: "running(2)", Laufend: 2, Gesamt: 2, Gestartet: true,
		},
		{
			Name: "halb", Verwaltet: true, Datei: "/opt/asylum/stacks/halb/compose.yaml",
			Status: "running(1), exited(2)", Laufend: 1, Gesamt: 3, Gestartet: true,
		},
		{
			Name: "neu", Verwaltet: true, Datei: "/opt/asylum/stacks/neu/compose.yaml",
			Status: "nicht gestartet",
		},
		{
			Name: "fremd", Datei: "/srv/fremd/docker-compose.yml",
			Status: "running(1)", Laufend: 1, Gesamt: 1, Gestartet: true,
		},
	}
}

func stackServer(t *testing.T, rolle string) (*Server, *fakeOps, *http.Cookie, string) {
	t.Helper()
	s, ops, cookie, csrf := dockerServer(t, rolle, privops.DockerState{
		Installiert: true, DaemonLaeuft: true, ComposeVerfuegbar: true,
	})
	ops.stacks = beispielStacks()
	ops.container = beispielContainer()
	ops.stackText = map[string]string{
		"web":  "services:\n  proxy:\n    image: nginx:alpine\n",
		"halb": "services:\n  a: {}\n",
		"neu":  "services: {}\n",
	}
	return s, ops, cookie, csrf
}

func stackListe(t *testing.T, s *Server, cookie *http.Cookie) apiStackListe {
	t.Helper()
	rec := get(t, s, "/api/v1/docker/stacks", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	var antwort apiStackListe
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	return antwort
}

func stackDetail(t *testing.T, s *Server, cookie *http.Cookie, name string) apiStackDetail {
	t.Helper()
	rec := get(t, s, "/api/v1/docker/stacks/"+name, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	var antwort apiStackDetail
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	return antwort
}

// Die Zähler und die Aufteilung. Verwaltet und fremd stehen getrennt, weil der
// Unterschied das ganze Modul prägt.
func TestAPIStacksZaehltUndTeiltAuf(t *testing.T) {
	s, _, cookie, _ := stackServer(t, store.RoleOwner)

	antwort := stackListe(t, s, cookie)
	if len(antwort.Zeilen) != 4 {
		t.Fatalf("erwartet 4 Stacks, gelesen %d", len(antwort.Zeilen))
	}
	if antwort.Zaehler.Alle != 4 || antwort.Zaehler.Verwaltet != 3 || antwort.Zaehler.Fremd != 1 {
		t.Errorf("Zähler falsch: %+v", antwort.Zaehler)
	}
	// Genau einer ist halb oben, und genau der ist auffällig.
	if antwort.Zaehler.Auffaellig != 1 {
		t.Errorf("erwartet 1 auffälliger Stack, gezählt %d", antwort.Zaehler.Auffaellig)
	}
	if antwort.Zeilen[0].Name != "halb" {
		t.Errorf("das Auffällige gehört nach oben, oben steht %q", antwort.Zeilen[0].Name)
	}
}

// Die Stufe ist die Auskunft, um die es auf dieser Seite geht: Ein Stack, von
// dem zwei von drei Diensten laufen, ist kaputt und sieht aus wie „läuft". Ein
// ganz gestoppter dagegen ist meistens Absicht.
func TestAPIStacksStufeUnterscheidetHalbVonGanz(t *testing.T) {
	faelle := []struct {
		name       string
		stack      privops.Stack
		stufe      string
		auffaellig bool
	}{
		{"ganz oben", privops.Stack{Laufend: 2, Gesamt: 2, Gestartet: true}, "gut", false},
		{"halb oben", privops.Stack{Laufend: 1, Gesamt: 3, Gestartet: true}, "warn", true},
		{"ganz unten", privops.Stack{Laufend: 0, Gesamt: 3, Gestartet: true}, "info", false},
		{"nie gestartet", privops.Stack{}, "info", false},
	}
	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			stufe, auffaellig := stackStufe(f.stack)
			if stufe != f.stufe || auffaellig != f.auffaellig {
				t.Errorf("Stufe = %q/%v, erwartet %q/%v", stufe, auffaellig, f.stufe, f.auffaellig)
			}
		})
	}
}

// Die Dienstnamen kommen aus den Compose-Labels der Container und nicht aus der
// Datei. Ein Stack, den Docker nicht kennt, hat deshalb keine — und das ist eine
// leere Liste und kein fehlendes Feld.
func TestAPIStacksNimmtDiensteAusDenContainern(t *testing.T) {
	s, _, cookie, _ := stackServer(t, store.RoleOwner)

	nach := map[string]apiStack{}
	for _, z := range stackListe(t, s, cookie).Zeilen {
		nach[z.Name] = z
	}
	if got := strings.Join(nach["web"].Dienste, ","); got != "api,db,proxy" {
		t.Errorf("Dienste von web = %q, erwartet alphabetisch api,db,proxy", got)
	}
	if nach["neu"].Dienste == nil {
		t.Error("ein nie gestarteter Stack braucht eine leere Liste, kein null")
	}
	if len(nach["neu"].Dienste) != 0 {
		t.Errorf("ein nie gestarteter Stack hat keine Dienste: %+v", nach["neu"].Dienste)
	}
}

// Fällt „docker ps" aus, fehlen die Dienstnamen — die Stacks stehen trotzdem da.
// Eine Teilauskunft ist immer noch die Auskunft, die jemand sucht.
func TestAPIStacksUeberlebtFehlendeContainer(t *testing.T) {
	s, ops, cookie, _ := stackServer(t, store.RoleOwner)
	ops.containerErr = errDockerAttrappe

	antwort := stackListe(t, s, cookie)
	if len(antwort.Zeilen) != 4 {
		t.Fatalf("erwartet 4 Stacks trotz fehlender Container, gelesen %d", len(antwort.Zeilen))
	}
	if antwort.Fehler != "" {
		t.Errorf("die Container sind die zweite Quelle; ihr Ausfall ist kein Listenfehler: %q", antwort.Fehler)
	}
}

// Scheitert die Liste selbst, steht der Fehler als FELD und nicht als
// Statuscode: Die Seite zeigt ihn an, statt leer zu bleiben.
func TestAPIStacksMeldetFehlerAlsFeld(t *testing.T) {
	s, ops, cookie, _ := stackServer(t, store.RoleOwner)
	ops.stackErr = errDockerAttrappe

	antwort := stackListe(t, s, cookie)
	if antwort.Fehler == "" {
		t.Error("der Fehler fehlt in der Antwort")
	}
	if antwort.Zeilen == nil {
		t.Error("leeres Feld statt null, auch im Fehlerfall")
	}
}

// Der Inspektor: Datei, Container des Stacks, und dieselben Angaben wie in der
// Liste.
func TestAPIStackDetailZeigtDateiUndContainer(t *testing.T) {
	s, _, cookie, _ := stackServer(t, store.RoleOwner)

	detail := stackDetail(t, s, cookie, "web")
	if !strings.Contains(detail.Text, "image: nginx:alpine") {
		t.Errorf("die Compose-Datei fehlt: %q", detail.Text)
	}
	if !detail.Verwaltet || detail.ZustandStufe != "gut" {
		t.Errorf("die Listenangaben fehlen im Inspektor: %+v", detail.apiStack)
	}
	if len(detail.Container) != 3 {
		t.Fatalf("erwartet 3 Container von web, gelesen %d", len(detail.Container))
	}
	// Im Inspektor nach Dienstnamen, nicht nach Auffälligkeit: Hier zeigt die
	// Seite EIN Projekt, und dort ist die verständliche Reihenfolge die nach
	// Dienst.
	if detail.Container[0].Dienst != "api" || detail.Container[2].Dienst != "proxy" {
		t.Errorf("die Container stehen nicht nach Dienstnamen: %+v", detail.Container)
	}
	// Die Stufe je Container kommt aus derselben Funktion wie in der Liste.
	if detail.Container[0].ZustandStufe != "schlecht" {
		t.Errorf("der ungesunde Container ist auch hier auffällig: %+v", detail.Container[0])
	}
}

// Ein fremdes Projekt ohne lesbare Datei ist der Normalfall und keine
// gescheiterte Auskunft: Der Stack steht mit seinen Containern da, der Fehler
// steht daneben.
func TestAPIStackDetailUeberlebtUnlesbareDatei(t *testing.T) {
	s, _, cookie, _ := stackServer(t, store.RoleOwner)

	detail := stackDetail(t, s, cookie, "fremd")
	if detail.Name != "fremd" || detail.Verwaltet {
		t.Errorf("ein fremdes Projekt darf nicht als verwaltet gelten: %+v", detail.apiStack)
	}
	if detail.Fehler == "" {
		t.Error("die unlesbare Datei gehört gesagt")
	}
	if detail.Container == nil {
		t.Error("leeres Feld statt null")
	}
}

// Der Kern der Sicherheitsentscheidung, auf Ebene der Route: Der Name aus dem
// Pfad wird gegen die Liste gehalten. Was sie nicht kennt, ist 404 — und ein
// Name, der wie ein Pfad aussieht, kommt gar nicht erst bis zu einer Datei.
func TestAPIStackDetailNimmtNurNamenAusDerListe(t *testing.T) {
	s, ops, cookie, _ := stackServer(t, store.RoleOwner)

	rec := get(t, s, "/api/v1/docker/stacks/gibtsnicht", cookie)
	if rec.Code != http.StatusNotFound {
		t.Errorf("Status = %d, erwartet 404: %s", rec.Code, rec.Body.String())
	}
	// Ein Pfad im Namen: Der Router zerlegt ihn gar nicht erst zu einem
	// {name}-Wert, und selbst wenn — die Liste kennt ihn nicht.
	for _, boese := range []string{"..%2f..%2fetc%2fshadow", "web%2f..%2f..", "%2Fetc%2Fshadow"} {
		rec := get(t, s, "/api/v1/docker/stacks/"+boese, cookie)
		if rec.Code == http.StatusOK {
			t.Errorf("%q darf keine Auskunft ergeben: %s", boese, rec.Body.String())
		}
	}
	// Und keiner dieser Versuche hat ein Kommando ausgelöst, das etwas ändert.
	for _, ruf := range ops.recorded() {
		if strings.Contains(ruf, "rm") || strings.Contains(ruf, "prune") {
			t.Errorf("eine Leseanfrage hat %q ausgelöst", ruf)
		}
	}
}

// Lesen darf jede Rolle — wer sehen darf, welche Dienste laufen, darf sehen,
// welche Stacks laufen. DarfAendern sagt trotzdem die Wahrheit über die Rolle.
func TestAPIStacksLesenFuerAlleRollen(t *testing.T) {
	s, _, cookie, _ := stackServer(t, store.RoleReadOnly)

	antwort := stackListe(t, s, cookie)
	if len(antwort.Zeilen) != 4 {
		t.Fatalf("auch ein Lesekonto sieht die Liste, gelesen %d", len(antwort.Zeilen))
	}
	if antwort.DarfAendern {
		t.Error("ein Lesekonto darf nicht ändern")
	}
}
