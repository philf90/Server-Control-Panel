package httpd

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/store"
	"github.com/philf90/asylum/internal/update"
	"github.com/philf90/asylum/internal/version"
)

// metadataServer liefert eine Kanaldatei über HTTPS.
func metadataServer(t *testing.T, body string) *httptest.Server {
	t.Helper()
	srv := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasPrefix(r.URL.Path, "/updates/") {
			http.NotFound(w, r)
			return
		}
		_, _ = w.Write([]byte(body))
	}))
	t.Cleanup(srv.Close)
	return srv
}

const testChannelJSON = `{
  "version": "9.9.9",
  "released_at": "2026-07-26T12:00:00Z",
  "min_upgradable_from": "0.0.1",
  "notes_url": "https://example.invalid/notes",
  "severity": "security",
  "artifacts": {
    "linux_amd64": {"url": "https://example.invalid/a.tar.gz",
      "sha256": "0000000000000000000000000000000000000000000000000000000000000000"},
    "linux_arm64": {"url": "https://example.invalid/b.tar.gz",
      "sha256": "0000000000000000000000000000000000000000000000000000000000000000"}
  }
}`

// updateServer verdrahtet den Server mit einem eigenen Metadatenserver.
func updateServer(t *testing.T, body string) (*Server, *fakeOps) {
	t.Helper()
	// Ein selbst gebautes Binary meldet "dev" und bekommt bewusst kein
	// Update angeboten — für den Test muss also eine echte Fassung gesetzt
	// sein. Siehe TestUpdateEntwicklungsfassung für den umgekehrten Fall.
	setVersion(t, "0.1.0")

	s, ops := newSystemServer(t)
	meta := metadataServer(t, body)
	s.cfg.Updates.BaseURL = meta.URL
	s.cfg.Updates.Channel = "stable"
	// Der Client des Testservers kennt dessen Zertifikat. So bleibt die
	// Prüfung im Produktivcode unangetastet.
	s.updHTTP = meta.Client()
	return s, ops
}

// setVersion setzt die Fassung für die Dauer eines Tests.
func setVersion(t *testing.T, v string) {
	t.Helper()
	previous := version.Version
	version.Version = v
	t.Cleanup(func() { version.Version = previous })
}

// TestUpdateEntwicklungsfassung hält fest, dass ein selbst gebautes Binary
// nicht über den Kanal aktualisiert wird. Was dort läge, wüsste niemand.
func TestUpdateEntwicklungsfassung(t *testing.T) {
	s, ops := updateServer(t, testChannelJSON)
	setVersion(t, "dev")

	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	if rec := post(t, s, "/alt/update/check", url.Values{"_csrf": {csrf}}, cookie); rec.Code != http.StatusOK {
		t.Fatalf("check: %d", rec.Code)
	}
	rec := post(t, s, "/alt/update/apply", url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("Status = %d, erwartet 400", rec.Code)
	}
	if len(ops.selfUpdates) != 0 {
		t.Error("eine Entwicklungsfassung wurde zum Update angeboten")
	}
}

func TestUpdateSeiteZeigtFassung(t *testing.T) {
	s, _ := updateServer(t, testChannelJSON)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	rec := get(t, s, "/alt/update", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d", rec.Code)
	}
	body := rec.Body.String()
	for _, want := range []string{version.Version, "Kanal stable", "noch nicht geprüft"} {
		if !strings.Contains(body, want) {
			t.Errorf("Seite enthält %q nicht", want)
		}
	}
}

func TestUpdateCheckFindetFassung(t *testing.T) {
	s, _ := updateServer(t, testChannelJSON)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/alt/update/check", url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	body := rec.Body.String()
	if !strings.Contains(body, "9.9.9") {
		t.Error("die gefundene Fassung erscheint nicht auf der Seite")
	}
	if !strings.Contains(body, "Sicherheitsupdate") {
		t.Error("die Einstufung als Sicherheitsupdate fehlt")
	}
}

func TestUpdateCheckMeldetUnerreichbareQuelle(t *testing.T) {
	s, _ := updateServer(t, "kein json")
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/alt/update/check", url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), "nicht erreichbar") {
		t.Error("der Fehler wird nicht angezeigt")
	}
}

func TestUpdateApplyStoesstVorgangAn(t *testing.T) {
	s, ops := updateServer(t, testChannelJSON)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	// Ohne vorherige Prüfung gibt es nichts einzuspielen.
	rec := post(t, s, "/alt/update/apply", url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("Status = %d, erwartet 400", rec.Code)
	}
	if len(ops.selfUpdates) != 0 {
		t.Fatal("es wurde ohne Prüfung ein Vorgang gestartet")
	}

	if rec := post(t, s, "/alt/update/check", url.Values{"_csrf": {csrf}}, cookie); rec.Code != http.StatusOK {
		t.Fatalf("check: Status = %d", rec.Code)
	}
	rec = post(t, s, "/alt/update/apply", ja(url.Values{"_csrf": {csrf}}), cookie)
	if rec.Code != http.StatusAccepted {
		t.Fatalf("Status = %d, erwartet 202: %s", rec.Code, rec.Body.String())
	}

	if len(ops.selfUpdates) != 1 {
		t.Fatalf("%d Vorgänge gestartet, erwartet 1", len(ops.selfUpdates))
	}
	spec := ops.selfUpdates[0]
	if spec.Version != "9.9.9" {
		t.Errorf("Zielfassung = %q, erwartet 9.9.9", spec.Version)
	}
	if spec.Channel != "stable" {
		t.Errorf("Kanal = %q", spec.Channel)
	}
	if spec.Rollback {
		t.Error("es wurde ein Rollback statt eines Updates angefordert")
	}
	if !filepath.IsAbs(spec.LogFile) || !strings.HasSuffix(spec.LogFile, updateLogName) {
		t.Errorf("Protokolldatei = %q", spec.LogFile)
	}
	if !strings.HasPrefix(spec.Unit, "asylum-update-") {
		t.Errorf("Unit-Name = %q", spec.Unit)
	}

	// Ein zweiter Anstoß muss abgewiesen werden, solange der erste läuft.
	rec = post(t, s, "/alt/update/apply", ja(url.Values{"_csrf": {csrf}}), cookie)
	if rec.Code != http.StatusConflict {
		t.Errorf("zweiter Anstoß: Status = %d, erwartet 409", rec.Code)
	}
	if len(ops.selfUpdates) != 1 {
		t.Errorf("%d Vorgänge gestartet, erwartet 1", len(ops.selfUpdates))
	}
}

func TestUpdateApplyLehntAeltereFassungAb(t *testing.T) {
	// Der Kanal meldet eine ältere Fassung als die laufende — eine
	// manipulierte oder zurückgedrehte Metadatendatei. Ein Downgrade darf
	// daraus nicht werden.
	body := strings.Replace(testChannelJSON, `"version": "9.9.9"`, `"version": "0.0.2"`, 1)
	s, ops := updateServer(t, body)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	if rec := post(t, s, "/alt/update/check", url.Values{"_csrf": {csrf}}, cookie); rec.Code != http.StatusOK {
		t.Fatalf("check: %d", rec.Code)
	}
	rec := post(t, s, "/alt/update/apply", url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("Status = %d, erwartet 400", rec.Code)
	}
	if len(ops.selfUpdates) != 0 {
		t.Error("es wurde trotzdem ein Vorgang gestartet")
	}
}

func TestUpdateRollbackOhneSicherung(t *testing.T) {
	s, ops := updateServer(t, testChannelJSON)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	rec := post(t, s, "/alt/update/rollback", url.Values{"_csrf": {csrf}}, cookie)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("Status = %d, erwartet 400", rec.Code)
	}
	if len(ops.selfUpdates) != 0 {
		t.Error("ohne Sicherung wurde ein Rollback gestartet")
	}
}

// TestUpdateRollenTrennung ist der Kern: Ein Update tauscht das Programm aus,
// das alle übrigen Rechte durchsetzt. Admin darf zwar Dienste neu starten,
// aber nicht bestimmen, welcher Code als root läuft.
func TestUpdateRollenTrennung(t *testing.T) {
	tests := []struct {
		role       string
		check      int
		apply      int
		rollback   int
		sichtbar   bool
		beschreibt string
	}{
		{store.RoleReadOnly, http.StatusForbidden, http.StatusForbidden, http.StatusForbidden, true, "nur lesen"},
		{store.RoleAdmin, http.StatusOK, http.StatusForbidden, http.StatusForbidden, true, "suchen, nicht einspielen"},
	}

	for _, tc := range tests {
		t.Run(tc.role, func(t *testing.T) {
			s, ops := updateServer(t, testChannelJSON)
			user := addUser(t, s, "konto", tc.role)
			cookie, csrf := login(t, s, user)

			if rec := get(t, s, "/alt/update", cookie); (rec.Code == http.StatusOK) != tc.sichtbar {
				t.Errorf("GET /update: Status = %d", rec.Code)
			}
			form := url.Values{"_csrf": {csrf}}
			if rec := post(t, s, "/alt/update/check", form, cookie); rec.Code != tc.check {
				t.Errorf("check: Status = %d, erwartet %d", rec.Code, tc.check)
			}
			if rec := post(t, s, "/alt/update/apply", form, cookie); rec.Code != tc.apply {
				t.Errorf("apply: Status = %d, erwartet %d", rec.Code, tc.apply)
			}
			if rec := post(t, s, "/alt/update/rollback", form, cookie); rec.Code != tc.rollback {
				t.Errorf("rollback: Status = %d, erwartet %d", rec.Code, tc.rollback)
			}
			if len(ops.selfUpdates) != 0 {
				t.Errorf("%s: es wurde ein Vorgang gestartet", tc.beschreibt)
			}
		})
	}
}

func TestUpdateStatusLiefertProtokoll(t *testing.T) {
	s, _ := updateServer(t, testChannelJSON)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	logPath := s.updateLogPath()
	if err := os.MkdirAll(filepath.Dir(logPath), 0o750); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(logPath, []byte("12:00:00  archiv wird geladen\n\n12:00:05  fertig\n"), 0o640); err != nil {
		t.Fatal(err)
	}

	rec := get(t, s, "/alt/update/status", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d", rec.Code)
	}
	var got updateStatus
	if err := json.Unmarshal(rec.Body.Bytes(), &got); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	if got.Version != version.Version {
		t.Errorf("Version = %q", got.Version)
	}
	if len(got.Lines) != 2 {
		t.Fatalf("%d Zeilen, erwartet 2 (Leerzeilen fallen weg): %v", len(got.Lines), got.Lines)
	}
	if !strings.Contains(got.Lines[1], "fertig") {
		t.Errorf("letzte Zeile = %q", got.Lines[1])
	}
}

func TestTailFile(t *testing.T) {
	dir := t.TempDir()

	// Eine fehlende Datei ist der Normalfall vor dem ersten Update.
	if lines := tailFile(filepath.Join(dir, "gibt-es-nicht"), 10); lines != nil {
		t.Errorf("= %v, erwartet nil", lines)
	}

	path := filepath.Join(dir, "update.log")
	var sb strings.Builder
	for i := range 50 {
		sb.WriteString("zeile ")
		sb.WriteString(string(rune('a' + i%26)))
		sb.WriteString("\n")
	}
	if err := os.WriteFile(path, []byte(sb.String()), 0o600); err != nil {
		t.Fatal(err)
	}
	lines := tailFile(path, 10)
	if len(lines) != 10 {
		t.Fatalf("%d Zeilen, erwartet 10", len(lines))
	}
	if lines[9] != "zeile x" {
		t.Errorf("letzte Zeile = %q", lines[9])
	}
}

// TestUpdateSeiteImNavigationsmenue stellt sicher, dass die Seite erreichbar
// beworben wird — eine Update-Funktion, die niemand findet, ist keine.
func TestUpdateSeiteImNavigationsmenue(t *testing.T) {
	s, _ := updateServer(t, testChannelJSON)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	rec := get(t, s, "/alt/", cookie)
	if !strings.Contains(rec.Body.String(), `href="/alt/update"`) {
		t.Error("die Übersicht verlinkt die Update-Seite nicht")
	}
}

func TestUpdateStateFortschritt(t *testing.T) {
	st := newUpdateState()
	if _, _, _, running, _ := st.snapshot(); running {
		t.Error("frisch angelegt darf nichts laufen")
	}
	st.setResult(update.Release{Version: "1.0.0"}, nil)
	if _, rel, errMsg, _, _ := st.snapshot(); rel.Version != "1.0.0" || errMsg != "" {
		t.Errorf("= %q / %q", rel.Version, errMsg)
	}
	st.markStarted("1.0.0")
	if _, _, _, running, target := st.snapshot(); !running || target != "1.0.0" {
		t.Errorf("running=%v target=%q", running, target)
	}
}
