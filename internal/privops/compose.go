package privops

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
)

// Compose-Stacks: lesen.
//
// Ein Stack ist das führende Objekt dieses Moduls (docs/16-neukonzeption.md §5).
// Dieser Schritt liest sie nur — Anlegen, Ändern und Starten kommen mit dem
// nächsten, zusammen mit dem Compose-Prüfer.
//
// Zwei Quellen, eine Liste:
//
//  1. **Verwaltete Stacks** liegen unter /opt/asylum/stacks/<name>/compose.yaml
//     und tragen einen Marker in der ersten Zeile. Sie gehören dem Panel.
//  2. **Fremde Projekte** kennt Docker selbst („docker compose ls"). Sie werden
//     gelesen und angezeigt, aber nie geschrieben — dieselbe Trennung wie bei
//     nftables und bei fremden Crontabs.
//
// Der Marker und nicht der Ort entscheidet über „verwaltet": Wer von Hand ein
// Verzeichnis unter /opt/asylum/stacks/ anlegt, hat es damit nicht dem Panel
// überschrieben. Dasselbe Muster wie bei Cron (cron.go).
//
// **Kein Pfad kommt je aus der Anfrage.** Die Oberfläche nennt einen
// Stack-NAMEN; wo seine Datei liegt, sagt entweder Docker oder das verwaltete
// Verzeichnis. Damit gibt es keinen Weg, über diesen Endpunkt eine beliebige
// Datei des Servers zu lesen — die Frage stellt sich gar nicht erst.

// StacksWurzel ist das Verzeichnis der verwalteten Stacks.
//
// Eine Variable und keine Konstante, damit Tests in ein Wegwerfverzeichnis
// zeigen können. Sie wird im Betrieb nirgends gesetzt — der Ort ist festgelegt
// (docs/16-neukonzeption.md §5) und soll nicht über die Konfiguration wandern:
// Ein verschiebbarer Schreibbereich wäre eine Einstellung, deren Folgen niemand
// überblickt.
var StacksWurzel = "/opt/asylum/stacks"

const (
	// stackDatei ist der Name der Compose-Datei in einem verwalteten Stack.
	stackDatei = "compose.yaml"
	// stackMarker steht in der ersten Zeile jeder vom Panel geschriebenen
	// Compose-Datei. Er ist die einzige Auskunft darüber, ob eine Datei dem
	// Panel gehört — der Ort allein genügt nicht.
	stackMarker = "# Vom Panel verwaltet — Modul Docker."
	// stackSicherung ist der Name der Sicherung neben der compose.yaml.
	stackSicherung = "compose.yaml.bak"
	// maxComposeGroesse begrenzt, was gelesen wird. Eine Compose-Datei ist
	// selten größer als ein paar Kilobyte; alles darüber ist keine, und sie in
	// den Speicher zu ziehen wäre der falsche Umgang damit.
	maxComposeGroesse = 256 << 10
)

// Stack ist ein Compose-Projekt.
type Stack struct {
	Name string `json:"name"`
	// Verwaltet heißt: Die Datei trägt den Marker des Panels und darf
	// geschrieben werden. Alles andere ist Auskunft.
	Verwaltet bool `json:"verwaltet"`
	// Datei ist die Compose-Datei. Bei mehreren nennt Docker sie durch Komma
	// getrennt; genommen wird die erste — sie ist die, die zählt.
	Datei string `json:"datei"`
	// Status ist Dockers Wort dazu ("running(2)", "exited(1)"). Roh übernommen.
	Status string `json:"status"`
	// Laufend und Gesamt kommen aus dem Status. Sie stehen getrennt, weil „2 von
	// 3 laufen" ein anderer Zustand ist als „3 von 3" und als „0 von 3" — und
	// der mittlere ist der, den man sucht.
	Laufend int `json:"laufend"`
	Gesamt  int `json:"gesamt"`
	// Gestartet heißt: Docker kennt das Projekt. Ein verwalteter Stack, der noch
	// nie lief, ist ein Verzeichnis mit einer Datei und sonst nichts — und das
	// ist ein Zustand, kein Fehler.
	Gestartet bool `json:"gestartet"`
}

// StackInhalt ist eine Compose-Datei mit ihrer Herkunft.
type StackInhalt struct {
	Name      string `json:"name"`
	Datei     string `json:"datei"`
	Verwaltet bool   `json:"verwaltet"`
	Text      string `json:"text"`
	// Gekuerzt heißt: Die Datei ist größer als die Grenze und steht nur zum
	// Teil da. Das gehört gesagt — eine halbe Datei, die wie eine ganze
	// aussieht, ist die schlechteste Auskunft.
	Gekuerzt bool `json:"gekuerzt"`
}

// StackList liest alle Compose-Projekte, verwaltete wie fremde.
//
// Ein Fehler an einer Quelle beendet die Auskunft nicht: Ohne Compose gibt es
// keine Projekte von Docker, aber vielleicht Verzeichnisse — und umgekehrt.
func (s *System) StackList(ctx context.Context) ([]Stack, error) {
	nachName := map[string]*Stack{}

	// Was Docker kennt.
	res, err := s.run(ctx, Command{
		Name: "docker",
		Args: []string{"compose", "ls", "--all", "--format", "json"},
	})
	if err == nil && res.ExitCode == 0 {
		for _, p := range parseComposeLS(res.Stdout) {
			kopie := p
			nachName[p.Name] = &kopie
		}
	}

	// Was im verwalteten Verzeichnis liegt. Auch ein Stack, den Docker nicht
	// kennt, gehört in die Liste — er wurde angelegt und nie gestartet.
	eigene, err := eigeneStacks()
	if err != nil {
		return nil, err
	}
	for name, datei := range eigene {
		if vorhanden, ok := nachName[name]; ok {
			vorhanden.Verwaltet = true
			if vorhanden.Datei == "" {
				vorhanden.Datei = datei
			}
			continue
		}
		nachName[name] = &Stack{Name: name, Verwaltet: true, Datei: datei, Status: "nicht gestartet"}
	}

	// Ein Projekt, das Docker kennt und dessen Datei im verwalteten Verzeichnis
	// liegt, ohne den Marker zu tragen, ist NICHT verwaltet. Der Ort allein
	// genügt nicht — sonst gehörte dem Panel jedes Verzeichnis, das jemand dort
	// anlegt.
	out := make([]Stack, 0, len(nachName))
	for _, p := range nachName {
		out = append(out, *p)
	}
	sort.Slice(out, func(i, j int) bool {
		// Verwaltete zuerst, dann alphabetisch: Was das Panel selbst angelegt
		// hat, ist das, wonach jemand hier sucht.
		if out[i].Verwaltet != out[j].Verwaltet {
			return out[i].Verwaltet
		}
		return out[i].Name < out[j].Name
	})
	return out, nil
}

// StackDatei liest die Compose-Datei eines Stacks.
//
// Der Weg über StackList ist Absicht und keine Bequemlichkeit: Die Anfrage
// nennt einen NAMEN, und wo dessen Datei liegt, sagt Docker oder das verwaltete
// Verzeichnis. Käme der Pfad aus der Anfrage, wäre dieser Endpunkt ein Weg,
// jede Datei des Servers zu lesen — und die Pfadwache des Dateimanagers stünde
// daneben, ohne dass ihn jemand fragt.
func (s *System) StackDatei(ctx context.Context, name string) (StackInhalt, error) {
	if err := PruefeName(name); err != nil {
		return StackInhalt{}, err
	}

	liste, err := s.StackList(ctx)
	if err != nil {
		return StackInhalt{}, err
	}
	var stack *Stack
	for i := range liste {
		if liste[i].Name == name {
			stack = &liste[i]
			break
		}
	}
	if stack == nil {
		return StackInhalt{}, fmt.Errorf("kein Stack mit dem Namen %q", name)
	}
	if stack.Datei == "" {
		return StackInhalt{}, fmt.Errorf("zu %q ist keine Compose-Datei bekannt", name)
	}
	// Bei einem FREMDEN Projekt sagt Docker, wo die Datei liegt — und das ist
	// eine Angabe, die das Panel nicht gesetzt hat. Zeigt sie auf /etc/shadow,
	// läse dieser Endpunkt /etc/shadow und zeigte es jedem angemeldeten Konto,
	// auch einem mit reinem Leserecht. Genau das ist im Angriffsdurchgang
	// (Schritt 9) passiert.
	//
	// Gelesen wird deshalb nur, was auch eine Compose-Datei sein KANN. Die
	// Einschränkung kostet nichts — Compose-Dateien heißen .yaml oder .yml —
	// und nimmt diesem Endpunkt die Eigenschaft, ein allgemeines Leseprogramm
	// zu sein.
	if !istComposeName(stack.Datei) {
		return StackInhalt{}, fmt.Errorf("%s sieht nicht wie eine Compose-Datei aus und "+
			"wird nicht gelesen", stack.Datei)
	}

	text, gekuerzt, err := composeLesen(stack.Datei)
	if err != nil {
		return StackInhalt{}, err
	}
	return StackInhalt{
		Name: name, Datei: stack.Datei, Verwaltet: stack.Verwaltet,
		Text: text, Gekuerzt: gekuerzt,
	}, nil
}

// istComposeName sagt, ob ein Pfad eine Compose-Datei sein kann.
//
// Nur die Endung, und das genügt: Compose selbst liest ausschließlich YAML, und
// jede Datei, die Docker als ConfigFiles nennt, hat deshalb eine dieser
// Endungen. Was sie nicht hat, ist keine — und wird nicht angezeigt.
func istComposeName(pfad string) bool {
	nach := strings.ToLower(filepath.Ext(pfad))
	return nach == ".yaml" || nach == ".yml"
}

// eigeneStacks sammelt die verwalteten Stacks aus dem Verzeichnis.
//
// Verwaltet ist, was den Marker trägt. Ein Verzeichnis ohne compose.yaml oder
// mit einer fremden Datei darin taucht nicht auf — es gehört dem Panel nicht,
// auch wenn es an seinem Platz liegt.
func eigeneStacks() (map[string]string, error) {
	out := map[string]string{}
	eintraege, err := os.ReadDir(StacksWurzel)
	if err != nil {
		// Das Verzeichnis gibt es erst, wenn der erste Stack angelegt wird.
		// Sein Fehlen ist der Normalfall und kein Fehler.
		if errors.Is(err, fs.ErrNotExist) {
			return out, nil
		}
		return nil, fmt.Errorf("%s: %w", StacksWurzel, err)
	}
	for _, e := range eintraege {
		if !e.IsDir() || PruefeName(e.Name()) != nil {
			continue
		}
		pfad := filepath.Join(StacksWurzel, e.Name(), stackDatei)
		if !traegtMarker(pfad) {
			continue
		}
		out[e.Name()] = pfad
	}
	return out, nil
}

// traegtMarker prüft die erste Zeile einer Datei auf den Marker des Panels.
func traegtMarker(pfad string) bool {
	f, err := os.Open(pfad) //nolint:gosec // Pfad aus dem verwalteten Verzeichnis, nicht aus der Anfrage
	if err != nil {
		return false
	}
	defer func() { _ = f.Close() }()

	// Nur den Anfang lesen: Der Marker steht in der ersten Zeile, und eine
	// große Datei dafür ganz einzulesen wäre Verschwendung.
	kopf := make([]byte, len(stackMarker)+2)
	n, _ := f.Read(kopf)
	return strings.HasPrefix(string(kopf[:n]), stackMarker)
}

// composeLesen liest eine Compose-Datei mit Obergrenze.
func composeLesen(pfad string) (string, bool, error) {
	f, err := os.Open(pfad) //nolint:gosec // Pfad aus StackList, nicht aus der Anfrage
	if err != nil {
		return "", false, fmt.Errorf("%s: %w", pfad, err)
	}
	defer func() { _ = f.Close() }()

	info, err := f.Stat()
	if err != nil {
		return "", false, err
	}
	// Nur reguläre Dateien: Ein open() auf eine FIFO blockiert unbegrenzt, und
	// ein Gerät liefert unendlich viel. Dieselbe Regel wie im Dateimanager.
	if !info.Mode().IsRegular() {
		return "", false, fmt.Errorf("%s ist keine gewöhnliche Datei", pfad)
	}

	puffer := make([]byte, maxComposeGroesse)
	n, err := f.Read(puffer)
	if err != nil && n == 0 {
		return "", false, fmt.Errorf("%s: %w", pfad, err)
	}
	return string(puffer[:n]), info.Size() > int64(n), nil
}

// ------------------------------------------------------------------ Parser ---

// parseComposeLS liest "docker compose ls --all --format json".
//
// Anders als bei "docker ps" ist die Ausgabe ein FELD und keine Zeilenfolge:
//
//	[{"Name":"web","Status":"running(2)","ConfigFiles":"/opt/asylum/stacks/web/compose.yaml"},
//	 {"Name":"alt","Status":"exited(1)","ConfigFiles":"/srv/alt/docker-compose.yml"}]
//
// Der Parser nimmt trotzdem beide Formen an. Der Grund ist nicht Beliebigkeit,
// sondern die Erfahrung aus den anderen Unterkommandos: Docker hat das Format
// je Unterkommando anders gewählt, und ein Parser, der an einem Zeilenumbruch
// scheitert, kostet mehr als die zehn Zeilen, die ihn nachsichtig machen.
func parseComposeLS(out string) []Stack {
	out = strings.TrimSpace(out)
	if out == "" {
		return nil
	}

	var roh []composeProjekt
	if strings.HasPrefix(out, "[") {
		if json.Unmarshal([]byte(out), &roh) != nil {
			return nil
		}
	} else {
		for _, zeile := range strings.Split(out, "\n") {
			zeile = strings.TrimSpace(zeile)
			if zeile == "" || !strings.HasPrefix(zeile, "{") {
				continue
			}
			var p composeProjekt
			if json.Unmarshal([]byte(zeile), &p) == nil {
				roh = append(roh, p)
			}
		}
	}

	stacks := make([]Stack, 0, len(roh))
	for _, p := range roh {
		if p.Name == "" {
			continue
		}
		laufend, gesamt := statusZahlen(p.Status)
		stacks = append(stacks, Stack{
			Name:      p.Name,
			Datei:     ersteDatei(p.ConfigFiles),
			Status:    p.Status,
			Laufend:   laufend,
			Gesamt:    gesamt,
			Gestartet: true,
		})
	}
	return stacks
}

type composeProjekt struct {
	Name        string `json:"Name"`
	Status      string `json:"Status"`
	ConfigFiles string `json:"ConfigFiles"`
}

// ersteDatei nimmt die erste von mehreren Compose-Dateien.
//
// Ein Projekt kann aus mehreren zusammengesetzt sein (compose.yaml plus
// override); Docker nennt sie durch Komma getrennt. Für die Anzeige zählt die
// erste — sie ist die, in der der Stack beschrieben ist.
func ersteDatei(s string) string {
	if i := strings.IndexByte(s, ','); i >= 0 {
		return strings.TrimSpace(s[:i])
	}
	return strings.TrimSpace(s)
}

// statusZahlen liest "running(2)" oder "running(1), exited(2)".
//
// Gezählt wird, wie viele Dienste laufen und wie viele es insgesamt gibt. Die
// Unterscheidung ist der Grund, warum die Zahlen überhaupt getrennt stehen:
// „2 von 3" ist der Zustand, den man sucht — ein Stack, der halb oben ist.
func statusZahlen(status string) (laufend, gesamt int) {
	for _, teil := range strings.Split(status, ",") {
		teil = strings.TrimSpace(teil)
		auf := strings.IndexByte(teil, '(')
		zu := strings.IndexByte(teil, ')')
		if auf < 0 || zu < auf {
			continue
		}
		n, err := strconv.Atoi(teil[auf+1 : zu])
		if err != nil {
			continue
		}
		gesamt += n
		if strings.HasPrefix(teil, "running") {
			laufend += n
		}
	}
	return laufend, gesamt
}
