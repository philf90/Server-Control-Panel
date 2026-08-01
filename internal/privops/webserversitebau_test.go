package privops

import (
	"strings"
	"testing"
)

// Die Tests des Site-Prüfers. Je ein Fall pro Ablehnungsgrund — und dazu die
// Umgehungsversuche, denn ein Prüfer wird nicht daran gemessen, dass er den
// geraden Fall durchlässt.

// gueltig ist der Entwurf, von dem alle Fälle ausgehen. Ohne ihn stünde in jedem
// Test die Frage, ob die Ablehnung wirklich aus dem geänderten Feld kommt.
func gueltig() SiteEntwurf {
	return SiteEntwurf{
		Name:    "shop",
		Domains: []string{"shop.example.com"},
		Zielart: "proxy",
		Ziel:    "http://127.0.0.1:3000",
	}
}

func lageOhneBesonderheit() SiteLage {
	return SiteLage{PanelPort: 8443}
}

// gruende sammelt die Felder der Ablehnungen — die Prüfung soll sagen, WO der
// Fehler steckt, nicht nur DASS einer steckt.
func felder(befunde []SiteBefund) []string {
	var aus []string
	for _, b := range befunde {
		aus = append(aus, b.Feld)
	}
	return aus
}

func TestPruefeSiteEntwurfNimmtDenGeradenFallAn(t *testing.T) {
	p := PruefeSiteEntwurf(gueltig(), lageOhneBesonderheit())
	if !p.OK() {
		t.Fatalf("der gerade Fall wurde abgelehnt: %+v", p.Ablehnungen)
	}
	if len(p.Warnungen) != 0 {
		t.Errorf("unerwartete Warnung: %+v", p.Warnungen)
	}
}

// Die Injektionsstelle des Moduls. Der Name landet hinter server_name, und was
// hier durchkommt, ist eine zweite nginx-Anweisung — kein Tippfehler, sondern
// der ganze Angriff.
func TestPruefeSiteEntwurfLehntEingeschmuggelteAnweisungenAb(t *testing.T) {
	faelle := []string{
		"beispiel.de; root /;",
		"beispiel.de }",
		"beispiel.de{",
		"beispiel.de\nserver_name andere.de",
		"beispiel.de root /etc",
		"beispiel.de;",
	}
	for _, name := range faelle {
		e := gueltig()
		e.Domains = []string{name}
		p := PruefeSiteEntwurf(e, lageOhneBesonderheit())
		if p.OK() {
			t.Errorf("%q wurde angenommen — daraus würde eine zweite Anweisung in der Konfiguration", name)
		}
	}
}

// Der Platzhalter ist bei server_name erlaubt und für ACME nicht. Beide Regeln
// stehen in verschiedenen Funktionen, damit keine die andere aufweicht.
func TestPruefeSiteEntwurfPlatzhalter(t *testing.T) {
	faelle := []struct {
		name string
		gut  bool
		wozu string
	}{
		{"*.example.com", true, "der übliche Fall"},
		{"*.de", false, "ein Platzhalter über eine ganze Endung ist nie gemeint"},
		{"shop.*.example.com", false, "der Platzhalter steht nur vorn"},
		{"*", false, "ein Platzhalter über alles"},
		{"*example.com", false, "der Punkt fehlt — das ist kein Bestandteil"},
	}
	for _, f := range faelle {
		e := gueltig()
		e.Domains = []string{f.name}
		p := PruefeSiteEntwurf(e, lageOhneBesonderheit())
		if p.OK() != f.gut {
			t.Errorf("%q: angenommen = %v, erwartet %v (%s)", f.name, p.OK(), f.gut, f.wozu)
		}
	}
}

// Zwei Serverblöcke für denselben Namen sind kein Syntaxfehler — nginx nimmt den
// ersten, den es liest. Die Site, die dabei verliert, sieht richtig aus und
// antwortet nie.
func TestPruefeSiteEntwurfLehntBelegtenNamenAb(t *testing.T) {
	lage := lageOhneBesonderheit()
	lage.FremdeNamen = map[string]string{
		"shop.example.com": "/etc/nginx/sites-enabled/alt",
	}
	p := PruefeSiteEntwurf(gueltig(), lage)
	if p.OK() {
		t.Fatal("ein bereits vergebener Name wurde angenommen")
	}
	if !strings.Contains(p.Ablehnungen[0].Grund, "sites-enabled/alt") {
		t.Errorf("die Ablehnung nennt die andere Datei nicht: %q", p.Ablehnungen[0].Grund)
	}
}

func TestPruefeSiteEntwurfLehntDoppeltenNamenInDerselbenSiteAb(t *testing.T) {
	e := gueltig()
	e.Domains = []string{"shop.example.com", "shop.example.com"}
	if PruefeSiteEntwurf(e, lageOhneBesonderheit()).OK() {
		t.Error("derselbe Name zweimal wurde angenommen")
	}
}

func TestPruefeSiteEntwurfBrauchtEinenNamen(t *testing.T) {
	e := gueltig()
	e.Domains = nil
	p := PruefeSiteEntwurf(e, lageOhneBesonderheit())
	if p.OK() {
		t.Fatal("eine Site ohne Domainnamen wurde angenommen")
	}
	if felder(p.Ablehnungen)[0] != "domains" {
		t.Errorf("die Ablehnung zeigt auf %q, erwartet domains", felder(p.Ablehnungen)[0])
	}
}

// Ein Proxy auf das Panel wäre eine Schleife — und schlimmer: Er umginge die
// Herkunftsprüfung des Panels, weil danach jede Anfrage von 127.0.0.1 käme.
func TestPruefeSiteEntwurfLehntDenWegZumPanelAb(t *testing.T) {
	aufsPanel := []string{
		"http://127.0.0.1:8443",
		"http://localhost:8443",
		"http://[::1]:8443",
		"http://0.0.0.0:8443",
	}
	for _, ziel := range aufsPanel {
		e := gueltig()
		e.Ziel = ziel
		if PruefeSiteEntwurf(e, lageOhneBesonderheit()).OK() {
			t.Errorf("%q zeigt auf das Panel und wurde angenommen", ziel)
		}
	}

	// Die Gegenprobe, und sie ist die wichtigere: Ein anderer Dienst auf
	// derselben Adresse ist genau der Fall, für den es dieses Modul gibt. Eine
	// Prüfung, die nur auf 127.0.0.1 sieht, machte das Modul nutzlos.
	daneben := []string{
		"http://127.0.0.1:3000",
		"http://127.0.0.1:8080",
		"http://example.com:8443",
		"https://192.168.1.5:8443",
	}
	for _, ziel := range daneben {
		e := gueltig()
		e.Ziel = ziel
		if p := PruefeSiteEntwurf(e, lageOhneBesonderheit()); !p.OK() {
			t.Errorf("%q wurde abgelehnt: %+v", ziel, p.Ablehnungen)
		}
	}
}

func TestPruefeSiteEntwurfLehntUnbrauchbareGegenstellenAb(t *testing.T) {
	faelle := []string{
		"",
		"127.0.0.1:3000",                // ohne Schema
		"ftp://127.0.0.1:3000",          // falsches Schema
		"http://",                       // ohne Rechner
		"http://127.0.0.1:3000?a=b",     // Abfrage
		"http://127.0.0.1:3000#x",       // Fragment
		"http://n:p@127.0.0.1:3000",     // Zugangsdaten
		"http://127.0.0.1:3000; root /", // eingeschmuggelte Anweisung
		"http://127.0.0.1:3000 extra",
	}
	for _, ziel := range faelle {
		e := gueltig()
		e.Ziel = ziel
		if PruefeSiteEntwurf(e, lageOhneBesonderheit()).OK() {
			t.Errorf("%q wurde als Gegenstelle angenommen", ziel)
		}
	}
}

// Der Fall, der beim Compose-Prüfer dem Bind-Mount auf / entspricht: Eine Site
// mit root / veröffentlicht den ganzen Server im Netz.
func TestPruefeSiteEntwurfLehntGesperrteVerzeichnisseAb(t *testing.T) {
	gesperrt := []string{
		"/", "/etc", "/etc/nginx", "/root", "/root/.ssh",
		"/home", "/home/philipp", "/var/log", "/proc", "/dev",
		"/var/lib/asylum",
	}
	lage := lageOhneBesonderheit()
	lage.GesperrtePfade = []string{"/var/lib/asylum"}
	for _, pfad := range gesperrt {
		e := gueltig()
		e.Zielart, e.Ziel = "statisch", pfad
		if PruefeSiteEntwurf(e, lage).OK() {
			t.Errorf("root %q wurde angenommen — daraus würde ein Teil des Servers im Netz", pfad)
		}
	}
}

// Die Grenze von liegtUnter. Mit strings.HasPrefix läge /etc-eigenes unter /etc
// und /var/wwwroot unter /var/www — die erste Verwechslung sperrt einen
// zulässigen Pfad aus, die zweite lässt einen unbemerkt durch.
func TestPruefeSiteEntwurfVerwechseltKeineNachbarpfade(t *testing.T) {
	e := gueltig()
	e.Zielart, e.Ziel = "statisch", "/etc-eigenes/web"
	p := PruefeSiteEntwurf(e, lageOhneBesonderheit())
	if !p.OK() {
		t.Errorf("/etc-eigenes/web wurde als /etc gelesen: %+v", p.Ablehnungen)
	}
	if len(p.Warnungen) != 1 {
		t.Errorf("außerhalb der üblichen Wurzeln, aber keine Warnung: %+v", p.Warnungen)
	}

	e.Ziel = "/var/wwwroot"
	p = PruefeSiteEntwurf(e, lageOhneBesonderheit())
	if !p.OK() {
		t.Fatalf("/var/wwwroot wurde abgelehnt: %+v", p.Ablehnungen)
	}
	if len(p.Warnungen) != 1 {
		t.Error("/var/wwwroot wurde als /var/www gelesen und ging ohne Rückfrage durch")
	}
}

// Außerhalb der üblichen Wurzeln ist kein Fehler, sondern eine Rückfrage: Es ist
// der legitime und häufige Fall — und zugleich der Weg, über den eine Site
// fremde Daten ausliefert.
func TestPruefeSiteEntwurfWarntStattAbzulehnen(t *testing.T) {
	faelle := map[string]bool{
		"/var/www/shop": false,
		"/srv/shop":     false,
		"/opt/shop":     true,
		"/data/shop":    true,
	}
	for pfad, erwartetWarnung := range faelle {
		e := gueltig()
		e.Zielart, e.Ziel = "statisch", pfad
		p := PruefeSiteEntwurf(e, lageOhneBesonderheit())
		if !p.OK() {
			t.Errorf("%q wurde abgelehnt: %+v", pfad, p.Ablehnungen)
			continue
		}
		if (len(p.Warnungen) > 0) != erwartetWarnung {
			t.Errorf("%q: Warnung = %v, erwartet %v", pfad, len(p.Warnungen) > 0, erwartetWarnung)
		}
	}
}

func TestPruefeSiteEntwurfLehntRelativeUndVerbogenePfadeAb(t *testing.T) {
	faelle := []string{"", "var/www", "./web", "/var/www; root /etc", "/var/www\nroot /"}
	for _, pfad := range faelle {
		e := gueltig()
		e.Zielart, e.Ziel = "statisch", pfad
		if PruefeSiteEntwurf(e, lageOhneBesonderheit()).OK() {
			t.Errorf("%q wurde als Verzeichnis angenommen", pfad)
		}
	}
}

func TestPruefeSiteEntwurfUmleitung(t *testing.T) {
	gut := []string{"https://neu.example.com", "http://neu.example.com"}
	for _, ziel := range gut {
		e := gueltig()
		e.Zielart, e.Ziel = "umleitung", ziel
		if p := PruefeSiteEntwurf(e, lageOhneBesonderheit()); !p.OK() {
			t.Errorf("%q wurde abgelehnt: %+v", ziel, p.Ablehnungen)
		}
	}
	schlecht := []string{"", "neu.example.com", "/woanders", "https://neu.example.com; root /"}
	for _, ziel := range schlecht {
		e := gueltig()
		e.Zielart, e.Ziel = "umleitung", ziel
		if PruefeSiteEntwurf(e, lageOhneBesonderheit()).OK() {
			t.Errorf("%q wurde als Umleitungsziel angenommen", ziel)
		}
	}
}

// Was der Prüfer nicht kennt, ist nicht in Ordnung — es ist ungeprüft. Dieselbe
// Haltung wie bei ConfigCheckResult.Checked.
func TestPruefeSiteEntwurfUnbekannteZielartGiltAlsUngeprueft(t *testing.T) {
	e := gueltig()
	e.Zielart = "fastcgi"
	p := PruefeSiteEntwurf(e, lageOhneBesonderheit())
	if p.OK() {
		t.Error("eine unbekannte Zielart wurde angenommen")
	}
	if len(p.Ungeprueft) == 0 {
		t.Error("eine unbekannte Zielart wurde abgelehnt, aber nicht als ungeprüft vermerkt — " +
			"der nächste, der eine hinzufügt, sähe die Lücke nicht")
	}
}

// Der Selbstausschluss: Eine Site auf dem Port des Panels nimmt die Oberfläche
// vom Netz, mit der man sie zurücknehmen müsste.
func TestPruefeSiteEntwurfLehntDenPanelPortAb(t *testing.T) {
	e := gueltig()
	p := PruefeSiteEntwurf(e, SiteLage{PanelPort: 80})
	if p.OK() {
		t.Error("eine Site auf Port 80 wurde angenommen, obwohl das Panel dort antwortet")
	}

	// Mit TLS kommt 443 dazu — aber nur, wenn auch ein Zertifikat dasteht.
	e.TLS = true
	e.Zertifikat, e.Schluessel = "/etc/ssl/c.pem", "/etc/ssl/k.pem"
	if PruefeSiteEntwurf(e, SiteLage{PanelPort: 443}).OK() {
		t.Error("eine Site auf Port 443 wurde angenommen, obwohl das Panel dort antwortet")
	}
	// Ohne Zertifikat entsteht kein 443-Block, also gibt es auch keinen Streit.
	e.Zertifikat, e.Schluessel = "", ""
	if p := PruefeSiteEntwurf(e, SiteLage{PanelPort: 443}); !p.OK() {
		t.Errorf("ohne Zertifikat entsteht kein 443-Block, trotzdem abgelehnt: %+v", p.Ablehnungen)
	}
}

func TestPruefeSiteEntwurfTLSAngaben(t *testing.T) {
	e := gueltig()
	e.TLS = true
	e.Zertifikat = "/etc/ssl/c.pem"
	if PruefeSiteEntwurf(e, lageOhneBesonderheit()).OK() {
		t.Error("Zertifikat ohne Schlüssel wurde angenommen")
	}

	e.Schluessel = "relativ/k.pem"
	if PruefeSiteEntwurf(e, lageOhneBesonderheit()).OK() {
		t.Error("ein relativer Schlüsselpfad wurde angenommen")
	}

	// Eine Umleitung nach https ohne TLS führt auf eine Site, die es nicht gibt.
	e = gueltig()
	e.HTTPUmleitung = true
	if PruefeSiteEntwurf(e, lageOhneBesonderheit()).OK() {
		t.Error("Umleitung nach https ohne TLS wurde angenommen")
	}
}

func TestPruefeSiteEntwurfPrueftDenNamen(t *testing.T) {
	for _, name := range []string{"", "../weg", "mit leerzeichen", "Groß"} {
		e := gueltig()
		e.Name = name
		if PruefeSiteEntwurf(e, lageOhneBesonderheit()).OK() {
			t.Errorf("die Kennung %q wurde angenommen — sie bestimmt den Dateinamen", name)
		}
	}
}

// ------------------------------------------------------------- Der Erzeuger ---

func TestErzeugeSiteProxyOhneTLS(t *testing.T) {
	aus := erzeugeSite(gueltig())

	if !strings.HasPrefix(aus, nginxMarker) {
		t.Error("der Marker fehlt in der ersten Zeile — das Panel erkennt seine eigene Datei daran")
	}
	for _, muss := range []string{
		"listen 80;", "listen [::]:80;",
		"server_name shop.example.com;",
		"proxy_pass http://127.0.0.1:3000;",
		"proxy_set_header X-Forwarded-Proto $scheme;",
	} {
		if !strings.Contains(aus, muss) {
			t.Errorf("%q fehlt in der erzeugten Datei:\n%s", muss, aus)
		}
	}
	if strings.Contains(aus, "listen 443") {
		t.Error("ohne TLS entsteht ein 443-Block")
	}
}

// Ohne Zertifikat entsteht KEIN 443-Block, auch wenn TLS gewünscht ist: Ein
// ssl_certificate, das ins Leere zeigt, lässt nginx gar nicht erst starten — und
// dann ist nicht nur diese Site weg, sondern jede.
func TestErzeugeSiteOhneZertifikatKeinTLSBlock(t *testing.T) {
	e := gueltig()
	e.TLS = true
	if strings.Contains(erzeugeSite(e), "ssl_certificate") {
		t.Error("ohne Zertifikatspfad entsteht ein ssl_certificate ins Leere")
	}
}

// Die Ausnahme, die man vergisst: Leitet Port 80 alles auf https um, leitet er
// auch die ACME-Prüfung um — und der Fehler fällt erst beim Erneuern auf, also
// in sechzig Tagen.
func TestErzeugeSiteUmleitungLaesstDieACMEPruefungDurch(t *testing.T) {
	e := gueltig()
	e.TLS = true
	e.Zertifikat, e.Schluessel = "/etc/ssl/c.pem", "/etc/ssl/k.pem"
	e.HTTPUmleitung = true
	aus := erzeugeSite(e)

	if !strings.Contains(aus, "/.well-known/acme-challenge/") {
		t.Fatal("die Umleitung lässt die ACME-Prüfung nicht durch — die Erneuerung " +
			"schlüge fehl, und zwar erst in sechzig Tagen")
	}
	// Die Ausnahme muss VOR der Umleitung stehen; danach wäre sie wirkungslos,
	// weil location ^~ zwar vorrangig ist, die Reihenfolge im Text aber lesbar
	// dieselbe Aussage tragen soll.
	if strings.Index(aus, "acme-challenge") > strings.Index(aus, "return 308") {
		t.Error("die Ausnahme steht hinter der Umleitung")
	}
	if !strings.Contains(aus, "return 308 https://$host$request_uri;") {
		t.Errorf("die Umleitung fehlt:\n%s", aus)
	}
	if !strings.Contains(aus, "ssl_certificate /etc/ssl/c.pem;") {
		t.Errorf("der 443-Block fehlt:\n%s", aus)
	}
}

func TestErzeugeSiteStatischUndUmleitung(t *testing.T) {
	e := gueltig()
	e.Zielart, e.Ziel = "statisch", "/var/www/shop/"
	aus := erzeugeSite(e)
	if !strings.Contains(aus, "root /var/www/shop;") {
		t.Errorf("die Wurzel fehlt oder ist nicht geglättet:\n%s", aus)
	}
	if !strings.Contains(aus, "try_files $uri $uri/ =404;") {
		t.Errorf("try_files fehlt:\n%s", aus)
	}

	e.Zielart, e.Ziel = "umleitung", "https://neu.example.com"
	aus = erzeugeSite(e)
	if !strings.Contains(aus, "return 308 https://neu.example.com$request_uri;") {
		t.Errorf("die Umleitung fehlt oder verliert den Pfad:\n%s", aus)
	}
}

// Mehrere Namen stehen in einer server_name-Zeile, kleingeschrieben. nginx
// vergleicht Namen ohne Rücksicht auf Groß- und Kleinschreibung; die Datei
// gleich zu schreiben erspart die Frage, ob zwei Schreibweisen dieselbe Site
// sind.
func TestErzeugeSiteSchreibtNamenKlein(t *testing.T) {
	e := gueltig()
	e.Domains = []string{"Shop.Example.COM", "www.shop.example.com"}
	aus := erzeugeSite(e)
	if !strings.Contains(aus, "server_name shop.example.com www.shop.example.com;") {
		t.Errorf("die Namenszeile stimmt nicht:\n%s", aus)
	}
}

// -------------------------------------------------------------- PHP-FPM ---

// PHP braucht ZWEI Angaben: ein Verzeichnis und einen Prozess. Fehlt der
// Socket, liefert nginx die .php-Dateien als Klartext aus — mit allem, was an
// Zugangsdaten darin steht.
func TestPruefeSiteEntwurfPHPBrauchtBeides(t *testing.T) {
	e := gueltig()
	e.Zielart, e.Ziel = "php", "/var/www/shop"
	if PruefeSiteEntwurf(e, lageOhneBesonderheit()).OK() {
		t.Error("php ohne Socket wurde angenommen — nginx lieferte die Quelltexte aus")
	}

	e.PHPSocket = "/run/php/php8.2-fpm.sock"
	if p := PruefeSiteEntwurf(e, lageOhneBesonderheit()); !p.OK() {
		t.Errorf("der gerade PHP-Fall wurde abgelehnt: %+v", p.Ablehnungen)
	}

	// Das Verzeichnis gilt bei php genauso wie bei statisch.
	e.Ziel = "/etc"
	if PruefeSiteEntwurf(e, lageOhneBesonderheit()).OK() {
		t.Error("php mit root /etc wurde angenommen")
	}
}

// Der Socket liegt unter /run — ein Pfad daneben wäre die Erlaubnis, einen
// beliebigen Unix-Socket des Servers mit FastCGI-Verkehr zu beschicken.
func TestPruefeSiteEntwurfSocketAllowlist(t *testing.T) {
	faelle := map[string]bool{
		"/run/php/php8.2-fpm.sock":     true,
		"/var/run/php/php8.1-fpm.sock": true,
		"/run/php-fpm/www.sock":        true,
		"/tmp/beliebig.sock":           false,
		"/run/docker.sock":             false,
		"/run/php/php8.2-fpm":          false, // ohne .sock
		"run/php/x.sock":               false, // relativ
		"/run/php/x.sock; root /":      false,
		"":                             false,
	}
	for pfad, gut := range faelle {
		e := gueltig()
		e.Zielart, e.Ziel, e.PHPSocket = "php", "/var/www/shop", pfad
		if got := PruefeSiteEntwurf(e, lageOhneBesonderheit()).OK(); got != gut {
			t.Errorf("%q: angenommen = %v, erwartet %v", pfad, got, gut)
		}
	}
}

// Die Zeile, wegen der es die klassische Lücke „nginx + php-fpm Remote Code
// Execution" gibt: Ohne try_files beantwortet nginx auch /bild.jpg/x.php und
// lässt PHP die hochgeladene Datei ausführen.
func TestErzeugeSitePHPSchliesstDiePathInfoLuecke(t *testing.T) {
	e := gueltig()
	e.Zielart, e.Ziel = "php", "/var/www/shop"
	e.PHPSocket = "/run/php/php8.2-fpm.sock"
	aus := erzeugeSite(e)

	php := strings.Index(aus, "location ~ \\.php$")
	if php < 0 {
		t.Fatalf("der PHP-Block fehlt:\n%s", aus)
	}
	pruef := strings.Index(aus[php:], "try_files $uri =404;")
	pass := strings.Index(aus[php:], "fastcgi_pass")
	if pruef < 0 {
		t.Fatal("try_files $uri =404 fehlt im PHP-Block — damit führte nginx " +
			"/bild.jpg/x.php an PHP weiter, und PHP führte die hochgeladene Datei aus")
	}
	if pruef > pass {
		t.Error("try_files steht hinter fastcgi_pass — die Prüfung käme zu spät")
	}
	for _, muss := range []string{
		"fastcgi_pass unix:/run/php/php8.2-fpm.sock;",
		"fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;",
		"root /var/www/shop;",
		"location ~ /\\.ht {",
	} {
		if !strings.Contains(aus, muss) {
			t.Errorf("%q fehlt:\n%s", muss, aus)
		}
	}
}
