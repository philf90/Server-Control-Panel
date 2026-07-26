package store

import (
	"context"
	"os"
	"path/filepath"
	"testing"
)

func TestSnapshotIstVollstaendig(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	if _, err := db.CreateUser(ctx, User{
		Username: "philipp", PasswordHash: "x", Role: RoleOwner, TOTPSecret: "y",
	}); err != nil {
		t.Fatal(err)
	}
	if err := db.SetSetting(ctx, "probe", "vor dem Update"); err != nil {
		t.Fatal(err)
	}

	// Das Verzeichnis darf noch nicht bestehen — Snapshot legt es an.
	path := filepath.Join(t.TempDir(), "backups", "vor-0.2.0.db")
	if err := db.Snapshot(ctx, path); err != nil {
		t.Fatalf("Snapshot: %v", err)
	}

	// Nach dem Abzug weitergeschriebene Daten dürfen nicht darin stehen.
	if err := db.SetSetting(ctx, "probe", "nach dem Update"); err != nil {
		t.Fatal(err)
	}

	restored, err := Open(path)
	if err != nil {
		t.Fatalf("Abzug öffnen: %v", err)
	}
	defer func() { _ = restored.Close() }()

	value, err := restored.Setting(ctx, "probe")
	if err != nil {
		t.Fatalf("Einstellung aus dem Abzug: %v", err)
	}
	if value != "vor dem Update" {
		t.Errorf("= %q, erwartet %q", value, "vor dem Update")
	}
	user, err := restored.UserByName(ctx, "philipp")
	if err != nil {
		t.Fatalf("Konto aus dem Abzug: %v", err)
	}
	if user.Role != RoleOwner {
		t.Errorf("Rolle = %q", user.Role)
	}

	// Das Schema muss mitkommen, sonst wäre der Abzug als Rückweg wertlos.
	version, err := restored.SchemaVersion(ctx)
	if err != nil || version < 1 {
		t.Errorf("Schemaversion im Abzug = %d, %v", version, err)
	}
}

func TestSnapshotUeberschreibtVorhandenen(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	path := filepath.Join(t.TempDir(), "abzug.db")

	if err := db.Snapshot(ctx, path); err != nil {
		t.Fatalf("erster Abzug: %v", err)
	}
	// VACUUM INTO weigert sich, eine vorhandene Datei zu beschreiben — der
	// zweite Lauf muss trotzdem durchgehen.
	if err := db.Snapshot(ctx, path); err != nil {
		t.Fatalf("zweiter Abzug: %v", err)
	}
	if fi, err := os.Stat(path); err != nil || fi.Size() == 0 {
		t.Errorf("Abzug fehlt oder ist leer: %v", err)
	}
}

func TestSnapshotInUnbeschreibbaresVerzeichnis(t *testing.T) {
	if os.Geteuid() == 0 {
		t.Skip("als root greifen die Schreibrechte nicht")
	}
	db := testDB(t)
	dir := t.TempDir()
	if err := os.Chmod(dir, 0o500); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = os.Chmod(dir, 0o700) })

	if err := db.Snapshot(context.Background(), filepath.Join(dir, "x", "abzug.db")); err == nil {
		t.Fatal("Fehler erwartet")
	}
}
