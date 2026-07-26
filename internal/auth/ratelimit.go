package auth

import (
	"sync"
	"time"
)

// Limiter bremst Anmeldeversuche aus — je Quell-IP und je Konto getrennt.
//
// Die Zähler liegen im Speicher und nicht in der Datenbank: Ein Neustart setzt
// sie zurück, dafür kostet ein Fehlversuch keinen Schreibzugriff. Da ein
// Neustart des Panels root-Rechte voraussetzt, ist das kein Umgehungsweg für
// einen Angreifer von außen.
type Limiter struct {
	mu       sync.Mutex
	attempts map[string]*counter

	// MaxAttempts ist die Zahl der Fehlversuche vor der ersten Sperre.
	MaxAttempts int
	// BaseDelay ist die erste Sperrdauer; sie verdoppelt sich je weiterem
	// Fehlversuch bis MaxDelay.
	BaseDelay time.Duration
	MaxDelay  time.Duration
	// Window ist die Zeit ohne Fehlversuch, nach der ein Zähler verfällt.
	Window time.Duration

	now func() time.Time
}

type counter struct {
	failures    int
	lockedUntil time.Time
	lastFailure time.Time
}

// NewLimiter liefert einen Limiter mit brauchbaren Vorgaben.
func NewLimiter() *Limiter {
	return &Limiter{
		attempts:    make(map[string]*counter),
		MaxAttempts: 5,
		BaseDelay:   30 * time.Second,
		MaxDelay:    15 * time.Minute,
		Window:      time.Hour,
		now:         time.Now,
	}
}

// Allowed sagt, ob ein Versuch für diesen Schlüssel zulässig ist. Ist er es
// nicht, liefert retryAfter die verbleibende Sperrzeit.
func (l *Limiter) Allowed(key string) (allowed bool, retryAfter time.Duration) {
	l.mu.Lock()
	defer l.mu.Unlock()

	c, ok := l.attempts[key]
	if !ok {
		return true, 0
	}
	now := l.now()

	if now.Sub(c.lastFailure) > l.Window {
		delete(l.attempts, key)
		return true, 0
	}
	if now.Before(c.lockedUntil) {
		return false, c.lockedUntil.Sub(now).Round(time.Second)
	}
	return true, 0
}

// Fail vermerkt einen Fehlversuch und verlängert bei Bedarf die Sperre.
func (l *Limiter) Fail(key string) {
	l.mu.Lock()
	defer l.mu.Unlock()

	now := l.now()
	c, ok := l.attempts[key]
	if !ok || now.Sub(c.lastFailure) > l.Window {
		c = &counter{}
		l.attempts[key] = c
	}
	c.failures++
	c.lastFailure = now

	if c.failures >= l.MaxAttempts {
		// Exponentiell ab dem ersten Überschreiten: 30 s, 1 min, 2 min …
		delay := l.BaseDelay << (c.failures - l.MaxAttempts)
		if delay > l.MaxDelay || delay <= 0 {
			delay = l.MaxDelay
		}
		c.lockedUntil = now.Add(delay)
	}
}

// Reset löscht den Zähler nach einer erfolgreichen Anmeldung.
func (l *Limiter) Reset(key string) {
	l.mu.Lock()
	defer l.mu.Unlock()
	delete(l.attempts, key)
}

// Cleanup entfernt verfallene Zähler. Ohne diesen Aufruf würde die Map bei
// dauerhaftem Beschuss aus wechselnden IPs unbegrenzt wachsen.
func (l *Limiter) Cleanup() {
	l.mu.Lock()
	defer l.mu.Unlock()

	now := l.now()
	for key, c := range l.attempts {
		if now.Sub(c.lastFailure) > l.Window && now.After(c.lockedUntil) {
			delete(l.attempts, key)
		}
	}
}

// Size liefert die Anzahl verfolgter Schlüssel (für Tests und Diagnose).
func (l *Limiter) Size() int {
	l.mu.Lock()
	defer l.mu.Unlock()
	return len(l.attempts)
}
