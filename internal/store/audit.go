package store

import (
	"context"
	"fmt"
	"strings"
	"time"
)

// Ergebnisse eines Audit-Eintrags.
const (
	ResultOK     = "ok"
	ResultDenied = "denied"
	ResultError  = "error"
)

// AuditEntry ist ein Eintrag im Audit-Log.
type AuditEntry struct {
	ID     int64
	At     time.Time
	Actor  string
	Action string
	Target string
	Result string
	IP     string
	Detail string
}

// maxAuditFeld begrenzt ein Textfeld. Ein Pfad darf bis zu 4096 Zeichen lang
// sein; als Zeile in einer Übersicht ist er dann kein Eintrag mehr, sondern eine
// Wand.
const maxAuditFeld = 1024

// AppendAudit schreibt einen Eintrag. Das Audit-Log ist nur additiv — es gibt
// bewusst keine Lösch- oder Änderungsfunktion.
func (db *DB) AppendAudit(ctx context.Context, e AuditEntry) error {
	if e.At.IsZero() {
		e.At = time.Now()
	}
	// Steuerzeichen werden sichtbar gemacht, nicht übernommen. Seit es den
	// Dateimanager gibt, wandern freie Pfade in dieses Feld — und ein Pfad darf
	// einen Zeilenumbruch enthalten. In einem zeilenweisen Protokoll (geplant:
	// /var/log/asylum/audit.log) würde daraus aus einem Eintrag zwei, und der
	// zweite wäre frei erfunden. Auch in der Anzeige hat ein Zeilenumbruch
	// mitten in einem Zielpfad nichts zu suchen.
	e.Actor = auditFeld(e.Actor)
	e.Action = auditFeld(e.Action)
	e.Target = auditFeld(e.Target)
	e.Result = auditFeld(e.Result)
	e.IP = auditFeld(e.IP)
	e.Detail = auditFeld(e.Detail)

	_, err := db.sql.ExecContext(ctx, `
		INSERT INTO audit_log (at, actor, action, target, result, ip, detail)
		VALUES (?, ?, ?, ?, ?, ?, ?)`,
		e.At.UTC().Format(time.RFC3339), e.Actor, e.Action, e.Target, e.Result, e.IP, e.Detail)
	if err != nil {
		return fmt.Errorf("audit schreiben: %w", err)
	}
	return nil
}

// auditFeld macht ein Textfeld protokollfähig: Steuerzeichen werden als
// Escape-Folge sichtbar, die Länge ist begrenzt.
//
// Sichtbar machen statt entfernen: Ein Pfad, aus dem stillschweigend Zeichen
// verschwinden, führt bei der Fehlersuche in die Irre. "\n" im Eintrag sagt,
// was dort stand.
func auditFeld(v string) string {
	var b strings.Builder
	b.Grow(len(v))
	for _, r := range v {
		switch {
		case r == '\n':
			b.WriteString(`\n`)
		case r == '\r':
			b.WriteString(`\r`)
		case r == '\t':
			b.WriteString(`\t`)
		case r < 0x20 || r == 0x7f:
			// Fprintf statt WriteString(Sprintf(…)): Der Umweg über eine
			// Zwischenzeichenkette kostet eine Allokation je Zeichen, und
			// staticcheck (QF1012) besteht darauf. Ein strings.Builder gibt beim
			// Schreiben nie einen Fehler zurück.
			_, _ = fmt.Fprintf(&b, `\x%02x`, r)
		// Die Schreibrichtungs-Umschalter lassen einen Eintrag anders aussehen,
		// als er ist — in einem Audit-Log der letzte Ort, an dem das tragbar wäre.
		case r >= 0x202a && r <= 0x202e, r >= 0x2066 && r <= 0x2069:
			_, _ = fmt.Fprintf(&b, `\u%04x`, r)
		default:
			b.WriteRune(r)
		}
		if b.Len() >= maxAuditFeld {
			return b.String()[:maxAuditFeld] + "…"
		}
	}
	return b.String()
}

// ListAudit liefert die jüngsten Einträge.
func (db *DB) ListAudit(ctx context.Context, limit int) ([]AuditEntry, error) {
	if limit <= 0 || limit > 500 {
		limit = 100
	}
	rows, err := db.sql.QueryContext(ctx, `
		SELECT id, at, actor, action, target, result, ip, detail
		FROM audit_log ORDER BY id DESC LIMIT ?`, limit)
	if err != nil {
		return nil, fmt.Errorf("audit lesen: %w", err)
	}
	defer func() { _ = rows.Close() }()

	var out []AuditEntry
	for rows.Next() {
		var (
			e  AuditEntry
			at string
		)
		if err := rows.Scan(&e.ID, &at, &e.Actor, &e.Action, &e.Target, &e.Result, &e.IP, &e.Detail); err != nil {
			return nil, err
		}
		e.At, _ = time.Parse(time.RFC3339, at)
		out = append(out, e)
	}
	return out, rows.Err()
}

// ------------------------------------------------------------------ Suchen ---

// AuditFilter beschreibt eine Abfrage über das Protokoll.
//
// Warum überhaupt serverseitig gefiltert wird, und nicht im Browser wie bei den
// Diensten: Das Protokoll ist die einzige Liste des Panels, die unbegrenzt
// wächst. Eine Antwort ist deshalb immer nur ein Ausschnitt — und ein Filter über
// einem Ausschnitt behauptete „kein Treffer" für einen Eintrag, den es gibt. Wer
// fragt „wer hat /etc/nginx/site.conf gelöscht", stellt genau diese Frage an
// einen Eintrag von vorletzter Woche.
type AuditFilter struct {
	// Actor ist ein Kontoname, exakt verglichen. Die Namen kommen aus einer
	// Auswahl (siehe AuditFacetten), nicht aus einem Textfeld.
	Actor string
	// Action ist ein PRÄFIX. Die Aktionen sind hierarchisch benannt
	// ("files.delete", "files.chmod", "service.stop"), und "files." ist die
	// Frage, die man tatsächlich stellt: alles, was am Dateimanager geschah.
	Action string
	// Result ist "ok", "denied" oder "error". Ein anderer Wert findet nichts —
	// die Spalte hat einen CHECK, es gibt keine vierte Möglichkeit.
	Result string
	// Query sucht frei in Ziel und Detail. Die beiden Felder tragen die Pfade
	// und die Ausgaben; der Rest ist über die anderen Felder erreichbar.
	Query string
	// Before blättert: Geliefert wird, was älter ist als diese ID.
	//
	// Nach ID und nicht über OFFSET, und das ist mehr als Geschmack. Das
	// Protokoll wächst, während man darin liest — ein OFFSET von 100 zeigt nach
	// drei neuen Einträgen drei Zeilen doppelt und überspringt keine. Bei einem
	// Revisionsprotokoll ist das die falsche Art von Fehler: Man blättert darin,
	// um etwas NICHT zu übersehen.
	Before int64
	Limit  int
}

// FilterAudit liefert Einträge nach Filter, absteigend nach ID.
//
// Zur Geschwindigkeit: Gelesen wird der Primärschlüsselindex rückwärts, gefiltert
// wird dabei. Bei einem selektiven Filter über ein großes Protokoll heißt das
// weit lesen. Für ein Panel auf einem Server ist das in Ordnung — und ein Index
// auf actor/action, den niemand gemessen hat, wäre eine Migration auf Verdacht.
// Fällt es im Betrieb auf, gehört er nachgezogen; die Stelle ist diese.
func (db *DB) FilterAudit(ctx context.Context, f AuditFilter) ([]AuditEntry, error) {
	if f.Limit <= 0 || f.Limit > 500 {
		f.Limit = 100
	}

	abfrage := `SELECT id, at, actor, action, target, result, ip, detail FROM audit_log WHERE 1=1`
	var args []any

	if f.Actor != "" {
		abfrage += ` AND actor = ?`
		args = append(args, f.Actor)
	}
	if f.Action != "" {
		// Präfixsuche. Das LIKE-Muster wird hier gebaut und nicht vom Aufrufer
		// übergeben: Ein "%" mitten im Wert wäre sonst ein Platzhalter, den
		// niemand gemeint hat.
		abfrage += ` AND action LIKE ? ESCAPE '\'`
		args = append(args, likePraefix(f.Action))
	}
	if f.Result != "" {
		abfrage += ` AND result = ?`
		args = append(args, f.Result)
	}
	if f.Query != "" {
		abfrage += ` AND (target LIKE ? ESCAPE '\' OR detail LIKE ? ESCAPE '\')`
		muster := likeEnthaelt(f.Query)
		args = append(args, muster, muster)
	}
	if f.Before > 0 {
		abfrage += ` AND id < ?`
		args = append(args, f.Before)
	}
	abfrage += ` ORDER BY id DESC LIMIT ?`
	args = append(args, f.Limit)

	rows, err := db.sql.QueryContext(ctx, abfrage, args...)
	if err != nil {
		return nil, fmt.Errorf("audit filtern: %w", err)
	}
	defer func() { _ = rows.Close() }()

	out := make([]AuditEntry, 0, f.Limit)
	for rows.Next() {
		var (
			e  AuditEntry
			at string
		)
		if err := rows.Scan(&e.ID, &at, &e.Actor, &e.Action, &e.Target, &e.Result, &e.IP, &e.Detail); err != nil {
			return nil, err
		}
		e.At, _ = time.Parse(time.RFC3339, at)
		out = append(out, e)
	}
	return out, rows.Err()
}

// AuditFacetten liefert die vorkommenden Akteure und Aktionsfamilien.
//
// Sie füllen die Auswahlfelder. Ein Textfeld für den Akteur wäre eine
// Rechtschreibprüfung: Wer sich vertippt, bekommt „keine Treffer" und schließt
// daraus, dass nichts geschehen ist. Die Aktionsfamilie ist der Teil vor dem
// ersten Punkt — "files", "service", "package" —, weil danach die einzelne
// Handlung steht und niemand nach "files.chmod" allein sucht.
func (db *DB) AuditFacetten(ctx context.Context) (actors, families []string, err error) {
	actors, err = db.spalteLesen(ctx, `SELECT DISTINCT actor FROM audit_log ORDER BY actor`)
	if err != nil {
		return nil, nil, err
	}
	// Der Teil vor dem ersten Punkt. In SQL, damit nicht alle Aktionen der
	// Geschichte durch den Prozess wandern, nur um gekürzt zu werden.
	families, err = db.spalteLesen(ctx, `
		SELECT DISTINCT
			CASE WHEN instr(action, '.') > 0
			     THEN substr(action, 1, instr(action, '.') - 1)
			     ELSE action END AS familie
		FROM audit_log ORDER BY familie`)
	if err != nil {
		return nil, nil, err
	}
	return actors, families, nil
}

func (db *DB) spalteLesen(ctx context.Context, abfrage string) ([]string, error) {
	rows, err := db.sql.QueryContext(ctx, abfrage)
	if err != nil {
		return nil, fmt.Errorf("audit-facetten: %w", err)
	}
	defer func() { _ = rows.Close() }()

	out := []string{}
	for rows.Next() {
		var s string
		if err := rows.Scan(&s); err != nil {
			return nil, err
		}
		if s != "" {
			out = append(out, s)
		}
	}
	return out, rows.Err()
}

// likePraefix und likeEnthaelt bauen LIKE-Muster und maskieren dabei die
// Platzhalter des Musters selbst.
//
// Ohne das Maskieren wäre ein Unterstrich im Suchbegriff ein Joker für ein
// beliebiges Zeichen — "sshd_config" fände auch "sshdXconfig". Das ist kein
// Sicherheitsproblem (der Wert bleibt ein Parameter, keine Zeichenkette im SQL),
// aber ein falsches Ergebnis.
func likePraefix(s string) string { return likeMaskieren(s) + "%" }

func likeEnthaelt(s string) string { return "%" + likeMaskieren(s) + "%" }

func likeMaskieren(s string) string {
	s = strings.ReplaceAll(s, `\`, `\\`)
	s = strings.ReplaceAll(s, "%", `\%`)
	return strings.ReplaceAll(s, "_", `\_`)
}
