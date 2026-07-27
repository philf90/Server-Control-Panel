package certs

import (
	"bytes"
	"crypto/tls"
	"path/filepath"
	"testing"
)

func loadPair(t *testing.T, dir string) tls.Certificate {
	t.Helper()
	cert := filepath.Join(dir, "server.crt")
	key := filepath.Join(dir, "server.key")
	if _, err := EnsurePair(cert, key, []string{"example.test"}); err != nil {
		t.Fatal(err)
	}
	pair, err := tls.LoadX509KeyPair(cert, key)
	if err != nil {
		t.Fatal(err)
	}
	return pair
}

func sameLeaf(a, b *tls.Certificate) bool {
	if a == nil || b == nil || len(a.Certificate) == 0 || len(b.Certificate) == 0 {
		return false
	}
	return bytes.Equal(a.Certificate[0], b.Certificate[0])
}

func TestHolderSwapsCertificate(t *testing.T) {
	first := loadPair(t, t.TempDir())
	second := loadPair(t, t.TempDir())

	h := NewHolder(first)
	got, err := h.GetCertificate(nil)
	if err != nil {
		t.Fatal(err)
	}
	if !sameLeaf(got, &first) {
		t.Fatal("der Halter liefert nicht das Anfangszertifikat")
	}

	h.Set(second)
	got, err = h.GetCertificate(nil)
	if err != nil {
		t.Fatal(err)
	}
	if !sameLeaf(got, &second) {
		t.Error("der Austausch griff nicht")
	}
	if sameLeaf(got, &first) {
		t.Error("der Halter liefert weiterhin das erste Zertifikat")
	}
}

// TestHolderConcurrent sichert die Sperre ab: Unter -race darf der gleichzeitige
// Austausch und Abruf keinen Datenwettlauf melden.
func TestHolderConcurrent(t *testing.T) {
	a := loadPair(t, t.TempDir())
	b := loadPair(t, t.TempDir())
	h := NewHolder(a)

	done := make(chan struct{})
	go func() {
		for i := 0; i < 500; i++ {
			h.Set(b)
			h.Set(a)
		}
		close(done)
	}()
	for i := 0; i < 500; i++ {
		if _, err := h.GetCertificate(nil); err != nil {
			t.Error(err)
		}
	}
	<-done
}
