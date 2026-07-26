// Package version hält die zur Buildzeit eingesetzten Versionsangaben.
package version

import (
	"fmt"
	"runtime"
	"runtime/debug"
)

// Diese Werte setzt der Linker: -X github.com/philf90/asylum/internal/version.Version=…
var (
	Version = "dev"
	Commit  = ""
	Date    = ""
)

// String liefert eine einzeilige Beschreibung für Logs und HTTP-Header.
func String() string {
	if Commit == "" {
		return Version
	}
	return fmt.Sprintf("%s (%s)", Version, shortCommit())
}

// Full liefert die mehrzeilige Ausgabe von `asylumd version`.
func Full() string {
	commit := Commit
	if commit == "" {
		commit = revisionFromBuildInfo()
	}
	if commit == "" {
		commit = "unbekannt"
	}
	date := Date
	if date == "" {
		date = "unbekannt"
	}
	return fmt.Sprintf(
		"Project Asylum %s\ncommit:  %s\nbuilt:   %s\ngo:      %s\nplatform: %s/%s",
		Version, commit, date, runtime.Version(), runtime.GOOS, runtime.GOARCH,
	)
}

func shortCommit() string {
	if len(Commit) > 7 {
		return Commit[:7]
	}
	return Commit
}

// revisionFromBuildInfo greift auf die VCS-Angaben zurück, die `go build`
// selbst einbettet, wenn der Linker nichts gesetzt hat.
func revisionFromBuildInfo() string {
	info, ok := debug.ReadBuildInfo()
	if !ok {
		return ""
	}
	for _, s := range info.Settings {
		if s.Key == "vcs.revision" {
			return s.Value
		}
	}
	return ""
}
