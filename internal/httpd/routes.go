package httpd

import (
	"bytes"
	"encoding/json"
	"net/http"
	"os"
	"time"

	"github.com/philf90/asylum/internal/ui"
	"github.com/philf90/asylum/internal/version"
)

// Handler baut den Router auf. Öffentlich, damit Tests ihn ohne TLS-Listener
// verwenden können.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()

	mux.HandleFunc("GET /{$}", s.handleIndex)
	mux.HandleFunc("GET /healthz", s.handleHealth)

	if static, err := ui.Static(); err == nil {
		fileServer := http.FileServer(http.FS(static))
		mux.Handle("GET /static/", cacheStatic(http.StripPrefix("/static/", fileServer)))
	} else {
		s.log.Error("statische Dateien nicht verfügbar", "err", err)
	}

	return s.recoverer(securityHeaders(s.requestLog(mux)))
}

type indexData struct {
	Version  string
	Hostname string
	Uptime   string
}

func (s *Server) handleIndex(w http.ResponseWriter, r *http.Request) {
	host, err := os.Hostname()
	if err != nil {
		host = "unbekannt"
	}

	// Erst in einen Puffer rendern: schlägt das Template fehl, ist noch nichts
	// geschrieben und der Fehler lässt sich als 500 melden.
	var buf bytes.Buffer
	data := indexData{
		Version:  version.String(),
		Hostname: host,
		Uptime:   humanDuration(time.Since(s.started)),
	}
	if err := s.tmpl.ExecuteTemplate(&buf, "index", data); err != nil {
		s.log.Error("template", "name", "index", "err", err)
		http.Error(w, "interner Fehler", http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.WriteHeader(http.StatusOK)
	_, _ = buf.WriteTo(w)
	_ = r
}

type healthResponse struct {
	Status        string `json:"status"`
	Version       string `json:"version"`
	UptimeSeconds int64  `json:"uptime_seconds"`
}

// handleHealth ist der Endpunkt, auf den Installer und Update-Vorgang warten.
// Er darf niemals von optionalen Komponenten abhängen — er beantwortet
// ausschließlich die Frage, ob der Prozess Anfragen bedient.
func (s *Server) handleHealth(w http.ResponseWriter, r *http.Request) {
	resp := healthResponse{
		Status:        "ok",
		Version:       version.Version,
		UptimeSeconds: int64(time.Since(s.started).Seconds()),
	}
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(http.StatusOK)
	_ = json.NewEncoder(w).Encode(resp)
	_ = r
}

func cacheStatic(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Kurz genug, dass ein Update nicht tagelang alte Assets ausliefert.
		w.Header().Set("Cache-Control", "public, max-age=300")
		next.ServeHTTP(w, r)
	})
}

func humanDuration(d time.Duration) string {
	d = d.Round(time.Second)
	switch {
	case d < time.Minute:
		return d.String()
	case d < time.Hour:
		return d.Truncate(time.Second).String()
	default:
		return d.Truncate(time.Minute).String()
	}
}
