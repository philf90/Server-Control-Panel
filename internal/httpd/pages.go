package httpd

import (
	"net/http"

	"github.com/philf90/asylum/internal/metrics"
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
}

type usersPage struct {
	Users []store.User
}
