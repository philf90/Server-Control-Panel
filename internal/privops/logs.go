package privops

import (
	"context"
	"encoding/json"
	"fmt"
	"sort"
	"strconv"
	"strings"
	"time"
)

// maxLogLimit deckelt die Zeilenzahl einer Abfrage.
const maxLogLimit = 2000

// Logs fragt das Journal ab.
func (s *System) Logs(ctx context.Context, q LogQuery) ([]LogEntry, error) {
	args := []string{"--no-pager", "--output=json", "--quiet"}

	if q.Unit != "" {
		if err := ValidateUnit(q.Unit); err != nil {
			return nil, err
		}
		args = append(args, "--unit", q.Unit)
	}
	if q.Priority >= 0 && q.Priority <= 7 {
		args = append(args, "--priority", strconv.Itoa(q.Priority))
	}
	if q.Since != "" {
		if err := validateSince(q.Since); err != nil {
			return nil, err
		}
		args = append(args, "--since", q.Since)
	}

	limit := q.Limit
	if limit <= 0 || limit > maxLogLimit {
		limit = 200
	}
	args = append(args, "--lines", strconv.Itoa(limit))

	res, err := s.run(ctx, Command{Name: "journalctl", Args: args, Timeout: 2 * defaultTimeout})
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("journalctl: %s", firstLine(res.Stderr))
	}

	entries := parseJournalJSON(res.Stdout)

	// Die Freitextsuche läuft bewusst hier und nicht über journalctl --grep:
	// Dort wäre die Eingabe ein regulärer Ausdruck, den ein Nutzer versehentlich
	// zu einer teuren Suche ausbauen kann.
	if needle := strings.ToLower(strings.TrimSpace(q.Search)); needle != "" {
		filtered := entries[:0]
		for _, e := range entries {
			if strings.Contains(strings.ToLower(e.Message), needle) ||
				strings.Contains(strings.ToLower(e.Unit), needle) {
				filtered = append(filtered, e)
			}
		}
		entries = filtered
	}
	return entries, nil
}

// LogUnits liefert die Units, zu denen das Journal Einträge hat.
func (s *System) LogUnits(ctx context.Context) ([]string, error) {
	res, err := s.run(ctx, Command{
		Name: "journalctl",
		Args: []string{"--no-pager", "--field=_SYSTEMD_UNIT"},
	})
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("journalctl --field: %s", firstLine(res.Stderr))
	}

	var units []string
	for _, line := range strings.Split(res.Stdout, "\n") {
		unit := strings.TrimSpace(line)
		if unit == "" || ValidateUnit(unit) != nil {
			continue
		}
		units = append(units, unit)
	}
	sort.Strings(units)
	return units, nil
}

// validateSince lässt nur Zeitangaben durch, die journalctl gefahrlos versteht.
func validateSince(since string) error {
	switch since {
	case "today", "yesterday", "-1h", "-6h", "-24h", "-7d":
		return nil
	}
	// Absolute Angabe im Format "2006-01-02" oder "2006-01-02 15:04:05".
	for _, layout := range []string{"2006-01-02", "2006-01-02 15:04:05"} {
		if _, err := time.Parse(layout, since); err == nil {
			return nil
		}
	}
	return fmt.Errorf("Zeitangabe %q wird nicht unterstützt", since)
}

// journalEntry bildet die Felder ab, die uns interessieren. journald liefert
// sie als Zeichenketten, auch die numerischen.
type journalEntry struct {
	Timestamp string `json:"__REALTIME_TIMESTAMP"`
	Unit      string `json:"_SYSTEMD_UNIT"`
	Priority  string `json:"PRIORITY"`
	Message   any    `json:"MESSAGE"`
	Host      string `json:"_HOSTNAME"`
	Comm      string `json:"_COMM"`
}

func parseJournalJSON(out string) []LogEntry {
	var entries []LogEntry

	for _, line := range strings.Split(out, "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		var raw journalEntry
		if err := json.Unmarshal([]byte(line), &raw); err != nil {
			continue
		}

		e := LogEntry{
			Unit:     raw.Unit,
			Host:     raw.Host,
			Message:  journalMessage(raw.Message),
			Priority: 6,
		}
		if e.Unit == "" {
			e.Unit = raw.Comm
		}
		if p, err := strconv.Atoi(raw.Priority); err == nil {
			e.Priority = p
		}
		// __REALTIME_TIMESTAMP sind Mikrosekunden seit Epoch.
		if usec, err := strconv.ParseInt(raw.Timestamp, 10, 64); err == nil {
			e.At = time.UnixMicro(usec)
		}
		entries = append(entries, e)
	}
	return entries
}

// journalMessage entpackt das MESSAGE-Feld. Bei nicht druckbarem Inhalt
// liefert journald ein Array von Bytewerten statt einer Zeichenkette.
func journalMessage(v any) string {
	switch m := v.(type) {
	case string:
		return m
	case []any:
		buf := make([]byte, 0, len(m))
		for _, item := range m {
			if f, ok := item.(float64); ok {
				buf = append(buf, byte(int(f)))
			}
		}
		return strings.ToValidUTF8(string(buf), "�")
	default:
		return ""
	}
}
