package httpd

// Tests der Probe mit Rückweg (probe.go).
//
// Sie standen bis zur Herauslösung in api_firewall_test.go, weil es den Wächter
// nur für die Firewall gab. Er trägt jetzt zwei Bereiche, und die Sicherung
// gehört zu ihm und nicht zu einem seiner Anwender: Ein Test, der unter
// „Firewall" liegt, wird beim Bau des zweiten Anwenders nicht gelesen.
//
// Ausgeführt und nicht umschrieben: Das ist der Kern von Grundsatz VI und die
// wichtigste Sicherung des Panels. Die Frist wird dafür auf Millisekunden
// gesetzt — die sechzig Sekunden des Betriebs abzuwarten wäre ein Test, den
// niemand laufen lässt, und dann bliebe ausgerechnet diese Sicherung ungeprüft.

import (
	"context"
	"testing"
	"time"
)

// Der Wächter rollt ohne Bestätigung zurück. Das ist der Kern von Grundsatz VI
// und die wichtigste Sicherung des Panels — deshalb wird sie nicht umschrieben,
// sondern ausgeführt: mit einer kurzen Frist, damit der Test in Millisekunden
// läuft statt in einer Minute.
func TestProbenWaechterRolltOhneBestaetigungZurueck(t *testing.T) {
	g := neuerProbenWaechter(80 * time.Millisecond)

	zurueck := make(chan struct{}, 1)
	g.arm("Test", func(_ context.Context) error {
		zurueck <- struct{}{}
		return nil
	})

	offen, rest := g.state()
	if !offen {
		t.Fatal("nach arm steht keine Probe aus")
	}
	if rest < 0 {
		t.Errorf("Restfrist = %v, erwartet nicht negativ", rest)
	}
	if g.subjectOf() != "Test" {
		t.Errorf("Gegenstand = %q, erwartet Test", g.subjectOf())
	}

	select {
	case <-zurueck:
	case <-time.After(3 * time.Second):
		t.Fatal("der Rückbau lief nicht — eine unbestätigte Änderung bliebe dauerhaft " +
			"stehen, und genau das soll die Probe verhindern")
	}

	// Danach steht keine Probe mehr aus: Der Wächter hat sich selbst aufgeräumt.
	if offen, _ := g.state(); offen {
		t.Error("nach dem Rückbau steht weiter eine Probe aus")
	}
	if g.confirm() {
		t.Error("confirm bestätigt eine Probe, die es nicht mehr gibt")
	}
}

// Bestätigen verhindert den Rückbau. Die andere Hälfte derselben Sicherung: Wäre
// sie kaputt, würde eine bestätigte Änderung nach einer Minute doch zurückgerollt.
func TestProbenWaechterBestaetigenVerhindertDenRueckbau(t *testing.T) {
	g := neuerProbenWaechter(80 * time.Millisecond)

	zurueck := make(chan struct{}, 1)
	g.arm("Test", func(_ context.Context) error {
		zurueck <- struct{}{}
		return nil
	})

	if !g.confirm() {
		t.Fatal("confirm hat die ausstehende Probe nicht gefunden")
	}
	if g.confirm() {
		t.Error("confirm hat zweimal bestätigt — die zweite Zustimmung gilt für nichts")
	}
	if offen, _ := g.state(); offen {
		t.Error("nach dem Bestätigen steht weiter eine Probe aus")
	}

	// Deutlich über der Frist warten: Der Rückbau darf auch später nicht kommen.
	select {
	case <-zurueck:
		t.Error("der Rückbau lief, obwohl bestätigt wurde")
	case <-time.After(400 * time.Millisecond):
	}
}

// Eine zweite Änderung während einer laufenden Probe ersetzt die erste. Ohne das
// liefen zwei Wächter mit zwei Rücknahmefunktionen, und der ältere würde eine
// Änderung zurückrollen, die inzwischen von einer neueren überholt ist.
func TestProbenWaechterZweitesArmErsetztDasErste(t *testing.T) {
	g := neuerProbenWaechter(80 * time.Millisecond)

	ersterLief := make(chan struct{}, 1)
	zweiterLief := make(chan struct{}, 1)

	g.arm("erster", func(_ context.Context) error {
		ersterLief <- struct{}{}
		return nil
	})
	g.arm("zweiter", func(_ context.Context) error {
		zweiterLief <- struct{}{}
		return nil
	})

	if g.subjectOf() != "zweiter" {
		t.Errorf("Gegenstand = %q, erwartet zweiter", g.subjectOf())
	}

	select {
	case <-zweiterLief:
	case <-time.After(3 * time.Second):
		t.Fatal("der Rückbau der zweiten Änderung lief nicht")
	}
	select {
	case <-ersterLief:
		t.Error("der Rückbau der ERSTEN Änderung lief ebenfalls — er hätte einen " +
			"Stand wiederhergestellt, den die zweite Änderung längst überholt hat")
	case <-time.After(200 * time.Millisecond):
	}
}
