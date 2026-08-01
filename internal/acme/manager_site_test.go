package acme

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/certs"
)

// Der Manager je Zertifikat (Schritt 3, docs/18-webserver.md §4).
//
// Bis 0.5 gab es genau einen: den des Panels. Mit den Sites kommen weitere
// dazu, und die Unterschiede sind genau zwei — wohin das Zertifikat gelegt wird
// und wie es in den Halter kommt.

// siteManager baut einen Manager mit Kennung, wie New() ihn für eine Site baut.
func siteManager(dir, kennung string, holder *certs.Holder, iss issuer, now time.Time) *Manager {
	m := newTestManager(dir, holder, iss, now)
	m.kennung = kennung
	m.zertDir = zertVerzeichnis(dir, kennung)
	m.domains = []string{"shop.example.test"}
	return m
}

// Ein Zertifikat mit Kennung landet unter seiner Kennung im Halter — nicht als
// Panelzertifikat. Diese Unterscheidung ist die ganze Änderung, und wer sie
// falsch macht, nimmt dem Panel seine eigene Oberfläche.
func TestManagerMitKennungSetztDieSite(t *testing.T) {
	jetzt := time.Now()
	dir := t.TempDir()
	panelZert := selfSignedHolder(t, jetzt.Add(24*time.Hour))
	certPEM, keyPEM := makeCertFor(t, "shop.example.test", jetzt.Add(90*24*time.Hour))

	m := siteManager(dir, "shop", panelZert, &fakeIssuer{certPEM: certPEM, keyPEM: keyPEM}, jetzt)
	if _, err := m.runObtain(context.Background()); err != nil {
		t.Fatalf("Bezug: %v", err)
	}

	if k := panelZert.Kennungen(); len(k) != 1 || k[0] != "shop" {
		t.Fatalf("Kennungen im Halter = %v, erwartet [shop]", k)
	}
	// Und das Panelzertifikat ist unangetastet: Der Handshake ohne Namen
	// bekommt weiter das selbstsignierte.
	c, err := panelZert.GetCertificate(nil)
	if err != nil {
		t.Fatal(err)
	}
	if c.Leaf != nil && c.Leaf.NotAfter.After(jetzt.Add(48*time.Hour)) {
		t.Error("der Bezug einer Site hat das Panelzertifikat überschrieben")
	}
}

// Die Ablage liegt getrennt. Ohne das schrieben zwei Sites einander das
// Zertifikat um, und keiner der beiden fiele es auf — beide bekämen beim
// nächsten Start eines, das ihre Namen nicht deckt, und bezögen neu.
func TestManagerMitKennungLegtGetrenntAb(t *testing.T) {
	jetzt := time.Now()
	dir := t.TempDir()
	certPEM, keyPEM := makeCertFor(t, "shop.example.test", jetzt.Add(90*24*time.Hour))

	m := siteManager(dir, "shop", selfSignedHolder(t, jetzt.Add(24*time.Hour)),
		&fakeIssuer{certPEM: certPEM, keyPEM: keyPEM}, jetzt)
	if _, err := m.runObtain(context.Background()); err != nil {
		t.Fatalf("Bezug: %v", err)
	}

	eigen := filepath.Join(dir, "sites", "shop", "cert.pem")
	if _, err := os.Stat(eigen); err != nil {
		t.Errorf("das Zertifikat der Site liegt nicht unter %s: %v", eigen, err)
	}
	// Und NICHT im Wurzelverzeichnis, wo das des Panels liegt.
	if _, err := os.Stat(filepath.Join(dir, "cert.pem")); err == nil {
		t.Error("eine Site hat ins Wurzelverzeichnis geschrieben — dort liegt das " +
			"Zertifikat des Panels")
	}
}

// Der Kontoschlüssel wird GETEILT. Ein eigener je Site wäre ein eigenes
// ACME-Konto, und Kontoanmeldungen zählen gegen die Grenzen von Let's Encrypt —
// zwanzig Sites wären zwanzig Konten.
func TestZertVerzeichnisTrenntNurDasZertifikat(t *testing.T) {
	wurzel := "/var/lib/asylum/acme"

	if got := zertVerzeichnis(wurzel, ""); got != wurzel {
		t.Errorf("ohne Kennung = %q, erwartet das Wurzelverzeichnis %q", got, wurzel)
	}
	will := filepath.Join(wurzel, "sites", "shop")
	if got := zertVerzeichnis(wurzel, "shop"); got != will {
		t.Errorf("mit Kennung = %q, erwartet %q", got, will)
	}
}

// Die Kennung kommt mit den Sites aus einem Formular. PruefeKennung ist die
// erste Linie — eine Allowlist der zulässigen Form.
func TestPruefeKennung(t *testing.T) {
	for _, gut := range []string{"shop", "shop-2", "kunde_a", "a", "web123"} {
		if err := PruefeKennung(gut); err != nil {
			t.Errorf("%q sollte zulässig sein: %v", gut, err)
		}
	}
	for _, schlecht := range []string{
		"", "..", "../..", "/etc/nginx", "a/b", "Shop", "shop.example",
		"-shop", "_shop", "shop ", "shop;rm", strings.Repeat("a", 65),
	} {
		if err := PruefeKennung(schlecht); err == nil {
			t.Errorf("%q wurde angenommen", schlecht)
		}
	}
}

// Und die zweite Linie: Selbst wenn eine ungeprüfte Kennung durchkäme, darf sie
// nicht im Wurzelverzeichnis landen.
//
// Der Fall ist nicht theoretisch: filepath.Base("..") ist "..", und
// filepath.Join(wurzel, "sites", "..") kürzt sich auf das WURZELVERZEICHNIS —
// dort liegt das Zertifikat des Panels. Eine Site mit der Kennung ".." hätte es
// überschrieben. Gefunden hat das dieser Test.
func TestZertVerzeichnisLaesstNichtHinaus(t *testing.T) {
	wurzel := "/var/lib/asylum/acme"
	unten := filepath.Join(wurzel, "sites") + string(filepath.Separator)

	for _, kennung := range []string{
		"../..", "../fremd", "/etc/nginx", "a/b/c", "..", ".", "/",
	} {
		got := zertVerzeichnis(wurzel, kennung)
		if got == wurzel {
			t.Errorf("zertVerzeichnis(%q) = das Wurzelverzeichnis — dort liegt das "+
				"Zertifikat des Panels", kennung)
			continue
		}
		if !strings.HasPrefix(got, unten) {
			t.Errorf("zertVerzeichnis(%q) = %q — das liegt außerhalb", kennung, got)
			continue
		}
		if strings.Contains(got, "..") {
			t.Errorf("zertVerzeichnis(%q) = %q enthält noch einen Aufstieg", kennung, got)
		}
	}
}

// Das Panel bleibt, wo es war. Eine vorhandene Installation soll ihr Zertifikat
// dort wiederfinden, wo es liegt — ein Umzug wäre ein Bezug mehr für nichts,
// und einer, der gegen die Ratengrenze zählt.
func TestManagerOhneKennungBleibtImWurzelverzeichnis(t *testing.T) {
	jetzt := time.Now()
	dir := t.TempDir()
	certPEM, keyPEM := makeCertFor(t, "panel.example.test", jetzt.Add(90*24*time.Hour))

	m := newTestManager(dir, selfSignedHolder(t, jetzt.Add(24*time.Hour)),
		&fakeIssuer{certPEM: certPEM, keyPEM: keyPEM}, jetzt)
	if _, err := m.runObtain(context.Background()); err != nil {
		t.Fatalf("Bezug: %v", err)
	}
	if _, err := os.Stat(filepath.Join(dir, "cert.pem")); err != nil {
		t.Errorf("das Panelzertifikat liegt nicht mehr im Wurzelverzeichnis: %v", err)
	}
}
