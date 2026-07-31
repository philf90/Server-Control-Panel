package httpd

// Tests für /api/v1/schedules.
//
// Dieses Modul öffnet die einzige Fläche des Panels, an der ein FREIER Befehl
// entsteht — ein Cron-Eintrag ist eine Shell-Zeile, cron gibt sie an /bin/sh.
// Geprüft wird deshalb weniger die Darstellung als die Schranken davor:
//
//  1. **Lesen darf jede Rolle, Schreiben nur der Owner.** Wer einen Eintrag
//     anlegen darf, führt Code als den eingetragenen Benutzer aus.
//  2. **Ein Eintrag als root fragt mit dem Hostnamen zurück** (Stufe 3), einer
//     als anderer Benutzer mit einem zweiten Klick (Stufe 2), das Abschalten
//     nicht (Stufe 1).
//  3. **Die Rückfrage nennt Zeit, Benutzer und Befehl** — alle drei, weil alle
//     drei Fehler vorkommen.
//  4. **Geprüft wird vor der Rückfrage**, nicht danach: Sonst tippt jemand den
//     Hostnamen für einen Zeitplan, den der Server gleich abweist.
//  5. **Der Befehl steht im Protokoll.** Ein Protokoll, das den Zeitplan nennt
//     und den Befehl weglässt, beantwortet die Frage nicht, für die man es
//     aufschlägt.
//  6. **Lücken werden genannt.** Eine unvollständige Liste als vollständig
//     auszugeben bricht Grundsatz IV.

import (
	"encoding/json"
	"errors"
	"net/http"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

func holeZeitplaene(t *testing.T, s *Server, cookie *http.Cookie) apiZeitplaene {
	t.Helper()
	rec := get(t, s, "/api/v1/schedules", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var a apiZeitplaene
	if err := json.Unmarshal(rec.Body.Bytes(), &a); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	return a
}

// zeitplanOps holt die Attrappe aus dem Server, um Vorgaben zu setzen und
// Aufrufe zu lesen.
func zeitplanOps(t *testing.T, s *Server) *fakeOps {
	t.Helper()
	ops, ok := s.ops.(*fakeOps)
	if !ok {
		t.Fatal("der Testserver trägt keine Attrappe")
	}
	return ops
}

func TestAPIZeitplaeneLesen(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleReadOnly)

	a := holeZeitplaene(t, s, cookie)

	if len(a.Cron) != 4 {
		t.Fatalf("%d Cron-Einträge, erwartet 4", len(a.Cron))
	}
	if len(a.Timer) != 2 {
		t.Fatalf("%d Timer, erwartet 2", len(a.Timer))
	}
	if a.TimerFehler != "" {
		t.Errorf("TimerFehler = %q", a.TimerFehler)
	}

	// Der Zeitplan steht roh UND in Worten da. Der Satz ist die Lesehilfe; ein
	// falsch gelesener Zeitplan ist der häufigste Grund, warum jemand einen
	// Eintrag für kaputt hält.
	erster := a.Cron[0]
	if erster.Schedule == "" || erster.ScheduleText == "" {
		t.Errorf("Zeitplan = %q, Satz = %q — beide gehören in die Antwort",
			erster.Schedule, erster.ScheduleText)
	}
	// Die Quelle ist immer genannt: Sie ist der Weg, den Eintrag von Hand zu
	// ändern, und das Panel versteckt nicht, woher es etwas weiß.
	for _, e := range a.Cron {
		if e.Quelle == "" {
			t.Errorf("Eintrag ohne Quelle: %+v", e)
		}
	}

	// Die Stufe wird vom Server gerechnet, damit die Oberfläche sie nicht ein
	// zweites Mal rechnen muss — zwei Rechnungen derselben Sicherheitsregel
	// laufen auseinander.
	for _, e := range a.Cron {
		var erwartet int
		switch {
		case !e.Verwaltet:
			erwartet = 0
		case e.User == "root":
			erwartet = 3
		default:
			erwartet = 2
		}
		if e.Stufe != erwartet {
			t.Errorf("%s (%s, verwaltet=%t): Stufe = %d, erwartet %d",
				e.Name, e.User, e.Verwaltet, e.Stufe, erwartet)
		}
	}

	// Ein Timer ohne nächsten Lauf hat keinen Zeitpunkt, und ein Datum dafür wäre
	// eine Behauptung.
	if a.Timer[1].Naechster != "" {
		t.Errorf("der abgeschaltete Timer hat einen nächsten Lauf: %q", a.Timer[1].Naechster)
	}
	if a.Timer[0].Naechster == "" {
		t.Error("dem laufenden Timer fehlt der nächste Lauf")
	}

	// Der Rahmen: Ohne Benutzerliste und Vorlagen wäre das Formular fünf leere
	// Felder.
	if len(a.Kennen.Benutzer) == 0 {
		t.Error("die Benutzerliste ist leer")
	}
	if len(a.Kennen.Vorlagen) == 0 {
		t.Error("es gibt keine Vorlagen")
	}
	for _, v := range a.Kennen.Vorlagen {
		if v.Text == "" {
			t.Errorf("Vorlage %q ohne Satz", v.Schedule)
		}
		if err := privops.ValidateSchedule(v.Schedule); err != nil {
			t.Errorf("Vorlage %q besteht die eigene Prüfung nicht: %v", v.Schedule, err)
		}
	}
	if a.Kennen.Verzeichnis == "" {
		t.Error("das Verzeichnis der verwalteten Dateien wird nicht genannt")
	}
	// Leserecht heißt: keine Knöpfe. Ein Knopf, der zuverlässig mit 403
	// antwortet, ist schlimmer als keiner.
	if a.Kennen.DarfAendern {
		t.Error("DarfAendern = true für ein Konto mit Leserecht")
	}
}

// TestAPIZeitplaeneLueckenWerdenGenannt: Eine Quelle, die sich nicht lesen ließ,
// gehört in die Antwort. Eine unvollständige Liste als vollständig auszugeben
// bricht Grundsatz IV — das Panel versteckt nichts, auch nicht sein eigenes
// Unwissen.
func TestAPIZeitplaeneLueckenWerdenGenannt(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)
	ops := zeitplanOps(t, s)
	ops.cronLuecken = []string{"/var/spool/cron/crontabs: permission denied"}

	a := holeZeitplaene(t, s, cookie)
	if len(a.Luecken) != 1 || !strings.Contains(a.Luecken[0], "permission denied") {
		t.Errorf("Luecken = %v", a.Luecken)
	}
	// Und die Einträge stehen trotzdem da.
	if len(a.Cron) == 0 {
		t.Error("die Liste wurde wegen einer Lücke verworfen")
	}
}

// TestAPIZeitplaeneOhneSystemd: Auf einem System ohne systemd bleibt die
// Cron-Hälfte interessant. Ein Fehlerstatus für die ganze Antwort wäre eine
// leere Seite, obwohl die Hälfte der Auskunft vorliegt.
func TestAPIZeitplaeneOhneSystemd(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)
	ops := zeitplanOps(t, s)
	ops.timerErr = errors.New("systemctl nicht vorhanden")

	a := holeZeitplaene(t, s, cookie)
	if a.TimerFehler == "" {
		t.Error("der Timer-Fehler wird verschwiegen")
	}
	if len(a.Cron) == 0 {
		t.Error("die Cron-Einträge fehlen, obwohl nur systemd fehlte")
	}
	if a.Timer == nil {
		t.Error("Timer = null — die Oberfläche unterscheidet nicht zwischen null und leer")
	}
}

// ----------------------------------------------------------- Rückfragen ---

// TestAPICronRootFragtMitHostname ist die Kernentscheidung dieses Moduls: Ein
// Eintrag als root ist umkehrbar, seine Folgen sind es nicht, und er läuft
// unbeaufsichtigt. Deshalb Stufe 3 mit dem Hostnamen — wer zwei Server offen hat,
// legt so keinen Nachtlauf auf dem falschen an.
func TestAPICronRootFragtMitHostname(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)
	ops := zeitplanOps(t, s)

	koerper := `{"name":"sicherung","schedule":"17 3 * * *","user":"root",` +
		`"command":"/usr/local/bin/sicherung.sh","kommentar":"Nachtsicherung","aktiv":true}`
	rec := postJSON(t, s, "/api/v1/schedules/cron", koerper, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}

	var antwort apiBestaetigungAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	host := s.rechnername()
	if antwort.Bestaetigung.Tippen != host {
		t.Errorf("Tippen = %q, erwartet den Hostnamen %q", antwort.Bestaetigung.Tippen, host)
	}

	// Der Text nennt Zeit, Benutzer und Befehl. Alle drei, weil alle drei Fehler
	// vorkommen — der Zeitplan falsch gelesen, der Benutzer falsch gewählt, der
	// Befehl mit einem Tippfehler.
	ganz := antwort.Bestaetigung.Frage + " " + strings.Join(antwort.Bestaetigung.Punkte, " ")
	for _, teil := range []string{"03:17", "root", "/usr/local/bin/sicherung.sh"} {
		if !strings.Contains(ganz, teil) {
			t.Errorf("die Rückfrage nennt %q nicht:\n%s", teil, ganz)
		}
	}

	// Und nichts wurde geschrieben.
	if len(ops.cronSpecs) != 0 {
		t.Errorf("der Eintrag wurde ohne Bestätigung geschrieben: %+v", ops.cronSpecs)
	}

	// Ein falsch getipptes Wort führt nicht aus.
	falsch := `{"name":"sicherung","schedule":"17 3 * * *","user":"root",` +
		`"command":"/usr/local/bin/sicherung.sh","aktiv":true,` +
		`"bestaetigt":true,"getippt":"irgendwas"}`
	rec = postJSON(t, s, "/api/v1/schedules/cron", falsch, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Errorf("falsch getippt: Status = %d, erwartet 409", rec.Code)
	}
	if len(ops.cronSpecs) != 0 {
		t.Errorf("der Eintrag wurde nach falscher Eingabe geschrieben: %+v", ops.cronSpecs)
	}

	// Mit dem richtigen Hostnamen läuft es.
	richtig := `{"name":"sicherung","schedule":"17 3 * * *","user":"root",` +
		`"command":"/usr/local/bin/sicherung.sh","kommentar":"Nachtsicherung",` +
		`"aktiv":true,"bestaetigt":true,"getippt":"` + host + `"}`
	rec = postJSON(t, s, "/api/v1/schedules/cron", richtig, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	spec := ops.letzteCronSpec(t)
	if spec.Name != "sicherung" || spec.User != "root" || spec.Schedule != "17 3 * * *" {
		t.Errorf("Vorgabe = %+v", spec)
	}
	if spec.Command != "/usr/local/bin/sicherung.sh" {
		t.Errorf("Befehl = %q", spec.Command)
	}
	if spec.Kommentar != "Nachtsicherung" {
		t.Errorf("Kommentar = %q — die Beschreibung wurde nicht durchgereicht", spec.Kommentar)
	}
	if !spec.Aktiv {
		t.Error("Aktiv = false, obwohl ein aktiver Eintrag verlangt war")
	}
}

// TestAPICronAndererBenutzerNurStufeZwei: Die Folgen bleiben in dem, was dieser
// Benutzer erreicht. Ein getipptes Wort wäre eine Hürde ohne zusätzlichen
// Schutz — und Hürden ohne Schutz lehrt man sich, wegzuklicken.
func TestAPICronAndererBenutzerNurStufeZwei(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)
	ops := zeitplanOps(t, s)

	koerper := `{"name":"bericht","schedule":"0 6 * * 1","user":"philipp",` +
		`"command":"/home/philipp/bericht.sh","aktiv":true}`
	rec := postJSON(t, s, "/api/v1/schedules/cron", koerper, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	var antwort apiBestaetigungAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatal(err)
	}
	if antwort.Bestaetigung.Tippen != "" {
		t.Errorf("Tippen = %q — für einen Eintrag als anderer Benutzer genügt Stufe 2",
			antwort.Bestaetigung.Tippen)
	}

	// Ein zweiter Klick genügt: bestaetigt ohne getippt.
	rec = postJSON(t, s, "/api/v1/schedules/cron",
		`{"name":"bericht","schedule":"0 6 * * 1","user":"philipp",`+
			`"command":"/home/philipp/bericht.sh","aktiv":true,"bestaetigt":true}`,
		cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	if ops.letzteCronSpec(t).User != "philipp" {
		t.Errorf("Benutzer = %q", ops.letzteCronSpec(t).User)
	}
}

// TestAPICronAbgeschaltetFragtNicht: Ein abgeschalteter Eintrag läuft nicht.
// Nach ihm zu fragen wäre eine Rückfrage ohne Anlass — Stufe 1 nach
// docs/14-bestaetigungen.md.
func TestAPICronAbgeschaltetFragtNicht(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)
	ops := zeitplanOps(t, s)

	rec := postJSON(t, s, "/api/v1/schedules/cron",
		`{"name":"sicherung","schedule":"17 3 * * *","user":"root",`+
			`"command":"/usr/local/bin/sicherung.sh","aktiv":false}`,
		cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	spec := ops.letzteCronSpec(t)
	if spec.Aktiv {
		t.Error("Aktiv = true, obwohl abgeschaltet verlangt war")
	}
	var antwort apiZeitplanAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(antwort.Meldung, "abgeschaltet") {
		t.Errorf("die Meldung sagt nicht, dass der Eintrag abgeschaltet ist: %q", antwort.Meldung)
	}
}

// TestAPICronPruefungVorRueckfrage: Erst prüfen, dann fragen. Andernfalls tippt
// jemand den Hostnamen für einen Zeitplan, den der Server gleich darauf abweist —
// und hält die Abweisung für die Folge seiner Bestätigung.
func TestAPICronPruefungVorRueckfrage(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)
	ops := zeitplanOps(t, s)

	faelle := map[string]string{
		"Zeitplan mit vier Feldern": `{"name":"test","schedule":"17 3 * *","user":"root","command":"/usr/bin/true","aktiv":true}`,
		"Minute 60":                 `{"name":"test","schedule":"60 3 * * *","user":"root","command":"/usr/bin/true","aktiv":true}`,
		"Name mit Punkt":            `{"name":"test.sh","schedule":"17 3 * * *","user":"root","command":"/usr/bin/true","aktiv":true}`,
		"Zeilenumbruch im Befehl":   `{"name":"test","schedule":"17 3 * * *","user":"root","command":"/usr/bin/true\n0 2 * * * root /bin/sh","aktiv":true}`,
		"leerer Befehl":             `{"name":"test","schedule":"17 3 * * *","user":"root","command":"  ","aktiv":true}`,
		"unmaskiertes Prozent":      `{"name":"test","schedule":"17 3 * * *","user":"root","command":"/usr/bin/date +%F","aktiv":true}`,
		"Zeilenumbruch im Kommentar": `{"name":"test","schedule":"17 3 * * *","user":"root","command":"/usr/bin/true",` +
			`"kommentar":"harmlos\n0 2 * * * root /bin/sh","aktiv":true}`,
	}
	for name, koerper := range faelle {
		rec := postJSON(t, s, "/api/v1/schedules/cron", koerper, cookie, csrf)
		if rec.Code != http.StatusBadRequest {
			t.Errorf("%s: Status = %d, erwartet 400: %s", name, rec.Code, rec.Body.String())
		}
	}
	if len(ops.cronSpecs) != 0 {
		t.Errorf("%d Einträge geschrieben trotz ausschließlich fehlerhafter Vorgaben", len(ops.cronSpecs))
	}
}

// ------------------------------------------------------------- Rechte ---

// TestAPIZeitplaeneRechte: Lesen darf jede Rolle, Schreiben nur der Owner. Ein
// Cron-Eintrag ist eine Shell-Zeile — wer einen anlegen darf, führt Code als den
// eingetragenen Benutzer aus, und für root heißt das: vollen Zugriff.
func TestAPIZeitplaeneRechte(t *testing.T) {
	for _, rolle := range []string{store.RoleReadOnly, store.RoleAdmin} {
		s, cookie, csrf := angemeldet(t, rolle)
		ops := zeitplanOps(t, s)

		// Lesen geht.
		if rec := get(t, s, "/api/v1/schedules", cookie); rec.Code != http.StatusOK {
			t.Errorf("%s: Lesen = %d, erwartet 200", rolle, rec.Code)
		}

		// Schreiben nicht.
		rec := postJSON(t, s, "/api/v1/schedules/cron",
			`{"name":"test","schedule":"17 3 * * *","user":"root","command":"/usr/bin/true","aktiv":false}`,
			cookie, csrf)
		if rec.Code != http.StatusForbidden {
			t.Errorf("%s: Anlegen = %d, erwartet 403: %s", rolle, rec.Code, rec.Body.String())
		}
		rec = postJSON(t, s, "/api/v1/schedules/cron/test/delete", `{"bestaetigt":true}`, cookie, csrf)
		if rec.Code != http.StatusForbidden {
			t.Errorf("%s: Löschen = %d, erwartet 403", rolle, rec.Code)
		}
		if len(ops.cronSpecs) != 0 || len(ops.recorded()) != 0 {
			t.Errorf("%s: privops wurde gerufen: %v %+v", rolle, ops.recorded(), ops.cronSpecs)
		}
	}
}

// TestAPIZeitplaeneOhneToken: Dieselbe Schranke wie überall. Der Owner-Test steht
// zuerst in der Kette, damit der Grund der richtige ist — wer nicht Owner ist,
// soll das erfahren und nicht „Token fehlt".
func TestAPIZeitplaeneOhneToken(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleOwner)
	ops := zeitplanOps(t, s)

	rec := postJSON(t, s, "/api/v1/schedules/cron",
		`{"name":"test","schedule":"17 3 * * *","user":"root","command":"/usr/bin/true","aktiv":false}`,
		cookie, "")
	if rec.Code != http.StatusForbidden {
		t.Errorf("Status = %d, erwartet 403", rec.Code)
	}
	if len(ops.cronSpecs) != 0 {
		t.Error("ohne Token wurde geschrieben")
	}
}

// ------------------------------------------------------------- Löschen ---

// TestAPICronLoeschen: Stufe 2, auch für einen root-Eintrag. Löschen macht das
// System nicht unsicherer und schließt niemanden aus. Was verloren geht, ist der
// Text — deshalb nennt der Dialog das Abschalten als das, was man vermutlich
// meint.
func TestAPICronLoeschen(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)
	ops := zeitplanOps(t, s)

	rec := postJSON(t, s, "/api/v1/schedules/cron/sicherung/delete", `{}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	var antwort apiBestaetigungAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatal(err)
	}
	if antwort.Bestaetigung.Tippen != "" {
		t.Errorf("Tippen = %q — Löschen ist Stufe 2", antwort.Bestaetigung.Tippen)
	}
	if !strings.Contains(strings.Join(antwort.Bestaetigung.Punkte, " "), "schalten Sie ihn ab") {
		t.Errorf("der Dialog nennt das Abschalten nicht: %v", antwort.Bestaetigung.Punkte)
	}
	for _, aktion := range ops.recorded() {
		if strings.HasPrefix(aktion, "cron:delete") {
			t.Error("ohne Bestätigung wurde gelöscht")
		}
	}

	rec = postJSON(t, s, "/api/v1/schedules/cron/sicherung/delete", `{"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	var gefunden bool
	for _, aktion := range ops.recorded() {
		if aktion == "cron:delete:sicherung" {
			gefunden = true
		}
	}
	if !gefunden {
		t.Errorf("CronDelete wurde nicht gerufen: %v", ops.recorded())
	}
}

// TestAPICronLoeschenPrueftDenNamen: Der Name wird zum Dateipfad. Ein Name mit
// Pfadanteil darf nicht bis privops kommen — dort ist filepath.Base der zweite
// Riegel, aber der erste gehört hierhin, damit die Meldung die richtige ist.
func TestAPICronLoeschenPrueftDenNamen(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)
	ops := zeitplanOps(t, s)

	// Ein Name mit Schrägstrich passt nicht auf das Routenmuster und ergibt 404;
	// ein Punkt kommt durch und muss hier abgewiesen werden.
	rec := postJSON(t, s, "/api/v1/schedules/cron/boes.datei/delete", `{"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
	}
	for _, aktion := range ops.recorded() {
		if strings.HasPrefix(aktion, "cron:delete") {
			t.Errorf("privops wurde mit einem unzulässigen Namen gerufen: %v", ops.recorded())
		}
	}
}

// ------------------------------------------------------------ Protokoll ---

// TestAPICronProtokolliertDenBefehl: Der ganze Befehl steht im Audit-Protokoll.
// Absicht und nicht Versehen — er ist die Antwort auf „was lief da", und ein
// Protokoll, das den Zeitplan nennt und den Befehl weglässt, beantwortet genau
// die Frage nicht, für die man es aufschlägt.
func TestAPICronProtokolliertDenBefehl(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)

	rec := postJSON(t, s, "/api/v1/schedules/cron",
		`{"name":"sicherung","schedule":"17 3 * * *","user":"philipp",`+
			`"command":"/usr/local/bin/sicherung.sh --ziel /srv","aktiv":true,"bestaetigt":true}`,
		cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}

	eintraege, err := s.db.ListAudit(t.Context(), 20)
	if err != nil {
		t.Fatal(err)
	}
	var gefunden bool
	for _, e := range eintraege {
		if e.Action != "cron.write" {
			continue
		}
		gefunden = true
		if e.Target != "sicherung" {
			t.Errorf("Ziel = %q", e.Target)
		}
		for _, teil := range []string{"user=philipp", "schedule=17 3 * * *", "sicherung.sh --ziel /srv"} {
			if !strings.Contains(e.Detail, teil) {
				t.Errorf("dem Protokolleintrag fehlt %q: %q", teil, e.Detail)
			}
		}
	}
	if !gefunden {
		t.Error("kein Protokolleintrag cron.write")
	}
}

// ---------------------------------------------------------- Letzter Lauf ---

// TestAPITimerLauf: Gefragt wird nach dem DIENST, den der Timer auslöst. Der
// Timer glückt immer, sobald er auslöst — was schiefgehen kann, geht im Dienst
// schief.
func TestAPITimerLauf(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleReadOnly)
	ops := zeitplanOps(t, s)
	ops.timerLauf = privops.TimerLauf{
		Unit: "apt-daily.service", Ergebnis: "exit-code", ExitCode: 2,
		Geglueckt: false, Zeilen: []privops.LogEntry{},
	}

	rec := get(t, s, "/api/v1/schedules/timers/apt-daily.service/run", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var lauf apiTimerLauf
	if err := json.Unmarshal(rec.Body.Bytes(), &lauf); err != nil {
		t.Fatal(err)
	}
	if lauf.ExitCode != 2 || lauf.Geglueckt {
		t.Errorf("Lauf = %+v", lauf)
	}
	if lauf.Zeilen == nil {
		t.Error("Zeilen = null statt einer leeren Liste")
	}

	// Ein unzulässiger Unitname kommt nicht bis privops.
	rec = get(t, s, "/api/v1/schedules/timers/a%20b.service/run", cookie)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("unzulässiger Unitname: Status = %d, erwartet 400", rec.Code)
	}
}
