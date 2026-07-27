package config

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// schreibeConfig legt eine Hauptdatei an und liefert ihren Pfad.
func schreibeConfig(t *testing.T, inhalt string) string {
	t.Helper()
	dir := t.TempDir()
	pfad := filepath.Join(dir, "config.yaml")
	if err := os.WriteFile(pfad, []byte(inhalt), 0o600); err != nil {
		t.Fatal(err)
	}
	return pfad
}

// TestOhneErgaenzungenUnveraendert: Die allermeisten Installationen haben kein
// conf.d. Ein fehlendes Verzeichnis darf nichts kosten und nichts melden.
func TestOhneErgaenzungenUnveraendert(t *testing.T) {
	pfad := schreibeConfig(t, "server:\n  port: 9443\n")
	cfg, err := Load(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Server.Port != 9443 || cfg.Server.TLS.Mode != TLSModeSelfSigned {
		t.Errorf("unerwartet: %+v", cfg.Server)
	}
}

// TestErgaenzungUeberschreibtHauptdatei ist der Kern: Was im Panel eingestellt
// wurde, gilt — auch wenn in der Hauptdatei noch etwas anderes steht.
func TestErgaenzungUeberschreibtHauptdatei(t *testing.T) {
	pfad := schreibeConfig(t, "server:\n  tls:\n    mode: selfsigned\n")

	if err := WriteManagedTLS(pfad, TLSSettings{
		Mode: TLSModeACME,
		ACME: ACME{
			Email:   "admin@example.org",
			Domains: []string{"panel.example.org"},
		},
	}); err != nil {
		t.Fatal(err)
	}

	cfg, err := Load(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Server.TLS.Mode != TLSModeACME {
		t.Errorf("Modus = %q, erwartet %q", cfg.Server.TLS.Mode, TLSModeACME)
	}
	if cfg.ACME.Email != "admin@example.org" {
		t.Errorf("E-Mail = %q", cfg.ACME.Email)
	}
	if len(cfg.ACME.Domains) != 1 || cfg.ACME.Domains[0] != "panel.example.org" {
		t.Errorf("Domains = %v", cfg.ACME.Domains)
	}
	// Die Hauptdatei bleibt unangetastet — sie gehört dem Betreiber.
	haupt, err := os.ReadFile(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(haupt), "mode: selfsigned") {
		t.Error("die Hauptdatei wurde verändert")
	}
}

// Die Pfade zu Zertifikat und Schlüssel stehen in der Hauptdatei und dürfen von
// der Ergänzung nicht mitgerissen werden: Sie ist absichtlich ein Ausschnitt.
func TestErgaenzungLaesstUebrigeWerteStehen(t *testing.T) {
	pfad := schreibeConfig(t, `server:
  port: 9443
  tls:
    cert: /eigener/pfad.crt
    key: /eigener/pfad.key
paths:
  data: /var/lib/eigen
`)
	if err := WriteManagedTLS(pfad, TLSSettings{
		Mode: TLSModeACME,
		ACME: ACME{Email: "admin@example.org"},
	}); err != nil {
		t.Fatal(err)
	}
	cfg, err := Load(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Server.TLS.Cert != "/eigener/pfad.crt" || cfg.Server.TLS.Key != "/eigener/pfad.key" {
		t.Errorf("TLS-Pfade verloren: %+v", cfg.Server.TLS)
	}
	if cfg.Server.Port != 9443 || cfg.Paths.Data != "/var/lib/eigen" {
		t.Errorf("übrige Werte verloren: %+v %+v", cfg.Server, cfg.Paths)
	}
}

// Mehrere Ergänzungen gelten in Namensreihenfolge; die höhere Nummer gewinnt.
// Das ist die Zusage an Betreiber, die eigene Dateien danebenlegen.
func TestErgaenzungenInNamensreihenfolge(t *testing.T) {
	pfad := schreibeConfig(t, "")
	dir := ConfDir(pfad)
	if err := os.MkdirAll(dir, 0o750); err != nil {
		t.Fatal(err)
	}
	for name, inhalt := range map[string]string{
		"10-tls.yaml":   "acme:\n  email: aus-10@example.org\n",
		"90-eigen.yaml": "acme:\n  email: aus-90@example.org\n",
	} {
		if err := os.WriteFile(filepath.Join(dir, name), []byte(inhalt), 0o600); err != nil {
			t.Fatal(err)
		}
	}

	cfg, err := Load(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.ACME.Email != "aus-90@example.org" {
		t.Errorf("E-Mail = %q, erwartet die aus der Datei mit der höheren Nummer", cfg.ACME.Email)
	}
}

// Eine kaputte Ergänzung darf nicht stillschweigend übergangen werden: Der
// Dienst liefe sonst mit anderen TLS-Einstellungen als angezeigt.
func TestKaputteErgaenzungMeldetSich(t *testing.T) {
	pfad := schreibeConfig(t, "")
	dir := ConfDir(pfad)
	if err := os.MkdirAll(dir, 0o750); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "10-tls.yaml"), []byte("acme:\n  unbekannt: ja\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := Load(pfad); err == nil {
		t.Fatal("ein unbekanntes Feld wurde stillschweigend geschluckt")
	} else if !strings.Contains(err.Error(), "10-tls.yaml") {
		t.Errorf("die Fehlermeldung nennt die Datei nicht: %v", err)
	}
}

// TestWriteManagedTLSSchreibtAtomar: Ein abgebrochener Schreibvorgang darf
// keine halbe Datei hinterlassen — sonst startet der Dienst beim nächsten Mal
// nicht mehr, ausgerechnet wegen einer Einstellung für bessere Erreichbarkeit.
func TestWriteManagedTLSSchreibtAtomar(t *testing.T) {
	pfad := schreibeConfig(t, "")
	if err := WriteManagedTLS(pfad, TLSSettings{
		Mode: TLSModeACME,
		ACME: ACME{Email: "admin@example.org"},
	}); err != nil {
		t.Fatal(err)
	}

	dir := ConfDir(pfad)
	eintraege, err := os.ReadDir(dir)
	if err != nil {
		t.Fatal(err)
	}
	if len(eintraege) != 1 || eintraege[0].Name() != ManagedTLSFile {
		namen := []string{}
		for _, e := range eintraege {
			namen = append(namen, e.Name())
		}
		t.Errorf("im Verzeichnis liegt: %v — erwartet nur %s", namen, ManagedTLSFile)
	}

	info, err := os.Stat(ManagedTLSPath(pfad))
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o640 {
		t.Errorf("Rechte = %o, erwartet 640", info.Mode().Perm())
	}

	raw, err := os.ReadFile(ManagedTLSPath(pfad))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(string(raw), "# Vom Panel verwaltet") {
		t.Error("der Datei fehlt der Hinweis, dass sie überschrieben wird")
	}
}

// Zweimal Schreiben ersetzt, statt anzuhängen.
func TestWriteManagedTLSErsetzt(t *testing.T) {
	pfad := schreibeConfig(t, "")
	for _, mail := range []string{"erst@example.org", "dann@example.org"} {
		if err := WriteManagedTLS(pfad, TLSSettings{
			Mode: TLSModeACME, ACME: ACME{Email: mail},
		}); err != nil {
			t.Fatal(err)
		}
	}
	cfg, err := Load(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.ACME.Email != "dann@example.org" {
		t.Errorf("E-Mail = %q", cfg.ACME.Email)
	}
}

// TestLeereHauptdateiIstKeinFehler: Eine versehentlich geleerte Datei mit
// "EOF" abzulehnen ist eine Meldung, aus der niemand schlau wird — und der
// Dienst startet dann gar nicht mehr.
func TestLeereHauptdateiIstKeinFehler(t *testing.T) {
	cfg, err := Load(schreibeConfig(t, ""))
	if err != nil {
		t.Fatalf("leere Datei: %v", err)
	}
	if cfg.Server.Port != Default().Server.Port {
		t.Errorf("Vorgaben nicht übernommen: %+v", cfg.Server)
	}
}
