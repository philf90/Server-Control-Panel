package httpd

// API-Tokens als zweiter Anmeldeweg — die Schranken.
//
// Diese Datei ist die Sicherheitsbetrachtung des Token-Wegs. Sie steht neben
// session.go und nicht darin, weil die beiden Wege verschiedene Eigenschaften
// haben und die Unterschiede benannt gehören:
//
// **Ein Cookie ist eine UMGEBENDE Berechtigung, ein Token eine mitgebrachte.**
// Der Browser schickt das Cookie bei jeder Anfrage an diesen Ursprung mit, auch
// bei einer, die eine fremde Seite ausgelöst hat — daher CSRF und daher das
// Sitzungstoken in der Kopfzeile. Einen `Authorization`-Kopf kann eine fremde
// Seite NICHT setzen: Ein Formular kann keine Kopfzeilen setzen, und ein `fetch`
// mit eigener Kopfzeile ist keine einfache Anfrage — der Browser schickt erst
// einen Vorabflug, und den beantwortet dieses Panel nicht (es setzt keine
// CORS-Kopfzeilen). Eine Anfrage mit Token ist deshalb entweder gleichen
// Ursprungs oder kommt gar nicht aus einem Browser. **Darum braucht der
// Token-Weg keine CSRF-Prüfung — und nur darum.**
//
// Daraus folgt die Regel, die dieser Datei ihre Form gibt:
//
//	Ist ein Authorization-Kopf da, gilt AUSSCHLIESSLICH der Token-Weg.
//	Ein ungültiger Token endet mit 401 und fällt NICHT auf das Cookie zurück.
//
// Der Rückfall wäre der eigentliche Angriff: Eine fremde Seite kann keinen
// gültigen Token setzen, aber sie könnte — wäre der Kopf über CORS erreichbar —
// einen unsinnigen setzen, um damit die CSRF-Prüfung abzuschalten und sich auf
// das mitgeschickte Cookie zu verlassen. Ein Rückfall macht aus einer
// Kopfzeile, die niemand fälschen kann, eine Kopfzeile, mit der man eine Prüfung
// ausschaltet.
//
// **Drei Familien sind für Tokens grundsätzlich gesperrt**, unabhängig von Rolle
// und Scopes (tokenGesperrt):
//
//   - `tokens` — sonst mintet ein entwendeter Token einen frischen und überlebt
//     seinen eigenen Widerruf. Ein Widerruf, der sich umgehen lässt, ist keiner.
//   - `panel-users` — sonst legt er ein Konto an und braucht den Token nicht mehr.
//   - `account` — sonst ändert er Passwort, zweiten Faktor oder Passkeys des
//     Kontos, dem er gehört, und schließt den Inhaber aus.
//
// Diese drei sind der Grund, warum ein Token nicht „die Rolle in Textform" ist:
// Er kann weniger als das Konto, dem er gehört, und er kann seine eigene
// Zurücknahme nicht verhindern.

import (
	"context"
	"errors"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/store"
)

// tokenPraefix steht vor jedem Token. Er macht einen versehentlich
// veröffentlichten Token erkennbar — für Menschen in einem Diff und für
// Geheimnis-Sucher in einer Sammlung.
const tokenPraefix = "asy_"

// tokenPrefixLaenge ist die Zahl der Zeichen, die als sichtbarer Anfang
// gespeichert werden. Acht Zeichen aus einem 32-Byte-Geheimnis sind zum
// Wiedererkennen genug und zum Erraten des Rests nichts.
const tokenPrefixLaenge = 8

// tokenFamilien sind die Modulfamilien, die ein Token führen darf.
//
// Eine Allowlist und keine Sperrliste: Eine neue Familie ist damit
// standardmäßig NICHT für Tokens offen, und wer sie öffnen will, trägt sie hier
// ein und denkt dabei darüber nach. Umgekehrt — Sperrliste — wäre jedes neue
// Modul stillschweigend erreichbar.
var tokenFamilien = []string{
	"overview", "signals", "metrics", "services", "packages", "system",
	"firewall", "logs", "audit", "files", "schedules", "certificate", "update",
	"system-users", "jobs", "session",
	// docker ist offen, weil eine Automatisierung wissen können soll, ob die
	// Laufzeit steht. Was ein Token damit AUSLÖSEN kann, ist davon unberührt:
	// Die schreibenden Routen liegen hinter der Owner-Rolle, und ein Token kann
	// die Rolle seines Kontos nur unterschreiten, nie überschreiten.
	"docker",
}

// tokenGesperrt sind die Familien, die ein Token NIE erreicht — auch nicht, wenn
// jemand sie in die Scopes schreibt. Die Begründung steht im Kopf dieser Datei.
var tokenGesperrt = map[string]bool{
	"tokens":      true,
	"panel-users": true,
	"account":     true,
}

type tokenCtxKey int

const ctxToken tokenCtxKey = 0

// tokenFrom liefert den Token aus dem Kontext, sofern die Anfrage über einen kam.
func tokenFrom(ctx context.Context) (store.APIToken, bool) {
	t, ok := ctx.Value(ctxToken).(store.APIToken)
	return t, ok
}

// NeuerAPIToken erzeugt Klartext, Hash und sichtbaren Anfang.
func NeuerAPIToken() (klartext, hash, prefix string, err error) {
	roh, err := auth.NewToken()
	if err != nil {
		return "", "", "", err
	}
	klartext = tokenPraefix + roh
	// Gehasht wird der VOLLE Klartext samt Präfix — also genau das, was der
	// Aufrufer später schickt. Nur den Rumpf zu hashen wäre die Sorte
	// Ungenauigkeit, die erst bei einer Änderung des Präfixes auffällt.
	hash = auth.HashToken(klartext)
	prefix = roh[:min(tokenPrefixLaenge, len(roh))]
	return klartext, hash, prefix, nil
}

// bearerAus liest den Token aus der Kopfzeile. Der zweite Wert sagt, ob ein
// Authorization-Kopf überhaupt da war — der Unterschied zwischen „kein Token"
// und „unbrauchbarer Token" entscheidet über 401 gegen Durchlassen.
func bearerAus(r *http.Request) (token string, vorhanden bool) {
	kopf := r.Header.Get("Authorization")
	if kopf == "" {
		return "", false
	}
	rest, gefunden := schnittOhneGrossKlein(kopf, "Bearer ")
	if !gefunden {
		// Ein Authorization-Kopf mit einem anderen Verfahren (Basic, Digest) ist
		// da, aber nicht für uns. Er gilt als vorhanden: Sonst wäre er der
		// stille Weg, die CSRF-Prüfung abzuschalten.
		return "", true
	}
	return strings.TrimSpace(rest), true
}

// schnittOhneGrossKlein schneidet ein Präfix ohne Rücksicht auf Groß- und
// Kleinschreibung ab. „bearer" schreiben manche Clients klein, und RFC 7235
// erlaubt es.
func schnittOhneGrossKlein(s, praefix string) (string, bool) {
	if len(s) < len(praefix) || !strings.EqualFold(s[:len(praefix)], praefix) {
		return "", false
	}
	return s[len(praefix):], true
}

// loadToken legt Benutzer und Token in den Kontext, wenn die Anfrage einen
// Bearer-Token trägt — und weist sie ab, wenn der Kopf da, aber unbrauchbar ist.
//
// Der Handler davor ist loadSession. Diese Hülle läuft VOR ihr: Ist ein Token da,
// soll das Cookie gar nicht erst gelesen werden.
func (s *Server) loadToken(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		klartext, vorhanden := bearerAus(r)
		if !vorhanden {
			next.ServeHTTP(w, r)
			return
		}

		// Ab hier endet jeder Weg entweder mit einem gültigen Token oder mit 401.
		// Kein Rückfall auf das Cookie — die Begründung steht im Kopf.
		ip := clientIP(r)
		if erlaubt, wartezeit := s.limiter.Allowed("token:" + ip); !erlaubt {
			w.Header().Set("Retry-After", sekundenText(wartezeit))
			s.apiFehler(w, http.StatusTooManyRequests,
				"Zu viele Versuche mit ungültigen Tokens. Bitte später erneut.")
			return
		}

		if klartext == "" || !strings.HasPrefix(klartext, tokenPraefix) {
			s.tokenAbgewiesen(w, r, "", "kein Token im Authorization-Kopf")
			return
		}

		tok, err := s.db.TokenByHash(r.Context(), auth.HashToken(klartext))
		if err != nil {
			if !errors.Is(err, store.ErrNotFound) {
				s.log.Error("token laden", "err", err)
			}
			// Dieselbe Meldung wie bei einem abgelaufenen Token wäre bequem, ist
			// aber falsch: „unbekannt" und „abgelaufen" sind für den, der ein
			// Skript sucht, zwei ganz verschiedene Auskünfte. Ein Angreifer
			// gewinnt daraus nichts — er kennt den Token nicht.
			s.tokenAbgewiesen(w, r, "", "Token unbekannt oder widerrufen")
			return
		}

		if tok.Abgelaufen(time.Now()) {
			s.tokenAbgewiesen(w, r, tok.Name, "Token abgelaufen am "+
				tok.ExpiresAt.Format("02.01.2006"))
			return
		}

		user, err := s.db.UserByID(r.Context(), tok.UserID)
		if err != nil || user.Disabled {
			s.tokenAbgewiesen(w, r, tok.Name, "das Konto des Tokens ist gesperrt oder fort")
			return
		}
		// Ein Konto mit Wechselzwang trägt ein Einmalpasswort aus einer
		// Zurücksetzung. Ein Token daran wäre die dauerhafte Berechtigung, die
		// der Wechselzwang gerade verhindern soll.
		if user.MustChangePassword {
			s.tokenAbgewiesen(w, r, tok.Name,
				"das Konto muss zuerst sein Passwort wechseln")
			return
		}

		s.limiter.Reset("token:" + ip)

		// Die Grenzen werden HIER geprüft und nicht in einer eigenen Hülle je
		// Route. Der Grund ist keine Bequemlichkeit: Eine Hülle, die man je Route
		// anhängt, ist eine Hülle, die man bei der einundsechzigsten Route
		// vergisst — und dann steht eine Fläche offen, die niemand geöffnet hat.
		// Hier kommt jede Anfrage mit Token durch, ohne Ausnahme.
		if grund := tokenGrenzen(r, tok); grund != "" {
			s.audit(r, "token.denied", tok.Name, store.ResultDenied, grund)
			s.apiFehler(w, http.StatusForbidden, grund)
			return
		}

		s.tokenNutzungVermerken(tok, ip)

		ctx := context.WithValue(r.Context(), ctxUser, user)
		ctx = context.WithValue(ctx, ctxToken, tok)
		next.ServeHTTP(w, r.WithContext(ctx))
	})
}

// tokenAbgewiesen protokolliert und antwortet 401.
//
// Immer mit Protokolleintrag: Ein Token, der reihenweise abgewiesen wird, ist
// entweder ein kaputtes Skript oder ein Versuch, und beides gehört ins Journal.
// Der Token selbst steht dort nie — nur sein Name, sofern bekannt.
func (s *Server) tokenAbgewiesen(w http.ResponseWriter, r *http.Request, name, grund string) {
	s.limiter.Fail("token:" + clientIP(r))
	ziel := r.URL.Path
	if name != "" {
		ziel = name
	}
	s.audit(r, "token.rejected", ziel, store.ResultDenied, grund)
	w.Header().Set("WWW-Authenticate", `Bearer realm="asylum"`)
	s.apiFehler(w, http.StatusUnauthorized, grund)
}

// tokenNutzungVermerken schreibt „zuletzt benutzt" fort — höchstens einmal je
// Minute.
//
// Die Drosselung ist kein Feinschliff: Ein Abfrageskript im Sekundentakt wäre
// sonst ein Schreibzugriff im Sekundentakt, und die Auskunft wird dadurch nicht
// genauer. Ein eigener Kontext, weil die Notiz keinen Grund hat, mit der Anfrage
// zu enden; ein Fehler dabei weist die Anfrage NICHT ab — der Token ist gültig,
// und die Notiz ist Beiwerk.
func (s *Server) tokenNutzungVermerken(tok store.APIToken, ip string) {
	jetzt := time.Now()
	if tok.LastUsedAt != nil && jetzt.Sub(*tok.LastUsedAt) < time.Minute {
		return
	}
	if err := s.db.TouchAPIToken(context.Background(), tok.ID, jetzt, ip); err != nil {
		s.log.Warn("token-nutzung vermerken", "err", err)
	}
}

// tokenGrenzen prüft Scopes und Leserecht des Tokens gegen die Anfrage.
//
// Rückgabe leer heißt: erlaubt. An EINER Stelle, weil eine zweite Prüfung
// derselben Regel die Stelle wäre, an der beide auseinanderlaufen — und bei einer
// Rechteregel heißt „auseinander" im Zweifel „zu viel erlaubt".
func tokenGrenzen(r *http.Request, tok store.APIToken) string {
	// Ein Token gilt NUR für die JSON-Schnittstelle. Die gerenderten Seiten sind
	// für Browser da, und ein Browser schickt keinen Authorization-Kopf. Diese
	// Zeile ist mehr als Ordnung: Ohne sie fände apiFamilie für /services die
	// leere Familie, und die Sperrliste unten griffe nicht — ein Token käme an
	// jede Seite der alten Oberfläche.
	if !strings.HasPrefix(r.URL.Path, "/api/") {
		return "Ein API-Token gilt nur für die Schnittstelle unter /api/. " +
			"Für die Oberfläche selbst ist eine Anmeldung nötig."
	}

	familie := apiFamilie(r.URL.Path)

	if tokenGesperrt[familie] {
		return "Diese Fläche ist für API-Tokens gesperrt und nur mit einer " +
			"Anmeldung erreichbar: Ein Token soll weder Tokens noch Zugänge " +
			"anlegen und nicht den eigenen Anmeldeweg ändern können."
	}
	if !enthaeltText(tokenFamilien, familie) {
		return "Für diese Fläche gibt es keinen Token-Zugang."
	}
	// Nur lesend heißt: nur lesende Verfahren. GET und HEAD verändern nichts;
	// alles andere kann es.
	if tok.ReadOnly && r.Method != http.MethodGet && r.Method != http.MethodHead {
		return "Dieser Token darf nur lesen."
	}
	// Leere Scope-Liste heißt „alle erlaubten Familien". Das ist die Vorgabe und
	// nicht ein Sonderfall: Wer keine Einschränkung wählt, bekommt den Umfang
	// seiner Rolle, und die Rolle ist die Obergrenze.
	if len(tok.Scopes) > 0 && !enthaeltText(tok.Scopes, familie) {
		return "Dieser Token gilt nicht für " + familie + ". Erlaubt: " +
			strings.Join(tok.Scopes, ", ") + "."
	}
	return ""
}

// apiFamilie ist der zweite Pfadteil unter /api/v1/… — also „services" in
// /api/v1/services/ssh.service.
//
// Der zweite Teil und nicht der ganze Pfad: Ein Scope je Endpunkt wäre eine
// Liste, die niemand pflegt, und ein Scope je Präfix („alles unter /files")
// wäre ein Textvergleich, bei dem /files-geheim zufällig mitpasst.
func apiFamilie(pfad string) string {
	rest, gefunden := strings.CutPrefix(pfad, "/api/v1/")
	if !gefunden {
		return ""
	}
	familie, _, _ := strings.Cut(rest, "/")
	return familie
}

func enthaeltText(liste []string, wert string) bool {
	if wert == "" {
		return false
	}
	for _, e := range liste {
		if e == wert {
			return true
		}
	}
	return false
}

func sekundenText(d time.Duration) string {
	sek := int(d.Seconds())
	if sek < 1 {
		sek = 1
	}
	return strconv.Itoa(sek)
}
