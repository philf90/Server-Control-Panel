// Package acme bezieht und erneuert TLS-Zertifikate über das ACME-Protokoll
// (Let's Encrypt). Der Manager läuft im Hintergrund: Er spielt ein vorhandenes
// Zertifikat sofort ein, besorgt bei Bedarf ein neues und erneuert vor Ablauf.
// Schlägt etwas fehl, bleibt das selbstsignierte Zertifikat im Halter — das
// Panel startet und läuft weiter, notfalls mit Warnung im Browser.
package acme

import (
	"crypto"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"encoding/pem"
	"errors"
	"fmt"
	"os"
	"path/filepath"
)

const (
	accountKeyFile = "account.key"
	certFile       = "cert.pem"
	keyFile        = "key.pem"
)

// CertPath liefert den Pfad des über ACME bezogenen Zertifikats unterhalb des
// Datenverzeichnisses — für CLI und Panel, die die Datei nur lesen.
func CertPath(dataDir string) string {
	return filepath.Join(dataDir, "acme", certFile)
}

// loadOrCreateAccountKey lädt den ACME-Kontoschlüssel oder legt ihn an. Er wird
// über Erneuerungen hinweg wiederverwendet: Ein neuer Schlüssel wäre ein neues
// Konto und zählte gegen die Rate-Limits von Let's Encrypt.
func loadOrCreateAccountKey(dir string) (crypto.Signer, error) {
	path := filepath.Join(dir, accountKeyFile)
	raw, err := os.ReadFile(path) //nolint:gosec // Pfad aus der Konfiguration
	switch {
	case err == nil:
		block, _ := pem.Decode(raw)
		if block == nil {
			return nil, fmt.Errorf("%s enthält kein PEM", path)
		}
		return x509.ParseECPrivateKey(block.Bytes)
	case errors.Is(err, os.ErrNotExist):
		// unten anlegen
	default:
		return nil, err
	}

	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, err
	}
	der, err := x509.MarshalECPrivateKey(key)
	if err != nil {
		return nil, err
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return nil, err
	}
	block := pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: der})
	if err := os.WriteFile(path, block, 0o600); err != nil {
		return nil, err
	}
	return key, nil
}

// saveCert schreibt Zertifikatskette und Schlüssel. Der Schlüssel bekommt 0600,
// bevor das Zertifikat daneben liegt.
func saveCert(dir string, certPEM, keyPEM []byte) error {
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(dir, keyFile), keyPEM, 0o600); err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(dir, certFile), certPEM, 0o644); err != nil { //nolint:gosec // Zertifikate sind öffentlich
		return err
	}
	return nil
}

// loadCert lädt ein zuvor bezogenes Zertifikat samt geparstem Leaf. Fehlt es,
// kommt os.ErrNotExist zurück.
func loadCert(dir string) (tls.Certificate, error) {
	certPEM, err := os.ReadFile(filepath.Join(dir, certFile)) //nolint:gosec // Pfad aus der Konfiguration
	if err != nil {
		return tls.Certificate{}, err
	}
	keyPEM, err := os.ReadFile(filepath.Join(dir, keyFile)) //nolint:gosec // Pfad aus der Konfiguration
	if err != nil {
		return tls.Certificate{}, err
	}
	pair, err := tls.X509KeyPair(certPEM, keyPEM)
	if err != nil {
		return tls.Certificate{}, err
	}
	// Das Leaf parsen, damit der Ablauf ohne erneutes Parsen abfragbar ist.
	if pair.Leaf == nil && len(pair.Certificate) > 0 {
		leaf, err := x509.ParseCertificate(pair.Certificate[0])
		if err != nil {
			return tls.Certificate{}, err
		}
		pair.Leaf = leaf
	}
	return pair, nil
}
