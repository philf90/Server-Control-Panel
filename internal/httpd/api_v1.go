package httpd

// JSON-Schnittstelle unter /api/v1.
//
// Sie ist die einzige Datenquelle der neuen Oberfläche. Geschnitten ist sie
// von Anfang an so, als käme später eine zweite Kundin (CLI, Automatisierung):
// Ressourcen statt Seiten, Fehler als JSON statt als HTML, Rechte serverseitig
// am Endpunkt.
//
// Was hier NICHT passiert: rechnen, was die alte Oberfläche schon rechnet. Die
// Verläufe kommen aus demselben buildSpark, die Messwerte aus demselben
// Ringpuffer, der Live-Kanal bleibt der bestehende SSE-Hub. Zwei Fassungen
// derselben Zahl liefen früher oder später auseinander.

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/metrics"
	"github.com/philf90/asylum/internal/ui"
)

// apiJSON schreibt eine Antwort. Kein Caching: Alles hier ist ein Messwert
// oder ein Sitzungsdetail, und beides darf kein Zwischenspeicher festhalten.
func (s *Server) apiJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(status)
	if err := json.NewEncoder(w).Encode(v); err != nil {
		// Der Kopf ist geschrieben, der Statuscode steht — mehr als
		// protokollieren geht hier nicht mehr.
		s.log.Error("api: Antwort nicht geschrieben", "err", err)
	}
}

// apiFehler antwortet mit JSON, nicht mit HTML. Das ist der Grund, warum es
// diese Funktion gibt: renderError liefert eine Seite, und ein fetch, das eine
// Seite bekommt, meldet einen Parserfehler statt der eigentlichen Ursache.
func (s *Server) apiFehler(w http.ResponseWriter, status int, meldung string) {
	s.apiJSON(w, status, map[string]string{"fehler": meldung})
}

// apiSitzung ist die Antwort von GET /api/v1/session.
type apiSitzung struct {
	Benutzer      string `json:"benutzer"`
	Rolle         string `json:"rolle"`
	DarfSchreiben bool   `json:"darf_schreiben"`
	IstOwner      bool   `json:"ist_owner"`
	// CSRF ist das Token der Sitzung. Die alte Oberfläche bekam es in jede
	// gerenderte Seite; eine SPA bekommt kein gerendertes HTML und holt es
	// hier. Es steht bewusst nicht in einem Cookie — dann wäre es kein zweiter
	// Nachweis mehr, sondern derselbe, den ein Angreifer schon mitschickt.
	CSRF string `json:"csrf"`
}

func (s *Server) handleAPISession(w http.ResponseWriter, r *http.Request) {
	user, ok := userFrom(r.Context())
	if !ok {
		s.apiFehler(w, http.StatusUnauthorized, "nicht angemeldet")
		return
	}
	sess, ok := sessionFrom(r.Context())
	if !ok {
		s.apiFehler(w, http.StatusUnauthorized, "nicht angemeldet")
		return
	}

	s.apiJSON(w, http.StatusOK, apiSitzung{
		Benutzer:      user.Username,
		Rolle:         user.Role,
		DarfSchreiben: user.CanWrite(),
		IstOwner:      user.CanManageUsers(),
		CSRF:          sess.CSRFToken,
	})
}

// apiBefehl ist ein Eintrag des privops-Journals für die Protokollzeile.
type apiBefehl struct {
	At          time.Time `json:"at"`
	Zeile       string    `json:"zeile"`
	Exit        int       `json:"exit"`
	DauerText   string    `json:"dauer_text"`
	Gescheitert bool      `json:"gescheitert"`
}

// apiWert ist ein Kachelwert: die große Zahl und die kleine Einheit daneben.
//
// Getrennt und nicht als ein Text, weil die Kachel beides verschieden groß
// setzt — dieselbe Aufteilung wie in der alten Vorlage, wo die Zahl aus `pct`
// kommt und das Prozentzeichen als <small> danebensteht.
type apiWert struct {
	Wert    string `json:"wert"`
	Einheit string `json:"einheit"`
}

// apiWerte sind die Kachelwerte, fertig formatiert. Einheit, Rundung und
// Sprache stehen damit an einer Stelle — auf dem Server. Der Live-Kanal
// überträgt daneben weiter rohe Zahlen, weil ihn beide Oberflächen lesen; die
// neue formatiert sie in web/src/lib/formate.ts nach denselben Regeln.
type apiWerte struct {
	CPU    apiWert `json:"cpu"`
	Memory apiWert `json:"memory"`
	Load   apiWert `json:"load"`
	Netz   apiWert `json:"netz"`
}

// kachelZahl ist die Rundung der großen Zahl: eine Nachkommastelle, wie die
// Template-Funktion `pct` der alten Oberfläche.
//
// Ausdrücklich nicht lastText: Das rundet auf zwei Stellen und gehört zu den
// Stützstellen des Verlaufs, wo bei einem ruhigen Server sonst jeder Punkt
// "0.0" hieße. Auf der Kachel wären zwei Stellen eine Genauigkeit, die die
// Zahl nicht hat.
func kachelZahl(v float64) string { return fmt.Sprintf("%.1f", v) }

// durchsatzKachel teilt "2.0 KiB/s" in Zahl und Einheit.
//
// Zerlegt statt getrennt gerechnet, damit es bei genau einer Formatierung
// bleibt: ui.FormatRate bestimmt Größenordnung und Rundung gemeinsam, und eine
// zweite Fassung davon liefe irgendwann auseinander. Die Ausgabe hat immer die
// Form "<Zahl> <Einheit>"; fehlt das Leerzeichen doch, steht alles in der Zahl
// und die Kachel zeigt eher zu viel als etwas Falsches.
func durchsatzKachel(bytesPerSecond float64) apiWert {
	text := ui.FormatRate(bytesPerSecond)
	if i := strings.LastIndex(text, " "); i > 0 {
		return apiWert{Wert: text[:i], Einheit: text[i+1:]}
	}
	return apiWert{Wert: text}
}

// apiUebersicht ist die Antwort von GET /api/v1/overview.
type apiUebersicht struct {
	Host     metrics.Host      `json:"host"`
	Name     string            `json:"name"`
	Snapshot *metrics.Snapshot `json:"snapshot"`
	Werte    apiWerte          `json:"werte"`
	NetzName string            `json:"netz_name"`
	// LetzterBefehl ist leer, wenn noch nichts ausgeführt wurde oder der Server
	// mit einem fremden Executor läuft (Tests) — dann zeigt die Protokollzeile
	// ihren leeren Zustand statt eines falschen Bildes.
	LetzterBefehl *apiBefehl `json:"letzter_befehl"`
}

// handleAPIOverview liefert Host, jüngste Messung und die fertigen
// Kachelwerte.
//
// Bewusst ohne Handlungsbedarf: Dessen Erhebung ruft systemctl und apt auf. Der
// Endpunkt soll billig bleiben, damit ihn die Oberfläche bei jedem Aufbau
// fragen kann; die Signale kommen als eigene Ressource, wenn die Übersicht sie
// zeigt.
func (s *Server) handleAPIOverview(w http.ResponseWriter, r *http.Request) {
	host := s.sampler.Host()
	antwort := apiUebersicht{
		Host: host,
		Name: host.Name(),
	}

	if snap, ok := s.lastSnapshot(); ok {
		antwort.Snapshot = &snap
		antwort.Werte = apiWerte{
			CPU:    apiWert{Wert: kachelZahl(snap.CPU.Total), Einheit: "%"},
			Memory: apiWert{Wert: kachelZahl(snap.Memory.UsedPct), Einheit: "%"},
			// Die Last trägt keine Einheit — sie ist keine.
			Load: apiWert{Wert: kachelZahl(snap.Load[0])},
			Netz: durchsatzKachel(0),
		}
		if ifc, ok := snap.PrimaryInterface(); ok {
			antwort.Werte.Netz = durchsatzKachel(ifc.RXRate)
			antwort.NetzName = ifc.Name
		}
	}

	if s.journal != nil {
		if letzte := s.journal.Letzte(1); len(letzte) > 0 {
			n := letzte[0]
			antwort.LetzterBefehl = &apiBefehl{
				At:          n.Zeit,
				Zeile:       n.Befehl,
				Exit:        n.ExitCode,
				DauerText:   n.DauerText(),
				Gescheitert: !n.Gelungen(),
			}
		}
	}

	s.apiJSON(w, http.StatusOK, antwort)
}

// apiVerlauf ist ein Verlauf, wie ihn die Kachel zeichnet: der Pfad im
// 100×34-Feld, der Endpunkt und die Stützstellen mit fertigen Texten.
type apiVerlauf struct {
	Path   string       `json:"path"`
	Dot    string       `json:"dot"`
	Points []sparkPunkt `json:"points"`
	Has    bool         `json:"has"`
}

func verlaufAus(sp spark) apiVerlauf {
	// Ohne Verlauf ein leeres Feld statt null: Die Oberfläche prüft `has`, und
	// ein fehlendes Array wäre eine zweite Sonderregel für denselben Fall.
	punkte := sp.Punkte
	if punkte == nil {
		punkte = []sparkPunkt{}
	}
	return apiVerlauf{Path: sp.Path, Dot: sp.Dot, Points: punkte, Has: sp.Has}
}

// apiVerlaeufe ist die Antwort von GET /api/v1/metrics/history.
type apiVerlaeufe struct {
	CPU    apiVerlauf `json:"cpu"`
	Memory apiVerlauf `json:"memory"`
	Load   apiVerlauf `json:"load"`
	Netz   apiVerlauf `json:"netz"`
}

// handleAPIMetricsHistory liefert die Verläufe der letzten 24 Stunden aus dem
// Ringpuffer — dieselbe Rechnung, die die alte Übersicht zeichnet.
func (s *Server) handleAPIMetricsHistory(w http.ResponseWriter, r *http.Request) {
	sp := s.dashboardSparks()
	s.apiJSON(w, http.StatusOK, apiVerlaeufe{
		CPU:    verlaufAus(sp.CPU),
		Memory: verlaufAus(sp.Mem),
		Load:   verlaufAus(sp.Load),
		Netz:   verlaufAus(sp.Net),
	})
}
