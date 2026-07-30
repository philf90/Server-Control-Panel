package httpd

// Tests für /api/v1/panel-users.
//
// Fünf Stellen, und alle fünf sind Schranken, nicht Bequemlichkeiten:
//
//  1. **Nur Owner.** Auch lesend. Ein Admin, der die Kontenliste sieht, weiß,
//     wen er angreifen müsste.
//  2. **Kein store.User im JSON.** Der Typ trägt PasswordHash und TOTPSecret.
//     Der Test sucht deshalb nicht nach Feldnamen, sondern nach den WERTEN im
//     rohen Körper — ein umbenanntes Feld rutschte sonst durch.
//  3. **Das eigene Konto läuft nicht über diesen Weg.** Sonst ist
//     Selbstaussperrung zwei Klicks entfernt.
//  4. **Das letzte Owner-Konto bleibt.** Und zwar mit einer Ablehnung VOR der
//     Rückfrage.
//  5. **Zurücksetzen verlangt das eigene Passwort des Owners.** Ein übernommenes
//     Cookie allein soll keine fremden Konten übernehmen können.

import (
	"encoding/json"
	"net/http"
	"slices"
	"strconv"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/store"
)

func holePanel(t *testing.T, s *Server, cookie *http.Cookie) apiPanelzugaenge {
	t.Helper()
	rec := get(t, s, "/api/v1/panel-users", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var a apiPanelzugaenge
	if err := json.Unmarshal(rec.Body.Bytes(), &a); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	return a
}

func panelkontoAusListe(t *testing.T, a apiPanelzugaenge, name string) apiPanelkonto {
	t.Helper()
	for _, k := range a.Konten {
		if k.Name == name {
			return k
		}
	}
	t.Fatalf("Konto %q fehlt in der Liste", name)
	return apiPanelkonto{}
}

func panelAntwortVon(t *testing.T, roh []byte) apiPanelAntwort {
	t.Helper()
	var a apiPanelAntwort
	if err := json.Unmarshal(roh, &a); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v (%s)", err, roh)
	}
	return a
}

// angemeldetAlsOwner liefert einen Server mit einem Owner und einem zweiten
// Konto, um das es in den Tests geht.
func angemeldetAlsOwner(t *testing.T) (s *Server, cookie *http.Cookie, csrf string, ziel store.User) {
	t.Helper()
	s = newTestServer(t)
	owner := addUser(t, s, "chef", store.RoleOwner)
	ziel = addUser(t, s, "mitarbeit", store.RoleAdmin)
	cookie, csrf = login(t, s, owner)
	return s, cookie, csrf, ziel
}

func panelPfad(ziel store.User, rest string) string {
	return "/api/v1/panel-users/" + strconv.FormatInt(ziel.ID, 10) + rest
}

// Die Liste ist der Owner-Rolle vorbehalten — auch lesend. Wer keine Konten
// verwalten darf, soll auch nicht wissen, welche es gibt.
func TestAPIPanelNurOwner(t *testing.T) {
	for _, rolle := range []string{store.RoleAdmin, store.RoleReadOnly} {
		t.Run(rolle, func(t *testing.T) {
			s, cookie, csrf := angemeldet(t, rolle)

			rec := get(t, s, "/api/v1/panel-users", cookie)
			if rec.Code != http.StatusForbidden {
				t.Errorf("GET als %s = %d, erwartet 403: %s", rolle, rec.Code, rec.Body.String())
			}
			rec = postJSON(t, s, "/api/v1/panel-users", `{"name":"neu","rolle":"admin"}`, cookie, csrf)
			if rec.Code != http.StatusForbidden {
				t.Errorf("POST als %s = %d, erwartet 403", rolle, rec.Code)
			}
			// Und die Begründung soll die richtige sein: „Owner-Rolle" und nicht
			// „Token fehlt". Sonst lädt die Oberfläche neu, holt ein frisches Token
			// und bekommt denselben Fehler wieder.
			if !strings.Contains(rec.Body.String(), "Owner") {
				t.Errorf("Fehlertext nennt die Rolle nicht: %s", rec.Body.String())
			}
		})
	}
}

// Kein Geheimnis im JSON. Der Test prüft nicht Feldnamen, sondern die Werte im
// rohen Körper: Ein umbenanntes Feld rutschte sonst durch.
func TestAPIPanelKeineGeheimnisse(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "chef", store.RoleOwner)
	ziel := addUser(t, s, "mitarbeit", store.RoleAdmin)
	cookie, _ := login(t, s, owner)

	rec := get(t, s, "/api/v1/panel-users", cookie)
	koerper := rec.Body.String()

	for was, wert := range map[string]string{
		"Passwort-Hash":  ziel.PasswordHash,
		"TOTP-Geheimnis": ziel.TOTPSecret,
	} {
		if wert == "" {
			t.Fatalf("Testaufbau: %s des Zielkontos ist leer", was)
		}
		if strings.Contains(koerper, wert) {
			t.Errorf("%s steht in der Antwort — store.User darf nie serialisiert werden", was)
		}
	}
	// Gegenprobe: Ein Teilstück des Hashes reicht, um zu erkennen, dass der Test
	// überhaupt etwas sieht. Der Argon2id-Kopf steht in jedem Hash.
	if strings.Contains(koerper, "$argon2id$") {
		t.Error("die Antwort enthält einen Argon2id-Kopf")
	}
}

// Die Liste, ihre Zähler und die Handgriffe je Zeile.
func TestAPIPanelListe(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "chef", store.RoleOwner)
	addUser(t, s, "mitarbeit", store.RoleAdmin)
	gesperrt := addUser(t, s, "pausiert", store.RoleReadOnly)
	if err := s.db.SetDisabled(t.Context(), gesperrt.ID, true); err != nil {
		t.Fatal(err)
	}
	cookie, _ := login(t, s, owner)

	a := holePanel(t, s, cookie)

	if a.Zaehler.Gesamt != 3 || a.Zaehler.Owner != 1 || a.Zaehler.Gesperrt != 1 {
		t.Errorf("Zähler = %+v, erwartet 3 gesamt / 1 owner / 1 gesperrt", a.Zaehler)
	}
	if a.Ich != owner.ID {
		t.Errorf("Ich = %d, erwartet %d", a.Ich, owner.ID)
	}
	if len(a.Rollen) != 3 {
		t.Errorf("%d Rollen angeboten, erwartet 3", len(a.Rollen))
	}
	for _, r := range a.Rollen {
		if r.Was == "" {
			t.Errorf("Rolle %q ohne Erklärung — „admin\" allein sagt nicht, was es bedeutet", r.Wert)
		}
	}

	// Das eigene Konto: erkannt, und ohne Handgriffe. Nicht weil es verboten wäre,
	// sondern weil sie woanders hingehören.
	ich := panelkontoAusListe(t, a, "chef")
	if !ich.Ich {
		t.Error("das eigene Konto ist nicht als solches gekennzeichnet")
	}
	if len(ich.Aktionen) != 0 {
		t.Errorf("das eigene Konto bietet %v an — erwartet keine Handgriffe", ich.Aktionen)
	}
	// Und es ist der letzte Owner: Löschen ist ausgeschlossen.
	if !ich.LetzterOwner {
		t.Error("der einzige Owner ist nicht als letzter gekennzeichnet")
	}

	// Ein fremdes Konto: sperren, löschen, zurücksetzen.
	fremd := panelkontoAusListe(t, a, "mitarbeit")
	for _, erwartet := range []string{panelAktionSperren, panelAktionLoeschen,
		panelAktionPasswort, panelAktionZweiterFaktor} {
		if !slices.Contains(fremd.Aktionen, erwartet) {
			t.Errorf("mitarbeit bietet %q nicht an: %v", erwartet, fremd.Aktionen)
		}
	}
	// Ohne Passkey kein Passkey-Handgriff — ein Knopf, der nichts findet, ist eine
	// Behauptung.
	if slices.Contains(fremd.Aktionen, panelAktionPasskeys) {
		t.Errorf("mitarbeit bietet %q an, hat aber keinen Passkey", panelAktionPasskeys)
	}

	// Ein gesperrtes Konto bietet freigeben statt sperren.
	pausiert := panelkontoAusListe(t, a, "pausiert")
	if !slices.Contains(pausiert.Aktionen, panelAktionFreigeben) ||
		slices.Contains(pausiert.Aktionen, panelAktionSperren) {
		t.Errorf("gesperrtes Konto bietet %v — erwartet freigeben statt sperren", pausiert.Aktionen)
	}
	if pausiert.Zustand != "schlecht" || pausiert.ZustandText != "gesperrt" {
		t.Errorf("gesperrtes Konto = %q/%q", pausiert.Zustand, pausiert.ZustandText)
	}
}

// Ein neues Konto bekommt ein Einmalpasswort, und es steht genau einmal in der
// Antwort — nie im Audit-Protokoll.
func TestAPIPanelAnlegen(t *testing.T) {
	s, cookie, csrf, _ := angemeldetAlsOwner(t)

	rec := postJSON(t, s, "/api/v1/panel-users", `{"name":"neuling","rolle":"readonly"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	a := panelAntwortVon(t, rec.Body.Bytes())
	if a.Einmalpasswort == "" {
		t.Fatal("kein Einmalpasswort in der Antwort")
	}
	if a.NeuesKonto != "neuling" {
		t.Errorf("NeuesKonto = %q", a.NeuesKonto)
	}
	if a.Konto == nil || a.Konto.Rolle != store.RoleReadOnly {
		t.Fatalf("Konto in der Antwort = %+v", a.Konto)
	}
	// Der neue Zugang muss durch die Einrichtung: Einmalpasswort und kein zweiter
	// Faktor.
	if !a.Konto.Einmalpasswort || a.Konto.ZweiterFaktor {
		t.Errorf("neues Konto = %+v, erwartet Einmalpasswort ohne bestätigten zweiten Faktor", a.Konto)
	}

	// Das Passwort steht NICHT im Protokoll. Dass zurückgesetzt wurde, schon.
	eintraege, err := s.db.ListAudit(t.Context(), 50)
	if err != nil {
		t.Fatal(err)
	}
	gefunden := false
	for _, e := range eintraege {
		if strings.Contains(e.Detail, a.Einmalpasswort) {
			t.Fatal("das Einmalpasswort steht im Audit-Protokoll")
		}
		if e.Action == "user.create" && e.Target == "neuling" {
			gefunden = true
		}
	}
	if !gefunden {
		t.Error("das Anlegen steht nicht im Audit-Protokoll")
	}

	// Zweimal derselbe Name geht nicht.
	rec = postJSON(t, s, "/api/v1/panel-users", `{"name":"neuling","rolle":"admin"}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("zweites Anlegen = %d, erwartet 400", rec.Code)
	}
	// Und ein unmöglicher Name auch nicht.
	rec = postJSON(t, s, "/api/v1/panel-users", `{"name":"ab","rolle":"admin"}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("zu kurzer Name = %d, erwartet 400", rec.Code)
	}
	rec = postJSON(t, s, "/api/v1/panel-users", `{"name":"gueltig","rolle":"gott"}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("unbekannte Rolle = %d, erwartet 400", rec.Code)
	}
}

// Sperren fragt nach (Stufe 2) und beendet danach die offenen Sitzungen. Ohne
// das wäre die Sperre erst wirksam, wenn das Cookie von selbst abläuft.
func TestAPIPanelSperrenBeendetSitzungen(t *testing.T) {
	s, cookie, csrf, ziel := angemeldetAlsOwner(t)
	zielCookie, _ := login(t, s, ziel)

	// Ohne Bestätigung: 409 mit dem Text der Frage.
	rec := postJSON(t, s, panelPfad(ziel, "/disabled"), `{"gesperrt":true}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("ohne Bestätigung = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	frage := frageVon(t, rec.Body.Bytes())
	if !strings.Contains(frage.Frage, ziel.Username) {
		t.Errorf("die Frage nennt das Konto nicht: %q", frage.Frage)
	}
	if frage.Tippen != "" {
		t.Errorf("Sperren verlangt Getipptes (%q) — es ist umkehrbar, Stufe 2 genügt", frage.Tippen)
	}

	// Die Sitzung des Ziels lebt noch.
	if rec := get(t, s, "/api/v1/session", zielCookie); rec.Code != http.StatusOK {
		t.Fatalf("Testaufbau: die Sitzung des Ziels ist schon tot (%d)", rec.Code)
	}

	rec = postJSON(t, s, panelPfad(ziel, "/disabled"), `{"gesperrt":true,"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("mit Bestätigung = %d: %s", rec.Code, rec.Body.String())
	}
	a := panelAntwortVon(t, rec.Body.Bytes())
	if a.Konto == nil || !a.Konto.Gesperrt {
		t.Errorf("Konto in der Antwort = %+v, erwartet gesperrt", a.Konto)
	}

	// Und jetzt ist die Sitzung des Ziels weg.
	if rec := get(t, s, "/api/v1/session", zielCookie); rec.Code == http.StatusOK {
		t.Error("die Sitzung des gesperrten Kontos lebt weiter")
	}

	// Freigeben fragt nicht nach: Es nimmt etwas zurück.
	rec = postJSON(t, s, panelPfad(ziel, "/disabled"), `{"gesperrt":false}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Freigeben = %d, erwartet 200 ohne Rückfrage: %s", rec.Code, rec.Body.String())
	}
	a = panelAntwortVon(t, rec.Body.Bytes())
	if a.Konto == nil || a.Konto.Gesperrt {
		t.Errorf("Konto nach Freigeben = %+v", a.Konto)
	}
}

// Löschen ist Stufe 3: Der Anmeldename muss getippt werden.
func TestAPIPanelLoeschen(t *testing.T) {
	s, cookie, csrf, ziel := angemeldetAlsOwner(t)

	rec := postJSON(t, s, panelPfad(ziel, "/delete"), `{}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("ohne Bestätigung = %d, erwartet 409", rec.Code)
	}
	frage := frageVon(t, rec.Body.Bytes())
	if frage.Tippen != ziel.Username {
		t.Errorf("Tippen = %q, erwartet %q", frage.Tippen, ziel.Username)
	}

	// Ein falsch getippter Name löscht nicht.
	rec = postJSON(t, s, panelPfad(ziel, "/delete"),
		`{"bestaetigt":true,"getippt":"mitarbeitt"}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Errorf("falsch getippt = %d, erwartet 409", rec.Code)
	}
	if _, err := s.db.UserByID(t.Context(), ziel.ID); err != nil {
		t.Fatalf("das Konto ist nach einem Fehltipper weg: %v", err)
	}

	rec = postJSON(t, s, panelPfad(ziel, "/delete"),
		`{"bestaetigt":true,"getippt":"mitarbeit"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Löschen = %d: %s", rec.Code, rec.Body.String())
	}
	a := panelAntwortVon(t, rec.Body.Bytes())
	if a.Konto != nil {
		t.Error("die Antwort trägt ein Konto, das es nicht mehr gibt")
	}
	if _, err := s.db.UserByID(t.Context(), ziel.ID); err == nil {
		t.Error("das Konto ist noch da")
	}
}

// Das letzte Owner-Konto bleibt — und die Ablehnung kommt VOR der Rückfrage.
// Eine Frage, deren Bestätigung dann abgelehnt wird, wäre eine Zumutung.
func TestAPIPanelLetzterOwnerBleibt(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "chef", store.RoleOwner)
	zweiter := addUser(t, s, "vertretung", store.RoleOwner)
	cookie, csrf := login(t, s, owner)

	// Zwei Owner: Der zweite lässt sich löschen.
	rec := postJSON(t, s, panelPfad(zweiter, "/delete"),
		`{"bestaetigt":true,"getippt":"vertretung"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("zweiten Owner löschen = %d: %s", rec.Code, rec.Body.String())
	}

	// Jetzt ist chef der letzte — und das eigene Konto obendrein. Beide Gründe
	// führen zu einer Ablehnung, und keiner davon zu einer Rückfrage.
	rec = postJSON(t, s, panelPfad(owner, "/delete"), `{}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("eigenes Konto löschen = %d, erwartet 400: %s", rec.Code, rec.Body.String())
	}

	// Und mit einem dritten Konto, das den letzten Owner löschen wollte, greift die
	// Owner-Prüfung: ein weiterer Owner, dann bleibt nur einer übrig.
	dritter := addUser(t, s, "aushilfe", store.RoleOwner)
	drittCookie, drittCSRF := login(t, s, dritter)
	// aushilfe löscht chef — danach ist aushilfe der letzte.
	rec = postJSON(t, s, panelPfad(owner, "/delete"),
		`{"bestaetigt":true,"getippt":"chef"}`, drittCookie, drittCSRF)
	if rec.Code != http.StatusOK {
		t.Fatalf("chef löschen = %d: %s", rec.Code, rec.Body.String())
	}
	// Und jetzt gibt es keinen zweiten mehr, den aushilfe löschen könnte — die
	// Liste sagt das auch.
	a := holePanel(t, s, drittCookie)
	if k := panelkontoAusListe(t, a, "aushilfe"); !k.LetzterOwner {
		t.Error("der übrige Owner ist nicht als letzter gekennzeichnet")
	}
}

// Das eigene Konto läuft nicht über diesen Weg — für alle vier Handgriffe.
func TestAPIPanelEigenesKontoAbgelehnt(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "chef", store.RoleOwner)
	cookie, csrf := login(t, s, owner)

	for _, weg := range []string{"/disabled", "/delete", "/reset-password", "/reset-2fa", "/reset-passkeys"} {
		rec := postJSON(t, s, panelPfad(owner, weg),
			`{"gesperrt":true,"bestaetigt":true,"getippt":"chef","eigenes_passwort":"`+testPassword+`"}`,
			cookie, csrf)
		if rec.Code != http.StatusBadRequest {
			t.Errorf("%s auf das eigene Konto = %d, erwartet 400: %s", weg, rec.Code, rec.Body.String())
		}
		if !strings.Contains(rec.Body.String(), "Kontoseite") {
			t.Errorf("%s: der Fehlertext verweist nicht auf die Kontoseite: %s", weg, rec.Body.String())
		}
	}
	// Und das Konto ist unverändert.
	u, err := s.db.UserByID(t.Context(), owner.ID)
	if err != nil {
		t.Fatal(err)
	}
	if u.Disabled || u.MustChangePassword || !u.TOTPConfirmed {
		t.Errorf("das eigene Konto hat sich verändert: %+v", u)
	}
}

// Zurücksetzen verlangt das eigene Passwort des Owners. Ein übernommenes
// Owner-Cookie allein soll keine fremden Konten übernehmen können.
func TestAPIPanelZuruecksetzenBrauchtEigenesPasswort(t *testing.T) {
	for _, weg := range []string{"/reset-password", "/reset-2fa", "/reset-passkeys"} {
		t.Run(weg, func(t *testing.T) {
			s, cookie, csrf, ziel := angemeldetAlsOwner(t)

			// Ohne Passwort: 403.
			rec := postJSON(t, s, panelPfad(ziel, weg), `{}`, cookie, csrf)
			if rec.Code != http.StatusForbidden {
				t.Errorf("ohne Passwort = %d, erwartet 403: %s", rec.Code, rec.Body.String())
			}
			// Mit falschem Passwort: auch 403, und im Protokoll steht der Versuch.
			rec = postJSON(t, s, panelPfad(ziel, weg), `{"eigenes_passwort":"daneben"}`, cookie, csrf)
			if rec.Code != http.StatusForbidden {
				t.Errorf("falsches Passwort = %d, erwartet 403", rec.Code)
			}
			eintraege, err := s.db.ListAudit(t.Context(), 10)
			if err != nil {
				t.Fatal(err)
			}
			abgelehnt := false
			for _, e := range eintraege {
				if e.Result == store.ResultDenied && e.Target == ziel.Username {
					abgelehnt = true
				}
				if strings.Contains(e.Detail, "daneben") {
					t.Error("das versuchte Passwort steht im Audit-Protokoll")
				}
			}
			if !abgelehnt {
				t.Error("der abgelehnte Versuch steht nicht im Audit-Protokoll")
			}

			// Das Zielkonto ist unangetastet.
			u, err := s.db.UserByID(t.Context(), ziel.ID)
			if err != nil {
				t.Fatal(err)
			}
			if u.MustChangePassword || !u.TOTPConfirmed {
				t.Errorf("das Zielkonto hat sich ohne gültiges Passwort verändert: %+v", u)
			}
		})
	}
}

// Ein Einmalpasswort beendet die Sitzungen des Ziels und hebt eine Sperre auf:
// Wer ein neues Passwort bekommt, soll sich damit auch anmelden können.
func TestAPIPanelPasswortZuruecksetzen(t *testing.T) {
	s, cookie, csrf, ziel := angemeldetAlsOwner(t)
	zielCookie, _ := login(t, s, ziel)
	if err := s.db.SetDisabled(t.Context(), ziel.ID, true); err != nil {
		t.Fatal(err)
	}

	rec := postJSON(t, s, panelPfad(ziel, "/reset-password"),
		`{"eigenes_passwort":"`+testPassword+`"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	a := panelAntwortVon(t, rec.Body.Bytes())
	if a.Einmalpasswort == "" {
		t.Fatal("kein Einmalpasswort in der Antwort")
	}
	if a.Konto == nil || !a.Konto.Einmalpasswort || a.Konto.Gesperrt {
		t.Errorf("Konto = %+v, erwartet Einmalpasswort und aufgehobene Sperre", a.Konto)
	}
	if rec := get(t, s, "/api/v1/session", zielCookie); rec.Code == http.StatusOK {
		t.Error("die Sitzung des zurückgesetzten Kontos lebt weiter")
	}

	// Das Passwort steht nicht im Protokoll — nur, dass zurückgesetzt wurde.
	eintraege, err := s.db.ListAudit(t.Context(), 20)
	if err != nil {
		t.Fatal(err)
	}
	for _, e := range eintraege {
		if strings.Contains(e.Detail, a.Einmalpasswort) {
			t.Fatal("das Einmalpasswort steht im Audit-Protokoll")
		}
	}
}

// Der zweite Faktor wird unbestätigt, und die alten Wiederherstellungscodes
// gehen mit: Sie gehörten zum alten Geheimnis.
func TestAPIPanelZweitenFaktorZuruecksetzen(t *testing.T) {
	s, cookie, csrf, ziel := angemeldetAlsOwner(t)
	if err := s.db.ReplaceRecoveryCodes(t.Context(), ziel.ID,
		[]string{"hash-eins", "hash-zwei"}); err != nil {
		t.Fatal(err)
	}
	vorher, err := s.db.UserByID(t.Context(), ziel.ID)
	if err != nil {
		t.Fatal(err)
	}

	rec := postJSON(t, s, panelPfad(ziel, "/reset-2fa"),
		`{"eigenes_passwort":"`+testPassword+`"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	a := panelAntwortVon(t, rec.Body.Bytes())
	if a.Konto == nil || a.Konto.ZweiterFaktor {
		t.Errorf("Konto = %+v, erwartet unbestätigten zweiten Faktor", a.Konto)
	}
	// Und das Passwort bleibt: Der zweite Faktor ist zurückgesetzt, nicht das Konto.
	nachher, err := s.db.UserByID(t.Context(), ziel.ID)
	if err != nil {
		t.Fatal(err)
	}
	if nachher.PasswordHash != vorher.PasswordHash {
		t.Error("das Passwort hat sich mitgeändert")
	}
	if nachher.TOTPSecret == vorher.TOTPSecret {
		t.Error("das TOTP-Geheimnis ist dasselbe geblieben")
	}
	offen, err := s.db.CountUnusedRecoveryCodes(t.Context(), ziel.ID)
	if err != nil {
		t.Fatal(err)
	}
	if offen != 0 {
		t.Errorf("%d Wiederherstellungscodes übrig — sie gehörten zum alten Geheimnis", offen)
	}
	// Das neue Geheimnis steht nicht in der Antwort. Es gehört dem Konto, nicht
	// dem Owner; eingerichtet wird es beim nächsten Anmelden.
	if strings.Contains(rec.Body.String(), nachher.TOTPSecret) {
		t.Error("das neue TOTP-Geheimnis steht in der Antwort")
	}
}
