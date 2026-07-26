package update

import (
	"crypto/ed25519"
	"crypto/rand"
	"encoding/base64"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Das Material in testdata stammt aus einem echten minisign-Lauf mit dem
// veröffentlichten Projektschlüssel. Damit prüft der Test nicht die eigene
// Vorstellung vom Format, sondern das Format.

func readTestdata(t *testing.T, name string) string {
	t.Helper()
	b, err := os.ReadFile(filepath.Join("testdata", name))
	if err != nil {
		t.Fatalf("testdata %s: %v", name, err)
	}
	return string(b)
}

func testKey(t *testing.T) PublicKey {
	t.Helper()
	key, err := ParsePublicKey(readTestdata(t, "minisign.pub"))
	if err != nil {
		t.Fatalf("ParsePublicKey: %v", err)
	}
	return key
}

func TestParsePublicKey(t *testing.T) {
	key := testKey(t)

	// Kennung aus dem untrusted comment der Schlüsseldatei.
	if got, want := key.KeyIDString(), "1BB2A4210C0FE23"; got != want {
		t.Errorf("KeyIDString = %s, erwartet %s", got, want)
	}
	if len(key.Key) != 32 {
		t.Errorf("Schlüssel hat %d Byte", len(key.Key))
	}

	// Die reine Base64-Zeile, wie sie im Installer eingebettet ist, muss
	// denselben Schlüssel ergeben.
	line := lastNonEmptyLine(readTestdata(t, "minisign.pub"))
	bare, err := ParsePublicKey(line)
	if err != nil {
		t.Fatalf("ParsePublicKey(nur Zeile): %v", err)
	}
	if bare.KeyID != key.KeyID || string(bare.Key) != string(key.Key) {
		t.Error("einzeiliger und zweizeiliger Schlüssel unterscheiden sich")
	}
}

func TestParsePublicKeyFehler(t *testing.T) {
	tests := map[string]string{
		"leer":          "",
		"nur Kommentar": "untrusted comment: minisign public key ABC\n",
		"kein base64":   "!!!keine gültigen zeichen!!!",
		"zu kurz":       "RWQj/sAQQiq7AQ==",
		"falscher Typ":  "RVdj/sAQQiq7Aa8sPaBSb21Wcbp9n165J+s6z8qqq0GUmB2ZXzDNoNXf",
	}
	for name, in := range tests {
		t.Run(name, func(t *testing.T) {
			if _, err := ParsePublicKey(in); err == nil {
				t.Fatal("Fehler erwartet, bekam keinen")
			}
		})
	}
}

func TestVerifyEchteSignatur(t *testing.T) {
	key := testKey(t)
	content := []byte(readTestdata(t, "SHA256SUMS"))
	sig := readTestdata(t, "SHA256SUMS.minisig")

	comment, err := Verify(content, sig, key)
	if err != nil {
		t.Fatalf("Verify: %v", err)
	}
	if want := "Project Asylum 0.1.0"; comment != want {
		t.Errorf("beglaubigter Kommentar = %q, erwartet %q", comment, want)
	}
}

func TestVerifyAbweisung(t *testing.T) {
	key := testKey(t)
	content := []byte(readTestdata(t, "SHA256SUMS"))
	sig := readTestdata(t, "SHA256SUMS.minisig")

	t.Run("verändertes Artefakt", func(t *testing.T) {
		// Eine einzige Stelle der Prüfsumme gedreht — genau der Fall, den
		// ein untergeschobenes Archiv erzeugt.
		bad := []byte(strings.Replace(string(content), "47054f", "47054e", 1))
		if _, err := Verify(bad, sig, key); !errors.Is(err, ErrBadSignature) {
			t.Fatalf("erwartet ErrBadSignature, bekam %v", err)
		}
	})

	t.Run("fremder Schlüssel", func(t *testing.T) {
		other := key
		other.Key[0] ^= 0x01
		if _, err := Verify(content, sig, other); !errors.Is(err, ErrBadSignature) {
			t.Fatalf("erwartet ErrBadSignature, bekam %v", err)
		}
		other.Key[0] ^= 0x01
	})

	t.Run("fremde Schlüsselkennung", func(t *testing.T) {
		other := key
		other.KeyID[0] ^= 0x01
		if _, err := Verify(content, sig, other); !errors.Is(err, ErrBadSignature) {
			t.Fatalf("erwartet ErrBadSignature, bekam %v", err)
		}
	})

	t.Run("veränderter Kommentar", func(t *testing.T) {
		// Ohne Prüfung der globalen Signatur ließe sich hier die Version
		// umschreiben, ohne dass die Inhaltssignatur bricht.
		bad := strings.Replace(sig, "Project Asylum 0.1.0", "Project Asylum 9.9.9", 1)
		if _, err := Verify(content, bad, key); !errors.Is(err, ErrBadSignature) {
			t.Fatalf("erwartet ErrBadSignature, bekam %v", err)
		}
	})

	t.Run("vertauschtes Verfahren", func(t *testing.T) {
		// "ED" (prehashed) zu "Ed" (legacy) umbiegen: der Signaturblock
		// beginnt mit dem Kennzeichen, also greift der Austausch im Base64.
		lines := strings.Split(sig, "\n")
		raw := lines[1]
		lines[1] = "RWQ" + raw[3:]
		if _, err := Verify(content, strings.Join(lines, "\n"), key); err == nil {
			t.Fatal("Fehler erwartet, bekam keinen")
		}
	})
}

func TestParseSignatureFehler(t *testing.T) {
	sig := readTestdata(t, "SHA256SUMS.minisig")
	lines := strings.Split(strings.TrimRight(sig, "\n"), "\n")

	kaputt := func(idx int, val string) string {
		cp := append([]string(nil), lines...)
		cp[idx] = val
		return strings.Join(cp, "\n")
	}

	tests := map[string]string{
		"zu wenige Zeilen":    strings.Join(lines[:3], "\n"),
		"Signatur kein b64":   kaputt(1, "###"),
		"Signatur zu kurz":    kaputt(1, "RUQj/sAQQiq7AQ=="),
		"Kommentar fehlt":     kaputt(2, "comment: irgendwas"),
		"globale kein b64":    kaputt(3, "###"),
		"globale falsche Län": kaputt(3, "RUQj/sAQQiq7AQ=="),
	}
	for name, in := range tests {
		t.Run(name, func(t *testing.T) {
			if _, err := parseSignature(in); err == nil {
				t.Fatal("Fehler erwartet, bekam keinen")
			}
		})
	}
}

// TestVerifyLegacy deckt das Verfahren ohne Vorab-Hash ab. minisign schreibt
// es seit Jahren nicht mehr, alte Signaturen tragen es aber noch.
func TestVerifyLegacy(t *testing.T) {
	pub, sigFile := legacySignature(t, []byte("inhalt"), "Kanal stable")

	comment, err := Verify([]byte("inhalt"), sigFile, pub)
	if err != nil {
		t.Fatalf("Verify: %v", err)
	}
	if comment != "Kanal stable" {
		t.Errorf("Kommentar = %q", comment)
	}
	if _, err := Verify([]byte("anderer Inhalt"), sigFile, pub); !errors.Is(err, ErrBadSignature) {
		t.Fatalf("erwartet ErrBadSignature, bekam %v", err)
	}
}

// legacySignature erzeugt eine Signatur im Verfahren "Ed" (ohne Vorab-Hash).
func legacySignature(t *testing.T, content []byte, comment string) (PublicKey, string) {
	t.Helper()

	pub, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatalf("Schlüssel erzeugen: %v", err)
	}
	key := PublicKey{Key: pub}
	copy(key.KeyID[:], []byte{1, 2, 3, 4, 5, 6, 7, 8})

	sig := ed25519.Sign(priv, content)
	block := append([]byte(algLegacy), key.KeyID[:]...)
	block = append(block, sig...)

	global := ed25519.Sign(priv, append(append([]byte(nil), sig...), []byte(comment)...))

	file := strings.Join([]string{
		"untrusted comment: test",
		base64.StdEncoding.EncodeToString(block),
		trustedCommentPrefix + comment,
		base64.StdEncoding.EncodeToString(global),
	}, "\n") + "\n"
	return key, file
}
