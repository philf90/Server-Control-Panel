package httpd

// Tests für den Editor über /api/v1/files/text.
//
// Der Schwerpunkt liegt auf den drei Zusagen, die den Editor eines Panels von
// einem Textfeld unterscheiden — und auf der einen, die man am leichtesten
// versehentlich bricht: dass ein Konflikt NICHT überschreibt.

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

func textVon(t *testing.T, roh []byte) apiDateiText {
	t.Helper()
	var a apiDateiText
	if err := json.Unmarshal(roh, &a); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v (%s)", err, roh)
	}
	return a
}

func TestAPIDateienEditorLiestUndSchreibt(t *testing.T) {
	s, wurzel, cookie, csrf := angemeldetMitDateien(t, store.RoleAdmin)
	datei := filepath.Join(wurzel, "schreibbar", "server.conf")
	lege(t, datei, "port: 8443\n")

	rec := get(t, s, "/api/v1/files/text?pfad="+datei, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	text := textVon(t, rec.Body.Bytes())

	if text.Inhalt != "port: 8443\n" {
		t.Errorf("Inhalt = %q", text.Inhalt)
	}
	if text.Hash == "" {
		t.Error("es fehlt der Hash — ohne ihn kann das Speichern keinen Konflikt erkennen")
	}
	// Die Sprache bestimmt der Server, weil dort der ganze Pfad bekannt ist.
	if text.Sprache != "ini" {
		t.Errorf("Sprache = %q, erwartet ini", text.Sprache)
	}
	// Die Grenze des Editors, nicht die Größe dieser Datei.
	if text.MaxEdit <= int64(len(text.Inhalt)) {
		t.Errorf("max_edit = %d — das ist die Dateigröße und nicht die Grenze", text.MaxEdit)
	}
	// Für eine gewöhnliche .conf gibt es kein Prüfprogramm; das muss dastehen,
	// statt eine Prüfung zu versprechen, die nicht kommt.
	if text.Pruefbar {
		t.Errorf("die Datei gilt als prüfbar (Werkzeug %q) — für eine beliebige .conf "+
			"gibt es keines", text.Werkzeug)
	}

	rec = postJSON(t, s, "/api/v1/files/text",
		`{"pfad":"`+datei+`","inhalt":"port: 9000\n","hash":"`+text.Hash+`",`+
			`"crlf":false,"ohne_schlussumbruch":false,"ueberschreiben":false}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Speichern: Status = %d: %s", rec.Code, rec.Body.String())
	}
	var antwort apiTextAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatal(err)
	}
	if antwort.Meldung == "" {
		t.Error("die Antwort sagt nicht, dass gespeichert wurde")
	}
	// Der NEUE Hash muss zurückkommen: Ohne ihn liefe das nächste Speichern in
	// einen Konflikt mit der eigenen Änderung.
	if antwort.Text.Hash == "" || antwort.Text.Hash == text.Hash {
		t.Errorf("der Hash hat sich nicht geändert (%q → %q)", text.Hash, antwort.Text.Hash)
	}
	if antwort.Pruefung != nil {
		t.Errorf("es steht eine Prüfung dabei, obwohl es keine gab: %+v", *antwort.Pruefung)
	}

	inhalt, err := os.ReadFile(datei)
	if err != nil {
		t.Fatal(err)
	}
	if string(inhalt) != "port: 9000\n" {
		t.Errorf("auf der Platte steht %q", inhalt)
	}
}

// Der Fall, um den es beim Editor eines Panels wirklich geht: Zwei Menschen
// bearbeiten dieselbe Datei. Die fremde Änderung darf nicht verschwinden.
func TestAPIDateienEditorKonfliktUeberschreibtNicht(t *testing.T) {
	s, wurzel, cookie, csrf := angemeldetMitDateien(t, store.RoleAdmin)
	datei := filepath.Join(wurzel, "schreibbar", "gemeinsam.conf")
	lege(t, datei, "erste fassung\n")

	rec := get(t, s, "/api/v1/files/text?pfad="+datei, cookie)
	if rec.Code != http.StatusOK {
		t.Fatal(rec.Body.String())
	}
	alt := textVon(t, rec.Body.Bytes())

	// Jemand anders schreibt.
	lege(t, datei, "fremde fassung\n")

	// Und jetzt der eigene Speicherversuch mit dem alten Hash.
	rec = postJSON(t, s, "/api/v1/files/text",
		`{"pfad":"`+datei+`","inhalt":"meine fassung\n","hash":"`+alt.Hash+`",`+
			`"crlf":false,"ohne_schlussumbruch":false,"ueberschreiben":false}`, cookie, csrf)
	// 412 und nicht 409: In dieser Schnittstelle trägt 409 schon die Bedeutung
	// „unbestätigt, hier ist der Text der Rückfrage". Zwei Bedeutungen an einem
	// Code wären die Stelle, an der ein Konflikt als Rückfrage erscheint und ein
	// zweiter Klick die fremde Änderung überschreibt.
	if rec.Code != http.StatusPreconditionFailed {
		t.Fatalf("Status = %d, erwartet 412: %s", rec.Code, rec.Body.String())
	}

	// Und das Entscheidende: Auf der Platte steht weiter die fremde Fassung.
	inhalt, err := os.ReadFile(datei)
	if err != nil {
		t.Fatal(err)
	}
	if string(inhalt) != "fremde fassung\n" {
		t.Fatalf("die fremde Änderung wurde überschrieben: %q", inhalt)
	}

	var konflikt apiTextKonflikt
	if err := json.Unmarshal(rec.Body.Bytes(), &konflikt); err != nil {
		t.Fatal(err)
	}
	if konflikt.Fehler == "" {
		t.Error("der Konflikt nennt keinen Grund")
	}
	// Der fremde Stand kommt mit — samt frischem Hash. Ohne ihn müsste die
	// Oberfläche eine zweite Anfrage stellen, und in der Lücke dazwischen hätte
	// der Bediener einen Konflikt ohne Weg heraus.
	if konflikt.Jetzt.Inhalt != "fremde fassung\n" {
		t.Errorf("der mitgelieferte Stand ist %q", konflikt.Jetzt.Inhalt)
	}
	if konflikt.Jetzt.Hash == "" || konflikt.Jetzt.Hash == alt.Hash {
		t.Errorf("der mitgelieferte Hash ist alt oder leer: %q", konflikt.Jetzt.Hash)
	}

	// Mit ueberschreiben=true geht es durch — bewusst.
	rec = postJSON(t, s, "/api/v1/files/text",
		`{"pfad":"`+datei+`","inhalt":"meine fassung\n","hash":"`+alt.Hash+`",`+
			`"crlf":false,"ohne_schlussumbruch":false,"ueberschreiben":true}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("mit ueberschreiben: Status = %d: %s", rec.Code, rec.Body.String())
	}
	inhalt, err = os.ReadFile(datei)
	if err != nil {
		t.Fatal(err)
	}
	if string(inhalt) != "meine fassung\n" {
		t.Errorf("nach dem bewussten Überschreiben steht %q da", inhalt)
	}
}

// Zeilenenden bleiben, wie sie waren. Ein Editor, der aus 4000 CRLF-Zeilen
// stillschweigend LF macht, schiebt den Unterschied in ein Diff, das niemand
// lesen kann.
func TestAPIDateienEditorBehaeltZeilenenden(t *testing.T) {
	s, wurzel, cookie, csrf := angemeldetMitDateien(t, store.RoleAdmin)
	datei := filepath.Join(wurzel, "schreibbar", "windows.ini")
	lege(t, datei, "[a]\r\nb=1\r\n")

	rec := get(t, s, "/api/v1/files/text?pfad="+datei, cookie)
	if rec.Code != http.StatusOK {
		t.Fatal(rec.Body.String())
	}
	text := textVon(t, rec.Body.Bytes())

	if !text.CRLF {
		t.Error("crlf ist nicht gesetzt — die Oberfläche könnte den Hinweis nicht zeigen")
	}
	// Der Editor bekommt LF: Der Browser schickt es so zurück, und alles andere
	// wäre eine Sonderbehandlung in jeder Zeile.
	if strings.Contains(text.Inhalt, "\r") {
		t.Errorf("der Inhalt trägt CR: %q", text.Inhalt)
	}

	rec = postJSON(t, s, "/api/v1/files/text",
		`{"pfad":"`+datei+`","inhalt":"[a]\nb=2\n","hash":"`+text.Hash+`",`+
			`"crlf":true,"ohne_schlussumbruch":false,"ueberschreiben":false}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}

	inhalt, err := os.ReadFile(datei)
	if err != nil {
		t.Fatal(err)
	}
	if string(inhalt) != "[a]\r\nb=2\r\n" {
		t.Errorf("auf der Platte steht %q, erwartet CRLF zurück", inhalt)
	}
}

// Ein fehlender Schlussumbruch bleibt ebenfalls. Er ist bei manchen Formaten
// bedeutungstragend und in jedem Fall ein Unterschied, den ein Diff zeigt.
func TestAPIDateienEditorBehaeltFehlendenSchlussumbruch(t *testing.T) {
	s, wurzel, cookie, csrf := angemeldetMitDateien(t, store.RoleAdmin)
	datei := filepath.Join(wurzel, "schreibbar", "ohneumbruch.txt")
	lege(t, datei, "letzte zeile")

	rec := get(t, s, "/api/v1/files/text?pfad="+datei, cookie)
	text := textVon(t, rec.Body.Bytes())
	if !text.OhneSchlussumbruch {
		t.Error("ohne_schlussumbruch ist nicht gesetzt")
	}

	rec = postJSON(t, s, "/api/v1/files/text",
		`{"pfad":"`+datei+`","inhalt":"andere zeile","hash":"`+text.Hash+`",`+
			`"crlf":false,"ohne_schlussumbruch":true,"ueberschreiben":false}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	inhalt, err := os.ReadFile(datei)
	if err != nil {
		t.Fatal(err)
	}
	if string(inhalt) != "andere zeile" {
		t.Errorf("auf der Platte steht %q — der Umbruch wurde hinzugefügt", inhalt)
	}
}

// Der Editor ist an dieselbe Grenze gebunden wie die Politik. Eine Logdatei von
// 800 MiB im Browser zu öffnen ist kein Handgriff, sondern ein Absturz.
func TestAPIDateienEditorGrenze(t *testing.T) {
	s, wurzel := newFilesServerMit(t, func(p *privops.FilesPolicy) {
		p.MaxEditSize = 8
	})
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, _ := login(t, s, user)

	datei := filepath.Join(wurzel, "schreibbar", "gross.txt")
	lege(t, datei, strings.Repeat("x", 64))

	rec := get(t, s, "/api/v1/files/text?pfad="+datei, cookie)
	if rec.Code != http.StatusRequestEntityTooLarge {
		t.Errorf("Status = %d, erwartet 413: %s", rec.Code, rec.Body.String())
	}
}

// Lesen im Editor braucht kein Schreibrecht — ansehen darf jede Rolle. Schreiben
// nicht.
func TestAPIDateienEditorRechte(t *testing.T) {
	s, wurzel := newFilesServer(t)
	leser := addUser(t, s, "leser", store.RoleReadOnly)
	cookie, csrf := login(t, s, leser)

	datei := filepath.Join(wurzel, "schreibbar", "nur-lesen.conf")
	lege(t, datei, "a=1\n")

	rec := get(t, s, "/api/v1/files/text?pfad="+datei, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Lesen: Status = %d, erwartet 200 für ein Leserkonto: %s", rec.Code, rec.Body.String())
	}
	text := textVon(t, rec.Body.Bytes())

	rec = postJSON(t, s, "/api/v1/files/text",
		`{"pfad":"`+datei+`","inhalt":"a=2\n","hash":"`+text.Hash+`",`+
			`"crlf":false,"ohne_schlussumbruch":false,"ueberschreiben":false}`, cookie, csrf)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Schreiben: Status = %d, erwartet 403", rec.Code)
	}
	if inhalt, err := os.ReadFile(datei); err != nil || string(inhalt) != "a=1\n" {
		t.Errorf("die Datei wurde verändert: %q / %v", inhalt, err)
	}
}

// Ein gesperrter Eintrag wird nicht gelesen — auch nicht als Text.
func TestAPIDateienEditorGesperrt(t *testing.T) {
	s, wurzel, cookie, _ := angemeldetMitDateien(t, store.RoleAdmin)
	datei := filepath.Join(wurzel, "schluessel.geheim")
	lege(t, datei, "privat")

	rec := get(t, s, "/api/v1/files/text?pfad="+datei, cookie)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Status = %d, erwartet 403: %s", rec.Code, rec.Body.String())
	}
	if strings.Contains(rec.Body.String(), "privat") {
		t.Error("der Inhalt des gesperrten Eintrags steht in der Fehlermeldung")
	}
}

// Die Hülle der neuen Oberfläche trägt den Stil-Nonce, und die Richtlinie nennt
// ihn. Ohne beides bleibt der Editor ungestylt — und zwar erst im Browser
// sichtbar, weshalb dieser Test die Zusage hier festhält.
func TestV2HuelleTraegtStilNonce(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)

	rec := get(t, s, "/v2/dateien", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}

	csp := rec.Header().Get("Content-Security-Policy")
	if !strings.Contains(csp, "'nonce-") {
		t.Fatalf("die Richtlinie nennt keinen Nonce: %q", csp)
	}
	// Der Platzhalter darf nicht mehr dastehen: Er wäre ein Nonce-Wert, den jeder
	// kennt, und damit dasselbe wie 'unsafe-inline'.
	if strings.Contains(rec.Body.String(), nonceMarke) {
		t.Error("der Platzhalter steht noch in der Hülle — der Nonce wurde nicht eingesetzt")
	}

	// Und der Wert in der Hülle ist DERSELBE wie in der Kopfzeile. Zwei
	// verschiedene wären schlimmer als keiner: Die Seite trüge einen Nonce, den
	// die Richtlinie nicht kennt, und niemand käme auf die Ursache.
	anfang := strings.Index(csp, "'nonce-")
	rest := csp[anfang+len("'nonce-"):]
	wert := rest[:strings.IndexByte(rest, '\'')]
	if wert == "" {
		t.Fatalf("der Nonce in der Richtlinie ist leer: %q", csp)
	}
	if !strings.Contains(rec.Body.String(), `content="`+wert+`"`) {
		t.Errorf("der Nonce der Hülle ist nicht der der Richtlinie (%q)", wert)
	}

	// Je Antwort ein neuer: Ein gleichbleibender Wert wäre über mehrere Aufrufe
	// erratbar und damit wirkungslos.
	zweite := get(t, s, "/v2/dateien", cookie)
	if zweite.Header().Get("Content-Security-Policy") == csp {
		t.Error("zwei Antworten tragen denselben Nonce")
	}
}
