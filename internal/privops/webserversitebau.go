package privops

import (
	"fmt"
	"net"
	"net/url"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
)

// Der Site-Prüfer und der Erzeuger (Schritt 5 der Stufe 0.6,
// docs/18-webserver.md §6).
//
// Dies ist der sicherheitskritische Kern des Moduls, aus demselben Grund wie der
// Compose-Prüfer bei Docker: Was das Panel hier schreibt, liest nginx als root,
// und was dabei herauskommt, ist aus dem Netz erreichbar.
//
// # Geprüft werden die FELDER, nicht der erzeugte Text
//
// Das ist der Unterschied zum Compose-Prüfer, und er ist wesentlich. Bei Docker
// kommt die Datei vom Benutzer, also wird die Datei geprüft. Hier kommt die
// Datei vom PANEL — sie entsteht aus Feldern. Ein Prüfer, der den erzeugten Text
// liest, prüft die eigene Vorlage und findet naturgemäß nichts: Was die Vorlage
// falsch macht, macht sie in jeder Zeile gleich falsch.
//
// Geprüft wird deshalb der Entwurf, bevor daraus Text wird. Was danach noch
// schiefgehen kann — ein Syntaxfehler, eine Kollision mit einer anderen Datei —
// findet `nginx -t`, und das ist die zweite Linie und nicht die erste.
//
// # Die dritte Regel des Compose-Prüfers gilt unverändert
//
// Was der Prüfer nicht kennt, meldet er als „nicht geprüft" und nicht als „in
// Ordnung". Eine Zielart, die er nicht auslegt, ist kein Freibrief; sie führt
// zur Ablehnung UND steht in Ungeprueft, damit der nächste, der eine hinzufügt,
// sieht, dass hier eine Lücke war.

// SiteEntwurf ist eine Site, wie das Formular sie beschreibt.
//
// Bewusst Felder und keine Datei: Der Weg „Benutzer tippt nginx-Konfiguration"
// existiert schon — er heißt Dateimanager. Was dieses Modul dazugibt, ist die
// Zusage, dass aus den Feldern etwas Gültiges und Geprüftes wird.
type SiteEntwurf struct {
	// Name ist die Kennung der Site und bestimmt den Dateinamen
	// (asylum-<name>.conf). Nicht der Domainname: Der kann sich ändern, und ein
	// Dateiname, der sich mit ändert, verliert die Zuordnung zum Zertifikat.
	Name string `json:"name"`
	// Domains sind die Namen für server_name. Der erste ist der führende.
	Domains []string `json:"domains"`
	// Zielart ist "proxy", "statisch", "php" oder "umleitung".
	Zielart string `json:"zielart"`
	// Ziel ist die Gegenstelle, das Verzeichnis oder das Umleitungsziel.
	Ziel string `json:"ziel"`
	// PHPSocket ist der FPM-Socket bei Zielart "php". Ein zweites Feld und nicht
	// eine zweite Bedeutung von Ziel: PHP braucht BEIDES — ein Verzeichnis, aus
	// dem ausgeliefert wird, und einen Prozess, der die .php-Dateien ausführt.
	PHPSocket string `json:"php_socket"`
	// TLS heißt: zusätzlich auf 443 mit Zertifikat.
	TLS bool `json:"tls"`
	// Zertifikat und Schluessel sind die Pfade. Leer bei TLS heißt: Das
	// Zertifikat ist noch nicht bezogen — die Site entsteht dann ohne 443 und
	// wird mit Schritt 7 nachgezogen.
	Zertifikat string `json:"zertifikat"`
	Schluessel string `json:"schluessel"`
	// HTTPUmleitung schaltet Port 80 auf eine Umleitung nach https um. Nur
	// sinnvoll mit TLS, und der Prüfer sagt das auch.
	HTTPUmleitung bool `json:"http_umleitung"`
}

// SiteLage ist das, was der Prüfer über den Server wissen muss und nicht selbst
// herausfinden kann.
//
// Als Parameter und nicht als Aufruf im Prüfer: Damit bleibt er eine reine
// Funktion und damit vollständig prüfbar. Ein Prüfer, der selbst `ss` und
// `nginx -T` aufruft, ließe sich nur mit einem laufenden Server testen — und
// dann testet ihn niemand.
type SiteLage struct {
	// PanelPort ist der Port, auf dem das Panel selbst antwortet.
	PanelPort int
	// FremdeNamen bildet einen bereits vergebenen server_name auf die Datei ab,
	// in der er steht. Verwaltete Sites gehören NICHT hinein — eine Site zu
	// ändern heißt, ihre eigenen Namen zu behalten.
	FremdeNamen map[string]string
	// GesperrtePfade sind Verzeichnisse, aus denen keine Site ausliefern darf.
	// Der Aufrufer legt das Datenverzeichnis des Panels dazu; die feste Liste
	// steht in gesperrteWurzeln.
	GesperrtePfade []string
}

// SiteBefund ist ein einzelner Fund des Prüfers.
type SiteBefund struct {
	Feld  string `json:"feld"`
	Wert  string `json:"wert"`
	Grund string `json:"grund"`
}

// SitePruefung ist das Ergebnis.
type SitePruefung struct {
	// Ablehnungen führen zu 400. Die Site wird nicht geschrieben.
	Ablehnungen []SiteBefund `json:"ablehnungen"`
	// Warnungen führen zu einer Rückfrage der Stufe 3 (getippter Domainname).
	// Sie sind kein Fehler, sondern der Fall, den man bewusst tun kann.
	Warnungen []SiteBefund `json:"warnungen"`
	// Ungeprueft nennt, was der Prüfer nicht auslegen konnte. „Nicht geprüft"
	// ist kein „in Ordnung" — dieselbe Haltung wie bei ConfigCheckResult.
	Ungeprueft []string `json:"ungeprueft"`
}

// OK sagt, ob geschrieben werden darf.
func (p SitePruefung) OK() bool { return len(p.Ablehnungen) == 0 }

// gesperrteWurzeln sind Verzeichnisse, aus denen keine Site ausliefern darf.
//
// Eine Site mit `root /etc` veröffentlicht die Konfiguration des Servers im
// Netz, eine mit `root /` den ganzen Server — der Fall, der beim Compose-Prüfer
// dem Bind-Mount auf `/` entspricht. Das ist kein Tippfehler, den man abfängt,
// sondern der Angriff selbst: Ein Formularfeld, in das jemand `/` schreibt, ist
// billiger als jeder Exploit.
//
// Eine Allowlist wäre hier die schärfere Antwort und ist es nicht: Auf einem
// Server liegen Webverzeichnisse an den unmöglichsten Stellen, und eine
// Allowlist hieße, dass die Hälfte der Betreiber das Modul nicht benutzen kann.
// Was außerhalb der üblichen Wurzeln liegt, führt deshalb zur RÜCKFRAGE (Stufe
// 3) und nicht zur Ablehnung; abgelehnt wird nur, was nie richtig sein kann.
var gesperrteWurzeln = []string{
	"/", "/etc", "/root", "/boot", "/proc", "/sys", "/dev", "/run",
	"/bin", "/sbin", "/lib", "/usr/bin", "/usr/sbin", "/usr/lib",
	"/var/lib", "/var/log", "/var/backups", "/home",
}

// UeblicheWurzeln sind die Orte, an denen Webinhalte üblicherweise liegen. Was
// darunter liegt, geht ohne Rückfrage durch.
var UeblicheWurzeln = []string{"/var/www", "/srv"}

// PruefeSiteName prüft die Kennung einer Site.
//
// Eine Allowlist und nicht pruefeName aus der Pfadwache: Die Kennung ist kein
// Dateiname, den jemand tippt, sondern ein Bezeichner, aus dem das Panel drei
// Dinge baut — den Dateinamen asylum-<name>.conf, das Verzeichnis des
// Zertifikats und den Namen in der Oberfläche. pruefeName lässt Leerzeichen,
// Großbuchstaben und Umlaute zu; alles davon wäre hier eine Quelle stiller
// Verwechslungen (zwei Sites „Shop" und „shop" zeigten auf dieselbe Datei, weil
// nginx Namen ohne Rücksicht auf Groß- und Kleinschreibung vergleicht).
//
// Dieselbe Form wie acme.PruefeKennung, und die Doppelung ist Absicht: privops
// darf internal/acme nicht kennen (es wäre die falsche Richtung), und jede der
// beiden Schichten muss das prüfen, was sie selbst schreibt. Gehen die Regeln
// je auseinander, ist die Folge ein Name, den die eine annimmt und die andere
// ablehnt — sichtbar als Fehler, nicht als Lücke.
func PruefeSiteName(name string) error {
	if name == "" {
		return fmt.Errorf("kein Name angegeben")
	}
	if len(name) > 63 {
		return fmt.Errorf("der Name ist zu lang (höchstens 63 Zeichen)")
	}
	for i, r := range name {
		switch {
		case r >= 'a' && r <= 'z', r >= '0' && r <= '9':
			continue
		case (r == '-' || r == '_') && i > 0:
			continue
		}
		return fmt.Errorf("unzulässiges Zeichen %q in der Kennung %q — erlaubt sind "+
			"a–z, 0–9 sowie - und _ (nicht als erstes Zeichen)", string(r), kuerzen(name))
	}
	return nil
}

// PruefeSiteEntwurf prüft einen Entwurf, bevor daraus eine Datei wird.
func PruefeSiteEntwurf(e SiteEntwurf, lage SiteLage) SitePruefung {
	var p SitePruefung

	if err := PruefeSiteName(e.Name); err != nil {
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "name", Wert: kuerzen(e.Name), Grund: err.Error(),
		})
	}

	p.pruefeDomainfeld(e, lage)
	p.pruefeZiel(e, lage)
	p.pruefeTLS(e, lage)

	return p
}

// pruefeDomainfeld prüft die Namen.
func (p *SitePruefung) pruefeDomainfeld(e SiteEntwurf, lage SiteLage) {
	if len(e.Domains) == 0 {
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld:  "domains",
			Grund: "Ohne Domainnamen gäbe es keinen server_name, und die Site würde alles beantworten, was sonst niemand beantwortet.",
		})
		return
	}

	gesehen := map[string]bool{}
	for _, roh := range e.Domains {
		name := strings.ToLower(strings.TrimSpace(roh))
		if err := pruefeSiteDomain(name); err != nil {
			// Die Injektionsstelle dieses Moduls: Der Name landet hinter
			// server_name, und `beispiel.de; root /;` wäre kein Name mehr,
			// sondern eine zweite Anweisung. Geprüft wird gegen die FORM eines
			// Hostnamens und nicht gegen eine Liste gefährlicher Zeichen — eine
			// solche Liste vergisst immer eines.
			p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
				Feld: "domains", Wert: kuerzen(name), Grund: err.Error(),
			})
			continue
		}
		if gesehen[name] {
			p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
				Feld: "domains", Wert: name,
				Grund: "Dieser Name steht zweimal in derselben Site.",
			})
			continue
		}
		gesehen[name] = true

		if datei, belegt := lage.FremdeNamen[name]; belegt {
			// Zwei Serverblöcke für denselben Namen sind kein Syntaxfehler:
			// nginx nimmt den ersten, den es liest, und welcher das ist, hängt
			// an der Reihenfolge der include-Auflösung. Eine Site, die still
			// nie antwortet, ist der unangenehmste aller Fehler — sie sieht
			// richtig aus.
			p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
				Feld: "domains", Wert: name,
				Grund: "Diesen Namen führt bereits ein anderer Serverblock (" + datei +
					"). Zwei Blöcke für denselben Namen: nginx nimmt den ersten, und welcher das ist, hängt an der Lesereihenfolge.",
			})
		}
	}
}

// pruefeZiel prüft Zielart und Ziel.
func (p *SitePruefung) pruefeZiel(e SiteEntwurf, lage SiteLage) {
	switch e.Zielart {
	case "proxy":
		p.pruefeProxyziel(e.Ziel, lage)
	case "statisch":
		p.pruefeVerzeichnis(e.Ziel, lage)
	case "php":
		// Beides prüfen: Das Verzeichnis nach denselben Regeln wie eine
		// statische Site — es ist eine —, und dazu der Socket.
		p.pruefeVerzeichnis(e.Ziel, lage)
		p.pruefeSocket(e.PHPSocket)
	case "umleitung":
		p.pruefeUmleitung(e.Ziel)
	default:
		// Abgelehnt UND als ungeprüft vermerkt. Beides: Die Ablehnung
		// verhindert den Schreibvorgang, der Vermerk sagt dem nächsten, der
		// eine Zielart hinzufügt, dass der Prüfer sie nicht kennt.
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "zielart", Wert: kuerzen(e.Zielart),
			Grund: "Diese Zielart kennt das Panel nicht.",
		})
		p.Ungeprueft = append(p.Ungeprueft,
			"Zielart "+kuerzen(e.Zielart)+" — der Prüfer legt sie nicht aus, deshalb gilt sie als ungeprüft und nicht als in Ordnung.")
	}
}

// pruefeProxyziel prüft die Gegenstelle eines Reverse-Proxy.
func (p *SitePruefung) pruefeProxyziel(ziel string, lage SiteLage) {
	ziel = strings.TrimSpace(ziel)
	u, err := url.Parse(ziel)
	switch {
	case ziel == "":
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "ziel", Grund: "Ohne Gegenstelle gibt es nichts weiterzureichen.",
		})
		return
	case err != nil:
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "ziel", Wert: kuerzen(ziel), Grund: "Das ist keine gültige Adresse: " + err.Error(),
		})
		return
	case u.Scheme != "http" && u.Scheme != "https":
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "ziel", Wert: kuerzen(ziel),
			Grund: "Die Gegenstelle muss mit http:// oder https:// beginnen.",
		})
		return
	case u.Host == "":
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "ziel", Wert: kuerzen(ziel), Grund: "In der Adresse fehlt der Rechner.",
		})
		return
	}

	// Alles hinter dem Pfad ist bei proxy_pass keine Zierde, sondern ändert die
	// Bedeutung — und eine Abfrage oder ein Fragment gehört nie dorthin.
	if u.RawQuery != "" || u.Fragment != "" || u.User != nil {
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "ziel", Wert: kuerzen(ziel),
			Grund: "Die Gegenstelle darf keine Abfrage, kein Fragment und keine Zugangsdaten enthalten.",
		})
		return
	}
	// Zeichen, die bei nginx eine Anweisung beenden würden. url.Parse nimmt sie
	// klaglos an — die Prüfung darauf ist deshalb keine Wiederholung.
	if strings.ContainsAny(ziel, ";{}\n\r \t\"'\\") {
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "ziel", Wert: kuerzen(ziel),
			Grund: "Die Adresse enthält ein Zeichen, das in der Konfiguration eine eigene Anweisung beginnen würde.",
		})
		return
	}

	if zeigtAufDasPanel(u, lage.PanelPort) {
		// Zwei Gründe, und der zweite wiegt schwerer: Es wäre eine Schleife —
		// und ein Proxy vor dem Panel umgeht dessen Herkunftsprüfung, weil
		// jede Anfrage dann von 127.0.0.1 zu kommen scheint.
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "ziel", Wert: kuerzen(ziel),
			Grund: "Diese Adresse zeigt auf das Panel selbst. Ein Proxy davor umgeht dessen Herkunftsprüfung und legt eine Schleife.",
		})
	}
}

// pruefeVerzeichnis prüft die Wurzel einer statischen Site.
func (p *SitePruefung) pruefeVerzeichnis(ziel string, lage SiteLage) {
	ziel = strings.TrimSpace(ziel)
	if ziel == "" {
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "ziel", Grund: "Ohne Verzeichnis gibt es nichts auszuliefern.",
		})
		return
	}
	if !filepath.IsAbs(ziel) {
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "ziel", Wert: kuerzen(ziel),
			Grund: "Das Verzeichnis muss ein absoluter Pfad sein.",
		})
		return
	}
	if strings.ContainsAny(ziel, ";{}\n\r\"'\\") {
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "ziel", Wert: kuerzen(ziel),
			Grund: "Der Pfad enthält ein Zeichen, das in der Konfiguration eine eigene Anweisung beginnen würde.",
		})
		return
	}

	sauber := filepath.Clean(ziel)
	gesperrt := append(append([]string{}, gesperrteWurzeln...), lage.GesperrtePfade...)
	for _, wurzel := range gesperrt {
		if !liegtUnter(sauber, wurzel) {
			continue
		}
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "ziel", Wert: sauber,
			Grund: "Aus " + wurzel + " liefert das Panel nichts aus. Eine Site auf diesem Pfad veröffentlichte Teile des Servers im Netz.",
		})
		return
	}

	for _, wurzel := range UeblicheWurzeln {
		if liegtUnter(sauber, wurzel) {
			return
		}
	}
	// Kein Fehler, aber der Weg, über den eine Site fremde Daten ausliefert —
	// dasselbe Muster wie der Bind-Mount nach draußen beim Compose-Prüfer.
	p.Warnungen = append(p.Warnungen, SiteBefund{
		Feld: "ziel", Wert: sauber,
		Grund: "Dieses Verzeichnis liegt außerhalb von " + strings.Join(UeblicheWurzeln, " und ") +
			". Alles darin wird im Netz ausgeliefert.",
	})
}

// phpSocketWurzeln sind die Verzeichnisse, in denen ein FPM-Socket liegen darf.
//
// Eine Allowlist und keine Formprüfung: Der Pfad landet hinter `fastcgi_pass
// unix:` und sagt nginx, an wen es die Anfrage samt Kopfzeilen weiterreicht. Ein
// beliebiger Pfad wäre die Erlaubnis, jeden Unix-Socket des Servers mit
// FastCGI-Verkehr zu beschicken — und FastCGI ist ein Protokoll, das
// Umgebungsvariablen setzt. Unter /run liegen die Sockets der Dienste, und dort
// gehört diese Erlaubnis hin.
var phpSocketWurzeln = []string{"/run/php", "/var/run/php", "/run/php-fpm"}

// pruefeSocket prüft den FPM-Socket.
func (p *SitePruefung) pruefeSocket(pfad string) {
	pfad = strings.TrimSpace(pfad)
	if pfad == "" {
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld:  "php_socket",
			Grund: "Ohne FPM-Socket führt niemand die PHP-Dateien aus; nginx lieferte sie im Klartext aus.",
		})
		return
	}
	if strings.ContainsAny(pfad, ";{}\n\r \t\"'\\") || !filepath.IsAbs(pfad) {
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "php_socket", Wert: kuerzen(pfad),
			Grund: "Das muss ein absoluter Pfad ohne Sonderzeichen sein.",
		})
		return
	}
	sauber := filepath.Clean(pfad)
	if !strings.HasSuffix(sauber, ".sock") {
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "php_socket", Wert: sauber,
			Grund: "Ein FPM-Socket endet auf .sock.",
		})
		return
	}
	for _, wurzel := range phpSocketWurzeln {
		if liegtUnter(sauber, wurzel) {
			return
		}
	}
	p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
		Feld: "php_socket", Wert: sauber,
		Grund: "Ein FPM-Socket liegt unter " + strings.Join(phpSocketWurzeln, ", ") +
			". Ein Pfad daneben wäre die Erlaubnis, einen beliebigen Socket des Servers mit FastCGI-Verkehr zu beschicken.",
	})
}

// pruefeUmleitung prüft das Ziel einer Umleitung.
func (p *SitePruefung) pruefeUmleitung(ziel string) {
	ziel = strings.TrimSpace(ziel)
	if ziel == "" {
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "ziel", Grund: "Ohne Ziel gibt es nichts, wohin umgeleitet werden könnte.",
		})
		return
	}
	u, err := url.Parse(ziel)
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "ziel", Wert: kuerzen(ziel),
			Grund: "Das Umleitungsziel muss eine vollständige Adresse mit http:// oder https:// sein.",
		})
		return
	}
	if strings.ContainsAny(ziel, ";{}\n\r \t\"'\\") {
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld: "ziel", Wert: kuerzen(ziel),
			Grund: "Die Adresse enthält ein Zeichen, das in der Konfiguration eine eigene Anweisung beginnen würde.",
		})
	}
}

// pruefeTLS prüft die Zertifikatsangaben und den Port.
func (p *SitePruefung) pruefeTLS(e SiteEntwurf, lage SiteLage) {
	// Der Selbstausschluss. Eine Site auf dem Port des Panels nimmt nginx den
	// Port weg oder umgekehrt — und wer es merkt, merkt es an der Oberfläche,
	// die er dafür bräuchte.
	for _, port := range entwurfsPorts(e) {
		if port == lage.PanelPort {
			p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
				Feld: "tls", Wert: strconv.Itoa(port),
				Grund: "Auf diesem Port antwortet das Panel selbst. Eine Site dort nähme die Oberfläche vom Netz, mit der sie sich zurücknehmen ließe.",
			})
		}
	}

	if e.HTTPUmleitung && !e.TLS {
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld:  "http_umleitung",
			Grund: "Eine Umleitung nach https ohne TLS führt auf eine Site, die es nicht gibt.",
		})
	}

	if !e.TLS {
		return
	}
	// Leere Pfade sind erlaubt und heißen: Das Zertifikat ist noch nicht
	// bezogen. Die Site entsteht dann ohne 443 (siehe erzeugeSite) statt mit
	// einem ssl_certificate, das ins Leere zeigt — nginx startet damit nicht.
	for feld, pfad := range map[string]string{
		"zertifikat": e.Zertifikat,
		"schluessel": e.Schluessel,
	} {
		if pfad == "" {
			continue
		}
		if !filepath.IsAbs(pfad) || strings.ContainsAny(pfad, ";{}\n\r \t\"'\\") {
			p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
				Feld: feld, Wert: kuerzen(pfad),
				Grund: "Das muss ein absoluter Pfad ohne Sonderzeichen sein.",
			})
		}
	}
	if (e.Zertifikat == "") != (e.Schluessel == "") {
		p.Ablehnungen = append(p.Ablehnungen, SiteBefund{
			Feld:  "zertifikat",
			Grund: "Zertifikat und Schlüssel gehören zusammen — entweder beide oder keins.",
		})
	}
}

// entwurfsPorts nennt die Ports, auf denen dieser Entwurf lauschen würde.
func entwurfsPorts(e SiteEntwurf) []int {
	ports := []int{80}
	if e.TLS && e.Zertifikat != "" && e.Schluessel != "" {
		ports = append(ports, 443)
	}
	return ports
}

// zeigtAufDasPanel sagt, ob diese Gegenstelle das Panel selbst ist.
//
// Geprüft wird auf den Port UND auf eine lokale Adresse. Nur der Port wäre zu
// scharf (ein anderer Rechner darf denselben Port benutzen), nur die Adresse zu
// lasch (auf 127.0.0.1 laufen die meisten Anwendungen, die man hinter einen
// Proxy stellt — genau dafür ist das Modul da).
func zeigtAufDasPanel(u *url.URL, panelPort int) bool {
	if panelPort == 0 {
		return false
	}
	host := u.Hostname()
	port := u.Port()
	if port == "" {
		if u.Scheme == "https" {
			port = "443"
		} else {
			port = "80"
		}
	}
	if port != strconv.Itoa(panelPort) {
		return false
	}
	if host == "localhost" {
		return true
	}
	ip := net.ParseIP(host)
	return ip != nil && (ip.IsLoopback() || ip.IsUnspecified())
}

// liegtUnter sagt, ob pfad das Verzeichnis wurzel ist oder darunter liegt.
//
// Über die Pfadbestandteile und nicht über strings.HasPrefix: Sonst läge
// /etc-eigenes unter /etc, und /var/wwwroot unter /var/www. Beides wäre eine
// falsche Antwort in der jeweils unangenehmen Richtung.
func liegtUnter(pfad, wurzel string) bool {
	pfad = filepath.Clean(pfad)
	wurzel = filepath.Clean(wurzel)
	if wurzel == "/" {
		// Alles liegt unter /. Gemeint ist hier: die Wurzel SELBST.
		return pfad == "/"
	}
	if pfad == wurzel {
		return true
	}
	return strings.HasPrefix(pfad, wurzel+string(filepath.Separator))
}

// erzeugeSite baut die Datei aus dem Entwurf.
//
// Sie wird NUR mit einem Entwurf aufgerufen, den PruefeSiteEntwurf angenommen
// hat. Diese Funktion prüft deshalb nichts mehr — täte sie es, gäbe es zwei
// Prüfungen, und die zweite wäre die, die niemand pflegt.
//
// Der Aufbau folgt der Vorlage, die auch das ACME-Drop-in benutzt: Marker in der
// ersten Zeile (daran erkennt das Panel seine eigenen Dateien wieder), dann ein
// Kommentarkopf, der erklärt, woher die Datei kommt. Der Kopf ist kein Beiwerk:
// Wer sie über den Dateimanager findet, soll dort lesen, dass Änderungen daran
// beim nächsten Speichern verloren gehen.
func erzeugeSite(e SiteEntwurf) string {
	namen := make([]string, 0, len(e.Domains))
	for _, d := range e.Domains {
		namen = append(namen, strings.ToLower(strings.TrimSpace(d)))
	}
	namenZeile := strings.Join(namen, " ")
	mitTLS := e.TLS && e.Zertifikat != "" && e.Schluessel != ""

	var b strings.Builder
	b.WriteString(nginxMarker + "\n")
	b.WriteString("#\n")
	b.WriteString("# Site \"" + e.Name + "\", angelegt über die Oberfläche des Panels.\n")
	b.WriteString("# Änderungen an dieser Datei gehen beim nächsten Speichern verloren.\n")
	b.WriteString("# Siehe docs/18-webserver.md.\n")

	// Der Block auf Port 80. Er steht immer da — auch mit TLS, denn ohne ihn
	// beantwortet niemand die ACME-Prüfung und eine http-Anfrage landet beim
	// Vorgabeblock eines anderen.
	b.WriteString("\nserver {\n")
	b.WriteString("    listen 80;\n")
	b.WriteString("    listen [::]:80;\n")
	b.WriteString("    server_name " + namenZeile + ";\n")

	if mitTLS && e.HTTPUmleitung {
		// Die Umleitung lässt /.well-known/acme-challenge/ ausdrücklich aus.
		// Ohne diese Ausnahme leitete der Block die Prüfung des nächsten
		// Erneuerungslaufs auf https um — und die Prüfstelle folgt zwar
		// Umleitungen, aber der Fehler fiele erst in sechzig Tagen auf.
		b.WriteString("\n")
		b.WriteString("    location ^~ /.well-known/acme-challenge/ {\n")
		b.WriteString("        root " + acmeWebrootDir + ";\n")
		b.WriteString("        default_type \"text/plain\";\n")
		b.WriteString("    }\n")
		b.WriteString("\n")
		b.WriteString("    location / {\n")
		b.WriteString("        return 308 https://$host$request_uri;\n")
		b.WriteString("    }\n")
		b.WriteString("}\n")
	} else {
		schreibeZiel(&b, e)
		b.WriteString("}\n")
	}

	if !mitTLS {
		return b.String()
	}

	b.WriteString("\nserver {\n")
	b.WriteString("    listen 443 ssl;\n")
	b.WriteString("    listen [::]:443 ssl;\n")
	b.WriteString("    http2 on;\n")
	b.WriteString("    server_name " + namenZeile + ";\n")
	b.WriteString("\n")
	b.WriteString("    ssl_certificate " + e.Zertifikat + ";\n")
	b.WriteString("    ssl_certificate_key " + e.Schluessel + ";\n")
	schreibeZiel(&b, e)
	b.WriteString("}\n")
	return b.String()
}

// schreibeZiel schreibt den Teil, der von der Zielart abhängt.
func schreibeZiel(b *strings.Builder, e SiteEntwurf) {
	b.WriteString("\n")
	switch e.Zielart {
	case "proxy":
		b.WriteString("    location / {\n")
		b.WriteString("        proxy_pass " + strings.TrimSpace(e.Ziel) + ";\n")
		// Die vier Kopfzeilen sind nicht Geschmack, sondern die Bedingung
		// dafür, dass die Anwendung dahinter überhaupt weiß, wer sie aufruft:
		// Ohne sie sieht sie als Gegenstelle nur den Webserver und als Schema
		// immer http — und baut ihre eigenen Verweise falsch.
		b.WriteString("        proxy_set_header Host $host;\n")
		b.WriteString("        proxy_set_header X-Real-IP $remote_addr;\n")
		b.WriteString("        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\n")
		b.WriteString("        proxy_set_header X-Forwarded-Proto $scheme;\n")
		b.WriteString("    }\n")
	case "statisch":
		b.WriteString("    root " + filepath.Clean(strings.TrimSpace(e.Ziel)) + ";\n")
		b.WriteString("\n")
		b.WriteString("    location / {\n")
		b.WriteString("        try_files $uri $uri/ =404;\n")
		b.WriteString("    }\n")
	case "php":
		b.WriteString("    root " + filepath.Clean(strings.TrimSpace(e.Ziel)) + ";\n")
		b.WriteString("    index index.php index.html;\n")
		b.WriteString("\n")
		b.WriteString("    location / {\n")
		b.WriteString("        try_files $uri $uri/ /index.php?$query_string;\n")
		b.WriteString("    }\n")
		b.WriteString("\n")
		b.WriteString("    location ~ \\.php$ {\n")
		// try_files ZUERST, und das ist keine Feinheit: Ohne diese Zeile
		// beantwortet nginx auch /bild.jpg/x.php, reicht die Datei an PHP
		// weiter, und PHP führt sie mit cgi.fix_pathinfo aus. Das ist die
		// klassische Lücke „nginx + php-fpm Remote Code Execution" — sie kommt
		// nicht von einem Fehler in nginx, sondern von einer Konfiguration ohne
		// diese Zeile.
		b.WriteString("        try_files $uri =404;\n")
		b.WriteString("        include fastcgi_params;\n")
		b.WriteString("        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\n")
		b.WriteString("        fastcgi_pass unix:" +
			filepath.Clean(strings.TrimSpace(e.PHPSocket)) + ";\n")
		b.WriteString("    }\n")
		b.WriteString("\n")
		// .htaccess und Geschwister gehören nicht ins Netz. Bei einer Anwendung,
		// die von Apache kommt, liegen sie im Verzeichnis — und stehen sonst
		// als Klartext zum Abruf bereit.
		b.WriteString("    location ~ /\\.ht {\n")
		b.WriteString("        deny all;\n")
		b.WriteString("    }\n")
	case "umleitung":
		b.WriteString("    location / {\n")
		// 308 und nicht 301: Ein 301 darf ein POST in ein GET verwandeln, und
		// das ist der Fehler, der bei einer umgezogenen Anwendung erst dem
		// auffällt, der ein Formular abschickt.
		b.WriteString("        return 308 " + strings.TrimSpace(e.Ziel) + "$request_uri;\n")
		b.WriteString("    }\n")
	}
}

// PHPSockets sucht die vorhandenen FPM-Sockets.
//
// Gesucht wird auf der PLATTE und nicht in einer Paketliste: Ein Socket, den es
// gibt, ist der Beleg dafür, dass ein FPM-Prozess läuft — ein installiertes
// Paket ist es nicht. Umgekehrt findet diese Suche auch eine Installation, die
// an apt vorbeigegangen ist.
//
// Sortiert, damit die Reihenfolge nicht am Dateisystem hängt: Bei zwei
// PHP-Fassungen nebeneinander soll die Auswahl zweimal gleich aussehen.
func PHPSockets() []string {
	var aus []string
	gesehen := map[string]bool{}
	for _, wurzel := range phpSocketWurzeln {
		treffer, err := filepath.Glob(filepath.Join(wurzel, "*.sock"))
		if err != nil {
			continue
		}
		for _, pfad := range treffer {
			sauber := filepath.Clean(pfad)
			if gesehen[sauber] {
				continue
			}
			// Ein Socket und keine gewöhnliche Datei: Wer eine Datei namens
			// x.sock dorthin legt, soll sie nicht in der Auswahl finden.
			fi, err := os.Stat(sauber)
			if err != nil || fi.Mode()&os.ModeSocket == 0 {
				continue
			}
			gesehen[sauber] = true
			aus = append(aus, sauber)
		}
	}
	sort.Strings(aus)
	return aus
}

// pruefeSiteDomain prüft einen Namen für server_name.
//
// Anders als pruefeDomain (das für ACME gilt) ist der Platzhalter hier erlaubt:
// `*.beispiel.de` ist gültige nginx-Syntax und ein üblicher Fall. Für die
// HTTP-01-Prüfung wäre er sinnlos, deshalb sind es zwei Funktionen und nicht
// eine mit einem Schalter.
func pruefeSiteDomain(name string) error {
	rest := name
	if strings.HasPrefix(rest, "*.") {
		rest = rest[2:]
		if !strings.Contains(rest, ".") {
			// `*.de` wäre ein Platzhalter über eine ganze Endung. nginx nähme
			// ihn an; er ist trotzdem nie gemeint.
			return fmt.Errorf("Platzhalter über eine ganze Endung: %q", kuerzen(name))
		}
	}
	if strings.Contains(rest, "*") {
		return fmt.Errorf("der Platzhalter steht nur als erster Bestandteil: %q", kuerzen(name))
	}
	return pruefeDomain(rest)
}
