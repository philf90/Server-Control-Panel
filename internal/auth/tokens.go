package auth

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"fmt"
	"strings"
)

// TokenBytes ist die Länge der Zufallswerte für Session-IDs, Setup-Token und
// CSRF-Token. 32 Byte sind auch gegen offline geratene Werte weit jenseits von
// Brute-Force.
const TokenBytes = 32

// NewToken erzeugt einen URL-tauglichen Zufallswert.
func NewToken() (string, error) {
	buf := make([]byte, TokenBytes)
	if _, err := rand.Read(buf); err != nil {
		return "", fmt.Errorf("token erzeugen: %w", err)
	}
	return base64.RawURLEncoding.EncodeToString(buf), nil
}

// HashToken bildet den Speicherwert eines Tokens.
//
// In der Datenbank liegt nie der Token selbst: Ein Datenbankabzug erlaubt damit
// weder die Übernahme laufender Sitzungen noch die Nutzung eines offenen
// Setup-Tokens. Ein schneller Hash genügt hier — anders als bei Passwörtern
// gibt es keinen Suchraum, den man durchprobieren könnte.
func HashToken(token string) string {
	sum := sha256.Sum256([]byte(token))
	return hex.EncodeToString(sum[:])
}

// RecoveryCodeCount ist die Anzahl der beim Einrichten erzeugten
// Wiederherstellungscodes.
const RecoveryCodeCount = 10

// NewRecoveryCodes erzeugt Codes im Format "abcd-efgh-ijkl" und liefert sie
// zusammen mit ihren Hashes. Der Klartext wird genau einmal angezeigt.
func NewRecoveryCodes() (codes []string, hashes []string, err error) {
	// Ohne Vokale und ohne 0/1/l/o: verwechslungsarm beim Abschreiben.
	const alphabet = "abcdefghjkmnpqrstuvwxyz23456789"

	codes = make([]string, 0, RecoveryCodeCount)
	hashes = make([]string, 0, RecoveryCodeCount)

	for i := 0; i < RecoveryCodeCount; i++ {
		buf := make([]byte, 12)
		if _, err := rand.Read(buf); err != nil {
			return nil, nil, fmt.Errorf("wiederherstellungscode: %w", err)
		}
		var sb strings.Builder
		for j, b := range buf {
			if j > 0 && j%4 == 0 {
				sb.WriteByte('-')
			}
			sb.WriteByte(alphabet[int(b)%len(alphabet)])
		}
		code := sb.String()
		codes = append(codes, code)
		hashes = append(hashes, HashToken(NormalizeRecoveryCode(code)))
	}
	return codes, hashes, nil
}

// NormalizeRecoveryCode macht die Eingabe unabhängig von Groß-/Kleinschreibung
// und Trennzeichen.
func NormalizeRecoveryCode(code string) string {
	code = strings.ToLower(strings.TrimSpace(code))
	code = strings.ReplaceAll(code, "-", "")
	code = strings.ReplaceAll(code, " ", "")
	return code
}
