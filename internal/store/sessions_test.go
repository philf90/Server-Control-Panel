package store

import (
	"context"
	"errors"
	"testing"
	"time"
)

// newSessionUser legt ein Konto an, an dem Sitzungen hängen können.
func newSessionUser(t *testing.T, db *DB, name string) int64 {
	t.Helper()
	id, err := db.CreateUser(context.Background(), User{
		Username: name, PasswordHash: "x", Role: RoleAdmin, TOTPSecret: "y",
	})
	if err != nil {
		t.Fatalf("Konto %s anlegen: %v", name, err)
	}
	return id
}

func addSession(t *testing.T, db *DB, userID int64, id string, lastSeen time.Time, expires time.Time) {
	t.Helper()
	err := db.CreateSession(context.Background(), Session{
		ID: id, UserID: userID, CSRFToken: "csrf-" + id,
		CreatedAt: lastSeen, LastSeenAt: lastSeen, ExpiresAt: expires,
		IP: "203.0.113.7", UserAgent: "curl/8.5.0",
	})
	if err != nil {
		t.Fatalf("Sitzung %s anlegen: %v", id, err)
	}
}

func TestListUserSessions(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	user := newSessionUser(t, db, "philipp")
	fremd := newSessionUser(t, db, "andere")

	now := time.Now()
	addSession(t, db, user, "alt", now.Add(-2*time.Hour), now.Add(time.Hour))
	addSession(t, db, user, "neu", now.Add(-time.Minute), now.Add(time.Hour))
	// Abgelaufen: darf nicht erscheinen, auch wenn die Zeile noch dasteht.
	addSession(t, db, user, "abgelaufen", now.Add(-3*time.Hour), now.Add(-time.Minute))
	addSession(t, db, fremd, "fremd", now, now.Add(time.Hour))

	sessions, err := db.ListUserSessions(ctx, user)
	if err != nil {
		t.Fatalf("ListUserSessions: %v", err)
	}
	if len(sessions) != 2 {
		t.Fatalf("%d Sitzungen, erwartet 2: %+v", len(sessions), sessions)
	}
	// Zuletzt gesehen zuerst.
	if sessions[0].ID != "neu" || sessions[1].ID != "alt" {
		t.Errorf("Reihenfolge = %s, %s", sessions[0].ID, sessions[1].ID)
	}
	if sessions[0].IP != "203.0.113.7" || sessions[0].UserAgent != "curl/8.5.0" {
		t.Errorf("Herkunftsangaben fehlen: %+v", sessions[0])
	}
	if sessions[0].LastSeenAt.IsZero() || sessions[0].ExpiresAt.IsZero() {
		t.Error("die Zeitstempel wurden nicht gelesen")
	}
}

func TestDeleteUserSessionNurEigene(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	user := newSessionUser(t, db, "philipp")
	fremd := newSessionUser(t, db, "andere")

	now := time.Now()
	addSession(t, db, user, "eigene", now, now.Add(time.Hour))
	addSession(t, db, fremd, "fremde", now, now.Add(time.Hour))

	// Die fremde Sitzung darf sich nicht über die eigene Kennung beenden
	// lassen — sonst genügte das Raten einer Sitzungskennung.
	if err := db.DeleteUserSession(ctx, user, "fremde"); !errors.Is(err, ErrNotFound) {
		t.Fatalf("= %v, erwartet ErrNotFound", err)
	}
	if _, err := db.SessionByID(ctx, "fremde"); err != nil {
		t.Error("die fremde Sitzung wurde trotzdem beendet")
	}

	if err := db.DeleteUserSession(ctx, user, "eigene"); err != nil {
		t.Fatalf("eigene Sitzung beenden: %v", err)
	}
	if _, err := db.SessionByID(ctx, "eigene"); !errors.Is(err, ErrNotFound) {
		t.Error("die eigene Sitzung besteht weiter")
	}
	// Ein zweiter Versuch meldet sauber, dass nichts mehr da ist.
	if err := db.DeleteUserSession(ctx, user, "eigene"); !errors.Is(err, ErrNotFound) {
		t.Errorf("= %v, erwartet ErrNotFound", err)
	}
}

func TestDeleteOtherUserSessions(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	user := newSessionUser(t, db, "philipp")
	fremd := newSessionUser(t, db, "andere")

	now := time.Now()
	for _, id := range []string{"behalten", "weg1", "weg2"} {
		addSession(t, db, user, id, now, now.Add(time.Hour))
	}
	addSession(t, db, fremd, "fremde", now, now.Add(time.Hour))

	n, err := db.DeleteOtherUserSessions(ctx, user, "behalten")
	if err != nil {
		t.Fatalf("DeleteOtherUserSessions: %v", err)
	}
	if n != 2 {
		t.Errorf("%d beendet, erwartet 2", n)
	}
	if _, err := db.SessionByID(ctx, "behalten"); err != nil {
		t.Error("die zu behaltende Sitzung wurde mitbeendet")
	}
	if _, err := db.SessionByID(ctx, "fremde"); err != nil {
		t.Error("eine Sitzung eines anderen Kontos wurde beendet")
	}

	// Ein zweiter Lauf beendet nichts mehr.
	n, err = db.DeleteOtherUserSessions(ctx, user, "behalten")
	if err != nil || n != 0 {
		t.Errorf("zweiter Lauf: %d, %v", n, err)
	}
}

// TestSetTOTPCounter deckt den Wiederholungsschutz auf Datenbankebene ab.
func TestSetTOTPCounter(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	user := newSessionUser(t, db, "philipp")

	loaded, err := db.UserByID(ctx, user)
	if err != nil {
		t.Fatal(err)
	}
	if loaded.TOTPLastCounter != 0 {
		t.Fatalf("frisches Konto startet bei %d, erwartet 0", loaded.TOTPLastCounter)
	}

	if err := db.SetTOTPCounter(ctx, user, 100); err != nil {
		t.Fatalf("SetTOTPCounter: %v", err)
	}
	if loaded, _ = db.UserByID(ctx, user); loaded.TOTPLastCounter != 100 {
		t.Errorf("Zähler = %d, erwartet 100", loaded.TOTPLastCounter)
	}

	// Derselbe Stand noch einmal: Das ist der Wettlauf zweier gleichzeitiger
	// Anmeldungen mit demselben Code. Genau eine darf durchkommen.
	if err := db.SetTOTPCounter(ctx, user, 100); !errors.Is(err, ErrNotFound) {
		t.Errorf("zweiter Versuch mit demselben Stand = %v, erwartet ErrNotFound", err)
	}
	// Ein älterer Stand ebenfalls nicht — sonst ließe sich der Schutz
	// zurückdrehen.
	if err := db.SetTOTPCounter(ctx, user, 99); !errors.Is(err, ErrNotFound) {
		t.Errorf("Rückschritt = %v, erwartet ErrNotFound", err)
	}
	if loaded, _ = db.UserByID(ctx, user); loaded.TOTPLastCounter != 100 {
		t.Errorf("Zähler wurde verändert: %d", loaded.TOTPLastCounter)
	}

	// Ein neueres Fenster geht durch.
	if err := db.SetTOTPCounter(ctx, user, 101); err != nil {
		t.Errorf("neueres Fenster: %v", err)
	}
}

// TestSetTOTPZuruecksetztDenZaehler: Ein neues Geheimnis bringt eine eigene
// Zählerfolge mit. Bliebe der alte Stand stehen, wären die ersten Codes des
// neuen Faktors reihenweise ungültig.
func TestSetTOTPZuruecksetztDenZaehler(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	user := newSessionUser(t, db, "philipp")

	if err := db.SetTOTPCounter(ctx, user, 5000); err != nil {
		t.Fatal(err)
	}
	if err := db.SetTOTP(ctx, user, "NEUESGEHEIMNIS", true); err != nil {
		t.Fatal(err)
	}
	loaded, err := db.UserByID(ctx, user)
	if err != nil {
		t.Fatal(err)
	}
	if loaded.TOTPLastCounter != 0 {
		t.Errorf("Zähler nach Geheimniswechsel = %d, erwartet 0", loaded.TOTPLastCounter)
	}
}
