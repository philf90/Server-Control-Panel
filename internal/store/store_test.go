package store

import (
	"context"
	"errors"
	"path/filepath"
	"testing"
	"time"
)

func testDB(t *testing.T) *DB {
	t.Helper()
	db, err := Open(filepath.Join(t.TempDir(), "asylum.db"))
	if err != nil {
		t.Fatalf("Open: %v", err)
	}
	t.Cleanup(func() { _ = db.Close() })

	if _, err := db.Migrate(context.Background()); err != nil {
		t.Fatalf("Migrate: %v", err)
	}
	return db
}

func TestMigrateIsIdempotent(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	version, err := db.SchemaVersion(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if version < 1 {
		t.Fatalf("Schemaversion = %d, erwartet mindestens 1", version)
	}

	applied, err := db.Migrate(ctx)
	if err != nil {
		t.Fatalf("zweiter Migrationslauf: %v", err)
	}
	if applied != 0 {
		t.Errorf("zweiter Lauf hat %d Migrationen erneut eingespielt", applied)
	}
}

func TestMigrateCreatesDatabaseFileWithTightPermissions(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "sub", "asylum.db")

	db, err := Open(path)
	if err != nil {
		t.Fatalf("Open: %v", err)
	}
	defer func() { _ = db.Close() }()

	info, err := statFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if perm := info.Mode().Perm(); perm != 0o600 {
		t.Errorf("Rechte = %o, erwartet 600 — die Datei enthält Sitzungen und Geheimnisse", perm)
	}
}

func TestUserRoundtrip(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	if n, err := db.CountUsers(ctx); err != nil || n != 0 {
		t.Fatalf("CountUsers = %d, %v — erwartet 0", n, err)
	}

	id, err := db.CreateUser(ctx, User{
		Username: "philipp", PasswordHash: "hash", Role: RoleOwner, TOTPSecret: "SECRET",
	})
	if err != nil {
		t.Fatalf("CreateUser: %v", err)
	}

	user, err := db.UserByName(ctx, "philipp")
	if err != nil {
		t.Fatalf("UserByName: %v", err)
	}
	if user.ID != id || user.Role != RoleOwner || user.TOTPConfirmed {
		t.Errorf("unerwarteter Benutzer: %+v", user)
	}
	if !user.CanWrite() || !user.CanManageUsers() {
		t.Error("Owner muss schreiben und Benutzer verwalten dürfen")
	}

	// Anmeldenamen sind unabhängig von der Groß-/Kleinschreibung eindeutig,
	// sonst ließen sich "philipp" und "Philipp" nebeneinander anlegen.
	if _, err := db.CreateUser(ctx, User{Username: "PHILIPP", PasswordHash: "x", Role: RoleAdmin}); err == nil {
		t.Error("doppelter Anmeldename wurde angenommen")
	}
	if _, err := db.UserByName(ctx, "PhIlIpP"); err != nil {
		t.Errorf("Suche unabhängig von Groß-/Kleinschreibung schlug fehl: %v", err)
	}
}

func TestUserNotFound(t *testing.T) {
	db := testDB(t)
	if _, err := db.UserByName(context.Background(), "gibtsnicht"); !errors.Is(err, ErrNotFound) {
		t.Errorf("Fehler = %v, erwartet ErrNotFound", err)
	}
}

func TestCreateUserRejectsUnknownRole(t *testing.T) {
	db := testDB(t)
	if _, err := db.CreateUser(context.Background(), User{
		Username: "x", PasswordHash: "h", Role: "gott",
	}); err == nil {
		t.Error("unbekannte Rolle wurde angenommen")
	}
}

func TestRolePermissions(t *testing.T) {
	tests := map[string]struct{ write, manage bool }{
		RoleOwner:    {true, true},
		RoleAdmin:    {true, false},
		RoleReadOnly: {false, false},
	}
	for role, want := range tests {
		u := User{Role: role}
		if u.CanWrite() != want.write || u.CanManageUsers() != want.manage {
			t.Errorf("%s: CanWrite=%t CanManageUsers=%t, erwartet %t/%t",
				role, u.CanWrite(), u.CanManageUsers(), want.write, want.manage)
		}
	}
}

func TestSessionLifecycle(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	userID, err := db.CreateUser(ctx, User{Username: "u", PasswordHash: "h", Role: RoleAdmin})
	if err != nil {
		t.Fatal(err)
	}

	now := time.Now()
	sess := Session{
		ID: "abc", UserID: userID, CSRFToken: "csrf",
		CreatedAt: now, LastSeenAt: now, ExpiresAt: now.Add(time.Hour),
		IP: "203.0.113.5",
	}
	if err := db.CreateSession(ctx, sess); err != nil {
		t.Fatalf("CreateSession: %v", err)
	}

	got, err := db.SessionByID(ctx, "abc")
	if err != nil {
		t.Fatalf("SessionByID: %v", err)
	}
	if got.UserID != userID || got.CSRFToken != "csrf" {
		t.Errorf("unerwartete Sitzung: %+v", got)
	}

	if err := db.DeleteSession(ctx, "abc"); err != nil {
		t.Fatal(err)
	}
	if _, err := db.SessionByID(ctx, "abc"); !errors.Is(err, ErrNotFound) {
		t.Errorf("gelöschte Sitzung lieferte %v", err)
	}
}

func TestExpiredSessionIsGone(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	userID, err := db.CreateUser(ctx, User{Username: "u", PasswordHash: "h", Role: RoleAdmin})
	if err != nil {
		t.Fatal(err)
	}
	past := time.Now().Add(-time.Hour)
	if err := db.CreateSession(ctx, Session{
		ID: "alt", UserID: userID, CSRFToken: "c",
		CreatedAt: past, LastSeenAt: past, ExpiresAt: past.Add(time.Minute),
	}); err != nil {
		t.Fatal(err)
	}

	if _, err := db.SessionByID(ctx, "alt"); !errors.Is(err, ErrNotFound) {
		t.Errorf("abgelaufene Sitzung lieferte %v, erwartet ErrNotFound", err)
	}
}

func TestDeleteUserSessionsAndCascade(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	userID, err := db.CreateUser(ctx, User{Username: "u", PasswordHash: "h", Role: RoleAdmin})
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	for _, id := range []string{"s1", "s2"} {
		if err := db.CreateSession(ctx, Session{
			ID: id, UserID: userID, CSRFToken: "c",
			CreatedAt: now, LastSeenAt: now, ExpiresAt: now.Add(time.Hour),
		}); err != nil {
			t.Fatal(err)
		}
	}

	if err := db.DeleteUserSessions(ctx, userID); err != nil {
		t.Fatal(err)
	}
	for _, id := range []string{"s1", "s2"} {
		if _, err := db.SessionByID(ctx, id); !errors.Is(err, ErrNotFound) {
			t.Errorf("Sitzung %s besteht noch", id)
		}
	}
}

func TestPurgeExpiredSessions(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	userID, err := db.CreateUser(ctx, User{Username: "u", PasswordHash: "h", Role: RoleAdmin})
	if err != nil {
		t.Fatal(err)
	}
	past := time.Now().Add(-2 * time.Hour)
	if err := db.CreateSession(ctx, Session{
		ID: "alt", UserID: userID, CSRFToken: "c",
		CreatedAt: past, LastSeenAt: past, ExpiresAt: past.Add(time.Minute),
	}); err != nil {
		t.Fatal(err)
	}

	n, err := db.PurgeExpiredSessions(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if n != 1 {
		t.Errorf("%d Sitzungen entfernt, erwartet 1", n)
	}
}

func TestRecoveryCodesAreSingleUse(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	userID, err := db.CreateUser(ctx, User{Username: "u", PasswordHash: "h", Role: RoleOwner})
	if err != nil {
		t.Fatal(err)
	}
	if err := db.ReplaceRecoveryCodes(ctx, userID, []string{"hash1", "hash2"}); err != nil {
		t.Fatal(err)
	}

	if n, _ := db.CountUnusedRecoveryCodes(ctx, userID); n != 2 {
		t.Fatalf("%d ungenutzte Codes, erwartet 2", n)
	}
	if err := db.UseRecoveryCode(ctx, userID, "hash1"); err != nil {
		t.Fatalf("erster Einsatz schlug fehl: %v", err)
	}
	if err := db.UseRecoveryCode(ctx, userID, "hash1"); !errors.Is(err, ErrNotFound) {
		t.Error("derselbe Code war ein zweites Mal einlösbar")
	}
	if n, _ := db.CountUnusedRecoveryCodes(ctx, userID); n != 1 {
		t.Errorf("%d ungenutzte Codes, erwartet 1", n)
	}

	// Ein neuer Satz ersetzt den alten vollständig.
	if err := db.ReplaceRecoveryCodes(ctx, userID, []string{"neu"}); err != nil {
		t.Fatal(err)
	}
	if err := db.UseRecoveryCode(ctx, userID, "hash2"); !errors.Is(err, ErrNotFound) {
		t.Error("alter Code gilt nach dem Ersetzen weiterhin")
	}
}

func TestAuditIsAppendOnlyAndOrdered(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	for _, action := range []string{"login.success", "user.create", "logout"} {
		if err := db.AppendAudit(ctx, AuditEntry{
			Actor: "philipp", Action: action, Result: ResultOK, IP: "127.0.0.1",
		}); err != nil {
			t.Fatal(err)
		}
	}

	entries, err := db.ListAudit(ctx, 10)
	if err != nil {
		t.Fatal(err)
	}
	if len(entries) != 3 {
		t.Fatalf("%d Einträge, erwartet 3", len(entries))
	}
	// Jüngster zuerst.
	if entries[0].Action != "logout" {
		t.Errorf("erster Eintrag = %q, erwartet logout", entries[0].Action)
	}
	if entries[0].At.IsZero() {
		t.Error("Zeitstempel wurde nicht gesetzt")
	}
}

func TestAuditRejectsUnknownResult(t *testing.T) {
	db := testDB(t)
	err := db.AppendAudit(context.Background(), AuditEntry{
		Actor: "x", Action: "y", Result: "vielleicht",
	})
	if err == nil {
		t.Error("unbekanntes Ergebnis wurde angenommen — die CHECK-Bedingung greift nicht")
	}
}

func TestSettings(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	if _, err := db.Setting(ctx, "fehlt"); !errors.Is(err, ErrNotFound) {
		t.Errorf("fehlende Einstellung lieferte %v", err)
	}
	if err := db.SetSetting(ctx, "k", "v1"); err != nil {
		t.Fatal(err)
	}
	// Zweites Setzen überschreibt, statt zu scheitern.
	if err := db.SetSetting(ctx, "k", "v2"); err != nil {
		t.Fatal(err)
	}
	if v, err := db.Setting(ctx, "k"); err != nil || v != "v2" {
		t.Errorf("Setting = %q, %v — erwartet v2", v, err)
	}
	if err := db.DeleteSetting(ctx, "k"); err != nil {
		t.Fatal(err)
	}
	if _, err := db.Setting(ctx, "k"); !errors.Is(err, ErrNotFound) {
		t.Errorf("gelöschte Einstellung lieferte %v", err)
	}
}

func TestUserUpdates(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	id, err := db.CreateUser(ctx, User{Username: "u", PasswordHash: "alt", Role: RoleAdmin})
	if err != nil {
		t.Fatal(err)
	}

	if err := db.SetPassword(ctx, id, "neu"); err != nil {
		t.Fatal(err)
	}
	if err := db.SetTOTP(ctx, id, "SECRET", true); err != nil {
		t.Fatal(err)
	}
	if err := db.TouchLogin(ctx, id); err != nil {
		t.Fatal(err)
	}

	user, err := db.UserByID(ctx, id)
	if err != nil {
		t.Fatal(err)
	}
	if user.PasswordHash != "neu" {
		t.Errorf("PasswordHash = %q", user.PasswordHash)
	}
	if user.TOTPSecret != "SECRET" || !user.TOTPConfirmed {
		t.Errorf("TOTP nicht übernommen: %+v", user)
	}
	if user.LastLoginAt == nil {
		t.Error("letzte Anmeldung wurde nicht vermerkt")
	}

	if err := db.SetDisabled(ctx, id, true); err != nil {
		t.Fatal(err)
	}
	if user, _ = db.UserByID(ctx, id); !user.Disabled {
		t.Error("Konto wurde nicht gesperrt")
	}
}

func TestListUsersIsSorted(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	for _, name := range []string{"zoe", "anna", "max"} {
		if _, err := db.CreateUser(ctx, User{Username: name, PasswordHash: "h", Role: RoleReadOnly}); err != nil {
			t.Fatal(err)
		}
	}

	users, err := db.ListUsers(ctx)
	if err != nil {
		t.Fatal(err)
	}
	got := make([]string, len(users))
	for i, u := range users {
		got[i] = u.Username
	}
	want := []string{"anna", "max", "zoe"}
	for i := range want {
		if got[i] != want[i] {
			t.Fatalf("Reihenfolge = %v, erwartet %v", got, want)
		}
	}
}

func TestTouchSessionExtends(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	userID, err := db.CreateUser(ctx, User{Username: "u", PasswordHash: "h", Role: RoleAdmin})
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	if err := db.CreateSession(ctx, Session{
		ID: "s", UserID: userID, CSRFToken: "c",
		CreatedAt: now, LastSeenAt: now, ExpiresAt: now.Add(time.Minute),
	}); err != nil {
		t.Fatal(err)
	}

	newExpiry := now.Add(2 * time.Hour)
	if err := db.TouchSession(ctx, "s", newExpiry); err != nil {
		t.Fatal(err)
	}

	sess, err := db.SessionByID(ctx, "s")
	if err != nil {
		t.Fatal(err)
	}
	if sess.ExpiresAt.Before(now.Add(time.Hour)) {
		t.Errorf("Ablauf = %v, erwartet ungefähr %v", sess.ExpiresAt, newExpiry)
	}
}

func TestDeletingUserRemovesSessionsAndCodes(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	userID, err := db.CreateUser(ctx, User{Username: "u", PasswordHash: "h", Role: RoleAdmin})
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	if err := db.CreateSession(ctx, Session{
		ID: "s", UserID: userID, CSRFToken: "c",
		CreatedAt: now, LastSeenAt: now, ExpiresAt: now.Add(time.Hour),
	}); err != nil {
		t.Fatal(err)
	}
	if err := db.ReplaceRecoveryCodes(ctx, userID, []string{"h1"}); err != nil {
		t.Fatal(err)
	}

	// ON DELETE CASCADE greift nur mit eingeschaltetem foreign_keys-Pragma —
	// genau das prüft dieser Test mit.
	if _, err := db.SQL().ExecContext(ctx, `DELETE FROM users WHERE id = ?`, userID); err != nil {
		t.Fatal(err)
	}
	if _, err := db.SessionByID(ctx, "s"); !errors.Is(err, ErrNotFound) {
		t.Error("Sitzung überlebte das Löschen des Kontos")
	}
	if n, _ := db.CountUnusedRecoveryCodes(ctx, userID); n != 0 {
		t.Errorf("%d Wiederherstellungscodes überlebten das Löschen", n)
	}
}

func TestListAuditLimits(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	for i := 0; i < 5; i++ {
		if err := db.AppendAudit(ctx, AuditEntry{Actor: "a", Action: "x", Result: ResultOK}); err != nil {
			t.Fatal(err)
		}
	}

	if entries, _ := db.ListAudit(ctx, 2); len(entries) != 2 {
		t.Errorf("%d Einträge, erwartet 2", len(entries))
	}
	// Unsinnige Werte fallen auf die Vorgabe zurück, statt alles zu liefern.
	if entries, _ := db.ListAudit(ctx, -1); len(entries) != 5 {
		t.Errorf("%d Einträge bei limit=-1, erwartet 5", len(entries))
	}
	if entries, _ := db.ListAudit(ctx, 10000); len(entries) != 5 {
		t.Errorf("%d Einträge bei überhöhtem Limit, erwartet 5", len(entries))
	}
}

func TestOpenFailsOnUnwritablePath(t *testing.T) {
	if _, err := Open("/proc/gibtsnicht/asylum.db"); err == nil {
		t.Error("unbeschreibbarer Pfad muss einen Fehler ergeben")
	}
}
