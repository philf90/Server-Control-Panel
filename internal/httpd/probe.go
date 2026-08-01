package httpd

// Die Probe mit Rückweg — Grundsatz VI, an einer Stelle.
//
// Der häufigste Weg, sich von einem Server auszusperren, ist eine Änderung, die
// den eigenen Zugang mit abschneidet — und man merkt es genau dann, wenn man sie
// nicht mehr korrigieren kann. Dagegen hilft keine Rückfrage vorher: Wer eine
// Regel für richtig hält, hält sie auch beim Bestätigen für richtig. Was hilft,
// ist eine Frist danach. Die Änderung gilt zunächst auf Probe; bleibt die
// Bestätigung aus dem Browser aus, stellt das Panel den vorherigen Stand von
// selbst wieder her.
//
// Gebaut wurde das für die Firewall, und die Begründung stammt von dort: Das
// Einschalten von ufw ist die gefährlichere der beiden Änderungen, weil dabei
// mit einem Schlag alles abgewiesen wird, was nicht ausdrücklich erlaubt ist.
// Der Rückweg heißt dann nicht „vorherige Regeln wiederherstellen", sondern
// „wieder ausschalten" — deshalb merkt sich der Wächter eine
// RÜCKNAHMEFUNKTION und keinen Zustand. Genau das macht ihn hier
// wiederverwendbar: Was zurückzunehmen ist, weiß der Aufrufer, nicht der
// Wächter.
//
// Der zweite Anlass ist der Webserver (docs/18-webserver.md §7.4): Eine Site,
// die den Port des Panels an sich zieht, oder eine Konfiguration, nach der nginx
// zwar startet, aber nicht mehr antwortet, ist derselbe Fall wie eine Regel, die
// den eigenen Zugang abschneidet. Herausgelöst wurde der Wächter deshalb VOR
// dem Schreibpfad und in einem eigenen Schritt, damit die Sicherung der Firewall
// dabei unverändert bleibt.
//
// Ein Wächter je Bereich und nicht einer für alles: Zwei Wächter, die sich eine
// Frist teilen, hießen, dass eine bestätigte Firewalländerung eine unbestätigte
// Site mitbestätigt.

import (
	"context"
	"sync"
	"time"
)

// probeVorgabefenster ist die Frist, wenn niemand eine gesetzt hat.
//
// Eine Frist von null wäre ein Rückbau, der sofort losläuft — die Vorgabe ist
// sicherer als eine Zahl, die niemand gemeint hat.
const probeVorgabefenster = 60 * time.Second

// probenWaechter nimmt eine Änderung zurück, wenn sie nicht bestätigt wird.
type probenWaechter struct {
	mu       sync.Mutex
	pending  bool
	subject  string
	deadline time.Time
	cancel   context.CancelFunc
	// fenster ist die Frist. Als Feld und nicht als Konstante im Code, damit der
	// Rückbau prüfbar ist: Die 60 Sekunden des Betriebs abzuwarten wäre ein Test,
	// den niemand laufen lässt — und dann bliebe die wichtigste Sicherung des
	// Panels die einzige ungeprüfte.
	fenster time.Duration
}

// neuerProbenWaechter baut einen Wächter mit der genannten Frist. Null oder
// weniger heißt: die Vorgabe.
func neuerProbenWaechter(fenster time.Duration) *probenWaechter {
	if fenster <= 0 {
		fenster = probeVorgabefenster
	}
	return &probenWaechter{fenster: fenster}
}

// arm startet die Frist. subject benennt für die Oberfläche, was auf Probe
// steht; revert nimmt es zurück, wenn niemand bestätigt.
func (g *probenWaechter) arm(subject string, revert func(context.Context) error) {
	g.mu.Lock()
	defer g.mu.Unlock()

	if g.cancel != nil {
		g.cancel()
	}
	fenster := g.fenster
	if fenster <= 0 {
		// Auch hier, nicht nur im Konstruktor: Ein Wächter als Nullwert eines
		// Feldes käme sonst mit einer Frist von null in Betrieb.
		fenster = probeVorgabefenster
	}

	ctx, cancel := context.WithCancel(context.Background())
	g.pending = true
	g.subject = subject
	g.deadline = time.Now().Add(fenster)
	g.cancel = cancel

	go func() {
		timer := time.NewTimer(fenster)
		defer timer.Stop()

		select {
		case <-ctx.Done():
			return
		case <-timer.C:
		}

		g.mu.Lock()
		if !g.pending {
			g.mu.Unlock()
			return
		}
		g.pending = false
		g.subject = ""
		g.cancel = nil
		g.mu.Unlock()

		revertCtx, revertCancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer revertCancel()
		_ = revert(revertCtx)
	}()
}

// confirm bestätigt die Änderung und verhindert den Rückbau.
func (g *probenWaechter) confirm() bool {
	g.mu.Lock()
	defer g.mu.Unlock()

	if !g.pending {
		return false
	}
	if g.cancel != nil {
		g.cancel()
	}
	g.pending = false
	g.subject = ""
	g.cancel = nil
	return true
}

// subjectOf benennt, was gerade auf Probe steht.
func (g *probenWaechter) subjectOf() string {
	g.mu.Lock()
	defer g.mu.Unlock()
	return g.subject
}

// state liefert den Zustand für die Oberfläche.
func (g *probenWaechter) state() (pending bool, remaining time.Duration) {
	g.mu.Lock()
	defer g.mu.Unlock()

	if !g.pending {
		return false, 0
	}
	remaining = time.Until(g.deadline).Round(time.Second)
	if remaining < 0 {
		remaining = 0
	}
	return true, remaining
}
