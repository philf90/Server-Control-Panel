package privops

import (
	"strings"
	"testing"
)

// TestValidateCronName: Der Name wird zum Dateinamen unter /etc/cron.d. Der
// wichtigste abzuweisende Fall ist der Punkt — cron überspringt Dateien mit
// Punkt im Namen stillschweigend, und ein Eintrag „sicherung.sh" stünde da und
// liefe nie.
func TestValidateCronName(t *testing.T) {
	gut := []string{"ab", "sicherung", "srv-nacht", "log_rotation", "db2",
		strings.Repeat("a", 64)}
	for _, name := range gut {
		if err := ValidateCronName(name); err != nil {
			t.Errorf("ValidateCronName(%q) = %v", name, err)
		}
	}

	schlecht := map[string]string{
		"":                      "leer",
		"a":                     "ein Zeichen",
		"sicherung.sh":          "Punkt — cron überspringt die Datei",
		"Sicherung":             "Großbuchstabe",
		"../../etc/cron.d/boes": "Pfadanteil",
		"srv/nacht":             "Schrägstrich",
		"-fuehrend":             "führender Bindestrich",
		"_fuehrend":             "führender Unterstrich",
		"mit leerzeichen":       "Leerzeichen",
		strings.Repeat("a", 65): "zu lang",
		"nacht\x00":             "Nullbyte",
		"sicherung~":            "Tilde — Sicherungsdatei der Paketverwaltung",
	}
	for name, warum := range schlecht {
		if err := ValidateCronName(name); err == nil {
			t.Errorf("ValidateCronName(%q) angenommen (%s)", name, warum)
		}
	}
}

// TestValidateSchedule prüft gegen die Wertebereiche und nicht nur gegen die
// Zeichenmenge. Der Grund ist, wie cron mit einem Fehler umgeht: „bad minute"
// ins Journal, Datei übersprungen. Der Eintrag stünde da, sähe richtig aus und
// liefe nie — die schlechteste Sorte Fehler.
func TestValidateSchedule(t *testing.T) {
	gut := []string{
		"* * * * *",
		"17 3 * * *",
		"0 0 1 1 0",
		"59 23 31 12 7", // die Obergrenzen, Wochentag 7 ist Sonntag wie 0
		"*/5 * * * *",
		"0-30/2 * * * *",
		"0 8-17 * * 1-5",
		"0 9 * * mon-fri",
		"0 9 * jan,jul *",
		"0 9 1,15 * *",
		"@reboot", "@daily", "@DAILY", "@midnight", "@hourly", "@weekly",
		"@monthly", "@yearly", "@annually",
		"  17 3 * * *  ", // Leerraum an den Rändern
	}
	for _, plan := range gut {
		if err := ValidateSchedule(plan); err != nil {
			t.Errorf("ValidateSchedule(%q) = %v", plan, err)
		}
	}

	schlecht := map[string]string{
		"":                 "leer",
		"17 3 * *":         "vier Felder",
		"17 3 * * * *":     "sechs Felder — Sekunden kann cron nicht",
		"60 3 * * *":       "Minute 60",
		"17 24 * * *":      "Stunde 24",
		"17 3 0 * *":       "Tag 0 — Monatstage beginnen bei 1",
		"17 3 32 * *":      "Tag 32",
		"17 3 * 13 *":      "Monat 13",
		"17 3 * * 8":       "Wochentag 8",
		"5-1 3 * * *":      "rückwärts laufender Bereich — der Eintrag käme nie dran",
		"17 3 * * mon-sun": "rückwärts: Montag(1) bis Sonntag(0)",
		"*/0 * * * *":      "Schrittweite 0",
		"*/99 * * * *":     "Schrittweite über dem Feldmaximum",
		"1,,5 3 * * *":     "leerer Listeneintrag",
		"jan 3 * * *":      "Monatsname im Minutenfeld",
		"mon 3 * * *":      "Wochentagsname im Minutenfeld",
		"17 3 * * xyz":     "erfundener Name",
		"17 3 * * *;ls":    "Semikolon im Zeitfeld",
		"@immerdann":       "erfundenes Sonderwort",
		"@":                "nur das Zeichen",
	}
	for plan, warum := range schlecht {
		if err := ValidateSchedule(plan); err == nil {
			t.Errorf("ValidateSchedule(%q) angenommen (%s)", plan, warum)
		}
	}
}

// TestValidateCronCommandLaesstShellZeichenDurch ist die Zusage, die dieses
// Modul von allen anderen unterscheidet: Ein Cron-Eintrag IST eine Shell-Zeile.
// Semikolon, Pipe, Backtick und Umleitung zu verbieten gäbe eine Sicherheit vor,
// die es nicht gibt — cron gibt die Zeile an /bin/sh, und die Schranke davor ist
// die Rolle und die Rückfrage, nicht ein Zeichenfilter.
func TestValidateCronCommandLaesstShellZeichenDurch(t *testing.T) {
	gut := []string{
		"/usr/bin/true",
		"cd /srv && tar cf - . | gzip > /var/backups/srv.tgz",
		"/usr/bin/test -x /usr/sbin/anacron || run-parts /etc/cron.daily",
		"echo $(hostname); /usr/bin/logger fertig",
		"/usr/bin/find /tmp -mtime +7 -delete 2>/dev/null",
		`/usr/bin/logger "Lauf $(date +\%F)"`, // maskiertes Prozentzeichen
		strings.Repeat("a", 1024),
	}
	for _, cmd := range gut {
		if err := ValidateCronCommand(cmd); err != nil {
			t.Errorf("ValidateCronCommand(%q) = %v", cmd, err)
		}
	}
}

// TestValidateCronCommandWeistFormatbruecheAb: Geprüft wird das DATEIFORMAT. Der
// Zeilenumbruch ist der einzige echte Injektionsweg in eine Crontab — er erzeugt
// einen zweiten Eintrag, und der könnte ein eigenes Benutzerfeld tragen.
func TestValidateCronCommandWeistFormatbruecheAb(t *testing.T) {
	schlecht := map[string]string{
		"":                                      "leer",
		"   ":                                   "nur Leerraum",
		"/usr/bin/true\n0 2 * * * root /bin/sh": "Zeilenumbruch — zweiter Eintrag mit eigenem Benutzerfeld",
		"/usr/bin/true\r\n0 2 * * * root /bin/sh": "Wagenrücklauf",
		"/usr/bin/true\x00":                       "Nullbyte",
		"/usr/bin/true\x07":                       "Steuerzeichen",
		`/usr/bin/date +%F`:                       "unmaskiertes Prozentzeichen",
		strings.Repeat("a", 1025):                 "über 1024 Zeichen",
	}
	for cmd, warum := range schlecht {
		if err := ValidateCronCommand(cmd); err == nil {
			t.Errorf("ValidateCronCommand(%q) angenommen (%s)", cmd, warum)
		}
	}

	// Die Meldung zum Prozentzeichen muss die Lösung nennen. „Unzulässiges
	// Zeichen" hilft niemandem: Das Zeichen ist zulässig, es muss maskiert werden.
	err := ValidateCronCommand(`/usr/bin/date +%F`)
	if err == nil || !strings.Contains(err.Error(), `\%`) {
		t.Errorf("die Meldung nennt die Lösung nicht: %v", err)
	}
}

// TestValidateCronComment: Die Beschreibung ist strenger als eine Kommentarzeile
// sein müsste und lockerer als ein Befehl. Ein Prozentzeichen darf bleiben — in
// einer Kommentarzeile ist es ein Prozentzeichen.
func TestValidateCronComment(t *testing.T) {
	gut := []string{
		"",
		"Nachtsicherung nach /srv",
		"Warnt, wenn die Platte über 90 % voll ist",
		"Läuft; danach kommt der Bericht",
		// Ein anhängender Wagenrücklauf wird beschnitten und ist kein Fehler.
		// CronWrite schreibt das Beschnittene — siehe
		// TestCronWriteSchreibtDasGepruefte.
		"harmlos\r",
		strings.Repeat("a", 200),
	}
	for _, k := range gut {
		if err := ValidateCronComment(k); err != nil {
			t.Errorf("ValidateCronComment(%q) = %v", k, err)
		}
	}

	schlecht := map[string]string{
		"harmlos\n0 2 * * * root /bin/sh": "Zeilenumbruch — die zweite Zeile wäre ein Eintrag",
		"harmlos\x00":                     "Nullbyte",
		strings.Repeat("a", 201):          "über 200 Zeichen",
	}
	for k, warum := range schlecht {
		if err := ValidateCronComment(k); err == nil {
			t.Errorf("ValidateCronComment(%q) angenommen (%s)", k, warum)
		}
	}
}
