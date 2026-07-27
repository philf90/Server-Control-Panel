package httpd

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/certs"
	"github.com/philf90/asylum/internal/metrics"
	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
)

// TestDumpSeiten schreibt gerenderte Seiten mit reichhaltigen Testdaten in ein
// Verzeichnis, damit sich das Layout im Browser vermessen und für das README
// fotografieren lässt. Ohne ASYLUM_DUMP_DIR passiert nichts.
//
// Die Daten sind repräsentative Beispiele, kein Abzug eines echten Servers:
// Die Entwicklungsumgebung hat kein systemd, weshalb Dienste, Pakete und
// Firewall hier über den einspeisbaren Runner kommen. Die Übersicht dagegen
// zeigt echte /proc-Werte dieser Maschine.
func TestDumpSeiten(t *testing.T) {
	ziel := os.Getenv("ASYLUM_DUMP_DIR")
	if ziel == "" {
		t.Skip("ohne ASYLUM_DUMP_DIR nichts zu tun")
	}
	ctx := context.Background()

	s, ops := newSystemServer(t)
	user := addUser(t, s, "philipp", store.RoleOwner)
	cookie, _ := login(t, s, user)

	// --- Dienste: eine repräsentative Auswahl statt zweier Zeilen ---
	ops.mu.Lock()
	ops.services = nil
	dienste := []privops.Service{
		{Unit: "asylumd.service", Description: "Project Asylum — Control Panel", Active: "active", Sub: "running", Enabled: "enabled"},
		{Unit: "ssh.service", Description: "OpenSSH server daemon", Active: "active", Sub: "running", Enabled: "enabled"},
		{Unit: "nginx.service", Description: "A high performance web server", Active: "active", Sub: "running", Enabled: "enabled"},
		{Unit: "cron.service", Description: "Regular background program processing daemon", Active: "active", Sub: "running", Enabled: "enabled"},
		{Unit: "systemd-journald.service", Description: "Journal Service", Active: "active", Sub: "running", Enabled: "static"},
		{Unit: "apparmor.service", Description: "Load AppArmor profiles", Active: "active", Sub: "exited", Enabled: "enabled"},
		{Unit: "apt-daily.service", Description: "Daily apt download activities", Active: "inactive", Sub: "dead", Enabled: "static"},
		{Unit: "postgresql.service", Description: "PostgreSQL RDBMS", Active: "failed", Sub: "failed", Enabled: "enabled"},
	}
	ops.services = append(ops.services, dienste...)

	ops.firewall = privops.FirewallState{
		Backend: privops.BackendUFW, Installed: true, Active: true, Managed: true,
		Rules: []privops.FirewallRule{
			{Port: 22, Protocol: "tcp", Comment: "SSH"},
			{Port: 443, Protocol: "tcp", Comment: "HTTPS"},
			{Port: 8443, Protocol: "tcp", Comment: "Panel"},
		},
	}

	ops.sysUsers = []privops.SystemUser{
		{Name: "root", UID: 0, Home: "/root", Shell: "/bin/bash", HasShell: true, Protected: true, SSHKeys: 1},
		{Name: "philipp", UID: 1000, Home: "/home/philipp", Shell: "/bin/bash", HasShell: true, SSHKeys: 2},
		{Name: "deploy", UID: 1001, Home: "/home/deploy", Shell: "/bin/bash", HasShell: true, SSHKeys: 1},
		{Name: "www-data", UID: 33, System: true},
		{Name: "postgres", UID: 114, Home: "/var/lib/postgresql", System: true},
	}
	ops.mu.Unlock()

	// --- Konto: Wiederherstellungscodes und mehrere Sitzungen ---
	if _, hashes, err := auth.NewRecoveryCodes(); err != nil {
		t.Fatal(err)
	} else if err := s.db.ReplaceRecoveryCodes(ctx, user.ID, hashes); err != nil {
		t.Fatal(err)
	}

	now := time.Now()
	for _, se := range []store.Session{
		{ID: "dumptablet0000", UserID: user.ID, CSRFToken: "x", IP: "192.168.1.42", UserAgent: "Firefox 128 auf Android", CreatedAt: now.Add(-30 * time.Hour), LastSeenAt: now.Add(-2 * time.Hour), ExpiresAt: now.Add(time.Hour)},
		{ID: "dumplaptop0000", UserID: user.ID, CSRFToken: "y", IP: "10.0.0.9", UserAgent: "Chrome 140 auf macOS", CreatedAt: now.Add(-3 * time.Hour), LastSeenAt: now.Add(-11 * time.Minute), ExpiresAt: now.Add(time.Hour)},
	} {
		if err := s.db.CreateSession(ctx, se); err != nil {
			t.Fatal(err)
		}
	}

	// --- Audit: ein paar Einträge mit unterschiedlichem Ausgang ---
	for _, a := range []store.AuditEntry{
		{Actor: "philipp", Action: "login.success", Result: store.ResultOK, IP: "192.168.1.42"},
		{Actor: "philipp", Action: "firewall.rule.add", Target: "443/tcp", Result: store.ResultOK, IP: "192.168.1.42"},
		{Actor: "philipp", Action: "service.restart", Target: "nginx.service", Result: store.ResultOK, IP: "10.0.0.9"},
		{Actor: "deploy", Action: "login.failed", Result: store.ResultDenied, IP: "203.0.113.7"},
		{Actor: "philipp", Action: "package.upgrade", Target: "nur Sicherheit", Result: store.ResultOK, IP: "10.0.0.9"},
	} {
		if err := s.db.AppendAudit(ctx, a); err != nil {
			t.Fatal(err)
		}
	}

	// --- Zertifikat: selbstsigniertes Material anlegen, damit die Seite Daten hat ---
	if _, err := certs.EnsurePair(s.cfg.Server.TLS.Cert, s.cfg.Server.TLS.Key, []string{"panel.example.test"}); err != nil {
		t.Fatal(err)
	}

	// --- Übersicht: ein repräsentativer Snapshot ---
	//
	// Bewusst nicht s.sampler.Sample(): Die echte Messung dieser
	// Entwicklungsmaschine trüge deren Prozess- und Dateisystemnamen ins
	// Bildschirmfoto. Die Zahlen hier sind erfundene, aber plausible Werte
	// eines kleinen Servers.
	const giB = 1 << 30
	s.setLatest(metrics.Snapshot{
		At:         now,
		CPU:        metrics.CPU{Total: 6.4, PerCore: []float64{7.1, 5.0, 8.2, 5.3}, IOWait: 0.4, Steal: 0.0},
		Memory:     metrics.Memory{Total: 4 * giB, Used: 1288 * giB / 1024, UsedPct: 30.1, Available: 4*giB - 1288*giB/1024, SwapTotal: 2 * giB, SwapUsed: 0},
		Load:       [3]float64{0.18, 0.12, 0.09},
		Uptime:     8*24*time.Hour + 4*time.Hour,
		UptimeText: "8 Tage, 4 Std",
		Filesystems: []metrics.Filesystem{
			{Mount: "/", Device: "/dev/vda1", Type: "ext4", Total: 40 * giB, Used: 96 * giB / 10, UsedPct: 24.0, InodesPct: 3.2},
			{Mount: "/boot", Device: "/dev/vda2", Type: "ext4", Total: 1 * giB, Used: 36 * giB / 100, UsedPct: 36.4, InodesPct: 0.4},
		},
		Interfaces: []metrics.Interface{
			{Name: "eth0", Addrs: []string{"203.0.113.10/24", "2001:db8::10/64"}, RXRate: 12480, TXRate: 3620, RXBytes: 4823449600, TXBytes: 1290551296},
		},
		TopProcesses: []metrics.Process{
			{PID: 612, Name: "postgres", User: "postgres", CPUPct: 1.2, RSS: 268 * giB / 1024, RSSPct: 6.4},
			{PID: 388, Name: "asylumd", User: "root", CPUPct: 0.3, RSS: 16 * giB / 1024, RSSPct: 0.4},
			{PID: 701, Name: "nginx", User: "www-data", CPUPct: 0.1, RSS: 12 * giB / 1024, RSSPct: 0.3},
			{PID: 655, Name: "redis-server", User: "redis", CPUPct: 0.4, RSS: 9 * giB / 1024, RSSPct: 0.2},
			{PID: 240, Name: "systemd-journald", User: "root", CPUPct: 0.0, RSS: 11 * giB / 1024, RSSPct: 0.3},
			{PID: 421, Name: "sshd", User: "root", CPUPct: 0.0, RSS: 7 * giB / 1024, RSSPct: 0.2},
		},
	})

	seiten := []struct{ pfad, name string }{
		{"/", "uebersicht.html"},
		{"/services", "dienste.html"},
		{"/firewall", "firewall.html"},
		{"/system-users", "system-users.html"},
		{"/packages", "packages.html"},
		{"/logs", "logs.html"},
		{"/update", "updates.html"},
		{"/audit", "audit.html"},
		{"/account", "konto.html"},
		{"/certificate", "zertifikat.html"},
	}
	for _, seite := range seiten {
		rec := get(t, s, seite.pfad, cookie)
		if rec.Code != 200 {
			t.Fatalf("%s: Status %d", seite.pfad, rec.Code)
		}
		// Für das Rendern von der Platte: Stylesheet daneben statt unter /static.
		body := strings.ReplaceAll(rec.Body.String(), "/static/app.css", "app.css")
		if err := os.WriteFile(filepath.Join(ziel, seite.name), []byte(body), 0o600); err != nil {
			t.Fatal(err)
		}
		fmt.Println("geschrieben:", seite.name, len(body), "Bytes")
	}
}
