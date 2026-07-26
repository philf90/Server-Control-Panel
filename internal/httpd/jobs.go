package httpd

import (
	"context"
	"sync"
	"time"

	"github.com/philf90/asylum/internal/privops"
)

// maxJobLines begrenzt den mitgeschriebenen Ausgabepuffer eines Jobs.
const maxJobLines = 5000

// job ist ein langlaufender Vorgang mit Live-Ausgabe, etwa ein Paket-Update.
//
// Der Vorgang läuft serverseitig weiter, auch wenn der Browser die Verbindung
// verliert. Ein abgebrochenes apt-get mitten im dpkg-Lauf hinterlässt ein
// halb konfiguriertes System — das darf nicht davon abhängen, ob jemand den
// Tab offen lässt.
type job struct {
	mu       sync.RWMutex
	kind     string
	actor    string
	started  time.Time
	finished time.Time
	lines    []string
	err      error
	done     bool
	subs     map[chan string]struct{}
}

func newJob(kind, actor string) *job {
	return &job{
		kind:    kind,
		actor:   actor,
		started: time.Now(),
		subs:    make(map[chan string]struct{}),
	}
}

func (j *job) append(line string) {
	j.mu.Lock()
	if len(j.lines) < maxJobLines {
		j.lines = append(j.lines, line)
	}
	subs := make([]chan string, 0, len(j.subs))
	for ch := range j.subs {
		subs = append(subs, ch)
	}
	j.mu.Unlock()

	for _, ch := range subs {
		select {
		case ch <- line:
		default: // langsamer Betrachter überspringt eine Zeile
		}
	}
}

func (j *job) finish(err error) {
	j.mu.Lock()
	j.err = err
	j.done = true
	j.finished = time.Now()
	subs := make([]chan string, 0, len(j.subs))
	for ch := range j.subs {
		subs = append(subs, ch)
	}
	j.subs = make(map[chan string]struct{})
	j.mu.Unlock()

	for _, ch := range subs {
		close(ch)
	}
}

func (j *job) snapshot() (lines []string, done bool, err error) {
	j.mu.RLock()
	defer j.mu.RUnlock()

	out := make([]string, len(j.lines))
	copy(out, j.lines)
	return out, j.done, j.err
}

func (j *job) subscribe() (ch chan string, alreadyDone bool) {
	j.mu.Lock()
	defer j.mu.Unlock()

	if j.done {
		return nil, true
	}
	ch = make(chan string, 64)
	j.subs[ch] = struct{}{}
	return ch, false
}

func (j *job) unsubscribe(ch chan string) {
	j.mu.Lock()
	defer j.mu.Unlock()

	if _, ok := j.subs[ch]; ok {
		delete(j.subs, ch)
		close(ch)
	}
}

// jobs hält den jeweils letzten Vorgang je Art. Mehr als einer gleichzeitig
// ist nicht vorgesehen: Zwei parallele apt-Läufe blockieren sich ohnehin an
// der dpkg-Sperre.
type jobs struct {
	mu      sync.Mutex
	current map[string]*job
}

func newJobs() *jobs {
	return &jobs{current: make(map[string]*job)}
}

// start legt einen Job an, sofern für diese Art keiner läuft.
func (r *jobs) start(kind, actor string) (*job, bool) {
	r.mu.Lock()
	defer r.mu.Unlock()

	if existing, ok := r.current[kind]; ok {
		if _, done, _ := existing.snapshot(); !done {
			return existing, false
		}
	}
	j := newJob(kind, actor)
	r.current[kind] = j
	return j, true
}

func (r *jobs) get(kind string) *job {
	r.mu.Lock()
	defer r.mu.Unlock()
	return r.current[kind]
}

// ------------------------------------------------- Firewall-Rückrollschutz ---

// firewallGuard rollt eine Regeländerung zurück, wenn sie nicht bestätigt wird.
//
// Der häufigste Weg, sich von einem Server auszusperren, ist eine
// Firewall-Regel, die den eigenen Zugang mit abschneidet — und man merkt es
// genau dann, wenn man es nicht mehr korrigieren kann. Deshalb gilt jede
// Änderung zunächst auf Probe: Ohne Bestätigung im Browser stellt das Panel
// den vorherigen Stand wieder her.
type firewallGuard struct {
	mu       sync.Mutex
	pending  bool
	previous []privops.FirewallRule
	deadline time.Time
	cancel   context.CancelFunc
}

// firewallConfirmWindow ist die Frist zur Bestätigung.
const firewallConfirmWindow = 60 * time.Second

func newFirewallGuard() *firewallGuard { return &firewallGuard{} }

// arm merkt sich den vorherigen Stand und startet die Frist.
func (g *firewallGuard) arm(previous []privops.FirewallRule, revert func(context.Context, []privops.FirewallRule) error) {
	g.mu.Lock()
	defer g.mu.Unlock()

	if g.cancel != nil {
		g.cancel()
	}
	ctx, cancel := context.WithCancel(context.Background())
	g.pending = true
	g.previous = previous
	g.deadline = time.Now().Add(firewallConfirmWindow)
	g.cancel = cancel

	go func() {
		timer := time.NewTimer(firewallConfirmWindow)
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
		rules := g.previous
		g.pending = false
		g.previous = nil
		g.cancel = nil
		g.mu.Unlock()

		revertCtx, revertCancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer revertCancel()
		_ = revert(revertCtx, rules)
	}()
}

// confirm bestätigt die Änderung und verhindert den Rückbau.
func (g *firewallGuard) confirm() bool {
	g.mu.Lock()
	defer g.mu.Unlock()

	if !g.pending {
		return false
	}
	if g.cancel != nil {
		g.cancel()
	}
	g.pending = false
	g.previous = nil
	g.cancel = nil
	return true
}

// state liefert den Zustand für die Oberfläche.
func (g *firewallGuard) state() (pending bool, remaining time.Duration) {
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
