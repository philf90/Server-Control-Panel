package privops

import (
	"context"
	"fmt"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

// Das Selbstupdate ist der einzige Vorgang, der den eigenen Prozess beendet.
// Liefe er in der Kontrollgruppe des Dienstes, würde systemd ihn beim Neustart
// mitnehmen — und zwar genau zwischen dem Austausch des Binaries und der
// Bereitschaftsprüfung. Zurück bliebe eine ungeprüfte neue Fassung ohne
// jemanden, der sie im Zweifel wieder zurücknimmt.
//
// Deshalb startet das Panel den Vorgang über systemd-run als eigene
// Transient-Unit. Sie hängt an keiner anderen Unit und überlebt den Neustart
// des Panels.

// systemdRunTimeout begrenzt nur das Absetzen des Auftrags, nicht seine Dauer.
const systemdRunTimeout = 15 * time.Second

// unitNamePattern hält den Namen der Transient-Unit auf harmlosen Zeichen.
var unitNamePattern = regexp.MustCompile(`^[a-z0-9][a-z0-9-]{0,63}$`)

// SelfUpdateSpec beschreibt einen anzustoßenden Update-Lauf.
type SelfUpdateSpec struct {
	// Binary ist der absolute Pfad des laufenden Programms.
	Binary string
	// Unit ist der Name der Transient-Unit ohne Endung.
	Unit string
	// Channel ist "stable" oder "beta".
	Channel string
	// Version ist die Zielfassung; leer bedeutet: die neueste des Kanals.
	Version string
	// LogFile nimmt die Ausgabe auf, damit das Panel sie nach dem eigenen
	// Neustart wieder anzeigen kann.
	LogFile string
	// Rollback kehrt zur gesicherten Fassung zurück, statt zu aktualisieren.
	Rollback bool
}

// Erlaubte Kanäle. Die Liste steht hier noch einmal, weil privops nicht von
// internal/update abhängen soll — die Abhängigkeit liefe sonst im Kreis.
var selfUpdateChannels = map[string]bool{"stable": true, "beta": true}

// versionArgPattern lässt nur Zeichen zu, die in einer SemVer vorkommen.
var versionArgPattern = regexp.MustCompile(`^[0-9A-Za-z.+-]{1,64}$`)

func (s SelfUpdateSpec) validate() error {
	if !filepath.IsAbs(s.Binary) {
		return fmt.Errorf("der Pfad zum Programm muss absolut sein, nicht %q", s.Binary)
	}
	if !unitNamePattern.MatchString(s.Unit) {
		return fmt.Errorf("unit-Name %q ist unzulässig", s.Unit)
	}
	if !s.Rollback {
		if !selfUpdateChannels[s.Channel] {
			return fmt.Errorf("unbekannter Kanal %q", s.Channel)
		}
		if s.Version != "" && !versionArgPattern.MatchString(s.Version) {
			return fmt.Errorf("versionsangabe %q ist unzulässig", s.Version)
		}
	}
	if s.LogFile != "" && !filepath.IsAbs(s.LogFile) {
		return fmt.Errorf("der Pfad zur Protokolldatei muss absolut sein, nicht %q", s.LogFile)
	}
	return nil
}

// args baut den Aufruf. Jedes Argument entsteht hier im Code; nichts wird aus
// einer Zeichenkette zusammengesetzt, die von außen kommt.
func (s SelfUpdateSpec) args() []string {
	out := []string{
		"--unit=" + s.Unit,
		"--collect", // die Unit räumt sich nach dem Ende selbst weg
		"--description=Project Asylum: Selbstupdate",
		"--property=Type=oneshot",
		"--property=TimeoutStartSec=600",
		"--setenv=LC_ALL=C",
		s.Binary,
	}
	if s.Rollback {
		out = append(out, "rollback", "--assume-yes")
	} else {
		out = append(out, "update", "--assume-yes", "--channel="+s.Channel)
		if s.Version != "" {
			out = append(out, "--version="+s.Version)
		}
	}
	if s.LogFile != "" {
		out = append(out, "--log="+s.LogFile)
	}
	return out
}

// SelfUpdateStart setzt den Update-Lauf als eigene Unit ab und kehrt sofort
// zurück. Der Fortschritt landet in der Protokolldatei.
func (s *System) SelfUpdateStart(ctx context.Context, spec SelfUpdateSpec) error {
	if err := spec.validate(); err != nil {
		return err
	}
	res, err := s.run(ctx, Command{
		Name:    "systemd-run",
		Args:    spec.args(),
		Timeout: systemdRunTimeout,
	})
	if err != nil {
		return fmt.Errorf("selbstupdate anstoßen: %w", err)
	}
	if res.ExitCode != 0 {
		return fmt.Errorf("systemd-run endete mit %d: %s", res.ExitCode,
			strings.TrimSpace(res.Stderr))
	}
	return nil
}
