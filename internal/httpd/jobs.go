package httpd

import (
	"context"
	"sync"
	"time"
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
	// note ist eine Anmerkung zum Ergebnis, die kein Fehler ist: der
	// Teilerfolg von apt-get update etwa, bei dem einzelne Quellen klemmen und
	// die übrigen Listen trotzdem neu sind.
	note string
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

// setNote hinterlegt die Anmerkung zum Ergebnis. Vor finish aufzurufen: Wer den
// Vorgang als beendet sieht, soll auch die Anmerkung sehen.
func (j *job) setNote(note string) {
	j.mu.Lock()
	defer j.mu.Unlock()
	j.note = note
}

// jobStand ist der vollständige Zustand eines Vorgangs.
//
// snapshot liefert drei Werte, weil die alten Seiten nur drei brauchen. Die
// JSON-Schnittstelle braucht mehr: Wer nach einem Neuladen auf die Seite kommt,
// soll sehen, wer den Vorgang angestoßen hat, wann, und wie lange er lief —
// Grundsatz III aus docs/15-neuordnung.md, „Handlungen sind quittiert". Als
// eigener Typ und nicht als fünf weitere Rückgabewerte, damit ein Aufrufer nicht
// zwei davon vertauscht.
type jobStand struct {
	Art      string
	Akteur   string
	Start    time.Time
	Ende     time.Time
	Zeilen   []string
	Fertig   bool
	Fehler   error
	Hinweis  string
	Laufzeit time.Duration
}

// stand liest alles unter einem Schloss. Einzelne Lesemethoden hintereinander
// aufzurufen ergäbe ein Bild aus zwei Augenblicken: Zwischen `fertig` und
// `zeilen` kann der Vorgang enden, und die Oberfläche zeigte dann einen
// laufenden Vorgang mit vollständiger Ausgabe — oder umgekehrt.
func (j *job) stand() jobStand {
	j.mu.RLock()
	defer j.mu.RUnlock()

	zeilen := make([]string, len(j.lines))
	copy(zeilen, j.lines)

	// Bei einem laufenden Vorgang ist die Laufzeit die bis jetzt; bei einem
	// beendeten die gemessene. Ohne diese Unterscheidung wüchse die Angabe eines
	// vor Stunden beendeten Laufs immer weiter.
	ende := j.finished
	if !j.done {
		ende = time.Now()
	}

	return jobStand{
		Art:      j.kind,
		Akteur:   j.actor,
		Start:    j.started,
		Ende:     j.finished,
		Zeilen:   zeilen,
		Fertig:   j.done,
		Fehler:   j.err,
		Hinweis:  j.note,
		Laufzeit: ende.Sub(j.started),
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

// firewallGuard nimmt eine Firewall-Änderung zurück, wenn sie nicht bestätigt
// wird.
//
// Der häufigste Weg, sich von einem Server auszusperren, ist eine
// Firewall-Regel, die den eigenen Zugang mit abschneidet — und man merkt es
// genau dann, wenn man es nicht mehr korrigieren kann. Deshalb gilt jede
// Änderung zunächst auf Probe: Ohne Bestätigung im Browser stellt das Panel
// den vorherigen Stand wieder her.
//
// Das gilt nicht nur für Regeln. Das Einschalten von ufw ist die gefährlichere
// der beiden Änderungen, weil dabei mit einem Schlag alles abgewiesen wird, was
// nicht ausdrücklich erlaubt ist. Der Rückweg ist dann nicht "vorherige Regeln
// wiederherstellen", sondern "wieder ausschalten" — deshalb merkt sich der
// Wächter eine Rücknahmefunktion statt eines Regelsatzes.
type firewallGuard struct {
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

// firewallConfirmWindow ist die Frist zur Bestätigung.
const firewallConfirmWindow = 60 * time.Second

func newFirewallGuard() *firewallGuard {
	return &firewallGuard{fenster: firewallConfirmWindow}
}

// arm startet die Frist. subject benennt für die Oberfläche, was auf Probe
// steht; revert nimmt es zurück, wenn niemand bestätigt.
func (g *firewallGuard) arm(subject string, revert func(context.Context) error) {
	g.mu.Lock()
	defer g.mu.Unlock()

	if g.cancel != nil {
		g.cancel()
	}
	fenster := g.fenster
	if fenster <= 0 {
		// Eine Frist von null wäre ein Rückbau, der sofort losläuft — sicherer ist
		// die Vorgabe als eine Zahl, die niemand gesetzt hat.
		fenster = firewallConfirmWindow
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
	g.subject = ""
	g.cancel = nil
	return true
}

// subjectOf benennt, was gerade auf Probe steht.
func (g *firewallGuard) subjectOf() string {
	g.mu.Lock()
	defer g.mu.Unlock()
	return g.subject
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
