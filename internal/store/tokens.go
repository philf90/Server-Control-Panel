package store

// API-Tokens im Speicher: anlegen, auflösen, auflisten, widerrufen.
//
// Die Tabelle und die Überlegungen dahinter stehen in
// migrations/0005_api_tokens.sql. Hier sind zwei Dinge festzuhalten, die den
// Code betreffen und nicht das Schema:
//
// **Gesucht wird über den Hash, nie über den Klartext.** TokenByHash bekommt
// bereits den Hash — das Auflösen des Klartexts geschieht beim Aufrufer
// (auth.HashToken). Der Grund ist nicht Bequemlichkeit: Ein Klartext, der in
// diese Schicht wandert, landet früher oder später in einer
// Fehlermeldung, einem Log oder einem Test-Dump.
//
// **Ein abgelaufener Token wird nicht gelöscht.** Bei den Sitzungen ist es
// umgekehrt (SessionByID entfernt sie gleich), und der Unterschied hat einen
// Grund: Eine abgelaufene Sitzung ist ein geschlossenes Fenster, das niemand
// vermisst. Ein abgelaufener Token ist eine kaputte Automatisierung, und wer
// sucht, warum sein Skript seit Dienstag scheitert, soll den Token in der Liste
// finden — mit dem Ablaufdatum daneben. Weggeräumt wird er, wenn ein Mensch ihn
// widerruft.

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"strings"
	"time"
)

// APIToken ist ein Token, wie er in der Datenbank steht — ohne den Token selbst.
type APIToken struct {
	ID   int64
	Hash string
	// Prefix ist der sichtbare Anfang. Er erlaubt keine Anmeldung und macht die
	// Liste benutzbar: Wer drei Tokens in drei Skripten liegen hat, erkennt daran,
	// welcher welcher ist.
	Prefix string
	Name   string
	UserID int64
	// Scopes sind die erlaubten Modulfamilien. Leer heißt „alle erlaubten".
	Scopes    []string
	ReadOnly  bool
	CreatedAt time.Time
	// ExpiresAt ist nil für „ohne Ablauf". Ein eigener Zustand und nicht ein
	// Datum in ferner Zukunft: Die Oberfläche muss ihn benennen können.
	ExpiresAt  *time.Time
	LastUsedAt *time.Time
	LastUsedIP string
}

// Abgelaufen sagt, ob der Token seine Frist überschritten hat.
func (t APIToken) Abgelaufen(jetzt time.Time) bool {
	return t.ExpiresAt != nil && jetzt.After(*t.ExpiresAt)
}

// CreateAPIToken legt einen Token an und liefert seine Kennung.
func (db *DB) CreateAPIToken(ctx context.Context, t APIToken) (int64, error) {
	res, err := db.sql.ExecContext(ctx, `
		INSERT INTO api_tokens
			(token_hash, prefix, name, user_id, scopes, read_only, created_at, expires_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
		t.Hash, t.Prefix, t.Name, t.UserID,
		strings.Join(t.Scopes, ","), boolZahl(t.ReadOnly),
		t.CreatedAt.UTC().Format(time.RFC3339),
		zeitOderNull(t.ExpiresAt),
	)
	if err != nil {
		return 0, fmt.Errorf("token anlegen: %w", err)
	}
	id, err := res.LastInsertId()
	if err != nil {
		return 0, fmt.Errorf("token anlegen: %w", err)
	}
	return id, nil
}

// TokenByHash löst einen Token auf. Der Aufrufer übergibt den HASH, nie den
// Klartext.
//
// Ein abgelaufener Token wird gefunden und als abgelaufen zurückgegeben, nicht
// verschwiegen: Der Aufrufer entscheidet, ob er ihn abweist (der Anmeldeweg) oder
// anzeigt (die Liste). Ein Fehler „nicht gefunden" für einen abgelaufenen Token
// hieße in der Oberfläche „diesen Token gab es nie".
func (db *DB) TokenByHash(ctx context.Context, hash string) (APIToken, error) {
	row := db.sql.QueryRowContext(ctx, `
		SELECT id, token_hash, prefix, name, user_id, scopes, read_only,
		       created_at, expires_at, last_used_at, last_used_ip
		FROM api_tokens WHERE token_hash = ?`, hash)
	t, err := tokenAusZeile(row)
	if errors.Is(err, sql.ErrNoRows) {
		return APIToken{}, ErrNotFound
	}
	if err != nil {
		return APIToken{}, fmt.Errorf("token lesen: %w", err)
	}
	return t, nil
}

// ListAPITokens liefert alle Tokens, jüngster zuerst.
//
// Alle und nicht nur die eigenen: Die Fläche gehört der Owner-Rolle, und ein
// Token, den ein anderes Konto angelegt hat, ist genau der, den man übersieht.
// Wer welchen angelegt hat, sagt das Feld UserID.
func (db *DB) ListAPITokens(ctx context.Context) ([]APIToken, error) {
	rows, err := db.sql.QueryContext(ctx, `
		SELECT id, token_hash, prefix, name, user_id, scopes, read_only,
		       created_at, expires_at, last_used_at, last_used_ip
		FROM api_tokens ORDER BY id DESC`)
	if err != nil {
		return nil, fmt.Errorf("tokens lesen: %w", err)
	}
	defer func() { _ = rows.Close() }()

	out := []APIToken{}
	for rows.Next() {
		t, err := tokenAusZeile(rows)
		if err != nil {
			return nil, fmt.Errorf("tokens lesen: %w", err)
		}
		out = append(out, t)
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("tokens lesen: %w", err)
	}
	return out, nil
}

// DeleteAPIToken widerruft einen Token, indem es die Zeile entfernt.
//
// Die Zahl der entfernten Zeilen kommt mit zurück: Der Aufrufer soll „gab es
// nicht" von „ist widerrufen" unterscheiden können, ohne vorher zu lesen.
func (db *DB) DeleteAPIToken(ctx context.Context, id int64) (bool, error) {
	res, err := db.sql.ExecContext(ctx, `DELETE FROM api_tokens WHERE id = ?`, id)
	if err != nil {
		return false, fmt.Errorf("token widerrufen: %w", err)
	}
	n, err := res.RowsAffected()
	if err != nil {
		return false, fmt.Errorf("token widerrufen: %w", err)
	}
	return n > 0, nil
}

// TouchAPIToken hält die letzte Nutzung fest.
//
// Bewusst ohne Fehlerweitergabe an den Anmeldevorgang beim Aufrufer: Dass die
// Notiz misslingt, darf keine Anfrage abweisen — der Token ist gültig, und die
// Auskunft „zuletzt benutzt" ist Beiwerk. Sie wird nur fortgeschrieben, wenn sich
// die Minute geändert hat; jede Anfrage eines Abfrageskripts sonst ein
// Schreibzugriff.
func (db *DB) TouchAPIToken(ctx context.Context, id int64, jetzt time.Time, ip string) error {
	_, err := db.sql.ExecContext(ctx,
		`UPDATE api_tokens SET last_used_at = ?, last_used_ip = ? WHERE id = ?`,
		jetzt.UTC().Format(time.RFC3339), ip, id)
	if err != nil {
		return fmt.Errorf("token-nutzung vermerken: %w", err)
	}
	return nil
}

// DeleteAPITokensByUser entfernt die Tokens eines Kontos.
//
// Wird beim Löschen eines Kontos nicht gebraucht — das erledigt
// ON DELETE CASCADE —, wohl aber beim Zurücksetzen eines Zugangs: Wer das
// Passwort eines fremden Kontos zurücksetzt, weil es übernommen wurde, muss auch
// dessen Tokens los sein. Ein Token überlebt jeden Passwortwechsel, das ist seine
// Aufgabe, und genau deshalb ist er der Weg, mit dem eine Übernahme bestehen
// bleibt.
func (db *DB) DeleteAPITokensByUser(ctx context.Context, userID int64) (int64, error) {
	res, err := db.sql.ExecContext(ctx, `DELETE FROM api_tokens WHERE user_id = ?`, userID)
	if err != nil {
		return 0, fmt.Errorf("tokens des kontos widerrufen: %w", err)
	}
	n, err := res.RowsAffected()
	if err != nil {
		return 0, fmt.Errorf("tokens des kontos widerrufen: %w", err)
	}
	return n, nil
}

// ------------------------------------------------------------- Lesehilfen ---

// zeilenLeser ist das, was *sql.Row und *sql.Rows gemeinsam haben.
type zeilenLeser interface {
	Scan(ziel ...any) error
}

func tokenAusZeile(z zeilenLeser) (APIToken, error) {
	var (
		t         APIToken
		scopes    string
		readOnly  int
		createdAt string
		// Nullable Spalten: sql.NullString und nicht *string, damit der leere
		// Fall vom Treiber kommt und nicht aus einer eigenen Auslegung.
		expiresAt  sql.NullString
		lastUsedAt sql.NullString
	)
	err := z.Scan(&t.ID, &t.Hash, &t.Prefix, &t.Name, &t.UserID, &scopes, &readOnly,
		&createdAt, &expiresAt, &lastUsedAt, &t.LastUsedIP)
	if err != nil {
		return APIToken{}, err
	}

	t.Scopes = scopesLesen(scopes)
	t.ReadOnly = readOnly == 1
	t.CreatedAt, _ = time.Parse(time.RFC3339, createdAt)
	t.ExpiresAt = zeitAusNull(expiresAt)
	t.LastUsedAt = zeitAusNull(lastUsedAt)
	return t, nil
}

// scopesLesen zerlegt die kommagetrennte Liste. Leere Teile fallen weg: Eine
// Liste mit einem leeren Eintrag wäre eine Familie mit leerem Namen, und die
// passt auf keinen Pfad — der Token könnte dann gar nichts.
func scopesLesen(roh string) []string {
	out := []string{}
	for teil := range strings.SplitSeq(roh, ",") {
		teil = strings.TrimSpace(teil)
		if teil != "" {
			out = append(out, teil)
		}
	}
	return out
}

func boolZahl(b bool) int {
	if b {
		return 1
	}
	return 0
}

func zeitOderNull(t *time.Time) any {
	if t == nil {
		return nil
	}
	return t.UTC().Format(time.RFC3339)
}

func zeitAusNull(v sql.NullString) *time.Time {
	if !v.Valid || v.String == "" {
		return nil
	}
	t, err := time.Parse(time.RFC3339, v.String)
	if err != nil {
		return nil
	}
	return &t
}
