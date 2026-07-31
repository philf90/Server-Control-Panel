package privops

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"gopkg.in/yaml.v3"
)

// Compose-Stacks: schreiben und bedienen.
//
// Schritt 5 aus docs/17-docker.md — der gefährlichste Schritt des Moduls, und
// deshalb der letzte der Grundausstattung. Alles Lesende stand vorher.
//
// **Die Pfadwache besteht aus zwei Sätzen, und beide sind kurz:**
//
//  1. Geschrieben wird nur unter StacksWurzel/<name>/compose.yaml. Der Name
//     wird geprüft (PruefeStackName), zusammengesetzt wird mit filepath.Join,
//     und es gibt keinen Weg, von außen einen Pfad hereinzureichen.
//  2. Überschrieben wird nur, was den Marker trägt. Eine Datei ohne ihn gehört
//     jemand anderem — auch dann, wenn sie an unserem Platz liegt.
//
// **Und der Prüfer läuft davor, nicht danach.** Ein abgelehnter Stack wird
// nicht geschrieben und nicht gestartet. Geprüft wird die gerenderte Fassung
// (composepruef.go), und damit das Rendern nicht selbst zum Leseweg wird, geht
// eine Prüfung der Rohdatei auf Verweise nach draußen voraus.

// stackNameMuster ist strenger als PruefeName, und zwar aus zwei Gründen.
//
// Erstens wird der Name ein Verzeichnisname; zweitens geht er als "-p <name>"
// an docker. Compose selbst verlangt Kleinbuchstaben, Ziffern, Bindestrich und
// Unterstrich und einen Anfang aus Buchstabe oder Ziffer — ein Name, den das
// Panel annimmt und docker ablehnt, wäre ein Stack, den man anlegen, aber nie
// starten kann.
var stackNameMuster = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{0,62}$`)

// PruefeStackName prüft den Namen eines Stacks.
func PruefeStackName(name string) error {
	if err := PruefeName(name); err != nil {
		return err
	}
	if !stackNameMuster.MatchString(name) {
		return fmt.Errorf("%q ist kein zulässiger Stack-Name: erlaubt sind Kleinbuchstaben, "+
			"Ziffern, Bindestrich und Unterstrich, beginnend mit Buchstabe oder Ziffer "+
			"(so verlangt es Compose selbst)", name)
	}
	return nil
}

// StackVerzeichnis nennt den Ort, an dem ein verwalteter Stack liegt oder
// liegen wird.
//
// Für den Aufrufer, der prüfen will, BEVOR es die Datei gibt: Beim Anlegen
// eines Stacks gibt es noch kein Verzeichnis, und ohne es hielte der Prüfer
// jeden Pfad für einen nach draußen. Der Ort kommt aus privops und nicht aus
// dem Aufrufer — zwei Fassungen von „wo liegen Stacks" liefen auseinander.
func StackVerzeichnis(name string) string {
	if PruefeStackName(name) != nil {
		return ""
	}
	return filepath.Join(StacksWurzel, name)
}

// StackAktion ist ein Handgriff an einem Stack.
type StackAktion string

const (
	StackUp      StackAktion = "up"
	StackDown    StackAktion = "down"
	StackPull    StackAktion = "pull"
	StackRestart StackAktion = "restart"
)

// ValidStackAktion hält die Menge klein und benannt — dasselbe Muster wie
// ServiceAction und ContainerAction. Eine Zeichenkette aus der Anfrage wird nie
// zu einem Argument, ohne hier durchzugehen.
func ValidStackAktion(a StackAktion) bool {
	switch a {
	case StackUp, StackDown, StackPull, StackRestart:
		return true
	default:
		return false
	}
}

// StackPruefen rendert einen Stack und prüft das Ergebnis.
//
// panelPort geht durch, damit eine Kollision mit dem Panel-Port auffällt,
// bevor sie den Zugang kostet — privops kennt den Port nicht und soll ihn nicht
// kennen.
func (s *System) StackPruefen(ctx context.Context, name string, panelPort int) (ComposePruefung, error) {
	if err := PruefeStackName(name); err != nil {
		return ComposePruefung{}, err
	}
	inhalt, err := s.StackDatei(ctx, name)
	if err != nil {
		return ComposePruefung{}, err
	}
	return s.pruefeDatei(ctx, inhalt.Datei, inhalt.Text, panelPort), nil
}

// pruefeDatei ist der gemeinsame Weg: Vorprüfung, Rendern, Prüfung.
//
// EINE Funktion für den Editor und für „up" — damit es nicht zwei Auslegungen
// davon gibt, was zulässig ist. Genau das verlangt docs/17-docker.md §4: „Er
// läuft serverseitig vor jedem up, nicht im Formular. Im Editor läuft er
// zusätzlich beim Speichern als Auskunft — dieselbe Funktion."
func (s *System) pruefeDatei(ctx context.Context, pfad, roh string, panelPort int) ComposePruefung {
	wurzel := filepath.Dir(pfad)

	// Vorprüfung auf der ROHDATEI, bevor irgendetwas gerendert wird. Sie ist
	// keine Sicherheitsprüfung des Stacks, sondern der Schutz des Prüfers
	// selbst: „extends: {file: /etc/asylum/config.yaml}" zöge fremden Inhalt in
	// die gerenderte Fassung, und die zeigt das Panel an.
	if befund := verweiseNachDraussen(roh, wurzel); befund != nil {
		return ComposePruefung{
			Geprueft: true, Gerendert: false, OK: false,
			Dienste: []string{}, Befunde: befund,
		}
	}

	res, err := s.run(ctx, Command{
		Name: "docker",
		Args: []string{"compose", "--file", pfad, "config"},
	})
	if err != nil || res.ExitCode != 0 {
		// Ohne Rendern wird trotzdem geprüft — auf der Rohdatei, und das steht
		// dann so in der Antwort. „Nicht geprüft" ist eine Auskunft; „in
		// Ordnung" wäre eine Behauptung.
		p := PruefeComposeText(roh, wurzel, panelPort, false)
		if err != nil {
			p.Meldung = err.Error()
		} else {
			p.Meldung = ersteAusgabezeile(res)
		}
		return p
	}
	return PruefeComposeText(res.Stdout, wurzel, panelPort, true)
}

// verweiseNachDraussen sucht in der Rohdatei nach Verweisen auf fremde Dateien.
//
// „extends: {file: …}" und „env_file:" sind die beiden Wege, über die Compose
// beim Rendern eine andere Datei liest. Beide sind für einen Stack sinnvoll,
// solange die andere Datei im Stack-Verzeichnis liegt — und beide wären sonst
// ein Weg, den Prüfer als Leseprogramm zu benutzen.
func verweiseNachDraussen(roh, wurzel string) []ComposeBefund {
	var datei struct {
		Services map[string]struct {
			Extends struct {
				File string `yaml:"file"`
			} `yaml:"extends"`
			EnvFile yaml.Node `yaml:"env_file"`
		} `yaml:"services"`
	}
	if yaml.Unmarshal([]byte(roh), &datei) != nil {
		// Unlesbares YAML ist hier kein Befund: Das Rendern wird daran ebenso
		// scheitern und die Fehlermeldung mitbringen, die jemand sucht.
		return nil
	}

	var out []ComposeBefund
	pruefe := func(dienst, feld, wert string) {
		if wert == "" {
			return
		}
		ziel := wert
		if !filepath.IsAbs(ziel) {
			ziel = filepath.Join(wurzel, ziel)
		}
		if innerhalb(ziel, wurzel) {
			return
		}
		out = append(out, ComposeBefund{
			Art: BefundAblehnung, Dienst: dienst, Feld: feld, Wert: wert,
			Grund: "Diese Datei liegt außerhalb des Stack-Verzeichnisses. Compose würde sie beim " +
				"Auflösen lesen und ihr Inhalt stünde danach in der Konfiguration, die das Panel " +
				"anzeigt — deshalb nimmt ein Stack nur Dateien aus seinem eigenen Verzeichnis.",
		})
	}
	for dienst, d := range datei.Services {
		pruefe(dienst, "extends.file", d.Extends.File)
		for _, ef := range listeAus(d.EnvFile) {
			pruefe(dienst, "env_file", ef)
		}
	}
	return out
}

// StackSchreiben legt einen Stack an oder ändert ihn.
//
// Die Reihenfolge ist die Entscheidung: erst Name, dann Besitz, dann Prüfung,
// dann Schreiben. Ein abgelehnter Stack landet nie auf der Platte — auch nicht
// kurz, auch nicht als Sicherung.
//
// Geprüft wird gegen die GERENDERTE Fassung, und rendern kann Compose nur eine
// Datei, die es gibt. Deshalb liegt der Text während der Prüfung als temporäre
// Datei IM Stack-Verzeichnis: Dort lösen sich „.env" und relative Pfade genauso
// auf wie später im Betrieb. Sie beginnt mit einem Punkt und trägt eine
// Endung, die Compose nicht von selbst einliest.
func (s *System) StackSchreiben(ctx context.Context, name, text string, panelPort int) (ComposePruefung, error) {
	if err := PruefeStackName(name); err != nil {
		return ComposePruefung{}, err
	}
	if len(text) > maxComposeGroesse {
		return ComposePruefung{}, fmt.Errorf("die Datei ist größer als %d Bytes", maxComposeGroesse)
	}

	verzeichnis := filepath.Join(StacksWurzel, name)
	ziel := filepath.Join(verzeichnis, stackDatei)

	// Besitz prüfen, bevor irgendetwas angelegt wird. Eine vorhandene Datei ohne
	// Marker gehört jemand anderem.
	if _, err := os.Stat(ziel); err == nil {
		if !traegtMarker(ziel) {
			return ComposePruefung{}, fmt.Errorf("%s gehört nicht dem Panel: Die Datei trägt "+
				"keinen Verwaltungsmarker und wird nicht angefasst", ziel)
		}
	} else if !errors.Is(err, os.ErrNotExist) {
		return ComposePruefung{}, fmt.Errorf("%s: %w", ziel, err)
	}

	if err := os.MkdirAll(verzeichnis, 0o750); err != nil {
		return ComposePruefung{}, fmt.Errorf("%s: %w", verzeichnis, err)
	}

	inhalt := mitMarker(text)
	tmp := filepath.Join(verzeichnis, ".compose.asylum.tmp.yaml")
	// 0600: In einer Compose-Datei stehen regelmäßig Passwörter als
	// Umgebungsvariablen. Sie ist eine Konfigurationsdatei des Panels und keine,
	// die andere Benutzer des Servers lesen müssen.
	if err := os.WriteFile(tmp, []byte(inhalt), 0o600); err != nil {
		return ComposePruefung{}, fmt.Errorf("%s: %w", tmp, err)
	}
	defer func() { _ = os.Remove(tmp) }()

	pruefung := s.pruefeDatei(ctx, tmp, inhalt, panelPort)
	if !pruefung.Geprueft {
		return pruefung, errors.New("die Datei ließ sich nicht lesen und wurde deshalb nicht gespeichert")
	}
	if !pruefung.OK {
		return pruefung, nil
	}

	// Sicherung vor dem Tausch. Sie ist nicht der Rückweg für den Betreiber —
	// dafür gibt es den Editor —, sondern der für den Fall, dass zwischen
	// Schreiben und Neustart etwas dazwischenkommt.
	if roh, err := os.ReadFile(ziel); err == nil { //nolint:gosec // Pfad aus geprüftem Namen
		_ = os.WriteFile(ziel+".bak", roh, 0o600)
	}
	// rename(2) ist atomar: Ein Abbruch hinterlässt die alte oder die neue
	// Datei, nie eine halbe. Ein „docker compose up", das genau in diesem
	// Augenblick liest, sieht eine vollständige Datei.
	if err := os.Rename(tmp, ziel); err != nil {
		return pruefung, fmt.Errorf("%s: %w", ziel, err)
	}
	return pruefung, nil
}

// mitMarker setzt den Verwaltungsmarker in die erste Zeile.
//
// Ein schon vorhandener wird nicht verdoppelt: Der Editor zeigt die Datei
// samt Marker, und wer sie unverändert speichert, soll nicht bei jedem Mal eine
// Zeile mehr bekommen. Wer ihn löscht, bekommt ihn zurück — die Datei bleibt
// verwaltet, solange das Panel sie schreibt.
func mitMarker(text string) string {
	text = strings.TrimPrefix(text, "\ufeff")
	if strings.HasPrefix(text, stackMarker) {
		return text
	}
	return stackMarker + "\n" + text
}

// StackLoeschen entfernt einen verwalteten Stack samt Verzeichnis.
//
// Vorher wird er heruntergefahren: Ein gelöschtes Verzeichnis hinterließe sonst
// laufende Container, die zu keinem Projekt mehr gehören — und die findet
// danach niemand mehr über diese Seite.
func (s *System) StackLoeschen(ctx context.Context, name string, stream LineWriter) error {
	if err := PruefeStackName(name); err != nil {
		return err
	}
	verzeichnis := filepath.Join(StacksWurzel, name)
	ziel := filepath.Join(verzeichnis, stackDatei)
	if !traegtMarker(ziel) {
		return fmt.Errorf("%s gehört nicht dem Panel: Die Datei trägt keinen "+
			"Verwaltungsmarker und wird nicht angefasst", ziel)
	}

	// Der Abbau darf scheitern, ohne das Löschen zu verhindern: Wenn Docker das
	// Projekt gar nicht kennt, gibt es nichts abzubauen — und ein Verzeichnis,
	// das deshalb stehen bliebe, wäre die schlechtere Antwort.
	res, err := s.run(ctx, Command{
		Name:    "docker",
		Args:    []string{"compose", "--project-name", name, "--file", ziel, "down", "--remove-orphans"},
		Timeout: longTimeout,
		Stream:  stream,
	})
	if err == nil && res.ExitCode != 0 && stream != nil {
		stream("Hinweis: der Abbau meldete " + ersteAusgabezeile(res) + " — das Verzeichnis wird trotzdem entfernt")
	}

	if err := os.RemoveAll(verzeichnis); err != nil {
		return fmt.Errorf("%s: %w", verzeichnis, err)
	}
	if stream != nil {
		stream("Verzeichnis entfernt: " + verzeichnis)
	}
	return nil
}

// StackAusfuehren fährt einen Stack hoch, herunter, zieht seine Abbilder oder
// startet ihn neu.
//
// Der Prüfer läuft vor „up" und vor „restart" — vor allem, was einen Container
// startet. Nicht vor „down": Etwas anzuhalten, war nie das Problem, und ein
// Stack, den man wegen eines Befundes nicht mehr stoppen könnte, wäre die
// Falle, in die eine zu eifrige Prüfung führt.
//
// Für FREMDE Projekte gilt derselbe Prüfer. Das ist eine bewusste Härte: Ein
// Bestandsprojekt mit „privileged: true" lässt sich über das Panel nicht
// starten, auch wenn es gerade läuft. Der Grund ist, dass die Alternative
// schlimmer wäre — ein Prüfer, der bei fremden Dateien nachgibt, prüft genau
// die nicht, die niemand geschrieben hat, der die Regeln kennt. Was das Panel
// nicht tut, sagt es: Der Befund steht mit Dienst, Feld und Grund da.
func (s *System) StackAusfuehren(ctx context.Context, name string, aktion StackAktion, mitVolumes bool, panelPort int, stream LineWriter) (ComposePruefung, error) {
	if err := PruefeStackName(name); err != nil {
		return ComposePruefung{}, err
	}
	if !ValidStackAktion(aktion) {
		return ComposePruefung{}, fmt.Errorf("unbekannte Stack-Aktion: %q", aktion)
	}

	inhalt, err := s.StackDatei(ctx, name)
	if err != nil {
		return ComposePruefung{}, err
	}

	var pruefung ComposePruefung
	if aktion == StackUp || aktion == StackRestart {
		pruefung = s.pruefeDatei(ctx, inhalt.Datei, inhalt.Text, panelPort)
		if !pruefung.OK {
			return pruefung, nil
		}
	}

	args := []string{"compose", "--project-name", name, "--file", inhalt.Datei}
	switch aktion {
	case StackUp:
		// --remove-orphans: Wer einen Dienst aus der Datei nimmt, will seinen
		// Container los sein. Ohne die Angabe bliebe er stehen und gehörte zu
		// einem Projekt, das ihn nicht mehr kennt.
		args = append(args, "up", "--detach", "--remove-orphans")
	case StackDown:
		args = append(args, "down", "--remove-orphans")
		if mitVolumes {
			args = append(args, "--volumes")
		}
	case StackPull:
		args = append(args, "pull")
	case StackRestart:
		args = append(args, "restart")
	}

	res, err := s.run(ctx, Command{
		Name: "docker", Args: args, Timeout: longTimeout, Stream: stream,
	})
	if err != nil {
		return pruefung, err
	}
	if res.ExitCode != 0 {
		return pruefung, fmt.Errorf("docker compose %s: %s", aktion, ersteAusgabezeile(res))
	}
	return pruefung, nil
}

// ------------------------------------------------------------- Vorlagen ---

// StackVorlage ist ein Gerüst für eine neue compose.yaml.
type StackVorlage struct {
	Kennung      string `json:"kennung"`
	Titel        string `json:"titel"`
	Beschreibung string `json:"beschreibung"`
	Text         string `json:"text"`
}

// StackVorlagen liefert die Gerüste.
//
// Ein Gerüst und kein Katalog (Entscheidung E7 in docs/17-docker.md): drei
// kommentierte Beispiele im Binary. Ein gepflegter Katalog fertiger Anwendungen
// wäre ein Inhaltsprojekt mit eigener Vertrauens- und Pflegefrage — wer heute
// „Nextcloud" aus einem Panel klickt, bekommt morgen eine Fassung, die niemand
// mehr ansieht.
//
// Die Kommentare in den Vorlagen sind der eigentliche Inhalt: Sie sagen, warum
// eine Zeile so dasteht, und nicht bloß, dass sie dasteht.
func StackVorlagen() []StackVorlage {
	return []StackVorlage{
		{
			Kennung: "leer",
			Titel:   "Leeres Gerüst",
			Beschreibung: "Ein einzelner Dienst mit einem benannten Volume. Der Ausgangspunkt, " +
				"wenn schon feststeht, was hineinkommt.",
			Text: `services:
  app:
    image: nginx:alpine
    restart: unless-stopped
    volumes:
      # Ein BENANNTES Volume: Docker verwaltet den Ort. Ein Wirtspfad wäre
      # ebenso möglich, verlangt aber eine Rückfrage — er reicht Daten des
      # Servers in den Container.
      - app_daten:/usr/share/nginx/html

volumes:
  app_daten:
`,
		},
		{
			Kennung:      "port",
			Titel:        "Dienst hinter einem Port",
			Beschreibung: "Ein Dienst, der von außen erreichbar sein soll — mit dem Hinweis zur Firewall.",
			Text: `services:
  app:
    image: nginx:alpine
    restart: unless-stopped
    ports:
      # Links der Port auf dem Server, rechts der im Container. Auf
      # 127.0.0.1 gebunden ist er nur lokal erreichbar — das ist die
      # richtige Wahl, wenn ein Reverse-Proxy davor steht.
      - "127.0.0.1:8080:80"
    healthcheck:
      # Ohne Prüfung steht der Container auf „läuft", auch wenn der Dienst
      # darin längst nicht mehr antwortet.
      test: ["CMD", "wget", "--spider", "-q", "http://localhost/"]
      interval: 30s
      timeout: 5s
      retries: 3
`,
		},
		{
			Kennung:      "abhaengig",
			Titel:        "Anwendung mit Datenbank",
			Beschreibung: "Zwei Dienste mit depends_on und einem Volume für die Daten.",
			Text: `services:
  app:
    image: nginx:alpine
    restart: unless-stopped
    depends_on:
      db:
        # Nicht bloß „gestartet", sondern „gesund": depends_on ohne condition
        # wartet nur darauf, dass der Prozess läuft — nicht darauf, dass die
        # Datenbank Verbindungen annimmt.
        condition: service_healthy

  db:
    image: postgres:16-alpine
    restart: unless-stopped
    environment:
      POSTGRES_PASSWORD: bitte-aendern
    volumes:
      - db_daten:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U postgres"]
      interval: 10s
      timeout: 5s
      retries: 5

volumes:
  db_daten:
`,
		},
	}
}
