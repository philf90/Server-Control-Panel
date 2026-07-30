package httpd

// Tests für /api/v1/audit.
//
// Zwei Dinge sind hier prüfenswert, und keines davon ist „liefert JSON":
//
//  1. Die Blätterung. Sie geht über eine ID und nicht über einen Versatz, weil
//     das Protokoll wächst, während man darin liest. Der Test schreibt deshalb
//     WÄHREND des Blätterns einen neuen Eintrag — mit OFFSET stünde danach eine
//     Zeile doppelt da.
//  2. Der Filter. Er läuft auf dem Server, und ein Suchbegriff mit Unterstrich
//     darf kein Joker sein: „sshd_config" soll nicht „sshdXconfig" finden.

import (
	"context"
	"encoding/json"
	"net/http"
	"strconv"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/store"
)

// eintraege schreibt n Protokollzeilen und gibt sie in der geschriebenen
// Reihenfolge zurück.
func schreibeAudit(t *testing.T, s *Server, eintraege ...store.AuditEntry) {
	t.Helper()
	for i, e := range eintraege {
		if e.At.IsZero() {
			// Aufsteigende Zeit, damit die Reihenfolge nachvollziehbar ist.
			e.At = time.Date(2026, 7, 30, 12, 0, i, 0, time.UTC)
		}
		if e.Result == "" {
			e.Result = store.ResultOK
		}
		if err := s.db.AppendAudit(context.Background(), e); err != nil {
			t.Fatalf("audit schreiben: %v", err)
		}
	}
}

func holeAudit(t *testing.T, s *Server, suchpfad string, cookie *http.Cookie) apiAudit {
	t.Helper()
	pfad := "/api/v1/audit"
	if suchpfad != "" {
		pfad += "?" + suchpfad
	}
	rec := get(t, s, pfad, cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d: %s", rec.Code, rec.Body.String())
	}
	var a apiAudit
	if err := json.Unmarshal(rec.Body.Bytes(), &a); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}
	return a
}

func TestAPIAuditListeUndFacetten(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleOwner)

	schreibeAudit(t, s,
		store.AuditEntry{Actor: "philipp", Action: "files.delete", Target: "/srv/alt", Detail: "3 Dateien"},
		store.AuditEntry{Actor: "philipp", Action: "files.chmod", Target: "/srv/neu", Result: store.ResultDenied},
		store.AuditEntry{Actor: "monteur", Action: "service.stop", Target: "nginx.service", Result: store.ResultError},
	)

	a := holeAudit(t, s, "", cookie)

	// Jüngstes zuerst — man liest ein Protokoll von hinten.
	if len(a.Zeilen) < 3 {
		t.Fatalf("%d Zeilen, erwartet mindestens 3", len(a.Zeilen))
	}
	if a.Zeilen[0].Aktion != "service.stop" {
		t.Errorf("erste Zeile ist %q, erwartet den jüngsten Eintrag", a.Zeilen[0].Aktion)
	}

	// Die Familie wird auf dem Server gebildet, damit Filterleiste und Zeile
	// dieselbe Regel benutzen.
	if a.Zeilen[0].Familie != "service" {
		t.Errorf("Familie = %q, erwartet service", a.Zeilen[0].Familie)
	}

	// Die Einfärbung: „denied" ist eine Warnung und kein Fehler — es heißt, dass
	// die Politik gegriffen hat, und das Panel hat funktioniert.
	nach := map[string]apiAuditZeile{}
	for _, z := range a.Zeilen {
		nach[z.Aktion] = z
	}
	if nach["files.delete"].Stufe != "gut" {
		t.Errorf("ok → Stufe %q, erwartet gut", nach["files.delete"].Stufe)
	}
	if nach["files.chmod"].Stufe != "warn" {
		t.Errorf("denied → Stufe %q, erwartet warn (die Politik hat gegriffen, "+
			"das ist kein Fehler)", nach["files.chmod"].Stufe)
	}
	if nach["service.stop"].Stufe != "schlecht" {
		t.Errorf("error → Stufe %q, erwartet schlecht", nach["service.stop"].Stufe)
	}

	// Die Auswahlfelder kommen aus dem Protokoll selbst.
	if len(a.Akteure) < 2 {
		t.Errorf("Akteure = %v, erwartet mindestens philipp und monteur", a.Akteure)
	}
	var hatFiles, hatService bool
	for _, f := range a.Familien {
		switch f {
		case "files":
			hatFiles = true
		case "service":
			hatService = true
		}
	}
	if !hatFiles || !hatService {
		t.Errorf("Familien = %v, erwartet files und service", a.Familien)
	}
}

func TestAPIAuditFiltert(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleOwner)

	schreibeAudit(t, s,
		store.AuditEntry{Actor: "philipp", Action: "files.delete", Target: "/srv/eins"},
		store.AuditEntry{Actor: "monteur", Action: "files.delete", Target: "/srv/zwei"},
		store.AuditEntry{Actor: "philipp", Action: "service.stop", Target: "nginx.service", Result: store.ResultDenied},
	)

	// Nach Akteur.
	a := holeAudit(t, s, "akteur=monteur", cookie)
	for _, z := range a.Zeilen {
		if z.Akteur != "monteur" {
			t.Errorf("Filter akteur=monteur liefert %q", z.Akteur)
		}
	}
	if len(a.Zeilen) != 1 {
		t.Errorf("%d Zeilen für monteur, erwartet 1", len(a.Zeilen))
	}

	// Nach Familie. Der Präfix wird mit Punkt gesucht: „files" soll
	// „files.delete" finden, aber kein künftiges „filesystem.pruefen".
	a = holeAudit(t, s, "familie=files", cookie)
	if len(a.Zeilen) != 2 {
		t.Errorf("%d Zeilen für familie=files, erwartet 2", len(a.Zeilen))
	}
	for _, z := range a.Zeilen {
		if z.Familie != "files" {
			t.Errorf("Filter familie=files liefert Familie %q", z.Familie)
		}
	}

	// Nach Ergebnis.
	a = holeAudit(t, s, "ergebnis=denied", cookie)
	if len(a.Zeilen) != 1 || a.Zeilen[0].Ergebnis != store.ResultDenied {
		t.Errorf("Filter ergebnis=denied liefert %+v", a.Zeilen)
	}

	// Freie Suche in Ziel und Detail.
	a = holeAudit(t, s, "q=zwei", cookie)
	if len(a.Zeilen) != 1 || a.Zeilen[0].Ziel != "/srv/zwei" {
		t.Errorf("Suche nach „zwei\" liefert %+v", a.Zeilen)
	}

	// Und der Filter kommt zurück: Was gilt, soll aus der Antwort ablesbar sein
	// und nicht aus der Adresse.
	if a.Filter.Suche != "zwei" {
		t.Errorf("Filter.Suche = %q, erwartet den Begriff zurück", a.Filter.Suche)
	}

	// Ein unbekanntes Ergebnis wird verworfen und nicht durchgereicht: Die Spalte
	// hat einen CHECK, es gibt genau drei Werte, und alles andere fände nichts —
	// eine leere Liste ohne Grund ist die schlechteste Antwort auf einen alten
	// Verweis.
	a = holeAudit(t, s, "ergebnis=quatsch", cookie)
	if a.Filter.Ergebnis != "" {
		t.Errorf("Filter.Ergebnis = %q, erwartet leer für einen unbekannten Wert", a.Filter.Ergebnis)
	}
	if len(a.Zeilen) != 3 {
		t.Errorf("%d Zeilen, erwartet alle 3 — der unbrauchbare Filter soll entfallen "+
			"und nicht alles ausschließen", len(a.Zeilen))
	}
}

// Ein Unterstrich im Suchbegriff ist kein Joker. Ohne Maskierung fände
// „sshd_config" auch „sshdXconfig" — kein Sicherheitsproblem, aber ein falsches
// Ergebnis, und in einem Revisionsprotokoll ist das schlimm genug.
func TestAPIAuditSucheOhneJoker(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleOwner)

	schreibeAudit(t, s,
		store.AuditEntry{Actor: "philipp", Action: "files.edit", Target: "/etc/ssh/sshd_config"},
		store.AuditEntry{Actor: "philipp", Action: "files.edit", Target: "/etc/ssh/sshdXconfig"},
	)

	a := holeAudit(t, s, "q=sshd_config", cookie)
	if len(a.Zeilen) != 1 {
		t.Fatalf("%d Treffer für „sshd_config\", erwartet 1 — der Unterstrich wurde "+
			"als Joker behandelt: %+v", len(a.Zeilen), a.Zeilen)
	}
	if a.Zeilen[0].Ziel != "/etc/ssh/sshd_config" {
		t.Errorf("Treffer ist %q", a.Zeilen[0].Ziel)
	}

	// Auch ein Prozentzeichen ist kein Joker. In der Adresse steht es als %25 —
	// ein einzelnes % wäre keine gültige Prozentkodierung, und der Wert käme beim
	// Filter nie an.
	schreibeAudit(t, s, store.AuditEntry{Actor: "philipp", Action: "files.edit", Target: "100%"})
	a = holeAudit(t, s, "q=%25", cookie)
	if len(a.Zeilen) != 1 {
		t.Errorf("%d Treffer für „%%\", erwartet 1 — das Prozentzeichen wurde als "+
			"Joker behandelt", len(a.Zeilen))
	}
}

// Die Blätterung über eine ID statt über einen Versatz. Der Test schreibt
// während des Blätterns einen neuen Eintrag — mit OFFSET stünde danach eine
// Zeile doppelt da, und in einem Protokoll blättert man, um etwas NICHT zu
// übersehen.
func TestAPIAuditBlaetternOhneDoppelte(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleOwner)

	// Eine Seite voll plus ein paar. Die Anmeldung hat schon Einträge erzeugt;
	// die zählen mit, was gerade richtig ist — der Test soll nicht von einem
	// leeren Protokoll ausgehen.
	for i := 0; i < auditSeite+20; i++ {
		schreibeAudit(t, s, store.AuditEntry{
			Actor:  "philipp",
			Action: "test.eintrag",
			Target: "nummer-" + strconv.Itoa(i),
			At:     time.Date(2026, 7, 30, 12, 0, 0, i*1000, time.UTC),
		})
	}

	erste := holeAudit(t, s, "familie=test", cookie)
	if len(erste.Zeilen) != auditSeite {
		t.Fatalf("erste Seite hat %d Zeilen, erwartet %d", len(erste.Zeilen), auditSeite)
	}
	if erste.Weiter == 0 {
		t.Fatal("die volle erste Seite nennt keine Fortsetzung")
	}

	// Und jetzt der Punkt: Während geblättert wird, kommt etwas dazu.
	schreibeAudit(t, s, store.AuditEntry{
		Actor: "monteur", Action: "test.eintrag", Target: "dazwischen",
		At: time.Date(2026, 7, 30, 13, 0, 0, 0, time.UTC),
	})

	zweite := holeAudit(t, s, "familie=test&vor="+strconv.FormatInt(erste.Weiter, 10), cookie)
	if len(zweite.Zeilen) == 0 {
		t.Fatal("die zweite Seite ist leer")
	}

	gesehen := map[int64]string{}
	for _, z := range erste.Zeilen {
		gesehen[z.ID] = z.Ziel
	}
	for _, z := range zweite.Zeilen {
		if voriges, doppelt := gesehen[z.ID]; doppelt {
			t.Errorf("Eintrag %d (%q) steht auf beiden Seiten — die Blätterung "+
				"verschiebt sich, wenn das Protokoll wächst", z.ID, voriges)
		}
		// Und der neue Eintrag darf nicht auf der zweiten Seite auftauchen: Er ist
		// jünger als die Grenze.
		if z.Ziel == "dazwischen" {
			t.Error("der während des Blätterns geschriebene Eintrag steht auf der " +
				"zweiten Seite — dann ist die Grenze keine")
		}
	}

	// Die letzte Seite nennt keine Fortsetzung.
	letzte := holeAudit(t, s, "familie=test&vor="+strconv.FormatInt(zweite.Zeilen[len(zweite.Zeilen)-1].ID, 10), cookie)
	if letzte.Weiter != 0 && len(letzte.Zeilen) < auditSeite {
		t.Errorf("eine halbe Seite nennt eine Fortsetzung (%d Zeilen, weiter=%d)",
			len(letzte.Zeilen), letzte.Weiter)
	}
}

// Lesen darf jede Rolle. Das Protokoll ist die Grundlage dafür, dass man einem
// Panel glauben kann — es vor einem Leserkonto zu verstecken hieße, ihm die
// Nachprüfbarkeit zu nehmen.
func TestAPIAuditLesenBrauchtKeineSchreibrolle(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "leser", store.RoleReadOnly)
	cookie, _ := login(t, s, user)

	if rec := get(t, s, "/api/v1/audit", cookie); rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200: %s", rec.Code, rec.Body.String())
	}
}

// Es gibt keinen verändernden Endpunkt. Das ist keine fehlende Hälfte, sondern
// die Aussage des Moduls: Das Protokoll ist nur additiv.
func TestAPIAuditKennKeinPOST(t *testing.T) {
	s, cookie, csrf := angemeldet(t, store.RoleOwner)

	rec := postJSON(t, s, "/api/v1/audit", `{}`, cookie, csrf)
	if rec.Code != http.StatusMethodNotAllowed && rec.Code != http.StatusNotFound {
		t.Errorf("POST /api/v1/audit → %d, erwartet 405 oder 404: %s", rec.Code, rec.Body.String())
	}
}
