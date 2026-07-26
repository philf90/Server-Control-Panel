// Package certs erzeugt und verwaltet das TLS-Material des Panels.
//
// Beim ersten Start existiert noch kein Zertifikat. Statt unverschlüsselt zu
// starten, legt der Daemon ein selbstsigniertes Paar an. Der Fingerprint wird
// beim Start geloggt und vom Installer ausgegeben, damit die erste Verbindung
// überprüfbar ist. ACME/Let's Encrypt folgt in einer späteren Ausbaustufe.
package certs

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"fmt"
	"math/big"
	"net"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/netinfo"
)

// Validity ist die Laufzeit des selbstsignierten Zertifikats.
const Validity = 2 * 365 * 24 * time.Hour

// EnsurePair stellt sicher, dass Zertifikat und Schlüssel existieren. Sind
// beide vorhanden, passiert nichts; created ist dann false.
func EnsurePair(certPath, keyPath string, hosts []string) (created bool, err error) {
	certExists := fileExists(certPath)
	keyExists := fileExists(keyPath)

	switch {
	case certExists && keyExists:
		return false, nil
	case certExists != keyExists:
		return false, fmt.Errorf(
			"unvollständiges TLS-Material: %q vorhanden=%t, %q vorhanden=%t — bitte beide Dateien entfernen",
			certPath, certExists, keyPath, keyExists)
	}

	if len(hosts) == 0 {
		hosts = DefaultHosts()
	}
	certPEM, keyPEM, err := generate(hosts)
	if err != nil {
		return false, err
	}

	for _, dir := range []string{filepath.Dir(certPath), filepath.Dir(keyPath)} {
		if err := os.MkdirAll(dir, 0o750); err != nil {
			return false, fmt.Errorf("verzeichnis %s: %w", dir, err)
		}
	}
	// Der Schlüssel zuerst und mit 0600 — zwischen den beiden Schreibvorgängen
	// darf er nie mit weiteren Rechten existieren.
	if err := os.WriteFile(keyPath, keyPEM, 0o600); err != nil {
		return false, fmt.Errorf("schlüssel %s: %w", keyPath, err)
	}
	if err := os.WriteFile(certPath, certPEM, 0o644); err != nil { //nolint:gosec // Zertifikate sind öffentlich
		return false, fmt.Errorf("zertifikat %s: %w", certPath, err)
	}
	return true, nil
}

// Fingerprint liefert den SHA-256-Fingerprint des Zertifikats in der auch von
// openssl verwendeten Schreibweise (Großbuchstaben, doppelpunktgetrennt).
func Fingerprint(certPath string) (string, error) {
	raw, err := os.ReadFile(certPath) //nolint:gosec // Pfad stammt aus der Konfiguration
	if err != nil {
		return "", err
	}
	block, _ := pem.Decode(raw)
	if block == nil || block.Type != "CERTIFICATE" {
		return "", fmt.Errorf("%s enthält kein PEM-Zertifikat", certPath)
	}
	sum := sha256.Sum256(block.Bytes)
	parts := make([]string, 0, len(sum))
	for _, b := range sum {
		parts = append(parts, fmt.Sprintf("%02X", b))
	}
	return strings.Join(parts, ":"), nil
}

// DefaultHosts sammelt die Namen und Adressen, unter denen das Panel
// voraussichtlich erreicht wird.
func DefaultHosts() []string {
	set := map[string]struct{}{
		"localhost": {},
		"127.0.0.1": {},
		"::1":       {},
	}
	if h, err := os.Hostname(); err == nil && h != "" {
		set[h] = struct{}{}
	}
	// Der vollqualifizierte Name muss mit hinein: Unter ihm ruft der Browser
	// das Panel auf. Fehlte er, käme zur Warnung vor dem selbstsignierten
	// Zertifikat noch eine vor dem falschen Namen — zwei Warnungen, von denen
	// die zweite nach einem Angriff aussieht.
	if fqdn := netinfo.FQDN(); fqdn != "" {
		set[fqdn] = struct{}{}
	}
	for _, addr := range netinfo.Addresses() {
		set[addr] = struct{}{}
	}
	hosts := make([]string, 0, len(set))
	for h := range set {
		hosts = append(hosts, h)
	}
	sort.Strings(hosts)
	return hosts
}

func generate(hosts []string) (certPEM, keyPEM []byte, err error) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, fmt.Errorf("schlüsselerzeugung: %w", err)
	}

	serialMax := new(big.Int).Lsh(big.NewInt(1), 128)
	serial, err := rand.Int(rand.Reader, serialMax)
	if err != nil {
		return nil, nil, fmt.Errorf("seriennummer: %w", err)
	}

	now := time.Now()
	tmpl := x509.Certificate{
		SerialNumber: serial,
		Subject: pkix.Name{
			Organization: []string{"Project Asylum"},
			CommonName:   primaryHost(hosts),
		},
		// Eine Minute Vorlauf federt Uhrabweichungen zwischen Server und
		// Client ab, die sonst als "not yet valid" auflaufen.
		NotBefore:             now.Add(-time.Minute),
		NotAfter:              now.Add(Validity),
		KeyUsage:              x509.KeyUsageDigitalSignature | x509.KeyUsageCertSign,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid: true,
		IsCA:                  true,
	}
	for _, h := range hosts {
		if ip := net.ParseIP(h); ip != nil {
			tmpl.IPAddresses = append(tmpl.IPAddresses, ip)
			continue
		}
		tmpl.DNSNames = append(tmpl.DNSNames, h)
	}

	der, err := x509.CreateCertificate(rand.Reader, &tmpl, &tmpl, &key.PublicKey, key)
	if err != nil {
		return nil, nil, fmt.Errorf("zertifikat: %w", err)
	}
	keyDER, err := x509.MarshalECPrivateKey(key)
	if err != nil {
		return nil, nil, fmt.Errorf("schlüssel kodieren: %w", err)
	}

	certPEM = pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})
	keyPEM = pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: keyDER})
	return certPEM, keyPEM, nil
}

func primaryHost(hosts []string) string {
	for _, h := range hosts {
		if h != "localhost" && net.ParseIP(h) == nil {
			return h
		}
	}
	if len(hosts) > 0 {
		return hosts[0]
	}
	return "asylum"
}

func fileExists(path string) bool {
	_, err := os.Stat(path)
	return err == nil || !errors.Is(err, os.ErrNotExist)
}
