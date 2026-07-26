package privops

import (
	"context"
	"strings"
	"testing"
)

// ---------------------------------------------------------------- Dienste ---

const unitListJSON = `[
  {"unit":"ssh.service","load":"loaded","active":"active","sub":"running","description":"OpenBSD Secure Shell server"},
  {"unit":"nginx.service","load":"loaded","active":"failed","sub":"failed","description":"A high performance web server"},
  {"unit":"cron.service","load":"loaded","active":"active","sub":"running","description":"Regular background program processing daemon"}
]`

const unitFilesJSON = `[
  {"unit_file":"ssh.service","state":"enabled"},
  {"unit_file":"/lib/systemd/system/nginx.service","state":"disabled"}
]`

func TestParseUnitList(t *testing.T) {
	services, err := parseUnitList(unitListJSON)
	if err != nil {
		t.Fatalf("parseUnitList: %v", err)
	}
	if len(services) != 3 {
		t.Fatalf("%d Dienste, erwartet 3", len(services))
	}

	if services[0].Unit != "ssh.service" || !services[0].Running() {
		t.Errorf("unerwarteter Eintrag: %+v", services[0])
	}
	// Beschreibungen mit Leerzeichen sind der Grund für JSON statt Spalten.
	if services[1].Description != "A high performance web server" {
		t.Errorf("Beschreibung = %q", services[1].Description)
	}
	if !services[1].Failed() {
		t.Error("nginx.service müsste als fehlgeschlagen gelten")
	}
}

func TestParseUnitFileStates(t *testing.T) {
	states, err := parseUnitFileStates(unitFilesJSON)
	if err != nil {
		t.Fatalf("parseUnitFileStates: %v", err)
	}
	if states["ssh.service"] != "enabled" {
		t.Errorf("ssh.service = %q", states["ssh.service"])
	}
	// Voller Pfad muss auf den Unit-Namen zurückgeführt werden.
	if states["nginx.service"] != "disabled" {
		t.Errorf("nginx.service = %q (Pfad nicht gekürzt?)", states["nginx.service"])
	}
}

func TestParseUnitShow(t *testing.T) {
	out := `Id=ssh.service
Description=OpenBSD Secure Shell server
LoadState=loaded
ActiveState=active
SubState=running
UnitFileState=enabled
MainPID=812
MemoryCurrent=6209536
TasksCurrent=1
FragmentPath=/lib/systemd/system/ssh.service
ActiveEnterTimestamp=Sat 2026-07-25 09:12:03 UTC`

	d := parseUnitShow(out)
	if d.Unit != "ssh.service" || d.MainPID != 812 || d.Memory != 6209536 || d.Tasks != 1 {
		t.Errorf("unerwartetes Ergebnis: %+v", d)
	}
	if d.Enabled != "enabled" || d.Since == "" {
		t.Errorf("Zustandsfelder fehlen: %+v", d)
	}
}

// systemd meldet unbekannte Speicherwerte als maximalen uint64 — das darf
// nicht als 16 Exabyte in der Oberfläche landen.
func TestParseUnitShowIgnoresUnsetMemory(t *testing.T) {
	d := parseUnitShow("Id=x.service\nMemoryCurrent=18446744073709551615")
	if d.Memory != 0 {
		t.Errorf("Memory = %d, erwartet 0", d.Memory)
	}
}

func TestFilterServices(t *testing.T) {
	services, _ := parseUnitList(unitListJSON)

	if got := filterServices(services, ServiceFilter{OnlyFailed: true}); len(got) != 1 {
		t.Errorf("OnlyFailed lieferte %d Einträge, erwartet 1", len(got))
	}
	if got := filterServices(services, ServiceFilter{OnlyActive: true}); len(got) != 2 {
		t.Errorf("OnlyActive lieferte %d Einträge, erwartet 2", len(got))
	}
	if got := filterServices(services, ServiceFilter{Search: "SHELL"}); len(got) != 1 {
		t.Errorf("Suche unabhängig von Groß-/Kleinschreibung lieferte %d Einträge", len(got))
	}
	if got := filterServices(services, ServiceFilter{Search: "nginx"}); len(got) != 1 {
		t.Errorf("Suche im Unit-Namen lieferte %d Einträge", len(got))
	}
}

func TestServiceActionValidatesInput(t *testing.T) {
	f := newFakeRunner()
	sys := NewSystemWithRunner(f)
	ctx := context.Background()

	if err := sys.ServiceAction(ctx, "nginx.service; rm -rf /", ServiceStart); err == nil {
		t.Error("unzulässiger Unit-Name wurde angenommen")
	}
	if err := sys.ServiceAction(ctx, "nginx.service", "mask"); err == nil {
		t.Error("unzulässige Aktion wurde angenommen")
	}
	if len(f.calls) != 0 {
		t.Errorf("es wurde trotz Ablehnung ein Kommando ausgeführt: %+v", f.calls)
	}

	if err := sys.ServiceAction(ctx, "nginx.service", ServiceRestart); err != nil {
		t.Fatalf("gültiger Aufruf schlug fehl: %v", err)
	}
	call := f.lastCall()
	if call.Name != "systemctl" {
		t.Errorf("Kommando = %q", call.Name)
	}
	// Das "--" trennt Optionen von Operanden.
	joined := strings.Join(call.Args, " ")
	if !strings.Contains(joined, "restart") || !strings.Contains(joined, "-- nginx.service") {
		t.Errorf("Argumente = %v", call.Args)
	}
}

func TestServiceActionReportsFailure(t *testing.T) {
	f := newFakeRunner()
	f.responses["systemctl start"] = Result{ExitCode: 5, Stderr: "Failed to start foo.service: Unit foo.service not found."}

	err := NewSystemWithRunner(f).ServiceAction(context.Background(), "foo.service", ServiceStart)
	if err == nil {
		t.Fatal("ein Exit-Code ungleich null muss als Fehler ankommen")
	}
	if !strings.Contains(err.Error(), "not found") {
		t.Errorf("die Meldung von systemd fehlt: %v", err)
	}
}

// ----------------------------------------------------------------- Pakete ---

// Echte Ausgabe von "apt-get --simulate upgrade" auf Ubuntu 24.04,
// ergänzt um ein Sicherheitspaket und einen Debian-Fall.
const aptSimulateOut = `Reading package lists...
Building dependency tree...
Calculating upgrade...
The following packages will be upgraded:
  coreutils libssl3 curl
Inst coreutils [9.4-3ubuntu6.1] (9.4-3ubuntu6.2 Ubuntu:24.04/noble-updates [amd64])
Inst libssl3 [3.0.13-0ubuntu3.1] (3.0.13-0ubuntu3.4 Ubuntu:24.04/noble-security [amd64])
Inst curl [7.88.1-10+deb12u5] (7.88.1-10+deb12u7 Debian-Security:12/stable-security [arm64])
Conf coreutils (9.4-3ubuntu6.2 Ubuntu:24.04/noble-updates [amd64])`

func TestParseAptSimulate(t *testing.T) {
	packages := parseAptSimulate(aptSimulateOut)
	if len(packages) != 3 {
		t.Fatalf("%d Pakete, erwartet 3", len(packages))
	}

	byName := make(map[string]Package, len(packages))
	for _, p := range packages {
		byName[p.Name] = p
	}

	coreutils := byName["coreutils"]
	if coreutils.CurrentVersion != "9.4-3ubuntu6.1" || coreutils.NewVersion != "9.4-3ubuntu6.2" {
		t.Errorf("Versionen falsch: %+v", coreutils)
	}
	if coreutils.Architecture != "amd64" {
		t.Errorf("Architektur = %q", coreutils.Architecture)
	}
	if coreutils.Security {
		t.Error("noble-updates ist keine Sicherheitsquelle")
	}

	if !byName["libssl3"].Security {
		t.Error("noble-security wurde nicht als Sicherheitsquelle erkannt")
	}
	if !byName["curl"].Security {
		t.Error("Debian-Security wurde nicht als Sicherheitsquelle erkannt")
	}

	// Sicherheitsupdates stehen oben.
	if !packages[0].Security || !packages[1].Security {
		t.Errorf("Sortierung stellt Sicherheitsupdates nicht voran: %+v", packages)
	}
	// "Conf"-Zeilen sind keine Upgrades.
	if len(packages) != 3 {
		t.Error("Conf-Zeile wurde mitgezählt")
	}
}

func TestParseAptSimulateEmpty(t *testing.T) {
	out := `Reading package lists...
Building dependency tree...
Calculating upgrade...
0 upgraded, 0 newly installed, 0 to remove and 0 not upgraded.`

	if got := parseAptSimulate(out); len(got) != 0 {
		t.Errorf("%d Pakete, erwartet 0", len(got))
	}
}

func TestPackageUpgradeValidatesNames(t *testing.T) {
	f := newFakeRunner()
	sys := NewSystemWithRunner(f)

	err := sys.PackageUpgrade(context.Background(), UpgradeOptions{Packages: []string{"nginx; rm -rf /"}}, nil)
	if err == nil {
		t.Fatal("unzulässiger Paketname wurde angenommen")
	}
	if len(f.calls) != 0 {
		t.Error("es wurde trotz Ablehnung apt-get aufgerufen")
	}
}

// Ein Update über die Paketliste darf nichts Neues installieren.
func TestPackageUpgradeUsesOnlyUpgrade(t *testing.T) {
	f := newFakeRunner()
	sys := NewSystemWithRunner(f)

	if err := sys.PackageUpgrade(context.Background(), UpgradeOptions{Packages: []string{"curl"}}, nil); err != nil {
		t.Fatalf("PackageUpgrade: %v", err)
	}

	args := strings.Join(f.lastCall().Args, " ")
	for _, want := range []string{"upgrade", "--yes", "--only-upgrade", "-- curl", "force-confold"} {
		if !strings.Contains(args, want) {
			t.Errorf("Argumente enthalten %q nicht: %s", want, args)
		}
	}
}

func TestPackageUpgradeOnlySecurity(t *testing.T) {
	f := newFakeRunner()
	f.responses["apt-get --simulate"] = Result{Stdout: aptSimulateOut}
	sys := NewSystemWithRunner(f)

	if err := sys.PackageUpgrade(context.Background(), UpgradeOptions{OnlySecurity: true}, nil); err != nil {
		t.Fatalf("PackageUpgrade: %v", err)
	}

	args := strings.Join(f.lastCall().Args, " ")
	if !strings.Contains(args, "libssl3") || !strings.Contains(args, "curl") {
		t.Errorf("die Sicherheitspakete fehlen: %s", args)
	}
	if strings.Contains(args, "coreutils") {
		t.Errorf("ein Nicht-Sicherheitspaket wurde eingeschlossen: %s", args)
	}
}

func TestPackageUpgradeStreams(t *testing.T) {
	f := newFakeRunner()
	f.responses["apt-get upgrade"] = Result{Stdout: "Setting up curl ...\nProcessing triggers ..."}

	var lines []string
	err := NewSystemWithRunner(f).PackageUpgrade(context.Background(), UpgradeOptions{}, func(l string) {
		lines = append(lines, l)
	})
	if err != nil {
		t.Fatalf("PackageUpgrade: %v", err)
	}
	if len(lines) != 2 {
		t.Errorf("%d Zeilen durchgereicht, erwartet 2: %v", len(lines), lines)
	}
}

// --------------------------------------------------------------- Firewall ---

const ufwStatusOut = `Status: active

     To                         Action      From
     --                         ------      ----
[ 1] 22/tcp                     ALLOW IN    Anywhere                   # SSH
[ 2] 8443/tcp                   ALLOW IN    Anywhere
[ 3] 5432/tcp                   ALLOW IN    203.0.113.0/24             # Datenbank intern
[ 4] OpenSSH                    ALLOW IN    Anywhere
[ 5] 22/tcp (v6)                ALLOW IN    Anywhere (v6)
[ 6] 53/udp                     ALLOW IN    Anywhere`

func TestParseUFWStatus(t *testing.T) {
	rules := parseUFWStatus(ufwStatusOut)

	// Erwartet: 22/tcp, 53/udp, 5432/tcp, 8443/tcp — ohne IPv6-Dublette und
	// ohne das benannte Profil OpenSSH.
	if len(rules) != 4 {
		t.Fatalf("%d Regeln, erwartet 4: %+v", len(rules), rules)
	}
	if rules[0].Port != 22 || rules[0].Protocol != "tcp" || rules[0].Comment != "SSH" {
		t.Errorf("erste Regel: %+v", rules[0])
	}
	if rules[0].Source != "" {
		t.Errorf("Anywhere muss als leere Quelle ankommen, ist %q", rules[0].Source)
	}

	var db FirewallRule
	for _, r := range rules {
		if r.Port == 5432 {
			db = r
		}
	}
	if db.Source != "203.0.113.0/24" || db.Comment != "Datenbank intern" {
		t.Errorf("Regel mit Quelle: %+v", db)
	}

	for _, r := range rules {
		if r.Port == 53 && r.Protocol != "udp" {
			t.Errorf("UDP-Regel falsch erkannt: %+v", r)
		}
	}
}

func TestParseUFWStatusInactive(t *testing.T) {
	if got := parseUFWStatus("Status: inactive"); len(got) != 0 {
		t.Errorf("%d Regeln bei inaktiver Firewall", len(got))
	}
}

func TestParseNFTAccepts(t *testing.T) {
	out := `table inet filter {
	chain input {
		type filter hook input priority filter; policy drop;
		ct state established,related accept
		iif "lo" accept
		tcp dport 22 accept
		tcp dport { 80, 443 } accept
		udp dport 51820 accept
	}
}`

	rules := parseNFTAccepts(out)
	if len(rules) != 4 {
		t.Fatalf("%d Regeln, erwartet 4: %+v", len(rules), rules)
	}

	ports := make(map[int]string, len(rules))
	for _, r := range rules {
		ports[r.Port] = r.Protocol
	}
	for port, proto := range map[int]string{22: "tcp", 80: "tcp", 443: "tcp", 51820: "udp"} {
		if ports[port] != proto {
			t.Errorf("Port %d = %q, erwartet %q", port, ports[port], proto)
		}
	}
}

func TestFirewallApplyRefusesUnmanagedBackend(t *testing.T) {
	f := newFakeRunner()
	// ufw fehlt, nft liefert ein Regelwerk.
	f.errs["ufw"] = ErrNotAllowed
	f.responses["nft list ruleset"] = Result{Stdout: "table inet filter {\n tcp dport 22 accept\n}"}

	err := NewSystemWithRunner(f).FirewallApply(context.Background(), []FirewallRule{{Port: 443, Protocol: "tcp"}})
	if err == nil {
		t.Fatal("bei einem fremden Regelwerk darf nichts geändert werden")
	}
	if !strings.Contains(err.Error(), "nicht verwaltet") {
		t.Errorf("unerwartete Meldung: %v", err)
	}
}

func TestFirewallApplyAddsAndRemoves(t *testing.T) {
	f := newFakeRunner()
	f.responses["ufw status numbered"] = Result{Stdout: ufwStatusOut}
	sys := NewSystemWithRunner(f)

	// Gewünscht: 22/tcp bleibt, 8443/tcp entfällt, 443/tcp kommt hinzu.
	// 5432 und 53 bleiben ebenfalls stehen.
	err := sys.FirewallApply(context.Background(), []FirewallRule{
		{Port: 22, Protocol: "tcp", Comment: "SSH"},
		{Port: 443, Protocol: "tcp", Comment: "HTTPS"},
		{Port: 5432, Protocol: "tcp", Source: "203.0.113.0/24", Comment: "Datenbank intern"},
		{Port: 53, Protocol: "udp"},
	})
	if err != nil {
		t.Fatalf("FirewallApply: %v", err)
	}

	var added, deleted []string
	for _, c := range f.calls {
		joined := strings.Join(c.Args, " ")
		switch {
		case strings.HasPrefix(joined, "allow"):
			added = append(added, joined)
		case strings.Contains(joined, "delete"):
			deleted = append(deleted, joined)
		}
	}

	if len(added) != 1 || !strings.Contains(added[0], "443/tcp") {
		t.Errorf("hinzugefügt: %v", added)
	}
	if len(deleted) != 1 || !strings.Contains(deleted[0], "8443/tcp") {
		t.Errorf("entfernt: %v", deleted)
	}

	// Reihenfolge: erst öffnen, dann schließen — sonst entsteht ein Moment
	// ohne den eigenen Zugang.
	addIdx, delIdx := -1, -1
	for i, c := range f.calls {
		joined := strings.Join(c.Args, " ")
		if addIdx == -1 && strings.HasPrefix(joined, "allow") {
			addIdx = i
		}
		if delIdx == -1 && strings.Contains(joined, "delete") {
			delIdx = i
		}
	}
	if addIdx > delIdx {
		t.Error("es wird entfernt, bevor hinzugefügt wurde")
	}
}

func TestFirewallApplyValidatesRules(t *testing.T) {
	f := newFakeRunner()
	err := NewSystemWithRunner(f).FirewallApply(context.Background(), []FirewallRule{{Port: 99999, Protocol: "tcp"}})
	if err == nil {
		t.Fatal("ungültige Regel wurde angenommen")
	}
	if len(f.calls) != 0 {
		t.Error("es wurde trotz ungültiger Regel ein Kommando ausgeführt")
	}
}

// ------------------------------------------------------------- Systemnutzer ---

const passwdFixture = `root:x:0:0:root:/root:/bin/bash
daemon:x:1:1:daemon:/usr/sbin:/usr/sbin/nologin
www-data:x:33:33:www-data:/var/www:/usr/sbin/nologin
philipp:x:1000:1000:Philipp,,,:/home/philipp:/bin/bash
deploy:x:1001:1001::/home/deploy:/bin/bash`

const groupFixture = `root:x:0:
sudo:x:27:philipp
www-data:x:33:
philipp:x:1000:
deploy:x:1001:
docker:x:999:philipp,deploy`

const shadowFixture = `root:$6$abc$def:19000:0:99999:7:::
philipp:$6$xyz$uvw:19500:0:99999:7:::
deploy:!$6$locked$hash:19500:0:99999:7:::
www-data:*:19000:0:99999:7:::`

func TestParsePasswd(t *testing.T) {
	users := parsePasswd(passwdFixture)
	if len(users) != 5 {
		t.Fatalf("%d Benutzer, erwartet 5", len(users))
	}

	byName := make(map[string]SystemUser, len(users))
	for _, u := range users {
		byName[u.Name] = u
	}

	philipp := byName["philipp"]
	if philipp.UID != 1000 || philipp.Home != "/home/philipp" || philipp.Shell != "/bin/bash" {
		t.Errorf("unerwartet: %+v", philipp)
	}
	// Das GECOS-Feld enthält kommagetrennte Zusatzangaben.
	if philipp.Comment != "Philipp" {
		t.Errorf("Comment = %q, erwartet Philipp", philipp.Comment)
	}
	if philipp.System {
		t.Error("UID 1000 ist kein Systemkonto")
	}
	if !philipp.HasShell {
		t.Error("/bin/bash ist eine Login-Shell")
	}

	if !byName["www-data"].System {
		t.Error("UID 33 ist ein Systemkonto")
	}
	if byName["www-data"].HasShell {
		t.Error("nologin ist keine Login-Shell")
	}
	// root hat UID 0, gilt aber nicht als gewöhnliches Systemkonto.
	if byName["root"].System {
		t.Error("root wurde als Systemkonto einsortiert")
	}

	// Reguläre Benutzer stehen vorn.
	if users[0].Name != "root" && users[0].System {
		t.Errorf("Sortierung: %+v", users[0])
	}
}

func TestParseGroups(t *testing.T) {
	members, byGID := parseGroups(groupFixture)

	if !contains(members["philipp"], "sudo") || !contains(members["philipp"], "docker") {
		t.Errorf("Zusatzgruppen von philipp: %v", members["philipp"])
	}
	if byGID[1000] != "philipp" {
		t.Errorf("GID 1000 = %q", byGID[1000])
	}
}

func TestParseShadowLocks(t *testing.T) {
	locked := parseShadowLocks(shadowFixture)

	if locked["philipp"] {
		t.Error("philipp ist nicht gesperrt")
	}
	if !locked["deploy"] {
		t.Error("das führende ! markiert ein gesperrtes Konto")
	}
	if !locked["www-data"] {
		t.Error("ein * im Passwortfeld bedeutet: keine Anmeldung möglich")
	}
}

func TestSystemUserCreateValidates(t *testing.T) {
	f := newFakeRunner()
	sys := NewSystemWithRunner(f)
	ctx := context.Background()

	cases := map[string]SystemUserSpec{
		"unzulässiger Name":      {Name: "Philipp; rm -rf /"},
		"Doppelpunkt":            {Name: "philipp", Comment: "a:b"},
		"unbekannte Gruppe":      {Name: "philipp", Groups: []string{"sudo; rm"}},
		"kaputter SSH-Schlüssel": {Name: "philipp", SSHKey: "das ist kein schlüssel"},
	}
	for name, spec := range cases {
		t.Run(name, func(t *testing.T) {
			if err := sys.SystemUserCreate(ctx, spec); err == nil {
				t.Error("wurde angenommen")
			}
		})
	}
	if len(f.calls) != 0 {
		t.Errorf("es wurde trotz Ablehnung ein Kommando ausgeführt: %+v", f.calls)
	}
}

func TestProtectedUsers(t *testing.T) {
	f := newFakeRunner()
	sys := NewSystemWithRunner(f)
	ctx := context.Background()

	for _, name := range []string{"root", "sshd", "asylum"} {
		if err := sys.SystemUserDelete(ctx, name, false); err == nil {
			t.Errorf("%q ließ sich löschen", name)
		}
		if err := sys.SystemUserSetLocked(ctx, name, true); err == nil {
			t.Errorf("%q ließ sich sperren", name)
		}
	}
	if len(f.calls) != 0 {
		t.Error("es wurde ein Kommando gegen ein geschütztes Konto ausgeführt")
	}
}

// --------------------------------------------------------- SSH-Schlüssel ---

// Fixtures mit unabhängig berechneten Fingerprints (Python: SHA-256 über den
// Rohschlüssel, base64 ohne Auffüllzeichen — so macht es auch OpenSSH).
const (
	ed25519Key = "AAAAC3NzaC1lZDI1NTE5AAAAIAABAgMEBQYHCAkKCwwNDg8QERITFBUWFxgZGhscHR4f"
	ed25519FP  = "SHA256:ZkAslGjFiUHdGf/WUL8rQvkib4PTvQatUV0OUQSncCA"
	rsaKey     = "AAAAB3NzaC1yc2EAAAADAQABAAABAQCAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAB"
	rsaFP      = "SHA256:Js2Dxmvn6q4imp7p4HPS1bOZqtUEaZ+i6v/mfPXmBbc"
)

func TestParseAuthorizedKeyLine(t *testing.T) {
	key, err := parseAuthorizedKeyLine("ssh-ed25519 " + ed25519Key + " philipp@laptop")
	if err != nil {
		t.Fatalf("parseAuthorizedKeyLine: %v", err)
	}
	if key.Fingerprint != ed25519FP {
		t.Errorf("Fingerprint = %q, erwartet %q", key.Fingerprint, ed25519FP)
	}
	if key.Type != "ssh-ed25519" || key.Comment != "philipp@laptop" || key.Bits != 256 {
		t.Errorf("unerwartet: %+v", key)
	}
}

func TestParseAuthorizedKeyLineRSA(t *testing.T) {
	key, err := parseAuthorizedKeyLine("ssh-rsa " + rsaKey + " buildbot")
	if err != nil {
		t.Fatalf("parseAuthorizedKeyLine: %v", err)
	}
	if key.Fingerprint != rsaFP {
		t.Errorf("Fingerprint = %q, erwartet %q", key.Fingerprint, rsaFP)
	}
	if key.Bits != 2048 {
		t.Errorf("Bits = %d, erwartet 2048", key.Bits)
	}
}

func TestParseAuthorizedKeyLineRejects(t *testing.T) {
	cases := map[string]string{
		"leer":                     "",
		"Kommentarzeile":           "# ein Kommentar",
		"kein Schlüssel":           "irgendwas",
		"veralteter Typ":           "ssh-dss AAAA irgendwas",
		"mit Optionen":             `command="/bin/sh" ssh-ed25519 ` + ed25519Key,
		"kein base64":              "ssh-ed25519 !!!keinbase64!!!",
		"mehrzeilig":               "ssh-ed25519 " + ed25519Key + "\nssh-ed25519 " + ed25519Key,
		"Typ passt nicht zum Blob": "ssh-ed25519 AAAAB3NzaC1yc2EAAAABAwAAAAEF",
	}

	for name, line := range cases {
		t.Run(name, func(t *testing.T) {
			if _, err := parseAuthorizedKeyLine(line); err == nil {
				t.Error("wurde angenommen")
			}
		})
	}
}

// Ein Schlüssel, der sich als ed25519 ausgibt, aber RSA enthält, ist entweder
// kaputt oder manipuliert — beides gehört abgewiesen.
func TestFingerprintDetectsTypeMismatch(t *testing.T) {
	_, _, err := sshFingerprint("ssh-ed25519", "AAAAB3NzaC1yc2EAAAABAwAAAAEF")
	if err == nil {
		t.Fatal("Typkonflikt wurde nicht erkannt")
	}
	if !strings.Contains(err.Error(), "gibt sich als") {
		t.Errorf("unerwartete Meldung: %v", err)
	}
}

func TestReadSSHStringRejectsOverlongLength(t *testing.T) {
	// Längenangabe größer als der Puffer.
	if _, _, err := readSSHString([]byte{0xff, 0xff, 0xff, 0xff, 0x01}); err == nil {
		t.Error("überlange Längenangabe wurde angenommen")
	}
}

// ------------------------------------------------------------------- Logs ---

func TestParseJournalJSON(t *testing.T) {
	out := `{"__REALTIME_TIMESTAMP":"1753531923000000","_SYSTEMD_UNIT":"ssh.service","PRIORITY":"6","MESSAGE":"Server listening on 0.0.0.0 port 22.","_HOSTNAME":"vm"}
{"__REALTIME_TIMESTAMP":"1753531924000000","_SYSTEMD_UNIT":"nginx.service","PRIORITY":"3","MESSAGE":"bind() failed","_HOSTNAME":"vm"}
kaputte Zeile
{"__REALTIME_TIMESTAMP":"1753531925000000","_COMM":"kernel","PRIORITY":"4","MESSAGE":[72,97,108,108,111],"_HOSTNAME":"vm"}`

	entries := parseJournalJSON(out)
	if len(entries) != 3 {
		t.Fatalf("%d Einträge, erwartet 3 (kaputte Zeile überspringen)", len(entries))
	}

	if entries[0].Unit != "ssh.service" || entries[0].Priority != 6 {
		t.Errorf("erster Eintrag: %+v", entries[0])
	}
	if entries[0].At.IsZero() {
		t.Error("Zeitstempel wurde nicht umgerechnet")
	}
	if entries[1].PriorityName() != "err" {
		t.Errorf("Priorität 3 = %q, erwartet err", entries[1].PriorityName())
	}
	// Ohne _SYSTEMD_UNIT dient _COMM als Ersatz.
	if entries[2].Unit != "kernel" {
		t.Errorf("Ersatz für fehlende Unit: %q", entries[2].Unit)
	}
	// MESSAGE kann ein Byte-Array sein.
	if entries[2].Message != "Hallo" {
		t.Errorf("Byte-Array-Nachricht = %q", entries[2].Message)
	}
}

func TestLogsValidatesUnit(t *testing.T) {
	f := newFakeRunner()
	if _, err := NewSystemWithRunner(f).Logs(context.Background(), LogQuery{Unit: "x; rm -rf /"}); err == nil {
		t.Fatal("unzulässiger Unit-Name wurde angenommen")
	}
	if len(f.calls) != 0 {
		t.Error("journalctl wurde trotzdem aufgerufen")
	}
}

func TestLogsSearchFiltersLocally(t *testing.T) {
	f := newFakeRunner()
	f.responses["journalctl"] = Result{Stdout: `{"__REALTIME_TIMESTAMP":"1753531923000000","_SYSTEMD_UNIT":"ssh.service","PRIORITY":"6","MESSAGE":"Accepted publickey"}
{"__REALTIME_TIMESTAMP":"1753531924000000","_SYSTEMD_UNIT":"ssh.service","PRIORITY":"6","MESSAGE":"Connection closed"}`}

	entries, err := NewSystemWithRunner(f).Logs(context.Background(), LogQuery{Search: "accepted", Priority: -1})
	if err != nil {
		t.Fatalf("Logs: %v", err)
	}
	if len(entries) != 1 {
		t.Fatalf("%d Treffer, erwartet 1", len(entries))
	}

	// Die Suche darf nicht als Regex an journalctl gehen.
	args := strings.Join(f.lastCall().Args, " ")
	if strings.Contains(args, "grep") || strings.Contains(args, "accepted") {
		t.Errorf("die Suche wurde an journalctl durchgereicht: %s", args)
	}
}
