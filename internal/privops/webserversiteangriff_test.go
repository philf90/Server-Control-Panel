package privops

import (
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Der Angriffsdurchgang gegen den Site-Prüfer (Schritt 8 der Stufe 0.6,
// docs/18-webserver.md §11).
//
// Die Tests in webserversitebau_test.go prüfen, dass der Prüfer die Regeln
// befolgt, die er sich gibt. Diese hier prüfen etwas anderes: ob die Regeln
// REICHEN. Sie sind beim Bau entstanden, indem der Prüfer angegriffen wurde,
// und jeder von ihnen war zuerst rot.
//
// Was dabei herauskam, steht als Befund am jeweiligen Fall — und es ist keine
// Sammlung theoretischer Möglichkeiten: Vier von ihnen kamen durch.

// Befund 1: Die Pfadprüfung war eine Sperrliste aus sieben Zeichen. NUL,
// Tabulator, Leerzeichen und die übrigen Steuerzeichen kamen durch.
//
// Kein einzelner davon reicht für eine eingeschmuggelte Anweisung — dafür
// bräuchte es ein Semikolon, und das war gesperrt. Aber ein Prüfer, der sich
// darauf verlässt, dass die zweite Linie (`nginx -t`) den Rest fängt, hat seine
// Aufgabe abgegeben. Und ein NUL in einer Konfigurationsdatei ist etwas, wovon
// niemand weiß, wie es gelesen wird.
func TestAngriffSteuerzeichenInPfaden(t *testing.T) {
	boese := []string{
		"/var/www/x\x00",
		"/var/www/x\x00; root /etc",
		// Mitten im Wert und nicht am Ende: Ein Steuerzeichen am RAND wird von
		// TrimSpace entfernt, bevor irgendetwas es sieht — und was geschrieben
		// wird, ist dann der getrimmte Wert. Das ist kein Loch, sondern die
		// richtige Behandlung; geprüft gehört der Fall in der Mitte.
		"/var/www/x\x0by",
		"/var/www/x\x0cy",
		"/var/www/x\x1b[2Jy",
		"/var/www/x\ty",
		"/var/www/x y",
		"/var/www/x #",
		"/var/www/x#kommentar",
		// Der Schreibrichtungs-Umschalter als Escape und nicht wörtlich: Ein
		// Quelltext, der ihn enthält, ist derselbe Angriff eine Ebene höher.
		"/var/www/\u202ex",
	}
	for _, pfad := range boese {
		e := gueltig()
		e.Zielart, e.Ziel = "statisch", pfad
		if PruefeSiteEntwurf(e, lageOhneBesonderheit()).OK() {
			t.Errorf("%q kam durch die Pfadprüfung", pfad)
		}
	}

	// Die Gegenprobe: Ein Pfad mit Umlauten ist zulässig und muss es bleiben.
	// Eine Allowlist über das ALPHABET eines Pfades wäre hier die falsche
	// Antwort — geprüft wird die Grammatik von nginx.
	e := gueltig()
	e.Zielart, e.Ziel = "statisch", "/var/www/münchen-2024"
	if p := PruefeSiteEntwurf(e, lageOhneBesonderheit()); !p.OK() {
		t.Errorf("ein Pfad mit Umlauten wurde abgelehnt: %+v", p.Ablehnungen)
	}
}

// Dieselbe Lücke am FPM-Socket und an den Zertifikatspfaden. Sie benutzten
// eigene Sperrlisten, und drei Sperrlisten für dasselbe Problem laufen
// auseinander.
func TestAngriffSteuerzeichenInSocketUndZertifikat(t *testing.T) {
	e := gueltig()
	e.Zielart, e.Ziel = "php", "/var/www/shop"
	e.PHPSocket = "/run/php/php8.2\x00-fpm.sock"
	if PruefeSiteEntwurf(e, lageOhneBesonderheit()).OK() {
		t.Error("ein NUL im Socketpfad kam durch")
	}

	e = gueltig()
	e.TLS = true
	e.Zertifikat = "/etc/ssl/c\x00.pem"
	e.Schluessel = "/etc/ssl/k.pem"
	if PruefeSiteEntwurf(e, lageOhneBesonderheit()).OK() {
		t.Error("ein NUL im Zertifikatspfad kam durch")
	}
}

// Befund 2: Ein Symlink umging die Sperrliste vollständig.
//
// `ln -s /etc /var/www/x` und dann `root /var/www/x`: Der Prüfer sah
// /var/www/x, nginx las /etc, und die Site veröffentlichte die Konfiguration des
// Servers im Netz. Das ist derselbe Angriff, gegen den der Dateimanager os.Root
// benutzt — nur dass hier ein Pfad geprüft wird, der noch gar nicht existieren
// muss.
func TestAngriffSymlinkAufGesperrtesVerzeichnis(t *testing.T) {
	wurzel := t.TempDir()
	ziel := filepath.Join(wurzel, "web")
	if err := os.Symlink("/etc", ziel); err != nil {
		t.Skipf("Symlinks nicht möglich: %v", err)
	}

	e := gueltig()
	e.Zielart, e.Ziel = "statisch", ziel
	p := PruefeSiteEntwurf(e, lageOhneBesonderheit())
	if p.OK() {
		t.Fatal("ein Symlink auf /etc kam durch — die Site hätte die Konfiguration " +
			"des Servers im Netz veröffentlicht")
	}
	// Und die Ablehnung sagt, dass ein Symlink im Spiel war: Sonst steht dort
	// ein Verzeichnis, dem man nicht ansieht, warum es abgelehnt wurde.
	if !strings.Contains(p.Ablehnungen[0].Grund, "Symlink") {
		t.Errorf("die Ablehnung erwähnt den Symlink nicht: %q", p.Ablehnungen[0].Grund)
	}
}

// Ein Symlink innerhalb der zulässigen Bereiche bleibt zulässig. Sonst wäre die
// Härtung eine Sperre gegen die übliche Bauart von Anwendungen, die ihre
// Releases symbolisch verlinken.
func TestAngriffSymlinkAufErlaubtesVerzeichnisBleibtErlaubt(t *testing.T) {
	wurzel := t.TempDir()
	echt := filepath.Join(wurzel, "release-3")
	if err := os.MkdirAll(echt, 0o755); err != nil {
		t.Fatal(err)
	}
	link := filepath.Join(wurzel, "aktuell")
	if err := os.Symlink(echt, link); err != nil {
		t.Skipf("Symlinks nicht möglich: %v", err)
	}

	e := gueltig()
	e.Zielart, e.Ziel = "statisch", link
	p := PruefeSiteEntwurf(e, lageOhneBesonderheit())
	if !p.OK() {
		t.Errorf("ein Symlink auf ein zulässiges Verzeichnis wurde abgelehnt: %+v",
			p.Ablehnungen)
	}
	// Er liegt außerhalb der üblichen Wurzeln, also gibt es die Rückfrage —
	// aber eben nur die.
	if len(p.Warnungen) != 1 {
		t.Errorf("erwartet genau die Rückfrage: %+v", p.Warnungen)
	}
}

// Befund 3: Die Zahl der Domains war unbegrenzt.
//
// Tausend server_name-Einträge ließen sich anlegen; abgelehnt hätte sie erst
// der erste Zertifikatsbezug — nach dem Schreiben, nach dem Reload, und mit
// einer Meldung von der Prüfstelle statt einer aus dem Formular.
func TestAngriffZuVieleDomains(t *testing.T) {
	e := gueltig()
	e.Domains = nil
	for i := 0; i < maxSiteDomains+1; i++ {
		e.Domains = append(e.Domains, "n"+strings.Repeat("x", i%20)+itoaTest(i)+".example.com")
	}
	p := PruefeSiteEntwurf(e, lageOhneBesonderheit())
	if p.OK() {
		t.Fatalf("%d Domains kamen durch", len(e.Domains))
	}
	if p.Ablehnungen[0].Feld != "domains" {
		t.Errorf("die Ablehnung zeigt auf %q", p.Ablehnungen[0].Feld)
	}
}

// Der Selbstausschluss, von der anderen Seite versucht: Die Site nimmt den Namen
// des Panels. Sie bekommt ihn — nginx kennt das Panel nicht —, aber der
// Zertifikatshalter gibt ihn nicht her. Diese Vorrangregel steht in
// internal/certs und ist dort geprüft; hier steht der Hinweis, damit niemand sie
// beim Umbau für überflüssig hält.
func TestAngriffPanelPortUeberJedenWeg(t *testing.T) {
	// Port 80 ist immer dabei — auch ohne TLS.
	e := gueltig()
	if PruefeSiteEntwurf(e, SiteLage{PanelPort: 80}).OK() {
		t.Error("eine Site auf dem Panel-Port 80 kam durch")
	}
	// Über TLS auf 443.
	e.TLS = true
	e.Zertifikat, e.Schluessel = "/etc/ssl/c.pem", "/etc/ssl/k.pem"
	if PruefeSiteEntwurf(e, SiteLage{PanelPort: 443}).OK() {
		t.Error("eine Site auf dem Panel-Port 443 kam durch")
	}
	// Und über den Proxy auf jede Schreibweise der lokalen Adresse.
	//
	// Befund 4, und der teuerste: `127.1` erreicht das Panel. Go liest es nicht
	// als IP-Adresse — ParseIP verlangt vier Bestandteile —, und nginx tut es
	// auch nicht: Es hält den Wert für einen Namen und lässt ihn auflösen.
	// getaddrinfo("127.1") ergibt 127.0.0.1, und damit stand ein Proxy vor dem
	// Panel, ohne dass die Prüfung es sah. Dieselbe Familie sind die dezimale,
	// die hexadezimale und die oktale Schreibweise.
	for _, ziel := range []string{
		"http://127.0.0.1:9999",
		"http://127.1:9999",
		"http://127.0.1:9999",
		"http://2130706433:9999",
		"http://0x7f000001:9999",
		"http://017700000001:9999",
		"http://[::1]:9999",
		"http://localhost:9999",
		"http://LOCALHOST:9999",
		"http://localhost.:9999",
		"http://0.0.0.0:9999",
		"http://0:9999",
		"https://127.0.0.1:9999",
	} {
		e := gueltig()
		e.Ziel = ziel
		if PruefeSiteEntwurf(e, SiteLage{PanelPort: 9999}).OK() {
			t.Errorf("%q zeigt auf das Panel und kam durch", ziel)
		}
	}

	// Die Gegenprobe, und sie wiegt schwer: Ein echter Rechnername auf demselben
	// Port ist der Normalfall dieses Moduls. Eine Regel, die ihn mitnimmt, machte
	// den Reverse-Proxy unbenutzbar.
	for _, ziel := range []string{
		"http://api.example.com:9999",
		"http://backend:9999",
		"http://web-api-1:9999",
		"http://192.168.1.5:9999",
		"http://10.0.0.7:9999",
	} {
		e := gueltig()
		e.Ziel = ziel
		if p := PruefeSiteEntwurf(e, SiteLage{PanelPort: 9999}); !p.OK() {
			t.Errorf("%q ist kein Weg zum Panel und wurde abgelehnt: %+v", ziel, p.Ablehnungen)
		}
	}
}

// Der Pfadausbruch über die Kennung. Sie wird zu einem Dateinamen UND zu einem
// Verzeichnisnamen für das Zertifikat — zwei Wege aus einem Feld.
func TestAngriffKennungAlsPfad(t *testing.T) {
	boese := []string{
		"../weg", "..", ".", "/etc/nginx/x", "a/b", "a\x00b",
		"..%2f..", "a b", "GROSS", "ümlaut", strings.Repeat("a", 64),
	}
	for _, name := range boese {
		if err := PruefeSiteName(name); err == nil {
			t.Errorf("die Kennung %q kam durch", name)
		}
	}
	for _, name := range []string{"shop", "shop-2", "shop_alt", "a", "x9"} {
		if err := PruefeSiteName(name); err != nil {
			t.Errorf("die zulässige Kennung %q wurde abgelehnt: %v", name, err)
		}
	}
}

// Der Erzeuger darf keinen Wert unverändert durchreichen, den der Prüfer
// abgelehnt hätte. Das ist die Gegenprobe zur Arbeitsteilung „Prüfer prüft die
// Felder, Erzeuger baut daraus Text": Sie trägt nur, solange niemand den
// Erzeuger ohne Prüfung aufruft — und dieser Test hält fest, was dann
// passierte.
func TestAngriffErzeugerOhnePruefungIstDerFehler(t *testing.T) {
	e := gueltig()
	e.Domains = []string{"beispiel.de; root /etc"}
	aus := erzeugeSite(e)

	// Der Erzeuger prüft NICHT — er baut, was man ihm gibt. Genau deshalb steht
	// über PruefeSiteEntwurf, dass es vorher laufen muss, und genau deshalb ruft
	// SiteApply beide in dieser Reihenfolge auf.
	if !strings.Contains(aus, "root /etc") {
		t.Skip("der Erzeuger filtert inzwischen selbst — dann gehört dieser Test umgeschrieben")
	}
	// Die eigentliche Zusicherung: Der Prüfer hätte es abgelehnt.
	if PruefeSiteEntwurf(e, lageOhneBesonderheit()).OK() {
		t.Fatal("der Prüfer nimmt an, was der Erzeuger ungefiltert einbaut — " +
			"das ist die Lücke, gegen die die ganze Arbeitsteilung steht")
	}
}

// itoaTest hält den Test ohne strconv-Import aus — er hat sonst keinen.
func itoaTest(n int) string {
	if n == 0 {
		return "0"
	}
	var b []byte
	for n > 0 {
		b = append([]byte{byte('0' + n%10)}, b...)
		n /= 10
	}
	return string(b)
}

// PHPSockets liest das Dateisystem — deshalb gehört der Test auf ein
// Wegwerfverzeichnis und nicht auf das der Maschine.
//
// Der Befund, der dazu geführt hat, kam aus dem ersten CI-Lauf dieser Stufe:
// Auf der Entwicklermaschine gab es kein php-fpm, auf dem Runner schon, und die
// Zielliste in httpd bekam vier Sockets, die kein Test vorgesehen hatte. Ein
// Test, der von der Maschine abhängt, auf der er läuft, ist kein Test — und die
// eigentliche Ursache war, dass httpd die Paketfunktion direkt aufrief, statt
// über den Executor zu gehen.
func TestPHPSocketsLiestNurSockets(t *testing.T) {
	wurzel := t.TempDir()
	alt := phpSocketWurzeln
	phpSocketWurzeln = []string{wurzel}
	t.Cleanup(func() { phpSocketWurzeln = alt })

	// Ein echter Socket, eine gewöhnliche Datei mit demselben Namensmuster und
	// eine Datei mit falscher Endung.
	ln, err := net.Listen("unix", filepath.Join(wurzel, "php8.2-fpm.sock"))
	if err != nil {
		t.Skipf("Unix-Sockets nicht möglich: %v", err)
	}
	t.Cleanup(func() { _ = ln.Close() })
	for _, name := range []string{"gefaelscht.sock", "php8.2-fpm.conf"} {
		if err := os.WriteFile(filepath.Join(wurzel, name), nil, 0o644); err != nil {
			t.Fatal(err)
		}
	}

	aus := PHPSockets()
	if len(aus) != 1 {
		t.Fatalf("PHPSockets = %v, erwartet genau den echten Socket", aus)
	}
	if !strings.HasSuffix(aus[0], "php8.2-fpm.sock") {
		t.Errorf("PHPSockets = %v", aus)
	}
}
