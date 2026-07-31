package httpd

// API-Tokens verwalten: /api/v1/tokens.
//
// Drei Endpunkte — auflisten, anlegen, widerrufen. Ändern gibt es nicht, und das
// ist Absicht: Ein Token ist sein Geheimnis. Wer seine Rechte ändern will,
// widerruft ihn und legt einen neuen an; ein Token, dessen Umfang sich still
// erweitern lässt, ist ein Token, dessen Umfang niemand kennt.
//
// **Diese Fläche ist selbst für Tokens gesperrt** (tokenGesperrt in
// tokenauth.go): Ein entwendeter Token darf keinen frischen minten, sonst
// überlebt er seinen eigenen Widerruf. Sie gehört der Owner-Rolle — wer Tokens
// vergeben kann, vergibt Zugänge.
//
// Zwei Dinge sind hier eigen und gehören begründet:
//
//  1. **Der Klartext steht genau einmal in einer Antwort und nirgends sonst.**
//     Nicht im Protokoll, nicht in einem Log, nicht in einer zweiten Abfrage. Die
//     Oberfläche zeigt ihn in einem Dialog, der geschlossen werden muss — dasselbe
//     Muster wie beim Einmalpasswort in api_v1_panelzugaenge.go, und aus demselben
//     Grund: Ein Band, das beim nächsten Klick verschwindet, fällt niemandem auf.
//  2. **Ein Token kann nie mehr als das Konto, dem er gehört.** Er hängt am
//     eigenen Konto des Anlegenden und nicht an einem wählbaren: Sonst wäre
//     „Token für das Owner-Konto" der Weg, mit dem sich ein Admin die Owner-Rolle
//     verschafft. Wer einen Token mit anderen Rechten will, meldet sich mit dem
//     Konto an, das sie hat.

import (
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/store"
)

// ------------------------------------------------------------------ Lesen ---

// apiTokens ist die Antwort von GET /api/v1/tokens.
type apiTokens struct {
	Tokens []apiTokenZeile `json:"tokens"`
	// Familien sind die Flächen, für die ein Token gelten kann — mit ihrem Namen
	// in Worten. Vom Server, weil die Liste dort steht und eine zweite in der
	// Oberfläche auseinanderlaufen würde.
	Familien []apiTokenFamilie `json:"familien"`
	// Gesperrt sind die Flächen, die kein Token erreicht. Sie stehen in der
	// Antwort, damit die Oberfläche sie NENNEN kann statt sie zu verschweigen:
	// Wer einen Token für die Kontoverwaltung sucht, soll erfahren, dass es den
	// nicht gibt und warum.
	Gesperrt []string `json:"gesperrt"`
	// Fristen sind die wählbaren Laufzeiten in Tagen. 0 heißt „ohne Ablauf".
	Fristen []apiTokenFrist `json:"fristen"`
	// Praefix ist der Anfang, den jeder Token trägt. Genannt, damit man einen
	// versehentlich veröffentlichten wiedererkennt.
	Praefix string `json:"praefix"`
}

type apiTokenFamilie struct {
	Wert string `json:"wert"`
	Was  string `json:"was"`
}

type apiTokenFrist struct {
	Tage int    `json:"tage"`
	Name string `json:"name"`
}

// apiTokenZeile ist ein Token in der Liste — ohne den Token selbst.
type apiTokenZeile struct {
	ID     int64  `json:"id"`
	Name   string `json:"name"`
	Prefix string `json:"prefix"`
	// Konto ist der Anmeldename des Inhabers, Rolle seine Rolle. Beide, weil die
	// Rolle die Obergrenze des Tokens ist und man sie sonst nachschlagen müsste.
	Konto string `json:"konto"`
	Rolle string `json:"rolle"`
	// Ich markiert die Tokens des eigenen Kontos. Ein fremder Token ist der, den
	// man übersieht.
	Ich      bool     `json:"ich"`
	Scopes   []string `json:"scopes"`
	NurLesen bool     `json:"nur_lesen"`
	Angelegt string   `json:"angelegt"`
	// Frist ist leer für „ohne Ablauf" — ein eigener Zustand, kein Datum in
	// ferner Zukunft.
	Frist         string `json:"frist"`
	Abgelaufen    bool   `json:"abgelaufen"`
	TageBisAblauf int    `json:"tage_bis_ablauf"`
	ZuletztAm     string `json:"zuletzt_am"`
	ZuletztVon    string `json:"zuletzt_von"`
	// NieBenutzt unterscheidet „noch nie" von „lange nicht". Beim Aufräumen ist
	// das der Unterschied zwischen einem Token, der nie ankam, und einem, der
	// nicht mehr gebraucht wird.
	NieBenutzt bool `json:"nie_benutzt"`
	// Zustand und ZustandText fassen zusammen: gut, warn, schlecht.
	Zustand     string `json:"zustand"`
	ZustandText string `json:"zustand_text"`
}

func (s *Server) handleAPITokens(w http.ResponseWriter, r *http.Request) {
	antwort, ok := s.tokenAntwort(w, r)
	if !ok {
		return
	}
	s.apiJSON(w, http.StatusOK, antwort)
}

func (s *Server) tokenAntwort(w http.ResponseWriter, r *http.Request) (apiTokens, bool) {
	ich, _ := userFrom(r.Context())

	tokens, err := s.db.ListAPITokens(r.Context())
	if err != nil {
		s.apiFehler(w, http.StatusInternalServerError, "Die Tokens ließen sich nicht lesen.")
		return apiTokens{}, false
	}
	konten, err := s.db.ListUsers(r.Context())
	if err != nil {
		s.apiFehler(w, http.StatusInternalServerError, "Die Konten ließen sich nicht lesen.")
		return apiTokens{}, false
	}
	// Kennung auf Konto: Die Liste zeigt den Anmeldenamen und nicht eine Zahl.
	namen := map[int64]store.User{}
	for _, k := range konten {
		namen[k.ID] = k
	}

	jetzt := time.Now()
	antwort := apiTokens{
		Tokens:   make([]apiTokenZeile, 0, len(tokens)),
		Familien: tokenFamilienListe(),
		Gesperrt: tokenGesperrteListe(),
		Fristen:  tokenFristen(),
		Praefix:  tokenPraefix,
	}
	for _, t := range tokens {
		antwort.Tokens = append(antwort.Tokens, tokenZeile(t, namen[t.UserID], ich, jetzt))
	}
	return antwort, true
}

func tokenZeile(t store.APIToken, inhaber, ich store.User, jetzt time.Time) apiTokenZeile {
	z := apiTokenZeile{
		ID: t.ID, Name: t.Name, Prefix: t.Prefix,
		Konto: inhaber.Username, Rolle: inhaber.Role,
		Ich:      t.UserID == ich.ID,
		Scopes:   t.Scopes,
		NurLesen: t.ReadOnly,
		Angelegt: t.CreatedAt.Format("02.01.2006"),
	}
	if z.Scopes == nil {
		z.Scopes = []string{}
	}
	if z.Konto == "" {
		// Sollte nicht vorkommen (ON DELETE CASCADE), aber ein leeres Feld in der
		// Oberfläche wäre die schlechtere Antwort als ein benanntes Rätsel.
		z.Konto = "unbekannt"
	}

	if t.LastUsedAt != nil {
		z.ZuletztAm = t.LastUsedAt.Format("02.01.2006 15:04")
		z.ZuletztVon = t.LastUsedIP
	} else {
		z.NieBenutzt = true
	}

	if t.ExpiresAt != nil {
		z.Frist = t.ExpiresAt.Format("02.01.2006")
		z.Abgelaufen = t.Abgelaufen(jetzt)
		z.TageBisAblauf = int(t.ExpiresAt.Sub(jetzt).Hours() / 24)
	}
	z.Zustand, z.ZustandText = tokenZustand(t, jetzt)
	return z
}

// tokenZustand fasst den Zustand eines Tokens zusammen.
//
// Die Reihenfolge der Fälle ist die Rangfolge, und sie ist überlegt: Abgelaufen
// zuerst, weil der Token dann gar nichts mehr tut — jede andere Auskunft wäre
// daneben. „Ohne Ablauf" gilt als Warnung und nicht als guter Zustand: Es ist
// erlaubt und bleibt eine offene Rechnung, und sie zu verschweigen wäre der
// Unterschied zwischen einer Entscheidung und einem Versehen.
func tokenZustand(t store.APIToken, jetzt time.Time) (zustand, text string) {
	switch {
	case t.Abgelaufen(jetzt):
		return "schlecht", "abgelaufen — dieser Token tut nichts mehr"
	case t.ExpiresAt == nil:
		return "warn", "ohne Ablauf — gilt, bis ihn jemand widerruft"
	case t.ExpiresAt.Sub(jetzt) < 14*24*time.Hour:
		return "warn", "läuft in weniger als zwei Wochen ab"
	case t.LastUsedAt == nil:
		return "warn", "noch nie benutzt"
	default:
		return "gut", "gültig"
	}
}

// tokenFamilienListe sind die wählbaren Flächen mit einem Satz dazu.
//
// Der Satz ist nicht Zierde: „schedules" sagt einem Menschen nichts, und wer
// einen Token einschränken will, muss wissen, was er damit abschaltet.
func tokenFamilienListe() []apiTokenFamilie {
	was := map[string]string{
		"overview":     "Übersicht, Telemetrie und Urteil",
		"signals":      "Handlungsbedarf",
		"metrics":      "Messwerte und ihre Verläufe",
		"services":     "Dienste lesen und schalten",
		"packages":     "Paketstand und Updates einspielen",
		"system":       "Neustart des Servers",
		"firewall":     "Firewall lesen und Regeln setzen",
		"logs":         "Journal lesen",
		"audit":        "Protokoll lesen",
		"files":        "Dateien lesen und schreiben",
		"schedules":    "Cron-Einträge und Timer",
		"certificate":  "Zertifikat und ACME",
		"update":       "Selbstaktualisierung des Panels",
		"system-users": "Systemkonten und SSH-Schlüssel",
		"jobs":         "laufende Vorgänge verfolgen",
		"session":      "Auskunft über den eigenen Zugang",
	}
	out := make([]apiTokenFamilie, 0, len(tokenFamilien))
	for _, f := range tokenFamilien {
		out = append(out, apiTokenFamilie{Wert: f, Was: was[f]})
	}
	return out
}

func tokenGesperrteListe() []string {
	// Feste Reihenfolge statt der Ordnung einer Karte: Eine Liste, die bei jedem
	// Aufruf anders sortiert ist, sieht in der Oberfläche nach einem Fehler aus.
	return []string{"tokens", "panel-users", "account"}
}

// tokenFristen sind die wählbaren Laufzeiten. 0 heißt „ohne Ablauf" und steht
// bewusst als Wahl da: Ein Token, der um drei Uhr nachts stillschweigend abläuft,
// bricht eine Automatisierung genau dann, wenn niemand hinsieht. Die Liste beginnt
// mit dem kürzesten — wer nicht nachdenkt, bekommt die engste Frist.
func tokenFristen() []apiTokenFrist {
	return []apiTokenFrist{
		{Tage: 30, Name: "30 Tage"},
		{Tage: 90, Name: "90 Tage"},
		{Tage: 365, Name: "ein Jahr"},
		{Tage: 0, Name: "ohne Ablauf"},
	}
}

// ------------------------------------------------------------- Schreiben ---

// apiTokenAuftrag ist der Körper von POST /api/v1/tokens.
type apiTokenAuftrag struct {
	Name     string   `json:"name"`
	Scopes   []string `json:"scopes"`
	NurLesen bool     `json:"nur_lesen"`
	// Tage ist die Laufzeit; 0 heißt „ohne Ablauf".
	Tage int `json:"tage"`

	Bestaetigt bool   `json:"bestaetigt"`
	Getippt    string `json:"getippt"`
}

// apiTokenAntwort ist die Antwort auf eine ausgeführte Handlung.
type apiTokenAntwort struct {
	Meldung string `json:"meldung"`
	// Token ist der Klartext — GENAU EINMAL, nur hier, nur in dieser Antwort.
	// Danach gibt es ihn nicht mehr: In der Datenbank steht der Hash, und es gibt
	// keinen Endpunkt, der ihn zurückgäbe.
	Token   string `json:"token,omitempty"`
	Hinweis string `json:"hinweis,omitempty"`
}

// handleAPITokenAnlegen legt einen Token an — Stufe 2.
//
// Nicht Stufe 3: Der Token existiert erst nach dem Klick, ist sofort widerrufbar,
// und seine Rechte sind durch die Rolle des Anlegenden begrenzt — er kann nichts,
// was der Anlegende nicht schon kann. Was die Rückfrage leisten muss, ist etwas
// anderes: Sie soll sagen, WAS dieser Token darf, bevor er da ist. Ein Token, den
// man in der Annahme anlegt, er dürfe nur lesen, ist die Gefahr — nicht der Klick.
func (s *Server) handleAPITokenAnlegen(w http.ResponseWriter, r *http.Request) {
	user, _ := userFrom(r.Context())

	var auftrag apiTokenAuftrag
	if !s.apiJSONKoerper(w, r, &auftrag) {
		return
	}
	auftrag.Name = strings.TrimSpace(auftrag.Name)

	if err := pruefeTokenName(auftrag.Name); err != nil {
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}
	scopes, err := pruefeScopes(auftrag.Scopes)
	if err != nil {
		s.apiFehler(w, http.StatusBadRequest, err.Error())
		return
	}
	if !enthaeltFrist(auftrag.Tage) {
		s.apiFehler(w, http.StatusBadRequest,
			"Die Laufzeit muss eine der angebotenen sein.")
		return
	}

	if !s.apiBestaetigt(w, apiAktionAnfrage{
		Bestaetigt: auftrag.Bestaetigt, Getippt: auftrag.Getippt,
	}, s.tokenFrage(user, auftrag, scopes)) {
		return
	}

	klartext, hash, prefix, err := NeuerAPIToken()
	if err != nil {
		s.apiFehler(w, http.StatusInternalServerError, "Der Token ließ sich nicht erzeugen.")
		return
	}

	tok := store.APIToken{
		Hash: hash, Prefix: prefix, Name: auftrag.Name,
		UserID: user.ID, Scopes: scopes, ReadOnly: auftrag.NurLesen,
		CreatedAt: time.Now(),
	}
	if auftrag.Tage > 0 {
		frist := time.Now().Add(time.Duration(auftrag.Tage) * 24 * time.Hour)
		tok.ExpiresAt = &frist
	}

	if _, err := s.db.CreateAPIToken(r.Context(), tok); err != nil {
		s.audit(r, "token.create", auftrag.Name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusInternalServerError, "Der Token ließ sich nicht ablegen.")
		return
	}

	// Im Protokoll steht, WAS vergeben wurde — nie der Token und nie sein
	// sichtbarer Anfang: Beides zusammen mit einem Datenbankabzug wäre mehr, als
	// ein Protokoll wissen muss.
	s.audit(r, "token.create", auftrag.Name, store.ResultOK,
		"scopes="+scopeText(scopes)+" nur_lesen="+boolText(auftrag.NurLesen)+
			" frist="+fristText(auftrag.Tage))

	s.apiJSON(w, http.StatusOK, apiTokenAntwort{
		Meldung: "Der Token ist angelegt.",
		Token:   klartext,
		Hinweis: "Dieser Token steht nur hier und wird nicht noch einmal angezeigt. " +
			"Wer ihn verliert, widerruft ihn und legt einen neuen an.",
	})
}

// tokenFrage baut die Rückfrage. Sie ist eine ZUSAMMENFASSUNG und keine Warnung:
// Was sie leisten muss, ist zu sagen, was dieser Token darf, bevor er da ist.
func (s *Server) tokenFrage(user store.User, auftrag apiTokenAuftrag, scopes []string) apiBestaetigung {
	umfang := "alle für Tokens offenen Flächen"
	if len(scopes) > 0 {
		umfang = strings.Join(scopes, ", ")
	}
	verfahren := "lesen und schreiben"
	if auftrag.NurLesen {
		verfahren = "nur lesen"
	}

	punkte := []string{
		"Rechte: " + verfahren + " auf " + umfang + ".",
		"Er erbt die Rolle von " + user.Username + " (" + user.Role +
			") und kann nie mehr als dieses Konto.",
		"Tokens, Panel-Zugänge und das eigene Konto bleiben für ihn gesperrt.",
	}
	if auftrag.Tage > 0 {
		punkte = append(punkte, "Er läuft nach "+fristText(auftrag.Tage)+" ab.")
	} else {
		// Der Satz ist bewusst deutlich: Diese Wahl ist erlaubt und bleibt eine
		// offene Rechnung.
		punkte = append(punkte, "Er läuft NICHT ab und gilt, bis ihn jemand widerruft.")
	}

	return apiBestaetigung{
		Titel:  "Token anlegen",
		Frage:  "Einen API-Token " + auftrag.Name + " vergeben?",
		Punkte: punkte,
		Knopf:  "anlegen",
	}
}

// handleAPITokenWiderrufen entfernt einen Token — Stufe 2.
//
// Der Name wird getippt? Nein. Widerrufen macht das System sicherer und nicht
// unsicherer, und eine Hürde davor ist eine Hürde vor der richtigen Handlung. Was
// die Rückfrage leistet, ist die Auskunft über die Folge: Ein Skript, das ihn
// benutzt, hört auf zu laufen — und das kann eine Sicherung sein.
func (s *Server) handleAPITokenWiderrufen(w http.ResponseWriter, r *http.Request) {
	id, err := strconv.ParseInt(r.PathValue("id"), 10, 64)
	if err != nil {
		s.apiFehler(w, http.StatusBadRequest, "Ungültige Kennung.")
		return
	}
	var auftrag apiCronLoeschAuftrag
	if !s.apiJSONKoerper(w, r, &auftrag) {
		return
	}

	tokens, err := s.db.ListAPITokens(r.Context())
	if err != nil {
		s.apiFehler(w, http.StatusInternalServerError, "Die Tokens ließen sich nicht lesen.")
		return
	}
	var ziel store.APIToken
	for _, t := range tokens {
		if t.ID == id {
			ziel = t
			break
		}
	}
	if ziel.ID == 0 {
		s.apiFehler(w, http.StatusNotFound, "Diesen Token gibt es nicht.")
		return
	}

	punkte := []string{
		"Jeder Aufruf mit diesem Token wird danach abgewiesen.",
		"Läuft damit eine Automatisierung, hört sie auf zu arbeiten — auch eine Sicherung.",
	}
	if ziel.LastUsedAt != nil {
		punkte = append(punkte, "Zuletzt benutzt: "+
			ziel.LastUsedAt.Format("02.01.2006 15:04")+" von "+ziel.LastUsedIP+".")
	} else {
		// „Noch nie benutzt" ist die Auskunft, die das Widerrufen leicht macht.
		punkte = append(punkte, "Dieser Token wurde noch nie benutzt.")
	}

	if !s.apiBestaetigt(w, apiAktionAnfrage{
		Bestaetigt: auftrag.Bestaetigt, Getippt: auftrag.Getippt,
	}, apiBestaetigung{
		Titel:  "Token " + ziel.Name + " widerrufen",
		Frage:  "Den Token " + ziel.Name + " endgültig entfernen?",
		Punkte: punkte,
		Knopf:  "widerrufen",
	}) {
		return
	}

	weg, err := s.db.DeleteAPIToken(r.Context(), id)
	if err != nil {
		s.audit(r, "token.revoke", ziel.Name, store.ResultError, err.Error())
		s.apiFehler(w, http.StatusInternalServerError, "Der Token ließ sich nicht widerrufen.")
		return
	}
	if !weg {
		// Zwei Fenster, zwei Klicks: Der zweite soll nichts melden, was nach einem
		// Fehler klingt.
		s.apiJSON(w, http.StatusOK, apiTokenAntwort{Meldung: "Der Token ist widerrufen."})
		return
	}
	s.audit(r, "token.revoke", ziel.Name, store.ResultOK, "")
	s.apiJSON(w, http.StatusOK, apiTokenAntwort{Meldung: "Der Token ist widerrufen."})
}

// ------------------------------------------------------------- Prüfungen ---

func pruefeTokenName(name string) error {
	if name == "" {
		return errText("Der Token braucht einen Namen — in sechs Monaten ist er " +
			"die einzige Auskunft darüber, wozu er da war.")
	}
	if len([]rune(name)) > 60 {
		return errText("Der Name ist länger als 60 Zeichen.")
	}
	for _, r := range name {
		if r < 0x20 || r == 0x7f {
			return errText("Der Name enthält ein Steuerzeichen.")
		}
	}
	return nil
}

// pruefeScopes weist alles ab, was nicht in der Familienliste steht — und nennt
// gesperrte Familien eigens.
//
// Der Unterschied ist keine Feinheit: „gibt es nicht" schickt jemanden auf die
// Suche nach einem Tippfehler, „ist gesperrt" beantwortet die Frage.
func pruefeScopes(roh []string) ([]string, error) {
	out := []string{}
	gesehen := map[string]bool{}
	for _, s := range roh {
		s = strings.TrimSpace(s)
		if s == "" {
			continue
		}
		if tokenGesperrt[s] {
			return nil, errText("Die Fläche " + s + " ist für Tokens gesperrt: Ein " +
				"Token soll weder Tokens noch Zugänge anlegen und nicht den eigenen " +
				"Anmeldeweg ändern können.")
		}
		if !enthaeltText(tokenFamilien, s) {
			return nil, errText("Die Fläche " + s + " gibt es nicht.")
		}
		if gesehen[s] {
			continue
		}
		gesehen[s] = true
		out = append(out, s)
	}
	return out, nil
}

func enthaeltFrist(tage int) bool {
	for _, f := range tokenFristen() {
		if f.Tage == tage {
			return true
		}
	}
	return false
}

func fristText(tage int) string {
	for _, f := range tokenFristen() {
		if f.Tage == tage {
			return f.Name
		}
	}
	return "unbekannt"
}

func scopeText(scopes []string) string {
	if len(scopes) == 0 {
		return "alle"
	}
	return strings.Join(scopes, "+")
}
