package httpd

// Zeitpläne über /api/v1: Cron-Einträge und systemd-Timer.
//
// Dieses Modul ist die erste neue privops-Familie seit dem Umbau der Oberfläche,
// und damit ist es eine Sicherheitsbetrachtung und nicht nur eine Seite. Die
// Betrachtung selbst steht in internal/privops/cron.go; hier stehen die zwei
// Entscheidungen, die an dieser Schicht hängen.
//
// **Erstens: Wer darf?** Ein Cron-Eintrag ist eine Shell-Zeile — cron gibt sie an
// /bin/sh. Wer einen Eintrag anlegen darf, führt Code als den eingetragenen
// Benutzer aus. Für root heißt das: vollen Zugriff auf den Rechner. Das ist
// dasselbe, was das Panel über die Systembenutzer-Verwaltung ohnehin erlaubt
// (ein Konto mit Shell und sudo-Gruppe), und es liegt deshalb bei derselben
// Schranke: Anlegen und Ändern verlangt die **Owner-Rolle**. Lesen genügt das
// Leserecht — wer wissen darf, welche Dienste laufen, darf wissen, was nachts
// läuft.
//
// **Zweitens: Wie oft wird gefragt?** Nach docs/14-bestaetigungen.md wäre das
// Anlegen Stufe 2: Der Eintrag ist löschbar, also umkehrbar. Für einen Eintrag
// ALS ROOT halte ich das für zu wenig, und das ist eine Abweichung, die benannt
// gehört: Der Eintrag ist umkehrbar, seine Folgen sind es nicht, und er läuft
// unbeaufsichtigt. Ein `rm -rf` um 3:17 ist genauso endgültig wie eines von Hand,
// nur merkt es niemand, bis es dreimal gelaufen ist. Deshalb:
//
//   - Eintrag als **root** → Stufe 3, getippt wird der **Hostname**. Wie beim
//     Neustart: Wer zwei Server offen hat, legt so keinen Nachtlauf auf dem
//     falschen an.
//   - Eintrag als **anderer Benutzer** → Stufe 2. Die Folgen bleiben in dem, was
//     dieser Benutzer erreicht.
//   - **Abschalten** → Stufe 1. Nichts läuft mehr, die Zeile bleibt lesbar.
//   - **Löschen** → Stufe 2. Der Text des Eintrags ist danach weg, und ihn
//     abzuschreiben war Arbeit — deshalb steht im Dialog der Hinweis auf das
//     Abschalten.
//
// Timer sind LESEND. Sie zu schalten geht schon: Ein Timer ist eine Unit, und
// start/stop/enable/disable laufen über /api/v1/services — dieselbe Allowlist,
// dieselbe Rückfrage. Das Anlegen eines Timers fehlt bewusst; die Begründung
// steht in internal/privops/timer.go.

import (
	"net/http"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// ------------------------------------------------------------------ Lesen ---

// apiZeitplaene ist die Antwort von GET /api/v1/schedules.
//
// Cron und Timer in einer Antwort, weil sie eine Frage beantworten: Was läuft
// hier von allein? Zwei Aufrufe würden zwei Ladezustände und zwei Fehlerfälle
// bedeuten für eine Seite, die man als Ganzes liest.
type apiZeitplaene struct {
	Cron   []apiCronEintrag  `json:"cron"`
	Timer  []apiTimer        `json:"timer"`
	Kennen apiZeitplanRahmen `json:"rahmen"`
	// Luecken sind Quellen, die sich nicht lesen ließen. Sie stehen in der
	// Antwort, weil eine unvollständige Liste als vollständig ausgegeben
	// Grundsatz IV bricht: Das Panel versteckt nichts, auch nicht sein eigenes
	// Unwissen.
	Luecken []string `json:"luecken"`
	// TimerFehler steht, wenn systemctl nicht antwortete. Auf einem System ohne
	// systemd ist das der Normalfall, und die Cron-Hälfte bleibt interessant —
	// deshalb ein Feld und kein Fehlerstatus für die ganze Antwort.
	TimerFehler string `json:"timer_fehler"`
}

// apiZeitplanRahmen ist, was die Oberfläche zum Bauen des Formulars braucht.
// Vom Server, weil hier bekannt ist, welche Benutzer es gibt und wie ein
// Zeitplan gelesen wird.
type apiZeitplanRahmen struct {
	// Benutzer sind die Konten, für die ein Eintrag angelegt werden kann. Ein
	// Eintrag für einen Benutzer, den es nicht gibt, läuft nie — cron
	// protokolliert es und überspringt die Datei.
	Benutzer []string `json:"benutzer"`
	// Vorlagen sind gebräuchliche Zeitpläne mit ihrem Satz. Sie sind der
	// Unterschied zwischen „fünf Felder ausfüllen" und „nachts um drei wählen".
	Vorlagen []apiZeitplanVorlage `json:"vorlagen"`
	// Verzeichnis ist der Ort der verwalteten Dateien. Genannt, damit niemand
	// suchen muss, wo das Panel schreibt.
	Verzeichnis string `json:"verzeichnis"`
	// DarfAendern sagt, ob diese Sitzung schreiben darf. Ohne die Auskunft zeigt
	// die Oberfläche Knöpfe, die zuverlässig mit 403 antworten — ein Knopf, der
	// immer scheitert, ist schlimmer als keiner.
	DarfAendern bool `json:"darf_aendern"`
}

// apiZeitplanVorlage ist ein vorgeschlagener Zeitplan.
type apiZeitplanVorlage struct {
	Name     string `json:"name"`
	Schedule string `json:"schedule"`
	Text     string `json:"text"`
}

// apiCronEintrag ist ein Cron-Eintrag für die Oberfläche.
//
// Fast deckungsgleich mit privops.CronEntry, aber nicht dasselbe: Hinzu kommt
// Stufe — die Rückfragestufe, die dieser Eintrag beim Löschen auslöst. Sie hier
// zu berechnen und nicht in der Oberfläche ist Absicht: Die Stufe ist eine
// Sicherheitsentscheidung, und zwei Stellen, die sie berechnen, laufen
// auseinander.
type apiCronEintrag struct {
	Quelle       string `json:"quelle"`
	Zeile        int    `json:"zeile"`
	Schedule     string `json:"schedule"`
	ScheduleText string `json:"schedule_text"`
	User         string `json:"user"`
	Command      string `json:"command"`
	Kommentar    string `json:"kommentar"`
	Verwaltet    bool   `json:"verwaltet"`
	Name         string `json:"name"`
	Art          string `json:"art"`
	Deaktiviert  bool   `json:"deaktiviert"`
	// Stufe ist 2 oder 3 — die Rückfrage, die das Speichern dieses Eintrags
	// verlangt. Für fremde Einträge 0: Sie sind nicht änderbar.
	Stufe int `json:"stufe"`
}

// apiTimer ist ein systemd-Timer für die Oberfläche.
type apiTimer struct {
	Unit         string `json:"unit"`
	Loest        string `json:"loest"`
	Beschreibung string `json:"beschreibung"`
	Aktiv        string `json:"aktiv"`
	Enabled      string `json:"enabled"`
	// Naechster und Letzter sind RFC-3339-Zeitpunkte; leer heißt „nicht bekannt".
	// Als Text und nicht als Zahl, weil die Oberfläche sie so formatiert wie alle
	// anderen Zeitpunkte des Panels.
	Naechster  string `json:"naechster"`
	Letzter    string `json:"letzter"`
	Plan       string `json:"plan"`
	Persistent bool   `json:"persistent"`
}

func (s *Server) handleAPIZeitplaene(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	eintraege, luecken, err := s.ops.CronList(r.Context())
	if err != nil {
		s.apiFehler(w, http.StatusBadGateway, "Die Zeitpläne ließen sich nicht lesen: "+err.Error())
		return
	}

	antwort := apiZeitplaene{
		Cron:    make([]apiCronEintrag, 0, len(eintraege)),
		Timer:   []apiTimer{},
		Luecken: luecken,
	}
	if antwort.Luecken == nil {
		antwort.Luecken = []string{}
	}
	for _, e := range eintraege {
		antwort.Cron = append(antwort.Cron, apiCronEintrag{
			Quelle: e.Quelle, Zeile: e.Zeile,
			Schedule: e.Schedule, ScheduleText: e.ScheduleText,
			User: e.User, Command: e.Command, Kommentar: e.Kommentar,
			Verwaltet: e.Verwaltet, Name: e.Name, Art: e.Art,
			Deaktiviert: e.Deaktiviert,
			Stufe:       cronStufe(e.Verwaltet, e.User),
		})
	}

	// Die Timer sind Beiwerk der Antwort: Ohne systemd bleibt die Cron-Hälfte
	// stehen. Umgekehrt wäre die Seite auf einem System ohne systemd leer.
	if timer, err := s.ops.TimerList(r.Context()); err != nil {
		antwort.TimerFehler = err.Error()
	} else {
		for _, t := range timer {
			antwort.Timer = append(antwort.Timer, apiTimer{
				Unit: t.Unit, Loest: t.Loest, Beschreibung: t.Beschreibung,
				Aktiv: t.Aktiv, Enabled: t.Enabled,
				Naechster: zeitText(t.Naechster), Letzter: zeitText(t.Letzter),
				Plan: t.Plan, Persistent: t.Persistent,
			})
		}
	}

	antwort.Kennen = apiZeitplanRahmen{
		Benutzer:    s.cronBenutzer(r),
		Vorlagen:    cronVorlagen(),
		Verzeichnis: privops.CronVerzeichnis(),
		DarfAendern: user.CanManageUsers(),
	}
	s.apiJSON(w, http.StatusOK, antwort)
}

// zeitText formatiert einen möglicherweise fehlenden Zeitpunkt. Leer statt
// „1970-01-01": Ein Timer ohne nächsten Lauf hat keinen, und ein Datum dafür wäre
// eine Behauptung.
func zeitText(t *time.Time) string {
	if t == nil || t.IsZero() {
		return ""
	}
	return t.Format(time.RFC3339)
}

// cronBenutzer sind die Konten, für die ein Eintrag angelegt werden kann.
//
// Nur solche mit Anmeldeschale: Ein Eintrag für www-data läuft, aber der Befehl
// darin läuft mit /usr/sbin/nologin als SHELL — und was cron daraus macht, hängt
// an der Fassung. Wer das wirklich braucht, schreibt die Datei von Hand; die
// Liste hier ist die der Konten, bei denen der Eintrag tut, was er soll.
func (s *Server) cronBenutzer(r *http.Request) []string {
	konten, err := s.ops.SystemUsers(r.Context())
	if err != nil {
		// Ohne Liste bleibt root: Das ist der Fall, um den es meistens geht, und
		// eine leere Auswahl wäre eine Seite ohne Formular.
		return []string{"root"}
	}
	out := make([]string, 0, len(konten))
	for _, k := range konten {
		if k.HasShell {
			out = append(out, k.Name)
		}
	}
	if len(out) == 0 {
		out = append(out, "root")
	}
	return out
}

// cronVorlagen sind die Zeitpläne, die in der Praxis vorkommen. Der Satz kommt
// aus derselben Funktion, die auch die gelesenen Einträge beschreibt — zwei
// Auslegungen derselben fünf Felder laufen auseinander.
func cronVorlagen() []apiZeitplanVorlage {
	plaene := []struct{ name, plan string }{
		{"stündlich", "0 * * * *"},
		{"alle 15 Minuten", "*/15 * * * *"},
		{"jede Nacht", "17 3 * * *"},
		{"jeden Werktagmorgen", "30 6 * * 1-5"},
		{"jeden Sonntag", "0 4 * * 0"},
		{"am Monatsersten", "0 4 1 * *"},
		{"beim Hochfahren", "@reboot"},
	}
	out := make([]apiZeitplanVorlage, 0, len(plaene))
	for _, p := range plaene {
		out = append(out, apiZeitplanVorlage{
			Name: p.name, Schedule: p.plan, Text: privops.ScheduleText(p.plan),
		})
	}
	return out
}

// cronStufe ist die Rückfragestufe für einen Eintrag.
//
// An einer Stelle berechnet, weil sie an drei gebraucht wird: beim Anlegen, beim
// Löschen und in der Liste (dort, damit die Oberfläche den richtigen Dialog
// vorbereitet). Drei Rechnungen derselben Regel laufen auseinander, und bei einer
// Sicherheitsregel heißt „auseinander" im Zweifel „zu niedrig".
func cronStufe(verwaltet bool, user string) int {
	if !verwaltet {
		return 0
	}
	if user == "root" {
		return 3
	}
	return 2
}

// ------------------------------------------------------------- Schreiben ---

// apiCronAuftrag ist der Körper von POST /api/v1/schedules/cron.
type apiCronAuftrag struct {
	Name      string `json:"name"`
	Schedule  string `json:"schedule"`
	User      string `json:"user"`
	Command   string `json:"command"`
	Kommentar string `json:"kommentar"`
	Aktiv     bool   `json:"aktiv"`

	Bestaetigt bool   `json:"bestaetigt"`
	Getippt    string `json:"getippt"`
}

// apiZeitplanAntwort ist die Antwort auf eine ausgeführte Handlung. Sie trägt den
// neu gelesenen Zustand mit, damit die Oberfläche nicht nachfragen muss und in
// der Lücke dazwischen den alten zeigt.
type apiZeitplanAntwort struct {
	Meldung string `json:"meldung"`
	Hinweis string `json:"hinweis,omitempty"`
}

// handleAPICronSpeichern legt einen verwalteten Eintrag an oder ersetzt ihn.
func (s *Server) handleAPICronSpeichern(w http.ResponseWriter, r *http.Request) {
	var auftrag apiCronAuftrag
	if !s.apiJSONKoerper(w, r, &auftrag) {
		return
	}
	auftrag.Name = strings.TrimSpace(auftrag.Name)
	auftrag.User = strings.TrimSpace(auftrag.User)
	auftrag.Command = strings.TrimSpace(auftrag.Command)

	// Erst prüfen, dann fragen. Andernfalls stünde in der Rückfrage ein Zeitplan,
	// den der Server gleich darauf abweist — und der Mensch hätte den Hostnamen
	// für nichts getippt.
	if err := privops.ValidateCronName(auftrag.Name); err != nil {
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}
	if err := privops.ValidateSchedule(auftrag.Schedule); err != nil {
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}
	if err := privops.ValidateCronCommand(auftrag.Command); err != nil {
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}
	if err := privops.ValidateCronComment(auftrag.Kommentar); err != nil {
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}

	// Abschalten ist Stufe 1: Danach läuft nichts mehr, und die Zeile bleibt
	// lesbar. Ein aktiver Eintrag fragt zurück — als root mit dem Hostnamen.
	if auftrag.Aktiv {
		if !s.apiBestaetigt(w, apiAktionAnfrage{
			Bestaetigt: auftrag.Bestaetigt, Getippt: auftrag.Getippt,
		}, s.cronFrage(auftrag)) {
			return
		}
	}

	spec := privops.CronSpec{
		Name: auftrag.Name, Schedule: auftrag.Schedule, User: auftrag.User,
		Command: auftrag.Command, Kommentar: auftrag.Kommentar, Aktiv: auftrag.Aktiv,
	}
	if err := s.ops.CronWrite(r.Context(), spec); err != nil {
		s.audit(r, "cron.write", auftrag.Name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}

	// Im Protokoll steht der ganze Befehl. Das ist Absicht und nicht Versehen: Er
	// ist die Antwort auf „was lief da", und ein Protokoll, das den Zeitplan nennt
	// und den Befehl weglässt, beantwortet genau die Frage nicht, für die man es
	// aufschlägt.
	s.audit(r, "cron.write", auftrag.Name, store.ResultOK,
		"user="+auftrag.User+" schedule="+auftrag.Schedule+
			" aktiv="+boolText(auftrag.Aktiv)+" command="+auftrag.Command)

	meldung := "Der Zeitplan ist gespeichert."
	if !auftrag.Aktiv {
		meldung = "Der Zeitplan ist gespeichert und abgeschaltet."
	}
	s.apiJSON(w, http.StatusOK, apiZeitplanAntwort{
		Meldung: meldung,
		Hinweis: "Die Datei liegt in " + privops.CronVerzeichnis() + ".",
	})
}

// cronFrage baut die Rückfrage zu einem Eintrag.
//
// Der Text nennt die Zeit in Worten, den Benutzer und den Befehl. Alle drei, weil
// alle drei Fehler vorkommen: der Zeitplan falsch gelesen, der Benutzer falsch
// gewählt, der Befehl mit einem Tippfehler. Ein Dialog, der nur „wirklich
// anlegen?" fragt, verhindert keinen davon.
func (s *Server) cronFrage(auftrag apiCronAuftrag) apiBestaetigung {
	wann := privops.ScheduleText(auftrag.Schedule)
	if wann == "" {
		wann = auftrag.Schedule
	}

	b := apiBestaetigung{
		Titel: "Zeitplan anlegen",
		Frage: "Diesen Befehl künftig " + wann + " als " + auftrag.User + " ausführen?",
		Punkte: []string{
			auftrag.Command,
			"Er läuft unbeaufsichtigt und ohne Rückfrage — auch wenn niemand angemeldet ist.",
			"Was er anrichtet, nimmt kein Rückweg des Panels zurück; der Eintrag selbst ist löschbar.",
		},
		Knopf: "anlegen",
	}

	// Stufe 3 für root: Der Eintrag ist umkehrbar, seine Folgen sind es nicht.
	// Getippt wird der Hostname wie beim Neustart — wer zwei Server offen hat,
	// legt so keinen Nachtlauf auf dem falschen an.
	if cronStufe(true, auftrag.User) == 3 {
		host := s.rechnername()
		b.Titel = "Zeitplan als root anlegen"
		b.Punkte = append([]string{
			"Der Befehl läuft mit vollen Rechten auf " + host + ".",
		}, b.Punkte...)
		b.Tippen = host
		b.TippenHinweis = "Zum Bestätigen den Hostnamen eingeben: " + host
	}
	return b
}

// apiCronLoeschAuftrag ist der Körper von POST /api/v1/schedules/cron/{name}/delete.
type apiCronLoeschAuftrag struct {
	Bestaetigt bool   `json:"bestaetigt"`
	Getippt    string `json:"getippt"`
}

// handleAPICronLoeschen entfernt einen verwalteten Eintrag — Stufe 2.
//
// Nicht Stufe 3, auch für einen root-Eintrag: Löschen macht das System nicht
// unsicherer und schließt niemanden aus. Was verloren geht, ist der Text des
// Eintrags, und den abzuschreiben war Arbeit — deshalb nennt der Dialog das
// Abschalten als das, was man vermutlich meint.
func (s *Server) handleAPICronLoeschen(w http.ResponseWriter, r *http.Request) {
	name := r.PathValue("name")
	var auftrag apiCronLoeschAuftrag
	if !s.apiJSONKoerper(w, r, &auftrag) {
		return
	}
	if err := privops.ValidateCronName(name); err != nil {
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}

	if !s.apiBestaetigt(w, apiAktionAnfrage{
		Bestaetigt: auftrag.Bestaetigt, Getippt: auftrag.Getippt,
	}, apiBestaetigung{
		Titel: "Zeitplan " + name + " löschen",
		Frage: "Den Eintrag " + name + " endgültig entfernen?",
		Punkte: []string{
			"Die Datei wird entfernt; Zeitplan und Befehl sind danach weg.",
			"Wollen Sie ihn nur vorübergehend stillstellen, schalten Sie ihn ab — dann bleibt er lesbar.",
		},
		Knopf: "löschen",
	}) {
		return
	}

	if err := s.ops.CronDelete(r.Context(), name); err != nil {
		s.audit(r, "cron.delete", name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}
	s.audit(r, "cron.delete", name, store.ResultOK, "")
	s.apiJSON(w, http.StatusOK, apiZeitplanAntwort{Meldung: "Der Zeitplan ist gelöscht."})
}

// apiTimerLauf ist das Ergebnis des letzten Laufs.
//
// Eine eigene Form und nicht privops.TimerLauf durchgereicht: Dessen Journalzeilen
// tragen die Feldnamen von systemd (message, priority), und die Oberfläche kennt
// nur eine Journalzeile — apiLogzeile mit `nachricht` und `stufe`. Zwei Formen für
// dieselbe Sache wären zwei Darstellungen im Browser, und die zweite fällt beim
// Bauen nicht auf, weil TypeScript die Antwort nicht prüft.
type apiTimerLauf struct {
	Unit      string        `json:"unit"`
	Ergebnis  string        `json:"ergebnis"`
	ExitCode  int           `json:"exit_code"`
	Geglueckt bool          `json:"geglueckt"`
	Zeilen    []apiLogzeile `json:"zeilen"`
}

// handleAPITimerLauf liefert das Ergebnis des letzten Laufs einer Timer-Unit.
//
// Gefragt wird nach dem DIENST, den der Timer auslöst: Der Timer glückt immer,
// sobald er auslöst — was schiefgehen kann, geht im Dienst schief.
func (s *Server) handleAPITimerLauf(w http.ResponseWriter, r *http.Request) {
	unit := r.PathValue("unit")
	if err := privops.ValidateUnit(unit); err != nil {
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}
	lauf, err := s.ops.TimerRuns(r.Context(), unit)
	if err != nil {
		s.apiFehler(w, http.StatusBadGateway, "Der letzte Lauf ließ sich nicht lesen: "+err.Error())
		return
	}

	antwort := apiTimerLauf{
		Unit: lauf.Unit, Ergebnis: lauf.Ergebnis, ExitCode: lauf.ExitCode,
		Geglueckt: lauf.Geglueckt,
		Zeilen:    make([]apiLogzeile, 0, len(lauf.Zeilen)),
	}
	for _, l := range lauf.Zeilen {
		antwort.Zeilen = append(antwort.Zeilen, apiLogzeile{
			At:        l.At.Format("02.01. 15:04:05"),
			Stufe:     l.PriorityName(),
			Nachricht: l.Message,
			Ernst:     l.Priority <= 3,
		})
	}
	s.apiJSON(w, http.StatusOK, antwort)
}
