package httpd

// Compose-Stacks über /api/v1 — schreibend.
//
// Schritt 5 aus docs/17-docker.md, der gefährlichste des Moduls. Vier
// Festlegungen bestimmen diese Datei:
//
//  1. **Der Prüfer ist die Grenze, nicht die Oberfläche.** Jeder Weg, der eine
//     Datei schreibt oder einen Container startet, geht durch
//     privops.StackSchreiben beziehungsweise StackAusfuehren — und beide rufen
//     denselben Prüfer. Eine Prüfung im Browser wäre eine Bequemlichkeit; hier
//     ist sie die Bedingung.
//  2. **Ein Befund erklärt sich.** Eine Ablehnung antwortet mit 400 UND mit den
//     Befunden — Dienst, Feld, Wert, Grund. „Abgelehnt" allein schickte
//     jemanden auf die Suche in einer Datei, die er gerade geschrieben hat.
//  3. **Bind-Mounts nach draußen sind kein Fehler, sondern eine Frage.**
//     „/srv/daten:/data" ist der häufigste legitime Fall und zugleich der Weg,
//     über den ein Container an fremde Daten kommt. Deshalb Stufe 3 mit dem
//     getippten Stack-Namen statt einer Ablehnung.
//  4. **Was Minuten läuft, ist ein Vorgang.** up, down, pull, restart und
//     Löschen ziehen Abbilder und starten Container; sie laufen als Job mit
//     Strom. Speichern ist eine Anfrage — es schreibt eine Datei.

import (
	"context"
	"errors"
	"net/http"
	"strconv"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// jobDockerStack ist die Vorgangsart für alle Stack-Handgriffe.
//
// EINE Art für up, down, pull, restart und Löschen, und damit höchstens einer
// gleichzeitig. Das ist kein Sparen an Vorgangsarten, sondern die Absicht: Zwei
// „compose up" nebeneinander auf demselben Wirt streiten um dieselben Netze und
// Volumes, und wer das Ergebnis später auseinandersortieren will, hat schon
// verloren.
const jobDockerStack = "docker-stack"

// apiStackSchreiben ist der Körper beim Anlegen und Speichern.
type apiStackSchreiben struct {
	// Name steht nur beim Anlegen im Körper; beim Speichern kommt er aus dem
	// Pfad. Zwei Quellen für dieselbe Angabe wären eine Einladung, sie
	// auseinanderlaufen zu lassen.
	Name       string `json:"name"`
	Text       string `json:"text"`
	Bestaetigt bool   `json:"bestaetigt"`
	Getippt    string `json:"getippt"`
}

// apiStackAktionAnfrage ist der Körper von POST /api/v1/docker/stacks/{name}.
type apiStackAktionAnfrage struct {
	Aktion string `json:"aktion"`
	// MitVolumes gilt nur bei „down": Es nimmt die benannten Volumes des
	// Projekts mit. Ein eigenes Feld und keine eigene Aktion, weil es dieselbe
	// Aktion mit anderer Tragweite ist — und die Rückfrage sagt den Unterschied.
	MitVolumes bool   `json:"mit_volumes"`
	Bestaetigt bool   `json:"bestaetigt"`
	Getippt    string `json:"getippt"`
}

// apiStackSchreibAntwort ist die Antwort auf Anlegen und Speichern.
type apiStackSchreibAntwort struct {
	Meldung  string                  `json:"meldung"`
	Pruefung privops.ComposePruefung `json:"pruefung"`
	// Detail ist der frisch gelesene Zustand. Er erspart der Oberfläche eine
	// zweite Anfrage — und die Lücke, in der sie den alten zeigt.
	Detail *apiStackDetail `json:"detail,omitempty"`
	// Befunde stehen bei einer Ablehnung neben dem Fehler: Dienst, Feld, Wert,
	// Grund. „Abgelehnt" allein schickte jemanden auf die Suche in einer Datei,
	// die er gerade geschrieben hat.
	Befunde []privops.ComposeBefund `json:"befunde,omitempty"`
	Fehler  string                  `json:"fehler,omitempty"`
}

// handleAPIDockerStackAnlegen legt einen neuen Stack an.
func (s *Server) handleAPIDockerStackAnlegen(w http.ResponseWriter, r *http.Request) {
	var anfrage apiStackSchreiben
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}
	if err := privops.PruefeStackName(anfrage.Name); err != nil {
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}
	// Ein vorhandener Name ist kein Schreibfehler, sondern eine Verwechslung:
	// Anlegen würde die Datei überschreiben, und wer „anlegen" drückt, erwartet
	// das nicht.
	if liste, err := s.ops.StackList(r.Context()); err == nil {
		for _, st := range liste {
			if st.Name == anfrage.Name {
				s.apiFehler(w, http.StatusConflict,
					"Es gibt schon einen Stack mit dem Namen "+anfrage.Name+".")
				return
			}
		}
	}
	s.stackSpeichern(w, r, anfrage.Name, anfrage)
}

// handleAPIDockerStackSpeichern ändert die Compose-Datei eines Stacks.
func (s *Server) handleAPIDockerStackSpeichern(w http.ResponseWriter, r *http.Request) {
	var anfrage apiStackSchreiben
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}
	s.stackSpeichern(w, r, r.PathValue("name"), anfrage)
}

// stackSpeichern ist der gemeinsame Weg für Anlegen und Ändern.
//
// Dieselbe Funktion, weil beides dasselbe tut: prüfen und schreiben. Der
// Unterschied liegt allein davor — beim Anlegen wird der Name auf Doppelung
// geprüft.
func (s *Server) stackSpeichern(w http.ResponseWriter, r *http.Request, name string, anfrage apiStackSchreiben) {
	if anfrage.Text == "" {
		s.apiFehler(w, http.StatusBadRequest, "Die Compose-Datei ist leer.")
		return
	}

	// Erst prüfen, dann fragen, dann schreiben — in dieser Reihenfolge, und
	// zwar OHNE zu schreiben. Ein Bind-Mount nach draußen ist eine Rückfrage,
	// und eine Rückfrage darf nichts hinterlassen haben. StackSchreiben prüft
	// deshalb ein zweites Mal; das kostet einen Aufruf von „compose config" und
	// erspart die Fassung, in der die Prüfung vor der Frage schon geschrieben
	// hat.
	vorschau := s.stackVorschau(r.Context(), name, anfrage.Text)
	if !s.stackBefundeBestaetigt(w, apiAktionAnfrage{
		Bestaetigt: anfrage.Bestaetigt, Getippt: anfrage.Getippt,
	}, name, vorschau, "speichern") {
		return
	}

	pruefung, err := s.ops.StackSchreiben(r.Context(), name, anfrage.Text, s.cfg.Server.Port)
	if err != nil {
		s.audit(r, "docker.stack.write", name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}
	if !pruefung.OK {
		// 400 mit den Befunden: Der Statuscode sagt „nicht angenommen", der
		// Körper sagt warum. Das Panel hat nichts geschrieben.
		s.audit(r, "docker.stack.write", name, store.ResultError, "vom Compose-Prüfer abgelehnt")
		// 400 mit einem vollen Körper und nicht bloß apiFehler: Das Feld
		// „fehler" bedient den gewöhnlichen Fehlerweg der Oberfläche, „befunde"
		// die Liste darunter. Ein Statuscode allein sagte nur, dass etwas nicht
		// ging.
		s.apiJSON(w, http.StatusBadRequest, apiStackSchreibAntwort{
			Fehler:   "Der Compose-Prüfer hat die Datei abgelehnt.",
			Meldung:  "Der Compose-Prüfer hat die Datei abgelehnt.",
			Pruefung: pruefung,
			Befunde:  pruefung.Ablehnungen(),
		})
		return
	}
	s.audit(r, "docker.stack.write", name, store.ResultOK, "")

	antwort := apiStackSchreibAntwort{
		Meldung:  name + " gespeichert.",
		Pruefung: pruefung,
	}
	if detail, err := s.stackDetailLesen(r.Context(), name); err == nil {
		antwort.Detail = &detail
	}
	s.apiJSON(w, http.StatusOK, antwort)
}

// stackVorschau prüft einen Text, ohne ihn zu schreiben.
//
// Für die Rückfrage gebraucht: Sie muss wissen, ob ein Bind-Mount nach draußen
// darin steht, BEVOR irgendetwas auf der Platte liegt. Geprüft wird die
// Rohfassung — was ein Anker verbirgt, sieht erst StackSchreiben beim Rendern,
// und das läuft danach noch einmal.
func (s *Server) stackVorschau(ctx context.Context, name, text string) privops.ComposePruefung {
	// Der Ort des Stacks entscheidet, was „innen" heißt. Bei einem vorhandenen
	// sagt ihn die Liste; bei einem neuen gibt es ihn noch nicht, und dann zählt
	// der Ort, an den er kommt. Ohne diesen zweiten Fall hielte der Prüfer beim
	// Anlegen jeden Pfad für einen nach draußen — und stellte eine Rückfrage zu
	// einem Verzeichnis, das dem Stack selbst gehört.
	wurzel := privops.StackVerzeichnis(name)
	if st, err := s.ops.StackDatei(ctx, name); err == nil {
		wurzel = verzeichnisVon(st.Datei)
	}
	return privops.PruefeComposeText(text, wurzel, s.cfg.Server.Port, false)
}

// handleAPIDockerStackAktion fährt einen Stack hoch, herunter, zieht seine
// Abbilder, startet ihn neu oder löscht ihn.
func (s *Server) handleAPIDockerStackAktion(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	name := r.PathValue("name")

	var anfrage apiStackAktionAnfrage
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}
	if err := privops.PruefeStackName(name); err != nil {
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}

	// Den Stack VOR der Rückfrage lesen: Die Frage soll sagen, wie viele
	// Dienste sie trifft. Lesen ist erlaubt, solange nichts verändert wurde
	// (Kontrakt von apiBestaetigt).
	stack, gefunden := s.stackAus(r.Context(), name)
	if !gefunden {
		s.apiFehler(w, http.StatusNotFound, "kein Stack mit dem Namen "+name)
		return
	}

	if anfrage.Aktion == "loeschen" {
		s.stackLoeschen(w, r, user.Username, stack, anfrage)
		return
	}

	aktion := privops.StackAktion(anfrage.Aktion)
	if !privops.ValidStackAktion(aktion) {
		s.apiFehler(w, http.StatusBadRequest, "unbekannte Aktion: "+anfrage.Aktion)
		return
	}

	if !s.stackAktionBestaetigt(w, r, name, stack, aktion, anfrage) {
		return
	}

	j, neu := s.jobs.start(jobDockerStack, user.Username)
	if !neu {
		s.apiFehler(w, http.StatusConflict, "Es läuft bereits ein Stack-Vorgang.")
		return
	}
	s.audit(r, "docker.stack."+anfrage.Aktion, name, store.ResultOK, "gestartet")

	mitVolumes := anfrage.MitVolumes && aktion == privops.StackDown
	port := s.cfg.Server.Port
	go func() { //nolint:gosec // eigener Kontext ist hier Absicht
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Minute)
		defer cancel()

		pruefung, err := s.ops.StackAusfuehren(ctx, name, aktion, mitVolumes, port, j.append)
		// Eine Ablehnung ist kein Go-Fehler, sondern ein Ergebnis — sie muss
		// hier zu einem werden, sonst endete der Vorgang als „erfolgreich"
		// und der Stack liefe trotzdem nicht.
		if err == nil && pruefung.Geprueft && !pruefung.OK {
			err = errors.New(befundsatz(pruefung))
			for _, b := range pruefung.Ablehnungen() {
				j.append("abgelehnt — " + b.Dienst + "." + b.Feld + ": " + b.Grund)
			}
		}
		j.finish(err)

		result, detail := store.ResultOK, "abgeschlossen"
		if err != nil {
			result, detail = store.ResultError, err.Error()
		}
		s.auditNachtraeglich(user.Username, "docker.stack."+string(aktion), name, result, detail)
	}()

	s.gestartet(w, jobDockerStack, stackMeldung(aktion, name))
}

// stackLoeschen entfernt einen verwalteten Stack samt Verzeichnis.
//
// Immer Stufe 3 mit dem getippten Stack-Namen: Es fährt das Projekt herunter
// UND löscht seine Dateien. Kein anderer Handgriff dieses Moduls tut zwei
// unumkehrbare Dinge auf einmal.
func (s *Server) stackLoeschen(w http.ResponseWriter, r *http.Request, akteur string, stack apiStack, anfrage apiStackAktionAnfrage) {
	if !stack.Verwaltet {
		s.apiFehler(w, http.StatusBadRequest,
			"Dieses Projekt hat jemand außerhalb des Panels angelegt. Das Panel löscht nur, was es selbst geschrieben hat.")
		return
	}
	if !s.apiBestaetigt(w, apiAktionAnfrage{
		Bestaetigt: anfrage.Bestaetigt, Getippt: anfrage.Getippt,
	}, apiBestaetigung{
		Titel: "Stack löschen",
		Frage: stack.Name + " herunterfahren und löschen?",
		Punkte: []string{
			"Der Stack wird zuerst heruntergefahren — was er bereitstellt, ist sofort nicht mehr erreichbar.",
			"Das Verzeichnis " + stack.Datei + " wird mit allem darin entfernt.",
			"Benannte Volumes bleiben erhalten; sie lassen sich im Bestand einzeln entfernen.",
		},
		Knopf:         "herunterfahren und löschen",
		Tippen:        stack.Name,
		TippenHinweis: "Zum Bestätigen den Stack-Namen eingeben: " + stack.Name,
	}) {
		return
	}

	j, neu := s.jobs.start(jobDockerStack, akteur)
	if !neu {
		s.apiFehler(w, http.StatusConflict, "Es läuft bereits ein Stack-Vorgang.")
		return
	}
	s.audit(r, "docker.stack.remove", stack.Name, store.ResultOK, "gestartet")

	name := stack.Name
	go func() { //nolint:gosec // eigener Kontext ist hier Absicht
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Minute)
		defer cancel()

		err := s.ops.StackLoeschen(ctx, name, j.append)
		j.finish(err)

		result, detail := store.ResultOK, "abgeschlossen"
		if err != nil {
			result, detail = store.ResultError, err.Error()
		}
		s.auditNachtraeglich(akteur, "docker.stack.remove", name, result, detail)
	}()

	s.gestartet(w, jobDockerStack, name+" wird gelöscht.")
}

// stackAktionBestaetigt stellt die Frage zur Aktion.
//
// Die Stufen stehen in docs/17-docker.md §5 und in docs/14-bestaetigungen.md:
//
//   - pull: Stufe 1. Es lädt herunter und ändert nichts an dem, was läuft.
//   - up und restart: Stufe 1 — AUSSER der Prüfer meldet einen Bind-Mount nach
//     draußen. Dann Stufe 3 mit dem Stack-Namen, denn dann startet dieser
//     Handgriff einen Container mit Zugriff auf Daten des Servers.
//   - down: Stufe 2, weil danach nichts mehr erreichbar ist.
//   - down mit Volumes: Stufe 3 mit dem Stack-Namen — dasselbe Argument wie
//     beim einzelnen Volume: Daten weg, kein Rückweg.
func (s *Server) stackAktionBestaetigt(w http.ResponseWriter, r *http.Request, name string, stack apiStack, aktion privops.StackAktion, anfrage apiStackAktionAnfrage) bool {
	basis := apiAktionAnfrage{Bestaetigt: anfrage.Bestaetigt, Getippt: anfrage.Getippt}

	switch aktion {
	case privops.StackPull:
		return true

	case privops.StackUp, privops.StackRestart:
		// Der Prüfer läuft VOR der Frage, weil sein Ergebnis die Stufe
		// entscheidet. Er läuft danach in StackAusfuehren noch einmal — das ist
		// die verbindliche Prüfung, diese hier ist die für die Frage.
		pruefung, err := s.ops.StackPruefen(r.Context(), name, s.cfg.Server.Port)
		if err == nil && len(pruefung.Aussenmounts()) > 0 {
			punkte := []string{
				"Dieser Stack hängt Verzeichnisse des Servers in seine Container ein:",
			}
			for _, m := range pruefung.Aussenmounts() {
				punkte = append(punkte, m.Dienst+": "+m.Wert)
			}
			punkte = append(punkte,
				"Was in diesen Verzeichnissen liegt, ist für den Container erreichbar.")
			return s.apiBestaetigt(w, basis, apiBestaetigung{
				Titel:         "Stack starten",
				Frage:         name + " starten?",
				Punkte:        punkte,
				Knopf:         "starten",
				Tippen:        name,
				TippenHinweis: "Zum Bestätigen den Stack-Namen eingeben: " + name,
			})
		}
		return true

	case privops.StackDown:
		b := apiBestaetigung{
			Titel: "Stack stoppen",
			Frage: name + " herunterfahren?",
			Punkte: []string{
				dienstesatz(stack) + " werden gestoppt und entfernt.",
				"Benannte Volumes und Abbilder bleiben erhalten — der Stack lässt sich wieder starten.",
			},
			Knopf: "herunterfahren",
		}
		if anfrage.MitVolumes {
			b.Frage = name + " herunterfahren und seine Volumes löschen?"
			b.Punkte = []string{
				dienstesatz(stack) + " werden gestoppt und entfernt.",
				"Die benannten Volumes dieses Projekts werden GELÖSCHT. Was darin liegt, ist danach weg, und kein Rückweg holt es zurück.",
			}
			b.Knopf = "herunterfahren und Volumes löschen"
			b.Tippen = name
			b.TippenHinweis = "Zum Bestätigen den Stack-Namen eingeben: " + name
		}
		return s.apiBestaetigt(w, basis, b)
	}
	return true
}

// stackBefundeBestaetigt fragt beim Speichern nach, wenn Bind-Mounts nach
// draußen in der Datei stehen.
//
// Beim Speichern und nicht erst beim Starten, weil der Editor die Stelle ist,
// an der jemand die Zeile gerade geschrieben hat — dort erklärt sich die Frage
// von selbst.
func (s *Server) stackBefundeBestaetigt(w http.ResponseWriter, anfrage apiAktionAnfrage, name string, pruefung privops.ComposePruefung, verb string) bool {
	aussen := pruefung.Aussenmounts()
	if len(aussen) == 0 {
		return true
	}
	punkte := []string{"Diese Datei hängt Verzeichnisse des Servers in Container ein:"}
	for _, m := range aussen {
		punkte = append(punkte, m.Dienst+": "+m.Wert)
	}
	punkte = append(punkte, "Beim Start ist alles darin für den Container erreichbar.")
	return s.apiBestaetigt(w, anfrage, apiBestaetigung{
		Titel:         "Stack " + verb,
		Frage:         name + " mit Zugriff auf Serververzeichnisse " + verb + "?",
		Punkte:        punkte,
		Knopf:         verb,
		Tippen:        name,
		TippenHinweis: "Zum Bestätigen den Stack-Namen eingeben: " + name,
	})
}

// ---------------------------------------------------------------- Hilfen ---

// stackAus liest einen Stack aus der Liste.
func (s *Server) stackAus(ctx context.Context, name string) (apiStack, bool) {
	liste, err := s.ops.StackList(ctx)
	if err != nil {
		return apiStack{}, false
	}
	for _, st := range liste {
		if st.Name == name {
			return stackAus(st, nil), true
		}
	}
	return apiStack{}, false
}

// dienstesatz nennt, wie viele Dienste eine Aktion trifft.
//
// „3 Dienste" statt „der Stack": docs/14-bestaetigungen.md verlangt Zahlen in
// der Frage — „alle Updates einspielen?" befähigt zu keiner Entscheidung,
// „alle 42" schon.
func dienstesatz(stack apiStack) string {
	switch {
	case stack.Gesamt > 1:
		return "Alle " + strconv.Itoa(stack.Gesamt) + " Container dieses Projekts"
	case stack.Gesamt == 1:
		return "Der Container dieses Projekts"
	default:
		return "Die Container dieses Projekts"
	}
}

// befundsatz fasst die Ablehnungen in einen Satz für den Vorgang.
func befundsatz(p privops.ComposePruefung) string {
	ablehnungen := p.Ablehnungen()
	if len(ablehnungen) == 0 {
		return "der Compose-Prüfer hat den Stack abgelehnt"
	}
	erste := ablehnungen[0]
	satz := "vom Compose-Prüfer abgelehnt: " + erste.Dienst + "." + erste.Feld
	if len(ablehnungen) > 1 {
		satz += " (und " + strconv.Itoa(len(ablehnungen)-1) + " weitere)"
	}
	return satz
}

// stackMeldung ist der Satz, der beim Start des Vorgangs zurückgeht.
func stackMeldung(aktion privops.StackAktion, name string) string {
	switch aktion {
	case privops.StackUp:
		return name + " wird gestartet."
	case privops.StackDown:
		return name + " wird heruntergefahren."
	case privops.StackPull:
		return "Abbilder für " + name + " werden geholt."
	case privops.StackRestart:
		return name + " wird neu gestartet."
	default:
		return name + ": Vorgang läuft."
	}
}

// verzeichnisVon nimmt das Verzeichnis eines Pfades. Eigene Hilfe statt
// filepath.Dir, damit ein leerer Pfad leer bleibt statt zu "." zu werden — ein
// Stack ohne bekannte Datei hat kein Verzeichnis, und "." wäre eine Behauptung.
func verzeichnisVon(pfad string) string {
	if pfad == "" {
		return ""
	}
	for i := len(pfad) - 1; i >= 0; i-- {
		if pfad[i] == '/' {
			return pfad[:i]
		}
	}
	return ""
}
