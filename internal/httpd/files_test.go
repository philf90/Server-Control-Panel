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

	rec := get(t, s, "/alt/files?path="+wurzel+"/schreibbar", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d: %s", rec.Code, rec.Body.String())
	}
	body := rec.Body.String()
	for _, erwartet := range []string{
		"notizen.txt",
		"unterordner",
		"0644",                 // Rechte in Oktal
		"5 B",                  // Größe
		"tar.gz",               // Archiv-Aktion für das Verzeichnis
		"/alt/files/download?", // Download-Aktion für die Datei
		"krumen",               // klickbarer Pfad
	} {
		if !strings.Contains(body, erwartet) {
			t.Errorf("die Seite enthält %q nicht", erwartet)
		}
	}

	// Die Wurzel zeigt den gesperrten Eintrag mit Begründung, aber ohne
	// Download-Verweis.
	rec = get(t, s, "/alt/files?path="+wurzel, cookie)
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

	rec := get(t, s, "/alt/files?path="+wurzel+"&q=nginx", cookie)
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

	rec := get(t, s, "/alt/files/download?path="+strings.ReplaceAll(filepath.Join(wurzel, "schreibbar", "prot okoll.log"), " ", "%20"), cookie)
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

	rec := get(t, s, "/alt/files/download?path="+urlWert(filepath.Join(wurzel, "schreibbar", name)), cookie)
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

	rec := get(t, s, "/alt/files/archive?path="+urlWert(filepath.Join(wurzel, "schreibbar", "baum")), cookie)
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

	rec := get(t, s, "/alt/files/detail?path="+urlWert(filepath.Join(wurzel, "schreibbar", "baum")), cookie)
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
		{"außerhalb der Wurzel", "/etc/passwd", "/alt/files", http.StatusForbidden},
		{"gesperrt", filepath.Join(wurzel, "geheim.geheim"), "/alt/files/download", http.StatusForbidden},
		{"gibt es nicht", filepath.Join(wurzel, "nichts"), "/alt/files", http.StatusNotFound},
		{"Verzeichnis als Download", filepath.Join(wurzel, "schreibbar"), "/alt/files/download", http.StatusUnsupportedMediaType},
		{"Pseudo-Dateisystem", "/proc/self/environ", "/alt/files/download", http.StatusForbidden},
		{"relativer Pfad", "etc/passwd", "/alt/files", http.StatusBadRequest},
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

	for _, route := range []string{"/alt/files", "/alt/files/download?path=/etc/passwd", "/alt/files/archive?path=/etc", "/alt/files/detail?path=/etc"} {
		rec := get(t, s, route, cookie)
		if rec.Code != http.StatusNotFound {
			t.Errorf("%s: Status %d, erwartet 404", route, rec.Code)
		}
	}

	// Und der Menüpunkt fehlt.
	rec := get(t, s, "/alt/", cookie)
	if strings.Contains(rec.Body.String(), `href="/alt/files"`) {
		t.Error("die Navigation zeigt Dateien, obwohl das Modul aus ist")
	}
}

func TestFilesNavigationZeigtDenPunkt(t *testing.T) {
	s, _ := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	rec := get(t, s, "/alt/", cookie)
	if !strings.Contains(rec.Body.String(), `href="/alt/files"`) {
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

	if rec := get(t, s, "/alt/files?path="+urlWert(wurzel), cookie); rec.Code != http.StatusOK {
		t.Errorf("Browsen: Status %d", rec.Code)
	}
	rec := get(t, s, "/alt/files/download?path="+urlWert(filepath.Join(wurzel, "schreibbar", "da.txt")), cookie)
	if rec.Code != http.StatusOK {
		t.Errorf("Download: Status %d", rec.Code)
	}
}

func TestFilesOhneAnmeldungKeinZugriff(t *testing.T) {
	s, wurzel := newFilesServer(t)
	lege(t, filepath.Join(wurzel, "schreibbar", "da.txt"), "x")

	for _, route := range []string{
		"/alt/files?path=" + urlWert(wurzel),
		"/alt/files/download?path=" + urlWert(filepath.Join(wurzel, "schreibbar", "da.txt")),
		"/alt/files/archive?path=" + urlWert(wurzel),
		"/alt/files/detail?path=" + urlWert(wurzel),
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

// TestFilesWarntBeiNichtBeschreibbaremBereich prüft den Hinweis, der jede per
// Selbstupdate aktualisierte Installation betrifft: Das Update tauscht das
// Programm, nie die systemd-Unit. Trägt sie noch ProtectHome=read-only,
// scheitert jeder Schreibversuch mit EROFS — und zwar ohne dass die Rechtebits
// etwas davon verraten.
func TestFilesWarntBeiNichtBeschreibbaremBereich(t *testing.T) {
	if os.Geteuid() == 0 {
		t.Skip("als root ist auch ein Verzeichnis ohne Schreibrecht beschreibbar")
	}
	var wurzel string
	s, w := newFilesServerMit(t, func(p *privops.FilesPolicy) {
		wurzel = p.ReadableRoots[0]
		p.WritableRoots = append(p.WritableRoots, filepath.Join(wurzel, "gesperrt"))
	})
	wurzel = w

	// Ein Verzeichnis, in das der Prozess nicht schreiben darf.
	gesperrt := filepath.Join(wurzel, "gesperrt")
	if err := os.MkdirAll(gesperrt, 0o500); err != nil {
		t.Fatal(err)
	}

	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	rec := get(t, s, "/alt/files?path="+urlWert(wurzel), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d", rec.Code)
	}
	body := rec.Body.String()
	if !strings.Contains(body, "kann in diesen Bereichen nicht schreiben") {
		t.Error("der Hinweis auf den nicht beschreibbaren Bereich fehlt")
	}
	if !strings.Contains(body, "ProtectHome") {
		t.Error("der Hinweis nennt die wahrscheinliche Ursache nicht")
	}
	if !strings.Contains(body, "systemctl edit") {
		t.Error("der Hinweis nennt nicht, wie es behoben wird")
	}
}

// ------------------------------------------------- Zielauswahl (/files/dirs) ---

// TestFilesDirsNenntNurOrdner: Die Auswahl beim Verschieben und Kopieren zieht
// ihre Struktur aus diesem Endpunkt. Er darf nur Verzeichnisse nennen — und nur
// solche, die die Pfadwache ohnehin zeigt.
func TestFilesDirsNenntNurOrdner(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	arbeit := filepath.Join(wurzel, "schreibbar")
	for _, d := range []string{"ziel-a", "ziel-b"} {
		if err := os.MkdirAll(filepath.Join(arbeit, d), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	lege(t, filepath.Join(arbeit, "datei.txt"), "x")

	rec := get(t, s, "/alt/files/dirs?path="+urlWert(arbeit), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200 (%s)", rec.Code, rec.Body.String())
	}
	if ct := rec.Header().Get("Content-Type"); !strings.HasPrefix(ct, "application/json") {
		t.Errorf("Content-Type = %q", ct)
	}

	var antwort fileDirs
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	if antwort.Path != arbeit {
		t.Errorf("Path = %q, erwartet %q", antwort.Path, arbeit)
	}
	if !antwort.Writable {
		t.Error("der Schreibbereich gilt als nicht beschreibbar")
	}
	if len(antwort.Dirs) != 2 {
		t.Fatalf("%d Einträge, erwartet 2 (nur Ordner): %+v", len(antwort.Dirs), antwort.Dirs)
	}
	for _, d := range antwort.Dirs {
		if !d.Writable {
			t.Errorf("%s gilt als nicht beschreibbar", d.Path)
		}
		if strings.HasSuffix(d.Name, ".txt") {
			t.Errorf("eine Datei ist in der Auswahl: %s", d.Name)
		}
	}
	// Die Schreibbereiche kommen mit: Sie sind die Sprungmarken der Auswahl.
	if len(antwort.Roots) == 0 {
		t.Error("die Schreibbereiche fehlen in der Antwort")
	}
	if len(antwort.Crumbs) == 0 {
		t.Error("der klickbare Pfad fehlt")
	}
}

// Außerhalb der sichtbaren Bereiche gibt der Endpunkt nichts heraus — dieselbe
// Wache wie für die Liste, und der Statuscode sagt, woran es lag.
func TestFilesDirsBleibtInDerWache(t *testing.T) {
	s, _ := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	for pfad, wollen := range map[string]int{
		"/etc":            http.StatusForbidden,
		"/":               http.StatusForbidden,
		"/proc/self/root": http.StatusForbidden,
		// Ein relativer Pfad ist keine Politikfrage, sondern eine unbrauchbare
		// Angabe — die Wache unterscheidet das, und der Statuscode auch.
		"../../etc": http.StatusBadRequest,
	} {
		rec := get(t, s, "/alt/files/dirs?path="+urlWert(pfad), cookie)
		if rec.Code != wollen {
			t.Errorf("%s: Status = %d, erwartet %d", pfad, rec.Code, wollen)
		}
	}
}

// Ein nicht beschreibbarer Ordner steht in der Auswahl, ist aber als solcher
// gekennzeichnet: Die Auswahl gibt den Knopf nur für beschreibbare Ziele frei.
func TestFilesDirsKennzeichnetNurLesbare(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	if err := os.MkdirAll(filepath.Join(wurzel, "nurlesbar", "tief"), 0o755); err != nil {
		t.Fatal(err)
	}

	var antwort fileDirs
	rec := get(t, s, "/alt/files/dirs?path="+urlWert(wurzel), cookie)
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatal(err)
	}
	if antwort.Writable {
		t.Error("die Lesewurzel gilt als beschreibbar")
	}
	var gesehen bool
	for _, d := range antwort.Dirs {
		if d.Name != "nurlesbar" {
			continue
		}
		gesehen = true
		if d.Writable {
			t.Error("nurlesbar gilt als beschreibbar")
		}
	}
	if !gesehen {
		t.Errorf("der Ordner nurlesbar fehlt in der Auswahl: %+v", antwort.Dirs)
	}
}

// TestFilesListeZeigtAktionenImMenue: Die Aktionsspalte trägt ein Menü je Zeile,
// keine Reihe von Knöpfen — und im Menü steht weiter alles, was die Rolle darf.
//
// Vorher standen bis zu drei Knöpfe in jeder Zeile (bearbeiten, laden, Details).
// Bei zwanzig Einträgen waren das sechzig Knöpfe, und die Spalte war breiter als
// die Spalte "Geändert". Beim Verdichten ist die naheliegende Abkürzung, die
// Aktionen einfach zu streichen und auf die Detailseite zu verweisen; dieser
// Test hält fest, dass sie erreichbar geblieben sind.
func TestFilesListeZeigtAktionenImMenue(t *testing.T) {
	s, wurzel := newFilesServer(t)
	arbeit := filepath.Join(wurzel, "schreibbar")
	lege(t, filepath.Join(arbeit, "da.txt"), "x")

	owner := addUser(t, s, "philipp", store.RoleOwner)
	ownerCookie, _ := login(t, s, owner)

	rec := get(t, s, "/alt/files?path="+urlWert(arbeit), ownerCookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d — %s", rec.Code, rec.Body.String())
	}
	seite := rec.Body.String()

	if !strings.Contains(seite, `<details class="zeilenmenu">`) {
		t.Error("die Zeile hat kein Menü — die Aktionsspalte ist wieder eine Knopfreihe")
	}
	for _, ziel := range []string{"/alt/files/edit?", "/alt/files/download?", "/alt/files/entry?"} {
		if !strings.Contains(seite, ziel) {
			t.Errorf("%s ist aus der Liste verschwunden", ziel)
		}
	}
	// Das Menü braucht keine Knöpfe: Ein <a class="button"> in der Aktionszelle
	// wäre der alte Zustand.
	if strings.Contains(seite, `<a class="button small" href="/alt/files/download`) {
		t.Error("der Ladeknopf steht wieder frei in der Zeile")
	}

	// Dieselbe Liste für eine nur lesende Rolle: Das Menü bleibt, der Weg in den
	// Editor nicht.
	leser := addUser(t, s, "leser", store.RoleReadOnly)
	leserCookie, _ := login(t, s, leser)
	rec = get(t, s, "/alt/files?path="+urlWert(arbeit), leserCookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d", rec.Code)
	}
	seite = rec.Body.String()
	if !strings.Contains(seite, `<details class="zeilenmenu">`) {
		t.Error("die lesende Rolle sieht kein Menü")
	}
	if strings.Contains(seite, "/alt/files/edit?") {
		t.Error("die nur lesende Rolle sieht den Weg in den Editor")
	}
}

// TestFilesDetailseiteLoeschtAusDemKopf: Das Löschen steht bei den Aktionen der
// Seite, nicht in einem eigenen Abschnitt am Fuß.
//
// Der eigene Abschnitt war die vierte Platte der Seite — Überschrift, Karte,
// Erklärung, Knopf — für eine Aktion, die aus einem Klick besteht. Die
// Rückfrage bleibt, und sie nennt weiter die Zahlen: Sie ist die einzige Bremse,
// denn einen Papierkorb gibt es nicht.
func TestFilesDetailseiteLoeschtAusDemKopf(t *testing.T) {
	s, wurzel := newFilesServer(t)
	arbeit := filepath.Join(wurzel, "schreibbar")
	lege(t, filepath.Join(arbeit, "weg.txt"), "x")

	owner := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, owner)

	rec := get(t, s, "/alt/files/entry?path="+urlWert(filepath.Join(arbeit, "weg.txt")), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d — %s", rec.Code, rec.Body.String())
	}
	seite := rec.Body.String()

	// Der Seitenkopf reicht vom Beginn der Kopfzeile bis zum klickbaren Pfad
	// darunter — was danach kommt, sind die Abschnitte der Seite.
	kopf := seite[strings.Index(seite, `<div class="pagehead">`):strings.Index(seite, `<nav class="krumen"`)]
	if !strings.Contains(kopf, `action="/alt/files/delete"`) {
		t.Error("der Knopf zum Löschen steht nicht im Seitenkopf")
	}
	// Die Rückfrage ist der Ersatz für den erklärenden Abschnitt: Ohne sie wäre
	// aus einem Klick ein endgültiger geworden. Sie steht in data-bestaetigen und
	// nicht in einem onsubmit — ein Inline-Handler wird von der CSP verworfen,
	// und verbindlich ist ohnehin der Handler (siehe bestaetigung.go).
	if !strings.Contains(seite, "data-bestaetigen=") {
		t.Error("die Rückfrage vor dem Löschen fehlt")
	}
	if strings.Contains(seite, "onsubmit=") {
		t.Error("die Rückfrage steht wieder in einem Inline-Handler — die CSP verwirft ihn")
	}
	if strings.Contains(seite, ">Löschen<") {
		t.Error("der eigene Abschnitt Löschen ist zurück")
	}
	// Die Angaben stehen in einer Zeile, nicht mehr als Definitionsliste.
	if strings.Contains(seite, "<dl>") {
		t.Error("die Angaben sind wieder eine Definitionsliste")
	}
	if !strings.Contains(seite, `class="angabenzeile"`) {
		t.Error("die Zeile mit den Angaben fehlt")
	}
}
