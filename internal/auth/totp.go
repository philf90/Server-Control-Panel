package auth

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha1" //nolint:gosec // RFC 6238 schreibt HMAC-SHA1 für TOTP fest
	"crypto/subtle"
	"encoding/base32"
	"encoding/binary"
	"fmt"
	"net/url"
	"strings"
	"time"
)

// TOTP nach RFC 6238 mit den Vorgaben, die alle gängigen Authenticator-Apps
// erwarten: HMAC-SHA1, sechs Stellen, 30-Sekunden-Fenster.
//
// Die Implementierung ist absichtlich hier und nicht als Abhängigkeit: Es sind
// dreißig Zeilen über crypto/hmac aus der Standardbibliothek, geprüft gegen die
// Testvektoren des RFC. Das spart eine Abhängigkeit im Anmeldepfad.
const (
	totpDigits = 6
	totpPeriod = 30 * time.Second
	// Ein Fenster Toleranz in beide Richtungen federt Uhrabweichungen ab.
	totpSkew  = 1
	secretLen = 20 // 160 Bit, wie in RFC 4226 empfohlen
)

var base32NoPad = base32.StdEncoding.WithPadding(base32.NoPadding)

// GenerateTOTPSecret erzeugt ein neues Geheimnis in Base32.
func GenerateTOTPSecret() (string, error) {
	buf := make([]byte, secretLen)
	if _, err := rand.Read(buf); err != nil {
		return "", fmt.Errorf("totp-geheimnis: %w", err)
	}
	return base32NoPad.EncodeToString(buf), nil
}

// TOTPCode berechnet den Code für einen Zeitpunkt.
func TOTPCode(secret string, t time.Time) (string, error) {
	key, err := decodeSecret(secret)
	if err != nil {
		return "", err
	}
	counter, err := totpCounter(t)
	if err != nil {
		return "", err
	}
	return hotp(key, counter), nil
}

// totpCounter bildet den Zeitpunkt auf das Zählfenster ab.
//
// Zeiten vor 1970 ergeben einen negativen Wert; ungeprüft in uint64 gewandelt
// entstünde daraus ein astronomischer Zähler und damit ein Code, der zu nichts
// passt. Das ist im Betrieb kaum erreichbar — aber eine falsch gestellte
// Systemuhr ist genau der Fall, in dem man verständliche Fehler braucht.
func totpCounter(t time.Time) (uint64, error) {
	secs := t.Unix()
	if secs < 0 {
		return 0, fmt.Errorf("die Systemzeit liegt vor 1970 — der zweite Faktor kann so nicht geprüft werden")
	}
	return uint64(secs) / uint64(totpPeriod.Seconds()), nil
}

// VerifyTOTP prüft einen eingegebenen Code gegen das Geheimnis.
func VerifyTOTP(secret, code string, now time.Time) bool {
	code = strings.TrimSpace(code)
	if len(code) != totpDigits {
		return false
	}
	key, err := decodeSecret(secret)
	if err != nil {
		return false
	}

	counter, err := totpCounter(now)
	if err != nil {
		return false
	}
	// Alle Fenster durchlaufen, ohne früh abzubrechen: Ein Abbruch beim
	// Treffer würde über die Laufzeit verraten, welches Fenster gepasst hat.
	var match int
	for i := -totpSkew; i <= totpSkew; i++ {
		// Am unteren Rand nicht ins Negative rutschen: In der ersten halben
		// Minute nach 1970 gäbe es kein vorheriges Fenster.
		if i < 0 && counter < uint64(-i) {
			continue
		}
		want := hotp(key, uint64(int64(counter)+int64(i))) //nolint:gosec // Untergrenze eine Zeile darüber geprüft
		match |= subtle.ConstantTimeCompare([]byte(want), []byte(code))
	}
	return match == 1
}

// TOTPProvisioningURI liefert die otpauth://-URI für Authenticator-Apps.
func TOTPProvisioningURI(secret, account, issuer string) string {
	label := issuer + ":" + account
	q := url.Values{}
	q.Set("secret", secret)
	q.Set("issuer", issuer)
	q.Set("algorithm", "SHA1")
	q.Set("digits", fmt.Sprintf("%d", totpDigits))
	q.Set("period", fmt.Sprintf("%d", int(totpPeriod.Seconds())))

	return (&url.URL{
		Scheme:   "otpauth",
		Host:     "totp",
		Path:     "/" + label,
		RawQuery: q.Encode(),
	}).String()
}

// FormatSecret gruppiert das Geheimnis in Viererblöcke für die manuelle
// Eingabe, falls kein QR-Code gescannt werden kann.
func FormatSecret(secret string) string {
	var b strings.Builder
	for i, r := range secret {
		if i > 0 && i%4 == 0 {
			b.WriteByte(' ')
		}
		b.WriteRune(r)
	}
	return b.String()
}

func decodeSecret(secret string) ([]byte, error) {
	s := strings.ToUpper(strings.ReplaceAll(secret, " ", ""))
	key, err := base32NoPad.DecodeString(s)
	if err != nil {
		return nil, fmt.Errorf("totp-geheimnis unlesbar: %w", err)
	}
	if len(key) == 0 {
		return nil, fmt.Errorf("totp-geheimnis ist leer")
	}
	return key, nil
}

// hotp ist RFC 4226: HMAC über den Zähler, dann dynamische Kürzung.
func hotp(key []byte, counter uint64) string {
	var buf [8]byte
	binary.BigEndian.PutUint64(buf[:], counter)

	mac := hmac.New(sha1.New, key)
	mac.Write(buf[:])
	sum := mac.Sum(nil)

	offset := sum[len(sum)-1] & 0x0f
	value := binary.BigEndian.Uint32(sum[offset:offset+4]) & 0x7fffffff

	mod := uint32(1)
	for i := 0; i < totpDigits; i++ {
		mod *= 10
	}
	return fmt.Sprintf("%0*d", totpDigits, value%mod)
}
