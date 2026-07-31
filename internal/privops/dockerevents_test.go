package privops

import (
	"strings"
	"testing"
)

// Aufgezeichnete Zeilen aus "docker events --format {{json .}}" (Docker 27).
//
// Die dritte Zeile ist der Fall, wegen dessen es diesen Strom gibt: Ein
// Container ist gestorben, und der Exit-Code sagt, ob ordentlich oder
// erschlagen. 137 ist 128+9 — SIGKILL, meistens der OOM-Killer.
const ereignisZeilen = `{"status":"start","id":"aaaa11112222bbbb","Type":"container","Action":"start","Actor":{"ID":"aaaa11112222bbbb","Attributes":{"image":"nginx:alpine","name":"web-proxy-1","com.docker.compose.project":"web","com.docker.compose.service":"proxy"}},"scope":"local","time":1753948800,"timeNano":1753948800123456789}
{"status":"health_status: healthy","id":"aaaa11112222bbbb","Type":"container","Action":"health_status: healthy","Actor":{"ID":"aaaa11112222bbbb","Attributes":{"name":"web-proxy-1"}},"time":1753948830,"timeNano":1753948830000000000}
{"status":"die","id":"cccc11112222dddd","Type":"container","Action":"die","Actor":{"ID":"cccc11112222dddd","Attributes":{"exitCode":"137","name":"web-db-1","com.docker.compose.project":"web","com.docker.compose.service":"db"}},"time":1753948860,"timeNano":1753948860000000000}
{"Type":"volume","Action":"create","Actor":{"ID":"web_daten","Attributes":{"driver":"local"}},"time":1753948870,"timeNano":1753948870000000000}
{"Type":"image","Action":"pull","Actor":{"ID":"nginx:alpine","Attributes":{"name":"nginx:alpine"}},"time":1753948880,"timeNano":1753948880000000000}`

func TestParseDockerEreignis(t *testing.T) {
	var gelesen []DockerEreignis
	for _, zeile := range strings.Split(ereignisZeilen, "\n") {
		if e, ok := parseDockerEreignis(zeile); ok {
			gelesen = append(gelesen, e)
		}
	}
	if len(gelesen) != 5 {
		t.Fatalf("erwartet 5 Ereignisse, gelesen %d", len(gelesen))
	}

	// Der Name statt der Kennung: Eine Kennung ohne Namen ist für den
	// Betrachter keine Auskunft, sondern eine Zeichenfolge.
	if gelesen[0].Objekt != "web-proxy-1" || gelesen[0].Aktion != "start" {
		t.Errorf("erstes Ereignis falsch gelesen: %+v", gelesen[0])
	}
	if gelesen[0].Stack != "web" || gelesen[0].Dienst != "proxy" {
		t.Errorf("die Compose-Labels fehlen: %+v", gelesen[0])
	}
	if gelesen[0].Zeit.IsZero() {
		t.Error("die Zeit fehlt")
	}

	// „health_status: healthy" ist EINE Aktion mit einem Ergebnis dahinter.
	// Getrennt lesen sich beide besser — und die Spalte „Aktion" bleibt schmal.
	if gelesen[1].Aktion != "health_status" || gelesen[1].Zusatz != "healthy" {
		t.Errorf("der Gesundheitszustand wurde nicht getrennt: %+v", gelesen[1])
	}

	// Der Exit-Code ist die Angabe, wegen der jemand diesen Strom öffnet.
	if gelesen[2].Aktion != "die" || !strings.Contains(gelesen[2].Zusatz, "137") {
		t.Errorf("der Exit-Code fehlt: %+v", gelesen[2])
	}

	// Nicht nur Container: Ein angelegtes Volume und ein gezogenes Image
	// gehören dazu — sonst fehlte die Antwort auf „wann kam das Image".
	if gelesen[3].Art != "volume" || gelesen[3].Objekt != "web_daten" {
		t.Errorf("das Volume-Ereignis fehlt: %+v", gelesen[3])
	}
	if gelesen[4].Art != "image" || gelesen[4].Aktion != "pull" {
		t.Errorf("das Image-Ereignis fehlt: %+v", gelesen[4])
	}
}

// Eine Zeile, die sich nicht zerlegen lässt, wird übersprungen statt den Strom
// zu beenden: Docker schreibt bei Warnungen gelegentlich Text dazwischen.
func TestParseDockerEreignisUeberspringtUnlesbares(t *testing.T) {
	for _, zeile := range []string{"", "   ", "WARNING: something", "{kaputt", "[]"} {
		if _, ok := parseDockerEreignis(zeile); ok {
			t.Errorf("%q hätte übersprungen werden müssen", zeile)
		}
	}
}

// Ältere Docker-Fassungen schreiben nur „status" und „id". Der Parser fällt
// darauf zurück, statt eine leere Zeile zu ergeben.
func TestParseDockerEreignisNimmtAlteFelder(t *testing.T) {
	e, ok := parseDockerEreignis(
		`{"status":"stop","id":"aaaa11112222bbbbcccc","time":1753948800}`)
	if !ok {
		t.Fatal("die alte Form wurde nicht gelesen")
	}
	if e.Aktion != "stop" {
		t.Errorf("Aktion = %q, erwartet stop", e.Aktion)
	}
	if e.Art != "container" {
		t.Errorf("ohne Typ ist es ein Container, gelesen %q", e.Art)
	}
	// Ohne Namen die gekürzte Kennung — nicht die volle. Eine 64-stellige
	// Zeichenfolge neben einem Namen ist keine Spalte, die jemand liest.
	if e.Objekt != "aaaa11112222" {
		t.Errorf("Objekt = %q, erwartet die gekürzte Kennung", e.Objekt)
	}
}

// ------------------------------------------------------------------ Ports ---

func TestParseDockerPorts(t *testing.T) {
	faelle := []struct {
		name    string
		spalte  string
		erwarte []Veroeffentlichung
	}{
		{
			"IPv4 und IPv6 sind EINE Veröffentlichung",
			"0.0.0.0:8080->80/tcp, :::8080->80/tcp",
			[]Veroeffentlichung{{Adresse: "0.0.0.0", WirtPort: 8080, ContainerPort: 80, Protokoll: "tcp"}},
		},
		{
			"nur lokal",
			"127.0.0.1:5432->5432/tcp",
			[]Veroeffentlichung{{Adresse: "127.0.0.1", WirtPort: 5432, ContainerPort: 5432, Protokoll: "tcp"}},
		},
		{
			"zufälliger Wirtsport",
			"0.0.0.0:32768->6379/tcp",
			[]Veroeffentlichung{{Adresse: "0.0.0.0", WirtPort: 32768, ContainerPort: 6379, Protokoll: "tcp"}},
		},
		{
			"udp",
			"0.0.0.0:51820->51820/udp",
			[]Veroeffentlichung{{Adresse: "0.0.0.0", WirtPort: 51820, ContainerPort: 51820, Protokoll: "udp"}},
		},
		{
			"zwei verschiedene Ports",
			"0.0.0.0:80->80/tcp, 0.0.0.0:443->443/tcp",
			[]Veroeffentlichung{
				{Adresse: "0.0.0.0", WirtPort: 80, ContainerPort: 80, Protokoll: "tcp"},
				{Adresse: "0.0.0.0", WirtPort: 443, ContainerPort: 443, Protokoll: "tcp"},
			},
		},
	}

	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			got := parseDockerPorts(f.spalte)
			if len(got) != len(f.erwarte) {
				t.Fatalf("erwartet %d Einträge, gelesen %d: %+v", len(f.erwarte), len(got), got)
			}
			for i := range got {
				w := f.erwarte[i]
				if got[i].Adresse != w.Adresse || got[i].WirtPort != w.WirtPort ||
					got[i].ContainerPort != w.ContainerPort || got[i].Protokoll != w.Protokoll {
					t.Errorf("Eintrag %d = %+v, erwartet %+v", i, got[i], w)
				}
			}
		})
	}
}

// Der wichtigste Fall der ganzen Datei: Ein Eintrag OHNE „->" ist nicht
// veröffentlicht. Er sagt nur, worauf der Container selbst hört, und ist vom
// Wirt aus nicht erreichbar. Eine Portübersicht, in der Ports stehen, die keiner
// erreicht, ist keine.
func TestParseDockerPortsLaesstNichtVeroeffentlichteWeg(t *testing.T) {
	got := parseDockerPorts("80/tcp, 443/tcp")
	if len(got) != 0 {
		t.Errorf("EXPOSE ohne Veröffentlichung gehört nicht in die Liste: %+v", got)
	}
	// Gemischt: nur der veröffentlichte zählt.
	got = parseDockerPorts("80/tcp, 0.0.0.0:8080->8080/tcp")
	if len(got) != 1 || got[0].WirtPort != 8080 {
		t.Errorf("aus der gemischten Zeile kam %+v", got)
	}
	// Und ein leeres Feld ist eine leere Liste, kein null.
	if got := parseDockerPorts(""); got == nil || len(got) != 0 {
		t.Errorf("leeres Feld statt null: %+v", got)
	}
}

// Nur eine der beiden Adressfamilien auf „alle" gebunden heißt: von überall
// erreichbar. Die schärfere Aussage gewinnt — sie ist die, die stimmt.
func TestParseDockerPortsNimmtDieOffenereBindung(t *testing.T) {
	got := parseDockerPorts("127.0.0.1:8080->80/tcp, :::8080->80/tcp")
	if len(got) != 1 {
		t.Fatalf("erwartet einen Eintrag, gelesen %+v", got)
	}
	if !istAlleAdressen(got[0].Adresse) {
		t.Errorf("Adresse = %q — über IPv6 ist der Port von überall erreichbar, "+
			"und das ist die Aussage, die zählt", got[0].Adresse)
	}
}
