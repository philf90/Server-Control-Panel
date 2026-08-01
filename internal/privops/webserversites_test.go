package privops

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Aufgezeichnete Form der Ausgabe von `nginx -T`.
//
// Derselbe Vorbehalt wie bei den Docker-Parsern und den DNS-Anbietern: Die Form
// stammt aus der Dokumentation und aus der Erinnerung an echte Systeme, nicht
// aus einem Mitschnitt. Was hier belastbar geprüft wird, ist die Zerlegung —
// dass aus einer solchen Ausgabe die richtigen Blöcke werden.
//
// Enthalten sind absichtlich die Fälle, an denen ein naiver Parser scheitert:
//
//   - `server_name` und `server_tokens` beginnen mit „server", sind aber keine
//     Blöcke.
//   - Ein `upstream`-Block enthält das Wort „server" als ANWEISUNG.
//   - `proxy_pass` steht in einem verschachtelten `location`-Block.
//   - Ein `add_header` enthält geschweifte Klammern IN einer Zeichenkette.
//   - Ein Kommentar enthält eine schließende Klammer.
const nginxDumpOut = `# configuration file /etc/nginx/nginx.conf:
user www-data;
worker_processes auto;
http {
    server_tokens off;
    include /etc/nginx/conf.d/*.conf;
    include /etc/nginx/sites-enabled/*;
}

# configuration file /etc/nginx/sites-enabled/default:
upstream hinten {
    server 127.0.0.1:3000;
    server 127.0.0.1:3001;
}

server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    root /var/www/html;
}

# configuration file /etc/nginx/sites-enabled/shop:
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name shop.example.com www.shop.example.com;
    ssl_certificate /etc/letsencrypt/live/shop.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/shop.example.com/privkey.pem;

    # Diese Zeile enthält eine schließende Klammer } und darf nichts kippen.
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' {nonce}";

    location / {
        proxy_pass http://hinten;
        proxy_set_header Host $host;
    }
}

# configuration file /etc/nginx/conf.d/umleitung.conf:
server {
    listen 80;
    server_name alt.example.com;
    return 301 https://neu.example.com$request_uri;
}
`

func TestParseNginxDumpFindetDieBloecke(t *testing.T) {
	sites := parseNginxDump(nginxDumpOut)

	if len(sites) != 3 {
		t.Fatalf("%d Serverblöcke, erwartet 3: %+v", len(sites), sites)
	}

	// 1. Der Vorgabeblock: kein echter Name, statisches Ziel.
	//
	// „_" ist bei nginx der Platzhalter für „kein Name" — ihn als Domain
	// anzuzeigen wäre eine erfundene Auskunft.
	vorgabe := sites[0]
	if len(vorgabe.Domains) != 0 {
		t.Errorf("der Platzhalter _ wurde als Domain gelesen: %+v", vorgabe.Domains)
	}
	if vorgabe.Zielart != "statisch" || vorgabe.Ziel != "/var/www/html" {
		t.Errorf("Ziel falsch: %q / %q", vorgabe.Zielart, vorgabe.Ziel)
	}
	if len(vorgabe.Ports) != 1 || vorgabe.Ports[0] != 80 {
		t.Errorf("Ports = %v, erwartet [80] (IPv4 und IPv6 sind derselbe Port)", vorgabe.Ports)
	}
	if vorgabe.TLS {
		t.Error("der Vorgabeblock hat kein ssl")
	}

	// 2. Der Shop: zwei Namen, TLS, und das Ziel steht in einem
	//    VERSCHACHTELTEN location-Block. Wer die überspringt, findet bei jeder
	//    zweiten Site kein Ziel.
	var shop Site
	for _, s := range sites {
		if len(s.Domains) > 0 && s.Domains[0] == "shop.example.com" {
			shop = s
		}
	}
	if shop.Name == "" {
		t.Fatalf("der Shop-Block fehlt: %+v", sites)
	}
	if len(shop.Domains) != 2 || shop.Domains[1] != "www.shop.example.com" {
		t.Errorf("Domains = %v", shop.Domains)
	}
	if !shop.TLS || shop.Zertifikat == "" {
		t.Errorf("TLS nicht erkannt: %+v", shop)
	}
	if shop.Zielart != "proxy" || shop.Ziel != "http://hinten" {
		t.Errorf("das Ziel aus dem location-Block fehlt: %q / %q", shop.Zielart, shop.Ziel)
	}
	if len(shop.Ports) != 1 || shop.Ports[0] != 443 {
		t.Errorf("Ports = %v, erwartet [443]", shop.Ports)
	}

	// 3. Die Umleitung.
	var um Site
	for _, s := range sites {
		if s.Zielart == "umleitung" {
			um = s
		}
	}
	if um.Ziel != "https://neu.example.com$request_uri" {
		t.Errorf("Umleitungsziel = %q", um.Ziel)
	}
}

// Der Fall, an dem ein naiver Parser scheitert: `upstream` enthält das Wort
// „server" als ANWEISUNG, und `server_tokens` beginnt damit. Beides darf keinen
// Block eröffnen — sonst stünden in der Übersicht Einträge, die es nicht gibt.
func TestParseNginxDumpVerwechseltServerNichtMitServerbloecken(t *testing.T) {
	sites := parseNginxDump(nginxDumpOut)
	for _, s := range sites {
		if strings.Contains(s.Ziel, "3000") || strings.Contains(s.Ziel, "3001") {
			t.Errorf("ein upstream-Eintrag wurde als Site gelesen: %+v", s)
		}
	}
	// Und die Datei mit nur nginx.conf (server_tokens) darf gar keinen Block
	// beitragen.
	for _, s := range sites {
		if s.Datei == "/etc/nginx/nginx.conf" {
			t.Errorf("aus nginx.conf kam ein Block: %+v", s)
		}
	}
}

// Die Herkunft muss stimmen: An ihr hängt, ob eine Site verwaltet ist und wo
// sie zu ändern wäre.
func TestParseNginxDumpMerktSichDieDatei(t *testing.T) {
	sites := parseNginxDump(nginxDumpOut)
	gefunden := map[string]int{}
	for _, s := range sites {
		gefunden[s.Datei]++
	}
	for datei, anzahl := range map[string]int{
		"/etc/nginx/sites-enabled/default": 1,
		"/etc/nginx/sites-enabled/shop":    1,
		"/etc/nginx/conf.d/umleitung.conf": 1,
	} {
		if gefunden[datei] != anzahl {
			t.Errorf("%s: %d Blöcke, erwartet %d", datei, gefunden[datei], anzahl)
		}
	}
}

// Zeichenketten und Kommentare dürfen die Klammerzählung nicht kippen. Wer das
// übersieht, verliert ab der ersten solchen Zeile die Zählung — und damit alle
// folgenden Blöcke der Datei.
func TestParseNginxDumpZaehltKlammernRichtig(t *testing.T) {
	dump := `# configuration file /etc/nginx/sites-enabled/tricky:
server {
    server_name erste.example.com;
    add_header X-Test "hier } steht eine Klammer { in einer Zeichenkette";
    # und hier ein Kommentar mit }
    root /var/www/erste;
}
server {
    server_name zweite.example.com;
    root /var/www/zweite;
}
`
	sites := parseNginxDump(dump)
	if len(sites) != 2 {
		t.Fatalf("%d Blöcke, erwartet 2 — die Zählung ist an einer Zeichenkette "+
			"oder einem Kommentar gekippt: %+v", len(sites), sites)
	}
	if sites[1].Domains[0] != "zweite.example.com" {
		t.Errorf("der zweite Block ist falsch gelesen: %+v", sites[1])
	}
}

// Ein Serverblock ohne server_name ist gültig — er bedient, was sonst nichts
// nimmt. Ihn wegzulassen hieße, eine Übersicht zu zeigen, in der ein
// vorhandener vHost fehlt.
func TestParseNginxDumpBehaeltBloeckeOhneNamen(t *testing.T) {
	sites := parseNginxDump(`# configuration file /etc/nginx/sites-enabled/default:
server {
    listen 8080;
    root /srv/leer;
}
`)
	if len(sites) != 1 {
		t.Fatalf("%d Blöcke, erwartet 1", len(sites))
	}
	if sites[0].Name == "" {
		t.Error("ein Block ohne Namen braucht trotzdem eine Bezeichnung in der Liste")
	}
	if sites[0].Anmerkung == "" {
		t.Error("dass der Block keinen server_name hat, gehört gesagt")
	}
}

func TestListenPort(t *testing.T) {
	for _, fall := range []struct {
		felder []string
		port   int
		ok     bool
	}{
		{[]string{"80"}, 80, true},
		{[]string{"443", "ssl"}, 443, true},
		{[]string{"[::]:443", "ssl"}, 443, true},
		{[]string{"0.0.0.0:8080"}, 8080, true},
		{[]string{"127.0.0.1:9000"}, 9000, true},
		{[]string{"unix:/var/run/nginx.sock"}, 0, false},
		{[]string{}, 0, false},
		{[]string{"keinport"}, 0, false},
		{[]string{"99999"}, 0, false},
	} {
		port, ok := listenPort(fall.felder)
		if ok != fall.ok || port != fall.port {
			t.Errorf("listenPort(%v) = %d,%v — erwartet %d,%v",
				fall.felder, port, ok, fall.port, fall.ok)
		}
	}
}

// „nicht lesbar" ist ein eigener Zustand und KEINE leere Liste.
//
// Eine leere Liste hieße „keine Sites", und die Fläche böte an, die erste
// anzulegen. Bei kaputter Konfiguration ist das die falsche Antwort — und
// nginx -T läuft genau dann nicht.
func TestSiteListMeldetKaputteKonfigurationAlsUngelesen(t *testing.T) {
	f := newFakeRunner()
	f.responses["nginx -T"] = Result{
		ExitCode: 1,
		Stderr:   "nginx: [emerg] unknown directive \"srver_name\" in /etc/nginx/conf.d/x.conf:3",
	}
	s := NewSystemWithRunner(f)

	bestand, err := s.SiteList(context.Background())
	if err != nil {
		t.Fatalf("SiteList: %v", err)
	}
	if bestand.Gelesen {
		t.Error("eine abgelehnte Konfiguration darf nicht als gelesen gelten")
	}
	if len(bestand.Sites) != 0 {
		t.Errorf("ohne Auskunft darf keine Site behauptet werden: %+v", bestand.Sites)
	}
	if !strings.Contains(bestand.Fehler, "srver_name") {
		t.Errorf("die Meldung von nginx fehlt: %q", bestand.Fehler)
	}
}

// Und ohne nginx überhaupt: ebenfalls „nicht gelesen", kein Fehlerabbruch.
func TestSiteListOhneNginx(t *testing.T) {
	f := newFakeRunner()
	f.errs["nginx"] = os.ErrNotExist
	s := NewSystemWithRunner(f)

	bestand, err := s.SiteList(context.Background())
	if err != nil {
		t.Fatalf("ein fehlendes nginx ist kein Fehlerfall: %v", err)
	}
	if bestand.Gelesen {
		t.Error("ohne nginx gibt es nichts zu lesen")
	}
}

// Verwaltet ist eine Site nur, wenn Ort UND Marker stimmen. Der Ort allein
// genügt nicht — jemand kann eine Datei so nennen.
func TestSiteListTrenntVerwaltetVonFremd(t *testing.T) {
	dropin := acmeVerzeichnisse(t)
	conf := filepath.Dir(dropin)

	eigen := filepath.Join(conf, "asylum-shop.conf")
	if err := os.WriteFile(eigen, []byte(nginxMarker+"\nserver { server_name shop.example.com; }\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	// Gleicher Ort, gleiches Namensmuster — aber ohne Marker.
	getarnt := filepath.Join(conf, "asylum-getarnt.conf")
	if err := os.WriteFile(getarnt, []byte("server { server_name getarnt.example.com; }\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	f := newFakeRunner()
	f.responses["nginx -T"] = Result{Stdout: "" +
		"# configuration file " + eigen + ":\nserver {\n server_name shop.example.com;\n}\n" +
		"# configuration file " + getarnt + ":\nserver {\n server_name getarnt.example.com;\n}\n" +
		"# configuration file /etc/nginx/sites-enabled/fremd:\nserver {\n server_name fremd.example.com;\n}\n",
	}
	s := NewSystemWithRunner(f)

	bestand, err := s.SiteList(context.Background())
	if err != nil {
		t.Fatalf("SiteList: %v", err)
	}
	if !bestand.Gelesen || len(bestand.Sites) != 3 {
		t.Fatalf("%d Sites gelesen: %+v", len(bestand.Sites), bestand.Sites)
	}

	nach := map[string]Site{}
	for _, si := range bestand.Sites {
		nach[si.Datei] = si
	}
	if !nach[eigen].Verwaltet {
		t.Error("die eigene Datei mit Marker gilt nicht als verwaltet")
	}
	// Der Name kommt bei verwalteten aus dem Dateinamen, nicht aus der Domain.
	if nach[eigen].Name != "shop" {
		t.Errorf("Name = %q, erwartet die Kennung aus dem Dateinamen", nach[eigen].Name)
	}
	if nach[getarnt].Verwaltet {
		t.Error("eine Datei ohne Marker gehört dem Panel nicht — auch nicht am " +
			"eigenen Platz und mit dem eigenen Namensmuster")
	}
	if nach["/etc/nginx/sites-enabled/fremd"].Verwaltet {
		t.Error("eine fremde Datei gilt als verwaltet")
	}
}

// SiteDatei liefert nur eigene Dateien. Eine fremde anzuzeigen wäre kein
// Sicherheitsproblem — der Dateimanager kann sie ohnehin —, aber es wäre die
// Zusage, sie auch bearbeiten zu können, und die gibt dieses Modul nicht.
func TestSiteDateiNurEigene(t *testing.T) {
	dropin := acmeVerzeichnisse(t)
	conf := filepath.Dir(dropin)
	s := NewSystemWithRunner(newFakeRunner())

	inhalt := nginxMarker + "\nserver { server_name shop.example.com; }\n"
	if err := os.WriteFile(filepath.Join(conf, "asylum-shop.conf"), []byte(inhalt), 0o644); err != nil {
		t.Fatal(err)
	}
	got, err := s.SiteDatei(context.Background(), "shop")
	if err != nil {
		t.Fatalf("SiteDatei: %v", err)
	}
	if got != inhalt {
		t.Errorf("Inhalt = %q", got)
	}

	// Ohne Marker: nicht unsere.
	if err := os.WriteFile(filepath.Join(conf, "asylum-fremd.conf"), []byte("server {}\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := s.SiteDatei(context.Background(), "fremd"); err == nil {
		t.Error("eine Datei ohne Marker darf nicht ausgeliefert werden")
	}

	// Und der Name wird geprüft, bevor er zu einem Pfad wird.
	for _, name := range []string{"../../etc/shadow", "a/b", "", "."} {
		if _, err := s.SiteDatei(context.Background(), name); err == nil {
			t.Errorf("der Name %q wurde angenommen", name)
		}
	}
}
