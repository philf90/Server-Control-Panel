package privops

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"testing"
)

// Aufgezeichnete Ausgaben.
//
// Der Vorbehalt aus docs/18-webserver.md §11 gilt und gehört hierher, damit ihn
// niemand übersieht: Diese Ausgaben stammen aus der Erinnerung an echte Systeme
// und sind NICHT auf einer laufenden Installation abgenommen worden. Die Formen
// sind seit Jahren stabil (ss seit iproute2 4.x, "nginx -v" seit jeher), aber
// bestätigt ist das hier nicht. Beim Handdurchgang auf einem echten Server
// gehört beides nachgeschaut — es ist die eine Sorte Fehler, gegen die kein
// Test hilft, weil der Test dieselbe Annahme teilt.
const (
	// "nginx -v" schreibt auf die FEHLERAUSGABE. Zwei Formen, beide gebräuchlich:
	// mit Distributionszusatz (Debian, Ubuntu) und ohne (Paket von nginx.org).
	nginxVersionErr    = "nginx version: nginx/1.24.0 (Ubuntu)\n"
	nginxVersionPurErr = "nginx version: nginx/1.27.3\n"

	// "ss --no-header --listening --tcp --numeric --processes" als root.
	//
	// Enthält absichtlich mehr, als der Parser behalten darf: Port 53 und 8443
	// gehen ihn nichts an, und der Eintrag mit %lo prüft die Adressform, an der
	// eine Trennung am ERSTEN Doppelpunkt scheitern würde.
	ssLauscherOut = `LISTEN 0      4096   127.0.0.53%lo:53          0.0.0.0:*    users:(("systemd-resolve",pid=712,fd=14))
LISTEN 0      511          0.0.0.0:80          0.0.0.0:*    users:(("nginx",pid=1234,fd=6),("nginx",pid=1233,fd=6))
LISTEN 0      511             [::]:80             [::]:*    users:(("nginx",pid=1234,fd=7))
LISTEN 0      511          0.0.0.0:443         0.0.0.0:*    users:(("nginx",pid=1234,fd=8))
LISTEN 0      4096         0.0.0.0:8443        0.0.0.0:*    users:(("asylumd",pid=999,fd=3))
LISTEN 0      128            0.0.0.0:22          0.0.0.0:*    users:(("sshd",pid=880,fd=3))
`

	// Derselbe Server, aber mit Caddy statt nginx — der Fall, wegen dem es die
	// Portabfrage überhaupt gibt.
	ssCaddyOut = `LISTEN 0      4096         0.0.0.0:80          0.0.0.0:*    users:(("caddy",pid=651,fd=9))
LISTEN 0      4096            [::]:443            [::]:*    users:(("caddy",pid=651,fd=11))
`

	// Und ein Traefik im Container: Auf dem Wirt heißt der Prozess
	// "docker-proxy" und steht in keiner Paketliste. Genau deshalb fragt dieses
	// Modul nach dem Port und nicht nach einem Paketnamen.
	ssContainerOut = `LISTEN 0      4096         0.0.0.0:80          0.0.0.0:*    users:(("docker-proxy",pid=2201,fd=4))
`
)

// setzeWebserver legt die Antworten für einen vollständigen Durchlauf hin.
func setzeWebserver(f *fakeRunner, version, paket, aktiv, ss Result) {
	f.responses["nginx -v"] = version
	f.responses["dpkg-query -W -f=${db:Status-Status} nginx"] = paket
	f.responses["systemctl is-active --quiet -- nginx.service"] = aktiv
	f.responses["ss --no-header"] = ss
}

func TestWebServerStateMitNginx(t *testing.T) {
	f := newFakeRunner()
	setzeWebserver(f,
		Result{Stderr: nginxVersionErr},
		Result{Stdout: "installed"},
		Result{},
		Result{Stdout: ssLauscherOut},
	)
	s := NewSystemWithRunner(f)

	st, err := s.WebServerState(context.Background())
	if err != nil {
		t.Fatalf("WebServerState: %v", err)
	}
	if !st.Installiert {
		t.Error("nginx sollte als installiert gelten")
	}
	// Die Fassung steht auf der Fehlerausgabe. Wer hier nur Stdout liest,
	// bekommt ein installiertes nginx ohne Fassung — und merkt es nie, weil
	// „installiert" ja stimmt.
	if st.Version != "1.24.0" {
		t.Errorf("Fassung falsch gelesen: %q", st.Version)
	}
	if st.Paket != "nginx" {
		t.Errorf("Paket falsch gelesen: %q", st.Paket)
	}
	if !st.DienstAktiv {
		t.Error("nginx.service sollte als aktiv gelten")
	}
	if !st.LauscherGeprueft {
		t.Error("die Portbelegung wurde ermittelt und muss als geprüft gelten")
	}
	if len(st.Belegt()) != 0 {
		t.Errorf("nginx belegt seine eigenen Ports nicht fremd: %+v", st.Belegt())
	}
}

// Der Fall, für den dieses Modul zuerst gebaut wurde: Es läuft ein Webserver,
// nur nicht der, den das Panel verwaltet. Dann darf nichts eingespielt werden.
func TestWebServerStateErkenntFremdenServer(t *testing.T) {
	for _, fall := range []struct {
		name    string
		ausgabe string
		prozess string
	}{
		{"Caddy", ssCaddyOut, "caddy"},
		{"Container", ssContainerOut, "docker-proxy"},
	} {
		t.Run(fall.name, func(t *testing.T) {
			f := newFakeRunner()
			f.errs["nginx"] = errors.New("nginx ist auf diesem System nicht vorhanden")
			f.responses["ss --no-header"] = Result{Stdout: fall.ausgabe}
			s := NewSystemWithRunner(f)

			st, err := s.WebServerState(context.Background())
			if err != nil {
				t.Fatalf("ein fehlendes nginx ist kein Fehlerfall, bekam: %v", err)
			}
			if st.Installiert {
				t.Error("ohne Binary darf nginx nicht als installiert gelten")
			}
			if !st.LauscherGeprueft {
				t.Fatal("die Portbelegung wurde ermittelt und muss als geprüft gelten")
			}
			belegt := st.Belegt()
			if len(belegt) == 0 {
				t.Fatalf("%s hält Port 80 und muss als Belegung erscheinen", fall.prozess)
			}
			if belegt[0].Prozess != fall.prozess {
				t.Errorf("Prozessname falsch gelesen: %q", belegt[0].Prozess)
			}
		})
	}
}

// Die wichtigste Prüfung dieser Datei: „ss antwortet nicht" darf nicht
// aussehen wie „auf 80 hört niemand". Ohne LauscherGeprueft wären beide Lagen
// eine leere Liste — und der Installationsknopf erschiene ausgerechnet dann,
// wenn das Panel nichts weiß.
func TestWebServerStateOhneSSGiltAlsUngeprueft(t *testing.T) {
	f := newFakeRunner()
	f.errs["nginx"] = errors.New("nginx ist auf diesem System nicht vorhanden")
	f.errs["ss"] = errors.New("ss ist auf diesem System nicht vorhanden")
	s := NewSystemWithRunner(f)

	st, err := s.WebServerState(context.Background())
	if err != nil {
		t.Fatalf("ein fehlendes ss ist kein Fehlerfall, bekam: %v", err)
	}
	if st.LauscherGeprueft {
		t.Fatal("ohne ss darf die Belegung nicht als geprüft gelten")
	}
	if len(st.Lauscher) != 0 {
		t.Errorf("ohne Auskunft darf keine Belegung behauptet werden: %+v", st.Lauscher)
	}
}

// Ein Kommando außerhalb der Allowlist ist ein Programmierfehler und muss
// durchschlagen — anders als ein Programm, das schlicht fehlt.
func TestWebServerStateMeldetUnerlaubtesKommando(t *testing.T) {
	f := newFakeRunner()
	f.errs["nginx"] = fmt.Errorf("%w: %q", ErrNotAllowed, "nginx")
	s := NewSystemWithRunner(f)

	if _, err := s.WebServerState(context.Background()); !errors.Is(err, ErrNotAllowed) {
		t.Fatalf("ErrNotAllowed muss durchschlagen, bekam: %v", err)
	}
}

// Fehlt das Metapaket, aber eine der Geschmacksrichtungen ist da, muss das
// Paket trotzdem genannt werden. Debian installiert über "nginx" das Paket
// "nginx-core" — ein Panel, das nur nach dem ersten Namen fragt, hielte die
// Herkunft für unbekannt.
func TestWebServerStateFindetDieGeschmacksrichtung(t *testing.T) {
	f := newFakeRunner()
	setzeWebserver(f,
		Result{Stderr: nginxVersionErr},
		Result{ExitCode: 1},
		Result{},
		Result{Stdout: ssLauscherOut},
	)
	f.responses["dpkg-query -W -f=${db:Status-Status} nginx-core"] = Result{Stdout: "installed"}
	s := NewSystemWithRunner(f)

	st, err := s.WebServerState(context.Background())
	if err != nil {
		t.Fatalf("WebServerState: %v", err)
	}
	if st.Paket != "nginx-core" {
		t.Errorf("Paket falsch gelesen: %q", st.Paket)
	}
}

func TestWebServerStateOhneAktivenDienst(t *testing.T) {
	f := newFakeRunner()
	setzeWebserver(f,
		Result{Stderr: nginxVersionErr},
		Result{Stdout: "installed"},
		Result{ExitCode: 3}, // systemctl is-active: "inactive"
		Result{Stdout: ssContainerOut},
	)
	s := NewSystemWithRunner(f)

	st, err := s.WebServerState(context.Background())
	if err != nil {
		t.Fatalf("WebServerState: %v", err)
	}
	if !st.Installiert {
		t.Error("nginx ist da, nur gestartet ist es nicht")
	}
	if st.DienstAktiv {
		t.Error("ein Exit-Code ungleich 0 heißt: der Dienst läuft nicht")
	}
}

func TestParseLauscherLiestNurDieWebports(t *testing.T) {
	l := parseLauscher(ssLauscherOut)
	if len(l) != 3 {
		t.Fatalf("erwartet: 80 zweimal (IPv4 und IPv6) und 443 einmal, bekam %d: %+v", len(l), l)
	}
	// Sortiert nach Port, dann Adresse — sonst hängt die Reihenfolge an der
	// Laune des Kernels und die Auskunft sieht bei jedem Aufruf anders aus.
	if l[0].Port != 80 || l[1].Port != 80 || l[2].Port != 443 {
		t.Errorf("Reihenfolge oder Ports falsch: %+v", l)
	}
	for _, e := range l {
		if e.Prozess != "nginx" {
			t.Errorf("Prozessname falsch gelesen: %+v", e)
		}
		if e.PID != 1234 {
			t.Errorf("PID falsch gelesen: %+v", e)
		}
	}
	// Die IPv6-Zeile ist der eigentliche Prüfstein: "[::]:80" muss am LETZTEN
	// Doppelpunkt getrennt werden.
	if l[0].Adresse != "0.0.0.0" || l[1].Adresse != "[::]" {
		t.Errorf("Adressen falsch getrennt: %q und %q", l[0].Adresse, l[1].Adresse)
	}
}

// Zeilen, die der Parser nicht versteht, überspringt er stumm — sie dürfen die
// Auskunft nicht kippen. Ob die ABFRAGE lief, sagt LauscherGeprueft, und das
// ist die Trennung, auf die es ankommt.
func TestParseLauscherUeberspringtUnverstandenes(t *testing.T) {
	out := "Kopfzeile ohne Sinn\n" +
		"LISTEN 0 511\n" + // zu wenige Felder
		"LISTEN 0 511 0.0.0.0:abc 0.0.0.0:*\n" + // kein Port
		"LISTEN 0 511 kein-doppelpunkt 0.0.0.0:*\n" +
		"LISTEN 0 511 0.0.0.0:80 0.0.0.0:*\n" // gültig, aber ohne Prozessspalte
	l := parseLauscher(out)
	if len(l) != 1 {
		t.Fatalf("genau eine Zeile ist lesbar, bekam %d: %+v", len(l), l)
	}
	if l[0].Port != 80 {
		t.Errorf("Port falsch gelesen: %+v", l[0])
	}
	// Ohne root nennt ss keinen Prozess. Die Belegung gilt trotzdem — wer dort
	// hört, ist unbekannt, DASS dort jemand hört, steht fest. Und weil der
	// Prozess nicht "nginx" ist, zählt die Zeile als fremd; das ist die sichere
	// Richtung des Zweifels.
	if l[0].Prozess != "" {
		t.Errorf("ohne Prozessspalte darf kein Name erfunden werden: %+v", l[0])
	}
	st := WebServerState{Lauscher: l, LauscherGeprueft: true}
	if len(st.Belegt()) != 1 {
		t.Error("ein Lauscher ohne Namen muss als fremd gelten — im Zweifel gegen den Knopf")
	}
}

func TestParseNginxVersion(t *testing.T) {
	for _, fall := range []struct{ in, will string }{
		{nginxVersionErr, "1.24.0"},
		{nginxVersionPurErr, "1.27.3"},
		{"", ""},
		{"irgendetwas anderes", ""},
	} {
		if got := parseNginxVersion(fall.in); got != fall.will {
			t.Errorf("parseNginxVersion(%q) = %q, erwartet %q", fall.in, got, fall.will)
		}
	}
}

func TestWebServerInstallSpieltDasMetapaketEin(t *testing.T) {
	f := newFakeRunner()
	s := NewSystemWithRunner(f)

	if err := s.WebServerInstall(context.Background(), nil); err != nil {
		t.Fatalf("WebServerInstall: %v", err)
	}
	cmd := f.lastCall()
	if cmd.Name != "apt-get" {
		t.Fatalf("erwartet apt-get, bekam %q", cmd.Name)
	}
	zeile := strings.Join(cmd.Args, " ")
	if !strings.HasSuffix(zeile, "-- nginx") {
		t.Errorf("erwartet das Metapaket nginx als letztes Argument: %q", zeile)
	}
	// Der Paketname steht im Quelltext und nicht in einem Formular — es darf
	// keinen Weg geben, hier etwas anderes einzuspielen.
	if strings.Contains(zeile, "nginx.org") || strings.Contains(zeile, "--allow") {
		t.Errorf("die Installation soll schlicht aus den Distributionsquellen kommen: %q", zeile)
	}
}
