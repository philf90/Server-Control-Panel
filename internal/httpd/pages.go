package httpd

import (
	"net/http"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/certs"
	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/metrics"
	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
	"github.com/philf90/asylum/internal/version"
)

// basePage sind die Werte, die jede Seite braucht. Seitenspezifische Daten
// hängen unter Content.
type basePage struct {
	Title    string
	Nav      string
	User     store.User
	LoggedIn bool
	CanWrite bool
	IsOwner  bool
	CSRF     string
	Version  string
	Host     metrics.Host
	Flash    string
	Error    string
	// Theme ist "dark", "light" oder leer. Leer heißt: keine ausdrückliche
	// Wahl, es gilt die Systemeinstellung. Der Wert kommt aus dem Cookie
	// asylum_theme und wird ans <html> gerendert, damit die Seite ohne
	// Aufblitzen im richtigen Modus ankommt.
	Theme string
	// FilesOn sagt, ob der Dateimanager eingeschaltet ist. Ist er es nicht,
	// fehlt der Menüpunkt — es gibt dann auch keine Route dahinter.
	FilesOn bool
	// Policy ist die geltende Passwortrichtlinie. Sie steht in jeder Seite, weil
	// vier davon ein neues Passwort verlangen (Einrichtung, Kontoseite,
	// erzwungener Wechsel, Passkey-Weg) und alle dieselbe Anzeige benutzen. Die
	// Zahlen kommen von hier ins Markup — das Skript für die Prüfliste soll sie
	// nicht ein zweites Mal festschreiben.
	Policy  auth.PasswordPolicy
	Content any
}

func (s *Server) base(r *http.Request, title, nav string) basePage {
	p := basePage{
		Title:   title,
		Nav:     nav,
		Version: version.String(),
		Host:    s.sampler.Host(),
		FilesOn: s.files != nil,
		Policy:  auth.Policy(),
	}
	if u, ok := userFrom(r.Context()); ok {
		p.User = u
		p.LoggedIn = true
		p.CanWrite = u.CanWrite()
		p.IsOwner = u.CanManageUsers()
	}
	if sess, ok := sessionFrom(r.Context()); ok {
		p.CSRF = sess.CSRFToken
	}
	// Die Themenwahl steht in einem Cookie. Nur die beiden erwarteten Werte
	// werden übernommen — alles andere zählt als „keine Wahl" und überlässt die
	// Entscheidung der Systemeinstellung. So kann ein manipulierter Cookie-Wert
	// nicht ins Attribut durchschlagen.
	if c, err := r.Cookie("asylum_theme"); err == nil {
		if c.Value == "dark" || c.Value == "light" {
			p.Theme = c.Value
		}
	}
	return p
}

func (b basePage) with(content any) basePage {
	b.Content = content
	return b
}

func (b basePage) withError(msg string) basePage {
	b.Error = msg
	return b
}

func (b basePage) withFlash(msg string) basePage {
	b.Flash = msg
	return b
}

type errorPage struct {
	Message string
}

type loginPage struct {
	Username string
	// WebAuthnOn blendet den Passkey-Knopf ein. Ohne eingeschaltete Funktion
	// gäbe es keinen Endpunkt, den er ansprechen könnte.
	WebAuthnOn bool
}

type setupPage struct {
	Token string
}

type totpPage struct {
	Secret          string
	SecretFormatted string
	URI             string
}

type codesPage struct {
	Codes []string
	// AfterChange unterscheidet den Wechsel von der Ersteinrichtung: Danach
	// führt der Weg zurück zur Kontoseite, nicht in die Übersicht.
	AfterChange bool
}

type dashboardPage struct {
	Snapshot metrics.Snapshot
	HasData  bool
	Verdict  dashVerdict
	Signals  []dashSignal
	Sparks   dashSparks
	// Net ist die Schnittstelle der Netzwerkkachel: die mit der Standardroute,
	// nicht die erste der alphabetisch sortierten Liste. HasNet ist falsch, wenn
	// es überhaupt keine gibt.
	Net    metrics.Interface
	HasNet bool
}

// dashVerdict ist das Urteil in einem Satz ganz oben: Geht es dem Server gut?
type dashVerdict struct {
	Level string // "ok" | "warn"
	Title string
	Sub   string
}

// dashSignal ist ein Punkt auf der Handlungsbedarf-Liste. Die Aktion ist ein
// Link auf die zuständige Seite — keine schreibende Aktion von der Übersicht
// aus, damit die Seite ohne CSRF und ohne Schreibpfad auskommt.
type dashSignal struct {
	Level       string // "crit" | "warn"
	Tag         string // "Dienst" | "Speicher" | "System"
	Title       string
	Detail      string
	ActionLabel string
	ActionHref  string
	Primary     bool
}

// dashSparks hält die fertigen SVG-Pfade der Telemetrie-Verläufe. Serverseitig
// erzeugt, damit die Seite ohne Inline-Skript auskommt (CSP).
type dashSparks struct {
	CPU  spark
	Mem  spark
	Load spark
	Net  spark
}

// spark ist ein einzelner Verlauf: der Pfad, der Endpunkt und die Messpunkte.
type spark struct {
	Path string
	// Dot ist der Endpunkt als eigener Pfad (Segment der Länge null mit runder
	// Kappe), damit ihn die waagerechte Streckung des Feldes nicht verzerrt.
	Dot string
	// Points sind die Stützstellen als JSON: Stelle im Feld, Uhrzeit, Wert. Sie
	// stehen in einem data-Attribut, aus dem spark.js den Wert unter dem Zeiger
	// anzeigt — die CSP erlaubt kein Inline-Skript, das sie mitbrächte.
	Points string
	Has    bool
}

type auditPage struct {
	Entries []store.AuditEntry
}

type certPage struct {
	Mode     string // selfsigned | acme
	Source   string // menschlich lesbare Herkunft des aktiven Zertifikats
	Info     certs.Info
	DaysLeft int
	// ReadError: Konnte die Datei nicht gelesen werden, steht hier der Grund —
	// die Seite bleibt erreichbar, statt mit 500 zu scheitern.
	ReadError string
	// Set sind die Einstellungen, wie sie im Formular stehen.
	Set config.TLSSettings
	// DomainsText ist die Eingabefassung von Set.ACME.Domains, ein Name je
	// Zeile. Leer heißt: der vollqualifizierte Rechnername.
	DomainsText string
	// EffectiveDomains sind die Namen, die tatsächlich verwendet würden —
	// aufgelöst, damit niemand raten muss, was "leer" bedeutet.
	EffectiveDomains []string
	// Staging sagt, ob das Testverzeichnis von Let's Encrypt eingestellt ist.
	Staging bool
	// TokenHinterlegt: Ein gespeichertes Cloudflare-Token wird nie
	// zurückgezeigt, aber sein Vorhandensein schon.
	TokenHinterlegt bool
	// Attempt ist der letzte Bezugsversuch.
	Attempt tlsAttempt
	// ManagedFile ist die Datei, in der die Einstellungen landen. Sie wird
	// genannt, weil das Panel nichts versteckt.
	ManagedFile string

	// Verlauf des Bezugs, wie beim Paketvorgang. JobActor benennt, wer ihn
	// angestoßen hat — "automatisch" bei einer Erneuerung vor Ablauf.
	JobLines   []string
	JobRunning bool
	JobDone    bool
	JobError   string
	JobActor   string
}

type accountPage struct {
	RecoveryCodesLeft int
	NewCodes          []string
	Sessions          []sessionView
	OtherSessions     int
	// WebAuthnOn sagt, ob der Passkey-Abschnitt überhaupt erscheint.
	WebAuthnOn bool
	Passkeys   []passkeyView
}

type usersPage struct {
	Users []store.User
	// Others sind alle Konten außer dem eigenen — die Auswahl für den Abschnitt
	// „Zugang zurücksetzen". Ist sie leer, entfällt der Abschnitt: Bei einer
	// Installation mit einem einzigen Konto gibt es dort nichts zu tun.
	Others []store.User
	// ResetID ist die Vorauswahl aus dem Sprunglink der Tabellenzeile.
	ResetID int64
}

// resetPage zeigt ein Einmalpasswort — genau einmal, wie die
// Wiederherstellungscodes.
//
// Zwei Anlässe, eine Seite: ein zurückgesetzter Zugang und ein neu angelegtes
// Konto. Der Weg danach ist derselbe (anmelden, zweiter Faktor, Wechselzwang),
// deshalb wäre eine zweite Vorlage eine Kopie, die früher oder später
// auseinanderläuft. Created unterscheidet nur die Wortwahl.
type resetPage struct {
	Username string
	Password string
	Created  bool
}

type forgotPage struct {
	// WebAuthnOn entscheidet, ob es überhaupt einen Weg gibt. Ohne Passkeys
	// nennt die Seite den Befehl für die Kommandozeile.
	WebAuthnOn bool
}

type forgotNewPage struct {
	Username string
}

// --------------------------------------------------- Seiten der Systemmodule ---

type servicesPage struct {
	Services []privops.Service
	Filter   privops.ServiceFilter
	Failed   int
	State    string
}

type serviceDetailPage struct {
	Detail privops.ServiceDetail
}

type packagesPage struct {
	Packages []privops.Package
	Security int
	Reboot   privops.RebootState

	JobLines   []string
	JobRunning bool
	JobDone    bool
	JobError   string
	// JobNote ist eine Anmerkung, die kein Fehler ist: der Teilerfolg von
	// apt-get update, bei dem einzelne Quellen klemmen und die übrigen Listen
	// trotzdem neu sind.
	JobNote string
}

type firewallPage struct {
	State   privops.FirewallState
	Pending bool
	// PendingSubject benennt, was auf Probe steht — Regelsatz oder
	// Aktivierung. Beide laufen über denselben Wächter, aber der Satz
	// "wird zurückgerollt" bedeutet je nachdem etwas anderes.
	PendingSubject   string
	RemainingSeconds int
	// PanelPort und PanelPortOpen entscheiden, ob das Einschalten überhaupt
	// angeboten werden darf: Ohne Regel für diesen Port wäre danach auch die
	// Bestätigungsseite nicht mehr erreichbar.
	PanelPort     int
	PanelPortOpen bool
	// OpenPorts ist die Liste dessen, was nach dem Einschalten erreichbar
	// bleibt — ausgeschrieben, damit niemand raten muss.
	OpenPorts string
	// Ausgabe der ufw-Installation, wie beim Paketvorgang.
	JobLines   []string
	JobRunning bool
	JobDone    bool
	JobError   string
	// Rows sind die Zeilen des Formulars: die festgesetzte Regel des Panels,
	// die bestehenden Regeln und Vorschläge für SSH.
	Rows []firewallRow
}

// firewallRow ist eine Zeile im Regelformular.
type firewallRow struct {
	Rule privops.FirewallRule
	// Locked: Port und Protokoll stehen fest. Gilt für den Port, über den das
	// Panel erreichbar ist — ohne ihn sperrt das nächste Einschalten aus.
	Locked bool
	// Proposed: eine Regel, die es noch nicht gibt, aber geben sollte.
	Proposed bool
	// Note erklärt in einem Satz, warum die Zeile so ist, wie sie ist.
	Note string
}

type sysUsersPage struct {
	Users    []privops.SystemUser
	Selected string
	Keys     []privops.SSHKey
}

// filesPage ist die Seite des Dateimanagers.
type filesPage struct {
	// Path ist das angezeigte Verzeichnis, Dir sein Eintrag.
	Path   string
	Dir    privops.FileEntry
	Parent string
	Crumbs []crumb
	// Roots sind die freigegebenen Bäume als Einstiegspunkte.
	Roots []string

	Entries []privops.FileEntry
	Total   int
	// Truncated und TruncatedReason sagen, dass die Liste nicht vollständig ist.
	// Eine gekürzte Liste ohne Hinweis wäre eine Falschaussage: Man sieht ihr
	// nicht an, dass etwas fehlt.
	Truncated       bool
	TruncatedReason string

	Sort   string
	Desc   bool
	Hidden bool
	// Query und Suche: Ist gesucht worden, zeigt die Liste Treffer statt eines
	// Verzeichnisinhalts.
	Query string
	Suche bool

	// Free ist der freie Platz des Dateisystems an dieser Stelle.
	Free uint64
	// Warnungen sind Schreibbereiche, in die der Dienst nicht schreiben kann,
	// obwohl die Konfiguration es vorsieht. Praktisch immer eine systemd-Unit
	// aus der Zeit vor dem Dateimanager — siehe filesWurzelPruefung.
	Warnungen []privops.RootStatus
}

// fileEntryPage ist die Detailseite eines Eintrags.
//
// Eigene Seite statt Formularen in jeder Tabellenzeile: Umbenennen,
// Verschieben, Rechte und Löschen brauchen Eingabefelder, und zweitausend
// Zeilen mit je vier Formularen wären weder auf dem Telefon bedienbar noch
// schnell zu rendern. Dieselbe Aufteilung wie bei den Diensten.
type fileEntryPage struct {
	Entry  privops.FileEntry
	Dir    string
	Crumbs []crumb
	// Measurement ist bei Verzeichnissen die Zählung darunter. Sie steht neben
	// dem Löschknopf: "4.132 Dateien, 1,2 GiB" ist die Rückfrage, die zählt.
	Measurement *privops.Measurement
	// Users und Groups sind die Namen des Systems für die Auswahl beim
	// Eigentümerwechsel. Freitext gibt es dort nicht.
	Users  []string
	Groups []string
	// Editable sagt, ob der Editor angeboten wird.
	Editable bool
	// Text ist der Inhalt für den Editor, falls die Seite ihn zeigt.
	Text *privops.TextFile
	// Rechte ist die Rechteangabe in Worten: drei Rollen, drei Rechte, dazu die
	// Sonderbits. Serverseitig aufgeschlüsselt, damit die Beschreibung auch ohne
	// Skript stimmt — rechte.js macht daraus die Eingabe.
	Rechte privops.ModeDescription
	// Ziele sind die Ordner, die ohne Skript zur Wahl stehen: die
	// Schreibbereiche und die Ordner auf dem Weg hierher. Mit Skript wird daraus
	// eine durchsuchbare Auswahl (zielwahl.js über /files/dirs). Ein freies
	// Textfeld gibt es in keinem der beiden Fälle.
	Ziele []fileTarget
}

// fileTarget ist ein Ziel in der Auswahl zum Verschieben und Kopieren.
type fileTarget struct {
	Path     string
	Label    string
	Selected bool
}

// fileEditPage ist die Editor-Seite.
type fileEditPage struct {
	Entry  privops.FileEntry
	Text   privops.TextFile
	Dir    string
	Crumbs []crumb
	// Sprache ist die Kennung für die Hervorhebung, vom Server bestimmt: Dort
	// ist der ganze Pfad bekannt, und /etc/nginx/sites-enabled/beispiel hat
	// keine Endung.
	Sprache string
	// Eingabe ist der Inhalt im Textfeld. Nach einem Konflikt ist das die
	// Fassung des Benutzers, sonst die von der Platte.
	Eingabe string
	// Konflikt sagt, dass die Datei zwischenzeitlich von außen geändert wurde.
	Konflikt bool
	// Pruefung ist das Ergebnis des Prüfprogramms, falls es für diese Datei
	// eines gibt.
	Pruefung *privops.ConfigCheckResult
	// Nonce weist das Stil-Element des Editors gegenüber der
	// Content-Security-Policy aus. Je Antwort neu.
	Nonce string
}

// crumb ist ein Bestandteil des klickbaren Pfads.
// crumb ist ein Glied des klickbaren Pfades. Die JSON-Namen braucht die
// Zielauswahl beim Kopieren und Verschieben (/files/dirs).
type crumb struct {
	Name string `json:"name"`
	Path string `json:"path"`
}

type logsPage struct {
	Entries []privops.LogEntry
	Units   []string
	Query   privops.LogQuery
}
