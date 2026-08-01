package httpd

// Zertifikat und ACME über /api/v1.
//
// Drei Endpunkte statt der vier der alten Oberfläche, und das ist eine
// Vereinfachung und keine Auslassung: Der Bezug eines Zertifikats IST ein
// Vorgang im Sinne des Job-Modells (docs/16-neukonzeption.md 4) — er läuft
// Minuten, schreibt Zeilen und endet mit Erfolg oder Fehler. Er bekommt deshalb
// keinen eigenen Ereignisstrom, sondern läuft über
// `/api/v1/jobs/certificate/events` wie der Paketvorgang und das Einspielen von
// ufw. (Die alte Oberfläche hatte für dieselbe Sache einen eigenen Strom unter
// /certificate/events. Er ist mit ihr gegangen — es gibt jetzt einen Weg für
// Vorgangszeilen und nicht je Modul einen.)
//
// Vier Dinge halten diese Fläche eng:
//
//  1. **Die Einstellungen werden vor dem Speichern geprüft — mit derselben
//     Prüfung, die der Dienst beim Start fährt.** Ohne sie ließe sich eine
//     Konfiguration ablegen, mit der der Daemon nicht mehr hochkommt, und dann
//     ist die Seite weg, auf der man es zurücknimmt.
//  2. **Das Cloudflare-Token wird nie zurückgegeben.** Es geht in eine eigene
//     Datei mit 0600, nicht in die Konfiguration — die ist für die Gruppe des
//     Dienstes lesbar. Die Antwort sagt nur, DASS eines hinterlegt ist.
//  3. **Ein leeres Tokenfeld löscht kein hinterlegtes Token.** Wer die Seite
//     öffnet und speichert, ohne das Feld anzufassen, soll keinen
//     funktionierenden Zugang verlieren.
//  4. **Der Rückschritt auf ein selbstsigniertes Zertifikat fragt zurück.** Das
//     ist der einzige Unterschied im Verhalten gegenüber der alten Fläche, und
//     zwar mit Grund: Danach warnt jeder Browser, der das Panel aufruft. Das ist
//     für jeden Beteiligten sichtbar, und damit ein Fall für Stufe 2 nach
//     docs/14-bestaetigungen.md.

import (
	"math"
	"net/http"
	"net/mail"
	"os"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/acme"
	"github.com/philf90/asylum/internal/certs"
	"github.com/philf90/asylum/internal/config"
	"github.com/philf90/asylum/internal/store"
)

// ------------------------------------------------------------------ Lesen ---

// apiZertifikat ist die Antwort von GET /api/v1/certificate.
type apiZertifikat struct {
	// Modus ist "selfsigned" oder "acme" — die EINSTELLUNG, nicht die Herkunft
	// des gerade ausgelieferten Zertifikats. Beides fällt auseinander, solange
	// noch kein Bezug geglückt ist, und deshalb stehen beide da.
	Modus  string `json:"modus"`
	Quelle string `json:"quelle"`
	// Zustand fasst zusammen, ob gehandelt werden muss: gut (beglaubigt, Laufzeit
	// reicht), warn (selbstsigniert oder läuft bald ab), schlecht (abgelaufen oder
	// nicht lesbar).
	Zustand     string `json:"zustand"`
	ZustandText string `json:"zustand_text"`

	Datei string `json:"datei"`
	// Inhaber, Aussteller, Namen, Fingerprint: was im Zertifikat steht.
	Inhaber     string   `json:"inhaber"`
	Aussteller  string   `json:"aussteller"`
	Namen       []string `json:"namen"`
	Fingerprint string   `json:"fingerprint"`
	GueltigAb   string   `json:"gueltig_ab"`
	GueltigBis  string   `json:"gueltig_bis"`
	// TageUebrig kann negativ sein. Als Zahl und nicht als Text, weil die
	// Oberfläche daran einfärbt; der Satz daneben steht in ZustandText.
	TageUebrig     int  `json:"tage_uebrig"`
	Selbstsigniert bool `json:"selbstsigniert"`
	// Lesefehler steht, wenn die Datei nicht lesbar war. Die Fläche bleibt
	// erreichbar — die Einstellungen sind gerade dann interessant.
	Lesefehler string `json:"lesefehler"`

	// ---- Einstellungen ----
	Email string `json:"email"`
	// Namenstext ist die Eingabefassung: ein Name je Zeile. Leer heißt „der
	// vollqualifizierte Rechnername".
	Namenstext string `json:"namenstext"`
	// GeltendeNamen sind die Namen, die tatsächlich verwendet würden — aufgelöst,
	// damit niemand raten muss, was „leer" bedeutet.
	GeltendeNamen  []string `json:"geltende_namen"`
	Pruefmethode   string   `json:"pruefmethode"`
	Anbieter       string   `json:"anbieter"`
	HookSetzen     string   `json:"hook_setzen"`
	HookAufraeumen string   `json:"hook_aufraeumen"`
	// TokenHinterlegt: Das Token selbst kommt nie zurück, sein Vorhandensein
	// schon. Ohne diese Auskunft müsste man es bei jedem Speichern neu eingeben
	// oder raten, ob noch eines liegt.
	TokenHinterlegt bool `json:"token_hinterlegt"`
	Testverzeichnis bool `json:"testverzeichnis"`
	// VerwalteteDatei ist die Datei, in der die Einstellungen landen. Sie wird
	// genannt, weil das Panel nichts versteckt (Grundsatz „Hide nothing").
	VerwalteteDatei string `json:"verwaltete_datei"`

	// ---- Letzter Bezug ----
	BezugLaeuft bool   `json:"bezug_laeuft"`
	BezugZeit   string `json:"bezug_zeit"`
	BezugFehler string `json:"bezug_fehler"`
	// Job ist der laufende oder letzte Bezugsvorgang — in dieser Antwort, damit
	// die Seite mit einem Aufruf vollständig ist.
	Job *apiJob `json:"job"`

	// Pruefmethoden und Anbieter sind die wählbaren Werte mit ihrer Erklärung.
	// Vom Server, weil hier die Bedingungen bekannt sind: HTTP-01 braucht Port 80,
	// DNS-01 braucht einen Anbieter.
	Pruefmethoden []apiWahl `json:"pruefmethoden"`
	Anbieters     []apiWahl `json:"anbieter_liste"`
}

// apiWahl ist ein wählbarer Wert mit seiner Erklärung.
type apiWahl struct {
	Wert string `json:"wert"`
	Name string `json:"name"`
	Was  string `json:"was"`
}

func (s *Server) handleAPIZertifikat(w http.ResponseWriter, r *http.Request) {
	s.apiJSON(w, http.StatusOK, s.zertifikatAntwort(r))
}

// zertifikatPfad nennt das Zertifikat, das gerade AUSGELIEFERT wird, und woher
// es kommt.
//
// Eigene Funktion, seit auch der Handlungsbedarf danach fragt
// (dashboardSignals): Zwei Fassungen von „welches Zertifikat gilt gerade" liefen
// auseinander, und die Antwort ist nicht trivial — bei eingestelltem ACME, aber
// noch ohne bezogenes Zertifikat ist es weiter das selbstsignierte, und genau
// dieser Zwischenzustand ist der, den jemand erklärt haben möchte.
func (s *Server) zertifikatPfad() (pfad, quelle string) {
	set := s.tlsSettings()
	pfad = s.cfg.Server.TLS.Cert
	quelle = "selbstsigniert"
	if set.Mode == config.TLSModeACME {
		acmePfad := acme.CertPath(s.cfg.Paths.Data)
		if _, err := os.Stat(acmePfad); err == nil {
			return acmePfad, "ACME (Let's Encrypt)"
		}
		quelle = "selbstsigniert (Rückfall — noch kein Zertifikat bezogen)"
	}
	return pfad, quelle
}

func (s *Server) zertifikatAntwort(r *http.Request) apiZertifikat {
	set := s.tlsSettings()
	pfad, quelle := s.zertifikatPfad()

	versuch := s.tls.attempt()
	antwort := apiZertifikat{
		Modus:           set.Mode,
		Quelle:          quelle,
		Datei:           pfad,
		Email:           set.ACME.Email,
		Namenstext:      strings.Join(set.ACME.Domains, "\n"),
		GeltendeNamen:   acmeDomains(set),
		Pruefmethode:    set.ACME.Challenge,
		Anbieter:        set.ACME.DNS01.Provider,
		HookSetzen:      set.ACME.DNS01.Hook.Set,
		HookAufraeumen:  set.ACME.DNS01.Hook.Clean,
		TokenHinterlegt: fileExists(set.ACME.DNS01.ZugangsDatei()),
		Testverzeichnis: set.ACME.DirectoryURL == stagingDirectory,
		VerwalteteDatei: config.ManagedTLSPath(s.cfgPath),
		BezugLaeuft:     versuch.Running,
		BezugFehler:     versuch.Err,
		Pruefmethoden:   pruefmethoden(),
		Anbieters:       anbieterliste(),
	}
	if !versuch.At.IsZero() {
		antwort.BezugZeit = versuch.At.Format("02.01.2006 15:04")
	}
	antwort.Job = s.jobAus(jobCertificate)

	if info, err := certs.Describe(pfad); err != nil {
		antwort.Lesefehler = err.Error()
		antwort.Zustand, antwort.ZustandText = "schlecht", "nicht lesbar"
	} else {
		tage := zertifikatTage(info.NotAfter)
		antwort.Inhaber = info.Subject
		antwort.Aussteller = info.Issuer
		antwort.Namen = info.DNSNames
		antwort.Fingerprint = info.Fingerprint
		antwort.GueltigAb = info.NotBefore.Format("02.01.2006")
		antwort.GueltigBis = info.NotAfter.Format("02.01.2006")
		antwort.TageUebrig = tage
		antwort.Selbstsigniert = info.SelfSigned
		antwort.Zustand, antwort.ZustandText = zertifikatZustand(info.SelfSigned, tage)
	}
	if antwort.Namen == nil {
		antwort.Namen = []string{}
	}
	if antwort.GeltendeNamen == nil {
		antwort.GeltendeNamen = []string{}
	}
	return antwort
}

// zertifikatTage ist die Restlaufzeit in ganzen Tagen, abgerundet — auch ins
// Negative.
//
// Der Grund für die eigene Funktion ist ein Fehler, den die Warnpunkte ans Licht
// gebracht haben: Hier stand `int(time.Until(…).Hours() / 24)`, und int()
// schneidet zur Null hin ab. Ein Zertifikat, das vor zwei Stunden abgelaufen
// war, ergab damit 0 statt -1 — und die Seite meldete „läuft bald ab" statt
// „abgelaufen". Das galt für das ganze erste Tag nach dem Ablauf, also genau in
// dem Zeitraum, in dem die Auskunft am meisten zählt.
//
// math.Floor rundet in beide Richtungen ab; für positive Werte ändert sich
// nichts, denn dort tut int() dasselbe.
func zertifikatTage(notAfter time.Time) int {
	return int(math.Floor(time.Until(notAfter).Hours() / 24))
}

// zertifikatZustand fasst Beglaubigung und Restlaufzeit in einem Wort zusammen.
//
// Die Grenzen: Ein abgelaufenes Zertifikat ist schlecht, unter 14 Tagen eine
// Warnung (Let's Encrypt erneuert ab 30 Tagen vor Ablauf — wer bei 14 noch nicht
// erneuert hat, hat ein Problem mit der Erneuerung und nicht mit dem Datum).
// Selbstsigniert ist immer eine Warnung und niemals „gut": Es funktioniert, aber
// jeder Browser widerspricht.
func zertifikatZustand(selbstsigniert bool, tage int) (string, string) {
	switch {
	case tage < 0:
		return "schlecht", "abgelaufen"
	case tage < 14:
		return "warn", "läuft bald ab"
	case selbstsigniert:
		return "warn", "selbstsigniert"
	default:
		return "gut", "beglaubigt"
	}
}

// pruefmethoden erklärt die Wahl. „automatisch" steht zuerst, weil es in den
// meisten Fällen richtig ist.
func pruefmethoden() []apiWahl {
	return []apiWahl{
		{Wert: "", Name: "automatisch",
			Was: "DNS-01, wenn ein Anbieter eingerichtet ist, sonst HTTP-01 über Port 80"},
		{Wert: "http-01", Name: "HTTP-01",
			Was: "Let's Encrypt ruft den Server auf Port 80 auf. Der Port muss von außen erreichbar sein."},
		{Wert: "dns-01", Name: "DNS-01",
			Was: "Ein TXT-Eintrag im DNS beweist den Besitz. Nötig für Wildcards und ohne offenen Port 80."},
	}
}

// anbieterliste ist die Auswahl für die Oberfläche.
//
// Die eingebauten Anbieter kommen aus dem Register des acme-Pakets und stehen
// nicht ein zweites Mal hier. Das ist der Zweck des Registers: Ein neuer
// Anbieter trägt sich dort ein und erscheint damit ohne weiteres Zutun im
// Auswahlfeld — vorher wäre er an zwei Stellen einzutragen gewesen, und eine
// davon hätte irgendwann gefehlt.
func anbieterliste() []apiWahl {
	aus := []apiWahl{
		{Wert: "", Name: "keiner", Was: "nur mit HTTP-01 möglich"},
		{Wert: config.DNS01ProviderHook, Name: "Hook",
			Was: "Zwei eigene Programme setzen und entfernen den TXT-Eintrag. Absolute Pfade, ausführbar."},
	}
	for _, a := range acme.Anbieterliste() {
		aus = append(aus, apiWahl{Wert: a.Name, Name: a.Titel, Was: a.Hinweis})
	}
	return aus
}

// ------------------------------------------------------------- Verändern ---

// apiZertifikatAuftrag ist der Körper von POST /api/v1/certificate.
type apiZertifikatAuftrag struct {
	Modus string `json:"modus"`
	Email string `json:"email"`
	// Namenstext ist ein Name je Zeile (oder mit Komma getrennt). Leer heißt: der
	// vollqualifizierte Rechnername.
	Namenstext     string `json:"namenstext"`
	Pruefmethode   string `json:"pruefmethode"`
	Anbieter       string `json:"anbieter"`
	HookSetzen     string `json:"hook_setzen"`
	HookAufraeumen string `json:"hook_aufraeumen"`
	// Token ist das Cloudflare-API-Token. Leer heißt: das hinterlegte behalten —
	// ein leeres Feld darf keinen funktionierenden Zugang löschen. Es wird nie
	// protokolliert und nie zurückgegeben.
	Token           string `json:"token"`
	Testverzeichnis bool   `json:"testverzeichnis"`

	Bestaetigt bool   `json:"bestaetigt"`
	Getippt    string `json:"getippt"`
}

// apiZertifikatAntwort ist die Antwort auf eine ausgeführte Handlung.
type apiZertifikatAntwort struct {
	Meldung    string         `json:"meldung"`
	Zertifikat *apiZertifikat `json:"zertifikat,omitempty"`
	Hinweis    string         `json:"hinweis,omitempty"`
}

func (s *Server) zertifikatFertig(w http.ResponseWriter, r *http.Request, antwort apiZertifikatAntwort) {
	z := s.zertifikatAntwort(r)
	antwort.Zertifikat = &z
	s.apiJSON(w, http.StatusOK, antwort)
}

// handleAPIZertifikatSpeichern übernimmt die Einstellungen.
func (s *Server) handleAPIZertifikatSpeichern(w http.ResponseWriter, r *http.Request) {
	var auftrag apiZertifikatAuftrag
	if !s.apiJSONKoerper(w, r, &auftrag) {
		return
	}
	alt := s.tlsSettings()

	set, err := s.zertifikatEinstellungen(auftrag, alt)
	if err != nil {
		s.audit(r, "tls.settings", "", store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}

	// Der Rückschritt auf ein selbstsigniertes Zertifikat fragt zurück: Danach
	// widerspricht jeder Browser, der das Panel aufruft. Das ist für alle
	// Beteiligten sichtbar — Stufe 2. Der Weg hin zu ACME fragt nicht: Er
	// verbessert etwas, und bis der Bezug glückt, bleibt alles wie es war.
	if alt.Mode == config.TLSModeACME && set.Mode != config.TLSModeACME {
		if !s.apiBestaetigt(w, apiAktionAnfrage{
			Bestaetigt: auftrag.Bestaetigt, Getippt: auftrag.Getippt,
		}, apiBestaetigung{
			Titel: "Zurück auf ein selbstsigniertes Zertifikat",
			Frage: "Den automatischen Bezug abschalten und wieder selbstsigniert ausliefern?",
			Punkte: []string{
				"Jeder Browser warnt danach beim Aufruf des Panels.",
				"Ein bereits bezogenes Zertifikat bleibt auf der Platte liegen und wird nur nicht mehr benutzt.",
				"Die Erneuerung im Hintergrund hört auf.",
			},
			Knopf: "abschalten",
		}) {
			return
		}
	}

	// Dieselbe Prüfung wie beim Start des Dienstes. Ohne sie ließe sich eine
	// Konfiguration speichern, mit der der Daemon beim nächsten Start nicht mehr
	// hochkommt — und dann ist die Fläche weg, auf der man es zurücknimmt.
	probe := s.cfg
	probe.Server.TLS.Mode = set.Mode
	probe.ACME = set.ACME
	if err := probe.Validate(); err != nil {
		s.audit(r, "tls.settings", "", store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}

	if err := s.applyTLSSettings(set); err != nil {
		s.audit(r, "tls.settings", "", store.ResultError, err.Error())
		s.apiFehler(w, http.StatusInternalServerError,
			"Die Einstellungen ließen sich nicht speichern: "+err.Error())
		return
	}

	// Im Protokoll steht, WAS eingestellt wurde — nie das Token.
	s.audit(r, "tls.settings", set.Mode, store.ResultOK,
		"domains="+strings.Join(acmeDomains(set), ",")+
			" challenge="+set.ACME.Challenge+
			" provider="+set.ACME.DNS01.Provider+
			" staging="+boolText(set.ACME.DirectoryURL == stagingDirectory))

	hinweis := ""
	if set.Mode == config.TLSModeACME {
		hinweis = "Der Bezug läuft im Hintergrund; bis dahin bleibt das bisherige " +
			"Zertifikat aktiv. Die Einstellungen stehen in " + config.ManagedTLSPath(s.cfgPath) + "."
	}
	s.zertifikatFertig(w, r, apiZertifikatAntwort{
		Meldung: "Die Einstellungen sind gespeichert.",
		Hinweis: hinweis,
	})
}

func boolText(b bool) string {
	if b {
		return "ja"
	}
	return "nein"
}

// zertifikatEinstellungen prüft den Auftrag und baut die Einstellungen.
//
// Geprüft wird hier und nicht erst in der Konfigurationsprüfung, weil die
// Meldungen dort auf YAML-Feldnamen zeigen ("acme.dns01.hook") — in einer
// Oberfläche, die keine YAML-Datei zeigt, hilft das niemandem. Dieselbe
// Überlegung wie in parseTLSForm der alten Fläche; die Prüffunktionen selbst
// (pruefeHook, parseDomains, cloudflareToken) sind geteilt und nicht
// nachgebaut.
func (s *Server) zertifikatEinstellungen(auftrag apiZertifikatAuftrag, alt config.TLSSettings) (config.TLSSettings, error) {
	set := config.TLSSettings{Mode: config.TLSModeSelfSigned}
	if auftrag.Modus != config.TLSModeACME {
		return set, nil
	}
	set.Mode = config.TLSModeACME

	namen, err := parseDomains(auftrag.Namenstext)
	if err != nil {
		return set, err
	}
	set.ACME.Domains = namen

	email := strings.TrimSpace(auftrag.Email)
	if email == "" {
		return set, errText("für Let's Encrypt wird eine Kontaktadresse gebraucht — " +
			"dorthin geht die Warnung, wenn eine Erneuerung ausbleibt")
	}
	if err := pruefeEmail(email); err != nil {
		return set, err
	}
	set.ACME.Email = email

	if auftrag.Testverzeichnis {
		set.ACME.DirectoryURL = stagingDirectory
	}

	switch c := auftrag.Pruefmethode; c {
	case "", "http-01", "dns-01":
		set.ACME.Challenge = c
	default:
		return set, errText("unbekannte Prüfmethode " + c)
	}
	// http01.open_firewall bleibt, wie es in der Konfiguration steht: Die
	// Einstellung ist vorgesehen, aber noch ohne Wirkung (docs/10-tls-acme.md).
	// Ein Schalter, der nichts tut, ist schlimmer als keiner — er sieht wie eine
	// Zusage aus.
	set.ACME.HTTP01 = alt.ACME.HTTP01

	switch auftrag.Anbieter {
	case "":
		if set.ACME.Challenge == "dns-01" {
			return set, errText("die Prüfung über DNS braucht einen Anbieter — " +
				"wählen Sie Hook oder Cloudflare, oder stellen Sie die Prüfmethode zurück")
		}
	case config.DNS01ProviderHook:
		setzen, err := pruefeHook("Setzen", strings.TrimSpace(auftrag.HookSetzen))
		if err != nil {
			return set, err
		}
		aufraeumen, err := pruefeHook("Aufräumen", strings.TrimSpace(auftrag.HookAufraeumen))
		if err != nil {
			return set, err
		}
		set.ACME.DNS01.Hook.Set = setzen
		set.ACME.DNS01.Hook.Clean = aufraeumen
	default:
		// Jeder registrierte Anbieter, ohne eigenen Zweig. Was seine
		// Zugangsdatei enthalten muss, weiß der Anbieter selbst — geprüft wird
		// es beim Bau des Setzers, mit einer Meldung, die alle fehlenden Felder
		// auf einmal nennt.
		if !acme.AnbieterBekannt(auftrag.Anbieter) {
			return set, errText("unbekannter DNS-Anbieter " + auftrag.Anbieter)
		}
		datei, err := s.zugangAblegen(auftrag.Anbieter, strings.TrimSpace(auftrag.Token), alt)
		if err != nil {
			return set, err
		}
		set.ACME.DNS01.CredentialsFile = datei
	}
	set.ACME.DNS01.Provider = auftrag.Anbieter

	return set, nil
}

// handleAPIZertifikatBezug stößt einen sofortigen Bezug an.
//
// Keine Rückfrage: Ein Bezug nimmt nichts weg. Er kann scheitern, und dann steht
// im Verlauf, warum — das bisherige Zertifikat bleibt in jedem Fall aktiv.
func (s *Server) handleAPIZertifikatBezug(w http.ResponseWriter, r *http.Request) {
	// Der Körper ist leer, wird aber gelesen: apiJSONKoerper weist unbekannte
	// Felder ab, und ein Aufruf, der Felder mitschickt, die hier nichts bewirken,
	// soll das erfahren.
	var leer struct{}
	if !s.apiJSONKoerper(w, r, &leer) {
		return
	}
	user, _ := userFrom(r.Context())

	if err := s.obtainNow(user.Username); err != nil {
		s.audit(r, "tls.obtain", "", store.ResultError, err.Error())
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}
	namen := acmeDomains(s.tlsSettings())
	s.audit(r, "tls.obtain", strings.Join(namen, ","), store.ResultOK, "gestartet")

	s.zertifikatFertig(w, r, apiZertifikatAntwort{
		Meldung: "Der Bezug läuft.",
		Hinweis: "Der Verlauf kommt fortlaufend herein. Über DNS kann das einige Minuten " +
			"dauern; das bisherige Zertifikat bleibt bis zum Erfolg aktiv.",
	})
}

// pruefeEmail prüft die Kontaktadresse. Eigene Funktion mit eigener Meldung: Die
// Fehler von net/mail nennen englische Begriffe aus dem RFC, und die stehen dann
// in einer deutschen Oberfläche.
func pruefeEmail(adresse string) error {
	if _, err := mail.ParseAddress(adresse); err != nil {
		return errText(adresse + " ist keine E-Mail-Adresse")
	}
	return nil
}

// errText ist ein Fehler aus einem Satz. Kein fmt.Errorf, weil hier nichts
// eingesetzt wird, was formatiert werden müsste.
type errText string

func (e errText) Error() string { return string(e) }
