package httpd

// Tests für /api/v1/account.
//
// Sechs Stellen sind hier prüfenswert, und keine davon ist Kosmetik:
//
//  1. **Jede Rolle darf ihr eigenes Konto verwalten.** Kein apiSchreibend.
//     Sonst bliebe ein Konto mit Leserecht auf dem Einmalpasswort sitzen, mit
//     dem es angelegt wurde.
//  2. **Die eigene Sitzung überlebt die eigene Passwortänderung.** Alle
//     Sitzungen des Kontos werden beendet, die eigene wird neu aufgebaut, und
//     die Antwort trägt das frische CSRF-Token. Ohne beides wäre die Oberfläche
//     nach einer geglückten Änderung abgemeldet.
//  3. **Jede Änderung an einem Anmeldeweg verlangt das aktuelle Passwort.**
//     Passwort, zweiter Faktor, Passkey.
//  4. **Der halbe Wechsel des zweiten Faktors bleibt aus der Datenbank
//     heraus.** Wer abbricht, muss sich mit dem alten Faktor anmelden können.
//  5. **Fremde Sitzungen und fremde Passkeys sind unerreichbar**, auch mit der
//     richtigen Kennung.
//  6. **Codes und Passwörter stehen nie im Protokoll.**

import (
	"encoding/json"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/store"
)

func holeEigenesKonto(t *testing.T, s *Server, cookie *http.Cookie) apiEigenesKonto {
	t.Helper()
	rec := get(t, s, "/api/v1/account", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var a apiEigenesKonto
	if err := json.Unmarshal(rec.Body.Bytes(), &a); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	return a
}

func eigenAntwortVon(t *testing.T, roh []byte) apiEigenAntwort {
	t.Helper()
	var a apiEigenAntwort
	if err := json.Unmarshal(roh, &a); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v (%s)", err, roh)
	}
	return a
}

// Jede Rolle darf ihr eigenes Konto verwalten — auch „readonly". Das ist der
// Unterschied zu allen anderen schreibenden Endpunkten der Schnittstelle.
func TestAPIKontoJedeRolleDarfDasEigene(t *testing.T) {
	for _, rolle := range []string{store.RoleOwner, store.RoleAdmin, store.RoleReadOnly} {
		t.Run(rolle, func(t *testing.T) {
			s, cookie, csrf := angemeldet(t, rolle)

			a := holeEigenesKonto(t, s, cookie)
			if a.Rolle != rolle {
				t.Errorf("Rolle = %q, erwartet %q", a.Rolle, rolle)
			}
			if a.RolleWas == "" {
				t.Error("die Rolle steht ohne Erklärung da")
			}

			// Und das eigene Passwort lässt sich wechseln.
			rec := postJSON(t, s, "/api/v1/account/password", `{
				"passwort":"`+testPassword+`",
				"neu":"ein anderes langes Passwort",
				"neu_wiederholt":"ein anderes langes Passwort"}`, cookie, csrf)
			if rec.Code != http.StatusOK {
				t.Fatalf("Passwortwechsel als %s = %d: %s", rolle, rec.Code, rec.Body.String())
			}
		})
	}
}

// Ohne Sitzungstoken geht nichts — auch hier nicht.
func TestAPIKontoBrauchtToken(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleAdmin)

	rec := postJSON(t, s, "/api/v1/account/recovery-codes", `{"bestaetigt":true}`, cookie, "")
	if rec.Code != http.StatusForbidden {
		t.Errorf("ohne Token = %d, erwartet 403: %s", rec.Code, rec.Body.String())
	}
}

// Die eigene Sitzung überlebt die eigene Passwortänderung — mit frischem Token.
//
// Der Kern dieses Moduls: Alle Sitzungen des Kontos werden beendet, die eigene
// wird neu aufgebaut. Ohne den Neuaufbau wäre die Oberfläche nach einer
// geglückten Änderung abgemeldet; ohne das frische Token schlüge der nächste
// schreibende Aufruf fehl.
func TestAPIKontoPasswortErneuertDieEigeneSitzung(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	// Eine zweite Sitzung desselben Kontos — sie muss weg sein.
	zweite, _ := login(t, s, user)

	rec := postJSON(t, s, "/api/v1/account/password", `{
		"passwort":"`+testPassword+`",
		"neu":"ein frisches langes Passwort",
		"neu_wiederholt":"ein frisches langes Passwort"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	a := eigenAntwortVon(t, rec.Body.Bytes())

	// Ein neuer Cookie ist gesetzt, und er ist ein anderer.
	var neuerCookie *http.Cookie
	for _, c := range rec.Result().Cookies() {
		if c.Name == sessionCookie {
			neuerCookie = c
		}
	}
	if neuerCookie == nil {
		t.Fatal("es wurde kein neuer Sitzungscookie gesetzt — die Oberfläche wäre abgemeldet")
	}
	if neuerCookie.Value == cookie.Value {
		t.Error("der Sitzungscookie ist derselbe geblieben")
	}
	// Und das frische Token steht in der Antwort.
	if a.CSRF == "" {
		t.Fatal("die Antwort trägt kein frisches Sitzungstoken — der nächste " +
			"schreibende Aufruf schlüge fehl")
	}
	if a.CSRF == csrf {
		t.Error("das Sitzungstoken ist dasselbe geblieben")
	}
	if a.Abgemeldet {
		t.Error("die Antwort meldet eine Abmeldung, obwohl die Sitzung erneuert wurde")
	}

	// Die neue Sitzung trägt: derselbe Aufruf mit dem neuen Cookie und dem neuen
	// Token muss durchgehen. DAS ist die Prüfung, die zählt — ein gesetzter Cookie
	// allein sagt nichts darüber, ob er gilt.
	rec = postJSON(t, s, "/api/v1/account/recovery-codes",
		`{"bestaetigt":true}`, neuerCookie, a.CSRF)
	if rec.Code != http.StatusOK {
		t.Errorf("mit der erneuerten Sitzung = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}

	// Die zweite Sitzung ist beendet.
	if rec := get(t, s, "/api/v1/session", zweite); rec.Code == http.StatusOK {
		t.Error("die zweite Sitzung lebt nach der Passwortänderung weiter")
	}
	// Und das alte Passwort gilt nicht mehr.
	nachher, err := s.db.UserByID(t.Context(), user.ID)
	if err != nil {
		t.Fatal(err)
	}
	alt, _ := auth.VerifyPassword(testPassword, nachher.PasswordHash)
	if alt {
		t.Error("das alte Passwort gilt weiter")
	}
	neuGueltig, _ := auth.VerifyPassword("ein frisches langes Passwort", nachher.PasswordHash)
	if !neuGueltig {
		t.Error("das neue Passwort gilt nicht")
	}
	// Ein Wechselzwang aus einer Zurücksetzung ist damit erledigt.
	if nachher.MustChangePassword {
		t.Error("nach der selbst gewählten Änderung steht ein Wechselzwang an")
	}
}

// Das Passwort wird geprüft, die Richtlinie gilt, und die Wiederholung muss
// stimmen. Nichts davon darf das gespeicherte Passwort anfassen.
func TestAPIKontoPasswortAbgelehnt(t *testing.T) {
	faelle := []struct {
		was     string
		koerper string
		status  int
		imText  string
	}{
		{"falsches aktuelles Passwort", `{"passwort":"daneben","neu":"ein langes neues Passwort",
			"neu_wiederholt":"ein langes neues Passwort"}`, http.StatusForbidden, "stimmt nicht"},
		{"kein aktuelles Passwort", `{"neu":"ein langes neues Passwort",
			"neu_wiederholt":"ein langes neues Passwort"}`, http.StatusForbidden, "aktuelles Passwort"},
		{"Wiederholung weicht ab", `{"passwort":"` + testPassword + `",
			"neu":"ein langes neues Passwort","neu_wiederholt":"ein anderes"}`,
			http.StatusBadRequest, "überein"},
		{"zu kurz", `{"passwort":"` + testPassword + `","neu":"kurz","neu_wiederholt":"kurz"}`,
			http.StatusBadRequest, "Zeichen"},
		{"enthält den Anmeldenamen", `{"passwort":"` + testPassword + `",
			"neu":"philipp philipp philipp","neu_wiederholt":"philipp philipp philipp"}`,
			http.StatusBadRequest, "Anmeldenamen"},
	}
	for _, f := range faelle {
		t.Run(f.was, func(t *testing.T) {
			s := newTestServer(t)
			user := addUser(t, s, "philipp", store.RoleOwner)
			cookie, csrf := login(t, s, user)

			rec := postJSON(t, s, "/api/v1/account/password", f.koerper, cookie, csrf)
			if rec.Code != f.status {
				t.Errorf("Status = %d, erwartet %d: %s", rec.Code, f.status, rec.Body.String())
			}
			if !strings.Contains(rec.Body.String(), f.imText) {
				t.Errorf("der Fehlertext nennt %q nicht: %s", f.imText, rec.Body.String())
			}
			// Das Passwort ist unangetastet, und die Sitzung lebt.
			nachher, err := s.db.UserByID(t.Context(), user.ID)
			if err != nil {
				t.Fatal(err)
			}
			if nachher.PasswordHash != user.PasswordHash {
				t.Error("das Passwort wurde trotz Ablehnung geändert")
			}
			if rec := get(t, s, "/api/v1/session", cookie); rec.Code != http.StatusOK {
				t.Error("die Sitzung wurde trotz Ablehnung beendet")
			}
		})
	}
}

// Neue Wiederherstellungscodes: Stufe 2, genau einmal angezeigt, nie im
// Protokoll.
func TestAPIKontoCodes(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	rec := postJSON(t, s, "/api/v1/account/recovery-codes", `{}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("ohne Bestätigung = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	frage := frageVon(t, rec.Body.Bytes())
	if !strings.Contains(frage.Frage, "nicht mehr") {
		t.Errorf("die Frage sagt nicht, dass die alten Codes verfallen: %q", frage.Frage)
	}

	rec = postJSON(t, s, "/api/v1/account/recovery-codes", `{"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	a := eigenAntwortVon(t, rec.Body.Bytes())
	if len(a.Codes) == 0 {
		t.Fatal("keine Codes in der Antwort")
	}
	if a.Konto == nil || a.Konto.CodesOffen != len(a.Codes) {
		t.Errorf("Konto = %+v, erwartet %d offene Codes", a.Konto, len(a.Codes))
	}

	// Kein Code steht im Protokoll.
	for _, e := range mustAudit(t, s) {
		for _, code := range a.Codes {
			if strings.Contains(e.Detail, code) {
				t.Fatal("ein Wiederherstellungscode steht im Audit-Protokoll")
			}
		}
	}

	// Ein zweiter Aufruf erzeugt andere Codes — die alten gelten nicht mehr.
	rec = postJSON(t, s, "/api/v1/account/recovery-codes", `{"bestaetigt":true}`, cookie, csrf)
	zweite := eigenAntwortVon(t, rec.Body.Bytes())
	if len(zweite.Codes) > 0 && zweite.Codes[0] == a.Codes[0] {
		t.Error("der zweite Aufruf liefert dieselben Codes")
	}
	_ = user
}

// Der Wechsel des zweiten Faktors: Das neue Geheimnis bleibt bis zur Bestätigung
// aus der Datenbank heraus, der QR-Code hängt am begonnenen Wechsel, und der
// Abschluss liefert neue Codes.
func TestAPIKontoZweiterFaktorWechsel(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	zweite, _ := login(t, s, user)
	altesGeheimnis := user.TOTPSecret

	// Ohne begonnenen Wechsel gibt es keinen QR-Code.
	if rec := get(t, s, "/api/v1/account/2fa/qr.png", cookie); rec.Code != http.StatusForbidden {
		t.Errorf("QR-Code ohne Wechsel = %d, erwartet 403", rec.Code)
	}

	// Ohne Passwort kein Wechsel.
	rec := postJSON(t, s, "/api/v1/account/2fa", `{}`, cookie, csrf)
	if rec.Code != http.StatusForbidden {
		t.Fatalf("ohne Passwort = %d, erwartet 403: %s", rec.Code, rec.Body.String())
	}

	rec = postJSON(t, s, "/api/v1/account/2fa", `{"passwort":"`+testPassword+`"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Start = %d: %s", rec.Code, rec.Body.String())
	}
	a := eigenAntwortVon(t, rec.Body.Bytes())
	if a.Konto == nil || a.Konto.Wechsel == nil {
		t.Fatal("der begonnene Wechsel steht nicht in der Antwort")
	}
	wechsel := a.Konto.Wechsel
	if wechsel.Geheimnis == "" || wechsel.GeheimnisText == "" || wechsel.URI == "" {
		t.Errorf("der Wechsel ist unvollständig: %+v", wechsel)
	}
	if wechsel.LaeuftAb == "" {
		t.Error("es steht nicht, wie lange der Wechsel gilt — ein abgelaufener sähe " +
			"dann wie ein Fehler des Panels aus")
	}
	// Das Geheimnis liegt NICHT in der Datenbank.
	stored, err := s.db.UserByID(t.Context(), user.ID)
	if err != nil {
		t.Fatal(err)
	}
	if stored.TOTPSecret != altesGeheimnis {
		t.Fatal("das Geheimnis wurde vor der Bestätigung getauscht — wer abbricht, " +
			"käme nicht mehr herein")
	}
	// Und ein Neuladen zeigt ihn wieder: Der Zustand liegt auf dem Server.
	if holeEigenesKonto(t, s, cookie).Wechsel == nil {
		t.Error("nach dem Neuladen ist der begonnene Wechsel verschwunden")
	}
	// Jetzt gibt es den QR-Code.
	if rec := get(t, s, "/api/v1/account/2fa/qr.png", cookie); rec.Code != http.StatusOK {
		t.Errorf("QR-Code = %d, erwartet 200", rec.Code)
	} else if ct := rec.Header().Get("Content-Type"); ct != "image/png" {
		t.Errorf("QR-Code hat den Typ %q", ct)
	}

	// Ein falscher Code stellt nichts um.
	rec = postJSON(t, s, "/api/v1/account/2fa/confirm", `{"code":"000000"}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("falscher Code = %d, erwartet 400", rec.Code)
	}
	stored, _ = s.db.UserByID(t.Context(), user.ID)
	if stored.TOTPSecret != altesGeheimnis {
		t.Fatal("ein falscher Code hat den Faktor gewechselt")
	}

	// Der richtige Code schließt ab.
	code, err := auth.TOTPCode(wechsel.Geheimnis, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	rec = postJSON(t, s, "/api/v1/account/2fa/confirm", `{"code":"`+code+`"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Bestätigung = %d: %s", rec.Code, rec.Body.String())
	}
	fertig := eigenAntwortVon(t, rec.Body.Bytes())
	if len(fertig.Codes) == 0 {
		t.Error("nach dem Wechsel fehlen die neuen Wiederherstellungscodes — die alten " +
			"gehörten zum alten Faktor")
	}
	if fertig.Konto == nil || fertig.Konto.Wechsel != nil {
		t.Error("der begonnene Wechsel steht noch in der Antwort")
	}

	stored, _ = s.db.UserByID(t.Context(), user.ID)
	if stored.TOTPSecret != wechsel.Geheimnis {
		t.Error("das neue Geheimnis wurde nicht gespeichert")
	}
	if !stored.TOTPConfirmed {
		t.Error("der neue Faktor gilt nicht als bestätigt")
	}
	// Andere Sitzungen sind beendet, diese nicht.
	if rec := get(t, s, "/api/v1/session", zweite); rec.Code == http.StatusOK {
		t.Error("eine andere Sitzung lebt nach dem Wechsel weiter")
	}
	if rec := get(t, s, "/api/v1/session", cookie); rec.Code != http.StatusOK {
		t.Error("die eigene Sitzung wurde mitbeendet")
	}
}

// Der Abbruch verwirft den halben Wechsel. Diesen Weg gab es in der alten
// Oberfläche nicht — dort verließ man die Seite und wartete die Frist ab.
func TestAPIKontoZweiterFaktorAbbruch(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	// Ohne begonnenen Wechsel gibt es nichts abzubrechen.
	rec := postJSON(t, s, "/api/v1/account/2fa/cancel", `{}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Abbruch ohne Wechsel = %d, erwartet 400", rec.Code)
	}

	rec = postJSON(t, s, "/api/v1/account/2fa", `{"passwort":"`+testPassword+`"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Start = %d: %s", rec.Code, rec.Body.String())
	}
	rec = postJSON(t, s, "/api/v1/account/2fa/cancel", `{}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Abbruch = %d: %s", rec.Code, rec.Body.String())
	}
	a := eigenAntwortVon(t, rec.Body.Bytes())
	if a.Konto == nil || a.Konto.Wechsel != nil {
		t.Error("nach dem Abbruch steht der Wechsel noch da")
	}
	if _, offen := s.pending.get(user.ID); offen {
		t.Error("das halbe Geheimnis liegt noch im Speicher")
	}
	// Der alte Faktor gilt weiter.
	stored, _ := s.db.UserByID(t.Context(), user.ID)
	if stored.TOTPSecret != user.TOTPSecret || !stored.TOTPConfirmed {
		t.Error("der Abbruch hat den bestehenden Faktor angefasst")
	}
}

// Die Sitzungsliste, das Beenden einer einzelnen und das Beenden aller anderen.
func TestAPIKontoSitzungen(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	zweite, _ := login(t, s, user)
	dritte, _ := login(t, s, user)

	a := holeEigenesKonto(t, s, cookie)
	if len(a.Sitzungen) != 3 {
		t.Fatalf("%d Sitzungen, erwartet 3", len(a.Sitzungen))
	}
	if a.Andere != 2 {
		t.Errorf("Andere = %d, erwartet 2", a.Andere)
	}
	diese := 0
	for _, sz := range a.Sitzungen {
		if sz.Diese {
			diese++
		}
		if sz.Kurz == "" || len(sz.Kurz) > 12 {
			t.Errorf("die kurze Kennung ist %q — niemand liest 64 Zeichen", sz.Kurz)
		}
		if sz.Zuletzt == "" || sz.Laeuft == "" {
			t.Errorf("Sitzung ohne Zeitangaben: %+v — erst Adresse und letzte "+
				"Aktivität machen eine übernommene Sitzung sichtbar", sz)
		}
	}
	if diese != 1 {
		t.Errorf("%d Sitzungen sind als „diese\" markiert, erwartet genau eine", diese)
	}

	// Eine einzelne beenden.
	var zweiteID string
	for _, sz := range a.Sitzungen {
		if !sz.Diese && zweiteID == "" {
			zweiteID = sz.ID
		}
	}
	rec := postJSON(t, s, "/api/v1/account/sessions/revoke",
		`{"sitzung":"`+zweiteID+`"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Beenden = %d: %s", rec.Code, rec.Body.String())
	}
	nach := eigenAntwortVon(t, rec.Body.Bytes())
	if nach.Konto == nil || len(nach.Konto.Sitzungen) != 2 {
		t.Errorf("nach dem Beenden = %+v, erwartet 2 Sitzungen", nach.Konto)
	}
	if nach.Abgemeldet {
		t.Error("das Beenden einer FREMDEN Sitzung meldet eine Abmeldung")
	}

	// Eine Kennung, die es nicht gibt: 404, keine 500.
	rec = postJSON(t, s, "/api/v1/account/sessions/revoke", `{"sitzung":"gibtsnicht"}`, cookie, csrf)
	if rec.Code != http.StatusNotFound {
		t.Errorf("unbekannte Kennung = %d, erwartet 404", rec.Code)
	}

	// Alle anderen beenden — Stufe 2.
	rec = postJSON(t, s, "/api/v1/account/sessions/revoke-others", `{}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("ohne Bestätigung = %d, erwartet 409", rec.Code)
	}
	rec = postJSON(t, s, "/api/v1/account/sessions/revoke-others",
		`{"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Beenden aller anderen = %d: %s", rec.Code, rec.Body.String())
	}
	// Die eigene lebt, die anderen nicht.
	if rec := get(t, s, "/api/v1/session", cookie); rec.Code != http.StatusOK {
		t.Error("die eigene Sitzung wurde mitbeendet")
	}
	for was, c := range map[string]*http.Cookie{"zweite": zweite, "dritte": dritte} {
		if rec := get(t, s, "/api/v1/session", c); rec.Code == http.StatusOK {
			t.Errorf("die %s Sitzung lebt weiter", was)
		}
	}
	_ = user
}

// Die eigene Sitzung zu beenden ist ein Abmelden — und die Antwort sagt das,
// statt eine Weiterleitung auf eine HTML-Seite zu schicken, die ein fetch als
// Erfolg missversteht.
func TestAPIKontoEigeneSitzungBeenden(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	a := holeEigenesKonto(t, s, cookie)
	eigene := ""
	for _, sz := range a.Sitzungen {
		if sz.Diese {
			eigene = sz.ID
		}
	}
	rec := postJSON(t, s, "/api/v1/account/sessions/revoke",
		`{"sitzung":"`+eigene+`"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	antwort := eigenAntwortVon(t, rec.Body.Bytes())
	if !antwort.Abgemeldet {
		t.Error("die Antwort meldet keine Abmeldung — die Oberfläche zeigte dann eine " +
			"Liste, die es nicht mehr gibt")
	}
	if rec := get(t, s, "/api/v1/session", cookie); rec.Code == http.StatusOK {
		t.Error("die Sitzung lebt weiter")
	}
}

// Eine fremde Sitzung lässt sich nicht beenden, auch mit der richtigen Kennung:
// Die Abfrage bindet sie an den eigenen Benutzer.
func TestAPIKontoFremdeSitzungUnerreichbar(t *testing.T) {
	s := newTestServer(t)
	ich := addUser(t, s, "philipp", store.RoleOwner)
	fremd := addUser(t, s, "fremd", store.RoleAdmin)
	cookie, csrf := login(t, s, ich)
	fremdCookie, _ := login(t, s, fremd)

	fremdeSitzungen, err := s.db.ListUserSessions(t.Context(), fremd.ID)
	if err != nil || len(fremdeSitzungen) != 1 {
		t.Fatalf("Testaufbau: %d fremde Sitzungen (%v)", len(fremdeSitzungen), err)
	}

	rec := postJSON(t, s, "/api/v1/account/sessions/revoke",
		`{"sitzung":"`+fremdeSitzungen[0].ID+`"}`, cookie, csrf)
	if rec.Code != http.StatusNotFound {
		t.Errorf("fremde Sitzung = %d, erwartet 404: %s", rec.Code, rec.Body.String())
	}
	if rec := get(t, s, "/api/v1/session", fremdCookie); rec.Code != http.StatusOK {
		t.Error("die fremde Sitzung wurde beendet")
	}
	// Und im Protokoll steht der abgelehnte Versuch.
	abgelehnt := false
	for _, e := range mustAudit(t, s) {
		if e.Action == "session.revoke" && e.Result == store.ResultDenied {
			abgelehnt = true
		}
	}
	if !abgelehnt {
		t.Error("der abgelehnte Versuch steht nicht im Audit-Protokoll")
	}
}

// Ohne eingeschaltete Passkeys sagt die Schnittstelle das, statt einen Weg
// anzubieten, der ins Leere führt.
func TestAPIKontoPasskeysAus(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)
	if s.passkeys != nil {
		t.Fatal("Testaufbau: Passkeys sind eingeschaltet")
	}

	a := holeEigenesKonto(t, s, cookie)
	if a.PasskeysMoeglich {
		t.Error("passkeys_moeglich ist gesetzt, obwohl sie fehlen")
	}
	if len(a.Passkeys) != 0 {
		t.Errorf("%d Passkeys ohne eingeschaltete Passkeys", len(a.Passkeys))
	}

	rec := postJSON(t, s, "/api/v1/account/passkeys/register/begin",
		`{"passwort":"`+testPassword+`","name":"Telefon"}`, cookie, csrf)
	if rec.Code != http.StatusNotFound {
		t.Errorf("Registrierung = %d, erwartet 404: %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "eingeschaltet") {
		t.Errorf("der Fehlertext sagt nicht, dass Passkeys abgeschaltet sind: %s", rec.Body.String())
	}
}

// Die Beschriftung wird auf ZEICHEN gekürzt, nicht auf Bytes: Ein Schnitt mitten
// in einem Umlaut ergibt ein ungültiges Zeichen.
func TestPasskeyLabelKuerztAufZeichen(t *testing.T) {
	lang := strings.Repeat("ä", 80)
	gekuerzt := passkeyLabel(lang)
	if len([]rune(gekuerzt)) != 60 {
		t.Errorf("%d Zeichen, erwartet 60", len([]rune(gekuerzt)))
	}
	if !utf8Gueltig(gekuerzt) {
		t.Error("die gekürzte Beschriftung ist kein gültiges UTF-8")
	}
	if passkeyLabel("   ") != "Passkey" {
		t.Errorf("eine leere Beschriftung wird zu %q, erwartet „Passkey\"", passkeyLabel("   "))
	}
}

func utf8Gueltig(s string) bool {
	for _, r := range s {
		if r == '�' {
			return false
		}
	}
	return true
}
