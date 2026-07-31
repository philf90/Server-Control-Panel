package httpd

import (
	"errors"
	"net/http"
	"net/url"
	"path/filepath"
	"strings"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// Der Editor.
//
// Drei Zusagen, die über "Textfeld mit Speicherknopf" hinausgehen:
//
//  1. Zeilenenden und ein fehlender Schlussumbruch bleiben, wie sie waren. Ein
//     Editor, der aus 4000 CRLF-Zeilen stillschweigend LF macht, ist in einem
//     Panel nicht tragbar — der Unterschied wandert sonst in ein Diff, das
//     niemand lesen kann.
//  2. Wurde die Datei zwischenzeitlich von außen geändert, zeigt die Seite den
//     Konflikt, statt die fremde Änderung zu überschreiben. Verglichen wird der
//     SHA-256 des Inhalts beim Laden (docs/02-architektur.md, Regel 6).
//  3. Für Dateien, die sich prüfen lassen, läuft nach dem Schreiben das
//     Prüfprogramm des Systems. Schlägt es an, wird der Vorzustand
//     zurückgeschrieben — ein Tippfehler in sshd_config kostet sonst den Zugang
//     zum Server (Regel 5).

// handleFileEdit zeigt eine Datei im Editor.
func (s *Server) handleFileEdit(w http.ResponseWriter, r *http.Request) {
	pfad := r.URL.Query().Get("path")

	text, err := s.files.ReadText(r.Context(), pfad, 0)
	if err != nil {
		s.filesFehler(w, r, err)
		return
	}
	s.renderFileEdit(w, r, http.StatusOK, text, fileEditExtra{})
}

// fileEditExtra sind die Angaben, die nur nach einem Speichervorgang anfallen.
type fileEditExtra struct {
	Flash    string
	Fehler   string
	Konflikt bool
	// Eingabe ist der Inhalt, den der Benutzer geschickt hat. Bei einem Konflikt
	// bleibt er stehen: Die eigene Arbeit soll nicht verloren gehen, nur weil
	// jemand anders in der Zwischenzeit gespeichert hat.
	Eingabe  string
	Pruefung *privops.ConfigCheckResult
}

func (s *Server) renderFileEdit(w http.ResponseWriter, r *http.Request, status int, text privops.TextFile, extra fileEditExtra) {
	// Der Editor trägt seine Stile zur Laufzeit ein. Damit die
	// Content-Security-Policy sie nicht verwirft, bekommt genau dieses eine
	// Element einen Nonce — und nur diese Antwort erlaubt ihn. Siehe
	// cspMitStilNonce in middleware.go.
	nonce, err := auth.NewToken()
	if err != nil {
		s.log.Error("Nonce für den Editor", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "interner Fehler")
		return
	}
	w.Header().Set("Content-Security-Policy", cspMitStilNonce(nonce))

	seite := fileEditPage{
		Nonce:    nonce,
		Entry:    text.Entry,
		Text:     text,
		Sprache:  spracheFuer(text.Entry.Path),
		Dir:      filepath.Dir(text.Entry.Path),
		Crumbs:   krumen(text.Entry.Path),
		Konflikt: extra.Konflikt,
		Eingabe:  text.Content,
		Pruefung: extra.Pruefung,
	}
	if extra.Konflikt {
		seite.Eingabe = extra.Eingabe
	}

	basis := s.base(r, text.Entry.Name, "files").with(seite)
	if extra.Flash != "" {
		basis = basis.withFlash(extra.Flash)
	}
	if extra.Fehler != "" {
		basis = basis.withError(extra.Fehler)
	}
	s.renderPage(w, r, status, "file-edit", basis)
}

// handleFileSave schreibt den Editor-Inhalt zurück.
func (s *Server) handleFileSave(w http.ResponseWriter, r *http.Request) {
	pfad := r.PostFormValue("path")
	inhalt := r.PostFormValue("content")
	opts := privops.WriteOptions{
		ExpectHash:     r.PostFormValue("hash"),
		CRLF:           r.PostFormValue("crlf") == "1",
		NoFinalNewline: r.PostFormValue("nofinal") == "1",
	}

	// Der Vorzustand wird vor dem Schreiben gelesen, damit die Prüfung danach
	// einen Rückweg hat. Bei einer neuen Datei gibt es keinen — dann ist der
	// Rückweg das Löschen.
	vorher, vorherErr := s.files.ReadText(r.Context(), pfad, 0)

	text, err := s.files.WriteText(r.Context(), pfad, []byte(inhalt), opts)
	if err != nil {
		if errors.Is(err, privops.ErrConflict) {
			s.audit(r, "files.edit", pfad, store.ResultDenied, "Konflikt: von außen geändert")
			// Der aktuelle Stand von der Platte, damit der neue Hash im Formular
			// steht und ein zweiter Versuch bewusst überschreibt.
			jetzt, leseErr := s.files.ReadText(r.Context(), pfad, 0)
			if leseErr != nil {
				s.filesFehler(w, r, leseErr)
				return
			}
			s.renderFileEdit(w, r, http.StatusConflict, jetzt, fileEditExtra{
				Konflikt: true,
				Eingabe:  inhalt,
				Fehler: "Die Datei wurde zwischenzeitlich außerhalb des Editors geändert. " +
					"Ihre Fassung steht unten unverändert; ein erneutes Speichern überschreibt die fremde Änderung.",
			})
			return
		}
		s.audit(r, "files.edit", pfad, ergebnisVon(err), err.Error())
		if text, leseErr := s.files.ReadText(r.Context(), pfad, 0); leseErr == nil {
			s.renderFileEdit(w, r, statusVon(err), text, fileEditExtra{Fehler: err.Error(), Eingabe: inhalt, Konflikt: true})
			return
		}
		s.filesFehler(w, r, err)
		return
	}

	// Prüfprogramm, falls es für diese Datei eines gibt.
	pruefung, pruefErr := s.ops.ConfigCheck(r.Context(), text.Entry.Path)
	if pruefErr != nil {
		// Die Prüfung selbst ist gescheitert (Programm fehlt, Zeitüberschreitung).
		// Das ist kein Grund, die Änderung zurückzunehmen — aber es gehört
		// gesagt, statt "gespeichert" zu melden und zu schweigen.
		s.log.Warn("Konfigurationsprüfung nicht möglich", "path", text.Entry.Path, "err", pruefErr)
		s.audit(r, "files.edit", text.Entry.Path, store.ResultOK, "gespeichert, Prüfung nicht möglich: "+pruefErr.Error())
		s.renderFileEdit(w, r, http.StatusOK, text, fileEditExtra{
			Flash: "Gespeichert. Die Prüfung der Datei war nicht möglich: " + pruefErr.Error(),
		})
		return
	}

	if pruefung.Checked && !pruefung.OK {
		s.audit(r, "files.edit", text.Entry.Path, store.ResultError,
			pruefung.Tool+" hat abgelehnt: "+truncate(pruefung.Output, 200))

		zurueck := s.rolleZurueck(r, pfad, vorher, vorherErr)
		fehler := "Die Datei wurde nicht übernommen: " + pruefung.Tool + " lehnt sie ab.\n" + pruefung.Output
		if zurueck != "" {
			fehler += "\n" + zurueck
		}
		s.renderFileEdit(w, r, http.StatusBadRequest, text, fileEditExtra{
			Fehler:   fehler,
			Konflikt: true,
			Eingabe:  inhalt,
			Pruefung: &pruefung,
		})
		return
	}

	meldung := "Gespeichert."
	if pruefung.Checked {
		meldung += " " + pruefung.Tool + " hat die Datei angenommen."
	}
	s.audit(r, "files.edit", text.Entry.Path, store.ResultOK, pruefDetail(pruefung))
	s.renderFileEdit(w, r, http.StatusOK, text, fileEditExtra{Flash: meldung, Pruefung: pruefResult(pruefung)})
}

// rolleZurueck stellt den Vorzustand wieder her und liefert einen Satz für die
// Oberfläche darüber, was geschehen ist.
func (s *Server) rolleZurueck(r *http.Request, pfad string, vorher privops.TextFile, vorherErr error) string {
	if vorherErr != nil {
		// Es gab vorher keine Datei. Der Rückweg ist, sie wieder zu entfernen —
		// eine neue, kaputte Konfigurationsdatei liegen zu lassen wäre die
		// schlechtere Antwort.
		if err := s.files.Remove(r.Context(), pfad, nil); err != nil {
			return "Die neu angelegte Datei ließ sich nicht wieder entfernen: " + err.Error()
		}
		s.audit(r, "files.edit.rollback", pfad, store.ResultOK, "neu angelegte Datei entfernt")
		return "Die neu angelegte Datei wurde wieder entfernt."
	}

	// Kein ExpectHash: Der Hash auf der Platte ist gerade der der abgelehnten
	// Fassung, und zurückgeschrieben wird bewusst darüber.
	if _, err := s.files.WriteText(r.Context(), pfad, []byte(vorher.Content), privops.WriteOptions{
		CRLF:           vorher.CRLF,
		NoFinalNewline: vorher.NoFinalNewline,
	}); err != nil {
		s.audit(r, "files.edit.rollback", pfad, store.ResultError, err.Error())
		return "Der vorherige Stand ließ sich nicht wiederherstellen: " + err.Error() +
			" — die Datei liegt jetzt in der abgelehnten Fassung da."
	}
	s.audit(r, "files.edit.rollback", pfad, store.ResultOK, "vorheriger Stand wiederhergestellt")
	return "Der vorherige Stand ist wiederhergestellt."
}

func pruefResult(p privops.ConfigCheckResult) *privops.ConfigCheckResult {
	if !p.Checked {
		return nil
	}
	return &p
}

func pruefDetail(p privops.ConfigCheckResult) string {
	if !p.Checked {
		return "gespeichert"
	}
	if p.OK {
		return "gespeichert, " + p.Tool + " in Ordnung"
	}
	return "gespeichert, " + p.Tool + " hat abgelehnt"
}

// EditLink führt von der Liste in den Editor.
func (p filesPage) EditLink(pfad string) string {
	return "/alt/files/edit?" + url.Values{"path": {pfad}}.Encode()
}

// EditLink desselben Zwecks für die Detailseite.
func (p fileEntryPage) EditLink() string {
	return "/alt/files/edit?" + url.Values{"path": {p.Entry.Path}}.Encode()
}

// ListLink führt vom Editor zurück in die Liste.
func (p fileEditPage) ListLink() string {
	return "/alt/files?" + url.Values{"path": {p.Dir}}.Encode()
}

// EntryLink führt vom Editor auf die Detailseite.
func (p fileEditPage) EntryLink() string {
	return "/alt/files/entry?" + url.Values{"path": {p.Entry.Path}}.Encode()
}

// spracheFuer bestimmt die Hervorhebung im Editor.
//
// Die Zuordnung passiert hier und nicht im Browser, weil hier der ganze Pfad
// bekannt ist: /etc/nginx/sites-enabled/beispiel hat keine Endung, ist aber
// nginx-Syntax.
func spracheFuer(pfad string) string {
	sauber := filepath.Clean(pfad)
	name := strings.ToLower(filepath.Base(sauber))
	verzeichnis := strings.ToLower(filepath.Dir(sauber))

	switch filepath.Ext(name) {
	case ".yaml", ".yml":
		return "yaml"
	case ".json":
		return "json"
	case ".sh", ".bash", ".zsh":
		return "shell"
	case ".toml":
		return "toml"
	case ".conf", ".cfg", ".ini", ".properties":
		// .conf ist mehrdeutig: nginx, sshd und dutzende INI-artige Dateien
		// benutzen es. Der Ort entscheidet.
		if strings.Contains(verzeichnis, "nginx") {
			return "nginx"
		}
		return "ini"
	}

	switch {
	case strings.Contains(verzeichnis, "nginx"):
		return "nginx"
	case name == "dockerfile", strings.HasPrefix(name, "dockerfile."):
		return "dockerfile"
	case name == "bashrc", name == ".bashrc", name == "profile", name == ".profile",
		name == "bash_profile", name == ".bash_profile":
		return "shell"
	case name == "fstab", name == "hosts", name == "passwd", name == "group",
		name == "crontab", name == "sshd_config", name == "ssh_config":
		return "ini"
	}
	return ""
}
