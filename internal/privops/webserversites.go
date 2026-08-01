package privops

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
)

// Sites lesen (Schritt 4 der Stufe 0.6, docs/18-webserver.md).
//
// # Gelesen wird die GERENDERTE Konfiguration
//
// Nicht die Dateien in /etc/nginx, sondern die Ausgabe von `nginx -T`. Das ist
// dieselbe Entscheidung wie beim Compose-Prüfer (docs/17 E4) und aus demselben
// Grund: `include` ist bei nginx die Regel und nicht die Ausnahme. Die
// Debian-Vorgabe zieht `sites-enabled/*` und `conf.d/*` ein, viele Betreiber
// legen eigene Verzeichnisse daneben, und wer die Dateien selbst zusammensucht,
// baut den Auflöser von nginx nach — falsch und immer einen Fall zu knapp.
//
// `nginx -T` gibt die vollständige Konfiguration aus und schreibt vor jede
// Datei, woher sie stammt:
//
//	# configuration file /etc/nginx/nginx.conf:
//	…
//	# configuration file /etc/nginx/sites-enabled/default:
//	server { … }
//
// Damit ist beides beisammen: was nginx wirklich sieht, und aus welcher Datei
// es kommt.
//
// # Was das kostet, gehört dazu
//
// `nginx -T` läuft nur, wenn die Konfiguration GÜLTIG ist. Ist sie kaputt, gibt
// es keine Ausgabe — und dann meldet dieses Modul „nicht lesbar" samt der
// Meldung von nginx, statt eine leere Liste zu zeigen. Eine leere Liste hieße
// „keine Sites", und das ist eine andere Aussage als „ich konnte nicht
// nachsehen". Dieselbe Haltung wie bei ConfigCheckResult.Checked und bei
// WebServerState.LauscherGeprueft.
//
// Ein zweiter Parser, der bei kaputter Konfiguration die Dateien selbst liest,
// wäre die naheliegende Ergänzung und wird bewusst nicht gebaut: Er liefe genau
// dann, wenn die Lage unklar ist, und läge dann mit einer eigenen Auslegung
// daneben.

// Site ist ein Serverblock aus der Konfiguration des Webservers.
type Site struct {
	// Name ist die Kennung. Bei verwalteten Sites der Teil aus dem Dateinamen
	// (asylum-<name>.conf), bei fremden der erste Domainname.
	Name string `json:"name"`
	// Datei ist die Herkunft — die Datei, in der der Serverblock steht.
	Datei string `json:"datei"`
	// Domains sind die Namen aus server_name, ohne den Platzhalter "_".
	Domains []string `json:"domains"`
	// Zielart ist "proxy", "statisch", "umleitung" oder leer.
	Zielart string `json:"zielart"`
	// Ziel ist das Ziel in der Form, in der es in der Datei steht.
	Ziel string `json:"ziel"`
	// Ports sind die Ports aus den listen-Anweisungen.
	Ports []int `json:"ports"`
	// TLS heißt: Der Block hat mindestens ein listen mit ssl.
	TLS bool `json:"tls"`
	// Zertifikat ist der Pfad aus ssl_certificate, soweit gesetzt.
	Zertifikat string `json:"zertifikat"`
	// Verwaltet heißt: Diese Datei hat das Panel geschrieben (Marker in der
	// ersten Zeile). Nur solche werden je verändert.
	Verwaltet bool `json:"verwaltet"`
	// Anmerkung nennt, was an diesem Block nicht gelesen werden konnte.
	Anmerkung string `json:"anmerkung"`
}

// SiteBestand ist das Ergebnis von SiteList.
type SiteBestand struct {
	Sites []Site `json:"sites"`
	// Gelesen sagt, ob die Konfiguration überhaupt gelesen werden konnte.
	//
	// FALSE heißt NICHT „keine Sites" — es heißt „unbekannt". Ohne dieses Feld
	// sähe eine kaputte nginx-Konfiguration aus wie ein Server ohne Sites, und
	// die Fläche böte an, die erste anzulegen.
	Gelesen bool `json:"gelesen"`
	// Fehler ist die Meldung von nginx, wenn Gelesen false ist.
	Fehler string `json:"fehler,omitempty"`
}

// SiteList liest die Serverblöcke aus der Konfiguration des Webservers.
func (s *System) SiteList(ctx context.Context) (SiteBestand, error) {
	dump, meldung := s.nginxDump(ctx)
	if meldung != "" {
		return SiteBestand{Gelesen: false, Fehler: meldung}, nil
	}

	bestand := SiteBestand{Gelesen: true, Sites: parseNginxDump(dump)}
	// Der Marker steht in unseren eigenen Dateien und wird von der Platte
	// gelesen, nicht aus dem Dump: Ob `nginx -T` Kommentare mit ausgibt, ist
	// von der Fassung abhängig — und die Frage „gehört diese Datei dem Panel"
	// darf davon nicht abhängen.
	for i := range bestand.Sites {
		bestand.Sites[i].Verwaltet = istVerwalteteSite(bestand.Sites[i].Datei)
		if bestand.Sites[i].Verwaltet {
			bestand.Sites[i].Name = siteNameAusDatei(bestand.Sites[i].Datei)
		}
	}
	sort.Slice(bestand.Sites, func(i, j int) bool {
		if bestand.Sites[i].Name != bestand.Sites[j].Name {
			return bestand.Sites[i].Name < bestand.Sites[j].Name
		}
		return bestand.Sites[i].Datei < bestand.Sites[j].Datei
	})
	return bestand, nil
}

// nginxDump holt die gerenderte Konfiguration. Zweiter Rückgabewert ist die
// Meldung, wenn es nicht ging — leer heißt: es ging.
//
// Eine eigene Funktion, damit der Fehler dort verschluckt wird, wo das Verschlucken
// begründet ist: Weder ein fehlendes nginx noch eine ungültige Konfiguration ist
// ein Fehler DIESES Aufrufs. Beides ist ein Zustand des Systems, und beides trägt
// SiteBestand in seinen eigenen Feldern. Dieselbe Aufteilung wie bei nginxFassung.
func (s *System) nginxDump(ctx context.Context) (string, string) {
	res, err := s.run(ctx, Command{Name: "nginx", Args: []string{"-T"}})
	if err != nil {
		// Kein nginx auf diesem Server. Dann gibt es nichts zu lesen, und die
		// Fläche sagt über WebServerState, woran das liegt.
		return "", err.Error()
	}
	if res.ExitCode != 0 {
		return "", "nginx konnte die Konfiguration nicht lesen: " + kurzeAusgabe(res)
	}
	return res.Stdout, ""
}

// sitePraefix ist der Anfang jedes Dateinamens, den das Panel schreibt.
const sitePraefix = "asylum-"

// istVerwalteteSite sagt, ob diese Datei dem Panel gehört.
//
// Zwei Bedingungen, und beide müssen stimmen: der Ort samt Namensmuster UND der
// Marker in der ersten Zeile. Der Ort allein genügt nicht — jemand kann eine
// Datei so nennen —, und der Marker allein auch nicht, denn er ließe sich in
// eine Datei anderswo kopieren.
func istVerwalteteSite(datei string) bool {
	sauber := filepath.Clean(datei)
	if filepath.Dir(sauber) != filepath.Dir(acmeDropinPfad) {
		return false
	}
	name := filepath.Base(sauber)
	if !strings.HasPrefix(name, sitePraefix) || !strings.HasSuffix(name, ".conf") {
		return false
	}
	b, err := os.ReadFile(sauber) //nolint:gosec // Pfad aus der Ausgabe von nginx -T, nicht aus einer Anfrage
	if err != nil {
		return false
	}
	return strings.HasPrefix(string(b), nginxMarker)
}

// siteNameAusDatei zieht die Kennung aus asylum-<name>.conf.
func siteNameAusDatei(datei string) string {
	name := filepath.Base(filepath.Clean(datei))
	name = strings.TrimPrefix(name, sitePraefix)
	return strings.TrimSuffix(name, ".conf")
}

// ------------------------------------------------------------------ Parser ---

// dateiKopf leitet in der Ausgabe von `nginx -T` jede Datei ein.
const dateiKopf = "# configuration file "

// parseNginxDump zerlegt die Ausgabe von `nginx -T` in Serverblöcke.
//
// Der Parser ist absichtlich klein und tolerant. Er versteht genau, was für
// eine ÜBERSICHT gebraucht wird — welche Namen, welche Ports, welches Ziel —
// und lässt alles andere stehen. nginx hat über tausend Anweisungen; einen
// vollständigen Parser zu bauen hieße, nginx nachzubauen, und er wäre bei jeder
// Fassung wieder einen Fall zu knapp.
//
// Was er NICHT tut, gehört dazu: Er entscheidet nichts. Aus dem, was er liest,
// wird eine Anzeige — kein Schreibvorgang. Geschrieben werden ausschließlich
// eigene Dateien, und die entstehen aus Feldern (E2), nicht aus geparstem Text.
func parseNginxDump(dump string) []Site {
	var aus []Site
	for _, abschnitt := range dateiabschnitte(dump) {
		for _, block := range serverbloecke(abschnitt.inhalt) {
			site := parseServerblock(block)
			site.Datei = abschnitt.datei
			if site.Name == "" && len(site.Domains) > 0 {
				site.Name = site.Domains[0]
			}
			if site.Name == "" {
				// Ein Serverblock ohne server_name ist gültig — er bedient
				// dann, was sonst nichts nimmt. Ihn wegzulassen hieße, eine
				// Fläche zu zeigen, in der ein vorhandener vHost fehlt.
				site.Name = "ohne Namen"
				site.Anmerkung = "Dieser Block hat keinen server_name. Er bedient, was " +
					"sonst kein Block nimmt."
			}
			aus = append(aus, site)
		}
	}
	return aus
}

type dateiabschnitt struct {
	datei   string
	inhalt  string
	gesehen bool
}

// dateiabschnitte trennt den Dump an den Dateiköpfen.
func dateiabschnitte(dump string) []dateiabschnitt {
	var aus []dateiabschnitt
	var jetzt dateiabschnitt
	var b strings.Builder

	schliessen := func() {
		if jetzt.gesehen {
			jetzt.inhalt = b.String()
			aus = append(aus, jetzt)
		}
		b.Reset()
	}

	for _, zeile := range strings.Split(dump, "\n") {
		if rest, ok := strings.CutPrefix(strings.TrimSpace(zeile), dateiKopf); ok {
			schliessen()
			jetzt = dateiabschnitt{datei: strings.TrimSuffix(strings.TrimSpace(rest), ":"), gesehen: true}
			continue
		}
		b.WriteString(zeile)
		b.WriteString("\n")
	}
	schliessen()
	return aus
}

// serverbloecke findet die Rümpfe aller `server { … }` einer Datei.
//
// Gezählt werden Klammern, und zwar außerhalb von Zeichenketten und
// Kommentaren. Ein `#` in einer Zeichenkette ist kein Kommentar, und eine
// geschweifte Klammer in einer Zeichenkette ist keine Klammer — beides kommt in
// echten Konfigurationen vor (`add_header Content-Security-Policy "…{…}…";`),
// und wer es übersieht, verliert ab dort die Zählung und damit alle folgenden
// Blöcke.
func serverbloecke(inhalt string) []string {
	var aus []string
	roh := []rune(inhalt)

	for i := 0; i < len(roh); i++ {
		if !wortHierBeginnt(roh, i, "server") {
			continue
		}
		// Hinter „server" muss eine öffnende Klammer folgen (mit Zwischenraum).
		// Sonst ist es `server_name`, `server_tokens` oder eine Anweisung in
		// einem upstream-Block.
		j := i + len("server")
		for j < len(roh) && (roh[j] == ' ' || roh[j] == '\t' || roh[j] == '\n' || roh[j] == '\r') {
			j++
		}
		if j >= len(roh) || roh[j] != '{' {
			continue
		}

		ende, ok := blockEnde(roh, j)
		if !ok {
			// Unvollständiger Block — abbrechen statt raten. Der Rest der Datei
			// wäre ohnehin nicht verlässlich zu lesen.
			break
		}
		aus = append(aus, string(roh[j+1:ende]))
		i = ende
	}
	return aus
}

// wortHierBeginnt sagt, ob an dieser Stelle das Wort als eigenes Wort steht.
func wortHierBeginnt(roh []rune, i int, wort string) bool {
	if i > 0 && !istTrenner(roh[i-1]) {
		return false
	}
	if i+len(wort) > len(roh) {
		return false
	}
	return string(roh[i:i+len(wort)]) == wort
}

func istTrenner(r rune) bool {
	return r == ' ' || r == '\t' || r == '\n' || r == '\r' || r == ';' || r == '{' || r == '}'
}

// blockEnde findet die schließende Klammer zu der bei auf. Zeichenketten und
// Kommentare zählen nicht mit.
func blockEnde(roh []rune, auf int) (int, bool) {
	tiefe := 0
	for i := auf; i < len(roh); i++ {
		switch roh[i] {
		case '#':
			for i < len(roh) && roh[i] != '\n' {
				i++
			}
		case '"', '\'':
			ende := roh[i]
			i++
			for i < len(roh) && roh[i] != ende {
				if roh[i] == '\\' {
					i++
				}
				i++
			}
		case '{':
			tiefe++
		case '}':
			tiefe--
			if tiefe == 0 {
				return i, true
			}
		}
	}
	return 0, false
}

// parseServerblock zieht die Angaben aus einem Serverblock.
func parseServerblock(block string) Site {
	var site Site
	portsGesehen := map[int]bool{}

	for _, anweisung := range anweisungen(block) {
		felder := strings.Fields(anweisung)
		if len(felder) == 0 {
			continue
		}
		switch felder[0] {
		case "server_name":
			for _, n := range felder[1:] {
				// „_" ist bei nginx der Platzhalter für „kein echter Name".
				// Ihn als Domain anzuzeigen wäre eine erfundene Auskunft.
				if n == "_" || n == "" {
					continue
				}
				site.Domains = append(site.Domains, strings.ToLower(n))
			}
		case "listen":
			if port, ok := listenPort(felder[1:]); ok && !portsGesehen[port] {
				portsGesehen[port] = true
				site.Ports = append(site.Ports, port)
			}
			for _, f := range felder[1:] {
				if f == "ssl" {
					site.TLS = true
				}
			}
		case "ssl_certificate":
			if len(felder) > 1 {
				site.Zertifikat = felder[1]
				site.TLS = true
			}
		case "root":
			if len(felder) > 1 && site.Zielart == "" {
				site.Zielart, site.Ziel = "statisch", felder[1]
			}
		case "proxy_pass":
			// Der Proxy gewinnt gegen ein root: Ein Block mit beidem reicht in
			// aller Regel weiter, und das ist die Auskunft, die zählt.
			if len(felder) > 1 {
				site.Zielart, site.Ziel = "proxy", felder[1]
			}
		case "return":
			if site.Zielart == "" && len(felder) > 2 && strings.HasPrefix(felder[1], "3") {
				site.Zielart, site.Ziel = "umleitung", felder[2]
			}
		}
	}

	sort.Ints(site.Ports)
	return site
}

// anweisungen zerlegt einen Blockrumpf in einzelne Anweisungen.
//
// Verschachtelte Blöcke (location, if, limit_except) werden MITGELESEN und
// nicht übersprungen: proxy_pass steht fast immer in einem location-Block, und
// ohne ihn wäre das Ziel jeder zweiten Site leer.
func anweisungen(block string) []string {
	var aus []string
	var b strings.Builder
	roh := []rune(block)

	for i := 0; i < len(roh); i++ {
		switch roh[i] {
		case '#':
			for i < len(roh) && roh[i] != '\n' {
				i++
			}
		case '"', '\'':
			ende := roh[i]
			b.WriteRune(roh[i])
			i++
			for i < len(roh) && roh[i] != ende {
				if roh[i] == '\\' && i+1 < len(roh) {
					b.WriteRune(roh[i])
					i++
				}
				b.WriteRune(roh[i])
				i++
			}
			if i < len(roh) {
				b.WriteRune(roh[i])
			}
		case ';', '{', '}':
			if t := strings.TrimSpace(b.String()); t != "" {
				aus = append(aus, t)
			}
			b.Reset()
		default:
			b.WriteRune(roh[i])
		}
	}
	if t := strings.TrimSpace(b.String()); t != "" {
		aus = append(aus, t)
	}
	return aus
}

// listenPort zieht den Port aus einer listen-Anweisung.
//
// Die Formen, die vorkommen: „80", „[::]:80", „0.0.0.0:80", „443 ssl",
// „unix:/…" (kein Port). Der Port steht hinter dem LETZTEN Doppelpunkt, wie
// überall — bei einer IPv6-Adresse stehen davor beliebig viele weitere.
func listenPort(felder []string) (int, bool) {
	if len(felder) == 0 {
		return 0, false
	}
	wert := felder[0]
	if strings.HasPrefix(wert, "unix:") {
		return 0, false
	}
	if i := strings.LastIndex(wert, ":"); i >= 0 {
		wert = wert[i+1:]
	}
	port := 0
	for _, r := range wert {
		if r < '0' || r > '9' {
			return 0, false
		}
		port = port*10 + int(r-'0')
	}
	if port <= 0 || port > 65535 {
		return 0, false
	}
	return port, true
}

// SiteDatei liest den Inhalt einer verwalteten Site.
//
// Nur verwaltete: Eine fremde Datei anzuzeigen wäre kein Sicherheitsproblem
// (der Dateimanager kann sie ohnehin), aber es wäre die Zusage, sie auch
// bearbeiten zu können — und die gibt dieses Modul nicht.
func (s *System) SiteDatei(_ context.Context, name string) (string, error) {
	if err := PruefeName(name); err != nil {
		return "", err
	}
	pfad := filepath.Join(filepath.Dir(acmeDropinPfad), sitePraefix+name+".conf")
	if !istVerwalteteSite(pfad) {
		return "", fmt.Errorf("%s gehört dem Panel nicht", pfad)
	}
	b, err := os.ReadFile(pfad) //nolint:gosec // Name geprüft, Pfad aus festen Bestandteilen
	if err != nil {
		return "", err
	}
	return string(b), nil
}
