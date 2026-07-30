package httpd

// Der Editor über /api/v1.
//
// Drei Zusagen, die über „Textfeld mit Speicherknopf" hinausgehen. Sie sind
// dieselben wie in der alten Oberfläche, weil sie in privops liegen und nicht im
// Handler — dies ist die JSON-Fassung der Auskunft darüber:
//
//  1. Zeilenenden und ein fehlender Schlussumbruch bleiben, wie sie waren. Ein
//     Editor, der aus 4000 CRLF-Zeilen stillschweigend LF macht, ist in einem
//     Panel nicht tragbar — der Unterschied wandert sonst in ein Diff, das
//     niemand lesen kann.
//  2. Wurde die Datei zwischenzeitlich von außen geändert, wird die fremde
//     Änderung NICHT überschrieben. Verglichen wird der SHA-256 des Inhalts beim
//     Laden.
//  3. Für Dateien, die sich prüfen lassen, läuft nach dem Schreiben das
//     Prüfprogramm des Systems. Schlägt es an, wird der Vorzustand
//     zurückgeschrieben — ein Tippfehler in sshd_config kostet sonst den Zugang
//     zum Server (Grundsatz VI: Was schiefgehen kann, hat einen Rückweg).
//
// Der einzige nennenswerte Unterschied zur alten Fläche ist der Statuscode des
// Konflikts. Er ist 412 und nicht 409, und das ist keine Geschmacksfrage: 409
// trägt in dieser Schnittstelle schon eine Bedeutung — „unbestätigt, hier ist
// der Text der Rückfrage" (api_v1_bestaetigung.go). Zwei Bedeutungen an einem
// Code hätte die Oberfläche an einem Feld im Rumpf auseinanderhalten müssen, und
// die Stelle, an der jemand das vergisst, wäre die, an der ein Konflikt als
// Rückfrage erscheint und ein zweiter Klick die fremde Änderung überschreibt.
// 412 sagt genau, was gilt: Die mitgeschickte Vorbedingung (der Hash) trifft
// nicht mehr zu.

import (
	"errors"
	"net/http"
	"path/filepath"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// apiDateiText ist eine Textdatei, wie der Editor sie sieht.
type apiDateiText struct {
	Eintrag apiEintrag `json:"eintrag"`
	// Inhalt hat Zeilenenden in LF — der Browser schickt es so zurück, und CRLF
	// wird beim Schreiben wiederhergestellt.
	Inhalt string `json:"inhalt"`
	// Hash ist der SHA-256 des Inhalts auf der Platte. Er geht beim Speichern
	// zurück und wird verglichen.
	Hash string `json:"hash"`
	// CRLF und OhneSchlussumbruch beschreiben, wie die Datei aussah. Sie gehen
	// unverändert zurück, damit sie so bleibt.
	CRLF               bool `json:"crlf"`
	OhneSchlussumbruch bool `json:"ohne_schlussumbruch"`
	// Sprache ist die Hervorhebung. Der Server bestimmt sie, weil hier der ganze
	// Pfad bekannt ist: /etc/nginx/sites-enabled/beispiel hat keine Endung, ist
	// aber nginx-Syntax.
	Sprache string `json:"sprache"`
	// Pruefbar sagt, ob es für diese Datei ein Prüfprogramm gibt. Die Oberfläche
	// nennt es vor dem Speichern — „nginx -t prüft danach" ist eine Zusage, die
	// man vorher wissen will, nicht erst im Ergebnis.
	Pruefbar    bool   `json:"pruefbar"`
	Werkzeug    string `json:"werkzeug,omitempty"`
	Verzeichnis string `json:"verzeichnis"`
	// MaxEdit ist die Obergrenze des Editors, nicht die Größe dieser Datei. Sie
	// steht hier, weil die Oberfläche sie nennt — und weil der erste Anlauf
	// stattdessen die Dateigröße zeigte („Der Editor öffnet Dateien bis 11 B"),
	// was eine Zusage über das Werkzeug in eine Aussage über den Zufall verkehrt.
	MaxEdit     int64  `json:"max_edit"`
	MaxEditText string `json:"max_edit_text"`
}

func (s *Server) textAus(t privops.TextFile) apiDateiText {
	werkzeug := privops.ConfigCheckTool(t.Entry.Path)
	maxEdit, _ := s.files.Limits()
	return apiDateiText{
		Eintrag:            eintragAus(t.Entry),
		Inhalt:             t.Content,
		Hash:               t.Hash,
		CRLF:               t.CRLF,
		OhneSchlussumbruch: t.NoFinalNewline,
		Sprache:            spracheFuer(t.Entry.Path),
		Pruefbar:           werkzeug != "",
		Werkzeug:           werkzeug,
		Verzeichnis:        filepath.Dir(t.Entry.Path),
		MaxEdit:            maxEdit,
		MaxEditText:        groesseText(maxEdit),
	}
}

// apiPruefung ist das Ergebnis des Prüfprogramms.
type apiPruefung struct {
	Geprueft bool   `json:"geprueft"`
	OK       bool   `json:"ok"`
	Werkzeug string `json:"werkzeug"`
	Ausgabe  string `json:"ausgabe"`
}

func pruefungAus(p privops.ConfigCheckResult) apiPruefung {
	return apiPruefung{Geprueft: p.Checked, OK: p.OK, Werkzeug: p.Tool, Ausgabe: p.Output}
}

// apiTextAntwort ist die Antwort auf ein geglücktes Speichern.
type apiTextAntwort struct {
	Meldung string       `json:"meldung"`
	Text    apiDateiText `json:"text"`
	// Pruefung fehlt, wenn es für diese Datei kein Prüfprogramm gibt. Ein
	// Ergebnis mit geprueft=false wäre dasselbe, aber die Oberfläche müsste es
	// auswerten, statt das Feld einfach nicht zu haben.
	Pruefung *apiPruefung `json:"pruefung,omitempty"`
}

// apiTextKonflikt ist die Antwort auf einen Hash-Konflikt (412).
//
// Sie trägt den Stand VON DER PLATTE mit. Ohne ihn müsste die Oberfläche eine
// zweite Anfrage stellen, um den neuen Hash zu bekommen — und in der Lücke
// dazwischen hätte der Bediener einen Konflikt ohne die Möglichkeit, ihn
// aufzulösen.
type apiTextKonflikt struct {
	Fehler string       `json:"fehler"`
	Jetzt  apiDateiText `json:"jetzt"`
}

// apiTextAbgelehnt ist die Antwort, wenn das Prüfprogramm die Datei ablehnt.
//
// Der wichtigste Rumpf dieses Moduls: Die Datei ist geschrieben UND wieder
// zurückgerollt worden, und beides muss dastehen. „Fehler beim Speichern" wäre
// hier die schädlichste Auskunft — der Bediener würde erneut speichern.
type apiTextAbgelehnt struct {
	Fehler   string      `json:"fehler"`
	Pruefung apiPruefung `json:"pruefung"`
	// Zurueck sagt, was mit dem Vorzustand geschehen ist. Auch der Fall, in dem
	// das Zurückrollen selbst scheitert, steht hier — dann liegt die Datei in der
	// abgelehnten Fassung da, und das darf nicht verschwiegen werden.
	Zurueck string       `json:"zurueck"`
	Text    apiDateiText `json:"text"`
}

// handleAPIFileText liefert eine Datei für den Editor.
func (s *Server) handleAPIFileText(w http.ResponseWriter, r *http.Request) {
	text, err := s.files.ReadText(r.Context(), r.URL.Query().Get("pfad"), 0)
	if err != nil {
		s.apiDateiFehler(w, err)
		return
	}
	s.apiJSON(w, http.StatusOK, s.textAus(text))
}

// apiTextAnfrage ist der Körper von POST /api/v1/files/text.
type apiTextAnfrage struct {
	Pfad   string `json:"pfad"`
	Inhalt string `json:"inhalt"`
	// Hash ist der Stand, den der Schreibende gelesen hat. Leer heißt: Es gab
	// nichts zu überschreiben (neue Datei).
	Hash               string `json:"hash"`
	CRLF               bool   `json:"crlf"`
	OhneSchlussumbruch bool   `json:"ohne_schlussumbruch"`
	// Ueberschreiben löst einen Konflikt bewusst auf: Der Hash wird dann nicht
	// verglichen. Die Oberfläche setzt es erst, nachdem sie den Konflikt gezeigt
	// hat — es ist die zweite Stufe aus docs/14-bestaetigungen.md, nur dass die
	// Frage hier keinen Text braucht: Der Konflikt IST die Frage.
	Ueberschreiben bool `json:"ueberschreiben"`
}

// handleAPIFileTextSave schreibt den Editor-Inhalt zurück.
func (s *Server) handleAPIFileTextSave(w http.ResponseWriter, r *http.Request) {
	var anfrage apiTextAnfrage
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}
	pfad := anfrage.Pfad

	opts := privops.WriteOptions{
		ExpectHash:     anfrage.Hash,
		CRLF:           anfrage.CRLF,
		NoFinalNewline: anfrage.OhneSchlussumbruch,
	}
	if anfrage.Ueberschreiben {
		// Kein ExpectHash: Überschrieben wird bewusst.
		opts.ExpectHash = ""
	}

	// Der Vorzustand wird VOR dem Schreiben gelesen, damit die Prüfung danach
	// einen Rückweg hat. Bei einer neuen Datei gibt es keinen — dann ist der
	// Rückweg das Löschen.
	vorher, vorherErr := s.files.ReadText(r.Context(), pfad, 0)

	text, err := s.files.WriteText(r.Context(), pfad, []byte(anfrage.Inhalt), opts)
	if err != nil {
		if errors.Is(err, privops.ErrConflict) {
			s.audit(r, "files.edit", pfad, store.ResultDenied, "Konflikt: von außen geändert")

			// Der aktuelle Stand von der Platte, damit der neue Hash in der
			// Antwort steht und ein zweiter Versuch bewusst überschreibt.
			jetzt, leseErr := s.files.ReadText(r.Context(), pfad, 0)
			if leseErr != nil {
				s.apiDateiFehler(w, leseErr)
				return
			}
			s.apiJSON(w, http.StatusPreconditionFailed, apiTextKonflikt{
				Fehler: "Die Datei wurde zwischenzeitlich außerhalb des Editors geändert. " +
					"Ihre Fassung ist nicht verloren — sie steht weiter im Editor. " +
					"Ein erneutes Speichern überschreibt die fremde Änderung.",
				Jetzt: s.textAus(jetzt),
			})
			return
		}
		s.dateiFehlerMitAudit(w, r, "files.edit", pfad, err)
		return
	}

	// Prüfprogramm, falls es für diese Datei eines gibt.
	pruefung, pruefErr := s.ops.ConfigCheck(r.Context(), text.Entry.Path)
	if pruefErr != nil {
		// Die Prüfung selbst ist gescheitert (Programm fehlt,
		// Zeitüberschreitung). Das ist kein Grund, die Änderung zurückzunehmen —
		// aber es gehört gesagt, statt „gespeichert" zu melden und zu schweigen.
		s.log.Warn("Konfigurationsprüfung nicht möglich", "pfad", text.Entry.Path, "err", pruefErr)
		s.audit(r, "files.edit", text.Entry.Path, store.ResultOK,
			"gespeichert, Prüfung nicht möglich: "+pruefErr.Error())
		s.apiJSON(w, http.StatusOK, apiTextAntwort{
			Meldung: "Gespeichert. Die Prüfung der Datei war nicht möglich: " + pruefErr.Error(),
			Text:    s.textAus(text),
		})
		return
	}

	if pruefung.Checked && !pruefung.OK {
		s.audit(r, "files.edit", text.Entry.Path, store.ResultError,
			pruefung.Tool+" hat abgelehnt: "+truncate(pruefung.Output, 200))

		zurueck := s.rolleZurueck(r, pfad, vorher, vorherErr)

		// Der Stand NACH dem Rückweg. Er ist das, was auf der Platte liegt, und
		// die Oberfläche muss ihn zeigen können — samt frischem Hash, damit ein
		// korrigierter zweiter Versuch nicht in einen Konflikt läuft.
		antwort := apiTextAbgelehnt{
			Fehler:   "Die Datei wurde nicht übernommen: " + pruefung.Tool + " lehnt sie ab.",
			Pruefung: pruefungAus(pruefung),
			Zurueck:  zurueck,
		}
		if jetzt, leseErr := s.files.ReadText(r.Context(), pfad, 0); leseErr == nil {
			antwort.Text = s.textAus(jetzt)
		}
		s.apiJSON(w, http.StatusBadRequest, antwort)
		return
	}

	meldung := "Gespeichert."
	if pruefung.Checked {
		meldung += " " + pruefung.Tool + " hat die Datei angenommen."
	}
	s.audit(r, "files.edit", text.Entry.Path, store.ResultOK, pruefDetail(pruefung))

	antwort := apiTextAntwort{Meldung: meldung, Text: s.textAus(text)}
	if pruefung.Checked {
		p := pruefungAus(pruefung)
		antwort.Pruefung = &p
	}
	s.apiJSON(w, http.StatusOK, antwort)
}
