package store

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"time"
)

// Session ist eine serverseitig gehaltene Anmeldesitzung.
type Session struct {
	ID         string // SHA-256 des Cookie-Werts, nicht der Wert selbst
	UserID     int64
	CSRFToken  string
	CreatedAt  time.Time
	LastSeenAt time.Time
	ExpiresAt  time.Time
	IP         string
	UserAgent  string
}

// CreateSession legt eine Sitzung an.
func (db *DB) CreateSession(ctx context.Context, s Session) error {
	_, err := db.sql.ExecContext(ctx, `
		INSERT INTO sessions (id, user_id, csrf_token, created_at, last_seen_at, expires_at, ip, user_agent)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
		s.ID, s.UserID, s.CSRFToken,
		s.CreatedAt.UTC().Format(time.RFC3339),
		s.LastSeenAt.UTC().Format(time.RFC3339),
		s.ExpiresAt.UTC().Format(time.RFC3339),
		s.IP, s.UserAgent,
	)
	if err != nil {
		return fmt.Errorf("sitzung anlegen: %w", err)
	}
	return nil
}

// SessionByID lädt eine gültige Sitzung. Abgelaufene gelten als nicht
// vorhanden und werden gleich entfernt.
func (db *DB) SessionByID(ctx context.Context, id string) (Session, error) {
	var (
		s                                Session
		createdAt, lastSeenAt, expiresAt string
	)
	err := db.sql.QueryRowContext(ctx, `
		SELECT id, user_id, csrf_token, created_at, last_seen_at, expires_at, ip, user_agent
		FROM sessions WHERE id = ?`, id,
	).Scan(&s.ID, &s.UserID, &s.CSRFToken, &createdAt, &lastSeenAt, &expiresAt, &s.IP, &s.UserAgent)
	if errors.Is(err, sql.ErrNoRows) {
		return Session{}, ErrNotFound
	}
	if err != nil {
		return Session{}, fmt.Errorf("sitzung lesen: %w", err)
	}

	s.CreatedAt, _ = time.Parse(time.RFC3339, createdAt)
	s.LastSeenAt, _ = time.Parse(time.RFC3339, lastSeenAt)
	s.ExpiresAt, _ = time.Parse(time.RFC3339, expiresAt)

	if time.Now().After(s.ExpiresAt) {
		_ = db.DeleteSession(ctx, id)
		return Session{}, ErrNotFound
	}
	return s, nil
}

// TouchSession verlängert die Sitzung bei Aktivität (Idle-Timeout).
func (db *DB) TouchSession(ctx context.Context, id string, expiresAt time.Time) error {
	now := time.Now().UTC().Format(time.RFC3339)
	_, err := db.sql.ExecContext(ctx,
		`UPDATE sessions SET last_seen_at = ?, expires_at = ? WHERE id = ?`,
		now, expiresAt.UTC().Format(time.RFC3339), id)
	return err
}

// DeleteSession meldet eine Sitzung ab.
func (db *DB) DeleteSession(ctx context.Context, id string) error {
	_, err := db.sql.ExecContext(ctx, `DELETE FROM sessions WHERE id = ?`, id)
	return err
}

// DeleteUserSessions meldet alle Sitzungen eines Benutzers ab — nach einer
// Passwortänderung oder beim Sperren des Kontos.
func (db *DB) DeleteUserSessions(ctx context.Context, userID int64) error {
	_, err := db.sql.ExecContext(ctx, `DELETE FROM sessions WHERE user_id = ?`, userID)
	return err
}

// PurgeExpiredSessions räumt abgelaufene Sitzungen weg.
func (db *DB) PurgeExpiredSessions(ctx context.Context) (int64, error) {
	res, err := db.sql.ExecContext(ctx,
		`DELETE FROM sessions WHERE expires_at < ?`, time.Now().UTC().Format(time.RFC3339))
	if err != nil {
		return 0, err
	}
	return res.RowsAffected()
}
