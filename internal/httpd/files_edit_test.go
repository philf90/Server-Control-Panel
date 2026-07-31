package httpd

import (
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// hashVon liest den Hash, den die Editor-Seite ins Formular schreibt. Ohne ihn
// könnte ein Test nicht so speichern, wie der Browser es tut.
func hashVon(t *testing.T, body string) string {
	t.Helper()
	const marke = `name="hash" value="`
	i := strings.Index(body, marke)
	if i < 0 {
		t.Fatalf("die Seite enthält kein Hash-Feld:\n%s", truncate(body, 600))
	}
	rest := body[i+len(marke):]
	j := strings.IndexByte(rest, '"')
	if j < 0 {
		t.Fatal("das Hash-Feld ist nicht abgeschlossen")
	}
	return rest[:j]
}

func TestFilesEditorZeigtUndSpeichert(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	pfad := filepath.Join(wurzel, "schreibbar", "config.yaml")
	lege(t, pfad, "server:\n  port: 8443\n")

	rec := get(t, s, "/alt/files/edit?path="+urlWert(pfad), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d — %s", rec.Code, rec.Body.String())
	}
	body := rec.Body.String()
	if !strings.Contains(body, "server:") {
		t.Error("der Inhalt steht nicht in der Seite")
	}
	if !strings.Contains(body, `data-sprache="yaml"`) {
		t.Error("die Sprache für die Hervorhebung fehlt")
	}
	if !strings.Contains(body, "/static/editor/cm.js") {
		t.Error("der Editor wird nicht eingebunden")
	}

	// Die Antwort erlaubt genau ein Stil-Element per Nonce — und nicht
	// 'unsafe-inline'.
	csp := rec.Header().Get("Content-Security-Policy")
	if !strings.Contains(csp, "style-src 'self' 'nonce-") {
		t.Errorf("die Richtlinie dieser Antwort hat keinen Nonce: %q", csp)
	}
	if strings.Contains(csp, "unsafe-inline") {
		t.Errorf("die Richtlinie erlaubt unsafe-inline: %q", csp)
	}

	// Der Nonce steht auch in der Seite und ist derselbe.
	if !strings.Contains(body, `data-nonce="`) {
		t.Error("der Nonce fehlt in der Seite")
	}

	hash := hashVon(t, body)
	rec = post(t, s, "/alt/files/save", formular(csrf,
		"path", pfad, "hash", hash, "content", "server:\n  port: 9443\n"), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("speichern: Status %d — %s", rec.Code, rec.Body.String())
	}
	roh, err := os.ReadFile(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if string(roh) != "server:\n  port: 9443\n" {
		t.Errorf("Datei nach dem Speichern: %q", roh)
	}
}

// TestFilesEditorErkenntFremdeAenderung ist Regel 6 aus der Architektur: Eine
// von außen geänderte Datei wird nicht stillschweigend überschrieben.
func TestFilesEditorErkenntFremdeAenderung(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	pfad := filepath.Join(wurzel, "schreibbar", "config.yaml")
	lege(t, pfad, "a: 1\n")

	rec := get(t, s, "/alt/files/edit?path="+urlWert(pfad), cookie)
	hash := hashVon(t, rec.Body.String())

	// Jemand anders ändert die Datei, während der Editor offen ist.
	lege(t, pfad, "a: 2\n")

	rec = post(t, s, "/alt/files/save", formular(csrf,
		"path", pfad, "hash", hash, "content", "a: 3\n"), cookie)
	if rec.Code != http.StatusConflict {
		t.Fatalf("Status %d, erwartet 409 — %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "zwischenzeitlich") {
		t.Error("die Seite erklärt den Konflikt nicht")
	}
	// Die eigene Fassung bleibt im Feld stehen.
	if !strings.Contains(rec.Body.String(), "a: 3") {
		t.Error("die eigene Fassung ist verloren — das wäre die schlechteste Antwort")
	}
	// Und die fremde Änderung steht unangetastet auf der Platte.
	roh, _ := os.ReadFile(pfad)
	if string(roh) != "a: 2\n" {
		t.Errorf("die fremde Änderung wurde überschrieben: %q", roh)
	}

	// Mit dem neuen Hash aus der Konfliktseite geht es bewusst weiter.
	neuerHash := hashVon(t, rec.Body.String())
	rec = post(t, s, "/alt/files/save", formular(csrf,
		"path", pfad, "hash", neuerHash, "content", "a: 3\n"), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("zweiter Versuch: Status %d — %s", rec.Code, rec.Body.String())
	}
	roh, _ = os.ReadFile(pfad)
	if string(roh) != "a: 3\n" {
		t.Errorf("nach dem bewussten Überschreiben: %q", roh)
	}
}

// TestFilesEditorRolltNachAbgelehnterPruefungZurueck ist Regel 5: Ein Tippfehler
// in einer Konfigurationsdatei, die sich prüfen lässt, darf nicht stehen bleiben.
func TestFilesEditorRolltNachAbgelehnterPruefungZurueck(t *testing.T) {
	s, wurzel := newFilesServer(t)
	ops := newFakeOps()
	s.ops = ops
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	pfad := filepath.Join(wurzel, "schreibbar", "sshd_config")
	lege(t, pfad, "Port 22\n")

	rec := get(t, s, "/alt/files/edit?path="+urlWert(pfad), cookie)
	hash := hashVon(t, rec.Body.String())

	// Das Prüfprogramm lehnt ab.
	ops.mu.Lock()
	ops.configCheck = privops.ConfigCheckResult{
		Checked: true, OK: false, Tool: "sshd -t",
		Output: "/etc/ssh/sshd_config: line 1: Bad configuration option: Prt",
	}
	ops.mu.Unlock()

	rec = post(t, s, "/alt/files/save", formular(csrf,
		"path", pfad, "hash", hash, "content", "Prt 22\n"), cookie)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("Status %d, erwartet 400 — %s", rec.Code, rec.Body.String())
	}
	body := rec.Body.String()
	for _, erwartet := range []string{"sshd -t", "Bad configuration option", "wiederhergestellt"} {
		if !strings.Contains(body, erwartet) {
			t.Errorf("die Seite enthält %q nicht", erwartet)
		}
	}

	// Der Vorzustand liegt wieder da.
	roh, err := os.ReadFile(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if string(roh) != "Port 22\n" {
		t.Errorf("nach dem Rückweg: %q, erwartet den Vorzustand", roh)
	}

	// Beides steht im Audit-Log: die Ablehnung und der Rückweg.
	eintraege, err := s.db.ListAudit(t.Context(), 20)
	if err != nil {
		t.Fatal(err)
	}
	var abgelehnt, zurueck bool
	for _, e := range eintraege {
		switch e.Action {
		case "files.edit":
			if e.Result == store.ResultError && strings.Contains(e.Detail, "sshd -t") {
				abgelehnt = true
			}
		case "files.edit.rollback":
			zurueck = true
		}
	}
	if !abgelehnt {
		t.Error("die Ablehnung steht nicht im Audit-Log")
	}
	if !zurueck {
		t.Error("der Rückweg steht nicht im Audit-Log")
	}
}

// TestFilesEditorEntferntNeueDateiNachAbgelehnterPruefung: Gab es vorher keine
// Datei, ist der Rückweg das Entfernen. Eine neue, kaputte Konfigurationsdatei
// liegen zu lassen wäre die schlechtere Antwort.
func TestFilesEditorEntferntNeueDateiNachAbgelehnterPruefung(t *testing.T) {
	s, wurzel := newFilesServer(t)
	ops := newFakeOps()
	s.ops = ops
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)
	arbeit := filepath.Join(wurzel, "schreibbar")

	// Eine neue Datei über den Editor anlegen.
	if rec := post(t, s, "/alt/files/touch", formular(csrf, "dir", arbeit, "name", "neu.conf"), cookie); rec.Code != http.StatusOK {
		t.Fatalf("touch: Status %d", rec.Code)
	}
	pfad := filepath.Join(arbeit, "neu.conf")
	if err := os.Remove(pfad); err != nil {
		t.Fatal(err)
	}

	ops.mu.Lock()
	ops.configCheck = privops.ConfigCheckResult{Checked: true, OK: false, Tool: "nft -c -f", Output: "syntax error"}
	ops.mu.Unlock()

	rec := post(t, s, "/alt/files/save", formular(csrf, "path", pfad, "content", "kaputt\n"), cookie)
	if rec.Code != http.StatusBadRequest {
		t.Fatalf("Status %d, erwartet 400 — %s", rec.Code, rec.Body.String())
	}
	if _, err := os.Stat(pfad); !os.IsNotExist(err) {
		t.Error("die abgelehnte neue Datei liegt noch da")
	}
	if !strings.Contains(rec.Body.String(), "wieder entfernt") {
		t.Error("die Seite sagt nicht, dass die Datei entfernt wurde")
	}
}

func TestFilesEditorMeldetAngenommenePruefung(t *testing.T) {
	s, wurzel := newFilesServer(t)
	ops := newFakeOps()
	ops.configCheck = privops.ConfigCheckResult{Checked: true, OK: true, Tool: "sshd -t"}
	s.ops = ops
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, csrf := login(t, s, user)

	pfad := filepath.Join(wurzel, "schreibbar", "sshd_config")
	lege(t, pfad, "Port 22\n")
	rec := get(t, s, "/alt/files/edit?path="+urlWert(pfad), cookie)

	rec = post(t, s, "/alt/files/save", formular(csrf,
		"path", pfad, "hash", hashVon(t, rec.Body.String()), "content", "Port 2222\n"), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d — %s", rec.Code, rec.Body.String())
	}
	if !strings.Contains(rec.Body.String(), "sshd -t") {
		t.Error("die Seite nennt das Prüfprogramm nicht")
	}
	if !strings.Contains(rec.Body.String(), "angenommen") {
		t.Error("die Seite sagt nicht, dass die Prüfung durchgelaufen ist")
	}
}

func TestFilesEditorBrauchtSchreibrechte(t *testing.T) {
	s, wurzel := newFilesServer(t)
	arbeit := filepath.Join(wurzel, "schreibbar")
	lege(t, filepath.Join(arbeit, "da.conf"), "x")
	lege(t, filepath.Join(wurzel, "nurlesbar", "fremd.conf"), "x")

	leser := addUser(t, s, "leser", store.RoleReadOnly)
	leserCookie, leserCSRF := login(t, s, leser)
	owner := addUser(t, s, "philipp", store.RoleOwner)
	ownerCookie, _ := login(t, s, owner)

	// Lesen darf die lesende Rolle, speichern nicht.
	rec := get(t, s, "/alt/files/edit?path="+urlWert(filepath.Join(arbeit, "da.conf")), leserCookie)
	if rec.Code != http.StatusOK {
		t.Errorf("Lesen: Status %d", rec.Code)
	}
	if strings.Contains(rec.Body.String(), `action="/alt/files/save"`) {
		t.Error("die lesende Rolle sieht das Speicherformular")
	}
	rec = post(t, s, "/alt/files/save", formular(leserCSRF,
		"path", filepath.Join(arbeit, "da.conf"), "content", "verändert"), leserCookie)
	if rec.Code != http.StatusForbidden {
		t.Errorf("Speichern als lesende Rolle: Status %d, erwartet 403", rec.Code)
	}

	// Außerhalb der Schreibbereiche bleibt der Editor eine Anzeige.
	rec = get(t, s, "/alt/files/edit?path="+urlWert(filepath.Join(wurzel, "nurlesbar", "fremd.conf")), ownerCookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status %d", rec.Code)
	}
	if strings.Contains(rec.Body.String(), `action="/alt/files/save"`) {
		t.Error("außerhalb der Schreibbereiche wird ein Speicherformular angeboten")
	}
	if !strings.Contains(rec.Body.String(), "außerhalb der Bereiche") {
		t.Error("die Begründung fehlt")
	}
}

func TestSpracheFuer(t *testing.T) {
	faelle := map[string]string{
		"/etc/asylum/config.yaml":           "yaml",
		"/etc/asylum/conf.d/10-tls.yml":     "yaml",
		"/srv/app/package.json":             "json",
		"/etc/nginx/nginx.conf":             "nginx",
		"/etc/nginx/sites-enabled/beispiel": "nginx",
		"/etc/ssh/sshd_config":              "ini",
		"/etc/fstab":                        "ini",
		"/etc/hosts":                        "ini",
		"/home/max/.bashrc":                 "shell",
		"/usr/local/bin/sichern.sh":         "shell",
		"/srv/app/Dockerfile":               "dockerfile",
		"/srv/app/pyproject.toml":           "toml",
		// Ohne Endung und ohne bekannten Namen gibt es keine Hervorhebung. Die
		// apt-Syntax mit Doppelpunkten und Semikolons passt in keinen der
		// mitgelieferten Modi — sie zu raten wäre schlechter, als sie zu lassen.
		"/etc/apt/apt.conf.d/50unattended": "",
		"/var/log/syslog":                  "",
		"/srv/daten/notiz.txt":             "",
	}
	for pfad, erwartet := range faelle {
		if got := spracheFuer(pfad); got != erwartet {
			t.Errorf("spracheFuer(%q) = %q, erwartet %q", pfad, got, erwartet)
		}
	}
}
