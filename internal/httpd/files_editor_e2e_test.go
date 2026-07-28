package httpd

import (
	"encoding/json"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/store"
)

// TestFilesEditorBrowser fährt den Editor in einem echten Browser.
//
// Der Grund ist eine Frage, die kein Go-Test beantwortet: Läuft CodeMirror unter
// der Content-Security-Policy des Panels? Sie erlaubt keine inline-Stile, und
// CodeMirror trägt seine Regeln zur Laufzeit ein. Geschieht das über CSSOM, ist
// es erlaubt; über ein style-Attribut wäre es verworfen — und der Editor sähe
// aus wie eine kaputte Textarea. Das Projekt ist an genau dieser Stelle schon
// einmal gescheitert (Auslastungsbalken in rc.5).
//
// Bewusst hinter einer Umgebungsvariablen: Der Test braucht Node und Chromium
// und läuft nicht in jeder CI. Aufruf:
//
//	ASYLUM_FILES_E2E=1 \
//	  ASYLUM_NODE=/opt/node22/bin/node \
//	  ASYLUM_NODE_PATH=/opt/node22/lib/node_modules \
//	  ASYLUM_CHROMIUM=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
//	  go test ./internal/httpd -run TestFilesEditorBrowser -v
func TestFilesEditorBrowser(t *testing.T) {
	if os.Getenv("ASYLUM_FILES_E2E") == "" {
		t.Skip("ohne ASYLUM_FILES_E2E nichts zu tun (braucht Node und Chromium)")
	}
	chromium := os.Getenv("ASYLUM_CHROMIUM")
	if chromium == "" {
		t.Skip("ASYLUM_CHROMIUM (Pfad zum Browser) nicht gesetzt")
	}
	node := envOr("ASYLUM_NODE", "node")

	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	// Eine YAML-Datei: Sie deckt die Hervorhebung mit ab, und YAML ist der
	// häufigste Fall in diesem Panel.
	pfad := filepath.Join(wurzel, "schreibbar", "config.yaml")
	inhalt := "server:\n  bind: 0.0.0.0\n  port: 8443\nlog:\n  level: info\n"
	lege(t, pfad, inhalt)

	ts := httptest.NewServer(s.Handler())
	defer ts.Close()

	cmd := exec.Command(node, "testdata/files_editor_e2e.js")
	cmd.Env = append(os.Environ(),
		"ASYLUM_E2E_URL="+ts.URL,
		"ASYLUM_E2E_COOKIE="+cookie.Name+"="+cookie.Value,
		"ASYLUM_E2E_PATH="+pfad,
		"ASYLUM_CHROMIUM="+chromium,
	)
	if p := os.Getenv("ASYLUM_NODE_PATH"); p != "" {
		cmd.Env = append(cmd.Env, "NODE_PATH="+p)
	}

	ausgabe, err := cmd.CombinedOutput()
	t.Logf("Treiber:\n%s", ausgabe)
	if err != nil {
		t.Fatalf("Browsertreiber: %v", err)
	}

	var ergebnis struct {
		Zeilennummern int                                 `json:"zeilennummern"`
		InhaltVorher  string                              `json:"inhaltVorher"`
		InhaltNachher string                              `json:"inhaltNachher"`
		Stil          struct{ FontFamily, Gutter string } `json:"stil"`
		Meldung       string                              `json:"meldung"`
		Verstoesse    []string                            `json:"verstoesse"`
	}
	letzte := letzteZeile(string(ausgabe))
	if err := json.Unmarshal([]byte(letzte), &ergebnis); err != nil {
		t.Fatalf("Ausgabe des Treibers unlesbar: %v — %q", err, letzte)
	}

	// 1. Die Richtlinie hat nichts verworfen.
	if len(ergebnis.Verstoesse) > 0 {
		t.Errorf("die Content-Security-Policy hat etwas verworfen:\n  %s",
			strings.Join(ergebnis.Verstoesse, "\n  "))
	}

	// 2. Der Editor ist wirklich einer: Zeilennummern und eigene Stile.
	if ergebnis.Zeilennummern < 5 {
		t.Errorf("%d Zeilennummern, erwartet mindestens 5 — CodeMirror ist nicht angelaufen", ergebnis.Zeilennummern)
	}
	if !strings.Contains(strings.ToLower(ergebnis.Stil.FontFamily), "mono") {
		t.Errorf("die Schrift des Editors ist %q — die eingetragenen Stile sind nicht angekommen", ergebnis.Stil.FontFamily)
	}
	if ergebnis.Stil.Gutter == "" || ergebnis.Stil.Gutter == "0px" {
		t.Errorf("die Randspalte hat keine Trennlinie (%q) — das Thema ist nicht angekommen", ergebnis.Stil.Gutter)
	}
	if !strings.Contains(ergebnis.InhaltVorher, "server:") {
		t.Errorf("der Editor zeigt den Dateiinhalt nicht: %q", ergebnis.InhaltVorher)
	}

	// 3. Das Speichern kam beim Server an — und zwar auf der Platte.
	if !strings.Contains(ergebnis.Meldung, "Gespeichert") {
		t.Errorf("die Seite meldet kein Speichern: %q", ergebnis.Meldung)
	}
	roh, err := os.ReadFile(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(string(roh), "# vom Browser eingefügt\n") {
		t.Errorf("die Datei beginnt mit %q — die Änderung des Editors ist nicht angekommen",
			ersteZeile(string(roh)))
	}
	if !strings.Contains(string(roh), "port: 8443") {
		t.Error("der übrige Inhalt ist beim Speichern verloren gegangen")
	}
}

func letzteZeile(s string) string {
	zeilen := strings.Split(strings.TrimSpace(s), "\n")
	return zeilen[len(zeilen)-1]
}

func ersteZeile(s string) string {
	if i := strings.IndexByte(s, '\n'); i >= 0 {
		return s[:i]
	}
	return s
}
