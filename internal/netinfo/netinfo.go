// Package netinfo beantwortet eine Frage, die simpler klingt als sie ist:
// Unter welchem Namen und welcher Adresse ist dieser Rechner erreichbar?
//
// os.Hostname() liefert den Namen aus dem Kernel, und der ist auf Debian und
// Ubuntu üblicherweise der kurze: "cloudsrv24", nicht "cloudsrv24.de". Für eine
// Anzeige im Log genügt das. In einer Adresse, die jemand in den Browser eines
// anderen Rechners tippen soll, ist es falsch — der kurze Name löst dort nicht
// auf. Genau daran ist die Ersteinrichtung gescheitert: Der ausgegebene Link
// führte ins Leere, und wer nicht weiß, dass da eine Domainendung fehlt, sucht
// den Fehler beim Panel.
package netinfo

import (
	"context"
	"net"
	"os"
	"sort"
	"strings"
	"time"
)

// lookupTimeout begrenzt die Namensauflösung. Sie hängt an einem Resolver, der
// unerreichbar sein kann; die Ersteinrichtung darf daran nicht hängenbleiben.
// Ohne Antwort bleibt es beim kurzen Namen — schlechter, aber nicht kaputt.
const lookupTimeout = 2 * time.Second

// resolver ist überschreibbar, damit die Tests ohne Netz auskommen.
var resolver interface {
	LookupHost(ctx context.Context, host string) ([]string, error)
	LookupAddr(ctx context.Context, addr string) ([]string, error)
} = net.DefaultResolver

// hostname ist ebenfalls überschreibbar — os.Hostname() liefert im Test das,
// was der Testrechner gerade heißt.
var hostname = os.Hostname

// FQDN liefert den vollqualifizierten Namen des Rechners, also das, was
// "hostname -f" ausgibt. Ist er nicht zu ermitteln, kommt der kurze Name
// zurück; ist auch der nicht zu haben, ein leerer String.
//
// Der Weg ist derselbe wie bei hostname(1): den kurzen Namen auflösen und die
// Adressen rückwärts nachschlagen. Auf Debian und Ubuntu steht die Antwort
// meist schon in /etc/hosts ("203.0.113.7 cloudsrv24.de cloudsrv24"), sonst
// liefert sie der PTR-Eintrag.
func FQDN() string {
	short, err := hostname()
	if err != nil || short == "" {
		return ""
	}
	short = strings.TrimSuffix(short, ".")
	if strings.Contains(short, ".") {
		// Schon vollqualifiziert.
		return short
	}

	ctx, cancel := context.WithTimeout(context.Background(), lookupTimeout)
	defer cancel()

	addrs, err := resolver.LookupHost(ctx, short)
	if err != nil {
		return short
	}
	for _, addr := range addrs {
		names, err := resolver.LookupAddr(ctx, addr)
		if err != nil {
			continue
		}
		for _, name := range names {
			if full, ok := extendsHostname(short, name); ok {
				return full
			}
		}
	}
	return short
}

// extendsHostname prüft, ob name eine Verlängerung von short ist —
// "cloudsrv24" und "cloudsrv24.de" gehören zusammen, "cloudsrv24" und
// "static-203-0-113-7.example.net" nicht.
//
// Ohne diese Prüfung geriete der PTR-Eintrag des Anbieters in den Link. Bei
// vielen Hostern ist das ein generischer Name, der zwar auflöst, aber weder zum
// Zertifikat passt noch dem Nutzer etwas sagt.
func extendsHostname(short, name string) (string, bool) {
	name = strings.TrimSuffix(name, ".")
	if name == "" || !strings.Contains(name, ".") {
		return "", false
	}
	first, _, _ := strings.Cut(name, ".")
	if !strings.EqualFold(first, short) {
		return "", false
	}
	return name, true
}

// Addresses liefert die IP-Adressen, unter denen der Rechner von außen
// erreichbar ist — ohne Loopback und ohne Link-Local. IPv4 steht vorn, weil
// eine v4-Adresse in einer Adresszeile keine eckigen Klammern braucht und sich
// leichter abtippen lässt.
func Addresses() []string {
	ifaceAddrs, err := net.InterfaceAddrs()
	if err != nil {
		return nil
	}
	var v4, v6 []string
	for _, a := range ifaceAddrs {
		ipnet, ok := a.(*net.IPNet)
		if !ok || ipnet.IP.IsLoopback() || ipnet.IP.IsLinkLocalUnicast() {
			continue
		}
		if ipnet.IP.To4() != nil {
			v4 = append(v4, ipnet.IP.String())
		} else {
			v6 = append(v6, ipnet.IP.String())
		}
	}
	sort.Strings(v4)
	sort.Strings(v6)
	return append(v4, v6...)
}
