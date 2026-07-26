// Package ui bettet Templates und statische Dateien in das Binary ein.
//
// Damit bleibt das Deployment bei genau einer Datei — es gibt kein
// Asset-Verzeichnis, das beim Update aus dem Tritt geraten könnte.
package ui

import (
	"embed"
	"fmt"
	"html/template"
	"io/fs"
	"time"
)

//go:embed templates/*.html
var templateFS embed.FS

//go:embed static
var staticFS embed.FS

// Templates parst alle Templates. Ein Fehler hier ist ein Programmierfehler und
// wird beim Start gemeldet, nicht erst beim ersten Request.
func Templates() (*template.Template, error) {
	return template.New("").Funcs(funcs()).ParseFS(templateFS, "templates/*.html")
}

// Static liefert das Dateisystem für /static/.
func Static() (fs.FS, error) {
	return fs.Sub(staticFS, "static")
}

func funcs() template.FuncMap {
	return template.FuncMap{
		"bytes":    formatBytes,
		"rate":     formatRate,
		"pct":      func(v float64) string { return fmt.Sprintf("%.1f", v) },
		"datetime": func(t time.Time) string { return t.Local().Format("02.01.2006 15:04:05") },
		"date":     func(t time.Time) string { return t.Local().Format("02.01.2006") },
		"since":    formatSince,
		// list baut im Template eine Aufzählung, etwa für die Schaltflächen
		// einer Dienstdetailseite.
		"list": func(items ...string) []string { return items },
	}
}

func formatBytes(b uint64) string {
	const unit = 1024
	if b < unit {
		return fmt.Sprintf("%d B", b)
	}
	div, exp := uint64(unit), 0
	for n := b / unit; n >= unit && exp < 4; n /= unit {
		div *= unit
		exp++
	}
	return fmt.Sprintf("%.1f %ciB", float64(b)/float64(div), "KMGTP"[exp])
}

func formatRate(bytesPerSecond float64) string {
	if bytesPerSecond < 1 {
		return "0 B/s"
	}
	return formatBytes(uint64(bytesPerSecond)) + "/s"
}

func formatSince(t time.Time) string {
	if t.IsZero() {
		return "nie"
	}
	d := time.Since(t)
	switch {
	case d < time.Minute:
		return "gerade eben"
	case d < time.Hour:
		return fmt.Sprintf("vor %d Min", int(d.Minutes()))
	case d < 24*time.Hour:
		return fmt.Sprintf("vor %d Std", int(d.Hours()))
	default:
		return t.Local().Format("02.01.2006 15:04")
	}
}
