package store

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"time"
)

// WebAuthnCredential ist ein registrierter Passkey eines Kontos.
//
// Data trägt das serialisierte Credential der go-webauthn-Bibliothek (JSON):
// öffentlicher Schlüssel, Sign-Count, Flags, Transports. Der Store kennt dessen
// Aufbau bewusst nicht — er lagert und liefert das Byte-Feld, ausgewertet wird
// es im passkeys-Paket. So bleibt das Schema stabil, auch wenn die Bibliothek
// ihr Credential-Format erweitert.
type WebAuthnCredential struct {
	ID           int64
	UserID       int64
	CredentialID string
	Label        string
	Data         []byte
	CreatedAt    time.Time
	LastUsedAt   *time.Time
}

// AddWebAuthnCredential legt einen neuen Passkey an. Ist die credential_id schon
// vergeben (dasselbe Gerät ein zweites Mal), scheitert das UNIQUE — der Aufrufer
// erkennt daran die Doppelregistrierung.
func (db *DB) AddWebAuthnCredential(ctx context.Context, c WebAuthnCredential) (int64, error) {
	res, err := db.sql.ExecContext(ctx, `
		INSERT INTO webauthn_credentials (user_id, credential_id, label, data, created_at)
		VALUES (?, ?, ?, ?, ?)`,
		c.UserID, c.CredentialID, c.Label, c.Data, time.Now().UTC().Format(time.RFC3339),
	)
	if err != nil {
		return 0, fmt.Errorf("passkey anlegen: %w", err)
	}
	return res.LastInsertId()
}

const webauthnColumns = `id, user_id, credential_id, label, data, created_at, last_used_at`

// WebAuthnCredentialsByUser liefert die Passkeys eines Kontos, älteste zuerst.
func (db *DB) WebAuthnCredentialsByUser(ctx context.Context, userID int64) ([]WebAuthnCredential, error) {
	rows, err := db.sql.QueryContext(ctx,
		`SELECT `+webauthnColumns+` FROM webauthn_credentials WHERE user_id = ? ORDER BY created_at, id`, userID)
	if err != nil {
		return nil, fmt.Errorf("passkeys lesen: %w", err)
	}
	defer func() { _ = rows.Close() }()

	var out []WebAuthnCredential
	for rows.Next() {
		c, err := scanWebAuthnCredential(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, c)
	}
	return out, rows.Err()
}

// UpdateWebAuthnCredentialUse schreibt das Credential nach einer Anmeldung fort:
// Der Sign-Count steckt im aktualisierten Data-Feld, dazu der Zeitpunkt der
// letzten Nutzung. Anhand der credential_id, weil der Aufrufer beim Anmelden nur
// diese kennt.
func (db *DB) UpdateWebAuthnCredentialUse(ctx context.Context, credentialID string, data []byte, usedAt time.Time) error {
	res, err := db.sql.ExecContext(ctx,
		`UPDATE webauthn_credentials SET data = ?, last_used_at = ? WHERE credential_id = ?`,
		data, usedAt.UTC().Format(time.RFC3339), credentialID)
	if err != nil {
		return fmt.Errorf("passkey fortschreiben: %w", err)
	}
	return notFoundIfNoRows(res)
}

// RenameWebAuthnCredential ändert die Bezeichnung. Die user_id in der Bedingung
// verhindert, dass ein Konto den Passkey eines anderen umbenennt.
func (db *DB) RenameWebAuthnCredential(ctx context.Context, id, userID int64, label string) error {
	res, err := db.sql.ExecContext(ctx,
		`UPDATE webauthn_credentials SET label = ? WHERE id = ? AND user_id = ?`, label, id, userID)
	if err != nil {
		return fmt.Errorf("passkey umbenennen: %w", err)
	}
	return notFoundIfNoRows(res)
}

// DeleteWebAuthnCredential entfernt einen Passkey. Auch hier bindet die user_id
// die Aktion ans eigene Konto.
func (db *DB) DeleteWebAuthnCredential(ctx context.Context, id, userID int64) error {
	res, err := db.sql.ExecContext(ctx,
		`DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?`, id, userID)
	if err != nil {
		return fmt.Errorf("passkey löschen: %w", err)
	}
	return notFoundIfNoRows(res)
}

// CountWebAuthnCredentials zählt die Passkeys eines Kontos.
func (db *DB) CountWebAuthnCredentials(ctx context.Context, userID int64) (int, error) {
	var n int
	err := db.sql.QueryRowContext(ctx,
		`SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = ?`, userID).Scan(&n)
	if err != nil {
		return 0, fmt.Errorf("passkeys zählen: %w", err)
	}
	return n, nil
}

func scanWebAuthnCredential(row scanner) (WebAuthnCredential, error) {
	var (
		c         WebAuthnCredential
		createdAt string
		lastUsed  sql.NullString
	)
	err := row.Scan(&c.ID, &c.UserID, &c.CredentialID, &c.Label, &c.Data, &createdAt, &lastUsed)
	if errors.Is(err, sql.ErrNoRows) {
		return WebAuthnCredential{}, ErrNotFound
	}
	if err != nil {
		return WebAuthnCredential{}, fmt.Errorf("passkey lesen: %w", err)
	}
	c.CreatedAt, _ = time.Parse(time.RFC3339, createdAt)
	if lastUsed.Valid {
		if t, err := time.Parse(time.RFC3339, lastUsed.String); err == nil {
			c.LastUsedAt = &t
		}
	}
	return c, nil
}

// notFoundIfNoRows meldet ErrNotFound, wenn ein UPDATE/DELETE nichts getroffen
// hat — etwa weil die Zeile nicht existiert oder einem anderen Konto gehört.
func notFoundIfNoRows(res sql.Result) error {
	n, err := res.RowsAffected()
	if err != nil {
		return err
	}
	if n == 0 {
		return ErrNotFound
	}
	return nil
}
