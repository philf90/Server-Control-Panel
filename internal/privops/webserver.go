package privops

import (
	"context"
	"errors"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// Webserver: Zustand, Portbelegung und Installation.
//
// Stufe 0.6 aus docs/16-neukonzeption.md §5, ausgeführt in docs/18-webserver.md.
// Dies ist der erste Schritt — das Fundament. Er beantwortet drei Fragen, und
// die dritte ist die, wegen der es diese Datei zuerst gibt:
//
//  1. Ist nginx da, und in welcher Fassung?
//  2. Läuft der Dienst?
//  3. **Wer hält Port 80 und 443?**
//
// Verwaltet wird ausschließlich nginx. Jeder andere Webserver — Caddy, Apache,
// lighttpd, ein Traefik im Container — wird erkannt, benannt und nicht
// angefasst. Die Begründung steht in docs/18-webserver.md E1; die kurze Fassung:
// Zwei Backends schreibend heißt zwei Syntaxen, zwei Prüfprogramme und zwei
// Angriffsdurchgänge, und auch „nur lesend" wäre nicht billig, weil eine
// Caddy-Liste einen Caddyfile-Parser oder die Admin-API bräuchte — für eine
// Auskunft, mit der man nichts tun darf.
//
// Warum die dritte Frage die wichtigste ist. Das Panel bietet an, nginx
// einzuspielen — dieselbe Haltung wie bei ufw und Docker: Fehlt das Werkzeug,
// bietet das Panel es an, statt eine Kommandozeile zum Abtippen zu drucken. Nur
// ist dieser Knopf gefährlicher als seine Vorgänger: `apt-get install nginx`
// startet nginx, nginx bindet Port 80, und ein Webserver, der dort schon lief,
// ist weg. Es ist die einzige Aktion dieses Moduls, die einen Server im Betrieb
// umbringen kann.
//
// Deshalb fragt dieses Modul nach dem PORT und nicht nach einem Paketnamen.
// Eine Liste bekannter Konkurrenten wäre immer unvollständig — sie hätte Caddy
// gekannt und den Apache übersehen, und ein Traefik im Container heißt auf dem
// Wirt „docker-proxy" und in keiner Paketliste irgendwas.

const (
	// nginxPaket ist das Paket, das das Panel einspielt.
	//
	// Das Metapaket aus den Quellen der Distribution und nicht nginx aus dem
	// Repository von nginx.org: Letzteres einzubinden hieße, einen fremden Stack
	// neben apt zu stellen — Nicht-Ziel des Projekts, dieselbe Entscheidung wie
	// docker.io gegen docker-ce. Wer nginx anders installiert hat, behält es;
	// erkannt wird am Binary und nicht am Paket.
	nginxPaket = "nginx"
	// nginxUnit ist der Dienst, den systemd dafür führt.
	nginxUnit = "nginx.service"
)

// nginxPakete sind die Pakete, aus denen nginx stammen kann.
//
// Debian und Ubuntu teilen nginx in Geschmacksrichtungen auf: Das Metapaket
// "nginx" zieht eine davon nach, und installiert ist danach nginx-core. Ein
// Panel, das nur nach "nginx" fragt, bekäme auf vielen Servern „installiert"
// zurück und auf manchen nichts, obwohl dasselbe läuft.
var nginxPakete = []string{"nginx", "nginx-core", "nginx-full", "nginx-light", "nginx-extras"}

// webPorts sind die Ports, nach denen dieses Modul fragt.
//
// Nur diese beiden, und die Filterung passiert hier und nicht beim Aufrufer:
// Die vollständige Liste aller lauschenden Sockets ist eine Auskunft über den
// ganzen Server, die dieses Modul weder braucht noch weiterreichen soll.
var webPorts = map[int]bool{80: true, 443: true}

// Lauscher ist ein Prozess, der auf einem der Webports hört.
type Lauscher struct {
	Port    int    `json:"port"`
	Adresse string `json:"adresse"`
	// Prozess ist der Programmname, wie ss ihn nennt — "nginx", "caddy",
	// "apache2", "docker-proxy". Leer, wenn ss keinen nennen konnte.
	Prozess string `json:"prozess"`
	PID     int    `json:"pid"`
}

// WebServerState ist der Zustand des Webservers.
//
// Wie DockerState trennt der Typ Fragen, zu denen verschiedene Handgriffe
// gehören: „nicht installiert" und „installiert, aber gestoppt" dürfen nicht
// gleich aussehen, sonst bekommt der eine Fall den Rat des anderen.
type WebServerState struct {
	// Installiert heißt: Das Programm nginx ist vorhanden. Am Binary ermittelt
	// und nicht am Paketnamen — siehe nginxPaket.
	Installiert bool `json:"installiert"`
	// Version ist die Fassung aus "nginx -v", etwa "1.24.0".
	Version string `json:"version"`
	// Paket nennt, woher nginx stammt, soweit dpkg es weiß. Leer heißt: an apt
	// vorbei installiert. Reine Auskunft, sie entscheidet nichts.
	Paket string `json:"paket"`
	// DienstAktiv heißt: systemd führt nginx.service als laufend. Getrennt von
	// den Lauschern, weil beide verschiedene Fragen beantworten — ein nginx, das
	// startet und sofort wieder stirbt, ist inaktiv und hält trotzdem kurz einen
	// Port; ein nginx in einer fremden Unit hält Ports und ist hier inaktiv.
	DienstAktiv bool `json:"dienst_aktiv"`

	// Lauscher sind die Prozesse auf Port 80 und 443 — der Kern dieser Auskunft.
	Lauscher []Lauscher `json:"lauscher"`
	// LauscherGeprueft sagt, ob die Belegung überhaupt ermittelt werden konnte.
	//
	// Das ist kein Beiwerk, sondern die wichtigste Zeile dieses Typs. FALSE
	// heißt NICHT „dort hört niemand" — es heißt „unbekannt". Ohne dieses Feld
	// wäre eine leere Liste aus einem fehlgeschlagenen ss-Aufruf nicht von einem
	// freien Port zu unterscheiden, und genau daraus entstünde die Einladung,
	// nginx über einen laufenden Webserver zu installieren.
	//
	// Dieselbe Haltung wie bei ConfigCheckResult.Checked (configcheck.go): Was
	// nicht geprüft werden konnte, meldet das Panel als ungeprüft und nicht als
	// in Ordnung.
	LauscherGeprueft bool `json:"lauscher_geprueft"`
}

// Belegt gibt die Lauscher zurück, die nicht zu nginx gehören.
//
// Die Frage „darf das Panel nginx einspielen" hängt genau daran, und sie steht
// deshalb als Methode am Zustand und nicht als Rechnung in der Oberfläche: Eine
// zweite Auslegung wäre die Stelle, an der beide auseinanderlaufen.
//
// Ein leeres Ergebnis ist nur zusammen mit LauscherGeprueft eine Antwort. Wer
// diese Methode benutzt, ohne das Feld zu prüfen, hält „unbekannt" für „frei".
func (st WebServerState) Belegt() []Lauscher {
	var aus []Lauscher
	for _, l := range st.Lauscher {
		if l.Prozess == "nginx" {
			continue
		}
		aus = append(aus, l)
	}
	return aus
}

// WebServerState ermittelt den Zustand des Webservers.
//
// Drei billige Aufrufe, und keiner darf die Auskunft insgesamt kippen: Fehlt
// nginx, ist das kein Fehler, sondern der Normalfall auf einem frischen Server —
// und die Antwort darauf ist ein Angebot.
func (s *System) WebServerState(ctx context.Context) (WebServerState, error) {
	var st WebServerState

	da, version, err := s.nginxFassung(ctx)
	if err != nil {
		return WebServerState{}, err
	}
	if da {
		st.Installiert = true
		st.Version = version
		st.Paket = s.nginxPaketname(ctx)
		st.DienstAktiv = s.unitAktiv(ctx, nginxUnit)
	}

	st.Lauscher, st.LauscherGeprueft = s.webLauscher(ctx)
	return st, nil
}

// nginxFassung fragt "nginx -v" und trennt dabei zwei Dinge, die leicht
// zusammenfallen: „nginx ist nicht da" und „hier stimmt etwas nicht".
//
// Ein fehlendes Binary ist der Normalfall auf einem frischen Server und wird als
// (false, "", nil) gemeldet. Nur ein Kommando außerhalb der Allowlist schlägt
// durch — das wäre ein Programmierfehler.
//
// Eigene Funktion und kein Zweig in WebServerState: Dort stünde am Ende ein
// `return st, nil` unter einem `err != nil`, und das ist genau die Form, die
// der Linter zu Recht anmerkt. Man kann sie mit einer Ausnahme stillstellen —
// hier ist der Umbau ehrlicher, weil er das Verschlucken des Fehlers an die
// Stelle bringt, an der es begründet ist.
func (s *System) nginxFassung(ctx context.Context) (bool, string, error) {
	res, err := s.run(ctx, Command{Name: "nginx", Args: []string{"-v"}})
	if err != nil {
		if errors.Is(err, ErrNotAllowed) {
			return false, "", err
		}
		return false, "", nil
	}
	// "nginx -v" schreibt seine Fassung auf die FEHLERAUSGABE, nicht auf die
	// Standardausgabe. Das ist kein Fehlerfall, sondern seit jeher so — wer hier
	// nur Stdout liest, bekommt ein installiertes nginx ohne Fassung.
	return true, parseNginxVersion(res.Stderr + res.Stdout), nil
}

// WebServerInstall spielt nginx ein.
//
// Wie DockerInstall und FirewallInstall bewusst kein allgemeines „installiere
// Paket X": Der Paketweg des Panels trägt aus gutem Grund "--only-upgrade" und
// kann darüber nichts Neues ins System bringen. Diese Operation kennt genau ein
// Paket, und sein Name steht im Quelltext statt in einem Formular.
//
// Ob eingespielt werden DARF, entscheidet diese Methode nicht — das tut der
// Handler, der vorher die Portbelegung liest (api_v1_webserver.go). Die Prüfung
// hier zu wiederholen wären zwei Auslegungen derselben Regel; die eine steht
// dort, wo auch der Knopf entsteht.
func (s *System) WebServerInstall(ctx context.Context, stream LineWriter) error {
	return s.aptInstall(ctx, stream, nginxPaket)
}

// nginxPaketname sucht das Paket, aus dem nginx stammt.
//
// Findet sich keines, kam nginx an apt vorbei — auch das ist eine Antwort und
// keine Lücke: Sie sagt, dass ein apt-Lauf hier nichts ausrichtet.
func (s *System) nginxPaketname(ctx context.Context) string {
	for _, name := range nginxPakete {
		res, err := s.run(ctx, Command{
			Name: "dpkg-query",
			Args: []string{"-W", "-f=${db:Status-Status}", name},
		})
		if err != nil {
			// Ohne dpkg-query ist das kein Debian-System. Keine Auskunft.
			return ""
		}
		if res.ExitCode == 0 && strings.TrimSpace(res.Stdout) == "installed" {
			return name
		}
	}
	return ""
}

// unitAktiv fragt systemd, ob eine Unit läuft.
//
// "is-active" statt des vollen Dienstdetails aus services.go: Hier interessiert
// genau ein Ja/Nein, und der billige Aufruf ist auch der, den ein Mensch auf
// der Kommandozeile tippen würde — Grundsatz IV, das Konsolen-Echo soll
// nachvollziehbar bleiben.
func (s *System) unitAktiv(ctx context.Context, unit string) bool {
	res, err := s.run(ctx, Command{
		Name: "systemctl",
		Args: []string{"is-active", "--quiet", "--", unit},
	})
	return err == nil && res.ExitCode == 0
}

// webLauscher ermittelt, wer auf Port 80 und 443 hört.
//
// Der zweite Rückgabewert sagt, ob die Auskunft überhaupt zustande kam. Er ist
// der Grund, warum diese Funktion nicht einfach eine Liste zurückgibt: Eine
// leere Liste und eine gescheiterte Abfrage sehen sonst gleich aus, und der
// Unterschied entscheidet über den Installationsknopf.
func (s *System) webLauscher(ctx context.Context) ([]Lauscher, bool) {
	res, err := s.run(ctx, Command{
		Name: "ss",
		// Lange Namen statt "-Hltnp": Die Zeile steht im Konsolen-Echo, und wer
		// sie dort liest, soll sie verstehen können, ohne die Handbuchseite
		// danebenzulegen.
		Args: []string{"--no-header", "--listening", "--tcp", "--numeric", "--processes"},
	})
	if err != nil || res.ExitCode != 0 {
		return nil, false
	}
	return parseLauscher(res.Stdout), true
}

// ------------------------------------------------------------------ Parser ---

// nginxVersionMuster liest die Fassung aus "nginx version: nginx/1.24.0 (Ubuntu)".
var nginxVersionMuster = regexp.MustCompile(`nginx/(\S+)`)

// parseNginxVersion liest die Fassung aus der Ausgabe von "nginx -v".
//
// Aufgezeichnete echte Ausgabe (nginx 1.24.0 auf Ubuntu 24.04, auf STDERR):
//
//	nginx version: nginx/1.24.0 (Ubuntu)
//
// Und ohne Distributionszusatz (nginx.org-Paket):
//
//	nginx version: nginx/1.27.3
//
// Findet sich nichts, bleibt die Fassung leer — ein nginx ohne erkennbare
// Fassung ist immer noch ein installiertes nginx, und die Auskunft insgesamt
// darf daran nicht scheitern.
func parseNginxVersion(out string) string {
	m := nginxVersionMuster.FindStringSubmatch(out)
	if m == nil {
		return ""
	}
	return m[1]
}

// ssProzessMuster liest Programmname und PID aus der Prozessspalte von ss:
//
//	users:(("nginx",pid=1234,fd=6),("nginx",pid=1235,fd=6))
var ssProzessMuster = regexp.MustCompile(`\("([^"]+)",pid=(\d+)`)

// parseLauscher liest die lauschenden Sockets aus der Ausgabe von ss.
//
// Aufgezeichnete echte Ausgabe von
// "ss --no-header --listening --tcp --numeric --processes"
// (iproute2 6.1 auf Debian 12, als root — ohne root fehlt die letzte Spalte):
//
//	LISTEN 0 4096  127.0.0.53%lo:53    0.0.0.0:* users:(("systemd-resolve",pid=712,fd=14))
//	LISTEN 0 511         0.0.0.0:80    0.0.0.0:* users:(("nginx",pid=1234,fd=6),("nginx",pid=1233,fd=6))
//	LISTEN 0 511            [::]:80       [::]:* users:(("nginx",pid=1234,fd=7))
//	LISTEN 0 4096        0.0.0.0:8443   0.0.0.0:* users:(("asylumd",pid=999,fd=3))
//
// Zwei Eigenschaften, die der Parser haben muss:
//
//  1. **Tolerant gegen zusätzliche Spalten.** Die Prozessspalte wird gesucht und
//     nicht abgezählt — ss stellt je nach Fassung und Schaltern verschieden
//     viele Felder voran, und ein Parser, der auf Feld 6 zeigt, bricht am Tag,
//     an dem eines dazukommt.
//  2. **Stumm gegenüber allem Unverstandenen.** Eine Zeile, die nicht passt,
//     wird übersprungen und nicht als Fehler gemeldet. Der Aufrufer erfährt aus
//     dem zweiten Rückgabewert von webLauscher, ob die ABFRAGE lief; einzelne
//     unleserliche Zeilen dürfen die Auskunft nicht kippen.
func parseLauscher(out string) []Lauscher {
	var aus []Lauscher
	for _, zeile := range strings.Split(out, "\n") {
		felder := strings.Fields(zeile)
		if len(felder) < 4 {
			continue
		}
		adresse, port, ok := trennePort(felder[3])
		if !ok || !webPorts[port] {
			continue
		}

		l := Lauscher{Port: port, Adresse: adresse}
		for _, feld := range felder[4:] {
			m := ssProzessMuster.FindStringSubmatch(feld)
			if m == nil {
				continue
			}
			l.Prozess = m[1]
			l.PID, _ = strconv.Atoi(m[2])
			break
		}
		aus = append(aus, l)
	}

	// Nach Port und Adresse sortiert: Eine Auskunft, deren Reihenfolge von der
	// Laune des Kernels abhängt, ist eine Auskunft, die bei jedem Aufruf anders
	// aussieht.
	sort.Slice(aus, func(i, j int) bool {
		if aus[i].Port != aus[j].Port {
			return aus[i].Port < aus[j].Port
		}
		return aus[i].Adresse < aus[j].Adresse
	})
	return aus
}

// trennePort schneidet den Port von der Adresse ab.
//
// ss schreibt die lokale Adresse in vier Formen, und alle vier kommen auf einem
// gewöhnlichen Server vor: "0.0.0.0:80", "[::]:80", "*:80" und
// "127.0.0.53%lo:53". Getrennt wird am LETZTEN Doppelpunkt — bei einer
// IPv6-Adresse stehen davor beliebig viele weitere.
func trennePort(feld string) (string, int, bool) {
	i := strings.LastIndex(feld, ":")
	if i < 0 {
		return "", 0, false
	}
	port, err := strconv.Atoi(feld[i+1:])
	if err != nil {
		return "", 0, false
	}
	return feld[:i], port, true
}
