package httpd

// Update-Prüfung für Container-Images — Schritt 7 aus docs/17-docker.md.
//
// Vier Festlegungen bestimmen diese Datei, und drei davon handeln davon, was
// NICHT geschieht:
//
//  1. **Die Prüfung ist eine Auskunft, kein Vorgang** (Entscheidung E5). Sie
//     tauscht nichts aus. Sie sagt, dass es etwas Neues gibt, und der Mensch
//     drückt den Knopf. Ein Panel, das nachts von allein Images tauscht, ist
//     ein Panel, das nachts von allein etwas kaputt macht.
//  2. **Höchstens ein Lauf je Tag.** Docker Hub zählt anonyme Abfragen, und ein
//     Server mit dreißig Images verbraucht bei jedem Lauf dreißig davon. Die
//     Grenze liegt im Store und nicht im Speicher: Sonst setzte ein Neustart des
//     Panels sie zurück, und ein Dienst, der oft neu startet, fragte dauernd.
//  3. **Eine Ratengrenze wird angezeigt und nicht wiederholt.** Wer bei
//     „toomanyrequests" gleich noch einmal fragt, ist der Grund, warum es
//     Ratengrenzen gibt.
//  4. **Das Signal im Handlungsbedarf kommt aus dem Zwischenspeicher.** In der
//     Drei-Sekunden-Frist von dashboardSignals wird NIE eine Registry gefragt.

import (
	"context"
	"encoding/json"
	"net/http"
	"sort"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

const (
	// jobDockerUpdatePruefung ist die Vorgangsart des Prüflaufs.
	//
	// Ein Vorgang und keine Anfrage: Dreißig Registry-Abfragen dauern länger als
	// jede vernünftige Anfragefrist, und jede einzelne kann hängen.
	jobDockerUpdatePruefung = "docker-updates"

	// settingDockerUpdates hält das Ergebnis des letzten Laufs als JSON.
	settingDockerUpdates = "docker_updates"

	// updatePause ist der Mindestabstand zwischen zwei Läufen.
	//
	// Ein Tag, und die Zahl steht hier statt in der Konfiguration: Eine
	// Einstellung, mit der man sich selbst aus der Registry aussperren kann,
	// wäre eine schlechte Einstellung. Wer öfter prüfen will, hat eine Frage an
	// seine Registry und nicht an dieses Panel.
	updatePause = 24 * time.Hour
)

// gespeicherterUpdatestand ist, was zwischen zwei Läufen im Store liegt.
type gespeicherterUpdatestand struct {
	Geprueft time.Time             `json:"geprueft"`
	Staende  []privops.Updatestand `json:"staende"`
	Fehler   string                `json:"fehler,omitempty"`
}

// apiUpdatezeile ist ein Image in der Antwort.
type apiUpdatezeile struct {
	Ref string `json:"ref"`
	// Geprueft und Neu stehen getrennt, und das ist der Kern der ganzen Fläche:
	// „nicht geprüft" ist nicht „aktuell" und nicht „veraltet".
	Geprueft bool   `json:"geprueft"`
	Neu      bool   `json:"neu"`
	Grund    string `json:"grund,omitempty"`
	Weg      string `json:"weg,omitempty"`
	// Kurz ist die Kennung auf zwölf Stellen — die volle ist 71 Zeichen lang und
	// in einer Tabelle unlesbar.
	LokalKurz string `json:"lokal_kurz,omitempty"`
	FernKurz  string `json:"fern_kurz,omitempty"`
	// Stacks nennt die Compose-Projekte, die dieses Image benutzen. Sie sind
	// der Griff: Aktualisiert wird ein Stack, nicht ein Image.
	Stacks []string `json:"stacks"`
	// Container nennt die Container ohne Stack — für sie gibt es hier keinen
	// Handgriff, und das gehört gesagt statt einen Knopf anzubieten, der nichts
	// tut.
	Container []string `json:"container"`
}

// apiUpdates ist die Antwort von GET /api/v1/docker/updates.
type apiUpdates struct {
	Zeilen []apiUpdatezeile `json:"zeilen"`
	// Geprueft ist der Zeitpunkt des letzten Laufs, leer wenn noch keiner lief.
	Geprueft string `json:"geprueft"`
	// NaechsteFruehestens sagt, ab wann wieder geprüft werden darf. Die
	// Oberfläche zeigt den Knopf trotzdem — nur gesperrt und mit dem Grund
	// daneben, statt ihn zu verstecken.
	NaechsteFruehestens string `json:"naechste_fruehestens,omitempty"`
	DarfPruefen         bool   `json:"darf_pruefen"`
	// Zaehler: wie viele neu, wie viele aktuell, wie viele nicht geprüft. Die
	// dritte Zahl ist die ehrlichste der drei.
	Neu         int     `json:"neu"`
	Aktuell     int     `json:"aktuell"`
	Ungeprueft  int     `json:"ungeprueft"`
	DarfAendern bool    `json:"darf_aendern"`
	Job         *apiJob `json:"job"`
	Fehler      string  `json:"fehler,omitempty"`
}

// handleAPIDockerUpdates liefert den zwischengespeicherten Stand.
//
// Diese Route fragt NIE eine Registry. Sie liest, was der letzte Lauf
// hinterlassen hat — sonst wäre jeder Aufruf der Seite eine Abfrage gegen die
// Ratengrenze, und ein offener Tab verbrauchte sie im Hintergrund.
func (s *Server) handleAPIDockerUpdates(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	antwort := apiUpdates{
		Zeilen:      []apiUpdatezeile{},
		DarfAendern: user.CanManageUsers(),
		Job:         s.jobAus(jobDockerUpdatePruefung),
	}

	stand := s.updatestandLesen(r.Context())
	if !stand.Geprueft.IsZero() {
		antwort.Geprueft = stand.Geprueft.Local().Format("02.01.2006 15:04")
		if naechste := stand.Geprueft.Add(updatePause); naechste.After(time.Now()) {
			antwort.NaechsteFruehestens = naechste.Local().Format("02.01.2006 15:04")
		}
	}
	antwort.DarfPruefen = antwort.NaechsteFruehestens == ""
	antwort.Fehler = stand.Fehler

	// Wer welches Image benutzt, kommt aus der Containerliste und nicht aus dem
	// Zwischenspeicher: Container kommen und gehen, der Stand des Images nicht.
	stacks, container := map[string][]string{}, map[string][]string{}
	if cs, err := s.ops.DockerContainers(r.Context()); err == nil {
		for _, c := range cs {
			if c.Stack != "" {
				stacks[c.Image] = einmalig(append(stacks[c.Image], c.Stack))
				continue
			}
			container[c.Image] = einmalig(append(container[c.Image], c.Name))
		}
	}

	for _, st := range stand.Staende {
		z := apiUpdatezeile{
			Ref: st.Ref, Geprueft: st.Geprueft, Neu: st.Neu, Grund: st.Grund, Weg: st.Weg,
			LokalKurz: kurzeKennung(strings.TrimPrefix(st.LokalDigest, "sha256:")),
			FernKurz:  kurzeKennung(strings.TrimPrefix(st.FernDigest, "sha256:")),
			Stacks:    stacks[st.Ref],
			Container: container[st.Ref],
		}
		if z.Stacks == nil {
			z.Stacks = []string{}
		}
		if z.Container == nil {
			z.Container = []string{}
		}
		antwort.Zeilen = append(antwort.Zeilen, z)

		switch {
		case !st.Geprueft:
			antwort.Ungeprueft++
		case st.Neu:
			antwort.Neu++
		default:
			antwort.Aktuell++
		}
	}

	// Neues zuerst, dann Ungeprüftes, dann der Rest. Wer die Fläche öffnet,
	// sucht das, was es zu tun gibt.
	sort.SliceStable(antwort.Zeilen, func(i, j int) bool {
		a, b := antwort.Zeilen[i], antwort.Zeilen[j]
		if a.Neu != b.Neu {
			return a.Neu
		}
		if a.Geprueft != b.Geprueft {
			return !a.Geprueft
		}
		return a.Ref < b.Ref
	})

	s.apiJSON(w, http.StatusOK, antwort)
}

// handleAPIDockerUpdatePruefung startet einen Prüflauf.
func (s *Server) handleAPIDockerUpdatePruefung(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	// Die Ratengrenze zuerst, und ausdrücklich VOR dem Vorgang: Ein Vorgang, der
	// startet und sofort mit „zu früh" endet, sieht aus wie ein Fehlschlag.
	stand := s.updatestandLesen(r.Context())
	if naechste := stand.Geprueft.Add(updatePause); !stand.Geprueft.IsZero() && naechste.After(time.Now()) {
		s.apiFehler(w, http.StatusTooManyRequests,
			"Die Prüfung lief zuletzt am "+stand.Geprueft.Local().Format("02.01.2006 15:04")+
				". Sie läuft höchstens einmal am Tag, weil Docker Hub anonyme Abfragen zählt — "+
				"die nächste ist ab "+naechste.Local().Format("02.01.2006 15:04")+" möglich.")
		return
	}

	// Welche Images überhaupt in Frage kommen: die der laufenden Container.
	// Nicht alle lokalen — ein Image, das niemand benutzt, ist keine Frage
	// wert, und jede Abfrage kostet an der Ratengrenze.
	refs, err := s.gefragteImages(r.Context())
	if err != nil {
		s.apiFehler(w, http.StatusBadGateway, err.Error())
		return
	}
	if len(refs) == 0 {
		s.apiFehler(w, http.StatusBadRequest,
			"Es läuft kein Container, dessen Image sich prüfen ließe.")
		return
	}

	j, neu := s.jobs.start(jobDockerUpdatePruefung, user.Username)
	if !neu {
		s.apiFehler(w, http.StatusConflict, "Es läuft bereits eine Update-Prüfung.")
		return
	}
	s.audit(r, "docker.updates.check", "", store.ResultOK, "gestartet")

	go func() { //nolint:gosec // eigener Kontext ist hier Absicht
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Minute)
		defer cancel()

		ergebnis := gespeicherterUpdatestand{Geprueft: time.Now().UTC()}
		for _, ref := range refs {
			j.append("prüfe " + ref)
			st, err := s.ops.DockerUpdatePruefen(ctx, ref)
			if err != nil {
				st = privops.Updatestand{Ref: ref, Grund: err.Error()}
			}
			ergebnis.Staende = append(ergebnis.Staende, st)

			switch {
			case !st.Geprueft:
				j.append("  nicht geprüft: " + st.Grund)
			case st.Neu:
				j.append("  neu verfügbar")
			default:
				j.append("  aktuell")
			}

			// Eine Ratengrenze beendet den Lauf. Weiterzufragen, nachdem die
			// Registry abgewiesen hat, ist genau das Verhalten, gegen das die
			// Grenze gerichtet ist — und es macht die Sperre nur länger.
			if strings.Contains(st.Grund, "Ratengrenze") {
				ergebnis.Fehler = st.Grund
				j.append("Abbruch: " + st.Grund)
				break
			}
		}

		// Der Zeitpunkt wird auch bei einem Abbruch gespeichert. Sonst dürfte
		// gleich wieder geprüft werden — und die Ratengrenze wäre wirkungslos,
		// gerade wenn sie zugeschlagen hat.
		fehler := s.updatestandSchreiben(ctx, ergebnis)
		j.setNote(updatenotiz(ergebnis))
		j.finish(fehler)

		ergebnisText, art := "abgeschlossen: "+updatenotiz(ergebnis), store.ResultOK
		if fehler != nil {
			ergebnisText, art = fehler.Error(), store.ResultError
		}
		s.auditNachtraeglich(user.Username, "docker.updates.check", "", art, ergebnisText)
	}()

	s.gestartet(w, jobDockerUpdatePruefung, "Die Images werden mit den Registries verglichen.")
}

// gefragteImages sammelt die Images der laufenden Container.
//
// Eindeutig und sortiert: Zehn Container mit demselben Image sind eine
// Abfrage, nicht zehn. Das ist kein Feinschliff — es ist der Unterschied
// zwischen innerhalb und außerhalb der Ratengrenze.
func (s *Server) gefragteImages(ctx context.Context) ([]string, error) {
	cs, err := s.ops.DockerContainers(ctx)
	if err != nil {
		return nil, err
	}
	gesehen := map[string]bool{}
	var refs []string
	for _, c := range cs {
		if c.Zustand != "running" || c.Image == "" || gesehen[c.Image] {
			continue
		}
		// Ein Image, das über seine Kennung angezogen wurde, kann sich nicht
		// ändern: Die Kennung IST der Inhalt. Es zu prüfen wäre eine Abfrage
		// gegen die Ratengrenze mit garantiert derselben Antwort.
		if strings.Contains(c.Image, "@sha256:") {
			continue
		}
		if privops.ValidateImageRef(c.Image) != nil {
			continue
		}
		gesehen[c.Image] = true
		refs = append(refs, c.Image)
	}
	sort.Strings(refs)
	return refs, nil
}

// updatestandLesen holt den Zwischenspeicher.
//
// Ein fehlender oder unlesbarer Eintrag ist ein leerer Stand und kein Fehler:
// Vor dem ersten Lauf gibt es keinen, und ein Format, das sich einmal ändert,
// soll die Fläche nicht sperren.
func (s *Server) updatestandLesen(ctx context.Context) gespeicherterUpdatestand {
	roh, err := s.db.Setting(ctx, settingDockerUpdates)
	if err != nil {
		return gespeicherterUpdatestand{}
	}
	var stand gespeicherterUpdatestand
	if json.Unmarshal([]byte(roh), &stand) != nil {
		return gespeicherterUpdatestand{}
	}
	return stand
}

// updatestandSchreiben legt den Zwischenspeicher ab.
func (s *Server) updatestandSchreiben(ctx context.Context, stand gespeicherterUpdatestand) error {
	roh, err := json.Marshal(stand)
	if err != nil {
		return err
	}
	return s.db.SetSetting(ctx, settingDockerUpdates, string(roh))
}

// updatenotiz fasst den Lauf in einen Satz für die Vorgangsplatte.
func updatenotiz(stand gespeicherterUpdatestand) string {
	var neu, ungeprueft int
	for _, st := range stand.Staende {
		switch {
		case !st.Geprueft:
			ungeprueft++
		case st.Neu:
			neu++
		}
	}
	satz := zahlwort(neu, "Image", "Images") + " mit einer neueren Version"
	if ungeprueft > 0 {
		satz += ", " + zahlwort(ungeprueft, "Image", "Images") + " nicht geprüft"
	}
	return satz
}

// zahlwort setzt Zahl und Wort zusammen. Eine Zahl im Text braucht beide
// Formen — „1 Images" fällt im Betrieb auf.
func zahlwort(n int, einzahl, mehrzahl string) string {
	wort := mehrzahl
	if n == 1 {
		wort = einzahl
	}
	return itoaKlein(n) + " " + wort
}

// itoaKlein ist strconv.Itoa ohne den Import — die Datei braucht ihn sonst
// nirgends.
func itoaKlein(n int) string {
	if n == 0 {
		return "0"
	}
	var ziffern []byte
	for n > 0 {
		ziffern = append([]byte{byte('0' + n%10)}, ziffern...)
		n /= 10
	}
	return string(ziffern)
}

// einmalig entfernt Doppelungen und sortiert.
func einmalig(werte []string) []string {
	gesehen := map[string]bool{}
	out := werte[:0]
	for _, w := range werte {
		if gesehen[w] {
			continue
		}
		gesehen[w] = true
		out = append(out, w)
	}
	sort.Strings(out)
	return out
}
