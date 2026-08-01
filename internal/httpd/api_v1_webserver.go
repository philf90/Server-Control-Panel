package httpd

// Webserver über /api/v1.
//
// Die Stufe 0.6 aus docs/16-neukonzeption.md §5, ausgeführt in
// docs/18-webserver.md. Dies ist der erste Schritt: der Zustand des Webservers
// und seine Installation. Sites, Zertifikate und der Schreibpfad folgen — die
// Seite sagt das, statt eine leere Fläche zu zeigen.
//
// Drei Festlegungen, die für das ganze Modul gelten und deshalb hier stehen:
//
//  1. **Verwaltet wird nginx, und nur nginx.** Caddy, Apache, lighttpd, ein
//     Traefik im Container — alles davon wird erkannt, benannt und nicht
//     angefasst. docs/18-webserver.md E1.
//  2. **Schreiben verlangt die Owner-Rolle.** Eine Site ist eine Konfiguration,
//     die als root gelesen wird und einen Dienst aus dem Netz erreichbar macht.
//     Dieselbe Begründung wie bei Docker und den Zeitplänen.
//  3. **Eingespielt wird nur bei freiem Port.** Das ist die eigentliche Aufgabe
//     dieser Datei, und sie steht weiter unten ausführlich: Der
//     Installationsknopf ist die einzige Aktion dieses Moduls, die einen
//     laufenden Server umbringen kann.

import (
	"context"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// jobWebserverInstall ist die Vorgangsart der Installation.
const jobWebserverInstall = "webserver-install"

// apiWebserverLauscher ist ein Prozess auf Port 80 oder 443.
type apiWebserverLauscher struct {
	Port    int    `json:"port"`
	Adresse string `json:"adresse"`
	Prozess string `json:"prozess"`
	// Eigen heißt: Das ist nginx selbst. Die Oberfläche färbt danach — ein
	// eigener Lauscher ist der Normalzustand, ein fremder ist die Erklärung
	// dafür, dass hier kein Knopf steht.
	Eigen bool `json:"eigen"`
}

// apiWebserver ist die Antwort von GET /api/v1/webserver.
//
// Sie trägt den vollständigen Zustand der Seite in einem Aufruf, einschließlich
// eines laufenden Vorgangs: Wer die Seite neu lädt, während apt arbeitet, muss
// den Auszug vorfinden und nicht einen Knopf, der behauptet, es sei nichts los.
type apiWebserver struct {
	Installiert bool   `json:"installiert"`
	Version     string `json:"version"`
	// Paket nennt, woher nginx stammt — "nginx", "nginx-core" oder leer bei
	// einer Installation an apt vorbei. Grundsatz IV: Was das Panel weiß, sagt es.
	Paket       string `json:"paket"`
	DienstAktiv bool   `json:"dienst_aktiv"`

	// Lauscher ist die Belegung von Port 80 und 443, so wie sie ist.
	Lauscher []apiWebserverLauscher `json:"lauscher"`
	// PortsGeprueft sagt, ob die Belegung überhaupt ermittelt werden konnte.
	// Eine leere Liste bei false heißt „unbekannt" und nicht „frei" — der
	// Unterschied entscheidet über den Knopf.
	PortsGeprueft bool `json:"ports_geprueft"`
	// Fremd nennt die Programme, die auf den Webports hören und nicht nginx
	// sind. Fertig zusammengefasst, damit die Oberfläche keine zweite Auslegung
	// derselben Frage baut.
	Fremd []string `json:"fremd"`

	// Anmerkung ist der nächste Handgriff zum Zustand, vom Server formuliert.
	Anmerkung string `json:"anmerkung"`
	// Einspielbar sagt, ob das Panel hier etwas ausrichten kann — und ob es
	// darf. Siehe einspielbar().
	Einspielbar bool `json:"einspielbar"`
	// DarfAendern sagt, ob diese Sitzung das Modul bedienen darf — Owner-Rolle.
	DarfAendern bool    `json:"darf_aendern"`
	Job         *apiJob `json:"job"`
	// Fehler steht als Feld und nicht als Statuscode: Der Rest der Auskunft gilt
	// weiter, und eine Seite, die wegen einer Teilauskunft ganz leer bleibt,
	// verschweigt mehr als sie erklärt.
	Fehler string `json:"fehler,omitempty"`
}

// handleAPIWebserver liefert den Zustand des Webservers.
func (s *Server) handleAPIWebserver(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	antwort := apiWebserver{
		DarfAendern: user.CanManageUsers(),
		Job:         s.jobAus(jobWebserverInstall),
	}

	st, err := s.ops.WebServerState(r.Context())
	if err != nil {
		antwort.Fehler = err.Error()
		s.apiJSON(w, http.StatusOK, antwort)
		return
	}

	antwort.Installiert = st.Installiert
	antwort.Version = st.Version
	antwort.Paket = st.Paket
	antwort.DienstAktiv = st.DienstAktiv
	antwort.PortsGeprueft = st.LauscherGeprueft
	for _, l := range st.Lauscher {
		antwort.Lauscher = append(antwort.Lauscher, apiWebserverLauscher{
			Port: l.Port, Adresse: l.Adresse, Prozess: l.Prozess,
			Eigen: l.Prozess == "nginx",
		})
	}
	antwort.Fremd = fremdeNamen(st)
	antwort.Anmerkung = webserverAnmerkung(st)
	antwort.Einspielbar = einspielbar(st)

	s.apiJSON(w, http.StatusOK, antwort)
}

// einspielbar beantwortet die eine Frage, an der dieses Modul Schaden anrichten
// kann: Darf das Panel jetzt nginx einspielen?
//
// Drei Bedingungen, und die zweite ist die, die man vergisst:
//
//  1. nginx fehlt. Ist es da, richtet ein apt-Lauf nichts aus.
//  2. **Die Portbelegung ist bekannt.** „Nicht geprüft" ist kein „frei". Ohne
//     diese Zeile erschiene der Knopf ausgerechnet dann, wenn das Panel nichts
//     weiß — dieselbe Haltung wie bei ConfigCheckResult.Checked.
//  3. Auf 80 und 443 hört niemand Fremdes. `apt-get install nginx` startet
//     nginx, nginx bindet Port 80, und was dort lief, ist weg.
//
// Bewusst KEINE Rechteprüfung: Ob diese Sitzung darf, steht in DarfAendern, und
// die Antwort darauf gibt der Handler. Beides in ein Feld zu mischen hieße, dass
// ein Leserecht-Konto „nicht einspielbar" liest und den Server für belegt hält.
func einspielbar(st privops.WebServerState) bool {
	return !st.Installiert && st.LauscherGeprueft && len(st.Belegt()) == 0
}

// fremdeNamen sammelt die Programme auf den Webports, die nicht nginx sind.
//
// Ohne Wiederholungen und in stabiler Reihenfolge: Ein Webserver hört meist
// zweimal auf denselben Port (IPv4 und IPv6) und oft auf beide Ports —
// „caddy, caddy, caddy, caddy" wäre keine bessere Auskunft als „caddy".
//
// Ein Lauscher ohne Namen bekommt einen Platzhalter statt weggelassen zu
// werden: Dass dort jemand hört, steht fest, auch wenn ss ihn nicht benennen
// konnte, und das ist die Richtung, in die der Zweifel gehen muss.
func fremdeNamen(st privops.WebServerState) []string {
	var aus []string
	gesehen := map[string]bool{}
	for _, l := range st.Belegt() {
		name := l.Prozess
		if name == "" {
			name = "ein unbekanntes Programm"
		}
		if gesehen[name] {
			continue
		}
		gesehen[name] = true
		aus = append(aus, name)
	}
	return aus
}

// webserverAnmerkung formuliert den nächsten Handgriff zum Zustand.
//
// Der Satz steht hier und nicht in privops — dieselbe Trennung wie bei Docker:
// „nginx ist nicht installiert" ist eine Auskunft über das System, „das Panel
// kann es einspielen" ist eine Empfehlung an den Bedienenden. Empfehlung und
// Knopf gehören in dieselbe Schicht, sonst kann eines von beiden fehlen, ohne
// dass es auffällt.
//
// Die Reihenfolge ist die der Handgriffe und nicht die der Schwere: Was zuerst
// im Weg steht, wird zuerst genannt.
func webserverAnmerkung(st privops.WebServerState) string {
	fremde := fremdeNamen(st)

	switch {
	case !st.Installiert && !st.LauscherGeprueft:
		return "Das Panel konnte nicht feststellen, wer auf den Ports 80 und 443 hört. " +
			"Ohne diese Auskunft bietet es die Installation nicht an: nginx würde beim " +
			"Start Port 80 übernehmen, und ein Webserver, der dort schon läuft, wäre weg."
	case !st.Installiert && len(fremde) > 0:
		return fmt.Sprintf("Auf %s hört bereits %s. nginx würde den Port beim Start "+
			"übernehmen, deshalb installiert das Panel hier nichts. Die Konfiguration "+
			"bleibt über die Dateien erreichbar — das Panel fasst sie nicht an.",
			portWort(st), nennung(fremde))
	case !st.Installiert:
		return "nginx ist auf diesem Server nicht installiert. Das Panel kann es aus " +
			"den Paketquellen der Distribution installieren."
	case !st.DienstAktiv:
		return "nginx ist installiert, aber der Dienst läuft nicht. Unter Dienste lässt " +
			"sich nginx.service starten — ein apt-Lauf hilft hier nicht."
	case len(fremde) > 0:
		return fmt.Sprintf("Neben nginx hört auch %s auf %s. Zwei Server auf demselben "+
			"Port vertragen sich nicht; welcher antwortet, hängt daran, wer zuerst da war.",
			nennung(fremde), portWort(st))
	default:
		return ""
	}
}

// portWort nennt die belegten Webports als Text — „Port 80", „Port 443" oder
// „den Ports 80 und 443". Eine Meldung, die immer beide Ports nennt, obwohl nur
// einer belegt ist, schickt jemanden an die falsche Stelle.
func portWort(st privops.WebServerState) string {
	achtzig, vierhundert := false, false
	for _, l := range st.Belegt() {
		switch l.Port {
		case 80:
			achtzig = true
		case 443:
			vierhundert = true
		}
	}
	switch {
	case achtzig && vierhundert:
		return "den Ports 80 und 443"
	case vierhundert:
		return "Port 443"
	default:
		return "Port 80"
	}
}

// nennung reiht Namen zu einer lesbaren Aufzählung.
func nennung(namen []string) string {
	switch len(namen) {
	case 0:
		return ""
	case 1:
		return namen[0]
	default:
		return strings.Join(namen[:len(namen)-1], ", ") + " und " + namen[len(namen)-1]
	}
}

// handleAPIWebserverInstall spielt nginx ein — als Vorgang, wie ufw und Docker.
//
// Ohne Rückfrage, aber NICHT ohne Prüfung. Der Unterschied zu den beiden
// Vorgängern ist der Grund, warum dieser Handler länger ist als seine
// Geschwister: Ein `apt-get install ufw` nimmt niemandem etwas weg. Ein
// `apt-get install nginx` startet nginx, nginx bindet Port 80, und ein
// Webserver, der dort lief, ist vom Netz. Das ist die einzige Aktion dieses
// Moduls, die einen Server im Betrieb umbringen kann — und sie kommt aus einem
// Knopf, den jemand aus Neugier drückt.
//
// Deshalb wird der Zustand hier NOCHMALS gelesen und nicht dem geglaubt, was die
// Seite eine Minute vorher angezeigt hat. Zwischen dem Laden der Seite und dem
// Klick liegt beliebig viel Zeit, und ein Knopf, der nur zum Zeitpunkt seiner
// Beschriftung stimmte, ist keine Sicherung.
func (s *Server) handleAPIWebserverInstall(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	st, err := s.ops.WebServerState(r.Context())
	if err != nil {
		s.apiFehler(w, http.StatusBadGateway,
			"Der Zustand des Webservers ließ sich nicht ermitteln: "+err.Error())
		return
	}
	switch {
	case st.Installiert:
		s.apiFehler(w, http.StatusConflict, "nginx ist auf diesem Server bereits installiert.")
		return
	case !st.LauscherGeprueft:
		s.apiFehler(w, http.StatusConflict,
			"Das Panel konnte nicht feststellen, wer auf den Ports 80 und 443 hört, "+
				"und installiert deshalb nichts.")
		return
	case len(st.Belegt()) > 0:
		s.apiFehler(w, http.StatusConflict, fmt.Sprintf(
			"Auf %s hört bereits %s. nginx würde den Port beim Start übernehmen.",
			portWort(st), nennung(fremdeNamen(st))))
		return
	}

	j, neu := s.jobs.start(jobWebserverInstall, user.Username)
	if !neu {
		s.apiFehler(w, http.StatusConflict, "Die Installation läuft bereits.")
		return
	}
	s.audit(r, "webserver.install", "nginx", store.ResultOK, "gestartet")

	// Eigener Kontext, wie bei jedem apt-Lauf: Ein abgebrochener Seitenaufruf
	// darf kein halb konfiguriertes dpkg hinterlassen.
	go func() { //nolint:gosec // eigener Kontext ist hier Absicht
		ctx, cancel := context.WithTimeout(context.Background(), 20*time.Minute)
		defer cancel()

		err := s.ops.WebServerInstall(ctx, j.append)
		j.finish(err)

		result, detail := store.ResultOK, "abgeschlossen"
		if err != nil {
			result, detail = store.ResultError, err.Error()
		}
		s.auditNachtraeglich(user.Username, "webserver.install", "nginx", result, detail)
	}()

	s.gestartet(w, jobWebserverInstall, "nginx wird installiert.")
}
