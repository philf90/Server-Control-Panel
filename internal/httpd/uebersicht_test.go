package httpd

import (
	"context"
	"fmt"
	"net/http"
	"net/url"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/store"
)

// ---------------------------------------------------------------- Verläufe ---

// TestBuildSparkVerdichtetDenRingpuffer hält fest, warum die Verläufe unsauber
// aussahen: Der Ringpuffer hält 24 Stunden in 30-Sekunden-Auflösung, also bis zu
// 2880 Messungen, und die wurden alle in ein 100 Einheiten breites Feld
// gezeichnet. Bei rund 270 Pixeln Kachelbreite sind das zehn Punkte je Pixel —
// der Strich überzeichnet sich selbst.
func TestBuildSparkVerdichtetDenRingpuffer(t *testing.T) {
	const n = 2880
	at := make([]time.Time, n)
	vals := make([]float64, n)
	start := time.Date(2026, 7, 27, 12, 0, 0, 0, time.Local)
	for i := range vals {
		at[i] = start.Add(time.Duration(i) * 30 * time.Second)
		vals[i] = float64(i % 40)
	}

	s := buildSpark(at, vals, prozentText, 5)
	if !s.Has {
		t.Fatal("kein Verlauf erzeugt")
	}
	if got := strings.Count(s.Path, " L") + 1; got != sparkPunkte {
		t.Errorf("%d Stützstellen im Pfad, erwartet %d", got, sparkPunkte)
	}

	punkte := s.Punkte
	if len(punkte) != sparkPunkte {
		t.Fatalf("%d Messpunkte, erwartet %d", len(punkte), sparkPunkte)
	}

	// Jeder Messpunkt trägt Uhrzeit und fertigen Text, und die Stellen laufen
	// von links nach rechts durch das Feld.
	for i, p := range punkte {
		if p.T == "" || p.V == "" {
			t.Fatalf("Messpunkt %d ohne Text: %+v", i, p)
		}
		if !strings.HasSuffix(p.V, " %") {
			t.Fatalf("Messpunkt %d: %q trägt keine Einheit", i, p.V)
		}
		if p.X < sparkRand-0.05 || p.X > sparkBreite-sparkRand+0.05 {
			t.Fatalf("Messpunkt %d liegt außerhalb des Feldes: x=%.1f", i, p.X)
		}
		if p.Y < 0 || p.Y > sparkHoehe {
			t.Fatalf("Messpunkt %d liegt außerhalb des Feldes: y=%.1f", i, p.Y)
		}
		if i > 0 && p.X <= punkte[i-1].X {
			t.Fatalf("Messpunkt %d liegt nicht rechts von seinem Vorgänger", i)
		}
	}

	// Der Endpunkt ist ein Segment der Länge null auf dem letzten Messpunkt —
	// ein <circle> würde von der waagerechten Streckung zur Ellipse.
	letzter := punkte[len(punkte)-1]
	if !strings.Contains(s.Dot, "L") || !strings.HasPrefix(s.Dot, "M") {
		t.Errorf("Endpunkt ist kein Pfad: %q", s.Dot)
	}
	teile := strings.Split(strings.TrimPrefix(s.Dot, "M"), "L")
	if len(teile) != 2 || strings.TrimSpace(teile[0]) != strings.TrimSpace(teile[1]) {
		t.Errorf("Endpunkt hat eine Länge: %q", s.Dot)
	}
	if !strings.Contains(s.Dot, formatEins(letzter.X)) {
		t.Errorf("Endpunkt %q liegt nicht auf dem letzten Messpunkt (x=%.1f)", s.Dot, letzter.X)
	}
}

// Ein fast waagerechter Verlauf muss waagerecht aussehen. Ohne Untergrenze für
// die Spanne zieht die Min-Max-Skalierung jeden Bruchteil auf die volle
// Kachelhöhe: Eine CPU, die zwischen 0,1 und 0,3 Prozent pendelt, sah aus wie
// ein Gebirge, und ein ruhiger Server wirkte unruhig.
func TestBuildSparkLaesstFlachesFlach(t *testing.T) {
	at := make([]time.Time, 20)
	vals := make([]float64, 20)
	start := time.Now()
	for i := range vals {
		at[i] = start.Add(time.Duration(i) * time.Minute)
		vals[i] = 0.1
		if i%2 == 0 {
			vals[i] = 0.3
		}
	}

	punkte := buildSpark(at, vals, prozentText, 5).Punkte

	hoch, tief := punkte[0].Y, punkte[0].Y
	for _, p := range punkte {
		if p.Y < hoch {
			hoch = p.Y
		}
		if p.Y > tief {
			tief = p.Y
		}
	}
	// 0,2 Prozentpunkte Unterschied bei 5 Punkten Mindestspanne sind ein
	// Fünfundzwanzigstel der nutzbaren Höhe (30 Einheiten), also gut 1,2.
	if tief-hoch > 3 {
		t.Errorf("Ausschlag = %.1f Einheiten für 0,2 Prozentpunkte — die Skalierung überzeichnet", tief-hoch)
	}
	// Und der Verlauf sitzt unten, nicht in der Mitte: Die Spanne wird bei null
	// verankert, wenn die Werte dort liegen.
	if tief < sparkHoehe/2 {
		t.Errorf("ein Verlauf um 0,2 %% sitzt bei y=%.1f statt am unteren Rand", tief)
	}
}

func TestBuildSparkOhneVerlauf(t *testing.T) {
	jetzt := []time.Time{time.Now()}
	if s := buildSpark(jetzt, []float64{1}, prozentText, 5); s.Has {
		t.Error("ein einzelner Wert ist kein Verlauf")
	}
	if s := buildSpark(nil, nil, prozentText, 5); s.Has {
		t.Error("ohne Werte darf kein Verlauf entstehen")
	}
	// Zeitstempel und Werte müssen zusammenpassen, sonst stünde an einem
	// Messpunkt eine fremde Uhrzeit.
	if s := buildSpark(jetzt, []float64{1, 2}, prozentText, 5); s.Has {
		t.Error("ungleich lange Reihen dürfen keinen Verlauf ergeben")
	}
}

func TestVerdichtenMitteltUndBehaeltDieZeit(t *testing.T) {
	at := make([]time.Time, 10)
	vals := make([]float64, 10)
	start := time.Date(2026, 7, 28, 8, 0, 0, 0, time.Local)
	for i := range vals {
		at[i] = start.Add(time.Duration(i) * time.Minute)
		vals[i] = float64(i)
	}

	gotAt, gotV := verdichten(at, vals, 5)
	if len(gotV) != 5 || len(gotAt) != 5 {
		t.Fatalf("%d Werte / %d Zeiten, erwartet je 5", len(gotV), len(gotAt))
	}
	// Je zwei Werte gemittelt: (0+1)/2, (2+3)/2, …
	for i, want := range []float64{0.5, 2.5, 4.5, 6.5, 8.5} {
		if gotV[i] != want {
			t.Errorf("Wert %d = %.1f, erwartet %.1f", i, gotV[i], want)
		}
	}
	if !gotAt[0].Equal(start.Add(time.Minute)) {
		t.Errorf("Zeit der ersten Stützstelle = %v", gotAt[0])
	}

	// Weniger Werte als Stützstellen bleiben unangetastet.
	if _, v := verdichten(at, vals, 100); len(v) != len(vals) {
		t.Errorf("%d Werte nach dem Verdichten, erwartet %d", len(v), len(vals))
	}
}

// formatEins schreibt eine Zahl so, wie sie im Pfad steht: eine Nachkommastelle.
func formatEins(v float64) string { return fmt.Sprintf("%.1f", v) }

// Dieselbe Anzeige auf der Seite des erzwungenen Wechsels: Genau dort landet ein
// neues Konto mit seinem Startpasswort, und dort braucht es die Bedingungen am
// dringendsten.
func TestErzwungenerWechselZeigtDieRichtlinie(t *testing.T) {
	s := newTestServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	hash, err := auth.HashPassword("ein Einmalpasswort das reicht")
	if err != nil {
		t.Fatal(err)
	}
	if err := s.db.SetTemporaryPassword(context.Background(), user.ID, hash); err != nil {
		t.Fatal(err)
	}

	rec := get(t, s, "/account/password-change", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d, erwartet 200", rec.Code)
	}
	body := rec.Body.String()
	if !strings.Contains(body, `class="pwcheck"`) {
		t.Error("die Passwortprüfung fehlt auf der Wechselseite")
	}
	if !strings.Contains(body, `data-pw-name="philipp"`) {
		t.Error("der Anmeldename fehlt — die Regel dazu bleibt unentschieden")
	}
}

// Die Ersteinrichtung kennt den Anmeldenamen noch nicht: Er wird im Formular
// daneben eingegeben, und das Skript liest ihn dort.
func TestEinrichtungZeigtDieRichtlinie(t *testing.T) {
	s := newTestServer(t)
	ctx := context.Background()

	token, err := auth.NewToken()
	if err != nil {
		t.Fatal(err)
	}
	if err := s.db.SetSetting(ctx, store.SettingSetupTokenHash, auth.HashToken(token)); err != nil {
		t.Fatal(err)
	}
	if err := s.db.SetSetting(ctx, store.SettingSetupTokenExpires,
		time.Now().Add(time.Hour).Format(time.RFC3339)); err != nil {
		t.Fatal(err)
	}

	body := get(t, s, "/setup?token="+url.QueryEscape(token), nil).Body.String()
	if !strings.Contains(body, `data-pw-feld="password"`) {
		t.Error("die Passwortprüfung fehlt in der Ersteinrichtung")
	}
	if !strings.Contains(body, `data-pw-name=""`) {
		t.Error("hier darf kein Anmeldename vorgegeben sein — er steht noch nicht fest")
	}
}
