package httpd

// Dienste über /api/v1.
//
// Das erste Modul der neuen Oberfläche neben der Übersicht — und deshalb die
// Stelle, an der drei Dinge zum ersten Mal entschieden werden, die danach
// siebenmal wiederverwendet werden:
//
//  1. Wie eine Liste aussieht: die Reihen, dazu die Zähler, die der Server
//     ausrechnet. Zählt der Browser selbst, zählt bei „gescheitert" jedes Modul
//     nach eigener Regel — und die Übersicht nach einer dritten.
//  2. Wie eine Aktion läuft: POST mit JSON-Körper, Schreibrecht und Token am
//     Endpunkt, und die Antwort ist der **neu gelesene** Zustand des Ziels. Die
//     Oberfläche muss nach einem Neustart nichts raten.
//  3. Wie eine Rückfrage aussieht, wenn keine Seite gerendert wird: Der Handler
//     führt nichts aus, solange `bestaetigt` fehlt, und antwortet stattdessen
//     mit dem Text der Rückfrage als JSON. Das ist docs/14-bestaetigungen.md
//     wortgleich übersetzt — die Zwischenseite wird ein Objekt. Verbindlich
//     bleibt der Server: Ein selbstgebautes POST ohne das Feld tut nichts, und
//     der Dialog im Browser darf sich irren, ohne dass es gefährlich wird.

import (
	"net/http"
	"sort"
	"strings"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
	"github.com/philf90/asylum/internal/ui"
)

// Zustände, die die Tabelle einfärbt. Der Server entscheidet sie, nicht die
// Oberfläche: „gescheitert" hängt an zwei Feldern (Active und Sub), und diese
// Regel steht schon in privops.Service.Failed. Zwei Fassungen davon liefen
// auseinander, und die Übersicht zählte dann andere Dienste als die Liste.
const (
	zustandLaeuft      = "laeuft"
	zustandGescheitert = "gescheitert"
	zustandAus         = "aus"
)

// apiDienst ist eine Zeile der Dienstliste.
type apiDienst struct {
	Unit string `json:"unit"`
	// Name ist die Unit ohne die Endung .service — was in der Tabelle steht.
	// Gekürzt auf dem Server, damit die Oberfläche nicht an zwei Stellen
	// dieselbe Endung abschneidet und eine davon vergisst.
	Name         string `json:"name"`
	Beschreibung string `json:"beschreibung"`
	Zustand      string `json:"zustand"`
	// Aktiv und Unterzustand sind die rohen systemd-Wörter ("active",
	// "running"). Sie stehen daneben, weil „aus" drei sehr verschiedene Dinge
	// heißen kann: nie gestartet, sauber beendet, oder von Hand gestoppt.
	Aktiv        string `json:"aktiv"`
	Unterzustand string `json:"unterzustand"`
	Laden        string `json:"laden"`
	Autostart    string `json:"autostart"`
}

func dienstAus(svc privops.Service) apiDienst {
	return apiDienst{
		Unit:         svc.Unit,
		Name:         strings.TrimSuffix(svc.Unit, ".service"),
		Beschreibung: svc.Description,
		Zustand:      zustandVon(svc),
		Aktiv:        svc.Active,
		Unterzustand: svc.Sub,
		Laden:        svc.Load,
		Autostart:    svc.Enabled,
	}
}

func zustandVon(svc privops.Service) string {
	switch {
	case svc.Failed():
		return zustandGescheitert
	case svc.Running():
		return zustandLaeuft
	default:
		return zustandAus
	}
}

// apiDienstZaehler sind die Zahlen über der Liste.
type apiDienstZaehler struct {
	Gesamt      int `json:"gesamt"`
	Laeuft      int `json:"laeuft"`
	Gescheitert int `json:"gescheitert"`
	Aus         int `json:"aus"`
}

// apiDienste ist die Antwort von GET /api/v1/services.
type apiDienste struct {
	Dienste []apiDienst      `json:"dienste"`
	Zaehler apiDienstZaehler `json:"zaehler"`
}

// handleAPIServices liefert die vollständige Liste.
//
// Ohne Suchfeld und ohne Zustandsfilter in der Anfrage, anders als die alte
// Seite: Die Oberfläche hat die Liste schon und filtert im Browser, das ist
// beim Tippen sofort da statt einmal pro Buchstabe über systemctl. Ein Server
// mit einigen hundert Units bleibt dabei deutlich unter dem, was eine Antwort
// tragen kann.
//
// Sortiert wird hier und nicht im Browser: Gescheitertes zuerst — es ist der
// Grund, warum jemand diese Seite öffnet —, danach alphabetisch. Sortierte der
// Browser, müsste er dieselbe Regel kennen, und die Reihenfolge wäre von der
// Reihenfolge von systemctl abhängig, sobald jemand das Sortieren vergisst.
func (s *Server) handleAPIServices(w http.ResponseWriter, r *http.Request) {
	svcs, err := s.ops.Services(r.Context(), privops.ServiceFilter{})
	if err != nil {
		s.log.Error("dienste lesen", "err", err)
		s.apiFehler(w, http.StatusBadGateway, "Die Dienstliste ist nicht verfügbar: "+err.Error())
		return
	}

	antwort := apiDienste{Dienste: make([]apiDienst, 0, len(svcs))}
	for _, svc := range svcs {
		antwort.Dienste = append(antwort.Dienste, dienstAus(svc))
	}

	sort.SliceStable(antwort.Dienste, func(i, j int) bool {
		a, b := antwort.Dienste[i], antwort.Dienste[j]
		if (a.Zustand == zustandGescheitert) != (b.Zustand == zustandGescheitert) {
			return a.Zustand == zustandGescheitert
		}
		return a.Name < b.Name
	})

	antwort.Zaehler.Gesamt = len(antwort.Dienste)
	for _, d := range antwort.Dienste {
		switch d.Zustand {
		case zustandGescheitert:
			antwort.Zaehler.Gescheitert++
		case zustandLaeuft:
			antwort.Zaehler.Laeuft++
		default:
			antwort.Zaehler.Aus++
		}
	}

	s.apiJSON(w, http.StatusOK, antwort)
}

// apiLogzeile ist eine Journalzeile im Inspektor.
type apiLogzeile struct {
	At        string `json:"at"`
	Stufe     string `json:"stufe"`
	Nachricht string `json:"nachricht"`
	// Ernst markiert Zeilen ab „error" — das ist die Zeile, die man sucht, wenn
	// ein Dienst gescheitert ist. Die Stufe ist eine Zahl aus syslog; welche
	// davon ernst ist, entscheidet der Server, damit die Grenze einmal steht.
	Ernst bool `json:"ernst"`
}

// apiDienstDetail ist die Antwort von GET /api/v1/services/{unit}.
type apiDienstDetail struct {
	apiDienst
	Seit          string        `json:"seit"`
	HauptPID      int           `json:"haupt_pid"`
	Speicher      string        `json:"speicher"`
	SpeicherBytes uint64        `json:"speicher_bytes"`
	Aufgaben      int           `json:"aufgaben"`
	UnitDatei     string        `json:"unit_datei"`
	Logzeilen     []apiLogzeile `json:"logzeilen"`
	// Aktionen sind die Aktionen, die für diesen Dienst sinnvoll sind. Eine
	// Bedienhilfe und keine Rechteprüfung: Verbindlich ist der Endpunkt, der
	// jede Aktion gegen die Allowlist prüft. Der Grund, sie hier zu berechnen,
	// ist ein anderer — „starten" an einem laufenden Dienst anzubieten ist eine
	// Frage, die die Oberfläche selbst beantworten kann.
	Aktionen []string `json:"aktionen"`
}

func (s *Server) detailAus(d privops.ServiceDetail) apiDienstDetail {
	detail := apiDienstDetail{
		apiDienst:     dienstAus(d.Service),
		Seit:          d.Since,
		HauptPID:      d.MainPID,
		SpeicherBytes: d.Memory,
		Aufgaben:      d.Tasks,
		UnitDatei:     d.FragmentP,
		Logzeilen:     make([]apiLogzeile, 0, len(d.RecentLogs)),
		Aktionen:      aktionenFuer(d.Service),
	}
	// Leer statt "0 B", wenn systemd keinen Wert liefert: Ein Dienst ohne
	// Accounting hat keinen Verbrauch von null, sondern keinen bekannten.
	if d.Memory > 0 {
		detail.Speicher = ui.FormatBytes(d.Memory)
	}
	for _, l := range d.RecentLogs {
		detail.Logzeilen = append(detail.Logzeilen, apiLogzeile{
			At:        l.At.Format("02.01. 15:04:05"),
			Stufe:     l.PriorityName(),
			Nachricht: l.Message,
			Ernst:     l.Priority <= 3,
		})
	}
	return detail
}

// aktionenFuer sagt, welche Aktionen zu diesem Zustand passen.
func aktionenFuer(svc privops.Service) []string {
	aktionen := make([]string, 0, 4)
	if svc.Running() {
		aktionen = append(aktionen, string(privops.ServiceRestart),
			string(privops.ServiceReload), string(privops.ServiceStop))
	} else {
		aktionen = append(aktionen, string(privops.ServiceStart))
	}
	// „static" und „masked" kennen kein Ein und Aus: Die erste Unit hat kein
	// [Install], die zweite ist absichtlich nach /dev/null verlegt. Beides über
	// systemctl enable zu versuchen liefert einen Fehler, den niemand als
	// Antwort auf einen Knopf erwartet.
	switch svc.Enabled {
	case "enabled", "enabled-runtime":
		aktionen = append(aktionen, string(privops.ServiceDisable))
	case "disabled":
		aktionen = append(aktionen, string(privops.ServiceEnable))
	}
	return aktionen
}

func (s *Server) handleAPIServiceDetail(w http.ResponseWriter, r *http.Request) {
	detail, err := s.ops.Service(r.Context(), r.PathValue("unit"))
	if err != nil {
		s.apiFehler(w, http.StatusNotFound, "Die Unit ist nicht verfügbar: "+err.Error())
		return
	}
	s.apiJSON(w, http.StatusOK, s.detailAus(detail))
}

// apiAktionAnfrage ist der Körper eines POST auf eine Ressource.
//
// Bestaetigt und Getippt sind die JSON-Fassung der beiden Felder, die die
// Zwischenseite der alten Oberfläche im Formular mitschickt.
type apiAktionAnfrage struct {
	Aktion     string `json:"aktion"`
	Bestaetigt bool   `json:"bestaetigt"`
	Getippt    string `json:"getippt"`
}

// apiAktionAntwort ist die Antwort auf eine ausgeführte Aktion.
type apiAktionAntwort struct {
	Meldung string `json:"meldung"`
	// Detail ist der neu gelesene Zustand. Ohne ihn müsste die Oberfläche nach
	// jeder Aktion eine zweite Anfrage stellen — und würde in der Lücke
	// dazwischen den alten Zustand zeigen, was nach einem Neustart genau so
	// aussieht wie ein Neustart, der nicht geklappt hat.
	Detail apiDienstDetail `json:"detail"`
}

func (s *Server) handleAPIServiceAction(w http.ResponseWriter, r *http.Request) {
	unit := r.PathValue("unit")

	anfrage, ok := s.apiKoerper(w, r)
	if !ok {
		return
	}

	aktion := privops.ServiceAction(anfrage.Aktion)
	if !privops.ValidServiceAction(aktion) {
		s.apiFehler(w, http.StatusBadRequest, "Unbekannte Aktion: "+anfrage.Aktion)
		return
	}

	// Nur das Stoppen fragt zurück — Stufe 2 aus docs/14-bestaetigungen.md.
	// Starten, Neustarten und Nachladen sind umkehrbar; ein Dialog davor
	// erzieht zum Wegklicken und entwertet die Rückfrage dort, wo sie zählt.
	if aktion == privops.ServiceStop {
		if !s.apiBestaetigt(w, anfrage, apiBestaetigung{
			Titel: "Dienst stoppen",
			Frage: unit + " stoppen?",
			Punkte: []string{
				"Was der Dienst bereitstellt, ist danach nicht mehr erreichbar.",
				"Der Autostart bleibt unberührt: Nach einem Neustart des Servers läuft er wieder.",
			},
			Knopf: "stoppen",
		}) {
			return
		}
	}

	if err := s.ops.ServiceAction(r.Context(), unit, aktion); err != nil {
		s.audit(r, "service."+string(aktion), unit, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadGateway, err.Error())
		return
	}
	s.audit(r, "service."+string(aktion), unit, store.ResultOK, "")

	// Der Zustand wird neu gelesen. Scheitert das, ist die Aktion trotzdem
	// gelaufen — dann sagt die Antwort das und lässt das Detail leer, statt die
	// gelungene Aktion als Fehler zu melden.
	antwort := apiAktionAntwort{Meldung: unit + ": " + string(aktion) + " ausgeführt."}
	if detail, err := s.ops.Service(r.Context(), unit); err == nil {
		antwort.Detail = s.detailAus(detail)
	} else {
		s.log.Warn("dienst nach aktion lesen", "unit", unit, "err", err)
	}
	s.apiJSON(w, http.StatusOK, antwort)
}

// apiKoerper liest den JSON-Körper einer Dienstaktion.
func (s *Server) apiKoerper(w http.ResponseWriter, r *http.Request) (apiAktionAnfrage, bool) {
	var anfrage apiAktionAnfrage
	return anfrage, s.apiJSONKoerper(w, r, &anfrage)
}
