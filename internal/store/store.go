// Package store kapselt die SQLite-Datenbank des Panels.
//
// SQLite genügt für Nutzer, Sessions, Audit-Log und Einstellungen vollständig
// und erspart eine externe Abhängigkeit. Der Treiber ist reines Go
// (modernc.org/sqlite), damit CGO_ENABLED=0 und das statische Binary bleiben.
package store

import (
	"context"
	"database/sql"
	"embed"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"

	_ "modernc.org/sqlite" // Treiber "sqlite"
)

//go:embed migrations/*.sql
var migrationFS embed.FS

// ErrNotFound meldet einen nicht vorhandenen Datensatz.
var ErrNotFound = errors.New("nicht gefunden")

// DB ist der Zugriffspunkt auf die Datenbank.
type DB struct {
	sql  *sql.DB
	path string
}

// Open öffnet die Datenbank und richtet die Verbindungsparameter ein.
func Open(path string) (*DB, error) {
	if dir := filepath.Dir(path); dir != "" && dir != "." {
		if err := os.MkdirAll(dir, 0o750); err != nil {
			return nil, fmt.Errorf("datenverzeichnis %s: %w", dir, err)
		}
	}

	// WAL für parallele Leser neben einem Schreiber, foreign_keys für
	// referenzielle Integrität (in SQLite standardmäßig aus), busy_timeout
	// gegen "database is locked" bei gleichzeitigen Zugriffen.
	dsn := path + "?_pragma=journal_mode(WAL)" +
		"&_pragma=foreign_keys(1)" +
		"&_pragma=busy_timeout(5000)" +
		"&_pragma=synchronous(NORMAL)"

	sqlDB, err := sql.Open("sqlite", dsn)
	if err != nil {
		return nil, fmt.Errorf("datenbank öffnen: %w", err)
	}
	// SQLite verträgt genau einen Schreiber. Ein einzelner Verbindungspool-
	// Eintrag serialisiert die Zugriffe und vermeidet Lock-Konflikte.
	sqlDB.SetMaxOpenConns(1)
	sqlDB.SetMaxIdleConns(1)
	sqlDB.SetConnMaxLifetime(0)

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if err := sqlDB.PingContext(ctx); err != nil {
		_ = sqlDB.Close()
		return nil, fmt.Errorf("datenbank erreichen: %w", err)
	}

	// Die Datei enthält Sessions und Geheimnisse — niemand außer root liest sie.
	if err := os.Chmod(path, 0o600); err != nil && !errors.Is(err, os.ErrNotExist) {
		_ = sqlDB.Close()
		return nil, fmt.Errorf("rechte auf %s: %w", path, err)
	}

	return &DB{sql: sqlDB, path: path}, nil
}

// Close schließt die Datenbank.
func (db *DB) Close() error { return db.sql.Close() }

// SQL gibt den darunterliegenden Handle für Sonderfälle frei.
func (db *DB) SQL() *sql.DB { return db.sql }

// Path liefert den Dateipfad der Datenbank.
func (db *DB) Path() string { return db.path }

type migration struct {
	version int
	name    string
	body    string
}

// Migrate spielt alle offenen Migrationen ein und liefert die Anzahl.
//
// Vorwärts und nur vorwärts: Down-Migrationen sind in der Praxis selten
// getestet und erzeugen falsche Sicherheit. Rückwärtskompatibilität stellt der
// Datenbank-Schnappschuss her, den der Update-Ablauf vorher anlegt.
func (db *DB) Migrate(ctx context.Context) (applied int, err error) {
	if _, err := db.sql.ExecContext(ctx, `
		CREATE TABLE IF NOT EXISTS schema_migrations (
			version    INTEGER PRIMARY KEY,
			name       TEXT NOT NULL,
			applied_at TEXT NOT NULL
		)`); err != nil {
		return 0, fmt.Errorf("migrationstabelle: %w", err)
	}

	current, err := db.SchemaVersion(ctx)
	if err != nil {
		return 0, err
	}

	migrations, err := loadMigrations()
	if err != nil {
		return 0, err
	}

	for _, m := range migrations {
		if m.version <= current {
			continue
		}
		// Jede Migration in einer eigenen Transaktion: Schlägt eine fehl,
		// bleiben die vorherigen gültig und der Stand ist eindeutig.
		tx, err := db.sql.BeginTx(ctx, nil)
		if err != nil {
			return applied, fmt.Errorf("migration %04d: %w", m.version, err)
		}
		if _, err := tx.ExecContext(ctx, m.body); err != nil {
			_ = tx.Rollback()
			return applied, fmt.Errorf("migration %04d (%s): %w", m.version, m.name, err)
		}
		if _, err := tx.ExecContext(ctx,
			`INSERT INTO schema_migrations (version, name, applied_at) VALUES (?, ?, ?)`,
			m.version, m.name, time.Now().UTC().Format(time.RFC3339),
		); err != nil {
			_ = tx.Rollback()
			return applied, fmt.Errorf("migration %04d eintragen: %w", m.version, err)
		}
		if err := tx.Commit(); err != nil {
			return applied, fmt.Errorf("migration %04d abschließen: %w", m.version, err)
		}
		applied++
	}
	return applied, nil
}

// SchemaVersion liefert die höchste eingespielte Migrationsnummer.
func (db *DB) SchemaVersion(ctx context.Context) (int, error) {
	var version sql.NullInt64
	err := db.sql.QueryRowContext(ctx, `SELECT MAX(version) FROM schema_migrations`).Scan(&version)
	switch {
	case err != nil && strings.Contains(err.Error(), "no such table"):
		return 0, nil
	case err != nil:
		return 0, fmt.Errorf("schemaversion: %w", err)
	case !version.Valid:
		return 0, nil
	}
	return int(version.Int64), nil
}

func loadMigrations() ([]migration, error) {
	entries, err := fs.ReadDir(migrationFS, "migrations")
	if err != nil {
		return nil, err
	}

	out := make([]migration, 0, len(entries))
	for _, e := range entries {
		if e.IsDir() || !strings.HasSuffix(e.Name(), ".sql") {
			continue
		}
		// Namensschema: 0001_beschreibung.sql
		numPart, rest, found := strings.Cut(e.Name(), "_")
		if !found {
			return nil, fmt.Errorf("migration %q: erwartet wird NNNN_name.sql", e.Name())
		}
		version, err := strconv.Atoi(numPart)
		if err != nil {
			return nil, fmt.Errorf("migration %q: %w", e.Name(), err)
		}
		body, err := migrationFS.ReadFile("migrations/" + e.Name())
		if err != nil {
			return nil, err
		}
		out = append(out, migration{
			version: version,
			name:    strings.TrimSuffix(rest, ".sql"),
			body:    string(body),
		})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].version < out[j].version })

	for i, m := range out {
		if m.version != i+1 {
			return nil, fmt.Errorf("lückenhafte Migrationsnummern: erwartet %04d, gefunden %04d", i+1, m.version)
		}
	}
	return out, nil
}
