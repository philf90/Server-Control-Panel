package httpd

// Audit über /api/v1.
//
// Das Revisionsprotokoll ist die einzige Liste des Panels, die unbegrenzt wächst,
// und daraus folgt alles, was dieses Modul von den anderen unterscheidet:
//
//  1. **Gefiltert wird auf dem Server.** Bei den Diensten kommt die Liste einmal
//     und das Tippen filtert sofort; hier ist jede Antwort nur ein Ausschnitt,
//     und ein Filter darüber behauptete „kein Treffer" für einen Eintrag, den es
//     gibt. Wer fragt „wer hat site.conf gelöscht", meint einen Eintrag von
//     vorletzter Woche.
//  2. **Geblättert wird über eine ID, nicht über einen Versatz.** Das Protokoll
//     wächst, während man darin liest — ein OFFSET zeigte nach drei neuen
//     Einträgen drei Zeilen doppelt. In einem Revisionsprotokoll ist das die
//     falsche Art von Fehler: Man blättert darin, um etwas NICHT zu übersehen.
//  3. **Es gibt keine verändernde Aktion.** Das Protokoll ist nur additiv; es
//     gibt im Store bewusst keine Lösch- oder Änderungsfunktion. Dieses Modul
//     hat deshalb keinen einzigen POST — und das ist kein fehlendes Stück,
//     sondern die Aussage.

import (
	"net/http"
	"strconv"
	"strings"

	"github.com/philf90/asylum/internal/store"
)

// apiAuditZeile ist ein Eintrag des Protokolls.
type apiAuditZeile struct {
	ID int64 `json:"id"`
	// Zeit ist die aufbereitete Fassung, At der rohe Zeitstempel. Beides, weil
	// die Oberfläche das eine anzeigt und das andere zum Gruppieren nach Tagen
	// braucht.
	Zeit   string `json:"zeit"`
	At     string `json:"at"`
	Akteur string `json:"akteur"`
	Aktion string `json:"aktion"`
	// Familie ist der Teil der Aktion vor dem ersten Punkt — die Zuordnung zum
	// Modul. Sie wird hier gebildet und nicht im Browser, damit die Filterleiste
	// und die Zeile dieselbe Regel benutzen.
	Familie  string `json:"familie"`
	Ziel     string `json:"ziel"`
	Ergebnis string `json:"ergebnis"`
	// Stufe ist die Klasse für die Einfärbung: gut, warn, schlecht. Der Server
	// entscheidet sie, weil „denied" eine Aussage über die Politik ist und
	// „error" eine über das System — zwei verschiedene Dinge, die nicht dieselbe
	// Farbe tragen dürfen.
	Stufe  string `json:"stufe"`
	IP     string `json:"ip"`
	Detail string `json:"detail"`
}

func auditZeileAus(e store.AuditEntry) apiAuditZeile {
	return apiAuditZeile{
		ID:       e.ID,
		Zeit:     e.At.Format("02.01.2006 15:04:05"),
		At:       e.At.Format("2006-01-02"),
		Akteur:   e.Actor,
		Aktion:   e.Action,
		Familie:  familieVon(e.Action),
		Ziel:     e.Target,
		Ergebnis: e.Result,
		Stufe:    auditStufe(e.Result),
		IP:       e.IP,
		Detail:   e.Detail,
	}
}

// familieVon nimmt den Teil vor dem ersten Punkt. Dieselbe Regel wie in
// store.AuditFacetten — dort in SQL, damit nicht alle Aktionen der Geschichte
// durch den Prozess wandern, nur um gekürzt zu werden.
func familieVon(aktion string) string {
	if i := strings.IndexByte(aktion, '.'); i > 0 {
		return aktion[:i]
	}
	return aktion
}

// auditStufe färbt nach Ergebnis.
//
// „denied" ist bewusst eine Warnung und kein Fehler: Es heißt, dass die Politik
// gegriffen hat — das Panel hat funktioniert. Rot ist „error", wo etwas nicht
// getan wurde, was getan werden sollte.
func auditStufe(ergebnis string) string {
	switch ergebnis {
	case store.ResultOK:
		return "gut"
	case store.ResultDenied:
		return "warn"
	default:
		return "schlecht"
	}
}

// apiAudit ist die Antwort von GET /api/v1/audit.
type apiAudit struct {
	Zeilen []apiAuditZeile `json:"zeilen"`
	// Weiter ist die ID, mit der die nächste Seite geholt wird — 0, wenn es
	// keine weitere gibt. Der Server sagt das und nicht die Oberfläche: Ob eine
	// Seite die letzte war, hängt daran, ob sie voll wurde, und diese Rechnung
	// gehört an eine Stelle.
	Weiter int64 `json:"weiter"`
	// Akteure und Familien füllen die Auswahlfelder. Ein Textfeld wäre eine
	// Rechtschreibprüfung: Wer sich vertippt, bekommt „keine Treffer" und
	// schließt daraus, dass nichts geschehen ist.
	Akteure  []string `json:"akteure"`
	Familien []string `json:"familien"`
	// Filter gibt zurück, was gegolten hat. Die Oberfläche hat es geschickt,
	// aber der Server hat es beschnitten und geprüft — und was gilt, soll aus der
	// Antwort ablesbar sein und nicht aus der Adresse.
	Filter apiAuditFilter `json:"filter"`
}

type apiAuditFilter struct {
	Akteur   string `json:"akteur"`
	Familie  string `json:"familie"`
	Ergebnis string `json:"ergebnis"`
	Suche    string `json:"suche"`
}

// auditSeite ist die Zahl der Einträge je Antwort.
const auditSeite = 100

func (s *Server) handleAPIAudit(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()

	filter := store.AuditFilter{
		Actor:  strings.TrimSpace(q.Get("akteur")),
		Action: strings.TrimSpace(q.Get("familie")),
		Result: q.Get("ergebnis"),
		Query:  strings.TrimSpace(q.Get("q")),
		Limit:  auditSeite,
	}
	// Ein unbekanntes Ergebnis wird verworfen und nicht durchgereicht: Die Spalte
	// hat einen CHECK, es gibt genau drei Werte, und alles andere fände nichts —
	// eine leere Liste ohne Grund ist die schlechteste Antwort auf einen alten
	// Verweis.
	switch filter.Result {
	case store.ResultOK, store.ResultDenied, store.ResultError:
	default:
		filter.Result = ""
	}
	if roh := q.Get("vor"); roh != "" {
		if n, err := strconv.ParseInt(roh, 10, 64); err == nil && n > 0 {
			filter.Before = n
		}
	}
	// Die Familie wird als Präfix mit Punkt gesucht: „files" soll „files.delete"
	// finden, aber nicht ein künftiges „filesystem.pruefen".
	if filter.Action != "" {
		filter.Action += "."
	}

	eintraege, err := s.db.FilterAudit(r.Context(), filter)
	if err != nil {
		s.log.Error("audit lesen", "err", err)
		s.apiFehler(w, http.StatusInternalServerError, "Das Protokoll ist nicht lesbar: "+err.Error())
		return
	}

	antwort := apiAudit{
		Zeilen:   make([]apiAuditZeile, 0, len(eintraege)),
		Akteure:  []string{},
		Familien: []string{},
		Filter: apiAuditFilter{
			Akteur:   filter.Actor,
			Familie:  strings.TrimSuffix(filter.Action, "."),
			Ergebnis: filter.Result,
			Suche:    filter.Query,
		},
	}
	for _, e := range eintraege {
		antwort.Zeilen = append(antwort.Zeilen, auditZeileAus(e))
	}

	// Weiter nur, wenn die Seite voll wurde. Eine halbe Seite ist das Ende —
	// jede andere Regel bräuchte eine zweite Abfrage nur für die Frage, ob noch
	// etwas kommt.
	if len(eintraege) == auditSeite {
		antwort.Weiter = eintraege[len(eintraege)-1].ID
	}

	// Die Facetten scheitern zu lassen wäre falsch: Dann fehlten die
	// Auswahlfelder, die Liste steht aber da. Sie sind eine Bedienhilfe, kein
	// Teil der Antwort.
	if akteure, familien, err := s.db.AuditFacetten(r.Context()); err == nil {
		if len(akteure) > 0 {
			antwort.Akteure = akteure
		}
		if len(familien) > 0 {
			antwort.Familien = familien
		}
	} else {
		s.log.Warn("audit-facetten", "err", err)
	}

	s.apiJSON(w, http.StatusOK, antwort)
}
