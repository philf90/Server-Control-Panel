package httpd

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"time"

	"github.com/philf90/asylum/internal/metrics"
	"github.com/philf90/asylum/internal/store"
	"github.com/philf90/asylum/internal/ui"
	"github.com/philf90/asylum/internal/version"
)

// Handler baut den Router auf. Öffentlich, damit Tests ihn ohne TLS-Listener
// verwenden können.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()

	// Offen zugänglich.
	mux.HandleFunc("GET /healthz", s.handleHealth)
	mux.HandleFunc("GET /login", s.handleLoginForm)
	mux.Handle("POST /login", s.rateLimited(http.HandlerFunc(s.handleLogin)))
	mux.HandleFunc("GET /setup", s.handleSetupForm)
	mux.Handle("POST /setup", s.rateLimited(http.HandlerFunc(s.handleSetup)))

	// Angemeldet, aber noch ohne bestätigtes TOTP erreichbar.
	mux.Handle("GET /setup/2fa", s.loggedIn(http.HandlerFunc(s.handleTOTPForm)))
	mux.Handle("POST /setup/2fa", s.loggedIn(s.verifyCSRF(http.HandlerFunc(s.handleTOTPConfirm))))
	mux.Handle("GET /setup/2fa/qr.png", s.loggedIn(http.HandlerFunc(s.handleTOTPQR)))
	mux.Handle("POST /logout", s.loggedIn(s.verifyCSRF(http.HandlerFunc(s.handleLogout))))

	// Vollständig angemeldet.
	mux.Handle("GET /{$}", s.protected(http.HandlerFunc(s.handleDashboard)))
	mux.Handle("GET /events", s.protected(http.HandlerFunc(s.handleEvents)))
	mux.Handle("GET /audit", s.protected(http.HandlerFunc(s.handleAudit)))
	mux.Handle("GET /account", s.protected(http.HandlerFunc(s.handleAccount)))
	mux.Handle("POST /account/password", s.protected(s.verifyCSRF(http.HandlerFunc(s.handlePasswordChange))))
	mux.Handle("POST /account/recovery-codes", s.protected(s.verifyCSRF(http.HandlerFunc(s.handleRecoveryCodes))))
	// Wechsel des zweiten Faktors im laufenden Betrieb — bis hierher ging das
	// nur über "asylum reset-password" auf der Kommandozeile des Servers.
	mux.Handle("POST /account/2fa", s.protected(s.verifyCSRF(http.HandlerFunc(s.handleTOTPChangeStart))))
	mux.Handle("GET /account/2fa/qr.png", s.protected(http.HandlerFunc(s.handleTOTPChangeQR)))
	mux.Handle("POST /account/2fa/confirm", s.protected(s.verifyCSRF(http.HandlerFunc(s.handleTOTPChangeConfirm))))
	mux.Handle("POST /account/sessions/revoke", s.protected(s.verifyCSRF(http.HandlerFunc(s.handleSessionRevoke))))
	mux.Handle("POST /account/sessions/revoke-others", s.protected(s.verifyCSRF(http.HandlerFunc(s.handleSessionRevokeOthers))))

	// Systemverwaltung: lesen darf jede Rolle, ändern nur Admin und Owner.
	mux.Handle("GET /services", s.protected(http.HandlerFunc(s.handleServices)))
	mux.Handle("GET /services/{unit}", s.protected(http.HandlerFunc(s.handleServiceDetail)))
	mux.Handle("POST /services/{unit}", s.protected(s.requireWrite(s.verifyCSRF(http.HandlerFunc(s.handleServiceAction)))))

	mux.Handle("GET /packages", s.protected(http.HandlerFunc(s.handlePackages)))
	mux.Handle("GET /packages/events", s.protected(http.HandlerFunc(s.handlePackageEvents)))
	mux.Handle("POST /packages/refresh", s.protected(s.requireWrite(s.verifyCSRF(http.HandlerFunc(s.handlePackageRefresh)))))
	mux.Handle("POST /packages/upgrade", s.protected(s.requireWrite(s.verifyCSRF(http.HandlerFunc(s.handlePackageUpgrade)))))

	mux.Handle("GET /firewall", s.protected(http.HandlerFunc(s.handleFirewall)))
	mux.Handle("POST /firewall", s.protected(s.requireWrite(s.verifyCSRF(http.HandlerFunc(s.handleFirewallApply)))))
	mux.Handle("POST /firewall/confirm", s.protected(s.requireWrite(s.verifyCSRF(http.HandlerFunc(s.handleFirewallConfirm)))))
	mux.Handle("POST /firewall/active", s.protected(s.requireWrite(s.verifyCSRF(http.HandlerFunc(s.handleFirewallActivate)))))
	mux.Handle("POST /firewall/install", s.protected(s.requireWrite(s.verifyCSRF(http.HandlerFunc(s.handleFirewallInstall)))))
	mux.Handle("GET /firewall/events", s.protected(http.HandlerFunc(s.handleFirewallEvents)))

	mux.Handle("GET /certificate", s.protected(http.HandlerFunc(s.handleCertificate)))
	mux.Handle("POST /certificate", s.protected(s.requireWrite(s.verifyCSRF(http.HandlerFunc(s.handleCertificateSettings)))))
	mux.Handle("POST /certificate/obtain", s.protected(s.requireWrite(s.verifyCSRF(http.HandlerFunc(s.handleCertificateObtain)))))

	mux.Handle("GET /system-users", s.protected(http.HandlerFunc(s.handleSystemUsers)))
	mux.Handle("POST /system-users", s.protected(s.requireWrite(s.verifyCSRF(http.HandlerFunc(s.handleSystemUserCreate)))))
	mux.Handle("POST /system-users/{name}/locked", s.protected(s.requireWrite(s.verifyCSRF(http.HandlerFunc(s.handleSystemUserLock)))))
	mux.Handle("POST /system-users/{name}/delete", s.protected(s.requireWrite(s.verifyCSRF(http.HandlerFunc(s.handleSystemUserDelete)))))
	mux.Handle("POST /system-users/{name}/keys", s.protected(s.requireWrite(s.verifyCSRF(http.HandlerFunc(s.handleSSHKeyAdd)))))
	mux.Handle("POST /system-users/{name}/keys/remove", s.protected(s.requireWrite(s.verifyCSRF(http.HandlerFunc(s.handleSSHKeyRemove)))))

	mux.Handle("GET /logs", s.protected(http.HandlerFunc(s.handleLogs)))

	mux.Handle("GET /update", s.protected(http.HandlerFunc(s.handleUpdate)))
	mux.Handle("GET /update/status", s.protected(http.HandlerFunc(s.handleUpdateStatus)))
	mux.Handle("POST /update/check", s.protected(s.requireWrite(s.verifyCSRF(http.HandlerFunc(s.handleUpdateCheck)))))
	// Das Einspielen tauscht das Programm aus, das alle übrigen Rechte
	// durchsetzt — deshalb nur Owner.
	mux.Handle("POST /update/apply", s.protected(s.requireOwner(s.verifyCSRF(http.HandlerFunc(s.handleUpdateApply)))))
	mux.Handle("POST /update/rollback", s.protected(s.requireOwner(s.verifyCSRF(http.HandlerFunc(s.handleUpdateRollback)))))

	// Nur Owner.
	mux.Handle("GET /users", s.protected(s.requireOwner(http.HandlerFunc(s.handleUsers))))
	mux.Handle("POST /users", s.protected(s.requireOwner(s.verifyCSRF(http.HandlerFunc(s.handleUserCreate)))))
	mux.Handle("POST /users/{id}/disabled", s.protected(s.requireOwner(s.verifyCSRF(http.HandlerFunc(s.handleUserDisable)))))
	mux.Handle("POST /users/{id}/delete", s.protected(s.requireOwner(s.verifyCSRF(http.HandlerFunc(s.handleUserDelete)))))

	if static, err := ui.Static(); err == nil {
		fileServer := http.FileServer(http.FS(static))
		mux.Handle("GET /static/", cacheStatic(http.StripPrefix("/static/", fileServer)))
	} else {
		s.log.Error("statische Dateien nicht verfügbar", "err", err)
	}

	return s.recoverer(securityHeaders(s.requestLog(s.loadSession(mux))))
}

// loggedIn verlangt eine Sitzung, aber noch kein abgeschlossenes 2FA-Setup.
func (s *Server) loggedIn(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if _, ok := userFrom(r.Context()); !ok {
			s.redirectToLogin(w, r)
			return
		}
		next.ServeHTTP(w, r)
	})
}

// protected verlangt eine vollständig eingerichtete Anmeldung.
func (s *Server) protected(next http.Handler) http.Handler {
	return s.requireAuth(next)
}

type healthResponse struct {
	Status        string `json:"status"`
	Version       string `json:"version"`
	UptimeSeconds int64  `json:"uptime_seconds"`
}

// handleHealth ist der Endpunkt, auf den Installer und Update-Vorgang warten.
// Er darf niemals von optionalen Komponenten abhängen — er beantwortet
// ausschließlich die Frage, ob der Prozess Anfragen bedient, und verlangt
// deshalb bewusst keine Anmeldung.
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

// handleEvents liefert die Live-Metriken als Server-Sent Events.
//
// SSE statt WebSocket: Der Datenfluss geht nur in eine Richtung, das Protokoll
// ist einfaches HTTP, und Browser bauen die Verbindung von selbst wieder auf.
func (s *Server) handleEvents(w http.ResponseWriter, r *http.Request) {
	// ResponseController statt einer Typzusicherung auf http.Flusher: Der
	// Writer ist von der Middleware umhüllt, und der Controller folgt der
	// Unwrap-Kette bis zum echten Writer. Eine Zusicherung auf die Hülle
	// schlägt fehl — genau daran ist dieser Endpunkt schon einmal gescheitert.
	rc := http.NewResponseController(w)

	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("Connection", "keep-alive")
	// Falls doch einmal ein Proxy davor steht: nicht puffern.
	w.Header().Set("X-Accel-Buffering", "no")
	w.WriteHeader(http.StatusOK)

	if err := rc.Flush(); err != nil {
		s.log.Error("streaming nicht verfügbar", "err", err)
		return
	}

	// Sofort den letzten bekannten Stand senden, damit die Seite nicht bis zum
	// nächsten Takt leer bleibt.
	if snap, ok := s.ring.Last(); ok {
		writeEvent(w, rc, snap)
	}

	ch := s.hub.subscribe()
	defer s.hub.unsubscribe(ch)

	ctx := r.Context()
	for {
		select {
		case <-ctx.Done():
			return
		case snap, ok := <-ch:
			if !ok {
				return
			}
			if !writeEvent(w, rc, snap) {
				return
			}
		}
	}
}

// writeEvent schickt einen Metrik-Schnappschuss. Die Nutzlast ist bereits
// JSON und wird unverändert geschrieben — kompaktes JSON enthält keine
// Zeilenumbrüche, die das Ereignis vorzeitig beenden könnten.
func writeEvent(w http.ResponseWriter, rc *http.ResponseController, snap metrics.Snapshot) bool {
	payload, err := json.Marshal(snap)
	if err != nil {
		return false
	}
	if _, err := fmt.Fprintf(w, "event: metrics\ndata: %s\n\n", payload); err != nil {
		return false
	}
	return rc.Flush() == nil
}

// writeSSE schickt eine Textzeile, etwa aus der Ausgabe eines laufenden
// Kommandos. Der Text wird als JSON-Zeichenkette kodiert: Ein Zeilenumbruch
// oder ein Steuerzeichen würde das Ereignis sonst mitten im Datenfeld beenden.
// Der Empfänger parst die Zeile entsprechend mit JSON.parse.
func writeSSE(w http.ResponseWriter, rc *http.ResponseController, event, text string) bool {
	encoded, err := json.Marshal(text)
	if err != nil {
		return false
	}
	if _, err := fmt.Fprintf(w, "event: %s\ndata: %s\n\n", event, encoded); err != nil {
		return false
	}
	return rc.Flush() == nil
}

// rateLimited bremst Anmelde- und Setup-Versuche je Quell-IP.
func (s *Server) rateLimited(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		key := "ip:" + clientIP(r)
		if allowed, retryAfter := s.limiter.Allowed(key); !allowed {
			w.Header().Set("Retry-After", formatSeconds(retryAfter))
			s.renderError(w, r, http.StatusTooManyRequests,
				"Zu viele Fehlversuche. Bitte in "+retryAfter.String()+" erneut versuchen.")
			return
		}
		next.ServeHTTP(w, r)
	})
}

func cacheStatic(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Kurz genug, dass ein Update nicht tagelang alte Assets ausliefert.
		w.Header().Set("Cache-Control", "public, max-age=300")
		next.ServeHTTP(w, r)
	})
}

// audit schreibt einen Audit-Eintrag und protokolliert Fehler dabei, statt sie
// zu verschlucken — ein stilles Audit-Log ist schlimmer als keines.
func (s *Server) audit(r *http.Request, action, target, result, detail string) {
	actor := "anonym"
	if u, ok := userFrom(r.Context()); ok {
		actor = u.Username
	}
	entry := store.AuditEntry{
		At: time.Now(), Actor: actor, Action: action,
		Target: target, Result: result, IP: clientIP(r), Detail: detail,
	}
	// Eigener Kontext: Der Eintrag soll auch dann noch geschrieben werden,
	// wenn der Client die Verbindung bereits getrennt hat.
	if err := s.db.AppendAudit(context.Background(), entry); err != nil {
		s.log.Error("audit-eintrag", "action", action, "err", err)
	}
}

// renderPage rendert ein Template in einen Puffer und schreibt es erst danach.
func (s *Server) renderPage(w http.ResponseWriter, r *http.Request, status int, name string, data any) {
	var buf bytes.Buffer
	if err := s.tmpl.ExecuteTemplate(&buf, name, data); err != nil {
		s.log.Error("template", "name", name, "err", err)
		http.Error(w, "interner Fehler", http.StatusInternalServerError)
		return
	}
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(status)
	_, _ = buf.WriteTo(w)
	_ = r
}

func (s *Server) renderError(w http.ResponseWriter, r *http.Request, status int, message string) {
	s.renderPage(w, r, status, "error", s.base(r, "Fehler", "").with(errorPage{Message: message}))
}

func formatSeconds(d time.Duration) string {
	secs := int(d.Seconds())
	if secs < 1 {
		secs = 1
	}
	return strconv.Itoa(secs)
}
