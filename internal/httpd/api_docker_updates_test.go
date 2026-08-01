package httpd

import (
	"encoding/json"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/metrics"
	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// Die Update-Prüfung — Schritt 7 aus docs/17-docker.md.
//
// Geprüft wird nicht der Digest-Vergleich (der hat seine Tests in privops),
// sondern das Verhalten drumherum: die Ratengrenze, der Zwischenspeicher, und
// die Zusage, dass eine Leseanfrage NIE eine Registry berührt.

func imageServer(t *testing.T, rolle string) (*Server, *fakeOps, *http.Cookie, string) {
	t.Helper()
	s, ops, cookie, csrf := dockerServer(t, rolle, privops.DockerState{
		Installiert: true, DaemonLaeuft: true, ComposeVerfuegbar: true,
	})
	ops.container = []privops.Container{
		{ID: "a", Name: "web-proxy-1", Image: "nginx:alpine", Zustand: "running", Stack: "web", Dienst: "proxy"},
		{ID: "b", Name: "web-api-1", Image: "api:1.4", Zustand: "running", Stack: "web", Dienst: "api"},
		// Zweiter Container mit DEMSELBEN Image: eine Abfrage, nicht zwei.
		{ID: "c", Name: "web-proxy-2", Image: "nginx:alpine", Zustand: "running", Stack: "web", Dienst: "proxy"},
		// Gestoppt: kein Grund, dafür die Ratengrenze zu verbrauchen.
		{ID: "d", Name: "alt", Image: "alpine:3.19", Zustand: "exited"},
		// Über die Kennung angezogen: kann sich nicht ändern.
		{ID: "e", Name: "fest", Image: "redis@sha256:abc", Zustand: "running"},
	}
	ops.updateDone = make(chan struct{})
	return s, ops, cookie, csrf
}

func updateliste(t *testing.T, s *Server, cookie *http.Cookie) apiUpdates {
	t.Helper()
	rec := get(t, s, "/api/v1/docker/updates", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	var antwort apiUpdates
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	return antwort
}

// Die wichtigste Zusage der Leseroute: Sie fragt keine Registry. Wäre es
// anders, verbrauchte ein offener Tab die Ratengrenze im Hintergrund.
func TestAPIUpdatesLesenFragtKeineRegistry(t *testing.T) {
	s, ops, cookie, _ := imageServer(t, store.RoleOwner)

	antwort := updateliste(t, s, cookie)
	if len(antwort.Zeilen) != 0 {
		t.Errorf("ohne Lauf gibt es nichts zu zeigen: %+v", antwort.Zeilen)
	}
	if antwort.Geprueft != "" {
		t.Errorf("ohne Lauf gibt es keinen Zeitpunkt: %q", antwort.Geprueft)
	}
	if !antwort.DarfPruefen {
		t.Error("ohne vorherigen Lauf darf geprüft werden")
	}
	for _, ruf := range ops.recorded() {
		if strings.Contains(ruf, "update-pruefen") {
			t.Errorf("die Leseroute hat eine Registry gefragt: %v", ops.recorded())
		}
	}
}

// Der Prüflauf fragt jedes Image EINMAL — und nur die, bei denen es etwas zu
// fragen gibt. Jede Abfrage kostet an der Ratengrenze.
func TestAPIUpdatePruefungFragtJedesImageEinmal(t *testing.T) {
	s, ops, cookie, csrf := imageServer(t, store.RoleOwner)
	ops.staende = map[string]privops.Updatestand{
		"nginx:alpine": {Ref: "nginx:alpine", Geprueft: true, Neu: true,
			LokalDigest: "sha256:aaaa11112222", FernDigest: "sha256:bbbb11112222", Weg: "buildx"},
		"api:1.4": {Ref: "api:1.4", Geprueft: true, Neu: false, Weg: "buildx"},
	}

	rec := postJSON(t, s, "/api/v1/docker/updates/check", "{}", cookie, csrf)
	if rec.Code != http.StatusAccepted {
		t.Fatalf("Status = %d, erwartet 202: %s", rec.Code, rec.Body.String())
	}
	<-ops.updateDone
	warteBis(t, func() bool {
		j := s.jobAus(jobDockerUpdatePruefung)
		return j != nil && !j.Laeuft
	})

	var gefragt []string
	for _, ruf := range ops.recorded() {
		if ref, ok := strings.CutPrefix(ruf, "docker:update-pruefen:"); ok {
			gefragt = append(gefragt, ref)
		}
	}
	if len(gefragt) != 2 {
		t.Fatalf("erwartet 2 Abfragen, gestellt %d: %v", len(gefragt), gefragt)
	}
	// Zwei Container mit demselben Image sind EINE Abfrage.
	if gefragt[0] != "api:1.4" || gefragt[1] != "nginx:alpine" {
		t.Errorf("falsche Images gefragt: %v", gefragt)
	}

	antwort := updateliste(t, s, cookie)
	if antwort.Neu != 1 || antwort.Aktuell != 1 {
		t.Errorf("Zähler falsch: neu=%d aktuell=%d ungeprüft=%d",
			antwort.Neu, antwort.Aktuell, antwort.Ungeprueft)
	}
	// Das Neue steht oben — es ist das, was es zu tun gibt.
	if antwort.Zeilen[0].Ref != "nginx:alpine" || !antwort.Zeilen[0].Neu {
		t.Errorf("das neue Image steht nicht oben: %+v", antwort.Zeilen[0])
	}
	// Der Griff ist der Stack: Aktualisiert wird ein Projekt, kein Image.
	if len(antwort.Zeilen[0].Stacks) != 1 || antwort.Zeilen[0].Stacks[0] != "web" {
		t.Errorf("der Stack fehlt an der Zeile: %+v", antwort.Zeilen[0])
	}
}

// Die Ratengrenze: höchstens ein Lauf je Tag, und sie überlebt einen Neustart,
// weil sie im Store liegt.
func TestAPIUpdatePruefungHaeltDieRatengrenze(t *testing.T) {
	s, ops, cookie, csrf := imageServer(t, store.RoleOwner)

	if rec := postJSON(t, s, "/api/v1/docker/updates/check", "{}", cookie, csrf); rec.Code != http.StatusAccepted {
		t.Fatalf("erster Lauf: Status = %d", rec.Code)
	}
	<-ops.updateDone
	warteBis(t, func() bool {
		j := s.jobAus(jobDockerUpdatePruefung)
		return j != nil && !j.Laeuft
	})

	rec := postJSON(t, s, "/api/v1/docker/updates/check", "{}", cookie, csrf)
	if rec.Code != http.StatusTooManyRequests {
		t.Errorf("Status = %d, erwartet 429: %s", rec.Code, rec.Body.String())
	}
	// Die Meldung nennt den Grund UND den Zeitpunkt. „Zu früh" allein befähigt
	// zu keiner Entscheidung.
	if !strings.Contains(rec.Body.String(), "einmal am Tag") {
		t.Errorf("die Meldung erklärt die Grenze nicht: %s", rec.Body.String())
	}

	antwort := updateliste(t, s, cookie)
	if antwort.DarfPruefen {
		t.Error("nach einem Lauf darf nicht gleich wieder geprüft werden")
	}
	if antwort.NaechsteFruehestens == "" {
		t.Error("wann wieder geprüft werden darf, gehört in die Antwort")
	}
}

// Der Zeitpunkt wird auch dann gespeichert, wenn die Registry abgewiesen hat.
// Sonst dürfte gleich wieder gefragt werden — und die Grenze wäre wirkungslos,
// gerade wenn sie zugeschlagen hat.
func TestAPIUpdatePruefungBrichtBeiRatengrenzeAb(t *testing.T) {
	s, ops, cookie, csrf := imageServer(t, store.RoleOwner)
	ops.staende = map[string]privops.Updatestand{
		"api:1.4":      {Ref: "api:1.4", Grund: "Die Registry hat die Abfrage wegen ihrer Ratengrenze abgewiesen."},
		"nginx:alpine": {Ref: "nginx:alpine", Geprueft: true, Neu: true},
	}

	if rec := postJSON(t, s, "/api/v1/docker/updates/check", "{}", cookie, csrf); rec.Code != http.StatusAccepted {
		t.Fatalf("Status = %d", rec.Code)
	}
	<-ops.updateDone
	warteBis(t, func() bool {
		j := s.jobAus(jobDockerUpdatePruefung)
		return j != nil && !j.Laeuft
	})

	// „api:1.4" kommt alphabetisch zuerst und wird abgewiesen — danach wird
	// nicht weitergefragt.
	var gefragt int
	for _, ruf := range ops.recorded() {
		if strings.HasPrefix(ruf, "docker:update-pruefen:") {
			gefragt++
		}
	}
	if gefragt != 1 {
		t.Errorf("nach der Ratengrenze wurde weitergefragt: %d Abfragen", gefragt)
	}

	antwort := updateliste(t, s, cookie)
	if antwort.Fehler == "" {
		t.Error("die Ratengrenze gehört in die Antwort")
	}
	if antwort.DarfPruefen {
		t.Error("nach einem Abbruch an der Ratengrenze darf nicht gleich wieder geprüft werden")
	}
}

// „Nicht geprüft" ist weder „aktuell" noch „veraltet" — und die Zahl dafür
// steht eigens da. Sie ist die ehrlichste der drei.
func TestAPIUpdatesTrenntUngeprueftVonAktuell(t *testing.T) {
	s, ops, cookie, csrf := imageServer(t, store.RoleOwner)
	ops.staende = map[string]privops.Updatestand{
		"nginx:alpine": {Ref: "nginx:alpine", Grund: "Mehrarchitektur-Image: …"},
		"api:1.4":      {Ref: "api:1.4", Geprueft: true, Neu: false},
	}

	if rec := postJSON(t, s, "/api/v1/docker/updates/check", "{}", cookie, csrf); rec.Code != http.StatusAccepted {
		t.Fatalf("Status = %d", rec.Code)
	}
	<-ops.updateDone
	warteBis(t, func() bool {
		j := s.jobAus(jobDockerUpdatePruefung)
		return j != nil && !j.Laeuft
	})

	antwort := updateliste(t, s, cookie)
	if antwort.Ungeprueft != 1 || antwort.Aktuell != 1 || antwort.Neu != 0 {
		t.Errorf("Zähler falsch: neu=%d aktuell=%d ungeprüft=%d",
			antwort.Neu, antwort.Aktuell, antwort.Ungeprueft)
	}
	for _, z := range antwort.Zeilen {
		if z.Ref == "nginx:alpine" {
			if z.Neu {
				t.Error("ein ungeprüftes Image darf nicht als neu gelten")
			}
			if z.Grund == "" {
				t.Error("der Grund fehlt an der Zeile")
			}
		}
	}
}

// Das Signal im Handlungsbedarf kommt aus dem Zwischenspeicher und NIE aus
// einer Registry-Abfrage: In der Drei-Sekunden-Frist von dashboardSignals hat
// eine Registry nichts verloren.
func TestUpdateSignalKommtAusDemZwischenspeicher(t *testing.T) {
	s, ops, cookie, csrf := imageServer(t, store.RoleOwner)
	ops.staende = map[string]privops.Updatestand{
		"nginx:alpine": {Ref: "nginx:alpine", Geprueft: true, Neu: true},
		"api:1.4":      {Ref: "api:1.4", Geprueft: true, Neu: false},
	}

	// Vor dem Lauf: kein Signal.
	for _, sig := range s.dashboardSignals(t.Context(), metrics.Snapshot{}) {
		if strings.Contains(sig.Title, "neuere Fassung") {
			t.Errorf("ohne Lauf gibt es kein Update-Signal: %+v", sig)
		}
	}

	if rec := postJSON(t, s, "/api/v1/docker/updates/check", "{}", cookie, csrf); rec.Code != http.StatusAccepted {
		t.Fatalf("Status = %d", rec.Code)
	}
	<-ops.updateDone
	warteBis(t, func() bool {
		j := s.jobAus(jobDockerUpdatePruefung)
		return j != nil && !j.Laeuft
	})

	vorher := len(ops.recorded())
	signale := s.dashboardSignals(t.Context(), metrics.Snapshot{})
	gefunden := false
	for _, sig := range signale {
		if strings.Contains(sig.Title, "neuere Fassung") {
			gefunden = true
			// Auf die FLÄCHE und nicht auf das Modul: Den Verweis liest seit
			// 0.5.1 auch der Warnpunkt in der Seitenleiste, und ein Punkt an
			// „Docker" meinte fünf Flächen.
			if sig.ActionHref != "/docker/updates" {
				t.Errorf("der Verweis führt nach %q", sig.ActionHref)
			}
		}
	}
	if !gefunden {
		t.Errorf("das Update-Signal fehlt: %+v", signale)
	}
	// Und dabei wurde keine Registry gefragt.
	for _, ruf := range ops.recorded()[vorher:] {
		if strings.Contains(ruf, "update-pruefen") {
			t.Error("dashboardSignals hat eine Registry gefragt — in der " +
				"Drei-Sekunden-Frist hat das nichts verloren")
		}
	}
	_ = cookie
}

// Prüfen verlangt die Owner-Rolle: Der Lauf verbraucht eine Ratengrenze für den
// ganzen Server.
func TestAPIUpdatePruefungVerlangtOwner(t *testing.T) {
	s, ops, cookie, csrf := imageServer(t, store.RoleAdmin)

	rec := postJSON(t, s, "/api/v1/docker/updates/check", "{}", cookie, csrf)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Status = %d, erwartet 403", rec.Code)
	}
	for _, ruf := range ops.recorded() {
		if strings.Contains(ruf, "update-pruefen") {
			t.Error("ein Admin-Konto hat trotzdem geprüft")
		}
	}
	// Lesen darf es.
	if rec := get(t, s, "/api/v1/docker/updates", cookie); rec.Code != http.StatusOK {
		t.Errorf("Lesen muss offenstehen, Status = %d", rec.Code)
	}
}

// Ohne laufenden Container gibt es nichts zu prüfen — und dann wird kein
// Vorgang gestartet, der sofort mit „nichts gefunden" endet.
func TestAPIUpdatePruefungOhneImages(t *testing.T) {
	s, ops, cookie, csrf := imageServer(t, store.RoleOwner)
	ops.container = nil

	rec := postJSON(t, s, "/api/v1/docker/updates/check", "{}", cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
	}
	if s.jobAus(jobDockerUpdatePruefung) != nil {
		t.Error("es wurde ein Vorgang gestartet, der nichts zu tun hatte")
	}
}

// Der Zwischenspeicher liegt im Store und überlebt damit einen Neustart des
// Panels. Läge er im Speicher, setzte jeder Neustart die Ratengrenze zurück.
func TestUpdatestandUeberlebtImStore(t *testing.T) {
	s, _, _, _ := imageServer(t, store.RoleOwner)
	ctx := t.Context()

	stand := gespeicherterUpdatestand{
		Geprueft: time.Now().UTC(),
		Staende:  []privops.Updatestand{{Ref: "nginx:alpine", Geprueft: true, Neu: true}},
	}
	if err := s.updatestandSchreiben(ctx, stand); err != nil {
		t.Fatalf("updatestandSchreiben: %v", err)
	}
	gelesen := s.updatestandLesen(ctx)
	if len(gelesen.Staende) != 1 || !gelesen.Staende[0].Neu {
		t.Errorf("der Stand kam nicht zurück: %+v", gelesen)
	}
	if gelesen.Geprueft.IsZero() {
		t.Error("der Zeitpunkt fehlt — ohne ihn greift die Ratengrenze nicht")
	}
}
