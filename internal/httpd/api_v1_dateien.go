package httpd

// Dateien über /api/v1 — der lesende Teil.
//
// Das Modul ist das größte des Panels und das einzige, dessen Ziel aus der
// Anfrage kommt und nicht aus einer Allowlist. Dieselbe Regel wie in der alten
// Oberfläche gilt deshalb hier wörtlich weiter: Der Pfad wird in diesem Paket
// niemals zusammengebaut, geprüft oder normalisiert, sondern unverändert an
// privops.Files übergeben. Dort sitzt die Pfadwache, und nur sie entscheidet.
// Was hier steht, ist ausschließlich: Werte aus der Anfrage lesen, Zahlen für
// die Anzeige aufbereiten, Fehler mit dem Statuscode versehen, der zum Grund
// passt.
//
// Drei Dinge sind gegenüber der alten Oberfläche bewusst anders:
//
//  1. Der Parameter heißt `pfad`, nicht `path`. Die neue Schnittstelle ist
//     durchgehend deutsch benannt (`umfang`, `regeln`, `aktiv`), und eine
//     Ausnahme davon wäre die Stelle, an der man beim nächsten Endpunkt rät.
//     Die alten Routen behalten `path` — sie werden nicht angefasst.
//  2. Liste und Suche sind derselbe Endpunkt mit demselben Rückgabetyp. Die
//     Oberfläche zeigt beides in derselben Tabelle, und zwei Formen desselben
//     Ergebnisses hätten zwei Renderpfade bedeutet.
//  3. Größen und Zeitpunkte kommen fertig formatiert mit. Der rohe Wert steht
//     daneben, damit sortiert und gerechnet werden kann — aber „1,2 GiB" soll
//     nicht in jedem Browser anders herauskommen als im Audit-Log.

import (
	"context"
	"net/http"
	"strconv"
	"strings"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/ui"
)

// apiEintrag ist ein Eintrag, wie die neue Oberfläche ihn sieht: die Felder aus
// privops.FileEntry (die tragen ihre JSON-Namen selbst) plus die zwei
// aufbereiteten Angaben.
type apiEintrag struct {
	privops.FileEntry
	// GroesseText ist bei Ordnern leer. Die Größe eines Verzeichnis-Inodes ist
	// keine Aussage über seinen Inhalt, und „4,0 KiB" neben einem Ordner mit
	// zweitausend Dateien ist schlechter als kein Wert.
	GroesseText string `json:"groesse_text"`
	// GeaendertText ist die Zeit in der Schreibweise, die das Panel überall
	// benutzt. Der rohe Zeitstempel steht als mod_time daneben.
	GeaendertText string `json:"geaendert_text"`
	// Art ist das Wort für die Spalte. Kind trägt schon „datei"/„ordner"; Art
	// ist die Beschriftung, die auch Sonderfälle benennt — ein gebrochener
	// Verweis ist etwas anderes als ein Verweis.
	Art string `json:"art"`
}

func eintragAus(e privops.FileEntry) apiEintrag {
	out := apiEintrag{FileEntry: e, Art: string(e.Kind)}
	if !e.IsDir() {
		out.GroesseText = groesseText(e.Size)
	}
	if !e.ModTime.IsZero() {
		out.GeaendertText = e.ModTime.Format("02.01.2006 15:04")
	}
	if e.Kind == privops.KindSymlink && e.LinkBroken {
		out.Art = "verweis (gebrochen)"
	}
	return out
}

// apiDateiZaehler sind die Zahlen über der Liste. Der Server zählt sie, wie er
// bei den Diensten die Zustände zählt: Zählte der Browser, zählte er nach der
// Kürzung — und behauptete dann „12 Dateien" für ein Verzeichnis mit viertausend.
type apiDateiZaehler struct {
	Ordner    int    `json:"ordner"`
	Dateien   int    `json:"dateien"`
	Verweise  int    `json:"verweise"`
	Sonstiges int    `json:"sonstiges"`
	Bytes     int64  `json:"bytes"`
	BytesText string `json:"bytes_text"`
	// Gesperrt zählt Einträge auf der Sperrliste. Sie sind sichtbar, ihr Inhalt
	// aber nie — und das ist eine Aussage über das Verzeichnis, die über die
	// Liste gehört.
	Gesperrt int `json:"gesperrt"`
}

// apiDateiListe ist die Antwort von GET /api/v1/files.
type apiDateiListe struct {
	Pfad   string     `json:"pfad"`
	Ordner apiEintrag `json:"ordner"`
	// Eltern ist das übergeordnete Verzeichnis, leer an der obersten sichtbaren
	// Stelle.
	Eltern string  `json:"eltern"`
	Krumen []crumb `json:"krumen"`
	// Wurzeln sind die sichtbaren Bäume, Schreibwurzeln die beschreibbaren
	// darunter. Die Oberfläche zeigt die ersten als Einstiegspunkte und braucht
	// die zweiten für die Zielauswahl beim Kopieren.
	Wurzeln        []string     `json:"wurzeln"`
	Schreibwurzeln []string     `json:"schreibwurzeln"`
	Eintraege      []apiEintrag `json:"eintraege"`
	// Gesamt ist die Zahl der Einträge VOR der Kürzung.
	Gesamt        int    `json:"gesamt"`
	Gekuerzt      bool   `json:"gekuerzt"`
	GekuerztGrund string `json:"gekuerzt_grund"`
	// Suche trägt den Begriff, wenn die Liste ein Suchergebnis ist. Nicht ein
	// bool: Die Oberfläche schreibt den Begriff in die Kopfzeile, und ein
	// zweiter Weg, ihn dorthin zu bekommen, liefe auseinander, sobald der Server
	// ihn beschneidet.
	Suche      string `json:"suche"`
	Sortierung string `json:"sortierung"`
	Absteigend bool   `json:"absteigend"`
	Versteckt  bool   `json:"versteckt"`

	Zaehler apiDateiZaehler `json:"zaehler"`
	// Frei ist der Platz auf dem Dateisystem dieses Pfades. Er steht hier und
	// nicht nur in der Übersicht, weil er hier die Entscheidung trägt: Ein
	// Upload von 4 GiB auf ein Dateisystem mit 300 MiB frei soll man vorher
	// sehen.
	Frei      uint64 `json:"frei"`
	FreiText  string `json:"frei_text"`
	FreiKnapp bool   `json:"frei_knapp"`
	// Warnungen sind Schreibbereiche, in denen nicht geschrieben werden kann,
	// obwohl sie es sollten — fast immer eine alte systemd-Härtung nach einem
	// Selbstupdate. Ohne diesen Hinweis sucht man den Fehler im Panel.
	Warnungen []privops.RootStatus `json:"warnungen"`
	// Vorgang ist ein laufender oder gerade beendeter Dateivorgang. Er gehört in
	// die Liste, weil ein rekursives Löschen die Liste ändert, während man sie
	// ansieht.
	Vorgang *apiJob `json:"vorgang"`
}

// freiKnappSchwelle: Darunter färbt die Oberfläche die Angabe. Ein Prozentwert
// wäre auf einer 8-TiB-Platte nutzlos und auf einer 8-GiB-Wurzel zu spät.
const freiKnappSchwelle = 1 << 30 // 1 GiB

// handleAPIFiles liefert ein Verzeichnis oder ein Suchergebnis.
func (s *Server) handleAPIFiles(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	pfad := s.filesPfad(q.Get("pfad"))
	begriff := strings.TrimSpace(q.Get("q"))

	opts := privops.ListOptions{
		Sort:       privops.ListSort(q.Get("sort")),
		Desc:       q.Get("desc") == "1",
		ShowHidden: q.Get("versteckt") == "1",
	}
	switch opts.Sort {
	case privops.SortName, privops.SortSize, privops.SortTime:
	default:
		// Eine unbekannte Sortierung ist kein Fehler, sondern ein alter Verweis.
		// Die Vorgabe ist die richtige Antwort darauf.
		opts.Sort = privops.SortName
	}

	antwort := apiDateiListe{
		Pfad:           pfad,
		Krumen:         krumen(pfad),
		Wurzeln:        s.files.ReadableRoots(),
		Schreibwurzeln: s.files.WritableRoots(),
		Eintraege:      []apiEintrag{},
		Sortierung:     string(opts.Sort),
		Absteigend:     opts.Desc,
		Versteckt:      opts.ShowHidden,
		Suche:          begriff,
		Warnungen:      s.filesWarnungen(r.Context()),
		Vorgang:        s.jobAus(jobFiles),
	}
	if antwort.Warnungen == nil {
		antwort.Warnungen = []privops.RootStatus{}
	}

	if begriff != "" {
		// Die Suche ersetzt die Liste, behält aber das Verzeichnis als Bezug:
		// Man soll sehen, worin gesucht wurde. Dieselbe Frist wie in der alten
		// Oberfläche — ein Baum mit Millionen Einträgen darf keine Verbindung
		// blockieren.
		ctx, abbruch := context.WithTimeout(r.Context(), suchZeit)
		defer abbruch()

		res, err := s.files.Search(ctx, pfad, begriff, 0)
		if err != nil {
			s.apiDateiFehler(w, err)
			return
		}
		for _, e := range res.Entries {
			antwort.Eintraege = append(antwort.Eintraege, eintragAus(e))
		}
		antwort.Gesamt = len(res.Entries)
		antwort.Gekuerzt = res.Truncated
		antwort.GekuerztGrund = res.Reason
		// Der Ordner wird zusätzlich gelesen: Search liefert Treffer, keine
		// Auskunft über das Verzeichnis. Scheitert das, bleibt der Bezug leer —
		// die Treffer sind trotzdem eine Antwort.
		if eintrag, err := s.files.Stat(r.Context(), pfad); err == nil {
			antwort.Ordner = eintragAus(eintrag)
		}
		s.zaehlen(&antwort)
		s.apiJSON(w, http.StatusOK, antwort)
		return
	}

	liste, err := s.files.List(r.Context(), pfad, opts)
	if err != nil {
		s.apiDateiFehler(w, err)
		return
	}
	antwort.Pfad = liste.Dir.Path
	antwort.Ordner = eintragAus(liste.Dir)
	antwort.Eltern = liste.Parent
	antwort.Krumen = krumen(liste.Dir.Path)
	antwort.Gesamt = liste.Total
	antwort.Gekuerzt = liste.Truncated
	for _, e := range liste.Entries {
		antwort.Eintraege = append(antwort.Eintraege, eintragAus(e))
	}
	if liste.Truncated {
		antwort.GekuerztGrund = "nur die ersten " +
			strconv.Itoa(len(liste.Entries)) + " von " + strconv.Itoa(liste.Total) + " Einträgen"
	}
	if frei, err := s.files.FreeSpace(r.Context(), pfad); err == nil {
		antwort.Frei = frei
		antwort.FreiText = ui.FormatBytes(frei)
		antwort.FreiKnapp = frei < freiKnappSchwelle
	}
	s.zaehlen(&antwort)
	s.apiJSON(w, http.StatusOK, antwort)
}

// zaehlen füllt die Zähler aus den Einträgen, die die Antwort trägt.
//
// Gezählt wird, was ausgeliefert wird, und nicht was im Verzeichnis liegt: Bei
// einer gekürzten Liste stünde sonst eine Summe über Einträgen, die nicht
// dabei sind. Gesamt sagt daneben, wie viele es wirklich waren.
func (s *Server) zaehlen(a *apiDateiListe) {
	for _, e := range a.Eintraege {
		switch e.Kind {
		case privops.KindDir:
			a.Zaehler.Ordner++
		case privops.KindRegular:
			a.Zaehler.Dateien++
			a.Zaehler.Bytes += e.Size
		case privops.KindSymlink:
			a.Zaehler.Verweise++
		default:
			a.Zaehler.Sonstiges++
		}
		if e.Sensitive {
			a.Zaehler.Gesperrt++
		}
	}
	a.Zaehler.BytesText = groesseText(a.Zaehler.Bytes)
}

// ------------------------------------------------------------------ Detail ---

// apiDateiDetail ist die Antwort von GET /api/v1/files/entry.
//
// Eigener Endpunkt, weil die Liste für zweitausend Einträge nichts mitschleppen
// soll, was nur beim Anklicken einer einzelnen Zeile interessiert: die
// Rechteaufschlüsselung in Worten, die Zählung eines Baums, die Namen aller
// Benutzer und Gruppen des Systems.
type apiDateiDetail struct {
	Eintrag apiEintrag `json:"eintrag"`
	// Ordner ist das Verzeichnis, in dem der Eintrag liegt — der Weg zurück in
	// die Liste.
	Ordner string  `json:"ordner"`
	Krumen []crumb `json:"krumen"`
	// Mass ist die Zählung UNTERHALB eines Verzeichnisses — ohne das Verzeichnis
	// selbst, anders als privops.Measure sie liefert (siehe unterhalb). Sie trägt
	// die Rückfrage vor dem Löschen und steht deshalb schon im Detail: Wer die
	// Zahl erst im Dialog sieht, hat den Knopf schon gedrückt.
	Mass     *privops.Measurement `json:"mass,omitempty"`
	MassText string               `json:"mass_text,omitempty"`
	// Rechte ist die Aufschlüsselung in Worten. „0755" sagt nur denen etwas, die
	// es ohnehin wissen.
	Rechte privops.ModeDescription `json:"rechte"`
	// Benutzer und Gruppen sind die Namen des Systems für die Auswahlfelder von
	// chown. Freitext gibt es dort bewusst nicht — ein Tippfehler kam sonst als
	// „Benutzer gibt es nicht" zurück.
	Benutzer []string `json:"benutzer"`
	Gruppen  []string `json:"gruppen"`
	// Schreibwurzeln sind die Einstiegspunkte der Zielauswahl beim Kopieren und
	// Verschieben.
	Schreibwurzeln []string `json:"schreibwurzeln"`
	// Aktionen sind die Handgriffe, die für diesen Eintrag sinnvoll sind. Eine
	// Bedienhilfe und keine Rechteprüfung — verbindlich ist die Pfadwache. Der
	// Grund, sie hier zu berechnen, ist derselbe wie bei den Diensten:
	// „umbenennen" an einem Eintrag außerhalb der Schreibbereiche anzubieten
	// heißt, eine Schaltfläche zu zeigen, die zuverlässig in ein 403 läuft.
	Aktionen []string `json:"aktionen"`
	// Die Obergrenzen, roh und als Text. Der Text steht dabei, damit die
	// Meldung „größer als 2,0 MiB" nicht in jedem Browser anders gerundet wird.
	MaxEdit       int64  `json:"max_edit"`
	MaxEditText   string `json:"max_edit_text"`
	MaxUpload     int64  `json:"max_upload"`
	MaxUploadText string `json:"max_upload_text"`
}

// Die Handgriffe eines Eintrags. Als Konstanten, damit Server und Oberfläche
// dieselben Wörter benutzen und ein Tippfehler nicht still zu einem fehlenden
// Knopf wird.
const (
	dateiAktionOeffnen       = "oeffnen"
	dateiAktionHerunterladen = "herunterladen"
	dateiAktionArchiv        = "archiv"
	dateiAktionBearbeiten    = "bearbeiten"
	dateiAktionUmbenennen    = "umbenennen"
	dateiAktionKopieren      = "kopieren"
	dateiAktionVerschieben   = "verschieben"
	dateiAktionRechte        = "rechte"
	dateiAktionLoeschen      = "loeschen"
	dateiAktionAnlegen       = "anlegen"
	dateiAktionHochladen     = "hochladen"
)

// dateiAktionen sagt, welche Handgriffe zu diesem Eintrag passen.
//
// maxEdit ist die Obergrenze des Editors; 0 oder weniger heißt „keine bekannt"
// und lässt die Prüfung entfallen.
func dateiAktionen(e privops.FileEntry, maxEdit int64) []string {
	out := make([]string, 0, 8)

	switch {
	case e.IsDir():
		out = append(out, dateiAktionOeffnen, dateiAktionArchiv)
		if e.Writable && !e.Sensitive {
			out = append(out, dateiAktionAnlegen, dateiAktionHochladen)
		}
	case e.Readable():
		out = append(out, dateiAktionHerunterladen)
		// Der Editor hat eine Obergrenze. Eine Logdatei von 800 MiB im Browser
		// zu öffnen ist kein Handgriff, sondern ein Absturz — angeboten wird er
		// deshalb nur, wo er auch gehen kann.
		if e.Writable && (maxEdit <= 0 || e.Size <= maxEdit) {
			out = append(out, dateiAktionBearbeiten)
		}
	}

	// Kopieren hängt am ZIEL, nicht an der Quelle: Aus /usr/share nach /srv zu
	// kopieren ist erlaubt, obwohl die Quelle nicht beschreibbar ist. Gesperrtes
	// bleibt außen vor — sein Inhalt wird nie gelesen, also auch nicht kopiert.
	if !e.Sensitive && e.Kind != privops.KindOther {
		out = append(out, dateiAktionKopieren)
	}
	if e.Writable && !e.Sensitive {
		out = append(out, dateiAktionUmbenennen, dateiAktionVerschieben,
			dateiAktionRechte, dateiAktionLoeschen)
	}
	return out
}

func (s *Server) handleAPIFileEntry(w http.ResponseWriter, r *http.Request) {
	pfad := r.URL.Query().Get("pfad")

	// Erst durch die Wache: Ein abgelehnter oder fehlender Pfad soll ihre Antwort
	// bekommen (403, 404) und nicht ein leeres Detail.
	if _, err := s.files.Stat(r.Context(), pfad); err != nil {
		s.apiDateiFehler(w, err)
		return
	}

	// Zusammengebaut wird das Detail in dateiDetail — dieselbe Funktion, die auch
	// die Antwort nach einer Handlung trägt (api_v1_dateien_schreiben.go). Zwei
	// Fassungen davon wären die Stelle, an der eine irgendwann ein Feld verliert:
	// Nach einem chmod stünden die Rechte dann anders da als nach einem Neuladen.
	detail, ok := s.dateiDetail(r, pfad)
	if !ok {
		s.apiFehler(w, http.StatusBadGateway, "Der Eintrag ist nicht lesbar.")
		return
	}
	s.apiJSON(w, http.StatusOK, *detail)
}

// unterhalb zieht den gemessenen Eintrag selbst aus einer Zählung heraus.
//
// privops.Measure zählt ihn mit: fs.WalkDir besucht die Wurzel des Laufs, und ein
// leeres Verzeichnis kommt deshalb als „1 Ordner" zurück. Für zwei Dinge ist das
// falsch, und beide sind mehr als Kosmetik:
//
//   - Die Frage vor dem Löschen lautete „leer enthält 0 Dateien, 1 Ordner (0 B).
//     Alles endgültig löschen?" — eine Aussage über den Ordner selbst, gestellt
//     als Aussage über seinen Inhalt.
//   - Die Bestätigungsstufe hing an derselben Summe. Damit war JEDER Ordner
//     Stufe 3, auch der leere: Wer ein versehentlich angelegtes Verzeichnis
//     wieder loswerden wollte, musste seinen Namen abschreiben. Eine Hürde ohne
//     Anlass entwertet die Hürde dort, wo sie zählt (docs/14-bestaetigungen.md).
//
// Die alte Oberfläche trägt diesen Fehler noch; sie ist eingefroren und behält
// ihn. Die Regel steht deshalb hier und nicht in privops: Measure zählt richtig,
// was es zu zählen behauptet — die Frage ist, was die Oberfläche daraus macht.
func unterhalb(m privops.Measurement, istOrdner bool) privops.Measurement {
	if istOrdner && m.Dirs > 0 {
		m.Dirs--
	}
	return m
}

// massText schreibt eine Zählung als Satzteil — der Text, der in der Rückfrage
// vor einem rekursiven Eingriff steht.
func massText(m privops.Measurement) string {
	// Nur nennen, was es gibt: „1 Datei, 0 Ordner" ist eine Aufzählung mit einem
	// Posten, der nichts beiträgt, und in einer Rückfrage lenkt jede solche Zahl
	// von der ab, auf die es ankommt.
	var teile []string
	if m.Files > 0 {
		teile = append(teile, strconv.Itoa(m.Files)+" "+einsOder(m.Files, "Datei", "Dateien"))
	}
	if m.Dirs > 0 {
		teile = append(teile, strconv.Itoa(m.Dirs)+" Ordner")
	}
	if m.Symlinks > 0 {
		teile = append(teile, strconv.Itoa(m.Symlinks)+" "+einsOder(m.Symlinks, "Verweis", "Verweise"))
	}
	if len(teile) == 0 {
		// Der leere Fall braucht ein Wort: Ein Satz, der mit „ (0 B)" beginnt,
		// liest sich wie ein abgeschnittener.
		return "keine Einträge"
	}
	text := strings.Join(teile, ", ") + " (" + groesseText(m.Bytes) + ")"
	if m.Truncated {
		// Die Zählung brach an einer Obergrenze ab. Das muss dabeistehen: „4132
		// Dateien" liest sich sonst wie eine vollständige Auskunft, und die
		// Entscheidung, die darauf fällt, wäre auf einer falschen Zahl gebaut.
		text += ", mindestens — die Zählung brach an einer Obergrenze ab"
	}
	return text
}

func einsOder(n int, eins, mehr string) string {
	if n == 1 {
		return eins
	}
	return mehr
}

// ------------------------------------------------------------ Zielauswahl ---

// apiOrdnerEintrag ist ein Unterverzeichnis in der Zielauswahl.
type apiOrdnerEintrag struct {
	Name string `json:"name"`
	Pfad string `json:"pfad"`
	// Beschreibbar entscheidet, ob dieses Verzeichnis als Ziel gewählt werden
	// kann; hineinsehen darf man auch in die anderen.
	Beschreibbar bool `json:"beschreibbar"`
	Gesperrt     bool `json:"gesperrt"`
}

// apiDateiOrdner ist die Antwort von GET /api/v1/files/dirs — die Grundlage der
// Zielauswahl beim Kopieren und Verschieben.
//
// Das Ziel war in der alten Oberfläche ein Textfeld, und „/srv/date" statt
// „/srv/daten" wurde erst beim Absenden zu einer Meldung. Auswählbar ist jetzt
// nur, was dieser Endpunkt genannt hat. Das ist keine Sicherheitsgrenze — die
// bleibt die Pfadwache beim Ausführen —, sondern eine Bedienhilfe.
type apiDateiOrdner struct {
	Pfad         string             `json:"pfad"`
	Eltern       string             `json:"eltern"`
	Krumen       []crumb            `json:"krumen"`
	Beschreibbar bool               `json:"beschreibbar"`
	Wurzeln      []string           `json:"wurzeln"`
	Ordner       []apiOrdnerEintrag `json:"ordner"`
	Gekuerzt     bool               `json:"gekuerzt"`
}

func (s *Server) handleAPIFileDirs(w http.ResponseWriter, r *http.Request) {
	pfad := s.filesPfad(r.URL.Query().Get("pfad"))

	liste, err := s.files.List(r.Context(), pfad, privops.ListOptions{
		Sort:       privops.SortName,
		ShowHidden: r.URL.Query().Get("versteckt") == "1",
	})
	if err != nil {
		s.apiDateiFehler(w, err)
		return
	}

	antwort := apiDateiOrdner{
		Pfad:         liste.Dir.Path,
		Eltern:       liste.Parent,
		Krumen:       krumen(liste.Dir.Path),
		Beschreibbar: liste.Dir.Writable && !liste.Dir.Sensitive,
		Wurzeln:      s.files.WritableRoots(),
		Ordner:       []apiOrdnerEintrag{},
		Gekuerzt:     liste.Truncated,
	}
	for _, e := range liste.Entries {
		if !e.IsDir() {
			continue
		}
		antwort.Ordner = append(antwort.Ordner, apiOrdnerEintrag{
			Name:         e.Name,
			Pfad:         e.Path,
			Beschreibbar: e.Writable && !e.Sensitive,
			Gesperrt:     e.Sensitive,
		})
	}
	s.apiJSON(w, http.StatusOK, antwort)
}

// ------------------------------------------------------------- Hilfsmittel ---

// apiDateiFehler beantwortet einen Fehler des Dateimanagers als JSON, mit dem
// Statuscode aus statusVon.
//
// Dieselbe Zuordnung wie filesFehler für die alte Oberfläche, nur ein anderer
// Ausgang. Sie liegt in statusVon und nicht hier, damit es genau eine gibt: Ein
// abgelehnter Pfad ist etwas anderes als ein fehlender, und ein Bedienfehler
// etwas anderes als ein Serverfehler — und beide Oberflächen sollen dasselbe
// darüber sagen.
// groesseText schreibt eine Byte-Zahl in der Form, die das Panel überall
// benutzt.
//
// Der eigene Umschlag um ui.FormatBytes hat einen einzigen Grund: Die Größen des
// Dateimanagers sind int64 (so kommen sie aus os.FileInfo), FormatBytes nimmt
// uint64. Eine negative Größe gibt es nicht, aber ein Vorzeichenwechsel bei der
// Umwandlung ergäbe eine absurde Zahl statt einer Null — und diese Prüfung soll
// an einer Stelle stehen und nicht an fünf.
func groesseText(n int64) string {
	if n <= 0 {
		return ui.FormatBytes(0)
	}
	return ui.FormatBytes(uint64(n))
}

func (s *Server) apiDateiFehler(w http.ResponseWriter, err error) {
	status := statusVon(err)
	if status >= http.StatusInternalServerError || status == http.StatusBadRequest {
		s.log.Warn("Dateimanager", "err", err)
	}
	s.apiFehler(w, status, err.Error())
}
