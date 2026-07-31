package httpd

import (
	"strings"
	"sync"
	"time"
)

// Zwei Dinge gehören in die Hand des Kontoinhabers und nicht auf die
// Kommandozeile des Servers: der Wechsel des zweiten Faktors, wenn das Telefon
// getauscht wird, und der Überblick über die eigenen offenen Sitzungen.
//
// Das zweite ist mehr als Bequemlichkeit: Ein entwendetes Sitzungscookie
// hinterlässt sonst keine Spur, die dem Betroffenen auffiele. Erst die Liste
// mit Adresse und letzter Aktivität macht eine übernommene Sitzung sichtbar —
// und die Schaltfläche daneben beendet sie.

// pendingTOTPTTL ist die Frist, in der ein begonnener Wechsel abgeschlossen
// werden muss.
const pendingTOTPTTL = 15 * time.Minute

// pendingSecrets hält angefangene Wechsel des zweiten Faktors.
//
// Das neue Geheimnis darf erst dann in die Datenbank, wenn es bestätigt ist:
// Wer den Vorgang abbricht — weil die App abstürzt oder das Telefon leer ist —
// muss sich weiterhin mit dem alten Faktor anmelden können.
type pendingSecrets struct {
	mu     sync.Mutex
	byUser map[int64]pendingSecret
}

type pendingSecret struct {
	secret  string
	expires time.Time
}

func newPendingSecrets() *pendingSecrets {
	return &pendingSecrets{byUser: make(map[int64]pendingSecret)}
}

func (p *pendingSecrets) put(userID int64, secret string) {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.byUser[userID] = pendingSecret{secret: secret, expires: time.Now().Add(pendingTOTPTTL)}
}

func (p *pendingSecrets) get(userID int64) (string, bool) {
	p.mu.Lock()
	defer p.mu.Unlock()

	entry, ok := p.byUser[userID]
	if !ok {
		return "", false
	}
	if time.Now().After(entry.expires) {
		delete(p.byUser, userID)
		return "", false
	}
	return entry.secret, true
}

// mitFrist liefert das Geheimnis samt Ablauf.
//
// Die neue Oberfläche braucht die Frist, get allein genügt ihr nicht: Sie zeigt
// einen begonnenen Wechsel nach einem Neuladen wieder an, und ohne die Angabe,
// wie lange er noch gilt, sieht ein abgelaufener Wechsel wie ein Fehler des
// Panels aus. Die gerenderte Seite der alten Oberfläche brauchte das nicht — dort
// war der halbe Wechsel eine eigene Seite, die man verließ.
func (p *pendingSecrets) mitFrist(userID int64) (string, time.Time, bool) {
	p.mu.Lock()
	defer p.mu.Unlock()

	entry, ok := p.byUser[userID]
	if !ok {
		return "", time.Time{}, false
	}
	if time.Now().After(entry.expires) {
		delete(p.byUser, userID)
		return "", time.Time{}, false
	}
	return entry.secret, entry.expires, true
}

func (p *pendingSecrets) drop(userID int64) {
	p.mu.Lock()
	defer p.mu.Unlock()
	delete(p.byUser, userID)
}

// ------------------------------------------------- Zweiter Faktor wechseln ---

// ------------------------------------------------------- Eigene Sitzungen ---

func shortID(id string) string {
	if len(id) > 12 {
		return id[:12]
	}
	return id
}

// shortenUserAgent macht aus der üblichen Bandwurmkennung etwas Lesbares.
// Eine genaue Auswertung wäre Ratearbeit; hier geht es nur darum, zwei
// Sitzungen auseinanderhalten zu können.
func shortenUserAgent(ua string) string {
	if ua == "" {
		return "unbekannt"
	}
	for _, name := range []string{"Firefox", "Edg", "Chrome", "Safari", "curl", "Go-http-client"} {
		if i := strings.Index(ua, name); i >= 0 {
			rest := ua[i:]
			if j := strings.IndexAny(rest, " ;)"); j > 0 {
				rest = rest[:j]
			}
			if name == "Edg" {
				rest = "Edge" + strings.TrimPrefix(rest, "Edg")
			}
			return rest
		}
	}
	if len(ua) > 40 {
		return ua[:40] + "…"
	}
	return ua
}
