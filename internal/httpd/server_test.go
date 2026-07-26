package httpd

import (
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/config"
)

func newTestServer(t *testing.T) *Server {
	t.Helper()
	logger := slog.New(slog.NewTextHandler(io.Discard, nil))
	srv, err := New(config.Default(), logger)
	if err != nil {
		t.Fatalf("New: %v", err)
	}
	return srv
}

func TestIndex(t *testing.T) {
	rec := httptest.NewRecorder()
	newTestServer(t).Handler().ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/", nil))

	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}
	if ct := rec.Header().Get("Content-Type"); !strings.HasPrefix(ct, "text/html") {
		t.Errorf("Content-Type = %q", ct)
	}
	if body := rec.Body.String(); !strings.Contains(body, "Project Asylum") {
		t.Errorf("Seite enthält den Projektnamen nicht:\n%s", body)
	}
}

func TestHealthz(t *testing.T) {
	rec := httptest.NewRecorder()
	newTestServer(t).Handler().ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/healthz", nil))

	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}

	var resp healthResponse
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v (%s)", err, rec.Body.String())
	}
	if resp.Status != "ok" {
		t.Errorf("status = %q, erwartet ok", resp.Status)
	}
	if resp.Version == "" {
		t.Error("version fehlt — der Update-Healthcheck vergleicht darauf")
	}
	if got := rec.Header().Get("Cache-Control"); got != "no-store" {
		t.Errorf("Cache-Control = %q, erwartet no-store", got)
	}
}

func TestStaticIsServed(t *testing.T) {
	rec := httptest.NewRecorder()
	newTestServer(t).Handler().ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/static/app.css", nil))

	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), "--accent") {
		t.Error("app.css wurde nicht ausgeliefert")
	}
}

func TestSecurityHeaders(t *testing.T) {
	rec := httptest.NewRecorder()
	newTestServer(t).Handler().ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/", nil))

	want := map[string]string{
		"X-Content-Type-Options":    "nosniff",
		"X-Frame-Options":           "DENY",
		"Referrer-Policy":           "no-referrer",
		"Strict-Transport-Security": "max-age=31536000",
	}
	for header, value := range want {
		if got := rec.Header().Get(header); got != value {
			t.Errorf("%s = %q, erwartet %q", header, got, value)
		}
	}
	if csp := rec.Header().Get("Content-Security-Policy"); !strings.Contains(csp, "default-src 'none'") {
		t.Errorf("CSP = %q", csp)
	}
}

func TestUnknownPathIs404(t *testing.T) {
	rec := httptest.NewRecorder()
	newTestServer(t).Handler().ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/gibtsnicht", nil))

	if rec.Code != http.StatusNotFound {
		t.Errorf("Status = %d, erwartet 404", rec.Code)
	}
}

// "/" darf nur exakt die Wurzel bedienen. Ohne das {$}-Muster würde der
// Index-Handler jeden unbekannten Pfad mit 200 beantworten.
func TestIndexDoesNotSwallowSubpaths(t *testing.T) {
	rec := httptest.NewRecorder()
	newTestServer(t).Handler().ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/irgendwas/tief", nil))

	if rec.Code == http.StatusOK {
		t.Error("Unterpfad wurde vom Index-Handler beantwortet")
	}
}

func TestMethodNotAllowed(t *testing.T) {
	rec := httptest.NewRecorder()
	newTestServer(t).Handler().ServeHTTP(rec, httptest.NewRequest(http.MethodPost, "/healthz", nil))

	if rec.Code != http.StatusMethodNotAllowed {
		t.Errorf("Status = %d, erwartet 405", rec.Code)
	}
}

func TestRecovererTurnsPanicInto500(t *testing.T) {
	srv := newTestServer(t)
	handler := srv.recoverer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {
		panic("kaputt")
	}))

	rec := httptest.NewRecorder()
	handler.ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/", nil))

	if rec.Code != http.StatusInternalServerError {
		t.Errorf("Status = %d, erwartet 500", rec.Code)
	}
}

func TestClientIP(t *testing.T) {
	req := httptest.NewRequest(http.MethodGet, "/", nil)
	req.RemoteAddr = "203.0.113.7:54321"
	if got := clientIP(req); got != "203.0.113.7" {
		t.Errorf("clientIP = %q, erwartet 203.0.113.7", got)
	}

	req.RemoteAddr = "[2001:db8::1]:443"
	if got := clientIP(req); got != "2001:db8::1" {
		t.Errorf("clientIP (IPv6) = %q, erwartet 2001:db8::1", got)
	}
}
