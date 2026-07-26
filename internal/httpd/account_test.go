package httpd

import (
	"context"
	"net/http"
	"net/url"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/store"
)

// ------------------------------------------------- Zweiter Faktor wechseln ---

func TestTOTPWechselVollstaendig(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	altesGeheimnis := user.TOTPSecret

	rec := post(t, s, "/account/2fa", url.Values{
		"_csrf":            {csrf},
		"current_password": {testPassword},
	}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Start: Status = %d: %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "Zweiten Faktor wechseln") {
		t.Fatal("die Wechselseite erscheint nicht")
	}

	// Solange nicht bestätigt ist, bleibt der alte Faktor in der Datenbank.
	stored, err := s.db.UserByID(context.Background(), user.ID)
	if err != nil {
		t.Fatal(err)
	}
	if stored.TOTPSecret != altesGeheimnis {
		t.Fatal("das Geheimnis wurde bereits vor der Bestätigung getauscht")
	}

	neu, ok := s.pending.get(user.ID)
	if !ok {
		t.Fatal("es liegt kein begonnener Wechsel vor")
	}
	if neu == altesGeheimnis {
		t.Fatal("das neue Geheimnis ist mit dem alten identisch")
	}

	// Der QR-Code zum begonnenen Wechsel muss abrufbar sein.
	if rec := get(t, s, "/account/2fa/qr.png", cookie); rec.Code != http.StatusOK {
		t.Errorf("QR-Code: Status = %d", rec.Code)
	}

	// Ein falscher Code darf nichts umstellen.
	rec = post(t, s, "/account/2fa/confirm", url.Values{"_csrf": {csrf}, "code": {"000000"}}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("falscher Code: Status = %d, erwartet 400", rec.Code)
	}
	stored, _ = s.db.UserByID(context.Background(), user.ID)
	if stored.TOTPSecret != altesGeheimnis {
		t.Fatal("ein falscher Code hat den Faktor gewechselt")
	}

	// Der richtige Code schließt den Wechsel ab.
	code, err := auth.TOTPCode(neu, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	rec = post(t, s, "/account/2fa/confirm", url.Values{"_csrf": {csrf}, "code": {code}}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Bestätigung: Status = %d: %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "Wiederherstellungscodes") {
		t.Error("es werden keine neuen Wiederherstellungscodes gezeigt")
	}

	stored, _ = s.db.UserByID(context.Background(), user.ID)
	if stored.TOTPSecret != neu {
		t.Fatal("das neue Geheimnis wurde nicht gespeichert")
	}
	if !stored.TOTPConfirmed {
		t.Error("der neue Faktor gilt nicht als bestätigt")
	}
	if _, ok := s.pending.get(user.ID); ok {
		t.Error("der begonnene Wechsel wurde nicht aufgeräumt")
	}

	// Der alte Code darf nicht mehr gelten.
	altCode, err := auth.TOTPCode(altesGeheimnis, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	if auth.VerifyTOTP(stored.TOTPSecret, altCode, time.Now()) && altCode != code {
		t.Error("der alte Code wird weiterhin angenommen")
	}
}

// TestTOTPWechselBrauchtPasswort ist der eigentliche Schutz: Eine übernommene
// Sitzung darf den zweiten Faktor nicht austauschen können — sonst sperrt der
// Angreifer den Inhaber mit dessen eigenem Schutzmechanismus aus.
func TestTOTPWechselBrauchtPasswort(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/account/2fa", url.Values{
		"_csrf":            {csrf},
		"current_password": {"das ist nicht das passwort"},
	}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("Status = %d, erwartet 400", rec.Code)
	}
	if _, ok := s.pending.get(user.ID); ok {
		t.Fatal("ohne richtiges Passwort wurde ein Wechsel begonnen")
	}
}

func TestTOTPWechselOhneBegonnenenVorgang(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	if rec := get(t, s, "/account/2fa/qr.png", cookie); rec.Code != http.StatusForbidden {
		t.Errorf("QR ohne Vorgang: Status = %d, erwartet 403", rec.Code)
	}
	rec := post(t, s, "/account/2fa/confirm", url.Values{"_csrf": {csrf}, "code": {"123456"}}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Errorf("Bestätigung ohne Vorgang: Status = %d, erwartet 400", rec.Code)
	}
}

func TestPendingSecretsLaeuftAb(t *testing.T) {
	p := newPendingSecrets()
	p.put(1, "GEHEIMNIS")
	if got, ok := p.get(1); !ok || got != "GEHEIMNIS" {
		t.Fatalf("= %q, %v", got, ok)
	}

	// Von Hand vordatieren, statt eine Viertelstunde zu warten.
	p.mu.Lock()
	p.byUser[1] = pendingSecret{secret: "GEHEIMNIS", expires: time.Now().Add(-time.Second)}
	p.mu.Unlock()

	if _, ok := p.get(1); ok {
		t.Error("ein abgelaufener Vorgang gilt weiterhin")
	}
	p.mu.Lock()
	_, still := p.byUser[1]
	p.mu.Unlock()
	if still {
		t.Error("der abgelaufene Eintrag wurde nicht entfernt")
	}
}

// ------------------------------------------------------- Eigene Sitzungen ---

func TestSitzungslisteZeigtDieEigene(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	rec := get(t, s, "/account", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d", rec.Code)
	}
	body := rec.Body.String()
	if !strings.Contains(body, "Aktive Sitzungen") {
		t.Fatal("die Sitzungsliste fehlt")
	}
	if !strings.Contains(body, "diese Sitzung") {
		t.Error("die eigene Sitzung ist nicht als solche gekennzeichnet")
	}
	// Ohne weitere Sitzung darf es keine Sammelaktion geben.
	if strings.Contains(body, "revoke-others") {
		t.Error("die Sammelaktion erscheint, obwohl nur eine Sitzung offen ist")
	}
}

func TestFremdeSitzungBeenden(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	// Eine zweite Anmeldung desselben Kontos, etwa vom Telefon.
	zweite, _ := login(t, s, user)

	sessions, err := s.db.ListUserSessions(context.Background(), user.ID)
	if err != nil {
		t.Fatal(err)
	}
	if len(sessions) != 2 {
		t.Fatalf("%d Sitzungen, erwartet 2", len(sessions))
	}

	body := get(t, s, "/account", cookie).Body.String()
	if !strings.Contains(body, "revoke-others") {
		t.Error("bei zwei Sitzungen fehlt die Sammelaktion")
	}

	// Die andere Sitzung gezielt beenden. Die Kennung in der Datenbank ist
	// der Hash des Cookie-Werts, nicht der Wert selbst.
	target := auth.HashToken(zweite.Value)
	found := false
	for _, sess := range sessions {
		if sess.ID == target {
			found = true
		}
	}
	if !found {
		t.Fatal("die zweite Sitzung wurde nicht wiedergefunden")
	}

	rec := post(t, s, "/account/sessions/revoke", url.Values{
		"_csrf": {csrf}, "session": {target},
	}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}

	// Die eigene Sitzung lebt weiter, die andere ist tot.
	if rec := get(t, s, "/account", cookie); rec.Code != http.StatusOK {
		t.Errorf("die eigene Sitzung wurde mitbeendet: %d", rec.Code)
	}
	if rec := get(t, s, "/account", zweite); rec.Code == http.StatusOK {
		t.Error("die beendete Sitzung ist weiterhin gültig")
	}
}

// TestFremdesKontoSitzungNichtBeendbar prüft die Bedingung, die den Zugriff
// auf die eigene Benutzerkennung beschränkt.
func TestFremdesKontoSitzungNichtBeendbar(t *testing.T) {
	s := newTestServer(t)
	angreifer := addUser(t, s, "angreifer", store.RoleAdmin)
	opfer := addUser(t, s, "opfer", store.RoleOwner)

	cookieAngreifer, csrf := login(t, s, angreifer)
	cookieOpfer, _ := login(t, s, opfer)

	opferSessions, err := s.db.ListUserSessions(context.Background(), opfer.ID)
	if err != nil || len(opferSessions) != 1 {
		t.Fatalf("Sitzungen des Opfers: %v, %d", err, len(opferSessions))
	}

	rec := post(t, s, "/account/sessions/revoke", url.Values{
		"_csrf": {csrf}, "session": {opferSessions[0].ID},
	}, cookieAngreifer)
	if rec.Code != http.StatusNotFound {
		t.Fatalf("Status = %d, erwartet 404", rec.Code)
	}
	if rec := get(t, s, "/account", cookieOpfer); rec.Code != http.StatusOK {
		t.Error("die fremde Sitzung wurde trotzdem beendet")
	}
}

func TestAlleAnderenSitzungenBeenden(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	zweite, _ := login(t, s, user)
	dritte, _ := login(t, s, user)

	rec := post(t, s, "/account/sessions/revoke-others", url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d", rec.Code)
	}

	sessions, err := s.db.ListUserSessions(context.Background(), user.ID)
	if err != nil {
		t.Fatal(err)
	}
	if len(sessions) != 1 {
		t.Fatalf("%d Sitzungen übrig, erwartet 1", len(sessions))
	}
	for _, c := range []*http.Cookie{zweite, dritte} {
		if rec := get(t, s, "/account", c); rec.Code == http.StatusOK {
			t.Error("eine beendete Sitzung ist weiterhin gültig")
		}
	}
	if rec := get(t, s, "/account", cookie); rec.Code != http.StatusOK {
		t.Error("die eigene Sitzung wurde mitbeendet")
	}
}

func TestTOTPWechselBeendetAndereSitzungen(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	zweite, _ := login(t, s, user)

	if rec := post(t, s, "/account/2fa", url.Values{
		"_csrf": {csrf}, "current_password": {testPassword},
	}, cookie); rec.Code != http.StatusOK {
		t.Fatalf("Start: %d", rec.Code)
	}
	secret, _ := s.pending.get(user.ID)
	code, err := auth.TOTPCode(secret, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	if rec := post(t, s, "/account/2fa/confirm", url.Values{
		"_csrf": {csrf}, "code": {code},
	}, cookie); rec.Code != http.StatusOK {
		t.Fatalf("Bestätigung: %d", rec.Code)
	}

	// Das verlorene Gerät ist damit abgemeldet.
	if rec := get(t, s, "/account", zweite); rec.Code == http.StatusOK {
		t.Error("die andere Sitzung besteht nach dem Wechsel weiter")
	}
	if rec := get(t, s, "/account", cookie); rec.Code != http.StatusOK {
		t.Error("die eigene Sitzung wurde mitbeendet")
	}
}

func TestShortenUserAgent(t *testing.T) {
	tests := map[string]string{
		"Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36": "Chrome/126.0.0.0",
		"Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0":                                "Firefox/127.0",
		"curl/8.5.0": "curl/8.5.0",
		"":           "unbekannt",
	}
	for in, want := range tests {
		if got := shortenUserAgent(in); got != want {
			t.Errorf("shortenUserAgent(%q)\n = %q\nerwartet %q", in, got, want)
		}
	}
	long := strings.Repeat("x", 100)
	if got := shortenUserAgent(long); len(got) > 45 {
		t.Errorf("eine lange Kennung wird nicht gekürzt: %d Zeichen", len(got))
	}
}

// ------------------------------------------- Wiederholungsschutz für TOTP ---

// TestTOTPCodeGiltNurEinmal hält die Anforderung aus RFC 6238 §5.2 fest: Ein
// angenommener Code darf kein zweites Mal gelten. Ohne das bliebe er sein
// ganzes Zeitfenster über gültig — bis zu anderthalb Minuten, und beliebig oft.
func TestTOTPCodeGiltNurEinmal(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)

	code, err := auth.TOTPCode(user.TOTPSecret, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	form := url.Values{"username": {"philipp"}, "password": {testPassword}, "code": {code}}

	first := post(t, s, "/login", form, nil)
	if first.Code != http.StatusSeeOther {
		t.Fatalf("erste Anmeldung: Status = %d, erwartet 303", first.Code)
	}

	second := post(t, s, "/login", form, nil)
	if second.Code != http.StatusUnauthorized {
		t.Fatalf("zweite Anmeldung mit demselben Code: Status = %d, erwartet 401", second.Code)
	}

	// Und die Ablehnung steht mit ihrem Grund im Audit-Log.
	entries, err := s.db.ListAudit(context.Background(), 20)
	if err != nil {
		t.Fatal(err)
	}
	var vermerkt bool
	for _, e := range entries {
		if e.Action == "login.failed" && strings.Contains(e.Detail, "verbraucht") {
			vermerkt = true
		}
	}
	if !vermerkt {
		t.Error("der verbrauchte Code wurde nicht als solcher protokolliert")
	}
}

// TestFehlversuchVerbrauchtDenCodeNicht ist die Kehrseite: Wer sich beim
// Passwort vertippt, soll nicht eine halbe Minute auf den nächsten Code warten
// müssen. Verbraucht wird erst nach einer geglückten Anmeldung.
func TestFehlversuchVerbrauchtDenCodeNicht(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)

	code, err := auth.TOTPCode(user.TOTPSecret, time.Now())
	if err != nil {
		t.Fatal(err)
	}

	falsch := post(t, s, "/login", url.Values{
		"username": {"philipp"}, "password": {"daneben"}, "code": {code},
	}, nil)
	if falsch.Code != http.StatusUnauthorized {
		t.Fatalf("Fehlversuch: Status = %d, erwartet 401", falsch.Code)
	}

	richtig := post(t, s, "/login", url.Values{
		"username": {"philipp"}, "password": {testPassword}, "code": {code},
	}, nil)
	if richtig.Code != http.StatusSeeOther {
		t.Fatalf("derselbe Code nach einem Fehlversuch: Status = %d, erwartet 303", richtig.Code)
	}
}

// TestFalschesPasswortVerbrauchtKeinenWiederherstellungscode: Die Prüfung eines
// Wiederherstellungscodes löst ihn unwiderruflich ein. Ohne Passwort darf das
// nicht geschehen — sonst könnte jemand mit der Codeliste, aber ohne Passwort,
// die Vorräte des Kontos aufbrauchen.
func TestFalschesPasswortVerbrauchtKeinenWiederherstellungscode(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)

	codes, hashes, err := auth.NewRecoveryCodes()
	if err != nil {
		t.Fatal(err)
	}
	if err := s.db.ReplaceRecoveryCodes(context.Background(), user.ID, hashes); err != nil {
		t.Fatal(err)
	}

	vorher, err := s.db.CountUnusedRecoveryCodes(context.Background(), user.ID)
	if err != nil {
		t.Fatal(err)
	}

	post(t, s, "/login", url.Values{
		"username": {"philipp"}, "password": {"daneben"}, "code": {codes[0]},
	}, nil)

	nachher, err := s.db.CountUnusedRecoveryCodes(context.Background(), user.ID)
	if err != nil {
		t.Fatal(err)
	}
	if nachher != vorher {
		t.Errorf("ein Fehlversuch hat einen Wiederherstellungscode verbraucht: %d → %d", vorher, nachher)
	}

	// Mit richtigem Passwort greift derselbe Code dann sehr wohl.
	rec := post(t, s, "/login", url.Values{
		"username": {"philipp"}, "password": {testPassword}, "code": {codes[0]},
	}, nil)
	if rec.Code != http.StatusSeeOther {
		t.Fatalf("Anmeldung mit Wiederherstellungscode: Status = %d, erwartet 303", rec.Code)
	}
}
