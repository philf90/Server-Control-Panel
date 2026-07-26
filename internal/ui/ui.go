// Package ui bettet Templates und statische Dateien in das Binary ein.
//
// Damit bleibt das Deployment bei genau einer Datei — es gibt kein
// Asset-Verzeichnis, das beim Update aus dem Tritt geraten könnte.
package ui

import (
	"embed"
	"html/template"
	"io/fs"
)

//go:embed templates/*.html
var templateFS embed.FS

//go:embed static
var staticFS embed.FS

// Templates parst alle Templates. Ein Fehler hier ist ein Programmierfehler und
// wird beim Start gemeldet, nicht erst beim ersten Request.
func Templates() (*template.Template, error) {
	return template.ParseFS(templateFS, "templates/*.html")
}

// Static liefert das Dateisystem für /static/.
func Static() (fs.FS, error) {
	return fs.Sub(staticFS, "static")
}
