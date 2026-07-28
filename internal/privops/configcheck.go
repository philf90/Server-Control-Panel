package privops

import (
	"context"
	"path/filepath"
	"strings"
)

// Prüfung geschriebener Konfigurationsdateien.
//
// docs/02-architektur.md, Regel 5: "Nach jeder Änderung: Validierung vor dem
// Reload (sshd -t, nft -c -f). Schlägt sie fehl → automatisches Rollback,
// Fehler im UI." Für die vom Panel verwalteten Drop-ins galt das schon; der
// Editor macht beliebige Dateien änderbar, und damit auch die, an denen ein
// Tippfehler den Zugang zum Server kostet.
//
// Geprüft wird deshalb, was sich gefahrlos prüfen lässt, und nur das: Die
// Zuordnung ist eine feste Liste, keine Heuristik. Eine Datei, für die es kein
// Prüfprogramm gibt, wird als "nicht geprüft" gemeldet — nicht als "in
// Ordnung".

// ConfigCheckResult ist das Ergebnis einer Prüfung.
type ConfigCheckResult struct {
	// Checked sagt, ob es für diesen Pfad überhaupt ein Prüfprogramm gibt.
	Checked bool
	// OK sagt, ob die Datei angenommen wurde. Ohne Checked bedeutungslos.
	OK bool
	// Tool ist das aufgerufene Programm, für die Anzeige.
	Tool string
	// Output ist die Meldung des Programms, gekürzt auf das Wesentliche.
	Output string
}

// ConfigCheck prüft eine geschriebene Konfigurationsdatei, wenn es für sie ein
// Prüfprogramm gibt.
func (s *System) ConfigCheck(ctx context.Context, pfad string) (ConfigCheckResult, error) {
	switch art := konfigArt(pfad); art {
	case "sshd":
		// sshd -t prüft die Hauptdatei samt aller Drop-ins. Eine einzelne
		// Drop-in-Datei lässt sich nicht für sich prüfen — sshd kennt nur die
		// vollständige Konfiguration, und genau die ist die interessante Frage.
		res, err := s.run(ctx, Command{Name: "sshd", Args: []string{"-t"}})
		if err != nil {
			return ConfigCheckResult{}, err
		}
		return ConfigCheckResult{
			Checked: true,
			OK:      res.ExitCode == 0,
			Tool:    "sshd -t",
			Output:  kurzeAusgabe(res),
		}, nil

	case "nftables":
		res, err := s.run(ctx, Command{Name: "nft", Args: []string{"-c", "-f", pfad}})
		if err != nil {
			return ConfigCheckResult{}, err
		}
		return ConfigCheckResult{
			Checked: true,
			OK:      res.ExitCode == 0,
			Tool:    "nft -c -f",
			Output:  kurzeAusgabe(res),
		}, nil
	}
	return ConfigCheckResult{}, nil
}

// konfigArt ordnet einen Pfad einem Prüfprogramm zu. Feste Liste, keine
// Heuristik: Ein "sieht aus wie eine sshd-Konfiguration" wäre der Anfang von
// Prüfungen, die am falschen Ziel laufen.
func konfigArt(pfad string) string {
	sauber := filepath.Clean(pfad)
	switch {
	case sauber == "/etc/ssh/sshd_config",
		strings.HasPrefix(sauber, "/etc/ssh/sshd_config.d/"):
		return "sshd"
	case sauber == "/etc/nftables.conf",
		strings.HasPrefix(sauber, "/etc/nftables.d/"):
		return "nftables"
	}
	return ""
}

// kurzeAusgabe nimmt die Meldung des Prüfprogramms. sshd und nft schreiben nach
// stderr; ist dort nichts, zählt stdout.
func kurzeAusgabe(res Result) string {
	if t := strings.TrimSpace(res.Stderr); t != "" {
		return t
	}
	return strings.TrimSpace(res.Stdout)
}
