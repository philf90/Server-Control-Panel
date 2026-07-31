package httpd

// Compose-Stacks über /api/v1 — lesend.
//
// Schritt 4 aus docs/17-docker.md. Der Stack ist das führende Objekt dieses
// Moduls (docs/16-neukonzeption.md §5), und dieser Schritt zeigt ihn, ohne ihn
// anzufassen: Liste, Datei, zugehörige Container. Anlegen, Ändern und Starten
// kommen mit dem nächsten Schritt, zusammen mit dem Compose-Prüfer.
//
// Zwei Dinge, die das Verhalten dieser Datei bestimmen:
//
//  1. **Kein Pfad kommt aus der Anfrage.** Die Oberfläche nennt einen NAMEN; wo
//     dessen Datei liegt, sagt Docker oder das verwaltete Verzeichnis. Die
//     Prüfung dazu steht in privops (compose.go) und nicht hier — damit sie auch
//     dann gilt, wenn dieser Endpunkt einmal einen zweiten Aufrufer bekommt.
//  2. **Dienste kommen aus den Containern, nicht aus der Datei.** Ein YAML zu
//     zerlegen, um Dienstnamen zu zeigen, hieße einen zweiten Compose-Parser
//     neben dem Prüfer zu halten — und der käme mit Schritt 5. Was läuft, sagen
//     die Compose-Labels an den Containern; was nicht läuft, steht in der Datei,
//     die im Inspektor ohnehin danebensteht.

import (
	"net/http"
	"sort"

	"github.com/philf90/asylum/internal/privops"
)

// apiStack ist eine Zeile der Stackliste.
type apiStack struct {
	Name string `json:"name"`
	// Verwaltet heißt: Die Datei liegt unter /opt/asylum/stacks und trägt den
	// Marker des Panels. Nur solche Stacks wird das Panel je schreiben.
	Verwaltet bool   `json:"verwaltet"`
	Datei     string `json:"datei"`
	Status    string `json:"status"`
	Laufend   int    `json:"laufend"`
	Gesamt    int    `json:"gesamt"`
	// Gestartet heißt: Docker kennt das Projekt. Ein verwalteter Stack, der noch
	// nie lief, ist ein Verzeichnis mit einer Datei — ein Zustand, kein Fehler.
	Gestartet bool `json:"gestartet"`
	// ZustandStufe und Auffaellig rechnet der Server. Was hier entschieden ist,
	// entscheidet der Browser nicht noch einmal anders.
	ZustandStufe string `json:"zustand_stufe"`
	Auffaellig   bool   `json:"auffaellig"`
	// Dienste sind die Namen aus den Compose-Labels der laufenden Container,
	// alphabetisch. Bei einem nie gestarteten Stack ist die Liste leer.
	Dienste []string `json:"dienste"`
}

// apiStackZaehler steht über der Liste.
//
// Verwaltet und Fremd stehen getrennt, weil der Unterschied das ganze Modul
// prägt: Am fremden Projekt lässt sich nichts schreiben, und wer die Seite
// öffnet, soll die Aufteilung sehen, bevor er auf einen fehlenden Knopf trifft.
type apiStackZaehler struct {
	Alle       int `json:"alle"`
	Verwaltet  int `json:"verwaltet"`
	Fremd      int `json:"fremd"`
	Auffaellig int `json:"auffaellig"`
}

// apiStackListe ist die Antwort von GET /api/v1/docker/stacks.
type apiStackListe struct {
	Zeilen  []apiStack      `json:"zeilen"`
	Zaehler apiStackZaehler `json:"zaehler"`
	// DarfAendern: Owner-Rolle. Der Wert ist in diesem Schritt noch ohne Wirkung
	// — er steht schon da, damit die Oberfläche ihn nicht später nachrüsten muss.
	DarfAendern bool   `json:"darf_aendern"`
	Fehler      string `json:"fehler,omitempty"`
}

// apiStackDetail ist die Antwort von GET /api/v1/docker/stacks/{name}.
//
// Die Zeile selbst ist eingebettet: Der Inspektor zeigt dieselben Angaben wie
// die Liste und dazu die Datei und die Container. Sie zweimal zu tippen wäre die
// Stelle, an der Liste und Inspektor auseinanderlaufen.
type apiStackDetail struct {
	apiStack
	// Text ist die Compose-Datei, Gekuerzt sagt, ob sie vollständig dasteht.
	// Eine halbe Datei, die wie eine ganze aussieht, ist die schlechteste
	// Auskunft.
	Text     string `json:"text"`
	Gekuerzt bool   `json:"gekuerzt"`
	// Container sind die Container dieses Stacks, in derselben Form wie in der
	// Containerliste — damit der Inspektor dieselbe Tabelle benutzen kann.
	Container []apiContainer `json:"container"`
	// Fehler steht als Feld: Ist die Datei unlesbar, gilt der Rest der Auskunft
	// weiter. Auf einem fremden Projekt ist genau das der Normalfall — die Datei
	// kann in einem Verzeichnis liegen, das es längst nicht mehr gibt.
	Fehler string `json:"fehler,omitempty"`
}

// stackStufe entscheidet Farbe und Auffälligkeit.
//
// Der auffällige Fall ist der HALBE Stack: Ein Projekt, von dem zwei von drei
// Diensten laufen, ist kaputt und sieht aus wie „läuft". Ein ganz gestoppter
// Stack dagegen ist meistens Absicht — wer ihn heruntergefahren hat, weiß das,
// und ein Ausrufezeichen dafür wäre Lärm.
func stackStufe(st privops.Stack) (stufe string, auffaellig bool) {
	switch {
	case !st.Gestartet:
		return "info", false
	case st.Gesamt == 0:
		return "info", false
	case st.Laufend == st.Gesamt:
		return "gut", false
	case st.Laufend == 0:
		return "info", false
	default:
		return "warn", true
	}
}

// stackAus baut die Listenzeile.
func stackAus(st privops.Stack, dienste []string) apiStack {
	z := apiStack{
		Name:      st.Name,
		Verwaltet: st.Verwaltet,
		Datei:     st.Datei,
		Status:    st.Status,
		Laufend:   st.Laufend,
		Gesamt:    st.Gesamt,
		Gestartet: st.Gestartet,
		Dienste:   dienste,
	}
	if z.Dienste == nil {
		z.Dienste = []string{}
	}
	z.ZustandStufe, z.Auffaellig = stackStufe(st)
	return z
}

// dienstenachStack sammelt die Dienstnamen je Stack aus den Containern.
//
// Ein Aufruf von "docker ps" für die ganze Liste, nicht einer je Stack: Die
// Angabe steht als Label am Container, und die Liste liegt nach einem Kommando
// vollständig vor.
func dienstenachStack(cs []privops.Container) map[string][]string {
	gesehen := map[string]map[string]bool{}
	for _, c := range cs {
		if c.Stack == "" || c.Dienst == "" {
			continue
		}
		if gesehen[c.Stack] == nil {
			gesehen[c.Stack] = map[string]bool{}
		}
		gesehen[c.Stack][c.Dienst] = true
	}
	out := map[string][]string{}
	for stack, dienste := range gesehen {
		namen := make([]string, 0, len(dienste))
		for d := range dienste {
			namen = append(namen, d)
		}
		sort.Strings(namen)
		out[stack] = namen
	}
	return out
}

// handleAPIDockerStacks listet die Compose-Projekte.
//
// Vollständig und ungefiltert, wie die Containerliste: Gefiltert wird im
// Browser. Ein Server hat selten mehr als eine Handvoll Stacks, und was der
// Server rechnet — Stufe, Zähler, Reihenfolge —, rechnet der Browser nicht nach.
func (s *Server) handleAPIDockerStacks(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	antwort := apiStackListe{
		Zeilen:      []apiStack{},
		DarfAendern: user.CanManageUsers(),
	}

	liste, err := s.ops.StackList(r.Context())
	if err != nil {
		antwort.Fehler = err.Error()
		s.apiJSON(w, http.StatusOK, antwort)
		return
	}

	// Die Container dürfen fehlen, ohne die Liste zu kippen: Dann stehen die
	// Stacks ohne ihre Dienstnamen da, und das ist immer noch die Auskunft, die
	// jemand sucht.
	dienste := map[string][]string{}
	if cs, err := s.ops.DockerContainers(r.Context()); err == nil {
		dienste = dienstenachStack(cs)
	}

	for _, st := range liste {
		z := stackAus(st, dienste[st.Name])
		antwort.Zeilen = append(antwort.Zeilen, z)
		antwort.Zaehler.Alle++
		if z.Verwaltet {
			antwort.Zaehler.Verwaltet++
		} else {
			antwort.Zaehler.Fremd++
		}
		if z.Auffaellig {
			antwort.Zaehler.Auffaellig++
		}
	}

	// Auffälliges zuerst, dann Verwaltetes, dann alphabetisch. Die Reihenfolge
	// aus privops bleibt darunter erhalten (SliceStable) — sie sortiert schon
	// verwaltet vor fremd.
	sort.SliceStable(antwort.Zeilen, func(i, j int) bool {
		a, b := antwort.Zeilen[i], antwort.Zeilen[j]
		if a.Auffaellig != b.Auffaellig {
			return a.Auffaellig
		}
		return false
	})

	s.apiJSON(w, http.StatusOK, antwort)
}

// handleAPIDockerStack liefert einen Stack mit Datei und Containern.
//
// Der Name kommt aus dem Pfad und wird in privops gegen die Liste gehalten —
// ein Name, den die Liste nicht kennt, führt nirgendwohin. Ein unbekannter Stack
// ist deshalb 404 und nicht 502: Er ist keine gescheiterte Auskunft, sondern
// eine Anfrage nach etwas, das es nicht gibt.
func (s *Server) handleAPIDockerStack(w http.ResponseWriter, r *http.Request) {
	name := r.PathValue("name")

	liste, err := s.ops.StackList(r.Context())
	if err != nil {
		s.apiFehler(w, http.StatusBadGateway, err.Error())
		return
	}
	var gefunden *privops.Stack
	for i := range liste {
		if liste[i].Name == name {
			gefunden = &liste[i]
			break
		}
	}
	if gefunden == nil {
		s.apiFehler(w, http.StatusNotFound, "kein Stack mit dem Namen "+name)
		return
	}

	antwort := apiStackDetail{Container: []apiContainer{}}

	var dienste []string
	if cs, err := s.ops.DockerContainers(r.Context()); err == nil {
		for _, c := range cs {
			if c.Stack != name {
				continue
			}
			antwort.Container = append(antwort.Container, containerAus(c))
		}
		dienste = dienstenachStack(cs)[name]
		// Innerhalb des Stacks nach Dienstnamen: Der Inspektor zeigt ein
		// Projekt, und dort ist die Reihenfolge der Dienste die verständliche —
		// nicht die nach Auffälligkeit wie in der großen Liste.
		sort.SliceStable(antwort.Container, func(i, j int) bool {
			a, b := antwort.Container[i], antwort.Container[j]
			if a.Dienst != b.Dienst {
				return a.Dienst < b.Dienst
			}
			return a.Name < b.Name
		})
	}
	antwort.apiStack = stackAus(*gefunden, dienste)

	// Die Datei ist die zweite Auskunft und darf fehlen. Bei einem fremden
	// Projekt zeigt Docker gelegentlich auf ein Verzeichnis, das es nicht mehr
	// gibt — dann steht der Stack trotzdem da, mit seinen Containern.
	if inhalt, err := s.ops.StackDatei(r.Context(), name); err == nil {
		antwort.Text = inhalt.Text
		antwort.Gekuerzt = inhalt.Gekuerzt
	} else {
		antwort.Fehler = err.Error()
	}

	s.apiJSON(w, http.StatusOK, antwort)
}
