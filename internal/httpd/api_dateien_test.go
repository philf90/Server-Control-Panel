package httpd

// Tests für den lesenden Teil von /api/v1/files.
//
// Der Schwerpunkt liegt auf den Stellen, an denen ein Fehler nicht auffällt:
//
//   * Ein gesperrter Eintrag ist sichtbar, aber ohne Handgriff auf seinen Inhalt.
//     Wäre „herunterladen" dabei, lieferte der Endpunkt zwar 403 — aber die
//     Oberfläche hätte den Knopf gezeigt, und das ist bereits der Fehler.
//   * Die Aktionen eines Eintrags außerhalb der Schreibbereiche. Sie sind die
//     Bedienhilfe, die verhindert, dass eine Schaltfläche zuverlässig in ein 403
//     läuft.
//   * Die Zähler. Sie zählen, was ausgeliefert wurde; Gesamt sagt daneben, wie
//     viele es wirklich waren.
//   * Der Statuscode zum Grund: Ein abgelehnter Pfad ist etwas anderes als ein
//     fehlender, und beide sind etwas anderes als ein Serverfehler.

import (
	"encoding/json"
	"net/http"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// angemeldetMitDateien baut einen Server mit Dateimanager auf einem
// Wegwerfverzeichnis und ein angemeldetes Konto dazu.
func angemeldetMitDateien(t *testing.T, rolle string) (*Server, string, *http.Cookie, string) {
	t.Helper()
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "admin", rolle)
	cookie, csrf := login(t, s, user)
	return s, wurzel, cookie, csrf
}

func dateiListe(t *testing.T, s *Server, pfad string, cookie *http.Cookie) apiDateiListe {
	t.Helper()
	rec := get(t, s, "/api/v1/files?pfad="+pfad, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
	var antwort apiDateiListe
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	return antwort
}

func TestAPIDateienListeZaehltUndOrdnet(t *testing.T) {
	s, wurzel, cookie, _ := angemeldetMitDateien(t, store.RoleAdmin)

	lege(t, filepath.Join(wurzel, "schreibbar", "notizen.txt"), "hallo")
	lege(t, filepath.Join(wurzel, "schreibbar", "gross.log"), strings.Repeat("x", 4096))
	if err := os.MkdirAll(filepath.Join(wurzel, "schreibbar", "unterordner"), 0o755); err != nil {
		t.Fatal(err)
	}

	antwort := dateiListe(t, s, filepath.Join(wurzel, "schreibbar"), cookie)

	if antwort.Zaehler.Ordner != 1 || antwort.Zaehler.Dateien != 2 {
		t.Errorf("Zähler = %+v, erwartet 1 Ordner / 2 Dateien", antwort.Zaehler)
	}
	if antwort.Zaehler.Bytes != 4101 {
		t.Errorf("Bytes = %d, erwartet 4101 (5 + 4096)", antwort.Zaehler.Bytes)
	}
	if antwort.Zaehler.BytesText == "" {
		t.Error("bytes_text fehlt — die Oberfläche soll die Größe nicht selbst runden")
	}
	// Der Krumenpfad beginnt an der Wurzel und endet am Ort. Ohne ihn gibt es
	// keinen Weg nach oben, der ohne Tippen auskommt.
	if len(antwort.Krumen) < 2 || antwort.Krumen[0].Path != "/" {
		t.Errorf("Krumen = %+v, erwartet einen Pfad ab /", antwort.Krumen)
	}
	if antwort.Krumen[len(antwort.Krumen)-1].Path != antwort.Pfad {
		t.Errorf("letzte Krume ist %q, erwartet den Ort %q",
			antwort.Krumen[len(antwort.Krumen)-1].Path, antwort.Pfad)
	}
	// Die Größe kommt fertig formatiert mit, bei Ordnern aber nicht: Die Größe
	// eines Verzeichnis-Inodes ist keine Aussage über seinen Inhalt.
	for _, e := range antwort.Eintraege {
		if e.Kind == privops.KindDir && e.GroesseText != "" {
			t.Errorf("%s ist ein Ordner und trägt groesse_text %q", e.Name, e.GroesseText)
		}
		if e.Kind == privops.KindRegular && e.GroesseText == "" {
			t.Errorf("%s ist eine Datei ohne groesse_text", e.Name)
		}
		if e.GeaendertText == "" {
			t.Errorf("%s hat keinen geaendert_text", e.Name)
		}
	}
	// Die Bereiche sind die Einstiegspunkte, die Schreibbereiche die Teilmenge
	// darunter. Ohne beides kann die Oberfläche nicht sagen, wo etwas landen darf.
	if !slices.Contains(antwort.Wurzeln, wurzel) {
		t.Errorf("Wurzeln = %v, erwartet %q darin", antwort.Wurzeln, wurzel)
	}
	if !slices.Contains(antwort.Schreibwurzeln, filepath.Join(wurzel, "schreibbar")) {
		t.Errorf("Schreibwurzeln = %v, erwartet den schreibbaren Unterordner", antwort.Schreibwurzeln)
	}
}

// Ohne Pfad beginnt die Antwort im ersten sichtbaren Bereich — und sie sagt, wo
// sie begonnen hat. Täte sie das nicht, stünde in der Oberfläche ein Ort und in
// der Adresse keiner, und „eine Ebene höher" ginge ins Leere.
func TestAPIDateienOhnePfadBeginntInDerWurzel(t *testing.T) {
	s, wurzel, cookie, _ := angemeldetMitDateien(t, store.RoleAdmin)

	rec := get(t, s, "/api/v1/files", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var antwort apiDateiListe
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatal(err)
	}
	if antwort.Pfad != wurzel {
		t.Errorf("Pfad = %q, erwartet %q", antwort.Pfad, wurzel)
	}
}

// Ein gesperrter Eintrag ist sichtbar — sein Inhalt nie. Der Test prüft beides,
// weil beides eine Entscheidung ist: Ihn zu verstecken hieße, jemanden über den
// Inhalt seines Servers zu belügen; einen Download anzubieten hieße, die
// Sperrliste zur Empfehlung zu machen.
func TestAPIDateienGesperrtIstSichtbarOhneHandgriff(t *testing.T) {
	s, wurzel, cookie, _ := angemeldetMitDateien(t, store.RoleAdmin)

	lege(t, filepath.Join(wurzel, "schluessel.geheim"), "privat")

	antwort := dateiListe(t, s, wurzel, cookie)

	var gefunden *apiEintrag
	for i := range antwort.Eintraege {
		if antwort.Eintraege[i].Name == "schluessel.geheim" {
			gefunden = &antwort.Eintraege[i]
		}
	}
	if gefunden == nil {
		t.Fatal("der gesperrte Eintrag fehlt in der Liste — er soll sichtbar sein")
	}
	if !gefunden.Sensitive {
		t.Error("der Eintrag ist nicht als gesperrt gekennzeichnet")
	}
	if gefunden.SensitiveReason == "" {
		t.Error("es fehlt die Begründung — „gesperrt\" allein ist keine Auskunft")
	}
	if antwort.Zaehler.Gesperrt != 1 {
		t.Errorf("Zaehler.Gesperrt = %d, erwartet 1", antwort.Zaehler.Gesperrt)
	}

	// Und jetzt der eigentliche Punkt: Das Detail bietet keinen Handgriff an,
	// der den Inhalt anfassen würde.
	rec := get(t, s, "/api/v1/files/entry?pfad="+filepath.Join(wurzel, "schluessel.geheim"), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Detail-Status = %d: %s", rec.Code, rec.Body.String())
	}
	var detail apiDateiDetail
	if err := json.Unmarshal(rec.Body.Bytes(), &detail); err != nil {
		t.Fatal(err)
	}
	for _, verboten := range []string{
		dateiAktionHerunterladen, dateiAktionBearbeiten, dateiAktionKopieren,
	} {
		if slices.Contains(detail.Aktionen, verboten) {
			t.Errorf("Aktionen enthalten %q für einen gesperrten Eintrag: %v",
				verboten, detail.Aktionen)
		}
	}
}

// Die Aktionen richten sich nach dem, was gehen kann. Der Test hält die drei
// Fälle fest, in denen das nicht offensichtlich ist.
func TestAPIDateienAktionenPassenZumEintrag(t *testing.T) {
	s, wurzel, cookie, _ := angemeldetMitDateien(t, store.RoleAdmin)

	lege(t, filepath.Join(wurzel, "schreibbar", "notizen.txt"), "hallo")
	lege(t, filepath.Join(wurzel, "nurlesbar.txt"), "hallo")

	hole := func(pfad string) apiDateiDetail {
		t.Helper()
		rec := get(t, s, "/api/v1/files/entry?pfad="+pfad, cookie)
		if rec.Code != http.StatusOK {
			t.Fatalf("Status = %d für %s: %s", rec.Code, pfad, rec.Body.String())
		}
		var d apiDateiDetail
		if err := json.Unmarshal(rec.Body.Bytes(), &d); err != nil {
			t.Fatal(err)
		}
		return d
	}

	// 1. Eine Datei im Schreibbereich: alles.
	schreibbar := hole(filepath.Join(wurzel, "schreibbar", "notizen.txt"))
	for _, erwartet := range []string{
		dateiAktionHerunterladen, dateiAktionBearbeiten, dateiAktionKopieren,
		dateiAktionUmbenennen, dateiAktionVerschieben, dateiAktionRechte, dateiAktionLoeschen,
	} {
		if !slices.Contains(schreibbar.Aktionen, erwartet) {
			t.Errorf("Aktionen einer schreibbaren Datei enthalten %q nicht: %v",
				erwartet, schreibbar.Aktionen)
		}
	}

	// 2. Eine Datei außerhalb: lesen und kopieren ja, verändern nein. Kopieren
	//    hängt am Ziel, nicht an der Quelle — aus /usr/share nach /srv zu
	//    kopieren ist erlaubt.
	lesbar := hole(filepath.Join(wurzel, "nurlesbar.txt"))
	for _, erwartet := range []string{dateiAktionHerunterladen, dateiAktionKopieren} {
		if !slices.Contains(lesbar.Aktionen, erwartet) {
			t.Errorf("Aktionen einer nur lesbaren Datei enthalten %q nicht: %v",
				erwartet, lesbar.Aktionen)
		}
	}
	for _, verboten := range []string{
		dateiAktionBearbeiten, dateiAktionUmbenennen, dateiAktionLoeschen, dateiAktionRechte,
	} {
		if slices.Contains(lesbar.Aktionen, verboten) {
			t.Errorf("Aktionen einer nur lesbaren Datei enthalten %q: %v",
				verboten, lesbar.Aktionen)
		}
	}

	// 3. Ein Ordner: öffnen und archivieren, aber nicht herunterladen — ein
	//    open() auf ein Verzeichnis liefert keine Bytes.
	ordner := hole(filepath.Join(wurzel, "schreibbar"))
	if !slices.Contains(ordner.Aktionen, dateiAktionArchiv) {
		t.Errorf("ein Ordner bietet kein Archiv an: %v", ordner.Aktionen)
	}
	if slices.Contains(ordner.Aktionen, dateiAktionHerunterladen) {
		t.Errorf("ein Ordner bietet einen Download an: %v", ordner.Aktionen)
	}
	// Die Zählung steht schon im Detail und nicht erst im Löschdialog: Wer die
	// Zahl erst dort sieht, hat den Knopf bereits gedrückt.
	if ordner.Mass == nil || ordner.MassText == "" {
		t.Errorf("dem Ordner fehlt die Zählung: mass=%v mass_text=%q", ordner.Mass, ordner.MassText)
	}
	// Die Rechte in Worten. „0755" sagt nur denen etwas, die es ohnehin wissen.
	if len(ordner.Rechte.Roles) != 3 {
		t.Errorf("Rechte.Roles = %d, erwartet 3 (Eigentümer, Gruppe, andere)", len(ordner.Rechte.Roles))
	}
	// Die Grenzen kommen mit, damit der Editor nicht angeboten wird, wo er nicht
	// öffnen kann.
	if ordner.MaxEdit <= 0 || ordner.MaxUpload <= 0 {
		t.Errorf("Grenzen fehlen: max_edit=%d max_upload=%d", ordner.MaxEdit, ordner.MaxUpload)
	}
}

// Der Editor wird nicht angeboten, wo er nicht öffnen kann. Eine Schaltfläche,
// die zuverlässig in ein 413 läuft, nennt den Fehler erst nach dem Klick.
func TestAPIDateienEditorNurUnterDerGrenze(t *testing.T) {
	s, wurzel := newFilesServerMit(t, func(p *privops.FilesPolicy) {
		p.MaxEditSize = 16
	})
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, _ := login(t, s, user)

	lege(t, filepath.Join(wurzel, "schreibbar", "klein.txt"), "kurz")
	lege(t, filepath.Join(wurzel, "schreibbar", "gross.txt"), strings.Repeat("x", 64))

	hole := func(name string) []string {
		t.Helper()
		rec := get(t, s, "/api/v1/files/entry?pfad="+filepath.Join(wurzel, "schreibbar", name), cookie)
		if rec.Code != http.StatusOK {
			t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
		}
		var d apiDateiDetail
		if err := json.Unmarshal(rec.Body.Bytes(), &d); err != nil {
			t.Fatal(err)
		}
		return d.Aktionen
	}

	if !slices.Contains(hole("klein.txt"), dateiAktionBearbeiten) {
		t.Error("die kleine Datei wird nicht zum Bearbeiten angeboten")
	}
	if slices.Contains(hole("gross.txt"), dateiAktionBearbeiten) {
		t.Error("die zu große Datei wird zum Bearbeiten angeboten — der Klick liefe in ein 413")
	}
}

// Die Suche liefert dieselbe Form wie die Liste und trägt den Begriff zurück.
// Zwei Antwortformen hätten zwei Renderpfade bedeutet.
func TestAPIDateienSucheLiefertDieselbeForm(t *testing.T) {
	s, wurzel, cookie, _ := angemeldetMitDateien(t, store.RoleAdmin)

	lege(t, filepath.Join(wurzel, "schreibbar", "tief", "gesucht.conf"), "a: 1")
	lege(t, filepath.Join(wurzel, "schreibbar", "anderes.txt"), "x")

	rec := get(t, s, "/api/v1/files?pfad="+wurzel+"&q=gesucht", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var antwort apiDateiListe
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatal(err)
	}
	if antwort.Suche != "gesucht" {
		t.Errorf("Suche = %q, erwartet den Begriff zurück", antwort.Suche)
	}
	if len(antwort.Eintraege) != 1 || antwort.Eintraege[0].Name != "gesucht.conf" {
		t.Fatalf("Treffer = %+v, erwartet genau gesucht.conf", antwort.Eintraege)
	}
	// Der Ort steht am Treffer: Ein Suchergebnis quer über Unterordner wäre ohne
	// ihn eine Sammlung von Namen, von denen keiner auffindbar ist.
	if !strings.HasSuffix(antwort.Eintraege[0].Path, "tief/gesucht.conf") {
		t.Errorf("Pfad des Treffers = %q, erwartet den Ort unterhalb", antwort.Eintraege[0].Path)
	}
	// Der Bezug bleibt: Man soll sehen, worin gesucht wurde.
	if antwort.Pfad != wurzel {
		t.Errorf("Pfad = %q, erwartet den durchsuchten Ort %q", antwort.Pfad, wurzel)
	}
}

// Die Zielauswahl nennt nur Ordner und sagt an jedem, ob dort etwas landen darf.
// Ein Ziel, das man wählen kann und das dann 403 liefert, ist die schlechteste
// aller Antworten.
func TestAPIDateienOrdnerauswahlKennzeichnetBeschreibbar(t *testing.T) {
	s, wurzel, cookie, _ := angemeldetMitDateien(t, store.RoleAdmin)

	if err := os.MkdirAll(filepath.Join(wurzel, "nurlesbar"), 0o755); err != nil {
		t.Fatal(err)
	}
	lege(t, filepath.Join(wurzel, "datei.txt"), "x")

	rec := get(t, s, "/api/v1/files/dirs?pfad="+wurzel, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var antwort apiDateiOrdner
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatal(err)
	}

	nach := map[string]bool{}
	for _, o := range antwort.Ordner {
		nach[o.Name] = o.Beschreibbar
	}
	if _, drin := nach["datei.txt"]; drin {
		t.Error("die Auswahl enthält eine Datei — sie soll nur Ordner nennen")
	}
	if beschreibbar, drin := nach["schreibbar"]; !drin || !beschreibbar {
		t.Errorf("„schreibbar\" fehlt oder ist nicht als beschreibbar gekennzeichnet: %+v", antwort.Ordner)
	}
	if beschreibbar, drin := nach["nurlesbar"]; !drin || beschreibbar {
		t.Errorf("„nurlesbar\" fehlt oder ist als beschreibbar gekennzeichnet: %+v", antwort.Ordner)
	}
}

// Der Statuscode passt zum Grund. Ohne diese Unterscheidung stünde für jeden
// Fall 400 in der Oberfläche, und ein abgelehnter Pfad wäre von einem
// vertippten nicht zu unterscheiden.
func TestAPIDateienStatuscodesPassenZumGrund(t *testing.T) {
	s, wurzel, cookie, _ := angemeldetMitDateien(t, store.RoleAdmin)

	faelle := []struct {
		name   string
		pfad   string
		status int
	}{
		{"außerhalb der Wurzel", "/etc/shadow", http.StatusForbidden},
		{"gibt es nicht", filepath.Join(wurzel, "fehlt.txt"), http.StatusNotFound},
		// 400 und nicht 403: Ein relativer Pfad ist keine Ablehnung der Politik,
		// sondern eine Anfrage, die die Wache gar nicht auswerten kann. Die
		// Unterscheidung steht im Audit-Log — „denied" ist eine Aussage über die
		// Politik, und ein Tippfehler soll dort nicht wie ein Angriff aussehen.
		{"relativer Pfad", "../../etc", http.StatusBadRequest},
	}
	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			rec := get(t, s, "/api/v1/files/entry?pfad="+f.pfad, cookie)
			if rec.Code != f.status {
				t.Errorf("Status = %d, erwartet %d: %s", rec.Code, f.status, rec.Body.String())
			}
			// Und in jedem Fall JSON: Ein fetch, das HTML bekommt, meldet einen
			// Parserfehler statt der eigentlichen Ursache.
			var rumpf struct {
				Fehler string `json:"fehler"`
			}
			if err := json.Unmarshal(rec.Body.Bytes(), &rumpf); err != nil {
				t.Errorf("Antwort ist kein JSON: %v (%s)", err, rec.Body.String())
			}
			if rumpf.Fehler == "" {
				t.Error("die Antwort nennt keinen Grund")
			}
		})
	}
}

// Lesen darf jede Rolle — dieselbe Grenze wie bei GET /files. Ein Konto mit
// Leserecht soll den Server ansehen können.
func TestAPIDateienLesenBrauchtKeineSchreibrolle(t *testing.T) {
	s, wurzel := newFilesServer(t)
	user := addUser(t, s, "leser", store.RoleReadOnly)
	cookie, _ := login(t, s, user)

	rec := get(t, s, "/api/v1/files?pfad="+wurzel, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200 für ein Leserkonto: %s", rec.Code, rec.Body.String())
	}
}

// Ist das Modul abgeschaltet, gibt es die Routen nicht. Abschalten entfernt
// Rechte, nicht nur den Menüpunkt — ein 404 ist hier die richtige Antwort und
// nicht ein 403 mit Hinweis.
func TestAPIDateienAbgeschaltetHatKeineRoute(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleOwner)
	s.files = nil

	for _, pfad := range []string{
		"/api/v1/files", "/api/v1/files/entry?pfad=/etc",
		"/api/v1/files/dirs", "/api/v1/files/download?pfad=/etc/hosts",
	} {
		if rec := get(t, s, pfad, cookie); rec.Code != http.StatusNotFound {
			t.Errorf("%s → Status %d, erwartet 404 bei abgeschaltetem Modul", pfad, rec.Code)
		}
	}
}

// Download und Archiv liegen unter /api/v1 auf denselben Handlern wie unter
// /files. Der Test hält fest, dass die neuen Adressen wirklich Bytes liefern —
// eine Route, die nur registriert ist, hätte niemand bemerkt.
func TestAPIDateienDownloadUndArchivLiefernBytes(t *testing.T) {
	s, wurzel, cookie, _ := angemeldetMitDateien(t, store.RoleAdmin)

	lege(t, filepath.Join(wurzel, "schreibbar", "notizen.txt"), "hallo welt")

	rec := get(t, s, "/api/v1/files/download?path="+filepath.Join(wurzel, "schreibbar", "notizen.txt"), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Download-Status = %d: %s", rec.Code, rec.Body.String())
	}
	if rec.Body.String() != "hallo welt" {
		t.Errorf("Inhalt = %q, erwartet „hallo welt\"", rec.Body.String())
	}
	// Der Kopf entscheidet, ob eine ausgelieferte HTML-Datei im Ursprung des
	// Panels läuft. Er gehört deshalb in den Test und nicht in die Annahme.
	if got := rec.Header().Get("Content-Type"); got != "application/octet-stream" {
		t.Errorf("Content-Type = %q, erwartet application/octet-stream", got)
	}
	if !strings.HasPrefix(rec.Header().Get("Content-Disposition"), "attachment;") {
		t.Errorf("Content-Disposition = %q, erwartet attachment", rec.Header().Get("Content-Disposition"))
	}

	rec = get(t, s, "/api/v1/files/archive?path="+filepath.Join(wurzel, "schreibbar"), cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Archiv-Status = %d", rec.Code)
	}
	if got := rec.Header().Get("Content-Type"); got != "application/gzip" {
		t.Errorf("Content-Type = %q, erwartet application/gzip", got)
	}
	if rec.Body.Len() == 0 {
		t.Error("das Archiv ist leer")
	}
}

// Ein Verweis wird als solcher benannt, und ein gebrochener auch. Die
// Unterscheidung ist die Antwort auf „warum lässt sich das nicht öffnen".
func TestAPIDateienVerweisWirdBenannt(t *testing.T) {
	s, wurzel, cookie, _ := angemeldetMitDateien(t, store.RoleAdmin)

	lege(t, filepath.Join(wurzel, "ziel.txt"), "da")
	if err := os.Symlink(filepath.Join(wurzel, "ziel.txt"), filepath.Join(wurzel, "gut.link")); err != nil {
		t.Skipf("Symlinks nicht möglich: %v", err)
	}
	if err := os.Symlink(filepath.Join(wurzel, "weg.txt"), filepath.Join(wurzel, "kaputt.link")); err != nil {
		t.Fatal(err)
	}

	antwort := dateiListe(t, s, wurzel, cookie)
	nach := map[string]apiEintrag{}
	for _, e := range antwort.Eintraege {
		nach[e.Name] = e
	}

	if nach["gut.link"].Art != string(privops.KindSymlink) {
		t.Errorf("Art von gut.link = %q, erwartet %q", nach["gut.link"].Art, privops.KindSymlink)
	}
	if nach["gut.link"].LinkTarget == "" {
		t.Error("gut.link nennt kein Ziel")
	}
	if !nach["kaputt.link"].LinkBroken {
		t.Error("kaputt.link ist nicht als gebrochen gekennzeichnet")
	}
	if !strings.Contains(nach["kaputt.link"].Art, "gebrochen") {
		t.Errorf("Art von kaputt.link = %q, erwartet einen Hinweis auf den Bruch", nach["kaputt.link"].Art)
	}
	if antwort.Zaehler.Verweise != 2 {
		t.Errorf("Zaehler.Verweise = %d, erwartet 2", antwort.Zaehler.Verweise)
	}
}

// Der Dateivorgang ist eine bekannte Vorgangsart. Fehlte er in jobArten, wäre
// die Platte auf der Seite nie zu sehen und /api/v1/jobs/files ein 404.
func TestAPIDateienVorgangsartIstBekannt(t *testing.T) {
	if _, ok := jobArten[jobFiles]; !ok {
		t.Fatal("jobFiles fehlt in jobArten — die Vorgangsplatte bekäme ein 404")
	}
	s, cookie, _ := angemeldet(t, store.RoleAdmin)
	// Noch kein Vorgang gelaufen: 204, nicht 404. Die Ressource gibt es, sie ist
	// nur leer.
	if rec := get(t, s, "/api/v1/jobs/files", cookie); rec.Code != http.StatusNoContent {
		t.Errorf("Status = %d, erwartet 204 ohne gelaufenen Vorgang", rec.Code)
	}
}
