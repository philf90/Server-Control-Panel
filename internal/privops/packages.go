package privops

import (
	"context"
	"errors"
	"fmt"
	"os"
	"regexp"
	"sort"
	"strings"
	"time"
)

// PackageRefresh aktualisiert die Paketlisten (apt-get update) und schreibt die
// Ausgabe live weiter.
//
// „-q" und nicht „-qq": Ohne Fortschrittsbalken, aber mit den Zeilen, die etwas
// sagen — welche Quelle geantwortet hat, welche nicht und warum. Genau die
// stehen im Panel als Konsolenauszug; verworfen wurden sie bis hierher.
//
// Ein Exit-Code ungleich null bedeutet nicht zwangsläufig einen Fehlschlag:
// Klemmt eine von sieben Quellen, meldet apt 100, und die sechs übrigen sind
// trotzdem aktuell. Dieser Fall kommt als Ergebnis zurück (Partial), nicht als
// Fehler — siehe PackageRefreshResult.
func (s *System) PackageRefresh(ctx context.Context, stream LineWriter) (PackageRefreshResult, error) {
	res, err := s.run(ctx, Command{
		Name:    "apt-get",
		Args:    []string{"update", "-q"},
		Timeout: 5 * time.Minute,
		Stream:  stream,
	})
	if err != nil {
		return PackageRefreshResult{}, err
	}

	// Die Err-Zeilen und ihre Begründungen stehen auf stdout, die E-Zeilen auf
	// stderr. Ausgewertet wird stdout; stderr trägt die Meldung für den Fall,
	// dass gar nichts geglückt ist.
	out := parseAptUpdate(res.Stdout)
	switch {
	case res.ExitCode == 0:
		return out, nil
	case out.Partial():
		return out, nil
	default:
		meldung := firstLine(res.Stderr)
		if meldung == "" {
			meldung = fmt.Sprintf("Code %d", res.ExitCode)
		}
		return out, fmt.Errorf("apt-get update: %s", meldung)
	}
}

// PackageUpgradable listet die aktualisierbaren Pakete.
//
// Grundlage ist ein Trockenlauf von apt-get upgrade. Dessen "Inst"-Zeilen
// nennen alte Version, neue Version und Herkunft in einem Durchgang — anders
// als "apt list --upgradable", das für die Herkunft je Paket eine weitere
// Abfrage bräuchte.
func (s *System) PackageUpgradable(ctx context.Context) ([]Package, error) {
	res, err := s.run(ctx, Command{
		Name:    "apt-get",
		Args:    []string{"--simulate", "--quiet", "upgrade"},
		Timeout: 2 * defaultTimeout,
	})
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("apt-get upgrade --simulate: %s", firstLine(res.Stderr))
	}
	return parseAptSimulate(res.Stdout), nil
}

// PackageUpgrade spielt Updates ein und schreibt die Ausgabe live weiter.
func (s *System) PackageUpgrade(ctx context.Context, opts UpgradeOptions, stream LineWriter) error {
	args := []string{
		"upgrade", "--yes",
		// Bei Konflikten in Konfigurationsdateien die bestehende Fassung
		// behalten. Ein Panel darf keine Datei überschreiben, die jemand von
		// Hand angepasst hat, und es kann niemanden interaktiv fragen.
		"-o", "Dpkg::Options::=--force-confdef",
		"-o", "Dpkg::Options::=--force-confold",
	}

	packages := opts.Packages
	if opts.OnlySecurity {
		upgradable, err := s.PackageUpgradable(ctx)
		if err != nil {
			return err
		}
		packages = nil
		for _, p := range upgradable {
			if p.Security {
				packages = append(packages, p.Name)
			}
		}
		if len(packages) == 0 {
			return errors.New("keine Sicherheitsupdates verfügbar")
		}
	}

	for _, name := range packages {
		if err := ValidatePackage(name); err != nil {
			return err
		}
	}
	if len(packages) > 0 {
		// "--only-upgrade" verhindert, dass über diesen Weg neue Pakete
		// installiert werden können.
		args = append(args, "--only-upgrade")
		args = append(args, "--")
		args = append(args, packages...)
	}

	res, err := s.run(ctx, Command{
		Name:    "apt-get",
		Args:    args,
		Timeout: longTimeout,
		Stream:  stream,
	})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return aptFehler(fmt.Sprintf("apt-get upgrade endete mit Code %d", res.ExitCode), res)
	}
	return nil
}

// aptInstall spielt genau ein benanntes Paket ein.
//
// Der Gegenpol zu PackageUpgrade: Dort trägt der Aufruf "--only-upgrade" und
// kann nichts Neues ins System bringen, weil der Paketname aus der Anfrage
// kommt. Hier kommt er aus dem Quelltext — die Aufrufer sind FirewallInstall
// und DockerInstall, und beide kennen ihr Paket namentlich. Deshalb ist diese
// Funktion privat und nimmt bewusst keine Liste entgegen: Eine Schleife über
// Paketnamen wäre der erste Schritt zurück zu "installiere Paket X".
//
// Die beiden Dpkg-Optionen halten den Lauf unbeaufsichtigt: Sie beantworten
// eine Rückfrage nach einer geänderten Konfigurationsdatei mit "die vorhandene
// behalten", statt auf eine Eingabe zu warten, die nie kommt.
func (s *System) aptInstall(ctx context.Context, stream LineWriter, paket string) error {
	res, err := s.run(ctx, Command{
		Name: "apt-get",
		Args: []string{
			"install", "--yes",
			"-o", "Dpkg::Options::=--force-confdef",
			"-o", "Dpkg::Options::=--force-confold",
			"--", paket,
		},
		Timeout: longTimeout,
		Stream:  stream,
	})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return aptFehler(fmt.Sprintf("apt-get install %s endete mit Code %d", paket, res.ExitCode), res)
	}
	return nil
}

// Reboot startet den Rechner über systemd neu. `systemctl reboot` meldet den
// Wunsch an PID 1 und kehrt zurück; das eigentliche Herunterfahren übernimmt
// systemd. Anders als beim Selbstupdate ist hier keine eigene Unit nötig — ein
// Neustart beendet ohnehin alles, es gibt nichts, das ihn überdauern müsste.
func (s *System) Reboot(ctx context.Context) error {
	res, err := s.run(ctx, Command{
		Name:    "systemctl",
		Args:    []string{"reboot"},
		Timeout: defaultTimeout,
	})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		msg := firstLine(res.Stderr)
		if msg == "" {
			msg = firstLine(res.Stdout)
		}
		return fmt.Errorf("systemctl reboot: %s", msg)
	}
	return nil
}

// RebootRequired sagt, ob ein Neustart aussteht.
func (s *System) RebootRequired(ctx context.Context) (RebootState, error) {
	_ = ctx

	// Fehlt die Markierung, steht kein Neustart an — das ist der Normalfall
	// und kein Fehler.
	if _, err := os.Stat("/var/run/reboot-required"); err != nil {
		return RebootState{}, nil //nolint:nilerr // fehlende Markierung heißt: kein Neustart nötig
	}
	state := RebootState{Required: true}

	if raw, err := os.ReadFile("/var/run/reboot-required.pkgs"); err == nil {
		seen := make(map[string]bool)
		for _, line := range strings.Split(string(raw), "\n") {
			name := strings.TrimSpace(line)
			if name == "" || seen[name] {
				continue
			}
			seen[name] = true
			state.Packages = append(state.Packages, name)
		}
		sort.Strings(state.Packages)
	}
	return state, nil
}

// ------------------------------------------------------------------ Parser ---

// Die Zeilen von apt-get update. LC_ALL=C hält sie in der Form, die hier
// erwartet wird (siehe exec.go):
//
//	Hit:3 http://archive.ubuntu.com/ubuntu noble InRelease
//	Get:4 http://archive.ubuntu.com/ubuntu noble-updates InRelease [126 kB]
//	Err:1 https://ppa.launchpadcontent.net/deadsnakes/ppa/ubuntu noble InRelease
//	  403  Forbidden [IP: 185.125.189.187 443]
//
// Der Grund einer gescheiterten Quelle steht eingerückt in der Folgezeile.
// „Ign:" zählt weder als Antwort noch als Fehler — apt übergeht die Quelle
// bewusst.
var (
	aptHolPattern = regexp.MustCompile(`^(?:Hit|Get):\d+\s+\S`)
	aptErrPattern = regexp.MustCompile(`^Err:\d+\s+(\S.*)$`)
)

func parseAptUpdate(out string) PackageRefreshResult {
	var res PackageRefreshResult

	lines := strings.Split(out, "\n")
	for i, line := range lines {
		line = strings.TrimRight(line, "\r")
		switch {
		case aptHolPattern.MatchString(line):
			res.Reached++
		case aptErrPattern.MatchString(line):
			fehler := SourceFailure{
				Source: strings.TrimSpace(aptErrPattern.FindStringSubmatch(line)[1]),
			}
			if i+1 < len(lines) {
				naechste := strings.TrimRight(lines[i+1], "\r")
				if strings.HasPrefix(naechste, " ") && strings.TrimSpace(naechste) != "" {
					// Mehrfache Leerzeichen zu einem: apt richtet die Begründung
					// aus ("403  Forbidden"), in einem Satz stört das.
					fehler.Reason = strings.Join(strings.Fields(naechste), " ")
				}
			}
			res.Failed = append(res.Failed, fehler)
		}
	}
	return res
}

// Beispielzeile:
//
//	Inst coreutils [9.4-3ubuntu6.1] (9.4-3ubuntu6.2 Ubuntu:24.04/noble-updates [amd64])
//
// Die Klammern sind die Struktur: eckig die installierte Version, rund die
// neue Version samt Herkunft und Architektur.
var aptInstPattern = regexp.MustCompile(
	`^Inst\s+(\S+)\s+(?:\[([^\]]*)\]\s+)?\(([^\s]+)\s+(.*?)(?:\s+\[([^\]]+)\])?\)\s*$`)

func parseAptSimulate(out string) []Package {
	var packages []Package

	for _, line := range strings.Split(out, "\n") {
		m := aptInstPattern.FindStringSubmatch(strings.TrimSpace(line))
		if m == nil {
			continue
		}
		origin := strings.TrimSpace(m[4])
		packages = append(packages, Package{
			Name:           m[1],
			CurrentVersion: m[2],
			NewVersion:     m[3],
			Origin:         origin,
			Security:       isSecurityOrigin(origin),
			Architecture:   m[5],
		})
	}

	sort.Slice(packages, func(i, j int) bool {
		// Sicherheitsupdates zuerst — sie sind der Grund, warum jemand die
		// Seite überhaupt aufruft.
		if packages[i].Security != packages[j].Security {
			return packages[i].Security
		}
		return packages[i].Name < packages[j].Name
	})
	return packages
}

// isSecurityOrigin erkennt Sicherheitsquellen von Debian und Ubuntu.
func isSecurityOrigin(origin string) bool {
	lower := strings.ToLower(origin)
	return strings.Contains(lower, "-security") || strings.Contains(lower, "debian-security")
}
