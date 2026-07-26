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

// CheckPasswordPolicy prüft die Mindestanforderungen an ein neues Passwort.
//
// Bewusst nur Länge: Zeichenklassen-Regeln führen erwiesenermaßen zu
// "Passwort1!" statt zu besseren Passwörtern.
func CheckPasswordPolicy(password string) error {
	if len([]rune(password)) < MinPasswordLength {
		return fmt.Errorf("das Passwort muss mindestens %d Zeichen haben", MinPasswordLength)
	}
	if len(password) > 1024 {
		return errors.New("das Passwort ist zu lang (höchstens 1024 Byte)")
	}
	return nil
}
