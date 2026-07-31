package privops

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Der Compose-Prüfer.
//
// Die Tests hier sind die wertvollsten des Moduls: Ein Prüfer, der eine
// Ausbruchszeile übersieht, sieht genauso aus wie einer, der funktioniert. Zu
// jedem Ablehnungsgrund gehört deshalb ein Fall, und zu den Umgehungsversuchen
// (Anker, Kurzform, Groß-/Kleinschreibung) ebenfalls.

// aufrufe gibt die abgesetzten Kommandozeilen als Text — für die Prüfung
// „es lief NICHTS", die bei einer Ablehnung die eigentliche Aussage ist.
func aufrufeVon(f *fakeRunner) []string {
	out := make([]string, 0, len(f.calls))
	for _, c := range f.calls {
		out = append(out, c.Name+" "+strings.Join(c.Args, " "))
	}
	return out
}

// pruefe ist die Kurzform für einen Prüflauf gegen ein festes Stack-Verzeichnis.
func pruefe(text string) ComposePruefung {
	return PruefeComposeText(text, "/opt/asylum/stacks/web", 8443, true)
}

// abgelehntWegen sagt, ob ein Feld eine Ablehnung ausgelöst hat.
func abgelehntWegen(p ComposePruefung, feld string) bool {
	for _, b := range p.Ablehnungen() {
		if b.Feld == feld {
			return true
		}
	}
	return false
}

// Jeder Ablehnungsgrund aus docs/17-docker.md §4, einer je Fall.
func TestPrueferLehntAusbruchswegeAb(t *testing.T) {
	faelle := []struct {
		name string
		yaml string
		feld string
	}{
		{
			"privileged",
			"services:\n  web:\n    image: nginx\n    privileged: true\n",
			"privileged",
		},
		{
			"pid host",
			"services:\n  web:\n    image: nginx\n    pid: host\n",
			"pid",
		},
		{
			"ipc host",
			"services:\n  web:\n    image: nginx\n    ipc: host\n",
			"ipc",
		},
		{
			"userns host",
			"services:\n  web:\n    image: nginx\n    userns_mode: host\n",
			"userns_mode",
		},
		{
			"network_mode host",
			"services:\n  web:\n    image: nginx\n    network_mode: host\n",
			"network_mode",
		},
		{
			"devices",
			"services:\n  web:\n    image: nginx\n    devices:\n      - /dev/sda:/dev/sda\n",
			"devices",
		},
		{
			"cap_add SYS_ADMIN",
			"services:\n  web:\n    image: nginx\n    cap_add:\n      - SYS_ADMIN\n",
			"cap_add",
		},
		{
			"cap_add ALL",
			"services:\n  web:\n    image: nginx\n    cap_add:\n      - ALL\n",
			"cap_add",
		},
		{
			"seccomp unconfined",
			"services:\n  web:\n    image: nginx\n    security_opt:\n      - seccomp:unconfined\n",
			"security_opt",
		},
		{
			"apparmor unconfined",
			"services:\n  web:\n    image: nginx\n    security_opt:\n      - apparmor:unconfined\n",
			"security_opt",
		},
		{
			"docker.sock",
			"services:\n  web:\n    image: nginx\n    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n",
			"volumes",
		},
		{
			"Wurzel eingehängt",
			"services:\n  web:\n    image: nginx\n    volumes:\n      - /:/host\n",
			"volumes",
		},
		{
			"Sperrpfad /etc/shadow",
			"services:\n  web:\n    image: nginx\n    volumes:\n      - /etc/shadow:/tmp/s:ro\n",
			"volumes",
		},
		{
			"Panel-Datenbank",
			"services:\n  web:\n    image: nginx\n    volumes:\n      - /var/lib/asylum:/daten\n",
			"volumes",
		},
	}

	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			p := pruefe(f.yaml)
			if !p.Geprueft {
				t.Fatalf("die Datei wurde gar nicht geprüft: %s", p.Meldung)
			}
			if p.OK {
				t.Fatalf("%s ging durch — der Prüfer meldet in Ordnung: %+v", f.name, p.Befunde)
			}
			if !abgelehntWegen(p, f.feld) {
				t.Errorf("die Ablehnung nennt nicht das Feld %q: %+v", f.feld, p.Ablehnungen())
			}
			// Eine Ablehnung ohne Dienst und Grund schickt jemanden auf die
			// Suche. Sie soll erklären und nicht bloß sperren.
			for _, b := range p.Ablehnungen() {
				if b.Dienst == "" || b.Grund == "" {
					t.Errorf("ein Befund nennt Dienst oder Grund nicht: %+v", b)
				}
			}
		})
	}
}

// Die Umgehungsversuche. Sie sind der eigentliche Grund, warum gegen die
// GERENDERTE Fassung geprüft wird — hier steht jeweils, was Compose daraus
// macht, denn genau das bekommt der Prüfer im Betrieb zu sehen.
func TestPrueferUeberlebtUmgehungsversuche(t *testing.T) {
	t.Run("YAML-Anker mit Merge", func(t *testing.T) {
		// In der Rohdatei steht „privileged" bei keinem Dienst. yaml.v3 löst
		// den Merge beim Einlesen auf — das ist derselbe Weg, den Compose beim
		// Rendern nimmt.
		p := pruefe(`x-basis: &basis
  privileged: true
services:
  web:
    image: nginx
    <<: *basis
`)
		if p.OK {
			t.Errorf("ein Anker hat privileged an der Prüfung vorbeigebracht: %+v", p.Befunde)
		}
	})

	t.Run("Kurzform ohne Liste", func(t *testing.T) {
		// „cap_add: SYS_ADMIN" ohne Bindestrich. Compose nimmt es an, und ein
		// Prüfer, der nur Folgen liest, sieht ein leeres Feld.
		p := pruefe("services:\n  web:\n    image: nginx\n    cap_add: SYS_ADMIN\n")
		if p.OK {
			t.Error("cap_add in Kurzform ging durch")
		}
	})

	t.Run("cap_add mit CAP_-Präfix und Kleinschreibung", func(t *testing.T) {
		p := pruefe("services:\n  web:\n    image: nginx\n    cap_add:\n      - cap_sys_admin\n")
		if p.OK {
			t.Error("cap_sys_admin in Kleinschreibung ging durch")
		}
	})

	t.Run("Namensraum in Großschreibung", func(t *testing.T) {
		p := pruefe("services:\n  web:\n    image: nginx\n    pid: HOST\n")
		if p.OK {
			t.Error("„HOST" + `"` + " ging durch")
		}
	})

	t.Run("privileged als yes", func(t *testing.T) {
		// YAML kennt mehrere Schreibweisen für wahr, und Compose übernimmt sie.
		p := pruefe("services:\n  web:\n    image: nginx\n    privileged: yes\n")
		if p.OK {
			t.Error("„yes" + `"` + " als Wahrheitswert ging durch")
		}
	})

	t.Run("Mount in Langform", func(t *testing.T) {
		// Die gerenderte Fassung benutzt je nach Compose-Fassung die lange
		// Form. Ein Prüfer, der nur die kurze kennt, hält sie für leer — und
		// meldet dann „in Ordnung" zu einem Mount, den er nie gesehen hat.
		p := pruefe(`services:
  web:
    image: nginx
    volumes:
      - type: bind
        source: /var/run/docker.sock
        target: /var/run/docker.sock
`)
		if p.OK {
			t.Errorf("der Socket in Langform ging durch: %+v", p.Befunde)
		}
	})

	t.Run("Pfad mit Umweg", func(t *testing.T) {
		// filepath.Clean macht daraus /etc/shadow. Ohne Normalisierung wäre der
		// Vergleich mit der Sperrliste wirkungslos.
		p := pruefe("services:\n  web:\n    image: nginx\n    volumes:\n      - /etc/ssl/../shadow:/tmp/s\n")
		if p.OK {
			t.Error("ein Pfad mit .. ging an der Sperrliste vorbei")
		}
	})

	t.Run("Unterverzeichnis eines Sperrpfads", func(t *testing.T) {
		p := pruefe("services:\n  web:\n    image: nginx\n    volumes:\n      - /root/.ssh:/keys\n")
		if p.OK {
			t.Error("ein Unterverzeichnis von /root ging durch")
		}
	})
}

// Der häufige, legitime Fall: ein Wirtspfad außerhalb des Stack-Verzeichnisses.
// Er wird NICHT abgelehnt, sondern löst eine Rückfrage der Stufe 3 aus.
func TestPrueferMeldetAussenmountsOhneAbzulehnen(t *testing.T) {
	p := pruefe("services:\n  web:\n    image: nginx\n    volumes:\n      - /srv/daten:/data\n")
	if !p.OK {
		t.Fatalf("ein Wirtspfad ist kein Ablehnungsgrund: %+v", p.Ablehnungen())
	}
	aussen := p.Aussenmounts()
	if len(aussen) != 1 {
		t.Fatalf("erwartet 1 Mount nach außen, gefunden %d: %+v", len(aussen), p.Befunde)
	}
	if !strings.Contains(aussen[0].Wert, "/srv/daten") {
		t.Errorf("der Befund nennt den Pfad nicht: %+v", aussen[0])
	}
	// „nur lesen" gehört in den Grund: Es ist der Unterschied zwischen „kann
	// mitlesen" und „kann überschreiben".
	nurLesen := pruefe("services:\n  web:\n    image: nginx\n    volumes:\n      - /srv/daten:/data:ro\n")
	if strings.Contains(nurLesen.Aussenmounts()[0].Grund, "schreiben") {
		t.Error("bei :ro darf der Grund nicht von Schreiben sprechen")
	}
}

// Ein Pfad IM Stack-Verzeichnis ist weder Ablehnung noch Rückfrage: Dort liegt
// die compose.yaml selbst, und ein Verzeichnis daneben ist der Normalfall.
func TestPrueferLaesstEigenesVerzeichnisDurch(t *testing.T) {
	p := pruefe(`services:
  web:
    image: nginx
    volumes:
      - /opt/asylum/stacks/web/html:/usr/share/nginx/html
      - ./daten:/daten
      - benannt:/var/lib/daten
`)
	if !p.OK || len(p.Aussenmounts()) != 0 {
		t.Errorf("das eigene Verzeichnis und ein benanntes Volume sind keine Befunde: %+v", p.Befunde)
	}
}

// Der klassische Fehler einer Pfadwache: Präfixvergleich statt Pfadvergleich.
// „/opt/asylum/stacks-fremd" hat „/opt/asylum/stacks/web" NICHT als Präfix,
// aber „/opt/asylum/stacks/webseite" liegt auch nicht in „/opt/asylum/stacks/web".
func TestPrueferVerwechseltKeineNachbarverzeichnisse(t *testing.T) {
	p := pruefe("services:\n  web:\n    image: nginx\n    volumes:\n      - /opt/asylum/stacks/webseite:/daten\n")
	if len(p.Aussenmounts()) != 1 {
		t.Errorf("ein Nachbarverzeichnis liegt draußen und gehört gemeldet: %+v", p.Befunde)
	}
}

// Unbekannte Felder sind kein Freibrief: Sie werden als „nicht geprüft"
// gemeldet, statt stillschweigend durchzugehen. Dieselbe Haltung wie
// configcheck.go.
func TestPrueferMeldetUnbekannteFelder(t *testing.T) {
	p := pruefe("services:\n  web:\n    image: nginx\n    voellig_neues_feld: irgendwas\n")
	if !p.OK {
		t.Error("ein unbekanntes Feld ist kein Ablehnungsgrund")
	}
	gefunden := false
	for _, h := range p.Hinweise() {
		if h.Feld == "voellig_neues_feld" {
			gefunden = true
		}
	}
	if !gefunden {
		t.Errorf("das unbekannte Feld wurde verschwiegen: %+v", p.Befunde)
	}
}

// Ein Stack, der den Panel-Port belegt, nimmt der Oberfläche den Zugang — und
// zwar in dem Augenblick, in dem er startet. Das ist ein Hinweis und keine
// Ablehnung: Es kann Absicht sein, wenn ein Reverse-Proxy übernimmt.
func TestPrueferWarntVorDemPanelPort(t *testing.T) {
	faelle := []string{
		`ports: ["8443:80"]`,
		`ports: ["127.0.0.1:8443:80"]`,
		"ports:\n      - published: 8443\n        target: 80",
	}
	for _, form := range faelle {
		p := pruefe("services:\n  web:\n    image: nginx\n    " + form + "\n")
		if !p.OK {
			t.Errorf("%s: eine Portkollision ist kein Ablehnungsgrund", form)
		}
		if len(p.Hinweise()) == 0 {
			t.Errorf("%s: die Kollision mit dem Panel-Port fehlt: %+v", form, p.Befunde)
		}
	}
	// Ein anderer Port ist kein Hinweis. Ein Prüfer, der bei jedem Port etwas
	// sagt, wird nicht mehr gelesen.
	p := pruefe("services:\n  web:\n    image: nginx\n    ports: [\"8080:80\"]\n")
	if len(p.Hinweise()) != 0 {
		t.Errorf("ein gewöhnlicher Port ist kein Hinweis: %+v", p.Hinweise())
	}
}

// Ein harmloser Stack geht ohne jeden Befund durch. Ohne diesen Fall wüsste
// niemand, ob der Prüfer bloß alles ablehnt.
func TestPrueferLaesstHarmlosesDurch(t *testing.T) {
	p := pruefe(`services:
  web:
    image: nginx:alpine
    restart: unless-stopped
    ports:
      - "8080:80"
    environment:
      TZ: Europe/Berlin
    volumes:
      - html:/usr/share/nginx/html
    healthcheck:
      test: ["CMD", "true"]
volumes:
  html:
`)
	if !p.OK || len(p.Befunde) != 0 {
		t.Errorf("ein harmloser Stack darf keinen Befund ergeben: %+v", p.Befunde)
	}
	if len(p.Dienste) != 1 || p.Dienste[0] != "web" {
		t.Errorf("die Dienstnamen fehlen: %+v", p.Dienste)
	}
}

// Unlesbares oder leeres YAML ist „nicht geprüft" — nicht „in Ordnung" und
// nicht „abgelehnt". Der Unterschied zählt, weil ein Formfehler etwas anderes
// ist als ein Ausbruchsversuch.
func TestPrueferUnterscheidetNichtGeprueftVonInOrdnung(t *testing.T) {
	for _, text := range []string{"kein: [yaml", "", "nur: text\n"} {
		p := PruefeComposeText(text, "/opt/asylum/stacks/web", 0, true)
		if p.Geprueft {
			t.Errorf("%q wurde als geprüft gemeldet: %+v", text, p)
		}
		if p.OK {
			t.Errorf("%q wurde als in Ordnung gemeldet — das ist die Aussage, die hier fehlen muss", text)
		}
	}
}

// Der Prüfer sortiert nach Dringlichkeit: Wer eine Ablehnung liest, soll sie
// nicht unter drei Hinweisen suchen.
func TestPrueferStelltAblehnungenNachOben(t *testing.T) {
	p := pruefe(`services:
  a:
    image: nginx
    unbekanntes_feld: x
  b:
    image: nginx
    volumes:
      - /srv/daten:/data
  c:
    image: nginx
    privileged: true
`)
	if len(p.Befunde) < 3 {
		t.Fatalf("erwartet drei Befunde, gefunden %d: %+v", len(p.Befunde), p.Befunde)
	}
	if p.Befunde[0].Art != BefundAblehnung {
		t.Errorf("oben steht %q statt der Ablehnung: %+v", p.Befunde[0].Art, p.Befunde)
	}
}

// ------------------------------------------------------ Schreiben und Bedienen ---

// Der Kern der Pfadwache: Ein Name wird geprüft, bevor er ein Verzeichnis wird.
func TestStackNamePruefung(t *testing.T) {
	gut := []string{"web", "web-2", "mein_stack", "a", "0815"}
	for _, n := range gut {
		if err := PruefeStackName(n); err != nil {
			t.Errorf("%q ist ein zulässiger Name, wurde aber abgelehnt: %v", n, err)
		}
	}
	// Großbuchstaben sind hier nicht Geschmack: Compose selbst lehnt sie ab, und
	// ein Stack, den das Panel anlegt und docker nicht startet, wäre die
	// unangenehmste Art von Fehler.
	boese := []string{"", ".", "..", "../web", "/etc", "Web", "mein stack", "web;rm", "web\n", "-web"}
	for _, n := range boese {
		if err := PruefeStackName(n); err == nil {
			t.Errorf("%q muss abgelehnt werden", n)
		}
	}
}

// Geschrieben wird nur, was den Marker trägt — und der Marker kommt von selbst
// dazu, auch wenn ihn jemand aus dem Editor löscht.
func TestStackSchreibenSetztMarkerUndSchreibtNurEigenes(t *testing.T) {
	wurzel := stacksIn(t)
	f := newFakeRunner()
	// Ohne echte Docker-Antwort scheitert das Rendern — dann prüft der Prüfer
	// die Rohdatei und sagt das. Geschrieben wird trotzdem, denn die Rohprüfung
	// hat nichts gefunden.
	f.responses["docker compose"] = Result{ExitCode: 127, Stderr: "docker: not found"}
	s := NewSystemWithRunner(f)
	ctx := context.Background()

	p, err := s.StackSchreiben(ctx, "neu", "services:\n  web:\n    image: nginx\n", 0)
	if err != nil {
		t.Fatalf("StackSchreiben: %v", err)
	}
	if p.Gerendert {
		t.Error("ohne Docker kann nichts gerendert worden sein")
	}
	inhalt, _, err := composeLesen(wurzel + "/neu/" + stackDatei)
	if err != nil {
		t.Fatalf("die Datei fehlt: %v", err)
	}
	if !strings.HasPrefix(inhalt, stackMarker) {
		t.Errorf("der Marker fehlt in der ersten Zeile: %q", inhalt)
	}

	// Zweimal speichern verdoppelt den Marker nicht.
	if _, err := s.StackSchreiben(ctx, "neu", inhalt, 0); err != nil {
		t.Fatalf("zweites Schreiben: %v", err)
	}
	zweimal, _, _ := composeLesen(wurzel + "/neu/" + stackDatei)
	if strings.Count(zweimal, stackMarker) != 1 {
		t.Errorf("der Marker steht %d mal da", strings.Count(zweimal, stackMarker))
	}

	// Eine fremde Datei am selben Platz wird nicht angefasst.
	eigenerStack(t, wurzel, "fremd", "services: {}\n", false)
	if _, err := s.StackSchreiben(ctx, "fremd", "services:\n  x:\n    image: nginx\n", 0); err == nil {
		t.Error("eine Datei ohne Marker darf nicht überschrieben werden")
	}
	roh, _, _ := composeLesen(wurzel + "/fremd/" + stackDatei)
	if strings.Contains(roh, "image: nginx") {
		t.Error("die fremde Datei wurde trotzdem überschrieben")
	}
}

// Ein abgelehnter Stack landet nie auf der Platte — auch nicht kurz.
func TestStackSchreibenLegtAbgelehntesNichtAb(t *testing.T) {
	wurzel := stacksIn(t)
	f := newFakeRunner()
	f.responses["docker compose"] = Result{ExitCode: 127}
	s := NewSystemWithRunner(f)

	p, err := s.StackSchreiben(context.Background(), "boese",
		"services:\n  web:\n    image: nginx\n    privileged: true\n", 0)
	if err != nil {
		t.Fatalf("eine Ablehnung ist kein Go-Fehler, sondern ein Ergebnis: %v", err)
	}
	if p.OK {
		t.Fatal("privileged ging durch")
	}
	if _, _, err := composeLesen(wurzel + "/boese/" + stackDatei); err == nil {
		t.Error("die abgelehnte Datei liegt auf der Platte")
	}
	// Auch die temporäre Datei ist weg — sonst läge der abgelehnte Text noch
	// dort, nur unter anderem Namen.
	eintraege, err := os.ReadDir(wurzel + "/boese")
	if err == nil && len(eintraege) != 0 {
		namen := make([]string, 0, len(eintraege))
		for _, e := range eintraege {
			namen = append(namen, e.Name())
		}
		t.Errorf("im Verzeichnis liegt noch etwas: %v", namen)
	}
}

// Der Prüfer läuft vor „up" und nicht danach: Ein abgelehnter Stack startet
// nicht, und es läuft kein einziges Kommando.
func TestStackAusfuehrenPruefungHaeltUpAn(t *testing.T) {
	wurzel := stacksIn(t)
	eigenerStack(t, wurzel, "boese", "services:\n  web:\n    image: nginx\n    privileged: true\n", true)

	f := newFakeRunner()
	f.responses["docker compose ls"] = Result{Stdout: "[]"}
	f.responses["docker compose"] = Result{ExitCode: 127}
	s := NewSystemWithRunner(f)

	p, err := s.StackAusfuehren(context.Background(), "boese", StackUp, false, 0, nil)
	if err != nil {
		t.Fatalf("eine Ablehnung ist ein Ergebnis und kein Fehler: %v", err)
	}
	if p.OK {
		t.Fatal("der Stack wurde nicht abgelehnt")
	}
	for _, ruf := range aufrufeVon(f) {
		if strings.Contains(ruf, " up") {
			t.Errorf("trotz Ablehnung lief %q", ruf)
		}
	}
}

// „down" läuft ohne Prüfung. Etwas anzuhalten war nie das Problem — und ein
// Stack, den man wegen eines Befundes nicht mehr stoppen könnte, wäre die
// Falle, in die eine zu eifrige Prüfung führt.
func TestStackAusfuehrenStopptAuchAbgelehnte(t *testing.T) {
	wurzel := stacksIn(t)
	eigenerStack(t, wurzel, "boese", "services:\n  web:\n    image: nginx\n    privileged: true\n", true)

	f := newFakeRunner()
	f.responses["docker compose ls"] = Result{Stdout: "[]"}
	s := NewSystemWithRunner(f)

	if _, err := s.StackAusfuehren(context.Background(), "boese", StackDown, false, 0, nil); err != nil {
		t.Fatalf("down muss auch bei einem abgelehnten Stack gehen: %v", err)
	}
	gefunden := false
	for _, ruf := range aufrufeVon(f) {
		if strings.Contains(ruf, "down") {
			gefunden = true
		}
	}
	if !gefunden {
		t.Errorf("down wurde nicht ausgeführt: %v", aufrufeVon(f))
	}
}

// Löschen fährt zuerst herunter: Ein gelöschtes Verzeichnis hinterließe sonst
// laufende Container, die zu keinem Projekt mehr gehören.
func TestStackLoeschenFaehrtVorherHerunter(t *testing.T) {
	wurzel := stacksIn(t)
	eigenerStack(t, wurzel, "weg", "services: {}\n", true)

	f := newFakeRunner()
	s := NewSystemWithRunner(f)

	if err := s.StackLoeschen(context.Background(), "weg", nil); err != nil {
		t.Fatalf("StackLoeschen: %v", err)
	}
	gefunden := false
	for _, ruf := range aufrufeVon(f) {
		if strings.Contains(ruf, "down") {
			gefunden = true
		}
	}
	if !gefunden {
		t.Errorf("vor dem Löschen wurde nicht heruntergefahren: %v", aufrufeVon(f))
	}
	if _, _, err := composeLesen(wurzel + "/weg/" + stackDatei); err == nil {
		t.Error("das Verzeichnis steht noch")
	}

	// Ein fremdes Verzeichnis wird nicht gelöscht.
	eigenerStack(t, wurzel, "fremd", "services: {}\n", false)
	if err := s.StackLoeschen(context.Background(), "fremd", nil); err == nil {
		t.Error("ein Verzeichnis ohne Marker darf nicht gelöscht werden")
	}
	if _, _, err := composeLesen(wurzel + "/fremd/" + stackDatei); err != nil {
		t.Error("das fremde Verzeichnis wurde trotzdem entfernt")
	}
}

// Der Schutz des Prüfers selbst: „extends" und „env_file" auf eine Datei
// außerhalb des Stack-Verzeichnisses werden abgelehnt, BEVOR gerendert wird.
// Ohne diese Prüfung wäre der Prüfer der Weg, /etc/asylum/config.yaml zu lesen.
func TestVerweiseNachDraussenWerdenVorDemRendernAbgelehnt(t *testing.T) {
	faelle := []struct{ name, yaml string }{
		{"extends", "services:\n  web:\n    extends:\n      file: /etc/asylum/config.yaml\n      service: x\n"},
		{"extends relativ", "services:\n  web:\n    extends:\n      file: ../../../etc/asylum/config.yaml\n      service: x\n"},
		{"env_file", "services:\n  web:\n    image: nginx\n    env_file: /etc/asylum/config.yaml\n"},
		{"env_file als Liste", "services:\n  web:\n    image: nginx\n    env_file:\n      - ./eigen.env\n      - /root/.ssh/id_ed25519\n"},
	}
	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			befunde := verweiseNachDraussen(f.yaml, "/opt/asylum/stacks/web")
			if len(befunde) == 0 {
				t.Fatal("der Verweis nach draußen wurde nicht bemerkt")
			}
			if befunde[0].Art != BefundAblehnung {
				t.Errorf("der Befund ist keine Ablehnung: %+v", befunde[0])
			}
		})
	}
	// Eine Datei im eigenen Verzeichnis ist der Normalfall und kein Befund.
	ok := "services:\n  web:\n    image: nginx\n    env_file: ./eigen.env\n"
	if befunde := verweiseNachDraussen(ok, "/opt/asylum/stacks/web"); len(befunde) != 0 {
		t.Errorf("eine eigene .env ist kein Befund: %+v", befunde)
	}
}

// Die Vorlagen sind Gerüste und keine Beispiele mit Ausbruchszeilen. Sie gehen
// durch den eigenen Prüfer — wäre es anders, brächte das Panel bei, was es
// verbietet.
func TestVorlagenGehenDurchDenEigenenPruefer(t *testing.T) {
	vorlagen := StackVorlagen()
	if len(vorlagen) < 3 {
		t.Fatalf("erwartet mindestens drei Vorlagen, gefunden %d", len(vorlagen))
	}
	for _, v := range vorlagen {
		t.Run(v.Kennung, func(t *testing.T) {
			p := pruefe(v.Text)
			if !p.Geprueft {
				t.Fatalf("die Vorlage ist kein lesbares Compose: %s", p.Meldung)
			}
			if !p.OK {
				t.Errorf("die eigene Vorlage wird vom eigenen Prüfer abgelehnt: %+v", p.Ablehnungen())
			}
			if v.Titel == "" || v.Beschreibung == "" {
				t.Error("eine Vorlage ohne Titel oder Beschreibung ist eine Textwand ohne Anlass")
			}
			// Der Wert der Vorlagen sind ihre Kommentare: Sie sagen, warum eine
			// Zeile so dasteht. Eine Vorlage ohne sie wäre nur weniger Tipparbeit.
			if !strings.Contains(v.Text, "#") {
				t.Error("die Vorlage erklärt nichts")
			}
		})
	}
}

// ------------------------------------------------- Angriffsdurchgang (Schritt 9) ---
//
// Diese Fälle sind KEINE erdachten Randfälle. Jeder einzelne ist beim
// Angriffsdurchgang gegen den eigenen Prüfer durch ihn hindurchgegangen, und
// jeder steht hier, damit er es nicht wieder tut.
//
// Sie stehen zusammen und nicht verstreut, weil das ihre Gemeinsamkeit ist: Sie
// alle sahen für den Prüfer nach etwas Harmlosem aus. Ein Wirtspfad, der wie ein
// benanntes Volume aussieht. Ein Geräteschalter, der nicht „devices" heißt. Ein
// Pfad, der nicht absolut ist und deshalb für „innen" gehalten wurde.

func TestPrueferSchliesstDieAusbruecheDesAngriffsdurchgangs(t *testing.T) {
	faelle := []struct {
		name string
		yaml string
		feld string
	}{
		{
			// Der Vergleich mit der Sperrliste traf nicht, weil dort absolute
			// Pfade stehen — und danach galt „nicht absolut" als „liegt innen".
			"relativer Ausbruch zum Socket",
			"services:\n  web:\n    image: nginx\n    volumes:\n      - ../../../../var/run/docker.sock:/var/run/docker.sock\n",
			"volumes",
		},
		{
			// Der schwerwiegendste Fund: Im Dienst steht nur „- hack:/host",
			// also etwas, das wie ein harmloses benanntes Volume aussieht.
			"benanntes Volume, das in Wahrheit / einhängt",
			`services:
  web:
    image: nginx
    volumes:
      - hack:/host
volumes:
  hack:
    driver: local
    driver_opts:
      type: none
      device: /
      o: bind
`,
			"volumes",
		},
		{
			// „devices" ohne das Wort: Den Geräteknoten legt der Container
			// selbst an, MKNOD hat er von Haus aus.
			"device_cgroup_rules statt devices",
			"services:\n  web:\n    image: nginx\n    device_cgroup_rules:\n      - \"c *:* rwm\"\n",
			"device_cgroup_rules",
		},
		{
			// „docker compose up" baut, wenn ein Bauabschnitt dasteht.
			"Bauabschnitt mit Kontext auf der Wurzel",
			"services:\n  web:\n    build:\n      context: /\n      dockerfile: Dockerfile\n",
			"build.context",
		},
		{
			// Was ein fremder Container mitbringt, steht nirgends.
			"volumes_from auf einen fremden Container",
			"services:\n  web:\n    image: nginx\n    volumes_from:\n      - container:privilegiert\n",
			"volumes_from",
		},
	}

	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			p := pruefe(f.yaml)
			if p.OK {
				t.Fatalf("der Ausbruch ging durch: %+v", p.Befunde)
			}
			if !abgelehntWegen(p, f.feld) {
				t.Errorf("die Ablehnung nennt nicht das Feld %q: %+v", f.feld, p.Ablehnungen())
			}
		})
	}
}

// Der Bauabschnitt IM eigenen Verzeichnis ist der Normalfall und kein Befund.
// Ohne diesen Fall wüsste niemand, ob die Prüfung bloß jeden Bau ablehnt.
func TestPrueferLaesstEigenenBauabschnittDurch(t *testing.T) {
	for _, yaml := range []string{
		"services:\n  web:\n    build: .\n",
		"services:\n  web:\n    build:\n      context: ./app\n      dockerfile: Dockerfile\n",
	} {
		if p := pruefe(yaml); !p.OK {
			t.Errorf("ein eigener Bauabschnitt ist kein Befund: %+v", p.Ablehnungen())
		}
	}
}

// Ein gewöhnliches benanntes Volume bleibt eines. Der neue Blick auf die
// oberste volumes-Ebene darf nicht dazu führen, dass jedes Volume verdächtig
// ist — sonst wäre die Prüfung wertlos.
func TestPrueferLaesstEchteBenannteVolumesDurch(t *testing.T) {
	p := pruefe(`services:
  db:
    image: postgres:16
    volumes:
      - db_daten:/var/lib/postgresql/data
volumes:
  db_daten:
`)
	if !p.OK || len(p.Befunde) != 0 {
		t.Errorf("ein gewöhnliches Volume ist kein Befund: %+v", p.Befunde)
	}
}

// Ein symbolischer Verweis IM Stack-Verzeichnis, der hinausführt, ist ein
// Ausbruch: Eingehängt wird das Ziel, nicht der Verweis.
func TestPrueferFolgtSymbolischenVerweisen(t *testing.T) {
	wurzel := t.TempDir()
	draussen := t.TempDir()
	if err := os.Symlink(draussen, filepath.Join(wurzel, "raus")); err != nil {
		t.Skipf("symbolische Verweise nicht möglich: %v", err)
	}

	p := PruefeComposeText(
		"services:\n  web:\n    image: nginx\n    volumes:\n      - "+
			filepath.Join(wurzel, "raus")+":/daten\n", wurzel, 0, true)
	if p.OK {
		t.Errorf("ein Verweis aus dem Verzeichnis heraus ging durch: %+v", p.Befunde)
	}

	// Ein gewöhnliches Verzeichnis daneben bleibt harmlos.
	if err := os.Mkdir(filepath.Join(wurzel, "daten"), 0o755); err != nil {
		t.Fatal(err)
	}
	p = PruefeComposeText(
		"services:\n  web:\n    image: nginx\n    volumes:\n      - "+
			filepath.Join(wurzel, "daten")+":/daten\n", wurzel, 0, true)
	if !p.OK || len(p.Aussenmounts()) != 0 {
		t.Errorf("das eigene Verzeichnis ist kein Befund: %+v", p.Befunde)
	}
}

// ------------------------------------------------ Pfadwache im Angriffsdurchgang ---

// Ein fremdes Projekt sagt selbst, wo seine Datei liegt — und diese Angabe hat
// nicht das Panel gesetzt. Zeigt sie auf eine Systemdatei, läse dieser Endpunkt
// sie und zeigte sie JEDEM angemeldeten Konto, auch einem mit reinem Leserecht.
// Genau das ging im Angriffsdurchgang durch.
func TestStackDateiLiestNurComposeDateien(t *testing.T) {
	stacksIn(t)

	f := newFakeRunner()
	f.responses["docker compose ls"] = Result{
		Stdout: `[{"Name":"boese","Status":"running(1)","ConfigFiles":"/etc/shadow"}]`,
	}
	s := NewSystemWithRunner(f)

	if _, err := s.StackDatei(context.Background(), "boese"); err == nil {
		t.Error("eine Datei ohne YAML-Endung darf nicht gelesen werden")
	}

	// Und eine echte Compose-Datei geht weiterhin durch.
	echt := t.TempDir() + "/docker-compose.yml"
	if err := os.WriteFile(echt, []byte("services: {}\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	f.responses["docker compose ls"] = Result{
		Stdout: `[{"Name":"gut","Status":"running(1)","ConfigFiles":"` + echt + `"}]`,
	}
	if _, err := s.StackDatei(context.Background(), "gut"); err != nil {
		t.Errorf("eine echte Compose-Datei muss lesbar bleiben: %v", err)
	}
}

// Das Stack-Verzeichnis darf kein symbolischer Verweis sein: MkdirAll folgt
// einem vorhandenen Verweis wortlos, und dann läge die Datei woanders.
func TestStackSchreibenFolgtKeinemVerweis(t *testing.T) {
	wurzel := stacksIn(t)
	draussen := t.TempDir()
	if err := os.Symlink(draussen, filepath.Join(wurzel, "verweis")); err != nil {
		t.Skipf("symbolische Verweise nicht möglich: %v", err)
	}

	f := newFakeRunner()
	f.responses["docker compose --file"] = Result{ExitCode: 127}
	s := NewSystemWithRunner(f)

	if _, err := s.StackSchreiben(context.Background(), "verweis",
		"services:\n  a:\n    image: nginx\n", 0); err == nil {
		t.Error("in ein Verweis-Verzeichnis darf nicht geschrieben werden")
	}
	if _, err := os.Stat(filepath.Join(draussen, stackDatei)); err == nil {
		t.Error("die Datei wurde außerhalb der Wurzel angelegt")
	}
}

// Und Löschen nimmt das Ziel eines Verweises nicht mit: RemoveAll entfernt den
// Verweis, nicht das, worauf er zeigt — geprüft, weil das die Zusage ist, auf
// die es hier ankommt.
func TestStackLoeschenNimmtVerweisZielNichtMit(t *testing.T) {
	wurzel := stacksIn(t)
	draussen := t.TempDir()
	opfer := filepath.Join(draussen, stackDatei)
	if err := os.WriteFile(opfer, []byte(stackMarker+"\nservices: {}\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(draussen, filepath.Join(wurzel, "weg")); err != nil {
		t.Skipf("symbolische Verweise nicht möglich: %v", err)
	}

	f := newFakeRunner()
	s := NewSystemWithRunner(f)
	_ = s.StackLoeschen(context.Background(), "weg", nil)

	if _, err := os.Stat(opfer); err != nil {
		t.Errorf("das Ziel des Verweises wurde mitgelöscht: %v", err)
	}
}

// Die Namensprüfungen des Moduls gegen alles, was in einer Kommandozeile
// gefährlich wäre. Sie stehen hier zusammen, weil der Angriffsdurchgang sie
// zusammen prüft: Es gibt kein Argument aus einer Anfrage, das ohne eine dieser
// Prüfungen zu docker geht.
//
// Eine Shell gibt es nicht — die Argumente gehen als Feld an exec, und deshalb
// ist ein Semikolon kein Ausbruch. Gefährlich ist etwas anderes: ein Wert, der
// mit „-" beginnt, wird von docker als OPTION gelesen. Genau dagegen steht
// überall „--" vor dem Argument, und die Prüfungen sind der zweite Riegel.
func TestNamenspruefungenWeisenGefaehrlichesAb(t *testing.T) {
	boese := []string{
		"", " ", "-rf", "--force", "-", "a b", "a;b", "a|b", "a&b",
		"a\nb", "a\tb", "a`b`", "a$(b)", "a'b", "a\"b", "a\x00b",
	}

	for _, n := range boese {
		if err := ValidateContainerID(n); err == nil {
			t.Errorf("ValidateContainerID(%q) hat angenommen", n)
		}
		if err := ValidateImageRef(n); err == nil {
			t.Errorf("ValidateImageRef(%q) hat angenommen", n)
		}
		if err := PruefeStackName(n); err == nil {
			t.Errorf("PruefeStackName(%q) hat angenommen", n)
		}
	}

	// Und der Regelfall geht durch — sonst wäre die Prüfung nur streng.
	for _, gut := range []string{"aaaa11112222", "web-proxy-1", "web_daten"} {
		if err := ValidateContainerID(gut); err != nil {
			t.Errorf("ValidateContainerID(%q): %v", gut, err)
		}
	}
	for _, gut := range []string{"nginx:alpine", "sha256:aaa", "ghcr.io/o/n:1.2", "reg:5000/a/b:1"} {
		if err := ValidateImageRef(gut); err != nil {
			t.Errorf("ValidateImageRef(%q): %v", gut, err)
		}
	}
}
