package update

import (
	"os"
	"strings"
	"testing"
)

// minUpgradableFile ist die Datei, aus der die Freigabepipeline den Wert für
// min_upgradable_from liest.
const minUpgradableFile = "../../packaging/min-upgradable-from"

// readMinUpgradable liest den Wert wie der Release-Workflow: Zeilen mit #
// sind Kommentar, der Rest ist der Wert.
func readMinUpgradable(t *testing.T) string {
	t.Helper()
	raw, err := os.ReadFile(minUpgradableFile)
	if err != nil {
		t.Fatalf("%s lesen: %v", minUpgradableFile, err)
	}
	var value strings.Builder
	for line := range strings.SplitSeq(string(raw), "\n") {
		if strings.HasPrefix(strings.TrimSpace(line), "#") {
			continue
		}
		value.WriteString(strings.TrimSpace(line))
	}
	return value.String()
}

// TestMinUpgradableFrom wacht über einen Wert, der schon einmal jeden
// Beta-Tester ausgesperrt hat.
//
// min_upgradable_from stand fest auf "0.1.0", während 0.1.0-rc.2
// veröffentlicht wurde. Nach SemVer ist 0.1.0 *neuer* als 0.1.0-rc.1 — die
// Sperre gegen zu große Sprünge griff damit bei jedem, der von rc.1 kam, und
// riet ihm, "zuerst auf 0.1.0 zu aktualisieren", das es noch nicht gab.
//
// Die Freigabepipeline setzt ASYLUM_RELEASE_VERSION auf den Tag. Nur dann
// lässt sich die eigentliche Frage beantworten: Ist der Wert älter als das,
// was gerade veröffentlicht wird?
func TestMinUpgradableFrom(t *testing.T) {
	value := readMinUpgradable(t)

	if value == "" {
		// Keine Grenze — der Regelfall. Nichts weiter zu prüfen.
		t.Log("keine Untergrenze gesetzt, direkte Updates aus jeder Fassung")
		return
	}

	min, err := ParseVersion(value)
	if err != nil {
		t.Fatalf("%s enthält %q — das ist keine gültige Fassung: %v",
			minUpgradableFile, value, err)
	}

	release := os.Getenv("ASYLUM_RELEASE_VERSION")
	if release == "" {
		t.Skip("ohne ASYLUM_RELEASE_VERSION lässt sich nur die Schreibweise prüfen")
	}
	rel, err := ParseVersion(release)
	if err != nil {
		t.Fatalf("ASYLUM_RELEASE_VERSION=%q ist keine gültige Fassung: %v", release, err)
	}

	if min.Compare(rel) > 0 {
		t.Fatalf(
			"min_upgradable_from ist %s und damit neuer als die veröffentlichte Fassung %s.\n"+
				"Niemand könnte dieses Update einspielen: Die Sperre gegen zu große Sprünge\n"+
				"griffe bei jedem. Beachte, dass eine Freigabe nach SemVer neuer ist als ihre\n"+
				"Vorabversionen — %s > %s-rc.1.",
			min, rel, rel, rel)
	}
}

// TestMinUpgradableFallenBeispiel hält die Rechnung fest, die den Fehler
// erzeugt hat — unabhängig davon, was gerade in der Datei steht.
func TestMinUpgradableFallenBeispiel(t *testing.T) {
	min, err := ParseVersion("0.1.0")
	if err != nil {
		t.Fatal(err)
	}
	for _, rc := range []string{"0.1.0-rc.1", "0.1.0-rc.2"} {
		v, err := ParseVersion(rc)
		if err != nil {
			t.Fatal(err)
		}
		if min.Compare(v) <= 0 {
			t.Errorf("0.1.0 müsste neuer als %s sein — sonst greift der Wächter nicht", rc)
		}
		// Und genau so äußert es sich im Update-Weg:
		if !Newer(rc, "0.1.0") {
			t.Errorf("Newer(%q, \"0.1.0\") sollte true sein — das löst die Sperre aus", rc)
		}
	}
}
