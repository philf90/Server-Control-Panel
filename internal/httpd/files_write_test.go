package httpd

import (
	"fmt"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// formular baut die Werte eines Schreibformulars samt CSRF-Token.
func formular(csrf string, paare ...string) url.Values {
	v := url.Values{"_csrf": {csrf}}
	for i := 0; i+1 < len(paare); i += 2 {
		v.Set(paare[i], paare[i+1])
	}
	return v
}

func TestFilesMkdirUndTouch(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	rec := post(t, s, "/alt/files/mkdir", formular(csrf, "dir", arbeit, "name", "neuer ordner"), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("mkdir: Status %d — %s", rec.Code, rec.Body.String())
	}
	if info, err := os.Stat(filepath.Join(arbeit, "neuer ordner")); err != nil || !info.IsDir() {
		t.Fatalf("der Ordner fehlt: %v", err)
	}

	rec = post(t, s, "/alt/files/touch", formular(csrf, "dir", arbeit, "name", "leer.conf"), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("touch: Status %d — %s", rec.Code, rec.Body.String())
	}
	if info, err := os.Stat(filepath.Join(arbeit, "leer.conf")); err != nil || info.Size() != 0 {
		t.Fatalf("die Datei fehlt oder ist nicht leer: %v", err)
	}

	// Zweimal derselbe Name ist ein Fehler, keine stille Übernahme.
	rec = post(t, s, "/alt/files/mkdir", formular(csrf, "dir", arbeit, "name", "neuer ordner"), cookie)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("zweites mkdir: Status %d, erwartet 400", rec.Code)
	}

	// Ein Name mit Schrägstrich ist ein Pfad und käme an der Prüfung des
	// Elternverzeichnisses vorbei.
	for _, name := range []string{"../raus", "unter/strich", "..", ""} {
		rec = post(t, s, "/alt/files/mkdir", formular(csrf, "dir", arbeit, "name", name), cookie)
		if rec.Code == http.StatusOK {
			t.Errorf("der Name %q wurde angenommen", name)
		}
	}
	if _, err := os.Stat(filepath.Join(wurzel, "raus")); err == nil {
		t.Error("es ist ein Verzeichnis außerhalb des Ziels entstanden")
	}
}

func TestFilesUmbenennenVerschiebenKopieren(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	lege(t, filepath.Join(arbeit, "alt.txt"), "inhalt")
	if err := os.MkdirAll(filepath.Join(arbeit, "ziel"), 0o755); err != nil {
		t.Fatal(err)
	}

	rec := post(t, s, "/alt/files/rename", formular(csrf, "path", filepath.Join(arbeit, "alt.txt"), "name", "neu.txt"), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("rename: Status %d — %s", rec.Code, rec.Body.String())
	}
	if _, err := os.Stat(filepath.Join(arbeit, "neu.txt")); err != nil {
		t.Fatalf("nach dem Umbenennen: %v", err)
	}

	rec = post(t, s, "/alt/files/copy", formular(csrf,
		"path", filepath.Join(arbeit, "neu.txt"), "target", filepath.Join(arbeit, "ziel")), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("copy: Status %d — %s", rec.Code, rec.Body.String())
	}
	roh, err := os.ReadFile(filepath.Join(arbeit, "ziel", "neu.txt"))
	if err != nil || string(roh) != "inhalt" {
		t.Fatalf("die Kopie: %q, %v", roh, err)
	}

	rec = post(t, s, "/alt/files/move", formular(csrf,
		"path", filepath.Join(arbeit, "neu.txt"), "target", filepath.Join(arbeit, "ziel", "unterordner")), cookie)
	if rec.Code == http.StatusOK {
		t.Error("das Verschieben in ein nicht vorhandenes Verzeichnis wurde angenommen")
	}

	// Und der echte Weg.
	if err := os.MkdirAll(filepath.Join(arbeit, "zwei"), 0o755); err != nil {
		t.Fatal(err)
	}
	rec = post(t, s, "/alt/files/move", formular(csrf,
		"path", filepath.Join(arbeit, "neu.txt"), "target", filepath.Join(arbeit, "zwei")), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("move: Status %d — %s", rec.Code, rec.Body.String())
	}
	if _, err := os.Stat(filepath.Join(arbeit, "neu.txt")); !os.IsNotExist(err) {
		t.Error("die Quelle liegt noch da")
	}
	if _, err := os.Stat(filepath.Join(arbeit, "zwei", "neu.txt")); err != nil {
		t.Errorf("am Ziel fehlt sie: %v", err)
	}
}

func TestFilesLoeschen(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	lege(t, filepath.Join(arbeit, "weg.txt"), "x")
	rec := post(t, s, "/alt/files/delete", ja(formular(csrf, "path", filepath.Join(arbeit, "weg.txt"))), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("delete: Status %d — %s", rec.Code, rec.Body.String())
	}
	if _, err := os.Stat(filepath.Join(arbeit, "weg.txt")); !os.IsNotExist(err) {
		t.Error("die Datei liegt noch da")
	}

	// Ein Verzeichnis samt Inhalt.
	lege(t, filepath.Join(arbeit, "baum", "tief", "a.txt"), "x")
	rec = post(t, s, "/alt/files/delete", ja(formular(csrf, "path", filepath.Join(arbeit, "baum")), "baum"), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("delete Ordner: Status %d — %s", rec.Code, rec.Body.String())
	}
	if _, err := os.Stat(filepath.Join(arbeit, "baum")); !os.IsNotExist(err) {
		t.Error("der Ordner liegt noch da")
	}

	// Der Löschvorgang steht mit Umfang im Audit-Log.
	eintraege, err := s.db.ListAudit(t.Context(), 20)
	if err != nil {
		t.Fatal(err)
	}
	var gefunden bool
	for _, e := range eintraege {
		if e.Action == "files.delete" && strings.HasSuffix(e.Target, "baum") {
			gefunden = true
			if !strings.Contains(e.Detail, "Dateien") {
				t.Errorf("der Audit-Eintrag nennt den Umfang nicht: %q", e.Detail)
			}
		}
	}
	if !gefunden {
		t.Error("das Löschen steht nicht im Audit-Log")
	}
}

// TestFilesLoeschenSchuetztGesperrtes: Ein rm -rf über einem Verzeichnis, in
// dem etwas Gesperrtes liegt, darf nichts anfassen — auch nicht die harmlosen
// Dateien daneben. Der Abbruch kommt vor dem ersten unlink, nicht nach dem
// ersten Treffer.
func TestFilesLoeschenSchuetztGesperrtes(t *testing.T) {
	var wurzel string
	s, w := newFilesServerMit(t, func(p *privops.FilesPolicy) {
		wurzel = p.ReadableRoots[0]
		p.DeniedPaths = append(p.DeniedPaths, filepath.Join(wurzel, "schreibbar", "baum", "*.geheim"))
	})
	wurzel = w
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	lege(t, filepath.Join(arbeit, "baum", "harmlos.txt"), "x")
	lege(t, filepath.Join(arbeit, "baum", "schluessel.geheim"), "privat")

	rec := post(t, s, "/alt/files/delete", ja(formular(csrf, "path", filepath.Join(arbeit, "baum")), "baum"), cookie)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("Status %d, erwartet 403 — %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "gesperrte") {
		t.Error("die Meldung nennt den Grund nicht")
	}
	for _, name := range []string{"harmlos.txt", "schluessel.geheim"} {
		if _, err := os.Stat(filepath.Join(arbeit, "baum", name)); err != nil {
			t.Errorf("%s wurde trotz Ablehnung gelöscht", name)
		}
	}

	// Und im Audit-Log steht eine Ablehnung, kein Systemfehler.
	eintraege, err := s.db.ListAudit(t.Context(), 20)
	if err != nil {
		t.Fatal(err)
	}
	var gefunden bool
	for _, e := range eintraege {
		if e.Action == "files.delete" && e.Result == store.ResultDenied {
			gefunden = true
		}
	}
	if !gefunden {
		t.Error("die Ablehnung steht nicht als denied im Audit-Log")
	}
}

func TestFilesRechteUndEigentuemer(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	lege(t, filepath.Join(arbeit, "baum", "a.txt"), "x")

	rec := post(t, s, "/alt/files/mode", formular(csrf,
		"path", filepath.Join(arbeit, "baum", "a.txt"), "mode", "600"), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("chmod: Status %d — %s", rec.Code, rec.Body.String())
	}
	info, err := os.Stat(filepath.Join(arbeit, "baum", "a.txt"))
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Errorf("Rechte %v, erwartet -rw-------", info.Mode().Perm())
	}

	// Rekursiv über das Verzeichnis.
	rec = post(t, s, "/alt/files/mode", formular(csrf,
		"path", filepath.Join(arbeit, "baum"), "mode", "700", "recursive", "1"), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("chmod rekursiv: Status %d — %s", rec.Code, rec.Body.String())
	}
	info, err = os.Stat(filepath.Join(arbeit, "baum", "a.txt"))
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o700 {
		t.Errorf("rekursiv: Rechte %v, erwartet 0700", info.Mode().Perm())
	}

	// Unsinnige Angaben werden abgewiesen, nicht geraten.
	for _, mode := range []string{"999", "abc", "77777"} {
		rec = post(t, s, "/alt/files/mode", formular(csrf,
			"path", filepath.Join(arbeit, "baum", "a.txt"), "mode", mode), cookie)
		if rec.Code == http.StatusOK {
			t.Errorf("die Rechteangabe %q wurde angenommen", mode)
		}
	}

	// Ohne jede Angabe passiert nichts, und das sagt die Seite auch.
	rec = post(t, s, "/alt/files/mode", formular(csrf, "path", filepath.Join(arbeit, "baum", "a.txt")), cookie)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("leeres Formular: Status %d, erwartet 400", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), "nichts anzuwenden") {
		t.Error("die Seite erklärt nicht, warum nichts geschehen ist")
	}
}

// TestFilesSchreibenBrauchtRolleUndToken ist die Zusage der Middleware, hier
// gegen jeden Endpunkt einzeln geprüft. Eine vergessene Kette an einer einzigen
// Route wäre ein Loch, das kein anderer Test findet.
func TestFilesSchreibenBrauchtRolleUndToken(t *testing.T) {
	s, wurzel := newFilesServer(t)
	arbeit := filepath.Join(wurzel, "schreibbar")
	lege(t, filepath.Join(arbeit, "da.txt"), "x")

	owner := addUser(t, s, "philipp", store.RoleOwner)
	ownerCookie, _ := login(t, s, owner)
	leser := addUser(t, s, "leser", store.RoleReadOnly)
	leserCookie, leserCSRF := login(t, s, leser)

	routen := map[string]url.Values{
		"/alt/files/mkdir":  formular("", "dir", arbeit, "name", "x"),
		"/alt/files/touch":  formular("", "dir", arbeit, "name", "y.txt"),
		"/alt/files/rename": formular("", "path", filepath.Join(arbeit, "da.txt"), "name", "z.txt"),
		"/alt/files/copy":   formular("", "path", filepath.Join(arbeit, "da.txt"), "target", arbeit),
		"/alt/files/move":   formular("", "path", filepath.Join(arbeit, "da.txt"), "target", arbeit),
		"/alt/files/delete": formular("", "path", filepath.Join(arbeit, "da.txt")),
		"/alt/files/mode":   formular("", "path", filepath.Join(arbeit, "da.txt"), "mode", "600"),
	}

	for route, werte := range routen {
		t.Run("ohne Token "+route, func(t *testing.T) {
			// Angemeldet als Owner, aber ohne CSRF-Token.
			rec := post(t, s, route, werte, ownerCookie)
			if rec.Code != http.StatusForbidden {
				t.Errorf("Status %d, erwartet 403", rec.Code)
			}
		})
		t.Run("nur lesende Rolle "+route, func(t *testing.T) {
			mitToken := url.Values{}
			for k, v := range werte {
				mitToken[k] = v
			}
			mitToken.Set("_csrf", leserCSRF)
			rec := post(t, s, route, mitToken, leserCookie)
			if rec.Code != http.StatusForbidden {
				t.Errorf("Status %d, erwartet 403", rec.Code)
			}
		})
	}

	// Die Datei hat all das überlebt.
	if _, err := os.Stat(filepath.Join(arbeit, "da.txt")); err != nil {
		t.Fatalf("die Datei wurde trotz abgewiesener Anfragen angefasst: %v", err)
	}
}

// TestFilesSchreibenNurInSchreibwurzeln: Lesen überall, Ändern nur dort, wo es
// erlaubt ist — auch über HTTP.
func TestFilesSchreibenNurInSchreibwurzeln(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	nurlesbar := filepath.Join(wurzel, "nurlesbar")
	if err := os.MkdirAll(nurlesbar, 0o755); err != nil {
		t.Fatal(err)
	}
	lege(t, filepath.Join(nurlesbar, "da.txt"), "unberührt")

	faelle := map[string]url.Values{
		"/alt/files/mkdir":  formular(csrf, "dir", nurlesbar, "name", "neu"),
		"/alt/files/delete": formular(csrf, "path", filepath.Join(nurlesbar, "da.txt")),
		"/alt/files/mode":   formular(csrf, "path", filepath.Join(nurlesbar, "da.txt"), "mode", "600"),
		"/alt/files/rename": formular(csrf, "path", filepath.Join(nurlesbar, "da.txt"), "name", "anders.txt"),
	}
	for route, werte := range faelle {
		rec := post(t, s, route, werte, cookie)
		if rec.Code != http.StatusForbidden {
			t.Errorf("%s: Status %d, erwartet 403 — %s", route, rec.Code, rec.Body.String())
		}
	}

	roh, err := os.ReadFile(filepath.Join(nurlesbar, "da.txt"))
	if err != nil || string(roh) != "unberührt" {
		t.Fatalf("die Datei wurde verändert: %q, %v", roh, err)
	}
}

func TestFilesDetailseiteZeigtNurErlaubteAktionen(t *testing.T) {
	s, wurzel := newFilesServer(t)
	arbeit := filepath.Join(wurzel, "schreibbar")
	lege(t, filepath.Join(arbeit, "da.txt"), "x")
	lege(t, filepath.Join(wurzel, "nurlesbar", "fremd.txt"), "x")

	owner := addUser(t, s, "philipp", store.RoleOwner)
	ownerCookie, _ := login(t, s, owner)
	leser := addUser(t, s, "leser", store.RoleReadOnly)
	leserCookie, _ := login(t, s, leser)

	// Owner auf einem beschreibbaren Pfad: alle Formulare.
	rec := get(t, s, "/alt/files/entry?path="+urlWert(filepath.Join(arbeit, "da.txt")), ownerCookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d — %s", rec.Code, rec.Body.String())
	}
	for _, erwartet := range []string{"/alt/files/rename", "/alt/files/move", "/alt/files/copy", "/alt/files/mode", "/alt/files/delete"} {
		if !strings.Contains(rec.Body.String(), erwartet) {
			t.Errorf("das Formular %s fehlt", erwartet)
		}
	}

	// Dieselbe Seite für eine nur lesende Rolle: kein einziges Formular.
	rec = get(t, s, "/alt/files/entry?path="+urlWert(filepath.Join(arbeit, "da.txt")), leserCookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d", rec.Code)
	}
	for _, verboten := range []string{"/alt/files/rename", "/alt/files/delete", "/alt/files/mode"} {
		if strings.Contains(rec.Body.String(), verboten) {
			t.Errorf("die nur lesende Rolle sieht %s", verboten)
		}
	}
	if !strings.Contains(rec.Body.String(), "nichts ändern") {
		t.Error("die Seite erklärt der lesenden Rolle nicht, warum sie keine Aktionen sieht")
	}

	// Owner auf einem Pfad außerhalb der Schreibbereiche: ebenfalls keine
	// Formulare, aber mit anderer Begründung.
	rec = get(t, s, "/alt/files/entry?path="+urlWert(filepath.Join(wurzel, "nurlesbar", "fremd.txt")), ownerCookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d", rec.Code)
	}
	if strings.Contains(rec.Body.String(), "/alt/files/delete") {
		t.Error("außerhalb der Schreibbereiche wird das Löschen angeboten")
	}
	if !strings.Contains(rec.Body.String(), "außerhalb der Bereiche") {
		t.Error("die Begründung fehlt")
	}
}

// TestFilesGrosserVorgangLaeuftAlsJob prüft die Schwelle, ab der ein Vorgang
// den Request verlässt. Ohne sie hinge ein Browser minutenlang und ließe bei
// Abbruch halbe Arbeit zurück.
func TestFilesGrosserVorgangLaeuftAlsJob(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	gross := filepath.Join(arbeit, "viele")
	if err := os.MkdirAll(gross, 0o755); err != nil {
		t.Fatal(err)
	}
	for i := 0; i < grosseVorgangSchwelle+10; i++ {
		lege(t, filepath.Join(gross, fmt.Sprintf("d%04d.txt", i)), "x")
	}

	rec := post(t, s, "/alt/files/delete", ja(formular(csrf, "path", gross), "viele"), cookie)
	if rec.Code != http.StatusAccepted {
		t.Fatalf("Status %d, erwartet 202 — %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "Hintergrund") {
		t.Error("die Seite sagt nicht, dass der Vorgang weiterläuft")
	}

	// Der Vorgang läuft und wird fertig.
	frist := time.After(30 * time.Second)
	for {
		if _, err := os.Stat(gross); os.IsNotExist(err) {
			break
		}
		select {
		case <-frist:
			t.Fatal("der Hintergrundvorgang wurde nicht fertig")
		case <-time.After(50 * time.Millisecond):
		}
	}

	// Und sein Verlauf ist über den Ereigniskanal abrufbar.
	events := stream(t, s, "/alt/files/events", cookie, 2*time.Second)
	if events.Code != http.StatusOK {
		t.Fatalf("Ereigniskanal: Status %d", events.Code)
	}
	if !strings.Contains(events.Body.String(), "files.delete") {
		t.Errorf("der Verlauf nennt den Vorgang nicht: %q", events.Body.String())
	}
}
