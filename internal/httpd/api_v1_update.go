package httpd

// Selbstupdate und Rückweg über /api/v1.
//
// Die gefährlichste Fläche des Panels, und die einzige, deren Vorgang den
// eigenen Dienst neu startet. Daraus folgt fast alles Übrige:
//
//  1. **Kein Ereignisstrom, sondern ein Poller.** Ein offener Kanal übersteht
//     den Neustart des Dienstes nicht. Der Update-Lauf schreibt deshalb in eine
//     Protokolldatei, und /api/v1/update/status liest sie — nach dem Neustart
//     genauso wie davor. Das ist der Grund, warum dieses Modul das Job-Modell
//     NICHT benutzt: Ein Job lebt im Speicher des Prozesses, den dieser Vorgang
//     gerade beendet.
//  2. **Die abbrechende Verbindung ist der Normalfall**, kein Fehler. Die
//     Oberfläche muss das unterscheiden können, und deshalb steht in der Antwort
//     die laufende Fassung: Wer eine andere zurückbekommt als die, mit der er
//     angefangen hat, weiß, dass es durch ist.
//  3. **Nur Owner darf auslösen.** Das Update tauscht das Programm aus, das alle
//     anderen Rechte durchsetzt. Wer es anstößt, bestimmt, welcher Code als root
//     läuft — das ist keine gewöhnliche Schreiboperation. Die Prüfung (lesend)
//     steht allen schreibberechtigten Rollen offen: Sie ändert nichts.
//  4. **Installiert wird, was der Auslöser gesehen hat.** Die Fassung geht
//     ausdrücklich mit in den Auftrag, damit nicht eine andere installiert wird,
//     die zwischen Anzeige und Klick veröffentlicht wurde.

import (
	"context"
	"net/http"
	"strconv"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
	"github.com/philf90/asylum/internal/update"
	"github.com/philf90/asylum/internal/version"
)

// ------------------------------------------------------------------ Lesen ---

// apiUpdate ist die Antwort von GET /api/v1/update.
type apiUpdate struct {
	// Fassung ist die LAUFENDE Fassung. Sie ist die Antwort auf die Frage, ob ein
	// Update durch ist: Wer nach dem Neustart eine andere zurückbekommt, weiß es.
	Fassung string `json:"fassung"`
	Kanal   string `json:"kanal"`
	Quelle  string `json:"quelle"`

	// GeprueftAm ist leer, solange in dieser Laufzeit nicht geprüft wurde. Der
	// Zustand liegt im Speicher und ist nach einem Neustart weg — was hier fehlt,
	// heißt „noch nicht gefragt" und nicht „kein Update".
	GeprueftAm string `json:"geprueft_am"`
	Verfuegbar string `json:"verfuegbar"`
	Erschienen string `json:"erschienen"`
	Notizen    string `json:"notizen"`
	// Dringlichkeit kommt aus den Metadaten der Fassung ("security" etwa).
	Dringlichkeit string `json:"dringlichkeit"`
	UpdateDa      bool   `json:"update_da"`
	Prueffehler   string `json:"pruef_fehler"`

	Laeuft bool   `json:"laeuft"`
	Ziel   string `json:"ziel"`
	// Zeilen ist der Auszug aus der Protokolldatei des Update-Laufs. Grundsatz IV:
	// die rohe Ausgabe, nicht eine Zusammenfassung davon.
	Zeilen []string `json:"zeilen"`

	// Vorher ist die Fassung in der Sicherung neben dem Binary; RueckwegMoeglich
	// sagt, ob es sie gibt. Ein Knopf ohne Sicherung liefe zuverlässig ins Leere.
	Vorher           string `json:"vorher"`
	RueckwegMoeglich bool   `json:"rueckweg_moeglich"`
	// DarfAusloesen sagt, ob die Rolle Update und Rückweg auslösen darf. Vom
	// Server, weil die Regel dort steht — die Oberfläche soll sie nicht ein
	// zweites Mal kennen.
	DarfAusloesen bool `json:"darf_ausloesen"`
}

func (s *Server) handleAPIUpdate(w http.ResponseWriter, r *http.Request) {
	s.apiJSON(w, http.StatusOK, s.updateAntwort(r))
}

func (s *Server) updateAntwort(r *http.Request) apiUpdate {
	geprueft, rel, pruefFehler, laeuft, ziel := s.upd.snapshot()
	user, _ := userFrom(r.Context())

	antwort := apiUpdate{
		Fassung:       version.Version,
		Kanal:         s.cfg.Updates.Channel,
		Quelle:        s.cfg.Updates.BaseURL,
		Prueffehler:   pruefFehler,
		Laeuft:        laeuft,
		Ziel:          ziel,
		Zeilen:        tailFile(s.updateLogPath(), maxUpdateLogLines),
		DarfAusloesen: user.CanManageUsers(),
	}
	if antwort.Zeilen == nil {
		antwort.Zeilen = []string{}
	}
	if !geprueft.IsZero() {
		antwort.GeprueftAm = geprueft.Format("02.01.2006 15:04")
	}
	if rel.Version != "" {
		antwort.Verfuegbar = rel.Version
		antwort.Notizen = rel.NotesURL
		antwort.Dringlichkeit = rel.Severity
		antwort.UpdateDa = update.Newer(version.Version, rel.Version)
		if !rel.ReleasedAt.IsZero() {
			antwort.Erschienen = rel.ReleasedAt.Format("02.01.2006")
		}
	}
	if vorher, err := s.previousVersion(r.Context()); err == nil {
		antwort.Vorher = vorher
		antwort.RueckwegMoeglich = true
	}
	return antwort
}

// apiUpdateStand ist die Antwort von GET /api/v1/update/status — die Auskunft für
// den Poller.
//
// Absichtlich klein: Sie wird im Sekundentakt gefragt, auch während der Dienst
// neu startet. Sie liest nur den Zustand im Speicher und die Protokolldatei;
// previousVersion steht bewusst NICHT darin, denn das startet einen Unterprozess
// (`asylumd --version` auf der Sicherung), und das sekündlich zu tun wäre
// verschwenderisch.
type apiUpdateStand struct {
	// Fassung ist die laufende. Wechselt sie, ist der Vorgang durch — das ist der
	// verlässlichste Hinweis, den es gibt, denn er kommt aus dem neuen Programm.
	Fassung string   `json:"fassung"`
	Laeuft  bool     `json:"laeuft"`
	Ziel    string   `json:"ziel"`
	Zeilen  []string `json:"zeilen"`
}

func (s *Server) handleAPIUpdateStand(w http.ResponseWriter, r *http.Request) {
	_, _, _, laeuft, ziel := s.upd.snapshot()
	zeilen := tailFile(s.updateLogPath(), maxUpdateLogLines)
	if zeilen == nil {
		zeilen = []string{}
	}
	_ = r
	s.apiJSON(w, http.StatusOK, apiUpdateStand{
		Fassung: version.Version,
		Laeuft:  laeuft,
		Ziel:    ziel,
		Zeilen:  zeilen,
	})
}

// ------------------------------------------------------------- Verändern ---

// apiUpdateAuftrag ist der Körper der verändernden Endpunkte.
type apiUpdateAuftrag struct {
	Bestaetigt bool   `json:"bestaetigt"`
	Getippt    string `json:"getippt"`
}

// apiUpdateAntwort ist die Antwort auf eine ausgeführte Handlung.
type apiUpdateAntwort struct {
	Meldung string     `json:"meldung"`
	Update  *apiUpdate `json:"update,omitempty"`
	Hinweis string     `json:"hinweis,omitempty"`
}

func (s *Server) updateFertig(w http.ResponseWriter, r *http.Request, status int, antwort apiUpdateAntwort) {
	u := s.updateAntwort(r)
	antwort.Update = &u
	s.apiJSON(w, status, antwort)
}

// handleAPIUpdatePruefen fragt die Metadaten ab.
//
// Ein lesender Vorgang, der nichts am System ändert — deshalb allen
// schreibberechtigten Rollen offen und ohne Rückfrage. Ein Fehlschlag ist keine
// Fehlermeldung der Fläche, sondern eine Auskunft: Die Metadaten sind nicht
// erreichbar, und das kann am Netz liegen.
func (s *Server) handleAPIUpdatePruefen(w http.ResponseWriter, r *http.Request) {
	var leer struct{}
	if !s.apiJSONKoerper(w, r, &leer) {
		return
	}

	ctx, abbrechen := context.WithTimeout(r.Context(), updateCheckTimeout)
	defer abbrechen()

	client := update.NewClient()
	client.BaseURL = s.cfg.Updates.BaseURL
	if s.updHTTP != nil {
		client.HTTP = s.updHTTP
	}

	rel, err := client.Latest(ctx, s.cfg.Updates.Channel)
	s.upd.setResult(rel, err)
	if err != nil {
		s.log.Warn("update-metadaten abrufen", "err", err)
		// 200 und keine 502: Die Anfrage war in Ordnung, und das Ergebnis der
		// Prüfung — samt Grund — steht in der Antwort. Ein Fehlerstatus hier
		// machte aus einer Auskunft eine rote Zeile.
		s.updateFertig(w, r, http.StatusOK, apiUpdateAntwort{
			Meldung: "Die Prüfung ist gelaufen.",
			Hinweis: "Die Update-Metadaten sind nicht erreichbar: " + err.Error(),
		})
		return
	}

	meldung := "Version " + version.Version + " ist aktuell."
	if update.Newer(version.Version, rel.Version) {
		meldung = "Im Kanal " + s.cfg.Updates.Channel + " steht Version " + rel.Version + "."
	}
	s.updateFertig(w, r, http.StatusOK, apiUpdateAntwort{Meldung: meldung})
}

// handleAPIUpdateEinspielen stößt den Update-Lauf an.
func (s *Server) handleAPIUpdateEinspielen(w http.ResponseWriter, r *http.Request) {
	var auftrag apiUpdateAuftrag
	if !s.apiJSONKoerper(w, r, &auftrag) {
		return
	}
	_, rel, _, laeuft, _ := s.upd.snapshot()

	if laeuft {
		s.apiFehler(w, http.StatusConflict, "Es läuft bereits ein Update.")
		return
	}
	if rel.Version == "" {
		s.apiFehler(w, http.StatusBadRequest, "Bitte zuerst nach Updates suchen.")
		return
	}
	if !update.Newer(version.Version, rel.Version) {
		s.apiFehler(w, http.StatusBadRequest, "Es liegt keine neuere Version vor.")
		return
	}

	// Stufe 2 und nicht Stufe 3, obwohl das die einschneidendste Aktion des Panels
	// ist: Sie ist umkehrbar, und die Frage sagt das. Der Neustart des Servers
	// (Stufe 3 mit dem Rechnernamen) ist es nicht — dort geht die Maschine weg,
	// hier nur der Dienst, und die vorige Fassung liegt daneben.
	if !s.apiBestaetigt(w, apiAktionAnfrage{
		Bestaetigt: auftrag.Bestaetigt, Getippt: auftrag.Getippt,
	}, apiBestaetigung{
		Titel: "Panel aktualisieren",
		Frage: "Das Panel von " + version.Version + " auf " + rel.Version + " aktualisieren?",
		Punkte: []string{
			"Der Dienst startet dabei neu — die Oberfläche ist einen Moment nicht erreichbar.",
			"Die laufende Version wird gesichert; ein Rollback bleibt möglich.",
			"Das Programm wird vor dem Austauschen gegen den eingebauten Signaturschlüssel geprüft.",
		},
		Knopf: "auf " + rel.Version + " aktualisieren",
	}) {
		return
	}

	// Die angezeigte Fassung geht mit: Der Hintergrundlauf installiert genau das,
	// was der Auslöser gesehen hat, und nicht eine, die zwischenzeitlich
	// veröffentlicht wurde.
	s.apiSelbstupdate(w, r, privops.SelfUpdateSpec{
		Channel: s.cfg.Updates.Channel,
		Version: rel.Version,
	}, rel.Version, "update.apply")
}

// handleAPIUpdateRueckweg kehrt zur gesicherten Fassung zurück.
func (s *Server) handleAPIUpdateRueckweg(w http.ResponseWriter, r *http.Request) {
	var auftrag apiUpdateAuftrag
	if !s.apiJSONKoerper(w, r, &auftrag) {
		return
	}
	if _, _, _, laeuft, _ := s.upd.snapshot(); laeuft {
		s.apiFehler(w, http.StatusConflict, "Es läuft bereits ein Update.")
		return
	}
	vorher, err := s.previousVersion(r.Context())
	if err != nil {
		s.apiFehler(w, http.StatusBadRequest,
			"Es liegt kein Backup einer vorherigen Version bereit.")
		return
	}

	if !s.apiBestaetigt(w, apiAktionAnfrage{
		Bestaetigt: auftrag.Bestaetigt, Getippt: auftrag.Getippt,
	}, apiBestaetigung{
		Titel: "Zurück auf die vorherige Version",
		Frage: "Das Panel von " + version.Version + " zurück auf " + vorher + " setzen?",
		Punkte: []string{
			"Der Dienst startet dabei neu — die Oberfläche ist einen Moment nicht erreichbar.",
			"Zurückgesetzt wird das Programm, nicht die Datenbank: Was neuere Versionen " +
				"an ihr geändert haben, bleibt.",
		},
		Knopf: "zurück auf " + vorher,
	}) {
		return
	}

	s.apiSelbstupdate(w, r, privops.SelfUpdateSpec{Rollback: true}, vorher, "update.rollback")
}

// apiSelbstupdate startet den Lauf und antwortet mit 202.
//
// 202 und nicht 200: Angenommen, nicht ausgeführt. Der Vorgang läuft in einer
// eigenen Unit weiter, und dieser Prozess hier wird dabei beendet — die Antwort
// ist buchstäblich das Letzte, was er sagt.
func (s *Server) apiSelbstupdate(w http.ResponseWriter, r *http.Request, spec privops.SelfUpdateSpec, ziel, aktion string) {
	binary, err := update.CurrentBinary()
	if err != nil {
		s.apiFehler(w, http.StatusInternalServerError,
			"Der eigene Programmpfad ließ sich nicht bestimmen: "+err.Error())
		return
	}
	spec.Binary = binary
	spec.LogFile = s.updateLogPath()
	// Ein eigener Unit-Name je Lauf: Zwei Läufe gleichzeitig sind ausgeschlossen
	// (siehe oben), aber ein Name, der noch von einem beendeten Lauf belegt ist,
	// ließe systemd-run scheitern.
	spec.Unit = "asylum-update-" + strconv.FormatInt(time.Now().UnixNano(), 10)

	if err := s.ops.SelfUpdateStart(r.Context(), spec); err != nil {
		s.audit(r, aktion, ziel, store.ResultError, err.Error())
		s.log.Error("selbstupdate anstoßen", "err", err)
		s.apiFehler(w, http.StatusInternalServerError,
			"Der Vorgang ließ sich nicht starten: "+err.Error())
		return
	}
	s.upd.markStarted(ziel)
	s.audit(r, aktion, ziel, store.ResultOK, "über systemd-run angestoßen")

	s.updateFertig(w, r, http.StatusAccepted, apiUpdateAntwort{
		Meldung: "Der Vorgang läuft.",
		Hinweis: "Das Panel startet dabei neu. Die Verbindung reißt für einige Sekunden " +
			"ab — das gehört dazu. Die Oberfläche merkt selbst, wenn Version " + ziel +
			" antwortet.",
	})
}
