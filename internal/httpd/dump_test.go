package httpd

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// TestDumpSeiten schreibt gerenderte Seiten mit reichhaltigen Testdaten in ein
// Verzeichnis, damit sich das Layout im Browser vermessen lässt. Ohne
// ASYLUM_DUMP_DIR passiert nichts.
func TestDumpSeiten(t *testing.T) {
	ziel := os.Getenv("ASYLUM_DUMP_DIR")
	if ziel == "" {
		t.Skip("ohne ASYLUM_DUMP_DIR nichts zu tun")
	}

	s, ops := newSystemServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	ops.mu.Lock()
	ops.services = nil
	beschreibungen := []string{
		"Load AppArmor profiles",
		"Process error reports when automatic reporting is enabled",
		"automatic crash report generation",
		"Daily apt upgrade and clean activities",
		"Daily apt download activities",
		"Project Asylum — Control Panel",
		"auditd.service",
		"Availability of block devices",
		"Cloud-init: Local Stage (pre-network)",
		"Regular background program processing daemon",
		"Getty on tty1",
		"D-Bus System Message Bus",
	}
	namen := []string{
		"apparmor", "apport-autoreport", "apport", "apt-daily-upgrade",
		"apt-daily", "asylumd", "auditd", "blk-availability",
		"cloud-init-local", "cron", "getty@tty1", "dbus",
	}
	for i, n := range namen {
		aktiv, sub, enabled := "active", "exited", "enabled"
		switch i % 3 {
		case 1:
			aktiv, sub, enabled = "inactive", "dead", "static"
		case 2:
			aktiv, sub, enabled = "active", "running", "enabled"
		}
		ops.services = append(ops.services, privops.Service{
			Unit: n + ".service", Active: aktiv, Sub: sub,
			Enabled: enabled, Description: beschreibungen[i],
		})
	}
	ops.firewall = privops.FirewallState{
		Backend: privops.BackendUFW, Installed: true, Managed: true,
		Rules: []privops.FirewallRule{{Port: 80, Protocol: "tcp", Comment: "HTTP"}},
	}
	ops.mu.Unlock()

	for _, pfad := range []string{"/services", "/firewall", "/system-users", "/packages"} {
		rec := get(t, s, pfad, cookie)
		name := filepath.Base(pfad) + ".html"
		body := rec.Body.String()
		// Für das Rendern von der Platte: Stylesheet daneben statt unter /static.
		body = strings.ReplaceAll(body, "/static/app.css", "app.css")
		if err := os.WriteFile(filepath.Join(ziel, name), []byte(body), 0o600); err != nil {
			t.Fatal(err)
		}
		fmt.Println("geschrieben:", name, len(body), "Bytes")
	}
}
