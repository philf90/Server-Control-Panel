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

	// Cron und systemd-Timer.
	//
	// CronWrite ist die einzige Operation dieses Interfaces, die einen FREIEN
	// Befehl entgegennimmt — ein Cron-Eintrag ist eine Shell-Zeile, cron gibt sie
	// an /bin/sh. Das ist keine Aufweichung des Verzichts auf eine Shell, sondern
	// das Wesen von cron, und es steht hier ausdrücklich, damit niemand es für ein
	// Versehen hält. Was den Weg eng hält, steht im Kopf von cron.go: geschrieben
	// werden nur eigene Dateien mit Marker, eine Datei je Eintrag, und das
	// Dateiformat ist geprüft (der Zeilenumbruch ist der Injektionsweg, nicht das
	// Semikolon).
	//
	// Für Timer gibt es bewusst nur Lesendes: Ein Timer ist eine Unit, und
	// start/stop/enable/disable laufen über ServiceAction. Anlegen fehlt, weil
	// dazu zwei Unit-Dateien samt ExecStart gehören — siehe timer.go.
	CronList(ctx context.Context) ([]CronEntry, []string, error)
	CronWrite(ctx context.Context, spec CronSpec) error
	CronDelete(ctx context.Context, name string) error
	TimerList(ctx context.Context) ([]Timer, error)
	TimerRuns(ctx context.Context, unit string) (TimerLauf, error)

	// Docker.
	//
	// Der Zugriff läuft über die Kommandozeile und nie über den Socket. Das ist
	// keine Bequemlichkeitsfrage: Wer den Docker-Socket hat, hat die Maschine,
	// und dieses Interface ist die Stelle, an der das Panel entscheidet, was es
	// überhaupt anbieten kann. Ein durchgereichter Socket wäre eine Operation
	// „tu irgendwas" — das Gegenteil dessen, wofür es dieses Interface gibt.
	// Ausführlich in docs/17-docker.md.
	DockerState(ctx context.Context) (DockerState, error)
	DockerInstall(ctx context.Context, stream LineWriter) error
	DockerContainers(ctx context.Context) ([]Container, error)
	DockerContainer(ctx context.Context, id string) (ContainerDetail, error)
	// DockerContainerDetails ist dasselbe für viele — in einem Prozess statt in
	// N. Siehe den Kommentar an der Umsetzung: Die Bestandsfläche hatte hier ein
	// N+1.
	DockerContainerDetails(ctx context.Context, ids []string) ([]ContainerDetail, error)
	DockerContainerAction(ctx context.Context, id string, a ContainerAction) error
	DockerContainerRemove(ctx context.Context, id string, erzwingen bool) error
	DockerContainerLogs(ctx context.Context, id string, zeilen int) ([]string, error)
	// DockerContainerLogsFollow ist nach LogsFollow die zweite Operation ohne
	// eigene Frist: Der Kontext des Betrachters ist die Frist.
	DockerContainerLogsFollow(ctx context.Context, id string, zeilen int, sink LineWriter) error
	DockerStats(ctx context.Context) ([]ContainerStats, error)
	DockerImages(ctx context.Context) ([]Image, error)
	DockerImageRemove(ctx context.Context, id string) error
	DockerVolumes(ctx context.Context) ([]Volume, error)
	DockerVolumeRemove(ctx context.Context, name string) error
	DockerNetworks(ctx context.Context) ([]Netz, error)
	DockerNetworkRemove(ctx context.Context, id string) error
	DockerDiskUsage(ctx context.Context) ([]Bestandsposten, error)
	// DockerPrune gibt zurück, wie viel Platz frei wurde — die Antwort, wegen
	// der jemand aufräumt.
	DockerPrune(ctx context.Context, art PruneArt, alleUnbenutzten bool, stream LineWriter) (string, error)
	// Compose-Stacks.
	//
	// StackDatei nimmt einen NAMEN und keinen Pfad: Wo die Datei liegt, sagt
	// Docker oder das verwaltete Verzeichnis. Käme der Pfad aus der Anfrage,
	// wäre das ein Weg, jede Datei des Servers zu lesen.
	StackList(ctx context.Context) ([]Stack, error)
	StackDatei(ctx context.Context, name string) (StackInhalt, error)
	// Schreiben und Bedienen. Jede dieser Methoden gibt die ComposePruefung mit
	// zurück, und das ist kein Beiwerk: Ein Stack, dessen Prüfung nicht OK ist,
	// wurde weder geschrieben noch gestartet. Der Aufrufer erfährt aus dem
	// Ergebnis, WAS der Prüfer gefunden hat — ein blankes „abgelehnt" wäre
	// keine Antwort, mit der jemand die Datei reparieren kann.
	//
	// panelPort geht durch, weil privops den Port des Panels nicht kennt und
	// nicht kennen soll. Er dient nur dem Hinweis auf eine Kollision.
	StackSchreiben(ctx context.Context, name, text string, panelPort int) (ComposePruefung, error)
	StackPruefen(ctx context.Context, name string, panelPort int) (ComposePruefung, error)
	StackAusfuehren(ctx context.Context, name string, aktion StackAktion, mitVolumes bool, panelPort int, stream LineWriter) (ComposePruefung, error)
	StackLoeschen(ctx context.Context, name string, stream LineWriter) error
	// DockerEventsFollow verfolgt den Ereignisstrom. Ohne eigene Frist: Der
	// Kontext des Betrachters ist die Frist, derselbe Vertrag wie bei
	// LogsFollow. Er beantwortet „warum ist der Container um 3 Uhr neu
	// gestartet" — eine Frage, auf die der Zustand allein keine Antwort hat.
	DockerEventsFollow(ctx context.Context, sink func(DockerEreignis)) error
	// DockerUpdatePruefen vergleicht ein Image mit der Registry. Das Ergebnis
	// trennt „geprüft" von „neu": Ohne belastbaren Vergleich meldet es „nicht
	// geprüft" und nie „veraltet" — eine Update-Prüfung, die falsch Alarm
	// schlägt, wird nach einer Woche nicht mehr gelesen.
	//
	// Ein Aufruf je Image. Die Ratengrenze sitzt eine Schicht darüber: Wie oft
	// überhaupt geprüft werden darf, ist keine Frage der Kommandozeile.
	DockerUpdatePruefen(ctx context.Context, ref string) (Updatestand, error)

	// Webserver.
	//
	// Verwaltet wird nginx, und nur nginx. Jeder andere Webserver wird erkannt
	// und nicht angefasst — die Begründung steht in docs/18-webserver.md E1 und
	// im Kopf von webserver.go.
	//
	// WebServerState liefert deshalb nicht nur „ist nginx da", sondern vor allem
	// WER PORT 80 UND 443 HÄLT. Daran hängt die einzige Aktion dieses Moduls,
	// die einen laufenden Server umbringen kann: Ein apt-Lauf für nginx startet
	// nginx, nginx bindet 80, und was dort lief, ist weg.
	WebServerState(ctx context.Context) (WebServerState, error)
	WebServerInstall(ctx context.Context, stream LineWriter) error
	// SiteList liest die Serverblöcke aus der GERENDERTEN Konfiguration
	// (`nginx -T`), nicht aus den Dateien. `include` ist bei nginx die Regel;
	// wer die Dateien selbst zusammensucht, baut den Auflöser nach.
	//
	// SiteBestand.Gelesen trennt „keine Sites" von „nicht nachsehen können" —
	// nginx -T läuft nur bei gültiger Konfiguration.
	SiteList(ctx context.Context) (SiteBestand, error)
	// SiteDatei liefert den Inhalt einer VERWALTETEN Site. Nimmt einen Namen und
	// keinen Pfad — derselbe Vertrag wie bei StackDatei.
	SiteDatei(ctx context.Context, name string) (string, error)
	// SiteApply schreibt eine Site: prüfen, schreiben, `nginx -t`, bei Fehler
	// zurücknehmen, neu laden. Die Frist der Probe hält der Aufrufer; was er
	// dafür braucht, steht in SiteErgebnis.Ruecknahme.
	//
	// fassung ist der Hash der Datei, die der Aufrufer gelesen hat. Stimmt er
	// nicht mehr, wird nicht geschrieben (ErrSiteFassung). Leer heißt „neu".
	SiteApply(ctx context.Context, e SiteEntwurf, lage SiteLage, fassung string) (SiteErgebnis, error)
	// SiteSchalten schaltet eine Site an oder ab (Umbenennen der Endung).
	SiteSchalten(ctx context.Context, name string, an bool) (SiteRuecknahme, error)
	// SiteRemove löscht eine Site samt ihrer abgeschalteten Fassung.
	SiteRemove(ctx context.Context, name string) (SiteRuecknahme, error)
	// SiteRestore nimmt eine Änderung zurück — der Rückweg der Probe.
	SiteRestore(ctx context.Context, r SiteRuecknahme) error
	// AcmeWebroot legt den Weg für die HTTP-01-Prüfung DURCH nginx hindurch und
	// gibt das Verzeichnis zurück, in das die Token gehören.
	//
	// Er behebt einen Fehler, den die Installation erst erzeugt: Das Panel
	// bindet für HTTP-01 selbst Port 80 — sobald nginx läuft, gehört der Port
	// nginx, und das Panel kann sein eigenes Zertifikat nicht mehr erneuern.
	// Ausführlich in webserveracme.go und docs/18-webserver.md §3.
	AcmeWebroot(ctx context.Context, domains []string) (string, error)

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
