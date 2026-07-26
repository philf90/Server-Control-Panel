package privops

import (
	"crypto/sha256"
	"encoding/base64"
	"encoding/binary"
	"fmt"
	"strings"
)

// sshFingerprint berechnet den Fingerprint eines öffentlichen Schlüssels
// genauso, wie ihn `ssh-keygen -lf` ausgibt: SHA-256 über den Rohschlüssel,
// base64-kodiert ohne Auffüllzeichen.
//
// Bewusst ohne Aufruf von ssh-keygen: Das spart einen Prozessstart je Zeile,
// funktioniert ohne installiertes OpenSSH und ist ohne Systemzustand testbar.
func sshFingerprint(keyType, encoded string) (fingerprint string, bits int, err error) {
	blob, err := base64.StdEncoding.DecodeString(encoded)
	if err != nil {
		return "", 0, fmt.Errorf("der Schlüssel ist nicht base64-kodiert")
	}
	if len(blob) < 4 {
		return "", 0, fmt.Errorf("der Schlüssel ist unvollständig")
	}

	// Der Rohschlüssel beginnt mit seinem eigenen Typnamen. Stimmt er nicht
	// mit dem Klartext davor überein, ist die Zeile manipuliert oder kaputt.
	declared, rest, err := readSSHString(blob)
	if err != nil {
		return "", 0, err
	}
	if string(declared) != keyType {
		return "", 0, fmt.Errorf("der Schlüssel gibt sich als %q aus, enthält aber %q",
			keyType, string(declared))
	}

	sum := sha256.Sum256(blob)
	fingerprint = "SHA256:" + base64.RawStdEncoding.EncodeToString(sum[:])

	return fingerprint, sshKeyBits(keyType, rest), nil
}

// sshKeyBits bestimmt die Schlüsselstärke, soweit sie sich ablesen lässt.
func sshKeyBits(keyType string, rest []byte) int {
	switch {
	case keyType == "ssh-ed25519", keyType == "sk-ssh-ed25519@openssh.com":
		return 256
	case strings.HasPrefix(keyType, "ecdsa-sha2-nistp"), strings.HasPrefix(keyType, "sk-ecdsa-sha2-nistp"):
		switch {
		case strings.Contains(keyType, "nistp256"):
			return 256
		case strings.Contains(keyType, "nistp384"):
			return 384
		case strings.Contains(keyType, "nistp521"):
			return 521
		}
		return 0
	case keyType == "ssh-rsa":
		// Aufbau: mpint e, mpint n. Die Stärke ist die Länge von n.
		_, afterE, err := readSSHString(rest)
		if err != nil {
			return 0
		}
		modulus, _, err := readSSHString(afterE)
		if err != nil {
			return 0
		}
		// Führende Nullbytes gehören zur Kodierung, nicht zum Wert.
		for len(modulus) > 0 && modulus[0] == 0 {
			modulus = modulus[1:]
		}
		return len(modulus) * 8
	default:
		return 0
	}
}

// readSSHString liest ein längenpräfigiertes Feld im SSH-Wire-Format.
func readSSHString(b []byte) (value, rest []byte, err error) {
	if len(b) < 4 {
		return nil, nil, fmt.Errorf("der Schlüssel ist unvollständig")
	}
	length := binary.BigEndian.Uint32(b[:4])
	// Obergrenze gegen absichtlich überlange Längenangaben.
	if length > 1<<20 || int(length)+4 > len(b) {
		return nil, nil, fmt.Errorf("der Schlüssel ist beschädigt")
	}
	return b[4 : 4+length], b[4+length:], nil
}
