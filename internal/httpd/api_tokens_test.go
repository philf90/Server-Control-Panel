package httpd

// Tests für /api/v1/tokens — die Verwaltung.
//
// Der Anmeldeweg selbst steht in tokenauth_test.go. Hier geht es um die Fläche,
// auf der Tokens entstehen und verschwinden, und um die vier Zusagen, die sie
// gibt:
//
//  1. **Der Klartext kommt genau einmal** — in der Antwort auf das Anlegen und
//     nirgends sonst. Keine zweite Abfrage bringt ihn zurück, und im Protokoll
//     steht er nie.
//  2. **Der Token hängt am eigenen Konto.** Es gibt kein Feld, mit dem sich ein
//     Admin einen Owner-Token ausstellt.
//  3. **Die Rückfrage sagt, was der Token darf**, bevor er da ist. Nicht „wirklich
//     anlegen?" — der gefährliche Fall ist der Token, den man in falscher Annahme
//     anlegt.
//  4. **Gesperrte Flächen werden benannt**, nicht verschwiegen: Wer einen Token für
//     die Kontoverwaltung sucht, soll erfahren, dass es den nicht gibt und warum.

import (
	"encoding/json"
	"net/http"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/store"
)

func holeTokens(t *testing.T, s *Server, cookie *http.Cookie) apiTokens {
	t.Helper()
	rec := get(t, s, "/api/v1/tokens", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var a apiTokens
	if err := json.Unmarshal(rec.Body.Bytes(), &a); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	return a
}

// anlegenPer legt über die Schnittstelle an und liefert die Antwort.
func anlegenPer(t *testing.T, s *Server, cookie *http.Cookie, csrf, koerper string) (int, apiTokenAntwort) {
	t.Helper()
	rec := postJSON(t, s, "/api/v1/tokens", koerper, cookie, csrf)
	var a apiTokenAntwort
	if rec.Code == http.StatusOK {
		if err := json.Unmarshal(rec.Body.Bytes(), &a); err != nil {
			t.Fatalf("Antwort ist kein JSON: %v", err)
		}
	}
	return rec.Code, a
}

// TestAPITokenAnlegenZeigtDenKlartextEinmal ist die Kernzusage: Der Token steht
// in der Antwort auf das Anlegen — und in keiner zweiten Abfrage, in keinem
// Protokolleintrag und in der Datenbank nur als Hash.
func TestAPITokenAnlegenZeigtDenKlartextEinmal(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)

	koerper := `{"name":"Sicherungsskript","scopes":["files","schedules"],` +
		`"nur_lesen":false,"tage":90,"bestaetigt":true}`
	code, antwort := anlegenPer(t, s, cookie, csrf, koerper)
	if code != http.StatusOK {
		t.Fatalf("Status = %d", code)
	}
	if antwort.Token == "" {
		t.Fatal("die Antwort trägt keinen Token")
	}
	if !strings.HasPrefix(antwort.Token, "asy_") {
		t.Errorf("Token = %q — ohne erkennbares Präfix fällt ein veröffentlichter nicht auf",
			antwort.Token[:min(8, len(antwort.Token))])
	}
	if !strings.Contains(antwort.Hinweis, "nicht noch einmal") {
		t.Errorf("der Hinweis sagt nicht, dass der Token nur einmal erscheint: %q", antwort.Hinweis)
	}

	// Die zweite Abfrage bringt ihn nicht zurück.
	liste := holeTokens(t, s, cookie)
	if len(liste.Tokens) != 1 {
		t.Fatalf("%d Tokens in der Liste", len(liste.Tokens))
	}
	roh, _ := json.Marshal(liste)
	if strings.Contains(string(roh), antwort.Token) {
		t.Error("die Liste enthält den Klartext des Tokens")
	}

	// In der Datenbank steht der Hash.
	tokens, err := s.db.ListAPITokens(t.Context())
	if err != nil {
		t.Fatal(err)
	}
	if tokens[0].Hash != auth.HashToken(antwort.Token) {
		t.Error("der gespeicherte Wert ist nicht der Hash des ausgegebenen Tokens")
	}

	// Und im Protokoll steht der Umfang, aber nicht der Token und nicht sein
	// sichtbarer Anfang.
	eintraege, err := s.db.ListAudit(t.Context(), 20)
	if err != nil {
		t.Fatal(err)
	}
	var gefunden bool
	for _, e := range eintraege {
		if e.Action != "token.create" {
			continue
		}
		gefunden = true
		if e.Target != "Sicherungsskript" {
			t.Errorf("Ziel = %q", e.Target)
		}
		if !strings.Contains(e.Detail, "files") || !strings.Contains(e.Detail, "90") {
			t.Errorf("dem Eintrag fehlt der Umfang: %q", e.Detail)
		}
		if strings.Contains(e.Detail, antwort.Token) ||
			strings.Contains(e.Detail, tokens[0].Prefix) {
			t.Errorf("der Token oder sein Anfang steht im Protokoll: %q", e.Detail)
		}
	}
	if !gefunden {
		t.Error("kein Protokolleintrag token.create")
	}
}

// TestAPITokenRueckfrageSagtWasErDarf: Der gefährliche Fall ist nicht der Klick,
// sondern der Token, den jemand in der Annahme anlegt, er dürfe nur lesen. Die
// Rückfrage muss den Umfang nennen — Rechte, Rolle, Frist und die gesperrten
// Flächen.
func TestAPITokenRueckfrageSagtWasErDarf(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)

	rec := postJSON(t, s, "/api/v1/tokens",
		`{"name":"Leser","scopes":["logs"],"nur_lesen":true,"tage":30}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	var antwort apiBestaetigungAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatal(err)
	}
	// Stufe 2: Der Token existiert erst nach dem Klick und ist sofort widerrufbar.
	if antwort.Bestaetigung.Tippen != "" {
		t.Errorf("Tippen = %q — Anlegen ist Stufe 2", antwort.Bestaetigung.Tippen)
	}

	ganz := antwort.Bestaetigung.Frage + " " + strings.Join(antwort.Bestaetigung.Punkte, " ")
	for _, teil := range []string{"Leser", "nur lesen", "logs", "admin", "30 Tage", "gesperrt"} {
		if !strings.Contains(ganz, teil) {
			t.Errorf("die Rückfrage nennt %q nicht:\n%s", teil, ganz)
		}
	}

	// Und nichts wurde angelegt.
	if tokens, _ := s.db.ListAPITokens(t.Context()); len(tokens) != 0 {
		t.Errorf("ohne Bestätigung angelegt: %+v", tokens)
	}
}

// TestAPITokenOhneAblaufWirdBenannt: „Ohne Ablauf" ist erlaubt und bleibt eine
// offene Rechnung. Die Rückfrage sagt es deutlich, und die Liste markiert es
// dauerhaft — verschweigen wäre der Unterschied zwischen einer Entscheidung und
// einem Versehen.
func TestAPITokenOhneAblaufWirdBenannt(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)

	rec := postJSON(t, s, "/api/v1/tokens",
		`{"name":"Dauerlaeufer","tage":0}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var frage apiBestaetigungAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &frage); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(strings.Join(frage.Bestaetigung.Punkte, " "), "NICHT ab") {
		t.Errorf("die Rückfrage verschweigt, dass der Token nicht abläuft: %v",
			frage.Bestaetigung.Punkte)
	}

	if code, _ := anlegenPer(t, s, cookie, csrf,
		`{"name":"Dauerlaeufer","tage":0,"bestaetigt":true}`); code != http.StatusOK {
		t.Fatalf("Status = %d", code)
	}

	liste := holeTokens(t, s, cookie)
	z := liste.Tokens[0]
	if z.Frist != "" {
		t.Errorf("Frist = %q — „ohne Ablauf\" ist ein eigener Zustand", z.Frist)
	}
	if z.Zustand != "warn" {
		t.Errorf("Zustand = %q, erwartet warn", z.Zustand)
	}
	if !strings.Contains(z.ZustandText, "ohne Ablauf") {
		t.Errorf("ZustandText = %q", z.ZustandText)
	}
}

// TestAPITokenHaengtAmEigenenKonto: Es gibt kein Feld, mit dem sich ein Admin
// einen Owner-Token ausstellt. Der Token erbt die Rolle des Anlegenden — und
// unbekannte Felder weist der Körperleser ohnehin ab.
func TestAPITokenHaengtAmEigenenKonto(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)
	fremd := addUser(t, s, "fremdkonto", store.RoleReadOnly)

	// Ein Versuch, das Konto vorzugeben: Der Körperleser weist unbekannte Felder
	// ab (DisallowUnknownFields), also endet das mit 400 und nicht mit einem
	// Token für ein fremdes Konto.
	rec := postJSON(t, s, "/api/v1/tokens",
		`{"name":"fremd","tage":30,"user_id":`+itoaTest(fremd.ID)+`,"bestaetigt":true}`,
		cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Status = %d, erwartet 400 für ein unbekanntes Feld: %s",
			rec.Code, rec.Body.String())
	}

	// Der reguläre Weg hängt den Token an das angemeldete Konto.
	if code, _ := anlegenPer(t, s, cookie, csrf,
		`{"name":"meiner","tage":30,"bestaetigt":true}`); code != http.StatusOK {
		t.Fatal("das Anlegen scheiterte")
	}
	liste := holeTokens(t, s, cookie)
	if liste.Tokens[0].Konto != "admin" {
		t.Errorf("Konto = %q, erwartet das angemeldete", liste.Tokens[0].Konto)
	}
	if !liste.Tokens[0].Ich {
		t.Error("der eigene Token ist nicht als eigener markiert")
	}
}

// TestAPITokenScopesWerdenGeprueft: Eine gesperrte Fläche wird als gesperrt
// gemeldet und eine unbekannte als unbekannt. Der Unterschied ist keine
// Feinheit — „gibt es nicht" schickt jemanden auf die Suche nach einem
// Tippfehler.
func TestAPITokenScopesWerdenGeprueft(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)

	rec := postJSON(t, s, "/api/v1/tokens",
		`{"name":"x","scopes":["tokens"],"tage":30,"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("gesperrte Fläche: Status = %d, erwartet 400", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), "gesperrt") {
		t.Errorf("die Meldung nennt den Grund nicht: %s", rec.Body.String())
	}

	rec = postJSON(t, s, "/api/v1/tokens",
		`{"name":"x","scopes":["diensteee"],"tage":30,"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("unbekannte Fläche: Status = %d, erwartet 400", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), "gibt es nicht") {
		t.Errorf("die Meldung unterscheidet nicht von „gesperrt\": %s", rec.Body.String())
	}

	// Eine unangebotene Frist auch nicht.
	rec = postJSON(t, s, "/api/v1/tokens",
		`{"name":"x","tage":7,"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("eigene Frist: Status = %d, erwartet 400", rec.Code)
	}

	// Und ein Token ohne Namen: In sechs Monaten ist der Name die einzige
	// Auskunft darüber, wozu er da war.
	rec = postJSON(t, s, "/api/v1/tokens", `{"name":"  ","tage":30,"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("ohne Namen: Status = %d, erwartet 400", rec.Code)
	}

	if tokens, _ := s.db.ListAPITokens(t.Context()); len(tokens) != 0 {
		t.Errorf("%d Tokens trotz ausschließlich abgewiesener Vorgaben", len(tokens))
	}
}

// TestAPITokenListeNenntDieGesperrten: Die Antwort trägt die gesperrten Flächen
// mit, damit die Oberfläche sie NENNEN kann. Sie zu verschweigen hieße, die Frage
// „warum kann mein Token keine Konten anlegen" unbeantwortet zu lassen.
func TestAPITokenListeNenntDieGesperrten(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleOwner)

	liste := holeTokens(t, s, cookie)
	if len(liste.Gesperrt) != 3 {
		t.Errorf("Gesperrt = %v, erwartet drei Flächen", liste.Gesperrt)
	}
	for _, f := range []string{"tokens", "panel-users", "account"} {
		if !enthaelt(liste.Gesperrt, f) {
			t.Errorf("%q fehlt in der Liste der gesperrten Flächen", f)
		}
	}
	// Jede wählbare Fläche trägt ihren Satz: „schedules" sagt einem Menschen
	// nichts.
	if len(liste.Familien) == 0 {
		t.Fatal("es gibt keine wählbaren Flächen")
	}
	for _, f := range liste.Familien {
		if f.Was == "" {
			t.Errorf("die Fläche %q trägt keine Erklärung", f.Wert)
		}
		if enthaelt(liste.Gesperrt, f.Wert) {
			t.Errorf("die gesperrte Fläche %q steht in der Auswahl", f.Wert)
		}
	}
	// Und die Fristen, kürzeste zuerst: Wer nicht nachdenkt, bekommt die engste.
	if len(liste.Fristen) == 0 || liste.Fristen[0].Tage != 30 {
		t.Errorf("Fristen = %v", liste.Fristen)
	}
	if liste.Praefix != "asy_" {
		t.Errorf("Praefix = %q", liste.Praefix)
	}
}

// TestAPITokenWiderrufen: Stufe 2, und die Rückfrage sagt die Folge — ein Skript,
// das ihn benutzt, hört auf zu laufen. Der Name wird NICHT getippt: Widerrufen
// macht das System sicherer, und eine Hürde vor der richtigen Handlung ist eine
// Hürde zu viel.
func TestAPITokenWiderrufen(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)
	if code, _ := anlegenPer(t, s, cookie, csrf,
		`{"name":"Nachtwache","tage":30,"bestaetigt":true}`); code != http.StatusOK {
		t.Fatal("das Anlegen scheiterte")
	}
	liste := holeTokens(t, s, cookie)
	id := itoaTest(liste.Tokens[0].ID)

	rec := postJSON(t, s, "/api/v1/tokens/"+id+"/revoke", `{}`, cookie, csrf)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
	}
	var frage apiBestaetigungAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &frage); err != nil {
		t.Fatal(err)
	}
	if frage.Bestaetigung.Tippen != "" {
		t.Errorf("Tippen = %q — Widerrufen ist Stufe 2", frage.Bestaetigung.Tippen)
	}
	punkte := strings.Join(frage.Bestaetigung.Punkte, " ")
	if !strings.Contains(punkte, "Automatisierung") {
		t.Errorf("die Rückfrage nennt die Folge nicht: %v", frage.Bestaetigung.Punkte)
	}
	if !strings.Contains(punkte, "noch nie benutzt") {
		t.Errorf("die Rückfrage sagt nicht, dass der Token nie benutzt wurde: %v",
			frage.Bestaetigung.Punkte)
	}

	// Nichts widerrufen ohne Bestätigung.
	if tokens, _ := s.db.ListAPITokens(t.Context()); len(tokens) != 1 {
		t.Error("ohne Bestätigung widerrufen")
	}

	rec = postJSON(t, s, "/api/v1/tokens/"+id+"/revoke", `{"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	if tokens, _ := s.db.ListAPITokens(t.Context()); len(tokens) != 0 {
		t.Error("der Token ist noch da")
	}

	// Ein zweiter Widerruf meldet keinen Fehler: zwei Fenster, zwei Klicks.
	rec = postJSON(t, s, "/api/v1/tokens/"+id+"/revoke", `{"bestaetigt":true}`, cookie, csrf)
	if rec.Code != http.StatusNotFound {
		t.Errorf("zweiter Widerruf = %d, erwartet 404 mit einer klaren Meldung: %s",
			rec.Code, rec.Body.String())
	}
}

// TestAPITokenNurOwner: Wer Tokens vergeben kann, vergibt Zugänge. Auch das Lesen
// gehört der Owner-Rolle: Die Liste sagt, welche Zugänge zu diesem Server bestehen.
func TestAPITokenNurOwner(t *testing.T) {
	for _, rolle := range []string{store.RoleReadOnly, store.RoleAdmin} {
		s, cookie, csrf := angemeldet(t, rolle)

		if rec := get(t, s, "/api/v1/tokens", cookie); rec.Code != http.StatusForbidden {
			t.Errorf("%s: Lesen = %d, erwartet 403", rolle, rec.Code)
		}
		rec := postJSON(t, s, "/api/v1/tokens", `{"name":"x","tage":30,"bestaetigt":true}`,
			cookie, csrf)
		if rec.Code != http.StatusForbidden {
			t.Errorf("%s: Anlegen = %d, erwartet 403", rolle, rec.Code)
		}
		rec = postJSON(t, s, "/api/v1/tokens/1/revoke", `{"bestaetigt":true}`, cookie, csrf)
		if rec.Code != http.StatusForbidden {
			t.Errorf("%s: Widerrufen = %d, erwartet 403", rolle, rec.Code)
		}
		if tokens, _ := s.db.ListAPITokens(t.Context()); len(tokens) != 0 {
			t.Errorf("%s: es wurde etwas angelegt", rolle)
		}
	}
}

// TestAPITokenZustand prüft die Rangfolge der Zustände einzeln. Sie ist überlegt:
// Abgelaufen zuerst, weil der Token dann gar nichts mehr tut — jede andere
// Auskunft wäre daneben.
func TestAPITokenZustand(t *testing.T) {
	jetzt := time.Now()
	gestern := jetzt.Add(-24 * time.Hour)
	naechsteWoche := jetzt.Add(7 * 24 * time.Hour)
	naechstesJahr := jetzt.Add(300 * 24 * time.Hour)

	faelle := []struct {
		name    string
		tok     store.APIToken
		zustand string
		imText  string
	}{
		{
			name:    "abgelaufen schlägt alles",
			tok:     store.APIToken{ExpiresAt: &gestern, LastUsedAt: &gestern},
			zustand: "schlecht", imText: "abgelaufen",
		},
		{
			name:    "ohne Ablauf ist eine Warnung",
			tok:     store.APIToken{LastUsedAt: &gestern},
			zustand: "warn", imText: "ohne Ablauf",
		},
		{
			name:    "läuft bald ab",
			tok:     store.APIToken{ExpiresAt: &naechsteWoche, LastUsedAt: &gestern},
			zustand: "warn", imText: "zwei Wochen",
		},
		{
			name:    "nie benutzt",
			tok:     store.APIToken{ExpiresAt: &naechstesJahr},
			zustand: "warn", imText: "nie benutzt",
		},
		{
			name:    "gültig und in Gebrauch",
			tok:     store.APIToken{ExpiresAt: &naechstesJahr, LastUsedAt: &gestern},
			zustand: "gut", imText: "gültig",
		},
	}
	for _, f := range faelle {
		zustand, text := tokenZustand(f.tok, jetzt)
		if zustand != f.zustand {
			t.Errorf("%s: Zustand = %q, erwartet %q", f.name, zustand, f.zustand)
		}
		if !strings.Contains(text, f.imText) {
			t.Errorf("%s: Text = %q, erwartet %q darin", f.name, text, f.imText)
		}
	}
}

// itoaTest ist strconv.FormatInt mit kürzerem Namen — in dieser Datei steht es
// dreimal mitten in einem Pfad.
func itoaTest(n int64) string {
	return strconv.FormatInt(n, 10)
}

// TestZuruecksetzenWiderruftTokens: Ein Token überlebt jeden Passwortwechsel —
// das ist seine Aufgabe, und genau deshalb ist er der Weg, mit dem eine Übernahme
// bestehen bleibt. Wer sich Zugang verschafft und einen Token anlegt, wäre nach
// einer Zurücksetzung weiter drin, während alle glauben, der Zugang sei
// geschlossen. Eine halbe Zurücksetzung ist schlimmer als keine, weil sie für eine
// ganze gehalten wird.
func TestZuruecksetzenWiderruftTokens(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)
	betroffen := addUser(t, s, "uebernommen", store.RoleAdmin)

	// Zwei Tokens des betroffenen Kontos und einer eines unbeteiligten.
	unbeteiligt := addUser(t, s, "unbeteiligt", store.RoleAdmin)
	legeToken(t, s, betroffen, func(tok *store.APIToken) { tok.Hash = auth.HashToken("a") })
	legeToken(t, s, betroffen, func(tok *store.APIToken) { tok.Hash = auth.HashToken("b") })
	legeToken(t, s, unbeteiligt, func(tok *store.APIToken) { tok.Hash = auth.HashToken("c") })

	rec := postJSON(t, s, "/api/v1/panel-users/"+itoaTest(betroffen.ID)+"/reset-password",
		`{"eigenes_passwort":"`+testPassword+`"}`, cookie, csrf)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}

	tokens, err := s.db.ListAPITokens(t.Context())
	if err != nil {
		t.Fatal(err)
	}
	if len(tokens) != 1 {
		t.Fatalf("%d Tokens übrig, erwartet 1 (den unbeteiligten): %+v", len(tokens), tokens)
	}
	if tokens[0].UserID != unbeteiligt.ID {
		t.Error("der Token des unbeteiligten Kontos wurde mitgenommen — eine " +
			"Zurücksetzung darf nicht die Automatisierung eines anderen abschalten")
	}

	// Und die Antwort sagt es: Ein widerrufener Token ist eine abgeschaltete
	// Automatisierung, und wer das nicht erfährt, sucht nächste Woche an der
	// falschen Stelle.
	var antwort apiPanelAntwort
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(antwort.Hinweis, "API-Tokens") {
		t.Errorf("der Hinweis verschweigt die widerrufenen Tokens: %q", antwort.Hinweis)
	}
}
