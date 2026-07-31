package privops

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Aufgezeichnete Ausgabe. Anders als bei "docker ps" ist sie ein FELD.
const composeLSOut = `[{"Name":"web","Status":"running(2)","ConfigFiles":"/opt/asylum/stacks/web/compose.yaml"},` +
	`{"Name":"alt","Status":"exited(1)","ConfigFiles":"/srv/alt/docker-compose.yml,/srv/alt/override.yml"},` +
	`{"Name":"halb","Status":"running(1), exited(2)","ConfigFiles":"/srv/halb/compose.yml"}]`

// stacksIn legt ein Wegwerfverzeichnis als verwaltete Wurzel an.
func stacksIn(t *testing.T) string {
	t.Helper()
	wurzel := t.TempDir()
	alt := StacksWurzel
	StacksWurzel = wurzel
	t.Cleanup(func() { StacksWurzel = alt })
	return wurzel
}

// eigenerStack legt einen verwalteten Stack an. mitMarker entscheidet, ob er
// dem Panel gehört — der Marker und nicht der Ort ist die Auskunft.
func eigenerStack(t *testing.T, wurzel, name, inhalt string, mitMarker bool) {
	t.Helper()
	dir := filepath.Join(wurzel, name)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	text := inhalt
	if mitMarker {
		text = stackMarker + "\n" + inhalt
	}
	if err := os.WriteFile(filepath.Join(dir, stackDatei), []byte(text), 0o644); err != nil {
		t.Fatal(err)
	}
}

func TestParseComposeLS(t *testing.T) {
	stacks := parseComposeLS(composeLSOut)
	if len(stacks) != 3 {
		t.Fatalf("erwartet 3 Projekte, gelesen %d", len(stacks))
	}
	if stacks[0].Name != "web" || stacks[0].Laufend != 2 || stacks[0].Gesamt != 2 {
		t.Errorf("erstes Projekt falsch gelesen: %+v", stacks[0])
	}
	// Mehrere Compose-Dateien: die erste zählt.
	if stacks[1].Datei != "/srv/alt/docker-compose.yml" {
		t.Errorf("Datei = %q, erwartet die erste von zweien", stacks[1].Datei)
	}
	// Der Zustand, den man sucht: halb oben.
	if stacks[2].Laufend != 1 || stacks[2].Gesamt != 3 {
		t.Errorf("„running(1), exited(2)"+`"`+" falsch gezählt: %+v", stacks[2])
	}
	for _, s := range stacks {
		if !s.Gestartet {
			t.Errorf("%s: was Docker kennt, ist gestartet", s.Name)
		}
	}
}

// Der Parser nimmt beide Formen an. Der Grund ist nicht Beliebigkeit, sondern
// die Erfahrung aus den anderen Unterkommandos: Docker hat das Format je
// Unterkommando anders gewählt.
func TestParseComposeLSNimmtAuchZeilen(t *testing.T) {
	ndjson := `{"Name":"web","Status":"running(2)","ConfigFiles":"/srv/web/compose.yml"}
{"Name":"alt","Status":"exited(1)","ConfigFiles":"/srv/alt/compose.yml"}`
	if got := parseComposeLS(ndjson); len(got) != 2 {
		t.Errorf("erwartet 2 Projekte, gelesen %d", len(got))
	}
	if got := parseComposeLS("kein JSON"); len(got) != 0 {
		t.Errorf("unlesbare Ausgabe sollte nichts ergeben, ergab %+v", got)
	}
}

// Der Marker entscheidet über „verwaltet", nicht der Ort. Wer von Hand ein
// Verzeichnis unter der Wurzel anlegt, hat es damit nicht dem Panel überschrieben.
func TestStackListMarkerEntscheidet(t *testing.T) {
	wurzel := stacksIn(t)
	eigenerStack(t, wurzel, "meiner", "services:\n  web:\n    image: nginx\n", true)
	eigenerStack(t, wurzel, "fremder", "services:\n  db:\n    image: postgres\n", false)

	f := newFakeRunner()
	f.responses["docker compose ls"] = Result{Stdout: "[]"}
	s := NewSystemWithRunner(f)

	liste, err := s.StackList(context.Background())
	if err != nil {
		t.Fatalf("StackList: %v", err)
	}
	if len(liste) != 1 {
		t.Fatalf("erwartet 1 Stack, gelesen %d: %+v", len(liste), liste)
	}
	if liste[0].Name != "meiner" || !liste[0].Verwaltet {
		t.Errorf("falscher Stack erkannt: %+v", liste[0])
	}
	// Ein Stack, den Docker nicht kennt, ist angelegt und nie gestartet — ein
	// Zustand und kein Fehler.
	if liste[0].Gestartet {
		t.Error("ein Stack, den Docker nicht kennt, ist nicht gestartet")
	}
}

// Die beiden Quellen werden verschmolzen: Was Docker kennt UND unter der Wurzel
// liegt, ist ein Eintrag und nicht zwei.
func TestStackListVerschmilztQuellen(t *testing.T) {
	wurzel := stacksIn(t)
	eigenerStack(t, wurzel, "web", "services:\n  web:\n    image: nginx\n", true)

	f := newFakeRunner()
	f.responses["docker compose ls"] = Result{
		Stdout: `[{"Name":"web","Status":"running(2)","ConfigFiles":"` +
			filepath.Join(wurzel, "web", stackDatei) + `"},` +
			`{"Name":"fremd","Status":"running(1)","ConfigFiles":"/srv/fremd/compose.yml"}]`,
	}
	s := NewSystemWithRunner(f)

	liste, err := s.StackList(context.Background())
	if err != nil {
		t.Fatalf("StackList: %v", err)
	}
	if len(liste) != 2 {
		t.Fatalf("erwartet 2 Stacks, gelesen %d: %+v", len(liste), liste)
	}
	// Verwaltete zuerst: Was das Panel angelegt hat, ist das, wonach jemand hier
	// sucht.
	if liste[0].Name != "web" || !liste[0].Verwaltet || !liste[0].Gestartet {
		t.Errorf("der verwaltete Stack fehlt oder steht falsch: %+v", liste[0])
	}
	if liste[0].Laufend != 2 {
		t.Errorf("die Zahlen von Docker fehlen am verwalteten Stack: %+v", liste[0])
	}
	if liste[1].Name != "fremd" || liste[1].Verwaltet {
		t.Errorf("ein fremdes Projekt darf nicht als verwaltet gelten: %+v", liste[1])
	}
}

// Ohne Compose gibt es keine Projekte von Docker — aber vielleicht
// Verzeichnisse. Ein Fehler an einer Quelle beendet die Auskunft nicht.
func TestStackListUeberlebtFehlendesCompose(t *testing.T) {
	wurzel := stacksIn(t)
	eigenerStack(t, wurzel, "meiner", "services: {}\n", true)

	f := newFakeRunner()
	f.responses["docker compose ls"] = Result{ExitCode: 125}
	s := NewSystemWithRunner(f)

	liste, err := s.StackList(context.Background())
	if err != nil {
		t.Fatalf("StackList: %v", err)
	}
	if len(liste) != 1 || liste[0].Name != "meiner" {
		t.Errorf("der eigene Stack fehlt: %+v", liste)
	}
}

// Das Verzeichnis gibt es erst, wenn der erste Stack angelegt wird. Sein Fehlen
// ist der Normalfall und kein Fehler.
func TestStackListOhneWurzel(t *testing.T) {
	alt := StacksWurzel
	StacksWurzel = filepath.Join(t.TempDir(), "gibtsnicht")
	t.Cleanup(func() { StacksWurzel = alt })

	f := newFakeRunner()
	f.responses["docker compose ls"] = Result{Stdout: "[]"}
	s := NewSystemWithRunner(f)

	if _, err := s.StackList(context.Background()); err != nil {
		t.Errorf("ein fehlendes Verzeichnis ist kein Fehlerfall: %v", err)
	}
}

// Der Kern der Sicherheitsentscheidung: Die Anfrage nennt einen NAMEN, und wo
// die Datei liegt, sagt die Liste. Ein Name, den die Liste nicht kennt, führt
// nirgendwohin — und ein Pfad im Namen wird gar nicht erst zum Pfad.
func TestStackDateiNimmtNurNamenAusDerListe(t *testing.T) {
	wurzel := stacksIn(t)
	eigenerStack(t, wurzel, "web", "services:\n  web:\n    image: nginx\n", true)

	f := newFakeRunner()
	f.responses["docker compose ls"] = Result{Stdout: "[]"}
	s := NewSystemWithRunner(f)
	ctx := context.Background()

	inhalt, err := s.StackDatei(ctx, "web")
	if err != nil {
		t.Fatalf("StackDatei: %v", err)
	}
	if !strings.Contains(inhalt.Text, "image: nginx") {
		t.Errorf("der Inhalt fehlt: %q", inhalt.Text)
	}
	if !inhalt.Verwaltet {
		t.Error("der Stack ist verwaltet")
	}

	// Ein unbekannter Name: kein Pfad, kein Zugriff.
	if _, err := s.StackDatei(ctx, "gibtsnicht"); err == nil {
		t.Error("ein unbekannter Stack muss einen Fehler ergeben")
	}
	// Und ein Name, der wie ein Pfad aussieht, kommt nicht durch die
	// Namensprüfung — bevor überhaupt jemand nach einer Datei sucht.
	for _, boese := range []string{"../../etc/shadow", "/etc/shadow", "web/../..", ""} {
		if _, err := s.StackDatei(ctx, boese); err == nil {
			t.Errorf("%q muss abgelehnt werden", boese)
		}
	}
}

// Eine zu große Datei wird gekürzt UND als gekürzt gemeldet. Eine halbe Datei,
// die wie eine ganze aussieht, ist die schlechteste Auskunft.
func TestStackDateiMeldetKuerzung(t *testing.T) {
	wurzel := stacksIn(t)
	eigenerStack(t, wurzel, "gross", strings.Repeat("# Füllung\n", maxComposeGroesse/5), true)

	f := newFakeRunner()
	f.responses["docker compose ls"] = Result{Stdout: "[]"}
	s := NewSystemWithRunner(f)

	inhalt, err := s.StackDatei(context.Background(), "gross")
	if err != nil {
		t.Fatalf("StackDatei: %v", err)
	}
	if !inhalt.Gekuerzt {
		t.Error("die Kürzung muss gemeldet werden")
	}
	if len(inhalt.Text) > maxComposeGroesse {
		t.Errorf("die Obergrenze greift nicht: %d Bytes", len(inhalt.Text))
	}
}
