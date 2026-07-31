package store

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"
)

func tokenKonto(t *testing.T, db *DB, name string) int64 {
	t.Helper()
	id, err := db.CreateUser(context.Background(), User{
		Username: name, PasswordHash: "x", Role: RoleOwner, TOTPSecret: "y",
	})
	if err != nil {
		t.Fatal(err)
	}
	return id
}

// TestAPITokenRundlauf: anlegen, über den Hash finden, in der Liste sehen,
// widerrufen. Der Klartext kommt in dieser Schicht nie vor — sie kennt nur den
// Hash, und das ist der Grund, warum der Aufrufer ihn bildet.
func TestAPITokenRundlauf(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	user := tokenKonto(t, db, "philipp")

	frist := time.Now().Add(30 * 24 * time.Hour).Truncate(time.Second)
	id, err := db.CreateAPIToken(ctx, APIToken{
		Hash: "hash-eins", Prefix: "abcd1234", Name: "Sicherungsskript",
		UserID: user, Scopes: []string{"overview", "services"}, ReadOnly: true,
		CreatedAt: time.Now(), ExpiresAt: &frist,
	})
	if err != nil {
		t.Fatalf("CreateAPIToken: %v", err)
	}
	if id == 0 {
		t.Fatal("keine Kennung zurückgegeben")
	}

	got, err := db.TokenByHash(ctx, "hash-eins")
	if err != nil {
		t.Fatalf("TokenByHash: %v", err)
	}
	if got.ID != id || got.Name != "Sicherungsskript" || got.UserID != user {
		t.Errorf("gelesen: %+v", got)
	}
	if got.Prefix != "abcd1234" {
		t.Errorf("Prefix = %q — ohne ihn ist die Liste eine Liste von Namen", got.Prefix)
	}
	if !got.ReadOnly {
		t.Error("ReadOnly = false, angelegt war true")
	}
	if len(got.Scopes) != 2 || got.Scopes[0] != "overview" || got.Scopes[1] != "services" {
		t.Errorf("Scopes = %v", got.Scopes)
	}
	if got.ExpiresAt == nil || !got.ExpiresAt.Equal(frist.UTC()) {
		t.Errorf("Frist = %v, erwartet %v", got.ExpiresAt, frist.UTC())
	}
	// Nie benutzt heißt: kein Zeitpunkt. Nicht der 1. Januar 1970.
	if got.LastUsedAt != nil {
		t.Errorf("LastUsedAt = %v bei einem nie benutzten Token", got.LastUsedAt)
	}

	liste, err := db.ListAPITokens(ctx)
	if err != nil {
		t.Fatalf("ListAPITokens: %v", err)
	}
	if len(liste) != 1 {
		t.Fatalf("%d Tokens in der Liste, erwartet 1", len(liste))
	}

	weg, err := db.DeleteAPIToken(ctx, id)
	if err != nil {
		t.Fatalf("DeleteAPIToken: %v", err)
	}
	if !weg {
		t.Error("DeleteAPIToken meldet, es habe nichts gegeben")
	}
	if _, err := db.TokenByHash(ctx, "hash-eins"); !errors.Is(err, ErrNotFound) {
		t.Errorf("der widerrufene Token ist noch auflösbar: %v", err)
	}

	// Ein zweiter Widerruf meldet „gab es nicht" und ist kein Fehler: zwei
	// Fenster, zwei Klicks.
	weg, err = db.DeleteAPIToken(ctx, id)
	if err != nil {
		t.Errorf("zweiter Widerruf: %v", err)
	}
	if weg {
		t.Error("der zweite Widerruf meldet einen Treffer")
	}
}

// TestAPITokenOhneAblauf: nil ist ein eigener Zustand und muss als nil
// zurückkommen. Ein Datum in ferner Zukunft wäre eine Behauptung, und die
// Oberfläche könnte „ohne Ablauf" dann nicht mehr benennen.
func TestAPITokenOhneAblauf(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	user := tokenKonto(t, db, "philipp")

	if _, err := db.CreateAPIToken(ctx, APIToken{
		Hash: "h", Prefix: "p", Name: "dauerlaeufer", UserID: user,
		CreatedAt: time.Now(),
	}); err != nil {
		t.Fatal(err)
	}

	got, err := db.TokenByHash(ctx, "h")
	if err != nil {
		t.Fatal(err)
	}
	if got.ExpiresAt != nil {
		t.Errorf("aus „ohne Ablauf\" wurde %v", got.ExpiresAt)
	}
	if got.Abgelaufen(time.Now().Add(100 * 365 * 24 * time.Hour)) {
		t.Error("ein Token ohne Ablauf gilt in hundert Jahren als abgelaufen")
	}
	// Und die leere Scope-Liste ist leer, nicht eine Liste mit einem leeren
	// Eintrag: Der würde auf keinen Pfad passen, der Token könnte gar nichts.
	if len(got.Scopes) != 0 {
		t.Errorf("Scopes = %v, erwartet leer", got.Scopes)
	}
}

// TestAPITokenAbgelaufenBleibtLesbar ist der Unterschied zu den Sitzungen: Eine
// abgelaufene Sitzung wird beim Lesen entfernt, ein abgelaufener Token nicht. Er
// ist eine kaputte Automatisierung, und wer sucht, warum sein Skript seit
// Dienstag scheitert, soll ihn in der Liste finden — mit dem Datum daneben.
func TestAPITokenAbgelaufenBleibtLesbar(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	user := tokenKonto(t, db, "philipp")

	gestern := time.Now().Add(-24 * time.Hour)
	if _, err := db.CreateAPIToken(ctx, APIToken{
		Hash: "alt", Prefix: "p", Name: "verfallen", UserID: user,
		CreatedAt: time.Now().Add(-48 * time.Hour), ExpiresAt: &gestern,
	}); err != nil {
		t.Fatal(err)
	}

	got, err := db.TokenByHash(ctx, "alt")
	if err != nil {
		t.Fatalf("der abgelaufene Token ist nicht mehr lesbar: %v", err)
	}
	if !got.Abgelaufen(time.Now()) {
		t.Error("Abgelaufen() = false bei einer Frist von gestern")
	}
	if liste, err := db.ListAPITokens(ctx); err != nil || len(liste) != 1 {
		t.Errorf("Liste = %v, %v — der abgelaufene Token fehlt", liste, err)
	}
}

// TestAPITokenNeuesteZuerst: Die Liste ist nach Kennung absteigend sortiert. Wer
// gerade einen angelegt hat, findet ihn oben — und nicht am Ende einer Liste von
// zwanzig.
func TestAPITokenNeuesteZuerst(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	user := tokenKonto(t, db, "philipp")

	for _, name := range []string{"erster", "zweiter", "dritter"} {
		if _, err := db.CreateAPIToken(ctx, APIToken{
			Hash: "hash-" + name, Prefix: "p", Name: name, UserID: user,
			CreatedAt: time.Now(),
		}); err != nil {
			t.Fatal(err)
		}
	}

	liste, err := db.ListAPITokens(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if len(liste) != 3 {
		t.Fatalf("%d Tokens", len(liste))
	}
	if liste[0].Name != "dritter" || liste[2].Name != "erster" {
		t.Errorf("Reihenfolge = %s, %s, %s", liste[0].Name, liste[1].Name, liste[2].Name)
	}
}

// TestAPITokenHashIstEindeutig: Zwei Zeilen mit demselben Hash würden bedeuten,
// dass ein Token zwei Rollen hat — und welche gilt, entschiede die Reihenfolge
// der Zeilen. Die Datenbank muss das abweisen.
func TestAPITokenHashIstEindeutig(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	user := tokenKonto(t, db, "philipp")

	if _, err := db.CreateAPIToken(ctx, APIToken{
		Hash: "derselbe", Prefix: "p", Name: "eins", UserID: user, CreatedAt: time.Now(),
	}); err != nil {
		t.Fatal(err)
	}
	_, err := db.CreateAPIToken(ctx, APIToken{
		Hash: "derselbe", Prefix: "p", Name: "zwei", UserID: user, CreatedAt: time.Now(),
	})
	if err == nil {
		t.Fatal("zwei Tokens mit demselben Hash wurden angelegt")
	}
}

// TestTouchAPIToken hält die letzte Nutzung fest — die Antwort auf „benutzt das
// noch jemand?". Ohne sie ist Aufräumen Raten.
func TestTouchAPIToken(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	user := tokenKonto(t, db, "philipp")

	id, err := db.CreateAPIToken(ctx, APIToken{
		Hash: "h", Prefix: "p", Name: "skript", UserID: user, CreatedAt: time.Now(),
	})
	if err != nil {
		t.Fatal(err)
	}

	jetzt := time.Now().Truncate(time.Second)
	if err := db.TouchAPIToken(ctx, id, jetzt, "10.0.0.7"); err != nil {
		t.Fatalf("TouchAPIToken: %v", err)
	}

	got, err := db.TokenByHash(ctx, "h")
	if err != nil {
		t.Fatal(err)
	}
	if got.LastUsedAt == nil || !got.LastUsedAt.Equal(jetzt.UTC()) {
		t.Errorf("LastUsedAt = %v, erwartet %v", got.LastUsedAt, jetzt.UTC())
	}
	if got.LastUsedIP != "10.0.0.7" {
		t.Errorf("LastUsedIP = %q", got.LastUsedIP)
	}
}

// TestAPITokenFolgtDemKonto: Ein Token, der ein gelöschtes Konto überlebt, wäre
// ein Zugang ohne Inhaber und ohne Rolle. ON DELETE CASCADE nimmt ihn mit.
func TestAPITokenFolgtDemKonto(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	user := tokenKonto(t, db, "geht-weg")

	if _, err := db.CreateAPIToken(ctx, APIToken{
		Hash: "h", Prefix: "p", Name: "waise", UserID: user, CreatedAt: time.Now(),
	}); err != nil {
		t.Fatal(err)
	}
	if err := db.DeleteUser(ctx, user); err != nil {
		t.Fatal(err)
	}

	if _, err := db.TokenByHash(ctx, "h"); !errors.Is(err, ErrNotFound) {
		t.Errorf("der Token des gelöschten Kontos ist noch auflösbar: %v", err)
	}
}

// TestDeleteAPITokensByUser: Ein Token überlebt jeden Passwortwechsel — das ist
// seine Aufgabe, und genau deshalb ist er der Weg, mit dem eine Übernahme
// bestehen bleibt. Wer einen Zugang zurücksetzt, muss die Tokens los sein.
func TestDeleteAPITokensByUser(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	betroffen := tokenKonto(t, db, "uebernommen")
	fremd := tokenKonto(t, db, "unbeteiligt")

	for i, u := range []int64{betroffen, betroffen, fremd} {
		if _, err := db.CreateAPIToken(ctx, APIToken{
			Hash: "hash" + strings.Repeat("x", i+1), Prefix: "p",
			Name: "t", UserID: u, CreatedAt: time.Now(),
		}); err != nil {
			t.Fatal(err)
		}
	}

	n, err := db.DeleteAPITokensByUser(ctx, betroffen)
	if err != nil {
		t.Fatalf("DeleteAPITokensByUser: %v", err)
	}
	if n != 2 {
		t.Errorf("%d Tokens widerrufen, erwartet 2", n)
	}

	// Der fremde bleibt: Eine Zurücksetzung an einem Konto darf nicht die
	// Automatisierung eines anderen abschalten.
	liste, err := db.ListAPITokens(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if len(liste) != 1 || liste[0].UserID != fremd {
		t.Errorf("übrig: %+v", liste)
	}
}

// TestScopesLesen: Eine Liste mit einem leeren Eintrag wäre eine Familie mit
// leerem Namen — sie passt auf keinen Pfad, und der Token könnte gar nichts.
func TestScopesLesen(t *testing.T) {
	faelle := map[string][]string{
		"":                   {},
		",":                  {},
		" , , ":              {},
		"services":           {"services"},
		"services,packages":  {"services", "packages"},
		" services , logs ":  {"services", "logs"},
		"services,,packages": {"services", "packages"},
	}
	for roh, erwartet := range faelle {
		got := scopesLesen(roh)
		if got == nil {
			t.Errorf("scopesLesen(%q) = nil, erwartet eine leere Liste", roh)
			continue
		}
		if len(got) != len(erwartet) {
			t.Errorf("scopesLesen(%q) = %v, erwartet %v", roh, got, erwartet)
			continue
		}
		for i := range got {
			if got[i] != erwartet[i] {
				t.Errorf("scopesLesen(%q) = %v, erwartet %v", roh, got, erwartet)
				break
			}
		}
	}
}
