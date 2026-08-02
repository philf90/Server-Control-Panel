package privops

import (
	"context"
	"strings"
	"testing"
)

// Die Ausgabe stammt von einem echten Server (Ubuntu 24.04, asylumd aus dem
// .deb, Unit mit ProtectSystem=true) beim ersten Druck auf „nginx
// installieren". Sie ist gekürzt, aber nicht redigiert — Repo-Gewohnheit: Ein
// Parser gegen die eigene Vorstellung vom Format ist ein Parser, der gegen die
// eigene Vorstellung passt und gegen die Wirklichkeit nicht.
const aptSperreEcht = `Preparing to unpack .../nginx-common_1.24.0-2ubuntu7.15_all.deb ...
Unpacking nginx-common (1.24.0-2ubuntu7.15) ...
dpkg: error processing archive /var/cache/apt/archives/nginx-common_1.24.0-2ubuntu7.15_all.deb (--unpack):
 unable to create '/usr/lib/systemd/system/nginx.service.dpkg-new' (while processing './usr/lib/systemd/system/nginx.service'): Read-only file system
dpkg: error while cleaning up:
 unable to remove newly-extracted version of '/usr/lib/systemd/system/nginx.service': Read-only file system
Selecting previously unselected package nginx.
Preparing to unpack .../nginx_1.24.0-2ubuntu7.15_amd64.deb ...
Unpacking nginx (1.24.0-2ubuntu7.15) ...
dpkg: error processing archive /var/cache/apt/archives/nginx_1.24.0-2ubuntu7.15_amd64.deb (--unpack):
 unable to create '/usr/sbin/nginx.dpkg-new' (while processing './usr/sbin/nginx'): Read-only file system
dpkg: error while cleaning up:
 unable to remove newly-extracted version of '/usr/sbin/nginx': Read-only file system
dpkg-deb: error: paste subprocess was killed by signal (Broken pipe)
Errors were encountered while processing:
 /var/cache/apt/archives/nginx-common_1.24.0-2ubuntu7.15_all.deb
 /var/cache/apt/archives/nginx_1.24.0-2ubuntu7.15_amd64.deb
E: Sub-process /usr/bin/dpkg returned an error code (1)`

func TestAptSchreibsperreErkenntDenEchtenFall(t *testing.T) {
	if !aptSchreibsperre(aptSperreEcht) {
		t.Fatal("die Ausgabe des echten Fehlschlags wird nicht erkannt — dann steht " +
			"vor dem Bedienenden weiter ein dpkg-Dump ohne Grund")
	}
}

// Die Gegenprobe, und sie ist der eigentliche Punkt dieses Paars: Ein Hinweis,
// der bei JEDEM gescheiterten apt-Lauf erscheint, ist kein Hinweis, sondern
// Rauschen — und schickt beim nächsten Mal jemanden in die falsche Richtung.
func TestAptSchreibsperreSchweigtBeiAnderenFehlern(t *testing.T) {
	faelle := map[string]string{
		"Paket gibt es nicht": "E: Unable to locate package nosuchpkg",
		"Sperre belegt": "E: Could not get lock /var/lib/dpkg/lock-frontend. " +
			"It is held by process 4711 (apt-get)",
		"kein Platz": "dpkg: unrecoverable fatal error, aborting:\n " +
			"unable to write '/usr/sbin/nginx': No space left on device",
		"leer": "",
		// Der unbequeme Fall: Eine wirklich read-only eingehängte Platte
		// AUSSERHALB von /usr. Dort ist die Unit nicht schuld, und der Hinweis
		// wäre schlicht falsch.
		"fremde read-only-Einhängung": "unable to create '/srv/daten/x.dpkg-new': " +
			"Read-only file system",
	}
	for name, ausgabe := range faelle {
		if aptSchreibsperre(ausgabe) {
			t.Errorf("%s wird fälschlich als Härtung der eigenen Unit gedeutet: %q",
				name, ausgabe)
		}
	}
}

// Der Hinweis muss handlungsfähig machen. Ein Satz, der nur die Ursache nennt,
// verschiebt das Rätsel eine Ebene tiefer.
func TestAptSperrenHinweisNenntUrsacheUndAusweg(t *testing.T) {
	for _, muss := range []string{
		"ProtectSystem",        // die Ursache beim Namen
		"Kindprozess",          // warum apt davon betroffen ist
		"systemctl edit",       // der Handgriff …
		"ProtectSystem=no",     // … und was einzutragen ist
		"restart asylumd",      // damit es gilt
		"--fix-broken",         // und das Aufräumen danach
		"tauscht das Programm", // warum ein Update allein nicht genügt
	} {
		if !strings.Contains(aptSperrenHinweis, muss) {
			t.Errorf("der Hinweis nennt %q nicht: %s", muss, aptSperrenHinweis)
		}
	}
}

// Und der Weg durch die echte Operation: Was WebServerInstall zurückgibt, ist
// das, was in der Vorgangsanzeige steht.
func TestWebServerInstallNenntDieSchreibsperre(t *testing.T) {
	f := newFakeRunner()
	f.responses["apt-get"] = Result{ExitCode: 100, Stdout: aptSperreEcht}
	s := NewSystemWithRunner(f)

	err := s.WebServerInstall(context.Background(), nil)
	if err == nil {
		t.Fatal("ein apt-Lauf mit Code 100 muss ein Fehler sein")
	}
	if !strings.Contains(err.Error(), "ProtectSystem") {
		t.Errorf("die Meldung nennt die Ursache nicht: %v", err)
	}
	// Die knappe Zeile bleibt trotzdem stehen: Sie sagt, WELCHER Lauf gescheitert
	// ist, und der Hinweis sagt, woran.
	if !strings.Contains(err.Error(), "endete mit Code 100") {
		t.Errorf("die Meldung nennt den Lauf nicht mehr: %v", err)
	}
}

// Dasselbe für den Paketweg. Er ist der wichtigere von beiden: Updates
// einzuspielen ist kein Modul, das man weglassen kann.
func TestPackageUpgradeNenntDieSchreibsperre(t *testing.T) {
	f := newFakeRunner()
	f.responses["apt-get"] = Result{ExitCode: 100, Stdout: aptSperreEcht}
	s := NewSystemWithRunner(f)

	err := s.PackageUpgrade(context.Background(), UpgradeOptions{}, nil)
	if err == nil {
		t.Fatal("ein apt-Lauf mit Code 100 muss ein Fehler sein")
	}
	if !strings.Contains(err.Error(), "ProtectSystem") {
		t.Errorf("die Meldung nennt die Ursache nicht: %v", err)
	}
}

// Ein gescheiterter Lauf aus einem ANDEREN Grund bleibt knapp. Sonst stünde der
// Hinweis auf der Unit unter jedem Fehlschlag.
func TestAptFehlerBleibtOhneSperreKnapp(t *testing.T) {
	f := newFakeRunner()
	f.responses["apt-get"] = Result{ExitCode: 100, Stderr: "E: Unable to locate package nginx"}
	s := NewSystemWithRunner(f)

	err := s.WebServerInstall(context.Background(), nil)
	if err == nil {
		t.Fatal("Fehler erwartet")
	}
	if strings.Contains(err.Error(), "ProtectSystem") {
		t.Errorf("der Hinweis steht unter einem Fehlschlag, der nichts damit zu tun "+
			"hat: %v", err)
	}
}
