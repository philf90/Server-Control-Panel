package httpd

// Tests für /api/v1/packages, /api/v1/jobs und den Neustart.
//
// Der Schwerpunkt liegt auf den Stellen, die bei einer lange laufenden Aktion
// wehtun: Antwortet der POST sofort oder wartet er auf apt? Läuft ein zweiter
// Vorgang los, während der erste noch arbeitet? Überlebt der Vorgang das Ende
// der Anfrage? Und stimmt die Stufe der Rückfrage — ein einzelnes Paket ohne,
// alle mit, der Neustart mit getipptem Hostnamen.

import (
	"encoding/json"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// Die Liste: Sicherheitsupdates oben, Zähler getrennt, Neustartmarkierung dabei.
func TestAPIPaketeSicherheitZuerst(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)
	ops.reboot = privops.RebootState{Required: true, Packages: []string{"linux-image-generic"}}

	var antwort apiPakete
	mussJSON(t, get(t, s, "/api/v1/packages", cookie), &antwort)

	if len(antwort.Pakete) != 2 {
		t.Fatalf("%d Pakete, erwartet 2", len(antwort.Pakete))
	}
	// libssl3 ist das Sicherheitsupdate, coreutils nicht — alphabetisch stünde
	// coreutils oben.
	if antwort.Pakete[0].Name != "libssl3" {
		t.Errorf("erstes Paket ist %q, erwartet libssl3 — Sicherheit gehört nach oben",
			antwort.Pakete[0].Name)
	}
	if antwort.Zaehler.Gesamt != 2 || antwort.Zaehler.Sicherheit != 1 {
		t.Errorf("Zähler = %+v, erwartet 2 gesamt / 1 Sicherheit", antwort.Zaehler)
	}
	if !antwort.Neustart.Erforderlich || len(antwort.Neustart.Pakete) != 1 {
		t.Errorf("Neustart = %+v, erwartet erforderlich wegen einem Paket", antwort.Neustart)
	}
	// Der Rechnername steht in der Antwort: Die Oberfläche kann ihn nicht erraten,
	// und die Rückfrage zum Neustart verlangt ihn.
	if antwort.Rechnername == "" {
		t.Error("der Rechnername fehlt — die Rückfrage zum Neustart braucht ihn")
	}
	// Noch kein Vorgang gelaufen: null und keine Attrappe.
	if antwort.Job != nil {
		t.Errorf("es steht ein Vorgang in der Antwort, obwohl keiner lief: %+v", antwort.Job)
	}
}

// Leeres Feld statt null, auch wenn nichts da ist: Die Oberfläche prüft die
// Länge, und ein fehlendes Array wäre eine zweite Sonderregel für denselben Fall.
func TestAPIPaketeLeereFelderSindFelder(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)
	ops.packages = nil
	ops.reboot = privops.RebootState{}

	rumpf := get(t, s, "/api/v1/packages", cookie).Body.String()
	// Beide Listen sind Felder: die Paketliste und die Pakete, die den Neustart
	// verlangen. Der Name ist zweimal derselbe, deshalb wird auf beide Stellen
	// geprüft, an denen er vorkommt.
	if n := strings.Count(rumpf, `"pakete":[]`); n != 2 {
		t.Errorf("%d leere Paketfelder, erwartet 2 (Liste und Neustart): %s", n, rumpf)
	}
	// `"job":null` ist dagegen richtig und ausdrücklich so gemeint: Es gibt
	// keinen Vorgang, und ein leeres Objekt wäre eine Erfindung — die Oberfläche
	// prüft auf null und zeigt dann keine Platte.
	if !strings.Contains(rumpf, `"job":null`) {
		t.Errorf("ohne Vorgang steht kein null im Feld job: %s", rumpf)
	}
}

// Der POST wartet nicht auf apt. Er antwortet mit 202 und dem frischen Vorgang.
func TestAPIPaketlistenHolenAntwortetSofort(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)

	rec := postJSON(t, s, "/api/v1/packages/refresh", "", cookie, csrf)
	if rec.Code != http.StatusAccepted {
		t.Fatalf("Status = %d, erwartet 202: %s", rec.Code, rec.Body.String())
	}

	var antwort apiVorgangGestartet
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	if antwort.Meldung == "" {
		t.Error("die Antwort sagt nicht, was angestoßen wurde")
	}
	if antwort.Job.Art != jobPackages || antwort.Job.Titel == "" {
		t.Errorf("Vorgang = %+v, erwartet Art %q mit Beschriftung", antwort.Job, jobPackages)
	}
	// Die Zeilen sind ein Feld, auch wenn noch keine da ist.
	if antwort.Job.Zeilen == nil {
		t.Error("die Zeilen sind null statt eines leeren Feldes")
	}

	// Der Vorgang läuft in einer eigenen Goroutine — abwarten, dass er wirklich
	// gelaufen ist, statt auf eine Dauer zu hoffen.
	<-ops.refreshDone
	if !enthaelt(ops.recorded(), "package:refresh") {
		t.Errorf("apt-get update lief nicht: %v", ops.recorded())
	}
}

// Zwei Paketvorgänge gleichzeitig gibt es nicht. Sie blockierten sich an der
// dpkg-Sperre, und das soll die Oberfläche verhindern statt ausprobieren.
func TestAPIZweiterPaketvorgangAbgewiesen(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)

	// Einen Vorgang von Hand anlegen und offen lassen — so, wie er es während
	// eines echten Laufs wäre.
	j, neu := s.jobs.start(jobPackages, "admin")
	if !neu {
		t.Fatal("der erste Vorgang ließ sich nicht anlegen")
	}
	defer j.finish(nil)

	for _, fall := range []struct{ pfad, koerper string }{
		{"/api/v1/packages/refresh", ""},
		{"/api/v1/packages/upgrade", `{"umfang":"einzeln","paket":"libssl3"}`},
	} {
		rec := postJSON(t, s, fall.pfad, fall.koerper, cookie, csrf)
		if rec.Code != http.StatusConflict {
			t.Errorf("%s: Status = %d, erwartet 409: %s", fall.pfad, rec.Code, rec.Body.String())
		}
	}
}

// Ein einzelnes Paket ist ein gezielter Klick in seiner Zeile — Stufe 1, keine
// Rückfrage. „Alle" und „nur Sicherheit" fragen, und die Frage nennt die Zahl:
// „Alle Updates einspielen?" befähigt zu keiner Entscheidung, „alle 2" schon.
func TestAPIEinspielenStufenUndZahlInDerFrage(t *testing.T) {
	t.Run("einzelnes Paket ohne Rückfrage", func(t *testing.T) {
		s, cookie, csrf := angemeldet(t, store.RoleAdmin)
		ops := s.ops.(*fakeOps)

		rec := postJSON(t, s, "/api/v1/packages/upgrade",
			`{"umfang":"einzeln","paket":"libssl3"}`, cookie, csrf)
		if rec.Code != http.StatusAccepted {
			t.Fatalf("Status = %d, erwartet 202: %s", rec.Code, rec.Body.String())
		}
		<-ops.upgradeDone
		if !enthaelt(ops.recorded(), "package:upgrade:libssl3") {
			t.Errorf("das Paket wurde nicht eingespielt: %v", ops.recorded())
		}
	})

	t.Run("alle mit Rückfrage und Zahl", func(t *testing.T) {
		s, cookie, csrf := angemeldet(t, store.RoleAdmin)
		ops := s.ops.(*fakeOps)

		rec := postJSON(t, s, "/api/v1/packages/upgrade", `{"umfang":"alle"}`, cookie, csrf)
		if rec.Code != http.StatusConflict {
			t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
		}
		if len(ops.recorded()) != 0 {
			t.Fatalf("es wurde ohne Bestätigung eingespielt: %v", ops.recorded())
		}

		var frage apiBestaetigungAntwort
		if err := json.Unmarshal(rec.Body.Bytes(), &frage); err != nil {
			t.Fatalf("Antwort ist kein JSON: %v", err)
		}
		if !strings.Contains(frage.Bestaetigung.Frage, "2") {
			t.Errorf("die Frage nennt die Zahl nicht: %q", frage.Bestaetigung.Frage)
		}
		if frage.Bestaetigung.Tippen != "" {
			t.Error("Updates einspielen verlangt ein getipptes Wort — es ist Stufe 2")
		}

		rec = postJSON(t, s, "/api/v1/packages/upgrade",
			`{"umfang":"alle","bestaetigt":true}`, cookie, csrf)
		if rec.Code != http.StatusAccepted {
			t.Fatalf("bestätigt: Status = %d, erwartet 202: %s", rec.Code, rec.Body.String())
		}
		<-ops.upgradeDone
		if !enthaelt(ops.recorded(), "package:upgrade:all") {
			t.Errorf("die bestätigte Aktion lief nicht: %v", ops.recorded())
		}
	})

	t.Run("nur Sicherheit zählt nur Sicherheitsupdates", func(t *testing.T) {
		s, cookie, csrf := angemeldet(t, store.RoleAdmin)

		rec := postJSON(t, s, "/api/v1/packages/upgrade", `{"umfang":"sicherheit"}`, cookie, csrf)
		var frage apiBestaetigungAntwort
		if err := json.Unmarshal(rec.Body.Bytes(), &frage); err != nil {
			t.Fatalf("Antwort ist kein JSON: %v", err)
		}
		// Die Attrappe hat zwei Pakete, davon eines aus einer Sicherheitsquelle.
		// Stünde hier 2, zählte die Frage die falsche Menge.
		if !strings.Contains(frage.Bestaetigung.Frage, "1 Sicherheitsupdate") {
			t.Errorf("die Frage lautet %q, erwartet die Zahl der Sicherheitsupdates (1)",
				frage.Bestaetigung.Frage)
		}
	})

	t.Run("unbekannter Umfang abgewiesen", func(t *testing.T) {
		s, cookie, csrf := angemeldet(t, store.RoleAdmin)
		ops := s.ops.(*fakeOps)

		rec := postJSON(t, s, "/api/v1/packages/upgrade", `{"umfang":"irgendwas"}`, cookie, csrf)
		if rec.Code != http.StatusBadRequest {
			t.Errorf("Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
		}
		if len(ops.recorded()) != 0 {
			t.Errorf("es wurde etwas ausgeführt: %v", ops.recorded())
		}
	})

	t.Run("einzeln ohne Paketnamen abgewiesen", func(t *testing.T) {
		s, cookie, csrf := angemeldet(t, store.RoleAdmin)
		rec := postJSON(t, s, "/api/v1/packages/upgrade", `{"umfang":"einzeln"}`, cookie, csrf)
		if rec.Code != http.StatusBadRequest {
			t.Errorf("Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
		}
	})
}

// Der Vorgang ist danach abfragbar, mit Zeilen, Dauer und Ergebnis. Das ist
// Grundsatz III: Wer nach einem Neuladen hereinkommt, findet ihn vor.
func TestAPIVorgangIstAbfragbar(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)

	// Vorher: noch kein Vorgang. 204 und nicht 404 — die Ressource gibt es, sie
	// ist nur leer.
	leer := get(t, s, "/api/v1/jobs/packages", cookie)
	if leer.Code != http.StatusNoContent {
		t.Errorf("ohne Vorgang: Status = %d, erwartet 204: %s", leer.Code, leer.Body.String())
	}

	postJSON(t, s, "/api/v1/packages/refresh", "", cookie, csrf)
	<-ops.refreshDone

	var job apiJob
	mussJSON(t, get(t, s, "/api/v1/jobs/packages", cookie), &job)

	if job.Akteur != "admin" {
		t.Errorf("Akteur = %q, erwartet admin — wer den Vorgang angestoßen hat, gehört dazu",
			job.Akteur)
	}
	if len(job.Zeilen) == 0 {
		t.Error("der Vorgang hat keine Zeilen — der Auszug ist der Kern der Quittung")
	}
	if job.DauerText == "" {
		t.Error("die Laufzeit fehlt")
	}
	if job.Gescheitert {
		t.Errorf("der Vorgang gilt als gescheitert: %q", job.Fehler)
	}
}

// Ein Teilerfolg von apt-get update ist kein Fehler, aber auch nicht Erfolg. Er
// wird als Hinweis geführt — verschwiegen wäre er eine Zusage, die niemand
// halten kann.
func TestAPIVorgangTeilerfolgTraegtHinweis(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)
	ops.refreshResult = privops.PackageRefreshResult{
		Reached: 2,
		Failed:  []privops.SourceFailure{{Source: "ppa.example.com", Reason: "403 Forbidden"}},
	}

	postJSON(t, s, "/api/v1/packages/refresh", "", cookie, csrf)
	<-ops.refreshDone

	var job apiJob
	mussJSON(t, get(t, s, "/api/v1/jobs/packages", cookie), &job)

	if job.Gescheitert {
		t.Error("ein Teilerfolg gilt als gescheitert — die übrigen Listen sind neu")
	}
	if job.Hinweis == "" {
		t.Fatal("der Teilerfolg wird verschwiegen")
	}
	if !strings.Contains(job.Hinweis, "ppa.example.com") {
		t.Errorf("der Hinweis nennt die klemmende Quelle nicht: %q", job.Hinweis)
	}
}

// Eine unbekannte Vorgangsart wird abgewiesen, statt „kein Vorgang" zu sagen.
func TestAPIUnbekannteVorgangsart(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)

	for _, pfad := range []string{"/api/v1/jobs/erfunden", "/api/v1/jobs/erfunden/events"} {
		rec := get(t, s, pfad, cookie)
		if rec.Code != http.StatusNotFound {
			t.Errorf("%s: Status = %d, erwartet 404", pfad, rec.Code)
		}
		if ct := rec.Header().Get("Content-Type"); !strings.HasPrefix(ct, "application/json") {
			t.Errorf("%s: Content-Type = %q, erwartet JSON", pfad, ct)
		}
	}
}

// Der Neustart ist Stufe 3, und das getippte Wort ist der Hostname: Wer zwei
// Server im Browser offen hat, startet so nicht den falschen neu.
func TestAPINeustartVerlangtDenHostnamen(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)
	ops := s.ops.(*fakeOps)

	rec := postJSON(t, s, "/api/v1/system/reboot", "", cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}

	var frage apiBestaetigungAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &frage); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	host := frage.Bestaetigung.Tippen
	if host == "" {
		t.Fatal("der Neustart verlangt kein getipptes Wort — er ist Stufe 3")
	}
	if len(ops.recorded()) != 0 {
		t.Fatalf("es wurde ohne Bestätigung neu gestartet: %v", ops.recorded())
	}

	// Ein falsches Wort führt zu nichts, und die Frage kommt mit dem Grund zurück.
	rec = postJSON(t, s, "/api/v1/system/reboot",
		`{"bestaetigt":true,"getippt":"falsch"}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Errorf("falsches Wort: Status = %d, erwartet 409", rec.Code)
	}
	if len(ops.recorded()) != 0 {
		t.Fatalf("mit falschem Wort wurde neu gestartet: %v", ops.recorded())
	}

	// Das richtige Wort in anderer Schreibung genügt: Auf einem Telefon macht die
	// Tastatur aus "vm" gern "Vm".
	rec = postJSON(t, s, "/api/v1/system/reboot",
		`{"bestaetigt":true,"getippt":"`+strings.ToUpper(host)+`"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("richtiges Wort: Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	if !enthaelt(ops.recorded(), "reboot") {
		t.Errorf("der Neustart lief nicht: %v", ops.recorded())
	}
}

// Der Neustart bleibt der Owner-Rolle vorbehalten — dieselbe Grenze wie in der
// alten Oberfläche, und die Antwort ist JSON mit dem richtigen Grund.
func TestAPINeustartNurOwner(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)

	rec := postJSON(t, s, "/api/v1/system/reboot",
		`{"bestaetigt":true,"getippt":"egal"}`, cookie, csrf)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("Status = %d, erwartet 403: %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "Owner") {
		t.Errorf("die Meldung nennt nicht die fehlende Rolle: %s", rec.Body.String())
	}
	if len(ops.recorded()) != 0 {
		t.Errorf("es wurde neu gestartet: %v", ops.recorded())
	}
}

// Ohne Schreibrecht läuft kein Paketvorgang, und die Antwort ist JSON.
func TestAPIPaketvorgangBrauchtSchreibrecht(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleReadOnly)
	ops := s.ops.(*fakeOps)

	for _, pfad := range []string{"/api/v1/packages/refresh", "/api/v1/packages/upgrade"} {
		rec := postJSON(t, s, pfad, `{"umfang":"alle"}`, cookie, csrf)
		if rec.Code != http.StatusForbidden {
			t.Errorf("%s: Status = %d, erwartet 403", pfad, rec.Code)
		}
	}
	// Lesen darf sie: Eine Rolle ohne Schreibrecht soll sehen, was aussteht.
	if rec := get(t, s, "/api/v1/packages", cookie); rec.Code != http.StatusOK {
		t.Errorf("Lesen: Status = %d, erwartet 200", rec.Code)
	}
	if len(ops.recorded()) != 0 {
		t.Errorf("es wurde etwas ausgeführt: %v", ops.recorded())
	}
}

// Die Laufzeit wird gelesen, wie man sie liest: Sekunden unter einer Minute,
// danach Minuten und Sekunden. „312 s" verlangt Kopfrechnen.
func TestDauerText(t *testing.T) {
	faelle := []struct {
		sek      int
		erwartet string
	}{
		{0, "0 s"},
		{7, "7 s"},
		{59, "59 s"},
		{60, "1 min 0 s"},
		{312, "5 min 12 s"},
		{3661, "61 min 1 s"},
	}
	for _, f := range faelle {
		if got := dauerText(time.Duration(f.sek) * time.Second); got != f.erwartet {
			t.Errorf("dauerText(%d s) = %q, erwartet %q", f.sek, got, f.erwartet)
		}
	}
	// Eine negative Dauer kommt vor, wenn die Uhr springt. Sie darf kein
	// Minuszeichen in der Oberfläche ergeben.
	if got := dauerText(-5 * time.Second); got != "0 s" {
		t.Errorf("dauerText(-5 s) = %q, erwartet 0 s", got)
	}
}

// Der Zustand eines Vorgangs wird unter einem Schloss gelesen. Einzelne
// Lesemethoden hintereinander ergäben ein Bild aus zwei Augenblicken: Zwischen
// „fertig" und „Zeilen" kann der Vorgang enden.
func TestJobStandIstEinAugenblick(t *testing.T) {
	j := newJob("test", "admin")
	j.append("erste")
	j.append("zweite")
	j.setNote("Anmerkung")

	st := j.stand()
	if st.Fertig {
		t.Error("ein laufender Vorgang gilt als fertig")
	}
	if len(st.Zeilen) != 2 || st.Hinweis != "Anmerkung" || st.Akteur != "admin" {
		t.Errorf("Zustand = %+v", st)
	}
	// Die Laufzeit eines laufenden Vorgangs zählt bis jetzt, die eines beendeten
	// bleibt stehen. Ohne die Unterscheidung wüchse die Angabe eines vor Stunden
	// beendeten Laufs immer weiter.
	if st.Laufzeit < 0 {
		t.Errorf("Laufzeit = %v, erwartet nicht negativ", st.Laufzeit)
	}

	j.finish(nil)
	nachher := j.stand()
	if !nachher.Fertig {
		t.Error("ein beendeter Vorgang gilt als laufend")
	}
	ruhe := j.stand()
	if ruhe.Laufzeit != nachher.Laufzeit {
		t.Errorf("die Laufzeit eines beendeten Vorgangs wächst weiter: %v → %v",
			nachher.Laufzeit, ruhe.Laufzeit)
	}

	// Die Zeilen sind eine Kopie: Wer sie ändert, ändert nicht den Vorgang.
	nachher.Zeilen[0] = "verändert"
	if j.stand().Zeilen[0] != "erste" {
		t.Error("stand() gibt die inneren Zeilen heraus statt einer Kopie")
	}
}

// Der Ereignisstrom liefert die bisherigen Zeilen, die folgenden, und am Ende
// ein Ereignis dafür.
//
// Am Endpunkt geprüft und nicht im Browser: Dort ist die Attrappe in
// Millisekunden fertig, und die Zeilen stünden auch dann auf der Seite, wenn sie
// nur die Ressource abgefragt hätte. Hier wird der Vorgang absichtlich offen
// gehalten — das ist der Fall, für den der Strom gebaut ist.
func TestAPIVorgangStromLiefertZeilenUndEnde(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)

	j, neu := s.jobs.start(jobPackages, "admin")
	if !neu {
		t.Fatal("der Vorgang ließ sich nicht anlegen")
	}
	j.append("erste Zeile")

	// Eine Zeile nachschieben und den Vorgang beenden, während der Strom offen
	// ist. Über eine Goroutine, weil der Abruf bis zum Ende blockiert — genau
	// das ist der Sinn eines Stroms.
	go func() {
		// Kurz warten, damit der Abonnent steht. Ohne das könnte die Zeile vor
		// dem subscribe ankommen — sie stünde dann im Rückblick und nicht als
		// Ereignis, und der Test prüfte nicht, was er prüfen soll.
		time.Sleep(150 * time.Millisecond)
		j.append("zweite Zeile")
		time.Sleep(50 * time.Millisecond)
		j.finish(nil)
	}()

	rec := stream(t, s, "/api/v1/jobs/packages/events", cookie, 5*time.Second)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}
	if ct := rec.Header().Get("Content-Type"); !strings.HasPrefix(ct, "text/event-stream") {
		t.Errorf("Content-Type = %q, erwartet text/event-stream", ct)
	}

	rumpf := rec.Body.String()
	// Die Zeile von VOR dem Anhängen ist dabei: Wer später dazukommt, sieht den
	// ganzen Lauf und nicht nur den Rest.
	if !strings.Contains(rumpf, "erste Zeile") {
		t.Errorf("die bisherige Ausgabe fehlt im Strom: %q", rumpf)
	}
	if !strings.Contains(rumpf, "zweite Zeile") {
		t.Errorf("die nachgeschobene Zeile kam nicht als Ereignis: %q", rumpf)
	}
	if !strings.Contains(rumpf, "event: end") {
		t.Errorf("das Ende wird nicht gemeldet: %q", rumpf)
	}
}

// Ein Strom auf eine bekannte Art ohne Vorgang ist ein 404 mit JSON — und nicht
// eine Textzeile, an der ein fetch scheitert.
func TestAPIVorgangStromOhneVorgang(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)

	rec := get(t, s, "/api/v1/jobs/packages/events", cookie)
	if rec.Code != http.StatusNotFound {
		t.Errorf("Status = %d, erwartet 404", rec.Code)
	}
	if ct := rec.Header().Get("Content-Type"); !strings.HasPrefix(ct, "application/json") {
		t.Errorf("Content-Type = %q, erwartet JSON", ct)
	}
}
