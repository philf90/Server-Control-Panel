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

// journalArgs baut die Argumente einer Abfrage.
//
// Gemeinsam für Logs und LogsFollow: Beide müssen dieselben Filter setzen und
// dieselben Eingaben prüfen. Zwei Fassungen davon liefen auseinander, und dann
// zeigte der laufende Strom mehr, als die Abfrage vorher hergab — bei einer
// Stufenbeschränkung wäre das ein Leck durch die Hintertür.
func journalArgs(q LogQuery) ([]string, error) {
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
	return args, nil
}

// passtZurSuche prüft den Freitext.
//
// Bewusst hier und nicht über journalctl --grep: Dort wäre die Eingabe ein
// regulärer Ausdruck, den ein Nutzer versehentlich zu einer teuren Suche
// ausbauen kann.
func passtZurSuche(e LogEntry, needle string) bool {
	if needle == "" {
		return true
	}
	return strings.Contains(strings.ToLower(e.Message), needle) ||
		strings.Contains(strings.ToLower(e.Unit), needle)
}

// Logs fragt das Journal ab.
func (s *System) Logs(ctx context.Context, q LogQuery) ([]LogEntry, error) {
	args, err := journalArgs(q)
	if err != nil {
		return nil, err
	}

	res, err := s.run(ctx, Command{Name: "journalctl", Args: args, Timeout: 2 * defaultTimeout})
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("journalctl: %s", firstLine(res.Stderr))
	}

	entries := parseJournalJSON(res.Stdout)

	if needle := strings.ToLower(strings.TrimSpace(q.Search)); needle != "" {
		filtered := entries[:0]
		for _, e := range entries {
			if passtZurSuche(e, needle) {
				filtered = append(filtered, e)
			}
		}
		entries = filtered
	}
	return entries, nil
}

// LogsFollow verfolgt das Journal, bis der Kontext abgebrochen wird.
//
// Der Unterschied zu einem Vorgang (siehe jobs.go): Ein Vorgang hat ein Ende,
// das der Server bestimmt — apt ist fertig. Ein Journal hat keines. Es endet,
// wenn niemand mehr zusieht, und deshalb ist der Kontext des Betrachters die
// einzige Frist. Läuft er ab, tötet CommandContext den journalctl-Prozess.
//
// Der Rückblick kommt mit: `--follow` zusammen mit `--lines N` liefert erst die
// letzten N Einträge und dann die neuen. Wer den Strom öffnet, sieht also nicht
// eine leere Fläche, bis zufällig etwas passiert.
//
// Der Fehler eines abgebrochenen Kontexts ist context.Canceled — für den
// Aufrufer das vorgesehene Ende und kein Fehlerbericht.
func (s *System) LogsFollow(ctx context.Context, q LogQuery, sink LogSink) error {
	if sink == nil {
		return fmt.Errorf("LogsFollow ohne Empfänger")
	}

	args, err := journalArgs(q)
	if err != nil {
		return err
	}
	args = append(args, "--follow")

	needle := strings.ToLower(strings.TrimSpace(q.Search))

	// Jede Zeile ist ein eigenes JSON-Objekt. Zeilen, die sich nicht zerlegen
	// lassen, werden übersprungen und nicht gemeldet: journalctl schreibt bei
	// einer Rotation gelegentlich eine Hinweiszeile dazwischen, und daran soll
	// der Strom nicht abreißen.
	res, err := s.run(ctx, Command{
		Name:      "journalctl",
		Args:      args,
		OhneFrist: true,
		Stream: func(line string) {
			e, ok := parseJournalLine(line)
			if !ok || !passtZurSuche(e, needle) {
				return
			}
			sink(e)
		},
	})
	// Der Abbruch des Kontexts ist das vorgesehene Ende: Der Betrachter hat die
	// Seite verlassen. Er wird zuerst geprüft, weil ein getöteter Prozess beides
	// hinterlässt — einen Exit-Code ungleich null UND unter Umständen einen
	// Fehler. Beides als Scheitern zu melden hieße, bei jedem geschlossenen Tab
	// eine Fehlermeldung in den Strom zu schreiben.
	if ctx.Err() != nil {
		//nolint:nilerr // Kein verschluckter Fehler: Der Abbruch IST das Ende.
		// journalctl wurde getötet, weil niemand mehr zusieht — was es dabei auf
		// stderr schreibt und mit welchem Code es endet, ist die Folge des
		// Abbruchs und kein Befund.
		return nil
	}
	if err != nil {
		return err
	}
	// Ein Follow-Lauf, der von selbst mit einem Fehlercode endet, ist ein echter
	// Fehler: journalctl bleibt sonst offen.
	if res.ExitCode != 0 {
		return fmt.Errorf("journalctl --follow: %s", firstLine(res.Stderr))
	}
	return nil
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
		if e, ok := parseJournalLine(line); ok {
			entries = append(entries, e)
		}
	}
	return entries
}

// parseJournalLine zerlegt eine Zeile der JSON-Ausgabe.
//
// Einzeln herausgezogen, weil der Follow-Strom sie zeilenweise bekommt und nicht
// als Block. Die Regeln sind dieselben — hätte der Strom seinen eigenen Parser,
// wäre eine kaputte Nachricht dort anders behandelt als in der Abfrage.
func parseJournalLine(line string) (LogEntry, bool) {
	line = strings.TrimSpace(line)
	if line == "" {
		return LogEntry{}, false
	}
	var raw journalEntry
	if err := json.Unmarshal([]byte(line), &raw); err != nil {
		return LogEntry{}, false
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
	return e, true
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
			f, ok := item.(float64)
			// Werte außerhalb eines Bytes stammen aus einer kaputten oder
			// manipulierten Zeile. Ungeprüft konvertiert würden sie still
			// überlaufen und andere Zeichen ergeben.
			if !ok || f < 0 || f > 255 {
				continue
			}
			buf = append(buf, byte(f))
		}
		return strings.ToValidUTF8(string(buf), "�")
	default:
		return ""
	}
}
