package privops

import (
	"fmt"
	"path/filepath"
	"sort"
	"strings"

	"gopkg.in/yaml.v3"
)

// Der Compose-Prüfer — der sicherheitskritische Kern des Moduls Docker.
//
// Er beantwortet eine Frage: Öffnet dieser Stack einen Weg vom Container auf
// den Wirt? Denn genau das ist mit Compose in einer Zeile getan —
// „privileged: true" oder „- /var/run/docker.sock:/var/run/docker.sock", und
// der Container ist root auf dem Server.
//
// **Was der Prüfer ist und was er nicht ist.** Er ist kein Rechtefilter gegen
// die Owner-Rolle: Wer dieses Modul bedienen darf, darf auch Pakete
// installieren und Dateien als root schreiben — auf dem Weg käme er ohnehin
// dahin. Er ist ein Geländer gegen den häufigsten Fall, nämlich die aus einem
// Forum kopierte compose.yaml, in der eine solche Zeile steht, ohne dass
// jemand sie liest. Deshalb nennt eine Ablehnung immer Dienst, Feld und Grund:
// Sie soll erklären und nicht bloß sperren.
//
// **Geprüft wird die GERENDERTE Konfiguration** (Entscheidung E4 in
// docs/17-docker.md), also die Ausgabe von „docker compose config". Das ist die
// wichtigste Einzelentscheidung dieser Stufe: „extends", YAML-Anker, „env_file"
// und „.env" können ein „privileged: true" an einer Prüfung der Rohdatei
// vorbeischmuggeln —
//
//	x-basis: &basis
//	  privileged: true
//	services:
//	  web:
//	    <<: *basis
//
// steht in der Rohdatei nirgends als „privileged" unter einem Dienst, in der
// gerenderten Fassung aber sehr wohl. Compose löst all das auf, bevor es hier
// gelesen wird.
//
// **Die Rohprüfung bleibt trotzdem, und zwar davor.** Rendern liest fremde
// Dateien: „extends: {file: …}" zieht eine beliebige YAML in die Ausgabe, und
// die Ausgabe zeigt das Panel an. Ohne Vorprüfung wäre der Prüfer selbst der
// Weg, /etc/asylum/config.yaml zu lesen. Deshalb wird die Rohdatei zuerst auf
// Verweise nach draußen geprüft — und erst danach gerendert.
//
// **Unbekanntes ist kein Freibrief.** Ein Feld, das der Prüfer nicht kennt,
// meldet er als „nicht geprüft" statt es zu übergehen. Dieselbe Haltung wie in
// configcheck.go: „Eine Datei, für die es kein Prüfprogramm gibt, wird als
// 'nicht geprüft' gemeldet — nicht als 'in Ordnung'."

// Befundart unterscheidet, was ein Fund für den Vorgang bedeutet.
const (
	// BefundAblehnung hält den Vorgang an. Weder geschrieben noch gestartet.
	BefundAblehnung = "ablehnung"
	// BefundAussen ist ein Bind-Mount aus dem Stack-Verzeichnis heraus. Das ist
	// ein legitimer, häufiger Fall („/srv/daten:/data") und zugleich der Weg,
	// über den ein Container an fremde Daten kommt — deshalb keine Ablehnung,
	// sondern eine Rückfrage der Stufe 3.
	BefundAussen = "aussen"
	// BefundHinweis ist alles, was auffällt, ohne zu sperren: ein Feld, das der
	// Prüfer nicht kennt, oder ein Port, der dem Panel gehört.
	BefundHinweis = "hinweis"
)

// ComposeBefund ist ein einzelner Fund.
//
// Dienst und Feld stehen getrennt, weil eine Meldung ohne sie unbrauchbar ist:
// „Der Stack wurde abgelehnt" schickt jemanden auf die Suche, „web: privileged"
// zeigt auf die Zeile.
type ComposeBefund struct {
	Art    string `json:"art"`
	Dienst string `json:"dienst"`
	Feld   string `json:"feld"`
	Wert   string `json:"wert"`
	Grund  string `json:"grund"`
}

// ComposePruefung ist das Ergebnis. Der Aufbau folgt ConfigCheckResult
// (configcheck.go): „geprüft" und „in Ordnung" sind zwei verschiedene Fragen.
type ComposePruefung struct {
	// Geprueft sagt, ob überhaupt eine Prüfung stattgefunden hat. Ohne sie ist
	// OK bedeutungslos.
	Geprueft bool `json:"geprueft"`
	// Gerendert sagt, ob gegen die aufgelöste Fassung geprüft wurde. Ist es
	// falsch, wurde nur die Rohdatei gelesen — dann können Anker, extends und
	// env_file an der Prüfung vorbei, und das gehört gesagt.
	Gerendert bool `json:"gerendert"`
	OK        bool `json:"ok"`
	// Meldung ist die Ausgabe von "docker compose config", wenn das Rendern
	// scheiterte — meist ein Formfehler mit Zeilennummer, also genau das, was
	// jemand im Editor sucht.
	Meldung string          `json:"meldung,omitempty"`
	Dienste []string        `json:"dienste"`
	Befunde []ComposeBefund `json:"befunde"`
}

// Ablehnungen sind die Funde, die den Vorgang anhalten.
func (p ComposePruefung) Ablehnungen() []ComposeBefund { return p.nachArt(BefundAblehnung) }

// Aussenmounts sind die Bind-Mounts aus dem Stack-Verzeichnis heraus.
func (p ComposePruefung) Aussenmounts() []ComposeBefund { return p.nachArt(BefundAussen) }

// Hinweise sind die Funde, die nichts anhalten.
func (p ComposePruefung) Hinweise() []ComposeBefund { return p.nachArt(BefundHinweis) }

func (p ComposePruefung) nachArt(art string) []ComposeBefund {
	out := []ComposeBefund{}
	for _, b := range p.Befunde {
		if b.Art == art {
			out = append(out, b)
		}
	}
	return out
}

// sperrpfade sind Wirtspfade, die in keinen Container gehören.
//
// Die Liste ist bewusst kurz und nennt nur, wovon die Weitergabe den Server
// selbst kostet. Sie ersetzt nicht die Rückfrage bei jedem Mount nach draußen —
// sie ist die Handvoll Fälle, bei denen auch eine Rückfrage die falsche Antwort
// wäre, weil niemand sie sinnvoll bejahen kann.
var sperrpfade = []struct {
	pfad  string
	grund string
}{
	{"/var/run/docker.sock", "Der Docker-Socket im Container heißt: Der Container darf jeden anderen Container starten, auch einen mit dem ganzen Wirtsdateisystem. Wer den Socket hat, hat die Maschine."},
	{"/run/docker.sock", "Der Docker-Socket im Container heißt: Der Container darf jeden anderen Container starten. Wer den Socket hat, hat die Maschine."},
	{"/etc/shadow", "Die Datei mit den Passwort-Hashes des Servers."},
	{"/etc/sudoers", "Wer sie schreiben kann, macht sich zu root."},
	{"/root", "Das Heimatverzeichnis von root, samt seiner SSH-Schlüssel."},
	{"/etc/ssh", "Die Wirtsschlüssel des SSH-Dienstes."},
	{"/var/lib/asylum", "Die Datenbank des Panels — Sitzungen, Passwort-Hashes, Tokens."},
	{"/etc/asylum", "Die Konfiguration des Panels."},
	{"/boot", "Kernel und Bootloader."},
	{"/sys/fs/cgroup", "Über die Cgroup-Hierarchie lässt sich auf dem Wirt Code ausführen."},
}

// gefaehrlicheFaehigkeiten sind die Linux-Capabilities, mit denen ein Container
// den Namensraum verlässt.
var gefaehrlicheFaehigkeiten = map[string]string{
	"ALL":             "Alle Fähigkeiten auf einmal — das ist privileged mit anderen Worten.",
	"SYS_ADMIN":       "Die Sammelfähigkeit schlechthin: mount, pivot_root, Cgroup-Schreibzugriff. Der klassische Ausbruchsweg.",
	"SYS_PTRACE":      "Erlaubt, sich in fremde Prozesse zu hängen — auch in die des Wirts, wenn der PID-Namensraum geteilt wird.",
	"SYS_MODULE":      "Erlaubt, Kernelmodule zu laden. Danach gibt es keine Grenze mehr.",
	"DAC_READ_SEARCH": "Hebt die Rechteprüfung beim Lesen auf und erlaubt zusammen mit open_by_handle_at den Zugriff auf das ganze Wirtsdateisystem.",
	"SYS_BOOT":        "Erlaubt, den Wirt neu zu starten.",
	"SYS_RAWIO":       "Roher Zugriff auf Ein-/Ausgabeports und Speicher.",
}

// bekannteDienstfelder sind die Compose-Felder, zu denen der Prüfer eine Meinung
// hat oder von denen er weiß, dass sie harmlos sind.
//
// Die Liste ist der Preis für die Zusage „unbekannte Felder sind kein
// Freibrief": Was nicht darin steht, wird als „nicht geprüft" gemeldet. Sie
// wächst mit Compose, und das ist Absicht — eine Prüfung, die jedes neue Feld
// stillschweigend durchlässt, sagt am Ende „in Ordnung" zu allem.
var bekannteDienstfelder = map[string]bool{
	"annotations": true, "attach": true, "blkio_config": true, "build": true,
	"cap_add": true, "cap_drop": true, "cgroup": true, "cgroup_parent": true,
	"command": true, "configs": true, "container_name": true, "cpu_count": true,
	"cpu_percent": true, "cpu_period": true, "cpu_quota": true, "cpu_rt_period": true,
	"cpu_rt_runtime": true, "cpu_shares": true, "cpus": true, "cpuset": true,
	"credential_spec": true, "depends_on": true, "deploy": true, "develop": true,
	"device_cgroup_rules": true, "devices": true, "dns": true, "dns_opt": true,
	"dns_search": true, "domainname": true, "entrypoint": true, "env_file": true,
	"environment": true, "expose": true, "extends": true, "external_links": true,
	"extra_hosts": true, "gpus": true, "group_add": true, "healthcheck": true,
	"hostname": true, "image": true, "init": true, "ipc": true, "isolation": true,
	"labels": true, "label_file": true, "links": true, "logging": true,
	"mac_address": true, "mem_limit": true, "mem_reservation": true,
	"mem_swappiness": true, "memswap_limit": true, "network_mode": true,
	"networks": true, "oom_kill_disable": true, "oom_score_adj": true,
	"pid": true, "pids_limit": true, "platform": true, "ports": true,
	"post_start": true, "pre_stop": true, "privileged": true, "profiles": true,
	"pull_policy": true, "pull_refresh_after": true, "read_only": true,
	"restart": true, "runtime": true, "scale": true, "secrets": true,
	"security_opt": true, "shm_size": true, "stdin_open": true, "stop_grace_period": true,
	"stop_signal": true, "storage_opt": true, "sysctls": true, "tmpfs": true,
	"tty": true, "ulimits": true, "user": true, "userns_mode": true,
	"uts": true, "volumes": true, "volumes_from": true, "working_dir": true,
}

// composeDatei ist die gerenderte Konfiguration, soweit der Prüfer sie liest.
//
// Alle Felder stehen als yaml.Node oder als nachsichtiger Typ: Compose hat je
// nach Fassung die kurze oder die lange Form, und ein Parser, der nur eine
// kennt, hält die andere für leer — und meldet dann „in Ordnung" zu einem
// Mount, den er nicht gesehen hat.
type composeDatei struct {
	Services map[string]map[string]yaml.Node `yaml:"services"`
	// Volumes ist die OBERSTE Ebene, und sie steht hier wegen eines Ausbruchs,
	// den der Prüfer zuerst nicht gesehen hat: Ein „benanntes" Volume mit
	// driver_opts.device ist in Wahrheit ein Bind-Mount.
	Volumes map[string]composeVolume `yaml:"volumes"`
}

// composeVolume ist ein Eintrag der obersten volumes-Ebene.
//
// Der local-Treiber nimmt dieselben Angaben wie mount(8): Mit „type: none",
// „o: bind" und „device: /" entsteht ein Volume, das das ganze
// Wirtsdateisystem einhängt — und im Dienst steht dann nur „- hack:/host",
// also etwas, das wie ein harmloses benanntes Volume aussieht.
//
//	volumes:
//	  hack:
//	    driver: local
//	    driver_opts: {type: none, device: /, o: bind}
//
// Genau dieser Fall ging beim Angriffsdurchgang (Schritt 9) durch den Prüfer.
type composeVolume struct {
	Driver     string            `yaml:"driver"`
	DriverOpts map[string]string `yaml:"driver_opts"`
}

// PruefeComposeText prüft einen Compose-Text.
//
// stackVerzeichnis ist die Wurzel, gegen die „innen" und „außen" bemessen wird;
// panelPort der Port des Panels, damit eine Kollision auffällt, bevor sie den
// Zugang kostet. gerendert sagt, ob der Text schon von Compose aufgelöst wurde —
// er entscheidet nur über das gleichnamige Feld, nicht über die Prüfung selbst.
//
// Ausdrücklich eine freie Funktion und keine Methode: Sie ruft kein Kommando
// auf, und ein Test kann sie ohne System aufrufen. Der Prüfer ist die Stelle,
// an der ein Testfall am meisten wert ist.
func PruefeComposeText(text, stackVerzeichnis string, panelPort int, gerendert bool) ComposePruefung {
	p := ComposePruefung{Geprueft: true, Gerendert: gerendert, OK: true, Dienste: []string{}, Befunde: []ComposeBefund{}}

	var datei composeDatei
	if err := yaml.Unmarshal([]byte(text), &datei); err != nil {
		// Kein YAML heißt: nicht geprüft. Nicht „in Ordnung", und auch nicht
		// „abgelehnt" — der Unterschied zählt, weil ein Formfehler etwas
		// anderes ist als ein Ausbruchsversuch.
		return ComposePruefung{
			Geprueft: false, Gerendert: gerendert,
			Meldung: err.Error(), Dienste: []string{}, Befunde: []ComposeBefund{},
		}
	}
	if len(datei.Services) == 0 {
		return ComposePruefung{
			Geprueft: false, Gerendert: gerendert,
			Meldung: "die Datei nennt keinen Dienst unter services:",
			Dienste: []string{}, Befunde: []ComposeBefund{},
		}
	}

	for name := range datei.Services {
		p.Dienste = append(p.Dienste, name)
	}
	sort.Strings(p.Dienste)

	// Die benannten Volumes zuerst auflösen: Was sie in Wahrheit einhängen,
	// entscheidet später über jeden Dienst, der sie benutzt.
	bindVolumes := bindVolumesAus(datei.Volumes)

	for _, dienst := range p.Dienste {
		pruefeDienst(&p, dienst, datei.Services[dienst], stackVerzeichnis, panelPort, bindVolumes)
	}

	// Die Reihenfolge ist die der Dringlichkeit und nicht die des Auftretens:
	// Wer eine Ablehnung liest, soll sie nicht unter drei Hinweisen suchen.
	rang := map[string]int{BefundAblehnung: 0, BefundAussen: 1, BefundHinweis: 2}
	sort.SliceStable(p.Befunde, func(i, j int) bool {
		return rang[p.Befunde[i].Art] < rang[p.Befunde[j].Art]
	})

	p.OK = len(p.Ablehnungen()) == 0
	return p
}

// pruefeDienst prüft einen einzelnen Dienst.
func pruefeDienst(p *ComposePruefung, dienst string, felder map[string]yaml.Node, wurzel string, panelPort int, bindVolumes map[string]string) {
	fuege := func(art, feld, wert, grund string) {
		p.Befunde = append(p.Befunde, ComposeBefund{
			Art: art, Dienst: dienst, Feld: feld, Wert: wert, Grund: grund,
		})
	}

	for feld, knoten := range felder {
		// Kleinschreibung: YAML unterscheidet zwar, Compose auch — aber ein
		// „Privileged" wäre ein unbekanntes Feld und damit ein Hinweis. Er
		// stünde dann als „nicht geprüft" da statt stillschweigend durchzugehen.
		if !bekannteDienstfelder[feld] {
			fuege(BefundHinweis, feld, knotenText(knoten),
				"Dieses Feld kennt der Prüfer nicht. Es wurde nicht geprüft — nicht für in Ordnung befunden.")
		}
	}

	if wahr(felder["privileged"]) {
		fuege(BefundAblehnung, "privileged", "true",
			"Ein privilegierter Container hat auf dem Wirt praktisch die Rechte von root. Der Weg nach draußen ist damit offen, ohne dass es dafür noch eine Lücke bräuchte.")
	}

	// Geteilte Namensräume. Compose schreibt sie als "host" oder als
	// "container:<id>"; der zweite Fall ist enger, aber immer noch ein Weg in
	// einen fremden Namensraum — geprüft wird deshalb auf das Wort "host".
	// "uts: host" steht bewusst NICHT dabei: Es teilt nur den Hostnamen und
	// öffnet keinen Weg nach draußen. Eine Prüfung, die auch Harmloses ablehnt,
	// wird umgangen statt gelesen.
	for _, feld := range []string{"pid", "ipc", "userns_mode", "cgroup"} {
		if wert := knotenText(felder[feld]); istHost(wert) {
			fuege(BefundAblehnung, feld, wert,
				"Der Container teilt sich diesen Namensraum mit dem Wirt. Damit fällt genau die Trennung weg, die einen Container ausmacht.")
		}
	}
	if wert := knotenText(felder["network_mode"]); istHost(wert) {
		fuege(BefundAblehnung, "network_mode", wert,
			"Mit dem Netz des Wirts umgeht der Container jede Portveröffentlichung — und damit auch die Übersicht, die das Panel über offene Ports hat.")
	}

	if len(listeAus(felder["devices"])) > 0 {
		fuege(BefundAblehnung, "devices", strings.Join(listeAus(felder["devices"]), ", "),
			"Ein durchgereichtes Gerät ist roher Zugriff auf die Hardware des Wirts. Eine Platte als Gerät im Container heißt: jede Datei des Servers, ohne Rechteprüfung.")
	}
	// device_cgroup_rules ist „devices" ohne das Wort. Es erlaubt den Zugriff
	// auf Gerätenummern, und die Fähigkeit MKNOD hat ein Container von Haus aus
	// — er legt sich den Geräteknoten also selbst an. „c *:* rwm" ist damit die
	// Platte des Wirts. Ging beim Angriffsdurchgang durch.
	if len(listeAus(felder["device_cgroup_rules"])) > 0 {
		fuege(BefundAblehnung, "device_cgroup_rules", strings.Join(listeAus(felder["device_cgroup_rules"]), ", "),
			"Diese Regeln erlauben den Zugriff auf Geräte des Wirts. Den Geräteknoten dazu legt der Container selbst an — die Fähigkeit MKNOD hat er von Haus aus. Damit ist die Platte des Servers lesbar.")
	}
	for _, quelle := range listeAus(felder["volumes_from"]) {
		// Ein Dienst AUS DIESER DATEI ist selbst geprüft; ein fremder Container
		// nicht. Was der mitbringt, steht nirgends — auch nicht der Socket, den
		// er vielleicht eingehängt hat.
		if strings.HasPrefix(quelle, "container:") {
			fuege(BefundAblehnung, "volumes_from", quelle,
				"Der Container übernimmt die Einhängungen eines FREMDEN Containers. Was dabei hereinkommt, steht nicht in dieser Datei und ist damit nicht geprüft.")
			continue
		}
		fuege(BefundHinweis, "volumes_from", quelle,
			"Der Container übernimmt die Einhängungen eines anderen Dienstes dieser Datei.")
	}

	// build: „docker compose up" baut, wenn ein Bauabschnitt dasteht. Ein
	// Kontext außerhalb des Stack-Verzeichnisses kopiert fremde Dateien in ein
	// Abbild — bei „context: /" das halbe Dateisystem.
	if bau := felder["build"]; bau.Kind != 0 {
		for feld, wert := range bauPfade(bau) {
			if wert == "" {
				continue
			}
			ziel := wert
			if !filepath.IsAbs(ziel) {
				ziel = filepath.Join(wurzel, ziel)
			}
			if wurzel != "" && !innerhalb(ziel, wurzel) {
				fuege(BefundAblehnung, "build."+feld, wert,
					"Der Bauabschnitt zeigt aus dem Stack-Verzeichnis heraus. Alles darin landet im Abbild — bei einem Kontext auf einem hohen Verzeichnis ist das ein Abzug fremder Dateien.")
			}
		}
	}

	for _, faehigkeit := range listeAus(felder["cap_add"]) {
		schluessel := strings.ToUpper(strings.TrimPrefix(strings.ToUpper(faehigkeit), "CAP_"))
		if grund, schlimm := gefaehrlicheFaehigkeiten[schluessel]; schlimm {
			fuege(BefundAblehnung, "cap_add", faehigkeit, grund)
		}
	}

	for _, opt := range listeAus(felder["security_opt"]) {
		flach := strings.ToLower(strings.ReplaceAll(opt, " ", ""))
		switch {
		case strings.HasPrefix(flach, "apparmor") && strings.Contains(flach, "unconfined"):
			fuege(BefundAblehnung, "security_opt", opt,
				"Ohne AppArmor-Profil fällt eine der beiden Absicherungen weg, die einen Container einsperren.")
		case strings.HasPrefix(flach, "seccomp") && strings.Contains(flach, "unconfined"):
			fuege(BefundAblehnung, "security_opt", opt,
				"Ohne seccomp-Filter stehen dem Container alle Systemaufrufe offen, auch die, mit denen man ausbricht.")
		case strings.Contains(flach, "systempaths=unconfined"):
			fuege(BefundAblehnung, "security_opt", opt,
				"Damit werden /proc und /sys wieder beschreibbar — der kürzeste Weg aus dem Container heraus.")
		}
	}

	for _, mount := range mountsAus(felder["volumes"]) {
		// Ein „benanntes" Volume, das in Wahrheit ein Bind ist, wird hier zu
		// dem, was es ist. Ohne diesen Schritt geht das ganze
		// Wirtsdateisystem als harmloser Name durch.
		if !mount.bind {
			if wirtspfad, ist := bindVolumes[mount.quelle]; ist {
				mount.roh += " — das Volume " + mount.quelle + " hängt " + wirtspfad + " ein"
				mount.bind = true
				mount.quelle = wirtspfad
			}
		}
		pruefeMount(p, dienst, mount, wurzel)
	}

	for _, port := range portsAus(felder["ports"]) {
		if port.host == panelPort && panelPort > 0 {
			fuege(BefundHinweis, "ports", port.roh,
				fmt.Sprintf("Port %d gehört dem Panel. Ein Dienst, der ihn belegt, nimmt der Oberfläche den Zugang — und zwar in dem Augenblick, in dem der Stack startet.", panelPort))
		}
	}
}

// pruefeMount entscheidet über eine einzelne Einhängung.
//
// Drei Ausgänge, und die Reihenfolge ist die Entscheidung: Ein Sperrpfad wird
// abgelehnt, ein Pfad außerhalb des Stack-Verzeichnisses löst eine Rückfrage
// aus, ein Volume oder ein Pfad darin geht durch. Ein benanntes Volume ist kein
// Wirtspfad und deshalb hier gar nicht die Frage.
func pruefeMount(p *ComposePruefung, dienst string, m mountAngabe, wurzel string) {
	if !m.bind {
		return
	}
	// Relative Quellen ZUERST auflösen, und zwar gegen das Stack-Verzeichnis.
	// Ohne diesen Schritt ging „- ../../../../var/run/docker.sock:/…" durch:
	// Der Vergleich mit der Sperrliste traf nicht, weil dort absolute Pfade
	// stehen, und danach galt „nicht absolut" als „liegt innen". Compose löst
	// solche Pfade genauso auf — das hier ist dieselbe Rechnung, nur früher.
	quelle := filepath.Clean(m.quelle)
	if !filepath.IsAbs(quelle) && wurzel != "" {
		quelle = filepath.Clean(filepath.Join(wurzel, quelle))
	}

	for _, sperre := range sperrpfade {
		if quelle == sperre.pfad || strings.HasPrefix(quelle, sperre.pfad+"/") {
			p.Befunde = append(p.Befunde, ComposeBefund{
				Art: BefundAblehnung, Dienst: dienst, Feld: "volumes", Wert: m.roh,
				Grund: sperre.grund,
			})
			return
		}
	}

	// Das ganze Dateisystem einzuhängen ist kein Sonderfall der Sperrliste,
	// sondern jeder ihrer Einträge auf einmal.
	if quelle == "/" {
		p.Befunde = append(p.Befunde, ComposeBefund{
			Art: BefundAblehnung, Dienst: dienst, Feld: "volumes", Wert: m.roh,
			Grund: "Das gesamte Wirtsdateisystem im Container. Damit ist jede Datei des Servers erreichbar, einschließlich aller Schlüssel und der Panel-Datenbank.",
		})
		return
	}

	if wurzel != "" && innerhalb(quelle, wurzel) {
		// Ein Pfad im eigenen Verzeichnis, der über einen symbolischen Verweis
		// hinausführt, liegt nicht darin. Ein Verweis wird erst beim Einhängen
		// aufgelöst, und dann hängt der Container am Ziel — nicht am Verweis.
		if echt, err := filepath.EvalSymlinks(quelle); err == nil && !innerhalb(echt, wurzel) {
			p.Befunde = append(p.Befunde, ComposeBefund{
				Art: BefundAblehnung, Dienst: dienst, Feld: "volumes", Wert: m.roh,
				Grund: "Dieser Pfad liegt zwar im Stack-Verzeichnis, ist aber ein symbolischer " +
					"Verweis auf " + echt + ". Eingehängt wird das Ziel.",
			})
		}
		return
	}
	// Ohne bekannte Wurzel lässt sich „innen" nicht bestimmen. Dann gilt jeder
	// absolute Pfad als außen — die vorsichtigere der beiden Auslegungen.
	if !filepath.IsAbs(quelle) {
		return
	}

	p.Befunde = append(p.Befunde, ComposeBefund{
		Art: BefundAussen, Dienst: dienst, Feld: "volumes", Wert: m.roh,
		Grund: "Dieser Pfad liegt außerhalb des Stack-Verzeichnisses. Der Container kann darin lesen" +
			schreibzusatz(m.nurLesen) + " — das ist häufig gewollt und zugleich der Weg, über den ein Container an fremde Daten kommt.",
	})
}

func schreibzusatz(nurLesen bool) string {
	if nurLesen {
		return ""
	}
	return " und schreiben"
}

// innerhalb sagt, ob pfad unter wurzel liegt.
//
// Über filepath.Rel und nicht über HasPrefix: „/opt/asylum/stacks-fremd" hat
// „/opt/asylum/stacks" als Präfix und liegt trotzdem nicht darin. Genau diese
// Verwechslung ist der klassische Fehler einer Pfadwache.
func innerhalb(pfad, wurzel string) bool {
	rel, err := filepath.Rel(filepath.Clean(wurzel), filepath.Clean(pfad))
	if err != nil {
		return false
	}
	return rel != ".." && !strings.HasPrefix(rel, ".."+string(filepath.Separator))
}

// bindVolumesAus löst die oberste volumes-Ebene in Wirtspfade auf.
//
// Nur der local-Treiber mit „o: bind" (oder „type: none") ist gemeint: Das ist
// die Schreibweise, mit der ein benanntes Volume in Wahrheit ein Bind-Mount
// wird. Andere Treiber (nfs, cifs) hängen etwas ein, das nicht auf dieser
// Platte liegt — sie sind eine andere Frage und hier nicht die.
func bindVolumesAus(volumes map[string]composeVolume) map[string]string {
	out := map[string]string{}
	for name, v := range volumes {
		if v.Driver != "" && v.Driver != "local" {
			continue
		}
		geraet := v.DriverOpts["device"]
		if geraet == "" || !strings.HasPrefix(geraet, "/") {
			continue
		}
		opt := strings.ToLower(v.DriverOpts["o"])
		art := strings.ToLower(v.DriverOpts["type"])
		if strings.Contains(opt, "bind") || art == "none" || art == "bind" {
			out[name] = geraet
		}
	}
	return out
}

// bauPfade liest die Pfadangaben eines build-Abschnitts.
//
// Beide Schreibweisen: „build: ." und „build: {context: ., dockerfile: X}".
func bauPfade(k yaml.Node) map[string]string {
	switch k.Kind {
	case yaml.ScalarNode:
		return map[string]string{"context": k.Value}
	case yaml.MappingNode:
		var lang struct {
			Context    string `yaml:"context"`
			Dockerfile string `yaml:"dockerfile"`
		}
		if k.Decode(&lang) != nil {
			return nil
		}
		return map[string]string{"context": lang.Context, "dockerfile": lang.Dockerfile}
	default:
		return nil
	}
}

// ---------------------------------------------------------------- Formen ---
//
// Compose kennt für Einhängungen und Ports je zwei Schreibweisen, und die
// gerenderte Fassung benutzt je nach Compose-Fassung die eine oder die andere.
// Ein Parser, der nur eine kennt, hält die andere für leer — und meldet dann
// „in Ordnung" zu einem Mount, den er nie gesehen hat. Deshalb beide.

type mountAngabe struct {
	roh      string
	quelle   string
	bind     bool
	nurLesen bool
}

// mountsAus liest „volumes:" in beiden Formen.
//
//	volumes:
//	  - /srv/daten:/data:ro                      (kurz)
//	  - type: bind                               (lang)
//	    source: /srv/daten
//	    target: /data
//	    read_only: true
func mountsAus(knoten yaml.Node) []mountAngabe {
	out := []mountAngabe{}
	if knoten.Kind != yaml.SequenceNode {
		return out
	}
	for _, eintrag := range knoten.Content {
		switch eintrag.Kind {
		case yaml.ScalarNode:
			if m, ok := kurzerMount(eintrag.Value); ok {
				out = append(out, m)
			}
		case yaml.MappingNode:
			var lang struct {
				Type     string `yaml:"type"`
				Source   string `yaml:"source"`
				Target   string `yaml:"target"`
				ReadOnly bool   `yaml:"read_only"`
			}
			if eintrag.Decode(&lang) != nil {
				continue
			}
			// Ohne "type" entscheidet die Quelle: Ein absoluter oder relativer
			// Pfad ist ein Bind, ein Name ist ein Volume. Dieselbe Regel, die
			// Compose selbst anwendet.
			bind := lang.Type == "bind" || (lang.Type == "" && istWirtspfad(lang.Source))
			out = append(out, mountAngabe{
				roh:      lang.Source + ":" + lang.Target,
				quelle:   lang.Source,
				bind:     bind,
				nurLesen: lang.ReadOnly,
			})
		}
	}
	return out
}

// kurzerMount zerlegt „/srv/daten:/data:ro".
//
// Der Doppelpunkt ist auch in einem Windows-Pfad ein Trennzeichen, aber das
// Panel läuft auf Linux — hier ist er eindeutig. Ein Eintrag ohne Doppelpunkt
// ist ein anonymes Volume und kein Wirtspfad.
func kurzerMount(s string) (mountAngabe, bool) {
	teile := strings.Split(s, ":")
	if len(teile) < 2 {
		return mountAngabe{}, false
	}
	quelle := teile[0]
	if !istWirtspfad(quelle) {
		// Ein benanntes Volume: "daten:/var/lib/daten". Kein Wirtspfad.
		return mountAngabe{roh: s, quelle: quelle, bind: false}, true
	}
	nurLesen := false
	for _, opt := range teile[2:] {
		if opt == "ro" {
			nurLesen = true
		}
	}
	return mountAngabe{roh: s, quelle: quelle, bind: true, nurLesen: nurLesen}, true
}

// istWirtspfad unterscheidet einen Wirtspfad von einem Volumenamen.
//
// Die Regel ist Compose eigene: Was mit /, ./, ../ oder ~ beginnt, ist ein Pfad
// auf dem Wirt; alles andere ein benanntes Volume. Der Unterschied entscheidet,
// ob überhaupt eine Frage zu stellen ist — ein benanntes Volume verwaltet
// Docker, und dort liegt nichts vom Server.
func istWirtspfad(s string) bool {
	return strings.HasPrefix(s, "/") || strings.HasPrefix(s, "./") ||
		strings.HasPrefix(s, "../") || strings.HasPrefix(s, "~")
}

type portAngabe struct {
	roh  string
	host int
}

// portsAus liest „ports:" in beiden Formen: „8080:80" und „published: 8080".
func portsAus(knoten yaml.Node) []portAngabe {
	out := []portAngabe{}
	if knoten.Kind != yaml.SequenceNode {
		return out
	}
	for _, eintrag := range knoten.Content {
		switch eintrag.Kind {
		case yaml.ScalarNode:
			out = append(out, portAngabe{roh: eintrag.Value, host: hostPortAus(eintrag.Value)})
		case yaml.MappingNode:
			var lang struct {
				Published any `yaml:"published"`
				Target    any `yaml:"target"`
			}
			if eintrag.Decode(&lang) != nil {
				continue
			}
			roh := fmt.Sprintf("%v:%v", lang.Published, lang.Target)
			out = append(out, portAngabe{roh: roh, host: zahlAus(fmt.Sprintf("%v", lang.Published))})
		}
	}
	return out
}

// hostPortAus nimmt den Wirtsport aus „8080:80", „127.0.0.1:8080:80" oder „80".
//
// Der Wirtsport ist der vorletzte Teil, wenn es mehr als einen gibt — bei einem
// einzelnen Teil veröffentlicht Docker auf einem zufälligen Port, und dann gibt
// es keine Kollision zu melden.
func hostPortAus(s string) int {
	s = strings.TrimSuffix(strings.TrimSuffix(s, "/tcp"), "/udp")
	teile := strings.Split(s, ":")
	if len(teile) < 2 {
		return 0
	}
	return zahlAus(teile[len(teile)-2])
}

// zahlAus liest eine Portzahl. Ein Bereich („8000-8010") ergibt seinen Anfang.
func zahlAus(s string) int {
	if i := strings.IndexByte(s, '-'); i > 0 {
		s = s[:i]
	}
	n := 0
	for _, r := range s {
		if r < '0' || r > '9' {
			return 0
		}
		n = n*10 + int(r-'0')
	}
	return n
}

// ------------------------------------------------------------- Knotenhilfen ---

// knotenText gibt den Wert eines Knotens als Zeichenkette.
func knotenText(k yaml.Node) string {
	switch k.Kind {
	case yaml.ScalarNode:
		return k.Value
	case yaml.SequenceNode:
		return strings.Join(listeAus(k), ", ")
	case 0:
		return ""
	default:
		return "…"
	}
}

// wahr liest einen Wahrheitswert. YAML kennt mehrere Schreibweisen, und
// Compose übernimmt sie alle.
func wahr(k yaml.Node) bool {
	switch strings.ToLower(k.Value) {
	case "true", "yes", "on", "1":
		return true
	default:
		return false
	}
}

// istHost erkennt „host" in jeder Schreibweise.
func istHost(s string) bool { return strings.EqualFold(strings.TrimSpace(s), "host") }

// listeAus liest eine Folge von Zeichenketten. Ein einzelner Skalar zählt als
// Liste mit einem Eintrag — Compose nimmt beides an, und ein Prüfer, der es
// nicht tut, übersieht „cap_add: SYS_ADMIN" ohne Bindestrich.
func listeAus(k yaml.Node) []string {
	switch k.Kind {
	case yaml.ScalarNode:
		if k.Value == "" {
			return nil
		}
		return []string{k.Value}
	case yaml.SequenceNode:
		out := make([]string, 0, len(k.Content))
		for _, e := range k.Content {
			if e.Kind == yaml.ScalarNode {
				out = append(out, e.Value)
				continue
			}
			// devices und ähnliche können auch in Langform stehen. Für die
			// Frage „steht da überhaupt etwas" genügt ein Platzhalter.
			out = append(out, "…")
		}
		return out
	default:
		return nil
	}
}
