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

	// Ein zweites Panel-Konto: Ohne ein fremdes Konto zeigt „Panel-Zugänge" den
	// Abschnitt zum Zurücksetzen nicht — das eigene steht dort bewusst nicht zur
	// Auswahl.
	addUser(t, s, "kollege", store.RoleReadOnly)

	// --- Passkeys: Funktion an, zwei Beispielschlüssel für das Bildschirmfoto ---
	enablePasskeys(t, s)
	for _, pk := range []store.WebAuthnCredential{
		{UserID: user.ID, CredentialID: "pk-phone", Label: "iPhone", Data: []byte(`{"flags":{"backupEligible":true,"backupState":true}}`)},
		{UserID: user.ID, CredentialID: "pk-key", Label: "Titan-Stick", Data: []byte(`{}`)},
	} {
		if _, err := s.db.AddWebAuthnCredential(ctx, pk); err != nil {
			t.Fatal(err)
		}
	}

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

	// --- Pakete: ein abgeschlossener Lauf von apt-get update ---
	//
	// Die Zeilen sind die eines echten Laufs (Ubuntu 24.04, LC_ALL=C), samt
	// eines Teilerfolgs: Zwei aufgegebene PPAs antworten mit 403, die übrigen
	// Quellen sind neu. Genau dieser Fall soll im Bildschirmfoto zu sehen sein —
	// er ist der Grund, warum der Auszug überhaupt angezeigt wird.
	if j, ok := s.jobs.start(jobPackages, "philipp"); ok {
		for _, zeile := range []string{
			"Err:1 https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble InRelease",
			"  403  Forbidden [IP: 185.125.189.187 443]",
			"Hit:2 http://archive.ubuntu.com/ubuntu noble InRelease",
			"Get:3 http://security.ubuntu.com/ubuntu noble-security InRelease [126 kB]",
			"Get:4 http://archive.ubuntu.com/ubuntu noble-updates InRelease [126 kB]",
			"Get:5 https://download.docker.com/linux/ubuntu noble InRelease [48.5 kB]",
			"Get:6 http://security.ubuntu.com/ubuntu noble-security/main amd64 Packages [1110 kB]",
			"Get:7 http://archive.ubuntu.com/ubuntu noble-updates/main amd64 Packages [1433 kB]",
			"Fetched 2843 kB in 2s (1421 kB/s)",
			"Reading package lists...",
			"E: Failed to fetch https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/noble/InRelease  403  Forbidden [IP: 185.125.189.187 443]",
			"E: The repository 'https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble InRelease' is no longer signed.",
		} {
			j.append(zeile)
		}
		j.setNote(refreshHinweis(privops.PackageRefreshResult{
			Reached: 6,
			Failed: []privops.SourceFailure{{
				Source: "https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble InRelease",
				Reason: "403 Forbidden [IP: 185.125.189.187 443]",
			}},
		}))
		j.finish(nil)
	}

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

	// --- Zertifikat: ein abgeschlossener Bezug, damit der Verlauf zu sehen ist ---
	//
	// Die Zeilen entsprechen dem, was certProgress bei einem DNS-01-Durchlauf
	// meldet. Ein echter Bezug ist hier nicht möglich (kein ACME-Server), aber
	// die Darstellung soll trotzdem messbar sein.
	zertVorgang := certProgress{s: s}
	zertVorgang.Begin([]string{"panel.example.org"})
	for _, zeile := range []string{
		"Kontoschlüssel bereit",
		"Bei Let's Encrypt (Testverzeichnis) angemeldet als admin@example.org",
		"Prüfverfahren: dns-01",
		"Auftrag angelegt, 1 Autorisierung(en) zu erledigen",
		"_acme-challenge.panel.example.org: TXT-Record gesetzt",
		"_acme-challenge.panel.example.org: warte auf Sichtbarkeit im DNS (bis zu 2m0s)",
		"_acme-challenge.panel.example.org: sichtbar nach 12s",
		"panel.example.org: Prüfung angestoßen, warte auf Let's Encrypt (Testverzeichnis)",
		"panel.example.org: bestätigt",
		"_acme-challenge.panel.example.org: TXT-Record entfernt",
		"Schlüssel erzeugt, Zertifikatsanforderung eingereicht",
		"Zertifikat abgeholt, Kette aus 2 Zertifikat(en)",
		"Zertifikat eingesetzt, gültig bis 2026-10-25 09:14 UTC",
	} {
		zertVorgang.Step(zeile)
	}
	zertVorgang.End(nil)

	// --- Übersicht: Ringpuffer für die Telemetrieverläufe (Sparklines) ---
	//
	// Der Leitstand zeichnet je Kachel den Verlauf der letzten Stunden. Die
	// Werte hier sind erfunden, aber plausibel — ein ruhiger Server mit einer
	// kleinen CPU-Spitze in der Nacht.
	const giB = 1 << 30
	cpuVerlauf := []float64{5, 6, 5, 7, 6, 8, 7, 9, 12, 16, 22, 19, 14, 11, 9, 8, 7, 7, 6, 7, 6, 7, 6, 6}
	memVerlauf := []float64{30, 30, 29, 30, 31, 30, 30, 31, 30, 30, 29, 30, 30, 31, 30, 30, 30, 29, 30, 30, 31, 30, 30, 30}
	loadVerlauf := []float64{.10, .11, .10, .12, .11, .13, .15, .18, .16, .14, .13, .12, .12, .13, .12, .11, .12, .13, .14, .13, .12, .12, .11, .12}
	netVerlauf := []float64{4, 7, 5, 9, 12, 8, 14, 10, 18, 11, 7, 13, 9, 16, 10, 6, 12, 8, 11, 15, 9, 7, 10, 12}
	for i := range cpuVerlauf {
		s.ring.Add(metrics.Snapshot{
			At:     now.Add(time.Duration(i-len(cpuVerlauf)) * time.Hour),
			CPU:    metrics.CPU{Total: cpuVerlauf[i]},
			Memory: metrics.Memory{UsedPct: memVerlauf[i]},
			Load:   [3]float64{loadVerlauf[i], 0, 0},
			// docker0 steht bewusst dabei: Der Netzverlauf zählt nur die
			// markierte Schnittstelle. Wäre er die Summe über alle, käme eine
			// Zahl heraus, die zu keiner Angabe auf der Kachel gehört.
			Interfaces: []metrics.Interface{
				{Name: "docker0"},
				{Name: "eth0", Physical: true, Primary: true,
					RXRate: netVerlauf[i] * 1024, TXRate: netVerlauf[i] * 300},
			},
		})
	}

	// --- Übersicht: ein repräsentativer Snapshot ---
	//
	// Bewusst nicht s.sampler.Sample(): Die echte Messung dieser
	// Entwicklungsmaschine trüge deren Prozess- und Dateisystemnamen ins
	// Bildschirmfoto. Die Zahlen hier sind erfundene, aber plausible Werte
	// eines kleinen Servers.
	s.setLatest(metrics.Snapshot{
		At:         now,
		CPU:        metrics.CPU{Total: 6.4, PerCore: []float64{7.1, 5.0, 8.2, 5.3}, IOWait: 0.4, Steal: 0.0},
		Memory:     metrics.Memory{Total: 4 * giB, Used: 1288 * giB / 1024, UsedPct: 30.1, Available: 4*giB - 1288*giB/1024, SwapTotal: 2 * giB, SwapUsed: 0},
		Load:       [3]float64{0.18, 0.12, 0.09},
		Uptime:     8*24*time.Hour + 4*time.Hour,
		UptimeText: "8 Tage, 4 Std",
		// Die weiteren Einhängepunkte von / sind die, die die ausgelieferte
		// systemd-Unit anlegt: Ihre Härtung hängt Teile von / erneut ein. Auf
		// einem echten Server steht die Liste genau so da — im Bildschirmfoto
		// soll deshalb auch der Aufklapper zu sehen sein.
		Filesystems: []metrics.Filesystem{
			{Mount: "/", Device: "/dev/vda1", Type: "ext4", Total: 40 * giB, Used: 96 * giB / 10, UsedPct: 24.0, InodesPct: 3.2,
				AlsoAt: []string{"/etc/asylum", "/tmp", "/usr", "/var/lib/asylum", "/var/log/asylum", "/var/tmp"}},
			{Mount: "/boot", Device: "/dev/vda2", Type: "ext4", Total: 1 * giB, Used: 36 * giB / 100, UsedPct: 36.4, InodesPct: 0.4},
		},
		Interfaces: []metrics.Interface{
			{Name: "docker0", Addrs: []string{"172.17.0.1/16"}},
			{Name: "eth0", Addrs: []string{"203.0.113.10/24", "2001:db8::10/64"}, Physical: true, Primary: true,
				RXRate: 12480, TXRate: 3620, RXBytes: 4823449600, TXBytes: 1290551296},
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

	// --- Dateimanager: ein Verzeichnis mit Beispielinhalt ---
	//
	// Auch hier nicht das echte Dateisystem: Ein Bildschirmfoto soll nicht die
	// Verzeichnisse dieser Maschine zeigen, und die Entwicklungsumgebung hat
	// weder /etc/nginx noch typische Server-Ablagen.
	dateiWurzel := dumpDateien(t, s)

	seiten := []struct{ pfad, name string }{
		{"/", "uebersicht.html"},
		{"/alt/files?path=" + dateiWurzel, "dateien.html"},
		{"/alt/files/entry?path=" + dateiWurzel + "/nginx", "datei-detail.html"},
		{"/alt/files/edit?path=" + dateiWurzel + "/nginx/nginx.conf", "datei-editor.html"},
		{"/alt/services", "dienste.html"},
		{"/alt/firewall", "firewall.html"},
		{"/alt/system-users", "system-users.html"},
		{"/alt/packages", "packages.html"},
		{"/alt/logs", "logs.html"},
		{"/alt/update", "updates.html"},
		{"/alt/audit", "audit.html"},
		// Panel-Zugänge fehlte in dieser Liste. Die Seite trägt zwei Formulare
		// (Konto anlegen, Zugang zurücksetzen) — genau die Art Inhalt, deren
		// Umbruch man im Browser nachmessen will.
		{"/alt/users", "panel-zugaenge.html"},
		{"/alt/account", "konto.html"},
		{"/alt/certificate", "zertifikat.html"},
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

// dumpDateien richtet für das Bildschirmfoto ein Verzeichnis mit
// Beispielinhalt ein und hängt den Dateimanager davor.
//
// Enthalten ist bewusst auch ein gesperrter Eintrag und ein Verweis: Beides
// sieht man auf dem Bild sonst nie, und beides ist eine Aussage der Oberfläche,
// die stimmen muss.
func dumpDateien(t *testing.T, s *Server) string {
	t.Helper()

	// Fester, lesbarer Ort statt t.TempDir(): Der Pfad steht im klickbaren
	// Pfad des Bildschirmfotos, und "TestDumpSeiten2328801387" sieht dort aus
	// wie ein Fehler. Das Verzeichnis wird am Ende wieder entfernt.
	basis, err := filepath.EvalSymlinks(os.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	wurzel := filepath.Join(basis, "asylum-beispiel", "etc")
	if err := os.RemoveAll(filepath.Dir(wurzel)); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = os.RemoveAll(filepath.Dir(wurzel)) })

	beispiele := map[string]string{
		"nginx/nginx.conf": `user www-data;
worker_processes auto;
pid /run/nginx.pid;

events {
    worker_connections 768;
}

http {
    sendfile on;
    tcp_nopush on;
    types_hash_max_size 2048;
    server_tokens off;

    include /etc/nginx/mime.types;
    default_type application/octet-stream;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers off;

    access_log /var/log/nginx/access.log;
    error_log /var/log/nginx/error.log;

    gzip on;

    include /etc/nginx/conf.d/*.conf;
    include /etc/nginx/sites-enabled/*;
}
`,
		"nginx/sites-enabled/beispiel.conf": "server {\n  listen 443 ssl;\n  server_name beispiel.de;\n}\n",
		"asylum/config.yaml":                "server:\n  bind: 0.0.0.0\n  port: 8443\n",
		"asylum/conf.d/10-tls.yaml":         "server:\n  tls:\n    mode: acme\n",
		"ssl/private/server.key":            "-----BEGIN PRIVATE KEY-----\n",
		"hosts":                             "127.0.0.1 localhost\n::1 localhost\n",
		"fstab":                             "/dev/vda1 / ext4 defaults 0 1\n",
		"os-release":                        "PRETTY_NAME=\"Ubuntu 24.04.4 LTS\"\n",
	}
	for name, inhalt := range beispiele {
		pfad := filepath.Join(wurzel, name)
		if err := os.MkdirAll(filepath.Dir(pfad), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(pfad, []byte(inhalt), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	// Ein Verweis und ein gesperrter Pfad: Beides ist eine Aussage der
	// Oberfläche, die stimmen muss, und auf einem Bild sonst nie zu sehen.
	if err := os.Symlink("nginx/nginx.conf", filepath.Join(wurzel, "nginx.conf")); err != nil {
		t.Fatal(err)
	}

	fsys, err := privops.NewFileSystem(privops.FilesPolicy{
		ReadableRoots: []string{wurzel},
		WritableRoots: []string{wurzel},
		DeniedPaths:   []string{filepath.Join(wurzel, "ssl", "private", "*")},
		BackupDir:     filepath.Join(t.TempDir(), "backups"),
	})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(fsys.Close)
	s.files = fsys
	return wurzel
}
