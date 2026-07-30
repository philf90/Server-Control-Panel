package httpd

// Tests für /api/v1/update.
//
// Fünf Stellen sind hier prüfenswert, und dieses Modul ist das gefährlichste des
// Panels:
//
//  1. **Nur Owner löst aus.** Das Update tauscht das Programm aus, das alle
//     anderen Rechte durchsetzt. Die Prüfung ändert nichts und darf offener sein.
//  2. **Ohne Prüfung wird nichts eingespielt**, und eine ältere Fassung nie.
//  3. **Installiert wird, was der Auslöser gesehen hat.** Die Fassung geht
//     ausdrücklich mit.
//  4. **Ein zweiter Anstoß wird abgewiesen**, solange der erste läuft.
//  5. **Eine unerreichbare Metadatenquelle ist eine Auskunft, kein Fehler.** Sie
//     antwortet 200 mit dem Grund darin — ein Fehlerstatus machte aus einer
//     Auskunft eine rote Zeile.

import (
	"encoding/json"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/store"
	"github.com/philf90/asylum/internal/version"
)

func holeUpdate(t *testing.T, s *Server, cookie *http.Cookie) apiUpdate {
	t.Helper()
	rec := get(t, s, "/api/v1/update", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var a apiUpdate
	if err := json.Unmarshal(rec.Body.Bytes(), &a); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	return a
}

func updateAntwortVon(t *testing.T, roh []byte) apiUpdateAntwort {
	t.Helper()
	var a apiUpdateAntwort
	if err := json.Unmarshal(roh, &a); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v (%s)", err, roh)
	}
	return a
}

func TestAPIUpdateZustand(t *testing.T) {
	s, _ := updateServer(t, testChannelJSON)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	a := holeUpdate(t, s, cookie)
	if a.Fassung != version.Version {
		t.Errorf("Fassung = %q, erwartet %q", a.Fassung, version.Version)
	}
	if a.Kanal != "stable" {
		t.Errorf("Kanal = %q", a.Kanal)
	}
	if a.Quelle == "" {
		t.Error("die Quelle der Metadaten wird nicht genannt — das Panel versteckt nichts")
	}
	// Vor der ersten Prüfung ist nichts geprüft. Das ist ein Zustand und kein
	// „kein Update verfügbar".
	if a.GeprueftAm != "" || a.Verfuegbar != "" {
		t.Errorf("vor der Prüfung steht schon ein Ergebnis da: %+v", a)
	}
	if a.UpdateDa {
		t.Error("vor der Prüfung wird ein Update gemeldet")
	}
	if !a.DarfAusloesen {
		t.Error("die Owner-Rolle darf nicht auslösen")
	}
	if a.RueckwegMoeglich {
		t.Error("ohne Sicherung wird ein Rückweg angeboten — der Knopf liefe ins Leere")
	}
}

// Nur Owner löst aus. Prüfen darf jede schreibberechtigte Rolle, weil es nichts
// ändert; lesen darf jede.
func TestAPIUpdateRollentrennung(t *testing.T) {
	s, ops := updateServer(t, testChannelJSON)
	admin := addUser(t, s, "admin2", store.RoleAdmin)
	leser := addUser(t, s, "leser", store.RoleReadOnly)
	adminCookie, adminCSRF := login(t, s, admin)
	leserCookie, leserCSRF := login(t, s, leser)

	// Lesen: beide.
	for was, c := range map[string]*http.Cookie{"admin": adminCookie, "leser": leserCookie} {
		if rec := get(t, s, "/api/v1/update", c); rec.Code != http.StatusOK {
			t.Errorf("%s lesend = %d, erwartet 200", was, rec.Code)
		}
		if rec := get(t, s, "/api/v1/update/status", c); rec.Code != http.StatusOK {
			t.Errorf("%s Stand = %d, erwartet 200", was, rec.Code)
		}
	}
	// Der Leser sieht, dass er nicht auslösen darf.
	if holeUpdate(t, s, leserCookie).DarfAusloesen {
		t.Error("die Leserolle darf laut Antwort auslösen")
	}

	// Prüfen: Admin ja, Leser nein.
	if rec := postJSON(t, s, "/api/v1/update/check", `{}`, adminCookie, adminCSRF); rec.Code != http.StatusOK {
		t.Errorf("Prüfen als admin = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	if rec := postJSON(t, s, "/api/v1/update/check", `{}`, leserCookie, leserCSRF); rec.Code != http.StatusForbidden {
		t.Errorf("Prüfen als leser = %d, erwartet 403", rec.Code)
	}

	// Einspielen und Rückweg: nur Owner.
	for _, weg := range []string{"/api/v1/update/apply", "/api/v1/update/rollback"} {
		rec := postJSON(t, s, weg, `{"bestaetigt":true}`, adminCookie, adminCSRF)
		if rec.Code != http.StatusForbidden {
			t.Errorf("%s als admin = %d, erwartet 403: %s", weg, rec.Code, rec.Body.String())
		}
		if !strings.Contains(rec.Body.String(), "Owner") {
			t.Errorf("%s: der Fehlertext nennt die Rolle nicht: %s", weg, rec.Body.String())
		}
	}
	if len(ops.selfUpdates) != 0 {
		t.Errorf("%d Vorgänge gestartet, erwartet 0", len(ops.selfUpdates))
	}
}

// Der ganze Weg: prüfen, Rückfrage, einspielen. Und dazwischen die Ablehnungen.
func TestAPIUpdateEinspielen(t *testing.T) {
	s, ops := updateServer(t, testChannelJSON)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	// Ohne Prüfung gibt es nichts einzuspielen — auch mit Bestätigung nicht.
	rec := postJSON(t, s, "/api/v1/update/apply", `{"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("ohne Prüfung = %d, erwartet 400: %s", rec.Code, rec.Body.String())
	}
	if len(ops.selfUpdates) != 0 {
		t.Fatal("es wurde ohne Prüfung ein Vorgang gestartet")
	}

	// Prüfen.
	rec = postJSON(t, s, "/api/v1/update/check", `{}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Prüfen = %d: %s", rec.Code, rec.Body.String())
	}
	a := updateAntwortVon(t, rec.Body.Bytes())
	if !strings.Contains(a.Meldung, "9.9.9") {
		t.Errorf("die gefundene Fassung steht nicht in der Meldung: %q", a.Meldung)
	}
	if a.Update == nil || !a.Update.UpdateDa {
		t.Fatalf("Update = %+v, erwartet ein verfügbares", a.Update)
	}
	if a.Update.Verfuegbar != "9.9.9" {
		t.Errorf("Verfuegbar = %q", a.Update.Verfuegbar)
	}
	if a.Update.Dringlichkeit != "security" {
		t.Errorf("Dringlichkeit = %q, erwartet security", a.Update.Dringlichkeit)
	}
	if a.Update.GeprueftAm == "" {
		t.Error("der Zeitpunkt der Prüfung fehlt")
	}
	if a.Update.Notizen == "" {
		t.Error("der Verweis auf die Notizen fehlt")
	}

	// Ohne Bestätigung: 409 mit der Frage.
	rec = postJSON(t, s, "/api/v1/update/apply", `{}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("ohne Bestätigung = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	frage := frageVon(t, rec.Body.Bytes())
	for _, wort := range []string{version.Version, "9.9.9"} {
		if !strings.Contains(frage.Frage, wort) {
			t.Errorf("die Frage nennt %q nicht: %q", wort, frage.Frage)
		}
	}
	// Die Frage nennt die zwei Folgen, die zählen: Neustart und Rückweg.
	neustart, rueckweg := false, false
	for _, p := range frage.Punkte {
		if strings.Contains(p, "startet dabei neu") {
			neustart = true
		}
		if strings.Contains(p, "Rückweg") {
			rueckweg = true
		}
	}
	if !neustart || !rueckweg {
		t.Errorf("die Frage nennt Neustart und Rückweg nicht beide: %v", frage.Punkte)
	}
	if len(ops.selfUpdates) != 0 {
		t.Fatal("ohne Bestätigung wurde ein Vorgang gestartet")
	}

	// Mit Bestätigung: 202, und der Auftrag trägt genau die geprüfte Fassung.
	rec = postJSON(t, s, "/api/v1/update/apply", `{"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusAccepted {
		t.Fatalf("Status = %d, erwartet 202: %s", rec.Code, rec.Body.String())
	}
	fertig := updateAntwortVon(t, rec.Body.Bytes())
	if fertig.Update == nil || !fertig.Update.Laeuft {
		t.Errorf("Update = %+v, erwartet laufend", fertig.Update)
	}
	if fertig.Update.Ziel != "9.9.9" {
		t.Errorf("Ziel = %q", fertig.Update.Ziel)
	}
	// Der Hinweis sagt, dass die Verbindung abreißt. Ohne ihn sieht der Abbruch
	// wie ein Fehlschlag aus.
	if !strings.Contains(fertig.Hinweis, "reißt") {
		t.Errorf("der Hinweis nennt den Verbindungsabbruch nicht: %q", fertig.Hinweis)
	}

	if len(ops.selfUpdates) != 1 {
		t.Fatalf("%d Vorgänge gestartet, erwartet 1", len(ops.selfUpdates))
	}
	spec := ops.selfUpdates[0]
	if spec.Version != "9.9.9" {
		t.Errorf("Zielfassung im Auftrag = %q — installiert wird sonst nicht das, "+
			"was der Auslöser gesehen hat", spec.Version)
	}
	if spec.Channel != "stable" || spec.Rollback {
		t.Errorf("Auftrag = %+v", spec)
	}
	if !filepath.IsAbs(spec.LogFile) || !strings.HasSuffix(spec.LogFile, updateLogName) {
		t.Errorf("Protokolldatei = %q", spec.LogFile)
	}
	if !strings.HasPrefix(spec.Unit, "asylum-update-") {
		t.Errorf("Unit-Name = %q", spec.Unit)
	}

	// Ein zweiter Anstoß wird abgewiesen, solange der erste läuft.
	rec = postJSON(t, s, "/api/v1/update/apply", `{"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Errorf("zweiter Anstoß = %d, erwartet 409", rec.Code)
	}
	// Und der Rückweg auch: Zwei Läufe gleichzeitig gibt es nicht.
	rec = postJSON(t, s, "/api/v1/update/rollback", `{"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Errorf("Rückweg während eines Laufs = %d, erwartet 409", rec.Code)
	}
	if len(ops.selfUpdates) != 1 {
		t.Errorf("%d Vorgänge gestartet, erwartet 1", len(ops.selfUpdates))
	}
}

// Eine ältere Fassung im Kanal wird nicht eingespielt — eine manipulierte oder
// zurückgedrehte Metadatendatei darf kein Downgrade werden.
func TestAPIUpdateKeinDowngrade(t *testing.T) {
	body := strings.Replace(testChannelJSON, `"version": "9.9.9"`, `"version": "0.0.2"`, 1)
	s, ops := updateServer(t, body)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	if rec := postJSON(t, s, "/api/v1/update/check", `{}`, cookie, csrf); rec.Code != http.StatusOK {
		t.Fatalf("Prüfen = %d", rec.Code)
	}
	a := holeUpdate(t, s, cookie)
	if a.UpdateDa {
		t.Error("eine ältere Fassung wird als Update gemeldet")
	}
	if a.Verfuegbar != "0.0.2" {
		t.Errorf("Verfuegbar = %q — die gefundene Fassung soll dastehen, auch wenn "+
			"sie älter ist", a.Verfuegbar)
	}

	rec := postJSON(t, s, "/api/v1/update/apply", `{"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400", rec.Code)
	}
	if len(ops.selfUpdates) != 0 {
		t.Error("es wurde ein Downgrade gestartet")
	}
}

// Eine Entwicklungsfassung bekommt kein Update: Was danach liefe, wüsste niemand.
func TestAPIUpdateEntwicklungsfassung(t *testing.T) {
	s, ops := updateServer(t, testChannelJSON)
	setVersion(t, "dev")
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	if rec := postJSON(t, s, "/api/v1/update/check", `{}`, cookie, csrf); rec.Code != http.StatusOK {
		t.Fatalf("Prüfen = %d", rec.Code)
	}
	if holeUpdate(t, s, cookie).UpdateDa {
		t.Error("einer Entwicklungsfassung wird ein Update angeboten")
	}
	rec := postJSON(t, s, "/api/v1/update/apply", `{"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400", rec.Code)
	}
	if len(ops.selfUpdates) != 0 {
		t.Error("eine Entwicklungsfassung wurde aktualisiert")
	}
}

// Eine unerreichbare Quelle ist eine Auskunft und kein Fehler: 200 mit dem Grund.
func TestAPIUpdateQuelleUnerreichbar(t *testing.T) {
	s, _ := updateServer(t, "kein json")
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	rec := postJSON(t, s, "/api/v1/update/check", `{}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200 — die Anfrage war in Ordnung, nur ihr "+
			"Ergebnis ist eine schlechte Nachricht: %s", rec.Code, rec.Body.String())
	}
	a := updateAntwortVon(t, rec.Body.Bytes())
	if !strings.Contains(a.Hinweis, "nicht erreichbar") {
		t.Errorf("der Grund fehlt: %+v", a)
	}
	if a.Update == nil || a.Update.Prueffehler == "" {
		t.Errorf("der Fehler steht nicht im Zustand: %+v", a.Update)
	}
	// Und ein Einspielen ist danach nicht möglich.
	if rec := postJSON(t, s, "/api/v1/update/apply", `{"bestaetigt":true}`, cookie, csrf); rec.Code != http.StatusBadRequest {
		t.Errorf("Einspielen nach gescheiterter Prüfung = %d, erwartet 400", rec.Code)
	}
}

// Ohne Sicherung gibt es keinen Rückweg — und die Antwort sagt das.
func TestAPIUpdateRueckwegOhneSicherung(t *testing.T) {
	s, ops := updateServer(t, testChannelJSON)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	rec := postJSON(t, s, "/api/v1/update/rollback", `{"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("Status = %d, erwartet 400: %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "Sicherung") {
		t.Errorf("der Grund fehlt: %s", rec.Body.String())
	}
	if len(ops.selfUpdates) != 0 {
		t.Error("es wurde ein Rückweg ohne Sicherung gestartet")
	}
}

// Der Poller: klein, und er liefert die LAUFENDE Fassung. An ihr erkennt die
// Oberfläche, dass der Vorgang durch ist — sie kommt aus dem neuen Programm.
func TestAPIUpdateStand(t *testing.T) {
	s, _ := updateServer(t, testChannelJSON)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	// Ein Protokoll, wie der Update-Lauf es hinterlässt.
	if err := os.MkdirAll(s.cfg.Paths.Log, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(s.updateLogPath(),
		[]byte("lade herunter\nprüfe Signatur\ntausche Binary\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	rec := get(t, s, "/api/v1/update/status", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d", rec.Code)
	}
	var stand apiUpdateStand
	if err := json.Unmarshal(rec.Body.Bytes(), &stand); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	if stand.Fassung != version.Version {
		t.Errorf("Fassung = %q, erwartet %q — an ihr erkennt die Oberfläche das Ende",
			stand.Fassung, version.Version)
	}
	if len(stand.Zeilen) != 3 {
		t.Errorf("%d Zeilen, erwartet 3: %v", len(stand.Zeilen), stand.Zeilen)
	}
	if stand.Laeuft {
		t.Error("es wird ein Lauf gemeldet, obwohl keiner angestoßen wurde")
	}

	// Nach dem Anstoß gilt der Lauf als laufend, und das Ziel steht dabei.
	if rec := postJSON(t, s, "/api/v1/update/check", `{}`, cookie, csrf); rec.Code != http.StatusOK {
		t.Fatalf("Prüfen = %d", rec.Code)
	}
	if rec := postJSON(t, s, "/api/v1/update/apply", `{"bestaetigt":true}`, cookie, csrf); rec.Code != http.StatusAccepted {
		t.Fatalf("Einspielen = %d", rec.Code)
	}
	rec = get(t, s, "/api/v1/update/status", cookie)
	if err := json.Unmarshal(rec.Body.Bytes(), &stand); err != nil {
		t.Fatal(err)
	}
	if !stand.Laeuft || stand.Ziel != "9.9.9" {
		t.Errorf("Stand = %+v, erwartet laufend auf 9.9.9", stand)
	}
}
