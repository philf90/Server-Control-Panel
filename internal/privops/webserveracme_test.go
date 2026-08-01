package privops

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// acmeVerzeichnisse legt die Schreibziele auf ein Wegwerfverzeichnis um.
//
// Echte Dateien und keine Attrappe: Die Arbeit dieser Familie IST das Schreiben
// — atomarer Tausch, Marker, Rücknahme nach einer abgelehnten Prüfung. Ein Test
// gegen eine Attrappe berührte genau davon nichts.
func acmeVerzeichnisse(t *testing.T) (dropin string) {
	t.Helper()
	wurzel := t.TempDir()
	altWebroot, altDropin := acmeWebrootDir, acmeDropinPfad

	acmeWebrootDir = filepath.Join(wurzel, "www")
	acmeDropinPfad = filepath.Join(wurzel, "conf.d", "asylum-acme.conf")
	if err := os.MkdirAll(filepath.Dir(acmeDropinPfad), 0o755); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { acmeWebrootDir, acmeDropinPfad = altWebroot, altDropin })
	return acmeDropinPfad
}

// nginxOK legt die Antworten für einen glückenden Durchlauf hin.
func nginxOK(f *fakeRunner) {
	f.responses["nginx -t"] = Result{Stderr: "nginx: configuration file /etc/nginx/nginx.conf test is successful\n"}
	f.responses["systemctl reload -- nginx.service"] = Result{}
}

func TestAcmeWebrootSchreibtUndLaedtNeu(t *testing.T) {
	dropin := acmeVerzeichnisse(t)
	f := newFakeRunner()
	nginxOK(f)
	s := NewSystemWithRunner(f)

	dir, err := s.AcmeWebroot(context.Background(), []string{"panel.example.com"})
	if err != nil {
		t.Fatalf("AcmeWebroot: %v", err)
	}
	if dir != acmeWebrootDir {
		t.Errorf("Verzeichnis = %q, erwartet %q", dir, acmeWebrootDir)
	}

	// Das Verzeichnis, in das der Löser schreibt, muss der URI entsprechen:
	// nginx bildet mit `root` die Adresse auf den Pfad ab.
	unter := filepath.Join(acmeWebrootDir, ".well-known", "acme-challenge")
	if fi, err := os.Stat(unter); err != nil || !fi.IsDir() {
		t.Errorf("%s fehlt: %v", unter, err)
	}

	b, err := os.ReadFile(dropin)
	if err != nil {
		t.Fatalf("Drop-in: %v", err)
	}
	inhalt := string(b)
	if !strings.HasPrefix(inhalt, nginxMarker) {
		t.Error("dem Drop-in fehlt der Marker in der ersten Zeile — ohne ihn erkennt " +
			"das Panel beim nächsten Mal seine eigene Datei nicht wieder")
	}
	if !strings.Contains(inhalt, "server_name panel.example.com;") {
		t.Errorf("der Name steht nicht in der Datei:\n%s", inhalt)
	}
	if !strings.Contains(inhalt, "root "+acmeWebrootDir+";") {
		t.Errorf("die Wurzel steht nicht in der Datei:\n%s", inhalt)
	}
	// Kein default_server: Auf einem frisch eingespielten nginx führt die
	// Debian-Vorgabe bereits einen, und ein zweiter lässt `nginx -t` scheitern.
	if strings.Contains(inhalt, "default_server") {
		t.Errorf("das Drop-in beansprucht den default_server:\n%s", inhalt)
	}

	// Die Reihenfolge ist die Zusage: erst prüfen, dann neu laden.
	var pruef, reload int
	for i, c := range f.calls {
		switch c.Name {
		case "nginx":
			pruef = i + 1
		case "systemctl":
			reload = i + 1
		}
	}
	if pruef == 0 || reload == 0 {
		t.Fatalf("Prüfung oder Reload fehlen: %+v", f.calls)
	}
	if pruef > reload {
		t.Error("neu geladen wurde vor der Prüfung — dann prüft sie nichts mehr")
	}

	// Keine Reste: Die temporäre Datei darf nicht liegen bleiben, und sie darf
	// nicht auf .conf enden, sonst liest nginx sie mit.
	eintraege, _ := os.ReadDir(filepath.Dir(dropin))
	for _, e := range eintraege {
		if e.Name() != filepath.Base(dropin) {
			t.Errorf("im Verzeichnis liegt ein Rest: %q", e.Name())
		}
	}
}

// Der Kern der Kette: Lehnt nginx die Konfiguration ab, ist der vorherige Stand
// wieder da. Eine abgelehnte Datei liegen zu lassen hieße, dass der nächste
// Reload — von wem auch immer angestoßen — an unserer Datei scheitert.
func TestAcmeWebrootNimmtBeiAbgelehnterPruefungZurueck(t *testing.T) {
	dropin := acmeVerzeichnisse(t)
	f := newFakeRunner()
	f.responses["nginx -t"] = Result{
		ExitCode: 1,
		Stderr:   "nginx: [emerg] a duplicate default server for 0.0.0.0:80 in /etc/nginx/conf.d/asylum-acme.conf:8",
	}
	s := NewSystemWithRunner(f)

	_, err := s.AcmeWebroot(context.Background(), []string{"panel.example.com"})
	if err == nil {
		t.Fatal("eine abgelehnte Konfiguration muss ein Fehler sein")
	}
	if !strings.Contains(err.Error(), "duplicate default server") {
		t.Errorf("die Meldung von nginx fehlt in der Antwort: %v", err)
	}
	if _, err := os.Stat(dropin); !os.IsNotExist(err) {
		t.Error("die abgelehnte Datei liegt noch da")
	}
	// Und neu geladen wurde nichts.
	for _, c := range f.calls {
		if c.Name == "systemctl" {
			t.Error("nach einer abgelehnten Prüfung darf nicht neu geladen werden")
		}
	}
}

// Gab es die Datei vorher schon — etwa mit einem älteren Namen —, wird ihr
// Inhalt wiederhergestellt und nicht bloß gelöscht.
func TestAcmeWebrootStelltDenVorherigenInhaltWiederHer(t *testing.T) {
	dropin := acmeVerzeichnisse(t)
	alt := nginxMarker + "\nserver { server_name alt.example.com; }\n"
	if err := os.WriteFile(dropin, []byte(alt), 0o644); err != nil {
		t.Fatal(err)
	}

	f := newFakeRunner()
	f.responses["nginx -t"] = Result{ExitCode: 1, Stderr: "nginx: [emerg] irgendwas"}
	s := NewSystemWithRunner(f)

	if _, err := s.AcmeWebroot(context.Background(), []string{"neu.example.com"}); err == nil {
		t.Fatal("eine abgelehnte Konfiguration muss ein Fehler sein")
	}
	b, err := os.ReadFile(dropin)
	if err != nil {
		t.Fatalf("die Datei ist weg: %v", err)
	}
	if string(b) != alt {
		t.Errorf("der vorherige Inhalt kam nicht zurück:\n%s", b)
	}
}

// Eine Datei ohne Marker gehört dem Panel nicht — auch nicht an diesem Platz.
// Dieselbe Regel wie bei Crontabs und Compose-Stacks.
func TestAcmeWebrootFasstFremdeDateiNichtAn(t *testing.T) {
	dropin := acmeVerzeichnisse(t)
	fremd := "server { listen 80; server_name von-hand.example.com; }\n"
	if err := os.WriteFile(dropin, []byte(fremd), 0o644); err != nil {
		t.Fatal(err)
	}

	f := newFakeRunner()
	nginxOK(f)
	s := NewSystemWithRunner(f)

	_, err := s.AcmeWebroot(context.Background(), []string{"panel.example.com"})
	if err == nil {
		t.Fatal("eine fremde Datei darf nicht überschrieben werden")
	}
	b, _ := os.ReadFile(dropin)
	if string(b) != fremd {
		t.Errorf("die fremde Datei wurde verändert:\n%s", b)
	}
	if len(f.calls) != 0 {
		t.Errorf("es darf kein Kommando gelaufen sein: %+v", f.calls)
	}
}

// Die Injektionsstelle dieses Schritts. Die Namen landen unverändert hinter
// `server_name`; ein Semikolon darin wäre kein Name mehr, sondern eine
// nginx-Anweisung. Geprüft wird gegen die FORM eines Hostnamens und nicht gegen
// eine Sperrliste — eine Sperrliste vergisst immer ein Zeichen.
func TestAcmeWebrootLehntGebasteltenNamenAb(t *testing.T) {
	for _, name := range []string{
		"beispiel.de; root /",
		"beispiel.de }\nserver { listen 80 default_server",
		"beispiel.de #kommentar",
		"beispiel.de\tzweiter.de",
		"beispiel.de'",
		"$hostname",
		"../../etc/passwd",
		"*.beispiel.de",
		"-beispiel.de",
		"beispiel..de",
		"",
	} {
		t.Run(name, func(t *testing.T) {
			dropin := acmeVerzeichnisse(t)
			f := newFakeRunner()
			nginxOK(f)
			s := NewSystemWithRunner(f)

			if _, err := s.AcmeWebroot(context.Background(), []string{name}); err == nil {
				b, _ := os.ReadFile(dropin)
				t.Fatalf("der Name %q wurde angenommen, geschrieben wurde:\n%s", name, b)
			}
			// Und es darf nichts geschrieben und nichts gelaufen sein.
			if _, err := os.Stat(dropin); !os.IsNotExist(err) {
				t.Error("trotz abgelehntem Namen wurde eine Datei geschrieben")
			}
			if len(f.calls) != 0 {
				t.Errorf("es darf kein Kommando gelaufen sein: %+v", f.calls)
			}
		})
	}
}

func TestAcmeWebrootNimmtMehrereNamen(t *testing.T) {
	dropin := acmeVerzeichnisse(t)
	f := newFakeRunner()
	nginxOK(f)
	s := NewSystemWithRunner(f)

	// Großschreibung ist in DNS bedeutungslos und in einer Konfigurationsdatei
	// verwirrend. Sie wird vereinheitlicht, nicht abgelehnt.
	if _, err := s.AcmeWebroot(context.Background(),
		[]string{"Panel.example.com", "www.example.com"}); err != nil {
		t.Fatalf("AcmeWebroot: %v", err)
	}
	b, _ := os.ReadFile(dropin)
	if !strings.Contains(string(b), "server_name panel.example.com www.example.com;") {
		t.Errorf("die Namen stehen nicht wie erwartet in der Datei:\n%s", b)
	}
}

// Der zweite Aufruf mit denselben Namen darf nichts tun. Diese Funktion läuft
// bei jedem Start des Panels; ohne den Vergleich stünde in jedem
// Betriebsprotokoll ein nginx-Reload, für den es keinen Grund gibt.
func TestAcmeWebrootLaedtOhneAenderungNichtNeu(t *testing.T) {
	acmeVerzeichnisse(t)
	f := newFakeRunner()
	nginxOK(f)
	s := NewSystemWithRunner(f)
	ctx := context.Background()

	if _, err := s.AcmeWebroot(ctx, []string{"panel.example.com"}); err != nil {
		t.Fatalf("erster Aufruf: %v", err)
	}
	ersteRunde := len(f.calls)
	if ersteRunde == 0 {
		t.Fatal("der erste Aufruf muss schreiben und neu laden")
	}

	if _, err := s.AcmeWebroot(ctx, []string{"panel.example.com"}); err != nil {
		t.Fatalf("zweiter Aufruf: %v", err)
	}
	if len(f.calls) != ersteRunde {
		t.Errorf("der zweite Aufruf hat %d weitere Kommandos ausgelöst: %+v",
			len(f.calls)-ersteRunde, f.calls[ersteRunde:])
	}

	// Ändert sich aber der Name, wird sehr wohl geschrieben — sonst bliebe ein
	// Drop-in stehen, das die Prüfung für den neuen Namen nie beantwortet.
	if _, err := s.AcmeWebroot(ctx, []string{"neu.example.com"}); err != nil {
		t.Fatalf("dritter Aufruf: %v", err)
	}
	if len(f.calls) == ersteRunde {
		t.Error("ein geänderter Name muss geschrieben und neu geladen werden")
	}
}

func TestAcmeWebrootOhneDomainIstEinFehler(t *testing.T) {
	acmeVerzeichnisse(t)
	f := newFakeRunner()
	s := NewSystemWithRunner(f)

	if _, err := s.AcmeWebroot(context.Background(), nil); err == nil {
		t.Fatal("ohne Domain gibt es nichts zu schreiben")
	}
	if len(f.calls) != 0 {
		t.Errorf("es darf kein Kommando gelaufen sein: %+v", f.calls)
	}
}

// nginx -t gehört in dieselbe Zuordnung wie sshd -t und nft -c: eine feste
// Liste von Pfaden, keine Heuristik.
func TestConfigCheckKenntNginx(t *testing.T) {
	for _, pfad := range []string{
		"/etc/nginx/nginx.conf",
		"/etc/nginx/conf.d/asylum-acme.conf",
		"/etc/nginx/sites-enabled/default",
		"/etc/nginx/sites-available/beispiel",
	} {
		if got := ConfigCheckTool(pfad); got != "nginx -t" {
			t.Errorf("ConfigCheckTool(%q) = %q, erwartet nginx -t", pfad, got)
		}
	}
	// Und nichts daneben: /etc/nginxwas-anderes ist keine nginx-Konfiguration.
	for _, pfad := range []string{"/etc/nginx-fremd/datei.conf", "/srv/nginx.conf"} {
		if got := ConfigCheckTool(pfad); got != "" {
			t.Errorf("ConfigCheckTool(%q) = %q, erwartet keine Prüfung", pfad, got)
		}
	}
}

func TestConfigCheckMeldetNginxfehler(t *testing.T) {
	f := newFakeRunner()
	f.responses["nginx -t"] = Result{
		ExitCode: 1,
		Stderr:   "nginx: [emerg] unknown directive \"srver_name\" in /etc/nginx/conf.d/x.conf:3",
	}
	s := NewSystemWithRunner(f)

	res, err := s.ConfigCheck(context.Background(), "/etc/nginx/conf.d/x.conf")
	if err != nil {
		t.Fatalf("ConfigCheck: %v", err)
	}
	if !res.Checked {
		t.Fatal("für nginx gibt es ein Prüfprogramm — die Datei gilt als geprüft")
	}
	if res.OK {
		t.Error("eine abgelehnte Konfiguration darf nicht als in Ordnung gelten")
	}
	if !strings.Contains(res.Output, "srver_name") {
		t.Errorf("die Meldung von nginx fehlt: %q", res.Output)
	}
}
