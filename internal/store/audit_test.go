package store

import (
	"context"
	"strings"
	"testing"
)

// TestAuditFeldMachtSteuerzeichenSichtbar hält die Zusage fest, die seit dem
// Dateimanager gebraucht wird: In diesem Feld landen freie Pfade, und ein Pfad
// darf einen Zeilenumbruch enthalten.
//
// Das Log liegt heute in SQLite, wo eine Spalte das verträgt. Für das in der
// Roadmap vorgesehene zeilenweise Protokoll unter /var/log/asylum/audit.log
// wären aus einem Eintrag zwei geworden — der zweite frei erfunden.
func TestAuditFeldMachtSteuerzeichenSichtbar(t *testing.T) {
	faelle := map[string]string{
		"/etc/hosts":               "/etc/hosts",
		"harmlos\ngefälscht":       `harmlos\ngefälscht`,
		"mit\rWagenrücklauf":       `mit\rWagenrücklauf`,
		"mit\tTabulator":           `mit\tTabulator`,
		"mit\x00Null":              `mit\x00Null`,
		"mit\x1bEscape":            `mit\x1bEscape`,
		"rechnung\u202egpj.exe":    `rechnung\u202egpj.exe`,
		"\u2066irreführend\u2069":  `\u2066irreführend\u2069`,
		"Umlaute bleiben: äöüß 日本": "Umlaute bleiben: äöüß 日本",
	}
	for ein, erwartet := range faelle {
		if got := auditFeld(ein); got != erwartet {
			t.Errorf("auditFeld(%q) = %q, erwartet %q", ein, got, erwartet)
		}
	}
}

// TestAuditFeldBegrenztDieLaenge: Ein Pfad darf 4096 Zeichen lang sein. Als
// Zeile in einer Übersicht ist er dann kein Eintrag mehr, sondern eine Wand.
func TestAuditFeldBegrenztDieLaenge(t *testing.T) {
	got := auditFeld(strings.Repeat("a", 5000))
	if len(got) > maxAuditFeld+len("…") {
		t.Errorf("Länge %d, erwartet höchstens %d", len(got), maxAuditFeld+len("…"))
	}
	if !strings.HasSuffix(got, "…") {
		t.Error("die Kürzung ist nicht erkennbar")
	}
}

// TestAppendAuditSaeubertAlleFelder: Nicht nur das Ziel — auch Akteur, Aktion
// und Detail kommen aus Eingaben.
func TestAppendAuditSaeubertAlleFelder(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	if err := db.AppendAudit(ctx, AuditEntry{
		Actor:  "phil\nipp",
		Action: "files.delete\n",
		Target: "/srv/daten\n2026-01-01\troot\tfiles.delete\t/\tok",
		Result: ResultOK,
		IP:     "10.0.0.1\r",
		Detail: "ein\nzwei",
	}); err != nil {
		t.Fatalf("AppendAudit: %v", err)
	}

	eintraege, err := db.ListAudit(ctx, 5)
	if err != nil {
		t.Fatal(err)
	}
	if len(eintraege) == 0 {
		t.Fatal("kein Eintrag geschrieben")
	}
	e := eintraege[0]
	for name, wert := range map[string]string{
		"Actor": e.Actor, "Action": e.Action, "Target": e.Target,
		"IP": e.IP, "Detail": e.Detail,
	} {
		if strings.ContainsAny(wert, "\n\r\t\x00") {
			t.Errorf("%s enthält ein Steuerzeichen: %q", name, wert)
		}
	}
	// Der Inhalt ist trotzdem noch nachvollziehbar.
	if !strings.Contains(e.Target, `\n`) {
		t.Errorf("der Zeilenumbruch ist nicht als Escape-Folge erhalten: %q", e.Target)
	}
}
