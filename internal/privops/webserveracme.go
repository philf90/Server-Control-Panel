package privops

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

// Der Weg für die ACME-Prüfung durch den Webserver hindurch.
//
// Schritt 2 der Stufe 0.6 (docs/18-webserver.md §3). Er behebt einen Fehler,
// den Schritt 1 erst erzeugt hat, und deshalb steht er vor der ersten
// Benutzersite:
//
// Das Panel bindet für HTTP-01 selbst Port 80 (internal/acme/http01.go). Sobald
// es nginx einspielt, gehört der Port nginx — und das Panel kann sein eigenes
// Zertifikat nicht mehr erneuern. Nicht sofort und nicht sichtbar, sondern beim
// nächsten Erneuerungslauf, sechzig Tage später.
//
// Der Ausweg ist der, den certbot seit jeher geht: Nicht neben dem Webserver
// lauschen, sondern DURCH ihn hindurch antworten. Das Panel legt die Token als
// Dateien in ein Verzeichnis, und ein verwaltetes Drop-in sagt nginx, dass es
// /.well-known/acme-challenge/ von dort ausliefern soll. Kein Port, kein
// Wettlauf, und es funktioniert für das Panel und später für jede Site gleich.
//
// Was hier NICHT passiert: Für einen FREMDEN Webserver — Caddy, Apache, ein
// Traefik im Container — wird nichts geschrieben. Das Panel verwaltet dessen
// Konfiguration nicht (E1), und Token in ein Verzeichnis zu legen, das niemand
// ausliefert, wäre schlechter als der heutige Zustand: Die Prüfung schlüge mit
// einer unverständlichen Meldung fehl statt mit der klaren „Port 80 ist
// belegt". Wer einen fremden Webserver betreibt, nimmt DNS-01.

// Die Orte, an denen dieser Schritt schreibt. Als Variablen und nicht als
// Konstanten, damit die Tests sie auf ein Wegwerfverzeichnis umlegen können —
// dasselbe Muster wie cronDDir in cron.go und passwdPath in users.go.
var (
	// acmeWebrootDir ist das Verzeichnis, aus dem der Webserver die Antwort
	// ausliefert.
	//
	// Unter /var/www und NICHT im Datenverzeichnis des Panels: Dort liegt die
	// Datenbank, und das Verzeichnis ist für andere gesperrt. nginx läuft als
	// www-data und käme nicht einmal hinein — ein Webserver, der sich durch das
	// Datenverzeichnis des Panels arbeiten darf, wäre auch die falsche Antwort.
	acmeWebrootDir = "/var/www/asylum-acme"
	// acmeDropinPfad ist die einzige nginx-Datei, die dieser Schritt schreibt.
	acmeDropinPfad = "/etc/nginx/conf.d/asylum-acme.conf"
)

// nginxMarker steht in der ersten Zeile jeder nginx-Datei, die vom Panel
// stammt. Eine Datei ohne ihn wird nie überschrieben — auch nicht an diesem
// Platz. Dieselbe Regel wie bei den Crontabs und den Compose-Stacks.
const nginxMarker = "# von asylum verwaltet — Änderungen werden überschrieben"

// AcmeWebroot richtet den Weg für die HTTP-01-Prüfung durch nginx ein und gibt
// das Verzeichnis zurück, in das die Token gehören.
//
// Die Kette ist die aus docs/16 §7.4, gekürzt auf das, was dieser Schritt
// braucht: schreiben → `nginx -t` → bei Fehler ZURÜCKNEHMEN → neu laden. Die
// vollständige Probe mit Frist und selbsttätigem Rückweg kommt mit dem
// Schreibpfad für Sites (Schritt 5); hier trägt sie nichts bei, weil dieses
// Drop-in keinen Weg zum Panel legt — es beantwortet einen einzigen Pfad, und
// wenn es fehlt, schlägt eine Zertifikatserneuerung fehl, aber niemand ist
// ausgesperrt.
func (s *System) AcmeWebroot(ctx context.Context, domains []string) (string, error) {
	sauber, err := pruefeDomains(domains)
	if err != nil {
		return "", err
	}

	// Das Verzeichnis samt Unterpfad: nginx bildet mit `root` die URI auf den
	// Pfad ab, also muss .well-known/acme-challenge unter der Wurzel liegen.
	// 0755, weil www-data lesen können muss — hier liegen nur Token, die
	// ohnehin öffentlich abgefragt werden.
	ziel := filepath.Join(acmeWebrootDir, ".well-known", "acme-challenge")
	if err := os.MkdirAll(ziel, 0o755); err != nil { //nolint:gosec // muss für den Webserver begehbar sein
		return "", fmt.Errorf("%s: %w", ziel, err)
	}

	vorher, hatte, err := lesbarerVorzustand(acmeDropinPfad)
	if err != nil {
		return "", err
	}
	if hatte && !strings.HasPrefix(vorher, nginxMarker) {
		return "", fmt.Errorf("%s gehört dem Panel nicht (kein Marker in der ersten Zeile) "+
			"und wird nicht überschrieben", acmeDropinPfad)
	}

	// Steht schon genau das da, wird nichts angefasst.
	//
	// Diese Funktion läuft bei jedem Start des Panels und bei jeder Änderung an
	// den TLS-Einstellungen. Ohne diesen Vergleich hieße das: bei jedem
	// Neustart ein Schreibvorgang und ein nginx-Reload, für nichts. Ein Reload
	// ist billig und nicht umsonst — er wirft die Arbeiterprozesse ab, und in
	// einem Betriebsprotokoll steht danach eine Zeile, für die es keinen Grund
	// gibt.
	neu := acmeDropinInhalt(sauber)
	if hatte && vorher == neu {
		return acmeWebrootDir, nil
	}

	if err := nginxAtomarSchreiben(acmeDropinPfad, neu); err != nil {
		return "", err
	}

	// Geprüft wird die GESAMTE Konfiguration und nicht die eine Datei: nginx
	// kennt nur die vollständige, und genau die ist die interessante Frage —
	// unsere Datei kann für sich richtig sein und trotzdem mit einer anderen
	// kollidieren.
	pruef, err := s.run(ctx, Command{Name: "nginx", Args: []string{"-t"}})
	if err != nil {
		zuruecknehmen(acmeDropinPfad, vorher, hatte)
		return "", err
	}
	if pruef.ExitCode != 0 {
		// Der Rückweg vor der Meldung: Eine abgelehnte Datei liegen zu lassen
		// hieße, dass der nächste Reload — von wem auch immer angestoßen — an
		// unserer Datei scheitert. Der Fehler wäre dann unserer und sähe nach
		// einem fremden aus.
		zuruecknehmen(acmeDropinPfad, vorher, hatte)
		return "", fmt.Errorf("nginx hat die Konfiguration abgelehnt, der vorherige Stand "+
			"ist wiederhergestellt: %s", kurzeAusgabe(pruef))
	}

	if err := s.nginxNeuLaden(ctx); err != nil {
		return "", err
	}
	return acmeWebrootDir, nil
}

// nginxNeuLaden lädt die Konfiguration neu.
//
// Über systemd und nicht über `nginx -s reload`: Der Dienstmanager ist die
// Wahrheit über den laufenden Prozess, und ein Reload an ihm vorbei taucht in
// keinem Statusbericht auf.
func (s *System) nginxNeuLaden(ctx context.Context) error {
	res, err := s.run(ctx, Command{
		Name: "systemctl",
		Args: []string{"reload", "--", nginxUnit},
	})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return fmt.Errorf("%s ließ sich nicht neu laden: %s", nginxUnit, kurzeAusgabe(res))
	}
	return nil
}

// acmeDropinInhalt baut die Datei.
//
// Kein `default_server`. Das ist die überlegte Stelle: Ein zweiter
// default_server auf Port 80 lässt `nginx -t` scheitern, sobald ein anderer
// schon einen führt — und auf einem frisch eingespielten nginx tut das die
// Debian-Vorgabe. Ein exakter server_name gewinnt bei nginx ohnehin gegen den
// default_server, also braucht dieses Drop-in ihn nicht und tritt niemandem auf
// die Füße.
//
// `location /` antwortet 404 und leitet NICHT auf https um. Eine Umleitung wäre
// bequem und hier falsch: Sie beträfe jede Anfrage an diesen Namen auf Port 80
// und damit eine Entscheidung, die dem Betreiber gehört. Dieses Drop-in hat
// genau eine Aufgabe.
func acmeDropinInhalt(domains []string) string {
	var b strings.Builder
	b.WriteString(nginxMarker + "\n")
	b.WriteString("#\n")
	b.WriteString("# Beantwortet die ACME-Prüfung (HTTP-01) für das Panel selbst.\n")
	b.WriteString("# Ohne diese Datei kann das Panel sein Zertifikat nicht erneuern,\n")
	b.WriteString("# solange nginx Port 80 hält. Siehe docs/18-webserver.md.\n")
	b.WriteString("server {\n")
	b.WriteString("    listen 80;\n")
	b.WriteString("    listen [::]:80;\n")
	b.WriteString("    server_name " + strings.Join(domains, " ") + ";\n")
	b.WriteString("\n")
	b.WriteString("    location ^~ /.well-known/acme-challenge/ {\n")
	b.WriteString("        root " + acmeWebrootDir + ";\n")
	b.WriteString("        default_type \"text/plain\";\n")
	b.WriteString("    }\n")
	b.WriteString("\n")
	b.WriteString("    location / {\n")
	b.WriteString("        return 404;\n")
	b.WriteString("    }\n")
	b.WriteString("}\n")
	return b.String()
}

// pruefeDomains prüft die Namen, bevor sie in eine Konfigurationsdatei geraten.
//
// Das ist keine Formsache, sondern die Injektionsstelle dieses Schritts: Die
// Namen landen unverändert hinter `server_name`, und ein Name, der ein
// Semikolon oder eine geschweifte Klammer enthält, wäre kein Name mehr, sondern
// eine nginx-Anweisung. `beispiel.de; root /;` in einem Formularfeld ist der
// ganze Angriff.
//
// Geprüft wird gegen die Form eines Hostnamens und nicht gegen eine Sperrliste
// gefährlicher Zeichen — eine Sperrliste vergisst immer eines.
func pruefeDomains(domains []string) ([]string, error) {
	if len(domains) == 0 {
		return nil, fmt.Errorf("keine Domain für das ACME-Drop-in")
	}
	aus := make([]string, 0, len(domains))
	for _, d := range domains {
		name := strings.ToLower(strings.TrimSpace(d))
		if err := pruefeDomain(name); err != nil {
			return nil, err
		}
		aus = append(aus, name)
	}
	return aus, nil
}

// pruefeDomain prüft einen einzelnen Namen.
func pruefeDomain(name string) error {
	switch {
	case name == "":
		return fmt.Errorf("leerer Domainname")
	case len(name) > 253:
		return fmt.Errorf("Domainname länger als 253 Zeichen: %q", kuerzen(name))
	case strings.HasPrefix(name, "*"):
		// Ein Platzhalter ist gültige nginx-Syntax und für HTTP-01 trotzdem
		// sinnlos: Die Prüfung fragt einen konkreten Namen ab. Ihn anzunehmen
		// hieße, ein Drop-in zu schreiben, das nie gebraucht wird.
		return fmt.Errorf("Platzhalter-Domains lassen sich nicht über HTTP-01 prüfen: %q", name)
	}
	for _, teil := range strings.Split(name, ".") {
		if teil == "" {
			return fmt.Errorf("Domainname mit leerem Bestandteil: %q", name)
		}
		if len(teil) > 63 {
			return fmt.Errorf("Bestandteil länger als 63 Zeichen in %q", name)
		}
		if teil[0] == '-' || teil[len(teil)-1] == '-' {
			return fmt.Errorf("Bestandteil beginnt oder endet mit Bindestrich in %q", name)
		}
		for _, r := range teil {
			if (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') || r == '-' {
				continue
			}
			return fmt.Errorf("unzulässiges Zeichen %q im Domainnamen %q", string(r), kuerzen(name))
		}
	}
	return nil
}

// kuerzen hält eine Meldung lesbar. Ein Name, der schon zu lang war, soll die
// Fehlermeldung nicht unlesbar machen.
func kuerzen(s string) string {
	if len(s) <= 60 {
		return s
	}
	return s[:60] + "…"
}

// lesbarerVorzustand liest die vorhandene Datei, falls es sie gibt.
func lesbarerVorzustand(pfad string) (string, bool, error) {
	b, err := os.ReadFile(pfad) //nolint:gosec // fester Pfad aus einer Konstante
	if os.IsNotExist(err) {
		return "", false, nil
	}
	if err != nil {
		return "", false, fmt.Errorf("%s: %w", pfad, err)
	}
	return string(b), true, nil
}

// zuruecknehmen stellt den Stand vor dem Schreiben wieder her.
//
// Bewusst ohne Fehlerrückgabe: Der Aufrufer meldet gerade schon einen Fehler,
// und ein zweiter daneben verdrängte den ersten. Was hier schiefgehen kann,
// steht danach in `nginx -t` — der Aufrufer nennt es.
func zuruecknehmen(pfad, vorher string, hatte bool) {
	if !hatte {
		_ = os.Remove(pfad)
		return
	}
	_ = nginxAtomarSchreiben(pfad, vorher)
}

// nginxAtomarSchreiben schreibt über eine temporäre Datei und benennt um.
//
// rename(2) ist atomar: Ein Abbruch hinterlässt die alte oder die neue Datei,
// nie eine halbe. Bei nginx ist das mehr als Sorgfalt — conf.d wird beim
// nächsten Reload vollständig gelesen, und eine halbe Datei nähme den
// Webserver mit.
//
// Der temporäre Name endet NICHT auf .conf: nginx liest aus conf.d alles, was
// so heißt, und eine halb geschriebene Datei mit dieser Endung wäre für einen
// Reload, der zufällig dazwischenfällt, ein Syntaxfehler.
func nginxAtomarSchreiben(pfad, inhalt string) error {
	tmp := filepath.Join(filepath.Dir(pfad), "."+filepath.Base(pfad)+".asylum.tmp")
	if err := os.WriteFile(tmp, []byte(inhalt), 0o644); err != nil { //nolint:gosec // nginx liest als www-data
		return fmt.Errorf("%s: %w", tmp, err)
	}
	if err := os.Rename(tmp, pfad); err != nil {
		_ = os.Remove(tmp)
		return fmt.Errorf("%s: %w", pfad, err)
	}
	return nil
}
