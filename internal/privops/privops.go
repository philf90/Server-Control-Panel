// Package privops bündelt alle privilegierten Systemoperationen.
//
// Das ist die einzige Stelle im Projekt, die Systemzustand verändert. Der Rest
// der Anwendung kennt nur das Executor-Interface. Zwei Gründe:
//
//   - Angriffsfläche. Das Interface kennt keine freien Kommandos, sondern nur
//     typisierte Operationen. Kein Aufrufer kann eine Zeichenkette
//     zusammensetzen, die am Ende in einer Shell landet — es gibt keine Shell.
//   - Umbaubarkeit. In einer späteren Ausbaustufe läuft der Webprozess
//     unprivilegiert und spricht über einen Unix-Socket mit einem
//     root-Agenten. Der Agent implementiert dann dasselbe Interface; für den
//     übrigen Code ändert sich nichts.
package privops

import (
	"context"
	"time"
)

// Executor ist die Schnittstelle zu allem, was Rechte braucht.
type Executor interface {
	// Dienste
	Services(ctx context.Context, filter ServiceFilter) ([]Service, error)
	Service(ctx context.Context, unit string) (ServiceDetail, error)
	ServiceAction(ctx context.Context, unit string, action ServiceAction) error

	// Pakete
	PackageRefresh(ctx context.Context, stream LineWriter) (PackageRefreshResult, error)
	PackageUpgradable(ctx context.Context) ([]Package, error)
	PackageUpgrade(ctx context.Context, opts UpgradeOptions, stream LineWriter) error
	RebootRequired(ctx context.Context) (RebootState, error)
	// Reboot startet den Rechner neu. Der Aufruf kehrt zurück, sobald systemd
	// den Neustart angenommen hat — er ist kein Versprechen, dass der Prozess
	// die Zeile noch überlebt.
	Reboot(ctx context.Context) error

	// Firewall
	FirewallState(ctx context.Context) (FirewallState, error)
	FirewallApply(ctx context.Context, rules []FirewallRule) error
	FirewallInstall(ctx context.Context, stream LineWriter) error
	FirewallSetActive(ctx context.Context, active bool) error
	SSHPorts(ctx context.Context) []int

	// Systembenutzer und SSH
	SystemUsers(ctx context.Context) ([]SystemUser, error)
	SystemUserCreate(ctx context.Context, spec SystemUserSpec) error
	SystemUserSetLocked(ctx context.Context, name string, locked bool) error
	SystemUserDelete(ctx context.Context, name string, removeHome bool) error
	// LoginShells und Groups sind Auskünfte für die Auswahlfelder beim Anlegen
	// eines Kontos. Sie stehen hier und nicht in der Oberfläche, weil sie
	// dieselben Quellen lesen, gegen die ValidateShell und ValidateGroupName
	// prüfen: Angeboten werden soll genau, was die Prüfung annimmt. Eine eigene
	// Liste daneben wäre die Stelle, an der ein Auswahlfeld etwas vorschlägt, das
	// der Server dann ablehnt.
	LoginShells(ctx context.Context) ([]string, error)
	Groups(ctx context.Context) ([]string, error)
	AuthorizedKeys(ctx context.Context, user string) ([]SSHKey, error)
	AuthorizedKeyAdd(ctx context.Context, user, key string) error
	AuthorizedKeyRemove(ctx context.Context, user, fingerprint string) error

	// Konfigurationsprüfung. Sie gehört hierher und nicht in Files: Geprüft
	// wird mit einem Programm des Systems, und der Aufruf von Programmen ist
	// genau das, was dieses Interface bündelt.
	ConfigCheck(ctx context.Context, path string) (ConfigCheckResult, error)

	// Logs
	Logs(ctx context.Context, q LogQuery) ([]LogEntry, error)
	LogUnits(ctx context.Context) ([]string, error)
	// LogsFollow verfolgt das Journal, bis der Kontext abgebrochen wird. Die
	// einzige Operation ohne eigene Frist: Sie endet, wenn der Aufrufer sie
	// beendet, und nicht nach einer Zeit, die hier niemand kennen kann.
	LogsFollow(ctx context.Context, q LogQuery, sink LogSink) error

	// Selbstupdate
	SelfUpdateStart(ctx context.Context, spec SelfUpdateSpec) error
}

// LineWriter nimmt zeilenweise Ausgabe entgegen, etwa für die Live-Anzeige
// eines laufenden Paket-Updates.
type LineWriter func(line string)

// LogSink nimmt einen Journaleintrag entgegen, sobald er anfällt.
//
// Ein eigener Typ neben LineWriter, weil es hier keine Zeile ist, sondern ein
// zerlegter Eintrag: Wer das Journal verfolgt, will nach Stufe einfärben und
// nach Unit filtern, und das ginge an einer rohen Zeile nur mit einem zweiten
// Parser an der falschen Stelle.
type LogSink func(LogEntry)

// ---------------------------------------------------------------- Dienste ---

// ServiceAction ist eine erlaubte Aktion auf einer systemd-Unit.
type ServiceAction string

// Die vollständige Liste. Alles außerhalb wird abgewiesen — es gibt keinen
// Weg, eine beliebige systemctl-Unteraktion durchzureichen.
const (
	ServiceStart   ServiceAction = "start"
	ServiceStop    ServiceAction = "stop"
	ServiceRestart ServiceAction = "restart"
	ServiceReload  ServiceAction = "reload"
	ServiceEnable  ServiceAction = "enable"
	ServiceDisable ServiceAction = "disable"
)

// ValidServiceAction prüft eine Aktion.
func ValidServiceAction(a ServiceAction) bool {
	switch a {
	case ServiceStart, ServiceStop, ServiceRestart, ServiceReload, ServiceEnable, ServiceDisable:
		return true
	}
	return false
}

// Service ist eine systemd-Unit in der Übersicht.
type Service struct {
	Unit        string `json:"unit"`
	Load        string `json:"load"`
	Active      string `json:"active"`
	Sub         string `json:"sub"`
	Description string `json:"description"`
	Enabled     string `json:"enabled"`
}

// Failed sagt, ob die Unit in einem Fehlerzustand ist.
func (s Service) Failed() bool { return s.Active == "failed" || s.Sub == "failed" }

// Running sagt, ob die Unit läuft.
func (s Service) Running() bool { return s.Active == "active" }

// ServiceDetail ergänzt die Übersicht um Details und die letzten Logzeilen.
type ServiceDetail struct {
	Service
	Since      string     `json:"since"`
	MainPID    int        `json:"main_pid"`
	Memory     uint64     `json:"memory"`
	Tasks      int        `json:"tasks"`
	FragmentP  string     `json:"fragment_path"`
	RecentLogs []LogEntry `json:"recent_logs"`
}

// ServiceFilter schränkt die Übersicht ein.
type ServiceFilter struct {
	Search     string
	OnlyFailed bool
	OnlyActive bool
}

// ---------------------------------------------------------------- Pakete ---

// Package ist ein aktualisierbares Paket.
type Package struct {
	Name           string `json:"name"`
	CurrentVersion string `json:"current_version"`
	NewVersion     string `json:"new_version"`
	Origin         string `json:"origin"`
	Security       bool   `json:"security"`
	Architecture   string `json:"architecture"`
}

// UpgradeOptions steuert ein Paket-Update.
type UpgradeOptions struct {
	// Packages leer bedeutet: alle aktualisierbaren Pakete.
	Packages []string
	// OnlySecurity beschränkt auf Pakete aus einer Sicherheitsquelle.
	OnlySecurity bool
}

// RebootState beschreibt, ob ein Neustart aussteht.
type RebootState struct {
	Required bool     `json:"required"`
	Packages []string `json:"packages"`
}

// PackageRefreshResult sagt, was ein Lauf von apt-get update erreicht hat.
//
// Nötig ist das wegen des Teilerfolgs: apt beendet sich mit 100, sobald eine
// einzige Quelle klemmt — auch dann, wenn alle übrigen aktualisiert wurden. Ein
// nackter Fehler wäre an dieser Stelle falsch. Er war es bisher: Auf einem
// Server mit einer aufgegebenen PPA meldete das Panel „Paketlisten konnten
// nicht aktualisiert werden", obwohl die Listen von Ubuntu und Docker frisch
// waren.
type PackageRefreshResult struct {
	// Reached zählt die Antworten (Hit/Get). Es ist die Kennzahl dafür, dass
	// überhaupt etwas geglückt ist, und keine Zahl für die Anzeige: apt zählt
	// Indexdateien, nicht Quellen — eine Quelle liefert mehrere.
	Reached int
	// Failed sind die Quellen, die nicht abgeholt werden konnten.
	Failed []SourceFailure
}

// Partial sagt, ob der Lauf teils geglückt ist: einzelne Quellen klemmen, die
// übrigen sind auf dem neuen Stand.
func (r PackageRefreshResult) Partial() bool { return len(r.Failed) > 0 && r.Reached > 0 }

// SourceFailure ist eine Quelle, die apt nicht abholen konnte, mit dem Grund
// aus der Folgezeile (etwa „403 Forbidden").
type SourceFailure struct {
	Source string
	Reason string
}

// -------------------------------------------------------------- Firewall ---

// FirewallBackend benennt das erkannte Regelwerk.
type FirewallBackend string

const (
	BackendUFW      FirewallBackend = "ufw"
	BackendNFTables FirewallBackend = "nftables"
	BackendNone     FirewallBackend = "keins"
)

// FirewallState ist der aktuelle Zustand der Firewall.
type FirewallState struct {
	Backend FirewallBackend `json:"backend"`
	Active  bool            `json:"active"`
	Rules   []FirewallRule  `json:"rules"`
	// Managed sagt, ob das Panel den Regelsatz verwaltet oder ihn nur anzeigt.
	Managed bool `json:"managed"`
	// Installed sagt, ob das Paket ufw vorhanden ist. Ohne diese Angabe sähen
	// "nicht installiert" und "installiert, aber kaputt" gleich aus, und die
	// Oberfläche könnte nicht entscheiden, ob sie zum Installieren oder zum
	// Aktivieren auffordert.
	Installed bool `json:"installed"`
	// Notice erklärt bei nicht verwalteten Regelwerken, warum.
	Notice string `json:"notice"`
}

// FirewallRule ist eine Regel für eingehenden Verkehr.
type FirewallRule struct {
	Port     int    `json:"port"`
	Protocol string `json:"protocol"` // tcp | udp
	Source   string `json:"source"`   // leer = überall
	Comment  string `json:"comment"`
}

// -------------------------------------------------- Systembenutzer und SSH ---

// SystemUser ist ein Benutzer aus /etc/passwd.
type SystemUser struct {
	Name    string   `json:"name"`
	UID     int      `json:"uid"`
	GID     int      `json:"gid"`
	Comment string   `json:"comment"`
	Home    string   `json:"home"`
	Shell   string   `json:"shell"`
	Groups  []string `json:"groups"`
	Locked  bool     `json:"locked"`
	System  bool     `json:"system"`
	// Protected: Das Konto lässt sich über das Panel nicht sperren oder
	// löschen. Die Prüfung sitzt in protectedUser und greift ohnehin — aber
	// eine Oberfläche, die "löschen" anbietet und dann verweigert, ist die
	// schlechteste der möglichen Antworten. root gehört dazu.
	Protected bool `json:"protected"`
	SSHKeys   int  `json:"ssh_keys"`
	HasShell  bool `json:"has_shell"`
}

// SystemUserSpec beschreibt einen anzulegenden Benutzer.
type SystemUserSpec struct {
	Name       string
	Comment    string
	Shell      string
	Groups     []string
	CreateHome bool
	SSHKey     string
}

// SSHKey ist ein Eintrag aus authorized_keys.
type SSHKey struct {
	Type        string `json:"type"`
	Comment     string `json:"comment"`
	Fingerprint string `json:"fingerprint"`
	Bits        int    `json:"bits"`
	Line        string `json:"-"`
}

// ------------------------------------------------------------------ Logs ---

// LogQuery filtert die journald-Abfrage.
type LogQuery struct {
	Unit     string
	Priority int // 0–7, -1 = alle
	Since    string
	Search   string
	Limit    int
}

// LogEntry ist eine Zeile aus dem Journal.
type LogEntry struct {
	At       time.Time `json:"at"`
	Unit     string    `json:"unit"`
	Priority int       `json:"priority"`
	Message  string    `json:"message"`
	Host     string    `json:"host"`
}

// PriorityName übersetzt die syslog-Stufe in einen lesbaren Namen.
func (e LogEntry) PriorityName() string {
	switch e.Priority {
	case 0:
		return "emerg"
	case 1:
		return "alert"
	case 2:
		return "crit"
	case 3:
		return "err"
	case 4:
		return "warn"
	case 5:
		return "notice"
	case 6:
		return "info"
	case 7:
		return "debug"
	default:
		return "?"
	}
}
