package store

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

// Migrationen sind vorwärtsgerichtet — es gibt keine Down-Migration. Ein
// Rollback des Binaries allein würde also eine ältere Fassung auf ein neueres
// Schema treffen lassen. Solange Migrationen nur ergänzen (neue Tabellen,
// neue Spalten), geht das meist gut; darauf zu bauen wäre trotzdem leichtsinnig.
//
// Deshalb legt das Update vor dem Austausch einen Abzug an, und der
// selbsttätige Rückweg spielt ihn wieder ein.

// Snapshot schreibt einen in sich stimmigen Abzug der Datenbank.
//
// "VACUUM INTO" ist dafür der richtige Weg und nicht etwa das Kopieren der
// Datei: Bei eingeschaltetem WAL liegt ein Teil der Daten im
// Write-Ahead-Log, eine Dateikopie wäre also je nach Zeitpunkt unvollständig.
func (db *DB) Snapshot(ctx context.Context, path string) error {
	if dir := filepath.Dir(path); dir != "" && dir != "." {
		if err := os.MkdirAll(dir, 0o750); err != nil {
			return fmt.Errorf("verzeichnis %s: %w", dir, err)
		}
	}
	// VACUUM INTO weigert sich, eine vorhandene Datei zu überschreiben.
	if err := os.Remove(path); err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("alten Abzug entfernen: %w", err)
	}

	// Der Pfad kann nicht als Parameter gebunden werden; SQLite erwartet hier
	// ein Literal. Einfache Anführungszeichen werden nach SQL-Regel verdoppelt.
	quoted := "'" + strings.ReplaceAll(path, "'", "''") + "'"
	if _, err := db.sql.ExecContext(ctx, "VACUUM INTO "+quoted); err != nil { //nolint:gosec // Pfad maskiert, kein Nutzereingabewert
		return fmt.Errorf("abzug nach %s: %w", path, err)
	}
	return nil
}
