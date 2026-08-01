package httpd

// Die Auswahl des Challenge-Wegs nach dem Zustand des Servers.
//
// Der Befund dahinter steht in docs/18-webserver.md §3: Das Panel bindet für
// HTTP-01 selbst Port 80. Sobald es nginx einspielt — was es seit 0.6 anbietet
// —, gehört der Port nginx, und die Erneuerung des eigenen Zertifikats schlägt
// fehl. Nicht sofort und nicht sichtbar, sondern beim nächsten Lauf sechzig
// Tage später. Genau dagegen prüfen diese Tests.

import (
	"errors"
	"testing"

	"github.com/philf90/asylum/internal/privops"
)

func TestAcmeWebrootWirdBeiEigenemNginxGelegt(t *testing.T) {
	s, ops := newSystemServer(t)
	ops.web = privops.WebServerState{
		Installiert: true, Version: "1.24.0", DienstAktiv: true, LauscherGeprueft: true,
		Lauscher: []privops.Lauscher{
			{Port: 80, Adresse: "0.0.0.0", Prozess: "nginx", PID: 4711},
		},
	}
	ops.webroot = "/var/www/asylum-acme"

	dir := s.acmeWebroot(t.Context(), []string{"panel.example.com"})
	if dir != "/var/www/asylum-acme" {
		t.Fatalf("Webroot = %q — läuft nginx, muss die Prüfung durch nginx laufen", dir)
	}
	// Und für die richtigen Namen: Ein Drop-in mit dem falschen server_name
	// beantwortet die Prüfung nie.
	if len(ops.webrootDomains) != 1 || ops.webrootDomains[0][0] != "panel.example.com" {
		t.Errorf("die Namen kamen nicht durch: %+v", ops.webrootDomains)
	}
}

// Ohne nginx bleibt es beim eigenen Listener — der Weg, der bis 0.6 der einzige
// war und auf einem Server ohne Webserver auch der richtige ist.
func TestAcmeWebrootBleibtOhneNginxLeer(t *testing.T) {
	s, ops := newSystemServer(t)
	ops.web = privops.WebServerState{LauscherGeprueft: true}
	ops.webroot = "/var/www/asylum-acme"

	if dir := s.acmeWebroot(t.Context(), []string{"panel.example.com"}); dir != "" {
		t.Fatalf("Webroot = %q, erwartet keiner", dir)
	}
	for _, a := range ops.recorded() {
		if a == "webserver:acme-webroot" {
			t.Error("ohne nginx darf kein Drop-in geschrieben werden — es läse es niemand")
		}
	}
}

// Ein installiertes, aber gestopptes nginx liefert nichts aus. Ein Drop-in
// dafür zu schreiben wäre nicht falsch, aber es DANN ZU BENUTZEN wäre es:
// Die Prüfung liefe gegen einen Webserver, der nicht antwortet.
func TestAcmeWebrootBleibtBeiGestopptemNginxLeer(t *testing.T) {
	s, ops := newSystemServer(t)
	ops.web = privops.WebServerState{
		Installiert: true, Version: "1.24.0", DienstAktiv: false, LauscherGeprueft: true,
	}
	ops.webroot = "/var/www/asylum-acme"

	if dir := s.acmeWebroot(t.Context(), []string{"panel.example.com"}); dir != "" {
		t.Fatalf("Webroot = %q, erwartet keiner", dir)
	}
}

// Der Fall aus E1, hier von der anderen Seite: Läuft neben nginx noch ein
// fremder Webserver, entscheidet nicht unsere Konfiguration, wer die Challenge
// beantwortet, sondern wer den Port hält. Dann lieber der klare Fehlschlag als
// eine Prüfung, die aus unerfindlichen Gründen 404 bekommt.
func TestAcmeWebrootBleibtBeiFremdemServerDanebenLeer(t *testing.T) {
	s, ops := newSystemServer(t)
	ops.web = privops.WebServerState{
		Installiert: true, Version: "1.24.0", DienstAktiv: true, LauscherGeprueft: true,
		Lauscher: []privops.Lauscher{
			{Port: 80, Adresse: "0.0.0.0", Prozess: "caddy", PID: 651},
		},
	}
	ops.webroot = "/var/www/asylum-acme"

	if dir := s.acmeWebroot(t.Context(), []string{"panel.example.com"}); dir != "" {
		t.Fatalf("Webroot = %q, erwartet keiner", dir)
	}
}

// Lässt sich das Drop-in nicht schreiben, ist das kein Abbruch: Der Bezug läuft
// über den eigenen Listener weiter und scheitert dort mit der Meldung, die den
// Grund nennt. Ein Manager, der gar nicht erst entsteht, nähme auch DNS-01 mit
// — und das hätte funktioniert.
func TestAcmeWebrootFehlerFuehrtZumEigenenListener(t *testing.T) {
	s, ops := newSystemServer(t)
	ops.web = privops.WebServerState{
		Installiert: true, Version: "1.24.0", DienstAktiv: true, LauscherGeprueft: true,
	}
	ops.webrootErr = errors.New("nginx hat die Konfiguration abgelehnt")

	if dir := s.acmeWebroot(t.Context(), []string{"panel.example.com"}); dir != "" {
		t.Fatalf("Webroot = %q — ein Fehlschlag darf keinen Pfad melden", dir)
	}
}

// Und dasselbe, wenn schon der Zustand nicht lesbar ist.
func TestAcmeWebrootOhneZustandsauskunft(t *testing.T) {
	s, ops := newSystemServer(t)
	ops.webErr = errors.New("ss antwortet nicht")

	if dir := s.acmeWebroot(t.Context(), []string{"panel.example.com"}); dir != "" {
		t.Fatalf("Webroot = %q, erwartet keiner", dir)
	}
	for _, a := range ops.recorded() {
		if a == "webserver:acme-webroot" {
			t.Error("ohne Zustandsauskunft darf nichts geschrieben werden")
		}
	}
}
