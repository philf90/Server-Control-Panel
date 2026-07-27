package acme

import (
	"crypto/ecdsa"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestAccountKeyRoundTrip(t *testing.T) {
	dir := t.TempDir()

	first, err := loadOrCreateAccountKey(dir)
	if err != nil {
		t.Fatal(err)
	}
	second, err := loadOrCreateAccountKey(dir)
	if err != nil {
		t.Fatal(err)
	}

	fk, ok := first.(*ecdsa.PrivateKey)
	if !ok {
		t.Fatalf("unerwarteter Schlüsseltyp %T", first)
	}
	sk := second.(*ecdsa.PrivateKey)
	if fk.D.Cmp(sk.D) != 0 {
		t.Error("der zweite Aufruf lieferte einen anderen Schlüssel — das wäre ein neues ACME-Konto")
	}

	info, err := os.Stat(filepath.Join(dir, accountKeyFile))
	if err != nil {
		t.Fatal(err)
	}
	if perm := info.Mode().Perm(); perm != 0o600 {
		t.Errorf("Kontoschlüssel hat Rechte %o, erwartet 600", perm)
	}
}

func TestSaveLoadCert(t *testing.T) {
	dir := t.TempDir()
	notAfter := time.Now().Add(60 * 24 * time.Hour).Truncate(time.Second)
	certPEM, keyPEM := makeCert(t, notAfter)

	if err := saveCert(dir, certPEM, keyPEM); err != nil {
		t.Fatal(err)
	}
	cert, err := loadCert(dir)
	if err != nil {
		t.Fatal(err)
	}
	if cert.Leaf == nil {
		t.Fatal("das Leaf wurde nicht geparst")
	}
	if !cert.Leaf.NotAfter.Equal(notAfter) {
		t.Errorf("Ablauf = %s, erwartet %s", cert.Leaf.NotAfter, notAfter)
	}

	info, err := os.Stat(filepath.Join(dir, keyFile))
	if err != nil {
		t.Fatal(err)
	}
	if perm := info.Mode().Perm(); perm != 0o600 {
		t.Errorf("Schlüssel hat Rechte %o, erwartet 600", perm)
	}
}

func TestLoadCertMissing(t *testing.T) {
	if _, err := loadCert(t.TempDir()); err == nil {
		t.Error("ohne Zertifikat sollte ein Fehler kommen")
	}
}
