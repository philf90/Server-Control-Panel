package certs

import (
	"path/filepath"
	"testing"
)

func TestDescribeSelfSigned(t *testing.T) {
	dir := t.TempDir()
	cert := filepath.Join(dir, "server.crt")
	key := filepath.Join(dir, "server.key")
	if _, err := EnsurePair(cert, key, []string{"panel.example.test", "127.0.0.1"}); err != nil {
		t.Fatal(err)
	}

	info, err := Describe(cert)
	if err != nil {
		t.Fatal(err)
	}
	if !info.SelfSigned {
		t.Error("ein selbstsigniertes Zertifikat sollte als solches erkannt werden")
	}
	var sawName bool
	for _, n := range info.DNSNames {
		if n == "panel.example.test" {
			sawName = true
		}
	}
	if !sawName {
		t.Errorf("DNS-Name fehlt: %v", info.DNSNames)
	}
	if info.Fingerprint == "" {
		t.Error("Fingerprint fehlt")
	}
	// Der Fingerprint muss zu dem passen, den Fingerprint() liefert.
	fp, err := Fingerprint(cert)
	if err != nil {
		t.Fatal(err)
	}
	if info.Fingerprint != fp {
		t.Errorf("Fingerprint weicht ab:\n Describe:    %s\n Fingerprint: %s", info.Fingerprint, fp)
	}
}

func TestDescribeMissingFile(t *testing.T) {
	if _, err := Describe(filepath.Join(t.TempDir(), "gibtsnicht.crt")); err == nil {
		t.Error("eine fehlende Datei sollte einen Fehler ergeben")
	}
}
