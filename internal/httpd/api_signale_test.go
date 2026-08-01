package httpd

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"math/big"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/metrics"
	"github.com/philf90/asylum/internal/store"
)

// Prüfungen an den Signalen, die die Warnpunkte der Seitenleiste speisen.
//
// Sie stehen hier und nicht bei den Modulen, weil sie eine gemeinsame Eigenschaft
// haben: Jedes Signal ist ein Punkt im Menü, und ein Punkt, der auf eine Fläche
// zeigt, die der Leser nicht öffnen darf oder die es nicht gibt, ist schlimmer
// als kein Punkt.

// signaleMit erhebt den Handlungsbedarf im Namen eines Kontos.
//
// Über den Kontext und nicht über die Route: dashboardSignals liest die Rolle
// aus dem Kontext, und genau das soll geprüft werden.
func signaleMit(t *testing.T, s *Server, rolle string) []dashSignal {
	t.Helper()
	user := addUser(t, s, "sig-"+strings.ToLower(rolle), rolle)
	ctx := context.WithValue(t.Context(), ctxUser, user)
	return s.dashboardSignals(ctx, metrics.Snapshot{})
}

// signalMitTag sucht ein Signal an seiner Marke.
func signalMitTag(signale []dashSignal, tag string) (dashSignal, bool) {
	for _, sig := range signale {
		if sig.Tag == tag {
			return sig, true
		}
	}
	return dashSignal{}, false
}

// TestSignalFirewallProbe prüft das einzige zeitkritische Signal des Panels.
//
// Ohne Bestätigung nimmt der Wächter die Änderung binnen einer Minute zurück.
// Wer den Tab gewechselt hat, während die Uhr läuft, verliert sie — deshalb crit
// und deshalb überhaupt ein Punkt im Menü.
func TestSignalFirewallProbe(t *testing.T) {
	s := newTestServer(t)

	if _, da := signalMitTag(s.dashboardSignals(t.Context(), metrics.Snapshot{}), "Firewall"); da {
		t.Fatal("ohne laufende Probe darf es kein Firewall-Signal geben")
	}

	s.fwGuard.arm("Regelsatz", func(context.Context) error { return nil })
	t.Cleanup(func() { s.fwGuard.confirm() })

	sig, da := signalMitTag(s.dashboardSignals(t.Context(), metrics.Snapshot{}), "Firewall")
	if !da {
		t.Fatal("bei laufender Probe fehlt das Signal")
	}
	if sig.Level != "crit" {
		t.Errorf("Stufe = %q, erwartet crit — die Probe läuft ab", sig.Level)
	}
	if sig.ActionHref != "/firewall" {
		t.Errorf("der Verweis führt nach %q", sig.ActionHref)
	}
	if !strings.Contains(sig.Title, "Regelsatz") {
		t.Errorf("der Titel nennt nicht, was auf Probe steht: %q", sig.Title)
	}
	// Keine Restsekunden im Text: Die Auskunft wird im Minutentakt aufgefrischt,
	// die Frist ist selbst eine Minute. Eine Zahl darin wäre in dem Augenblick
	// falsch, in dem sie jemand liest.
	for _, zahl := range []string{"60", "59", "Sekunden"} {
		if strings.Contains(sig.Detail, zahl) {
			t.Errorf("der Text nennt eine Restzeit (%q) — sie ist beim Lesen veraltet: %q",
				zahl, sig.Detail)
		}
	}

	if !s.fwGuard.confirm() {
		t.Fatal("die Probe ließ sich nicht bestätigen")
	}
	if _, da := signalMitTag(s.dashboardSignals(t.Context(), metrics.Snapshot{}), "Firewall"); da {
		t.Error("nach der Bestätigung steht das Signal noch")
	}
}

// TestSignalZertifikat prüft die Schwellen und — wichtiger — dass ein
// selbstsigniertes Zertifikat mit Restlaufzeit KEIN Signal auslöst.
func TestSignalZertifikat(t *testing.T) {
	faelle := []struct {
		name     string
		gueltig  time.Duration
		erwartet string // "" = kein Signal
	}{
		{"lange gültig", 90 * 24 * time.Hour, ""},
		{"läuft bald ab", 3 * 24 * time.Hour, "warn"},
		{"abgelaufen", -2 * time.Hour, "crit"},
	}

	for _, f := range faelle {
		t.Run(f.name, func(t *testing.T) {
			s := newTestServer(t)
			s.cfg.Server.TLS.Cert = zertifikatMitLaufzeit(t, f.gueltig)

			sig, da := signalMitTag(s.dashboardSignals(t.Context(), metrics.Snapshot{}), "Zertifikat")
			if f.erwartet == "" {
				if da {
					t.Fatalf("erwartet war kein Signal, gefunden: %+v", sig)
				}
				return
			}
			if !da {
				t.Fatal("das Signal fehlt")
			}
			if sig.Level != f.erwartet {
				t.Errorf("Stufe = %q, erwartet %q", sig.Level, f.erwartet)
			}
			if sig.ActionHref != "/zertifikate" {
				t.Errorf("der Verweis führt nach %q", sig.ActionHref)
			}
		})
	}
}

// zertifikatMitLaufzeit schreibt ein selbstsigniertes Zertifikat mit gewünschter
// Restlaufzeit und liefert den Pfad.
//
// Eigener Erzeuger statt certs.EnsurePair: Das Paket stellt zwei Jahre aus und
// bietet keinen Weg, die Laufzeit zu wählen — für eine Prüfung der Schwellen
// bräuchte es sonst eine verstellte Systemuhr.
//
// Selbstsigniert ist hier keine Nebensache, sondern der Punkt: Die
// Zertifikatsseite stuft selbstsigniert als Warnung ein. Als Warnpunkt im Menü
// wäre das eine Markierung, die auf einem bewusst selbstsignierten Server nie
// ausgeht — und ein Punkt, der immer an ist, nimmt den anderen ihre Wirkung.
func zertifikatMitLaufzeit(t *testing.T, rest time.Duration) string {
	t.Helper()

	schluessel, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatalf("Schlüssel: %v", err)
	}
	vorlage := x509.Certificate{
		SerialNumber: big.NewInt(1),
		Subject:      pkix.Name{CommonName: "vm"},
		NotBefore:    time.Now().Add(-time.Hour),
		NotAfter:     time.Now().Add(rest),
		DNSNames:     []string{"vm"},
	}
	roh, err := x509.CreateCertificate(rand.Reader, &vorlage, &vorlage, &schluessel.PublicKey, schluessel)
	if err != nil {
		t.Fatalf("Zertifikat: %v", err)
	}

	pfad := filepath.Join(t.TempDir(), "server.crt")
	datei, err := os.Create(pfad) //nolint:gosec // Pfad aus t.TempDir()
	if err != nil {
		t.Fatalf("Datei: %v", err)
	}
	defer func() { _ = datei.Close() }()
	if err := pem.Encode(datei, &pem.Block{Type: "CERTIFICATE", Bytes: roh}); err != nil {
		t.Fatalf("PEM schreiben: %v", err)
	}
	return pfad
}

// TestSignalTokenAblauf prüft beide Fälle und die Rollenschranke.
func TestSignalTokenAblauf(t *testing.T) {
	s := newTestServer(t)
	owner := addUser(t, s, "eigner", store.RoleOwner)

	abgelaufen := time.Now().Add(-24 * time.Hour)
	bald := time.Now().Add(48 * time.Hour)
	spaeter := time.Now().Add(90 * 24 * time.Hour)
	for _, tok := range []store.APIToken{
		{Prefix: "asy_a", Hash: "a", Name: "alt", UserID: owner.ID, ExpiresAt: &abgelaufen},
		{Prefix: "asy_b", Hash: "b", Name: "bald", UserID: owner.ID, ExpiresAt: &bald},
		{Prefix: "asy_c", Hash: "c", Name: "ruhig", UserID: owner.ID, ExpiresAt: &spaeter},
		{Prefix: "asy_d", Hash: "d", Name: "ohnefrist", UserID: owner.ID},
	} {
		if _, err := s.db.CreateAPIToken(t.Context(), tok); err != nil {
			t.Fatalf("Token anlegen: %v", err)
		}
	}

	signale := signaleMit(t, s, store.RoleOwner)
	var titel []string
	for _, sig := range signale {
		if sig.Tag == "Tokens" {
			titel = append(titel, sig.Title)
			if sig.ActionHref != "/tokens" {
				t.Errorf("der Verweis führt nach %q", sig.ActionHref)
			}
		}
	}
	if len(titel) != 2 {
		t.Fatalf("erwartet waren zwei Tokensignale (abgelaufen, läuft bald ab), gefunden: %v", titel)
	}
	// Bei EINEM Token steht sein Name da und nicht eine Eins.
	if !strings.Contains(strings.Join(titel, " "), "alt") {
		t.Errorf("der Name des abgelaufenen Tokens fehlt: %v", titel)
	}
	// Und der ruhige Token taucht nirgends auf.
	if strings.Contains(strings.Join(titel, " "), "ruhig") {
		t.Errorf("ein Token mit langer Frist wurde gemeldet: %v", titel)
	}

	// Die Rollenschranke: Die Tokenseite ist der Owner-Rolle vorbehalten. Ein
	// Signal mit einem Griff, der für den Leser mit 403 endet, wäre schlimmer
	// als keines.
	for _, rolle := range []string{store.RoleAdmin, store.RoleReadOnly} {
		if _, da := signalMitTag(signaleMit(t, s, rolle), "Tokens"); da {
			t.Errorf("die Rolle %s bekommt ein Tokensignal, erreicht die Seite aber nicht", rolle)
		}
	}
}

// TestSignaleVerweisenNurAufErreichbareFlaechen ist die allgemeine Fassung
// derselben Frage.
//
// Sie hält fest, was die Warnpunkte voraussetzen: Jeder Verweis eines Signals
// muss auf eine Adresse zeigen, die die Oberfläche kennt. Zeigt er woandershin,
// bekommt der Punkt im Menü kein Ziel — er verschwindet stillschweigend, und
// niemand merkt, dass ein Signal keinen Griff mehr hat.
func TestSignaleVerweisenNurAufErreichbareFlaechen(t *testing.T) {
	s, cookie, _ := angemeldet(t, store.RoleOwner)

	var antwort apiSignale
	rec := get(t, s, "/api/v1/signals", cookie)
	if rec.Code != http.StatusOK {
		t.Fatalf("Status = %d", rec.Code)
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &antwort); err != nil {
		t.Fatalf("Antwort ist kein JSON: %v", err)
	}

	for _, sig := range antwort.Signale {
		erstes, _, _ := strings.Cut(strings.TrimPrefix(sig.AktionHref, "/"), "/")
		if !spaSeiten[erstes] {
			t.Errorf("das Signal %q verweist auf %q — diesen Pfad kennt die Oberfläche nicht",
				sig.Titel, sig.AktionHref)
		}
	}
}
