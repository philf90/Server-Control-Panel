package store

import (
	"context"
	"fmt"
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

// AppendAudit schreibt einen Eintrag. Das Audit-Log ist nur additiv — es gibt
// bewusst keine Lösch- oder Änderungsfunktion.
func (db *DB) AppendAudit(ctx context.Context, e AuditEntry) error {
	if e.At.IsZero() {
		e.At = time.Now()
	}
	_, err := db.sql.ExecContext(ctx, `
		INSERT INTO audit_log (at, actor, action, target, result, ip, detail)
		VALUES (?, ?, ?, ?, ?, ?, ?)`,
		e.At.UTC().Format(time.RFC3339), e.Actor, e.Action, e.Target, e.Result, e.IP, e.Detail)
	if err != nil {
		return fmt.Errorf("audit schreiben: %w", err)
	}
	return nil
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
