package acme

import (
	"context"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

// Die Anbieter lassen sich hier nicht gegen die echte API prüfen — es gibt
// keine Zugangsdaten und soll keine geben. Geprüft wird deshalb, was über die
// Leitung geht: gegen einen httptest-Server, der die Form der echten Antwort
// nachstellt. Dasselbe Verfahren wie bei den Docker-Parsern, und mit demselben
// Vorbehalt: Die Form stammt aus der Dokumentation des Anbieters, nicht aus
// einem Mitschnitt. Der erste echte Bezug gehört gegen das Staging-Verzeichnis.

// acmeDNSAttrappe nimmt die Anfrage entgegen und legt sie für den Test ab.
type acmeDNSAttrappe struct {
	pfad    string
	user    string
	key     string
	koerper map[string]string
	status  int
	antwort string
}

func (a *acmeDNSAttrappe) server(t *testing.T) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		a.pfad = r.URL.Path
		a.user = r.Header.Get("X-Api-User")
		a.key = r.Header.Get("X-Api-Key")
		b, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(b, &a.koerper)

		if a.status != 0 {
			w.WriteHeader(a.status)
			_, _ = io.WriteString(w, a.antwort)
			return
		}
		_, _ = io.WriteString(w, `{"txt":"…"}`)
	}))
	t.Cleanup(srv.Close)
	return srv
}

func acmeDNSGegen(srv *httptest.Server) *acmeDNSSetter {
	return &acmeDNSSetter{
		basis:     srv.URL,
		username:  "benutzer",
		password:  "geheim",
		subdomain: "a1b2c3",
		http:      &http.Client{Timeout: 5 * time.Second},
	}
}

func TestAcmeDNSSetztDenWert(t *testing.T) {
	a := &acmeDNSAttrappe{}
	srv := a.server(t)
	setter := acmeDNSGegen(srv)

	err := setter.setTXT(context.Background(),
		"example.com", "_acme-challenge.example.com", "der-wert")
	if err != nil {
		t.Fatalf("setTXT: %v", err)
	}

	if a.pfad != "/update" {
		t.Errorf("Pfad = %q, erwartet /update", a.pfad)
	}
	if a.user != "benutzer" || a.key != "geheim" {
		t.Errorf("Zugangsdaten gingen nicht als Kopfzeilen mit: user=%q key=%q", a.user, a.key)
	}
	// Der RECORDNAME geht nicht mit — acme-dns kennt genau eine Subdomain je
	// Konto. Steht er trotzdem drin, ist das ein Missverständnis über das
	// Protokoll und gehört gefunden.
	if a.koerper["subdomain"] != "a1b2c3" {
		t.Errorf("Subdomain = %q", a.koerper["subdomain"])
	}
	if a.koerper["txt"] != "der-wert" {
		t.Errorf("Wert = %q", a.koerper["txt"])
	}
	if _, drin := a.koerper["domain"]; drin {
		t.Errorf("der Domainname gehört nicht in die Anfrage: %+v", a.koerper)
	}
}

// Ein Fehler des Dienstes muss durchschlagen — und die Meldung des Dienstes
// mitnehmen. „acme-dns antwortete mit 401" ohne den Text sagt nicht, ob die
// Zugangsdaten falsch sind oder die Subdomain.
func TestAcmeDNSMeldetFehlerMitText(t *testing.T) {
	a := &acmeDNSAttrappe{status: http.StatusUnauthorized, antwort: `{"error":"forbidden"}`}
	srv := a.server(t)

	err := acmeDNSGegen(srv).setTXT(context.Background(), "example.com", "_acme-challenge.example.com", "wert")
	if err == nil {
		t.Fatal("401 muss ein Fehler sein")
	}
	if !strings.Contains(err.Error(), "401") || !strings.Contains(err.Error(), "forbidden") {
		t.Errorf("die Meldung des Dienstes fehlt: %v", err)
	}
}

// Eine Fehlerseite eines Proxys davor darf die Meldung nicht sprengen.
func TestAcmeDNSKuerztLangeFehlerseiten(t *testing.T) {
	a := &acmeDNSAttrappe{status: http.StatusBadGateway, antwort: strings.Repeat("x", 100_000)}
	srv := a.server(t)

	err := acmeDNSGegen(srv).setTXT(context.Background(), "example.com", "_acme-challenge.example.com", "wert")
	if err == nil {
		t.Fatal("502 muss ein Fehler sein")
	}
	if len(err.Error()) > 1000 {
		t.Errorf("die Meldung ist %d Zeichen lang — eine Fehlerseite gehört gekürzt", len(err.Error()))
	}
}

// Aufräumen tut nichts, und das ist kein Versäumnis: acme-dns hat keinen
// Endpunkt zum Löschen und braucht keinen — der Record steht in einer
// Wegwerf-Subdomain. Ein Fehler daraus zu machen hieße, jeden Bezug mit einer
// Warnung zu beenden, die nichts bedeutet.
func TestAcmeDNSAufraeumenSchweigt(t *testing.T) {
	a := &acmeDNSAttrappe{}
	srv := a.server(t)

	if err := acmeDNSGegen(srv).removeTXT(context.Background(), "example.com", "_acme-challenge.example.com", "wert"); err != nil {
		t.Errorf("Aufräumen darf nicht fehlschlagen: %v", err)
	}
	if a.pfad != "" {
		t.Errorf("Aufräumen soll gar nicht erst anfragen, ging aber an %q", a.pfad)
	}
}
