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

	"github.com/philf90/asylum/internal/passkeys"
	"github.com/philf90/asylum/internal/store"
)

// TestPasskeyBrowserFlow fährt den vollständigen Durchlauf mit einem echten
// Browser und virtuellem Authenticator: Passkey im Konto registrieren, abmelden,
// mit dem Passkey anmelden. Er prüft, was ein Handler-Test nicht kann — dass die
// im Browser erzeugten Zeremonie-Antworten von passkey-register.js/-login.js
// richtig serialisiert und von go-webauthn samt unserer RP-Konfiguration
// akzeptiert werden.
//
// Bewusst hinter einer Umgebungsvariablen: Der Test braucht Node und Chromium
// und läuft nicht in jeder CI. Aufruf:
//
//	ASYLUM_PASSKEY_E2E=1 \
//	  ASYLUM_NODE=/opt/node22/bin/node \
//	  ASYLUM_NODE_PATH=/opt/node22/lib/node_modules \
//	  ASYLUM_CHROMIUM=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
//	  go test ./internal/httpd -run TestPasskeyBrowserFlow -v
func TestPasskeyBrowserFlow(t *testing.T) {
	if os.Getenv("ASYLUM_PASSKEY_E2E") == "" {
		t.Skip("ohne ASYLUM_PASSKEY_E2E nichts zu tun (braucht Node und Chromium)")
	}
	node := envOr("ASYLUM_NODE", "node")
	chromium := os.Getenv("ASYLUM_CHROMIUM")
	if chromium == "" {
		t.Skip("ASYLUM_CHROMIUM (Pfad zum Browser) nicht gesetzt")
	}

	s := newTestServer(t)

	// Listener zuerst öffnen, damit der Port für den Origin feststeht, bevor der
	// WebAuthn-Manager gebaut wird.
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	port := ln.Addr().(*net.TCPAddr).Port

	// RP-ID localhost, Origin auf genau diesen Port — der Browser ruft die Seite
	// über https://localhost:PORT auf (localhost gilt als sicherer Kontext,
	// anders als eine IP).
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

	ts := &httptest.Server{
		Listener: ln,
		Config:   &http.Server{Handler: s.Handler()},
	}
	ts.StartTLS()
	defer ts.Close()

	base := fmt.Sprintf("https://localhost:%d", port)
	script := "testdata/passkey_e2e.js"

	cmd := exec.Command(node, script, base, "philipp", testPassword, cookie.Value, chromium)
	cmd.Env = os.Environ()
	if np := os.Getenv("ASYLUM_NODE_PATH"); np != "" {
		cmd.Env = append(cmd.Env, "NODE_PATH="+np)
	}
	out, err := cmd.CombinedOutput()
	t.Logf("node:\n%s", out)
	if err != nil {
		t.Fatalf("Browserdurchlauf fehlgeschlagen: %v", err)
	}
	if !strings.Contains(string(out), "E2E-OK") {
		t.Fatalf("kein Erfolg gemeldet:\n%s", out)
	}

	// Der Passkey wurde registriert und beim Anmelden fortgeschrieben.
	creds, err := s.db.WebAuthnCredentialsByUser(context.Background(), user.ID)
	if err != nil || len(creds) != 1 {
		t.Fatalf("Passkeys nach Durchlauf = %d (%v), erwartet 1", len(creds), err)
	}
	if creds[0].LastUsedAt == nil {
		t.Error("der Passkey wurde bei der Anmeldung nicht als genutzt vermerkt")
	}

	// Und es gibt eine geglückte Anmeldung über Passkey im Audit-Log.
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

func envOr(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}
