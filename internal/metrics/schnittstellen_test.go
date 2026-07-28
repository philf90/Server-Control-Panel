package metrics

import (
	"reflect"
	"strings"
	"testing"
)

// TestHauptschnittstelleUeberspringtDockerBruecke hält den Befund fest, der
// diese Auswahl ausgelöst hat: In der Netzwerkkachel stand auf einem Server mit
// Docker dauerhaft docker0 mit 0 B/s.
//
// Die Liste kommt alphabetisch sortiert aus sampleInterfaces, und "docker0"
// steht vor "enp1s0". Gezeigt wurde damit eine Brücke, über die nach draußen
// nichts geht — mit einem Namen daneben, der die falsche Angabe glaubwürdig
// machte.
func TestHauptschnittstelleUeberspringtDockerBruecke(t *testing.T) {
	ifaces := []Interface{
		{Name: "docker0", Addrs: []string{"172.17.0.1/16"}},
		{Name: "enp1s0", Addrs: []string{"203.0.113.10/24"}, Physical: true},
		{Name: "veth3f1a2b"},
	}

	i := hauptschnittstelle(ifaces, []string{"enp1s0"})
	if i < 0 || ifaces[i].Name != "enp1s0" {
		t.Fatalf("gewählt wurde %d (%s), erwartet enp1s0", i, namen(ifaces, i))
	}

	// Auch ohne lesbare Routentabelle darf die Brücke nicht gewinnen: Dann
	// entscheidet der Verweis auf das Gerät.
	if i := hauptschnittstelle(ifaces, nil); ifaces[i].Name != "enp1s0" {
		t.Errorf("ohne Standardroute gewählt: %s, erwartet enp1s0", namen(ifaces, i))
	}
}

// Eine Brücke oder ein Bündel mit der Standardroute ist die reale Leitung
// dieses Rechners und darf gewinnen — auch ohne Verweis auf ein Gerät. Genau so
// hängt ein Hypervisor am Netz: br0 trägt die Adresse, enp1s0 ist ihr Anschluss.
func TestHauptschnittstelleNimmtBrueckeMitStandardroute(t *testing.T) {
	ifaces := []Interface{
		{Name: "br0", Addrs: []string{"192.168.1.10/24"}},
		{Name: "enp1s0", Physical: true},
	}
	if i := hauptschnittstelle(ifaces, []string{"br0"}); ifaces[i].Name != "br0" {
		t.Errorf("gewählt wurde %s, erwartet br0", namen(ifaces, i))
	}

	// bond0 steht bewusst nicht auf der Liste der künstlichen Namen.
	buendel := []Interface{{Name: "bond0", Addrs: []string{"10.0.0.5/24"}}}
	if i := hauptschnittstelle(buendel, []string{"bond0"}); i != 0 {
		t.Errorf("bond0 mit Standardroute wurde übergangen (Index %d)", i)
	}
}

// Bleibt nur Künstliches übrig, wird es gezeigt: Etwas anzuzeigen ist besser
// als ein Strich, und in einem Container ist docker0 vielleicht alles, was es
// gibt.
func TestHauptschnittstelleFaelltZurueck(t *testing.T) {
	if i := hauptschnittstelle([]Interface{{Name: "docker0"}}, nil); i != 0 {
		t.Errorf("Index = %d, erwartet 0", i)
	}
	if i := hauptschnittstelle(nil, []string{"eth0"}); i != -1 {
		t.Errorf("Index = %d, erwartet -1 für eine leere Liste", i)
	}
}

// PrimaryInterface liefert die markierte Schnittstelle, nicht die erste.
func TestPrimaryInterface(t *testing.T) {
	snap := Snapshot{Interfaces: []Interface{
		{Name: "docker0"},
		{Name: "eth0", Primary: true},
	}}
	ifc, ok := snap.PrimaryInterface()
	if !ok || ifc.Name != "eth0" {
		t.Errorf("PrimaryInterface = %q (%t), erwartet eth0", ifc.Name, ok)
	}

	// Ohne Markierung (ältere Ringpuffer-Einträge, Testdaten) bleibt die erste.
	ohne := Snapshot{Interfaces: []Interface{{Name: "eth0"}}}
	if ifc, ok := ohne.PrimaryInterface(); !ok || ifc.Name != "eth0" {
		t.Errorf("ohne Markierung = %q (%t)", ifc.Name, ok)
	}
	if _, ok := (Snapshot{}).PrimaryInterface(); ok {
		t.Error("ohne Schnittstellen darf es keine geben")
	}
}

func TestStandardroutenIPv4(t *testing.T) {
	// Ein Server mit Docker: Die Brücke hat eine Route auf ihr Netz, die
	// Standardroute (Ziel und Maske aus Nullen) gehört der Karte. Die zweite
	// Standardroute hat die höhere Metrik und kommt später.
	tabelle := strings.Join([]string{
		"Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\t\tMTU\tWindow\tIRTT",
		"enp1s0\t00000000\t01FE0A0A\t0003\t0\t0\t100\t00000000\t0\t0\t0",
		"docker0\t000011AC\t00000000\t0001\t0\t0\t0\t0000FFFF\t0\t0\t0",
		"wg0\t00000000\t00000000\t0001\t0\t0\t1000\t00000000\t0\t0\t0",
	}, "\n")

	got := standardroutenIPv4(strings.NewReader(tabelle))
	if want := []string{"enp1s0", "wg0"}; !reflect.DeepEqual(got, want) {
		t.Errorf("Standardrouten = %v, erwartet %v", got, want)
	}
}

func TestStandardroutenIPv6(t *testing.T) {
	tabelle := strings.Join([]string{
		"fe800000000000000000000000000000 40 00000000000000000000000000000000 00 " +
			"00000000000000000000000000000000 00000100 00000000 00000000 00000001     enp1s0",
		"00000000000000000000000000000000 00 00000000000000000000000000000000 00 " +
			"fe800000000000000000000000000001 00000400 00000001 00000000 00000003     enp1s0",
		"00000000000000000000000000000000 00 00000000000000000000000000000000 00 " +
			"00000000000000000000000000000000 ffffffff 00000001 00000000 00200200         lo",
	}, "\n")

	got := standardroutenIPv6(strings.NewReader(tabelle))
	if want := []string{"enp1s0"}; !reflect.DeepEqual(got, want) {
		t.Errorf("Standardrouten = %v, erwartet %v", got, want)
	}
}

func TestIstKuenstlich(t *testing.T) {
	for _, name := range []string{"docker0", "br-1a2b3c", "veth9f", "virbr0", "wg0", "tun0", "vxlan1"} {
		if !istKuenstlich(name) {
			t.Errorf("%q wurde für eine echte Leitung gehalten", name)
		}
	}
	// bond und team tragen echten Verkehr, wlan und Karten ohnehin.
	for _, name := range []string{"eth0", "enp1s0", "eno1", "wlp3s0", "bond0", "team0", "ens18"} {
		if istKuenstlich(name) {
			t.Errorf("%q wurde für künstlich gehalten", name)
		}
	}
}

// hatGeraet darf sich nicht auf einen Namen einlassen, der aus dem Pfad
// ausbricht. Die Namen kommen aus /proc/net/dev und sind dort harmlos — die
// Prüfung steht trotzdem, weil der Pfad zusammengesetzt wird.
func TestHatGeraetLehntPfadeAb(t *testing.T) {
	for _, name := range []string{"", ".", "..", "../../etc", `eth0\..`} {
		if hatGeraet(name) {
			t.Errorf("%q wurde als Gerät angenommen", name)
		}
	}
}

func namen(ifaces []Interface, i int) string {
	if i < 0 || i >= len(ifaces) {
		return "—"
	}
	return ifaces[i].Name
}
