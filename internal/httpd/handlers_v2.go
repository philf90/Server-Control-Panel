package httpd

// Ausliefern der neuen Oberfläche unter /v2/.
//
// Sie ist eine Einzelseiten-Anwendung: Ein Aufruf von /v2/dienste soll dieselbe
// index.html bekommen wie /v2/, weil die Wegewahl im Browser passiert. Nur die
// gebauten Dateien unter /v2/assets/ werden als Dateien geliefert.
//
// Solange die neue Oberfläche neben der alten läuft, liegt sie unter /v2/. Mit
// dem Umschalten wandert sie auf / — der Pfad steht deshalb an genau zwei
// Stellen: hier und als `base` in web/vite.config.js.

import (
	"io"
	"io/fs"
	"net/http"
	"strings"

	"github.com/philf90/asylum/internal/ui"
)

const v2Prefix = "/v2/"

// handleV2 liefert die Assets und für alles andere die index.html.
func (s *Server) handleV2(w http.ResponseWriter, r *http.Request) {
	dist, err := ui.Dist()
	if err != nil {
		s.log.Error("neue Oberfläche nicht verfügbar", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "Die neue Oberfläche ist in diesem Build nicht enthalten.")
		return
	}

	rest := strings.TrimPrefix(r.URL.Path, v2Prefix)

	// Die Dateinamen der Assets tragen den Inhaltshash. Ändert sich der Inhalt,
	// ändert sich der Name — deshalb darf der Browser sie behalten, statt sie
	// wie die alten statischen Dateien nach 300 Sekunden neu zu holen.
	if strings.HasPrefix(rest, "assets/") {
		datei, err := dist.Open(rest)
		if err != nil {
			http.NotFound(w, r)
			return
		}
		defer func() { _ = datei.Close() }()

		info, err := datei.Stat()
		if err != nil || info.IsDir() {
			http.NotFound(w, r)
			return
		}
		leser, ok := datei.(io.ReadSeeker)
		if !ok {
			// embed.FS liefert immer einen ReadSeeker; käme doch etwas anderes,
			// ist ein 404 die harmlosere Antwort als ein halber Auslieferungsweg.
			http.NotFound(w, r)
			return
		}

		w.Header().Set("Cache-Control", "public, max-age=31536000, immutable")
		http.ServeContent(w, r, info.Name(), info.ModTime(), leser)
		return
	}

	s.serveV2Index(w, r, dist)
}

// serveV2Index schickt die Hülle der Anwendung.
//
// Ohne Zwischenspeicher: Sie nennt die Assets mit ihren gehashten Namen, und
// eine behaltene Hülle würde nach einem Update auf Dateien zeigen, die es nicht
// mehr gibt. Genau dieser Fall macht ein Update sonst zu einer weißen Seite,
// bis jemand neu lädt.
func (s *Server) serveV2Index(w http.ResponseWriter, r *http.Request, dist fs.FS) {
	roh, err := fs.ReadFile(dist, "index.html")
	if err != nil {
		s.log.Error("index.html der neuen Oberfläche fehlt", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "Die neue Oberfläche ist in diesem Build nicht enthalten.")
		return
	}

	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	if _, err := w.Write(roh); err != nil {
		s.log.Debug("v2: Hülle nicht vollständig geschrieben", "err", err)
	}
}
