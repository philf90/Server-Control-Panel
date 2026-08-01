package privops

import (
	"context"
	"strings"
	"testing"
)

// TestKonfigArt hält die Zuordnung fest. Sie ist eine feste Liste, keine
// Heuristik: Ein Prüfprogramm, das gegen die falsche Datei läuft, wäre
// schlimmer als keines.
func TestKonfigArt(t *testing.T) {
	faelle := map[string]string{
		"/etc/ssh/sshd_config":                 "sshd",
		"/etc/ssh/sshd_config.d/10-panel.conf": "sshd",
		"/etc/nftables.conf":                   "nftables",
		"/etc/nftables.d/panel.nft":            "nftables",
		"/etc/ssh/ssh_config":                  "",
		"/home/max/sshd_config":                "",
		"/etc/ssh/../ssh/sshd_config":          "sshd",
		// nginx kam mit Stufe 0.6 dazu. Bis dahin stand hier "" — und das war
		// richtig: Ein Prüfprogramm einzutragen, das niemand aufruft, wäre eine
		// Zusage ohne Einlösung gewesen.
		"/etc/nginx/nginx.conf":              "nginx",
		"/etc/nginx/conf.d/asylum-acme.conf": "nginx",
		"/etc/nginx/sites-enabled/default":   "nginx",
		// Aber nichts daneben: Ein Verzeichnis, das nur so ähnlich heißt, ist
		// keine nginx-Konfiguration.
		"/etc/nginx-fremd/datei.conf":    "",
		"/srv/kopie/etc/ssh/sshd_config": "",
	}
	for pfad, erwartet := range faelle {
		if got := konfigArt(pfad); got != erwartet {
			t.Errorf("konfigArt(%q) = %q, erwartet %q", pfad, got, erwartet)
		}
	}
}

func TestConfigCheckOhnePruefprogramm(t *testing.T) {
	res, err := NewSystemWithRunner(newFakeRunner()).ConfigCheck(context.Background(), "/srv/daten/notiz.txt")
	if err != nil {
		t.Fatalf("ConfigCheck: %v", err)
	}
	if res.Checked {
		t.Error("für eine gewöhnliche Datei wurde eine Prüfung gemeldet")
	}
	if res.OK {
		t.Error("OK ist gesetzt, obwohl nichts geprüft wurde — das wäre eine Zusage ohne Grundlage")
	}
}

func TestConfigCheckReichtAusgabeDurch(t *testing.T) {
	f := newFakeRunner()
	f.responses["sshd -t"] = Result{ExitCode: 1, Stderr: "/etc/ssh/sshd_config: line 3: Bad configuration option: Prt"}

	res, err := NewSystemWithRunner(f).ConfigCheck(context.Background(), "/etc/ssh/sshd_config")
	if err != nil {
		t.Fatalf("ConfigCheck: %v", err)
	}
	if !res.Checked {
		t.Fatal("die Prüfung wurde nicht als solche gemeldet")
	}
	if res.OK {
		t.Error("ein Exit-Code von 1 gilt als in Ordnung")
	}
	if !strings.Contains(res.Output, "Bad configuration option") {
		t.Errorf("die Meldung des Programms fehlt: %q", res.Output)
	}
	if res.Tool != "sshd -t" {
		t.Errorf("Tool %q", res.Tool)
	}
}

func TestConfigCheckNimmtAn(t *testing.T) {
	f := newFakeRunner()
	f.responses["nft -c -f /etc/nftables.conf"] = Result{ExitCode: 0}

	res, err := NewSystemWithRunner(f).ConfigCheck(context.Background(), "/etc/nftables.conf")
	if err != nil {
		t.Fatalf("ConfigCheck: %v", err)
	}
	if !res.Checked || !res.OK {
		t.Errorf("Ergebnis %+v, erwartet geprüft und in Ordnung", res)
	}
}
