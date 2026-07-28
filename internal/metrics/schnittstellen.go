package metrics

import (
	"bufio"
	"io"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
)

// Auswahl der Schnittstelle, die in der Übersicht steht.
//
// Bis hierher nahm die Netzwerkkachel schlicht die erste Schnittstelle in
// alphabetischer Reihenfolge. Auf jedem Server mit Docker ist das docker0 —
// eine Brücke, über die nach draußen kein einziges Byte geht. Die Kachel zeigte
// dauerhaft 0 B/s, während die echte Karte Last hatte, und der Fußtext nannte
// mit <code>docker0</code> auch noch einen Namen, der die falsche Angabe
// glaubwürdig machte.
//
// Gesucht ist die Schnittstelle, über die dieser Rechner am Netz hängt. Zwei
// Quellen beantworten das, ohne zu raten:
//
//   - Die Standardroute in /proc/net/route und /proc/net/ipv6_route. Sie sagt,
//     wo der Verkehr nach draußen geht. Das ist die belastbarste Auskunft.
//   - Der Verweis /sys/class/net/<name>/device. Er besteht nur, wenn hinter der
//     Schnittstelle ein Gerät steckt (PCI, USB, virtio). Brücken, veth-Paare,
//     Tunnel und WireGuard erzeugt der Kernel selbst und haben ihn nicht.

// Pfade als Variablen, damit die Tests sie durch Attrappen ersetzen können.
var (
	procRoute   = "/proc/net/route"
	procRoute6  = "/proc/net/ipv6_route"
	sysClassNet = "/sys/class/net"
)

// kuenstlichePraefixe sind Namen, die der Kernel oder ein Dienst für etwas
// vergibt, das keine Leitung nach draußen ist. Die Liste greift nur als
// Rückfall: Steht /sys nicht zur Verfügung (etwa in einem Container mit
// beschnittenem Dateisystem), bleibt nur der Name.
//
// bond und team fehlen bewusst: Ein Bündel mehrerer Karten trägt echten
// Verkehr, und wenn die Standardroute darüber geht, ist es die richtige
// Antwort.
var kuenstlichePraefixe = []string{
	"docker", "br-", "veth", "virbr", "vnet", "vmbr", "lxcbr", "lxdbr",
	"tun", "tap", "wg", "tailscale", "zt", "cni", "flannel", "kube", "cali",
	"nomad", "podman", "ovs-", "dummy", "ifb", "sit", "gre", "erspan", "vxlan",
}

func istKuenstlich(name string) bool {
	for _, p := range kuenstlichePraefixe {
		if strings.HasPrefix(name, p) {
			return true
		}
	}
	return false
}

// hatGeraet meldet, ob unter /sys/class/net/<name>/device ein Gerät steht.
func hatGeraet(name string) bool {
	if name == "" || strings.ContainsAny(name, `/\`) || name == "." || name == ".." {
		return false
	}
	_, err := os.Stat(filepath.Join(sysClassNet, name, "device"))
	return err == nil
}

// standardrouten liefert die Namen der Schnittstellen mit einer Standardroute,
// die mit der niedrigsten Metrik zuerst. Fehlt eine der Dateien, fällt sie
// still aus — dann entscheidet der Verweis auf das Gerät.
func standardrouten() []string {
	var out []string
	if f, err := os.Open(procRoute); err == nil {
		out = append(out, standardroutenIPv4(f)...)
		_ = f.Close()
	}
	if f, err := os.Open(procRoute6); err == nil {
		out = append(out, standardroutenIPv6(f)...)
		_ = f.Close()
	}
	return ohneWiederholung(out)
}

// standardroutenIPv4 liest /proc/net/route. Ziel und Maske aus Nullen sind die
// Standardroute; die Metrik entscheidet, welche zuerst gilt.
//
//	Iface  Destination  Gateway   Flags  RefCnt  Use  Metric  Mask
//	eth0   00000000     0102A8C0  0003   0       0    100     00000000
func standardroutenIPv4(r io.Reader) []string {
	return routenNachMetrik(r, func(f []string) (string, int, bool) {
		if len(f) < 8 || f[1] != "00000000" || f[7] != "00000000" {
			return "", 0, false
		}
		metrik, err := strconv.Atoi(f[6])
		if err != nil {
			metrik = 0
		}
		return f[0], metrik, true
	})
}

// standardroutenIPv6 liest /proc/net/ipv6_route. Die Felder stehen ohne
// Kopfzeile: Ziel, Präfixlänge, Quelle, Quelllänge, nächster Sprung, Metrik,
// RefCnt, Use, Flags, Gerät. Ziel aus Nullen mit Präfixlänge 00 ist ::/0.
func standardroutenIPv6(r io.Reader) []string {
	return routenNachMetrik(r, func(f []string) (string, int, bool) {
		if len(f) < 10 || f[1] != "00" || strings.Trim(f[0], "0") != "" {
			return "", 0, false
		}
		metrik, err := strconv.ParseInt(f[5], 16, 32)
		if err != nil {
			metrik = 0
		}
		return f[len(f)-1], int(metrik), true
	})
}

func routenNachMetrik(r io.Reader, lies func([]string) (string, int, bool)) []string {
	type eintrag struct {
		name   string
		metrik int
	}
	var gefunden []eintrag

	sc := bufio.NewScanner(r)
	for sc.Scan() {
		f := strings.Fields(sc.Text())
		if len(f) == 0 || f[0] == "Iface" {
			continue
		}
		name, metrik, ok := lies(f)
		if !ok || name == "" || name == "lo" {
			continue
		}
		gefunden = append(gefunden, eintrag{name: name, metrik: metrik})
	}

	sort.SliceStable(gefunden, func(i, j int) bool { return gefunden[i].metrik < gefunden[j].metrik })
	out := make([]string, 0, len(gefunden))
	for _, e := range gefunden {
		out = append(out, e.name)
	}
	return out
}

func ohneWiederholung(in []string) []string {
	gesehen := make(map[string]bool, len(in))
	out := make([]string, 0, len(in))
	for _, s := range in {
		if gesehen[s] {
			continue
		}
		gesehen[s] = true
		out = append(out, s)
	}
	return out
}

// hauptschnittstelle liefert den Index der Schnittstelle, die in der Übersicht
// stehen soll, oder -1, wenn es keine gibt. Physical muss vorher gesetzt sein.
//
// Die Reihenfolge der Regeln ist die Reihenfolge der Verlässlichkeit:
//
//  1. Die Standardroute — es sei denn, sie zeigt auf etwas offensichtlich
//     Künstliches. Eine Brücke oder ein Bündel darf hier gewinnen: Wenn der
//     Verkehr dort hinausgeht, ist sie die reale Leitung dieses Rechners.
//  2. Ein Gerät mit Adresse. Ohne Standardroute (abgeschottetes Netz) bleibt
//     die Karte, die tatsächlich angeschlossen ist.
//  3. Ein Gerät ohne Adresse.
//  4. Irgendetwas, das nicht nach Brücke oder Tunnel aussieht.
//  5. Die erste Schnittstelle. Etwas anzuzeigen ist besser als ein Strich.
func hauptschnittstelle(ifaces []Interface, routen []string) int {
	if len(ifaces) == 0 {
		return -1
	}
	for _, name := range routen {
		for i := range ifaces {
			if ifaces[i].Name == name && !istKuenstlich(name) {
				return i
			}
		}
	}
	for i := range ifaces {
		if ifaces[i].Physical && len(ifaces[i].Addrs) > 0 {
			return i
		}
	}
	for i := range ifaces {
		if ifaces[i].Physical {
			return i
		}
	}
	for i := range ifaces {
		if !istKuenstlich(ifaces[i].Name) {
			return i
		}
	}
	return 0
}
