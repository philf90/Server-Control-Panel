package httpd

import (
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/metrics"
	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// Die Zusage von Entwurf 1 lautet: Der Zustand geht nie weg. Vorher standen die
// Kennzahlen nur auf der Übersicht — wer auf „Dienste" wechselte, um einen
// Ausfall zu beheben, sah sie in genau dem Moment nicht mehr, in dem sie
// interessant wurden. Dieser Test hält fest, dass die Statusleiste auf jeder
// Seite steht und nicht nur auf einer.
func TestStatusleisteStehtAufJederSeite(t *testing.T) {
	s := newTestServer(t)
	s.setLatest(metrics.Snapshot{
		At:         time.Now(),
		UptimeText: "8 Tage, 4 Std",
		CPU:        metrics.CPU{Total: 6.4},
		Memory:     metrics.Memory{UsedPct: 30.1, Used: 1 << 30, Total: 4 << 30},
		Load:       [3]float64{0.2, 0.1, 0.1},
		Filesystems: []metrics.Filesystem{
			{Mount: "/", UsedPct: 24.0},
			{Mount: "/boot", UsedPct: 36.4},
		},
	})

	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, _ := login(t, s, user)

	for _, pfad := range []string{"/", "/services", "/packages", "/firewall", "/audit", "/logs", "/account"} {
		t.Run(pfad, func(t *testing.T) {
			body := get(t, s, pfad, cookie).Body.String()

			if !strings.Contains(body, `<header class="status">`) {
				t.Error("die Statusleiste fehlt")
			}
			for _, wert := range []string{"6.4", "30.1", "0.2"} {
				if !strings.Contains(body, wert) {
					t.Errorf("Kennzahl %q steht nicht in der Leiste", wert)
				}
			}
			// Das engste Dateisystem, nicht das erste der Liste: Wer eine
			// einzelne Zahl bekommt, will die schlechteste.
			if !strings.Contains(body, "36.4") || !strings.Contains(body, "/boot") {
				t.Error("die Platte zeigt nicht das engste Dateisystem")
			}
			// Jede Zahl ist ein Griff.
			if !strings.Contains(body, `class="gauge"`) {
				t.Error("die Messwerte sind keine Links")
			}
			if !strings.Contains(body, `class="rail"`) {
				t.Error("die Symbolschiene fehlt")
			}
		})
	}
}

// Vor der Anmeldung darf die Leiste nichts verraten: Wer nur die Anmeldeseite
// sieht, hat keinen Anspruch darauf zu erfahren, wie es der Maschine geht.
func TestStatusleisteFehltVorDerAnmeldung(t *testing.T) {
	s := newTestServer(t)
	s.setLatest(metrics.Snapshot{CPU: metrics.CPU{Total: 42.5}})

	body := get(t, s, "/login", nil).Body.String()
	if strings.Contains(body, `<header class="status">`) {
		t.Error("die Statusleiste steht auf der Anmeldeseite")
	}
	if strings.Contains(body, "42.5") {
		t.Error("die Anmeldeseite verrät die Auslastung")
	}
	if strings.Contains(body, `class="konsole"`) {
		t.Error("die Konsole steht auf der Anmeldeseite")
	}
}

// Die Konsole ist das zweite Kernstück: Das Panel zeigt im Klartext, was es auf
// der Maschine ausgeführt hat — mit Rückgabewert und Laufzeit, auch im
// Fehlerfall.
func TestKonsoleZeigtAusgefuehrteBefehle(t *testing.T) {
	s := newTestServer(t)
	// Ein eingespeister Executor bringt kein Journal mit; für die Anzeige
	// genügt eines von Hand.
	s.journal = privops.NewJournal()
	s.journal.Notiere(privops.Notiz{
		Zeit: time.Now(), Befehl: "ufw status numbered", Dauer: 100 * time.Millisecond,
	})
	s.journal.Notiere(privops.Notiz{
		Zeit: time.Now(), Befehl: "systemctl restart postgresql.service",
		ExitCode: 1, Dauer: 1900 * time.Millisecond,
		Meldung: "Job for postgresql.service failed",
	})

	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, _ := login(t, s, user)
	body := get(t, s, "/services", cookie).Body.String()

	if !strings.Contains(body, "systemctl restart postgresql.service") {
		t.Error("der zuletzt ausgeführte Befehl steht nicht in der Konsole")
	}
	if !strings.Contains(body, "ufw status numbered") {
		t.Error("ältere Befehle fehlen in der aufgeklappten Liste")
	}
	if !strings.Contains(body, "1.9s") {
		t.Error("die Laufzeit fehlt")
	}
	if !strings.Contains(body, "Job for postgresql.service failed") {
		t.Error("die Meldung des fehlgeschlagenen Aufrufs fehlt")
	}
	// Eingeklappt steht der jüngste Befehl oben — er kommt vor dem älteren.
	if strings.Index(body, "systemctl restart") > strings.Index(body, "ufw status") {
		t.Error("die Konsole zeigt den ältesten Befehl zuerst")
	}
}

// Der Warnpunkt an der Schiene sagt, wo etwas offen ist, ohne dass man jede
// Seite besuchen muss. Er folgt denselben Signalen wie die Übersicht.
func TestWarnpunktFolgtDemHandlungsbedarf(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, _ := login(t, s, user)

	// Ohne erhobenen Stand kein Punkt: geraten wird nicht.
	if body := get(t, s, "/audit", cookie).Body.String(); strings.Contains(body, `class="pip`) {
		t.Error("ohne erhobenen Stand steht ein Warnpunkt an der Schiene")
	}

	s.lageSetzen([]dashSignal{{Level: "crit", Tag: "Dienst", Title: "x.service ist ausgefallen"}})

	body := get(t, s, "/audit", cookie).Body.String()
	if !strings.Contains(body, `class="pip crit"`) {
		t.Error("der Warnpunkt fehlt, obwohl ein Dienst ausgefallen ist")
	}
	if !strings.Contains(body, "1 offen") {
		t.Error("der Zähler in der Statusleiste nennt den offenen Punkt nicht")
	}
}

// Ein abgelaufener Stand ist keiner: Lieber gar kein Punkt als einer von
// gestern. Das greift, wenn der Messtakt steht.
func TestVeralteterLagestandZeigtKeinePunkte(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "admin", store.RoleAdmin)
	cookie, _ := login(t, s, user)

	s.lageSetzen([]dashSignal{{Level: "crit", Tag: "Dienst", Title: "x.service ist ausgefallen"}})
	s.lageMu.Lock()
	s.lageErhoben = time.Now().Add(-2 * lageTTL)
	s.lageMu.Unlock()

	if body := get(t, s, "/audit", cookie).Body.String(); strings.Contains(body, `class="pip`) {
		t.Error("ein veralteter Stand färbt noch einen Warnpunkt")
	}
}
