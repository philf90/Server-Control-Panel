package store

import (
	"context"
	"errors"
	"testing"
	"time"
)

// makeUser legt ein Konto an, an das ein Passkey gehängt werden kann — die
// Fremdschlüsselbindung verlangt einen echten Datensatz.
func makeUser(t *testing.T, db *DB, name string) int64 {
	t.Helper()
	id, err := db.CreateUser(context.Background(), User{
		Username: name, PasswordHash: "x", Role: RoleOwner, TOTPSecret: "s",
	})
	if err != nil {
		t.Fatalf("CreateUser: %v", err)
	}
	return id
}

func TestWebAuthnCredentialRoundtrip(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	uid := makeUser(t, db, "philipp")

	if n, err := db.CountWebAuthnCredentials(ctx, uid); err != nil || n != 0 {
		t.Fatalf("Count zu Beginn = %d, %v; erwartet 0", n, err)
	}

	id, err := db.AddWebAuthnCredential(ctx, WebAuthnCredential{
		UserID: uid, CredentialID: "cred-abc", Label: "Titan-Stick",
		Data: []byte(`{"id":"abc"}`),
	})
	if err != nil {
		t.Fatalf("Add: %v", err)
	}

	list, err := db.WebAuthnCredentialsByUser(ctx, uid)
	if err != nil {
		t.Fatalf("List: %v", err)
	}
	if len(list) != 1 {
		t.Fatalf("List = %d Einträge, erwartet 1", len(list))
	}
	got := list[0]
	if got.ID != id || got.CredentialID != "cred-abc" || got.Label != "Titan-Stick" {
		t.Errorf("gelesen: %+v", got)
	}
	if string(got.Data) != `{"id":"abc"}` {
		t.Errorf("Data = %q", got.Data)
	}
	if got.LastUsedAt != nil {
		t.Error("LastUsedAt sollte zu Beginn leer sein")
	}
}

func TestWebAuthnDuplicateCredentialIDRejected(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	uid := makeUser(t, db, "philipp")

	if _, err := db.AddWebAuthnCredential(ctx, WebAuthnCredential{
		UserID: uid, CredentialID: "gleich", Data: []byte("{}"),
	}); err != nil {
		t.Fatalf("erster Add: %v", err)
	}
	// Dasselbe Gerät ein zweites Mal — die UNIQUE-Bedingung muss greifen.
	if _, err := db.AddWebAuthnCredential(ctx, WebAuthnCredential{
		UserID: uid, CredentialID: "gleich", Data: []byte("{}"),
	}); err == nil {
		t.Error("die doppelte credential_id wurde angenommen")
	}
}

func TestWebAuthnUpdateUse(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	uid := makeUser(t, db, "philipp")
	if _, err := db.AddWebAuthnCredential(ctx, WebAuthnCredential{
		UserID: uid, CredentialID: "cred-1", Data: []byte(`{"sc":0}`),
	}); err != nil {
		t.Fatal(err)
	}

	used := time.Now()
	if err := db.UpdateWebAuthnCredentialUse(ctx, "cred-1", []byte(`{"sc":5}`), used); err != nil {
		t.Fatalf("UpdateUse: %v", err)
	}

	list, _ := db.WebAuthnCredentialsByUser(ctx, uid)
	if string(list[0].Data) != `{"sc":5}` {
		t.Errorf("Data nicht fortgeschrieben: %q", list[0].Data)
	}
	if list[0].LastUsedAt == nil {
		t.Error("LastUsedAt wurde nicht gesetzt")
	}

	// Ein unbekanntes Credential fortzuschreiben ist ErrNotFound, kein stiller
	// Erfolg.
	if err := db.UpdateWebAuthnCredentialUse(ctx, "gibtsnicht", []byte("{}"), used); !errors.Is(err, ErrNotFound) {
		t.Errorf("UpdateUse unbekannt = %v, erwartet ErrNotFound", err)
	}
}

func TestWebAuthnRenameAndDeleteBoundToUser(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	owner := makeUser(t, db, "philipp")
	other := makeUser(t, db, "fremd")

	id, err := db.AddWebAuthnCredential(ctx, WebAuthnCredential{
		UserID: owner, CredentialID: "cred-x", Label: "alt", Data: []byte("{}"),
	})
	if err != nil {
		t.Fatal(err)
	}

	// Ein fremdes Konto darf weder umbenennen noch löschen.
	if err := db.RenameWebAuthnCredential(ctx, id, other, "geklaut"); !errors.Is(err, ErrNotFound) {
		t.Errorf("fremdes Umbenennen = %v, erwartet ErrNotFound", err)
	}
	if err := db.DeleteWebAuthnCredential(ctx, id, other); !errors.Is(err, ErrNotFound) {
		t.Errorf("fremdes Löschen = %v, erwartet ErrNotFound", err)
	}

	if err := db.RenameWebAuthnCredential(ctx, id, owner, "neu"); err != nil {
		t.Fatalf("Rename: %v", err)
	}
	list, _ := db.WebAuthnCredentialsByUser(ctx, owner)
	if list[0].Label != "neu" {
		t.Errorf("Label = %q, erwartet neu", list[0].Label)
	}

	if err := db.DeleteWebAuthnCredential(ctx, id, owner); err != nil {
		t.Fatalf("Delete: %v", err)
	}
	if n, _ := db.CountWebAuthnCredentials(ctx, owner); n != 0 {
		t.Errorf("nach Delete = %d, erwartet 0", n)
	}
}

func TestWebAuthnCredentialsCascadeOnUserDelete(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	uid := makeUser(t, db, "philipp")
	if _, err := db.AddWebAuthnCredential(ctx, WebAuthnCredential{
		UserID: uid, CredentialID: "cred-c", Data: []byte("{}"),
	}); err != nil {
		t.Fatal(err)
	}

	if _, err := db.SQL().ExecContext(ctx, `DELETE FROM users WHERE id = ?`, uid); err != nil {
		t.Fatalf("Benutzer löschen: %v", err)
	}
	if n, _ := db.CountWebAuthnCredentials(ctx, uid); n != 0 {
		t.Errorf("Passkeys blieben nach Kontolöschung: %d", n)
	}
}
