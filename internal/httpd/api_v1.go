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
	"errors"
	"fmt"
	"io"
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

// apiJSONKoerper liest den JSON-Körper einer verändernden Anfrage in ziel.
//
// Mit Grenze: Ein Körper ohne Obergrenze ist ein Weg, dem Panel den Speicher zu
// nehmen, und diese Anfragen sind wenige hundert Bytes groß.
//
// Ein leerer Körper ist in Ordnung — er bedeutet „alle Felder auf ihrem
// Vorgabewert", und bei einer Rückfrage heißt das: nicht bestätigt. Genau so soll
// es sein: Wer nichts schickt, hat nichts bestätigt.
func (s *Server) apiJSONKoerper(w http.ResponseWriter, r *http.Request, ziel any) bool {
	dec := json.NewDecoder(io.LimitReader(r.Body, 64<<10))
	// Unbekannte Felder abweisen: Ein Tippfehler in "bestaetigt" wäre sonst
	// stillschweigend ein fehlendes Feld — also eine Rückfrage, die nie
	// beantwortet wurde, obwohl der Aufrufer meint, sie beantwortet zu haben.
	dec.DisallowUnknownFields()
	if err := dec.Decode(ziel); err != nil && !errors.Is(err, io.EOF) {
		s.apiFehler(w, http.StatusBadRequest, "Der Anfragekörper ist unlesbar: "+err.Error())
		return false
	}
	return true
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
	//
	// Bei einer Anfrage über einen API-Token ist das Feld leer: Es gibt keine
	// Sitzung, und ein Token braucht kein Sitzungstoken.
	CSRF string `json:"csrf"`

	// Token, NurLesen und Scopes stehen nur bei einer Anfrage über einen
	// API-Token. Sie sind die Antwort auf „womit bin ich hier unterwegs und was
	// darf ich damit" — ein Skript soll seine eigenen Grenzen erfragen können,
	// statt sie durch einen 403 zu erfahren.
	Token    string   `json:"token,omitempty"`
	NurLesen bool     `json:"nur_lesen,omitempty"`
	Scopes   []string `json:"scopes,omitempty"`
}

func (s *Server) handleAPISession(w http.ResponseWriter, r *http.Request) {
	user, ok := userFrom(r.Context())
	if !ok {
		s.apiFehler(w, http.StatusUnauthorized, "nicht angemeldet")
		return
	}
	antwort := apiSitzung{
		Benutzer:      user.Username,
		Rolle:         user.Role,
		DarfSchreiben: user.CanWrite(),
		IstOwner:      user.CanManageUsers(),
	}

	// Kam die Anfrage über einen API-Token, gibt es keine Sitzung und damit kein
	// Sitzungstoken. Das ist kein Fehler, sondern die Auskunft: Ein Skript fragt
	// hier, als wer es unterwegs ist und was es darf — und es braucht kein
	// CSRF-Token, weil es keinen Cookie mitschickt (siehe tokenauth.go).
	//
	// Bis hierher antwortete dieser Endpunkt in dem Fall mit 401 „nicht
	// angemeldet", weil er die Sitzung verlangte. Für ein Skript war das die
	// falscheste aller Auskünfte: Der Token war gültig, und die Meldung sagte das
	// Gegenteil.
	if tok, mitToken := tokenFrom(r.Context()); mitToken {
		antwort.Token = tok.Name
		antwort.NurLesen = tok.ReadOnly
		antwort.Scopes = tok.Scopes
		if antwort.Scopes == nil {
			antwort.Scopes = []string{}
		}
		// Ein Nur-Lese-Token darf nicht schreiben, egal was die Rolle sagt. Die
		// Auskunft muss das abbilden, sonst baut ein Skript einen Knopf, den der
		// Server gleich darauf verweigert.
		if tok.ReadOnly {
			antwort.DarfSchreiben = false
		}
		s.apiJSON(w, http.StatusOK, antwort)
		return
	}

	sess, ok := sessionFrom(r.Context())
	if !ok {
		s.apiFehler(w, http.StatusUnauthorized, "nicht angemeldet")
		return
	}
	antwort.CSRF = sess.CSRFToken
	s.apiJSON(w, http.StatusOK, antwort)
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

// apiUrteil ist der Satz über dem Handlungsbedarf.
type apiUrteil struct {
	Level string `json:"level"` // "ok" | "warn"
	Titel string `json:"titel"`
	Sub   string `json:"sub"`
}

// apiSignal ist ein Punkt des Handlungsbedarfs.
type apiSignal struct {
	Level       string `json:"level"` // "crit" | "warn"
	Tag         string `json:"tag"`
	Titel       string `json:"titel"`
	Detail      string `json:"detail"`
	AktionLabel string `json:"aktion_label"`
	AktionHref  string `json:"aktion_href"`
	Vorrangig   bool   `json:"vorrangig"`
}

// apiSignale ist die Antwort von GET /api/v1/signals.
type apiSignale struct {
	Urteil  apiUrteil   `json:"urteil"`
	Signale []apiSignal `json:"signale"`
}

// handleAPISignals erhebt den Handlungsbedarf.
//
// Eigene Ressource und nicht Teil von overview: Die Erhebung ruft systemctl und
// prüft die Neustartmarkierung, sie kostet also echte Zeit. overview soll billig
// bleiben, damit die Oberfläche es bei jedem Aufbau fragen kann — dieser
// Endpunkt darf länger brauchen, und die Oberfläche zeigt die Kacheln, während
// er noch läuft.
//
// Das Ergebnis wurde einmal zusätzlich in einem Cache abgelegt, aus dem die
// Warnpunkte an der Symbolschiene der alten Oberfläche gespeist wurden. Mit der
// Schiene ist der Cache gegangen (siehe server.go): Dieser Endpunkt ist jetzt
// die einzige Erhebung, und was er sagt, ist von jetzt.
func (s *Server) handleAPISignals(w http.ResponseWriter, r *http.Request) {
	snap, _ := s.lastSnapshot()
	signale := s.dashboardSignals(r.Context(), snap)

	urteil := urteilAus(signale)
	antwort := apiSignale{
		Urteil: apiUrteil{Level: urteil.Level, Titel: urteil.Title, Sub: urteil.Sub},
		// Leeres Feld statt null: „nichts zu tun" ist ein Zustand, den die
		// Oberfläche zeigt, und kein fehlender Wert.
		Signale: make([]apiSignal, 0, len(signale)),
	}
	for _, sig := range signale {
		antwort.Signale = append(antwort.Signale, apiSignal{
			Level:       sig.Level,
			Tag:         sig.Tag,
			Titel:       sig.Title,
			Detail:      sig.Detail,
			AktionLabel: sig.ActionLabel,
			// Der Verweis kommt unverändert aus der Erhebung. Bis zum Abbau der
			// alten Oberfläche stand hier eine Übersetzungstabelle: Die Erhebung
			// baute Pfade der alten Fläche, und diese Schicht rechnete sie in die
			// der neuen um. Es gibt nur noch eine Fläche, und die Erhebung nennt
			// deren Pfade selbst.
			AktionHref: sig.ActionHref,
			Vorrangig:  sig.Primary,
		})
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
