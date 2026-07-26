package privops

import (
	"context"
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
)

// Services listet die Dienste des Systems.
//
// Ausgewertet wird die JSON-Ausgabe von systemctl statt der Spaltenansicht:
// Unit-Beschreibungen enthalten Leerzeichen, und ein Spaltenparser bricht
// zuverlässig an der ersten Unit, deren Beschreibung nach einem Statuswort
// aussieht.
func (s *System) Services(ctx context.Context, filter ServiceFilter) ([]Service, error) {
	res, err := s.run(ctx, Command{
		Name: "systemctl",
		Args: []string{"list-units", "--type=service", "--all", "--no-pager", "--output=json"},
	})
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("systemctl list-units: %s", firstLine(res.Stderr))
	}

	services, err := parseUnitList(res.Stdout)
	if err != nil {
		return nil, err
	}

	// Der Aktivierungszustand steht in einer anderen Abfrage. Schlägt sie
	// fehl, ist das kein Grund, die ganze Liste zu verwerfen.
	if states, err := s.unitFileStates(ctx); err == nil {
		for i := range services {
			if state, ok := states[services[i].Unit]; ok {
				services[i].Enabled = state
			}
		}
	}

	return filterServices(services, filter), nil
}

func (s *System) unitFileStates(ctx context.Context) (map[string]string, error) {
	res, err := s.run(ctx, Command{
		Name: "systemctl",
		Args: []string{"list-unit-files", "--type=service", "--no-pager", "--output=json"},
	})
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("systemctl list-unit-files: %s", firstLine(res.Stderr))
	}
	return parseUnitFileStates(res.Stdout)
}

// Service liefert Detailangaben und die letzten Logzeilen einer Unit.
func (s *System) Service(ctx context.Context, unit string) (ServiceDetail, error) {
	if err := ValidateUnit(unit); err != nil {
		return ServiceDetail{}, err
	}

	res, err := s.run(ctx, Command{
		Name: "systemctl",
		Args: []string{
			"show", unit, "--no-pager",
			"--property=Id,Description,LoadState,ActiveState,SubState,UnitFileState," +
				"MainPID,MemoryCurrent,TasksCurrent,FragmentPath,ActiveEnterTimestamp",
		},
	})
	if err != nil {
		return ServiceDetail{}, err
	}
	if res.ExitCode != 0 {
		return ServiceDetail{}, fmt.Errorf("systemctl show %s: %s", unit, firstLine(res.Stderr))
	}

	detail := parseUnitShow(res.Stdout)
	if detail.Unit == "" {
		return ServiceDetail{}, fmt.Errorf("Unit %q ist unbekannt", unit)
	}

	// Logzeilen sind Beiwerk: Fehlt das Journal, bleibt die Detailseite trotzdem
	// nutzbar.
	if logs, err := s.Logs(ctx, LogQuery{Unit: unit, Limit: 25, Priority: -1}); err == nil {
		detail.RecentLogs = logs
	}
	return detail, nil
}

// ServiceAction führt eine Aktion auf einer Unit aus.
func (s *System) ServiceAction(ctx context.Context, unit string, action ServiceAction) error {
	if err := ValidateUnit(unit); err != nil {
		return err
	}
	if !ValidServiceAction(action) {
		return fmt.Errorf("Aktion %q ist nicht erlaubt", action)
	}

	// Das abschließende "--" trennt Optionen von Operanden. Zusammen mit der
	// Namensprüfung ist ausgeschlossen, dass ein Unit-Name als Flag gelesen wird.
	res, err := s.run(ctx, Command{
		Name:    "systemctl",
		Args:    []string{string(action), "--no-pager", "--", unit},
		Timeout: 2 * defaultTimeout,
	})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		msg := firstLine(res.Stderr)
		if msg == "" {
			msg = firstLine(res.Stdout)
		}
		return fmt.Errorf("systemctl %s %s: %s", action, unit, msg)
	}
	return nil
}

// ------------------------------------------------------------------ Parser ---

type unitListEntry struct {
	Unit        string `json:"unit"`
	Load        string `json:"load"`
	Active      string `json:"active"`
	Sub         string `json:"sub"`
	Description string `json:"description"`
}

func parseUnitList(out string) ([]Service, error) {
	out = strings.TrimSpace(out)
	if out == "" {
		return nil, nil
	}

	var entries []unitListEntry
	if err := json.Unmarshal([]byte(out), &entries); err != nil {
		return nil, fmt.Errorf("Unit-Liste unlesbar: %w", err)
	}

	services := make([]Service, 0, len(entries))
	for _, e := range entries {
		if e.Unit == "" {
			continue
		}
		services = append(services, Service{
			Unit:        e.Unit,
			Load:        e.Load,
			Active:      e.Active,
			Sub:         e.Sub,
			Description: e.Description,
		})
	}
	return services, nil
}

type unitFileEntry struct {
	UnitFile string `json:"unit_file"`
	State    string `json:"state"`
}

func parseUnitFileStates(out string) (map[string]string, error) {
	out = strings.TrimSpace(out)
	if out == "" {
		return map[string]string{}, nil
	}

	var entries []unitFileEntry
	if err := json.Unmarshal([]byte(out), &entries); err != nil {
		return nil, fmt.Errorf("Unit-Dateiliste unlesbar: %w", err)
	}

	states := make(map[string]string, len(entries))
	for _, e := range entries {
		// Der Name kann als voller Pfad kommen.
		name := e.UnitFile
		if idx := strings.LastIndex(name, "/"); idx >= 0 {
			name = name[idx+1:]
		}
		states[name] = e.State
	}
	return states, nil
}

func parseUnitShow(out string) ServiceDetail {
	fields := make(map[string]string)
	for _, line := range strings.Split(out, "\n") {
		key, value, found := strings.Cut(strings.TrimSpace(line), "=")
		if !found {
			continue
		}
		fields[key] = value
	}

	d := ServiceDetail{
		Service: Service{
			Unit:        fields["Id"],
			Description: fields["Description"],
			Load:        fields["LoadState"],
			Active:      fields["ActiveState"],
			Sub:         fields["SubState"],
			Enabled:     fields["UnitFileState"],
		},
		Since:     fields["ActiveEnterTimestamp"],
		FragmentP: fields["FragmentPath"],
	}
	d.MainPID, _ = strconv.Atoi(fields["MainPID"])
	d.Tasks, _ = strconv.Atoi(fields["TasksCurrent"])

	// systemd meldet für nicht gesetzte Werte den maximalen uint64-Wert.
	if mem, err := strconv.ParseUint(fields["MemoryCurrent"], 10, 64); err == nil && mem != ^uint64(0) {
		d.Memory = mem
	}
	return d
}

func filterServices(services []Service, f ServiceFilter) []Service {
	needle := strings.ToLower(strings.TrimSpace(f.Search))

	out := make([]Service, 0, len(services))
	for _, s := range services {
		if f.OnlyFailed && !s.Failed() {
			continue
		}
		if f.OnlyActive && !s.Running() {
			continue
		}
		if needle != "" &&
			!strings.Contains(strings.ToLower(s.Unit), needle) &&
			!strings.Contains(strings.ToLower(s.Description), needle) {
			continue
		}
		out = append(out, s)
	}
	return out
}

func firstLine(s string) string {
	s = strings.TrimSpace(s)
	if idx := strings.IndexByte(s, '\n'); idx >= 0 {
		return s[:idx]
	}
	return s
}
