package privops

import (
	"fmt"
	"net"
	"os"
	"regexp"
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
