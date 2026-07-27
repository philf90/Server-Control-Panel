package httpd

import (
	"net/http"

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
	Content  any
}

func (s *Server) base(r *http.Request, title, nav string) basePage {
	p := basePage{
		Title:   title,
		Nav:     nav,
		Version: version.String(),
		Host:    s.sampler.Host(),
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
}

type auditPage struct {
	Entries []store.AuditEntry
}

type accountPage struct {
	RecoveryCodesLeft int
	NewCodes          []string
	Sessions          []sessionView
	OtherSessions     int
}

type usersPage struct {
	Users []store.User
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
}

type sysUsersPage struct {
	Users    []privops.SystemUser
	Selected string
	Keys     []privops.SSHKey
}

type logsPage struct {
	Entries []privops.LogEntry
	Units   []string
	Query   privops.LogQuery
}
