package acme

import (
	"context"
	"fmt"
	"io"
	"net"
	"net/http"
	"path"
	"sync"
	"time"
)

// http01Solver beantwortet die HTTP-01-Prüfung über einen kurzlebigen Listener
// auf Port 80. Er wird erst zur Ausstellung geöffnet und danach wieder
// geschlossen — Port 80 wird nicht dauerhaft belegt.
//
// Läuft dort bereits ein Webserver, scheitert das Binden mit einer klaren
// Meldung; der Manager fällt dann auf das selbstsignierte Zertifikat zurück.
// Genau dieser Fall ist der Grund, DNS-01 daneben anzubieten.
type http01Solver struct {
	srv *http.Server

	mu     sync.RWMutex
	tokens map[string]string
}

func newHTTP01Solver(ctx context.Context, addr string) (*http01Solver, error) {
	if addr == "" {
		addr = ":80"
	}
	var lc net.ListenConfig
	ln, err := lc.Listen(ctx, "tcp", addr)
	if err != nil {
		return nil, fmt.Errorf("HTTP-01 braucht Port %s, das Binden schlug fehl (läuft dort ein Webserver?): %w", addr, err)
	}

	s := &http01Solver{tokens: make(map[string]string)}
	mux := http.NewServeMux()
	mux.HandleFunc("/.well-known/acme-challenge/", s.handle)
	s.srv = &http.Server{Handler: mux, ReadHeaderTimeout: 5 * time.Second}
	go func() { _ = s.srv.Serve(ln) }()
	return s, nil
}

func (s *http01Solver) handle(w http.ResponseWriter, r *http.Request) {
	token := path.Base(r.URL.Path)
	s.mu.RLock()
	value, ok := s.tokens[token]
	s.mu.RUnlock()
	if !ok {
		http.NotFound(w, r)
		return
	}
	w.Header().Set("Content-Type", "text/plain")
	_, _ = io.WriteString(w, value)
}

func (s *http01Solver) challengeType() string { return "http-01" }

func (s *http01Solver) present(_ context.Context, _, token, value string) error {
	s.mu.Lock()
	s.tokens[token] = value
	s.mu.Unlock()
	return nil
}

func (s *http01Solver) cleanup(_ context.Context, _, token, _ string) error {
	s.mu.Lock()
	delete(s.tokens, token)
	s.mu.Unlock()
	return nil
}

// Close hält den Listener an. Der Manager ruft es über den io.Closer-Pfad nach
// jeder Ausstellung.
func (s *http01Solver) Close() error {
	return s.srv.Close()
}
