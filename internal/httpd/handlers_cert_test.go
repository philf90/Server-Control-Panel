package httpd

import (
	"net/http"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/certs"
	"github.com/philf90/asylum/internal/store"
)

func TestCertificatePageSelfSigned(t *testing.T) {
	s := newTestServer(t)
	// Das TLS-Material existiert im Test nicht von selbst (EnsurePair läuft nur
	// in Run) — für die Seite legen wir es an.
	if _, err := certs.EnsurePair(s.cfg.Server.TLS.Cert, s.cfg.Server.TLS.Key, []string{"panel.example.test"}); err != nil {
		t.Fatal(err)
	}

	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	rec := get(t, s, "/certificate", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}
	body := rec.Body.String()
	for _, want := range []string{"TLS-Zertifikat", "selbstsigniert", "panel.example.test"} {
		if !strings.Contains(body, want) {
			t.Errorf("Seite enthält %q nicht", want)
		}
	}
}

// Auch ein Leser (readonly) darf den Zertifikatszustand sehen.
func TestCertificatePageReadOnly(t *testing.T) {
	s := newTestServer(t)
	if _, err := certs.EnsurePair(s.cfg.Server.TLS.Cert, s.cfg.Server.TLS.Key, nil); err != nil {
		t.Fatal(err)
	}
	user := addUser(t, s, "leser", store.RoleReadOnly)
	cookie, _ := login(t, s, user)

	if rec := get(t, s, "/certificate", cookie); rec.Code != http.StatusOK {
		t.Errorf("readonly: Status = %d, erwartet 200", rec.Code)
	}
}

// Fehlt die Datei, bleibt die Seite erreichbar und zeigt den Grund, statt mit
// 500 zu scheitern.
func TestCertificatePageMissingFileStillRenders(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	rec := get(t, s, "/certificate", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200 (Seite soll trotz fehlender Datei erreichbar bleiben)", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), "konnte nicht gelesen werden") {
		t.Error("der Lesefehler wird nicht angezeigt")
	}
}
