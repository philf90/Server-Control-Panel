package httpd

import (
	"context"
	"fmt"
	"net/http"
	"net/url"
	"path/filepath"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// Der Dateimanager ist das erste Modul, dessen Ziel aus der Anfrage kommt und
// nicht aus einer Allowlist. Deshalb gilt hier durchgehend: Der Pfad wird
// niemals in diesem Paket zusammengebaut oder geprüft, sondern unverändert an
// privops.Files übergeben. Dort sitzt die Pfadwache, und nur sie entscheidet.
//
// Die Handler kennen zwei Aufgaben: Werte aus der Anfrage lesen und Fehler so
// übersetzen, dass der Statuscode zum Grund passt.

// suchZeit begrenzt eine Namenssuche. Sie läuft im Request, und ein
// Verzeichnisbaum mit Millionen Einträgen darf keine Verbindung blockieren.
const suchZeit = 10 * time.Second

// handleFiles zeigt ein Verzeichnis oder das Ergebnis einer Namenssuche.
func (s *Server) handleFiles(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	pfad := s.filesPfad(q.Get("path"))

	opts := privops.ListOptions{
		Sort:       privops.ListSort(q.Get("sort")),
		Desc:       q.Get("desc") == "1",
		ShowHidden: q.Get("hidden") == "1",
	}
	switch opts.Sort {
	case privops.SortName, privops.SortSize, privops.SortTime:
	default:
		opts.Sort = privops.SortName
	}

	seite := filesPage{
		Path:   pfad,
		Sort:   string(opts.Sort),
		Desc:   opts.Desc,
		Hidden: opts.ShowHidden,
		Roots:  s.files.ReadableRoots(),
		Query:  strings.TrimSpace(q.Get("q")),
	}

	// Die Suche ersetzt die Liste, behält aber das Verzeichnis als Bezug: Der
	// Betrachter soll sehen, worin gesucht wurde.
	if seite.Query != "" {
		ctx, abbruch := context.WithTimeout(r.Context(), suchZeit)
		defer abbruch()

		res, err := s.files.Search(ctx, pfad, seite.Query, 0)
		if err != nil {
			s.filesFehler(w, r, err)
			return
		}
		seite.Suche = true
		seite.Entries = res.Entries
		seite.Total = len(res.Entries)
		seite.Truncated = res.Truncated
		seite.TruncatedReason = res.Reason
		if eintrag, err := s.files.Stat(r.Context(), pfad); err == nil {
			seite.Dir = eintrag
			seite.Crumbs = krumen(pfad)
		}
		s.renderPage(w, r, http.StatusOK, "files", s.base(r, "Dateien", "files").with(seite))
		return
	}

	liste, err := s.files.List(r.Context(), pfad, opts)
	if err != nil {
		s.filesFehler(w, r, err)
		return
	}
	seite.Dir = liste.Dir
	seite.Entries = liste.Entries
	seite.Total = liste.Total
	seite.Truncated = liste.Truncated
	if liste.Truncated {
		seite.TruncatedReason = fmt.Sprintf("nur die ersten %d von %d Einträgen", len(liste.Entries), liste.Total)
	}
	seite.Parent = liste.Parent
	seite.Crumbs = krumen(pfad)
	if frei, err := s.files.FreeSpace(r.Context(), pfad); err == nil {
		seite.Free = frei
	}
	seite.Warnungen = s.filesWarnungen(r.Context())

	s.renderPage(w, r, http.StatusOK, "files", s.base(r, "Dateien", "files").with(seite))
}

// filesPfad nimmt den Pfad aus der Anfrage oder den ersten sichtbaren Bereich.
//
// Geprüft wird hier nichts — das tut die Pfadwache. Hier steht nur die Antwort
// auf die Frage, wo die Seite ohne Angabe beginnt.
func (s *Server) filesPfad(roh string) string {
	if roh != "" {
		return roh
	}
	if wurzeln := s.files.ReadableRoots(); len(wurzeln) > 0 {
		return wurzeln[0]
	}
	return "/"
}

// handleFileDownload liefert eine Datei aus.
func (s *Server) handleFileDownload(w http.ResponseWriter, r *http.Request) {
	pfad := r.URL.Query().Get("path")

	leser, eintrag, err := s.files.Open(r.Context(), pfad)
	if err != nil {
		s.filesFehler(w, r, err)
		return
	}
	defer func() { _ = leser.Close() }()

	// Erst der Typ, dann ServeContent: Ohne gesetzten Content-Type würde
	// ServeContent ihn aus Endung oder Inhalt erraten, und eine ausgelieferte
	// HTML-Datei liefe dann im Ursprung des Panels. octet-stream und
	// "attachment" schließen das aus; X-Content-Type-Options: nosniff kommt aus
	// der Sicherheits-Middleware dazu.
	w.Header().Set("Content-Type", "application/octet-stream")
	w.Header().Set("Content-Disposition", anhang(eintrag.Name))
	w.Header().Set("Cache-Control", "no-store")

	s.audit(r, "files.download", eintrag.Path, store.ResultOK, formatBytesKurz(eintrag.Size))
	http.ServeContent(w, r, eintrag.Name, eintrag.ModTime, leser)
}

// handleFileArchive liefert ein Verzeichnis als tar.gz.
func (s *Server) handleFileArchive(w http.ResponseWriter, r *http.Request) {
	pfad := r.URL.Query().Get("path")

	eintrag, err := s.files.Stat(r.Context(), pfad)
	if err != nil {
		s.filesFehler(w, r, err)
		return
	}

	name := eintrag.Name
	if name == "" || name == "/" {
		name = "wurzel"
	}
	w.Header().Set("Content-Type", "application/gzip")
	w.Header().Set("Content-Disposition", anhang(name+".tar.gz"))
	w.Header().Set("Cache-Control", "no-store")
	// Kein Content-Length: Die Größe steht erst fest, wenn alles gepackt ist,
	// und bis dahin soll der Download längst laufen.
	w.WriteHeader(http.StatusOK)

	res, err := s.files.Archive(r.Context(), pfad, w)
	if err != nil {
		// Der Statuscode ist längst gesendet; mehr als protokollieren geht
		// nicht. Der Empfänger merkt es am unvollständigen Archiv — gzip und tar
		// haben beide einen Abschluss, der dann fehlt.
		s.log.Error("Archiv abgebrochen", "path", pfad, "err", err)
		s.audit(r, "files.archive", pfad, store.ResultError, err.Error())
		return
	}
	s.audit(r, "files.archive", pfad, store.ResultOK,
		fmt.Sprintf("%d Dateien, %s, %d ausgelassen", res.Files, formatBytesKurz(res.Bytes), res.Skipped))
}

// handleFileDetail beantwortet die Detailabfrage einer Zeile als JSON.
//
// Eigener Endpunkt, weil die Liste für zweitausend Einträge nicht alles
// mitschleppen soll, was nur beim Anklicken einer einzelnen Zeile interessiert.
func (s *Server) handleFileDetail(w http.ResponseWriter, r *http.Request) {
	pfad := r.URL.Query().Get("path")

	eintrag, err := s.files.Stat(r.Context(), pfad)
	if err != nil {
		s.filesFehler(w, r, err)
		return
	}
	antwort := fileDetail{Entry: eintrag}
	if eintrag.IsDir() {
		if mass, err := s.files.Measure(r.Context(), pfad); err == nil {
			antwort.Measurement = &mass
		}
	}
	s.writeJSON(w, http.StatusOK, antwort)
}

type fileDetail struct {
	Entry       privops.FileEntry    `json:"entry"`
	Measurement *privops.Measurement `json:"measurement,omitempty"`
}

// ------------------------------------------------------------- Hilfsmittel ---

// Link baut den Verweis auf ein Verzeichnis und behält Sortierung und die
// Anzeige versteckter Einträge bei. Ohne das setzte jeder Klick in einen
// Unterordner beides auf die Vorgabe zurück.
func (p filesPage) Link(pfad string) string {
	q := url.Values{"path": {pfad}}
	if p.Sort != "" && p.Sort != string(privops.SortName) {
		q.Set("sort", p.Sort)
	}
	if p.Desc {
		q.Set("desc", "1")
	}
	if p.Hidden {
		q.Set("hidden", "1")
	}
	return "/files?" + q.Encode()
}

// SortLink baut den Verweis einer Spaltenüberschrift. Ein Klick auf die schon
// aktive Spalte dreht die Richtung.
func (p filesPage) SortLink(feld string) string {
	q := url.Values{"path": {p.Path}}
	if feld != string(privops.SortName) {
		q.Set("sort", feld)
	}
	if p.Sort == feld && !p.Desc {
		q.Set("desc", "1")
	}
	if p.Hidden {
		q.Set("hidden", "1")
	}
	if p.Query != "" {
		q.Set("q", p.Query)
	}
	return "/files?" + q.Encode()
}

// SortPfeil ist das Zeichen hinter der aktiven Spaltenüberschrift.
func (p filesPage) SortPfeil(feld string) string {
	if p.Sort != feld {
		return ""
	}
	if p.Desc {
		return " ↓"
	}
	return " ↑"
}

// HiddenLink schaltet die Anzeige versteckter Einträge um.
func (p filesPage) HiddenLink() string {
	q := url.Values{"path": {p.Path}}
	if p.Sort != "" && p.Sort != string(privops.SortName) {
		q.Set("sort", p.Sort)
	}
	if p.Desc {
		q.Set("desc", "1")
	}
	if !p.Hidden {
		q.Set("hidden", "1")
	}
	return "/files?" + q.Encode()
}

// krumen baut den klickbaren Pfad über der Liste.
func krumen(pfad string) []crumb {
	sauber := filepath.Clean(pfad)
	out := []crumb{{Name: "/", Path: "/"}}
	if sauber == "/" || sauber == "." {
		return out
	}
	aktuell := "/"
	for _, teil := range strings.Split(strings.Trim(sauber, "/"), "/") {
		if teil == "" {
			continue
		}
		aktuell = filepath.Join(aktuell, teil)
		out = append(out, crumb{Name: teil, Path: aktuell})
	}
	return out
}

// anhang baut den Content-Disposition-Wert.
//
// Zwei Fassungen des Namens: eine auf ASCII eingeschränkte für alte Clients und
// die vollständige nach RFC 5987. Ohne die zweite verlieren Umlaute und
// kyrillische Namen beim Herunterladen ihre Zeichen.
func anhang(name string) string {
	var ascii strings.Builder
	for _, r := range name {
		if r < 0x20 || r == 0x7f || r == '"' || r == '\\' || r > 0x7e {
			ascii.WriteByte('_')
			continue
		}
		ascii.WriteRune(r)
	}
	return fmt.Sprintf("attachment; filename=%q; filename*=UTF-8''%s",
		ascii.String(), url.PathEscape(name))
}

// filesFehler beantwortet einen Fehler des Dateimanagers mit passendem
// Statuscode. Die Zuordnung steht in statusVon.
func (s *Server) filesFehler(w http.ResponseWriter, r *http.Request, err error) {
	status := statusVon(err)
	if status == http.StatusBadRequest {
		s.log.Warn("Dateimanager", "path", r.URL.Query().Get("path"), "err", err)
	}
	s.renderError(w, r, status, err.Error())
}

// formatBytesKurz ist die Größenangabe für Audit-Einträge.
func formatBytesKurz(n int64) string {
	if n < 0 {
		return "0 B"
	}
	const unit = 1024
	b := uint64(n)
	if b < unit {
		return fmt.Sprintf("%d B", b)
	}
	div, exp := uint64(unit), 0
	for x := b / unit; x >= unit && exp < 4; x /= unit {
		div *= unit
		exp++
	}
	return fmt.Sprintf("%.1f %ciB", float64(b)/float64(div), "KMGTP"[exp])
}

// filesWurzelPruefung liefert die Selbstprüfung der Schreibbereiche, einmal je
// Prozess.
//
// Der Grund ist eine Eigenart des Selbstupdates: Es tauscht das Programm, nie
// die systemd-Unit. Eine Installation, die von einer Fassung vor 0.3.0 kommt,
// trägt deshalb noch ProtectSystem=full und ProtectHome=read-only — und dann
// scheitert jeder Schreibversuch unter /etc und /home mit EROFS, ohne dass die
// Rechtebits der Verzeichnisse etwas davon verraten. Ohne diesen Hinweis suchen
// Betreiber den Fehler im Panel statt in ihrer Unit.
func (s *Server) filesWurzelPruefung(ctx context.Context) []privops.RootStatus {
	s.filesPruefOnce.Do(func() {
		s.filesPruefung = s.files.Verify(ctx)
		for _, st := range s.filesPruefung {
			if st.Exists && !st.Writable {
				s.log.Warn("Dateimanager: Bereich nicht beschreibbar",
					"pfad", st.Path, "grund", st.Reason)
			}
		}
	})
	return s.filesPruefung
}

// filesWarnungen sind die Bereiche, in denen nicht geschrieben werden kann,
// obwohl sie es sollten. Ein fehlendes Verzeichnis (etwa /srv auf einem System
// ohne) ist keine Warnung — es ist einfach nicht da.
func (s *Server) filesWarnungen(ctx context.Context) []privops.RootStatus {
	var out []privops.RootStatus
	for _, st := range s.filesWurzelPruefung(ctx) {
		if st.Exists && !st.Writable {
			out = append(out, st)
		}
	}
	return out
}
