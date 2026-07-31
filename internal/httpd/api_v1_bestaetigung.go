package httpd

// Rückfragen und Rechte für die JSON-Schnittstelle.
//
// Beides gibt es in der alten Oberfläche schon — verifyCSRF, requireWrite und
// bestaetigt. Sie antworten mit HTML, und ein fetch, das HTML bekommt, meldet
// einen Parserfehler statt der eigentlichen Ursache. Deshalb dieselbe Logik mit
// JSON-Ausgang, nicht eine andere Logik.
//
// Die Trennung ist bewusst nur die der Ausgabe. Was geprüft wird, bleibt Zeile
// für Zeile dasselbe: dieselbe Rolle, dasselbe Sitzungstoken im
// Konstantzeit-Vergleich, dasselbe `bestaetigt` vor der ersten Veränderung, und
// dieselbe Fehlermeldung im Audit-Log.

import (
	"net/http"
	"strings"

	"github.com/philf90/asylum/internal/store"
)

// apiSchreibend ist die Kette vor jedem verändernden Endpunkt unter /api/v1:
// Schreibrecht, dann das Token.
//
// Als eine Funktion und nicht als zwei ineinandergesteckte Handler, weil die
// Reihenfolge nicht beliebig ist: Wer kein Schreibrecht hat, soll das erfahren
// und nicht „Token fehlt" — sonst lädt die Oberfläche neu, holt ein frisches
// Token und bekommt denselben Fehler wieder.
func (s *Server) apiSchreibend(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		user, ok := userFrom(r.Context())
		if !ok || !user.CanWrite() {
			s.audit(r, "access.denied", r.URL.Path, store.ResultDenied, "Schreibrecht fehlt")
			s.apiFehler(w, http.StatusForbidden, "Diese Aktion erfordert Schreibrechte.")
			return
		}

		// Eine Anfrage mit API-Token braucht kein Sitzungstoken, und das ist keine
		// Ausnahme, sondern die Folge davon, was CSRF überhaupt ist: Die Prüfung
		// schützt gegen eine UMGEBENDE Berechtigung — ein Cookie, das der Browser
		// bei jeder Anfrage mitschickt, auch bei einer, die eine fremde Seite
		// ausgelöst hat. Ein Token wird mitgebracht, nicht mitgeschickt, und den
		// Authorization-Kopf kann eine fremde Seite nicht setzen (siehe den Kopf
		// von tokenauth.go). Es gibt hier auch nichts zu prüfen: Eine
		// Token-Anfrage hat keine Sitzung und damit kein Sitzungstoken.
		//
		// Die Bedingung, auf der das steht, ist die Regel aus loadToken: Ein
		// Authorization-Kopf schaltet den Cookie-Weg AB, statt neben ihm zu
		// stehen. Ohne sie wäre dieser Zweig genau der Weg, die CSRF-Prüfung mit
		// einem unsinnigen Kopf abzuschalten und sich auf das Cookie zu verlassen.
		if _, mitToken := tokenFrom(r.Context()); mitToken {
			next.ServeHTTP(w, r)
			return
		}

		// Das Token kommt aus der Kopfzeile und nicht aus dem Körper. Zwei
		// Gründe: Der Körper ist JSON und wird von den Handlern gelesen, nicht
		// von einer Middleware — und eine Kopfzeile kann ein Formular von einer
		// fremden Seite nicht setzen, ein verstecktes Feld schon.
		if !s.csrfPasst(r, r.Header.Get("X-CSRF-Token")) {
			s.audit(r, "csrf.rejected", r.URL.Path, store.ResultDenied, "")
			s.apiFehler(w, http.StatusForbidden,
				"Das Sitzungstoken passt nicht. Bitte die Seite neu laden.")
			return
		}

		next.ServeHTTP(w, r)
	})
}

// apiEigenerZugriff prüft NUR das Sitzungstoken, nicht die Rolle.
//
// Gebraucht für das eigene Konto, und das ist keine Lockerung von apiSchreibend,
// sondern eine andere Frage: Dort geht es um Zustände des Systems, die eine Rolle
// verändern darf oder nicht. Hier verändert jemand seinen EIGENEN Anmeldeweg, und
// das darf jede Rolle — sonst bliebe ein Konto mit Leserecht auf dem
// Einmalpasswort sitzen, mit dem es angelegt wurde, und könnte weder Passwort
// noch zweiten Faktor wechseln.
//
// Die Schranke dieser Endpunkte ist deshalb nicht die Rolle, sondern zweierlei:
// Sie fassen ausschließlich das Konto der laufenden Sitzung an (die Kennung kommt
// aus dem Kontext, nie aus dem Anfragekörper), und jede Änderung an einem
// Anmeldeweg verlangt das aktuelle Passwort.
func (s *Server) apiEigenerZugriff(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if _, ok := userFrom(r.Context()); !ok {
			s.apiFehler(w, http.StatusUnauthorized, "nicht angemeldet")
			return
		}
		if !s.csrfPasst(r, r.Header.Get("X-CSRF-Token")) {
			s.audit(r, "csrf.rejected", r.URL.Path, store.ResultDenied, "")
			s.apiFehler(w, http.StatusForbidden,
				"Das Sitzungstoken passt nicht. Bitte die Seite neu laden.")
			return
		}
		next.ServeHTTP(w, r)
	})
}

// apiOwner lässt nur die Owner-Rolle durch — mit JSON-Ausgang, wie
// apiSchreibend. Vor apiSchreibend gestellt, damit der Grund der richtige ist:
// Wer nicht Owner ist, soll das erfahren und nicht „Token fehlt".
func (s *Server) apiOwner(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		user, ok := userFrom(r.Context())
		if !ok || !user.CanManageUsers() {
			s.audit(r, "access.denied", r.URL.Path, store.ResultDenied, "Owner-Rolle erforderlich")
			s.apiFehler(w, http.StatusForbidden, "Diese Aktion ist der Owner-Rolle vorbehalten.")
			return
		}
		next.ServeHTTP(w, r)
	})
}

// apiBestaetigung ist der Text einer Rückfrage — dieselben Felder wie
// bestaetigung, nur ohne die, die zu einer gerenderten Seite gehören (Aktion,
// Abbruch, Felder). Die kennt die Oberfläche selbst: Sie hat den Knopf gedrückt
// und weiß, wohin sie ihn erneut schickt.
type apiBestaetigung struct {
	Titel         string   `json:"titel"`
	Frage         string   `json:"frage"`
	Punkte        []string `json:"punkte"`
	Knopf         string   `json:"knopf"`
	Tippen        string   `json:"tippen"`
	TippenHinweis string   `json:"tippen_hinweis"`
	// Fehler steht im Dialog, wenn das getippte Wort nicht passte.
	Fehler string `json:"fehler,omitempty"`
}

// apiBestaetigungAntwort ist der Körper, mit dem eine unbestätigte Aktion
// abgewiesen wird.
type apiBestaetigungAntwort struct {
	Fehler       string          `json:"fehler"`
	Bestaetigung apiBestaetigung `json:"bestaetigung"`
}

// apiBestaetigt sagt, ob die Aktion laufen darf.
//
// Ist die Antwort false, wurde bereits geantwortet — der Handler muss dann ohne
// weitere Ausgabe zurückkehren. Er darf bis zu diesem Aufruf nichts verändert
// haben; prüfen und lesen ist in Ordnung, damit die Frage die richtigen Zahlen
// tragen kann. Dieselbe Regel wie bei bestaetigt für die alte Oberfläche.
//
// Der Statuscode ist 409: Die Anfrage ist wohlgeformt und berechtigt, sie steht
// nur im Widerspruch zu dem, was der Endpunkt verlangt. Ausdrücklich nicht 400
// — das würde die Oberfläche als Programmierfehler behandeln und die Rückfrage
// als Fehlermeldung zeigen, statt sie zu stellen.
func (s *Server) apiBestaetigt(w http.ResponseWriter, anfrage apiAktionAnfrage, b apiBestaetigung) bool {
	if anfrage.Bestaetigt {
		if b.Tippen == "" {
			return true
		}
		// EqualFold wie in der alten Fassung: Auf einem Telefon macht die
		// Tastatur aus "vm" gern "Vm". Wer den Namen abgeschrieben hat, hat die
		// Rückfrage gelesen — mehr soll die Stufe nicht leisten.
		if strings.EqualFold(strings.TrimSpace(anfrage.Getippt), b.Tippen) {
			return true
		}
		b.Fehler = "Das stimmt nicht mit " + b.Tippen + " überein. Die Aktion wurde nicht ausgeführt."
	}

	if b.Knopf == "" {
		b.Knopf = "fortfahren"
	}
	if b.TippenHinweis == "" && b.Tippen != "" {
		b.TippenHinweis = "Zum Bestätigen " + b.Tippen + " eingeben"
	}
	if b.Punkte == nil {
		b.Punkte = []string{}
	}

	meldung := "Diese Aktion erfordert eine Bestätigung."
	if b.Fehler != "" {
		meldung = b.Fehler
	}
	s.apiJSON(w, http.StatusConflict, apiBestaetigungAntwort{
		Fehler:       meldung,
		Bestaetigung: b,
	})
	return false
}
