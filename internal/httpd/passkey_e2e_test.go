package httpd

import (
	"context"
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

// TestPasskeyBrowserFlow: der vollständige positive Durchlauf — Passkey im Konto
// registrieren, abmelden, mit dem Passkey anmelden. Belegt, dass die im Browser
// erzeugten Zeremonie-Antworten richtig serialisiert und von go-webauthn samt
// unserer RP-Konfiguration akzeptiert werden.
func TestPasskeyBrowserFlow(t *testing.T) {
	out, s, user := runPasskeyBrowser(t, "flow")
	if !strings.Contains(out, "E2E-OK") {
		t.Fatalf("kein Erfolg gemeldet:\n%s", out)
	}

	creds, err := s.db.WebAuthnCredentialsByUser(context.Background(), user.ID)
	if err != nil || len(creds) != 1 {
		t.Fatalf("Passkeys nach Durchlauf = %d (%v), erwartet 1", len(creds), err)
	}
	if creds[0].LastUsedAt == nil {
		t.Error("der Passkey wurde bei der Anmeldung nicht als genutzt vermerkt")
	}

	entries, err := s.db.ListAudit(context.Background(), 50)
	if err != nil {
		t.Fatal(err)
	}
	found := false
	for _, e := range entries {
		if e.Action == "login.success" && strings.Contains(e.Detail, "Passkey") {
			found = true
		}
	}
	if !found {
		t.Error("keine Passkey-Anmeldung im Audit-Log")
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
