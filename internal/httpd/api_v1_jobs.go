package httpd

// Vorgänge über /api/v1.
//
// Grundsatz III aus docs/15-neuordnung.md: Handlungen sind quittiert. Eine
// Aktion, die Minuten dauert — Paketlisten holen, Updates einspielen, später ein
// Container-Abbild ziehen oder ein Backup prüfen —, ist kein Klick mit
// Rückmeldung am Ende, sondern ein Vorgang mit Ausgabe, Dauer und Ergebnis.
//
// Als eigene Ressource und nicht als Teil des jeweiligen Moduls: Es gibt genau
// eine Art, einem Vorgang zuzusehen, und die Oberfläche baut sie einmal. Das
// Paketmodul startet sie, das Firewall-Modul auch, und beide zeigen dieselbe
// Platte.
//
// Der Vorgang selbst läuft weiter, wenn der Browser die Verbindung verliert —
// das war schon so und bleibt so. Ein abgebrochenes apt-get mitten im dpkg-Lauf
// hinterlässt ein halb konfiguriertes System; das darf nicht davon abhängen, ob
// jemand den Tab offen lässt.

import (
	"net/http"
	"strconv"
	"time"
)

// jobArten ist die Allowlist der Vorgangsarten mit ihrer Beschriftung.
//
// Eine Allowlist, weil die Art aus dem Pfad kommt: Ohne sie könnte jemand
// beliebige Schlüssel durchprobieren und aus „kein Vorgang" gegen „unbekannte
// Art" ableiten, welche Arten es gibt. Der Nutzen ist gering, der Aufwand für
// die Liste auch — und sie ist ohnehin die Stelle, an der die Beschriftung steht.
var jobArten = map[string]string{
	jobPackages:        "Paketvorgang",
	jobFirewallInstall: "ufw einrichten",
}

// apiJob ist der Zustand eines Vorgangs.
type apiJob struct {
	Art    string `json:"art"`
	Titel  string `json:"titel"`
	Akteur string `json:"akteur"`
	Laeuft bool   `json:"laeuft"`
	// Gescheitert und Fehler stehen getrennt: Die Oberfläche färbt nach dem
	// einen und zeigt das andere. Ein leerer Fehlertext bei Gescheitert=true
	// wäre trotzdem ein Scheitern — dann sagt sie es ohne Grund, statt den
	// Zustand zu verschweigen.
	Gescheitert bool   `json:"gescheitert"`
	Fehler      string `json:"fehler"`
	// Hinweis ist eine Anmerkung zum Ergebnis, die kein Fehler ist: der
	// Teilerfolg von apt-get update etwa, bei dem einzelne Quellen klemmen und
	// die übrigen Listen trotzdem neu sind.
	Hinweis   string    `json:"hinweis"`
	Zeilen    []string  `json:"zeilen"`
	Start     time.Time `json:"start"`
	DauerText string    `json:"dauer_text"`
}

func (s *Server) jobAus(art string) *apiJob {
	j := s.jobs.get(art)
	if j == nil {
		return nil
	}
	st := j.stand()

	antwort := &apiJob{
		Art:         art,
		Titel:       jobArten[art],
		Akteur:      st.Akteur,
		Laeuft:      !st.Fertig,
		Gescheitert: st.Fertig && st.Fehler != nil,
		Hinweis:     st.Hinweis,
		// Leeres Feld statt null: Ein Vorgang, der noch keine Zeile ausgegeben
		// hat, ist ein Zustand, den die Oberfläche zeigt, und kein fehlender Wert.
		Zeilen:    st.Zeilen,
		Start:     st.Start,
		DauerText: dauerText(st.Laufzeit),
	}
	if antwort.Zeilen == nil {
		antwort.Zeilen = []string{}
	}
	if st.Fehler != nil {
		antwort.Fehler = st.Fehler.Error()
	}
	return antwort
}

// dauerText schreibt eine Laufzeit so, wie man sie liest: Sekunden unter einer
// Minute, danach Minuten und Sekunden. „312 s" verlangt Kopfrechnen, „5 min 12 s"
// nicht.
func dauerText(d time.Duration) string {
	if d < 0 {
		d = 0
	}
	sek := int(d.Round(time.Second).Seconds())
	if sek < 60 {
		return strconv.Itoa(sek) + " s"
	}
	return strconv.Itoa(sek/60) + " min " + strconv.Itoa(sek%60) + " s"
}

// handleAPIJob liefert den Zustand eines Vorgangs.
//
// Gefragt wird er zweimal: beim Aufbau der Seite — dann steht ein Lauf, der noch
// läuft oder gerade fertig ist, sofort da — und noch einmal, wenn der Strom sein
// Ende meldet. Der Strom sagt nur „vorbei"; ob es geglückt ist, wie lange es
// dauerte und ob eine Anmerkung dazu gehört, steht hier. Zwei Fassungen dieser
// Auskunft im Ereignisstrom und in der Ressource liefen auseinander.
func (s *Server) handleAPIJob(w http.ResponseWriter, r *http.Request) {
	art := r.PathValue("art")
	if _, ok := jobArten[art]; !ok {
		s.apiFehler(w, http.StatusNotFound, "unbekannte Vorgangsart")
		return
	}

	j := s.jobAus(art)
	if j == nil {
		// 204 und nicht 404: Die Ressource gibt es, sie ist nur leer — bisher
		// wurde kein solcher Vorgang gestartet. Ein 404 wäre für die Oberfläche
		// ein Fehler, den sie melden müsste, und es ist keiner.
		w.WriteHeader(http.StatusNoContent)
		return
	}
	s.apiJSON(w, http.StatusOK, j)
}

// handleAPIJobEvents streamt die Ausgabe eines Vorgangs.
//
// Dieselbe Funktion, die die alte Oberfläche bedient: streamJob. Die Ereignisse
// heißen weiter `output` und `end`, und `end` trägt weiter nur „ok" oder den
// Fehlertext. Die neue Oberfläche verlässt sich darauf ausdrücklich NICHT — sie
// fragt beim Ende die Ressource oben. Damit bleibt der Strom, was er ist: der
// Weg für die Zeilen, während sie entstehen.
func (s *Server) handleAPIJobEvents(w http.ResponseWriter, r *http.Request) {
	art := r.PathValue("art")
	if _, ok := jobArten[art]; !ok {
		s.apiFehler(w, http.StatusNotFound, "unbekannte Vorgangsart")
		return
	}
	if s.jobs.get(art) == nil {
		s.apiFehler(w, http.StatusNotFound, "kein Vorgang")
		return
	}
	s.streamJob(w, r, art)
}
