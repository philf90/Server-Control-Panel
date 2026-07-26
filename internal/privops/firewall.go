package privops

import (
	"context"
	"fmt"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// Zur Backend-Wahl:
//
// Auf Ubuntu- und Debian-Servern ist ufw der Regelfall, und ufw ist ein
// Frontend für nftables. Wo es aktiv ist, verwaltet das Panel die Regeln über
// ufw — der Server bleibt damit mit den gewohnten Bordmitteln bedienbar.
//
// Wo ufw fehlt und ein eigenes nftables-Regelwerk läuft, zeigt das Panel den
// Zustand an und ändert nichts. Das ist eine bewusste Entscheidung: Ein
// blindes Einfügen in fremde Ketten kann bestehende Regeln unwirksam machen
// oder die laufende SSH-Sitzung kappen. Ein Panel, das den Server aussperrt,
// hat schlimmer versagt als eines, das eine Funktion nicht anbietet.

// FirewallState ermittelt den Zustand der Firewall.
func (s *System) FirewallState(ctx context.Context) (FirewallState, error) {
	if state, ok, err := s.ufwState(ctx); err != nil {
		return FirewallState{}, err
	} else if ok {
		return state, nil
	}

	if state, ok, err := s.nftState(ctx); err != nil {
		return FirewallState{}, err
	} else if ok {
		return state, nil
	}

	return FirewallState{
		Backend: BackendNone,
		Notice: "Es wurde kein aktives Firewall-Regelwerk gefunden. " +
			"Für einen Server im offenen Netz ist ufw der einfachste Weg: " +
			"apt install ufw && ufw allow OpenSSH && ufw enable.",
	}, nil
}

func (s *System) ufwState(ctx context.Context) (FirewallState, bool, error) {
	res, err := s.run(ctx, Command{Name: "ufw", Args: []string{"status", "numbered"}})
	if err != nil {
		// ufw ist nicht installiert — kein Fehler, nur kein Backend. Der
		// Aufrufer probiert danach nftables.
		return FirewallState{}, false, nil //nolint:nilerr // fehlendes Backend ist kein Fehlerfall
	}
	if res.ExitCode != 0 {
		return FirewallState{}, false, nil
	}

	active := strings.Contains(res.Stdout, "Status: active")
	state := FirewallState{
		Backend: BackendUFW,
		Active:  active,
		Managed: true,
		Rules:   parseUFWStatus(res.Stdout),
	}
	if !active {
		state.Notice = "ufw ist installiert, aber nicht aktiv — die Regeln unten greifen nicht."
	}
	return state, true, nil
}

func (s *System) nftState(ctx context.Context) (FirewallState, bool, error) {
	res, err := s.run(ctx, Command{Name: "nft", Args: []string{"list", "ruleset"}})
	if err != nil {
		return FirewallState{}, false, nil //nolint:nilerr // fehlendes Backend ist kein Fehlerfall
	}
	if res.ExitCode != 0 || strings.TrimSpace(res.Stdout) == "" {
		return FirewallState{}, false, nil
	}

	return FirewallState{
		Backend: BackendNFTables,
		Active:  true,
		Managed: false,
		Rules:   parseNFTAccepts(res.Stdout),
		Notice: "Dieses System nutzt ein eigenes nftables-Regelwerk. Das Panel zeigt es an, " +
			"ändert es aber nicht: Ein automatischer Eingriff in fremde Ketten könnte " +
			"bestehende Regeln unwirksam machen oder die SSH-Sitzung kappen.",
	}, true, nil
}

// FirewallApply setzt den gewünschten Regelsatz. Regeln, die das Panel kennt
// und die nicht mehr gewünscht sind, werden entfernt.
func (s *System) FirewallApply(ctx context.Context, rules []FirewallRule) error {
	for _, r := range rules {
		if err := ValidateRule(r); err != nil {
			return err
		}
	}

	state, err := s.FirewallState(ctx)
	if err != nil {
		return err
	}
	if !state.Managed {
		return fmt.Errorf("das Regelwerk (%s) wird vom Panel nicht verwaltet", state.Backend)
	}

	current := make(map[string]FirewallRule, len(state.Rules))
	for _, r := range state.Rules {
		current[ruleKey(r)] = r
	}
	desired := make(map[string]FirewallRule, len(rules))
	for _, r := range rules {
		desired[ruleKey(r)] = r
	}

	// Erst hinzufügen, dann entfernen. Andersherum entstünde ein Moment, in
	// dem der eigene Zugang bereits weg und der neue noch nicht da ist.
	for key, r := range desired {
		if _, exists := current[key]; exists {
			continue
		}
		if err := s.ufwAdd(ctx, r); err != nil {
			return err
		}
	}
	for key, r := range current {
		if _, wanted := desired[key]; wanted {
			continue
		}
		if err := s.ufwDelete(ctx, r); err != nil {
			return err
		}
	}
	return nil
}

func (s *System) ufwAdd(ctx context.Context, r FirewallRule) error {
	args := []string{"allow"}
	if r.Source != "" {
		args = append(args, "from", r.Source, "to", "any", "port", strconv.Itoa(r.Port), "proto", r.Protocol)
	} else {
		args = append(args, strconv.Itoa(r.Port)+"/"+r.Protocol)
	}
	if r.Comment != "" {
		args = append(args, "comment", r.Comment)
	}

	res, err := s.run(ctx, Command{Name: "ufw", Args: args})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return fmt.Errorf("ufw allow %d/%s: %s", r.Port, r.Protocol, firstLine(res.Stderr))
	}
	return nil
}

func (s *System) ufwDelete(ctx context.Context, r FirewallRule) error {
	args := []string{"--force", "delete", "allow"}
	if r.Source != "" {
		args = append(args, "from", r.Source, "to", "any", "port", strconv.Itoa(r.Port), "proto", r.Protocol)
	} else {
		args = append(args, strconv.Itoa(r.Port)+"/"+r.Protocol)
	}

	res, err := s.run(ctx, Command{Name: "ufw", Args: args})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return fmt.Errorf("ufw delete %d/%s: %s", r.Port, r.Protocol, firstLine(res.Stderr))
	}
	return nil
}

func ruleKey(r FirewallRule) string {
	return fmt.Sprintf("%d/%s/%s", r.Port, r.Protocol, r.Source)
}

// ------------------------------------------------------------------ Parser ---

// Zeilen von "ufw status numbered", zum Beispiel:
//
//	[ 1] 22/tcp                     ALLOW IN    Anywhere                   # SSH
//	[ 2] 8443/tcp                   ALLOW IN    203.0.113.0/24
//
// IPv6-Wiederholungen (Anywhere (v6)) werden übersprungen: Sie gehören zur
// selben Regel und würden die Liste verdoppeln.
var ufwRulePattern = regexp.MustCompile(
	`^\[\s*\d+\]\s+(\S+?)(?:/(tcp|udp))?\s+ALLOW IN\s+(\S+(?:\s\(v6\))?)\s*(?:#\s*(.*))?$`)

func parseUFWStatus(out string) []FirewallRule {
	var rules []FirewallRule

	for _, line := range strings.Split(out, "\n") {
		line = strings.TrimRight(line, " \t")
		m := ufwRulePattern.FindStringSubmatch(strings.TrimSpace(line))
		if m == nil {
			continue
		}
		if strings.Contains(m[3], "(v6)") {
			continue
		}

		port, err := strconv.Atoi(m[1])
		if err != nil {
			// Benannte Profile wie "OpenSSH" oder Portbereiche lassen sich
			// nicht als einfache Regel abbilden; sie erscheinen deshalb nicht
			// in der verwalteten Liste und bleiben unangetastet.
			continue
		}
		proto := m[2]
		if proto == "" {
			proto = "tcp"
		}
		source := m[3]
		if source == "Anywhere" {
			source = ""
		}

		rules = append(rules, FirewallRule{
			Port:     port,
			Protocol: proto,
			Source:   source,
			Comment:  strings.TrimSpace(m[4]),
		})
	}

	sort.Slice(rules, func(i, j int) bool {
		if rules[i].Port != rules[j].Port {
			return rules[i].Port < rules[j].Port
		}
		return rules[i].Protocol < rules[j].Protocol
	})
	return rules
}

// parseNFTAccepts liest die akzeptierten Ports aus einem nftables-Regelwerk.
// Das ist bewusst nur eine Anzeigehilfe, keine vollständige Abbildung.
var nftAcceptPattern = regexp.MustCompile(`(tcp|udp)\s+dport\s+(?:\{\s*([^}]+)\s*\}|(\d+))[^\n]*accept`)

func parseNFTAccepts(out string) []FirewallRule {
	var rules []FirewallRule
	seen := make(map[string]bool)

	for _, m := range nftAcceptPattern.FindAllStringSubmatch(out, -1) {
		proto := m[1]
		ports := m[3]
		if m[2] != "" {
			ports = m[2]
		}
		for _, raw := range strings.Split(ports, ",") {
			raw = strings.TrimSpace(raw)
			port, err := strconv.Atoi(raw)
			if err != nil {
				continue
			}
			key := proto + "/" + raw
			if seen[key] {
				continue
			}
			seen[key] = true
			rules = append(rules, FirewallRule{Port: port, Protocol: proto})
		}
	}

	sort.Slice(rules, func(i, j int) bool {
		if rules[i].Port != rules[j].Port {
			return rules[i].Port < rules[j].Port
		}
		return rules[i].Protocol < rules[j].Protocol
	})
	return rules
}
