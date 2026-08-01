package privops

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

// Der Schreibpfad für Sites (Schritt 5 der Stufe 0.6, docs/18-webserver.md §7.4).
//
// Die Kette steht in docs/16 §7.4 und gilt hier vollständig, anders als beim
// ACME-Drop-in:
//
//	Vorzustand lesen → prüfen → schreiben → `nginx -t` → bei Fehler ZURÜCKNEHMEN
//	→ neu laden → PROBE mit Frist
//
// Die letzte Stufe fehlte dem Drop-in aus einem benannten Grund: Es legt keinen
// Weg zum Panel, und wenn es fehlt, schlägt eine Zertifikatserneuerung fehl,
// aber niemand ist ausgesperrt. Bei einer Site ist das anders. Sie kann den Port
// des Panels an sich ziehen, sie kann einen Namen übernehmen, unter dem das
// Panel selbst erreichbar ist, und sie kann nginx in einen Zustand bringen, in
// dem er zwar startet, aber nicht mehr antwortet. `nginx -t` findet davon
// nichts: Es prüft die Syntax und nicht die Wirkung.
//
// Die Frist selbst hält httpd (probe.go). Hier liegt nur, was sie braucht: eine
// Rücknahme, die den Vorzustand vollständig beschreibt.
//
// # Die Fassungsprüfung
//
// SiteApply nimmt den Hash der Datei entgegen, die der Aufrufer gelesen hat.
// Stimmt er nicht mehr, wird nicht geschrieben. Das ist derselbe Schutz wie im
// Editor des Dateimanagers und aus demselben Grund: Zwei offene Fenster, zwei
// Bearbeitungen, und die zweite überschreibt die erste, ohne dass es jemand
// merkt. Ein leerer Hash heißt „die Datei soll neu sein" — auch das ist eine
// Aussage, und sie ist falsch, wenn die Datei schon da ist.

// siteAusEndung hängt an einer abgeschalteten Site. nginx zieht aus conf.d nur
// *.conf ein, deshalb genügt das Umbenennen — die Datei bleibt lesbar, und die
// Site kommt vollständig zurück, wenn jemand sie wieder einschaltet.
const siteAusEndung = ".aus"

// ErrSiteFassung meldet, dass die Datei sich seit dem Lesen geändert hat.
var ErrSiteFassung = errors.New("die Site wurde zwischenzeitlich geändert")

// ErrSiteAbgelehnt meldet, dass der Prüfer den Entwurf nicht angenommen hat.
var ErrSiteAbgelehnt = errors.New("der Entwurf wurde abgelehnt")

// SiteRuecknahme beschreibt den Zustand vor dem Schreiben, vollständig genug,
// um ihn wiederherzustellen.
//
// Der Inhalt wandert dabei durch den Aufrufer. Das ist Absicht: Die Frist der
// Probe läuft in httpd, und ein Zwischenspeicher in privops wäre ein zweiter
// Ort, an dem ein halber Vorgang liegen bleibt, wenn das Panel neu startet.
type SiteRuecknahme struct {
	Datei string `json:"datei"`
	// Inhalt ist die vorherige Fassung. Leer bei Hatte=false.
	Inhalt string `json:"inhalt"`
	// Hatte sagt, ob es die Datei vorher gab. FALSE heißt: Die Rücknahme
	// löscht sie wieder.
	Hatte bool `json:"hatte"`
}

// SiteErgebnis ist die Antwort von SiteApply.
type SiteErgebnis struct {
	Pruefung   SitePruefung   `json:"pruefung"`
	Datei      string         `json:"datei"`
	Fassung    string         `json:"fassung"`
	Ruecknahme SiteRuecknahme `json:"ruecknahme"`
}

// SiteApply schreibt eine Site und lädt den Webserver neu.
//
// lage kommt vom Aufrufer — bis auf FremdeNamen. Die füllt diese Funktion
// selbst, und zwar unmittelbar vor dem Schreiben: Eine Kollisionsprüfung gegen
// eine Liste, die der Browser vor fünf Minuten geholt hat, ist keine Prüfung.
func (s *System) SiteApply(ctx context.Context, e SiteEntwurf, lage SiteLage, fassung string) (SiteErgebnis, error) {
	var erg SiteErgebnis

	if err := PruefeSiteName(e.Name); err != nil {
		return erg, err
	}
	pfad := sitePfad(e.Name)
	erg.Datei = pfad

	namen, err := s.fremdeServernamen(ctx, pfad)
	if err != nil {
		return erg, err
	}
	lage.FremdeNamen = namen

	erg.Pruefung = PruefeSiteEntwurf(e, lage)
	if !erg.Pruefung.OK() {
		return erg, ErrSiteAbgelehnt
	}

	// Der Vorzustand — auch der abgeschalteten Fassung. Wer eine abgeschaltete
	// Site bearbeitet, soll sie nicht dadurch einschalten.
	vorher, hatte, err := lesbarerVorzustand(pfad)
	if err != nil {
		return erg, err
	}
	ausPfad := pfad + siteAusEndung
	if !hatte {
		if v, h, err := lesbarerVorzustand(ausPfad); err == nil && h {
			vorher, hatte, pfad = v, h, ausPfad
			erg.Datei = pfad
		}
	}
	if hatte && !strings.HasPrefix(vorher, nginxMarker) {
		return erg, fmt.Errorf("%s gehört dem Panel nicht (kein Marker in der ersten Zeile) "+
			"und wird nicht überschrieben", pfad)
	}
	if err := pruefeFassung(vorher, hatte, fassung); err != nil {
		return erg, err
	}
	erg.Ruecknahme = SiteRuecknahme{Datei: pfad, Inhalt: vorher, Hatte: hatte}

	neu := erzeugeSite(e)
	erg.Fassung = Fassungshash(neu)
	if err := nginxAtomarSchreiben(pfad, neu); err != nil {
		return erg, err
	}
	if err := s.pruefeUndLade(ctx, pfad, vorher, hatte); err != nil {
		return erg, err
	}
	return erg, nil
}

// SiteSchalten schaltet eine Site an oder ab.
//
// Umbenannt statt umgeschrieben: nginx zieht aus conf.d nur *.conf ein, also
// genügt die Endung. Die Datei bleibt vollständig lesbar, und beim
// Wiedereinschalten kommt genau das zurück, was vorher da war — eine Site, die
// beim Abschalten umgeschrieben würde, käme als etwas anderes wieder.
func (s *System) SiteSchalten(ctx context.Context, name string, an bool) (SiteRuecknahme, error) {
	var r SiteRuecknahme
	if err := PruefeSiteName(name); err != nil {
		return r, err
	}
	pfad := sitePfad(name)
	ausPfad := pfad + siteAusEndung

	von, nach := pfad, ausPfad
	if an {
		von, nach = ausPfad, pfad
	}
	inhalt, hatte, err := lesbarerVorzustand(von)
	if err != nil {
		return r, err
	}
	if !hatte {
		if _, schon, _ := lesbarerVorzustand(nach); schon {
			// Schon im gewünschten Zustand. Kein Fehler: Zwei Fenster, zweimal
			// geklickt — das Ergebnis ist dasselbe, und eine Fehlermeldung
			// dafür wäre eine Beschwerde über einen richtigen Zustand.
			return SiteRuecknahme{Datei: nach, Hatte: true}, nil
		}
		return r, fmt.Errorf("%s gibt es nicht", von)
	}
	if !strings.HasPrefix(inhalt, nginxMarker) {
		return r, fmt.Errorf("%s gehört dem Panel nicht", von)
	}

	if err := os.Rename(von, nach); err != nil {
		return r, fmt.Errorf("%s: %w", von, err)
	}
	// Die Rücknahme ist das Umbenennen zurück. Sie wird als „Datei nach, Inhalt
	// vorher, Hatte je nach Richtung" ausgedrückt, damit SiteRestore genügt und
	// es keinen zweiten Rückweg gibt.
	r = SiteRuecknahme{Datei: von, Inhalt: inhalt, Hatte: true}
	if err := s.pruefeUndLadeMitRuecknahme(ctx, func() {
		_ = os.Rename(nach, von)
	}); err != nil {
		return r, err
	}
	return r, nil
}

// SiteRemove löscht eine Site.
func (s *System) SiteRemove(ctx context.Context, name string) (SiteRuecknahme, error) {
	var r SiteRuecknahme
	if err := PruefeSiteName(name); err != nil {
		return r, err
	}
	for _, pfad := range []string{sitePfad(name), sitePfad(name) + siteAusEndung} {
		inhalt, hatte, err := lesbarerVorzustand(pfad)
		if err != nil {
			return r, err
		}
		if !hatte {
			continue
		}
		if !strings.HasPrefix(inhalt, nginxMarker) {
			return r, fmt.Errorf("%s gehört dem Panel nicht", pfad)
		}
		if err := os.Remove(pfad); err != nil {
			return r, fmt.Errorf("%s: %w", pfad, err)
		}
		r = SiteRuecknahme{Datei: pfad, Inhalt: inhalt, Hatte: true}
	}
	if r.Datei == "" {
		return r, fmt.Errorf("die Site %q gibt es nicht", name)
	}
	if err := s.pruefeUndLade(ctx, r.Datei, r.Inhalt, true); err != nil {
		return r, err
	}
	return r, nil
}

// SiteRestore stellt den Zustand vor einer Änderung wieder her.
//
// Die Rücknahme der Probe. Danach wird `nginx -t` NICHT mehr gefragt: Was hier
// zurückkommt, war vorher da und lief. Ein Reload folgt trotzdem, sonst hielte
// der laufende nginx weiter die Fassung, die gerade zurückgenommen wurde.
func (s *System) SiteRestore(ctx context.Context, r SiteRuecknahme) error {
	if r.Datei == "" {
		return nil
	}
	if !istEigeneSiteDatei(r.Datei) {
		// Der Pfad kommt aus einem Feld, das durch httpd gereicht wurde. Er
		// wird deshalb geprüft und nicht geglaubt — sonst wäre die Rücknahme
		// ein Schreibzugriff auf einen beliebigen Pfad.
		return fmt.Errorf("%s ist keine Site des Panels", r.Datei)
	}
	if !r.Hatte {
		if err := os.Remove(r.Datei); err != nil && !os.IsNotExist(err) {
			return fmt.Errorf("%s: %w", r.Datei, err)
		}
	} else {
		if err := nginxAtomarSchreiben(r.Datei, r.Inhalt); err != nil {
			return err
		}
		// Beim Abschalten liegt die andere Endung noch da. Sie muss weg, sonst
		// stünde die Site nach der Rücknahme zweimal.
		andere := strings.TrimSuffix(r.Datei, siteAusEndung)
		if andere == r.Datei {
			andere = r.Datei + siteAusEndung
		}
		if inhalt, da, _ := lesbarerVorzustand(andere); da && strings.HasPrefix(inhalt, nginxMarker) {
			_ = os.Remove(andere)
		}
	}
	return s.nginxNeuLaden(ctx)
}

// pruefeUndLade ist die zweite Hälfte jeder Änderung: prüfen, bei Fehler
// zurücknehmen, sonst neu laden.
func (s *System) pruefeUndLade(ctx context.Context, pfad, vorher string, hatte bool) error {
	return s.pruefeUndLadeMitRuecknahme(ctx, func() {
		zuruecknehmen(pfad, vorher, hatte)
	})
}

// pruefeUndLadeMitRuecknahme lässt den Rückweg offen, weil er beim Umbenennen
// ein anderer ist als beim Schreiben.
func (s *System) pruefeUndLadeMitRuecknahme(ctx context.Context, zurueck func()) error {
	// Geprüft wird die GESAMTE Konfiguration und nicht die eine Datei: nginx
	// kennt nur die vollständige, und unsere Datei kann für sich richtig sein
	// und trotzdem mit einer anderen kollidieren.
	pruef, err := s.run(ctx, Command{Name: "nginx", Args: []string{"-t"}})
	if err != nil {
		zurueck()
		return err
	}
	if pruef.ExitCode != 0 {
		// Der Rückweg VOR der Meldung: Eine abgelehnte Datei liegen zu lassen
		// hieße, dass der nächste Reload — von wem auch immer angestoßen — an
		// unserer Datei scheitert. Der Fehler wäre dann unserer und sähe nach
		// einem fremden aus.
		zurueck()
		return fmt.Errorf("nginx hat die Konfiguration abgelehnt, der vorherige Stand "+
			"ist wiederhergestellt: %s", kurzeAusgabe(pruef))
	}
	if err := s.nginxNeuLaden(ctx); err != nil {
		zurueck()
		return err
	}
	return nil
}

// fremdeServernamen sammelt die server_name, die schon vergeben sind.
//
// Ohne die eigene Datei: Eine Site zu ändern heißt, ihre eigenen Namen zu
// behalten, und ein Prüfer, der das nicht auseinanderhält, ließe keine einzige
// Änderung durch.
func (s *System) fremdeServernamen(ctx context.Context, eigenePfad string) (map[string]string, error) {
	bestand, err := s.SiteList(ctx)
	if err != nil {
		return nil, err
	}
	if !bestand.Gelesen {
		// Ohne lesbare Konfiguration ist die Frage „ist dieser Name schon
		// vergeben" nicht zu beantworten. Nicht zu schreiben ist hier die
		// richtige Antwort: „nicht geprüft" ist kein „frei", und ein zweiter
		// Block für denselben Namen ist genau der Fehler, der sich später nicht
		// mehr erklären lässt.
		return nil, fmt.Errorf("die Konfiguration des Webservers ließ sich nicht lesen, "+
			"deshalb schreibt das Panel nichts: %s", bestand.Fehler)
	}
	namen := map[string]string{}
	for _, si := range bestand.Sites {
		if si.Datei == eigenePfad || si.Datei == eigenePfad+siteAusEndung {
			continue
		}
		for _, d := range si.Domains {
			namen[strings.ToLower(d)] = si.Datei
		}
	}
	return namen, nil
}

// pruefeFassung vergleicht den mitgeschickten Hash mit dem, was auf der Platte
// liegt.
func pruefeFassung(vorher string, hatte bool, fassung string) error {
	ist := ""
	if hatte {
		ist = Fassungshash(vorher)
	}
	if fassung == ist {
		return nil
	}
	switch {
	case !hatte:
		return fmt.Errorf("%w: sie war beim Öffnen noch nicht da", ErrSiteFassung)
	case fassung == "":
		return fmt.Errorf("%w: es gibt sie bereits", ErrSiteFassung)
	default:
		return ErrSiteFassung
	}
}

// Fassungshash bildet den Vergleichswert einer Datei.
//
// SHA-256 und nicht die Änderungszeit: Die Zeit hat auf manchen Dateisystemen
// eine Auflösung von einer Sekunde, und zwei Schreibvorgänge in derselben
// Sekunde wären dann nicht zu unterscheiden — ausgerechnet der Fall, den diese
// Prüfung abfangen soll.
func Fassungshash(inhalt string) string {
	summe := sha256.Sum256([]byte(inhalt))
	return hex.EncodeToString(summe[:])
}

// sitePfad baut den Dateipfad zu einer Kennung. Der Name ist an dieser Stelle
// bereits durch PruefeSiteName gegangen.
func sitePfad(name string) string {
	return filepath.Join(filepath.Dir(acmeDropinPfad), sitePraefix+name+".conf")
}

// istEigeneSiteDatei prüft einen Pfad, der von außen kommt.
func istEigeneSiteDatei(pfad string) bool {
	sauber := filepath.Clean(pfad)
	if filepath.Dir(sauber) != filepath.Dir(acmeDropinPfad) {
		return false
	}
	if sauber == acmeDropinPfad {
		return false
	}
	name := filepath.Base(sauber)
	if !strings.HasPrefix(name, sitePraefix) {
		return false
	}
	return strings.HasSuffix(name, ".conf") || strings.HasSuffix(name, ".conf"+siteAusEndung)
}
