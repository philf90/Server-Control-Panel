package httpd

import (
	"net"
	"net/http"
	"runtime/debug"
	"time"
)

// contentSecurityPolicy ist bewusst eng. Kein 'unsafe-inline', keine externen
// Quellen: Alle Skripte und Stile kommen aus dem Binary selbst. Jede spätere
// Lockerung soll eine bewusste Entscheidung mit Begründung sein, kein stilles
// Aufweichen.
//
// script-src und connect-src sind für die Live-Ansicht nötig (SSE über
// EventSource), form-action 'self' für die Formulare der Oberfläche.
const contentSecurityPolicy = "default-src 'none'; " +
	"script-src 'self'; " +
	"style-src 'self'; " +
	"img-src 'self' data:; " +
	"connect-src 'self'; " +
	"form-action 'self'; " +
	"base-uri 'none'; " +
	"frame-ancestors 'none'"

func securityHeaders(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		h := w.Header()
		h.Set("Content-Security-Policy", contentSecurityPolicy)
		h.Set("X-Content-Type-Options", "nosniff")
		h.Set("X-Frame-Options", "DENY")
		h.Set("Referrer-Policy", "no-referrer")
		h.Set("Cross-Origin-Opener-Policy", "same-origin")
		h.Set("Permissions-Policy", "geolocation=(), camera=(), microphone=()")
		// Das Panel ist ausschließlich über HTTPS erreichbar, deshalb ist HSTS
		// unkritisch. Kein preload: die Domain gehört dem Betreiber, nicht uns.
		h.Set("Strict-Transport-Security", "max-age=31536000")
		next.ServeHTTP(w, r)
	})
}

// statusRecorder merkt sich den Statuscode für das Zugriffsprotokoll.
type statusRecorder struct {
	http.ResponseWriter
	status int
	bytes  int
}

// Unwrap gibt den umhüllten Writer frei.
//
// Ohne diese Methode verliert jede Hülle die Zusatzfähigkeiten des echten
// Writers — insbesondere http.Flusher, ohne den Server-Sent Events nicht
// funktionieren. http.NewResponseController folgt dieser Kette.
func (r *statusRecorder) Unwrap() http.ResponseWriter {
	return r.ResponseWriter
}

func (r *statusRecorder) WriteHeader(code int) {
	r.status = code
	r.ResponseWriter.WriteHeader(code)
}

func (r *statusRecorder) Write(b []byte) (int, error) {
	if r.status == 0 {
		r.status = http.StatusOK
	}
	n, err := r.ResponseWriter.Write(b)
	r.bytes += n
	return n, err
}

func (s *Server) requestLog(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		rec := &statusRecorder{ResponseWriter: w}
		next.ServeHTTP(rec, r)

		if rec.status == 0 {
			rec.status = http.StatusOK
		}
		// Health-Checks laufen im Sekundentakt und würden das Log fluten.
		level := "info"
		if r.URL.Path == "/healthz" && rec.status == http.StatusOK {
			level = "debug"
		}
		attrs := []any{
			"method", r.Method,
			"path", r.URL.Path,
			"status", rec.status,
			"bytes", rec.bytes,
			"duration", time.Since(start).Round(time.Millisecond).String(),
			"remote", clientIP(r),
		}
		if level == "debug" {
			s.log.Debug("request", attrs...)
			return
		}
		s.log.Info("request", attrs...)
	})
}

func (s *Server) recoverer(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		defer func() {
			if rec := recover(); rec != nil {
				s.log.Error("panic im Handler",
					"path", r.URL.Path,
					"panic", rec,
					"stack", string(debug.Stack()),
				)
				http.Error(w, "interner Fehler", http.StatusInternalServerError)
			}
		}()
		next.ServeHTTP(w, r)
	})
}

// clientIP liefert die Adresse ohne Port. Proxy-Header werden bewusst nicht
// ausgewertet: Das Panel wird direkt angesprochen, und ein blind vertrauter
// X-Forwarded-For würde später die Rate-Limits aushebelbar machen.
func clientIP(r *http.Request) string {
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		return r.RemoteAddr
	}
	return host
}
