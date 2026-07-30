package store

// Tests für den Filter des Revisionsprotokolls.
//
// Sie stehen hier und nicht nur in internal/httpd, obwohl die Fläche dort
// geprüft ist: Die Fälle, um die es geht, sind Eigenschaften der ABFRAGE und
// nicht der Anzeige — und zwei davon sind still, das heißt, sie liefern ein
// falsches Ergebnis statt eines Fehlers:
//
//  1. **Die LIKE-Maskierung.** Ein Unterstrich im Suchbegriff ist im Muster ein
//     Joker für ein beliebiges Zeichen: „sshd_config" fände auch „sshdXconfig".
//     Kein Sicherheitsproblem (der Wert bleibt ein Parameter), aber ein falsches
//     Ergebnis, das niemandem auffällt.
//  2. **Die Blätterung nach ID.** Sie ist der Grund, warum hier kein OFFSET
//     steht: Das Protokoll wächst, während man darin liest. Dass die Grenze
//     strikt ist (`id <`) und keinen Eintrag doppelt oder gar nicht liefert, ist
//     eine Zusage an ein Protokoll, in dem man blättert, um etwas NICHT zu
//     übersehen.

import (
	"context"
	"strings"
	"testing"
)

// lege schreibt einen Eintrag und gibt seine Kennung zurück.
func legeAudit(t *testing.T, db *DB, actor, action, target, result, detail string) int64 {
	t.Helper()
	if err := db.AppendAudit(context.Background(), AuditEntry{
		Actor: actor, Action: action, Target: target, Result: result, Detail: detail,
	}); err != nil {
		t.Fatal(err)
	}
	var id int64
	if err := db.sql.QueryRow(`SELECT MAX(id) FROM audit_log`).Scan(&id); err != nil {
		t.Fatal(err)
	}
	return id
}

func namen(eintraege []AuditEntry) []string {
	out := make([]string, 0, len(eintraege))
	for _, e := range eintraege {
		out = append(out, e.Target)
	}
	return out
}

func TestFilterAuditNachAkteurUndErgebnis(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	legeAudit(t, db, "philipp", "files.delete", "/tmp/eins", ResultOK, "")
	legeAudit(t, db, "philipp", "service.stop", "nginx", ResultDenied, "Schreibrecht fehlt")
	legeAudit(t, db, "gehilfe", "files.delete", "/tmp/zwei", ResultOK, "")

	// Akteur.
	got, err := db.FilterAudit(ctx, AuditFilter{Actor: "philipp"})
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 2 {
		t.Errorf("%d Einträge für philipp, erwartet 2: %v", len(got), namen(got))
	}
	// Absteigend nach Kennung: das Neueste zuerst. Wer ein Protokoll öffnet, will
	// wissen, was gerade geschehen ist.
	if len(got) == 2 && got[0].Target != "nginx" {
		t.Errorf("erster Eintrag ist %q, erwartet den neuesten (nginx)", got[0].Target)
	}

	// Ergebnis.
	got, err = db.FilterAudit(ctx, AuditFilter{Result: ResultDenied})
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 1 || got[0].Target != "nginx" {
		t.Errorf("Filter auf denied = %v, erwartet nur nginx", namen(got))
	}

	// Ein Ergebnis, das es nicht gibt, findet nichts — und ist kein Fehler.
	got, err = db.FilterAudit(ctx, AuditFilter{Result: "vielleicht"})
	if err != nil {
		t.Fatalf("ein unbekanntes Ergebnis ist ein Fehler geworden: %v", err)
	}
	if len(got) != 0 {
		t.Errorf("%d Einträge für ein unmögliches Ergebnis", len(got))
	}
}

// Die Aktion ist ein PRÄFIX: „files." ist die Frage, die man tatsächlich stellt —
// alles, was am Dateimanager geschah.
func TestFilterAuditAktionIstPraefix(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	legeAudit(t, db, "philipp", "files.delete", "/tmp/eins", ResultOK, "")
	legeAudit(t, db, "philipp", "files.chmod", "/tmp/zwei", ResultOK, "")
	legeAudit(t, db, "philipp", "service.stop", "nginx", ResultOK, "")

	got, err := db.FilterAudit(ctx, AuditFilter{Action: "files."})
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 2 {
		t.Errorf("%d Einträge für „files.\", erwartet 2: %v", len(got), namen(got))
	}
	// Und die einzelne Aktion geht auch.
	got, err = db.FilterAudit(ctx, AuditFilter{Action: "files.chmod"})
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 1 || got[0].Action != "files.chmod" {
		t.Errorf("Filter auf files.chmod = %v", namen(got))
	}
}

// Die freie Suche greift in Ziel UND Einzelheiten: Dort stehen die Pfade und die
// Ausgaben, und wer nach einer Fehlermeldung sucht, sucht im Detail.
func TestFilterAuditSucheInZielUndDetail(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	legeAudit(t, db, "philipp", "files.delete", "/etc/nginx/sites", ResultOK, "")
	legeAudit(t, db, "philipp", "service.stop", "ssh", ResultError, "Unit nicht gefunden")
	legeAudit(t, db, "philipp", "package.upgrade", "libssl3", ResultOK, "")

	got, err := db.FilterAudit(ctx, AuditFilter{Query: "nginx"})
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 1 || got[0].Target != "/etc/nginx/sites" {
		t.Errorf("Suche im Ziel = %v", namen(got))
	}

	got, err = db.FilterAudit(ctx, AuditFilter{Query: "nicht gefunden"})
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 1 || got[0].Target != "ssh" {
		t.Errorf("Suche im Detail = %v", namen(got))
	}
}

// Der stille Fall: Ein Unterstrich im Suchbegriff darf kein Joker sein.
//
// Ohne Maskierung fände „sshd_config" auch „sshdXconfig" — kein
// Sicherheitsproblem, aber ein falsches Ergebnis, und ein falsches Ergebnis in
// einem Revisionsprotokoll ist schlimmer als eine Fehlermeldung.
func TestFilterAuditMaskiertPlatzhalter(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	legeAudit(t, db, "philipp", "files.save", "/etc/ssh/sshd_config", ResultOK, "")
	legeAudit(t, db, "philipp", "files.save", "/etc/ssh/sshdXconfig", ResultOK, "")
	legeAudit(t, db, "philipp", "files.save", "/tmp/100%fertig", ResultOK, "")
	legeAudit(t, db, "philipp", "files.save", "/tmp/100beliebigfertig", ResultOK, "")
	legeAudit(t, db, "philipp", "files.save", `/tmp/rueck\waerts`, ResultOK, "")

	faelle := []struct {
		suche    string
		erwartet string
	}{
		// Der Unterstrich ist ein Zeichen und kein Joker.
		{"sshd_config", "/etc/ssh/sshd_config"},
		// Das Prozentzeichen auch.
		{"100%fertig", "/tmp/100%fertig"},
		// Und der Rückstrich, mit dem maskiert wird, findet sich selbst.
		{`rueck\waerts`, `/tmp/rueck\waerts`},
	}
	for _, f := range faelle {
		got, err := db.FilterAudit(ctx, AuditFilter{Query: f.suche})
		if err != nil {
			t.Fatalf("Suche nach %q: %v", f.suche, err)
		}
		if len(got) != 1 {
			t.Errorf("Suche nach %q findet %v, erwartet nur %q — ein Platzhalter im "+
				"Suchbegriff ist als Joker durchgekommen", f.suche, namen(got), f.erwartet)
			continue
		}
		if got[0].Target != f.erwartet {
			t.Errorf("Suche nach %q findet %q, erwartet %q", f.suche, got[0].Target, f.erwartet)
		}
	}

	// Die Gegenprobe zur Gegenprobe: Ein absichtlicher Joker ist keiner. Wer „%"
	// eingibt, sucht das Zeichen und bekommt nicht alles.
	got, err := db.FilterAudit(ctx, AuditFilter{Query: "%"})
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 1 || got[0].Target != "/tmp/100%fertig" {
		t.Errorf("die Suche nach „%%\" findet %v, erwartet nur den Eintrag mit dem "+
			"Zeichen darin", namen(got))
	}

	// Dasselbe für den Präfixfilter auf der Aktion.
	legeAudit(t, db, "philipp", "files_delete", "unterstrich", ResultOK, "")
	got, err = db.FilterAudit(ctx, AuditFilter{Action: "files_"})
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 1 || got[0].Target != "unterstrich" {
		t.Errorf("Präfixfilter „files_\" findet %v — der Unterstrich war ein Joker "+
			"und hat die files.*-Einträge mitgenommen", namen(got))
	}
}

// Die Blätterung nach ID: strikt kleiner, kein Eintrag doppelt und keiner
// übersprungen — auch wenn während des Blätterns geschrieben wird. Genau das ist
// der Grund, warum hier kein OFFSET steht.
func TestFilterAuditBlaetterungNachID(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	var ids []int64
	for i := range 5 {
		ids = append(ids, legeAudit(t, db, "philipp", "files.save",
			string(rune('a'+i)), ResultOK, ""))
	}

	erste, err := db.FilterAudit(ctx, AuditFilter{Limit: 2})
	if err != nil {
		t.Fatal(err)
	}
	if len(erste) != 2 || erste[0].ID != ids[4] || erste[1].ID != ids[3] {
		t.Fatalf("erste Seite = %v, erwartet die beiden neuesten", namen(erste))
	}

	// Zwischen den Seiten kommt ein Eintrag herein. Bei OFFSET verschöbe das die
	// Grenze; nach ID nicht.
	neu := legeAudit(t, db, "philipp", "files.save", "dazwischen", ResultOK, "")

	zweite, err := db.FilterAudit(ctx, AuditFilter{Before: erste[1].ID, Limit: 2})
	if err != nil {
		t.Fatal(err)
	}
	if len(zweite) != 2 || zweite[0].ID != ids[2] || zweite[1].ID != ids[1] {
		t.Fatalf("zweite Seite = %v, erwartet die nächsten beiden", namen(zweite))
	}
	// Der neue Eintrag steht NICHT auf der zweiten Seite: Er ist neuer als die
	// Grenze. Wer weiterblättert, arbeitet den Bestand ab, den er gesehen hat.
	for _, e := range zweite {
		if e.ID == neu {
			t.Error("ein während des Blätterns geschriebener Eintrag erscheint auf der " +
				"zweiten Seite — die Grenze ist verschoben worden")
		}
	}

	// Die Grenze ist STRIKT: Der Eintrag, dessen Kennung übergeben wurde, kommt
	// nicht noch einmal.
	for _, e := range zweite {
		if e.ID == erste[1].ID {
			t.Error("der Grenzeintrag erscheint doppelt")
		}
	}
}

// Die Obergrenze der Seitengröße ist eine Zusage: Ein Aufrufer, der 100000
// verlangt, bekommt nicht das ganze Protokoll in den Speicher gelegt.
func TestFilterAuditGrenzeDerSeitengroesse(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()
	for i := range 12 {
		legeAudit(t, db, "philipp", "files.save", string(rune('a'+i)), ResultOK, "")
	}

	for _, limit := range []int{0, -1, 100000} {
		got, err := db.FilterAudit(ctx, AuditFilter{Limit: limit})
		if err != nil {
			t.Fatalf("Limit %d: %v", limit, err)
		}
		if len(got) != 12 {
			t.Errorf("Limit %d liefert %d Einträge, erwartet alle 12 (die Vorgabe "+
				"greift, die Grenze wird nicht überschritten)", limit, len(got))
		}
	}
	// Und ein kleines Limit gilt.
	got, err := db.FilterAudit(ctx, AuditFilter{Limit: 3})
	if err != nil {
		t.Fatal(err)
	}
	if len(got) != 3 {
		t.Errorf("%d Einträge bei Limit 3", len(got))
	}
}

// Die Facetten füllen die Auswahlfelder. Ein Textfeld für den Akteur wäre eine
// Rechtschreibprüfung: Wer sich vertippt, bekommt „keine Treffer" und schließt
// daraus, dass nichts geschehen ist.
func TestAuditFacetten(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	// Ein leeres Protokoll liefert leere Listen und keinen Fehler — und nil wäre
	// hier falsch, weil die Schnittstelle daraus JSON macht.
	akteure, familien, err := db.AuditFacetten(ctx)
	if err != nil {
		t.Fatal(err)
	}
	if akteure == nil || familien == nil {
		t.Error("leere Listen sind nil statt leer")
	}
	if len(akteure) != 0 || len(familien) != 0 {
		t.Errorf("ein leeres Protokoll liefert %v / %v", akteure, familien)
	}

	legeAudit(t, db, "philipp", "files.delete", "/tmp/eins", ResultOK, "")
	legeAudit(t, db, "philipp", "files.chmod", "/tmp/zwei", ResultOK, "")
	legeAudit(t, db, "gehilfe", "service.stop", "nginx", ResultOK, "")
	// Eine Aktion OHNE Punkt: Sie ist ihre eigene Familie.
	legeAudit(t, db, "gehilfe", "logout", "gehilfe", ResultOK, "")
	// Ein Eintrag ohne Akteur — die Erneuerung im Hintergrund hat keinen.
	legeAudit(t, db, "", "tls.renew", "panel.example.test", ResultOK, "")

	akteure, familien, err = db.AuditFacetten(ctx)
	if err != nil {
		t.Fatal(err)
	}
	// Jeder Akteur genau einmal, sortiert. Der leere fällt weg: Er ist kein Konto,
	// nach dem man filtern könnte.
	if strings.Join(akteure, ",") != "gehilfe,philipp" {
		t.Errorf("Akteure = %v, erwartet [gehilfe philipp]", akteure)
	}
	// Die Familie ist der Teil vor dem ersten Punkt; „logout" ist seine eigene.
	if strings.Join(familien, ",") != "files,logout,service,tls" {
		t.Errorf("Familien = %v, erwartet [files logout service tls]", familien)
	}
}

// DeleteUser nimmt die abhängigen Zeilen mit: Sitzungen, Wiederherstellungscodes
// und Passkeys hängen mit ON DELETE CASCADE am Konto. Das Protokoll bleibt — es
// hält den Anmeldenamen als Text, weil ein gelöschtes Konto seine Spur nicht
// mitnehmen darf.
func TestDeleteUserNimmtAbhaengigesMit(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	id, err := db.CreateUser(ctx, User{
		Username: "mitarbeit", PasswordHash: "x", Role: RoleAdmin, TOTPSecret: "y",
	})
	if err != nil {
		t.Fatal(err)
	}
	if err := db.CreateSession(ctx, Session{
		ID: "sitzung-eins", UserID: id, CSRFToken: "t",
	}); err != nil {
		t.Fatal(err)
	}
	if err := db.ReplaceRecoveryCodes(ctx, id, []string{"hash-eins", "hash-zwei"}); err != nil {
		t.Fatal(err)
	}
	legeAudit(t, db, "chef", "user.delete", "mitarbeit", ResultOK, "")

	if err := db.DeleteUser(ctx, id); err != nil {
		t.Fatalf("DeleteUser: %v", err)
	}

	if _, err := db.UserByID(ctx, id); err == nil {
		t.Error("das Konto ist noch da")
	}
	// Die Sitzung ist mitgegangen.
	if _, err := db.SessionByID(ctx, "sitzung-eins"); err == nil {
		t.Error("die Sitzung des gelöschten Kontos lebt weiter — ohne den " +
			"Fremdschlüssel bliebe ein gültiges Cookie ohne Konto zurück")
	}
	// Die Codes auch.
	offen, err := db.CountUnusedRecoveryCodes(ctx, id)
	if err != nil {
		t.Fatal(err)
	}
	if offen != 0 {
		t.Errorf("%d Wiederherstellungscodes übrig", offen)
	}
	// Das Protokoll bleibt.
	eintraege, err := db.ListAudit(ctx, 10)
	if err != nil {
		t.Fatal(err)
	}
	gefunden := false
	for _, e := range eintraege {
		if e.Target == "mitarbeit" {
			gefunden = true
		}
	}
	if !gefunden {
		t.Error("der Protokolleintrag ist mit dem Konto verschwunden — ein gelöschtes " +
			"Konto darf seine Spur nicht mitnehmen")
	}

	// Ein Konto, das es nicht gibt, zu löschen ist kein Fehler: Zwei Fenster, zwei
	// Klicks, und der zweite soll keine Fehlermeldung bringen.
	if err := db.DeleteUser(ctx, 99999); err != nil {
		t.Errorf("das Löschen eines unbekannten Kontos ist ein Fehler: %v", err)
	}
}

// SetTemporaryPassword war bisher nur über die Fläche geprüft. Es setzt den
// Wechselzwang — das ist der Unterschied zu SetPassword, und er ist der Grund,
// warum es die Funktion überhaupt gibt.
func TestSetTemporaryPasswordSetztDenWechselzwang(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	id, err := db.CreateUser(ctx, User{
		Username: "neuling", PasswordHash: "alt", Role: RoleReadOnly, TOTPSecret: "y",
	})
	if err != nil {
		t.Fatal(err)
	}

	if err := db.SetTemporaryPassword(ctx, id, "einmal"); err != nil {
		t.Fatal(err)
	}
	u, err := db.UserByID(ctx, id)
	if err != nil {
		t.Fatal(err)
	}
	if u.PasswordHash != "einmal" {
		t.Errorf("Hash = %q", u.PasswordHash)
	}
	if !u.MustChangePassword {
		t.Error("der Wechselzwang ist nicht gesetzt — dann bliebe das Einmalpasswort " +
			"dauerhaft gültig")
	}

	// Und SetPassword nimmt ihn wieder weg: Wer es selbst gewählt hat, hat die
	// Bedingung erfüllt, die der Zwang stellt.
	if err := db.SetPassword(ctx, id, "selbst gewaehlt"); err != nil {
		t.Fatal(err)
	}
	u, err = db.UserByID(ctx, id)
	if err != nil {
		t.Fatal(err)
	}
	if u.MustChangePassword {
		t.Error("der Wechselzwang steht nach einem selbst gewählten Passwort noch")
	}
}

// DeleteWebAuthnCredentialsByUser ist der Weg für ein verlorenes Gerät: Der
// Kontoinhaber kann den Schlüssel nicht mehr selbst entfernen, weil er dafür das
// Gerät bräuchte. Die Anzahl kommt zurück, weil die Meldung sie nennt — „alle
// drei Passkeys entfernt" ist eine andere Auskunft als „der Passkey".
func TestDeleteWebAuthnCredentialsByUser(t *testing.T) {
	db := testDB(t)
	ctx := context.Background()

	ich, err := db.CreateUser(ctx, User{
		Username: "philipp", PasswordHash: "x", Role: RoleOwner, TOTPSecret: "y",
	})
	if err != nil {
		t.Fatal(err)
	}
	fremd, err := db.CreateUser(ctx, User{
		Username: "fremd", PasswordHash: "x", Role: RoleAdmin, TOTPSecret: "y",
	})
	if err != nil {
		t.Fatal(err)
	}

	// Ohne Passkeys ist die Antwort 0 und kein Fehler.
	n, err := db.DeleteWebAuthnCredentialsByUser(ctx, ich)
	if err != nil {
		t.Fatalf("ohne Passkeys: %v", err)
	}
	if n != 0 {
		t.Errorf("n = %d, erwartet 0", n)
	}

	for _, cred := range []string{"cred-eins", "cred-zwei"} {
		if _, err := db.AddWebAuthnCredential(ctx, WebAuthnCredential{
			UserID: ich, CredentialID: cred, Label: cred, Data: []byte(`{}`),
		}); err != nil {
			t.Fatal(err)
		}
	}
	if _, err := db.AddWebAuthnCredential(ctx, WebAuthnCredential{
		UserID: fremd, CredentialID: "cred-fremd", Label: "fremd", Data: []byte(`{}`),
	}); err != nil {
		t.Fatal(err)
	}

	n, err = db.DeleteWebAuthnCredentialsByUser(ctx, ich)
	if err != nil {
		t.Fatal(err)
	}
	if n != 2 {
		t.Errorf("n = %d, erwartet 2 — die Zahl steht in der Meldung", n)
	}
	if uebrig, err := db.CountWebAuthnCredentials(ctx, ich); err != nil || uebrig != 0 {
		t.Errorf("%d Passkeys übrig (%v)", uebrig, err)
	}
	// Das fremde Konto ist unangetastet: Die Abfrage bindet an den Benutzer.
	if uebrig, err := db.CountWebAuthnCredentials(ctx, fremd); err != nil || uebrig != 1 {
		t.Errorf("der Passkey des fremden Kontos ist mitgegangen: %d (%v)", uebrig, err)
	}
}
