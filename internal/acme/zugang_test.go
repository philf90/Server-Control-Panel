package acme

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// zugangsdatei legt eine Datei mit dem gewünschten Modus an.
func zugangsdatei(t *testing.T, inhalt string, modus os.FileMode) string {
	t.Helper()
	pfad := filepath.Join(t.TempDir(), "zugang")
	if err := os.WriteFile(pfad, []byte(inhalt), modus); err != nil {
		t.Fatal(err)
	}
	// WriteFile beachtet die umask; der Modus wird deshalb nachgesetzt.
	if err := os.Chmod(pfad, modus); err != nil {
		t.Fatal(err)
	}
	return pfad
}

func TestLadeZugangBenannteFelder(t *testing.T) {
	pfad := zugangsdatei(t, `
# Zugang zur acme-dns-Instanz
server   = https://auth.example.org
username = 1234-abcd
password = geheim
subdomain= a1b2c3
`, 0o600)

	z, err := LadeZugang(pfad)
	if err != nil {
		t.Fatalf("LadeZugang: %v", err)
	}
	for name, will := range map[string]string{
		"server":    "https://auth.example.org",
		"username":  "1234-abcd",
		"password":  "geheim",
		"subdomain": "a1b2c3",
	} {
		if got := z.Wert(name); got != will {
			t.Errorf("%s = %q, erwartet %q", name, got, will)
		}
	}
	if z.Warnung != "" {
		t.Errorf("bei 0600 gibt es nichts anzumerken: %q", z.Warnung)
	}
}

// Die Datei von 0.5 enthielt nur den Token. Sie muss weiter funktionieren —
// wer von dort kommt, soll nichts ändern müssen.
func TestLadeZugangNimmtDieAlteTokendatei(t *testing.T) {
	pfad := zugangsdatei(t, "  ein-cloudflare-token\n", 0o600)

	z, err := LadeZugang(pfad)
	if err != nil {
		t.Fatalf("LadeZugang: %v", err)
	}
	if got := z.Geheimnis("api_token", "token"); got != "ein-cloudflare-token" {
		t.Errorf("Geheimnis = %q", got)
	}
}

// Und dieselbe Datei mit benanntem Feld ergibt dasselbe.
func TestLadeZugangBenanntesGeheimnisGehtVor(t *testing.T) {
	pfad := zugangsdatei(t, "api_token = benannt\n", 0o600)
	z, err := LadeZugang(pfad)
	if err != nil {
		t.Fatal(err)
	}
	if got := z.Geheimnis("api_token", "token"); got != "benannt" {
		t.Errorf("Geheimnis = %q", got)
	}
}

// Die Rechteprüfung ist der Grund, warum es LadeZugang gibt. Ein DNS-Token
// stellt Zertifikate für die ganze Zone aus.
func TestLadeZugangLehntWeltlesbareDateiAb(t *testing.T) {
	pfad := zugangsdatei(t, "token\n", 0o644)

	_, err := LadeZugang(pfad)
	if err == nil {
		t.Fatal("eine für alle lesbare Zugangsdatei muss abgelehnt werden")
	}
	// Die Meldung nennt den Ausweg. Eine Ablehnung ohne den Handgriff dazu
	// schickt jemanden auf die Suche.
	if !strings.Contains(err.Error(), "chmod 600") {
		t.Errorf("die Meldung nennt den Handgriff nicht: %v", err)
	}
}

// Gruppenlesbar ist eine Anmerkung, kein Abbruch: Eine Gruppe für die
// Betreiber ist eine übliche und bewusste Einrichtung.
func TestLadeZugangWarntBeiGruppenrechten(t *testing.T) {
	pfad := zugangsdatei(t, "token\n", 0o640)

	z, err := LadeZugang(pfad)
	if err != nil {
		t.Fatalf("gruppenlesbar darf den Bezug nicht aufhalten: %v", err)
	}
	if z.Warnung == "" {
		t.Error("gruppenlesbar gehört angemerkt")
	}
}

func TestLadeZugangFehlerfaelle(t *testing.T) {
	if _, err := LadeZugang(""); err == nil {
		t.Error("ohne Pfad muss ein Fehler kommen")
	}
	if _, err := LadeZugang(filepath.Join(t.TempDir(), "gibtsnicht")); err == nil {
		t.Error("eine fehlende Datei muss ein Fehler sein")
	}
	if _, err := LadeZugang(t.TempDir()); err == nil {
		t.Error("ein Verzeichnis ist keine Zugangsdatei")
	}
	if _, err := LadeZugang(zugangsdatei(t, "\n# nur ein Kommentar\n", 0o600)); err == nil {
		t.Error("eine leere Datei muss ein Fehler sein")
	}
}

// Die Meldung nennt ALLE fehlenden Felder auf einmal. Wer eine Zugangsdatei
// anlegt, soll sie einmal ergänzen und nicht dreimal — jeder Versuch dazwischen
// ist ein Fehlversuch beim CA-Server.
func TestZugangPflichtNenntAlleFehlenden(t *testing.T) {
	z, err := LadeZugang(zugangsdatei(t, "server = https://auth.example.org\n", 0o600))
	if err != nil {
		t.Fatal(err)
	}
	err = z.Pflicht("server", "username", "password", "subdomain")
	if err == nil {
		t.Fatal("drei Felder fehlen")
	}
	for _, name := range []string{"username", "password", "subdomain"} {
		if !strings.Contains(err.Error(), name) {
			t.Errorf("die Meldung nennt %q nicht: %v", name, err)
		}
	}
	if strings.Contains(err.Error(), "server") && !strings.Contains(err.Error(), "zugang") {
		t.Errorf("das vorhandene Feld gehört nicht in die Meldung: %v", err)
	}
}

// Das Register ist die einzige Liste der Anbieter. Ein Anbieter, der sich hier
// nicht einträgt, ist in der Konfiguration unbekannt — und das soll beim
// Übersetzen auffallen und nicht im Betrieb.
func TestRegisterKenntDieAnbieter(t *testing.T) {
	namen := AnbieterNamen()
	if len(namen) == 0 || namen[0] != providerHook {
		t.Fatalf("hook gehört an die erste Stelle — er ist der, der immer geht: %v", namen)
	}
	for _, muss := range []string{providerHook, providerCloudflare, providerAcmeDNS} {
		if !AnbieterBekannt(muss) {
			t.Errorf("%q fehlt im Register", muss)
		}
	}
	if AnbieterBekannt("route53") {
		t.Error("route53 ist nicht gebaut und darf nicht als bekannt gelten")
	}

	// Jeder registrierte Anbieter sagt, woher seine Zugangsdaten kommen. Ohne
	// den Satz steht in der Oberfläche ein Dateifeld ohne Anleitung.
	for _, a := range Anbieterliste() {
		if a.Titel == "" || a.Hinweis == "" {
			t.Errorf("Anbieter %q ohne Titel oder Hinweis", a.Name)
		}
		if a.baue == nil {
			t.Errorf("Anbieter %q kann nichts bauen", a.Name)
		}
	}
}

// Fehlt ein Pflichtfeld, kommt der Fehler beim BAU des Setzers — also lange
// bevor irgendetwas beim CA-Server anfragt.
func TestNewDNSSetterPrueftPflichtfelder(t *testing.T) {
	pfad := zugangsdatei(t, "server = https://auth.example.org\n", 0o600)

	_, err := newDNSSetter(Options{DNS01Provider: providerAcmeDNS, ZugangsDatei: pfad})
	if err == nil {
		t.Fatal("acme-dns ohne username/password/subdomain muss scheitern")
	}
	if !strings.Contains(err.Error(), "username") {
		t.Errorf("die Meldung nennt das fehlende Feld nicht: %v", err)
	}
}

func TestNewDNSSetterBautAcmeDNS(t *testing.T) {
	pfad := zugangsdatei(t, `
server = https://auth.example.org/
username = benutzer
password = geheim
subdomain = a1b2c3
`, 0o600)

	setter, err := newDNSSetter(Options{DNS01Provider: providerAcmeDNS, ZugangsDatei: pfad})
	if err != nil {
		t.Fatalf("newDNSSetter: %v", err)
	}
	a, ok := setter.(*acmeDNSSetter)
	if !ok {
		t.Fatalf("erwartet acmeDNSSetter, bekam %T", setter)
	}
	// Der abschließende Schrägstrich fällt weg — sonst stünde in der Adresse
	// ein doppelter.
	if a.basis != "https://auth.example.org" {
		t.Errorf("Basis = %q", a.basis)
	}
}

// Über diese Verbindung gehen Zugangsdaten. http würde sie bei jedem Bezug im
// Klartext über das Netz schicken, und der Betreiber merkte es nie.
func TestAcmeDNSVerlangtHTTPS(t *testing.T) {
	for _, adresse := range []string{"http://auth.example.org", "auth.example.org", ""} {
		pfad := zugangsdatei(t, "server = "+adresse+"\nusername = u\npassword = p\nsubdomain = s\n", 0o600)
		if _, err := newDNSSetter(Options{DNS01Provider: providerAcmeDNS, ZugangsDatei: pfad}); err == nil {
			t.Errorf("%q wurde als Serveradresse angenommen", adresse)
		}
	}
}

// Der Übergang von 0.5. Eine vorhandene Konfiguration nennt den Pfad unter
// acme.dns01.cloudflare.api_token_file; sie muss weiterlaufen, ohne dass
// jemand etwas anfasst. Ein Panel, das nach einem Update stillschweigend keine
// Zertifikate mehr erneuert, merkt das niemand — bis sechzig Tage später der
// Browser warnt.
//
// Der Test steht hier und nicht bei config, weil hier die Datei gelesen wird:
// Geprüft ist der Übergang erst, wenn aus dem alten Pfad ein Setzer wird.
func TestZugangDerAlteCloudflarePfadLaeuftWeiter(t *testing.T) {
	pfad := zugangsdatei(t, "alter-token\n", 0o600)

	setter, err := newDNSSetter(Options{
		DNS01Provider: providerCloudflare,
		ZugangsDatei:  pfad, // so, wie config.ACMEDNS01.ZugangsDatei() ihn liefert
	})
	if err != nil {
		t.Fatalf("die Datei von 0.5 muss weiter funktionieren: %v", err)
	}
	c, ok := setter.(*cloudflareSetter)
	if !ok {
		t.Fatalf("erwartet cloudflareSetter, bekam %T", setter)
	}
	if c.token != "alter-token" {
		t.Errorf("Token = %q", c.token)
	}
}
