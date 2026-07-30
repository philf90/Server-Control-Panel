package httpd

// Dateien über /api/v1 — der verändernde Teil.
//
// Alle Endpunkte hier liegen hinter apiSchreibend (Schreibrecht und
// X-CSRF-Token) und folgen demselben Ablauf: Werte lesen, gegebenenfalls
// zurückfragen, Operation aufrufen, Audit-Eintrag schreiben, den NEU GELESENEN
// Zustand des Ziels zurückgeben. Der letzte Punkt ist derselbe wie bei den
// Diensten: Die Oberfläche soll nach einer Aktion nichts raten müssen.
//
// Drei Regeln, die dieses Modul von den anderen unterscheiden:
//
//  1. Der Pfad wird nie geprüft. Auch nicht „kurz vorher, zur Sicherheit" — das
//     wäre eine zweite Wache, und zwei Wachen laufen auseinander. Geprüft wird in
//     privops.Files. Was hier steht, ist nur die Zusammensetzung aus Ordner und
//     Name, und die geht durch privops.PruefeName, damit der Grund einer
//     Ablehnung an der Eingabe steht und nicht im Pfad.
//  2. Vor der ersten Veränderung wird gefragt. Lesen und Zählen davor ist
//     erlaubt und nötig: „4132 Dateien, 1,2 GiB" ist die Angabe, die eine
//     Entscheidung trägt, und „Ordner wirklich löschen?" ist keine.
//  3. Ein großer Baum läuft als Vorgang. Die Grenze und die Mechanik sind
//     dieselben wie in der alten Oberfläche (grosseVorgangSchwelle,
//     starteDateiJob): Der Lauf überlebt das Ende der Anfrage, weil ein halb
//     kopiertes Verzeichnis, um das sich niemand mehr kümmert, schlimmer ist als
//     ein Vorgang ohne Zuschauer.

import (
	"context"
	"fmt"
	"io/fs"
	"net/http"
	"path/filepath"
	"strings"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// apiDateiAnfrage ist der Körper aller verändernden Endpunkte.
//
// Ein Typ für alle und nicht einer je Endpunkt: Die Felder überschneiden sich
// fast vollständig, und acht Typen mit je zwei eigenen Feldern wären acht Stellen,
// an denen `bestaetigt` fehlen kann. Was ein Endpunkt nicht braucht, bleibt leer.
type apiDateiAnfrage struct {
	// Pfad ist das Ziel der Handlung. Bei mkdir und touch das Verzeichnis, in
	// dem angelegt wird; sonst der Eintrag selbst.
	Pfad string `json:"pfad"`
	// Name ist der neue Name (mkdir, touch, umbenennen).
	Name string `json:"name"`
	// Ziel ist das Zielverzeichnis (kopieren, verschieben).
	Ziel string `json:"ziel"`
	// Rechte ist die oktale Angabe, Eigentuemer und Gruppe die Namen.
	Rechte      string `json:"rechte"`
	Eigentuemer string `json:"eigentuemer"`
	Gruppe      string `json:"gruppe"`
	Rekursiv    bool   `json:"rekursiv"`

	Bestaetigt bool   `json:"bestaetigt"`
	Getippt    string `json:"getippt"`
}

// apiDateiAntwort ist die Antwort auf eine ausgeführte Handlung.
type apiDateiAntwort struct {
	Meldung string `json:"meldung"`
	// Eintrag ist der neu gelesene Zustand des Ziels. Er fehlt nach dem Löschen —
	// es gibt ihn dann nicht mehr, und ein erfundener leerer Eintrag wäre eine
	// Behauptung über etwas, das weg ist.
	Eintrag *apiDateiDetail `json:"eintrag,omitempty"`
	// Ordner ist der Ort, den die Liste danach zeigen soll. Nach dem Löschen der
	// übergeordnete, nach dem Verschieben das Ziel.
	Ordner string `json:"ordner"`
	// Vorgang ist gesetzt, wenn die Handlung als Hintergrundvorgang läuft. Dann
	// ist der Statuscode 202 und Eintrag leer: Der Zustand steht erst fest, wenn
	// der Vorgang fertig ist.
	Vorgang *apiJob `json:"vorgang,omitempty"`
}

// apiDateiKoerper liest den Körper und beschneidet die Textfelder.
//
// Das Beschneiden gehört hierher und nicht in jeden Endpunkt: Ein Name mit einem
// versehentlichen Leerzeichen am Ende ist ein anderer Name, und das Verzeichnis
// „daten " wäre auf der Kommandozeile eine Quälerei. Der Pfad bleibt
// unangetastet — ein Pfad mit Leerzeichen am Rand ist selten, aber möglich, und
// ihn stillschweigend zu ändern hieße, auf etwas anderes zu zeigen als
// angefragt.
func (s *Server) apiDateiKoerper(w http.ResponseWriter, r *http.Request) (apiDateiAnfrage, bool) {
	var anfrage apiDateiAnfrage
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return anfrage, false
	}
	anfrage.Name = strings.TrimSpace(anfrage.Name)
	anfrage.Ziel = strings.TrimSpace(anfrage.Ziel)
	anfrage.Rechte = strings.TrimSpace(anfrage.Rechte)
	anfrage.Eigentuemer = strings.TrimSpace(anfrage.Eigentuemer)
	anfrage.Gruppe = strings.TrimSpace(anfrage.Gruppe)
	return anfrage, true
}

// neuerPfadIn setzt einen Pfad aus Ordner und Name zusammen.
//
// Geprüft wird nur der Name, und zwar mit der Regel der Pfadwache selbst
// (privops.PruefeName). Der Grund ist die Fehlermeldung: Ohne diese Prüfung
// würde aus dem Namen „unter/tief" der Pfad „…/unter/tief", und die Ablehnung
// hieße dann „Verzeichnis gibt es nicht" statt „ein Pfad ist kein Name". Der
// zusammengesetzte Pfad geht danach unverändert an privops — die Entscheidung
// liegt weiterhin dort.
func (s *Server) neuerPfadIn(w http.ResponseWriter, dir, name string) (string, bool) {
	if err := privops.PruefeName(name); err != nil {
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return "", false
	}
	return filepath.Join(filepath.Clean(dir), name), true
}

// dateiAntwort beantwortet eine geglückte Handlung mit dem neu gelesenen Zustand.
//
// Scheitert das Nachlesen, ist die Handlung trotzdem gelaufen: Dann trägt die
// Antwort nur die Meldung, statt eine gelungene Handlung als Fehler zu melden.
func (s *Server) dateiAntwort(w http.ResponseWriter, r *http.Request, pfad, meldung string) {
	antwort := apiDateiAntwort{Meldung: meldung, Ordner: filepath.Dir(filepath.Clean(pfad))}
	if detail, ok := s.dateiDetail(r, pfad); ok {
		antwort.Eintrag = detail
	}
	s.apiJSON(w, http.StatusOK, antwort)
}

// dateiDetail liest ein Detail für die Antwort. Der zweite Wert sagt, ob es
// gelesen werden konnte.
func (s *Server) dateiDetail(r *http.Request, pfad string) (*apiDateiDetail, bool) {
	eintrag, err := s.files.Stat(r.Context(), pfad)
	if err != nil {
		s.log.Warn("Eintrag nach Handlung lesen", "pfad", pfad, "err", err)
		return nil, false
	}
	maxEdit, maxUpload := s.files.Limits()
	detail := &apiDateiDetail{
		Eintrag:        eintragAus(eintrag),
		Ordner:         filepath.Dir(eintrag.Path),
		Krumen:         krumen(eintrag.Path),
		Rechte:         privops.DescribeMode(eintrag.Mode, eintrag.IsDir()),
		Benutzer:       []string{},
		Gruppen:        []string{},
		Schreibwurzeln: s.files.WritableRoots(),
		Aktionen:       dateiAktionen(eintrag, maxEdit),
		MaxEdit:        maxEdit,
		MaxEditText:    groesseText(maxEdit),
		MaxUpload:      maxUpload,
		MaxUploadText:  groesseText(maxUpload),
	}
	if eintrag.IsDir() {
		if mass, err := s.files.Measure(r.Context(), eintrag.Path); err == nil {
			inhalt := unterhalb(mass, true)
			detail.Mass = &inhalt
			detail.MassText = massText(inhalt)
		}
	}
	if users, groups, err := s.files.OwnerCandidates(r.Context()); err == nil {
		if len(users) > 0 {
			detail.Benutzer = users
		}
		if len(groups) > 0 {
			detail.Gruppen = groups
		}
	}
	return detail, true
}

// mustMeasure zählt und verschluckt den Fehler bewusst.
//
// Ein Fehler beim Zählen ist keiner, der die Handlung aufhalten darf: Er heißt,
// dass die Frage vage bleibt („0 Dateien" statt der wahren Zahl) — und die
// Operation selbst lehnt danach ab, was abzulehnen ist. Sie hier scheitern zu
// lassen hieße, ein Löschen daran zu hindern, dass ein Unterverzeichnis nicht
// lesbar ist. Der Fehler steht im Protokoll, damit er nicht spurlos bleibt.
func mustMeasure(r *http.Request, s *Server, pfad string) privops.Measurement {
	mass, err := s.files.Measure(r.Context(), pfad)
	if err != nil {
		s.log.Warn("Zählung unvollständig", "pfad", pfad, "err", err)
	}
	return mass
}

// dateiFehlerMitAudit beantwortet einen gescheiterten Eingriff und schreibt ihn
// mit. Die Unterscheidung zwischen „denied" und „error" steht in ergebnisVon:
// Das erste ist eine Aussage über die Politik, das zweite über das System.
func (s *Server) dateiFehlerMitAudit(w http.ResponseWriter, r *http.Request, aktion, ziel string, err error) {
	s.audit(r, aktion, ziel, ergebnisVon(err), err.Error())
	s.apiDateiFehler(w, err)
}

// ------------------------------------------------------------------ Anlegen ---

// handleAPIFileMkdir legt ein Verzeichnis an — Stufe 1.
//
// Ohne Rückfrage: Ein leeres Verzeichnis anzulegen ist umkehrbar und nimmt
// nichts weg. Ein Dialog davor erzieht zum Wegklicken und entwertet die
// Rückfrage dort, wo sie zählt.
func (s *Server) handleAPIFileMkdir(w http.ResponseWriter, r *http.Request) {
	anfrage, ok := s.apiDateiKoerper(w, r)
	if !ok {
		return
	}
	ziel, ok := s.neuerPfadIn(w, anfrage.Pfad, anfrage.Name)
	if !ok {
		return
	}

	if err := s.files.Mkdir(r.Context(), ziel); err != nil {
		s.dateiFehlerMitAudit(w, r, "files.mkdir", ziel, err)
		return
	}
	s.audit(r, "files.mkdir", ziel, store.ResultOK, "")
	s.dateiAntwort(w, r, ziel, "Ordner "+anfrage.Name+" angelegt.")
}

// handleAPIFileTouch legt eine leere Datei an — Stufe 1.
func (s *Server) handleAPIFileTouch(w http.ResponseWriter, r *http.Request) {
	anfrage, ok := s.apiDateiKoerper(w, r)
	if !ok {
		return
	}
	ziel, ok := s.neuerPfadIn(w, anfrage.Pfad, anfrage.Name)
	if !ok {
		return
	}

	if err := s.files.Touch(r.Context(), ziel); err != nil {
		s.dateiFehlerMitAudit(w, r, "files.touch", ziel, err)
		return
	}
	s.audit(r, "files.touch", ziel, store.ResultOK, "")
	s.dateiAntwort(w, r, ziel, "Datei "+anfrage.Name+" angelegt. Sie ist leer.")
}

// handleAPIFileRename benennt innerhalb desselben Verzeichnisses um — Stufe 1.
//
// Umkehrbar durch ein zweites Umbenennen, und der alte Name steht im Audit-Log.
// Dieselbe Stufe wie in der alten Oberfläche.
func (s *Server) handleAPIFileRename(w http.ResponseWriter, r *http.Request) {
	anfrage, ok := s.apiDateiKoerper(w, r)
	if !ok {
		return
	}
	if err := privops.PruefeName(anfrage.Name); err != nil {
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}

	if err := s.files.Rename(r.Context(), anfrage.Pfad, anfrage.Name); err != nil {
		s.dateiFehlerMitAudit(w, r, "files.rename", anfrage.Pfad, err)
		return
	}
	s.audit(r, "files.rename", anfrage.Pfad, store.ResultOK, "neuer Name: "+anfrage.Name)

	neu := filepath.Join(filepath.Dir(filepath.Clean(anfrage.Pfad)), anfrage.Name)
	s.dateiAntwort(w, r, neu, "Umbenannt in "+anfrage.Name+".")
}

// ------------------------------------------------- Kopieren und Verschieben ---

func (s *Server) handleAPIFileCopy(w http.ResponseWriter, r *http.Request) {
	s.apiKopierenOderVerschieben(w, r, false)
}

func (s *Server) handleAPIFileMove(w http.ResponseWriter, r *http.Request) {
	s.apiKopierenOderVerschieben(w, r, true)
}

// apiKopierenOderVerschieben führt beides — Stufe 1, wie in der alten
// Oberfläche.
//
// Kein Dialog davor, und das ist eine Entscheidung: Beides ist umkehrbar (das
// Kopierte löschen, das Verschobene zurückschieben), das Ziel wurde eben
// ausgewählt, und der Weg dorthin ging über die Ordnerauswahl — es ist also kein
// Klick, der überrascht. Ein großer Baum läuft als Vorgang; DAS ist die Auskunft,
// die hier gebraucht wird, und sie kommt mit der Antwort.
func (s *Server) apiKopierenOderVerschieben(w http.ResponseWriter, r *http.Request, verschieben bool) {
	anfrage, ok := s.apiDateiKoerper(w, r)
	if !ok {
		return
	}
	if anfrage.Ziel == "" {
		s.apiFehler(w, http.StatusBadRequest, "Es fehlt das Zielverzeichnis.")
		return
	}

	aktion, wort := "files.copy", "kopiert"
	if verschieben {
		aktion, wort = "files.move", "verschoben"
	}
	pfad := anfrage.Pfad
	ziel := anfrage.Ziel

	// Gezählt wird vorher, weil die Zahl entscheidet, ob der Lauf im Vorgang
	// landet. Ein Fehler beim Zählen ist keiner: Dann läuft es im Request, und
	// die Operation selbst lehnt ab, was abzulehnen ist.
	mass := mustMeasure(r, s, pfad)
	if mass.Files+mass.Dirs+mass.Symlinks > grosseVorgangSchwelle {
		gestartet := s.starteDateiJob(r, aktion, pfad, func(ctx context.Context, fortschritt privops.Progress) error {
			if verschieben {
				return s.files.Move(ctx, pfad, ziel, fortschritt)
			}
			return s.files.Copy(ctx, pfad, ziel, fortschritt)
		})
		if !gestartet {
			s.apiFehler(w, http.StatusConflict,
				"Es läuft bereits ein Dateivorgang. Zwei rekursive Läufe über denselben "+
					"Baum kämen sich in die Quere — warten Sie, bis der erste fertig ist.")
			return
		}
		s.apiJSON(w, http.StatusAccepted, apiDateiAntwort{
			Meldung: fmt.Sprintf("%s wird nach %s %s (%s). Der Vorgang läuft im Hintergrund weiter, "+
				"auch wenn Sie die Seite verlassen.",
				filepath.Base(pfad), ziel, wort, massText(mass)),
			Ordner:  ziel,
			Vorgang: s.jobAus(jobFiles),
		})
		return
	}

	var err error
	if verschieben {
		err = s.files.Move(r.Context(), pfad, ziel, nil)
	} else {
		err = s.files.Copy(r.Context(), pfad, ziel, nil)
	}
	if err != nil {
		s.dateiFehlerMitAudit(w, r, aktion, pfad+" → "+ziel, err)
		return
	}
	s.audit(r, aktion, pfad, store.ResultOK, "Ziel: "+ziel)

	// Nach dem Verschieben gibt es den alten Pfad nicht mehr; die Antwort zeigt
	// deshalb das Ziel.
	if verschieben {
		s.dateiAntwort(w, r, filepath.Join(ziel, filepath.Base(filepath.Clean(pfad))),
			"Nach "+ziel+" verschoben.")
		return
	}
	s.dateiAntwort(w, r, filepath.Join(ziel, filepath.Base(filepath.Clean(pfad))),
		"Nach "+ziel+" kopiert.")
}

// ------------------------------------------------------------------ Löschen ---

// handleAPIFileDelete löscht, bei Verzeichnissen samt Inhalt.
//
// Stufe 2, bei einem Ordner mit Inhalt Stufe 3 mit dem Namen. Dieselbe Regel wie
// in der alten Oberfläche, und sie steht an einer Stelle: Wüsste der Dialog im
// Browser eine andere, verlangte der Server ein Wort, nach dem niemand fragt.
func (s *Server) handleAPIFileDelete(w http.ResponseWriter, r *http.Request) {
	anfrage, ok := s.apiDateiKoerper(w, r)
	if !ok {
		return
	}
	pfad := anfrage.Pfad
	elter := filepath.Dir(filepath.Clean(pfad))

	// Erst durch die Pfadwache, dann fragen: Ein Pfad, den die Wache ablehnt,
	// soll die Antwort der Wache bekommen (403 oder 400) und keine Rückfrage, in
	// der er noch einmal geschrieben steht. Lesen darf vor der Bestätigung
	// geschehen, verändern nicht.
	eintrag, err := s.files.Stat(r.Context(), pfad)
	if err != nil {
		s.apiDateiFehler(w, err)
		return
	}
	// Gezählt wird OHNE den Eintrag selbst (siehe unterhalb): Ein leerer Ordner
	// soll nicht „1 Ordner" enthalten und nicht Stufe 3 sein.
	mass := unterhalb(mustMeasure(r, s, pfad), eintrag.IsDir())
	anzahl := mass.Files + mass.Dirs + mass.Symlinks

	// Die Zahlen stehen in der Frage, weil sie die Entscheidung tragen: „Ordner
	// wirklich löschen?" befähigt zu keiner, „4132 Dateien, 1,2 GiB" schon.
	name := eintrag.Name
	frage := name + " endgültig löschen?"
	punkte := []string{"Einen Papierkorb gibt es nicht. Rückgängig geht das nur aus einer Sicherung."}
	tippen := ""
	if eintrag.IsDir() {
		if anzahl == 0 {
			frage = "Den leeren Ordner " + name + " löschen?"
		} else {
			frage = name + " enthält " + massText(mass) + ". Alles endgültig löschen?"
			punkte = append(punkte, "Gelöscht wird der Ordner mit allem, was darunter liegt.")
			// Stufe 3: Hinter einem Klick steht hier nicht ein Eintrag, sondern
			// ein Baum.
			tippen = name
		}
	}
	punkte = append(punkte, pfad)

	// Gefragt wird nur, wo gelöscht werden könnte. Liegt der Pfad außerhalb der
	// Schreibbereiche oder ist er gesperrt, soll die Ablehnung der Wache kommen —
	// eine Rückfrage, deren Bestätigung dann in ein 403 läuft, wäre eine
	// Zumutung. Die Ablehnung selbst entsteht in Remove; hier fällt nur die
	// Rückfrage weg.
	if eintrag.Writable && !eintrag.Sensitive {
		if !s.apiBestaetigt(w, apiAktionAnfrage{
			Bestaetigt: anfrage.Bestaetigt, Getippt: anfrage.Getippt,
		}, apiBestaetigung{
			Titel:  "Löschen",
			Frage:  frage,
			Punkte: punkte,
			Knopf:  "endgültig löschen",
			Tippen: tippen,
		}) {
			return
		}
	}

	if anzahl > grosseVorgangSchwelle {
		gestartet := s.starteDateiJob(r, "files.delete", pfad, func(ctx context.Context, fortschritt privops.Progress) error {
			return s.files.Remove(ctx, pfad, fortschritt)
		})
		if !gestartet {
			s.apiFehler(w, http.StatusConflict, "Es läuft bereits ein Dateivorgang.")
			return
		}
		s.apiJSON(w, http.StatusAccepted, apiDateiAntwort{
			Meldung: fmt.Sprintf("%s wird gelöscht (%s). Der Vorgang läuft im Hintergrund weiter.",
				name, massText(mass)),
			Ordner:  elter,
			Vorgang: s.jobAus(jobFiles),
		})
		return
	}

	if err := s.files.Remove(r.Context(), pfad, nil); err != nil {
		s.dateiFehlerMitAudit(w, r, "files.delete", pfad, err)
		return
	}
	s.audit(r, "files.delete", pfad, store.ResultOK, massText(mass))

	// Kein Eintrag in der Antwort: Es gibt ihn nicht mehr.
	s.apiJSON(w, http.StatusOK, apiDateiAntwort{
		Meldung: name + " gelöscht.",
		Ordner:  elter,
	})
}

// ------------------------------------------------- Rechte und Eigentümer ---

// handleAPIFileMode setzt Rechte, Eigentümer und Gruppe.
//
// Beides in einem Endpunkt, wie in der alten Oberfläche: Wer die Rechte einer
// Datei richtet, ändert häufig im selben Schritt den Eigentümer — zwei Endpunkte
// bedeuteten zwei Runden und zwei Gelegenheiten, die zweite zu vergessen.
//
// Ein Unterschied zur alten Fassung, und zwar ein bewusster: Der REKURSIVE Lauf
// fragt zurück (Stufe 2). Bisher tat er es nicht, und das war eine Lücke — ein
// chmod 0777 über einen Baum ist mit keinem zweiten Klick zurückzuholen, weil die
// vorherigen Rechte je Eintrag verschieden waren und nirgends stehen. Der
// einzelne Eintrag bleibt Stufe 1: Dort ist die alte Angabe im Inspektor
// abzulesen, und ein Dialog vor jedem chmod erzieht zum Wegklicken.
func (s *Server) handleAPIFileMode(w http.ResponseWriter, r *http.Request) {
	anfrage, ok := s.apiDateiKoerper(w, r)
	if !ok {
		return
	}
	pfad := anfrage.Pfad

	if anfrage.Rechte == "" && anfrage.Eigentuemer == "" && anfrage.Gruppe == "" {
		s.apiFehler(w, http.StatusBadRequest,
			"Es war nichts anzuwenden: weder Rechte noch Eigentümer angegeben.")
		return
	}

	// Die Rechteangabe wird VOR der Rückfrage gelesen: Ist sie unlesbar, soll das
	// die Antwort sein und nicht ein Dialog, dessen Bestätigung dann in einen
	// Formatfehler läuft.
	var mode fs.FileMode
	if anfrage.Rechte != "" {
		m, err := privops.ParseMode(anfrage.Rechte)
		if err != nil {
			s.apiFehler(w, http.StatusBadRequest, err.Error())
			return
		}
		mode = m
	}

	if anfrage.Rekursiv {
		eintrag, err := s.files.Stat(r.Context(), pfad)
		if err != nil {
			s.apiDateiFehler(w, err)
			return
		}
		mass := unterhalb(mustMeasure(r, s, pfad), eintrag.IsDir())

		punkte := []string{
			"Geändert wird der Eintrag mit allem, was darunter liegt: " + massText(mass) + ".",
			"Die vorherigen Rechte waren je Eintrag verschieden und stehen nirgends — " +
				"ein zweiter Lauf holt sie nicht zurück.",
			pfad,
		}
		was := "Die Rechte"
		if anfrage.Rechte == "" {
			was = "Eigentümer und Gruppe"
		} else if anfrage.Eigentuemer != "" || anfrage.Gruppe != "" {
			was = "Rechte, Eigentümer und Gruppe"
		}

		if !s.apiBestaetigt(w, apiAktionAnfrage{
			Bestaetigt: anfrage.Bestaetigt, Getippt: anfrage.Getippt,
		}, apiBestaetigung{
			Titel:  "Rekursiv anwenden",
			Frage:  was + " von " + eintrag.Name + " einschließlich aller Einträge darunter ändern?",
			Punkte: punkte,
			Knopf:  "rekursiv anwenden",
		}) {
			return
		}
	}

	var getan []string

	if anfrage.Rechte != "" {
		if err := s.files.Chmod(r.Context(), pfad, mode, anfrage.Rekursiv); err != nil {
			s.dateiFehlerMitAudit(w, r, "files.chmod", pfad, err)
			return
		}
		s.audit(r, "files.chmod", pfad, store.ResultOK, detailRekursiv(anfrage.Rechte, anfrage.Rekursiv))
		getan = append(getan, "Rechte auf "+anfrage.Rechte)
	}

	if anfrage.Eigentuemer != "" || anfrage.Gruppe != "" {
		if err := s.files.Chown(r.Context(), pfad, anfrage.Eigentuemer, anfrage.Gruppe, anfrage.Rekursiv); err != nil {
			s.dateiFehlerMitAudit(w, r, "files.chown", pfad, err)
			return
		}
		wer := anfrage.Eigentuemer + ":" + anfrage.Gruppe
		s.audit(r, "files.chown", pfad, store.ResultOK, detailRekursiv(wer, anfrage.Rekursiv))
		getan = append(getan, "Eigentümer auf "+wer)
	}

	meldung := strings.Join(getan, " und ") + " gesetzt"
	if anfrage.Rekursiv {
		meldung += ", einschließlich aller Einträge darunter"
	}
	s.dateiAntwort(w, r, pfad, meldung+".")
}
