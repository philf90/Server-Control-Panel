package httpd

import (
	"context"
	"errors"
	"fmt"
	"net/http"
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

func detailRekursiv(wert string, rekursiv bool) string {
	if rekursiv {
		return wert + " (rekursiv)"
	}
	return wert
}

// ------------------------------------------------------------- Detailseite ---

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

// ------------------------------------------------------------- Hilfsmittel ---

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
