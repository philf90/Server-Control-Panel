package httpd

// Docker über /api/v1.
//
// Die Stufe 0.5 aus docs/16-neukonzeption.md §5, ausgeführt in docs/17-docker.md.
// Dies ist der erste Schritt: der Zustand der Laufzeit und ihre Installation.
// Container, Stacks und Bestand folgen — die Seite sagt das, statt eine leere
// Fläche zu zeigen.
//
// Zwei Festlegungen, die für das ganze Modul gelten und deshalb hier stehen:
//
//  1. **Schreiben verlangt die Owner-Rolle**, nicht bloß Schreibrecht. Der Grund
//     ist derselbe wie bei den Zeitplänen, nur schärfer: Wer den Docker-Socket
//     hat, hat die Maschine — ein Container mit "-v /:/host" ist root auf dem
//     Wirt. Ein Admin-Konto, das Dienste neu starten darf, soll nicht nebenbei
//     die Rechtetrennung des Servers aufheben können.
//  2. **Die Rechteauskunft steht in der Antwort des Moduls** (DarfAendern) und
//     nicht in der Sitzung. „Darf schreiben" ist die falsche Frage — sie
//     stimmte für Dienste und wäre hier zu weit. Eine zweite Auslegung der
//     Rolle in der Oberfläche wäre die Stelle, an der beide auseinanderlaufen.
//
// Warum die Installation ein Vorgang ist und keine Anfrage: apt-get läuft
// Minuten und schreibt dabei Zeilen, die man sehen will. Dasselbe Muster wie
// bei ufw seit rc.4 — und dieselbe Haltung: Fehlt das Werkzeug, bietet das
// Panel es an, statt eine Kommandozeile zum Abtippen zu drucken.

import (
	"context"
	"net/http"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// jobDockerInstall ist die Vorgangsart der Installation.
const jobDockerInstall = "docker-install"

// apiDocker ist die Antwort von GET /api/v1/docker.
//
// Sie trägt den vollständigen Zustand der Seite in einem Aufruf, einschließlich
// eines laufenden Vorgangs: Wer die Seite neu lädt, während apt arbeitet, muss
// den Auszug vorfinden und nicht einen Knopf, der behauptet, es sei nichts los.
type apiDocker struct {
	Installiert bool `json:"installiert"`
	// Paket nennt, woher Docker stammt — "docker.io", "docker-ce" oder leer bei
	// einer Installation an apt vorbei. Grundsatz IV: Was das Panel weiß, sagt es.
	Paket             string `json:"paket"`
	DaemonLaeuft      bool   `json:"daemon_laeuft"`
	ClientVersion     string `json:"client_version"`
	ServerVersion     string `json:"server_version"`
	ComposeVerfuegbar bool   `json:"compose_verfuegbar"`
	ComposeVersion    string `json:"compose_version"`
	// Anmerkung ist der nächste Handgriff zum Zustand, vom Server formuliert.
	Anmerkung string `json:"anmerkung"`
	// Einspielbar sagt, ob das Panel hier etwas ausrichten kann. Nicht dasselbe
	// wie "nicht installiert": Bei totem Daemon oder bei Docker an apt vorbei
	// hilft kein apt-Lauf, und ein Knopf, der zuverlässig nichts bewirkt, ist
	// schlimmer als keiner.
	Einspielbar bool `json:"einspielbar"`
	// DarfAendern sagt, ob diese Sitzung das Modul bedienen darf — Owner-Rolle.
	DarfAendern bool    `json:"darf_aendern"`
	Job         *apiJob `json:"job"`
	// Fehler steht als Feld und nicht als Statuscode: Der Rest der Auskunft gilt
	// weiter, und eine Seite, die wegen einer Teilauskunft ganz leer bleibt,
	// verschweigt mehr als sie erklärt.
	Fehler string `json:"fehler,omitempty"`
}

// handleAPIDocker liefert den Zustand der Container-Laufzeit.
func (s *Server) handleAPIDocker(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	antwort := apiDocker{
		DarfAendern: user.CanManageUsers(),
		Job:         s.jobAus(jobDockerInstall),
	}

	st, err := s.ops.DockerState(r.Context())
	if err != nil {
		antwort.Fehler = err.Error()
		s.apiJSON(w, http.StatusOK, antwort)
		return
	}

	antwort.Installiert = st.Installiert
	antwort.Paket = st.Paket
	antwort.DaemonLaeuft = st.DaemonLaeuft
	antwort.ClientVersion = st.ClientVersion
	antwort.ServerVersion = st.ServerVersion
	antwort.ComposeVerfuegbar = st.ComposeVerfuegbar
	antwort.ComposeVersion = st.ComposeVersion
	antwort.Anmerkung = dockerAnmerkung(st)

	// Einspielen hilft in genau zwei Lagen: Docker fehlt ganz, oder es ist da
	// und Compose fehlt. Läuft der Daemon nicht, ist das ein Dienstproblem —
	// dann gehört der Weg auf die Dienstseite und nicht auf einen apt-Lauf.
	antwort.Einspielbar = !st.Installiert || (st.DaemonLaeuft && !st.ComposeVerfuegbar)

	s.apiJSON(w, http.StatusOK, antwort)
}

// dockerAnmerkung formuliert den nächsten Handgriff zum Zustand.
//
// Der Satz steht hier und nicht in privops, obwohl FirewallState seinen eigenen
// mitbringt. Der Unterschied: „ufw ist installiert, aber die Installation ist
// unvollständig" ist eine Auskunft über das System, „das Panel kann es
// einspielen" ist eine Empfehlung an den Bedienenden. Empfehlung und Knopf
// gehören in dieselbe Schicht — sonst kann eines von beiden fehlen, ohne dass
// es auffällt.
//
// Genau das ist passiert: Solange der Satz aus privops kam, zeigte die Seite im
// Zustand „nicht installiert" drei Karten und kein einziges Wort dazu. Gefunden
// hat das der Browsertest, kein Go-Test — der prüfte das Feld, das er selbst
// gesetzt hatte.
//
// Die Reihenfolge ist die der Handgriffe und nicht die der Schwere: Was zuerst
// fehlt, wird zuerst genannt.
func dockerAnmerkung(st privops.DockerState) string {
	switch {
	case !st.Installiert:
		return "Docker ist auf diesem Server nicht installiert. Das Panel kann es aus " +
			"den Paketquellen der Distribution einspielen."
	case !st.DaemonLaeuft:
		return "Docker ist installiert, aber der Daemon antwortet nicht. Unter Dienste " +
			"lässt sich docker.service starten — ein apt-Lauf hilft hier nicht."
	case !st.ComposeVerfuegbar:
		return "Docker läuft, aber \"docker compose\" fehlt. Stacks brauchen es; " +
			"das Panel kann es nachziehen."
	default:
		return ""
	}
}

// handleAPIDockerInstall spielt Docker ein — als Vorgang, wie ufw.
//
// Ohne Rückfrage: Ein Paket aus den Quellen der Distribution zu installieren
// nimmt nichts weg und sperrt niemanden aus. Stufe 1 nach
// docs/14-bestaetigungen.md. Die Rückfragen dieses Moduls kommen mit den
// Aktionen, die etwas beenden oder löschen.
func (s *Server) handleAPIDockerInstall(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	j, neu := s.jobs.start(jobDockerInstall, user.Username)
	if !neu {
		s.apiFehler(w, http.StatusConflict, "Die Installation läuft bereits.")
		return
	}
	s.audit(r, "docker.install", "docker", store.ResultOK, "gestartet")

	// Eigener Kontext, wie bei jedem apt-Lauf: Ein abgebrochener Seitenaufruf
	// darf kein halb konfiguriertes dpkg hinterlassen.
	go func() { //nolint:gosec // eigener Kontext ist hier Absicht
		ctx, cancel := context.WithTimeout(context.Background(), 20*time.Minute)
		defer cancel()

		err := s.ops.DockerInstall(ctx, j.append)
		j.finish(err)

		result, detail := store.ResultOK, "abgeschlossen"
		if err != nil {
			result, detail = store.ResultError, err.Error()
		}
		s.auditNachtraeglich(user.Username, "docker.install", "docker", result, detail)
	}()

	s.gestartet(w, jobDockerInstall, "Docker wird eingespielt.")
}
