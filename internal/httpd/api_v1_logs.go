package httpd

// Logs über /api/v1.
//
// Die zweite Seite mit einem Strom — und einem anderen als beim Paketvorgang.
// Der Unterschied ist nicht die Technik, sondern die Bedeutung:
//
//	Ein Vorgang hat ein Ende, das der Server bestimmt: apt ist fertig, Exit-Code
//	steht, der Strom schließt. Man sieht ihm zu, weil man wissen will, wie er
//	ausgeht.
//
//	Ein Journal hat kein Ende. Es endet, wenn niemand mehr zusieht. Man sieht ihm
//	zu, weil man wissen will, was gerade passiert.
//
// Daraus folgt alles Weitere: kein „gescheitert" und kein „abgeschlossen",
// sondern nur „verfolgt" oder „angehalten"; kein Ergebnis am Ende, sondern eine
// Fläche, die man jederzeit verlassen kann; und eine Obergrenze für Zuschauer,
// weil jeder von ihnen einen journalctl-Prozess hält, solange er zusieht — bei
// einem Vorgang teilen sich alle Zuschauer einen.

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"strconv"
	"sync/atomic"
	"time"

	"github.com/philf90/asylum/internal/privops"
)

// maxLogFolger begrenzt, wie viele Journalströme gleichzeitig offen sein
// dürfen.
//
// Jeder von ihnen hält einen eigenen journalctl-Prozess: Anders als beim
// Paketvorgang, wo alle Zuschauer einen Vorgang teilen, hat hier jeder seinen
// eigenen Filter und braucht deshalb einen eigenen Aufruf. Zwanzig offene Tabs
// wären zwanzig Prozesse — kein Angriff, aber ein Panel, das sich selbst
// beschäftigt. Vier reichen für ein Panel mit ein paar Bedienenden; wer mehr
// braucht, macht etwas anderes falsch.
const maxLogFolger = 4

// apiLogEintrag ist eine Journalzeile.
type apiLogEintrag struct {
	// Zeit ist die formatierte Zeit für die Anzeige, At der rohe Wert. Beides,
	// weil die Anzeige eine feste Breite braucht (Mono, gleiche Stellen) und
	// eine Suche später nach dem rohen Wert sortieren muss.
	At    time.Time `json:"at"`
	Zeit  string    `json:"zeit"`
	Unit  string    `json:"unit"`
	Stufe string    `json:"stufe"`
	// StufeNr ist die syslog-Zahl, Ernst die daraus gebildete Grenze. Die Grenze
	// zieht der Server, damit sie einmal steht — dieselbe Regel wie im
	// Dienst-Inspektor.
	StufeNr   int    `json:"stufe_nr"`
	Ernst     bool   `json:"ernst"`
	Nachricht string `json:"nachricht"`
}

func logEintragAus(e privops.LogEntry) apiLogEintrag {
	return apiLogEintrag{
		At:        e.At,
		Zeit:      e.At.Format("02.01. 15:04:05"),
		Unit:      e.Unit,
		Stufe:     e.PriorityName(),
		StufeNr:   e.Priority,
		Ernst:     e.Priority <= 3,
		Nachricht: e.Message,
	}
}

// apiLogAbfrage ist die Abfrage, wie der Server sie verstanden hat.
//
// Zurückgegeben und nicht bloß angenommen: Wer „since=vorgestern" schickt,
// bekommt eine Fehlermeldung — aber wer eine Grenze überschreitet, deren Deckel
// er nicht kennt (limit=100000), soll sehen, was tatsächlich gefragt wurde. Eine
// Liste mit 200 Zeilen, die nach 100000 gefragt wurde, sieht sonst wie ein leeres
// Journal aus.
type apiLogAbfrage struct {
	Unit   string `json:"unit"`
	Stufe  int    `json:"stufe"`
	Seit   string `json:"seit"`
	Suche  string `json:"suche"`
	Anzahl int    `json:"anzahl"`
}

// apiLogs ist die Antwort von GET /api/v1/logs.
type apiLogs struct {
	Zeilen  []apiLogEintrag `json:"zeilen"`
	Units   []string        `json:"units"`
	Abfrage apiLogAbfrage   `json:"abfrage"`
	// Fehler statt eines Statuscodes: Die Unit-Liste kann stehen, auch wenn die
	// Abfrage klemmt, und andersherum. Eine leere Antwort mit 502 wäre die
	// schlechtere Auskunft.
	Fehler string `json:"fehler"`
	// FolgerFrei sagt, ob noch ein Strom offen sein darf. Die Oberfläche kann
	// den Knopf dann gleich richtig zeigen, statt ihn anzubieten und mit 429
	// abgewiesen zu werden.
	FolgerFrei bool `json:"folger_frei"`
}

// logAbfrageAus liest die Abfrage aus der Adresse — mit denselben Grenzen für
// beide Endpunkte.
func logAbfrageAus(r *http.Request) privops.LogQuery {
	q := r.URL.Query()

	abfrage := privops.LogQuery{
		Unit: q.Get("unit"),
		// -1 heißt alle Stufen. Nicht 7: Das wäre „bis debug" und damit fast
		// dasselbe, aber eben nicht dasselbe — journalctl --priority 7 lässt
		// Einträge ohne Stufe weg.
		Priority: -1,
		Since:    q.Get("since"),
		Search:   q.Get("q"),
		Limit:    200,
	}
	if roh := q.Get("priority"); roh != "" {
		if p, err := strconv.Atoi(roh); err == nil && p >= 0 && p <= 7 {
			abfrage.Priority = p
		}
	}
	if roh := q.Get("limit"); roh != "" {
		if n, err := strconv.Atoi(roh); err == nil && n > 0 {
			// Gedeckelt, und privops deckelt noch einmal: Wer 100000 fragt,
			// bekommt 1000 und sieht das in der Antwort.
			abfrage.Limit = min(n, 1000)
		}
	}
	return abfrage
}

func (s *Server) handleAPILogs(w http.ResponseWriter, r *http.Request) {
	abfrage := logAbfrageAus(r)

	antwort := apiLogs{
		Zeilen: []apiLogEintrag{},
		Units:  []string{},
		Abfrage: apiLogAbfrage{
			Unit: abfrage.Unit, Stufe: abfrage.Priority, Seit: abfrage.Since,
			Suche: abfrage.Search, Anzahl: abfrage.Limit,
		},
		FolgerFrei: s.logFolger.Load() < maxLogFolger,
	}

	eintraege, err := s.ops.Logs(r.Context(), abfrage)
	if err != nil {
		s.log.Error("logs lesen", "err", err)
		antwort.Fehler = "Das Journal ist nicht verfügbar: " + err.Error()
	}
	// Die neuesten oben. journalctl liefert aufsteigend, und wer ein Journal
	// öffnet, sucht das Letzte — nicht das, was vor zwei Stunden war.
	for i := len(eintraege) - 1; i >= 0; i-- {
		antwort.Zeilen = append(antwort.Zeilen, logEintragAus(eintraege[i]))
	}

	// Die Unit-Liste darf einzeln scheitern: Sie ist die Auswahl im Filter, kein
	// Teil des Ergebnisses.
	// Zuweisen nur, wenn wirklich etwas kam: Ein nil aus einem Journal ohne
	// Einträge machte aus dem leeren Feld sonst wieder null — die Vorbelegung
	// oben wäre wirkungslos. Der Test hat genau das gefunden.
	if units, err := s.ops.LogUnits(r.Context()); err != nil {
		s.log.Warn("log-units lesen", "err", err)
	} else if len(units) > 0 {
		antwort.Units = units
	}

	s.apiJSON(w, http.StatusOK, antwort)
}

// handleAPILogsFollow verfolgt das Journal, solange der Betrachter zusieht.
//
// Der Strom endet auf drei Wegen, und alle drei sind vorgesehen: Der Betrachter
// schließt die Seite (Kontext abgebrochen), journalctl beendet sich (dann steht
// ein Fehler im Strom), oder der Server fährt herunter. Ein viertes „nach einer
// Stunde ist Schluss" gibt es bewusst nicht — ein Journal, das man verfolgt, ist
// genau dann interessant, wenn man lange darauf wartet.
func (s *Server) handleAPILogsFollow(w http.ResponseWriter, r *http.Request) {
	// Platz nehmen, bevor irgendetwas geschrieben wird. Die Zählung ist ein
	// Compare-and-Swap in einer Schleife und kein Load-dann-Add: Zwei
	// gleichzeitige Anfragen hätten sonst beide „noch Platz" gelesen.
	for {
		aktuell := s.logFolger.Load()
		if aktuell >= maxLogFolger {
			s.apiFehler(w, http.StatusTooManyRequests,
				"Es sehen schon zu viele Verbindungen dem Journal zu. "+
					"Bitte einen anderen Tab schließen.")
			return
		}
		if s.logFolger.CompareAndSwap(aktuell, aktuell+1) {
			break
		}
	}
	defer s.logFolger.Add(-1)

	abfrage := logAbfrageAus(r)

	rc := http.NewResponseController(w)
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Accel-Buffering", "no")
	w.WriteHeader(http.StatusOK)
	if err := rc.Flush(); err != nil {
		return
	}

	// Die Einträge laufen über einen Kanal in diese Goroutine zurück, statt
	// direkt aus dem Sink geschrieben zu werden: Der Sink läuft im Lesefaden von
	// privops, und ein http.ResponseWriter darf nur von einem Faden bedient
	// werden. Ohne den Kanal wäre das ein Datenrennen, das im Betrieb selten und
	// dann schwer zu finden ist.
	//
	// Gepuffert und mit Verwerfen bei Überlauf: Bei einem Journal, das schneller
	// schreibt als die Leitung überträgt, ist eine verlorene Zeile das kleinere
	// Übel gegenüber einem journalctl, das am Schreiben hängt.
	zeilen := make(chan apiLogEintrag, 256)
	verworfen := &atomic.Int64{}

	ctx := r.Context()
	fehler := make(chan error, 1)
	go func() {
		fehler <- s.ops.LogsFollow(ctx, abfrage, func(e privops.LogEntry) {
			select {
			case zeilen <- logEintragAus(e):
			default:
				verworfen.Add(1)
			}
		})
		close(zeilen)
	}()

	// Ein Herzschlag hält die Verbindung offen. Ein Reverse-Proxy schließt eine
	// stille Verbindung nach einer Minute, und ein ruhiges Journal ist genau das:
	// still. Ein Kommentar (Doppelpunkt am Zeilenanfang) ist im
	// Ereignisstrom-Format ausdrücklich dafür vorgesehen und löst beim Client
	// kein Ereignis aus.
	herzschlag := time.NewTicker(25 * time.Second)
	defer herzschlag.Stop()

	for {
		select {
		case <-ctx.Done():
			return

		case <-herzschlag.C:
			if _, err := w.Write([]byte(": still\n\n")); err != nil {
				return
			}
			if rc.Flush() != nil {
				return
			}

		case e, ok := <-zeilen:
			if !ok {
				// Der Strom ist zu Ende. Der Grund steht im Fehlerkanal — ein
				// abgebrochener Kontext ist keiner.
				err := <-fehler
				// LogsFollow gibt bei einem Abbruch bereits nil zurück; die
				// Prüfung hier ist der zweite Riegel für den Fall, dass ein
				// anderer Executor (etwa der spätere root-Agent) den Abbruch
				// doch als Fehler durchreicht. Eine Fehlermeldung bei jedem
				// geschlossenen Tab wäre das Gegenteil einer Auskunft.
				if err != nil && !errors.Is(err, context.Canceled) {
					writeSSE(w, rc, "fehler", err.Error())
				}
				writeSSE(w, rc, "ende", "")
				return
			}
			roh, err := json.Marshal(e)
			if err != nil {
				continue
			}
			if _, err := w.Write([]byte("event: zeile\ndata: " + string(roh) + "\n\n")); err != nil {
				return
			}
			if rc.Flush() != nil {
				return
			}
			// Verworfene Zeilen werden gemeldet und nicht verschwiegen: Eine
			// Lücke, die niemand sieht, ist schlimmer als eine, die dasteht.
			if n := verworfen.Swap(0); n > 0 {
				writeSSE(w, rc, "luecke", strconv.FormatInt(n, 10))
			}
		}
	}
}
