package privops

// systemd-Timer: die andere Hälfte des Zeitplan-Moduls.
//
// Sie ist die weniger gefährliche von beiden, und zwar aus einem Grund, der die
// Bauart bestimmt: **Ein Timer ist eine Unit, und Units schaltet das Panel
// längst.** start, stop, enable und disable laufen über ServiceAction — dieselbe
// Allowlist, dieselbe Namensprüfung, dieselben Rückfragen. Diese Datei fügt
// deshalb nur LESENDE Operationen hinzu:
//
//   - TimerList: was es gibt, wann es zuletzt lief und wann es das nächste Mal
//     tut.
//   - TimerRuns: was beim letzten Lauf herauskam — Exit-Code und Journalzeilen
//     der Unit, die der Timer auslöst.
//
// Das Anlegen eines Timers fehlt bewusst. Ein Timer besteht aus ZWEI Unit-Dateien
// (.timer und .service), die zusammenpassen müssen, und die .service-Datei trägt
// ExecStart — also wieder einen freien Befehl, diesmal mit systemd-Rechten
// (User=, CapabilityBoundingSet=, ProtectSystem=). Wer regelmäßig etwas laufen
// lassen will, bekommt das über den Cron-Teil dieses Moduls; wer die Härtung von
// systemd braucht, schreibt die Units von Hand. Ein halbes Formular für
// systemd-Optionen wäre die schlechteste der drei Möglichkeiten — es sähe aus,
// als könnte man damit alles einstellen, und könnte es nicht.

import (
	"context"
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
	"time"
)

// Timer ist ein systemd-Timer in der Übersicht.
type Timer struct {
	Unit string `json:"unit"`
	// Loest ist die Unit, die der Timer startet — meist derselbe Name mit
	// .service. Sie ist die Stelle, an der der Befehl steht, und damit die
	// Antwort auf „was tut dieser Timer".
	Loest        string `json:"loest"`
	Beschreibung string `json:"beschreibung"`
	// Aktiv und Enabled: läuft der Timer, und kommt er nach einem Neustart
	// wieder? Zwei Fragen, zwei Felder — ein gestoppter, aber aktivierter Timer
	// ist ein anderer Zustand als ein laufender, der nach dem Neustart weg ist.
	Aktiv   string `json:"aktiv"`
	Enabled string `json:"enabled"`
	// Naechster und Letzter sind Zeitpunkte; null heißt „nicht bekannt".
	// systemd kennt beide nicht immer: Ein Timer, der noch nie lief, hat keinen
	// letzten Lauf, und einer, der abgeschaltet ist, keinen nächsten.
	Naechster *time.Time `json:"naechster"`
	Letzter   *time.Time `json:"letzter"`
	// Plan ist die OnCalendar-Angabe, roh. Sie ist das Gegenstück zum
	// Cron-Zeitplan, hat aber eine eigene Syntax — deshalb keine Übersetzung in
	// Worte: Eine halbe Auslegung von OnCalendar wäre schlechter als das Feld.
	Plan string `json:"plan"`
	// Persistent heißt: Ein versäumter Lauf wird nachgeholt. Für einen Server,
	// der nachts aus ist, macht das den Unterschied zwischen „läuft nie" und
	// „läuft beim nächsten Start".
	Persistent bool `json:"persistent"`
}

// TimerLauf ist das Ergebnis des letzten Laufs.
type TimerLauf struct {
	// Unit ist die ausgelöste Unit, nicht der Timer.
	Unit string `json:"unit"`
	// Ergebnis ist das systemd-Wort: "success", "exit-code", "timeout", "signal".
	// Roh übernommen, weil systemd es genauer trifft als eine Übersetzung.
	Ergebnis string `json:"ergebnis"`
	// ExitCode ist der Rückgabewert des Programms; -1 heißt „nicht bekannt".
	ExitCode int `json:"exit_code"`
	// Geglueckt fasst zusammen, ohne die Einzelheit zu ersetzen.
	Geglueckt bool `json:"geglueckt"`
	// Zeilen sind die Journalzeilen des letzten Laufs — die Ausgabe des
	// Programms. Grundsatz IV: Was das Panel weiß, zeigt es.
	Zeilen []LogEntry `json:"zeilen"`
}

// TimerList listet die Timer des Systems.
func (s *System) TimerList(ctx context.Context) ([]Timer, error) {
	// list-timers kennt --all, zeigt aber keine Beschreibung der ausgelösten
	// Unit und kein OnCalendar. Beides kommt aus einem zweiten Aufruf; ohne das
	// stünde in der Liste ein Name und sonst nichts.
	res, err := s.run(ctx, Command{
		Name: "systemctl",
		Args: []string{"list-timers", "--all", "--no-pager", "--output=json"},
	})
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("systemctl list-timers: %s", firstLine(res.Stderr))
	}
	timers, err := parseTimerList(res.Stdout)
	if err != nil {
		return nil, err
	}

	// Die Eigenschaften je Timer. Ein Fehlschlag hier verwirft die Liste nicht:
	// Namen und Zeitpunkte stehen schon.
	for i := range timers {
		if eig, err := s.timerEigenschaften(ctx, timers[i].Unit); err == nil {
			if eig["Unit"] != "" {
				timers[i].Loest = eig["Unit"]
			}
			timers[i].Beschreibung = eig["Description"]
			timers[i].Aktiv = eig["ActiveState"]
			timers[i].Enabled = eig["UnitFileState"]
			timers[i].Plan = eig["TimersCalendar"]
			timers[i].Persistent = eig["Persistent"] == "yes"
		}
	}
	return timers, nil
}

func (s *System) timerEigenschaften(ctx context.Context, unit string) (map[string]string, error) {
	if err := ValidateUnit(unit); err != nil {
		return nil, err
	}
	res, err := s.run(ctx, Command{
		Name: "systemctl",
		Args: []string{
			"show", "--no-pager",
			"--property=Id,Description,ActiveState,UnitFileState,Unit,Persistent,TimersCalendar",
			"--", unit,
		},
	})
	if err != nil {
		return nil, err
	}
	if res.ExitCode != 0 {
		return nil, fmt.Errorf("systemctl show %s: %s", unit, firstLine(res.Stderr))
	}
	return parseEigenschaften(res.Stdout), nil
}

// TimerRuns liefert das Ergebnis des letzten Laufs der ausgelösten Unit.
//
// Gefragt wird nach der SERVICE-Unit und nicht nach dem Timer: Der Timer glückt
// immer, sobald er auslöst — was schiefgehen kann, geht im Dienst schief. Wer
// „letzter Lauf gescheitert" sucht, sucht dessen Exit-Code.
func (s *System) TimerRuns(ctx context.Context, unit string) (TimerLauf, error) {
	if err := ValidateUnit(unit); err != nil {
		return TimerLauf{}, err
	}
	res, err := s.run(ctx, Command{
		Name: "systemctl",
		Args: []string{
			"show", "--no-pager",
			"--property=Id,Result,ExecMainStatus,ActiveState,SubState",
			"--", unit,
		},
	})
	if err != nil {
		return TimerLauf{}, err
	}
	if res.ExitCode != 0 {
		return TimerLauf{}, fmt.Errorf("systemctl show %s: %s", unit, firstLine(res.Stderr))
	}
	eig := parseEigenschaften(res.Stdout)
	if eig["Id"] == "" {
		return TimerLauf{}, fmt.Errorf("Unit %q ist unbekannt", unit)
	}

	lauf := TimerLauf{
		Unit:     eig["Id"],
		Ergebnis: eig["Result"],
		ExitCode: -1,
		Zeilen:   []LogEntry{},
	}
	if code, err := strconv.Atoi(eig["ExecMainStatus"]); err == nil {
		lauf.ExitCode = code
	}
	// „success" ist das Wort von systemd für einen Lauf ohne Fehler. Ein leeres
	// Result heißt: noch nie gelaufen — das ist nicht geglückt und nicht
	// gescheitert, und die Oberfläche unterscheidet es am fehlenden Zeitpunkt.
	lauf.Geglueckt = eig["Result"] == "success"

	// Die Ausgabe des letzten Laufs. Beiwerk: Fehlt das Journal, bleibt der
	// Exit-Code die Auskunft.
	// Nur zuweisen, wenn wirklich Zeilen kamen: Logs gibt für ein leeres Journal
	// eine leere Liste zurück, die auch nil sein darf — und ein nil-Feld wird zu
	// JSON-null. Die Oberfläche unterscheidet „keine Ausgabe" von „nicht
	// gefragt", und null hieße das Zweite.
	if zeilen, err := s.Logs(ctx, LogQuery{Unit: unit, Limit: 50, Priority: -1}); err == nil && zeilen != nil {
		lauf.Zeilen = zeilen
	}
	return lauf, nil
}

// parseTimerList liest die JSON-Ausgabe von systemctl list-timers.
//
// Die Zeitpunkte kommen als Mikrosekunden seit der Epoche in
// NextElapseUSecRealtime und LastTriggerUSec. Der Wert 0 — und bei
// monotonen Timern der Höchstwert — heißt „kein Zeitpunkt"; als Datum ausgegeben
// wäre das der 1. Januar 1970 oder ein Jahr in ferner Zukunft, und beides sähe
// nach einem echten Zeitpunkt aus.
func parseTimerList(stdout string) ([]Timer, error) {
	stdout = strings.TrimSpace(stdout)
	if stdout == "" || stdout == "null" {
		return []Timer{}, nil
	}
	var roh []struct {
		Unit      string `json:"unit"`
		Activates string `json:"activates"`
		Next      any    `json:"next"`
		Last      any    `json:"last"`
		// Ältere systemd-Fassungen nennen die Felder anders.
		NextElapse  any `json:"NextElapseUSecRealtime"`
		LastTrigger any `json:"LastTriggerUSec"`
	}
	if err := json.Unmarshal([]byte(stdout), &roh); err != nil {
		return nil, fmt.Errorf("systemctl list-timers: Ausgabe unlesbar: %w", err)
	}

	out := make([]Timer, 0, len(roh))
	for _, r := range roh {
		if r.Unit == "" {
			continue
		}
		t := Timer{Unit: r.Unit, Loest: r.Activates}
		t.Naechster = mikrosekundenZeit(erstesNichtLeere(r.Next, r.NextElapse))
		t.Letzter = mikrosekundenZeit(erstesNichtLeere(r.Last, r.LastTrigger))
		out = append(out, t)
	}
	return out, nil
}

func erstesNichtLeere(werte ...any) any {
	for _, w := range werte {
		if w != nil {
			return w
		}
	}
	return nil
}

// mikrosekundenZeit wandelt den systemd-Zeitstempel. Rückgabe nil heißt „kein
// Zeitpunkt": 0 für „nie", und der Höchstwert für „nicht bestimmbar".
func mikrosekundenZeit(v any) *time.Time {
	var usec uint64
	switch n := v.(type) {
	case float64:
		if n <= 0 {
			return nil
		}
		usec = uint64(n)
	case string:
		parsed, err := strconv.ParseUint(n, 10, 64)
		if err != nil || parsed == 0 {
			return nil
		}
		usec = parsed
	default:
		return nil
	}
	// systemd trägt bei einem Timer ohne bekannten nächsten Lauf den
	// Höchstwert ein. Als Datum wäre das ein Jahr in ferner Zukunft.
	if usec >= uint64(1)<<63 {
		return nil
	}
	t := time.UnixMicro(int64(usec))
	return &t
}

// parseEigenschaften liest die KEY=VALUE-Ausgabe von systemctl show.
func parseEigenschaften(stdout string) map[string]string {
	out := map[string]string{}
	for zeile := range strings.SplitSeq(stdout, "\n") {
		zeile = strings.TrimSpace(zeile)
		if zeile == "" {
			continue
		}
		schluessel, wert, gefunden := strings.Cut(zeile, "=")
		if !gefunden {
			continue
		}
		out[schluessel] = wert
	}
	return out
}
