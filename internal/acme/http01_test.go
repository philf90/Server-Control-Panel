package acme

import (
	"context"
	"net"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestHTTP01Handle(t *testing.T) {
	s := &http01Solver{tokens: make(map[string]string)}
	if err := s.present(context.Background(), "panel.example.test", "tok123", "die-antwort"); err != nil {
		t.Fatal(err)
	}

	rec := httptest.NewRecorder()
	s.handle(rec, httptest.NewRequest(http.MethodGet, "/.well-known/acme-challenge/tok123", nil))
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}
	if rec.Body.String() != "die-antwort" {
		t.Errorf("Body = %q, erwartet die-antwort", rec.Body.String())
	}

	// Nach dem Aufräumen ist der Token weg.
	if err := s.cleanup(context.Background(), "panel.example.test", "tok123", "die-antwort"); err != nil {
		t.Fatal(err)
	}
	rec = httptest.NewRecorder()
	s.handle(rec, httptest.NewRequest(http.MethodGet, "/.well-known/acme-challenge/tok123", nil))
	if rec.Code != http.StatusNotFound {
		t.Errorf("nach cleanup Status = %d, erwartet 404", rec.Code)
	}
}

func TestHTTP01UnknownTokenIs404(t *testing.T) {
	s := &http01Solver{tokens: make(map[string]string)}
	rec := httptest.NewRecorder()
	s.handle(rec, httptest.NewRequest(http.MethodGet, "/.well-known/acme-challenge/gibtsnicht", nil))
	if rec.Code != http.StatusNotFound {
		t.Errorf("Status = %d, erwartet 404", rec.Code)
	}
}

// TestHTTP01PortBusy hält den Fall fest, der DNS-01 nötig macht: Läuft schon
// etwas auf dem Port, scheitert das Binden — und der Manager fällt zurück.
func TestHTTP01PortBusy(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = ln.Close() }()

	if s, err := newHTTP01Solver(context.Background(), ln.Addr().String()); err == nil {
		_ = s.Close()
		t.Error("ein belegter Port hätte einen Fehler ergeben müssen")
	}
}

func TestHTTP01ListenerServes(t *testing.T) {
	s, err := newHTTP01Solver(context.Background(), "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = s.Close() }()

	// Über den echten Listener: Adresse aus dem Server holen ist nicht
	// vorgesehen, deshalb prüft dieser Test nur, dass er sich öffnen und wieder
	// schließen lässt; das Ausliefern deckt TestHTTP01Handle ab.
	if s.srv == nil {
		t.Error("der HTTP-Server wurde nicht angelegt")
	}
}
