package store

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"time"
)

// Schlüssel der settings-Tabelle.
const (
	// SettingSetupTokenHash hält den SHA-256 des einmaligen Setup-Tokens.
	SettingSetupTokenHash = "setup_token_hash" //nolint:gosec // Name des Schlüssels, kein Geheimnis
	// SettingSetupTokenExpires hält dessen Ablaufzeitpunkt.
	SettingSetupTokenExpires = "setup_token_expires" //nolint:gosec // Name des Schlüssels, kein Geheimnis
)

// SetSetting legt einen Wert ab.
func (db *DB) SetSetting(ctx context.Context, key, value string) error {
	_, err := db.sql.ExecContext(ctx, `
		INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
		ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at`,
		key, value, time.Now().UTC().Format(time.RFC3339))
	if err != nil {
		return fmt.Errorf("einstellung %q speichern: %w", key, err)
	}
	return nil
}

// Setting liest einen Wert. Fehlt er, kommt ErrNotFound.
func (db *DB) Setting(ctx context.Context, key string) (string, error) {
	var value string
	err := db.sql.QueryRowContext(ctx, `SELECT value FROM settings WHERE key = ?`, key).Scan(&value)
	if errors.Is(err, sql.ErrNoRows) {
		return "", ErrNotFound
	}
	if err != nil {
		return "", fmt.Errorf("einstellung %q lesen: %w", key, err)
	}
	return value, nil
}

// DeleteSetting entfernt einen Wert.
func (db *DB) DeleteSetting(ctx context.Context, key string) error {
	_, err := db.sql.ExecContext(ctx, `DELETE FROM settings WHERE key = ?`, key)
	return err
}
