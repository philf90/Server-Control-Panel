package httpd

import (
	"bytes"
	"encoding/json"
	"io"
	"mime/multipart"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// uploadKoerper baut einen Multipart-Körper in der Reihenfolge, die der Server
// erwartet: Token und Zielverzeichnis vor den Dateien.
func uploadKoerper(t *testing.T, csrf, dir string, overwrite bool, dateien map[string]string) (string, io.Reader) {
	t.Helper()
	var puffer bytes.Buffer
	schreiber := multipart.NewWriter(&puffer)

	if csrf != "" {
		if err := schreiber.WriteField("_csrf", csrf); err != nil {
			t.Fatal(err)
		}
	}
	if err := schreiber.WriteField("dir", dir); err != nil {
		t.Fatal(err)
	}
	if overwrite {
		if err := schreiber.WriteField("overwrite", "1"); err != nil {
			t.Fatal(err)
		}
	}
	for name, inhalt := range dateien {
		teil, err := schreiber.CreateFormFile("file", name)
		if err != nil {
			t.Fatal(err)
		}
		if _, err := teil.Write([]byte(inhalt)); err != nil {
			t.Fatal(err)
		}
	}
	if err := schreiber.Close(); err != nil {
		t.Fatal(err)
	}
	return schreiber.FormDataContentType(), &puffer
}

// upload schickt einen Multipart-Upload.
func upload(t *testing.T, s *Server, koerperTyp string, koerper io.Reader, cookie *http.Cookie, kopfzeilen map[string]string) *httptest.ResponseRecorder {
	t.Helper()
	req := httptest.NewRequest(http.MethodPost, "/files/upload", koerper)
	req.Header.Set("Content-Type", koerperTyp)
	for k, v := range kopfzeilen {
		req.Header.Set(k, v)
	}
	if cookie != nil {
		req.AddCookie(cookie)
	}
	rec := httptest.NewRecorder()
	s.Handler().ServeHTTP(rec, req)
	return rec
}

func TestFilesUploadNimmtDateienAuf(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	typ, koerper := uploadKoerper(t, csrf, arbeit, false, map[string]string{
		"eins.txt": "Inhalt eins",
		"zwei.log": "Inhalt zwei",
	})
	rec := upload(t, s, typ, koerper, cookie, nil)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d — %s", rec.Code, rec.Body.String())
	}

	for name, erwartet := range map[string]string{"eins.txt": "Inhalt eins", "zwei.log": "Inhalt zwei"} {
		roh, err := os.ReadFile(filepath.Join(arbeit, name))
		if err != nil || string(roh) != erwartet {
			t.Errorf("%s: %q, %v", name, roh, err)
		}
	}

	// Jeder Upload steht im Audit-Log.
	eintraege, err := s.db.ListAudit(t.Context(), 20)
	if err != nil {
		t.Fatal(err)
	}
	var anzahl int
	for _, e := range eintraege {
		if e.Action == "files.upload" && e.Result == store.ResultOK {
			anzahl++
		}
	}
	if anzahl != 2 {
		t.Errorf("%d Audit-Einträge, erwartet 2", anzahl)
	}
}

// TestFilesUploadOhneTokenSchreibtNichts ist der Kern der Sonderbehandlung
// dieser Route: Sie liegt nicht hinter verifyCSRF, sondern prüft selbst. Ein
// Fehler darin wäre eine offene Schreibmöglichkeit für jede fremde Seite.
func TestFilesUploadOhneTokenSchreibtNichts(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	faelle := map[string]string{
		"ohne Token":    "",
		"falscher Wert": "nicht-der-echte-token",
		"fast richtig":  csrf + "x",
	}
	for name, token := range faelle {
		t.Run(name, func(t *testing.T) {
			typ, koerper := uploadKoerper(t, token, arbeit, false, map[string]string{"eindringling.txt": "x"})
			rec := upload(t, s, typ, koerper, cookie, nil)
			if rec.Code != http.StatusForbidden {
				t.Errorf("Status %d, erwartet 403 — %s", rec.Code, rec.Body.String())
			}
			if _, err := os.Stat(filepath.Join(arbeit, "eindringling.txt")); !os.IsNotExist(err) {
				t.Error("die Datei ist trotzdem entstanden")
			}
		})
	}

	// Und im Audit-Log steht die Ablehnung.
	eintraege, err := s.db.ListAudit(t.Context(), 20)
	if err != nil {
		t.Fatal(err)
	}
	var gefunden bool
	for _, e := range eintraege {
		if e.Action == "csrf.rejected" {
			gefunden = true
		}
	}
	if !gefunden {
		t.Error("die abgelehnten Uploads stehen nicht im Audit-Log")
	}
}

// TestFilesUploadMitTokenInKopfzeile: Der Weg des Fortschrittsbalkens.
func TestFilesUploadMitTokenInKopfzeile(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	typ, koerper := uploadKoerper(t, "", arbeit, false, map[string]string{"aus-xhr.txt": "hallo"})
	rec := upload(t, s, typ, koerper, cookie, map[string]string{
		"X-CSRF-Token": csrf,
		"Accept":       "application/json",
	})
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d — %s", rec.Code, rec.Body.String())
	}

	var antwort uploadAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("JSON: %v — %s", err, rec.Body.String())
	}
	if !antwort.OK || len(antwort.Entries) != 1 {
		t.Fatalf("Antwort: %+v", antwort)
	}
	if antwort.Entries[0].Name != "aus-xhr.txt" || antwort.Entries[0].Size != 5 {
		t.Errorf("Eintrag: %+v", antwort.Entries[0])
	}
}

func TestFilesUploadKonfliktUndOverwrite(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	lege(t, filepath.Join(arbeit, "da.txt"), "alt")

	// Ohne Kennzeichen bleibt die bestehende Datei stehen.
	typ, koerper := uploadKoerper(t, csrf, arbeit, false, map[string]string{"da.txt": "neu"})
	rec := upload(t, s, typ, koerper, cookie, nil)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("Status %d, erwartet 400 — %s", rec.Code, rec.Body.String())
	}
	roh, _ := os.ReadFile(filepath.Join(arbeit, "da.txt"))
	if string(roh) != "alt" {
		t.Fatalf("die Datei wurde ersetzt: %q", roh)
	}

	// Mit Kennzeichen wird ersetzt — und der Vorzustand liegt in der Sicherung.
	typ, koerper = uploadKoerper(t, csrf, arbeit, true, map[string]string{"da.txt": "neu"})
	rec = upload(t, s, typ, koerper, cookie, nil)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d — %s", rec.Code, rec.Body.String())
	}
	roh, _ = os.ReadFile(filepath.Join(arbeit, "da.txt"))
	if string(roh) != "neu" {
		t.Fatalf("die Datei wurde nicht ersetzt: %q", roh)
	}

	var sicherung string
	_ = filepath.WalkDir(filepath.Join(wurzel, "sicherungen"), func(p string, d os.DirEntry, err error) error {
		if err == nil && !d.IsDir() && filepath.Base(p) == "da.txt" {
			sicherung = p
		}
		return nil
	})
	if sicherung == "" {
		t.Fatal("keine Sicherung angelegt")
	}
	roh, _ = os.ReadFile(sicherung)
	if string(roh) != "alt" {
		t.Errorf("die Sicherung enthält %q, erwartet den Vorzustand", roh)
	}
}

func TestFilesUploadGrenzenUndPfade(t *testing.T) {
	s, wurzel := newFilesServerMit(t, func(p *privops.FilesPolicy) { p.MaxUpload = 64 })
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	// Zu groß: abgewiesen, ohne Rest auf der Platte.
	typ, koerper := uploadKoerper(t, csrf, arbeit, false, map[string]string{"gross.bin": strings.Repeat("x", 200)})
	rec := upload(t, s, typ, koerper, cookie, nil)
	if rec.Code != http.StatusRequestEntityTooLarge {
		t.Errorf("Status %d, erwartet 413 — %s", rec.Code, rec.Body.String())
	}
	eintraege, err := os.ReadDir(arbeit)
	if err != nil {
		t.Fatal(err)
	}
	for _, e := range eintraege {
		t.Errorf("zurückgeblieben: %s", e.Name())
	}

	// Ein Dateiname, der aus dem Zielverzeichnis führt, wird auf seinen letzten
	// Bestandteil gekürzt — Browser schicken manchmal ganze Pfade.
	typ, koerper = uploadKoerper(t, csrf, arbeit, false, map[string]string{`C:\Users\Max\bild.png`: "png"})
	rec = upload(t, s, typ, koerper, cookie, nil)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d — %s", rec.Code, rec.Body.String())
	}
	if _, err := os.Stat(filepath.Join(arbeit, "bild.png")); err != nil {
		t.Errorf("die Datei liegt nicht unter ihrem letzten Namensbestandteil: %v", err)
	}

	// Ein Ziel außerhalb der Schreibbereiche.
	if err := os.MkdirAll(filepath.Join(wurzel, "nurlesbar"), 0o755); err != nil {
		t.Fatal(err)
	}
	typ, koerper = uploadKoerper(t, csrf, filepath.Join(wurzel, "nurlesbar"), false, map[string]string{"x.txt": "x"})
	rec = upload(t, s, typ, koerper, cookie, nil)
	if rec.Code != http.StatusForbidden {
		t.Errorf("außerhalb der Schreibbereiche: Status %d, erwartet 403", rec.Code)
	}

	// Ein Ziel außerhalb der Wurzel überhaupt.
	typ, koerper = uploadKoerper(t, csrf, "/etc", false, map[string]string{"x.txt": "x"})
	rec = upload(t, s, typ, koerper, cookie, nil)
	if rec.Code != http.StatusForbidden {
		t.Errorf("außerhalb der Wurzel: Status %d, erwartet 403", rec.Code)
	}
}

func TestFilesUploadBrauchtSchreibrolle(t *testing.T) {
	s, wurzel := newFilesServer(t)
	leser := addUser(t, s, "leser", store.RoleReadOnly)
	cookie, csrf := login(t, s, leser)
	arbeit := filepath.Join(wurzel, "schreibbar")

	typ, koerper := uploadKoerper(t, csrf, arbeit, false, map[string]string{"x.txt": "x"})
	rec := upload(t, s, typ, koerper, cookie, nil)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Status %d, erwartet 403", rec.Code)
	}
	if _, err := os.Stat(filepath.Join(arbeit, "x.txt")); !os.IsNotExist(err) {
		t.Error("die nur lesende Rolle hat eine Datei angelegt")
	}
}

// TestFilesUploadOhneDatei: Ein Formular ohne Auswahl ist ein Bedienfehler und
// keine Ausnahme.
func TestFilesUploadOhneDatei(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	typ, koerper := uploadKoerper(t, csrf, filepath.Join(wurzel, "schreibbar"), false, nil)
	rec := upload(t, s, typ, koerper, cookie, nil)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status %d, erwartet 400", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), "keine Datei") {
		t.Error("die Meldung sagt nicht, was fehlt")
	}
}

// TestFilesUploadFeldReihenfolge: Kommt die Datei vor dem Token, wird sie nicht
// geschrieben. Das ist die Zusage, die die Reihenfolge im Formular begründet.
func TestFilesUploadFeldReihenfolge(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	var puffer bytes.Buffer
	schreiber := multipart.NewWriter(&puffer)
	if err := schreiber.WriteField("dir", arbeit); err != nil {
		t.Fatal(err)
	}
	teil, err := schreiber.CreateFormFile("file", "zuerst.txt")
	if err != nil {
		t.Fatal(err)
	}
	if _, err := teil.Write([]byte("Inhalt")); err != nil {
		t.Fatal(err)
	}
	// Der Token kommt zu spät.
	if err := schreiber.WriteField("_csrf", csrf); err != nil {
		t.Fatal(err)
	}
	if err := schreiber.Close(); err != nil {
		t.Fatal(err)
	}

	rec := upload(t, s, schreiber.FormDataContentType(), &puffer, cookie, nil)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("Status %d, erwartet 403 — %s", rec.Code, rec.Body.String())
	}
	if _, err := os.Stat(filepath.Join(arbeit, "zuerst.txt")); !os.IsNotExist(err) {
		t.Error("die Datei wurde geschrieben, obwohl der Token erst danach kam")
	}
}

// TestFilesUploadStreamtOhneSpeicher ist die Messung zur wichtigsten Zusage
// dieses Endpunkts.
//
// Der Körper ist größer als die Vorgabe von ParseMultipartForm (32 MiB) und
// größer als das halbe Speicherbudget des Dienstes (MemoryMax=256M). Würde er
// gepuffert, sähe man es hier: Der Haufen wüchse um die Größe der Datei.
func TestFilesUploadStreamtOhneSpeicher(t *testing.T) {
	const groesse = 40 << 20 // 40 MiB

	s, wurzel := newFilesServerMit(t, func(p *privops.FilesPolicy) { p.MaxUpload = 64 << 20 })
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	// Der Körper entsteht während des Lesens, nicht vorher: Ein Test, der ihn
	// selbst puffert, könnte über den Server nichts aussagen.
	pr, pw := io.Pipe()
	schreiber := multipart.NewWriter(pw)
	go func() {
		defer func() { _ = pw.Close() }()
		if err := schreiber.WriteField("_csrf", csrf); err != nil {
			return
		}
		if err := schreiber.WriteField("dir", arbeit); err != nil {
			return
		}
		teil, err := schreiber.CreateFormFile("file", "gross.bin")
		if err != nil {
			return
		}
		if _, err := io.CopyN(teil, wiederholLeser{}, groesse); err != nil {
			return
		}
		_ = schreiber.Close()
	}()

	runtime.GC()
	var vorher, nachher runtime.MemStats
	runtime.ReadMemStats(&vorher)

	rec := upload(t, s, schreiber.FormDataContentType(), pr, cookie, nil)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d — %s", rec.Code, rec.Body.String())
	}

	runtime.GC()
	runtime.ReadMemStats(&nachher)

	info, err := os.Stat(filepath.Join(arbeit, "gross.bin"))
	if err != nil {
		t.Fatalf("die Datei fehlt: %v", err)
	}
	if info.Size() != groesse {
		t.Fatalf("Größe %d, erwartet %d", info.Size(), groesse)
	}

	// Großzügige Grenze: Es geht nicht um ein paar Kilobyte, sondern um die
	// Frage, ob die Datei im Speicher landet.
	const grenze = 12 << 20
	zuwachs := int64(nachher.HeapAlloc) - int64(vorher.HeapAlloc)
	t.Logf("Haufen vorher %s, nachher %s, Zuwachs %s bei %s Körper",
		formatBytesKurz(int64(vorher.HeapAlloc)), formatBytesKurz(int64(nachher.HeapAlloc)),
		formatBytesKurz(zuwachs), formatBytesKurz(groesse))
	if zuwachs > grenze {
		t.Errorf("der Haufen wuchs um %s — der Körper wird gepuffert statt gestreamt",
			formatBytesKurz(zuwachs))
	}
}

// wiederholLeser liefert beliebig viele Bytes, ohne sie vorzuhalten.
type wiederholLeser struct{}

func (wiederholLeser) Read(p []byte) (int, error) {
	for i := range p {
		p[i] = byte('a' + i%26)
	}
	return len(p), nil
}
