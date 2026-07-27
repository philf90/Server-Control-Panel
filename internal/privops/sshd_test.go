package privops

import (
	"context"
	"os"
	"path/filepath"
	"reflect"
	"testing"
)

// mitSSHConfig legt eine Wegwerf-Konfiguration an und setzt die Pfade darauf.
func mitSSHConfig(t *testing.T, haupt string, ergaenzungen map[string]string) {
	t.Helper()
	dir := t.TempDir()

	altPath, altDir := sshdConfigPath, sshdConfigDir
	t.Cleanup(func() { sshdConfigPath, sshdConfigDir = altPath, altDir })

	sshdConfigPath = filepath.Join(dir, "sshd_config")
	sshdConfigDir = filepath.Join(dir, "sshd_config.d")

	if haupt != "" {
		if err := os.WriteFile(sshdConfigPath, []byte(haupt), 0o600); err != nil {
			t.Fatal(err)
		}
	}
	if len(ergaenzungen) > 0 {
		if err := os.MkdirAll(sshdConfigDir, 0o700); err != nil {
			t.Fatal(err)
		}
		for name, inhalt := range ergaenzungen {
			if err := os.WriteFile(filepath.Join(sshdConfigDir, name), []byte(inhalt), 0o600); err != nil {
				t.Fatal(err)
			}
		}
	}
}

func sshPorts(t *testing.T) []int {
	t.Helper()
	return NewSystemWithRunner(newFakeRunner()).SSHPorts(context.Background())
}

// Ohne Angabe lauscht sshd auf 22 — auch wenn es gar keine Konfiguration gibt.
func TestSSHPortsOhneAngabe(t *testing.T) {
	mitSSHConfig(t, "# nichts Besonderes\nPermitRootLogin no\n", nil)
	if got := sshPorts(t); !reflect.DeepEqual(got, []int{22}) {
		t.Errorf("= %v, erwartet [22]", got)
	}

	mitSSHConfig(t, "", nil)
	if got := sshPorts(t); !reflect.DeepEqual(got, []int{22}) {
		t.Errorf("ohne Datei = %v, erwartet [22]", got)
	}
}

// Der Fall, um den es geht: Wer sshd verlegt hat, bekommt sonst eine
// vorgeschlagene Firewall-Regel, die auf nichts zeigt — und merkt es erst,
// wenn der zweite Zugang zum Server schon zu ist.
func TestSSHPortsAusHauptdatei(t *testing.T) {
	mitSSHConfig(t, "Port 2222\nPermitRootLogin no\n", nil)
	if got := sshPorts(t); !reflect.DeepEqual(got, []int{2222}) {
		t.Errorf("= %v, erwartet [2222]", got)
	}
}

// Mehrere Port-Zeilen sind bei sshd kein Widerspruch, sondern mehrere Ports.
func TestSSHPortsMehrere(t *testing.T) {
	mitSSHConfig(t, "Port 22\nPort 2222\n", nil)
	if got := sshPorts(t); !reflect.DeepEqual(got, []int{22, 2222}) {
		t.Errorf("= %v, erwartet [22 2222]", got)
	}
}

// Auf Ubuntu liegt die eigentliche Einstellung oft in sshd_config.d.
func TestSSHPortsAusErgaenzung(t *testing.T) {
	mitSSHConfig(t, "Include /etc/ssh/sshd_config.d/*.conf\n", map[string]string{
		"50-cloud-init.conf": "PasswordAuthentication no\n",
		"99-port.conf":       "Port 2022\n",
	})
	if got := sshPorts(t); !reflect.DeepEqual(got, []int{2022}) {
		t.Errorf("= %v, erwartet [2022]", got)
	}
}

// In einem Match-Block gilt "Port" nicht. Ein Wert von dort wäre in der
// Firewall schlicht falsch.
func TestSSHPortsIgnoriertMatchBlock(t *testing.T) {
	mitSSHConfig(t, "Port 2222\n\nMatch Address 10.0.0.0/8\n    Port 9999\n", nil)
	if got := sshPorts(t); !reflect.DeepEqual(got, []int{2222}) {
		t.Errorf("= %v, erwartet [2222] — 9999 steht in einem Match-Block", got)
	}
}

func TestSSHPortsIgnoriertKommentareUndUnsinn(t *testing.T) {
	mitSSHConfig(t, "#Port 22\nPort abc\nPort 0\nPort 70000\nPort 2200\n", nil)
	if got := sshPorts(t); !reflect.DeepEqual(got, []int{2200}) {
		t.Errorf("= %v, erwartet [2200]", got)
	}
}
