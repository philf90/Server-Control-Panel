package privops

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// withFixtures zeigt die Systemdateipfade auf ein Testverzeichnis. Das Home
// des Testbenutzers liegt darin, sodass authorized_keys wirklich geschrieben
// wird — die Rechte darauf entscheiden, ob sshd den Zugang akzeptiert.
func withFixtures(t *testing.T) (home string) {
	t.Helper()
	dir := t.TempDir()
	home = filepath.Join(dir, "home", "philipp")

	if err := os.MkdirAll(home, 0o755); err != nil {
		t.Fatal(err)
	}

	files := map[string]string{
		"passwd": fmt.Sprintf("root:x:0:0:root:/root:/bin/bash\nphilipp:x:%d:%d:Philipp:%s:/bin/bash\n",
			os.Getuid(), os.Getgid(), home),
		"group":  "root:x:0:\nsudo:x:27:philipp\n",
		"shadow": "root:$6$x:19000:0:99999:7:::\nphilipp:$6$y:19000:0:99999:7:::\n",
	}
	for name, content := range files {
		path := filepath.Join(dir, name)
		if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
			t.Fatal(err)
		}
	}

	oldPasswd, oldGroup, oldShadow := passwdPath, groupPath, shadowPath
	passwdPath = filepath.Join(dir, "passwd")
	groupPath = filepath.Join(dir, "group")
	shadowPath = filepath.Join(dir, "shadow")
	t.Cleanup(func() {
		passwdPath, groupPath, shadowPath = oldPasswd, oldGroup, oldShadow
	})
	return home
}

func TestSystemUsersReadsFixtures(t *testing.T) {
	withFixtures(t)

	users, err := NewSystemWithRunner(newFakeRunner()).SystemUsers(context.Background())
	if err != nil {
		t.Fatalf("SystemUsers: %v", err)
	}
	if len(users) != 2 {
		t.Fatalf("%d Benutzer, erwartet 2", len(users))
	}

	var philipp SystemUser
	for _, u := range users {
		if u.Name == "philipp" {
			philipp = u
		}
	}
	if philipp.Name == "" {
		t.Fatal("philipp fehlt")
	}
	if !contains(philipp.Groups, "sudo") {
		t.Errorf("Gruppen = %v, erwartet sudo", philipp.Groups)
	}
	if philipp.Locked {
		t.Error("philipp ist nicht gesperrt")
	}
	if philipp.SSHKeys != 0 {
		t.Errorf("%d Schlüssel, erwartet 0", philipp.SSHKeys)
	}
}

func TestAuthorizedKeysRoundtrip(t *testing.T) {
	home := withFixtures(t)
	sys := NewSystemWithRunner(newFakeRunner())
	ctx := context.Background()

	// Ohne Datei gibt es keine Schlüssel und keinen Fehler.
	keys, err := sys.AuthorizedKeys(ctx, "philipp")
	if err != nil {
		t.Fatalf("AuthorizedKeys ohne Datei: %v", err)
	}
	if len(keys) != 0 {
		t.Fatalf("%d Schlüssel, erwartet 0", len(keys))
	}

	line := "ssh-ed25519 " + ed25519Key + " philipp@laptop"
	if err := sys.AuthorizedKeyAdd(ctx, "philipp", line); err != nil {
		t.Fatalf("AuthorizedKeyAdd: %v", err)
	}

	keys, err = sys.AuthorizedKeys(ctx, "philipp")
	if err != nil {
		t.Fatal(err)
	}
	if len(keys) != 1 || keys[0].Fingerprint != ed25519FP {
		t.Fatalf("unerwartete Schlüssel: %+v", keys)
	}

	// sshd verweigert den Dienst bei zu großzügigen Rechten.
	sshDir := filepath.Join(home, ".ssh")
	if info, err := os.Stat(sshDir); err != nil {
		t.Fatal(err)
	} else if perm := info.Mode().Perm(); perm != 0o700 {
		t.Errorf("Verzeichnisrechte = %o, erwartet 700", perm)
	}
	if info, err := os.Stat(filepath.Join(sshDir, "authorized_keys")); err != nil {
		t.Fatal(err)
	} else if perm := info.Mode().Perm(); perm != 0o600 {
		t.Errorf("Dateirechte = %o, erwartet 600", perm)
	}

	// Derselbe Schlüssel ein zweites Mal wird abgelehnt.
	if err := sys.AuthorizedKeyAdd(ctx, "philipp", line); err == nil {
		t.Error("doppelter Schlüssel wurde angenommen")
	}

	// Ein zweiter Schlüssel kommt hinzu, ohne den ersten zu verlieren.
	second := "ssh-rsa " + rsaKey + " buildbot"
	if err := sys.AuthorizedKeyAdd(ctx, "philipp", second); err != nil {
		t.Fatalf("zweiter Schlüssel: %v", err)
	}
	keys, _ = sys.AuthorizedKeys(ctx, "philipp")
	if len(keys) != 2 {
		t.Fatalf("%d Schlüssel, erwartet 2", len(keys))
	}

	// Entfernen trifft genau einen.
	if err := sys.AuthorizedKeyRemove(ctx, "philipp", ed25519FP); err != nil {
		t.Fatalf("AuthorizedKeyRemove: %v", err)
	}
	keys, _ = sys.AuthorizedKeys(ctx, "philipp")
	if len(keys) != 1 || keys[0].Fingerprint != rsaFP {
		t.Fatalf("nach dem Entfernen: %+v", keys)
	}

	// Ein unbekannter Fingerprint ändert nichts.
	if err := sys.AuthorizedKeyRemove(ctx, "philipp", "SHA256:gibtsnicht"); err == nil {
		t.Error("unbekannter Fingerprint wurde angenommen")
	}
}

// Kommentare und Reihenfolge müssen erhalten bleiben, sonst verliert der
// Betreiber die Zuordnung, welcher Schlüssel zu welchem Gerät gehört.
func TestAuthorizedKeysPreservesComments(t *testing.T) {
	home := withFixtures(t)
	sys := NewSystemWithRunner(newFakeRunner())
	ctx := context.Background()

	if err := sys.AuthorizedKeyAdd(ctx, "philipp", "ssh-ed25519 "+ed25519Key+" laptop von philipp"); err != nil {
		t.Fatal(err)
	}

	raw, err := os.ReadFile(filepath.Join(home, ".ssh", "authorized_keys"))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(raw), "laptop von philipp") {
		t.Errorf("Kommentar fehlt in der Datei: %q", raw)
	}
	if !strings.HasSuffix(string(raw), "\n") {
		t.Error("die Datei endet nicht mit einem Zeilenumbruch")
	}
}

func TestAuthorizedKeysRejectsUnknownUser(t *testing.T) {
	withFixtures(t)
	sys := NewSystemWithRunner(newFakeRunner())

	if _, err := sys.AuthorizedKeys(context.Background(), "gibtsnicht"); err == nil {
		t.Error("unbekannter Benutzer wurde angenommen")
	}
}

// --------------------------------------------------- Operationen mit Runner ---

func TestServicesMergesUnitFileStates(t *testing.T) {
	f := newFakeRunner()
	f.responses["systemctl list-units"] = Result{Stdout: unitListJSON}
	f.responses["systemctl list-unit-files"] = Result{Stdout: unitFilesJSON}

	services, err := NewSystemWithRunner(f).Services(context.Background(), ServiceFilter{})
	if err != nil {
		t.Fatalf("Services: %v", err)
	}
	if len(services) != 3 {
		t.Fatalf("%d Dienste, erwartet 3", len(services))
	}

	byUnit := make(map[string]Service, len(services))
	for _, s := range services {
		byUnit[s.Unit] = s
	}
	if byUnit["ssh.service"].Enabled != "enabled" {
		t.Errorf("ssh.service Enabled = %q", byUnit["ssh.service"].Enabled)
	}
	// Ohne Eintrag in der Dateiliste bleibt das Feld leer, statt zu raten.
	if byUnit["cron.service"].Enabled != "" {
		t.Errorf("cron.service Enabled = %q, erwartet leer", byUnit["cron.service"].Enabled)
	}
}

// Fehlt die Abfrage der Aktivierungszustände, bleibt die Liste trotzdem
// nutzbar — sie ist Beiwerk, kein Kernbestandteil.
func TestServicesSurvivesMissingUnitFiles(t *testing.T) {
	f := newFakeRunner()
	f.responses["systemctl list-units"] = Result{Stdout: unitListJSON}
	f.responses["systemctl list-unit-files"] = Result{ExitCode: 1, Stderr: "kaputt"}

	services, err := NewSystemWithRunner(f).Services(context.Background(), ServiceFilter{})
	if err != nil {
		t.Fatalf("Services: %v", err)
	}
	if len(services) != 3 {
		t.Errorf("%d Dienste, erwartet 3", len(services))
	}
}

func TestServiceDetailReadsShow(t *testing.T) {
	f := newFakeRunner()
	f.responses["systemctl show"] = Result{Stdout: "Id=ssh.service\nActiveState=active\nMainPID=812"}

	detail, err := NewSystemWithRunner(f).Service(context.Background(), "ssh.service")
	if err != nil {
		t.Fatalf("Service: %v", err)
	}
	if detail.Unit != "ssh.service" || detail.MainPID != 812 {
		t.Errorf("unerwartet: %+v", detail)
	}
}

func TestServiceDetailUnknownUnit(t *testing.T) {
	f := newFakeRunner()
	f.responses["systemctl show"] = Result{Stdout: ""}

	if _, err := NewSystemWithRunner(f).Service(context.Background(), "gibtsnicht.service"); err == nil {
		t.Error("eine unbekannte Unit muss einen Fehler ergeben")
	}
}

func TestPackageUpgradableUsesSimulation(t *testing.T) {
	f := newFakeRunner()
	f.responses["apt-get --simulate"] = Result{Stdout: aptSimulateOut}

	packages, err := NewSystemWithRunner(f).PackageUpgradable(context.Background())
	if err != nil {
		t.Fatalf("PackageUpgradable: %v", err)
	}
	if len(packages) != 3 {
		t.Errorf("%d Pakete, erwartet 3", len(packages))
	}

	args := strings.Join(f.lastCall().Args, " ")
	if !strings.Contains(args, "--simulate") {
		t.Errorf("es wurde nicht simuliert: %s", args)
	}
}

func TestPackageRefreshReportsFailure(t *testing.T) {
	f := newFakeRunner()
	f.responses["apt-get update"] = Result{ExitCode: 100, Stderr: "E: Failed to fetch"}

	err := NewSystemWithRunner(f).PackageRefresh(context.Background())
	if err == nil {
		t.Fatal("ein Fehlschlag muss gemeldet werden")
	}
	if !strings.Contains(err.Error(), "Failed to fetch") {
		t.Errorf("die apt-Meldung fehlt: %v", err)
	}
}

// ufwPaketVorhanden lässt dpkg-query melden, dass ufw installiert ist. Ohne
// diese Antwort gilt ufw als fehlend — der Zustand wird seit rc.4 an der
// Paketverwaltung festgestellt und nicht mehr am Fehlschlag des Aufrufs.
func ufwPaketVorhanden(f *fakeRunner) {
	f.responses["dpkg-query"] = Result{Stdout: "installed"}
}

func TestFirewallStateDetectsBackends(t *testing.T) {
	t.Run("ufw aktiv", func(t *testing.T) {
		f := newFakeRunner()
		ufwPaketVorhanden(f)
		f.responses["ufw status"] = Result{Stdout: ufwStatusOut}

		state, err := NewSystemWithRunner(f).FirewallState(context.Background())
		if err != nil {
			t.Fatal(err)
		}
		if state.Backend != BackendUFW || !state.Active || !state.Managed {
			t.Errorf("unerwartet: %+v", state)
		}
		if len(state.Rules) == 0 {
			t.Error("keine Regeln gelesen")
		}
	})

	t.Run("ufw inaktiv", func(t *testing.T) {
		f := newFakeRunner()
		ufwPaketVorhanden(f)
		f.responses["ufw status"] = Result{Stdout: "Status: inactive"}

		state, _ := NewSystemWithRunner(f).FirewallState(context.Background())
		if state.Active {
			t.Error("inaktives ufw wurde als aktiv gemeldet")
		}
		if state.Notice == "" {
			t.Error("der Hinweis auf die Inaktivität fehlt")
		}
	})

	t.Run("nur nftables", func(t *testing.T) {
		f := newFakeRunner()
		f.errs["ufw"] = ErrNotAllowed
		f.responses["nft list ruleset"] = Result{Stdout: "table inet filter {\n tcp dport 22 accept\n}"}

		state, _ := NewSystemWithRunner(f).FirewallState(context.Background())
		if state.Backend != BackendNFTables {
			t.Errorf("Backend = %q", state.Backend)
		}
		if state.Managed {
			t.Error("ein fremdes Regelwerk darf nicht als verwaltet gelten")
		}
	})

	t.Run("gar nichts", func(t *testing.T) {
		f := newFakeRunner()
		f.errs["ufw"] = ErrNotAllowed
		f.errs["nft"] = ErrNotAllowed

		state, _ := NewSystemWithRunner(f).FirewallState(context.Background())
		if state.Backend != BackendNone {
			t.Errorf("Backend = %q, erwartet keins", state.Backend)
		}
		if !strings.Contains(state.Notice, "ufw") {
			t.Error("der Hinweis nennt keinen Lösungsweg")
		}
		if state.Installed {
			t.Error("ohne dpkg-Eintrag darf ufw nicht als installiert gelten")
		}
	})

	// Bis rc.3 sahen "nicht installiert" und "installiert, aber kaputt" gleich
	// aus: Beide fielen durch den fehlgeschlagenen Aufruf, beide bekamen den
	// Rat, ufw zu installieren. Im zweiten Fall ist der falsch — das Paket ist
	// ja da.
	t.Run("Paket da, Programm kaputt", func(t *testing.T) {
		f := newFakeRunner()
		ufwPaketVorhanden(f)
		f.errs["ufw"] = ErrNotAllowed

		state, err := NewSystemWithRunner(f).FirewallState(context.Background())
		if err != nil {
			t.Fatal(err)
		}
		if !state.Installed {
			t.Error("das Paket ist laut dpkg vorhanden")
		}
		if !strings.Contains(state.Notice, "unvollständig") {
			t.Errorf("der Hinweis erklärt den Fall nicht: %q", state.Notice)
		}
	})

	// Ist ufw installiert, wird nftables nicht mehr befragt: ufw *ist* ein
	// nftables-Frontend, und sein eigenes Regelwerk als fremdes zu melden
	// würde die Verwaltung abschalten.
	t.Run("installiertes ufw verdeckt nftables nicht fälschlich", func(t *testing.T) {
		f := newFakeRunner()
		ufwPaketVorhanden(f)
		f.responses["ufw status"] = Result{Stdout: ufwStatusOut}
		f.responses["nft list ruleset"] = Result{Stdout: "table inet filter {}"}

		state, _ := NewSystemWithRunner(f).FirewallState(context.Background())
		if state.Backend != BackendUFW || !state.Managed {
			t.Errorf("unerwartet: %+v", state)
		}
	})
}

func TestFirewallSetActive(t *testing.T) {
	// "--force" ist nicht schmückend: Ohne es fragt ufw interaktiv, ob
	// bestehende SSH-Verbindungen unterbrochen werden dürfen, und es gibt kein
	// Terminal, das antworten könnte. Der Aufruf bliebe hängen.
	f := newFakeRunner()
	if err := NewSystemWithRunner(f).FirewallSetActive(context.Background(), true); err != nil {
		t.Fatal(err)
	}
	got := strings.Join(f.calls[len(f.calls)-1].Args, " ")
	if got != "--force enable" {
		t.Errorf("Argumente = %q, erwartet \"--force enable\"", got)
	}

	f = newFakeRunner()
	if err := NewSystemWithRunner(f).FirewallSetActive(context.Background(), false); err != nil {
		t.Fatal(err)
	}
	got = strings.Join(f.calls[len(f.calls)-1].Args, " ")
	if got != "disable" {
		t.Errorf("Argumente = %q, erwartet \"disable\"", got)
	}
}

// TestFirewallInstallNurUfw: Der Paketweg des Panels trägt aus gutem Grund
// "--only-upgrade" und kann darüber nichts Neues installieren. Diese Operation
// darf diese Grenze nur für genau ein Paket öffnen — und dessen Name kommt aus
// dem Quelltext, nicht aus einem Formular.
func TestFirewallInstallNurUfw(t *testing.T) {
	f := newFakeRunner()
	if err := NewSystemWithRunner(f).FirewallInstall(context.Background(), nil); err != nil {
		t.Fatal(err)
	}
	last := f.calls[len(f.calls)-1]
	if last.Name != "apt-get" {
		t.Fatalf("Kommando = %q", last.Name)
	}
	args := strings.Join(last.Args, " ")
	if !strings.HasSuffix(args, "-- ufw") {
		t.Errorf("Argumente = %q — das Paket muss das letzte Wort nach \"--\" sein", args)
	}
	if strings.Contains(args, "--only-upgrade") {
		t.Error("--only-upgrade verhinderte gerade die Installation")
	}
}

func TestLogUnitsFiltersInvalidNames(t *testing.T) {
	f := newFakeRunner()
	f.responses["journalctl"] = Result{Stdout: "ssh.service\nnginx.service\n\nkaputt ohne endung\ncron.timer"}

	units, err := NewSystemWithRunner(f).LogUnits(context.Background())
	if err != nil {
		t.Fatalf("LogUnits: %v", err)
	}
	if len(units) != 3 {
		t.Fatalf("%d Units, erwartet 3: %v", len(units), units)
	}
	for _, u := range units {
		if ValidateUnit(u) != nil {
			t.Errorf("%q ist kein gültiger Unit-Name", u)
		}
	}
}

func TestRebootRequiredWithoutMarker(t *testing.T) {
	state, err := NewSystemWithRunner(newFakeRunner()).RebootRequired(context.Background())
	if err != nil {
		t.Fatalf("RebootRequired: %v", err)
	}
	// In der Testumgebung existiert die Markierung nicht.
	if state.Required && len(state.Packages) == 0 {
		t.Log("Markierung vorhanden, aber ohne Paketliste — zulässig")
	}
}
