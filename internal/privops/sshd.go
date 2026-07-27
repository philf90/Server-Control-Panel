package privops

import (
	"bufio"
	"context"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
)

// Pfade als Variablen, damit die Tests gegen ein Wegwerfverzeichnis laufen
// können — dasselbe Muster wie bei /etc/passwd.
var (
	sshdConfigPath = "/etc/ssh/sshd_config"
	sshdConfigDir  = "/etc/ssh/sshd_config.d"
)

// defaultSSHPort ist der Port, auf dem sshd ohne Angabe lauscht.
const defaultSSHPort = 22

// SSHPorts liest die Ports, auf denen sshd lauscht.
//
// Der Firewall-Seite reicht "22" als Annahme nicht: Wer sshd auf einen anderen
// Port gelegt hat, bekäme eine vorgeschlagene Regel, die auf nichts zeigt —
// und schaltet die Firewall im Vertrauen darauf ein. Der zweite Zugang zum
// Server wäre dann weg, und zwar leise.
//
// Gelesen werden sshd_config und die Ergänzungen in sshd_config.d. Mehrere
// Port-Zeilen sind erlaubt und bedeuten bei sshd tatsächlich mehrere Ports;
// deshalb wird gesammelt und nicht der erste Treffer genommen.
func (s *System) SSHPorts(ctx context.Context) []int {
	_ = ctx

	ports := make(map[int]struct{})
	sammleSSHPorts(sshdConfigPath, ports)

	if eintraege, err := os.ReadDir(sshdConfigDir); err == nil {
		namen := make([]string, 0, len(eintraege))
		for _, e := range eintraege {
			if !e.IsDir() && strings.HasSuffix(e.Name(), ".conf") {
				namen = append(namen, e.Name())
			}
		}
		// sshd liest die Ergänzungen in Sortierreihenfolge; für das Sammeln
		// von Ports ist sie ohne Belang, für ein nachvollziehbares Ergebnis
		// aber schon.
		sort.Strings(namen)
		for _, n := range namen {
			sammleSSHPorts(filepath.Join(sshdConfigDir, n), ports)
		}
	}

	if len(ports) == 0 {
		return []int{defaultSSHPort}
	}
	out := make([]int, 0, len(ports))
	for p := range ports {
		out = append(out, p)
	}
	sort.Ints(out)
	return out
}

// sammleSSHPorts liest die Port-Zeilen einer Konfigurationsdatei.
//
// Zeilen innerhalb eines Match-Blocks werden übergangen: Dort gilt "Port"
// nicht, und ein Wert von dort in der Firewall wäre schlicht falsch.
func sammleSSHPorts(path string, out map[int]struct{}) {
	f, err := os.Open(path) //nolint:gosec // fester Pfad, in Tests umgesetzt
	if err != nil {
		return
	}
	defer func() { _ = f.Close() }()

	imMatchBlock := false
	sc := bufio.NewScanner(f)
	for sc.Scan() {
		zeile := strings.TrimSpace(sc.Text())
		if zeile == "" || strings.HasPrefix(zeile, "#") {
			continue
		}
		wort, rest, _ := strings.Cut(zeile, " ")
		switch strings.ToLower(wort) {
		case "match":
			imMatchBlock = true
			continue
		case "port":
			if imMatchBlock {
				continue
			}
			// "Port 22" und "Port=22" sind beide zulässig.
			for _, feld := range strings.FieldsFunc(rest, func(r rune) bool {
				return r == ' ' || r == '\t' || r == '='
			}) {
				if p, err := strconv.Atoi(feld); err == nil && p > 0 && p <= 65535 {
					out[p] = struct{}{}
				}
				break
			}
		}
	}
}
