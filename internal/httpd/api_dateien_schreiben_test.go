package httpd

// Tests für den verändernden Teil von /api/v1/files.
//
// Der Schwerpunkt liegt auf den drei Stellen, an denen ein Fehler hier teuer ist:
//
//  1. Die Rückfrage. Bis 0.3.0-rc.5 waren dreizehn Rückfragen im Projekt so
//     gebaut, dass keine einzige gefragt hat — ein Test hätte das gesehen.
//     Geprüft wird deshalb für jede Stufe: Fehlt die Bestätigung, ist NICHTS
//     passiert, und die Antwort ist 409 mit dem Text der Frage.
//  2. Die Grenze zwischen Politik und Bedienung. Ein Pfad außerhalb der
//     Schreibbereiche wird abgelehnt, auch wenn ein selbstgebautes POST die
//     Bedienhilfe umgeht.
//  3. Das Recht. Ein Leserkonto darf nichts verändern, und ohne Token gilt das
//     auch für ein Owner-Konto.

import (
	"encoding/json"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// antwortVon liest eine geglückte Antwort.
func antwortVon(t *testing.T, roh []byte) apiDateiAntwort {
	t.Helper()
	var a apiDateiAntwort
	if err := json.Unmarshal(roh, &a); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v (%s)", err, roh)
	}
	return a
}

// frageVon liest eine Rückfrage (409).
func frageVon(t *testing.T, roh []byte) apiBestaetigung {
	t.Helper()
	var a apiBestaetigungAntwort
	if err := json.Unmarshal(roh, &a); err != nil {
		t.Fatalf("Rückfrage ist kein JSON: %v (%s)", err, roh)
	}
	return a.Bestaetigung
}

func TestAPIDateienAnlegenUndUmbenennen(t *testing.T) {
	s, wurzel, cookie, csrf := angemeldetMitDateien(t, store.RoleAdmin)
	schreib := filepath.Join(wurzel, "schreibbar")

	// Ordner anlegen — Stufe 1, keine Rückfrage. Ein leeres Verzeichnis anzulegen
	// nimmt nichts weg.
	rec := postJSON(t, s, "/api/v1/files/mkdir",
		`{"pfad":"`+schreib+`","name":"neuer"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("mkdir: Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	antwort := antwortVon(t, rec.Body.Bytes())
	if antwort.Eintrag == nil {
		t.Fatal("mkdir liefert keinen Eintrag — die Oberfläche müsste den Zustand danach raten")
	}
	if antwort.Eintrag.Eintrag.Name != "neuer" || !antwort.Eintrag.Eintrag.IsDir() {
		t.Errorf("mkdir liefert %+v, erwartet den neuen Ordner", antwort.Eintrag.Eintrag)
	}
	if info, err := os.Stat(filepath.Join(schreib, "neuer")); err != nil || !info.IsDir() {
		t.Errorf("der Ordner liegt nicht auf der Platte: %v", err)
	}

	// Datei anlegen.
	rec = postJSON(t, s, "/api/v1/files/touch",
		`{"pfad":"`+schreib+`","name":"leer.txt"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("touch: Status = %d: %s", rec.Code, rec.Body.String())
	}

	// Umbenennen — Stufe 1, umkehrbar durch ein zweites Umbenennen.
	rec = postJSON(t, s, "/api/v1/files/rename",
		`{"pfad":"`+filepath.Join(schreib, "leer.txt")+`","name":"voll.txt"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("rename: Status = %d: %s", rec.Code, rec.Body.String())
	}
	antwort = antwortVon(t, rec.Body.Bytes())
	if antwort.Eintrag == nil || antwort.Eintrag.Eintrag.Name != "voll.txt" {
		t.Errorf("rename liefert nicht den neuen Namen: %+v", antwort.Eintrag)
	}
	if _, err := os.Stat(filepath.Join(schreib, "voll.txt")); err != nil {
		t.Errorf("die umbenannte Datei fehlt: %v", err)
	}
	if _, err := os.Stat(filepath.Join(schreib, "leer.txt")); err == nil {
		t.Error("die Datei liegt noch unter dem alten Namen")
	}
}

// Ein Name mit Schrägstrich wird abgelehnt, und zwar mit dem Grund. Ohne diese
// Prüfung würde aus dem Namen „unter/tief" ein Pfad, und die Ablehnung hieße
// „Verzeichnis gibt es nicht" — eine Auskunft, die in die Irre führt.
func TestAPIDateienNamePruefung(t *testing.T) {
	s, wurzel, cookie, csrf := angemeldetMitDateien(t, store.RoleAdmin)
	schreib := filepath.Join(wurzel, "schreibbar")

	faelle := []struct {
		name    string
		wert    string
		enthalt string
	}{
		{"mit Schrägstrich", "unter/tief", "Schrägstrich"},
		{"leer", "", "kein Name"},
		{"Punkt", "..", "nicht zulässig"},
	}
	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			rec := postJSON(t, s, "/api/v1/files/mkdir",
				`{"pfad":"`+schreib+`","name":"`+f.wert+`"}`, cookie, csrf)
			if rec.Code != http.StatusBadRequest {
				t.Fatalf("Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
			}
			if !strings.Contains(rec.Body.String(), f.enthalt) {
				t.Errorf("die Antwort nennt den Grund nicht (%q fehlt): %s", f.enthalt, rec.Body.String())
			}
		})
	}
}

// Das Löschen einer Datei ist Stufe 2: Ohne Bestätigung passiert nichts, und die
// Antwort ist die Frage.
func TestAPIDateienLoeschenFragtZurueck(t *testing.T) {
	s, wurzel, cookie, csrf := angemeldetMitDateien(t, store.RoleAdmin)
	ziel := filepath.Join(wurzel, "schreibbar", "weg.txt")
	lege(t, ziel, "inhalt")

	rec := postJSON(t, s, "/api/v1/files/delete", `{"pfad":"`+ziel+`"}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409 (Rückfrage): %s", rec.Code, rec.Body.String())
	}
	// Und der eigentliche Punkt: Die Datei ist noch da.
	if _, err := os.Stat(ziel); err != nil {
		t.Fatalf("die Datei ist trotz fehlender Bestätigung weg: %v", err)
	}

	frage := frageVon(t, rec.Body.Bytes())
	if !strings.Contains(frage.Frage, "weg.txt") {
		t.Errorf("die Frage nennt das Ziel nicht: %q", frage.Frage)
	}
	if len(frage.Punkte) == 0 {
		t.Error("die Frage nennt keine Folgen")
	}
	// Eine einzelne Datei ist Stufe 2 — kein getipptes Wort.
	if frage.Tippen != "" {
		t.Errorf("für eine einzelne Datei wird ein Wort verlangt (%q) — das ist Stufe 3", frage.Tippen)
	}

	// Mit Bestätigung ist sie weg.
	rec = postJSON(t, s, "/api/v1/files/delete",
		`{"pfad":"`+ziel+`","bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	antwort := antwortVon(t, rec.Body.Bytes())
	// Kein Eintrag in der Antwort: Es gibt ihn nicht mehr, und ein leerer wäre
	// eine Behauptung über etwas, das weg ist.
	if antwort.Eintrag != nil {
		t.Error("nach dem Löschen trägt die Antwort einen Eintrag")
	}
	if antwort.Ordner != filepath.Join(wurzel, "schreibbar") {
		t.Errorf("Ordner = %q, erwartet den übergeordneten", antwort.Ordner)
	}
	if _, err := os.Stat(ziel); err == nil {
		t.Error("die Datei liegt noch da")
	}
}

// Ein Ordner MIT Inhalt ist Stufe 3: Das getippte Wort ist sein Name. Hinter
// einem Klick steht dort nicht ein Eintrag, sondern ein Baum.
func TestAPIDateienLoeschenOrdnerIstStufeDrei(t *testing.T) {
	s, wurzel, cookie, csrf := angemeldetMitDateien(t, store.RoleAdmin)
	ordner := filepath.Join(wurzel, "schreibbar", "baum")
	lege(t, filepath.Join(ordner, "a.txt"), "aaa")
	lege(t, filepath.Join(ordner, "tief", "b.txt"), "bbbbb")

	rec := postJSON(t, s, "/api/v1/files/delete", `{"pfad":"`+ordner+`"}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	frage := frageVon(t, rec.Body.Bytes())
	if frage.Tippen != "baum" {
		t.Errorf("Tippen = %q, erwartet den Ordnernamen (Stufe 3)", frage.Tippen)
	}
	// Die Zahlen stehen in der Frage, weil sie die Entscheidung tragen. „Ordner
	// wirklich löschen?" befähigt zu keiner.
	if !strings.Contains(frage.Frage, "2 Dateien") || !strings.Contains(frage.Frage, "Ordner") {
		t.Errorf("die Frage nennt die Zählung nicht: %q", frage.Frage)
	}

	// Ein falsches Wort führt die Aktion NICHT aus und stellt die Frage erneut.
	rec = postJSON(t, s, "/api/v1/files/delete",
		`{"pfad":"`+ordner+`","bestaetigt":true,"getippt":"falsch"}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("mit falschem Wort: Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	if _, err := os.Stat(ordner); err != nil {
		t.Fatalf("der Ordner ist nach einem falschen Wort weg: %v", err)
	}
	if frageVon(t, rec.Body.Bytes()).Fehler == "" {
		t.Error("die erneute Frage sagt nicht, dass das Wort nicht passte")
	}

	// Groß- und Kleinschreibung ist einerlei: Auf einem Telefon macht die
	// Tastatur aus „baum" gern „Baum". Wer den Namen abgeschrieben hat, hat die
	// Rückfrage gelesen — mehr soll die Stufe nicht leisten.
	rec = postJSON(t, s, "/api/v1/files/delete",
		`{"pfad":"`+ordner+`","bestaetigt":true,"getippt":"Baum"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	if _, err := os.Stat(ordner); err == nil {
		t.Error("der Ordner liegt noch da")
	}
}

// Ein leerer Ordner bleibt Stufe 2: Es steht nichts darunter, und ein getipptes
// Wort wäre eine Hürde ohne Anlass.
func TestAPIDateienLoeschenLeererOrdnerIstStufeZwei(t *testing.T) {
	s, wurzel, cookie, csrf := angemeldetMitDateien(t, store.RoleAdmin)
	ordner := filepath.Join(wurzel, "schreibbar", "leer")
	if err := os.MkdirAll(ordner, 0o755); err != nil {
		t.Fatal(err)
	}

	rec := postJSON(t, s, "/api/v1/files/delete", `{"pfad":"`+ordner+`"}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	frage := frageVon(t, rec.Body.Bytes())
	if frage.Tippen != "" {
		t.Errorf("Tippen = %q, erwartet leer für einen leeren Ordner", frage.Tippen)
	}
	// Und die Frage sagt, dass er leer ist, statt „enthält 0 Dateien, 1 Ordner" zu
	// behaupten — die 1 wäre der Ordner selbst gewesen.
	if !strings.Contains(frage.Frage, "leeren Ordner") {
		t.Errorf("die Frage lautet %q, erwartet einen Hinweis auf den leeren Ordner", frage.Frage)
	}
}

// Die Zählung im Detail lässt den Eintrag selbst weg. Ohne das behauptete ein
// leerer Ordner, „1 Ordner" zu enthalten — sich selbst.
func TestAPIDateienZaehlungOhneSichSelbst(t *testing.T) {
	s, wurzel, cookie, _ := angemeldetMitDateien(t, store.RoleAdmin)
	leer := filepath.Join(wurzel, "schreibbar", "nichts")
	if err := os.MkdirAll(leer, 0o755); err != nil {
		t.Fatal(err)
	}

	rec := get(t, s, "/api/v1/files/entry?pfad="+leer, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var detail apiDateiDetail
	if err := json.Unmarshal(rec.Body.Bytes(), &detail); err != nil {
		t.Fatal(err)
	}
	if detail.Mass == nil {
		t.Fatal("dem Ordner fehlt die Zählung")
	}
	if detail.Mass.Dirs != 0 || detail.Mass.Files != 0 {
		t.Errorf("Zählung = %+v, erwartet null Einträge für einen leeren Ordner", *detail.Mass)
	}

	// Und mit einem Unterordner darin: genau einer, nicht zwei.
	if err := os.MkdirAll(filepath.Join(leer, "einer"), 0o755); err != nil {
		t.Fatal(err)
	}
	rec = get(t, s, "/api/v1/files/entry?pfad="+leer, cookie)
	if err := json.Unmarshal(rec.Body.Bytes(), &detail); err != nil {
		t.Fatal(err)
	}
	if detail.Mass.Dirs != 1 {
		t.Errorf("Ordner = %d, erwartet 1 (der Unterordner, nicht er selbst)", detail.Mass.Dirs)
	}
}

// Kopieren und Verschieben laufen ohne Rückfrage — beides ist umkehrbar, und das
// Ziel wurde eben ausgewählt. Geprüft wird, dass die Antwort den Blick richtig
// führt: nach dem Verschieben zum neuen Ort, nach dem Kopieren zum Ziel.
func TestAPIDateienKopierenUndVerschieben(t *testing.T) {
	s, wurzel, cookie, csrf := angemeldetMitDateien(t, store.RoleAdmin)
	schreib := filepath.Join(wurzel, "schreibbar")
	unten := filepath.Join(schreib, "unten")
	if err := os.MkdirAll(unten, 0o755); err != nil {
		t.Fatal(err)
	}
	quelle := filepath.Join(schreib, "kopie.txt")
	lege(t, quelle, "inhalt")

	rec := postJSON(t, s, "/api/v1/files/copy",
		`{"pfad":"`+quelle+`","ziel":"`+unten+`"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("copy: Status = %d: %s", rec.Code, rec.Body.String())
	}
	antwort := antwortVon(t, rec.Body.Bytes())
	if antwort.Ordner != unten {
		t.Errorf("copy: Ordner = %q, erwartet das Ziel %q", antwort.Ordner, unten)
	}
	if _, err := os.Stat(filepath.Join(unten, "kopie.txt")); err != nil {
		t.Errorf("die Kopie fehlt: %v", err)
	}
	// Das Original steht noch da — sonst wäre es ein Verschieben.
	if _, err := os.Stat(quelle); err != nil {
		t.Errorf("das Original ist weg: %v", err)
	}

	// Und jetzt verschieben — in einen ANDEREN Ordner: In „unten" liegt schon die
	// Kopie, und ein Verschieben darauf wird zu Recht abgelehnt.
	woanders := filepath.Join(schreib, "woanders")
	if err := os.MkdirAll(woanders, 0o755); err != nil {
		t.Fatal(err)
	}
	rec = postJSON(t, s, "/api/v1/files/move",
		`{"pfad":"`+quelle+`","ziel":"`+woanders+`"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("move: Status = %d: %s", rec.Code, rec.Body.String())
	}
	antwort = antwortVon(t, rec.Body.Bytes())
	// Nach dem Verschieben zeigt die Antwort das Ziel und den Eintrag dort: Der
	// alte Pfad gibt es nicht mehr, und die Oberfläche folgt dem Eintrag.
	if antwort.Ordner != woanders {
		t.Errorf("move: Ordner = %q, erwartet %q", antwort.Ordner, woanders)
	}
	if antwort.Eintrag == nil || antwort.Eintrag.Eintrag.Path != filepath.Join(woanders, "kopie.txt") {
		t.Errorf("move: Eintrag = %+v, erwartet den Eintrag am neuen Ort", antwort.Eintrag)
	}
	if _, err := os.Stat(quelle); err == nil {
		t.Error("nach dem Verschieben liegt die Datei noch am alten Ort")
	}

	// Ein Ziel, in dem der Name schon vergeben ist, wird abgelehnt — nichts wird
	// stillschweigend überschrieben.
	lege(t, quelle, "wieder da")
	rec = postJSON(t, s, "/api/v1/files/move",
		`{"pfad":"`+quelle+`","ziel":"`+woanders+`"}`, cookie, csrf)
	if rec.Code == http.StatusOK {
		t.Error("das Verschieben auf einen vergebenen Namen war erlaubt")
	}
	if inhalt, err := os.ReadFile(filepath.Join(woanders, "kopie.txt")); err != nil ||
		string(inhalt) != "inhalt" {
		t.Errorf("die vorhandene Datei wurde überschrieben: %q / %v", inhalt, err)
	}

	// Ohne Ziel: 400 mit Grund, und nichts passiert.
	rec = postJSON(t, s, "/api/v1/files/copy",
		`{"pfad":"`+filepath.Join(unten, "kopie.txt")+`"}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("ohne Ziel: Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
	}
}

// Rechte setzen: einzeln ohne Rückfrage, rekursiv mit. Der rekursive Lauf ist die
// bewusste Verschärfung gegenüber der alten Oberfläche — ein chmod über einen
// Baum ist mit keinem zweiten Klick zurückzuholen, weil die vorherigen Rechte je
// Eintrag verschieden waren und nirgends stehen.
func TestAPIDateienRechteEinzelnOhneRekursivMit(t *testing.T) {
	s, wurzel, cookie, csrf := angemeldetMitDateien(t, store.RoleAdmin)
	datei := filepath.Join(wurzel, "schreibbar", "rechte.txt")
	lege(t, datei, "x")

	rec := postJSON(t, s, "/api/v1/files/mode",
		`{"pfad":"`+datei+`","rechte":"0600"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("einzeln: Status = %d, erwartet 200 (Stufe 1): %s", rec.Code, rec.Body.String())
	}
	info, err := os.Stat(datei)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Errorf("Rechte = %o, erwartet 600", info.Mode().Perm())
	}

	// Rekursiv über einen Ordner: erst die Frage.
	ordner := filepath.Join(wurzel, "schreibbar", "tief")
	lege(t, filepath.Join(ordner, "drin.txt"), "y")

	rec = postJSON(t, s, "/api/v1/files/mode",
		`{"pfad":"`+ordner+`","rechte":"0700","rekursiv":true}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("rekursiv: Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	frage := frageVon(t, rec.Body.Bytes())
	if len(frage.Punkte) < 2 {
		t.Errorf("die Frage nennt zu wenig: %+v", frage.Punkte)
	}
	// Der Grund für die Stufe steht in der Frage: Die alten Rechte kommen nicht
	// zurück.
	if !strings.Contains(strings.Join(frage.Punkte, " "), "nirgends") {
		t.Errorf("die Frage sagt nicht, dass die alten Rechte nicht zurückkommen: %+v", frage.Punkte)
	}
	// Und nichts ist passiert.
	drin, err := os.Stat(filepath.Join(ordner, "drin.txt"))
	if err != nil {
		t.Fatal(err)
	}
	if drin.Mode().Perm() == 0o700 {
		t.Fatal("der rekursive Lauf ist ohne Bestätigung gelaufen")
	}

	rec = postJSON(t, s, "/api/v1/files/mode",
		`{"pfad":"`+ordner+`","rechte":"0700","rekursiv":true,"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("rekursiv bestätigt: Status = %d: %s", rec.Code, rec.Body.String())
	}
	drin, err = os.Stat(filepath.Join(ordner, "drin.txt"))
	if err != nil {
		t.Fatal(err)
	}
	if drin.Mode().Perm() != 0o700 {
		t.Errorf("Rechte darunter = %o, erwartet 700", drin.Mode().Perm())
	}
}

// Eine unlesbare Rechteangabe wird VOR der Rückfrage abgelehnt: Sonst führte die
// Bestätigung in einen Formatfehler, und der Dialog wäre eine Zwischenstation
// ohne Zweck.
func TestAPIDateienRechteFormatVorDerFrage(t *testing.T) {
	s, wurzel, cookie, csrf := angemeldetMitDateien(t, store.RoleAdmin)
	ordner := filepath.Join(wurzel, "schreibbar")

	rec := postJSON(t, s, "/api/v1/files/mode",
		`{"pfad":"`+ordner+`","rechte":"quatsch","rekursiv":true}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400 vor der Rückfrage: %s", rec.Code, rec.Body.String())
	}
}

// Ohne Angabe ist nichts anzuwenden — 400 statt eines stillen Nichts.
func TestAPIDateienModeOhneAngabe(t *testing.T) {
	s, wurzel, cookie, csrf := angemeldetMitDateien(t, store.RoleAdmin)

	rec := postJSON(t, s, "/api/v1/files/mode",
		`{"pfad":"`+filepath.Join(wurzel, "schreibbar")+`"}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
	}
}

// Ein Pfad außerhalb der Schreibbereiche wird abgelehnt — auch mit Bestätigung.
// Das ist der Fall, der die Bedienhilfe umgeht: Ein selbstgebautes POST kennt die
// Aktionsliste nicht.
func TestAPIDateienSchreibenAusserhalbAbgelehnt(t *testing.T) {
	s, wurzel, cookie, csrf := angemeldetMitDateien(t, store.RoleAdmin)
	// Innerhalb der LESE-Wurzel, außerhalb der Schreibwurzel.
	draussen := filepath.Join(wurzel, "nurlesbar.txt")
	lege(t, draussen, "x")

	faelle := []struct{ handlung, koerper string }{
		{"delete", `{"pfad":"` + draussen + `","bestaetigt":true,"getippt":"nurlesbar.txt"}`},
		{"mode", `{"pfad":"` + draussen + `","rechte":"0600"}`},
		{"rename", `{"pfad":"` + draussen + `","name":"anders.txt"}`},
		{"mkdir", `{"pfad":"` + wurzel + `","name":"verboten"}`},
	}
	for _, f := range faelle {
		t.Run(f.handlung, func(t *testing.T) {
			rec := postJSON(t, s, "/api/v1/files/"+f.handlung, f.koerper, cookie, csrf)
			if rec.Code != http.StatusForbidden {
				t.Errorf("Status = %d, erwartet 403: %s", rec.Code, rec.Body.String())
			}
		})
	}
	// Die Datei ist unangetastet.
	if inhalt, err := os.ReadFile(draussen); err != nil || string(inhalt) != "x" {
		t.Errorf("die Datei außerhalb wurde verändert: %q / %v", inhalt, err)
	}
}

// Ein gesperrter Eintrag wird nicht angefasst, auch nicht mit Bestätigung.
func TestAPIDateienGesperrtNichtVeraenderbar(t *testing.T) {
	s, wurzel := newFilesServerMit(t, func(p *privops.FilesPolicy) {
		// Die Sperre liegt im Schreibbereich: Sonst wäre die Ablehnung schon
		// die der Schreibwurzel, und der Test sagte nichts über die Sperrliste.
		p.DeniedPaths = append(p.DeniedPaths, filepath.Join(p.WritableRoots[0], "*.geheim"))
	})
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	ziel := filepath.Join(wurzel, "schreibbar", "schluessel.geheim")
	lege(t, ziel, "privat")

	rec := postJSON(t, s, "/api/v1/files/delete",
		`{"pfad":"`+ziel+`","bestaetigt":true,"getippt":"schluessel.geheim"}`, cookie, csrf)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("Status = %d, erwartet 403: %s", rec.Code, rec.Body.String())
	}
	if _, err := os.Stat(ziel); err != nil {
		t.Errorf("der gesperrte Eintrag ist weg: %v", err)
	}
}

// Rechte und Token. Ein Leserkonto darf nichts, und ein Owner-Konto ohne Token
// auch nicht — der Grund muss unterscheidbar sein, damit die Oberfläche nicht
// nach einem frischen Token greift, wo die Rolle fehlt.
func TestAPIDateienSchreibenBrauchtRechtUndToken(t *testing.T) {
	// Leserkonto.
	sLeser, wurzel := newFilesServer(t)
	leser := addUser(t, sLeser, "leser", store.RoleReadOnly)
	cookieLeser, csrfLeser := login(t, sLeser, leser)

	rec := postJSON(t, sLeser, "/api/v1/files/mkdir",
		`{"pfad":"`+filepath.Join(wurzel, "schreibbar")+`","name":"x"}`, cookieLeser, csrfLeser)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Leserkonto: Status = %d, erwartet 403: %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "Schreibrechte") {
		t.Errorf("der Grund nennt nicht die fehlende Rolle: %s", rec.Body.String())
	}

	// Owner ohne Token.
	s, wurzel2, cookie, _ := angemeldetMitDateien(t, store.RoleOwner)
	rec = postJSON(t, s, "/api/v1/files/mkdir",
		`{"pfad":"`+filepath.Join(wurzel2, "schreibbar")+`","name":"x"}`, cookie, "")
	if rec.Code != http.StatusForbidden {
		t.Errorf("ohne Token: Status = %d, erwartet 403: %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "Sitzungstoken") {
		t.Errorf("der Grund nennt nicht das fehlende Token: %s", rec.Body.String())
	}
	if _, err := os.Stat(filepath.Join(wurzel2, "schreibbar", "x")); err == nil {
		t.Error("der Ordner wurde ohne Token angelegt")
	}
}

// Jede Handlung schreibt einen Audit-Eintrag — auch die abgelehnte. „denied" ist
// eine Aussage über die Politik, „error" eine über das System, und beide gehören
// ins Protokoll.
func TestAPIDateienSchreibtAudit(t *testing.T) {
	s, wurzel, cookie, csrf := angemeldetMitDateien(t, store.RoleAdmin)
	schreib := filepath.Join(wurzel, "schreibbar")

	if rec := postJSON(t, s, "/api/v1/files/mkdir",
		`{"pfad":"`+schreib+`","name":"protokolliert"}`, cookie, csrf); rec.Code != http.StatusOK {
		t.Fatalf("mkdir: %d %s", rec.Code, rec.Body.String())
	}
	// Und eine Ablehnung.
	if rec := postJSON(t, s, "/api/v1/files/mkdir",
		`{"pfad":"`+wurzel+`","name":"verboten"}`, cookie, csrf); rec.Code != http.StatusForbidden {
		t.Fatalf("erwartet 403, bekam %d", rec.Code)
	}

	eintraege, err := s.db.ListAudit(t.Context(), 50)
	if err != nil {
		t.Fatal(err)
	}
	var ok, denied bool
	for _, e := range eintraege {
		if e.Action != "files.mkdir" {
			continue
		}
		switch e.Result {
		case store.ResultOK:
			ok = true
		case store.ResultDenied:
			denied = true
		}
	}
	if !ok {
		t.Error("die geglückte Handlung steht nicht im Audit-Log")
	}
	if !denied {
		t.Error("die abgelehnte Handlung steht nicht als „denied\" im Audit-Log")
	}
}
