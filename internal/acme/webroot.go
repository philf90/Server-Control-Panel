package acme

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
)

// webrootSolver beantwortet die HTTP-01-Prüfung, ohne selbst zu lauschen: Er
// legt die Antwort als Datei in ein Verzeichnis, aus dem ein Webserver sie
// ausliefert.
//
// Warum es ihn neben http01Solver gibt. Der andere öffnet Port 80 selbst. Das
// geht genau so lange gut, wie dort niemand sonst hört — und mit dem Modul
// Webserver (Stufe 0.6) spielt das Panel nginx ein, das den Port danach hält.
// Die Erneuerung des eigenen Zertifikats schlüge dann fehl, und zwar nicht
// sofort, sondern beim nächsten Lauf sechzig Tage später. Ausführlich in
// docs/18-webserver.md §3.
//
// Der Weg ist der, den certbot seit jeher geht („webroot"): Nicht neben dem
// Webserver lauschen, sondern durch ihn hindurch antworten.
//
// Die Wahl zwischen beiden trifft nicht der Betreiber, sondern der Zustand des
// Servers — siehe solverFactory. Ein Schalter dafür wäre eine Einstellung, die
// jemand falsch setzen kann, und deren richtiger Wert aus dem System ablesbar
// ist.
type webrootSolver struct {
	// dir ist die Wurzel, aus der der Webserver ausliefert. Der Unterpfad
	// darunter ist nicht frei wählbar: Er muss der Adresse entsprechen, unter
	// der die CA fragt.
	dir string
}

func newWebrootSolver(dir string) *webrootSolver { return &webrootSolver{dir: dir} }

func (s *webrootSolver) challengeType() string { return "http-01" }

// present legt die Antwort ab.
//
// Das Verzeichnis wird bei jedem Aufruf angelegt und nicht einmal beim Start:
// Zwischen zwei Erneuerungen liegen zwei Monate, und in dieser Zeit kann es
// jemand aufgeräumt haben.
func (s *webrootSolver) present(_ context.Context, _, token, value string) error {
	pfad, err := s.tokenPfad(token)
	if err != nil {
		return err
	}
	// 0755 und nicht 0750: Der Webserver läuft als www-data und muss durch das
	// Verzeichnis hindurch. Was darin liegt, ist ein Challenge-Token — die CA
	// fragt es im nächsten Augenblick über das offene Netz ab, es ist also
	// öffentlich, bevor es dort landet.
	if err := os.MkdirAll(filepath.Dir(pfad), 0o755); err != nil { //nolint:gosec // muss für den Webserver begehbar sein
		return fmt.Errorf("%s: %w", filepath.Dir(pfad), err)
	}
	// 0644: Der Webserver läuft als www-data und muss lesen können. Der Inhalt
	// ist ohnehin öffentlich — die CA fragt ihn gleich über das Netz ab.
	if err := os.WriteFile(pfad, []byte(value), 0o644); err != nil { //nolint:gosec // wird vom Webserver ausgeliefert
		return fmt.Errorf("%s: %w", pfad, err)
	}
	return nil
}

// cleanup entfernt die Antwort wieder.
//
// Ein fehlender Eintrag ist kein Fehler: cleanup läuft auch auf dem Weg heraus
// aus einem gescheiterten Bezug, und dann kann present nie gelaufen sein.
func (s *webrootSolver) cleanup(_ context.Context, _, token, _ string) error {
	pfad, err := s.tokenPfad(token)
	if err != nil {
		return err
	}
	if err := os.Remove(pfad); err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("%s: %w", pfad, err)
	}
	return nil
}

// tokenPfad baut den Pfad zur Antwortdatei — und prüft dabei den Token.
//
// Das ist die sicherheitskritische Zeile dieser Datei. Der Token kommt vom
// ACME-Server, also von außen, und er wird hier zu einem DATEINAMEN. Ein Token
// „../../etc/nginx/conf.d/boes.conf" schriebe eine Webserverkonfiguration; das
// Panel läuft als root, und niemand hielte es auf.
//
// Geprüft wird gegen das Alphabet, das RFC 8555 für Token vorschreibt
// (base64url ohne Auffüllzeichen) — nicht gegen eine Sperrliste mit „..", und
// auch nicht mit filepath.Clean hinterher. Eine Allowlist ist hier die einzige
// Form, die nichts übersieht: Was nicht ausdrücklich erlaubt ist, ist abgelehnt.
func (s *webrootSolver) tokenPfad(token string) (string, error) {
	if err := pruefeToken(token); err != nil {
		return "", err
	}
	return filepath.Join(s.dir, ".well-known", "acme-challenge", token), nil
}

// maxTokenLaenge begrenzt den Dateinamen. RFC 8555 nennt keine Obergrenze; 128
// Zeichen sind weit über dem, was CAs ausgeben (typisch 43), und weit unter dem,
// woran ein Dateisystem scheitert.
const maxTokenLaenge = 128

func pruefeToken(token string) error {
	if token == "" {
		return fmt.Errorf("leerer Challenge-Token")
	}
	if len(token) > maxTokenLaenge {
		return fmt.Errorf("Challenge-Token länger als %d Zeichen", maxTokenLaenge)
	}
	for _, r := range token {
		switch {
		case r >= 'A' && r <= 'Z',
			r >= 'a' && r <= 'z',
			r >= '0' && r <= '9',
			r == '-', r == '_':
			continue
		}
		return fmt.Errorf("unzulässiges Zeichen %q im Challenge-Token", string(r))
	}
	return nil
}
