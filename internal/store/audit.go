package store

import (
	"context"
	"fmt"
	"strings"
	"time"
)

// Ergebnisse eines Audit-Eintrags.
const (
	ResultOK     = "ok"
	ResultDenied = "denied"
	ResultError  = "error"
)

// AuditEntry ist ein Eintrag im Audit-Log.
type AuditEntry struct {
	ID     int64
	At     time.Time
	Actor  string
	Action string
	Target string
	Result string
	IP     string
	Detail string
}

// maxAuditFeld begrenzt ein Textfeld. Ein Pfad darf bis zu 4096 Zeichen lang
// sein; als Zeile in einer Übersicht ist er dann kein Eintrag mehr, sondern eine
// Wand.
const maxAuditFeld = 1024

// AppendAudit schreibt einen Eintrag. Das Audit-Log ist nur additiv — es gibt
// bewusst keine Lösch- oder Änderungsfunktion.
func (db *DB) AppendAudit(ctx context.Context, e AuditEntry) error {
	if e.At.IsZero() {
		e.At = time.Now()
	}
	// Steuerzeichen werden sichtbar gemacht, nicht übernommen. Seit es den
	// Dateimanager gibt, wandern freie Pfade in dieses Feld — und ein Pfad darf
	// einen Zeilenumbruch enthalten. In einem zeilenweisen Protokoll (geplant:
	// /var/log/asylum/audit.log) würde daraus aus einem Eintrag zwei, und der
	// zweite wäre frei erfunden. Auch in der Anzeige hat ein Zeilenumbruch
	// mitten in einem Zielpfad nichts zu suchen.
	e.Actor = auditFeld(e.Actor)
	e.Action = auditFeld(e.Action)
	e.Target = auditFeld(e.Target)
	e.Result = auditFeld(e.Result)
	e.IP = auditFeld(e.IP)
	e.Detail = auditFeld(e.Detail)

	_, err := db.sql.ExecContext(ctx, `
		INSERT INTO audit_log (at, actor, action, target, result, ip, detail)
		VALUES (?, ?, ?, ?, ?, ?, ?)`,
		e.At.UTC().Format(time.RFC3339), e.Actor, e.Action, e.Target, e.Result, e.IP, e.Detail)
	if err != nil {
		return fmt.Errorf("audit schreiben: %w", err)
	}
	return nil
}

// auditFeld macht ein Textfeld protokollfähig: Steuerzeichen werden als
// Escape-Folge sichtbar, die Länge ist begrenzt.
//
// Sichtbar machen statt entfernen: Ein Pfad, aus dem stillschweigend Zeichen
// verschwinden, führt bei der Fehlersuche in die Irre. "\n" im Eintrag sagt,
// was dort stand.
func auditFeld(v string) string {
	var b strings.Builder
	b.Grow(len(v))
	for _, r := range v {
		switch {
		case r == '\n':
			b.WriteString(`\n`)
		case r == '\r':
			b.WriteString(`\r`)
		case r == '\t':
			b.WriteString(`\t`)
		case r < 0x20 || r == 0x7f:
			// Fprintf statt WriteString(Sprintf(…)): Der Umweg über eine
			// Zwischenzeichenkette kostet eine Allokation je Zeichen, und
			// staticcheck (QF1012) besteht darauf. Ein strings.Builder gibt beim
			// Schreiben nie einen Fehler zurück.
			_, _ = fmt.Fprintf(&b, `\x%02x`, r)
		// Die Schreibrichtungs-Umschalter lassen einen Eintrag anders aussehen,
		// als er ist — in einem Audit-Log der letzte Ort, an dem das tragbar wäre.
		case r >= 0x202a && r <= 0x202e, r >= 0x2066 && r <= 0x2069:
			_, _ = fmt.Fprintf(&b, `\u%04x`, r)
		default:
			b.WriteRune(r)
		}
		if b.Len() >= maxAuditFeld {
			return b.String()[:maxAuditFeld] + "…"
		}
	}
	return b.String()
}

// ListAudit liefert die jüngsten Einträge.
func (db *DB) ListAudit(ctx context.Context, limit int) ([]AuditEntry, error) {
	if limit <= 0 || limit > 500 {
		limit = 100
	}
	rows, err := db.sql.QueryContext(ctx, `
		SELECT id, at, actor, action, target, result, ip, detail
		FROM audit_log ORDER BY id DESC LIMIT ?`, limit)
	if err != nil {
		return nil, fmt.Errorf("audit lesen: %w", err)
	}
	defer func() { _ = rows.Close() }()

	var out []AuditEntry
	for rows.Next() {
		var (
			e  AuditEntry
			at string
		)
		if err := rows.Scan(&e.ID, &at, &e.Actor, &e.Action, &e.Target, &e.Result, &e.IP, &e.Detail); err != nil {
			return nil, err
		}
		e.At, _ = time.Parse(time.RFC3339, at)
		out = append(out, e)
	}
	return out, rows.Err()
}
