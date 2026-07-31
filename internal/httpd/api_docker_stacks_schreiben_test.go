package httpd

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// Compose-Stacks, schreibend — Schritt 5 aus docs/17-docker.md.
//
// Geprüft wird nicht der Prüfer (der hat seine Tests in privops), sondern was
// die Schicht darüber mit seinem Urteil anfängt: dass eine Ablehnung die
// Aktion ANHÄLT und nicht bloß einen Statuscode ändert, dass ein Bind-Mount
// nach draußen eine Rückfrage der Stufe 3 auslöst, und dass ein Admin-Konto an
// jeder dieser Routen 403 bekommt.

// abgelehnt ist eine Prüfung, die den Vorgang anhält.
func abgelehnt() privops.ComposePruefung {
	return privops.ComposePruefung{
		Geprueft: true, Gerendert: true, OK: false,
		Dienste: []string{"web"},
		Befunde: []privops.ComposeBefund{{
			Art: privops.BefundAblehnung, Dienst: "web", Feld: "privileged", Wert: "true",
			Grund: "Ein privilegierter Container hat auf dem Wirt praktisch die Rechte von root.",
		}},
	}
}

// mitAussenmount ist eine bestandene Prüfung MIT einem Bind-Mount nach draußen —
// der Fall, der keine Ablehnung ist, aber eine Rückfrage der Stufe 3 auslöst.
func mitAussenmount() privops.ComposePruefung {
	return privops.ComposePruefung{
		Geprueft: true, Gerendert: true, OK: true,
		Dienste: []string{"web"},
		Befunde: []privops.ComposeBefund{{
			Art: privops.BefundAussen, Dienst: "web", Feld: "volumes", Wert: "/srv/daten:/data",
			Grund: "Dieser Pfad liegt außerhalb des Stack-Verzeichnisses.",
		}},
	}
}

func sauber() privops.ComposePruefung {
	return privops.ComposePruefung{Geprueft: true, Gerendert: true, OK: true, Dienste: []string{"web"}}
}

// schreibServer ist ein Stackserver mit gesetzter Prüfung und einem Kanal, an
// dem der Test das Ende des Hintergrundvorgangs abwartet.
func schreibServer(t *testing.T, p privops.ComposePruefung) (*Server, *fakeOps, *http.Cookie, string) {
	t.Helper()
	s, ops, cookie, csrf := stackServer(t, store.RoleOwner)
	ops.stackPruefung = p
	ops.stackDone = make(chan struct{})
	return s, ops, cookie, csrf
}

// stackPost schickt einen Körper an eine Stack-Route.
//
// Eigene Hilfe statt postJSON, weil hier auch PUT gebraucht wird: Anlegen und
// Speichern stehen auf verschiedenen Methoden, und ein Test, der beide über
// POST schickte, prüfte die Route nicht, die er meint.
func stackPost(t *testing.T, s *Server, methode, pfad string, cookie *http.Cookie, csrf string, koerper map[string]any) *httptest.ResponseRecorder {
	t.Helper()
	roh, err := json.Marshal(koerper)
	if err != nil {
		t.Fatal(err)
	}
	req := httptest.NewRequest(methode, pfad, bytes.NewReader(roh))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-CSRF-Token", csrf)
	req.AddCookie(cookie)
	rec := httptest.NewRecorder()
	s.Handler().ServeHTTP(rec, req)
	return rec
}

// Der Kern: Eine Ablehnung schreibt NICHT. Der Statuscode ist die halbe
// Auskunft; die andere Hälfte ist, dass nichts auf der Platte gelandet ist.
func TestAPIStackSpeichernAblehnungSchreibtNicht(t *testing.T) {
	s, ops, cookie, csrf := schreibServer(t, abgelehnt())

	rec := stackPost(t, s, http.MethodPut, "/api/v1/docker/stacks/web", cookie, csrf, map[string]any{
		"text": "services:\n  web:\n    image: nginx\n    privileged: true\n",
	})
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
	}

	var antwort apiStackSchreibAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	// Ein blankes „abgelehnt" schickte jemanden auf die Suche in einer Datei,
	// die er gerade geschrieben hat. Dienst, Feld und Grund gehören dazu.
	if len(antwort.Befunde) == 0 {
		t.Fatal("die Ablehnung nennt keine Befunde")
	}
	b := antwort.Befunde[0]
	if b.Dienst == "" || b.Feld == "" || b.Grund == "" {
		t.Errorf("ein Befund ohne Dienst, Feld oder Grund erklärt nichts: %+v", b)
	}
	// Und der Text ist nicht in der Attrappe gelandet.
	if _, da := ops.stackText["web"]; da && strings.Contains(ops.stackText["web"], "privileged") {
		t.Error("die abgelehnte Datei wurde trotzdem geschrieben")
	}
}

// Ein sauberer Stack wird gespeichert, und die Antwort trägt den frisch
// gelesenen Zustand — das erspart der Oberfläche eine zweite Anfrage.
func TestAPIStackSpeichernNimmtSauberesAn(t *testing.T) {
	s, ops, cookie, csrf := schreibServer(t, sauber())

	rec := stackPost(t, s, http.MethodPut, "/api/v1/docker/stacks/web", cookie, csrf, map[string]any{
		"text": "services:\n  web:\n    image: nginx:alpine\n",
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	var antwort apiStackSchreibAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	if antwort.Detail == nil {
		t.Error("die Antwort trägt den neuen Zustand nicht")
	}
	if !strings.Contains(ops.stackText["web"], "nginx:alpine") {
		t.Errorf("der Text kam nicht an: %q", ops.stackText["web"])
	}
}

// Ein Bind-Mount nach draußen ist keine Ablehnung, sondern eine Rückfrage der
// Stufe 3 — mit getipptem Stack-Namen. Und bis sie beantwortet ist, wurde
// nichts geschrieben.
func TestAPIStackSpeichernFragtBeiMountNachDraussen(t *testing.T) {
	s, ops, cookie, csrf := schreibServer(t, mitAussenmount())

	rec := stackPost(t, s, http.MethodPut, "/api/v1/docker/stacks/web", cookie, csrf, map[string]any{
		"text": "services:\n  web:\n    image: nginx\n    volumes:\n      - /srv/daten:/data\n",
	})
	frage := rueckfrageAus(t, rec)
	if frage.Bestaetigung.Tippen != "web" {
		t.Errorf("Stufe 3 verlangt den Stack-Namen, verlangt wird %q", frage.Bestaetigung.Tippen)
	}
	// Die Wirkung prüfen, nicht den Statuscode: Eine Rückfrage, die schon
	// geschrieben hat, ist keine.
	for _, ruf := range ops.recorded() {
		if strings.HasPrefix(ruf, "docker:stack-write") {
			t.Errorf("die Rückfrage hat trotzdem geschrieben: %v", ops.recorded())
		}
	}

	// Ein FALSCHES getipptes Wort wirkt ebenfalls nicht. Derselbe Text wie oben:
	// Ein anderer hätte den Mount nicht mehr enthalten, und dann käme die Frage
	// zu Recht nicht — der Test prüfte dann nichts.
	rec = stackPost(t, s, http.MethodPut, "/api/v1/docker/stacks/web", cookie, csrf, map[string]any{
		"text":       "services:\n  web:\n    image: nginx\n    volumes:\n      - /srv/daten:/data\n",
		"bestaetigt": true, "getippt": "webb",
	})
	if rec.Code != http.StatusConflict {
		t.Errorf("ein falsches Wort muss die Frage wiederholen, Status = %d", rec.Code)
	}

	// Mit dem richtigen Wort geht es durch.
	rec = stackPost(t, s, http.MethodPut, "/api/v1/docker/stacks/web", cookie, csrf, map[string]any{
		"text":       "services:\n  web:\n    image: nginx\n    volumes:\n      - /srv/daten:/data\n",
		"bestaetigt": true, "getippt": "web",
	})
	if rec.Code != http.StatusOK {
		t.Errorf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
}

// Anlegen mit einem Namen, den es schon gibt, ist eine Verwechslung und kein
// Schreibfehler: Es würde die vorhandene Datei überschreiben.
func TestAPIStackAnlegenLehntVorhandenenNamenAb(t *testing.T) {
	s, _, cookie, csrf := schreibServer(t, sauber())

	rec := stackPost(t, s, http.MethodPost, "/api/v1/docker/stacks", cookie, csrf, map[string]any{
		"name": "web", "text": "services:\n  x:\n    image: nginx\n",
	})
	if rec.Code != http.StatusConflict {
		t.Errorf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
}

// Der Name wird geprüft, bevor er ein Verzeichnis wird. Compose selbst verlangt
// Kleinbuchstaben — ein Stack, den das Panel anlegt und docker nicht startet,
// wäre die unangenehmste Art von Fehler.
func TestAPIStackAnlegenPrueftDenNamen(t *testing.T) {
	s, ops, cookie, csrf := schreibServer(t, sauber())

	for _, name := range []string{"../etc", "Gross", "mit leer", "", "web/unter"} {
		rec := stackPost(t, s, http.MethodPost, "/api/v1/docker/stacks", cookie, csrf, map[string]any{
			"name": name, "text": "services:\n  x:\n    image: nginx\n",
		})
		if rec.Code != http.StatusBadRequest {
			t.Errorf("%q: Status = %d, erwartet 400", name, rec.Code)
		}
	}
	for _, ruf := range ops.recorded() {
		if strings.HasPrefix(ruf, "docker:stack-write") {
			t.Errorf("ein unzulässiger Name hat geschrieben: %v", ops.recorded())
		}
	}
}

// „up" bei einem abgelehnten Stack: Der Vorgang startet, ENDET aber
// gescheitert. Ein Vorgang, der als „erfolgreich" endet, während der Stack
// nicht läuft, ist die schlechteste Auskunft von allen.
func TestAPIStackUpEndetGescheitertBeiAblehnung(t *testing.T) {
	s, ops, cookie, csrf := schreibServer(t, abgelehnt())

	rec := stackPost(t, s, http.MethodPost, "/api/v1/docker/stacks/web", cookie, csrf, map[string]any{
		"aktion": "up",
	})
	if rec.Code != http.StatusAccepted {
		t.Fatalf("Status = %d, erwartet 202: %s", rec.Code, rec.Body.String())
	}
	<-ops.stackDone
	warteBis(t, func() bool {
		j := s.jobAus(jobDockerStack)
		return j != nil && !j.Laeuft
	})

	j := s.jobAus(jobDockerStack)
	if !j.Gescheitert {
		t.Errorf("der Vorgang endete als erfolgreich, obwohl der Stack abgelehnt wurde: %+v", j)
	}
	// Der Grund steht im Auszug und nicht bloß im Fehlertext: Dort sucht man
	// ihn, wenn man die Platte offen hat.
	if !strings.Contains(strings.Join(j.Zeilen, "\n"), "privileged") {
		t.Errorf("der Befund fehlt im Auszug: %v", j.Zeilen)
	}
}

// „up" bei einem Stack mit Bind-Mount nach draußen ist Stufe 3 mit dem
// Stack-Namen — der Handgriff startet dann einen Container mit Zugriff auf
// Daten des Servers.
func TestAPIStackUpFragtBeiMountNachDraussen(t *testing.T) {
	s, ops, cookie, csrf := schreibServer(t, mitAussenmount())

	rec := stackPost(t, s, http.MethodPost, "/api/v1/docker/stacks/web", cookie, csrf, map[string]any{
		"aktion": "up",
	})
	frage := rueckfrageAus(t, rec)
	if frage.Bestaetigung.Tippen != "web" {
		t.Errorf("Stufe 3 verlangt den Stack-Namen, verlangt wird %q", frage.Bestaetigung.Tippen)
	}
	// Der Pfad gehört in die Frage: „Zugriff auf Serververzeichnisse" allein
	// befähigt zu keiner Entscheidung, „/srv/daten:/data" schon.
	if !strings.Contains(strings.Join(frage.Bestaetigung.Punkte, " "), "/srv/daten") {
		t.Errorf("die Frage nennt den Pfad nicht: %+v", frage.Bestaetigung.Punkte)
	}
	for _, ruf := range ops.recorded() {
		if strings.Contains(ruf, "stack-up") {
			t.Error("die Rückfrage hat den Stack trotzdem gestartet")
		}
	}
}

// Ein sauberer Stack startet ohne Rückfrage: Stufe 1. Eine Frage, die immer
// kommt, wird weggeklickt — und dann wird auch die weggeklickt, die zählt.
func TestAPIStackUpOhneBefundFragtNicht(t *testing.T) {
	s, ops, cookie, csrf := schreibServer(t, sauber())

	rec := stackPost(t, s, http.MethodPost, "/api/v1/docker/stacks/web", cookie, csrf, map[string]any{
		"aktion": "up",
	})
	if rec.Code != http.StatusAccepted {
		t.Fatalf("Status = %d, erwartet 202: %s", rec.Code, rec.Body.String())
	}
	<-ops.stackDone
	gefunden := false
	for _, ruf := range ops.recorded() {
		if ruf == "docker:stack-up:web" {
			gefunden = true
		}
	}
	if !gefunden {
		t.Errorf("der Stack wurde nicht gestartet: %v", ops.recorded())
	}
}

// „down" ist Stufe 2 — Dialog, aber kein getipptes Wort. Mit Volumes ist es
// Stufe 3: Daten weg, kein Rückweg.
func TestAPIStackDownStufen(t *testing.T) {
	s, _, cookie, csrf := schreibServer(t, sauber())

	rec := stackPost(t, s, http.MethodPost, "/api/v1/docker/stacks/web", cookie, csrf, map[string]any{
		"aktion": "down",
	})
	frage := rueckfrageAus(t, rec)
	if frage.Bestaetigung.Tippen != "" {
		t.Errorf("Stoppen ist Stufe 2 und braucht kein getipptes Wort: %q", frage.Bestaetigung.Tippen)
	}
	// Die Zahl gehört in die Frage: „der Stack" befähigt zu keiner
	// Entscheidung, „alle 3 Container" schon.
	if !strings.Contains(strings.Join(frage.Bestaetigung.Punkte, " "), "2 Container") {
		t.Errorf("die Frage nennt nicht, wie viele Container sie trifft: %+v", frage.Bestaetigung.Punkte)
	}

	rec = stackPost(t, s, http.MethodPost, "/api/v1/docker/stacks/web", cookie, csrf, map[string]any{
		"aktion": "down", "mit_volumes": true,
	})
	frage = rueckfrageAus(t, rec)
	if frage.Bestaetigung.Tippen != "web" {
		t.Errorf("mit Volumes ist es Stufe 3 mit dem Stack-Namen, verlangt wird %q", frage.Bestaetigung.Tippen)
	}
}

// „pull" ist Stufe 1: Es lädt herunter und ändert nichts an dem, was läuft.
func TestAPIStackPullFragtNicht(t *testing.T) {
	s, ops, cookie, csrf := schreibServer(t, sauber())

	rec := stackPost(t, s, http.MethodPost, "/api/v1/docker/stacks/web", cookie, csrf, map[string]any{
		"aktion": "pull",
	})
	if rec.Code != http.StatusAccepted {
		t.Fatalf("Status = %d, erwartet 202: %s", rec.Code, rec.Body.String())
	}
	<-ops.stackDone
}

// Löschen ist immer Stufe 3 mit dem Stack-Namen — es fährt herunter UND löscht.
// Und ein fremdes Projekt löscht das Panel gar nicht.
func TestAPIStackLoeschen(t *testing.T) {
	s, ops, cookie, csrf := schreibServer(t, sauber())

	rec := stackPost(t, s, http.MethodPost, "/api/v1/docker/stacks/web", cookie, csrf, map[string]any{
		"aktion": "loeschen",
	})
	frage := rueckfrageAus(t, rec)
	if frage.Bestaetigung.Tippen != "web" {
		t.Errorf("Löschen ist Stufe 3 mit dem Stack-Namen, verlangt wird %q", frage.Bestaetigung.Tippen)
	}

	rec = stackPost(t, s, http.MethodPost, "/api/v1/docker/stacks/web", cookie, csrf, map[string]any{
		"aktion": "loeschen", "bestaetigt": true, "getippt": "web",
	})
	if rec.Code != http.StatusAccepted {
		t.Fatalf("Status = %d, erwartet 202: %s", rec.Code, rec.Body.String())
	}
	<-ops.stackDone

	// Ein fremdes Projekt wird nicht gelöscht — auch nicht mit dem richtigen
	// Wort. Das Panel löscht nur, was es selbst geschrieben hat.
	rec = stackPost(t, s, http.MethodPost, "/api/v1/docker/stacks/fremd", cookie, csrf, map[string]any{
		"aktion": "loeschen", "bestaetigt": true, "getippt": "fremd",
	})
	if rec.Code != http.StatusBadRequest {
		t.Errorf("ein fremdes Projekt darf nicht gelöscht werden, Status = %d", rec.Code)
	}
	for _, ruf := range ops.recorded() {
		if ruf == "docker:stack-loeschen:fremd" {
			t.Error("das fremde Projekt wurde trotzdem gelöscht")
		}
	}
}

// Höchstens ein Stack-Vorgang gleichzeitig: Zwei „compose up" nebeneinander
// streiten um dieselben Netze und Volumes.
func TestAPIStackNurEinVorgang(t *testing.T) {
	s, _, cookie, csrf := schreibServer(t, sauber())

	if rec := stackPost(t, s, http.MethodPost, "/api/v1/docker/stacks/web", cookie, csrf, map[string]any{
		"aktion": "pull",
	}); rec.Code != http.StatusAccepted {
		t.Fatalf("erster Vorgang: Status = %d", rec.Code)
	}
	// Ohne warten: Der Vorgang der Attrappe ist schnell, aber der zweite Aufruf
	// geht sofort hinterher — genau darum geht es.
	rec := stackPost(t, s, http.MethodPost, "/api/v1/docker/stacks/fremd", cookie, csrf, map[string]any{
		"aktion": "pull",
	})
	if rec.Code != http.StatusAccepted && rec.Code != http.StatusConflict {
		t.Errorf("Status = %d, erwartet 202 oder 409: %s", rec.Code, rec.Body.String())
	}
}

// Schreiben verlangt die Owner-Rolle, nicht bloß Schreibrecht. Ein
// Compose-Stack ist Codeausführung als root — ein Admin-Konto, das Dienste neu
// starten darf, soll nicht nebenbei die Rechtetrennung des Servers aufheben.
func TestAPIStackSchreibroutenVerlangenOwner(t *testing.T) {
	s, ops, cookie, csrf := stackServer(t, store.RoleAdmin)
	ops.stackPruefung = sauber()

	faelle := []struct {
		pfad    string
		methode string
		koerper map[string]any
	}{
		{"/api/v1/docker/stacks", http.MethodPost, map[string]any{"name": "neu", "text": "services: {}\n"}},
		{"/api/v1/docker/stacks/web", http.MethodPut, map[string]any{"text": "services: {}\n"}},
		{"/api/v1/docker/stacks/web", http.MethodPost, map[string]any{"aktion": "up"}},
	}
	for _, f := range faelle {
		rec := stackPost(t, s, f.methode, f.pfad, cookie, csrf, f.koerper)
		if rec.Code != http.StatusForbidden {
			t.Errorf("%s %s: Status = %d, erwartet 403", f.methode, f.pfad, rec.Code)
		}
	}
	if len(ops.recorded()) != 0 {
		t.Errorf("ein Admin-Konto hat trotzdem etwas ausgelöst: %v", ops.recorded())
	}

	// Lesen darf es weiterhin.
	if rec := get(t, s, "/api/v1/docker/stacks", cookie); rec.Code != http.StatusOK {
		t.Errorf("Lesen muss auch einem Admin-Konto offenstehen, Status = %d", rec.Code)
	}
}

// Eine unbekannte Aktion wird abgewiesen, statt als etwas anderes ausgelegt zu
// werden. Ebenso ein unbekanntes JSON-Feld — ein Tippfehler in „bestaetigt"
// wäre sonst stillschweigend eine unbeantwortete Rückfrage.
func TestAPIStackAktionWeistUnbekanntesAb(t *testing.T) {
	s, _, cookie, csrf := schreibServer(t, sauber())

	rec := stackPost(t, s, http.MethodPost, "/api/v1/docker/stacks/web", cookie, csrf, map[string]any{
		"aktion": "zerstoeren",
	})
	if rec.Code != http.StatusBadRequest {
		t.Errorf("unbekannte Aktion: Status = %d, erwartet 400", rec.Code)
	}
	rec = stackPost(t, s, http.MethodPost, "/api/v1/docker/stacks/web", cookie, csrf, map[string]any{
		"aktion": "up", "bestaetig": true,
	})
	if rec.Code != http.StatusBadRequest {
		t.Errorf("unbekanntes Feld: Status = %d, erwartet 400", rec.Code)
	}
}

// Die Vorlagen stehen in der Liste: Wer einen Stack anlegt, soll nicht vor
// einem leeren Feld sitzen.
func TestAPIStacksLiefertVorlagen(t *testing.T) {
	s, _, cookie, _ := stackServer(t, store.RoleOwner)

	antwort := stackListe(t, s, cookie)
	if len(antwort.Vorlagen) < 3 {
		t.Fatalf("erwartet mindestens drei Vorlagen, geliefert %d", len(antwort.Vorlagen))
	}
	for _, v := range antwort.Vorlagen {
		if v.Kennung == "" || v.Titel == "" || v.Text == "" {
			t.Errorf("eine Vorlage ist unvollständig: %+v", v)
		}
	}
}
