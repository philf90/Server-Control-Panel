package store

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"time"
)

// Rollen des Panels. Owner darf alles einschließlich Nutzerverwaltung, Admin
// alles außer der Verwaltung anderer Owner, ReadOnly nur lesen.
const (
	RoleOwner    = "owner"
	RoleAdmin    = "admin"
	RoleReadOnly = "readonly"
)

// ValidRole prüft eine Rollenbezeichnung.
func ValidRole(role string) bool {
	switch role {
	case RoleOwner, RoleAdmin, RoleReadOnly:
		return true
	}
	return false
}

// User ist ein Panel-Benutzer. Er hat nichts mit Systembenutzern zu tun.
type User struct {
	ID            int64
	Username      string
	PasswordHash  string
	Role          string
	TOTPSecret    string
	TOTPConfirmed bool
	// TOTPLastCounter ist das zuletzt angenommene TOTP-Zeitfenster. Es
	// verhindert, dass derselbe Code ein zweites Mal gilt.
	TOTPLastCounter uint64
	Disabled        bool
	// MustChangePassword heißt: Das aktuelle Passwort ist ein Einmalpasswort
	// aus einer Zurücksetzung. Bis es ersetzt ist, kommt das Konto nur auf die
	// Wechselseite.
	MustChangePassword bool
	CreatedAt          time.Time
	LastLoginAt        *time.Time
}

// CanWrite sagt, ob die Rolle verändernde Aktionen ausführen darf.
func (u User) CanWrite() bool { return u.Role == RoleOwner || u.Role == RoleAdmin }

// CanManageUsers sagt, ob der Benutzer andere Konten verwalten darf.
func (u User) CanManageUsers() bool { return u.Role == RoleOwner }

// CountUsers liefert die Anzahl angelegter Benutzer. Ist sie 0, befindet sich
// das Panel im Erstinstallationszustand.
func (db *DB) CountUsers(ctx context.Context) (int, error) {
	var n int
	if err := db.sql.QueryRowContext(ctx, `SELECT COUNT(*) FROM users`).Scan(&n); err != nil {
		return 0, fmt.Errorf("benutzer zählen: %w", err)
	}
	return n, nil
}

// CreateUser legt einen Benutzer an.
func (db *DB) CreateUser(ctx context.Context, u User) (int64, error) {
	if !ValidRole(u.Role) {
		return 0, fmt.Errorf("unbekannte Rolle %q", u.Role)
	}
	res, err := db.sql.ExecContext(ctx, `
		INSERT INTO users (username, password_hash, role, totp_secret, totp_confirmed, disabled, must_change_password, created_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
		u.Username, u.PasswordHash, u.Role, u.TOTPSecret, boolToInt(u.TOTPConfirmed),
		boolToInt(u.Disabled), boolToInt(u.MustChangePassword), time.Now().UTC().Format(time.RFC3339),
	)
	if err != nil {
		return 0, fmt.Errorf("benutzer anlegen: %w", err)
	}
	return res.LastInsertId()
}

const userColumns = `id, username, password_hash, role, totp_secret, totp_confirmed, totp_last_counter, disabled, must_change_password, created_at, last_login_at`

// UserByName sucht einen Benutzer am Anmeldenamen.
func (db *DB) UserByName(ctx context.Context, username string) (User, error) {
	row := db.sql.QueryRowContext(ctx,
		`SELECT `+userColumns+` FROM users WHERE username = ? COLLATE NOCASE`, username)
	return scanUser(row)
}

// UserByID sucht einen Benutzer an der ID.
func (db *DB) UserByID(ctx context.Context, id int64) (User, error) {
	row := db.sql.QueryRowContext(ctx, `SELECT `+userColumns+` FROM users WHERE id = ?`, id)
	return scanUser(row)
}

// ListUsers liefert alle Benutzer, sortiert nach Anmeldename.
func (db *DB) ListUsers(ctx context.Context) ([]User, error) {
	rows, err := db.sql.QueryContext(ctx, `SELECT `+userColumns+` FROM users ORDER BY username`)
	if err != nil {
		return nil, fmt.Errorf("benutzer lesen: %w", err)
	}
	defer func() { _ = rows.Close() }()

	var out []User
	for rows.Next() {
		u, err := scanUser(rows)
		if err != nil {
			return nil, err
		}
		out = append(out, u)
	}
	return out, rows.Err()
}

// SetPassword ersetzt den Passwort-Hash.
//
// Der Wechselzwang fällt dabei weg: Wer hier ankommt, hat das Passwort selbst
// gewählt — sei es auf der Kontoseite, nach einem Passkey-Nachweis oder über die
// Kommandozeile des Servers. Genau das war die Bedingung, die der Zwang stellt.
func (db *DB) SetPassword(ctx context.Context, userID int64, hash string) error {
	_, err := db.sql.ExecContext(ctx,
		`UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?`, hash, userID)
	if err != nil {
		return fmt.Errorf("passwort setzen: %w", err)
	}
	return nil
}

// SetTemporaryPassword hinterlegt ein Einmalpasswort und verlangt den Wechsel
// bei der nächsten Anmeldung.
//
// Getrennt von SetPassword, weil sich die beiden Fälle im Ergebnis
// unterscheiden müssen: Hier hat jemand anderes das Passwort gewählt und kennt
// es. Es soll eine Anmeldung tragen, nicht länger.
func (db *DB) SetTemporaryPassword(ctx context.Context, userID int64, hash string) error {
	_, err := db.sql.ExecContext(ctx,
		`UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?`, hash, userID)
	if err != nil {
		return fmt.Errorf("einmalpasswort setzen: %w", err)
	}
	return nil
}

// SetTOTP hinterlegt oder bestätigt das TOTP-Geheimnis.
func (db *DB) SetTOTP(ctx context.Context, userID int64, secret string, confirmed bool) error {
	_, err := db.sql.ExecContext(ctx,
		// Der Zählerstand gehört zum alten Geheimnis; ein neues fängt bei 0 an.
		`UPDATE users SET totp_secret = ?, totp_confirmed = ?, totp_last_counter = 0 WHERE id = ?`,
		secret, boolToInt(confirmed), userID)
	if err != nil {
		return fmt.Errorf("totp setzen: %w", err)
	}
	return nil
}

// TouchLogin vermerkt eine erfolgreiche Anmeldung.
func (db *DB) TouchLogin(ctx context.Context, userID int64) error {
	_, err := db.sql.ExecContext(ctx,
		`UPDATE users SET last_login_at = ? WHERE id = ?`,
		time.Now().UTC().Format(time.RFC3339), userID)
	return err
}

// SetDisabled sperrt oder entsperrt ein Konto.
func (db *DB) SetDisabled(ctx context.Context, userID int64, disabled bool) error {
	_, err := db.sql.ExecContext(ctx,
		`UPDATE users SET disabled = ? WHERE id = ?`, boolToInt(disabled), userID)
	return err
}

// DeleteUser löscht ein Konto.
//
// Sitzungen, Wiederherstellungscodes und Passkeys hängen mit
// `ON DELETE CASCADE` am Konto und verschwinden mit ihm — deshalb genügt hier
// eine Anweisung. Das Audit-Protokoll bleibt: es hält den Anmeldenamen als
// Text, nicht als Fremdschlüssel, weil ein gelöschtes Konto seine Spur nicht
// mitnehmen darf.
func (db *DB) DeleteUser(ctx context.Context, userID int64) error {
	_, err := db.sql.ExecContext(ctx, `DELETE FROM users WHERE id = ?`, userID)
	if err != nil {
		return fmt.Errorf("benutzer löschen: %w", err)
	}
	return nil
}

// ReplaceRecoveryCodes ersetzt alle Wiederherstellungscodes eines Benutzers.
func (db *DB) ReplaceRecoveryCodes(ctx context.Context, userID int64, hashes []string) error {
	tx, err := db.sql.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer func() { _ = tx.Rollback() }()

	if _, err := tx.ExecContext(ctx, `DELETE FROM recovery_codes WHERE user_id = ?`, userID); err != nil {
		return fmt.Errorf("alte codes entfernen: %w", err)
	}
	now := time.Now().UTC().Format(time.RFC3339)
	for _, h := range hashes {
		if _, err := tx.ExecContext(ctx,
			`INSERT INTO recovery_codes (user_id, code_hash, created_at) VALUES (?, ?, ?)`,
			userID, h, now); err != nil {
			return fmt.Errorf("code speichern: %w", err)
		}
	}
	return tx.Commit()
}

// UseRecoveryCode löst einen Wiederherstellungscode ein. Er gilt genau einmal.
func (db *DB) UseRecoveryCode(ctx context.Context, userID int64, hash string) error {
	res, err := db.sql.ExecContext(ctx, `
		UPDATE recovery_codes SET used_at = ?
		WHERE user_id = ? AND code_hash = ? AND used_at IS NULL`,
		time.Now().UTC().Format(time.RFC3339), userID, hash)
	if err != nil {
		return fmt.Errorf("code einlösen: %w", err)
	}
	n, err := res.RowsAffected()
	if err != nil {
		return err
	}
	if n == 0 {
		return ErrNotFound
	}
	return nil
}

// CountUnusedRecoveryCodes zählt die noch gültigen Codes.
func (db *DB) CountUnusedRecoveryCodes(ctx context.Context, userID int64) (int, error) {
	var n int
	err := db.sql.QueryRowContext(ctx,
		`SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL`, userID).Scan(&n)
	return n, err
}

type scanner interface {
	Scan(dest ...any) error
}

func scanUser(row scanner) (User, error) {
	var (
		u             User
		totpConfirmed int
		disabled      int
		mustChange    int
		createdAt     string
		lastLogin     sql.NullString
	)
	err := row.Scan(&u.ID, &u.Username, &u.PasswordHash, &u.Role, &u.TOTPSecret,
		&totpConfirmed, &u.TOTPLastCounter, &disabled, &mustChange, &createdAt, &lastLogin)
	if errors.Is(err, sql.ErrNoRows) {
		return User{}, ErrNotFound
	}
	if err != nil {
		return User{}, fmt.Errorf("benutzer lesen: %w", err)
	}

	u.TOTPConfirmed = totpConfirmed != 0
	u.Disabled = disabled != 0
	u.MustChangePassword = mustChange != 0
	u.CreatedAt, _ = time.Parse(time.RFC3339, createdAt)
	if lastLogin.Valid {
		if t, err := time.Parse(time.RFC3339, lastLogin.String); err == nil {
			u.LastLoginAt = &t
		}
	}
	return u, nil
}

func boolToInt(b bool) int {
	if b {
		return 1
	}
	return 0
}

// SetTOTPCounter hält fest, welches Zeitfenster zuletzt angenommen wurde.
//
// Die Bedingung im UPDATE ist kein Beiwerk: Zwei gleichzeitige Anmeldungen mit
// demselben Code dürfen nicht beide durchgehen. Nur die erste findet den
// kleineren Stand vor und schreibt; die zweite ändert nichts und bekommt
// ErrNotFound.
func (db *DB) SetTOTPCounter(ctx context.Context, userID int64, counter uint64) error {
	res, err := db.sql.ExecContext(ctx,
		`UPDATE users SET totp_last_counter = ? WHERE id = ? AND totp_last_counter < ?`,
		counter, userID, counter)
	if err != nil {
		return fmt.Errorf("totp-zähler speichern: %w", err)
	}
	n, err := res.RowsAffected()
	if err != nil {
		return err
	}
	if n == 0 {
		return ErrNotFound
	}
	return nil
}
