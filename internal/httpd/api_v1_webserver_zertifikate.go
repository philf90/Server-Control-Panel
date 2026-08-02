package httpd

// Zertifikate je Site über /api/v1 (Schritt 7 der Stufe 0.6).
//
// Die Fläche beantwortet drei Fragen, und die dritte ist die, wegen der es sie
// gibt: Hat diese Site ein Zertifikat? Wie lange gilt es noch? Und wenn keines
// da ist — WARUM nicht?
//
// Die dritte Frage ist der Grund, warum hier ein eigener Stand geführt wird und
// nicht nur das Zertifikat aus dem Halter gelesen. „Kein Zertifikat" ohne den
// Grund ist die Auskunft, mit der niemand etwas anfangen kann: Es kann daran
// liegen, dass die Site erst angelegt wurde, dass die DNS-Zone nicht antwortet,
// dass der Anbieter Zugangsdaten ablehnt oder dass für das Panel selbst gar
// kein ACME läuft. Vier Handgriffe, vier verschiedene Stellen.

import (
	"context"
	"crypto/x509"
	"net/http"
	"time"

	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/store"
)

// apiSiteZert ist eine Zeile der Zertifikatsliste.
type apiSiteZert struct {
	Site    string   `json:"site"`
	Domains []string `json:"domains"`
	// Vorhanden sagt, ob ein Zertifikat im Halter liegt.
	Vorhanden bool `json:"vorhanden"`
	// Aussteller, Namen und Ablauf kommen aus dem Zertifikat selbst.
	Aussteller string    `json:"aussteller"`
	Namen      []string  `json:"namen"`
	Ablauf     time.Time `json:"ablauf,omitempty"`
	// Resttage ist die Restlaufzeit in Tagen, abgerundet. Negative Werte gibt es
	// — ein abgelaufenes Zertifikat ist ein Zustand und kein Rechenfehler.
	Resttage int `json:"resttage"`
	// Stufe und Satz sind das fertige Urteil. Beides kommt vom Server, damit es
	// eine Auslegung gibt und nicht zwei.
	Stufe string `json:"stufe"`
	Satz  string `json:"satz"`
	// LetzterVersuch und Fehler sind der Stand des Bezugs.
	LetzterVersuch time.Time `json:"letzter_versuch,omitempty"`
	Fehler         string    `json:"fehler,omitempty"`
	Laeuft         bool      `json:"laeuft"`
	// Bezugsbereit heißt: Für diese Site läuft ein Manager, der Knopf richtet
	// also etwas aus.
	Bezugsbereit bool `json:"bezugsbereit"`
}

// apiSiteZerts ist die Antwort von GET /api/v1/webserver/zertifikate.
type apiSiteZerts struct {
	Zertifikate []apiSiteZert `json:"zertifikate"`
	// ACMEAktiv sagt, ob das Panel überhaupt ein ACME-Konto hat. FALSE heißt:
	// Für Sites gibt es keinen Bezug, und die Fläche sagt, wo das umzustellen
	// ist — statt einen Knopf anzubieten, der zuverlässig scheitert.
	ACMEAktiv   bool   `json:"acme_aktiv"`
	Anmerkung   string `json:"anmerkung"`
	DarfAendern bool   `json:"darf_aendern"`
	Fehler      string `json:"fehler,omitempty"`
}

// handleAPIWebserverZertifikate liefert den Zertifikatsstand je Site.
func (s *Server) handleAPIWebserverZertifikate(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())
	set := s.tlsSettings()
	antwort := apiSiteZerts{
		Zertifikate: []apiSiteZert{},
		ACMEAktiv:   set.Mode == config.TLSModeACME,
		DarfAendern: user.CanManageUsers(),
	}

	bestand, err := s.ops.SiteList(r.Context())
	if err != nil {
		antwort.Fehler = err.Error()
		s.apiJSON(w, http.StatusOK, antwort)
		return
	}
	if !bestand.Gelesen {
		antwort.Fehler = bestand.Fehler
	}

	for _, si := range verwalteteSitesMitTLS(bestand.Sites) {
		z := apiSiteZert{
			Site:    si.Name,
			Domains: si.Domains,
		}
		if namen := s.siteZertNamen(si.Name); len(namen) > 0 {
			z.Domains = namen
			z.Bezugsbereit = true
		}
		stand := s.siteZerts.stand(si.Name)
		z.LetzterVersuch, z.Fehler, z.Laeuft = stand.Versuch, stand.Fehler, stand.Laeuft

		// Der Halter entsteht erst in Run. Ohne ihn gibt es kein Zertifikat zu
		// zeigen — der Rest der Zeile steht trotzdem, samt Grund.
		var blatt *x509.Certificate
		if s.certHolder != nil {
			blatt = s.certHolder.SiteZertifikat(si.Name)
		}
		if blatt != nil {
			z.Vorhanden = true
			z.Aussteller = blatt.Issuer.CommonName
			z.Namen = blatt.DNSNames
			z.Ablauf = blatt.NotAfter
			z.Resttage = int(time.Until(blatt.NotAfter).Hours() / 24)
		}
		z.Stufe, z.Satz = zertUrteil(z, antwort.ACMEAktiv)
		antwort.Zertifikate = append(antwort.Zertifikate, z)
	}

	antwort.Anmerkung = zertAnmerkung(antwort)
	s.apiJSON(w, http.StatusOK, antwort)
}

// zertUrteil formuliert Stufe und Satz je Site.
//
// Die Reihenfolge ist die der Dringlichkeit und nicht die der Felder: Was zuerst
// im Weg steht, wird zuerst gesagt.
func zertUrteil(z apiSiteZert, acmeAktiv bool) (stufe, satz string) {
	switch {
	case !acmeAktiv:
		return "info", "Für das Panel selbst ist die automatische Ausstellung abgeschaltet. " +
			"Ohne ihn gibt es kein ACME-Konto, aus dem eine Site ihr Zertifikat " +
			"beziehen könnte — umstellen lässt sich das unter Zertifikate."
	case z.Laeuft:
		return "info", "Die Anforderung läuft."
	case !z.Vorhanden && z.Fehler != "":
		// Der Fehler zuerst und im Klartext: Er ist die einzige Auskunft, mit
		// der sich der Grund finden lässt.
		return "schlecht", "Kein Zertifikat. Der letzte Versuch scheiterte: " + z.Fehler
	case !z.Vorhanden:
		return "warn", "Noch kein Zertifikat. Die erste Anforderung läuft im Hintergrund; " +
			"er lässt sich auch von Hand anstoßen."
	// tageWort steht in handlers_app.go und wird hier mitbenutzt: Dieselbe
	// Zahl soll auf jeder Fläche gleich klingen. Negative Werte erreichen es
	// nicht — der abgelaufene Fall steht darüber und nennt keine Tage.
	case z.Resttage < 0:
		return "schlecht", "Das Zertifikat ist abgelaufen. Jeder Browser weist die " +
			"Site zurück."
	case z.Resttage < 15:
		// Fünfzehn Tage: Die Erneuerung läuft bei dreißig an. Wer darunter
		// liegt, hat bereits zwei Wochen erfolgloser Versuche hinter sich.
		return "schlecht", "Das Zertifikat läuft in " + tageWort(z.Resttage) +
			" ab, und die selbsttätige Erneuerung hat es bis jetzt nicht ersetzt."
	case z.Resttage < 30:
		return "warn", "Das Zertifikat läuft in " + tageWort(z.Resttage) +
			" ab. Die Erneuerung läuft in diesem Zeitraum von selbst."
	default:
		return "gut", "Gültig noch " + tageWort(z.Resttage) + "."
	}
}

// zertAnmerkung formuliert die Lage über der Liste.
func zertAnmerkung(a apiSiteZerts) string {
	if !a.ACMEAktiv {
		return "Ein Zertifikat je Site setzt voraus, dass das Panel selbst über " +
			"Let's Encrypt bezieht: Alle Sites teilen sich dessen Konto. Unter " +
			"Zertifikate lässt sich das einschalten."
	}
	if len(a.Zertifikate) == 0 {
		return "Keine Site verlangt bisher TLS. Der Schalter dafür steht im " +
			"Formular einer Site."
	}
	// Der Satz zur Prüfmethode. Er steht nur, wenn er etwas erklärt — nämlich
	// dann, wenn eine Site nichts bekommen hat.
	for _, z := range a.Zertifikate {
		if !z.Vorhanden {
			return "Eine Anforderung braucht einen Weg, auf dem die Prüfstelle die Domain " +
				"erreicht: entweder über nginx (dann muss die Domain auf diesen " +
				"Server zeigen und Port 80 offen sein) oder über DNS-01 mit einem " +
				"eingerichteten Anbieter."
		}
	}
	return ""
}

// handleAPIWebserverZertifikatBeziehen stößt den Bezug für eine Site an.
//
// Stufe 1 und ohne Rückfrage: Ein Bezug ändert nichts an der Konfiguration. Er
// legt ein Zertifikat ab, und im schlechtesten Fall scheitert er — dieselbe
// Stufe wie „Zertifikat erneuern" beim Panel (docs/18 §7).
//
// Was er NICHT tut: die Site neu schreiben. Steht sie noch ohne 443-Block da,
// weil beim Anlegen kein Zertifikat vorlag, muss sie einmal gespeichert werden.
// Das sagt die Antwort, statt es stillschweigend zu tun: Ein Bezug, der
// nebenbei die Konfiguration des Webservers ändert, wäre eine Aktion mit zwei
// Wirkungen und einer Beschriftung.
func (s *Server) handleAPIWebserverZertifikatBeziehen(w http.ResponseWriter, r *http.Request) {
	name, ok := s.siteName(w, r)
	if !ok {
		return
	}

	if err := s.siteZertJetzt(r.Context(), name); err != nil {
		s.audit(r, "webserver.site.zertifikat", name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadGateway, err.Error())
		return
	}
	s.audit(r, "webserver.site.zertifikat", name, store.ResultOK, "bezogen")

	meldung := "Das Zertifikat für " + name + " ist ausgestellt."
	if s.siteOhneTLSBlock(r.Context(), name) {
		meldung += " Die Site liefert es noch nicht aus: Sie wurde ohne 443-Block " +
			"angelegt, weil es damals kein Zertifikat gab. Einmal speichern genügt."
	}
	s.apiJSON(w, http.StatusOK, map[string]string{"meldung": meldung})
}

// siteOhneTLSBlock sagt, ob die Site zwar TLS will, aber (noch) nicht auf 443
// lauscht.
//
// Der Fall entsteht beim Anlegen: Ohne bezogenes Zertifikat schreibt das Panel
// keinen 443-Block, weil ein ssl_certificate ins Leere nginx gar nicht erst
// starten ließe. Nach dem ersten Bezug ist die Lage anders — und der Unterschied
// gehört benannt, sonst sucht jemand den Fehler bei der Domain.
func (s *Server) siteOhneTLSBlock(ctx context.Context, name string) bool {
	bestand, err := s.ops.SiteList(ctx)
	if err != nil || !bestand.Gelesen {
		return false
	}
	for _, si := range bestand.Sites {
		if si.Name != name || !si.Verwaltet {
			continue
		}
		for _, p := range si.Ports {
			if p == 443 {
				return false
			}
		}
		return true
	}
	return false
}
