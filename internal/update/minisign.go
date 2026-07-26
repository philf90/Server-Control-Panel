// Package update prüft, lädt und installiert neue Fassungen des Panels.
package update

import (
	"crypto/ed25519"
	"encoding/base64"
	"encoding/binary"
	"errors"
	"fmt"
	"strings"

	"golang.org/x/crypto/blake2b"
)

// Die Signaturprüfung ist hier in Go umgesetzt und ruft nicht das
// minisign-Programm auf. Zwei Gründe:
//
//   - Der Daemon soll für ein Update kein zusätzliches Paket auf dem Zielsystem
//     brauchen. Ein Update, das erst eine Installation voraussetzt, ist genau
//     dann unbrauchbar, wenn man es am nötigsten hat.
//   - Der Vertrauensanker bleibt im Binary. Ein untergeschobenes minisign im
//     PATH könnte sonst jede Signatur für gültig erklären.
//
// Das Format ist schlank: Ed25519 über einen BLAKE2b-512-Hash der Datei.

// Signaturkennungen nach minisign.
const (
	algLegacy    = "Ed" // Ed25519 direkt über den Dateiinhalt
	algPrehashed = "ED" // Ed25519 über BLAKE2b-512 des Inhalts
)

// trustedCommentPrefix leitet die dritte Zeile einer .minisig ein.
const trustedCommentPrefix = "trusted comment: "

// ErrBadSignature meldet eine ungültige oder nicht passende Signatur.
var ErrBadSignature = errors.New("signatur ist ungültig")

// PublicKey ist ein minisign-Schlüssel.
type PublicKey struct {
	KeyID [8]byte
	Key   ed25519.PublicKey
}

// ParsePublicKey liest einen Schlüssel im minisign-Format. Angenommen wird
// sowohl die zweizeilige Datei als auch die reine Base64-Zeile, wie sie im
// Installer eingebettet ist.
func ParsePublicKey(s string) (PublicKey, error) {
	line := lastNonEmptyLine(s)
	if line == "" {
		return PublicKey{}, errors.New("leerer Schlüssel")
	}

	raw, err := base64.StdEncoding.DecodeString(line)
	if err != nil {
		return PublicKey{}, fmt.Errorf("schlüssel ist nicht base64: %w", err)
	}
	if len(raw) != 2+8+ed25519.PublicKeySize {
		return PublicKey{}, fmt.Errorf("schlüssel hat %d Byte, erwartet %d",
			len(raw), 2+8+ed25519.PublicKeySize)
	}
	if alg := string(raw[:2]); alg != algLegacy {
		return PublicKey{}, fmt.Errorf("unbekannter Schlüsseltyp %q", alg)
	}

	var key PublicKey
	copy(key.KeyID[:], raw[2:10])
	key.Key = ed25519.PublicKey(raw[10:])
	return key, nil
}

// signature ist der Inhalt einer .minisig-Datei.
type signature struct {
	algorithm      string
	keyID          [8]byte
	sig            []byte
	trustedComment string
	globalSig      []byte
}

func parseSignature(s string) (signature, error) {
	var out signature

	var lines []string
	for _, l := range strings.Split(s, "\n") {
		if l = strings.TrimRight(l, "\r"); strings.TrimSpace(l) != "" {
			lines = append(lines, l)
		}
	}
	// Erwartet: Kommentar, Signatur, "trusted comment: …", globale Signatur.
	if len(lines) < 4 {
		return signature{}, errors.New("signaturdatei ist unvollständig")
	}

	raw, err := base64.StdEncoding.DecodeString(strings.TrimSpace(lines[1]))
	if err != nil {
		return signature{}, fmt.Errorf("signatur ist nicht base64: %w", err)
	}
	if len(raw) != 2+8+ed25519.SignatureSize {
		return signature{}, fmt.Errorf("signatur hat %d Byte, erwartet %d",
			len(raw), 2+8+ed25519.SignatureSize)
	}

	out.algorithm = string(raw[:2])
	copy(out.keyID[:], raw[2:10])
	out.sig = raw[10:]

	// minisign signiert genau den Text hinter "trusted comment: " — ein
	// Trimmen wäre falsch, sobald der Kommentar mit einem Leerzeichen endet:
	// die globale Signatur passte dann nicht mehr.
	comment, ok := strings.CutPrefix(lines[2], trustedCommentPrefix)
	if !ok {
		return signature{}, errors.New("der beglaubigte Kommentar fehlt")
	}
	out.trustedComment = comment

	out.globalSig, err = base64.StdEncoding.DecodeString(strings.TrimSpace(lines[3]))
	if err != nil {
		return signature{}, fmt.Errorf("globale signatur ist nicht base64: %w", err)
	}
	if len(out.globalSig) != ed25519.SignatureSize {
		return signature{}, errors.New("globale signatur hat die falsche Länge")
	}
	return out, nil
}

// Verify prüft den Inhalt gegen eine minisign-Signatur.
//
// Geprüft wird beides: die Signatur über den Inhalt und die globale Signatur
// über Signatur und beglaubigten Kommentar. Ohne die zweite ließe sich der
// Kommentar — der die Version nennt — beliebig austauschen.
func Verify(content []byte, signatureFile string, key PublicKey) (trustedComment string, err error) {
	sig, err := parseSignature(signatureFile)
	if err != nil {
		return "", err
	}
	if sig.keyID != key.KeyID {
		return "", fmt.Errorf("%w: die Signatur stammt von einem anderen Schlüssel", ErrBadSignature)
	}

	var signed []byte
	switch sig.algorithm {
	case algPrehashed:
		sum := blake2b.Sum512(content)
		signed = sum[:]
	case algLegacy:
		signed = content
	default:
		return "", fmt.Errorf("unbekanntes Signaturverfahren %q", sig.algorithm)
	}

	if !ed25519.Verify(key.Key, signed, sig.sig) {
		return "", ErrBadSignature
	}

	// Die globale Signatur deckt Signatur und Kommentar gemeinsam ab.
	global := make([]byte, 0, len(sig.sig)+len(sig.trustedComment))
	global = append(global, sig.sig...)
	global = append(global, []byte(sig.trustedComment)...)
	if !ed25519.Verify(key.Key, global, sig.globalSig) {
		return "", fmt.Errorf("%w: der beglaubigte Kommentar passt nicht zur Signatur", ErrBadSignature)
	}
	return sig.trustedComment, nil
}

// KeyIDString liefert die Kennung in der Schreibweise von minisign.
func (k PublicKey) KeyIDString() string {
	return fmt.Sprintf("%X", binary.LittleEndian.Uint64(k.KeyID[:]))
}

func lastNonEmptyLine(s string) string {
	var last string
	for _, l := range strings.Split(s, "\n") {
		l = strings.TrimSpace(l)
		// Kommentarzeilen von minisign beginnen mit "untrusted comment:".
		if l == "" || strings.HasPrefix(l, "untrusted comment:") {
			continue
		}
		last = l
	}
	return last
}
