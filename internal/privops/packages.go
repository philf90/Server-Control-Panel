package privops

import (
	"context"
	"errors"
	"fmt"
	"os"
	"regexp"
	"sort"
	"strings"
)

// PackageRefresh aktualisiert die Paketlisten (apt-get update).
func (s *System) PackageRefresh(ctx context.Context) error {
	res, err := s.run(ctx, Command{
		Name:    "apt-get",
		Args:    []string{"update", "-q"},
		Timeout: 5 * 60 * 1e9, // 5 Minuten
	})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return fmt.Errorf("apt-get update: %s", firstLine(res.Stderr))
	}
	return nil
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
		return fmt.Errorf("apt-get upgrade endete mit Code %d", res.ExitCode)
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
