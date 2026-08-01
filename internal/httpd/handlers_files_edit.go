package httpd

import (
	"net/http"
	"path/filepath"
	"strings"

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
			" — die Datei liegt jetzt in der abgelehnten Version da."
	}
	s.audit(r, "files.edit.rollback", pfad, store.ResultOK, "vorheriger Stand wiederhergestellt")
	return "Der vorherige Stand ist wiederhergestellt."
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
