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

// TOTPCheck ist das Ergebnis von CheckTOTP.
type TOTPCheck struct {
	// Counter ist das getroffene Zeitfenster; nur bei Valid gesetzt.
	Counter uint64
	// Valid heißt: Der Code stimmt und wurde noch nicht eingelöst.
	Valid bool
	// Reused heißt: Der Code stimmt, war aber schon einmal angenommen.
	//
	// Der Unterschied zu "stimmt nicht" ist betrieblich wichtig: Ein
	// wiederverwendeter Code deutet darauf hin, dass jemand mitgelesen hat.
	// Ein falscher deutet auf ein Vertippen.
	Reused bool
}

// CheckTOTP prüft einen Code gegen das Geheimnis.
//
// Codes aus Zeitfenstern bis einschließlich "used" gelten als verbraucht. Ohne
// diese Grenze bliebe ein Code sein ganzes Fenster über gültig — bei einer
// Toleranz von einem Fenster also bis zu anderthalb Minuten, und beliebig oft.
// Wer ihn mitliest, könnte ihn in dieser Zeit ein zweites Mal einlösen.
// RFC 6238 §5.2 verlangt deshalb, dass ein bereits angenommener Code nicht
// erneut angenommen wird.
//
// Der Aufrufer speichert Counter nach einer geglückten Anmeldung und reicht ihn
// beim nächsten Mal als "used" wieder herein.
func CheckTOTP(secret, code string, now time.Time, used uint64) TOTPCheck {
	code = strings.TrimSpace(code)
	if len(code) != totpDigits {
		return TOTPCheck{}
	}
	key, err := decodeSecret(secret)
	if err != nil {
		return TOTPCheck{}
	}

	current, err := totpCounter(now)
	if err != nil {
		return TOTPCheck{}
	}

	// Alle Fenster durchlaufen, ohne früh abzubrechen: Ein Abbruch beim
	// Treffer würde über die Laufzeit verraten, welches Fenster gepasst hat.
	// Aus demselben Grund wird der Treffer verzweigungsfrei eingesammelt.
	var match int
	var matched uint64
	for i := -totpSkew; i <= totpSkew; i++ {
		// Am unteren Rand nicht ins Negative rutschen: In der ersten halben
		// Minute nach 1970 gäbe es kein vorheriges Fenster.
		if i < 0 && current < uint64(-i) {
			continue
		}
		window := uint64(int64(current) + int64(i)) //nolint:gosec // Untergrenze eine Zeile darüber geprüft
		// ConstantTimeCompare liefert laut Vertrag genau 0 oder 1; die
		// Multiplikation wählt damit verzweigungsfrei aus.
		hit := subtle.ConstantTimeCompare([]byte(hotp(key, window)), []byte(code))
		match |= hit
		matched |= uint64(hit) * window //nolint:gosec // hit ist 0 oder 1, siehe Kommentar
	}
	if match != 1 {
		return TOTPCheck{}
	}
	// Der Zählerstand selbst ist kein Geheimnis; hier darf verzweigt werden.
	if matched <= used {
		return TOTPCheck{Reused: true}
	}
	return TOTPCheck{Counter: matched, Valid: true}
}

// VerifyTOTP prüft einen Code ohne Wiederholungsschutz.
//
// Für die Anmeldung ist CheckTOTP zu verwenden. Diese Fassung bleibt für die
// Fälle, in denen es noch keinen gespeicherten Zählerstand gibt: die
// Ersteinrichtung und den Wechsel des zweiten Faktors.
func VerifyTOTP(secret, code string, now time.Time) bool {
	return CheckTOTP(secret, code, now, 0).Valid
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
