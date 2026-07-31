package httpd

import (
	"net/http"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/store"
)

// Angriffsdurchgang gegen das Modul Docker — Schritt 9 aus docs/17-docker.md.
//
// Die Prüfungen hier sind absichtlich stumpf und breit: Sie gehen JEDE
// schreibende Route des Moduls durch und stellen dieselben drei Fragen. Der
// Wert liegt in der Vollständigkeit, nicht im Einfallsreichtum — eine Route, die
// beim Anbauen vergessen wurde, fällt nur so auf.

// schreibrouten sind alle Wege, über die das Modul etwas verändert.
//
// Die Liste steht hier als Aufzählung und nicht als Ableitung aus dem Router:
// Eine Ableitung fände immer genau das, was da ist, und könnte nie sagen, dass
// etwas fehlt. Wer eine Route hinzufügt, trägt sie hier ein — und wenn er es
// vergisst, ist das eine Lücke, die dieser Test nicht schließt, sondern eine,
// die der Angriffsdurchgang beim nächsten Mal findet.
func schreibrouten() []struct {
	methode, pfad string
	koerper       map[string]any
} {
	return []struct {
		methode, pfad string
		koerper       map[string]any
	}{
		{http.MethodPost, "/api/v1/docker/install", map[string]any{}},
		{http.MethodPost, "/api/v1/docker/containers/aaaa11112222", map[string]any{"aktion": "stop"}},
		{http.MethodPost, "/api/v1/docker/images/sha256:aaa/remove", map[string]any{"aktion": "remove"}},
		{http.MethodPost, "/api/v1/docker/volumes/web_daten/remove", map[string]any{"aktion": "remove"}},
		{http.MethodPost, "/api/v1/docker/networks/4d5e6f/remove", map[string]any{"aktion": "remove"}},
		{http.MethodPost, "/api/v1/docker/prune", map[string]any{"art": "images"}},
		{http.MethodPost, "/api/v1/docker/stacks", map[string]any{"name": "neu", "text": "services: {}\n"}},
		{http.MethodPut, "/api/v1/docker/stacks/web", map[string]any{"text": "services: {}\n"}},
		{http.MethodPost, "/api/v1/docker/stacks/web", map[string]any{"aktion": "up"}},
		{http.MethodPost, "/api/v1/docker/updates/check", map[string]any{}},
	}
}

// Erstens: KEINE schreibende Route steht einem Konto ohne Owner-Rolle offen.
//
// Ein Compose-Stack ist Codeausführung als root; ein Admin-Konto, das Dienste
// neu starten darf, soll damit nicht die Rechtetrennung des Servers aufheben.
func TestDockerSchreibroutenAlleNurFuerOwner(t *testing.T) {
	for _, rolle := range []string{store.RoleAdmin, store.RoleReadOnly} {
		t.Run(rolle, func(t *testing.T) {
			s, ops, cookie, csrf := stackServer(t, rolle)
			ops.stackPruefung = sauber()

			for _, r := range schreibrouten() {
				rec := stackPost(t, s, r.methode, r.pfad, cookie, csrf, r.koerper)
				if rec.Code != http.StatusForbidden {
					t.Errorf("%s %s: Status = %d, erwartet 403", r.methode, r.pfad, rec.Code)
				}
			}
			if len(ops.recorded()) != 0 {
				t.Errorf("die Rolle %s hat trotzdem etwas ausgelöst: %v", rolle, ops.recorded())
			}
		})
	}
}

// Zweitens: KEINE schreibende Route wirkt ohne CSRF-Token.
//
// Ein Schreibzugriff, der ohne den zweiten Nachweis durchgeht, ist über einen
// Formularabsender einer fremden Seite auslösbar — die Sitzung schickt der
// Browser von selbst mit.
func TestDockerSchreibroutenBrauchenCSRF(t *testing.T) {
	s, ops, cookie, _ := stackServer(t, store.RoleOwner)
	ops.stackPruefung = sauber()

	for _, r := range schreibrouten() {
		rec := stackPost(t, s, r.methode, r.pfad, cookie, "", r.koerper)
		if rec.Code == http.StatusOK || rec.Code == http.StatusAccepted {
			t.Errorf("%s %s ging ohne CSRF-Token durch: %d", r.methode, r.pfad, rec.Code)
		}
	}
	if len(ops.recorded()) != 0 {
		t.Errorf("ohne CSRF-Token wurde etwas ausgelöst: %v", ops.recorded())
	}
}

// Drittens: KEINE schreibende Route wirkt ohne Sitzung.
func TestDockerSchreibroutenBrauchenAnmeldung(t *testing.T) {
	s, ops, _, _ := stackServer(t, store.RoleOwner)
	ops.stackPruefung = sauber()

	for _, r := range schreibrouten() {
		rec := stackPost(t, s, r.methode, r.pfad, nil, "", r.koerper)
		if rec.Code == http.StatusOK || rec.Code == http.StatusAccepted {
			t.Errorf("%s %s ging ohne Anmeldung durch: %d", r.methode, r.pfad, rec.Code)
		}
	}
	if len(ops.recorded()) != 0 {
		t.Errorf("ohne Anmeldung wurde etwas ausgelöst: %v", ops.recorded())
	}
}

// Die Rückfragen der Stufe 3: Ein FALSCHES getipptes Wort wirkt nicht. Geprüft
// wird die Wirkung und nicht der Statuscode — eine Rückfrage, die trotzdem
// ausführt, ist keine.
func TestDockerStufeDreiFalschesWortWirktNicht(t *testing.T) {
	faelle := []struct {
		name, pfad string
		koerper    map[string]any
	}{
		{"Volume entfernen", "/api/v1/docker/volumes/web_daten/remove",
			map[string]any{"aktion": "remove", "bestaetigt": true, "getippt": "falsch"}},
		{"Volumes aufräumen", "/api/v1/docker/prune",
			map[string]any{"art": "volumes", "bestaetigt": true, "getippt": "falsch"}},
		{"Stack löschen", "/api/v1/docker/stacks/web",
			map[string]any{"aktion": "loeschen", "bestaetigt": true, "getippt": "falsch"}},
		{"Stack mit Volumes herunterfahren", "/api/v1/docker/stacks/web",
			map[string]any{"aktion": "down", "mit_volumes": true, "bestaetigt": true, "getippt": "falsch"}},
	}

	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			s, ops, cookie, csrf := stackServer(t, store.RoleOwner)
			ops.stackPruefung = sauber()

			rec := stackPost(t, s, http.MethodPost, f.pfad, cookie, csrf, f.koerper)
			if rec.Code != http.StatusConflict {
				t.Errorf("Status = %d, erwartet 409: %s", rec.Code, rec.Body.String())
			}
			for _, ruf := range ops.recorded() {
				if strings.Contains(ruf, "rm") || strings.Contains(ruf, "prune") ||
					strings.Contains(ruf, "loeschen") || strings.Contains(ruf, "down") {
					t.Errorf("das falsche Wort hat trotzdem gewirkt: %q", ruf)
				}
			}
		})
	}
}

// Die Pfadwache auf Ebene der Routen: Ein Stack-Name, der wie ein Pfad
// aussieht, führt nirgendwohin — weder lesend noch schreibend.
func TestDockerStacknamenAlsPfadWirkenNicht(t *testing.T) {
	s, ops, cookie, csrf := stackServer(t, store.RoleOwner)
	ops.stackPruefung = sauber()

	boese := []string{
		"..%2f..%2fetc", "%2Fetc%2Fpasswd", "web%2f..%2f..", "..", ".",
		"web%00", "WEB", "web%20stack",
	}
	for _, name := range boese {
		for _, r := range []struct {
			methode string
			koerper map[string]any
		}{
			{http.MethodPut, map[string]any{"text": "services: {}\n"}},
			{http.MethodPost, map[string]any{"aktion": "up"}},
			{http.MethodPost, map[string]any{"aktion": "loeschen", "bestaetigt": true, "getippt": name}},
		} {
			rec := stackPost(t, s, r.methode, "/api/v1/docker/stacks/"+name, cookie, csrf, r.koerper)
			if rec.Code == http.StatusOK || rec.Code == http.StatusAccepted {
				t.Errorf("%s /stacks/%s ging durch: %d", r.methode, name, rec.Code)
			}
		}
		if rec := get(t, s, "/api/v1/docker/stacks/"+name, cookie); rec.Code == http.StatusOK {
			t.Errorf("GET /stacks/%s ergab eine Auskunft", name)
		}
	}
	for _, ruf := range ops.recorded() {
		if strings.Contains(ruf, "stack-write") || strings.Contains(ruf, "stack-loeschen") ||
			strings.Contains(ruf, "stack-up") {
			t.Errorf("ein Pfad als Name hat gewirkt: %q", ruf)
		}
	}
}

// Und die Leserouten stehen JEDER Rolle offen — das ist die andere Hälfte der
// Zusage. Wer sehen darf, welche Dienste laufen, darf sehen, welche Container
// laufen.
func TestDockerLeseroutenFuerAlleRollen(t *testing.T) {
	s, _, cookie, _ := stackServer(t, store.RoleReadOnly)

	for _, pfad := range []string{
		"/api/v1/docker",
		"/api/v1/docker/containers",
		"/api/v1/docker/bestand",
		"/api/v1/docker/stacks",
		"/api/v1/docker/ports",
		"/api/v1/docker/updates",
	} {
		if rec := get(t, s, pfad, cookie); rec.Code != http.StatusOK {
			t.Errorf("GET %s: Status = %d, erwartet 200", pfad, rec.Code)
		}
	}
}
