package httpd

import (
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
