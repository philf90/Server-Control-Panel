package privops

// Cron: die Zeitpläne des Systems lesen und eigene schreiben.
//
// Diese Familie ist anders als alle übrigen in diesem Paket, und der Unterschied
// gehört an den Anfang: **Ein Cron-Eintrag ist ein Shell-Kommando.** cron gibt
// die Zeile an /bin/sh, und wer einen Eintrag anlegen darf, führt Code als den
// eingetragenen Benutzer aus. Das ist keine Aufweichung des Verzichts auf eine
// Shell (siehe privops.go), sondern das Wesen von cron — und es lässt sich nicht
// wegtypisieren. Was den Weg trotzdem eng hält, sind vier Dinge:
//
//  1. **Geschrieben wird nur in eigene Dateien.** Ein verwalteter Eintrag ist
//     eine Datei /etc/cron.d/asylum-<name> mit einem Marker in der ersten Zeile.
//     Fremde Crontabs — /etc/crontab, fremde Dateien in /etc/cron.d, die
//     Spool-Crontabs der Benutzer — werden gelesen und nie geschrieben. Das ist
//     das nicht-besitzergreifende Verhalten, das das Panel überall einhält: Was
//     ein Mensch oder ein Paket dort eingetragen hat, bleibt unangetastet.
//  2. **Eine Datei, ein Eintrag.** Kein Anfügen an eine bestehende Datei, kein
//     Editieren einer Zeile in einer Datei mit mehreren. Damit ist Löschen ein
//     Entfernen der Datei und kann keine fremde Zeile mitnehmen.
//  3. **Das Dateiformat ist geprüft, nicht der Befehlsinhalt.** In einer Crontab
//     ist der Zeilenumbruch der Injektionsweg — er erzeugt einen zweiten Eintrag
//     mit eigenem Benutzerfeld. Semikolon und Backtick sind dagegen gewöhnliche
//     Shell-Zeichen; sie zu verbieten gäbe eine Sicherheit vor, die es nicht
//     gibt. Siehe ValidateCronCommand.
//  4. **Der Benutzer wird geprüft**, bevor er in das Feld kommt: Ein Eintrag für
//     einen Benutzer, den es nicht gibt, läuft nie — cron protokolliert es und
//     überspringt die Datei.
//
// Gelesen wird ohne ein einziges Kommando: Cron-Zeitpläne sind Dateien, und
// os.ReadFile ist die geradere Auskunft als `crontab -l` je Benutzer. Die
// Timer-Seite dieses Moduls braucht systemctl und steht in timer.go.

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
)

// Die Orte, an denen Zeitpläne stehen. Als Variablen und nicht als Konstanten,
// damit die Tests sie auf ein Wegwerfverzeichnis umlegen können — dasselbe
// Muster wie passwdPath in users.go.
var (
	crontabPath  = "/etc/crontab"
	cronDDir     = "/etc/cron.d"
	cronSpoolDir = "/var/spool/cron/crontabs"
	// Die run-parts-Verzeichnisse. Sie tragen Skripte statt Zeilen, und was
	// darin liegt, läuft — das gehört in die Übersicht, auch wenn das Panel es
	// nicht verwaltet.
	cronPeriodisch = map[string]string{
		"/etc/cron.hourly":  "@hourly",
		"/etc/cron.daily":   "@daily",
		"/etc/cron.weekly":  "@weekly",
		"/etc/cron.monthly": "@monthly",
	}
)

// cronMarker steht in der ersten Zeile jeder vom Panel geschriebenen Datei.
//
// Er ist die einzige Auskunft darüber, ob eine Datei dem Panel gehört. Ohne ihn
// müsste die Zugehörigkeit am Dateinamen hängen, und ein Mensch, der eine Datei
// asylum-backup nennt, hätte sie damit dem Panel überschrieben.
const cronMarker = "# Vom Panel verwaltet — Modul Cron & Timer."

// cronDateiPraefix ist der Namensanfang der verwalteten Dateien.
const cronDateiPraefix = "asylum-"

// CronEntry ist ein Zeitplan, wie ihn die Übersicht zeigt.
type CronEntry struct {
	// Quelle ist die Datei, in der der Eintrag steht — immer genannt, weil das
	// Panel nichts versteckt und weil sie der Weg ist, ihn von Hand zu ändern.
	Quelle string `json:"quelle"`
	// Zeile ist die Zeilennummer in der Quelle, 1-basiert. 0 bei einem Skript in
	// einem run-parts-Verzeichnis: Dort ist die Datei der Eintrag.
	Zeile int `json:"zeile"`
	// Schedule ist der rohe Zeitplan, wie er in der Datei steht.
	Schedule string `json:"schedule"`
	// ScheduleText ist derselbe Zeitplan in Worten. Nicht jeder liest
	// "17 3 * * 1-5" flüssig, und ein falsch gelesener Zeitplan ist der Grund,
	// warum jemand einen Eintrag für kaputt hält.
	ScheduleText string `json:"schedule_text"`
	User         string `json:"user"`
	Command      string `json:"command"`
	// Kommentar ist die Kommentarzeile unmittelbar über dem Eintrag, falls es
	// eine gibt. In fremden Crontabs steht dort oft, wozu die Zeile da ist.
	Kommentar string `json:"kommentar"`
	// Verwaltet heißt: Diese Datei trägt den Marker des Panels und darf
	// geschrieben werden. Alles andere ist Auskunft.
	Verwaltet bool `json:"verwaltet"`
	// Name ist der Name des verwalteten Eintrags (Datei ohne Präfix). Leer bei
	// fremden Einträgen.
	Name string `json:"name"`
	// Art unterscheidet, was für ein Ding das ist: "zeile" für eine Crontab-Zeile,
	// "skript" für eine Datei in einem run-parts-Verzeichnis.
	Art string `json:"art"`
	// Deaktiviert heißt: Die Zeile ist auskommentiert. Das Panel schreibt
	// abgeschaltete Einträge so — die Zeile bleibt lesbar, statt zu verschwinden.
	Deaktiviert bool `json:"deaktiviert"`
}

// CronList liest alle Zeitpläne, die auf dem System stehen.
//
// Ein Fehler an einer Quelle beendet die Auskunft nicht: Ein nicht lesbares
// Spool-Verzeichnis ist auf einem System ohne cron der Normalfall, und die
// übrigen Quellen sind trotzdem interessant. Was nicht gelesen werden konnte,
// steht in der zweiten Rückgabe — die Oberfläche nennt es, statt eine
// unvollständige Liste als vollständig auszugeben.
func (s *System) CronList(ctx context.Context) ([]CronEntry, []string, error) {
	_ = ctx
	var (
		alle    []CronEntry
		luecken []string
	)

	// /etc/crontab: mit Benutzerfeld.
	if eintraege, err := cronDateiLesen(crontabPath, true); err != nil {
		if !errors.Is(err, os.ErrNotExist) {
			luecken = append(luecken, fmt.Sprintf("%s: %v", crontabPath, err))
		}
	} else {
		alle = append(alle, eintraege...)
	}

	// /etc/cron.d/*: mit Benutzerfeld, und hier liegen auch die eigenen.
	namen, err := os.ReadDir(cronDDir)
	if err != nil {
		if !errors.Is(err, os.ErrNotExist) {
			luecken = append(luecken, fmt.Sprintf("%s: %v", cronDDir, err))
		}
	} else {
		for _, e := range namen {
			if e.IsDir() || !cronDateiname(e.Name()) {
				continue
			}
			pfad := filepath.Join(cronDDir, e.Name())
			eintraege, err := cronDateiLesen(pfad, true)
			if err != nil {
				luecken = append(luecken, fmt.Sprintf("%s: %v", pfad, err))
				continue
			}
			alle = append(alle, eintraege...)
		}
	}

	// Die Spool-Crontabs: OHNE Benutzerfeld — der Dateiname ist der Benutzer.
	spool, err := os.ReadDir(cronSpoolDir)
	if err != nil {
		if !errors.Is(err, os.ErrNotExist) && !errors.Is(err, os.ErrPermission) {
			luecken = append(luecken, fmt.Sprintf("%s: %v", cronSpoolDir, err))
		}
	} else {
		for _, e := range spool {
			if e.IsDir() {
				continue
			}
			pfad := filepath.Join(cronSpoolDir, e.Name())
			eintraege, err := cronDateiLesen(pfad, false)
			if err != nil {
				luecken = append(luecken, fmt.Sprintf("%s: %v", pfad, err))
				continue
			}
			// Der Benutzer steht im Dateinamen, nicht in der Zeile.
			for i := range eintraege {
				eintraege[i].User = e.Name()
			}
			alle = append(alle, eintraege...)
		}
	}

	// Die run-parts-Verzeichnisse. Was dort liegt, läuft — und es fehlt in jeder
	// Übersicht, die nur Crontab-Zeilen zeigt. Auf einem gewöhnlichen Debian ist
	// das die Hälfte dessen, was nachts geschieht.
	for dir, plan := range cronPeriodisch {
		eintraege, err := cronSkripteLesen(dir, plan)
		if err != nil {
			if !errors.Is(err, os.ErrNotExist) {
				luecken = append(luecken, fmt.Sprintf("%s: %v", dir, err))
			}
			continue
		}
		alle = append(alle, eintraege...)
	}

	sortiereCron(alle)
	return alle, luecken, nil
}

// cronDateiname sagt, ob eine Datei in /etc/cron.d von cron gelesen wird.
//
// cron überspringt Namen mit einem Punkt und die Sicherungen der Paketverwaltung
// (.dpkg-old, .rpmsave, ~). Sie hier mitzuzählen wäre eine Liste von Einträgen,
// die nie laufen — und damit eine falsche Auskunft über das System.
func cronDateiname(name string) bool {
	if name == "" || strings.HasPrefix(name, ".") {
		return false
	}
	if strings.Contains(name, ".") || strings.HasSuffix(name, "~") {
		return false
	}
	return true
}

// cronDateiLesen zerlegt eine Crontab. mitBenutzer unterscheidet die zwei
// Formate: /etc/crontab und /etc/cron.d/* haben ein Benutzerfeld zwischen
// Zeitplan und Befehl, die Spool-Crontabs der Benutzer nicht.
func cronDateiLesen(pfad string, mitBenutzer bool) ([]CronEntry, error) {
	roh, err := os.ReadFile(pfad) //nolint:gosec // feste Pfade, siehe Kopf
	if err != nil {
		return nil, err
	}
	verwaltet := strings.HasPrefix(string(roh), cronMarker)
	name := ""
	if verwaltet {
		name = strings.TrimPrefix(filepath.Base(pfad), cronDateiPraefix)
	}

	var (
		out       []CronEntry
		kommentar string
	)
	for i, zeile := range strings.Split(string(roh), "\n") {
		roh := zeile
		zeile = strings.TrimSpace(zeile)
		if zeile == "" {
			kommentar = ""
			continue
		}
		if strings.HasPrefix(zeile, "#") {
			// Ein auskommentierter Eintrag ist ein abgeschalteter Eintrag — so
			// schreibt das Panel sie, und so schreiben Menschen sie auch. Er wird
			// gezeigt, statt zu verschwinden: Sonst sieht man nicht, dass da
			// etwas war.
			if e, ok := cronZeileLesen(strings.TrimSpace(strings.TrimLeft(zeile, "# ")), mitBenutzer); ok {
				e.Quelle = pfad
				e.Zeile = i + 1
				e.Verwaltet = verwaltet
				e.Name = name
				e.Art = "zeile"
				e.Deaktiviert = true
				e.Kommentar = kommentar
				out = append(out, e)
				kommentar = ""
				continue
			}
			// Ein gewöhnlicher Kommentar. Der Marker selbst und die
			// Erklärzeilen darunter sind keine Beschreibung eines Eintrags.
			text := strings.TrimSpace(strings.TrimLeft(zeile, "# "))
			if !strings.HasPrefix(roh, cronMarker) && text != "" {
				kommentar = text
			}
			continue
		}
		// Zuweisungen (SHELL=, PATH=, MAILTO=) sind keine Einträge. Erkennbar am
		// Gleichheitszeichen vor dem ersten Leerraum.
		if istZuweisung(zeile) {
			kommentar = ""
			continue
		}
		e, ok := cronZeileLesen(zeile, mitBenutzer)
		if !ok {
			continue
		}
		e.Quelle = pfad
		e.Zeile = i + 1
		e.Verwaltet = verwaltet
		e.Name = name
		e.Art = "zeile"
		e.Kommentar = kommentar
		out = append(out, e)
		kommentar = ""
	}
	return out, nil
}

// istZuweisung erkennt SHELL=/bin/sh und Verwandte.
func istZuweisung(zeile string) bool {
	gleich := strings.Index(zeile, "=")
	if gleich <= 0 {
		return false
	}
	return !strings.ContainsAny(zeile[:gleich], " \t")
}

// cronZeileLesen zerlegt eine Eintragszeile.
func cronZeileLesen(zeile string, mitBenutzer bool) (CronEntry, bool) {
	felder := strings.Fields(zeile)
	if len(felder) == 0 {
		return CronEntry{}, false
	}

	var plan string
	var rest []string
	if strings.HasPrefix(felder[0], "@") {
		if !cronSonderworte[strings.ToLower(felder[0])] {
			return CronEntry{}, false
		}
		plan, rest = felder[0], felder[1:]
	} else {
		if len(felder) < 6 || !siehtWieZeitplanAus(felder[:5]) {
			return CronEntry{}, false
		}
		plan, rest = strings.Join(felder[:5], " "), felder[5:]
	}

	e := CronEntry{Schedule: plan, ScheduleText: ScheduleText(plan)}
	if mitBenutzer {
		if len(rest) < 2 {
			return CronEntry{}, false
		}
		e.User, e.Command = rest[0], strings.Join(rest[1:], " ")
	} else {
		if len(rest) < 1 {
			return CronEntry{}, false
		}
		e.Command = strings.Join(rest, " ")
	}
	if e.Command == "" {
		return CronEntry{}, false
	}
	return e, true
}

// siehtWieZeitplanAus prüft die fünf Felder auf Plausibilität.
//
// Nötig, weil jede Kommentarzeile mit sechs Wörtern sonst als abgeschalteter
// Eintrag gelesen würde — der Verwaltungsmarker selbst ist so eine Zeile, und er
// erschien als Eintrag mit dem Befehl „& Timer.". Absichtlich lockerer als
// ValidateSchedule: Hier wird gelesen, nicht geschrieben, und einen wirklich
// vorhandenen Eintrag aus der Liste zu werfen, weil sein Feld eigenartig ist,
// wäre schlimmer als ihn zu zeigen. Geprüft wird die Zeichenmenge, und für die
// ersten drei Felder, dass sie mit einer Ziffer oder einem Stern beginnen —
// Namen (jan, mon) gibt es nur im Monats- und Wochentagsfeld.
func siehtWieZeitplanAus(felder []string) bool {
	for i, feld := range felder {
		if !cronFeldPattern.MatchString(feld) {
			return false
		}
		if i < 3 && !strings.ContainsAny(feld[:1], "0123456789*") {
			return false
		}
	}
	return true
}

// cronSkripteLesen listet die ausführbaren Dateien eines run-parts-Verzeichnisses.
func cronSkripteLesen(dir, plan string) ([]CronEntry, error) {
	namen, err := os.ReadDir(dir)
	if err != nil {
		return nil, err
	}
	var out []CronEntry
	for _, e := range namen {
		if e.IsDir() || !cronDateiname(e.Name()) {
			continue
		}
		info, err := e.Info()
		if err != nil {
			continue
		}
		// Nicht ausführbar heißt: run-parts lässt es liegen. Es steht da und läuft
		// nicht — das ist eine Auskunft und kein Eintrag.
		if info.Mode().Perm()&0o111 == 0 {
			continue
		}
		out = append(out, CronEntry{
			Quelle:       filepath.Join(dir, e.Name()),
			Schedule:     plan,
			ScheduleText: ScheduleText(plan),
			// run-parts läuft aus /etc/crontab als root.
			User:    "root",
			Command: filepath.Join(dir, e.Name()),
			Art:     "skript",
		})
	}
	return out, nil
}

// sortiereCron ordnet: eigene Einträge zuerst, dann nach Quelle und Zeile.
//
// Die eigenen oben, weil sie die sind, an denen jemand etwas tut. Der Rest ist
// Auskunft und in der Reihenfolge der Dateien am ehesten wiederzufinden.
func sortiereCron(alle []CronEntry) {
	sort.SliceStable(alle, func(i, j int) bool {
		a, b := alle[i], alle[j]
		if a.Verwaltet != b.Verwaltet {
			return a.Verwaltet
		}
		if a.Quelle != b.Quelle {
			return a.Quelle < b.Quelle
		}
		return a.Zeile < b.Zeile
	})
}

// ---------------------------------------------------------------- Schreiben ---

// CronSpec beschreibt einen zu schreibenden Eintrag.
type CronSpec struct {
	// Name wird zum Dateinamen /etc/cron.d/asylum-<name>.
	Name     string
	Schedule string
	User     string
	Command  string
	// Kommentar landet als Zeile über dem Eintrag. Er ist der Platz für die
	// Antwort auf „wozu ist das da" — in sechs Monaten die wichtigste Angabe.
	Kommentar string
	// Aktiv false schreibt die Zeile auskommentiert. Abschalten statt löschen:
	// Der Eintrag bleibt lesbar, und wer ihn wieder braucht, tippt ihn nicht neu.
	Aktiv bool
}

// CronWrite legt einen verwalteten Eintrag an oder ersetzt ihn.
//
// Geschrieben wird ausschließlich /etc/cron.d/asylum-<name>, und zwar ganz: eine
// Datei, ein Eintrag. Eine bestehende Datei ohne den Marker wird NICHT
// überschrieben — dann hat ein Mensch dort eine Datei mit diesem Namen angelegt,
// und sie ihm wegzunehmen wäre genau das besitzergreifende Verhalten, das das
// Panel vermeidet.
func (s *System) CronWrite(ctx context.Context, spec CronSpec) error {
	_ = ctx
	// Erst beschneiden, dann prüfen, und das Beschnittene schreiben. Andernfalls
	// prüfte die Validierung etwas anderes als in der Datei landet: Sie ruft
	// TrimSpace auf, und ein Befehl mit anhängendem Wagenrücklauf käme so durch
	// die Prüfung und stünde ungekürzt in der Crontab.
	spec.Name = strings.TrimSpace(spec.Name)
	spec.Schedule = strings.Join(strings.Fields(spec.Schedule), " ")
	spec.User = strings.TrimSpace(spec.User)
	spec.Command = strings.TrimSpace(spec.Command)
	spec.Kommentar = strings.TrimSpace(spec.Kommentar)

	if err := ValidateCronName(spec.Name); err != nil {
		return err
	}
	if err := ValidateSchedule(spec.Schedule); err != nil {
		return err
	}
	if err := ValidateSystemUser(spec.User); err != nil {
		return err
	}
	if err := ValidateCronCommand(spec.Command); err != nil {
		return err
	}
	if err := ValidateCronComment(spec.Kommentar); err != nil {
		return err
	}
	// Ein Eintrag für einen Benutzer, den es nicht gibt, läuft nie: cron
	// protokolliert „unknown user" und überspringt die Datei. Der Eintrag stünde
	// da und sähe richtig aus.
	if !benutzerExistiert(spec.User) {
		return fmt.Errorf("der Benutzer %q existiert nicht — cron überspringt die "+
			"Datei dann stillschweigend", spec.User)
	}
	pfad := cronPfad(spec.Name)
	if err := cronFremdePruefen(pfad); err != nil {
		return err
	}
	if err := os.MkdirAll(cronDDir, 0o755); err != nil { //nolint:gosec // Verzeichnis für alle lesbar, wie von cron erwartet
		return fmt.Errorf("%s: %w", cronDDir, err)
	}
	return cronAtomarSchreiben(pfad, cronInhalt(spec))
}

// benutzerExistiert fragt /etc/passwd. Absichtlich nicht userHome: Dort ist ein
// Home-Verzeichnis von „/" ein Fehler, weil dort ein SSH-Schlüssel hin soll. Für
// einen Cron-Eintrag ist es keiner — cron braucht den Namen, nicht das
// Verzeichnis.
func benutzerExistiert(user string) bool {
	roh, err := os.ReadFile(passwdPath)
	if err != nil {
		return false
	}
	for _, u := range parsePasswd(string(roh)) {
		if u.Name == user {
			return true
		}
	}
	return false
}

// cronInhalt baut den Dateiinhalt.
//
// Die Datei erklärt sich selbst: Marker, ein Satz dazu, wo sie herkommt, und
// warum ein Mensch sie besser nicht von Hand ändert. Dieselbe Höflichkeit wie bei
// der TLS-Ergänzung (internal/config/dropin.go) — wer sie in sechs Monaten
// findet, soll nicht rätseln.
func cronInhalt(spec CronSpec) string {
	var b strings.Builder
	b.WriteString(cronMarker + "\n")
	b.WriteString("#\n")
	b.WriteString("# Diese Datei wird beim Speichern vollständig neu geschrieben; von Hand\n")
	b.WriteString("# geänderte Werte gehen dabei verloren. Für eigene Einträge legen Sie eine\n")
	b.WriteString("# weitere Datei in diesem Verzeichnis an — das Panel fasst sie nicht an.\n")
	// PATH ausdrücklich: cron gibt einen kurzen mit (/usr/bin:/bin), und der
	// häufigste Grund für „läuft von Hand, aber nicht über cron" ist ein Programm,
	// das darin nicht liegt.
	b.WriteString("SHELL=/bin/sh\n")
	b.WriteString("PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin\n")
	// Die Beschreibung steht unmittelbar über der Zeile und nicht im Kopf: Genau
	// so liest sie der eigene Leser wieder ein (die Kommentarzeile über dem
	// Eintrag), und genau so steht sie in fremden Crontabs. Im Kopf, vor den
	// Zuweisungen, ginge sie beim Lesen verloren.
	if spec.Kommentar != "" {
		b.WriteString("\n# " + spec.Kommentar + "\n")
	}
	if !spec.Aktiv {
		b.WriteString("# Abgeschaltet über das Panel:\n#")
	}
	b.WriteString(spec.Schedule + "\t" + spec.User + "\t" + spec.Command + "\n")
	return b.String()
}

// CronDelete entfernt einen verwalteten Eintrag.
//
// Nur eigene Dateien: Eine Datei ohne den Marker wird nicht angefasst, auch wenn
// ihr Name passt. Eine Datei, die es nicht gibt, ist kein Fehler — zwei Fenster,
// zwei Klicks, und der zweite soll nichts melden.
func (s *System) CronDelete(ctx context.Context, name string) error {
	_ = ctx
	if err := ValidateCronName(name); err != nil {
		return err
	}
	pfad := cronPfad(name)
	if err := cronFremdePruefen(pfad); err != nil {
		return err
	}
	if err := os.Remove(pfad); err != nil && !errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("%s: %w", pfad, err)
	}
	return nil
}

// CronVerzeichnis ist der Ort, an dem die verwalteten Dateien liegen.
//
// Nach außen gegeben, weil die Oberfläche ihn nennt: Das Panel versteckt nicht,
// wo es schreibt, und wer den Eintrag von Hand ändern will, soll nicht suchen.
func CronVerzeichnis() string { return cronDDir }

// cronPfad baut den Pfad einer verwalteten Datei. Der Name ist zu diesem
// Zeitpunkt geprüft und enthält keinen Schrägstrich; filepath.Base ist der
// Riegel, falls das jemals nicht mehr stimmt.
func cronPfad(name string) string {
	return filepath.Join(cronDDir, cronDateiPraefix+filepath.Base(name))
}

// cronFremdePruefen weist eine bestehende Datei ohne Marker ab.
func cronFremdePruefen(pfad string) error {
	roh, err := os.ReadFile(pfad) //nolint:gosec // aus cronPfad, geprüfter Name
	if errors.Is(err, os.ErrNotExist) {
		return nil
	}
	if err != nil {
		return fmt.Errorf("%s: %w", pfad, err)
	}
	if !strings.HasPrefix(string(roh), cronMarker) {
		return fmt.Errorf("%s gehört nicht dem Panel: Die Datei trägt keinen "+
			"Verwaltungsmarker und wird nicht angefasst", pfad)
	}
	return nil
}

// cronAtomarSchreiben schreibt über eine temporäre Datei und benennt um.
//
// rename(2) ist atomar: Ein Abbruch hinterlässt die alte oder die neue Datei, nie
// eine halbe. Bei einer Crontab ist das mehr als Sorgfalt — cron liest das
// Verzeichnis ständig, und eine halb geschriebene Zeile wäre ein Eintrag mit
// abgeschnittenem Befehl.
//
// Der temporäre Name beginnt mit einem Punkt: cron überspringt solche Dateien,
// also läuft nichts an, während sie einen Augenblick dort liegt.
func cronAtomarSchreiben(pfad, inhalt string) error {
	tmp := filepath.Join(filepath.Dir(pfad), "."+filepath.Base(pfad)+".asylum.tmp")
	// 0644: cron verlangt, dass die Datei nicht für andere schreibbar ist, und
	// liest sie als root. Lesbar darf sie sein — sie steht in /etc.
	if err := os.WriteFile(tmp, []byte(inhalt), 0o644); err != nil { //nolint:gosec // von cron so erwartet
		return fmt.Errorf("%s: %w", tmp, err)
	}
	if err := os.Rename(tmp, pfad); err != nil {
		_ = os.Remove(tmp)
		return fmt.Errorf("%s: %w", pfad, err)
	}
	return nil
}
