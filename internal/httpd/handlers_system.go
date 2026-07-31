package httpd

import (
	"context"
	"fmt"
	"net/http"
	"sort"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// ---------------------------------------------------------------- Dienste ---

// ----------------------------------------------------------------- Pakete ---

const (
	jobPackages        = "packages"
	jobFirewallInstall = "firewall-install"
)

// refreshHinweis formuliert den Teilerfolg für die Oberfläche.
//
// Ohne Zahl der geglückten Quellen: apt zählt Indexdateien, nicht Quellen, und
// eine hergeleitete Zahl wäre eine Behauptung. Genannt wird, was fehlt.
func refreshHinweis(res privops.PackageRefreshResult) string {
	einleitung := "Eine Quelle ließ sich nicht abholen"
	if len(res.Failed) > 1 {
		einleitung = fmt.Sprintf("%d Quellen ließen sich nicht abholen", len(res.Failed))
	}
	return einleitung + ": " + quellenListe(res.Failed) +
		". Die übrigen Listen sind auf dem neuen Stand — die Aufstellung unten " +
		"kann deshalb unvollständig sein. Einzelheiten stehen im Auszug."
}

// quellenListe nennt die gescheiterten Quellen mit ihrem Grund.
func quellenListe(failed []privops.SourceFailure) string {
	teile := make([]string, 0, len(failed))
	for _, f := range failed {
		if f.Reason != "" {
			teile = append(teile, f.Source+" ("+f.Reason+")")
			continue
		}
		teile = append(teile, f.Source)
	}
	return strings.Join(teile, " · ")
}

// streamJob schickt den bisherigen und den folgenden Ausstoß eines Vorgangs
// als Server-Sent Events.
func (s *Server) streamJob(w http.ResponseWriter, r *http.Request, kind string) {
	j := s.jobs.get(kind)
	if j == nil {
		http.Error(w, "kein Vorgang", http.StatusNotFound)
		return
	}

	rc := http.NewResponseController(w)
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("X-Accel-Buffering", "no")
	w.WriteHeader(http.StatusOK)
	if err := rc.Flush(); err != nil {
		return
	}

	// Erst den bisherigen Stand, dann die neuen Zeilen — wer später dazukommt,
	// sieht den ganzen Lauf.
	lines, done, jobErr := j.snapshot()
	for _, line := range lines {
		if !writeSSE(w, rc, "output", line) {
			return
		}
	}
	if done {
		writeJobEnd(w, rc, jobErr)
		return
	}

	ch, alreadyDone := j.subscribe()
	if alreadyDone {
		_, _, jobErr := j.snapshot()
		writeJobEnd(w, rc, jobErr)
		return
	}
	defer j.unsubscribe(ch)

	ctx := r.Context()
	for {
		select {
		case <-ctx.Done():
			return
		case line, ok := <-ch:
			if !ok {
				_, _, jobErr := j.snapshot()
				writeJobEnd(w, rc, jobErr)
				return
			}
			if !writeSSE(w, rc, "output", line) {
				return
			}
		}
	}
}

func writeJobEnd(w http.ResponseWriter, rc *http.ResponseController, err error) {
	msg := "ok"
	if err != nil {
		msg = err.Error()
	}
	writeSSE(w, rc, "end", msg)
}

// --------------------------------------------------------------- Firewall ---

// revertFirewall schreibt das Ergebnis einer Rücknahme ins Audit-Log.
//
// Der Rückbau läuft, wenn niemand mehr zusieht — im schlimmsten Fall, weil das
// Panel nicht mehr erreichbar ist. Wenn er dann auch noch scheitert, ist der
// Audit-Eintrag die einzige Spur, die davon übrig bleibt.
func (s *Server) revertFirewall(_ context.Context, action string, err error) error {
	result, detail := store.ResultOK, "automatisch zurückgerollt (keine Bestätigung)"
	if err != nil {
		result, detail = store.ResultError, "Rückbau fehlgeschlagen: "+err.Error()
		s.log.Error("firewall-rückbau", "err", err)
	}
	if auditErr := s.db.AppendAudit(context.Background(), store.AuditEntry{
		At: time.Now(), Actor: "system", Action: action,
		Result: result, IP: "-", Detail: detail,
	}); auditErr != nil {
		s.log.Error("audit-eintrag", "err", auditErr)
	}
	return err
}

// ruleCoversPort sagt, ob der Regelsatz einen Port von überall her freigibt.
//
// Eine auf eine Quelle eingeschränkte Regel zählt hier bewusst nicht: Sie mag
// den eigenen Zugang decken, aber das lässt sich von hier aus nicht
// feststellen — und eine Sicherung, die im Zweifel "passt schon" sagt, ist
// keine.
func ruleCoversPort(rules []privops.FirewallRule, port int) bool {
	for _, r := range rules {
		if r.Port == port && r.Source == "" {
			return true
		}
	}
	return false
}

// openPortSummary listet auf, was nach dem Einschalten erreichbar bleibt.
func openPortSummary(rules []privops.FirewallRule) string {
	if len(rules) == 0 {
		return "keine Zugänge"
	}
	seen := make(map[string]struct{}, len(rules))
	parts := make([]string, 0, len(rules))
	for _, r := range rules {
		label := fmt.Sprintf("%d/%s", r.Port, r.Protocol)
		if r.Source != "" {
			label += " von " + r.Source
		}
		if _, dup := seen[label]; dup {
			continue
		}
		seen[label] = struct{}{}
		parts = append(parts, label)
	}
	sort.Strings(parts)
	return strings.Join(parts, ", ")
}

// ------------------------------------------------------------ Systembenutzer ---

// ------------------------------------------------------------------- Logs ---

// ensurePanelRule sorgt dafür, dass der Port des Panels freigegeben bleibt.
//
// Ohne diese Regel sperrt das nächste "ufw enable" den Bedienenden aus, und
// zwar aus der Seite heraus, auf der er das zurücknehmen könnte. Eine bereits
// vorhandene Regel für diesen Port bleibt unangetastet — auch eine auf eine
// Quelle eingeschränkte: Wer sein Panel bewusst nur aus dem eigenen Netz
// erreichbar macht, soll das dürfen.
func ensurePanelRule(rules []privops.FirewallRule, panelPort int) []privops.FirewallRule {
	for _, r := range rules {
		if r.Port == panelPort {
			return rules
		}
	}
	return append(rules, privops.FirewallRule{
		Port: panelPort, Protocol: "tcp", Comment: panelRuleComment,
	})
}

// panelRuleComment kennzeichnet die Regel, die das Panel selbst braucht.
const panelRuleComment = "Asylum-Panel"

// firewallRows baut die Zeilen des Formulars: die Regel des Panels zuerst und
// festgesetzt, danach die bestehenden, zuletzt Vorschläge für SSH.
//
// Der Vorschlag ist kein Selbstzweck. Wer ufw ohne SSH-Regel einschaltet,
// verliert den zweiten Weg auf den Server — und merkt es erst, wenn er ihn
// braucht. Der Port kommt aus sshd_config, nicht aus der Annahme "22".
func (s *Server) firewallRows(ctx context.Context, rules []privops.FirewallRule) []firewallRow {
	panelPort := s.cfg.Server.Port

	rows := make([]firewallRow, 0, len(rules)+2)
	rest := make([]privops.FirewallRule, 0, len(rules))

	panelRule := privops.FirewallRule{Port: panelPort, Protocol: "tcp", Comment: panelRuleComment}
	for _, r := range rules {
		if r.Port == panelPort && r.Protocol == "tcp" {
			panelRule = r
			continue
		}
		rest = append(rest, r)
	}
	rows = append(rows, firewallRow{
		Rule:   panelRule,
		Locked: true,
		Note:   "Über diesen Port erreichen Sie das Panel. Die Regel lässt sich nicht entfernen.",
	})

	belegt := func(port int) bool {
		for _, r := range rest {
			if r.Port == port {
				return true
			}
		}
		return false
	}

	for _, r := range rest {
		rows = append(rows, firewallRow{Rule: r})
	}

	for _, port := range s.ops.SSHPorts(ctx) {
		if port == panelPort || belegt(port) {
			continue
		}
		rows = append(rows, firewallRow{
			Rule:     privops.FirewallRule{Port: port, Protocol: "tcp", Comment: "SSH"},
			Proposed: true,
			Note:     "Vorschlag: Auf diesem Port lauscht sshd laut Konfiguration. Ohne die Regel wäre SSH nach dem Einschalten zu.",
		})
	}
	return rows
}
