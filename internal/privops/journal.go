package privops

import (
	"context"
	"fmt"
	"strings"
	"sync"
	"time"
)

// Ein Journal merkt sich, was das Panel auf der Maschine ausgeführt hat.
//
// Der Zweck ist nicht Protokollierung — dafür gibt es das Audit-Log in der
// Datenbank, und das überlebt einen Neustart. Das Journal speist die Konsole
// am unteren Rand jeder Seite: Wer im Panel auf „neu starten" klickt, soll
// sehen, dass dahinter `systemctl restart ssh.service` steckt, wie lange es
// lief und was es zurückgab. Ein Panel, das seine Befehle zeigt, lässt sich
// überprüfen — und wer per SSH weiterarbeitet, findet dieselben Befehle vor.
//
// Es liegt bewusst nur im Speicher und in einem Ring fester Größe: Ein
// Nebenprodukt der Oberfläche darf weder wachsen noch überleben.

// Notiz ist ein ausgeführtes Kommando, so wie die Konsole es zeigt.
type Notiz struct {
	Zeit     time.Time
	Befehl   string
	ExitCode int
	Dauer    time.Duration
	// Fehler steht, wenn der Aufruf selbst scheiterte — Zeitüberschreitung,
	// Kommando nicht vorhanden, nicht erlaubt. Ein Exit-Code ungleich null ist
	// kein Fehler in diesem Sinn, sondern ein Ergebnis.
	Fehler string
	// Meldung ist die erste sprechende Ausgabezeile eines fehlgeschlagenen
	// Aufrufs. Ohne sie stünde in der Konsole nur „✗ 1", und das beantwortet
	// keine Frage.
	Meldung string
}

// Gelungen sagt, ob der Aufruf durchlief.
func (n Notiz) Gelungen() bool { return n.Fehler == "" && n.ExitCode == 0 }

// DauerText ist die Laufzeit, wie sie in der Konsole steht: bis zehn Sekunden
// auf die Zehntel genau, darüber ganze Sekunden — dort interessiert die
// Größenordnung, nicht die Stelle dahinter.
func (n Notiz) DauerText() string {
	if n.Dauer < 10*time.Second {
		return fmt.Sprintf("%.1fs", n.Dauer.Seconds())
	}
	return fmt.Sprintf("%ds", int(n.Dauer.Round(time.Second).Seconds()))
}

// journalGroesse ist die Zahl der Einträge, die die Konsole vorhält. Genug für
// eine Arbeitssitzung, zu wenig, um Speicher zu kosten.
const journalGroesse = 50

// Journal ist ein Ringpuffer über die zuletzt ausgeführten Kommandos.
// Alle Methoden sind nebenläufig benutzbar.
type Journal struct {
	mu     sync.RWMutex
	ring   []Notiz
	naechs int
	voll   bool
}

// NewJournal baut ein Journal mit der Standardgröße.
func NewJournal() *Journal { return &Journal{ring: make([]Notiz, journalGroesse)} }

// Notiere hängt einen Eintrag an und verdrängt den ältesten.
func (j *Journal) Notiere(n Notiz) {
	if j == nil {
		return
	}
	j.mu.Lock()
	defer j.mu.Unlock()
	j.ring[j.naechs] = n
	j.naechs = (j.naechs + 1) % len(j.ring)
	if j.naechs == 0 {
		j.voll = true
	}
}

// Letzte liefert bis zu n Einträge, den jüngsten zuerst.
func (j *Journal) Letzte(n int) []Notiz {
	if j == nil || n <= 0 {
		return nil
	}
	j.mu.RLock()
	defer j.mu.RUnlock()

	vorhanden := j.naechs
	if j.voll {
		vorhanden = len(j.ring)
	}
	if n > vorhanden {
		n = vorhanden
	}
	out := make([]Notiz, 0, n)
	for i := 0; i < n; i++ {
		// Rückwärts vom zuletzt beschriebenen Platz.
		idx := (j.naechs - 1 - i + len(j.ring)) % len(j.ring)
		out = append(out, j.ring[idx])
	}
	return out
}

// Anzahl ist die Zahl der vorgehaltenen Einträge.
func (j *Journal) Anzahl() int {
	if j == nil {
		return 0
	}
	j.mu.RLock()
	defer j.mu.RUnlock()
	if j.voll {
		return len(j.ring)
	}
	return j.naechs
}

// MitJournal legt ein Journal um einen Runner. Der innere Runner bleibt
// unverändert; aufgezeichnet wird, was durchgeht.
func MitJournal(inner Runner, j *Journal) Runner {
	if j == nil {
		return inner
	}
	return journalRunner{inner: inner, journal: j}
}

// NewSystemMitJournal baut den echten Executor und schreibt jeden Aufruf mit.
func NewSystemMitJournal(j *Journal) *System {
	return NewSystemWithRunner(MitJournal(ExecRunner{}, j))
}

type journalRunner struct {
	inner   Runner
	journal *Journal
}

func (r journalRunner) Run(ctx context.Context, cmd Command) (Result, error) {
	start := time.Now()
	res, err := r.inner.Run(ctx, cmd)

	n := Notiz{
		Zeit:     start,
		Befehl:   Befehlszeile(cmd),
		ExitCode: res.ExitCode,
		Dauer:    time.Since(start),
	}
	if err != nil {
		n.Fehler = err.Error()
	}
	if !n.Gelungen() {
		n.Meldung = ersteZeile(res.Stderr, res.Stdout)
	}
	r.journal.Notiere(n)
	return res, err
}

// Befehlszeile setzt aus Name und Argumenten die Zeile zusammen, die in der
// Konsole steht. Stdin bleibt außen vor — dort stehen die Passwörter, die
// `passwd` und `chpasswd` entgegennehmen, und die haben in einer Anzeige nichts
// verloren.
func Befehlszeile(cmd Command) string {
	teile := make([]string, 0, len(cmd.Args)+1)
	teile = append(teile, cmd.Name)
	verdeckeNaechstes := false
	for _, a := range cmd.Args {
		switch {
		case verdeckeNaechstes:
			teile = append(teile, "•••")
			verdeckeNaechstes = false
		case istGeheimSchalter(a):
			teile = append(teile, a)
			verdeckeNaechstes = true
		default:
			teile = append(teile, verdecke(a))
		}
	}
	return strings.Join(teile, " ")
}

// istGeheimSchalter erkennt eine Option, deren Wert im nächsten Argument steht.
func istGeheimSchalter(a string) bool {
	if !strings.HasPrefix(a, "-") || strings.Contains(a, "=") {
		return false
	}
	return enthaeltGeheimwort(a)
}

// verdecke ersetzt den Wert einer `--option=wert`-Form, wenn die Option nach
// einem Geheimnis klingt. Freitext bleibt stehen: Ein ufw-Kommentar ist die
// Eingabe des Benutzers, kein Geheimnis, und ihn zu verdecken machte die Zeile
// unbrauchbar.
func verdecke(a string) string {
	name, _, gefunden := strings.Cut(a, "=")
	if !gefunden || !strings.HasPrefix(name, "-") {
		return a
	}
	if enthaeltGeheimwort(name) {
		return name + "=•••"
	}
	return a
}

func enthaeltGeheimwort(s string) bool {
	s = strings.ToLower(s)
	for _, wort := range []string{"token", "password", "passwort", "passwd", "secret", "geheim", "key"} {
		if strings.Contains(s, wort) {
			return true
		}
	}
	return false
}

// ersteZeile sucht die erste nichtleere Zeile — erst in stderr, dann in stdout.
// Sie wird gekürzt: Die Konsole hat eine Zeile Platz, keinen Absatz.
func ersteZeile(quellen ...string) string {
	const max = 160
	for _, q := range quellen {
		for _, z := range strings.Split(q, "\n") {
			z = strings.TrimSpace(z)
			if z == "" {
				continue
			}
			if len(z) > max {
				return z[:max] + " …"
			}
			return z
		}
	}
	return ""
}
