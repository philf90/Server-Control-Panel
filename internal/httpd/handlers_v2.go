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
	"bytes"
	"io"
	"io/fs"
	"net/http"
	"strings"

	"github.com/philf90/asylum/internal/auth"
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

	// Ein Nonce für Stile, je Antwort neu gezogen.
	//
	// Er ist für den Editor da und für nichts anderes: CodeMirror trägt seine
	// Regeln zur Laufzeit in ein eigenes <style>-Element ein, und unter
	// `style-src 'self'` verwirft Chromium das — im Browser nachgemessen, der
	// Editor blieb ungestylt (leitstand_e2e.js, Abschnitt 12c). Die alte
	// Oberfläche löst es auf ihrer Editorseite genauso; die Begründung gegen
	// 'unsafe-inline' steht bei cspMitStilNonce in middleware.go und gilt hier
	// wörtlich.
	//
	// Der Nonce steht in der Hülle und nicht am Editor-Element: Die Hülle ist das
	// einzige, was der Server von dieser Oberfläche ausliefert — alles Weitere
	// baut der Browser. Deshalb ein <meta>, aus dem die Anwendung ihn liest
	// (lib/editorkern.ts).
	//
	// Er wird IMMER gesetzt und nicht nur, wenn der Editor offen ist. Der Grund
	// ist die Bauart einer SPA: Die Hülle kommt einmal, der Editor öffnet später
	// ohne neue Antwort. Ein Nonce, der erst mit dem Editor käme, käme nie. Der
	// Preis ist klein und benennbar: `style-src` trägt auf dieser Seite dauerhaft
	// einen Nonce-Wert, den nur diese Antwort kennt. Ein eingeschleuster Stil
	// ohne den Wert bleibt verworfen — genau das ist der Unterschied zu
	// 'unsafe-inline'.
	nonce, err := auth.NewToken()
	if err != nil {
		s.log.Error("Nonce für die neue Oberfläche", "err", err)
		s.renderError(w, r, http.StatusInternalServerError, "interner Fehler")
		return
	}
	w.Header().Set("Content-Security-Policy", cspMitStilNonce(nonce))

	// Eingesetzt wird in den Platzhalter, den index.html mitbringt. Kein
	// Textersatz auf gut Glück: Fehlt der Platzhalter, ist das ein Fehler im
	// Build und keine Antwort ohne Nonce — die wäre eine Seite mit ungestyltem
	// Editor, und niemand wüsste warum.
	if !bytes.Contains(roh, []byte(nonceMarke)) {
		s.log.Error("index.html der neuen Oberfläche hat keinen Nonce-Platzhalter",
			"marke", nonceMarke)
		s.renderError(w, r, http.StatusInternalServerError,
			"Die Hülle der neuen Oberfläche ist unvollständig gebaut.")
		return
	}
	roh = bytes.ReplaceAll(roh, []byte(nonceMarke), []byte(nonce))

	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	w.Header().Set("Cache-Control", "no-store")
	if _, err := w.Write(roh); err != nil {
		s.log.Debug("v2: Hülle nicht vollständig geschrieben", "err", err)
	}
}

// nonceMarke ist der Platzhalter in web/index.html, an dessen Stelle der
// Stil-Nonce tritt. Er steht hier und dort — und der Handler bricht ab, wenn er
// ihn nicht findet, damit die beiden nicht auseinanderlaufen können.
const nonceMarke = "__CSP_NONCE__"
