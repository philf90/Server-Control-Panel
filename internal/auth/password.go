// Package auth enthält Passwort-Hashing, TOTP, Tokens und die
// Anmelde-Ratenbegrenzung.
package auth

import (
	"crypto/rand"
	"crypto/subtle"
	"encoding/base64"
	"errors"
	"fmt"
	"runtime/debug"
	"strings"

	"golang.org/x/crypto/argon2"
)

// Argon2id-Parameter.
//
// Die Speichergröße ist bewusst nicht auf den oft zitierten 64-MiB-Wert
// gesetzt: Die systemd-Unit begrenzt den Dienst auf MemoryMax=256M, und das
// Projekt hat sich auf eine schlanke Grundlast festgelegt. 32 MiB liegen
// deutlich über der OWASP-Mindestempfehlung (19 MiB); zusammen mit der
// Serialisierung unten ergibt das einen Spitzenbedarf von genau 32 MiB
// zusätzlich zur Grundlast.
const (
	argonMemory  = 32 * 1024 // KiB
	argonTime    = 3
	argonThreads = 2
	argonKeyLen  = 32
	saltLen      = 16
)

// hashSlots serialisiert die Argon2-Berechnungen. Ohne diese Schranke wäre die
// Anmeldeseite ein bequemer Speicher-Erschöpfungsangriff: Jeder gleichzeitige
// Versuch belegt für die Dauer der Berechnung die volle Speichermenge. Ein
// Slot heißt Spitzenbedarf von genau argonMemory — bei einer Handvoll
// Administratoren ist die kurze Wartezeit kein Preis, der wehtut.
var hashSlots = make(chan struct{}, 1)

// releaseMemory gibt den Argon2-Arbeitsspeicher an das Betriebssystem zurück.
//
// Ohne diesen Aufruf bleibt die Grundlast des Prozesses nach der ersten
// Anmeldung dauerhaft erhöht: Go gibt freie Heap-Bereiche nur zögerlich zurück
// und verdoppelt bei einer 32-MiB-Spitze zudem sein Sammelziel. Gemessen ist
// das der Unterschied zwischen 16 MB und 80 MB Grundlast.
//
// Der Aufruf erfolgt bewusst nach jeder Berechnung und ohne Drosselung. Als
// Lastangriff taugt das nicht: Die Argon2-Berechnung selbst kostet rund
// hundert Millisekunden und damit ein Vielfaches eines Sammellaufs — wer die
// Freigabe auslösen will, bezahlt vorher den weit teureren Teil. Zusätzlich
// begrenzt die Ratenbegrenzung des Anmeldepfads die Zahl der Versuche.
func releaseMemory() {
	debug.FreeOSMemory()
}

// ErrInvalidHash meldet einen nicht interpretierbaren gespeicherten Hash.
var ErrInvalidHash = errors.New("unlesbarer Passwort-Hash")

// MinPasswordLength ist die Untergrenze für neue Passwörter.
const MinPasswordLength = 12

// HashPassword erzeugt einen Argon2id-Hash im PHC-String-Format.
func HashPassword(password string) (string, error) {
	salt := make([]byte, saltLen)
	if _, err := rand.Read(salt); err != nil {
		return "", fmt.Errorf("salt erzeugen: %w", err)
	}

	hashSlots <- struct{}{}
	key := argon2.IDKey([]byte(password), salt, argonTime, argonMemory, argonThreads, argonKeyLen)
	<-hashSlots
	releaseMemory()

	return fmt.Sprintf("$argon2id$v=%d$m=%d,t=%d,p=%d$%s$%s",
		argon2.Version, argonMemory, argonTime, argonThreads,
		base64.RawStdEncoding.EncodeToString(salt),
		base64.RawStdEncoding.EncodeToString(key),
	), nil
}

// VerifyPassword prüft ein Passwort gegen einen gespeicherten Hash. Die
// Parameter kommen aus dem Hash selbst, damit alte Hashes nach einer
// Parameteränderung weiter funktionieren.
func VerifyPassword(password, encoded string) (bool, error) {
	parts := strings.Split(encoded, "$")
	if len(parts) != 6 || parts[1] != "argon2id" {
		return false, ErrInvalidHash
	}

	var version int
	if _, err := fmt.Sscanf(parts[2], "v=%d", &version); err != nil {
		return false, ErrInvalidHash
	}
	if version != argon2.Version {
		return false, fmt.Errorf("%w: unbekannte Argon2-Version %d", ErrInvalidHash, version)
	}

	var memory uint32
	var timeCost uint32
	var threads uint8
	if _, err := fmt.Sscanf(parts[3], "m=%d,t=%d,p=%d", &memory, &timeCost, &threads); err != nil {
		return false, ErrInvalidHash
	}

	salt, err := base64.RawStdEncoding.DecodeString(parts[4])
	if err != nil {
		return false, ErrInvalidHash
	}
	want, err := base64.RawStdEncoding.DecodeString(parts[5])
	if err != nil {
		return false, ErrInvalidHash
	}
	// Ohne Prüfung ginge eine unsinnige Länge ungebremst in die
	// Schlüsselableitung — und ein manipulierter Datenbankeintrag könnte
	// Speicher in beliebiger Höhe anfordern.
	if len(want) < 16 || len(want) > 1024 {
		return false, ErrInvalidHash
	}

	hashSlots <- struct{}{}
	got := argon2.IDKey([]byte(password), salt, timeCost, memory, threads, uint32(len(want))) //nolint:gosec // Länge oben auf 16–1024 begrenzt
	<-hashSlots
	releaseMemory()

	// Konstante Laufzeit: ein früher Abbruch würde die Anzahl korrekter Bytes
	// über die Antwortzeit verraten.
	return subtle.ConstantTimeCompare(got, want) == 1, nil
}

// MaxPasswordBytes ist die Obergrenze. Sie ist keine Schikane, sondern eine
// Schranke gegen Argon2-Aufrufe mit beliebig großer Eingabe.
const MaxPasswordBytes = 1024

// Die Passwortrichtlinie des Panels.
//
// Weiterhin keine Zeichenklassen-Regeln: Sie führen erwiesenermaßen zu
// "Passwort1!" statt zu besseren Passwörtern, und NIST 800-63B rät seit 2017
// ausdrücklich davon ab. Was stattdessen geprüft wird, sind die Fälle, in denen
// ein langes Passwort trotzdem leicht zu erraten ist: der eigene Anmeldename und
// eine bloße Wiederholung oder Zeichenfolge. Beides steht in derselben
// Empfehlung (§5.1.1.2, "context-specific words", "repetitive or sequential
// characters").
//
// Die Regeln sind Daten und nicht bloß Programmcode, weil die Oberfläche sie
// zeigt: Wer ein Passwort wählt, soll die Bedingungen lesen können und nicht
// erst durch eine Ablehnung erfahren, welche es gibt.

// PasswordRuleKey benennt eine Regel. Derselbe Schlüssel steht im Markup
// (data-pw-regel) und in passwort.js, das die Regeln beim Tippen mitprüft.
type PasswordRuleKey string

const (
	RuleLength      PasswordRuleKey = "laenge"
	RuleMaxBytes    PasswordRuleKey = "hoechstlaenge"
	RuleNotUsername PasswordRuleKey = "nichtname"
	RuleNotTrivial  PasswordRuleKey = "abwechslung"
)

// PasswordRule ist ein Element der Richtlinie: ein Schlüssel für das Skript und
// ein Satz für den Menschen.
type PasswordRule struct {
	Key  PasswordRuleKey
	Text string
}

// PasswordPolicy ist die geltende Richtlinie in der Form, in der die Oberfläche
// sie braucht. Die Zahlen stehen genau einmal — hier — und werden ins Markup
// gerendert, damit das Skript sie nicht ein zweites Mal festschreibt.
type PasswordPolicy struct {
	MinLength int
	MaxBytes  int
	Rules     []PasswordRule
}

// Policy liefert die Richtlinie samt ihrer lesbaren Elemente.
func Policy() PasswordPolicy {
	return PasswordPolicy{
		MinLength: MinPasswordLength,
		MaxBytes:  MaxPasswordBytes,
		Rules: []PasswordRule{
			{RuleLength, fmt.Sprintf("mindestens %d Zeichen", MinPasswordLength)},
			{RuleNotUsername, "nicht der Anmeldename und nicht ein Teil davon"},
			{RuleNotTrivial, "keine bloße Wiederholung oder Zeichenfolge (aaaa…, 1234…)"},
			{RuleMaxBytes, fmt.Sprintf("höchstens %d Byte", MaxPasswordBytes)},
		},
	}
}

// CheckPasswordPolicy prüft ein neues Passwort gegen die Richtlinie. username
// darf leer sein — dann entfällt die Prüfung gegen den Anmeldenamen.
//
// Die Reihenfolge der Prüfungen entspricht der Reihenfolge der Regeln in der
// Anzeige: Wer eine Ablehnung liest, findet den Satz dazu an derselben Stelle
// wieder.
func CheckPasswordPolicy(username, password string) error {
	if len([]rune(password)) < MinPasswordLength {
		return fmt.Errorf("das Passwort muss mindestens %d Zeichen haben", MinPasswordLength)
	}
	if !PasswordUnlikeUsername(username, password) {
		return errors.New("das Passwort darf nicht den Anmeldenamen enthalten")
	}
	if PasswordIsTrivial(password) {
		return errors.New("das Passwort ist eine bloße Wiederholung oder Zeichenfolge")
	}
	if len(password) > MaxPasswordBytes {
		return fmt.Errorf("das Passwort ist zu lang (höchstens %d Byte)", MaxPasswordBytes)
	}
	return nil
}

// PasswordUnlikeUsername meldet, ob das Passwort frei vom Anmeldenamen ist.
// Ohne Namen gibt es nichts zu prüfen, dann gilt die Regel als erfüllt.
func PasswordUnlikeUsername(username, password string) bool {
	name := strings.ToLower(strings.TrimSpace(username))
	if name == "" {
		return true
	}
	return !strings.Contains(strings.ToLower(password), name)
}

// PasswordIsTrivial erkennt zwei Muster, die jede Längenregel bestehen und
// trotzdem in Sekunden geraten sind: dasselbe Zeichen wiederholt und eine
// durchgehende Folge in der Zeichentabelle ("abcdefghijkl", "987654321098").
//
// Bewusst nur diese beiden und keine Wörterbuchprüfung: Eine Sperrliste
// häufiger Passwörter wäre der nächste sinnvolle Schritt, sie gehört aber mit
// Herkunft und Pflege in eine eigene Entscheidung — nicht als stille Zutat.
func PasswordIsTrivial(password string) bool {
	runen := []rune(password)
	if len(runen) < 2 {
		return false
	}

	einerlei := true
	for _, r := range runen[1:] {
		if r != runen[0] {
			einerlei = false
			break
		}
	}
	if einerlei {
		return true
	}

	// Eine Folge ist erst eine, wenn sie über das ganze Passwort läuft. Ein
	// "abc" mitten in einem Passwortsatz ist keine.
	//
	// Unter vier Zeichen wird nicht geprüft: "ab" ist zwar formal eine Folge,
	// aber die Aussage "das ist eine bloße Zeichenfolge" trägt dort nichts —
	// zu kurz ist es ohnehin, und in der Anzeige neben dem Eingabefeld sähe
	// eine rote Regel beim zweiten Buchstaben nach einem Fehler aus.
	if len(runen) < 4 {
		return false
	}
	auf, ab := true, true
	for i := 1; i < len(runen); i++ {
		if runen[i] != runen[i-1]+1 {
			auf = false
		}
		if runen[i] != runen[i-1]-1 {
			ab = false
		}
	}
	return auf || ab
}
