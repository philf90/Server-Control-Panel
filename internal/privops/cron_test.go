package privops

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// cronVerzeichnisse legt ein Wegwerfsystem an: /etc/crontab, /etc/cron.d, die
// Spool-Crontabs und die vier run-parts-Verzeichnisse. Dieselbe Bauart wie
// withFixtures in users_test.go — die Prüfung liest und schreibt wirkliche
// Dateien, weil genau das die Arbeit dieser Familie ist. Ein Test mit einer
// Attrappe statt eines Verzeichnisses würde das atomare Schreiben, die
// Dateirechte und das Überspringen der Punktdateien nicht berühren.
func cronVerzeichnisse(t *testing.T) string {
	t.Helper()
	wurzel := t.TempDir()

	alt := struct {
		crontab, cronD, spool string
		periodisch            map[string]string
	}{crontabPath, cronDDir, cronSpoolDir, cronPeriodisch}

	crontabPath = filepath.Join(wurzel, "crontab")
	cronDDir = filepath.Join(wurzel, "cron.d")
	cronSpoolDir = filepath.Join(wurzel, "spool")
	cronPeriodisch = map[string]string{
		filepath.Join(wurzel, "cron.daily"):  "@daily",
		filepath.Join(wurzel, "cron.weekly"): "@weekly",
	}
	for _, dir := range []string{cronDDir, cronSpoolDir} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	for dir := range cronPeriodisch {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatal(err)
		}
	}

	t.Cleanup(func() {
		crontabPath, cronDDir, cronSpoolDir = alt.crontab, alt.cronD, alt.spool
		cronPeriodisch = alt.periodisch
	})
	return wurzel
}

func schreibe(t *testing.T, pfad, inhalt string) {
	t.Helper()
	if err := os.WriteFile(pfad, []byte(inhalt), 0o644); err != nil {
		t.Fatal(err)
	}
}

// finde sucht einen Eintrag am Befehl. Am Befehl und nicht am Index: Die
// Reihenfolge ist eine eigene Zusage und wird eigens geprüft — ein Test, der sie
// stillschweigend voraussetzt, scheitert dann zweimal am selben Fehler.
func finde(t *testing.T, eintraege []CronEntry, teil string) CronEntry {
	t.Helper()
	for _, e := range eintraege {
		if strings.Contains(e.Command, teil) {
			return e
		}
	}
	t.Fatalf("kein Eintrag mit %q in %d Einträgen", teil, len(eintraege))
	return CronEntry{}
}

// TestCronListLiestBeideFormate: /etc/crontab und /etc/cron.d haben ein
// Benutzerfeld, die Spool-Crontabs nicht. Wer das verwechselt, liest den
// Benutzernamen als Befehl — und zeigt dann „philipp" als Kommando an.
func TestCronListLiestBeideFormate(t *testing.T) {
	cronVerzeichnisse(t)

	schreibe(t, crontabPath, `SHELL=/bin/sh
PATH=/usr/bin:/bin
MAILTO=root

# Systemweite Wartung
17 3 * * *	root	/usr/local/sbin/wartung.sh
`)
	schreibe(t, filepath.Join(cronSpoolDir, "philipp"), `# Persönlicher Bericht
0 6 * * 1 /home/philipp/bericht.sh --wochenrueckblick
`)

	eintraege, luecken, err := neuesSystem().CronList(context.Background())
	if err != nil {
		t.Fatalf("CronList: %v", err)
	}
	if len(luecken) != 0 {
		t.Errorf("Lücken gemeldet, obwohl alles lesbar war: %v", luecken)
	}

	wartung := finde(t, eintraege, "wartung.sh")
	if wartung.User != "root" {
		t.Errorf("Benutzer = %q, erwartet root", wartung.User)
	}
	if wartung.Command != "/usr/local/sbin/wartung.sh" {
		t.Errorf("Befehl = %q", wartung.Command)
	}
	if wartung.Schedule != "17 3 * * *" {
		t.Errorf("Zeitplan = %q", wartung.Schedule)
	}
	if wartung.Kommentar != "Systemweite Wartung" {
		t.Errorf("Kommentar = %q — die Zeile über dem Eintrag fehlt", wartung.Kommentar)
	}
	if wartung.Zeile != 6 {
		t.Errorf("Zeilennummer = %d, erwartet 6", wartung.Zeile)
	}

	// Die Spool-Crontab: Der Benutzer steht im Dateinamen. Der Befehl beginnt
	// direkt nach dem fünften Feld.
	bericht := finde(t, eintraege, "bericht.sh")
	if bericht.User != "philipp" {
		t.Errorf("Benutzer = %q, erwartet philipp (Dateiname)", bericht.User)
	}
	if bericht.Command != "/home/philipp/bericht.sh --wochenrueckblick" {
		t.Errorf("Befehl = %q — das Benutzerfeld wurde fälschlich abgeschnitten", bericht.Command)
	}
}

// TestCronListZuweisungenSindKeineEintraege: SHELL=/bin/sh hat fünf Zeichen und
// keine fünf Felder — es darf nicht als Eintrag mit dem Befehl „/bin/sh"
// erscheinen.
func TestCronListZuweisungenSindKeineEintraege(t *testing.T) {
	cronVerzeichnisse(t)
	schreibe(t, crontabPath, `SHELL=/bin/sh
PATH=/usr/bin:/bin
MAILTO=""
CRON_TZ=Europe/Berlin
0 4 * * * root /usr/bin/true
`)

	eintraege, _, err := neuesSystem().CronList(context.Background())
	if err != nil {
		t.Fatalf("CronList: %v", err)
	}
	if len(eintraege) != 1 {
		for _, e := range eintraege {
			t.Logf("gelesen: %+v", e)
		}
		t.Fatalf("%d Einträge, erwartet 1", len(eintraege))
	}
}

// TestCronListAbgeschalteterEintrag: Eine auskommentierte Zeile ist ein
// abgeschalteter Eintrag und muss sichtbar bleiben. Verschwände sie, sähe
// niemand, dass da etwas war — und derselbe Zeitplan würde ein zweites Mal
// angelegt.
func TestCronListAbgeschalteterEintrag(t *testing.T) {
	cronVerzeichnisse(t)
	schreibe(t, filepath.Join(cronDDir, "asylum-bericht"), cronMarker+`
#
# Monatsbericht
#0 6 1 * *	root	/usr/local/bin/bericht.sh
`)

	eintraege, _, err := neuesSystem().CronList(context.Background())
	if err != nil {
		t.Fatalf("CronList: %v", err)
	}
	e := finde(t, eintraege, "bericht.sh")
	if !e.Deaktiviert {
		t.Error("der auskommentierte Eintrag gilt als aktiv")
	}
	if !e.Verwaltet || e.Name != "bericht" {
		t.Errorf("Verwaltet = %t, Name = %q — der Marker wurde nicht erkannt", e.Verwaltet, e.Name)
	}
	if e.Kommentar != "Monatsbericht" {
		t.Errorf("Kommentar = %q — die Beschreibung fehlt", e.Kommentar)
	}
	// Der Marker selbst ist keine Beschreibung eines Eintrags.
	if strings.Contains(e.Kommentar, "Panel verwaltet") {
		t.Error("der Verwaltungsmarker wurde als Beschreibung übernommen")
	}
}

// TestCronListUeberspringtPunktdateien: cron liest keine Datei mit Punkt im
// Namen und keine Sicherung der Paketverwaltung. Sie mitzuzählen wäre eine
// Liste von Einträgen, die nie laufen — die schlechteste Sorte Auskunft.
func TestCronListUeberspringtPunktdateien(t *testing.T) {
	cronVerzeichnisse(t)
	for _, name := range []string{
		"sicherung.dpkg-old", "sicherung~", ".versteckt", "notiz.txt",
	} {
		schreibe(t, filepath.Join(cronDDir, name), "0 1 * * * root /usr/bin/nie\n")
	}
	schreibe(t, filepath.Join(cronDDir, "echt"), "0 1 * * * root /usr/bin/doch\n")

	eintraege, luecken, err := neuesSystem().CronList(context.Background())
	if err != nil {
		t.Fatalf("CronList: %v", err)
	}
	if len(luecken) != 0 {
		t.Errorf("Lücken: %v", luecken)
	}
	if len(eintraege) != 1 {
		t.Fatalf("%d Einträge, erwartet 1 — übersprungene Dateien wurden mitgezählt", len(eintraege))
	}
	if !strings.Contains(eintraege[0].Command, "doch") {
		t.Errorf("Befehl = %q", eintraege[0].Command)
	}
}

// TestCronListRunParts: Was in /etc/cron.daily liegt, läuft — und fehlt in jeder
// Übersicht, die nur Crontab-Zeilen zeigt. Nicht ausführbare Dateien laufen
// nicht und gehören nicht in die Liste.
func TestCronListRunParts(t *testing.T) {
	wurzel := cronVerzeichnisse(t)
	taeglich := filepath.Join(wurzel, "cron.daily")

	if err := os.WriteFile(filepath.Join(taeglich, "logrotate"), []byte("#!/bin/sh\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	// Ohne Ausführungsrecht: run-parts lässt es liegen.
	schreibe(t, filepath.Join(taeglich, "notiz"), "kein Skript\n")

	eintraege, _, err := neuesSystem().CronList(context.Background())
	if err != nil {
		t.Fatalf("CronList: %v", err)
	}
	if len(eintraege) != 1 {
		for _, e := range eintraege {
			t.Logf("gelesen: %s", e.Command)
		}
		t.Fatalf("%d Einträge, erwartet 1", len(eintraege))
	}
	e := eintraege[0]
	if e.Art != "skript" {
		t.Errorf("Art = %q, erwartet skript", e.Art)
	}
	if e.Schedule != "@daily" || e.ScheduleText == "" {
		t.Errorf("Zeitplan = %q / %q", e.Schedule, e.ScheduleText)
	}
	if e.User != "root" {
		t.Errorf("Benutzer = %q — run-parts läuft aus /etc/crontab als root", e.User)
	}
}

// TestCronListLueckeWirdGenannt: Eine unlesbare Quelle darf die übrigen nicht
// verwerfen, und sie darf auch nicht verschwiegen werden. Eine unvollständige
// Liste als vollständig auszugeben wäre der Bruch von Grundsatz IV.
func TestCronListLueckeWirdGenannt(t *testing.T) {
	if os.Getuid() == 0 {
		t.Skip("als root ist keine Datei unlesbar — die Prüfung braucht ein " +
			"unprivilegiertes Konto (siehe CI)")
	}
	cronVerzeichnisse(t)
	schreibe(t, crontabPath, "0 1 * * * root /usr/bin/lesbar\n")

	geheim := filepath.Join(cronDDir, "geheim")
	schreibe(t, geheim, "0 2 * * * root /usr/bin/verborgen\n")
	if err := os.Chmod(geheim, 0o000); err != nil {
		t.Fatal(err)
	}

	eintraege, luecken, err := neuesSystem().CronList(context.Background())
	if err != nil {
		t.Fatalf("CronList: %v — eine Lücke darf die Auskunft nicht beenden", err)
	}
	if len(eintraege) != 1 {
		t.Errorf("%d Einträge, erwartet 1", len(eintraege))
	}
	if len(luecken) != 1 || !strings.Contains(luecken[0], "geheim") {
		t.Errorf("Lücken = %v — die unlesbare Quelle wird nicht genannt", luecken)
	}
}

// TestCronListFehlendeQuellenSindKeineLuecke: Auf einem System ohne cron gibt es
// /etc/cron.d nicht. Das als Fehler zu melden wäre eine Warnung ohne Anlass —
// und Warnungen ohne Anlass lehrt man sich schnell zu übersehen.
func TestCronListFehlendeQuellenSindKeineLuecke(t *testing.T) {
	cronVerzeichnisse(t)
	if err := os.RemoveAll(cronDDir); err != nil {
		t.Fatal(err)
	}
	if err := os.RemoveAll(cronSpoolDir); err != nil {
		t.Fatal(err)
	}

	eintraege, luecken, err := neuesSystem().CronList(context.Background())
	if err != nil {
		t.Fatalf("CronList: %v", err)
	}
	if len(luecken) != 0 {
		t.Errorf("Lücken = %v — fehlende Verzeichnisse sind der Normalfall", luecken)
	}
	if len(eintraege) != 0 {
		t.Errorf("%d Einträge auf einem System ohne Zeitpläne", len(eintraege))
	}
}

// TestCronListEigeneZuerst: Die verwalteten Einträge stehen oben, weil an ihnen
// jemand etwas tut. Der Rest ist Auskunft.
func TestCronListEigeneZuerst(t *testing.T) {
	cronVerzeichnisse(t)
	schreibe(t, crontabPath, "0 1 * * * root /usr/bin/fremd\n")
	schreibe(t, filepath.Join(cronDDir, "asylum-eigen"),
		cronMarker+"\n0 2 * * * root /usr/bin/eigen\n")

	eintraege, _, err := neuesSystem().CronList(context.Background())
	if err != nil {
		t.Fatalf("CronList: %v", err)
	}
	if len(eintraege) != 2 {
		t.Fatalf("%d Einträge, erwartet 2", len(eintraege))
	}
	if !eintraege[0].Verwaltet {
		t.Error("der eigene Eintrag steht nicht oben")
	}
}

// ---------------------------------------------------------------- Schreiben ---

func TestCronWriteUndDelete(t *testing.T) {
	cronVerzeichnisse(t)
	withFixtures(t) // liefert /etc/passwd mit root und philipp

	sys := neuesSystem()
	spec := CronSpec{
		Name:      "sicherung",
		Schedule:  "17 3 * * *",
		User:      "philipp",
		Command:   "/usr/local/bin/sicherung.sh --ziel /srv",
		Kommentar: "Nachtsicherung nach /srv",
		Aktiv:     true,
	}
	if err := sys.CronWrite(context.Background(), spec); err != nil {
		t.Fatalf("CronWrite: %v", err)
	}

	pfad := filepath.Join(cronDDir, "asylum-sicherung")
	roh, err := os.ReadFile(pfad)
	if err != nil {
		t.Fatalf("die Datei fehlt: %v", err)
	}
	inhalt := string(roh)
	if !strings.HasPrefix(inhalt, cronMarker) {
		t.Error("die Datei trägt den Verwaltungsmarker nicht in der ersten Zeile")
	}
	// PATH ausdrücklich: der häufigste Grund für „läuft von Hand, aber nicht über
	// cron" ist ein Programm, das im kurzen cron-PATH nicht liegt.
	if !strings.Contains(inhalt, "\nPATH=") {
		t.Error("die Datei setzt keinen PATH")
	}
	if !strings.Contains(inhalt, "Nachtsicherung nach /srv") {
		t.Error("die Beschreibung fehlt in der Datei")
	}

	// Die Rechte: cron verlangt, dass die Datei für andere nicht schreibbar ist.
	info, err := os.Stat(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm()&0o022 != 0 {
		t.Errorf("Rechte = %v — cron verweigert eine für andere schreibbare Datei", info.Mode().Perm())
	}

	// Und sie wird wieder als eigener Eintrag gelesen. Das ist der eigentliche
	// Beweis: Geschriebenes und Gelesenes passen zusammen.
	eintraege, _, err := sys.CronList(context.Background())
	if err != nil {
		t.Fatalf("CronList: %v", err)
	}
	e := finde(t, eintraege, "sicherung.sh")
	if !e.Verwaltet || e.Name != "sicherung" || e.Deaktiviert {
		t.Errorf("gelesen: %+v", e)
	}
	if e.User != "philipp" || e.Schedule != "17 3 * * *" {
		t.Errorf("Benutzer = %q, Zeitplan = %q", e.User, e.Schedule)
	}
	if e.Command != spec.Command {
		t.Errorf("Befehl = %q, geschrieben war %q", e.Command, spec.Command)
	}
	if e.Kommentar != spec.Kommentar {
		t.Errorf("Kommentar = %q", e.Kommentar)
	}

	// Abschalten schreibt die Zeile auskommentiert — der Eintrag bleibt lesbar.
	spec.Aktiv = false
	if err := sys.CronWrite(context.Background(), spec); err != nil {
		t.Fatalf("CronWrite (abgeschaltet): %v", err)
	}
	eintraege, _, err = sys.CronList(context.Background())
	if err != nil {
		t.Fatalf("CronList: %v", err)
	}
	if e := finde(t, eintraege, "sicherung.sh"); !e.Deaktiviert {
		t.Error("der abgeschaltete Eintrag gilt weiter als aktiv")
	}

	// Löschen entfernt die Datei; ein zweites Löschen ist kein Fehler.
	if err := sys.CronDelete(context.Background(), "sicherung"); err != nil {
		t.Fatalf("CronDelete: %v", err)
	}
	if _, err := os.Stat(pfad); !errors.Is(err, os.ErrNotExist) {
		t.Errorf("die Datei ist noch da: %v", err)
	}
	if err := sys.CronDelete(context.Background(), "sicherung"); err != nil {
		t.Errorf("zweites Löschen meldet einen Fehler: %v", err)
	}
}

// TestCronWriteFasstFremdeDateienNichtAn ist die Kernzusage dieser Familie: Eine
// Datei ohne Marker gehört einem Menschen oder einem Paket. Sie zu überschreiben
// wäre genau das besitzergreifende Verhalten, das das Panel überall vermeidet —
// und der Name asylum-* schützt nicht davor, dass ein Mensch ihn zuerst benutzt.
func TestCronWriteFasstFremdeDateienNichtAn(t *testing.T) {
	cronVerzeichnisse(t)
	withFixtures(t)

	pfad := filepath.Join(cronDDir, "asylum-fremd")
	fremd := "# Von Hand angelegt\n0 5 * * * root /usr/bin/wichtig\n"
	schreibe(t, pfad, fremd)

	sys := neuesSystem()
	spec := CronSpec{
		Name: "fremd", Schedule: "0 1 * * *", User: "root",
		Command: "/usr/bin/panel", Aktiv: true,
	}
	err := sys.CronWrite(context.Background(), spec)
	if err == nil {
		t.Fatal("CronWrite hat eine fremde Datei überschrieben")
	}
	if !strings.Contains(err.Error(), "Verwaltungsmarker") {
		t.Errorf("Fehlermeldung nennt den Grund nicht: %v", err)
	}

	// Auch Löschen nicht.
	if err := sys.CronDelete(context.Background(), "fremd"); err == nil {
		t.Error("CronDelete hat eine fremde Datei entfernt")
	}
	roh, err := os.ReadFile(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if string(roh) != fremd {
		t.Errorf("die fremde Datei wurde verändert:\n%s", roh)
	}
}

// TestCronWriteUnbekannterBenutzer: cron protokolliert „unknown user" und
// überspringt die Datei. Der Eintrag stünde da, sähe richtig aus und liefe nie.
func TestCronWriteUnbekannterBenutzer(t *testing.T) {
	cronVerzeichnisse(t)
	withFixtures(t)

	err := neuesSystem().CronWrite(context.Background(), CronSpec{
		Name: "test", Schedule: "0 1 * * *", User: "gibtesnicht",
		Command: "/usr/bin/true", Aktiv: true,
	})
	if err == nil {
		t.Fatal("ein Eintrag für einen unbekannten Benutzer wurde angelegt")
	}
	if !strings.Contains(err.Error(), "existiert nicht") {
		t.Errorf("Fehlermeldung: %v", err)
	}
}

// TestCronWriteWeistFehlerhafteVorgabenAb prüft die Reihenfolge der Riegel: Was
// hier durchkäme, stünde in einer Crontab.
func TestCronWriteWeistFehlerhafteVorgabenAb(t *testing.T) {
	cronVerzeichnisse(t)
	withFixtures(t)
	sys := neuesSystem()

	faelle := map[string]CronSpec{
		"Name mit Punkt": {Name: "si.cherung", Schedule: "0 1 * * *", User: "root", Command: "/usr/bin/true"},
		"Name mit Schrägstrich": {Name: "../../etc/cron.d/boes", Schedule: "0 1 * * *",
			User: "root", Command: "/usr/bin/true"},
		"Zeitplan mit vier Feldern": {Name: "test", Schedule: "0 1 * *", User: "root", Command: "/usr/bin/true"},
		"Minute 60":                 {Name: "test", Schedule: "60 1 * * *", User: "root", Command: "/usr/bin/true"},
		"Zeilenumbruch im Befehl": {Name: "test", Schedule: "0 1 * * *", User: "root",
			Command: "/usr/bin/true\n0 2 * * * root /usr/bin/boes"},
		"leerer Befehl": {Name: "test", Schedule: "0 1 * * *", User: "root", Command: "   "},
		"Zeilenumbruch in der Beschreibung": {Name: "test", Schedule: "0 1 * * *", User: "root",
			Command: "/usr/bin/true", Kommentar: "harmlos\n0 2 * * * root /usr/bin/boes"},
	}
	for name, spec := range faelle {
		if err := sys.CronWrite(context.Background(), spec); err == nil {
			t.Errorf("%s wurde angenommen", name)
		}
	}

	// Gegenprobe: Nichts davon hat eine Datei hinterlassen — auch keine
	// temporäre.
	namen, err := os.ReadDir(cronDDir)
	if err != nil {
		t.Fatal(err)
	}
	if len(namen) != 0 {
		for _, e := range namen {
			t.Logf("übrig: %s", e.Name())
		}
		t.Errorf("%d Dateien nach ausschließlich abgewiesenen Vorgaben", len(namen))
	}
}

// TestCronWriteProzentzeichenWirdErklaert: In einer Crontab beendet ein
// unmaskiertes % den Befehl, alles danach wird dem Programm als Eingabe
// zugeführt. `date +%d` läuft dann anders als von Hand — und niemand sucht den
// Fehler an dieser Stelle. Deshalb weist die Prüfung darauf hin, statt es
// stillschweigend zu ändern.
func TestCronWriteProzentzeichenWirdErklaert(t *testing.T) {
	cronVerzeichnisse(t)
	withFixtures(t)
	sys := neuesSystem()

	err := sys.CronWrite(context.Background(), CronSpec{
		Name: "stempel", Schedule: "0 1 * * *", User: "root",
		Command: `/usr/bin/logger "Lauf $(date +%F)"`, Aktiv: true,
	})
	if err == nil {
		t.Fatal("das unmaskierte Prozentzeichen wurde angenommen")
	}
	if !strings.Contains(err.Error(), `\%`) {
		t.Errorf("die Meldung nennt die Lösung nicht: %v", err)
	}

	// Maskiert geht es durch: Die Prüfung verbietet kein Prozentzeichen, sie
	// verlangt die Maskierung.
	if err := sys.CronWrite(context.Background(), CronSpec{
		Name: "stempel", Schedule: "0 1 * * *", User: "root",
		Command: `/usr/bin/logger "Lauf $(date +\%F)"`, Aktiv: true,
	}); err != nil {
		t.Errorf("der maskierte Befehl wurde abgewiesen: %v", err)
	}
}

// TestCronWriteErlaubtShellZeichen: Semikolon, Pipe und Umleitung gehören zu
// einer Shell-Zeile. Sie zu verbieten gäbe eine Sicherheit vor, die es nicht
// gibt — cron gibt die Zeile an /bin/sh, und wer einen Eintrag anlegen darf,
// führt Code aus. Die Schranke davor ist die Rolle und die Rückfrage.
func TestCronWriteErlaubtShellZeichen(t *testing.T) {
	cronVerzeichnisse(t)
	withFixtures(t)

	befehl := "cd /srv && tar cf - . | gzip > /var/backups/srv.tgz 2>/dev/null; echo fertig"
	if err := neuesSystem().CronWrite(context.Background(), CronSpec{
		Name: "sicherung", Schedule: "17 3 * * *", User: "root",
		Command: befehl, Aktiv: true,
	}); err != nil {
		t.Fatalf("CronWrite: %v", err)
	}

	eintraege, _, err := neuesSystem().CronList(context.Background())
	if err != nil {
		t.Fatalf("CronList: %v", err)
	}
	if e := finde(t, eintraege, "tar cf"); e.Command != befehl {
		t.Errorf("Befehl = %q\nerwartet   %q", e.Command, befehl)
	}
}

// TestCronWriteSchreibtDasGepruefte: Die Prüfung beschneidet den Befehl
// (TrimSpace), und wenn CronWrite dann die ungekürzte Vorgabe schreibt, stünde
// in der Crontab etwas anderes als das Geprüfte — ein anhängender
// Wagenrücklauf zum Beispiel, der die Prüfung passiert, weil sie ihn wegschneidet.
func TestCronWriteSchreibtDasGepruefte(t *testing.T) {
	cronVerzeichnisse(t)
	withFixtures(t)

	if err := neuesSystem().CronWrite(context.Background(), CronSpec{
		Name: " test ", Schedule: "  0   1 * * *  ", User: " root ",
		Command: "  /usr/bin/true\r", Kommentar: "  Beschreibung  ", Aktiv: true,
	}); err != nil {
		t.Fatalf("CronWrite: %v", err)
	}

	roh, err := os.ReadFile(filepath.Join(cronDDir, "asylum-test"))
	if err != nil {
		t.Fatalf("die Datei fehlt: %v", err)
	}
	if strings.Contains(string(roh), "\r") {
		t.Errorf("der Wagenrücklauf steht in der Datei:\n%q", roh)
	}
	if !strings.Contains(string(roh), "0 1 * * *\troot\t/usr/bin/true\n") {
		t.Errorf("die Zeile ist nicht die geprüfte:\n%s", roh)
	}

	// Und der Eintrag wird wieder gefunden — der Name war beschnitten.
	eintraege, _, err := neuesSystem().CronList(context.Background())
	if err != nil {
		t.Fatalf("CronList: %v", err)
	}
	if e := finde(t, eintraege, "/usr/bin/true"); e.Name != "test" {
		t.Errorf("Name = %q, erwartet test", e.Name)
	}
}

// TestCronWriteLegtVerzeichnisAn: Auf einem System ohne cron.d muss das
// Anlegen trotzdem gehen — sonst hängt das Modul davon ab, dass vorher ein Paket
// das Verzeichnis erzeugt hat.
func TestCronWriteLegtVerzeichnisAn(t *testing.T) {
	cronVerzeichnisse(t)
	withFixtures(t)
	if err := os.RemoveAll(cronDDir); err != nil {
		t.Fatal(err)
	}

	if err := neuesSystem().CronWrite(context.Background(), CronSpec{
		Name: "test", Schedule: "0 1 * * *", User: "root",
		Command: "/usr/bin/true", Aktiv: true,
	}); err != nil {
		t.Fatalf("CronWrite: %v", err)
	}
	if _, err := os.Stat(filepath.Join(cronDDir, "asylum-test")); err != nil {
		t.Errorf("die Datei fehlt: %v", err)
	}
}

// TestCronWriteHinterlaesstKeineTemporaereDatei: Geschrieben wird über eine
// Punktdatei und umbenannt — cron überspringt Punktdateien, also läuft nichts
// an, während sie einen Augenblick dort liegt. Bleibt eine liegen, hätte das
// Verzeichnis mit jedem Speichern eine Datei mehr.
func TestCronWriteHinterlaesstKeineTemporaereDatei(t *testing.T) {
	cronVerzeichnisse(t)
	withFixtures(t)
	sys := neuesSystem()

	for range 3 {
		if err := sys.CronWrite(context.Background(), CronSpec{
			Name: "test", Schedule: "0 1 * * *", User: "root",
			Command: "/usr/bin/true", Aktiv: true,
		}); err != nil {
			t.Fatalf("CronWrite: %v", err)
		}
	}

	namen, err := os.ReadDir(cronDDir)
	if err != nil {
		t.Fatal(err)
	}
	if len(namen) != 1 || namen[0].Name() != "asylum-test" {
		for _, e := range namen {
			t.Logf("vorhanden: %s", e.Name())
		}
		t.Errorf("%d Dateien nach dreimaligem Speichern, erwartet 1", len(namen))
	}
}

// neuesSystem baut ein System für die Cron-Prüfungen. Der Läufer ist eine
// Attrappe und wird nicht gebraucht: Cron-Zeitpläne sind Dateien, und diese
// Familie ruft kein einziges Kommando auf. Genau das ist der Grund, warum sie so
// gebaut ist — os.ReadFile ist die geradere Auskunft als `crontab -l` je
// Benutzer.
func neuesSystem() *System {
	return NewSystemWithRunner(newFakeRunner())
}
