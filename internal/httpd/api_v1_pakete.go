package httpd

// Pakete über /api/v1.
//
// Das zweite Modul, und das erste mit einer Handlung, die Minuten dauert. Es
// legt damit fest, wie lange Aktionen in der neuen Oberfläche aussehen:
//
//   - Der POST startet den Vorgang und ist sofort zurück (202). Er wartet nicht
//     auf apt. Eine Anfrage, die zwanzig Minuten offen bleibt, überlebt keinen
//     Zwischenserver und kein WLAN.
//   - Zusehen läuft über /api/v1/jobs/{art}, für alle Module gleich.
//   - Es läuft höchstens ein Paketvorgang. Zwei apt-Läufe blockieren sich an der
//     dpkg-Sperre; das soll die Oberfläche verhindern und nicht ausprobieren.
//
// Was hier NICHT passiert: eine zweite Fassung der Vorgangsverwaltung. Gestartet
// wird über dieselbe jobs-Registry und mit denselben eigenen Kontexten wie in der
// alten Oberfläche — ein Vorgang, der doppelt gestartet werden kann, weil zwei
// Registries nichts voneinander wissen, wäre der schlimmere Fehler.

import (
	"context"
	"fmt"
	"net/http"
	"sort"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// apiPaket ist eine Zeile der Paketliste.
type apiPaket struct {
	Name        string `json:"name"`
	Von         string `json:"von"`
	Nach        string `json:"nach"`
	Quelle      string `json:"quelle"`
	Architektur string `json:"architektur"`
	Sicherheit  bool   `json:"sicherheit"`
}

// apiNeustart sagt, ob ein Neustart aussteht und wegen welcher Pakete.
type apiNeustart struct {
	Erforderlich bool     `json:"erforderlich"`
	Pakete       []string `json:"pakete"`
}

// apiPakete ist die Antwort von GET /api/v1/packages.
type apiPakete struct {
	Pakete  []apiPaket `json:"pakete"`
	Zaehler struct {
		Gesamt     int `json:"gesamt"`
		Sicherheit int `json:"sicherheit"`
	} `json:"zaehler"`
	Neustart apiNeustart `json:"neustart"`
	// Job ist der laufende oder letzte Paketvorgang, null wenn es keinen gibt.
	// Er steht in dieser Antwort und nicht nur unter /api/v1/jobs, damit die
	// Seite mit einem Aufruf vollständig ist: Wer nach einem Neuladen
	// hereinkommt, sieht den laufenden Vorgang ohne eine zweite Runde.
	Job *apiJob `json:"job"`
	// Rechnername ist das Wort, das beim Neustart getippt werden muss. Es steht
	// hier, weil die Oberfläche es nicht erraten kann und die Rückfrage es
	// ohnehin mitschickt — so kann sie den Knopf schon vorher richtig
	// beschriften.
	Rechnername string `json:"rechnername"`
	// Fehler ist gesetzt, wenn die Liste nicht zu lesen war. Als Feld und nicht
	// als Statuscode: Die Neustartmarkierung und ein laufender Vorgang können
	// trotzdem gelten, und die wegzulassen, weil apt klemmt, wäre die schlechtere
	// Antwort.
	Fehler string `json:"fehler"`
}

func (s *Server) handleAPIPackages(w http.ResponseWriter, r *http.Request) {
	ctx := r.Context()
	antwort := apiPakete{
		Pakete:      []apiPaket{},
		Job:         s.jobAus(jobPackages),
		Rechnername: s.rechnername(),
	}

	pakete, err := s.ops.PackageUpgradable(ctx)
	if err != nil {
		s.log.Error("pakete lesen", "err", err)
		antwort.Fehler = "Die Paketliste ist nicht verfügbar: " + err.Error()
	}
	for _, p := range pakete {
		antwort.Pakete = append(antwort.Pakete, apiPaket{
			Name:        p.Name,
			Von:         p.CurrentVersion,
			Nach:        p.NewVersion,
			Quelle:      p.Origin,
			Architektur: p.Architecture,
			Sicherheit:  p.Security,
		})
	}

	// Sicherheitsupdates zuerst, danach alphabetisch — dieselbe Regel wie bei
	// den Diensten: Was der Grund ist, die Seite zu öffnen, steht oben. Sortiert
	// auf dem Server, damit die Reihenfolge nicht von der Reihenfolge von apt
	// abhängt.
	sort.SliceStable(antwort.Pakete, func(i, j int) bool {
		a, b := antwort.Pakete[i], antwort.Pakete[j]
		if a.Sicherheit != b.Sicherheit {
			return a.Sicherheit
		}
		return a.Name < b.Name
	})

	antwort.Zaehler.Gesamt = len(antwort.Pakete)
	for _, p := range antwort.Pakete {
		if p.Sicherheit {
			antwort.Zaehler.Sicherheit++
		}
	}

	// Die Neustartmarkierung ist eine eigene Auskunft und darf einzeln
	// scheitern: /var/run/reboot-required zu lesen hat nichts mit apt zu tun.
	if neustart, err := s.ops.RebootRequired(ctx); err == nil {
		antwort.Neustart = apiNeustart{Erforderlich: neustart.Required, Pakete: neustart.Packages}
	} else {
		s.log.Warn("reboot-required lesen", "err", err)
	}
	if antwort.Neustart.Pakete == nil {
		antwort.Neustart.Pakete = []string{}
	}

	s.apiJSON(w, http.StatusOK, antwort)
}

// apiVorgangGestartet ist die Antwort auf einen gestarteten Vorgang.
type apiVorgangGestartet struct {
	Meldung string `json:"meldung"`
	Job     apiJob `json:"job"`
}

// gestartet antwortet mit 202 und dem frischen Vorgang.
//
// 202 und nicht 200: Die Arbeit ist angenommen, nicht getan. Der Unterschied ist
// für die Oberfläche keiner — sie hängt sich an den Strom —, für einen späteren
// Aufrufer über die CLI aber schon.
func (s *Server) gestartet(w http.ResponseWriter, art, meldung string) {
	j := s.jobAus(art)
	if j == nil {
		// Kann nicht vorkommen: Der Vorgang wurde eine Zeile vorher angelegt.
		// Falls doch, ist ein leerer Vorgang die ehrlichere Antwort als ein
		// erfundener.
		j = &apiJob{Art: art, Titel: jobArten[art], Laeuft: true, Zeilen: []string{}}
	}
	s.apiJSON(w, http.StatusAccepted, apiVorgangGestartet{Meldung: meldung, Job: *j})
}

// handleAPIPackageRefresh holt die Paketlisten neu.
//
// Ohne Rückfrage: apt-get update verändert nichts am System außer den Listen im
// Zwischenspeicher. Stufe 1 aus docs/14-bestaetigungen.md.
func (s *Server) handleAPIPackageRefresh(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	j, neu := s.jobs.start(jobPackages, user.Username)
	if !neu {
		s.apiFehler(w, http.StatusConflict, "Es läuft bereits ein Paketvorgang.")
		return
	}
	s.audit(r, "package.refresh", "", store.ResultOK, "gestartet")

	// Eigener Kontext: Der Vorgang soll nicht daran hängen, ob der Tab offen
	// bleibt. Die Frist liegt über der des Kommandos selbst (5 Minuten), damit
	// sie erst greift, wenn dort etwas festhängt.
	go func() { //nolint:gosec // eigener Kontext ist hier Absicht, siehe Kommentar oben
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
		defer cancel()

		res, err := s.ops.PackageRefresh(ctx, j.append)

		result, detail := store.ResultOK, "abgeschlossen"
		switch {
		case err != nil:
			result, detail = store.ResultError, err.Error()
		case res.Partial():
			// Teilerfolg: Die Listen sind neu, aber nicht vollständig. Das
			// gehört in die Oberfläche und ins Audit-Log — verschwiegen wäre es
			// eine Zusage, die niemand halten kann.
			j.setNote(refreshHinweis(res))
			detail = fmt.Sprintf("%d Quelle(n) nicht erreichbar: %s",
				len(res.Failed), quellenListe(res.Failed))
		}
		j.finish(err)

		s.auditNachtraeglich(user.Username, "package.refresh", "", result, detail)
	}()

	s.gestartet(w, jobPackages, "Die Paketlisten werden geholt.")
}

// apiUpgradeAnfrage ist der Körper von POST /api/v1/packages/upgrade.
type apiUpgradeAnfrage struct {
	// Umfang ist "alle", "sicherheit" oder "einzeln". Ein Wort statt zweier
	// Wahrheitswerte: "alle=true, sicherheit=true" wäre ein Zustand, den es
	// nicht gibt, und jemand müsste entscheiden, welcher gewinnt.
	Umfang     string `json:"umfang"`
	Paket      string `json:"paket"`
	Bestaetigt bool   `json:"bestaetigt"`
	Getippt    string `json:"getippt"`
}

func (s *Server) handleAPIPackageUpgrade(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	var anfrage apiUpgradeAnfrage
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}

	opts := privops.UpgradeOptions{}
	switch anfrage.Umfang {
	case "alle":
	case "sicherheit":
		opts.OnlySecurity = true
	case "einzeln":
		name := strings.TrimSpace(anfrage.Paket)
		if name == "" {
			s.apiFehler(w, http.StatusBadRequest, "Kein Paket angegeben.")
			return
		}
		opts.Packages = []string{name}
	default:
		s.apiFehler(w, http.StatusBadRequest, "Unbekannter Umfang: "+anfrage.Umfang)
		return
	}

	// Ein einzelnes Paket einzuspielen ist ein gezielter Klick in seiner Zeile —
	// dafür braucht es keine Rückfrage (Stufe 1). „Alle Updates einspielen" kann
	// Dutzende Pakete und Dienste-Neustarts bedeuten; wie viele, weiß der Server,
	// und die Zahl gehört in die Frage. „Alle Updates einspielen?" befähigt zu
	// keiner Entscheidung, „alle 42" schon.
	if len(opts.Packages) == 0 {
		liste, _ := s.ops.PackageUpgradable(r.Context())
		anzahl := len(liste)
		if opts.OnlySecurity {
			anzahl = 0
			for _, p := range liste {
				if p.Security {
					anzahl++
				}
			}
		}

		frage := "Alle verfügbaren Updates einspielen?"
		if opts.OnlySecurity {
			frage = "Alle Sicherheitsupdates einspielen?"
			if anzahl > 0 {
				frage = fmt.Sprintf("Alle %d Sicherheitsupdates einspielen?", anzahl)
			}
		} else if anzahl > 0 {
			frage = fmt.Sprintf("Alle %d verfügbaren Updates einspielen?", anzahl)
		}

		if !s.apiBestaetigt(w, apiAktionAnfrage{
			Bestaetigt: anfrage.Bestaetigt, Getippt: anfrage.Getippt,
		}, apiBestaetigung{
			Titel: "Updates einspielen",
			Frage: frage,
			Punkte: []string{
				"Betroffene Dienste werden dabei neu gestartet.",
				"Der Vorgang läuft im Hintergrund weiter, auch wenn Sie die Seite verlassen.",
				"Manche Pakete verlangen danach einen Neustart des Servers.",
			},
			Knopf: "Updates einspielen",
		}) {
			return
		}
	}

	j, neu := s.jobs.start(jobPackages, user.Username)
	if !neu {
		s.apiFehler(w, http.StatusConflict, "Es läuft bereits ein Paketvorgang.")
		return
	}

	ziel := "alle"
	switch {
	case opts.OnlySecurity:
		ziel = "nur Sicherheitsupdates"
	case len(opts.Packages) > 0:
		ziel = opts.Packages[0]
	}
	s.audit(r, "package.upgrade", ziel, store.ResultOK, "gestartet")

	// Eigener Kontext: Der Vorgang überlebt das Ende der Anfrage. Ein
	// abgebrochenes apt-get hinterlässt ein halb konfiguriertes System.
	go func() { //nolint:gosec // eigener Kontext ist hier Absicht, siehe Kommentar oben
		ctx, cancel := context.WithTimeout(context.Background(), 60*time.Minute)
		defer cancel()

		err := s.ops.PackageUpgrade(ctx, opts, j.append)
		j.finish(err)

		result, detail := store.ResultOK, "abgeschlossen"
		if err != nil {
			result, detail = store.ResultError, err.Error()
		}
		s.auditNachtraeglich(user.Username, "package.upgrade", ziel, result, detail)
	}()

	s.gestartet(w, jobPackages, "Die Updates werden eingespielt.")
}

// handleAPIReboot startet den Server neu — Stufe 3 mit dem Hostnamen.
//
// Die einschneidendste Aktion des Panels, und die einzige, bei der die Rückfrage
// nicht das Ziel benennt, sondern den Rechner: Wer zwei Server im Browser offen
// hat, startet so nicht den falschen neu. Der Name steht im Statusband und ist
// abzulesen — der Zweck ist kein Geheimnis, sondern ein Innehalten mit Blick auf
// das richtige Feld.
func (s *Server) handleAPIReboot(w http.ResponseWriter, r *http.Request) {
	var anfrage apiUpgradeAnfrage
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}

	host := s.rechnername()
	if !s.apiBestaetigt(w, apiAktionAnfrage{
		Bestaetigt: anfrage.Bestaetigt, Getippt: anfrage.Getippt,
	}, apiBestaetigung{
		Titel: "Server neu starten",
		Frage: "Den Server " + host + " jetzt neu starten?",
		Punkte: []string{
			"Alle Dienste werden beendet und danach wieder gestartet.",
			"Diese Sitzung bricht ab und kommt erst nach dem Hochfahren zurück.",
			"Wie lange das dauert, hängt am Server — das Panel kann es nicht sagen.",
		},
		Knopf:         "jetzt neu starten",
		Tippen:        host,
		TippenHinweis: "Zum Bestätigen den Hostnamen eingeben: " + host,
	}) {
		return
	}

	if err := s.ops.Reboot(r.Context()); err != nil {
		s.audit(r, "system.reboot", "", store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadGateway, "Der Neustart konnte nicht angestoßen werden: "+err.Error())
		return
	}
	s.audit(r, "system.reboot", "", store.ResultOK, "")

	// Die Meldung ist eher Höflichkeit als Zusicherung: Ein Erfolg zerreißt die
	// Verbindung binnen Sekunden.
	s.apiJSON(w, http.StatusOK, map[string]string{
		"meldung": "Der Neustart wurde angestoßen. Die Verbindung bricht gleich ab " +
			"und kommt nach dem Hochfahren zurück.",
	})
}

// auditNachtraeglich schreibt den Eintrag zum Ergebnis eines Vorgangs, der nach
// dem Ende der Anfrage fertig wurde.
//
// Eigener Kontext und keine IP: Die Anfrage ist längst beendet, ihr Kontext also
// abgebrochen, und r nicht mehr zu berühren. Der Strich statt einer erfundenen
// Adresse — der Eintrag zum Start trägt die echte.
func (s *Server) auditNachtraeglich(akteur, aktion, ziel, ergebnis, detail string) {
	if err := s.db.AppendAudit(context.Background(), store.AuditEntry{
		At: time.Now(), Actor: akteur, Action: aktion,
		Target: ziel, Result: ergebnis, IP: "-", Detail: detail,
	}); err != nil {
		s.log.Error("audit-eintrag", "err", err)
	}
}
