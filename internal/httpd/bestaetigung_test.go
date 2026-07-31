package httpd

import (
	"context"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// Die Rückfrage vor zerstörenden Aktionen.
//
// Der Anlass ist ein Befund und kein Wunsch: Dreizehn Formulare trugen ein
// onsubmit="return confirm(…)", und keines davon hat je gefragt. Die
// Content-Security-Policy des Panels ist `script-src 'self'` ohne
// 'unsafe-inline'; der Browser verwirft einen Inline-Handler, bevor er läuft. Im
// Browser nachgemessen: kein Dialog, ein Klick, Konto weg.
//
// Diese Tests halten das Gegenteil fest, und zwar auf der Seite, die zählt: Der
// Handler tut ohne das Feld "bestaetigt" nichts. Ein Dialog kann fehlen,
// verworfen oder umgangen werden — die Prüfung hier nicht.

// TestZerstoerendeSystemaktionenFragenZurueck: Ein POST ohne Bestätigung darf den
// Executor nicht erreichen. Geprüft wird nicht der Statuscode, sondern die
// Wirkung — eine Zwischenseite antwortet mit 200, und ein Test, der nur darauf
// schaut, besteht auch dann, wenn nichts geschah.
func TestZerstoerendeSystemaktionenFragenZurueck(t *testing.T) {
	faelle := []struct {
		name     string
		pfad     string
		werte    url.Values
		tippen   string
		erwartet string // was der Executor danach aufgezeichnet haben muss
	}{
		{
			name:     "Dienst stoppen",
			pfad:     "/alt/services/nginx.service",
			werte:    url.Values{"action": {"stop"}},
			erwartet: "service:stop:nginx.service",
		},
		{
			// Hier steht kein Erwartungswert: Der Paketlauf verlässt die Anfrage
			// und läuft als Hintergrundvorgang. Dass er anläuft, prüft
			// TestPackageUpgradeStartsJob; hier zählt, dass er es ohne
			// Bestätigung nicht tut.
			name:  "alle Updates einspielen",
			pfad:  "/alt/packages/upgrade",
			werte: url.Values{"scope": {"all"}},
		},
		{
			name:     "Systemkonto löschen",
			pfad:     "/alt/system-users/deploy/delete",
			werte:    url.Values{},
			tippen:   "deploy",
			erwartet: "sysuser:delete:deploy",
		},
		{
			name:     "SSH-Schlüssel entfernen",
			pfad:     "/alt/system-users/deploy/keys/remove",
			werte:    url.Values{"fingerprint": {"SHA256:abc"}},
			erwartet: "sshkey:remove:deploy",
		},
	}

	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			s, ops := newSystemServer(t)
			user := addUser(t, s, "chef", store.RoleOwner)
			cookie, csrf := login(t, s, user)

			ohne := url.Values{"_csrf": {csrf}}
			for k, v := range f.werte {
				ohne[k] = v
			}

			rec := post(t, s, f.pfad, ohne, cookie)
			if rec.Code != http.StatusOK {
				t.Fatalf("ohne Bestätigung: Status %d, erwartet die Rückfrage (200) — %s",
					rec.Code, rec.Body.String())
			}
			if got := ops.recorded(); len(got) != 0 {
				t.Fatalf("ohne Bestätigung ausgeführt: %v", got)
			}
			// Die Antwort ist die Zwischenseite: Sie schickt dasselbe POST erneut
			// und trägt das Feld, das der Handler verlangt.
			koerper := rec.Body.String()
			if !strings.Contains(koerper, `name="bestaetigt" value="1"`) {
				t.Error("die Antwort ist nicht die Rückfrage — das Feld bestaetigt fehlt")
			}
			if !strings.Contains(koerper, `action="`+f.pfad+`"`) {
				t.Errorf("die Rückfrage schickt nicht an %s zurück", f.pfad)
			}
			for k, v := range f.werte {
				if !strings.Contains(koerper, `name="`+k+`" value="`+v[0]+`"`) {
					t.Errorf("das Feld %s=%s wird nicht wieder mitgeschickt", k, v[0])
				}
			}
			if f.tippen != "" && !strings.Contains(koerper, `name="tippen"`) {
				t.Error("die Rückfrage verlangt kein getipptes Wort")
			}

			// Mit Bestätigung läuft dieselbe Anfrage durch.
			mit := ja(ohne, f.tippen)
			rec = post(t, s, f.pfad, mit, cookie)
			if rec.Code >= 400 {
				t.Fatalf("mit Bestätigung: Status %d — %s", rec.Code, rec.Body.String())
			}
			// Präfix und nicht Gleichheit: Manche Aufzeichnungen tragen noch das
			// Ziel im Namen (der Fingerprint eines Schlüssels).
			if f.erwartet == "" {
				return // läuft als Hintergrundvorgang, siehe Kommentar am Fall
			}
			if got := ops.recorded(); !slices.ContainsFunc(got, func(a string) bool {
				return strings.HasPrefix(a, f.erwartet)
			}) {
				t.Fatalf("mit Bestätigung ausgeführt: %v, erwartet %s", got, f.erwartet)
			}
		})
	}
}

// TestGetipptesWortMussStimmen: Die dritte Stufe ist keine Zierde. Ein falsches
// Wort führt zu einer Ablehnung, nicht zur Aktion.
func TestGetipptesWortMussStimmen(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "chef", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	form := url.Values{"_csrf": {csrf}, "bestaetigt": {"1"}, "tippen": {"deplyo"}}
	rec := post(t, s, "/alt/system-users/deploy/delete", form, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status %d, erwartet 400", rec.Code)
	}
	if got := ops.recorded(); len(got) != 0 {
		t.Fatalf("mit falschem Wort ausgeführt: %v", got)
	}
	if !strings.Contains(rec.Body.String(), "stimmt nicht") {
		t.Error("die Seite sagt nicht, dass das Wort nicht passte")
	}

	// Groß- und Kleinschreibung ist nicht der Zweck: Auf einem Telefon macht die
	// Tastatur aus "deploy" gern "Deploy".
	form.Set("tippen", "Deploy")
	if rec := post(t, s, "/alt/system-users/deploy/delete", form, cookie); rec.Code >= 400 {
		t.Fatalf("Status %d — %s", rec.Code, rec.Body.String())
	}
	if got := ops.recorded(); !slices.Contains(got, "sysuser:delete:deploy") {
		t.Fatalf("ausgeführt: %v", got)
	}
}

// TestNeustartVerlangtDenHostnamen: Bei systemweiten Aktionen ist das getippte
// Wort der Rechnername. Wer zwei Server im Browser offen hat, startet so nicht
// den falschen neu — und ein Klick allein genügt nicht.
func TestNeustartVerlangtDenHostnamen(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "chef", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/alt/system/reboot", url.Values{"_csrf": {csrf}, "bestaetigt": {"1"}}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("ohne getippten Namen: Status %d, erwartet 400", rec.Code)
	}
	if got := ops.recorded(); len(got) != 0 {
		t.Fatalf("der Neustart lief ohne den Hostnamen: %v", got)
	}
	if !strings.Contains(rec.Body.String(), s.rechnername()) {
		t.Error("die Rückfrage nennt den Hostnamen nicht — dann ist er nicht ablesbar")
	}

	rec = post(t, s, "/alt/system/reboot", ja(url.Values{"_csrf": {csrf}}, s.rechnername()), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d — %s", rec.Code, rec.Body.String())
	}
	if got := ops.recorded(); !slices.Contains(got, "reboot") {
		t.Fatalf("ausgeführt: %v", got)
	}
}

// TestFirewallAusschaltenFragtZurueck: Ausschalten öffnet den Server für jede
// eingehende Verbindung, und dieser Zustand nimmt sich — anders als das
// Einschalten — nicht von selbst zurück.
func TestFirewallAusschaltenFragtZurueck(t *testing.T) {
	s, ops := newSystemServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, csrf := login(t, s, user)

	ops.mu.Lock()
	ops.firewall = privops.FirewallState{
		Backend: privops.BackendUFW, Active: true, Managed: true, Installed: true,
	}
	ops.mu.Unlock()

	rec := post(t, s, "/alt/firewall/active", url.Values{"_csrf": {csrf}, "active": {"0"}}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d", rec.Code)
	}
	for _, a := range ops.recorded() {
		if strings.HasPrefix(a, "firewall:active") {
			t.Fatalf("ufw wurde ohne Bestätigung geschaltet: %v", ops.recorded())
		}
	}
	if !strings.Contains(rec.Body.String(), `name="active" value="0"`) {
		t.Error("die Rückfrage nimmt das Feld active nicht mit — sie würde ufw einschalten")
	}
}

// TestDateiLoeschenFragtZurueck: Bei einer einzelnen Datei genügt der zweite
// Klick, bei einem Ordner mit Inhalt muss der Name getippt werden. Hinter einem
// Klick steht dort kein Eintrag, sondern ein Baum.
func TestDateiLoeschenFragtZurueck(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	datei := filepath.Join(arbeit, "wichtig.conf")
	lege(t, datei, "inhalt")

	rec := post(t, s, "/alt/files/delete", formular(csrf, "path", datei), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d — %s", rec.Code, rec.Body.String())
	}
	if _, err := os.Stat(datei); err != nil {
		t.Fatal("die Datei wurde ohne Bestätigung gelöscht")
	}
	if strings.Contains(rec.Body.String(), `name="tippen"`) {
		t.Error("für eine einzelne Datei wird ein getipptes Wort verlangt — das ist die falsche Stufe")
	}

	// Ein Ordner mit Inhalt: dritte Stufe, und die Frage nennt die Zahlen.
	baum := filepath.Join(arbeit, "baum")
	lege(t, filepath.Join(baum, "tief", "a.txt"), "x")

	rec = post(t, s, "/alt/files/delete", formular(csrf, "path", baum), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d", rec.Code)
	}
	koerper := rec.Body.String()
	if !strings.Contains(koerper, `name="tippen"`) {
		t.Error("für einen Ordner mit Inhalt wird kein getipptes Wort verlangt")
	}
	// Die Zahlen tragen die Entscheidung: "Ordner wirklich löschen?" befähigt zu
	// keiner. Geprüft wird der Satzbau, nicht die Zahl selbst — Measure zählt den
	// Ordner mit, und diese Rechnung gehört nicht in diesen Test.
	if !strings.Contains(koerper, "enthält") || !strings.Contains(koerper, "Dateien") {
		t.Errorf("die Rückfrage nennt den Umfang nicht — dann trägt sie keine Entscheidung")
	}
	if _, err := os.Stat(baum); err != nil {
		t.Fatal("der Ordner wurde ohne Bestätigung gelöscht")
	}

	// Ein falscher Name löscht nichts.
	rec = post(t, s, "/alt/files/delete", ja(formular(csrf, "path", baum), "bam"), cookie)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("mit falschem Namen: Status %d, erwartet 400", rec.Code)
	}
	if _, err := os.Stat(baum); err != nil {
		t.Fatal("der Ordner wurde trotz falschen Namens gelöscht")
	}

	// Und mit dem richtigen ist er weg.
	rec = post(t, s, "/alt/files/delete", ja(formular(csrf, "path", baum), "baum"), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d — %s", rec.Code, rec.Body.String())
	}
	if _, err := os.Stat(baum); !os.IsNotExist(err) {
		t.Error("der Ordner liegt noch da")
	}
}

// TestLoeschenAusserhalbDerSchreibbereicheFragtNicht: Die Rückfrage kommt nur,
// wo gelöscht werden könnte. Ein Pfad, den die Wache ablehnt, bekommt die
// Antwort der Wache — eine Rückfrage, deren Bestätigung in ein 403 läuft, wäre
// eine Zumutung.
func TestLoeschenAusserhalbDerSchreibbereicheFragtNicht(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	fremd := filepath.Join(wurzel, "nurlesbar", "da.txt")
	lege(t, fremd, "unberührt")

	rec := post(t, s, "/alt/files/delete", formular(csrf, "path", fremd), cookie)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Status %d, erwartet 403 — %s", rec.Code, rec.Body.String())
	}
	if strings.Contains(rec.Body.String(), `name="bestaetigt"`) {
		t.Error("es kommt eine Rückfrage für einen Pfad, der ohnehin abgelehnt wird")
	}
}

// TestPanelZugangLoeschenFragtZurueck: Der Anmeldename muss getippt werden. In
// einer Liste gleich aussehender Zeilen trifft man leicht die falsche, und
// gelöscht ist gelöscht.
func TestPanelZugangLoeschenFragtZurueck(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "chef", store.RoleOwner)
	andere := addUser(t, s, "kollege", store.RoleAdmin)
	cookie, csrf := login(t, s, owner)
	pfad := "/alt/users/" + strconv.FormatInt(andere.ID, 10) + "/delete"

	da := func() bool {
		_, err := s.db.UserByName(context.Background(), "kollege")
		return err == nil
	}

	rec := post(t, s, pfad, url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d", rec.Code)
	}
	if !da() {
		t.Fatal("das Konto wurde ohne Bestätigung gelöscht")
	}
	if !strings.Contains(rec.Body.String(), "kollege") {
		t.Error("die Rückfrage nennt das Konto nicht")
	}

	rec = post(t, s, pfad, ja(url.Values{"_csrf": {csrf}}, "kolege"), cookie)
	if rec.Code != http.StatusBadRequest || !da() {
		t.Errorf("ein Tippfehler löschte das Konto (Status %d)", rec.Code)
	}

	rec = post(t, s, pfad, ja(url.Values{"_csrf": {csrf}}, "kollege"), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d — %s", rec.Code, rec.Body.String())
	}
	if da() {
		t.Error("das Konto ist noch da")
	}
}

// TestRueckfrageBrauchtDieRolle: Die Rückfrage ist kein Weg an der
// Rollentrennung vorbei — wer die Aktion nicht darf, bekommt 403 und keine
// Zwischenseite, die ihm den nächsten Schritt zeigt.
func TestRueckfrageBrauchtDieRolle(t *testing.T) {
	s, ops := newSystemServer(t)
	leser := addUser(t, s, "leser", store.RoleReadOnly)
	cookie, csrf := login(t, s, leser)

	for _, pfad := range []string{"/alt/system-users/deploy/delete", "/alt/packages/upgrade"} {
		rec := post(t, s, pfad, url.Values{"_csrf": {csrf}}, cookie)
		if rec.Code != http.StatusForbidden {
			t.Errorf("%s: Status %d, erwartet 403", pfad, rec.Code)
		}
		if strings.Contains(rec.Body.String(), `name="bestaetigt"`) {
			t.Errorf("%s: die nur lesende Rolle bekommt die Rückfrage gezeigt", pfad)
		}
	}
	if got := ops.recorded(); len(got) != 0 {
		t.Fatalf("ausgeführt: %v", got)
	}
}
