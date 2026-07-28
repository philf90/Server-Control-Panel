package httpd

import (
	"errors"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"path/filepath"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// Der Upload ist der einzige Endpunkt des Panels, der einen großen Körper liest.
// Drei Dinge müssen deshalb anders sein als überall sonst:
//
//  1. Der Körper wird gestreamt, nicht geparst. r.ParseMultipartForm zöge eine
//     Datei von zwei Gigabyte in Speicher und Temp-Dateien; bei MemoryMax=256M
//     ist das kein Weg. Gelesen wird über r.MultipartReader() Teil für Teil, und
//     der Dateiteil geht ohne Umweg an privops.Files.Receive.
//  2. Der CSRF-Token kommt aus dem ersten Teil oder aus einer Kopfzeile, nicht
//     aus einem geparsten Formular — sonst wäre Punkt 1 hinfällig. Deshalb liegt
//     diese Route nicht hinter verifyCSRF, sondern prüft selbst. Die Reihenfolge
//     im Formular ist damit sicherheitsrelevant: _csrf steht vor dem Dateifeld,
//     und der Token ist geprüft, bevor das erste Byte Inhalt gelesen wird.
//  3. Die Lesefrist wird während des Empfangs immer wieder verlängert. Der
//     globale ReadTimeout von 30 Sekunden (server.go) gilt für alle anderen
//     Routen weiter; ein Upload über eine langsame Leitung reißt sonst mitten
//     im Körper ab.

const (
	// uploadFrist ist die Frist ohne Fortschritt. Sie wird immer wieder neu
	// gesetzt, solange Daten fließen — sie deckelt also die Pause, nicht die
	// Gesamtdauer.
	uploadFrist = 2 * time.Minute
	// maxCSRFTeil begrenzt den ersten Teil. Ein Token ist wenige Dutzend Zeichen
	// lang; alles darüber ist ein Versuch, den Speicher zu füllen.
	maxCSRFTeil = 512
	// maxFelderTeil begrenzt die übrigen Textfelder (Zielordner, Kennzeichen).
	maxFelderTeil = 4096
)

// handleFileUpload nimmt eine oder mehrere Dateien auf.
func (s *Server) handleFileUpload(w http.ResponseWriter, r *http.Request) {
	// Der Token darf auch als Kopfzeile kommen: Der Fortschrittsbalken läuft
	// über XMLHttpRequest, und dort ist eine Kopfzeile der geradere Weg als ein
	// Formularfeld.
	tokenGeprueft := s.csrfPasst(r, r.Header.Get("X-CSRF-Token"))

	leser, err := r.MultipartReader()
	if err != nil {
		s.uploadFehler(w, r, "", http.StatusBadRequest, "Der Upload ist unlesbar: "+err.Error())
		return
	}

	// Frist verlängern, solange Daten fließen.
	frist := &fristWaechter{rc: http.NewResponseController(w), spanne: uploadFrist}
	frist.erneuern()

	var (
		dir         string
		overwrite   bool
		aufgenommen []privops.FileEntry
	)

	for {
		teil, err := leser.NextPart()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			s.uploadFehler(w, r, dir, http.StatusBadRequest, "Der Upload brach ab: "+err.Error())
			return
		}

		switch {
		case teil.FormName() == "_csrf":
			wert, err := teilText(teil, maxCSRFTeil)
			if err != nil {
				s.uploadFehler(w, r, dir, http.StatusBadRequest, err.Error())
				return
			}
			tokenGeprueft = tokenGeprueft || s.csrfPasst(r, wert)

		case teil.FormName() == "dir":
			wert, err := teilText(teil, maxFelderTeil)
			if err != nil {
				s.uploadFehler(w, r, dir, http.StatusBadRequest, err.Error())
				return
			}
			dir = wert

		case teil.FormName() == "overwrite":
			wert, err := teilText(teil, maxFelderTeil)
			if err != nil {
				s.uploadFehler(w, r, dir, http.StatusBadRequest, err.Error())
				return
			}
			overwrite = wert == "1"

		case teil.FileName() != "":
			// Ab hier fließt Inhalt. Vorher muss alles geklärt sein: Token,
			// Zielverzeichnis, Name.
			if !tokenGeprueft {
				s.audit(r, "csrf.rejected", r.URL.Path, store.ResultDenied, "Upload ohne gültigen Token")
				s.uploadFehler(w, r, dir, http.StatusForbidden,
					"Das Formular ist abgelaufen oder der Token fehlt. Bitte die Seite neu laden.")
				return
			}
			if dir == "" {
				s.uploadFehler(w, r, dir, http.StatusBadRequest,
					"Im Upload fehlt das Zielverzeichnis. Es muss im Formular vor der Datei stehen.")
				return
			}

			name, err := privops.UploadName(teil.FileName())
			if err != nil {
				s.uploadFehler(w, r, dir, http.StatusBadRequest, err.Error())
				return
			}

			eintrag, err := s.files.Receive(r.Context(), dir, name, frist.umhuellen(teil),
				privops.ReceiveOptions{Overwrite: overwrite})
			if err != nil {
				s.audit(r, "files.upload", filepath.Join(dir, name), ergebnisVon(err), err.Error())
				s.uploadFehler(w, r, dir, statusVon(err), err.Error())
				return
			}
			s.audit(r, "files.upload", eintrag.Path, store.ResultOK, formatBytesKurz(eintrag.Size))
			aufgenommen = append(aufgenommen, eintrag)

		default:
			// Unbekannte Felder werden verworfen, aber gelesen: Ein
			// übersprungener Teil hinterlässt Bytes im Körper, an denen der
			// nächste NextPart scheitert.
			_, _ = io.Copy(io.Discard, io.LimitReader(teil, maxFelderTeil))
		}
		_ = teil.Close()
	}

	if len(aufgenommen) == 0 {
		s.uploadFehler(w, r, dir, http.StatusBadRequest, "Es war keine Datei im Upload.")
		return
	}

	if willJSON(r) {
		s.writeJSON(w, http.StatusOK, uploadAntwort{OK: true, Entries: aufgenommen})
		return
	}
	s.renderFiles(w, r, http.StatusOK, dir, uploadMeldung(aufgenommen), "")
}

type uploadAntwort struct {
	OK      bool                `json:"ok"`
	Error   string              `json:"error,omitempty"`
	Entries []privops.FileEntry `json:"entries,omitempty"`
}

func uploadMeldung(eintraege []privops.FileEntry) string {
	if len(eintraege) == 1 {
		return fmt.Sprintf("%s hochgeladen (%s).", eintraege[0].Name, formatBytesKurz(eintraege[0].Size))
	}
	var summe int64
	for _, e := range eintraege {
		summe += e.Size
	}
	return fmt.Sprintf("%d Dateien hochgeladen (%s).", len(eintraege), formatBytesKurz(summe))
}

// uploadFehler antwortet je nach Aufrufer als JSON oder als Seite.
func (s *Server) uploadFehler(w http.ResponseWriter, r *http.Request, dir string, status int, meldung string) {
	if willJSON(r) {
		s.writeJSON(w, status, uploadAntwort{Error: meldung})
		return
	}
	if dir == "" {
		s.renderError(w, r, status, meldung)
		return
	}
	s.renderFiles(w, r, status, dir, "", meldung)
}

// willJSON unterscheidet den Aufruf aus dem Skript vom Formular ohne
// JavaScript. Der Rückweg ohne Skript ist ausdrücklich vorgesehen — deshalb
// darf die Antwort nicht in jedem Fall JSON sein.
func willJSON(r *http.Request) bool {
	return strings.Contains(r.Header.Get("Accept"), "application/json")
}

// teilText liest ein kurzes Textfeld mit Obergrenze.
func teilText(teil *multipart.Part, max int64) (string, error) {
	roh, err := io.ReadAll(io.LimitReader(teil, max+1))
	if err != nil {
		return "", fmt.Errorf("Formularfeld %q unlesbar: %w", teil.FormName(), err)
	}
	if int64(len(roh)) > max {
		return "", fmt.Errorf("das Formularfeld %q ist zu lang", teil.FormName())
	}
	return strings.TrimSpace(string(roh)), nil
}

// fristWaechter setzt die Lesefrist der Verbindung neu, solange Daten
// eintreffen.
type fristWaechter struct {
	rc     *http.ResponseController
	spanne time.Duration
	letzte time.Time
}

func (f *fristWaechter) erneuern() {
	// Ein Fehler hier bedeutet: Der Writer unterstützt keine Fristen (etwa in
	// einem Test mit httptest.ResponseRecorder). Dann gilt die Frist des
	// Servers, und das ist kein Grund, den Upload abzulehnen.
	_ = f.rc.SetReadDeadline(time.Now().Add(f.spanne))
	f.letzte = time.Now()
}

// umhuellen verlängert die Frist während des Lesens.
func (f *fristWaechter) umhuellen(r io.Reader) io.Reader {
	return &fristLeser{f: f, r: r}
}

type fristLeser struct {
	f *fristWaechter
	r io.Reader
}

func (l *fristLeser) Read(p []byte) (int, error) {
	// Nicht bei jedem Block: setsockopt für jeden 256-KiB-Puffer wäre Aufwand
	// ohne Nutzen. Die halbe Spanne ist früh genug.
	if time.Since(l.f.letzte) > l.f.spanne/2 {
		l.f.erneuern()
	}
	return l.r.Read(p)
}
