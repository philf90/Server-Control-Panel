package acme

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/philf90/asylum/internal/certs"
)

type fakeIssuer struct {
	calls   int
	certPEM []byte
	keyPEM  []byte
	err     error
}

func (f *fakeIssuer) obtain(context.Context, []string) ([]byte, []byte, error) {
	f.calls++
	if f.err != nil {
		return nil, nil, f.err
	}
	return f.certPEM, f.keyPEM, nil
}

func newTestManager(dir string, holder *certs.Holder, iss issuer, now time.Time) *Manager {
	return &Manager{
		dir:         dir,
		domains:     []string{"panel.example.test"},
		holder:      holder,
		issuer:      iss,
		renewBefore: 30 * 24 * time.Hour,
		log:         discardLogger(),
		now:         func() time.Time { return now },
	}
}

// holderLeaf liefert das Leaf des Zertifikats im Halter, egal ob es beim Setzen
// schon geparst war.
func holderLeaf(t *testing.T, h *certs.Holder) *x509.Certificate {
	t.Helper()
	c, err := h.GetCertificate(nil)
	if err != nil {
		t.Fatal(err)
	}
	if c.Leaf != nil {
		return c.Leaf
	}
	leaf, err := x509.ParseCertificate(c.Certificate[0])
	if err != nil {
		t.Fatal(err)
	}
	return leaf
}

func selfSignedHolder(t *testing.T, notAfter time.Time) *certs.Holder {
	t.Helper()
	certPEM, keyPEM := makeCert(t, notAfter)
	pair, err := tls.X509KeyPair(certPEM, keyPEM)
	if err != nil {
		t.Fatal(err)
	}
	return certs.NewHolder(pair)
}

func TestEnsureObtainsWhenMissing(t *testing.T) {
	now := time.Now()
	dir := t.TempDir()
	holder := selfSignedHolder(t, now.Add(2*365*24*time.Hour)) // selbstsigniert, lange gültig
	fresh := now.Add(90 * 24 * time.Hour)
	certPEM, keyPEM := makeCert(t, fresh)
	iss := &fakeIssuer{certPEM: certPEM, keyPEM: keyPEM}

	m := newTestManager(dir, holder, iss, now)
	wait := m.ensure(context.Background())

	if iss.calls != 1 {
		t.Fatalf("issuer wurde %d-mal gerufen, erwartet 1", iss.calls)
	}
	if got := holderLeaf(t, holder).NotAfter; !got.Equal(fresh.Truncate(time.Second)) && !got.Equal(fresh) {
		// x509 rundet auf Sekunden; grob vergleichen.
		if got.Sub(fresh).Abs() > time.Second {
			t.Errorf("Halter trägt nicht das frische Zertifikat: NotAfter = %s, erwartet ~%s", got, fresh)
		}
	}
	// ~60 Tage bis zur Erneuerung.
	if wait < 55*24*time.Hour || wait > 65*24*time.Hour {
		t.Errorf("Wartezeit = %s, erwartet ~60 Tage", wait)
	}
}

func TestEnsureUsesExistingValidCert(t *testing.T) {
	now := time.Now()
	dir := t.TempDir()
	certPEM, keyPEM := makeCert(t, now.Add(80*24*time.Hour))
	if err := saveCert(dir, certPEM, keyPEM); err != nil {
		t.Fatal(err)
	}
	holder := selfSignedHolder(t, now.Add(2*365*24*time.Hour))
	iss := &fakeIssuer{err: errors.New("darf nicht gerufen werden")}

	m := newTestManager(dir, holder, iss, now)
	wait := m.ensure(context.Background())

	if iss.calls != 0 {
		t.Errorf("issuer wurde gerufen, obwohl ein gültiges Zertifikat vorlag")
	}
	if rem := holderLeaf(t, holder).NotAfter.Sub(now); rem < 79*24*time.Hour {
		t.Errorf("Halter trägt nicht das abgelegte Zertifikat")
	}
	if wait < 49*24*time.Hour || wait > 51*24*time.Hour {
		t.Errorf("Wartezeit = %s, erwartet ~50 Tage", wait)
	}
}

func TestEnsureRenewsNearExpiry(t *testing.T) {
	now := time.Now()
	dir := t.TempDir()
	// Nur noch 10 Tage gültig — unter der 30-Tage-Schwelle.
	oldPEM, oldKey := makeCert(t, now.Add(10*24*time.Hour))
	if err := saveCert(dir, oldPEM, oldKey); err != nil {
		t.Fatal(err)
	}
	fresh := now.Add(90 * 24 * time.Hour)
	newPEM, newKey := makeCert(t, fresh)
	iss := &fakeIssuer{certPEM: newPEM, keyPEM: newKey}
	holder := selfSignedHolder(t, now.Add(2*365*24*time.Hour))

	m := newTestManager(dir, holder, iss, now)
	m.ensure(context.Background())

	if iss.calls != 1 {
		t.Fatalf("issuer wurde %d-mal gerufen, erwartet 1 (Erneuerung)", iss.calls)
	}
	if rem := holderLeaf(t, holder).NotAfter.Sub(now); rem < 85*24*time.Hour {
		t.Errorf("nach der Erneuerung trägt der Halter nicht das frische Zertifikat")
	}
}

func TestEnsureKeepsSelfSignedOnFailure(t *testing.T) {
	now := time.Now()
	selfSignedExpiry := now.Add(2 * 365 * 24 * time.Hour)
	holder := selfSignedHolder(t, selfSignedExpiry)
	iss := &fakeIssuer{err: errors.New("kein Netz zum CA")}

	m := newTestManager(t.TempDir(), holder, iss, now)
	wait := m.ensure(context.Background())

	if wait != retryInterval {
		t.Errorf("Wartezeit = %s, erwartet %s (Wiederholung)", wait, retryInterval)
	}
	// Der Halter trägt weiterhin das selbstsignierte Zertifikat.
	if got := holderLeaf(t, holder).NotAfter; got.Sub(selfSignedExpiry).Abs() > time.Second {
		t.Error("das selbstsignierte Zertifikat wurde trotz Fehlschlags ersetzt")
	}
}

// TestEnsureBeziehtNeuBeiGeaenderterDomain deckt einen Fall ab, der erst mit
// den Einstellungen in der Oberfläche wirklich auftritt.
//
// Bis hierher sah ensure nur auf die Restlaufzeit. Wer die Domain änderte —
// seit der Zertifikatsseite ein Klick — bekam bis zu 60 Tage lang weiter das
// Zertifikat für den alten Namen ausgeliefert, und der Browser warnte zu
// Recht. Die Ursache wäre für niemanden erkennbar gewesen: Die Oberfläche
// zeigte den neuen Namen, ausgeliefert wurde der alte.
func TestEnsureBeziehtNeuBeiGeaenderterDomain(t *testing.T) {
	now := time.Now()
	dir := t.TempDir()

	// Abgelegt: ein lange gültiges Zertifikat für den *alten* Namen.
	certPEM, keyPEM := makeCert(t, now.Add(80*24*time.Hour))
	if err := saveCert(dir, certPEM, keyPEM); err != nil {
		t.Fatal(err)
	}

	neuPEM, neuKeyPEM := makeCertFor(t, "neu.example.test", now.Add(90*24*time.Hour))
	iss := &fakeIssuer{certPEM: neuPEM, keyPEM: neuKeyPEM}

	m := newTestManager(dir, selfSignedHolder(t, now.Add(2*365*24*time.Hour)), iss, now)
	m.domains = []string{"neu.example.test"}
	m.ensure(context.Background())

	if iss.calls != 1 {
		t.Fatalf("issuer wurde %d-mal gerufen, erwartet 1 — das alte Zertifikat "+
			"deckt den neuen Namen nicht ab", iss.calls)
	}
	if err := holderLeaf(t, m.holder).VerifyHostname("neu.example.test"); err != nil {
		t.Errorf("der Halter trägt kein Zertifikat für den neuen Namen: %v", err)
	}
}

// Deckt das abgelegte Zertifikat alle Namen ab, wird nicht neu bezogen — sonst
// liefe jede Prüfung in die Rate-Limits der CA.
func TestEnsureBehaeltZertifikatMitAllenNamen(t *testing.T) {
	now := time.Now()
	dir := t.TempDir()
	certPEM, keyPEM := makeCertFor(t, "panel.example.test", now.Add(80*24*time.Hour))
	if err := saveCert(dir, certPEM, keyPEM); err != nil {
		t.Fatal(err)
	}
	iss := &fakeIssuer{err: errors.New("darf nicht gerufen werden")}

	m := newTestManager(dir, selfSignedHolder(t, now.Add(2*365*24*time.Hour)), iss, now)
	m.ensure(context.Background())

	if iss.calls != 0 {
		t.Error("issuer wurde gerufen, obwohl das Zertifikat passt")
	}
}

// TestObtainNowIgnoriertVorhandenes: Der Knopf "Jetzt beziehen" soll eine
// geänderte Einstellung prüfen. Ein Aufruf, der beim ersten gültigen
// Zertifikat abbricht, prüfte gar nichts.
func TestObtainNowIgnoriertVorhandenes(t *testing.T) {
	now := time.Now()
	dir := t.TempDir()
	certPEM, keyPEM := makeCert(t, now.Add(80*24*time.Hour))
	if err := saveCert(dir, certPEM, keyPEM); err != nil {
		t.Fatal(err)
	}
	neuPEM, neuKeyPEM := makeCert(t, now.Add(90*24*time.Hour))
	iss := &fakeIssuer{certPEM: neuPEM, keyPEM: neuKeyPEM}

	m := newTestManager(dir, selfSignedHolder(t, now.Add(2*365*24*time.Hour)), iss, now)
	if err := m.ObtainNow(context.Background()); err != nil {
		t.Fatal(err)
	}
	if iss.calls != 1 {
		t.Errorf("issuer wurde %d-mal gerufen, erwartet 1", iss.calls)
	}
}

// Scheitert der Bezug, muss der Fehler beim Aufrufer ankommen — der Knopf soll
// ja gerade die Rückmeldung geben.
func TestObtainNowMeldetFehler(t *testing.T) {
	m := newTestManager(t.TempDir(), selfSignedHolder(t, time.Now().Add(time.Hour)),
		&fakeIssuer{err: errors.New("DNS-Anbieter antwortet nicht")}, time.Now())

	err := m.ObtainNow(context.Background())
	if err == nil {
		t.Fatal("kein Fehler, obwohl der Bezug fehlschlug")
	}
	if !strings.Contains(err.Error(), "DNS-Anbieter") {
		t.Errorf("der Grund fehlt in der Meldung: %v", err)
	}
}
