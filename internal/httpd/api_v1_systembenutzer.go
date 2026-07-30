package httpd

// Systembenutzer und SSH-Schlüssel über /api/v1.
//
// Das Modul verwaltet Konten des Wirtsystems — nicht die des Panels. Der
// Unterschied ist wichtig genug, dass er in der Oberfläche zwei getrennte Punkte
// hat: Ein Systemkonto kommt über SSH auf den Server, ein Panelkonto in diese
// Fläche. Wer das verwechselt, legt ein Konto an, das nichts kann, oder eines,
// das mehr kann als gedacht.
//
// Zwei Dinge sind hier anders als in den übrigen Modulen:
//
//  1. **Diese Konten haben kein Passwort.** Sie werden mit gesperrtem
//     Passwortfeld angelegt, und die Anmeldung läuft ausschließlich über einen
//     SSH-Schlüssel. Ein Konto ohne Schlüssel ist deshalb kein halb fertiges
//     Konto, sondern eines, das niemand benutzen kann — und die Oberfläche sagt
//     das, statt es als Erfolg zu quittieren.
//  2. **Der letzte Schlüssel ist eine eigene Rückfrage.** Ihn zu entfernen
//     nimmt dem Konto den Zugang vollständig. Das ist derselbe Gedanke wie beim
//     Panel-Port in der Firewall: Der Fall, in dem man sich aussperrt, gehört
//     benannt und nicht nur abgelehnt.

import (
	"net/http"
	"sort"
	"strconv"
	"strings"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// apiSystemkonto ist eine Zeile der Kontenliste.
type apiSystemkonto struct {
	privops.SystemUser
	// Art ordnet das Konto ein: „mensch" für ein Konto mit Anmeldeschale,
	// „dienst" für ein Systemkonto ohne. Der Server entscheidet es, weil die
	// Regel an zwei Feldern hängt (System und HasShell) und beide Fassungen
	// davon auseinanderlaufen würden.
	Art string `json:"art"`
	// Zustand ist die Klasse für die Einfärbung. Ein gesperrtes Konto ist eine
	// Warnung und kein Fehler — es ist ein gewollter Zustand.
	Zustand string `json:"zustand"`
	// OhneSchluessel sagt, dass dieses Konto sich nicht anmelden kann. Bei einem
	// Menschenkonto ist das eine Auffälligkeit; bei einem Dienstkonto der
	// Normalfall, und dann steht sie nicht da.
	OhneSchluessel bool `json:"ohne_schluessel"`
	// Aktionen sind die Handgriffe, die für dieses Konto möglich sind. Eine
	// Bedienhilfe: Verbindlich ist protectedUser in privops. „löschen" an root
	// anzubieten und dann zu verweigern wäre die schlechteste Antwort.
	Aktionen []string `json:"aktionen"`
}

// Die Handgriffe eines Systemkontos.
const (
	kontoAktionSperren    = "sperren"
	kontoAktionEntsperren = "entsperren"
	kontoAktionLoeschen   = "loeschen"
	kontoAktionSchluessel = "schluessel"
)

func systemkontoAus(u privops.SystemUser) apiSystemkonto {
	k := apiSystemkonto{
		SystemUser: u,
		Art:        "dienst",
		Zustand:    "gut",
		Aktionen:   []string{},
	}
	if u.HasShell && !u.System {
		k.Art = "mensch"
	}
	if u.Locked {
		k.Zustand = "warn"
	}
	// Ein Konto ohne Schlüssel kommt nicht herein. Bei einem Dienstkonto ist das
	// so gewollt — dort ist es keine Auffälligkeit, sondern die Bauart.
	k.OhneSchluessel = k.Art == "mensch" && u.SSHKeys == 0

	// Schlüssel verwalten geht bei jedem Konto, das eine Anmeldeschale hat: Bei
	// einem Dienstkonto ohne Schale liegt in ~/.ssh nichts, was jemand benutzen
	// könnte, und ein Schlüssel dort wäre eine falsche Zusage.
	if u.HasShell {
		k.Aktionen = append(k.Aktionen, kontoAktionSchluessel)
	}
	if !u.Protected {
		if u.Locked {
			k.Aktionen = append(k.Aktionen, kontoAktionEntsperren)
		} else {
			k.Aktionen = append(k.Aktionen, kontoAktionSperren)
		}
		k.Aktionen = append(k.Aktionen, kontoAktionLoeschen)
	}
	return k
}

// apiSystemkontoZaehler sind die Zahlen über der Liste.
type apiSystemkontoZaehler struct {
	Gesamt   int `json:"gesamt"`
	Menschen int `json:"menschen"`
	Dienste  int `json:"dienste"`
	Gesperrt int `json:"gesperrt"`
	// OhneSchluessel zählt nur Menschenkonten. Es ist die Zahl, die eine
	// Handlung nach sich zieht: Diese Konten kommen nicht auf den Server.
	OhneSchluessel int `json:"ohne_schluessel"`
}

// apiSystembenutzer ist die Antwort von GET /api/v1/system-users.
type apiSystembenutzer struct {
	Konten  []apiSystemkonto      `json:"konten"`
	Zaehler apiSystemkontoZaehler `json:"zaehler"`
	// Schalen sind die Anmeldeschalen des Systems für das Auswahlfeld. Freitext
	// gibt es dort nicht: Eine Schale, die es nicht gibt, macht das Konto
	// unbenutzbar, und useradd nimmt sie widerspruchslos an.
	Schalen []string `json:"schalen"`
	// Gruppen sind die vorhandenen Gruppen. Dieselbe Überlegung.
	Gruppen []string `json:"gruppen"`
	Fehler  string   `json:"fehler"`
}

func (s *Server) handleAPISystemUsers(w http.ResponseWriter, r *http.Request) {
	antwort := apiSystembenutzer{
		Konten:  []apiSystemkonto{},
		Schalen: []string{},
		Gruppen: []string{},
	}

	konten, err := s.ops.SystemUsers(r.Context())
	if err != nil {
		s.log.Error("systembenutzer lesen", "err", err)
		s.apiFehler(w, http.StatusBadGateway, "Die Kontenliste ist nicht verfügbar: "+err.Error())
		return
	}
	for _, u := range konten {
		antwort.Konten = append(antwort.Konten, systemkontoAus(u))
	}

	// Menschenkonten zuerst, darunter alphabetisch. Ein Server hat dreißig
	// Dienstkonten und zwei, um die es geht — sortierte man nach UID, stünden die
	// zwei irgendwo in der Mitte.
	sort.SliceStable(antwort.Konten, func(i, j int) bool {
		a, b := antwort.Konten[i], antwort.Konten[j]
		if (a.Art == "mensch") != (b.Art == "mensch") {
			return a.Art == "mensch"
		}
		return a.Name < b.Name
	})

	antwort.Zaehler.Gesamt = len(antwort.Konten)
	for _, k := range antwort.Konten {
		if k.Art == "mensch" {
			antwort.Zaehler.Menschen++
		} else {
			antwort.Zaehler.Dienste++
		}
		if k.Locked {
			antwort.Zaehler.Gesperrt++
		}
		if k.OhneSchluessel {
			antwort.Zaehler.OhneSchluessel++
		}
	}

	// Schalen und Gruppen scheitern zu lassen wäre falsch: Dann fehlten die
	// Auswahlfelder, die Liste steht aber da. Sie sind eine Bedienhilfe.
	if schalen, err := s.ops.LoginShells(r.Context()); err == nil && len(schalen) > 0 {
		antwort.Schalen = schalen
	} else if err != nil {
		s.log.Warn("anmeldeschalen lesen", "err", err)
		antwort.Fehler = "Die Liste der Anmeldeschalen ist nicht lesbar; das Auswahlfeld bleibt leer."
	}
	if gruppen, err := s.ops.Groups(r.Context()); err == nil && len(gruppen) > 0 {
		antwort.Gruppen = gruppen
	} else if err != nil {
		s.log.Warn("gruppen lesen", "err", err)
	}

	s.apiJSON(w, http.StatusOK, antwort)
}

// ------------------------------------------------------------- Schlüssel ---

// apiSchluessel ist ein Eintrag aus authorized_keys.
type apiSchluessel struct {
	privops.SSHKey
	// Staerke ist eine Einschätzung in Worten. Sie steht hier und nicht im
	// Browser, weil „2048 Bit RSA" für die meisten keine Aussage ist und „nach
	// heutigem Maß knapp" schon.
	Staerke string `json:"staerke"`
	Schwach bool   `json:"schwach"`
}

// schluesselAus schätzt die Stärke ein.
//
// Die Grenzen sind die gängigen Empfehlungen: ed25519 ist immer in Ordnung, RSA
// ab 3072 Bit, alles darunter ist alt. ecdsa wird nicht bewertet — die Kurve
// steht nicht im Fingerprint, und eine Bewertung auf Verdacht wäre schlechter
// als keine.
func schluesselAus(k privops.SSHKey) apiSchluessel {
	out := apiSchluessel{SSHKey: k}
	switch {
	case strings.Contains(k.Type, "ed25519"):
		out.Staerke = "stark"
	case strings.Contains(k.Type, "rsa") && k.Bits > 0 && k.Bits < 3072:
		out.Staerke = strconv.Itoa(k.Bits) + " Bit — nach heutigem Maß knapp"
		out.Schwach = true
	case strings.Contains(k.Type, "rsa"):
		out.Staerke = strconv.Itoa(k.Bits) + " Bit"
	case strings.Contains(k.Type, "dss"):
		// DSA ist seit OpenSSH 7.0 abgeschaltet. Ein solcher Schlüssel liegt
		// vielleicht in der Datei, aber er trägt nichts mehr.
		out.Staerke = "DSA — von OpenSSH nicht mehr angenommen"
		out.Schwach = true
	}
	return out
}

// apiSchluesselListe ist die Antwort von GET /api/v1/system-users/{name}/keys.
type apiSchluesselListe struct {
	Konto      string          `json:"konto"`
	Schluessel []apiSchluessel `json:"schluessel"`
	// Datei ist der Ort, an dem sie stehen. Er gehört dazu: Wer den Zugang
	// verliert, muss wissen, wo er von Hand nachsehen kann.
	Datei string `json:"datei"`
}

func (s *Server) handleAPISSHKeys(w http.ResponseWriter, r *http.Request) {
	name := r.PathValue("name")

	keys, err := s.ops.AuthorizedKeys(r.Context(), name)
	if err != nil {
		s.apiFehler(w, http.StatusBadGateway, "Die SSH-Schlüssel sind nicht lesbar: "+err.Error())
		return
	}

	antwort := apiSchluesselListe{
		Konto:      name,
		Schluessel: make([]apiSchluessel, 0, len(keys)),
		Datei:      "~/.ssh/authorized_keys",
	}
	for _, k := range keys {
		antwort.Schluessel = append(antwort.Schluessel, schluesselAus(k))
	}
	s.apiJSON(w, http.StatusOK, antwort)
}

// ------------------------------------------------------------- Verändern ---

// apiKontoAnfrage ist der Körper der verändernden Endpunkte.
type apiKontoAnfrage struct {
	Name    string   `json:"name"`
	Notiz   string   `json:"notiz"`
	Schale  string   `json:"schale"`
	Gruppen []string `json:"gruppen"`
	// Schluessel ist der öffentliche Schlüssel — beim Anlegen gleich mit, damit
	// das Konto nicht als unbenutzbares Gerippe entsteht.
	Schluessel string `json:"schluessel"`
	// Fingerprint benennt den zu entfernenden Schlüssel.
	Fingerprint string `json:"fingerprint"`
	// Gesperrt ist der GEWÜNSCHTE Zustand und nicht ein Umschalter. Bei zwei
	// offenen Browserfenstern ist „umschalten" nicht bestimmt.
	Gesperrt bool `json:"gesperrt"`
	// HomeEntfernen gilt nur beim Löschen.
	HomeEntfernen bool `json:"home_entfernen"`

	Bestaetigt bool   `json:"bestaetigt"`
	Getippt    string `json:"getippt"`
}

func (s *Server) apiKontoKoerper(w http.ResponseWriter, r *http.Request) (apiKontoAnfrage, bool) {
	var anfrage apiKontoAnfrage
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return anfrage, false
	}
	anfrage.Name = strings.TrimSpace(anfrage.Name)
	anfrage.Notiz = strings.TrimSpace(anfrage.Notiz)
	anfrage.Schale = strings.TrimSpace(anfrage.Schale)
	anfrage.Schluessel = strings.TrimSpace(anfrage.Schluessel)
	anfrage.Fingerprint = strings.TrimSpace(anfrage.Fingerprint)
	return anfrage, true
}

// apiKontoAntwort ist die Antwort auf eine ausgeführte Handlung.
type apiKontoAntwort struct {
	Meldung string `json:"meldung"`
	// Konto ist der neu gelesene Zustand — leer nach dem Löschen.
	Konto *apiSystemkonto `json:"konto,omitempty"`
	// Hinweis ist eine Anmerkung, die kein Fehler ist: „Das Konto hat noch keinen
	// Schlüssel und kommt damit nicht auf den Server." Getrennt von Meldung,
	// damit die Oberfläche das eine grün und das andere bernsteinfarben zeigen
	// kann.
	Hinweis string `json:"hinweis,omitempty"`
}

// kontoAntwort liest das Konto neu und antwortet.
func (s *Server) kontoAntwort(w http.ResponseWriter, r *http.Request, name, meldung, hinweis string) {
	antwort := apiKontoAntwort{Meldung: meldung, Hinweis: hinweis}
	if konten, err := s.ops.SystemUsers(r.Context()); err == nil {
		for _, u := range konten {
			if u.Name == name {
				k := systemkontoAus(u)
				antwort.Konto = &k
				break
			}
		}
	} else {
		s.log.Warn("konto nach handlung lesen", "name", name, "err", err)
	}
	s.apiJSON(w, http.StatusOK, antwort)
}

// handleAPISystemUserCreate legt ein Konto an — Stufe 1.
//
// Ohne Rückfrage: Ein neues Konto nimmt nichts weg. Es hat kein Passwort und ohne
// Schlüssel keinen Zugang; es zu löschen ist ein Klick. Die Rückfrage gehört
// dorthin, wo etwas verschwindet.
func (s *Server) handleAPISystemUserCreate(w http.ResponseWriter, r *http.Request) {
	anfrage, ok := s.apiKontoKoerper(w, r)
	if !ok {
		return
	}

	spec := privops.SystemUserSpec{
		Name:    anfrage.Name,
		Comment: anfrage.Notiz,
		Shell:   anfrage.Schale,
		Groups:  anfrage.Gruppen,
		// Das Home-Verzeichnis wird immer angelegt. Ohne Home gibt es kein
		// ~/.ssh, das dem Konto gehört — und damit keine Anmeldung per Schlüssel,
		// den einzigen Weg, den diese Konten haben. Dieselbe Festlegung wie in
		// der alten Oberfläche, und aus demselben Grund.
		CreateHome: true,
		SSHKey:     anfrage.Schluessel,
	}

	if err := s.ops.SystemUserCreate(r.Context(), spec); err != nil {
		s.audit(r, "sysuser.create", spec.Name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}
	s.audit(r, "sysuser.create", spec.Name, store.ResultOK, "")

	meldung := "Konto " + spec.Name + " angelegt. Es hat kein Passwort — die Anmeldung läuft über den SSH-Schlüssel."
	hinweis := ""
	if spec.SSHKey == "" {
		// Gesagt und nicht als Erfolg quittiert: Ein Konto ohne Schlüssel kann
		// sich nicht anmelden. Wer das nicht weiß, sucht den Fehler später in
		// sshd.
		hinweis = "Es ist noch kein Schlüssel hinterlegt — bis dahin kommt dieses Konto nicht auf den Server."
	}
	s.kontoAntwort(w, r, spec.Name, meldung, hinweis)
}

// handleAPISystemUserLocked sperrt oder entsperrt — Stufe 2 beim Sperren.
//
// Sperren fragt zurück, Entsperren nicht: Das eine nimmt einen Zugang, das andere
// gibt ihn. Umkehrbar ist beides, aber ein gesperrtes Konto merkt man erst, wenn
// sich jemand nicht anmelden kann.
func (s *Server) handleAPISystemUserLocked(w http.ResponseWriter, r *http.Request) {
	name := r.PathValue("name")
	anfrage, ok := s.apiKontoKoerper(w, r)
	if !ok {
		return
	}

	if anfrage.Gesperrt {
		if !s.apiBestaetigt(w, apiAktionAnfrage{
			Bestaetigt: anfrage.Bestaetigt, Getippt: anfrage.Getippt,
		}, apiBestaetigung{
			Titel: "Konto sperren",
			Frage: "Das Systemkonto " + name + " sperren?",
			Punkte: []string{
				"Eine Anmeldung über SSH ist danach nicht mehr möglich — auch nicht mit einem hinterlegten Schlüssel.",
				"Laufende Sitzungen dieses Kontos bleiben bestehen, bis sie enden.",
				"Die Sperre lässt sich hier jederzeit aufheben.",
			},
			Knopf: "sperren",
		}) {
			return
		}
	}

	if err := s.ops.SystemUserSetLocked(r.Context(), name, anfrage.Gesperrt); err != nil {
		aktion := "sysuser.unlock"
		if anfrage.Gesperrt {
			aktion = "sysuser.lock"
		}
		s.audit(r, aktion, name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}

	aktion, meldung := "sysuser.unlock", "Konto "+name+" ist entsperrt."
	if anfrage.Gesperrt {
		aktion, meldung = "sysuser.lock", "Konto "+name+" ist gesperrt."
	}
	s.audit(r, aktion, name, store.ResultOK, "")
	s.kontoAntwort(w, r, name, meldung, "")
}

// handleAPISystemUserDelete löscht ein Konto — Stufe 3 mit dem Kontonamen.
func (s *Server) handleAPISystemUserDelete(w http.ResponseWriter, r *http.Request) {
	name := r.PathValue("name")
	anfrage, ok := s.apiKontoKoerper(w, r)
	if !ok {
		return
	}

	folgen := []string{
		"Der Zugang über SSH mit diesem Konto ist danach nicht mehr möglich.",
		"Dateien, die dem Konto gehören, bleiben liegen — sie tragen danach nur noch eine Zahl als Eigentümer.",
	}
	if anfrage.HomeEntfernen {
		folgen = append(folgen, "Das Home-Verzeichnis wird mit gelöscht, samt hinterlegter Schlüssel.")
	} else {
		folgen = append(folgen, "Das Home-Verzeichnis bleibt erhalten.")
	}

	if !s.apiBestaetigt(w, apiAktionAnfrage{
		Bestaetigt: anfrage.Bestaetigt, Getippt: anfrage.Getippt,
	}, apiBestaetigung{
		Titel:         "Systemkonto löschen",
		Frage:         "Das Systemkonto " + name + " endgültig löschen?",
		Punkte:        folgen,
		Knopf:         "endgültig löschen",
		Tippen:        name,
		TippenHinweis: "Zum Bestätigen den Kontonamen eingeben: " + name,
	}) {
		return
	}

	if err := s.ops.SystemUserDelete(r.Context(), name, anfrage.HomeEntfernen); err != nil {
		s.audit(r, "sysuser.delete", name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}

	detail := "Home behalten"
	if anfrage.HomeEntfernen {
		detail = "Home entfernt"
	}
	s.audit(r, "sysuser.delete", name, store.ResultOK, detail)

	// Kein Konto in der Antwort: Es gibt es nicht mehr.
	s.apiJSON(w, http.StatusOK, apiKontoAntwort{
		Meldung: "Konto " + name + " gelöscht (" + detail + ").",
	})
}

// handleAPISSHKeyAdd hinterlegt einen Schlüssel — Stufe 1.
func (s *Server) handleAPISSHKeyAdd(w http.ResponseWriter, r *http.Request) {
	name := r.PathValue("name")
	anfrage, ok := s.apiKontoKoerper(w, r)
	if !ok {
		return
	}

	if err := s.ops.AuthorizedKeyAdd(r.Context(), name, anfrage.Schluessel); err != nil {
		s.audit(r, "sshkey.add", name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}
	s.audit(r, "sshkey.add", name, store.ResultOK, "")
	s.kontoAntwort(w, r, name, "Schlüssel hinterlegt.", "")
}

// handleAPISSHKeyRemove entfernt einen Schlüssel — Stufe 2, und beim LETZTEN
// Schlüssel mit einer eigenen, deutlicheren Frage.
//
// Das ist derselbe Gedanke wie beim Panel-Port in der Firewall: Der Fall, in dem
// man sich aussperrt, gehört benannt. Abgelehnt wird er hier nicht — anders als
// beim Panel-Port, denn ein Systemkonto ohne Schlüssel ist ein zulässiger
// Zustand, und wer das Konto stilllegen will, hat einen Grund.
func (s *Server) handleAPISSHKeyRemove(w http.ResponseWriter, r *http.Request) {
	name := r.PathValue("name")
	anfrage, ok := s.apiKontoKoerper(w, r)
	if !ok {
		return
	}

	// Vor der Frage gelesen, damit sie die Zahl kennt. Lesen darf vor der
	// Bestätigung geschehen, verändern nicht.
	keys, leseErr := s.ops.AuthorizedKeys(r.Context(), name)
	if leseErr != nil {
		s.apiFehler(w, http.StatusBadGateway, "Die SSH-Schlüssel sind nicht lesbar: "+leseErr.Error())
		return
	}

	frage := apiBestaetigung{
		Titel: "SSH-Schlüssel entfernen",
		Frage: "Diesen Schlüssel von " + name + " entfernen?",
		Punkte: []string{
			anfrage.Fingerprint,
			"Wer nur diesen Schlüssel hat, kommt danach über SSH nicht mehr auf den Server.",
		},
		Knopf: "entfernen",
	}
	if len(keys) == 1 {
		// Stufe 3 mit dem Kontonamen: Danach hat das Konto keinen Zugang mehr.
		// Das ist keine Änderung an einem Schlüssel, sondern das Stilllegen eines
		// Zugangs, und es soll sich so anfühlen.
		frage.Frage = "Das ist der EINZIGE Schlüssel von " + name + ". Ihn entfernen?"
		frage.Punkte = []string{
			anfrage.Fingerprint,
			"Danach hat dieses Konto keinen Zugang mehr — es gibt kein Passwort, über das man hereinkäme.",
			"Ein neuer Schlüssel lässt sich hier jederzeit hinterlegen; über SSH kommt man dafür aber nicht mehr an das Konto.",
		}
		frage.Knopf = "letzten Schlüssel entfernen"
		frage.Tippen = name
		frage.TippenHinweis = "Zum Bestätigen den Kontonamen eingeben: " + name
	}
	if !s.apiBestaetigt(w, apiAktionAnfrage{
		Bestaetigt: anfrage.Bestaetigt, Getippt: anfrage.Getippt,
	}, frage) {
		return
	}

	if err := s.ops.AuthorizedKeyRemove(r.Context(), name, anfrage.Fingerprint); err != nil {
		s.audit(r, "sshkey.remove", name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}
	s.audit(r, "sshkey.remove", name, store.ResultOK, anfrage.Fingerprint)

	hinweis := ""
	if len(keys) == 1 {
		hinweis = "Das Konto " + name + " hat jetzt keinen Schlüssel und damit keinen Zugang."
	}
	s.kontoAntwort(w, r, name, "Schlüssel entfernt.", hinweis)
}
