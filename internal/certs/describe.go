package certs

import (
	"crypto/sha256"
	"crypto/x509"
	"encoding/pem"
	"fmt"
	"os"
	"strings"
	"time"
)

// Info beschreibt ein Zertifikat für die Anzeige in CLI und Panel.
type Info struct {
	Path        string
	Subject     string
	Issuer      string
	DNSNames    []string
	NotBefore   time.Time
	NotAfter    time.Time
	SelfSigned  bool
	Fingerprint string
}

// Describe liest das Zertifikat unter path und fasst es zusammen. SelfSigned
// ist eine Heuristik über den Vergleich von Aussteller und Inhaber — sie genügt,
// um „Warnung im Browser" von „von einer CA beglaubigt" zu unterscheiden.
func Describe(path string) (Info, error) {
	raw, err := os.ReadFile(path) //nolint:gosec // Pfad aus der Konfiguration
	if err != nil {
		return Info{}, err
	}
	block, _ := pem.Decode(raw)
	if block == nil || block.Type != "CERTIFICATE" {
		return Info{}, fmt.Errorf("%s enthält kein PEM-Zertifikat", path)
	}
	cert, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return Info{}, err
	}

	sum := sha256.Sum256(block.Bytes)
	parts := make([]string, 0, len(sum))
	for _, b := range sum {
		parts = append(parts, fmt.Sprintf("%02X", b))
	}

	return Info{
		Path:        path,
		Subject:     cert.Subject.String(),
		Issuer:      cert.Issuer.String(),
		DNSNames:    cert.DNSNames,
		NotBefore:   cert.NotBefore,
		NotAfter:    cert.NotAfter,
		SelfSigned:  cert.Subject.String() == cert.Issuer.String(),
		Fingerprint: strings.Join(parts, ":"),
	}, nil
}
