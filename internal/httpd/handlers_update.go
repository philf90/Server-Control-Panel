package httpd

import (
	"bufio"
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/philf90/asylum/internal/update"
)

// Die Oberfläche stößt das Update nur an; ausgeführt wird es von einem
// eigenständigen Prozess in einer Transient-Unit. Der Grund steht in
// internal/privops/selfupdate.go: Ein Vorgang, der den eigenen Dienst neu
// startet, überlebt seinen eigenen Neustart nicht — und könnte einen
// Fehlschlag dann nicht mehr zurücknehmen.
//
// Für die Anzeige heißt das: Die Verbindung zum Browser reißt mitten im
// Vorgang ab. Deshalb schreibt der Update-Prozess in eine Protokolldatei, die
// das Panel nach seinem Neustart wieder ausliest, statt auf einen offenen
// SSE-Kanal zu setzen.

// updateLogName ist die Datei, in die der Update-Lauf schreibt.
const updateLogName = "update.log"

// maxUpdateLogLines begrenzt, was aus der Protokolldatei angezeigt wird.
const maxUpdateLogLines = 400

// updateCheckTimeout begrenzt die Abfrage der Metadaten.
const updateCheckTimeout = 30 * time.Second

// updateState hält das Ergebnis der letzten Prüfung im Speicher.
type updateState struct {
	mu        sync.RWMutex
	checkedAt time.Time
	release   update.Release
	err       string
	// startedAt merkt sich, wann zuletzt ein Lauf angestoßen wurde. Solange er
	// jung ist, zeigt die Oberfläche den Vorgang als laufend an.
	startedAt time.Time
	startedTo string
}

func newUpdateState() *updateState { return &updateState{} }

func (u *updateState) setResult(rel update.Release, err error) {
	u.mu.Lock()
	defer u.mu.Unlock()
	u.checkedAt = time.Now()
	u.release = rel
	u.err = ""
	if err != nil {
		u.err = err.Error()
	}
}

func (u *updateState) markStarted(target string) {
	u.mu.Lock()
	defer u.mu.Unlock()
	u.startedAt = time.Now()
	u.startedTo = target
}

func (u *updateState) snapshot() (checkedAt time.Time, rel update.Release, errMsg string, running bool, target string) {
	u.mu.RLock()
	defer u.mu.RUnlock()
	// Nach dem Neustart des Panels ist dieser Zustand leer — dann entscheidet
	// allein die Protokolldatei, was angezeigt wird.
	running = !u.startedAt.IsZero() && time.Since(u.startedAt) < updateRunWindow
	return u.checkedAt, u.release, u.err, running, u.startedTo
}

// updateRunWindow ist die Zeit, in der ein angestoßener Lauf als laufend gilt.
const updateRunWindow = 5 * time.Minute

func (s *Server) updateLogPath() string {
	return filepath.Join(s.cfg.Paths.Log, updateLogName)
}

// previousVersion liest die Fassung aus der Sicherung neben dem Binary.
func (s *Server) previousVersion(ctx context.Context) (string, error) {
	binary, err := update.CurrentBinary()
	if err != nil {
		return "", err
	}
	backup := binary + ".vorher"
	if _, err := os.Stat(backup); err != nil {
		return "", err
	}
	ctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	return update.VersionOfBinary(ctx, backup)
}

// tailFile liest die letzten Zeilen einer Datei. Fehlt sie, ist das kein
// Fehler — vor dem ersten Update gibt es nichts zu zeigen.
func tailFile(path string, max int) []string {
	f, err := os.Open(path) //nolint:gosec // Pfad aus der Konfiguration, nicht aus einer Anfrage
	if err != nil {
		if !errors.Is(err, os.ErrNotExist) {
			return []string{"Protokoll nicht lesbar: " + err.Error()}
		}
		return nil
	}
	defer func() { _ = f.Close() }()

	var lines []string
	sc := bufio.NewScanner(f)
	sc.Buffer(make([]byte, 0, 64*1024), 256*1024)
	for sc.Scan() {
		line := strings.TrimRight(sc.Text(), "\r")
		if line == "" {
			continue
		}
		lines = append(lines, line)
		if len(lines) > max {
			lines = lines[1:]
		}
	}
	return lines
}
