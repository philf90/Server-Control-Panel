package httpd

import (
	"encoding/json"
	"net/http"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

func zieleLesen(t *testing.T, s *Server, cookie *http.Cookie) apiZiele {
	t.Helper()
	rec := get(t, s, "/api/v1/webserver/ziele", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var a apiZiele
	if err := json.Unmarshal(rec.Body.Bytes(), &a); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	return a
}

// Die FPM-Sockets kommen aus der Attrappe und nicht von der Platte. Auf der
// Entwicklermaschine gab es kein php-fpm, auf dem CI-Runner schon — und die
// erste Fassung dieser Tests war deshalb auf der einen grün und auf der anderen
// rot. Der Unterschied war die Maschine und nicht der Code.
func TestAPIZieleNenntVorhandeneFPMSockets(t *testing.T) {
	s, ops := newSystemServer(t)
	ops.phpSockets = []string{"/run/php/php8.2-fpm.sock"}
	cookie, _ := login(t, s, addUser(t, s, "chef", store.RoleOwner))

	a := zieleLesen(t, s, cookie)
	if len(a.Vorschlaege) != 1 {
		t.Fatalf("%d Vorschläge, erwartet 1: %+v", len(a.Vorschlaege), a.Vorschlaege)
	}
	v := a.Vorschlaege[0]
	if v.Zielart != "php" || v.Ziel != "/run/php/php8.2-fpm.sock" {
		t.Errorf("Vorschlag = %+v", v)
	}
}

// Die Zugabe aus 0.5: Wer eine Site anlegt, tippt die Adresse nicht ab. Ein
// abgetippter Port ist der häufigste Grund für eine Site, die 502 antwortet.
func TestAPIZieleSchlaegtLaufendeContainerVor(t *testing.T) {
	s, ops := newSystemServer(t)
	ops.container = []privops.Container{
		{
			Name: "web-api-1", Zustand: "running", Stack: "web", Dienst: "api",
			Ports: "127.0.0.1:3000->3000/tcp",
		},
		{
			// Ein gestoppter Container beantwortet nichts. Ihn vorzuschlagen
			// hieße, eine Site zu bauen, die von Anfang an 502 antwortet.
			Name: "alt", Zustand: "exited", Ports: "127.0.0.1:4000->4000/tcp",
		},
	}
	cookie, _ := login(t, s, addUser(t, s, "chef", store.RoleOwner))

	a := zieleLesen(t, s, cookie)
	if len(a.Vorschlaege) != 1 {
		t.Fatalf("%d Vorschläge, erwartet 1: %+v", len(a.Vorschlaege), a.Vorschlaege)
	}
	v := a.Vorschlaege[0]
	if v.Zielart != "proxy" || v.Ziel != "http://127.0.0.1:3000" {
		t.Errorf("Vorschlag = %+v", v)
	}
	if v.Titel != "web-api-1" || !strings.Contains(v.Detail, "web") {
		t.Errorf("Titel oder Detail unbrauchbar: %+v", v)
	}
	if v.Warnung != "" {
		t.Errorf("ein Port auf 127.0.0.1 braucht keine Warnung: %q", v.Warnung)
	}
}

// Der unbequeme Teil: Ein Container auf 0.0.0.0 ist schon jetzt aus dem Netz
// erreichbar. Ein Reverse-Proxy davor ändert das NICHT — und wer das nicht
// liest, hat den Dienst danach zweimal veröffentlicht.
func TestAPIZieleWarntVorOffenerVeroeffentlichung(t *testing.T) {
	s, ops := newSystemServer(t)
	ops.container = []privops.Container{
		{Name: "offen", Zustand: "running", Ports: "0.0.0.0:8080->80/tcp"},
	}
	cookie, _ := login(t, s, addUser(t, s, "chef", store.RoleOwner))

	a := zieleLesen(t, s, cookie)
	if len(a.Vorschlaege) != 1 {
		t.Fatalf("%d Vorschläge, erwartet 1", len(a.Vorschlaege))
	}
	// An der Zeile steht kurz, WELCHER Vorschlag es betrifft…
	if !strings.Contains(a.Vorschlaege[0].Warnung, "allen Adressen") {
		t.Errorf("die Warnung fehlt an der betroffenen Zeile: %q",
			a.Vorschlaege[0].Warnung)
	}
	// …und darüber der Satz, warum das zählt und was zu tun wäre. Zweimal
	// derselbe ganze Satz wäre Rauschen, das man überliest.
	if !strings.Contains(a.Anmerkung, "ändert das nicht") ||
		!strings.Contains(a.Anmerkung, "127.0.0.1") {
		t.Errorf("die Anmerkung trägt die Begründung nicht: %q", a.Anmerkung)
	}

	// Und die Adresse im Vorschlag ist trotzdem brauchbar: proxy_pass auf
	// 0.0.0.0 ist keine Zieladresse, sondern eine Bindungsangabe.
	if a.Vorschlaege[0].Ziel != "http://127.0.0.1:8080" {
		t.Errorf("Ziel = %q, erwartet die Loopback-Adresse", a.Vorschlaege[0].Ziel)
	}
}

// Der Panel-Port fällt heraus, bevor jemand ihn wählt. Ein Vorschlag, den der
// Prüfer danach ablehnt, ist eine Falle.
func TestAPIZieleLaesstDenPanelPortWeg(t *testing.T) {
	s, ops := newSystemServer(t)
	ops.container = []privops.Container{
		{Name: "panel-nah", Zustand: "running", Ports: "127.0.0.1:8443->8443/tcp"},
	}
	s.cfg.Server.Port = 8443
	cookie, _ := login(t, s, addUser(t, s, "chef", store.RoleOwner))

	for _, v := range zieleLesen(t, s, cookie).Vorschlaege {
		if strings.Contains(v.Ziel, "8443") {
			t.Errorf("der Panel-Port steht in der Auswahl: %+v", v)
		}
	}
}

// Ohne Docker gibt es keine Vorschläge — und das Formular funktioniert
// trotzdem. Ein 502 hier machte aus einer fehlenden Bequemlichkeit einen
// Fehler.
func TestAPIZieleOhneDockerIstKeinFehler(t *testing.T) {
	s, ops := newSystemServer(t)
	ops.containerErr = errDockerAttrappe
	cookie, _ := login(t, s, addUser(t, s, "chef", store.RoleOwner))

	rec := get(t, s, "/api/v1/webserver/ziele", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200 auch ohne Docker", rec.Code)
	}
	var a apiZiele
	if err := json.Unmarshal(rec.Body.Bytes(), &a); err != nil {
		t.Fatalf("Antwort nicht lesbar: %v", err)
	}
	if a.Fehler == "" {
		t.Error("der Grund für die leere Liste fehlt")
	}
}

// Lesen darf jede Rolle: Die Vorschläge nennen laufende Container und
// FPM-Sockets — dasselbe, was die Docker- und die Dienstseite ohnehin zeigen.
func TestAPIZieleLesenDarfJedeRolle(t *testing.T) {
	s, _ := newSystemServer(t)
	cookie, _ := login(t, s, addUser(t, s, "gast", store.RoleReadOnly))

	if rec := get(t, s, "/api/v1/webserver/ziele", cookie); rec.Code != http.StatusOK {
		t.Errorf("Status = %d, erwartet 200 für ein Konto mit Leserecht", rec.Code)
	}
}

// Die Adressumrechnung: proxy_pass auf 0.0.0.0 ist keine Zieladresse.
func TestProxyAdresse(t *testing.T) {
	faelle := map[string]string{
		"0.0.0.0":     "127.0.0.1",
		"::":          "127.0.0.1",
		"[::]":        "127.0.0.1",
		"":            "127.0.0.1",
		"127.0.0.1":   "127.0.0.1",
		"192.168.1.5": "192.168.1.5",
		"::1":         "[::1]",
	}
	for ein, aus := range faelle {
		if got := proxyAdresse(ein); got != aus {
			t.Errorf("proxyAdresse(%q) = %q, erwartet %q", ein, got, aus)
		}
	}
}
