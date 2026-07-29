package httpd

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"path/filepath"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// Die verändernden Endpunkte des Dateimanagers.
//
// Alle liegen hinter requireWrite und verifyCSRF (siehe routes.go). Sie folgen
// demselben Ablauf: Werte lesen, Operation aufrufen, Audit-Eintrag schreiben,
// Seite mit Meldung neu rendern. Kein Redirect — die Meldung soll zusammen mit
// dem neuen Zustand ankommen, und ein Redirect verlöre sie.
//
// Rekursive Eingriffe (Kopieren, Löschen, Rechte) laufen über privops.Files und
// werden dort vorher gezählt. Was daran abgelehnt wird — Gesperrtes darunter,
// eine Dateisystemgrenze, zu viele Einträge —, entscheidet nicht dieser Code.

// grosseVorgangSchwelle: Darüber läuft der Vorgang als Job mit Live-Ausgabe,
// statt die Anfrage minutenlang offen zu halten. Ein Browser, der nach 30
// Sekunden aufgibt, würde sonst ein halb kopiertes Verzeichnis hinterlassen,
// ohne dass jemand den Rest zu Ende bringt.
const grosseVorgangSchwelle = 500

// handleFileMkdir legt ein Verzeichnis an.
func (s *Server) handleFileMkdir(w http.ResponseWriter, r *http.Request) {
	dir := r.PostFormValue("dir")
	name := strings.TrimSpace(r.PostFormValue("name"))

	ziel := filepath.Join(filepath.Clean(dir), name)
	if err := s.files.Mkdir(r.Context(), ziel); err != nil {
		s.filesAntwort(w, r, dir, "files.mkdir", ziel, err, "")
		return
	}
	s.filesAntwort(w, r, dir, "files.mkdir", ziel, nil, "Ordner "+name+" angelegt.")
}

// handleFileTouch legt eine leere Datei an.
func (s *Server) handleFileTouch(w http.ResponseWriter, r *http.Request) {
	dir := r.PostFormValue("dir")
	name := strings.TrimSpace(r.PostFormValue("name"))

	ziel := filepath.Join(filepath.Clean(dir), name)
	if err := s.files.Touch(r.Context(), ziel); err != nil {
		s.filesAntwort(w, r, dir, "files.touch", ziel, err, "")
		return
	}
	s.filesAntwort(w, r, dir, "files.touch", ziel,
		nil, "Datei "+name+" angelegt. Sie ist leer und lässt sich im Editor füllen.")
}

// handleFileRename benennt um.
func (s *Server) handleFileRename(w http.ResponseWriter, r *http.Request) {
	pfad := r.PostFormValue("path")
	name := strings.TrimSpace(r.PostFormValue("name"))

	if err := s.files.Rename(r.Context(), pfad, name); err != nil {
		s.eintragAntwort(w, r, pfad, "files.rename", pfad, err, "")
		return
	}
	neu := filepath.Join(filepath.Dir(filepath.Clean(pfad)), name)
	s.audit(r, "files.rename", pfad, store.ResultOK, "neuer Name: "+name)
	s.renderFileEntry(w, r, http.StatusOK, neu, "Umbenannt in "+name+".", "")
}

// handleFileCopy und handleFileMove verlangen ein Zielverzeichnis.
func (s *Server) handleFileCopy(w http.ResponseWriter, r *http.Request) {
	s.kopierenOderVerschieben(w, r, false)
}

func (s *Server) handleFileMove(w http.ResponseWriter, r *http.Request) {
	s.kopierenOderVerschieben(w, r, true)
}

func (s *Server) kopierenOderVerschieben(w http.ResponseWriter, r *http.Request, verschieben bool) {
	pfad := r.PostFormValue("path")
	ziel := strings.TrimSpace(r.PostFormValue("target"))

	aktion, wort := "files.copy", "kopiert"
	if verschieben {
		aktion, wort = "files.move", "verschoben"
	}

	// Ein großer Baum läuft als Job: Die Anfrage wäre sonst minutenlang offen,
	// und ein Abbruch im Browser ließe die Arbeit halb erledigt zurück.
	mass, _ := s.files.Measure(r.Context(), pfad)
	if mass.Files+mass.Dirs+mass.Symlinks > grosseVorgangSchwelle {
		gestartet := s.starteDateiJob(r, aktion, pfad, func(ctx context.Context, fortschritt privops.Progress) error {
			if verschieben {
				return s.files.Move(ctx, pfad, ziel, fortschritt)
			}
			return s.files.Copy(ctx, pfad, ziel, fortschritt)
		})
		if !gestartet {
			s.renderFileEntry(w, r, http.StatusConflict, pfad, "",
				"Es läuft bereits ein Dateivorgang. Warten Sie, bis er fertig ist — zwei rekursive Läufe über denselben Baum kämen sich in die Quere.")
			return
		}
		s.renderFiles(w, r, http.StatusAccepted, filepath.Dir(filepath.Clean(pfad)),
			fmt.Sprintf("%s wird nach %s %s — der Vorgang läuft im Hintergrund weiter, auch wenn Sie die Seite verlassen.",
				filepath.Base(pfad), ziel, wort), "")
		return
	}

	var err error
	if verschieben {
		err = s.files.Move(r.Context(), pfad, ziel, nil)
	} else {
		err = s.files.Copy(r.Context(), pfad, ziel, nil)
	}
	if err != nil {
		s.eintragAntwort(w, r, pfad, aktion, pfad+" → "+ziel, err, "")
		return
	}
	s.audit(r, aktion, pfad, store.ResultOK, "Ziel: "+ziel)

	// Nach dem Verschieben gibt es den alten Pfad nicht mehr; die Seite zeigt
	// deshalb das Ziel.
	if verschieben {
		s.renderFileEntry(w, r, http.StatusOK, filepath.Join(ziel, filepath.Base(filepath.Clean(pfad))),
			"Nach "+ziel+" verschoben.", "")
		return
	}
	s.renderFileEntry(w, r, http.StatusOK, pfad, "Nach "+ziel+" kopiert.", "")
}

// handleFileDelete löscht, bei Verzeichnissen samt Inhalt.
func (s *Server) handleFileDelete(w http.ResponseWriter, r *http.Request) {
	pfad := r.PostFormValue("path")
	elter := filepath.Dir(filepath.Clean(pfad))

	// Erst durch die Pfadwache, dann fragen: Ein Pfad, den die Wache ablehnt,
	// soll die Antwort der Wache bekommen (403 oder 400) und keine Rückfrage, in
	// der er noch einmal geschrieben steht. Lesen darf vor der Bestätigung
	// geschehen, verändern nicht.
	eintrag, err := s.files.Stat(r.Context(), pfad)
	if err != nil {
		s.eintragAntwort(w, r, pfad, "files.delete", pfad, err, "")
		return
	}
	mass, _ := s.files.Measure(r.Context(), pfad)

	// Die Zahlen stehen in der Frage, weil sie die Entscheidung tragen: „Ordner
	// wirklich löschen?" befähigt zu keiner, „4132 Dateien, 1,2 GiB" schon. Bei
	// einem Ordner mit Inhalt kommt die dritte Stufe dazu — dort steht hinter
	// einem Klick nicht ein Eintrag, sondern ein Baum.
	name := filepath.Base(pfad)
	frage := name + " endgültig löschen?"
	folgen := []string{"Einen Papierkorb gibt es nicht. Rückgängig geht das nur aus einer Sicherung."}
	tippen := ""
	if eintrag.IsDir() {
		frage = fmt.Sprintf("%s enthält %d Dateien und %d Ordner (%s). Alles endgültig löschen?",
			name, mass.Files, mass.Dirs, formatBytesKurz(mass.Bytes))
		folgen = append(folgen, "Gelöscht wird der Ordner mit allem, was darunter liegt.")
		if mass.Files+mass.Dirs+mass.Symlinks > 0 {
			tippen = name
		}
	}
	// Gefragt wird nur, wo gelöscht werden könnte. Liegt der Pfad außerhalb der
	// Schreibbereiche oder ist er gesperrt, soll die Ablehnung der Wache kommen —
	// eine Rückfrage, deren Bestätigung dann in ein 403 läuft, wäre eine
	// Zumutung. Die Ablehnung selbst entsteht weiter unten in Remove; hier fällt
	// nur die Rückfrage weg.
	if eintrag.Writable && !eintrag.Sensitive {
		if !s.bestaetigt(w, r, bestaetigung{
			Titel:   "Löschen",
			Frage:   frage,
			Punkte:  append(folgen, pfad),
			Knopf:   "endgültig löschen",
			Tippen:  tippen,
			Abbruch: "/files/entry?" + url.Values{"path": {pfad}}.Encode(),
			Felder:  []bestaetigungFeld{{Name: "path", Wert: pfad}},
		}) {
			return
		}
	}

	if mass.Files+mass.Dirs+mass.Symlinks > grosseVorgangSchwelle {
		gestartet := s.starteDateiJob(r, "files.delete", pfad, func(ctx context.Context, fortschritt privops.Progress) error {
			return s.files.Remove(ctx, pfad, fortschritt)
		})
		if !gestartet {
			s.renderFileEntry(w, r, http.StatusConflict, pfad, "",
				"Es läuft bereits ein Dateivorgang. Warten Sie, bis er fertig ist.")
			return
		}
		s.renderFiles(w, r, http.StatusAccepted, elter,
			fmt.Sprintf("%s wird gelöscht (%d Einträge) — der Vorgang läuft im Hintergrund weiter.",
				filepath.Base(pfad), mass.Files+mass.Dirs+mass.Symlinks), "")
		return
	}

	if err := s.files.Remove(r.Context(), pfad, nil); err != nil {
		s.eintragAntwort(w, r, pfad, "files.delete", pfad, err, "")
		return
	}
	s.audit(r, "files.delete", pfad, store.ResultOK,
		fmt.Sprintf("%d Dateien, %d Ordner, %s", mass.Files, mass.Dirs, formatBytesKurz(mass.Bytes)))
	s.renderFiles(w, r, http.StatusOK, elter, filepath.Base(pfad)+" gelöscht.", "")
}

// handleFileMode setzt Rechte, Eigentümer und Gruppe.
//
// Beides in einem Formular und in einem Endpunkt: Wer die Rechte einer Datei
// richtet, ändert häufig im selben Schritt den Eigentümer — zwei getrennte
// Formulare bedeuteten zwei Runden und zwei Gelegenheiten, die zweite zu
// vergessen.
func (s *Server) handleFileMode(w http.ResponseWriter, r *http.Request) {
	pfad := r.PostFormValue("path")
	rekursiv := r.PostFormValue("recursive") == "1"
	modeText := strings.TrimSpace(r.PostFormValue("mode"))
	owner := strings.TrimSpace(r.PostFormValue("owner"))
	group := strings.TrimSpace(r.PostFormValue("group"))

	var getan []string

	if modeText != "" {
		mode, err := privops.ParseMode(modeText)
		if err != nil {
			s.eintragAntwort(w, r, pfad, "files.chmod", pfad, err, "")
			return
		}
		if err := s.files.Chmod(r.Context(), pfad, mode, rekursiv); err != nil {
			s.eintragAntwort(w, r, pfad, "files.chmod", pfad, err, "")
			return
		}
		s.audit(r, "files.chmod", pfad, store.ResultOK, detailRekursiv(modeText, rekursiv))
		getan = append(getan, "Rechte auf "+modeText)
	}

	if owner != "" || group != "" {
		if err := s.files.Chown(r.Context(), pfad, owner, group, rekursiv); err != nil {
			s.eintragAntwort(w, r, pfad, "files.chown", pfad, err, "")
			return
		}
		s.audit(r, "files.chown", pfad, store.ResultOK, detailRekursiv(owner+":"+group, rekursiv))
		getan = append(getan, "Eigentümer auf "+owner+":"+group)
	}

	if len(getan) == 0 {
		s.renderFileEntry(w, r, http.StatusBadRequest, pfad, "",
			"Es war nichts anzuwenden: weder Rechte noch Eigentümer angegeben.")
		return
	}
	meldung := strings.Join(getan, " und ") + " gesetzt"
	if rekursiv {
		meldung += ", einschließlich aller Einträge darunter"
	}
	s.renderFileEntry(w, r, http.StatusOK, pfad, meldung+".", "")
}

func detailRekursiv(wert string, rekursiv bool) string {
	if rekursiv {
		return wert + " (rekursiv)"
	}
	return wert
}

// ------------------------------------------------------------- Detailseite ---

// handleFileEntry zeigt einen einzelnen Eintrag mit allem, was man mit ihm tun
// kann.
//
// Eigene Seite statt Formularen in jeder Tabellenzeile: Umbenennen,
// Verschieben, Rechte und Löschen brauchen Eingabefelder, und zweitausend
// Zeilen mit je vier Formularen wären weder auf dem Telefon bedienbar noch
// schnell zu rendern. Dieselbe Aufteilung wie bei den Diensten — Liste, dann
// Detailseite.
func (s *Server) handleFileEntry(w http.ResponseWriter, r *http.Request) {
	s.renderFileEntry(w, r, http.StatusOK, r.URL.Query().Get("path"), "", "")
}

func (s *Server) renderFileEntry(w http.ResponseWriter, r *http.Request, status int, pfad, flash, fehler string) {
	eintrag, err := s.files.Stat(r.Context(), pfad)
	if err != nil {
		s.filesFehler(w, r, err)
		return
	}

	seite := fileEntryPage{
		Entry:  eintrag,
		Dir:    filepath.Dir(eintrag.Path),
		Crumbs: krumen(eintrag.Path),
	}
	if eintrag.IsDir() {
		if mass, err := s.files.Measure(r.Context(), eintrag.Path); err == nil {
			seite.Measurement = &mass
		}
	}
	// Nur echte Namen zur Auswahl: Ein Freitextfeld für den Eigentümer führt zu
	// Tippfehlern, die als "Benutzer gibt es nicht" zurückkommen.
	if users, groups, err := s.files.OwnerCandidates(r.Context()); err == nil {
		seite.Users, seite.Groups = users, groups
	}
	seite.Editable = eintrag.Readable() && eintrag.Writable
	seite.Rechte = privops.DescribeMode(eintrag.Mode, eintrag.IsDir())
	seite.Ziele = s.fileZiele(eintrag)

	basis := s.base(r, eintrag.Name, "files").with(seite)
	if flash != "" {
		basis = basis.withFlash(flash)
	}
	if fehler != "" {
		basis = basis.withError(fehler)
	}
	s.renderPage(w, r, status, "file-entry", basis)
}

// fileZiele sind die Ziele, die ohne Skript zur Wahl stehen: die Schreibbereiche
// und die Ordner auf dem Weg zum Eintrag.
//
// Das Ziel war bis hierher ein freies Textfeld. Ein Tippfehler wurde damit erst
// beim Absenden zu einer Fehlermeldung, und "/srv/date" statt "/srv/daten" legt
// im Zweifel nichts an, sondern benennt um. Zur Wahl steht jetzt nur, was es
// gibt — mit Skript durchsuchbar (zielwahl.js), ohne Skript diese Liste.
//
// Ohne List-Aufruf: Die Ordner auf dem Weg hierher stehen im Pfad, und die
// Schreibbereiche kennt die Wache. Das genügt als Rückfall; das Durchsuchen
// beliebiger Tiefen ist die Aufgabe des Skripts.
func (s *Server) fileZieleFuerPfad(dir string) []fileTarget {
	gesehen := make(map[string]bool)
	out := make([]fileTarget, 0, 8)
	hinzu := func(pfad, label string) {
		if pfad == "" || gesehen[pfad] {
			return
		}
		gesehen[pfad] = true
		out = append(out, fileTarget{Path: pfad, Label: label, Selected: pfad == dir})
	}

	hinzu(dir, dir+" (hier)")
	for _, k := range krumen(dir) {
		hinzu(k.Path, k.Path)
	}
	for _, w := range s.files.WritableRoots() {
		hinzu(w, w+" (Schreibbereich)")
	}
	return out
}

func (s *Server) fileZiele(eintrag privops.FileEntry) []fileTarget {
	return s.fileZieleFuerPfad(filepath.Dir(eintrag.Path))
}

// renderFiles rendert die Liste eines Verzeichnisses mit Meldung.
func (s *Server) renderFiles(w http.ResponseWriter, r *http.Request, status int, dir, flash, fehler string) {
	liste, err := s.files.List(r.Context(), dir, privops.ListOptions{})
	if err != nil {
		s.filesFehler(w, r, err)
		return
	}
	seite := filesPage{
		Path:    liste.Dir.Path,
		Dir:     liste.Dir,
		Parent:  liste.Parent,
		Crumbs:  krumen(liste.Dir.Path),
		Roots:   s.files.ReadableRoots(),
		Entries: liste.Entries,
		Total:   liste.Total,
		Sort:    string(privops.SortName),
	}
	if frei, err := s.files.FreeSpace(r.Context(), dir); err == nil {
		seite.Free = frei
	}
	seite.Warnungen = s.filesWarnungen(r.Context())

	basis := s.base(r, "Dateien", "files").with(seite)
	if flash != "" {
		basis = basis.withFlash(flash)
	}
	if fehler != "" {
		basis = basis.withError(fehler)
	}
	s.renderPage(w, r, status, "files", basis)
}

// filesAntwort beantwortet eine Operation, die sich auf ein Verzeichnis bezieht.
func (s *Server) filesAntwort(w http.ResponseWriter, r *http.Request, dir, aktion, ziel string, err error, flash string) {
	if err != nil {
		s.audit(r, aktion, ziel, ergebnisVon(err), err.Error())
		s.renderFiles(w, r, statusVon(err), dir, "", err.Error())
		return
	}
	s.audit(r, aktion, ziel, store.ResultOK, "")
	s.renderFiles(w, r, http.StatusOK, dir, flash, "")
}

// eintragAntwort beantwortet eine Operation, die sich auf einen Eintrag bezieht.
func (s *Server) eintragAntwort(w http.ResponseWriter, r *http.Request, pfad, aktion, ziel string, err error, flash string) {
	if err != nil {
		s.audit(r, aktion, ziel, ergebnisVon(err), err.Error())
		s.renderFileEntry(w, r, statusVon(err), pfad, "", err.Error())
		return
	}
	s.audit(r, aktion, ziel, store.ResultOK, "")
	s.renderFileEntry(w, r, http.StatusOK, pfad, flash, "")
}

// ------------------------------------------------------------------- Jobs ---

// jobFiles ist die Art des Dateivorgangs in der Job-Verwaltung. Nur einer
// gleichzeitig: Zwei rekursive Läufe über denselben Baum kämen sich in die
// Quere, und mehr als einen Fortschritt kann die Seite ohnehin nicht zeigen.
const jobFiles = "files"

// dateiJobFrist deckelt einen Vorgang. Zwei Stunden reichen für sehr große
// Bäume und verhindern trotzdem, dass ein hängender Lauf für immer bleibt.
const dateiJobFrist = 2 * time.Hour

// starteDateiJob hängt einen langen Vorgang an die vorhandene Job-Mechanik.
//
// Dieselbe wie beim Paket-Update: Der Vorgang läuft serverseitig weiter, auch
// wenn der Browser die Verbindung verliert. Ein halb kopiertes Verzeichnis, um
// das sich niemand mehr kümmert, ist schlimmer als ein Vorgang, der ohne
// Zuschauer zu Ende läuft.
func (s *Server) starteDateiJob(r *http.Request, aktion, ziel string, tun func(context.Context, privops.Progress) error) bool {
	akteur := "unbekannt"
	if u, ok := userFrom(r.Context()); ok {
		akteur = u.Username
	}
	j, gestartet := s.jobs.start(jobFiles, akteur)
	if !gestartet {
		return false
	}
	j.append(aktion + ": " + ziel)
	s.audit(r, aktion, ziel, store.ResultOK, "als Hintergrundvorgang gestartet")

	// Eigener Kontext: Der Vorgang überlebt das Ende der Anfrage.
	go func() { //nolint:gosec // eigener Kontext ist hier Absicht, siehe Kommentar oben
		ctx, abbruch := context.WithTimeout(context.Background(), dateiJobFrist)
		defer abbruch()

		// Nicht jede Zeile eines Laufs über 200.000 Einträge gehört in den
		// Puffer: Er ist auf 5.000 Zeilen begrenzt, und die letzten wären dann
		// die uninteressantesten. Gemeldet wird jeder fünfzigste Schritt.
		var gesehen int
		err := tun(ctx, func(schritt privops.Step) {
			gesehen++
			if gesehen%50 != 0 && schritt.Done != schritt.Total {
				return
			}
			if schritt.Total > 0 {
				j.append(fmt.Sprintf("[%d/%d] %s", schritt.Done, schritt.Total, schritt.Current))
				return
			}
			j.append(schritt.Current)
		})
		if err != nil {
			j.append("Fehler: " + err.Error())
		} else {
			j.append("fertig")
		}
		j.finish(err)

		ergebnis, detail := store.ResultOK, "abgeschlossen"
		if err != nil {
			ergebnis, detail = ergebnisVon(err), err.Error()
		}
		if auditErr := s.db.AppendAudit(context.Background(), store.AuditEntry{
			At: time.Now(), Actor: akteur, Action: aktion,
			Target: ziel, Result: ergebnis, IP: "-", Detail: detail,
		}); auditErr != nil {
			s.log.Error("audit-eintrag", "err", auditErr)
		}
	}()
	return true
}

// handleFileEvents streamt die Ausgabe des laufenden Dateivorgangs.
func (s *Server) handleFileEvents(w http.ResponseWriter, r *http.Request) {
	s.streamJob(w, r, jobFiles)
}

// ------------------------------------------------------------- Hilfsmittel ---

// EntryLink baut den Verweis auf die Detailseite eines Eintrags.
func (p filesPage) EntryLink(pfad string) string {
	return "/files/entry?" + url.Values{"path": {pfad}}.Encode()
}

// ListLink führt von der Detailseite zurück in die Liste.
func (p fileEntryPage) ListLink() string {
	return "/files?" + url.Values{"path": {p.Dir}}.Encode()
}

// LoeschFrage ist der Text der Rückfrage vor dem Löschen.
//
// Bei einem Verzeichnis steht die Zahl darin. "Ordner wirklich löschen?" ist
// keine Rückfrage, die zu einer Entscheidung befähigt — "4132 Dateien, 1,2 GiB"
// schon.
// LoeschTippen ist das Wort, das der Handler vor dem Löschen verlangt — der Name
// des Eintrags bei einem Ordner mit Inhalt, sonst leer.
//
// Es steht hier und nicht in der Vorlage, damit Dialog und Handler dieselbe
// Regel benutzen. Stünde sie zweimal, verlangte der Server irgendwann ein Wort,
// nach dem der Dialog nicht fragt — und der Knopf führte ohne Erklärung auf eine
// Zwischenseite.
func (p fileEntryPage) LoeschTippen() string {
	if !p.Entry.IsDir() || p.Measurement == nil {
		return ""
	}
	if p.Measurement.Files+p.Measurement.Dirs+p.Measurement.Symlinks == 0 {
		return ""
	}
	return p.Entry.Name
}

func (p fileEntryPage) LoeschFrage() string {
	if p.Measurement != nil {
		return fmt.Sprintf("%s enthält %d Dateien und %d Ordner (%s). Alles endgültig löschen?",
			p.Entry.Name, p.Measurement.Files, p.Measurement.Dirs, formatBytesKurz(p.Measurement.Bytes))
	}
	return p.Entry.Name + " endgültig löschen?"
}

// ergebnisVon unterscheidet im Audit-Log eine Ablehnung von einem Fehler. Ein
// "denied" ist eine Aussage über die Politik, ein "error" eine über das System.
func ergebnisVon(err error) string {
	if statusVon(err) == http.StatusForbidden {
		return store.ResultDenied
	}
	return store.ResultError
}

// statusVon bestimmt den Statuscode zu einem Fehler des Dateimanagers.
//
// Die Unterscheidung ist mehr als Kosmetik: Ein abgelehnter Pfad ist etwas
// anderes als ein fehlender, und ein Bedienfehler etwas anderes als ein
// Serverfehler. Ohne sie stünde für jeden Fall 500 im Protokoll.
func statusVon(err error) int {
	switch {
	case err == nil:
		return http.StatusOK
	case errors.Is(err, privops.ErrDenied):
		return http.StatusForbidden
	case errors.Is(err, privops.ErrConflict):
		return http.StatusConflict
	case errors.Is(err, privops.ErrTooLarge):
		return http.StatusRequestEntityTooLarge
	case errors.Is(err, privops.ErrNotRegular):
		return http.StatusUnsupportedMediaType
	case strings.Contains(err.Error(), "gibt es nicht"):
		return http.StatusNotFound
	default:
		return http.StatusBadRequest
	}
}
