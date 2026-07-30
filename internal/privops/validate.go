package privops

import (
	"fmt"
	"net"
	"os"
	"regexp"
	"strconv"
	"strings"
)

// Die Validierung ist die zweite Verteidigungslinie hinter dem Verzicht auf
// eine Shell. Sie hält Werte fern, die zwar keine Kommandoinjektion mehr
// erlauben, aber trotzdem Unsinn anrichten könnten — etwa ein Unit-Name mit
// "../" oder ein Optionsstring, den systemctl als Flag liest.

var (
	// systemd erlaubt in Unit-Namen Buchstaben, Ziffern und : - _ . \ @
	unitPattern = regexp.MustCompile(`^[a-zA-Z0-9:_.\\@-]+\.(service|socket|timer|target|mount|path|slice|scope)$`)
	// Debian-Paketnamen: Kleinbuchstaben, Ziffern, + - . ; mindestens zwei Zeichen.
	packagePattern = regexp.MustCompile(`^[a-z0-9][a-z0-9+.-]+$`)
	// Systembenutzer nach useradd(8)/NAME_REGEX, ohne führenden Bindestrich.
	systemUserPattern = regexp.MustCompile(`^[a-z_][a-z0-9_-]{0,31}$`)
	// Gruppennamen folgen denselben Regeln.
	groupPattern = regexp.MustCompile(`^[a-z_][a-z0-9_-]{0,31}$`)
)

// ValidateUnit prüft einen systemd-Unit-Namen.
func ValidateUnit(unit string) error {
	if unit == "" {
		return fmt.Errorf("kein Unit-Name angegeben")
	}
	if len(unit) > 256 {
		return fmt.Errorf("Unit-Name ist zu lang")
	}
	// Ein führender Bindestrich würde als Option gelesen.
	if strings.HasPrefix(unit, "-") {
		return fmt.Errorf("Unit-Name %q ist unzulässig", unit)
	}
	if !unitPattern.MatchString(unit) {
		return fmt.Errorf("Unit-Name %q ist unzulässig", unit)
	}
	return nil
}

// ValidatePackage prüft einen Debian-Paketnamen.
func ValidatePackage(name string) error {
	if name == "" {
		return fmt.Errorf("kein Paketname angegeben")
	}
	if len(name) > 128 {
		return fmt.Errorf("Paketname ist zu lang")
	}
	if !packagePattern.MatchString(name) {
		return fmt.Errorf("Paketname %q ist unzulässig", name)
	}
	return nil
}

// ValidateSystemUser prüft einen Systembenutzernamen.
func ValidateSystemUser(name string) error {
	if !systemUserPattern.MatchString(name) {
		return fmt.Errorf("Benutzername %q ist unzulässig (Kleinbuchstaben, Ziffern, Bindestrich, Unterstrich; höchstens 32 Zeichen)", name)
	}
	return nil
}

// ValidateGroup prüft einen Gruppennamen.
func ValidateGroup(name string) error {
	if !groupPattern.MatchString(name) {
		return fmt.Errorf("Gruppenname %q ist unzulässig", name)
	}
	return nil
}

// ValidateShell prüft, ob die Shell in /etc/shells steht.
//
// Ohne diese Prüfung ließe sich jeder beliebige Pfad als Login-Shell
// eintragen — inklusive eines vom Panel hochgeladenen Skripts.
func ValidateShell(shell string) error {
	if shell == "" {
		return nil // useradd nimmt dann die Vorgabe
	}
	if shell == "/usr/sbin/nologin" || shell == "/sbin/nologin" || shell == "/bin/false" {
		return nil
	}

	raw, err := os.ReadFile("/etc/shells")
	if err != nil {
		return fmt.Errorf("/etc/shells nicht lesbar: %w", err)
	}
	for _, line := range strings.Split(string(raw), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		if line == shell {
			return nil
		}
	}
	return fmt.Errorf("%q steht nicht in /etc/shells", shell)
}

// ValidatePort prüft eine Portnummer.
func ValidatePort(port int) error {
	if port < 1 || port > 65535 {
		return fmt.Errorf("Port %d liegt außerhalb von 1–65535", port)
	}
	return nil
}

// ValidateProtocol prüft ein Transportprotokoll.
func ValidateProtocol(proto string) error {
	switch proto {
	case "tcp", "udp":
		return nil
	}
	return fmt.Errorf("Protokoll %q ist unzulässig (tcp oder udp)", proto)
}

// ValidateSource prüft eine Quellangabe: leer, IP-Adresse oder CIDR.
func ValidateSource(source string) error {
	if source == "" {
		return nil
	}
	if strings.Contains(source, "/") {
		if _, _, err := net.ParseCIDR(source); err != nil {
			return fmt.Errorf("Quelle %q ist kein gültiges Netz", source)
		}
		return nil
	}
	if net.ParseIP(source) == nil {
		return fmt.Errorf("Quelle %q ist keine gültige Adresse", source)
	}
	return nil
}

// ValidateComment prüft einen Freitext, der in eine Konfigurationsdatei oder
// ein Kommandoargument wandert.
func ValidateComment(comment string) error {
	if len(comment) > 120 {
		return fmt.Errorf("Kommentar ist zu lang (höchstens 120 Zeichen)")
	}
	// Zeilenumbrüche würden Konfigurationsdateien zerlegen, Doppelpunkte die
	// Feldstruktur von /etc/passwd.
	if strings.ContainsAny(comment, "\n\r:") {
		return fmt.Errorf("Kommentar darf keine Zeilenumbrüche oder Doppelpunkte enthalten")
	}
	return nil
}

// ValidateRule prüft eine vollständige Firewall-Regel.
func ValidateRule(r FirewallRule) error {
	if err := ValidatePort(r.Port); err != nil {
		return err
	}
	if err := ValidateProtocol(r.Protocol); err != nil {
		return err
	}
	if err := ValidateSource(r.Source); err != nil {
		return err
	}
	return ValidateComment(r.Comment)
}

// isExecutable sagt, ob der Pfad eine ausführbare Datei ist.
func isExecutable(path string) bool {
	info, err := os.Stat(path)
	if err != nil || info.IsDir() {
		return false
	}
	return info.Mode()&0o111 != 0
}

// -------------------------------------------------- Cron: Zeitplan, Befehl ---

// Die Cron-Familie ist die einzige Stelle im Panel, an der ein freier Befehl
// entsteht — ein Cron-Eintrag IST eine Shell-Zeile, cron gibt sie an /bin/sh.
// Das ist keine Aufweichung des Verzichts auf eine Shell, sondern das Wesen der
// Sache, und es gehört benannt: Wer einen Cron-Eintrag anlegen darf, darf Code
// als den eingetragenen Benutzer ausführen.
//
// Daraus folgt, was die Prüfung hier leisten kann und was nicht. Sie prüft NICHT
// den Befehl auf „gefährliche" Zeichen — Semikolon, Backtick und Pipe sind in
// einer Shell-Zeile erwartbar, und sie herauszufiltern gäbe eine Sicherheit vor,
// die es nicht gibt. Sie prüft das DATEIFORMAT: In einer Crontab ist der
// Zeilenumbruch der Injektionsweg, nicht das Semikolon. Eine Zeile mit einem
// eingebetteten \n wäre ein zweiter Eintrag — mit einem eigenen Benutzerfeld.

var (
	// Der Name eines verwalteten Eintrags wird zum Dateinamen unter
	// /etc/cron.d/. Punkte sind dort verboten: run-parts und cron überspringen
	// Dateien mit Punkt im Namen stillschweigend — der Eintrag stünde da und
	// liefe nie.
	cronNamePattern = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{1,63}$`)
	// Ein einzelnes Zeitfeld: Stern, Zahl, Name, Bereich, Liste, jeweils mit
	// optionaler Schrittweite.
	cronFeldPattern = regexp.MustCompile(`^[0-9a-zA-Z*,/-]+$`)
)

// cronSonderworte sind die Kurzschreibweisen, die cron statt fünf Feldern nimmt.
var cronSonderworte = map[string]bool{
	"@reboot": true, "@yearly": true, "@annually": true, "@monthly": true,
	"@weekly": true, "@daily": true, "@midnight": true, "@hourly": true,
}

// cronNamen sind die erlaubten Wortformen in Monats- und Wochentagsfeldern.
var cronNamen = map[string]int{
	"jan": 1, "feb": 2, "mar": 3, "apr": 4, "may": 5, "jun": 6,
	"jul": 7, "aug": 8, "sep": 9, "oct": 10, "nov": 11, "dec": 12,
	"sun": 0, "mon": 1, "tue": 2, "wed": 3, "thu": 4, "fri": 5, "sat": 6,
}

// ValidateCronName prüft den Namen eines verwalteten Eintrags.
func ValidateCronName(name string) error {
	if name == "" {
		return fmt.Errorf("kein Name angegeben")
	}
	if !cronNamePattern.MatchString(name) {
		return fmt.Errorf("der Name %q ist unzulässig: Kleinbuchstaben, Ziffern, "+
			"Bindestrich und Unterstrich, 2 bis 64 Zeichen, kein Punkt — cron "+
			"überspringt Dateien mit Punkt im Namen stillschweigend", name)
	}
	return nil
}

// ValidateSchedule prüft einen Zeitplan: fünf Felder oder ein Sonderwort.
//
// Geprüft wird gegen die Wertebereiche, nicht nur gegen die Zeichenmenge. Der
// Grund ist die Art, wie cron mit einem Fehler umgeht: Es schreibt „bad minute"
// ins Journal und überspringt die Datei. Der Eintrag stünde also da, sähe richtig
// aus und liefe nie — die schlechteste Sorte Fehler.
func ValidateSchedule(schedule string) error {
	s := strings.TrimSpace(schedule)
	if s == "" {
		return fmt.Errorf("kein Zeitplan angegeben")
	}
	if strings.HasPrefix(s, "@") {
		if cronSonderworte[strings.ToLower(s)] {
			return nil
		}
		return fmt.Errorf("%q ist kein bekanntes Sonderwort (@reboot, @hourly, "+
			"@daily, @weekly, @monthly, @yearly)", s)
	}

	felder := strings.Fields(s)
	if len(felder) != 5 {
		return fmt.Errorf("ein Zeitplan hat fünf Felder (Minute Stunde Tag Monat Wochentag) "+
			"oder ein Sonderwort — %d gefunden", len(felder))
	}
	grenzen := []struct {
		name     string
		min, max int
	}{
		{"Minute", 0, 59},
		{"Stunde", 0, 23},
		{"Tag", 1, 31},
		{"Monat", 1, 12},
		// 7 ist Sonntag wie 0 — beides ist üblich, und wer 7 einträgt, meint
		// Sonntag.
		{"Wochentag", 0, 7},
	}
	for i, feld := range felder {
		if err := pruefeCronFeld(feld, grenzen[i].name, grenzen[i].min, grenzen[i].max); err != nil {
			return err
		}
	}
	return nil
}

// pruefeCronFeld prüft ein einzelnes Zeitfeld samt Listen, Bereichen und
// Schrittweiten.
func pruefeCronFeld(feld, name string, min, max int) error {
	if feld == "" || !cronFeldPattern.MatchString(feld) {
		return fmt.Errorf("das Feld %s (%q) enthält unzulässige Zeichen", name, feld)
	}
	for teil := range strings.SplitSeq(feld, ",") {
		if teil == "" {
			return fmt.Errorf("das Feld %s (%q) hat einen leeren Listeneintrag", name, feld)
		}
		// Schrittweite: "*/5", "0-30/2".
		wert := teil
		if vor, nach, gefunden := strings.Cut(teil, "/"); gefunden {
			wert = vor
			schritt, err := strconv.Atoi(nach)
			if err != nil || schritt < 1 || schritt > max {
				return fmt.Errorf("das Feld %s (%q) hat eine unzulässige Schrittweite", name, teil)
			}
		}
		if wert == "*" {
			continue
		}
		// Bereich: "1-5". Der Bindestrich kann auch in einem Namen stehen
		// ("mon-fri"), deshalb wird beide Seiten einzeln aufgelöst.
		if von, bis, gefunden := strings.Cut(wert, "-"); gefunden {
			a, err := cronZahl(von, name, min, max)
			if err != nil {
				return err
			}
			b, err := cronZahl(bis, name, min, max)
			if err != nil {
				return err
			}
			// Ein rückwärts laufender Bereich ist kein Fehler von cron, sondern
			// eine leere Menge: Der Eintrag liefe nie.
			if a > b {
				return fmt.Errorf("das Feld %s (%q) läuft rückwärts — der Eintrag käme nie dran",
					name, teil)
			}
			continue
		}
		if _, err := cronZahl(wert, name, min, max); err != nil {
			return err
		}
	}
	return nil
}

// cronZahl löst eine Zahl oder einen Namen (jan, mon) auf und prüft den Bereich.
func cronZahl(s, feld string, min, max int) (int, error) {
	if n, ok := cronNamen[strings.ToLower(s)]; ok {
		// Ein Monatsname im Minutenfeld ist ein Tippfehler und kein Zeitplan.
		if max > 12 {
			return 0, fmt.Errorf("das Feld %s nimmt keine Namen (%q)", feld, s)
		}
		return n, nil
	}
	n, err := strconv.Atoi(s)
	if err != nil {
		return 0, fmt.Errorf("das Feld %s (%q) ist keine Zahl", feld, s)
	}
	if n < min || n > max {
		return 0, fmt.Errorf("das Feld %s (%q) liegt außerhalb von %d–%d", feld, s, min, max)
	}
	return n, nil
}

// ValidateCronCommand prüft den Befehl gegen das DATEIFORMAT einer Crontab.
//
// Was hier NICHT geprüft wird, ist der Inhalt: Semikolon, Pipe, Backtick und
// Umleitungen gehören zu einer Shell-Zeile, und sie zu verbieten hieße, eine
// Sicherheit vorzugeben, die es nicht gibt — cron gibt die Zeile an /bin/sh.
// Wer einen Eintrag anlegen darf, führt Code aus; die Schranke davor ist die
// Rolle und die Rückfrage, nicht ein Zeichenfilter.
//
// Geprüft werden die drei Dinge, die das FORMAT brechen:
//
//   - Zeilenumbruch und Wagenrücklauf: Sie erzeugen einen zweiten Eintrag, und
//     der könnte ein eigenes Benutzerfeld tragen. Das ist der einzige echte
//     Injektionsweg in eine Crontab.
//   - Sonstige Steuerzeichen: Sie machen die Datei für einen Menschen unlesbar
//     und für cron unvorhersagbar.
//   - Ein unmaskiertes Prozentzeichen: In einer Crontab beendet es den Befehl,
//     der Rest wird dem Programm als Eingabe zugeführt. Wer `date +%d` schreibt,
//     meint das nicht — deshalb weist die Prüfung darauf hin, statt es
//     stillschweigend zu ändern.
func ValidateCronCommand(cmd string) error {
	s := strings.TrimSpace(cmd)
	if s == "" {
		return fmt.Errorf("kein Befehl angegeben")
	}
	if len(s) > 1024 {
		return fmt.Errorf("der Befehl ist länger als 1024 Zeichen — was so lang ist, "+
			"gehört in ein Skript, und in den Eintrag gehört dessen Pfad (%d Zeichen)", len(s))
	}
	for _, r := range s {
		switch {
		case r == '\n' || r == '\r':
			return fmt.Errorf("der Befehl enthält einen Zeilenumbruch — in einer Crontab " +
				"wäre das ein zweiter Eintrag mit eigenem Benutzerfeld")
		case r < 0x20 || r == 0x7f:
			return fmt.Errorf("der Befehl enthält ein Steuerzeichen (%U)", r)
		}
	}
	if unmaskiertesProzent(s) {
		return fmt.Errorf("der Befehl enthält ein unmaskiertes %%: In einer Crontab " +
			"beendet es den Befehl, alles danach wird dem Programm als Eingabe " +
			`zugeführt. Gemeint ist vermutlich \%%`)
	}
	return nil
}

// ValidateCronComment prüft die Beschreibung eines Eintrags.
//
// Sie ist strenger als eine Kommentarzeile sein müsste und lockerer als ein
// Befehl: Ein Zeilenumbruch muss weg, weil die zweite Zeile ohne Kommentarzeichen
// stünde und damit ein Eintrag wäre. Ein Prozentzeichen darf bleiben — in einer
// Kommentarzeile ist es ein Prozentzeichen, und „90 % Auslastung" ist ein
// vernünftiger Satz für die Beschreibung eines Zeitplans.
func ValidateCronComment(kommentar string) error {
	s := strings.TrimSpace(kommentar)
	if s == "" {
		return nil
	}
	if len(s) > 200 {
		return fmt.Errorf("die Beschreibung ist länger als 200 Zeichen (%d)", len(s))
	}
	for _, r := range s {
		switch {
		case r == '\n' || r == '\r':
			return fmt.Errorf("die Beschreibung enthält einen Zeilenumbruch — die zweite " +
				"Zeile stünde ohne Kommentarzeichen in der Crontab und wäre damit ein Eintrag")
		case r < 0x20 || r == 0x7f:
			return fmt.Errorf("die Beschreibung enthält ein Steuerzeichen (%U)", r)
		}
	}
	return nil
}

// unmaskiertesProzent sagt, ob ein % ohne vorangestellten Rückstrich vorkommt.
func unmaskiertesProzent(s string) bool {
	for i := 0; i < len(s); i++ {
		if s[i] != '%' {
			continue
		}
		if i == 0 || s[i-1] != '\\' {
			return true
		}
	}
	return false
}
