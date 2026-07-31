package httpd

import (
	"context"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/passkeys"
	"github.com/philf90/asylum/internal/store"
)

// runPasskeyBrowser startet das Panel über TLS auf localhost, hängt einen
// virtuellen Authenticator ein und fährt den Node-Treiber im gewünschten Modus.
// Rückgabe ist die kombinierte Ausgabe des Treibers und der Server samt Benutzer
// zum Nachprüfen.
//
// Bewusst hinter einer Umgebungsvariablen: Der Test braucht Node und Chromium
// und läuft nicht in jeder CI. Aufruf:
//
//	ASYLUM_PASSKEY_E2E=1 \
//	  ASYLUM_NODE=/opt/node22/bin/node \
//	  ASYLUM_NODE_PATH=/opt/node22/lib/node_modules \
//	  ASYLUM_CHROMIUM=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
//	  go test ./internal/httpd -run TestPasskeyBrowser -v
func runPasskeyBrowser(t *testing.T, mode string) (string, *Server, store.User) {
	t.Helper()
	if os.Getenv("ASYLUM_PASSKEY_E2E") == "" {
		t.Skip("ohne ASYLUM_PASSKEY_E2E nichts zu tun (braucht Node und Chromium)")
	}
	node := envOr("ASYLUM_NODE", "node")
	chromium := os.Getenv("ASYLUM_CHROMIUM")
	if chromium == "" {
		t.Skip("ASYLUM_CHROMIUM (Pfad zum Browser) nicht gesetzt")
	}

	s := newTestServer(t)

	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	port := ln.Addr().(*net.TCPAddr).Port

	m, err := passkeys.New(passkeys.Config{
		RPID:        "localhost",
		DisplayName: "Project Asylum",
		Origins:     []string{fmt.Sprintf("https://localhost:%d", port)},
	})
	if err != nil {
		t.Fatal(err)
	}
	s.passkeys = m

	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	ts := &httptest.Server{Listener: ln, Config: &http.Server{Handler: s.Handler()}}
	ts.StartTLS()
	defer ts.Close()

	base := fmt.Sprintf("https://localhost:%d", port)
	cmd := exec.Command(node, "testdata/passkey_e2e.js", mode, base, "philipp", testPassword, cookie.Value, chromium)
	cmd.Env = os.Environ()
	if np := os.Getenv("ASYLUM_NODE_PATH"); np != "" {
		cmd.Env = append(cmd.Env, "NODE_PATH="+np)
	}
	out, err := cmd.CombinedOutput()
	t.Logf("node (%s):\n%s", mode, out)
	if err != nil {
		t.Fatalf("Browserdurchlauf (%s) fehlgeschlagen: %v", mode, err)
	}
	return string(out), s, user
}

// TestPasskeyBrowserKonto fährt die Passkey-Zeremonie über die Kontoseite.
//
// Das ist der Nachweis, den kein Go-Test erbringen kann und der bei diesem Modul
// der eigentliche Punkt ist: Zwischen den zwei Aufrufen spricht der Browser mit
// dem Gerät, und dazwischen liegt eine Umrechnung base64url ↔ ArrayBuffer in
// web/src/lib/api.ts. Ein Fehler darin fällt in keinem Go-Test auf — die
// Endpunkte antworten korrekt, es kommt nur nie ein gültiger Nachweis an.
//
// Geprüft wird deshalb nicht, ob ein Eintrag in der Liste erscheint, sondern ob
// ein über /konto registrierter Passkey eine echte ANMELDUNG trägt. Erst das
// heißt, dass die ganze Kette stimmt.
func TestPasskeyBrowserKonto(t *testing.T) {
	out, s, user := runPasskeyBrowser(t, "konto")
	if !strings.Contains(out, "KONTO-OK") {
		t.Fatalf("kein Erfolg gemeldet:\n%s", out)
	}

	var b struct {
		Warum   string `json:"warum"`
		Vorher  int    `json:"vorher"`
		Nachher struct {
			Name    string `json:"name"`
			Marke   string `json:"marke"`
			Detail  string `json:"detail"`
			Meldung string `json:"meldung"`
		} `json:"nachher"`
		FeldLeer           bool   `json:"feldLeer"`
		NachUmbenennen     string `json:"nachUmbenennen"`
		AnmeldungGeglueckt bool   `json:"anmeldungGeglueckt"`
		Zuletzt            string `json:"zuletzt"`
		Frage              struct {
			Text     string   `json:"text"`
			Punkte   []string `json:"punkte"`
			Tippfeld bool     `json:"tippfeld"`
		} `json:"frage"`
		NachAbbruch   int `json:"nachAbbruch"`
		NachEntfernen int `json:"nachEntfernen"`
	}
	for _, zeile := range strings.Split(out, "\n") {
		roh, gefunden := strings.CutPrefix(strings.TrimSpace(zeile), "KONTO-BEOBACHTET ")
		if !gefunden {
			continue
		}
		if err := json.Unmarshal([]byte(roh), &b); err != nil {
			t.Fatalf("Beobachtungen sind kein JSON: %v (%s)", err, roh)
		}
	}
	if b.Nachher.Name == "" {
		t.Fatalf("keine Beobachtungen im Ausgabestrom:\n%s", out)
	}

	if b.Vorher != 0 {
		t.Errorf("vor der Registrierung standen %d Passkeys da", b.Vorher)
	}
	if b.Nachher.Name != "Neue Oberfläche" {
		t.Errorf("der Passkey heißt %q, erwartet den eingegebenen Namen", b.Nachher.Name)
	}
	// Der Unterschied gerätegebunden/geräteübergreifend gehört in die Anzeige: Ein
	// gebundener Schlüssel ist mit dem Gerät verloren.
	if b.Nachher.Marke == "" {
		t.Error("am Passkey steht nicht, ob er an das Gerät gebunden ist")
	}
	if !strings.Contains(b.Nachher.Detail, "noch nie") {
		t.Errorf("ein frisch hinterlegter Passkey wird nicht als unbenutzt gezeigt: %q — "+
			"ein Schlüssel, mit dem sich noch niemand angemeldet hat, ist ungeprüft",
			b.Nachher.Detail)
	}
	if !b.FeldLeer {
		t.Error("das Passwortfeld ist nach der Registrierung noch gefüllt")
	}
	if !strings.Contains(b.Warum, "ersetzt das Passwort") {
		t.Errorf("es steht nicht dabei, was ein Passkey tut: %q", b.Warum)
	}
	if b.NachUmbenennen != "Umbenanntes Gerät" {
		t.Errorf("nach dem Umbenennen heißt der Passkey %q", b.NachUmbenennen)
	}

	// Die Rückfrage vor dem Entfernen nennt den NAMEN und die Folge. In einer
	// Liste von drei Geräten ist „Passkey entfernen?" keine Auskunft darüber,
	// welches gemeint ist.
	if !strings.Contains(b.Frage.Text, "Umbenanntes Gerät") {
		t.Errorf("die Frage nennt den Passkey nicht: %q", b.Frage.Text)
	}
	if b.Frage.Tippfeld {
		t.Error("das Entfernen verlangt ein getipptes Wort — es ist umkehrbar " +
			"(neu hinterlegen), Stufe 2 genügt")
	}
	letzter := false
	for _, p := range b.Frage.Punkte {
		if strings.Contains(p, "letzte") {
			letzter = true
		}
	}
	if !letzter {
		t.Errorf("beim LETZTEN Passkey steht das nicht in der Frage: %v", b.Frage.Punkte)
	}
	if b.NachAbbruch != 1 {
		t.Errorf("nach dem ABBRUCH stehen %d Passkeys da, erwartet 1 — die Rückfrage "+
			"hat nicht gefragt, sondern nur gefragt ausgesehen", b.NachAbbruch)
	}
	if b.NachEntfernen != 0 {
		t.Errorf("nach dem Entfernen stehen noch %d Passkeys da", b.NachEntfernen)
	}

	// Nach der Anmeldung steht auf der Kontoseite, WANN der Passkey zuletzt
	// getragen hat. Vorher war die Zeile „noch nie" — steht sie danach immer noch
	// da, wurde die Nutzung nicht vermerkt, und in einer Liste von drei Geräten
	// wäre nicht zu erkennen, welches noch gilt und welches vergessen wurde.
	//
	// Das prüfte bis zum Abbau der alten Oberfläche ein eigener Browserdurchlauf
	// (Modus "flow", registriert über /alt/account) am Feld LastUsedAt in der
	// Ablage. Hier steht es an der Stelle, an der es jemand liest.
	if b.Zuletzt == "" || strings.Contains(b.Zuletzt, "noch nie") {
		t.Errorf("nach der Anmeldung steht am Passkey %q — die Nutzung wurde nicht vermerkt",
			b.Zuletzt)
	}

	// Und die Anmeldung mit dem über die Kontoseite registrierten Passkey steht im
	// Protokoll. DAS ist der Nachweis, dass die Kette stimmt: Der Nachweis des
	// Geräts ist durch die Umrechnung im Browser, durch go-webauthn und durch
	// unsere RP-Konfiguration gekommen.
	angemeldet := false
	for _, e := range mustAudit(t, s) {
		if e.Action == "login.success" && strings.Contains(e.Detail, "Passkey") {
			angemeldet = true
		}
	}
	if !angemeldet {
		t.Error("keine Passkey-Anmeldung im Audit-Protokoll — ein über /konto " +
			"hinterlegter Schlüssel trägt dann keine Anmeldung, und die Umrechnung " +
			"in lib/api.ts ist die erste Stelle, an der das schiefgeht")
	}
	// Der Passkey ist am Ende entfernt: Das prüft die Ablage und nicht nur die
	// Anzeige.
	creds, err := s.db.WebAuthnCredentialsByUser(context.Background(), user.ID)
	if err != nil {
		t.Fatal(err)
	}
	if len(creds) != 0 {
		t.Errorf("%d Passkeys in der Ablage, erwartet 0 nach dem Entfernen", len(creds))
	}
}

// TestPasskeyBrowserTamper: derselbe Weg, aber die Assertion wird unterwegs
// verfälscht (Signatur umgedreht). Die Anmeldung MUSS scheitern — der Beweis,
// dass eine manipulierte Antwort durch die ganze Kette abgelehnt wird und nicht
// nur im Idealfall stimmt.
func TestPasskeyBrowserTamper(t *testing.T) {
	out, s, user := runPasskeyBrowser(t, "tamper")
	if !strings.Contains(out, "TAMPER-REJECTED") {
		t.Fatalf("die verfälschte Assertion wurde nicht abgelehnt:\n%s", out)
	}

	// Keine geglückte Anmeldung im Audit — nur der Fehlversuch.
	entries, err := s.db.ListAudit(context.Background(), 50)
	if err != nil {
		t.Fatal(err)
	}
	for _, e := range entries {
		if e.Action == "login.success" && strings.Contains(e.Detail, "Passkey") {
			t.Fatalf("trotz verfälschter Assertion steht eine Anmeldung im Audit-Log")
		}
	}
	_ = user
}

// e2eNewPassword muss mit NEW_PASSWORD im Browsertreiber übereinstimmen.
const e2eNewPassword = "ein frisches langes Passwort"

// TestPasskeyBrowserForgot: der Weg für ein vergessenes Passwort, echt im
// Browser. Belegt das, was sich mit einem eingesetzten Ticket nicht prüfen
// lässt — dass eine Zeremonie ohne genanntes Konto tatsächlich zustande kommt
// (der Authenticator muss den Passkey von sich aus anbieten) und dass die
// Antwort durch go-webauthn und unsere RP-Konfiguration hindurch angenommen
// wird.
func TestPasskeyBrowserForgot(t *testing.T) {
	out, s, user := runPasskeyBrowser(t, "forgot")
	if !strings.Contains(out, "FORGOT-OK") {
		t.Fatalf("kein Erfolg gemeldet:\n%s", out)
	}

	nach, err := s.db.UserByID(context.Background(), user.ID)
	if err != nil {
		t.Fatal(err)
	}
	ok, err := auth.VerifyPassword(e2eNewPassword, nach.PasswordHash)
	if err != nil || !ok {
		t.Errorf("das im Browser gesetzte Passwort gilt nicht (ok=%v, err=%v)", ok, err)
	}
	// Kein Wechselzwang: Der Inhaber hat es selbst gewählt.
	if nach.MustChangePassword {
		t.Error("nach der Selbstbedienung steht ein Wechselzwang an")
	}

	entries, err := s.db.ListAudit(context.Background(), 50)
	if err != nil {
		t.Fatal(err)
	}
	found := false
	for _, e := range entries {
		if e.Action == "password.reset" && e.Result == store.ResultOK {
			found = true
		}
	}
	if !found {
		t.Error("die Zurücksetzung steht nicht im Audit-Log")
	}
}

// TestPasskeyBrowserForgotWithoutUV: derselbe Weg mit einem Authenticator, der
// nichts am Gerät prüft. Die Zurücksetzung MUSS scheitern — daran hängt die
// ganze Begründung des Entwurfs: Besitz allein ist ein Faktor, und ein Faktor
// genügt nicht, um ein Passwort zu ersetzen.
func TestPasskeyBrowserForgotWithoutUV(t *testing.T) {
	out, s, user := runPasskeyBrowser(t, "forgot-nouv")
	if !strings.Contains(out, "NOUV-REJECTED") {
		t.Fatalf("ein Passkey ohne Prüfung am Gerät wurde angenommen:\n%s", out)
	}

	nach, err := s.db.UserByID(context.Background(), user.ID)
	if err != nil {
		t.Fatal(err)
	}
	if nach.PasswordHash != user.PasswordHash {
		t.Error("das Passwort wurde trotz fehlender Prüfung am Gerät geändert")
	}
	for _, e := range mustAudit(t, s) {
		if e.Action == "password.reset" && e.Result == store.ResultOK {
			t.Error("eine geglückte Zurücksetzung steht im Audit-Log")
		}
	}
}

func mustAudit(t *testing.T, s *Server) []store.AuditEntry {
	t.Helper()
	entries, err := s.db.ListAudit(context.Background(), 50)
	if err != nil {
		t.Fatal(err)
	}
	return entries
}

func envOr(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}
