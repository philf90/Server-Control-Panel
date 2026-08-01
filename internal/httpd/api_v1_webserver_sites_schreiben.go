package httpd

// Sites schreiben (Schritt 5 der Stufe 0.6, docs/18-webserver.md §6 und §7).
//
// Die gefährlichste Fläche dieses Moduls, und sie ist es aus einem anderen Grund
// als der Installationsknopf. Der konnte einen laufenden Webserver umbringen —
// laut, sofort und sichtbar. Eine Site kann etwas Leiseres: Sie kann den Namen
// übernehmen, unter dem das Panel selbst erreichbar ist, oder nginx in einen
// Zustand bringen, in dem er startet und nicht mehr antwortet. Beides merkt man
// erst, wenn man die Oberfläche braucht, um es zurückzunehmen.
//
// Deshalb drei Linien übereinander, und jede fängt etwas anderes:
//
//  1. **Der Prüfer** (privops, PruefeSiteEntwurf) lehnt ab, was nie richtig sein
//     kann, und verlangt bei einem root außerhalb der üblichen Wurzeln eine
//     Rückfrage der Stufe 3.
//  2. **`nginx -t`** findet, was der Prüfer nicht sehen kann: eine Kollision mit
//     einer anderen Datei, einen Syntaxfehler. Läuft in privops, samt Rückweg.
//  3. **Die Probe** fängt, was beide nicht sehen können: die WIRKUNG. Ohne
//     Bestätigung binnen 60 Sekunden nimmt das Panel die Änderung zurück.
//
// Die Probe hat einen eigenen Wächter neben dem der Firewall (probe.go). Ein
// geteilter hieße, dass eine bestätigte Firewalländerung eine unbestätigte Site
// mitbestätigt.

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/acme"
	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// siteProbeFenster ist die Frist zur Bestätigung einer Site-Änderung.
//
// Dieselben 60 Sekunden wie bei der Firewall, und aus demselben Grund: lang
// genug, um zu merken, dass die Seite noch antwortet, kurz genug, dass niemand
// eine Minute lang glaubt, die Änderung sei schon fest. Eine eigene Konstante
// und kein Verweis auf die der Firewall — beide Bereiche dürfen ihre Frist
// unabhängig ändern.
const siteProbeFenster = 60 * time.Second

// apiSiteAnfrage ist der Körper von PUT /api/v1/webserver/sites/{name}.
type apiSiteAnfrage struct {
	apiAktionAnfrage
	Domains       []string `json:"domains"`
	Zielart       string   `json:"zielart"`
	Ziel          string   `json:"ziel"`
	TLS           bool     `json:"tls"`
	HTTPUmleitung bool     `json:"http_umleitung"`
	// Fassung ist der Hash der Datei, die der Browser gelesen hat. Leer heißt
	// „neu anlegen". Fehlt er bei einer bestehenden Site, wird nicht
	// geschrieben — der Konflikt ist der Punkt, nicht die Bequemlichkeit.
	Fassung string `json:"fassung"`
}

// apiSiteAntwort ist die Antwort auf jede Änderung: Meldung, Prüfergebnis und
// der Zustand der Probe. Der Zustand gehört dazu, sonst müsste die Oberfläche
// eine zweite Anfrage stellen und zeigte in der Lücke die alte Frist.
type apiSiteAntwort struct {
	Meldung string `json:"meldung"`
	Probe   struct {
		Offen      bool   `json:"offen"`
		Sekunden   int    `json:"sekunden"`
		Gegenstand string `json:"gegenstand"`
	} `json:"probe"`
	Pruefung *apiSitePruefung `json:"pruefung,omitempty"`
	Fassung  string           `json:"fassung,omitempty"`
}

// apiSitePruefung ist das Urteil des Prüfers für die Oberfläche.
type apiSitePruefung struct {
	Ablehnungen []privops.SiteBefund `json:"ablehnungen"`
	Warnungen   []privops.SiteBefund `json:"warnungen"`
	Ungeprueft  []string             `json:"ungeprueft"`
}

// handleAPIWebserverSiteSchreiben legt eine Site an oder ändert sie.
func (s *Server) handleAPIWebserverSiteSchreiben(w http.ResponseWriter, r *http.Request) {
	name, ok := s.siteName(w, r)
	if !ok {
		return
	}

	var anfrage apiSiteAnfrage
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}

	entwurf := privops.SiteEntwurf{
		Name:          name,
		Domains:       anfrage.Domains,
		Zielart:       anfrage.Zielart,
		Ziel:          strings.TrimSpace(anfrage.Ziel),
		TLS:           anfrage.TLS,
		HTTPUmleitung: anfrage.HTTPUmleitung,
	}
	// Die Zertifikatspfade kommen NICHT aus der Anfrage. Sie sind kein Feld des
	// Formulars, sondern die Folge davon, ob für diese Site ein Zertifikat
	// bezogen wurde — und sie zeigen in das Datenverzeichnis des Panels. Wer sie
	// setzen dürfte, könnte nginx eine beliebige Datei als Schlüssel unterschieben
	// und deren Inhalt über eine TLS-Fehlermeldung ausmessen.
	if anfrage.TLS {
		entwurf.Zertifikat, entwurf.Schluessel = s.siteZertifikatspfade(name)
	}

	// Vorprüfung OHNE die Namensliste: Sie liefert den Text der Rückfrage. Die
	// verbindliche Prüfung läuft in privops unmittelbar vor dem Schreiben und
	// gegen eine frisch gelesene Lage — eine Rückfrage zu stellen ist kein
	// Schreibvorgang, und deshalb darf sie hier stehen.
	vorab := privops.PruefeSiteEntwurf(entwurf, s.siteLage())
	if !vorab.OK() {
		s.apiJSON(w, http.StatusBadRequest, apiSiteAntwort{
			Meldung:  "Der Entwurf wurde abgelehnt.",
			Pruefung: uebersetzePruefung(vorab),
		})
		return
	}

	if !s.apiBestaetigt(w, anfrage.apiAktionAnfrage, siteFrage(entwurf, vorab)) {
		return
	}

	ergebnis, err := s.ops.SiteApply(r.Context(), entwurf, s.siteLage(), anfrage.Fassung)
	switch {
	case errors.Is(err, privops.ErrSiteAbgelehnt):
		s.audit(r, "webserver.site.apply", name, store.ResultDenied, "vom Prüfer abgelehnt")
		s.apiJSON(w, http.StatusBadRequest, apiSiteAntwort{
			Meldung:  "Der Entwurf wurde abgelehnt.",
			Pruefung: uebersetzePruefung(ergebnis.Pruefung),
		})
		return
	case errors.Is(err, privops.ErrSiteFassung):
		// 409 und nicht 400: Die Anfrage ist in Ordnung, sie geht nur von einem
		// Stand aus, den es nicht mehr gibt. Dieselbe Auslegung wie im Editor
		// des Dateimanagers.
		s.audit(r, "webserver.site.apply", name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusConflict, "Diese Site wurde zwischenzeitlich geändert. "+
			"Bitte neu laden — sonst überschreibt dieser Stand eine fremde Änderung.")
		return
	case err != nil:
		s.audit(r, "webserver.site.apply", name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadGateway, err.Error())
		return
	}

	s.audit(r, "webserver.site.apply", name, store.ResultOK,
		fmt.Sprintf("%s, Bestätigung ausstehend", strings.Join(entwurf.Domains, " ")))
	s.armSiteProbe(name, ergebnis.Ruecknahme)

	antwort := apiSiteAntwort{
		Meldung: "Die Site gilt auf Probe. Ohne Bestätigung innerhalb von 60 Sekunden " +
			"wird der vorherige Stand wiederhergestellt.",
		Fassung:  ergebnis.Fassung,
		Pruefung: uebersetzePruefung(ergebnis.Pruefung),
	}
	s.fuelleProbe(&antwort)
	s.apiJSON(w, http.StatusOK, antwort)
}

// handleAPIWebserverSiteSchalten schaltet eine Site an oder ab.
func (s *Server) handleAPIWebserverSiteSchalten(w http.ResponseWriter, r *http.Request) {
	name, ok := s.siteName(w, r)
	if !ok {
		return
	}

	var anfrage struct {
		apiAktionAnfrage
		An bool `json:"an"`
	}
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}

	// Einschalten ist Stufe 1: Es macht etwas erreichbar, das der Betreiber
	// selbst angelegt hat, und die Probe steht ohnehin. Abschalten ist Stufe 2 —
	// danach antwortet die Domain nicht mehr, und das merkt man an einer Stelle,
	// an der man gerade nicht sitzt.
	if !anfrage.An {
		if !s.apiBestaetigt(w, anfrage.apiAktionAnfrage, apiBestaetigung{
			Titel: "Site abschalten",
			Frage: fmt.Sprintf("Die Site „%s“ abschalten?", name),
			Punkte: []string{
				"Die Domains dieser Site werden danach nicht mehr beantwortet.",
				"Die Konfiguration bleibt liegen und lässt sich wieder einschalten.",
				"Die Änderung gilt auf Probe: Ohne Bestätigung binnen 60 Sekunden " +
					"kommt die Site zurück.",
			},
			Knopf: "abschalten",
		}) {
			return
		}
	}

	ruecknahme, err := s.ops.SiteSchalten(r.Context(), name, anfrage.An)
	if err != nil {
		s.audit(r, "webserver.site.schalten", name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadGateway, err.Error())
		return
	}
	zustand := "abgeschaltet"
	if anfrage.An {
		zustand = "eingeschaltet"
	}
	s.audit(r, "webserver.site.schalten", name, store.ResultOK, zustand+", Bestätigung ausstehend")
	s.armSiteProbe(name, ruecknahme)

	antwort := apiSiteAntwort{
		Meldung: "Die Site ist " + zustand + " und gilt auf Probe. Ohne Bestätigung " +
			"innerhalb von 60 Sekunden wird der vorherige Stand wiederhergestellt.",
	}
	s.fuelleProbe(&antwort)
	s.apiJSON(w, http.StatusOK, antwort)
}

// handleAPIWebserverSiteLoeschen löscht eine Site.
//
// Stufe 3 mit getipptem Namen, und ausdrücklich OHNE Probe: Eine Probe verspricht
// einen Rückweg, der hier keiner wäre. Das Zertifikat der Site bleibt zwar
// liegen, aber die Datei ist weg, und ein Rückweg, der die halbe Sache
// wiederherstellt, ist schlechter als keiner — er lässt jemanden glauben, er
// könne es sich noch überlegen.
func (s *Server) handleAPIWebserverSiteLoeschen(w http.ResponseWriter, r *http.Request) {
	name, ok := s.siteName(w, r)
	if !ok {
		return
	}

	var anfrage apiAktionAnfrage
	if !s.apiJSONKoerper(w, r, &anfrage) {
		return
	}
	if !s.apiBestaetigt(w, anfrage, apiBestaetigung{
		Titel: "Site löschen",
		Frage: fmt.Sprintf("Die Site „%s“ endgültig löschen?", name),
		Punkte: []string{
			"Die Konfigurationsdatei wird entfernt; die Domains werden danach nicht mehr beantwortet.",
			"Ein bezogenes Zertifikat bleibt auf der Platte liegen und wird nicht mehr erneuert.",
			"Diese Aktion läuft ohne Probe — es gibt keinen selbsttätigen Rückweg.",
		},
		Knopf:  "löschen",
		Tippen: name,
	}) {
		return
	}

	if _, err := s.ops.SiteRemove(r.Context(), name); err != nil {
		s.audit(r, "webserver.site.remove", name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadGateway, err.Error())
		return
	}
	s.audit(r, "webserver.site.remove", name, store.ResultOK, "")

	antwort := apiSiteAntwort{Meldung: "Die Site „" + name + "“ ist gelöscht."}
	s.fuelleProbe(&antwort)
	s.apiJSON(w, http.StatusOK, antwort)
}

// handleAPIWebserverSiteBestaetigen beendet die Probe.
//
// Ohne Rückfrage: Bestätigen ist die Zustimmung zu etwas, das gerade schon gilt.
// Wortgleich zur Firewall und aus demselben Grund.
func (s *Server) handleAPIWebserverSiteBestaetigen(w http.ResponseWriter, r *http.Request) {
	if !s.siteGuard.confirm() {
		antwort := apiSiteAntwort{
			Meldung: "Es steht keine Bestätigung aus. Wenn eine Frist lief, ist sie " +
				"abgelaufen und der vorherige Stand wiederhergestellt.",
		}
		s.fuelleProbe(&antwort)
		s.apiJSON(w, http.StatusConflict, antwort)
		return
	}
	s.audit(r, "webserver.site.confirm", "", store.ResultOK, "")

	antwort := apiSiteAntwort{Meldung: "Die Änderung ist bestätigt und bleibt bestehen."}
	s.fuelleProbe(&antwort)
	s.apiJSON(w, http.StatusOK, antwort)
}

// armSiteProbe stellt die Frist scharf.
//
// Der Rückbau läuft im Wächter und nicht hier: Er muss auch dann stattfinden,
// wenn diese Anfrage längst beendet ist — im schlimmsten Fall gerade deshalb,
// weil das Panel nicht mehr erreichbar ist.
func (s *Server) armSiteProbe(name string, ruecknahme privops.SiteRuecknahme) {
	// Der Name in Anführungszeichen: „Site neu gilt auf Probe" liest sich
	// sonst, als wäre „neu" ein Adverb — und Sites heißen oft so.
	s.siteGuard.arm("Site „"+name+"“", func(ctx context.Context) error {
		s.log.Warn("Site-Änderung nicht bestätigt — Rückbau läuft", "site", name)
		err := s.ops.SiteRestore(ctx, ruecknahme)
		ergebnis, detail := store.ResultOK, "zurückgenommen"
		if err != nil {
			ergebnis, detail = store.ResultError, err.Error()
		}
		s.auditNachtraeglich("system", "webserver.site.revert", name, ergebnis, detail)
		return err
	})
}

// fuelleProbe legt den Zustand der Frist an die Antwort.
func (s *Server) fuelleProbe(a *apiSiteAntwort) {
	offen, rest := s.siteGuard.state()
	a.Probe.Offen = offen
	a.Probe.Sekunden = int(rest.Seconds())
	a.Probe.Gegenstand = s.siteGuard.subjectOf()
}

// siteName liest die Kennung aus dem Pfad und prüft sie, bevor irgendetwas
// damit geschieht.
//
// Am Anfang jedes Schreibhandlers und nicht erst in privops. Zwei Gründe: Die
// Antwort wird ein 400 statt eines 502 aus der Tiefe — und dieser Name wird hier
// zu einem PFAD (siteZertifikatspfade). Ein Name aus der Adresszeile, der
// ungeprüft in filepath.Join geht, ist der klassische Weg aus einem Verzeichnis
// heraus, auch wenn die aufgerufene Funktion ihn ihrerseits säubert.
func (s *Server) siteName(w http.ResponseWriter, r *http.Request) (string, bool) {
	name := strings.TrimSpace(r.PathValue("name"))
	if err := privops.PruefeSiteName(name); err != nil {
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return "", false
	}
	return name, true
}

// siteZertifikatspfade nennt die Dateien für das Zertifikat einer Site — und
// LEERE Pfade, solange es keins gibt.
//
// Der leere Fall ist der wichtige. Ein ssl_certificate, das auf eine Datei
// zeigt, die es nicht gibt, lässt nginx gar nicht erst starten — und dann ist
// nicht diese eine Site weg, sondern jede. Die Site entsteht deshalb zunächst
// ohne 443; der Block kommt dazu, wenn das Zertifikat da ist (Schritt 7).
func (s *Server) siteZertifikatspfade(name string) (zertifikat, schluessel string) {
	zert, schluessel := acme.Zertifikatspfade(filepath.Join(s.cfg.Paths.Data, "acme"), name)
	for _, pfad := range []string{zert, schluessel} {
		// Der Name ist durch siteName gegangen — eine Allowlist aus a–z, 0–9, -
		// und _. Aus ihr lässt sich kein Pfadbestandteil bilden, und
		// acme.Zertifikatspfade säubert zusätzlich über filepath.Base. Die
		// Verfolgung von gosec sieht beides nicht.
		if _, err := os.Stat(pfad); err != nil { //nolint:gosec // Name über Allowlist geprüft, siehe siteName
			return "", ""
		}
	}
	return zert, schluessel
}

// siteLage sammelt, was der Prüfer über diesen Server wissen muss.
//
// FremdeNamen bleibt hier leer: Die füllt privops unmittelbar vor dem Schreiben
// selbst. Eine Kollisionsprüfung gegen eine Liste, die dieser Handler eine
// Sekunde vorher geholt hat, wäre eine Prüfung gegen einen Stand, den es beim
// Schreiben nicht mehr geben muss.
func (s *Server) siteLage() privops.SiteLage {
	return privops.SiteLage{
		PanelPort: s.cfg.Server.Port,
		// Das Datenverzeichnis des Panels dazu: Dort liegen die Datenbank, die
		// Schlüssel und die Zugangsdaten der DNS-Anbieter. Eine Site, die von
		// dort ausliefert, veröffentlicht das Panel selbst.
		GesperrtePfade: []string{s.cfg.Paths.Data},
	}
}

// siteFrage baut die Rückfrage zu einem Entwurf.
//
// Stufe 2 im Regelfall, Stufe 3 mit getipptem Domainnamen, wenn der Prüfer
// gewarnt hat — ein root außerhalb der üblichen Wurzeln ist der Weg, über den
// eine Site fremde Daten ausliefert. Dieselbe Stufung wie beim Bind-Mount nach
// draußen im Compose-Prüfer.
func siteFrage(e privops.SiteEntwurf, p privops.SitePruefung) apiBestaetigung {
	erste := ""
	if len(e.Domains) > 0 {
		erste = strings.ToLower(strings.TrimSpace(e.Domains[0]))
	}

	punkte := []string{
		"Domains: " + strings.Join(e.Domains, ", "),
		"Ziel: " + e.Ziel,
		"Die Änderung gilt auf Probe: Ohne Bestätigung binnen 60 Sekunden wird " +
			"der vorherige Stand wiederhergestellt.",
	}
	b := apiBestaetigung{
		Titel:  "Site speichern",
		Frage:  fmt.Sprintf("Die Site „%s“ so speichern?", e.Name),
		Punkte: punkte,
		Knopf:  "speichern",
	}
	if len(p.Warnungen) == 0 {
		return b
	}

	// Die Warnung steht VOR den übrigen Punkten: Sie ist der Grund, warum hier
	// getippt werden muss, und eine Begründung unter drei anderen Zeilen liest
	// niemand.
	b.Punkte = append([]string{p.Warnungen[0].Grund}, punkte...)
	b.Tippen = erste
	b.TippenHinweis = "Zum Bestätigen den Domainnamen eingeben: " + erste
	return b
}

// uebersetzePruefung macht aus dem Urteil des Prüfers die Antwort.
//
// Nil bei einem leeren Urteil: Ein Feld „pruefung": {} in jeder Antwort wäre
// Rauschen, und die Oberfläche prüft ohnehin auf Vorhandensein.
func uebersetzePruefung(p privops.SitePruefung) *apiSitePruefung {
	if len(p.Ablehnungen) == 0 && len(p.Warnungen) == 0 && len(p.Ungeprueft) == 0 {
		return nil
	}
	return &apiSitePruefung{
		Ablehnungen: p.Ablehnungen,
		Warnungen:   p.Warnungen,
		Ungeprueft:  p.Ungeprueft,
	}
}
