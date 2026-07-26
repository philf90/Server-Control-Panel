package certs

import (
	"crypto/tls"
	"crypto/x509"
	"encoding/pem"
	"os"
	"path/filepath"
	"regexp"
	"testing"
	"time"
)

func paths(t *testing.T) (certPath, keyPath string) {
	t.Helper()
	dir := t.TempDir()
	return filepath.Join(dir, "tls", "server.crt"), filepath.Join(dir, "tls", "server.key")
}

func TestEnsurePairCreatesUsablePair(t *testing.T) {
	certPath, keyPath := paths(t)

	created, err := EnsurePair(certPath, keyPath, []string{"asylum.test", "192.0.2.10"})
	if err != nil {
		t.Fatalf("EnsurePair: %v", err)
	}
	if !created {
		t.Fatal("created = false, obwohl nichts existierte")
	}

	// Das erzeugte Material muss von crypto/tls akzeptiert werden — genau so
	// lädt es der Server später.
	if _, err := tls.LoadX509KeyPair(certPath, keyPath); err != nil {
		t.Fatalf("erzeugtes Paar ist für crypto/tls unbrauchbar: %v", err)
	}

	raw, err := os.ReadFile(certPath)
	if err != nil {
		t.Fatal(err)
	}
	block, _ := pem.Decode(raw)
	cert, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		t.Fatalf("Zertifikat nicht parsebar: %v", err)
	}

	if err := cert.VerifyHostname("asylum.test"); err != nil {
		t.Errorf("DNS-SAN fehlt: %v", err)
	}
	if err := cert.VerifyHostname("192.0.2.10"); err != nil {
		t.Errorf("IP-SAN fehlt: %v", err)
	}
	if !cert.NotBefore.Before(time.Now()) {
		t.Error("NotBefore liegt in der Zukunft — Uhrabweichungen würden das Zertifikat unbrauchbar machen")
	}
	if got := cert.NotAfter.Sub(cert.NotBefore); got < Validity {
		t.Errorf("Laufzeit = %v, erwartet mindestens %v", got, Validity)
	}
}

func TestEnsurePairKeyPermissions(t *testing.T) {
	certPath, keyPath := paths(t)
	if _, err := EnsurePair(certPath, keyPath, []string{"asylum.test"}); err != nil {
		t.Fatal(err)
	}

	info, err := os.Stat(keyPath)
	if err != nil {
		t.Fatal(err)
	}
	if perm := info.Mode().Perm(); perm != 0o600 {
		t.Errorf("Schlüsselrechte = %o, erwartet 600", perm)
	}
}

func TestEnsurePairIsIdempotent(t *testing.T) {
	certPath, keyPath := paths(t)
	if _, err := EnsurePair(certPath, keyPath, []string{"asylum.test"}); err != nil {
		t.Fatal(err)
	}
	before, err := os.ReadFile(certPath)
	if err != nil {
		t.Fatal(err)
	}

	created, err := EnsurePair(certPath, keyPath, []string{"asylum.test"})
	if err != nil {
		t.Fatal(err)
	}
	if created {
		t.Error("zweiter Aufruf hat neu erzeugt statt zu behalten")
	}

	after, err := os.ReadFile(certPath)
	if err != nil {
		t.Fatal(err)
	}
	if string(before) != string(after) {
		t.Error("bestehendes Zertifikat wurde überschrieben")
	}
}

// Ein halbes Paar ist gefährlicher als gar keines: Würden wir stillschweigend
// neu erzeugen, verlöre ein Betreiber sein per ACME geholtes Zertifikat, weil
// nur der Schlüssel kurz fehlte.
func TestEnsurePairRejectsHalfPair(t *testing.T) {
	certPath, keyPath := paths(t)
	if _, err := EnsurePair(certPath, keyPath, []string{"asylum.test"}); err != nil {
		t.Fatal(err)
	}
	if err := os.Remove(keyPath); err != nil {
		t.Fatal(err)
	}

	if _, err := EnsurePair(certPath, keyPath, []string{"asylum.test"}); err == nil {
		t.Fatal("fehlender Schlüssel bei vorhandenem Zertifikat muss ein Fehler sein")
	}
}

func TestFingerprintFormat(t *testing.T) {
	certPath, keyPath := paths(t)
	if _, err := EnsurePair(certPath, keyPath, []string{"asylum.test"}); err != nil {
		t.Fatal(err)
	}

	fp, err := Fingerprint(certPath)
	if err != nil {
		t.Fatalf("Fingerprint: %v", err)
	}
	// Gleiche Schreibweise wie `openssl x509 -fingerprint -sha256`.
	if !regexp.MustCompile(`^([0-9A-F]{2}:){31}[0-9A-F]{2}$`).MatchString(fp) {
		t.Errorf("unerwartetes Format: %q", fp)
	}
}

func TestFingerprintRejectsNonPEM(t *testing.T) {
	path := filepath.Join(t.TempDir(), "kaputt.crt")
	if err := os.WriteFile(path, []byte("kein PEM"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := Fingerprint(path); err == nil {
		t.Fatal("Datei ohne PEM-Block muss abgelehnt werden")
	}
}

func TestDefaultHostsContainsLoopback(t *testing.T) {
	hosts := DefaultHosts()
	want := map[string]bool{"localhost": false, "127.0.0.1": false}
	for _, h := range hosts {
		if _, ok := want[h]; ok {
			want[h] = true
		}
	}
	for h, found := range want {
		if !found {
			t.Errorf("%q fehlt in DefaultHosts() = %v", h, hosts)
		}
	}
}
