package metrics

import (
	"reflect"
	"testing"
)

// TestFsSammlerFasstBindMountsZusammen bildet die Einhängetabelle nach, die
// ein echter Server mit der ausgelieferten systemd-Unit zeigt.
//
// Die Härtung der Unit (ProtectSystem, ReadWritePaths) hängt Teile von /
// erneut ein. In /proc/mounts stehen sie als eigene Zeilen mit derselben
// Gerätekennung und denselben Zahlen — in der Übersicht wurden daraus sieben
// Einträge für eine Platte. Auf dem Telefon sind das rund fünfzig Zeilen, die
// alle dasselbe sagen.
func TestFsSammlerFasstBindMountsZusammen(t *testing.T) {
	c := &fsSammler{nachID: make(map[string]int)}

	const vda3 = "/dev/vda3|123|456"
	platte := Filesystem{
		Mount: "/", Device: "/dev/vda3", Type: "ext4",
		Total: 539_000_000_000, Used: 6_300_000_000, UsedPct: 1.2,
	}

	// Die Reihenfolge aus /proc/mounts: "/" zuerst, dann die Bind-Mounts.
	if c.weitererOrt(vda3, "/") {
		t.Fatal("das erste Vorkommen ist kein weiterer Ort")
	}
	c.neu(vda3, platte)

	for _, m := range []string{"/etc", "/home", "/root", "/tmp", "/usr", "/var/lib/asylum", "/var/log/asylum"} {
		if !c.weitererOrt(vda3, m) {
			t.Errorf("%s wurde nicht als weiterer Ort derselben Platte erkannt", m)
		}
	}

	// Eine wirklich eigene Platte bleibt eigenständig.
	const vdb = "/dev/vdb|999|111"
	if c.weitererOrt(vdb, "/mnt/daten") {
		t.Error("ein fremdes Gerät wurde einem bekannten zugeschlagen")
	}
	c.neu(vdb, Filesystem{Mount: "/mnt/daten", Device: "/dev/vdb", Type: "xfs", Total: 1, Used: 0})

	got := c.fertig()
	if len(got) != 2 {
		t.Fatalf("%d Einträge, erwartet 2: %+v", len(got), got)
	}
	if got[0].Mount != "/" {
		t.Errorf("Hauptname = %q, erwartet \"/\"", got[0].Mount)
	}
	want := []string{"/etc", "/home", "/root", "/tmp", "/usr", "/var/lib/asylum", "/var/log/asylum"}
	if !reflect.DeepEqual(got[0].AlsoAt, want) {
		t.Errorf("weitere Orte = %v\nerwartet %v", got[0].AlsoAt, want)
	}
	if len(got[1].AlsoAt) != 0 {
		t.Errorf("die zweite Platte hat weitere Orte bekommen: %v", got[1].AlsoAt)
	}
}

// TestFsSammlerNimmtDenKuerzestenPfad: Steht der Bind-Mount vor dem
// eigentlichen Einhängepunkt in der Tabelle, darf nicht "/var/lib/asylum" als
// Name der Platte hängenbleiben.
func TestFsSammlerNimmtDenKuerzestenPfad(t *testing.T) {
	c := &fsSammler{nachID: make(map[string]int)}
	const id = "/dev/vda3|1|2"

	c.neu(id, Filesystem{Mount: "/var/lib/asylum", Device: "/dev/vda3", Type: "ext4"})
	c.weitererOrt(id, "/")

	got := c.fertig()
	if got[0].Mount != "/" {
		t.Errorf("Hauptname = %q, erwartet \"/\"", got[0].Mount)
	}
	if !reflect.DeepEqual(got[0].AlsoAt, []string{"/var/lib/asylum"}) {
		t.Errorf("weitere Orte = %v", got[0].AlsoAt)
	}
}

// Ohne Bind-Mounts ändert sich nichts.
func TestFsSammlerOhneWiederholung(t *testing.T) {
	c := &fsSammler{nachID: make(map[string]int)}
	c.neu("a", Filesystem{Mount: "/", Type: "ext4"})
	c.neu("b", Filesystem{Mount: "/boot", Type: "ext3"})

	got := c.fertig()
	if len(got) != 2 || got[0].Mount != "/" || got[1].Mount != "/boot" {
		t.Errorf("unerwartet: %+v", got)
	}
	for _, fs := range got {
		if fs.AlsoAt != nil {
			t.Errorf("%s hat weitere Orte, obwohl es keine gibt", fs.Mount)
		}
	}
}
