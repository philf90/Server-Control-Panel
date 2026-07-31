package httpd

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
	"github.com/philf90/asylum/internal/update"
	"github.com/philf90/asylum/internal/version"
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

// updatePage ist der Inhalt von /update.
type updatePage struct {
	Current     string
	Channel     string
	BaseURL     string
	CheckedAt   time.Time
	Available   string
	ReleasedAt  time.Time
	NotesURL    string
	Severity    string
	HasUpdate   bool
	CheckError  string
	Running     bool
	Target      string
	LogLines    []string
	Previous    string
	CanRollback bool
}

func (s *Server) updateLogPath() string {
	return filepath.Join(s.cfg.Paths.Log, updateLogName)
}

func (s *Server) handleUpdate(w http.ResponseWriter, r *http.Request) {
	s.renderUpdate(w, r, http.StatusOK, "", "")
}

func (s *Server) renderUpdate(w http.ResponseWriter, r *http.Request, status int, flash, errMsg string) {
	checkedAt, rel, checkErr, running, target := s.upd.snapshot()

	content := updatePage{
		Current:    version.Version,
		Channel:    s.cfg.Updates.Channel,
		BaseURL:    s.cfg.Updates.BaseURL,
		CheckedAt:  checkedAt,
		CheckError: checkErr,
		Running:    running,
		Target:     target,
		LogLines:   tailFile(s.updateLogPath(), maxUpdateLogLines),
	}
	if rel.Version != "" {
		content.Available = rel.Version
		content.ReleasedAt = rel.ReleasedAt
		content.NotesURL = rel.NotesURL
		content.Severity = rel.Severity
		content.HasUpdate = update.Newer(version.Version, rel.Version)
	}
	if prev, err := s.previousVersion(r.Context()); err == nil {
		content.Previous = prev
		content.CanRollback = true
	}

	page := s.base(r, "Updates", "update").with(content)
	if flash != "" {
		page = page.withFlash(flash)
	}
	if errMsg != "" {
		page = page.withError(errMsg)
	}
	s.renderPage(w, r, status, "update", page)
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

// handleUpdateCheck fragt die Metadaten ab. Das ist ein lesender Vorgang, der
// nichts am System ändert — er steht deshalb allen schreibberechtigten Rollen
// offen.
func (s *Server) handleUpdateCheck(w http.ResponseWriter, r *http.Request) {
	ctx, cancel := context.WithTimeout(r.Context(), updateCheckTimeout)
	defer cancel()

	client := update.NewClient()
	client.BaseURL = s.cfg.Updates.BaseURL
	if s.updHTTP != nil {
		client.HTTP = s.updHTTP
	}

	rel, err := client.Latest(ctx, s.cfg.Updates.Channel)
	s.upd.setResult(rel, err)
	if err != nil {
		s.log.Warn("update-metadaten abrufen", "err", err)
		s.renderUpdate(w, r, http.StatusOK, "", "Die Update-Metadaten sind nicht erreichbar: "+err.Error())
		return
	}

	flash := fmt.Sprintf("Im Kanal %s steht Fassung %s.", s.cfg.Updates.Channel, rel.Version)
	if !update.Newer(version.Version, rel.Version) {
		flash = fmt.Sprintf("Fassung %s ist aktuell.", version.Version)
	}
	s.renderUpdate(w, r, http.StatusOK, flash, "")
}

// handleUpdateApply stößt den Update-Lauf an.
//
// Nur Owner: Das Update tauscht das Programm aus, das alle anderen Rechte
// durchsetzt. Wer es auslösen darf, kann bestimmen, welcher Code als root
// läuft — das ist keine gewöhnliche Schreiboperation.
func (s *Server) handleUpdateApply(w http.ResponseWriter, r *http.Request) {
	_, rel, _, running, _ := s.upd.snapshot()
	if running {
		s.renderUpdate(w, r, http.StatusConflict, "", "Es läuft bereits ein Update.")
		return
	}
	if rel.Version == "" {
		s.renderUpdate(w, r, http.StatusBadRequest, "", "Bitte zuerst nach Updates suchen.")
		return
	}
	if !update.Newer(version.Version, rel.Version) {
		s.renderUpdate(w, r, http.StatusBadRequest, "", "Es liegt keine neuere Fassung vor.")
		return
	}
	if !s.bestaetigt(w, r, bestaetigung{
		Titel: "Panel aktualisieren",
		Frage: "Das Panel von " + version.Version + " auf " + rel.Version + " aktualisieren?",
		Punkte: []string{
			"Der Dienst startet dabei neu — diese Seite ist einen Moment nicht erreichbar.",
			"Die laufende Fassung wird gesichert; ein Rückweg bleibt.",
		},
		Knopf:   "auf " + rel.Version + " aktualisieren",
		Abbruch: "/alt/update",
	}) {
		return
	}

	// Die angezeigte Fassung wird mitgegeben: Der Hintergrundlauf installiert
	// genau das, was der Auslöser gesehen hat, und nicht eine, die
	// zwischenzeitlich veröffentlicht wurde.
	s.startSelfUpdate(w, r, privops.SelfUpdateSpec{
		Channel: s.cfg.Updates.Channel,
		Version: rel.Version,
	}, rel.Version, "update.apply")
}

// handleUpdateRollback kehrt zur gesicherten Fassung zurück.
func (s *Server) handleUpdateRollback(w http.ResponseWriter, r *http.Request) {
	if _, _, _, running, _ := s.upd.snapshot(); running {
		s.renderUpdate(w, r, http.StatusConflict, "", "Es läuft bereits ein Update.")
		return
	}
	previous, err := s.previousVersion(r.Context())
	if err != nil {
		s.renderUpdate(w, r, http.StatusBadRequest, "", "Es liegt keine Sicherung einer vorherigen Fassung bereit.")
		return
	}
	if !s.bestaetigt(w, r, bestaetigung{
		Titel: "Zurück auf die vorherige Fassung",
		Frage: "Das Panel von " + version.Version + " zurück auf " + previous + " setzen?",
		Punkte: []string{
			"Der Dienst startet dabei neu — diese Seite ist einen Moment nicht erreichbar.",
			"Zurückgesetzt wird das Programm, nicht die Datenbank: Was neuere Fassungen an ihr geändert haben, bleibt.",
		},
		Knopf:   "zurück auf " + previous,
		Abbruch: "/alt/update",
	}) {
		return
	}

	s.startSelfUpdate(w, r, privops.SelfUpdateSpec{Rollback: true}, previous, "update.rollback")
}

func (s *Server) startSelfUpdate(w http.ResponseWriter, r *http.Request, spec privops.SelfUpdateSpec, target, action string) {
	binary, err := update.CurrentBinary()
	if err != nil {
		s.renderUpdate(w, r, http.StatusInternalServerError, "", "Der eigene Programmpfad ließ sich nicht bestimmen: "+err.Error())
		return
	}
	spec.Binary = binary
	spec.LogFile = s.updateLogPath()
	spec.Unit = fmt.Sprintf("asylum-update-%d", time.Now().UnixNano())

	if err := s.ops.SelfUpdateStart(r.Context(), spec); err != nil {
		s.audit(r, action, target, store.ResultError, err.Error())
		s.log.Error("selbstupdate anstoßen", "err", err)
		s.renderUpdate(w, r, http.StatusInternalServerError, "", "Der Vorgang ließ sich nicht starten: "+err.Error())
		return
	}
	s.upd.markStarted(target)
	s.audit(r, action, target, store.ResultOK, "über systemd-run angestoßen")

	flash := fmt.Sprintf("Der Vorgang läuft. Das Panel startet dabei neu — diese Seite meldet sich, sobald Fassung %s antwortet.", target)
	s.renderUpdate(w, r, http.StatusAccepted, flash, "")
}

// updateStatus ist die Antwort für den Poller der Oberfläche.
type updateStatus struct {
	Version string   `json:"version"`
	Running bool     `json:"running"`
	Target  string   `json:"target"`
	Lines   []string `json:"lines"`
}

// handleUpdateStatus liefert Fassung und Protokoll als JSON. Die Oberfläche
// fragt das im Sekundentakt ab, weil ein offener Kanal den Neustart des
// Dienstes naturgemäß nicht übersteht.
func (s *Server) handleUpdateStatus(w http.ResponseWriter, r *http.Request) {
	_, _, _, running, target := s.upd.snapshot()
	resp := updateStatus{
		Version: version.Version,
		Running: running,
		Target:  target,
		Lines:   tailFile(s.updateLogPath(), maxUpdateLogLines),
	}
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	_ = json.NewEncoder(w).Encode(resp)
	_ = r
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
