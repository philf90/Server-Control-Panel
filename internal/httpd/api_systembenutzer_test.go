package httpd

// Tests für /api/v1/system-users.
//
// Vier Stellen sind hier prüfenswert:
//
//  1. Die Aktionsliste. root ist geschützt, und eine Oberfläche, die „löschen"
//     anbietet und dann verweigert, ist die schlechteste der möglichen
//     Antworten.
//  2. „ohne Schlüssel" nur bei Menschenkonten. Bei einem Dienstkonto ist es die
//     Bauart und keine Auffälligkeit — sonst stünde auf jedem Server eine
//     zweistellige Warnzahl, die nichts bedeutet.
//  3. Der LETZTE Schlüssel. Ihn zu entfernen legt den Zugang still, und das ist
//     eine andere Frage als „einen von drei entfernen".
//  4. Die Rückfragen. Ohne Bestätigung darf nichts geschehen.

import (
	"encoding/json"
	"net/http"
	"slices"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

func holeKonten(t *testing.T, s *Server, cookie *http.Cookie) apiSystembenutzer {
	t.Helper()
	rec := get(t, s, "/api/v1/system-users", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var a apiSystembenutzer
	if err := json.Unmarshal(rec.Body.Bytes(), &a); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	return a
}

func kontoAus(t *testing.T, a apiSystembenutzer, name string) apiSystemkonto {
	t.Helper()
	for _, k := range a.Konten {
		if k.Name == name {
			return k
		}
	}
	t.Fatalf("Konto %q fehlt in der Liste", name)
	return apiSystemkonto{}
}

func TestAPIKontenListeUndAktionen(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)

	a := holeKonten(t, s, cookie)

	// Menschenkonten zuerst: Ein Server hat dreißig Dienstkonten und zwei, um die
	// es geht.
	if a.Konten[0].Art != "mensch" {
		t.Errorf("erstes Konto ist %q (%s), erwartet ein Menschenkonto oben",
			a.Konten[0].Name, a.Konten[0].Art)
	}

	// root ist geschützt: keine verändernden Handgriffe.
	root := kontoAus(t, a, "root")
	if !root.Protected {
		t.Fatal("root ist nicht als geschützt gekennzeichnet")
	}
	for _, verboten := range []string{kontoAktionLoeschen, kontoAktionSperren, kontoAktionEntsperren} {
		if slices.Contains(root.Aktionen, verboten) {
			t.Errorf("root bietet %q an: %v — die Prüfung in privops greift ohnehin, "+
				"aber ein Knopf, der dann verweigert, ist die schlechteste Antwort",
				verboten, root.Aktionen)
		}
	}
	// Schlüssel verwalten geht bei root trotzdem: Es ist das Konto, über das man
	// im Notfall hereinkommt.
	if !slices.Contains(root.Aktionen, kontoAktionSchluessel) {
		t.Errorf("root bietet keine Schlüsselverwaltung an: %v", root.Aktionen)
	}

	// Ein gewöhnliches Menschenkonto kann alles.
	philipp := kontoAus(t, a, "philipp")
	for _, erwartet := range []string{kontoAktionSperren, kontoAktionLoeschen, kontoAktionSchluessel} {
		if !slices.Contains(philipp.Aktionen, erwartet) {
			t.Errorf("philipp bietet %q nicht an: %v", erwartet, philipp.Aktionen)
		}
	}

	// Ein Dienstkonto ohne Anmeldeschale bekommt keine Schlüsselverwaltung: In
	// ~/.ssh läge nichts, was jemand benutzen könnte, und ein Schlüssel dort wäre
	// eine falsche Zusage.
	wwwData := kontoAus(t, a, "www-data")
	if wwwData.Art != "dienst" {
		t.Errorf("www-data ist %q, erwartet dienst", wwwData.Art)
	}
	if slices.Contains(wwwData.Aktionen, kontoAktionSchluessel) {
		t.Errorf("www-data bietet Schlüsselverwaltung an, hat aber keine Anmeldeschale: %v",
			wwwData.Aktionen)
	}

	// Die Zähler.
	if a.Zaehler.Gesamt != 3 || a.Zaehler.Menschen != 2 || a.Zaehler.Dienste != 1 {
		t.Errorf("Zähler = %+v, erwartet 3 gesamt / 2 Menschen / 1 Dienst", a.Zaehler)
	}

	// Die Auswahlfelder kommen mit.
	if len(a.Schalen) == 0 || len(a.Gruppen) == 0 {
		t.Errorf("Schalen = %v, Gruppen = %v — die Auswahlfelder blieben leer", a.Schalen, a.Gruppen)
	}
}

// „ohne Schlüssel" ist nur bei einem Menschenkonto eine Auffälligkeit. Bei einem
// Dienstkonto ist es die Bauart — sonst stünde auf jedem Server eine zweistellige
// Warnzahl, die nichts bedeutet.
func TestAPIKontenOhneSchluesselNurBeiMenschen(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)
	ops.sysUsers = []privops.SystemUser{
		{Name: "mensch", UID: 1000, Shell: "/bin/bash", HasShell: true, SSHKeys: 0},
		{Name: "mitkey", UID: 1001, Shell: "/bin/bash", HasShell: true, SSHKeys: 2},
		{Name: "dienst", UID: 100, System: true, SSHKeys: 0},
	}

	a := holeKonten(t, s, cookie)

	if !kontoAus(t, a, "mensch").OhneSchluessel {
		t.Error("ein Menschenkonto ohne Schlüssel ist nicht gekennzeichnet — es kommt " +
			"nicht auf den Server, und das ist die Zahl, die eine Handlung nach sich zieht")
	}
	if kontoAus(t, a, "mitkey").OhneSchluessel {
		t.Error("ein Konto mit zwei Schlüsseln gilt als ohne")
	}
	if kontoAus(t, a, "dienst").OhneSchluessel {
		t.Error("ein Dienstkonto ohne Schlüssel ist als Auffälligkeit gekennzeichnet — " +
			"es ist die Bauart")
	}
	if a.Zaehler.OhneSchluessel != 1 {
		t.Errorf("Zaehler.OhneSchluessel = %d, erwartet 1", a.Zaehler.OhneSchluessel)
	}
}

func TestAPIKontenAnlegen(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)

	// Mit Schlüssel: kein Hinweis nötig.
	rec := postJSON(t, s, "/api/v1/system-users",
		`{"name":"monteur","notiz":"Wartung","schale":"/bin/bash","gruppen":["sudo"],`+
			`"schluessel":"ssh-ed25519 AAAA test"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var antwort apiKontoAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatal(err)
	}
	if antwort.Hinweis != "" {
		t.Errorf("mit Schlüssel steht ein Hinweis dabei: %q", antwort.Hinweis)
	}

	spec := ops.lastCreated(t)
	if spec.Name != "monteur" || spec.Comment != "Wartung" || spec.Shell != "/bin/bash" {
		t.Errorf("Vorgabe = %+v", spec)
	}
	if !slices.Contains(spec.Groups, "sudo") {
		t.Errorf("Gruppen = %v, erwartet sudo", spec.Groups)
	}
	// Das Home wird immer angelegt. Ohne Home gibt es kein ~/.ssh, das dem Konto
	// gehört — und damit keine Anmeldung per Schlüssel, den einzigen Weg, den
	// diese Konten haben.
	if !spec.CreateHome {
		t.Error("CreateHome ist falsch — ohne Home gibt es kein ~/.ssh und damit keinen Zugang")
	}

	// Ohne Schlüssel: Der Hinweis muss dastehen. Es als Erfolg zu quittieren
	// hieße, jemanden ein Konto anlegen zu lassen, das niemand benutzen kann.
	rec = postJSON(t, s, "/api/v1/system-users", `{"name":"leer"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatal(err)
	}
	if antwort.Hinweis == "" {
		t.Error("ohne Schlüssel fehlt der Hinweis, dass das Konto nicht hereinkommt")
	}
}

// Sperren fragt zurück, Entsperren nicht: Das eine nimmt einen Zugang, das andere
// gibt ihn.
func TestAPIKontenSperrenFragtZurueck(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)

	rec := postJSON(t, s, "/api/v1/system-users/philipp/locked", `{"gesperrt":true}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	if slices.ContainsFunc(ops.recorded(), func(s string) bool {
		return strings.HasPrefix(s, "sysuser:lock")
	}) {
		t.Fatal("das Konto wurde ohne Bestätigung gesperrt")
	}
	frage := frageVon(t, rec.Body.Bytes())
	if frage.Tippen != "" {
		t.Errorf("Sperren verlangt ein getipptes Wort (%q) — es ist Stufe 2, die "+
			"Sperre lässt sich jederzeit aufheben", frage.Tippen)
	}
	if len(frage.Punkte) < 2 {
		t.Errorf("die Frage nennt zu wenig: %+v", frage.Punkte)
	}

	rec = postJSON(t, s, "/api/v1/system-users/philipp/locked",
		`{"gesperrt":true,"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}

	// Entsperren läuft ohne Frage durch.
	rec = postJSON(t, s, "/api/v1/system-users/philipp/locked", `{"gesperrt":false}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Errorf("Entsperren: Status = %d, erwartet 200 ohne Rückfrage: %s", rec.Code, rec.Body.String())
	}
}

// Löschen ist Stufe 3 mit dem Kontonamen, und die Frage nennt, was mit dem Home
// geschieht — je nachdem, was gewählt wurde.
func TestAPIKontenLoeschenIstStufeDrei(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)

	rec := postJSON(t, s, "/api/v1/system-users/philipp/delete", `{}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	frage := frageVon(t, rec.Body.Bytes())
	if frage.Tippen != "philipp" {
		t.Errorf("Tippen = %q, erwartet den Kontonamen", frage.Tippen)
	}
	if !strings.Contains(strings.Join(frage.Punkte, " "), "bleibt erhalten") {
		t.Errorf("ohne home_entfernen sagt die Frage nicht, dass das Home bleibt: %+v", frage.Punkte)
	}

	// Mit home_entfernen ändert sich die Folge in der Frage.
	rec = postJSON(t, s, "/api/v1/system-users/philipp/delete", `{"home_entfernen":true}`, cookie, csrf)
	frage = frageVon(t, rec.Body.Bytes())
	if !strings.Contains(strings.Join(frage.Punkte, " "), "mit gelöscht") {
		t.Errorf("mit home_entfernen sagt die Frage nicht, dass das Home mitgeht: %+v", frage.Punkte)
	}

	// Ein falsches Wort führt nicht aus.
	rec = postJSON(t, s, "/api/v1/system-users/philipp/delete",
		`{"bestaetigt":true,"getippt":"falsch"}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("mit falschem Wort: Status = %d, erwartet 409", rec.Code)
	}
	if slices.ContainsFunc(ops.recorded(), func(s string) bool {
		return strings.HasPrefix(s, "sysuser:delete")
	}) {
		t.Fatal("das Konto wurde mit falschem Wort gelöscht")
	}

	rec = postJSON(t, s, "/api/v1/system-users/philipp/delete",
		`{"bestaetigt":true,"getippt":"philipp","home_entfernen":true}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var antwort apiKontoAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatal(err)
	}
	// Kein Konto in der Antwort: Es gibt es nicht mehr.
	if antwort.Konto != nil {
		t.Error("nach dem Löschen trägt die Antwort ein Konto")
	}
	if !strings.Contains(antwort.Meldung, "Home entfernt") {
		t.Errorf("die Meldung sagt nicht, was mit dem Home geschah: %q", antwort.Meldung)
	}
}

// Der LETZTE Schlüssel ist eine andere Frage als „einen von dreien entfernen":
// Danach hat das Konto keinen Zugang. Abgelehnt wird es nicht — ein Systemkonto
// ohne Schlüssel ist ein zulässiger Zustand, und wer es stilllegen will, hat
// einen Grund.
func TestAPISchluesselLetzterIstStufeDrei(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)

	// Erst mit drei Schlüsseln: Stufe 2, kein getipptes Wort.
	ops.keys = []privops.SSHKey{
		{Type: "ssh-ed25519", Fingerprint: "SHA256:aaa"},
		{Type: "ssh-rsa", Bits: 4096, Fingerprint: "SHA256:bbb"},
		{Type: "ssh-rsa", Bits: 2048, Fingerprint: "SHA256:ccc"},
	}
	rec := postJSON(t, s, "/api/v1/system-users/philipp/keys/remove",
		`{"fingerprint":"SHA256:bbb"}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	frage := frageVon(t, rec.Body.Bytes())
	if frage.Tippen != "" {
		t.Errorf("einen von drei Schlüsseln zu entfernen verlangt ein Wort (%q) — "+
			"das ist Stufe 2", frage.Tippen)
	}
	if !strings.Contains(strings.Join(frage.Punkte, " "), "SHA256:bbb") {
		t.Errorf("die Frage nennt den Fingerprint nicht: %+v — wer drei Schlüssel "+
			"hat, entscheidet sonst blind", frage.Punkte)
	}

	// Und jetzt mit einem einzigen: Stufe 3 mit dem Kontonamen.
	ops.keys = []privops.SSHKey{{Type: "ssh-ed25519", Fingerprint: "SHA256:aaa"}}
	rec = postJSON(t, s, "/api/v1/system-users/philipp/keys/remove",
		`{"fingerprint":"SHA256:aaa"}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	frage = frageVon(t, rec.Body.Bytes())
	if frage.Tippen != "philipp" {
		t.Errorf("Tippen = %q beim letzten Schlüssel, erwartet den Kontonamen — "+
			"danach hat das Konto keinen Zugang mehr", frage.Tippen)
	}
	if !strings.Contains(frage.Frage, "EINZIGE") {
		t.Errorf("die Frage sagt nicht, dass es der einzige ist: %q", frage.Frage)
	}

	// Mit Bestätigung geht es durch — und der Hinweis sagt, was jetzt gilt.
	rec = postJSON(t, s, "/api/v1/system-users/philipp/keys/remove",
		`{"fingerprint":"SHA256:aaa","bestaetigt":true,"getippt":"philipp"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var antwort apiKontoAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatal(err)
	}
	if antwort.Hinweis == "" {
		t.Error("nach dem Entfernen des letzten Schlüssels fehlt der Hinweis, dass " +
			"das Konto keinen Zugang mehr hat")
	}
}

// Die Stärke eines Schlüssels steht in Worten. „2048 Bit RSA" ist für die meisten
// keine Aussage, „nach heutigem Maß knapp" schon.
func TestAPISchluesselStaerke(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)
	ops := s.ops.(*fakeOps)
	ops.keys = []privops.SSHKey{
		{Type: "ssh-ed25519", Fingerprint: "SHA256:ed"},
		{Type: "ssh-rsa", Bits: 4096, Fingerprint: "SHA256:gross"},
		{Type: "ssh-rsa", Bits: 2048, Fingerprint: "SHA256:klein"},
		{Type: "ssh-dss", Bits: 1024, Fingerprint: "SHA256:alt"},
	}

	rec := get(t, s, "/api/v1/system-users/philipp/keys", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var liste apiSchluesselListe
	if err := json.Unmarshal(rec.Body.Bytes(), &liste); err != nil {
		t.Fatal(err)
	}
	if len(liste.Schluessel) != 4 {
		t.Fatalf("%d Schlüssel, erwartet 4", len(liste.Schluessel))
	}

	nach := map[string]apiSchluessel{}
	for _, k := range liste.Schluessel {
		nach[k.Fingerprint] = k
	}
	if nach["SHA256:ed"].Schwach {
		t.Error("ed25519 gilt als schwach")
	}
	if nach["SHA256:gross"].Schwach {
		t.Error("RSA mit 4096 Bit gilt als schwach")
	}
	if !nach["SHA256:klein"].Schwach {
		t.Error("RSA mit 2048 Bit gilt nicht als schwach")
	}
	if !nach["SHA256:alt"].Schwach {
		t.Error("DSA gilt nicht als schwach — OpenSSH nimmt es seit 7.0 nicht mehr an")
	}
	// Und der Ort der Datei steht dabei: Wer den Zugang verliert, muss wissen, wo
	// er von Hand nachsehen kann.
	if liste.Datei == "" {
		t.Error("es fehlt der Ort der Schlüsseldatei")
	}
}

// Lesen darf jede Rolle, verändern nur mit Schreibrecht — und ohne Token gilt das
// auch für ein Owner-Konto.
func TestAPIKontenRechte(t *testing.T) {
	sLeser := newTestServer(t)
	leser := addUser(t, sLeser, "leser", store.RoleReadOnly)
	cookieLeser, csrfLeser := login(t, sLeser, leser)

	if rec := get(t, sLeser, "/api/v1/system-users", cookieLeser); rec.Code != http.StatusOK {
		t.Errorf("Lesen mit Leserrolle: Status = %d, erwartet 200", rec.Code)
	}
	rec := postJSON(t, sLeser, "/api/v1/system-users", `{"name":"neu"}`, cookieLeser, csrfLeser)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Anlegen mit Leserrolle: Status = %d, erwartet 403", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), "Schreibrechte") {
		t.Errorf("der Grund nennt nicht die fehlende Rolle: %s", rec.Body.String())
	}

	s, cookie, _ := angemeldet(t, store.RoleOwner)
	ops := s.ops.(*fakeOps)
	rec = postJSON(t, s, "/api/v1/system-users", `{"name":"neu"}`, cookie, "")
	if rec.Code != http.StatusForbidden {
		t.Errorf("ohne Token: Status = %d, erwartet 403", rec.Code)
	}
	if slices.ContainsFunc(ops.recorded(), func(s string) bool {
		return strings.HasPrefix(s, "sysuser:create")
	}) {
		t.Error("das Konto wurde ohne Token angelegt")
	}
}
