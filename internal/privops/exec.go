package privops

import (
	"bufio"
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"os/exec"
	"strings"
	"sync"
	"time"
)

// Grenzen für Kommandoaufrufe.
const (
	defaultTimeout = 30 * time.Second
	// Paketoperationen dürfen länger dauern als eine Statusabfrage.
	longTimeout = 30 * time.Minute
	// Obergrenze für eingesammelte Ausgabe. Ohne sie könnte ein Kommando mit
	// endloser Ausgabe den Speicher des Panels füllen.
	maxOutput = 4 << 20 // 4 MiB
)

// ErrNotAllowed meldet ein Kommando außerhalb der Allowlist.
var ErrNotAllowed = errors.New("kommando nicht erlaubt")

// allowedCommands ist die vollständige Liste der Programme, die das Panel
// aufrufen darf, mit ihren zulässigen Pfaden.
//
// Der Pfad wird nicht über $PATH gesucht: Ein manipuliertes PATH-Element wäre
// sonst ein direkter Weg zu Codeausführung als root.
var allowedCommands = map[string][]string{
	"systemctl":   {"/usr/bin/systemctl", "/bin/systemctl"},
	"systemd-run": {"/usr/bin/systemd-run", "/bin/systemd-run"},
	"journalctl":  {"/usr/bin/journalctl", "/bin/journalctl"},
	"apt-get":     {"/usr/bin/apt-get"},
	"apt-cache":   {"/usr/bin/apt-cache"},
	"dpkg-query":  {"/usr/bin/dpkg-query"},
	"nft":         {"/usr/sbin/nft", "/sbin/nft"},
	// Genau ein Eintrag für Docker, und keiner für Compose: Compose v2 ist ein
	// Unterkommando desselben Binaries ("docker compose"). Das alte
	// docker-compose v1 ist bewusst nicht aufgenommen — es ist abgekündigt, und
	// ein zweiter erlaubter Pfad wäre eine zweite Angriffsfläche für eine
	// Fassung, die niemand mehr pflegt.
	"docker": {"/usr/bin/docker"},
	// Genau ein Eintrag für den Webserver, und der heißt nginx. Caddy, Apache
	// und lighttpd stehen bewusst NICHT hier: Verwaltet wird nginx, jeder andere
	// Webserver wird erkannt und nicht angefasst. Ein zweiter Eintrag wäre die
	// Zusage, auch dessen Konfiguration schreiben zu können — mit zweiter
	// Syntax, zweitem Prüfprogramm und zweitem Angriffsdurchgang.
	// Begründung in docs/18-webserver.md E1.
	"nginx": {"/usr/sbin/nginx", "/sbin/nginx"},
	// ss beantwortet die einzige Frage, an der die Installation eines Webservers
	// hängt: Hört auf Port 80 oder 443 schon jemand? Gefragt wird nach dem Port
	// und nicht nach einem Paketnamen — sonst übersieht die Prüfung genau die
	// Fälle, die sie abfangen soll (Apache, ein Traefik im Container, ein
	// selbstgebautes Binary). Rein lesend; ss ändert nichts.
	"ss": {"/usr/bin/ss", "/bin/ss", "/usr/sbin/ss"},
	// sshd wird ausschließlich mit -t aufgerufen: Konfiguration prüfen, nichts
	// starten. Der Editor kann sshd_config ändern, und ein Tippfehler darin
	// kostet den Zugang zum Server — siehe configcheck.go.
	"sshd":       {"/usr/sbin/sshd", "/sbin/sshd"},
	"ufw":        {"/usr/sbin/ufw", "/sbin/ufw"},
	"useradd":    {"/usr/sbin/useradd", "/sbin/useradd"},
	"usermod":    {"/usr/sbin/usermod", "/sbin/usermod"},
	"userdel":    {"/usr/sbin/userdel", "/sbin/userdel"},
	"passwd":     {"/usr/bin/passwd"},
	"ssh-keygen": {"/usr/bin/ssh-keygen"},
	"id":         {"/usr/bin/id", "/bin/id"},
}

// Command beschreibt einen Aufruf. Args werden als Argumentvektor übergeben,
// niemals über eine Shell — Anführungszeichen, Semikolons und Backticks in
// einem Argument sind damit bedeutungslos.
type Command struct {
	Name    string
	Args    []string
	Stdin   string
	Timeout time.Duration
	// Env ergänzt die Umgebung. Der Basiszustand ist bewusst minimal.
	Env []string
	// Stream bekommt jede Ausgabezeile, sobald sie anfällt.
	Stream LineWriter
	// Ohne Frist heißt: Das Kommando läuft, bis der übergebene Kontext
	// abgebrochen wird. Genau ein Aufruf braucht das — `journalctl --follow`,
	// das endet, wenn niemand mehr zusieht, und nicht nach einer Zeit, die hier
	// niemand kennen kann. Ausdrücklich als Feld und nicht als „Timeout = 0",
	// weil null heute die Vorgabefrist bedeutet: Ein vergessenes Timeout wäre
	// sonst plötzlich ein Kommando ohne Ende.
	OhneFrist bool
}

// Result ist das Ergebnis eines Aufrufs.
type Result struct {
	Stdout   string
	Stderr   string
	ExitCode int
}

// Runner führt Kommandos aus. Tests setzen eine eigene Implementierung ein und
// kommen damit ohne echte Systemaufrufe aus.
type Runner interface {
	Run(ctx context.Context, cmd Command) (Result, error)
}

// ExecRunner ist die echte Implementierung.
type ExecRunner struct{}

// Run führt das Kommando aus.
func (ExecRunner) Run(ctx context.Context, cmd Command) (Result, error) {
	path, err := resolve(cmd.Name)
	if err != nil {
		return Result{}, err
	}

	timeout := cmd.Timeout
	if timeout <= 0 {
		timeout = defaultTimeout
	}
	if cmd.OhneFrist {
		// Der Kontext des Aufrufers ist die Frist. CommandContext tötet den
		// Prozess, sobald er abgebrochen wird — bei einem Betrachter, der die
		// Seite schließt, ist das genau der Zeitpunkt.
		timeout = 0
	} else {
		var cancel context.CancelFunc
		ctx, cancel = context.WithTimeout(ctx, timeout)
		defer cancel()
	}

	c := exec.CommandContext(ctx, path, cmd.Args...) //nolint:gosec // Pfad aus Allowlist, Argumente ohne Shell
	// Minimale, vorhersagbare Umgebung. LC_ALL=C hält die Ausgabe in einer
	// Sprache, deren Format wir kennen — sonst scheitert das Parsen auf einem
	// deutsch eingestellten Server.
	c.Env = append([]string{
		"PATH=/usr/sbin:/usr/bin:/sbin:/bin",
		"LC_ALL=C",
		"LANG=C",
		"DEBIAN_FRONTEND=noninteractive",
	}, cmd.Env...)

	if cmd.Stdin != "" {
		c.Stdin = strings.NewReader(cmd.Stdin)
	}

	var stdout, stderr limitedBuffer
	stdout.limit = maxOutput
	stderr.limit = maxOutput

	if cmd.Stream != nil {
		pr, pw := io.Pipe()
		c.Stdout = io.MultiWriter(&stdout, pw)
		c.Stderr = io.MultiWriter(&stderr, pw)

		done := make(chan struct{})
		go func() {
			defer close(done)
			sc := bufio.NewScanner(pr)
			sc.Buffer(make([]byte, 0, 64*1024), 1024*1024)
			for sc.Scan() {
				cmd.Stream(sc.Text())
			}
		}()

		err = c.Run()
		_ = pw.Close()
		<-done
	} else {
		c.Stdout = &stdout
		c.Stderr = &stderr
		err = c.Run()
	}

	res := Result{Stdout: stdout.String(), Stderr: stderr.String()}

	var exitErr *exec.ExitError
	switch {
	case err == nil:
		return res, nil
	case errors.As(err, &exitErr):
		res.ExitCode = exitErr.ExitCode()
		// Ein Exit-Code ungleich null ist ein Ergebnis, kein Programmfehler.
		// Die Auswertung übernimmt der Aufrufer, der weiß, was er erwartet.
		return res, nil
	case errors.Is(ctx.Err(), context.Canceled):
		// Ein abgebrochener Kontext ist bei einem Kommando ohne Frist der
		// vorgesehene Weg zum Ende: Der Betrachter hat die Seite verlassen. Der
		// Aufrufer erkennt das an context.Canceled und macht daraus keinen
		// Fehlerbericht.
		return res, fmt.Errorf("%s: %w", cmd.Name, context.Canceled)
	case errors.Is(ctx.Err(), context.DeadlineExceeded):
		if cmd.OhneFrist {
			// Die Frist stammt dann vom Aufrufer, nicht von hier — eine Zahl zu
			// nennen, die wir nicht gesetzt haben, wäre irreführend.
			return res, fmt.Errorf("%s: Zeitüberschreitung des Aufrufers", cmd.Name)
		}
		return res, fmt.Errorf("%s: Zeitüberschreitung nach %s", cmd.Name, timeout)
	default:
		return res, fmt.Errorf("%s: %w", cmd.Name, err)
	}
}

// resolve findet den absoluten Pfad eines erlaubten Kommandos.
func resolve(name string) (string, error) {
	candidates, ok := allowedCommands[name]
	if !ok {
		return "", fmt.Errorf("%w: %q", ErrNotAllowed, name)
	}
	for _, path := range candidates {
		if isExecutable(path) {
			return path, nil
		}
	}
	return "", fmt.Errorf("%s ist auf diesem System nicht vorhanden", name)
}

// limitedBuffer sammelt Ausgabe bis zu einer Obergrenze und verwirft den Rest.
type limitedBuffer struct {
	mu       sync.Mutex
	buf      bytes.Buffer
	limit    int
	overflow bool
}

func (b *limitedBuffer) Write(p []byte) (int, error) {
	b.mu.Lock()
	defer b.mu.Unlock()

	if remaining := b.limit - b.buf.Len(); remaining > 0 {
		if len(p) > remaining {
			b.buf.Write(p[:remaining])
			b.overflow = true
		} else {
			b.buf.Write(p)
		}
	} else {
		b.overflow = true
	}
	// Immer die volle Länge melden: Ein kurzer Rückgabewert würde das
	// schreibende Kommando mit einem Fehler abbrechen lassen.
	return len(p), nil
}

func (b *limitedBuffer) String() string {
	b.mu.Lock()
	defer b.mu.Unlock()

	s := b.buf.String()
	if b.overflow {
		s += "\n… Ausgabe gekürzt"
	}
	return s
}
