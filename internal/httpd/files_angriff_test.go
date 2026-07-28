package httpd

import (
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/store"
)

// Der Angriffsdurchgang der HTTP-Schicht des Dateimanagers.
//
// Die Pfadprüfung selbst hat ihren eigenen Durchgang in
// internal/privops/files_angriff_test.go. Hier geht es um das, was erst durch
// HTML, HTTP und das Audit-Log dazukommt: Ausgabe, Kopfzeilen und Protokolle.

// TestAngriffDateinameImHTML: Ein Dateiname ist Fremdeingabe. Wer eine Datei
// hochladen oder über SSH anlegen kann, bestimmt damit Text auf einer Seite,
// die ein Owner mit vollen Rechten betrachtet.
func TestAngriffDateinameImHTML(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	// Kein Schrägstrich in den Namen: Er wäre ein Verzeichnistrenner, und dann
	// prüfte der Test etwas anderes als gemeint.
	namen := []string{
		`<script>alert(1)<`,
		`"><img src=x onerror=alert(1)>.conf`,
		`'><svg onload=alert(1)>.log`,
		`da"ten.txt`,
	}
	for _, name := range namen {
		lege(t, filepath.Join(arbeit, name), "x")
	}

	rec := get(t, s, "/files?path="+urlWert(arbeit), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d", rec.Code)
	}
	body := rec.Body.String()

	// Kein einziger der Namen darf als Markup ankommen.
	for _, boese := range []string{"<script>alert", "<img src=x", "<svg onload="} {
		if strings.Contains(body, boese) {
			t.Errorf("die Seite enthält %q unmaskiert", boese)
		}
	}
	// Aber die Namen sind trotzdem sichtbar — maskiert.
	if !strings.Contains(body, "&lt;script&gt;alert(1)&lt;") {
		t.Error("der Name mit Markup fehlt in der Liste; er soll erscheinen, nur maskiert")
	}

	// Dasselbe auf der Detailseite und im Editor.
	pfad := filepath.Join(arbeit, namen[0])
	for _, route := range []string{"/files/entry?path=", "/files/edit?path="} {
		rec = get(t, s, route+urlWert(pfad), cookie)
		if rec.Code != http.StatusOK {
			t.Fatalf("%s: Status %d — %s", route, rec.Code, truncate(rec.Body.String(), 200))
		}
		if strings.Contains(rec.Body.String(), "<script>alert") {
			t.Errorf("%s enthält den Namen unmaskiert", route)
		}
	}
}

// TestAngriffDateinameImAuditLog: Ein Zeilenumbruch im Ziel eines
// Audit-Eintrags würde das Protokoll zerlegen — aus einem Eintrag würden zwei,
// und der zweite wäre frei erfunden.
func TestAngriffDateinameImAuditLog(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	// Der Versuch: ein Pfad mit Zeilenumbruch und einem gefälschten zweiten
	// Eintrag dahinter.
	boese := arbeit + "/harmlos.txt\n2026-01-01T00:00:00Z\troot\tfiles.delete\t/\tok"
	rec := post(t, s, "/files/delete", formular(csrf, "path", boese), cookie)
	if rec.Code == http.StatusOK {
		t.Fatal("ein Pfad mit Zeilenumbruch wurde angenommen")
	}

	eintraege, err := s.db.ListAudit(t.Context(), 20)
	if err != nil {
		t.Fatal(err)
	}
	for _, e := range eintraege {
		if strings.ContainsAny(e.Target, "\n\r") {
			t.Errorf("ein Audit-Eintrag trägt einen Zeilenumbruch im Ziel: %q", e.Target)
		}
		if e.Actor == "root" {
			t.Error("es ist ein Eintrag mit gefälschtem Akteur entstanden")
		}
	}
}

// TestAngriffQueryMitNullByte: Ein NUL-Byte in der Abfragezeichenkette schneidet
// Zeichenketten in C-Bibliotheken ab. Es darf nicht bis zu einem syscall
// durchkommen.
func TestAngriffQueryMitNullByte(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	lege(t, filepath.Join(wurzel, "schreibbar", "da.txt"), "x")

	ziel := "/files/download?path=" + url.QueryEscape(filepath.Join(wurzel, "schreibbar", "da.txt")+"\x00.png")
	rec := get(t, s, ziel, cookie)
	if rec.Code == http.StatusOK {
		t.Errorf("ein Pfad mit NUL-Byte wurde ausgeliefert (%d Bytes)", rec.Body.Len())
	}
}

// TestAngriffOhneAnmeldungAufSchreibrouten: Jede verändernde Route ohne
// Sitzung. Eine, die durchkäme, wäre Schreibzugriff ohne Anmeldung.
func TestAngriffOhneAnmeldungAufSchreibrouten(t *testing.T) {
	s, wurzel := newFilesServer(t)
	arbeit := filepath.Join(wurzel, "schreibbar")
	lege(t, filepath.Join(arbeit, "da.txt"), "unberührt")

	routen := map[string]url.Values{
		"/files/mkdir":  {"dir": {arbeit}, "name": {"x"}},
		"/files/touch":  {"dir": {arbeit}, "name": {"y.txt"}},
		"/files/rename": {"path": {filepath.Join(arbeit, "da.txt")}, "name": {"z.txt"}},
		"/files/copy":   {"path": {filepath.Join(arbeit, "da.txt")}, "target": {arbeit}},
		"/files/move":   {"path": {filepath.Join(arbeit, "da.txt")}, "target": {arbeit}},
		"/files/delete": {"path": {filepath.Join(arbeit, "da.txt")}},
		"/files/mode":   {"path": {filepath.Join(arbeit, "da.txt")}, "mode": {"777"}},
		"/files/save":   {"path": {filepath.Join(arbeit, "da.txt")}, "content": {"verändert"}},
	}
	for route, werte := range routen {
		rec := post(t, s, route, werte, nil)
		if rec.Code == http.StatusOK {
			t.Errorf("%s ohne Anmeldung: Status 200", route)
		}
	}

	roh, err := os.ReadFile(filepath.Join(arbeit, "da.txt"))
	if err != nil || string(roh) != "unberührt" {
		t.Fatalf("die Datei wurde ohne Anmeldung verändert: %q, %v", roh, err)
	}
	eintraege, err := os.ReadDir(arbeit)
	if err != nil {
		t.Fatal(err)
	}
	if len(eintraege) != 1 {
		for _, e := range eintraege {
			t.Errorf("entstanden: %s", e.Name())
		}
	}
}

// TestAngriffDownloadEinerHTMLDatei: Eine ausgelieferte HTML-Datei darf nicht im
// Ursprung des Panels laufen — sonst hätte sie Zugriff auf dessen Cookies und
// könnte im Namen des Betrachters handeln.
func TestAngriffDownloadEinerHTMLDatei(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	pfad := filepath.Join(wurzel, "schreibbar", "seite.html")
	lege(t, pfad, "<html><script>fetch('/users')</script></html>")

	rec := get(t, s, "/files/download?path="+urlWert(pfad), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d", rec.Code)
	}
	if got := rec.Header().Get("Content-Type"); got != "application/octet-stream" {
		t.Errorf("Content-Type %q — der Browser würde die Datei rendern", got)
	}
	if !strings.HasPrefix(rec.Header().Get("Content-Disposition"), "attachment;") {
		t.Errorf("Content-Disposition %q", rec.Header().Get("Content-Disposition"))
	}
	// Und die Richtlinie dieser Antwort erlaubt ohnehin nichts.
	if csp := rec.Header().Get("Content-Security-Policy"); !strings.Contains(csp, "default-src 'none'") {
		t.Errorf("Content-Security-Policy %q", csp)
	}
}

// TestAngriffEditorSpeichertNichtUeberSperrliste: Der Editor ist der bequemste
// Weg, eine Datei zu ersetzen — auch eine gesperrte.
func TestAngriffEditorSpeichertNichtUeberSperrliste(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	geheim := filepath.Join(wurzel, "schluessel.geheim")
	lege(t, geheim, "privater Schlüssel")

	// Lesen im Editor ist schon abgelehnt.
	if rec := get(t, s, "/files/edit?path="+urlWert(geheim), cookie); rec.Code != http.StatusForbidden {
		t.Errorf("Editor auf gesperrte Datei: Status %d, erwartet 403", rec.Code)
	}
	// Speichern ohne vorheriges Lesen ebenso.
	rec := post(t, s, "/files/save", formular(csrf, "path", geheim, "content", "ersetzt"), cookie)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Speichern auf gesperrte Datei: Status %d, erwartet 403 — %s", rec.Code, truncate(rec.Body.String(), 200))
	}
	roh, err := os.ReadFile(geheim)
	if err != nil || string(roh) != "privater Schlüssel" {
		t.Fatalf("die gesperrte Datei wurde verändert: %q, %v", roh, err)
	}
}
