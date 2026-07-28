package httpd

import (
	"archive/tar"
	"compress/gzip"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// newFilesServer baut einen Server, dessen Dateimanager auf ein
// Wegwerfverzeichnis zeigt.
//
// Der echte Dateimanager zeigt auf "/". Ein Test dagegen würde entweder das
// System des Entwicklers anfassen oder von dessen Inhalt abhängen — beides
// wäre für eine Prüfung unbrauchbar.
func newFilesServer(t *testing.T) (*Server, string) {
	t.Helper()
	return newFilesServerMit(t, nil)
}

// newFilesServerMit erlaubt es, die Politik anzupassen — etwa um eine Sperre
// tiefer im Baum zu prüfen.
func newFilesServerMit(t *testing.T, anpassen func(*privops.FilesPolicy)) (*Server, string) {
	t.Helper()
	s := newTestServer(t)

	wurzel, err := filepath.EvalSymlinks(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(wurzel, "schreibbar"), 0o755); err != nil {
		t.Fatal(err)
	}

	pol := privops.FilesPolicy{
		ReadableRoots: []string{wurzel},
		WritableRoots: []string{filepath.Join(wurzel, "schreibbar")},
		DeniedPaths:   []string{filepath.Join(wurzel, "*.geheim")},
		BackupDir:     filepath.Join(wurzel, "sicherungen"),
	}
	if anpassen != nil {
		anpassen(&pol)
	}
	fsys, err := privops.NewFileSystem(pol)
	if err != nil {
		t.Fatalf("NewFileSystem: %v", err)
	}
	t.Cleanup(fsys.Close)
	s.files = fsys
	return s, wurzel
}

func lege(t *testing.T, pfad, inhalt string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(pfad), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(pfad, []byte(inhalt), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestFilesSeiteZeigtVerzeichnis(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	lege(t, filepath.Join(wurzel, "schreibbar", "notizen.txt"), "hallo")
	lege(t, filepath.Join(wurzel, "schreibbar", "unterordner", "tief.conf"), "a: 1")
	lege(t, filepath.Join(wurzel, "schluessel.geheim"), "privat")

	rec := get(t, s, "/files?path="+wurzel+"/schreibbar", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d: %s", rec.Code, rec.Body.String())
	}
	body := rec.Body.String()
	for _, erwartet := range []string{
		"notizen.txt",
		"unterordner",
		"0644",             // Rechte in Oktal
		"5 B",              // Größe
		"tar.gz",           // Archiv-Aktion für das Verzeichnis
		"/files/download?", // Download-Aktion für die Datei
		"krumen",           // klickbarer Pfad
	} {
		if !strings.Contains(body, erwartet) {
			t.Errorf("die Seite enthält %q nicht", erwartet)
		}
	}

	// Die Wurzel zeigt den gesperrten Eintrag mit Begründung, aber ohne
	// Download-Verweis.
	rec = get(t, s, "/files?path="+wurzel, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d", rec.Code)
	}
	body = rec.Body.String()
	if !strings.Contains(body, "schluessel.geheim") {
		t.Error("der gesperrte Eintrag fehlt — er soll sichtbar sein")
	}
	if !strings.Contains(body, "gesperrt") {
		t.Error("die Kennzeichnung als gesperrt fehlt")
	}
	if strings.Contains(body, "download?path="+wurzel+"/schluessel.geheim") {
		t.Error("für den gesperrten Eintrag steht ein Download-Verweis in der Seite")
	}
}

func TestFilesSucheFindetUnterhalb(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	lege(t, filepath.Join(wurzel, "a", "nginx.conf"), "x")
	lege(t, filepath.Join(wurzel, "b", "tief", "nginx-alt.conf"), "x")
	lege(t, filepath.Join(wurzel, "b", "anderes.txt"), "x")

	rec := get(t, s, "/files?path="+wurzel+"&q=nginx", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d: %s", rec.Code, rec.Body.String())
	}
	body := rec.Body.String()
	if !strings.Contains(body, "nginx.conf") || !strings.Contains(body, "nginx-alt.conf") {
		t.Error("nicht alle Treffer stehen in der Seite")
	}
	if strings.Contains(body, "anderes.txt") {
		t.Error("ein Nicht-Treffer steht in der Seite")
	}
	if !strings.Contains(body, "Treffer für") {
		t.Error("die Seite sagt nicht, dass sie Treffer statt eines Verzeichnisses zeigt")
	}
}

func TestFilesDownloadLiefertBytes(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	inhalt := "Zeile eins\nZeile zwei\n"
	lege(t, filepath.Join(wurzel, "schreibbar", "prot okoll.log"), inhalt)

	rec := get(t, s, "/files/download?path="+strings.ReplaceAll(filepath.Join(wurzel, "schreibbar", "prot okoll.log"), " ", "%20"), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d: %s", rec.Code, rec.Body.String())
	}
	if rec.Body.String() != inhalt {
		t.Errorf("Inhalt %q, erwartet %q", rec.Body.String(), inhalt)
	}
	// Niemals ein Typ, den der Browser rendern könnte: Eine ausgelieferte
	// HTML-Datei liefe im Ursprung des Panels.
	if got := rec.Header().Get("Content-Type"); got != "application/octet-stream" {
		t.Errorf("Content-Type %q", got)
	}
	verfuegung := rec.Header().Get("Content-Disposition")
	if !strings.HasPrefix(verfuegung, "attachment;") {
		t.Errorf("Content-Disposition %q beginnt nicht mit attachment", verfuegung)
	}
	if !strings.Contains(verfuegung, "filename*=UTF-8''") {
		t.Errorf("Content-Disposition %q hat keine UTF-8-Fassung des Namens", verfuegung)
	}

	// Der Download steht im Audit-Log: Er verlässt den Server.
	eintraege, err := s.db.ListAudit(t.Context(), 10)
	if err != nil {
		t.Fatal(err)
	}
	var gefunden bool
	for _, e := range eintraege {
		if e.Action == "files.download" && strings.HasSuffix(e.Target, "prot okoll.log") {
			gefunden = true
		}
	}
	if !gefunden {
		t.Error("der Download steht nicht im Audit-Log")
	}
}

func TestFilesDownloadMitUmlautImNamen(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	name := "Änderungen-Übersicht.txt"
	lege(t, filepath.Join(wurzel, "schreibbar", name), "x")

	rec := get(t, s, "/files/download?path="+urlWert(filepath.Join(wurzel, "schreibbar", name)), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d: %s", rec.Code, rec.Body.String())
	}
	verfuegung := rec.Header().Get("Content-Disposition")
	// Die ASCII-Fassung ersetzt die Umlaute, die UTF-8-Fassung trägt sie.
	if !strings.Contains(verfuegung, `filename="_nderungen-_bersicht.txt"`) {
		t.Errorf("ASCII-Fassung fehlt oder ist falsch: %q", verfuegung)
	}
	if !strings.Contains(verfuegung, "%C3%84nderungen") {
		t.Errorf("UTF-8-Fassung fehlt: %q", verfuegung)
	}
}

func TestFilesArchiveLiefertTarGz(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	lege(t, filepath.Join(wurzel, "schreibbar", "baum", "a.txt"), "aaa")
	lege(t, filepath.Join(wurzel, "schreibbar", "baum", "tief", "b.txt"), "bbb")

	rec := get(t, s, "/files/archive?path="+urlWert(filepath.Join(wurzel, "schreibbar", "baum")), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d: %s", rec.Code, rec.Body.String())
	}
	if got := rec.Header().Get("Content-Type"); got != "application/gzip" {
		t.Errorf("Content-Type %q", got)
	}
	if !strings.Contains(rec.Header().Get("Content-Disposition"), "baum.tar.gz") {
		t.Errorf("Content-Disposition %q", rec.Header().Get("Content-Disposition"))
	}

	gz, err := gzip.NewReader(rec.Body)
	if err != nil {
		t.Fatalf("gzip: %v", err)
	}
	tr := tar.NewReader(gz)
	namen := map[string]bool{}
	for {
		kopf, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			t.Fatalf("tar: %v", err)
		}
		namen[kopf.Name] = true
	}
	for _, erwartet := range []string{"baum/", "baum/a.txt", "baum/tief/b.txt"} {
		if !namen[erwartet] {
			t.Errorf("%q fehlt im Archiv (enthalten: %v)", erwartet, namen)
		}
	}
}

func TestFilesDetailAlsJSON(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	lege(t, filepath.Join(wurzel, "schreibbar", "baum", "a.txt"), "12345")

	rec := get(t, s, "/files/detail?path="+urlWert(filepath.Join(wurzel, "schreibbar", "baum")), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d: %s", rec.Code, rec.Body.String())
	}
	var antwort fileDetail
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("JSON: %v — %s", err, rec.Body.String())
	}
	if antwort.Entry.Kind != privops.KindDir {
		t.Errorf("Art %q", antwort.Entry.Kind)
	}
	if antwort.Measurement == nil {
		t.Fatal("die Zählung fehlt")
	}
	if antwort.Measurement.Files != 1 || antwort.Measurement.Bytes != 5 {
		t.Errorf("Zählung %+v", *antwort.Measurement)
	}
}

// TestFilesStatuscodesPassenZumGrund: Ein abgelehnter Pfad ist etwas anderes
// als ein fehlender. Ohne die Unterscheidung stünde für jeden Fall 500 im
// Protokoll — und der Bedienende wüsste nicht, ob er sich vertippt hat oder
// etwas nicht darf.
func TestFilesStatuscodesPassenZumGrund(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	lege(t, filepath.Join(wurzel, "schreibbar", "da.txt"), "x")
	lege(t, filepath.Join(wurzel, "geheim.geheim"), "x")

	faelle := []struct {
		name   string
		pfad   string
		route  string
		status int
	}{
		{"außerhalb der Wurzel", "/etc/passwd", "/files", http.StatusForbidden},
		{"gesperrt", filepath.Join(wurzel, "geheim.geheim"), "/files/download", http.StatusForbidden},
		{"gibt es nicht", filepath.Join(wurzel, "nichts"), "/files", http.StatusNotFound},
		{"Verzeichnis als Download", filepath.Join(wurzel, "schreibbar"), "/files/download", http.StatusUnsupportedMediaType},
		{"Pseudo-Dateisystem", "/proc/self/environ", "/files/download", http.StatusForbidden},
		{"relativer Pfad", "etc/passwd", "/files", http.StatusBadRequest},
	}
	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			rec := get(t, s, f.route+"?path="+urlWert(f.pfad), cookie)
			if rec.Code != f.status {
				t.Errorf("Status %d, erwartet %d — %s", rec.Code, f.status, rec.Body.String())
			}
		})
	}
}

// TestFilesAbgeschaltetHatKeineRoute prüft die Zusage aus der Architektur: Ein
// abgeschaltetes Modul verliert Routen und Rechte, nicht nur den Menüpunkt.
func TestFilesAbgeschaltetHatKeineRoute(t *testing.T) {
	s, _ := newFilesServer(t)
	s.files = nil
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	for _, route := range []string{"/files", "/files/download?path=/etc/passwd", "/files/archive?path=/etc", "/files/detail?path=/etc"} {
		rec := get(t, s, route, cookie)
		if rec.Code != http.StatusNotFound {
			t.Errorf("%s: Status %d, erwartet 404", route, rec.Code)
		}
	}

	// Und der Menüpunkt fehlt.
	rec := get(t, s, "/", cookie)
	if strings.Contains(rec.Body.String(), `href="/files"`) {
		t.Error("die Navigation zeigt Dateien, obwohl das Modul aus ist")
	}
}

func TestFilesNavigationZeigtDenPunkt(t *testing.T) {
	s, _ := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	rec := get(t, s, "/", cookie)
	if !strings.Contains(rec.Body.String(), `href="/files"`) {
		t.Error("der Menüpunkt Dateien fehlt")
	}
}

// TestFilesLesenBrauchtKeineSchreibrolle: Browsen und Herunterladen darf jede
// angemeldete Rolle, auch readonly.
func TestFilesLesenBrauchtKeineSchreibrolle(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "leser", store.RoleReadOnly)
	cookie, _ := login(t, s, user)

	lege(t, filepath.Join(wurzel, "schreibbar", "da.txt"), "inhalt")

	if rec := get(t, s, "/files?path="+urlWert(wurzel), cookie); rec.Code != http.StatusOK {
		t.Errorf("Browsen: Status %d", rec.Code)
	}
	rec := get(t, s, "/files/download?path="+urlWert(filepath.Join(wurzel, "schreibbar", "da.txt")), cookie)
	if rec.Code != http.StatusOK {
		t.Errorf("Download: Status %d", rec.Code)
	}
}

func TestFilesOhneAnmeldungKeinZugriff(t *testing.T) {
	s, wurzel := newFilesServer(t)
	lege(t, filepath.Join(wurzel, "schreibbar", "da.txt"), "x")

	for _, route := range []string{
		"/files?path=" + urlWert(wurzel),
		"/files/download?path=" + urlWert(filepath.Join(wurzel, "schreibbar", "da.txt")),
		"/files/archive?path=" + urlWert(wurzel),
		"/files/detail?path=" + urlWert(wurzel),
	} {
		rec := get(t, s, route, nil)
		if rec.Code != http.StatusSeeOther && rec.Code != http.StatusFound {
			t.Errorf("%s: Status %d, erwartet eine Weiterleitung zur Anmeldung", route, rec.Code)
		}
	}
}

// urlWert kodiert einen Pfad für die Abfragezeichenkette.
func urlWert(pfad string) string {
	return strings.NewReplacer(" ", "%20", "+", "%2B", "&", "%26", "#", "%23", "?", "%3F").Replace(pfad)
}
