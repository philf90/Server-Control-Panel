package acme

import (
	"context"
	"errors"
	"strings"
	"sync"
	"testing"
	"time"
)

// mitschrift sammelt, was gemeldet wird. Die Sperre ist keine Zierde: Der
// Bezug läuft in einer eigenen Goroutine, der Test liest von seiner aus.
type mitschrift struct {
	mu      sync.Mutex
	begonn  [][]string
	zeilen  []string
	beendet int
	fehler  error
}

func (m *mitschrift) Begin(domains []string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.begonn = append(m.begonn, append([]string(nil), domains...))
}

func (m *mitschrift) Step(text string) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.zeilen = append(m.zeilen, text)
}

func (m *mitschrift) End(err error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.beendet++
	m.fehler = err
}

func (m *mitschrift) text() string {
	m.mu.Lock()
	defer m.mu.Unlock()
	return strings.Join(m.zeilen, "\n")
}

// Ein Bezug meldet Anfang und Ende — auch der, den niemand angestoßen hat.
// Genau das ist der Unterschied zur bisherigen Anzeige: Eine Erneuerung, die
// vor Ablauf von selbst läuft, hinterließ bis hierher nur eine Logzeile.
func TestBezugMeldetAnfangUndEnde(t *testing.T) {
	jetzt := time.Now()
	certPEM, keyPEM := makeCertFor(t, "panel.example.test", jetzt.Add(90*24*time.Hour))

	m := &mitschrift{}
	mgr := newTestManager(t.TempDir(), selfSignedHolder(t, jetzt.Add(24*time.Hour)),
		&fakeIssuer{certPEM: certPEM, keyPEM: keyPEM}, jetzt)
	mgr.report = reporter{p: m}

	// ensure ist der Weg der selbsttätigen Erneuerung, nicht der des Knopfes.
	mgr.ensure(context.Background())

	m.mu.Lock()
	defer m.mu.Unlock()
	if len(m.begonn) != 1 {
		t.Fatalf("Begin %d-mal gemeldet, erwartet einmal", len(m.begonn))
	}
	if len(m.begonn[0]) != 1 || m.begonn[0][0] != "panel.example.test" {
		t.Errorf("Begin mit %v", m.begonn[0])
	}
	if m.beendet != 1 || m.fehler != nil {
		t.Errorf("End %d-mal, Fehler %v — erwartet einmal ohne Fehler", m.beendet, m.fehler)
	}
	// Das Einsetzen ist der Schritt, auf den es ankommt: Erst danach bekommt
	// ein Browser das neue Zertifikat.
	if !strings.Contains(strings.Join(m.zeilen, "\n"), "eingesetzt") {
		t.Errorf("keine Meldung über das Einsetzen: %v", m.zeilen)
	}
}

// Ein Fehlschlag muss als solcher ankommen. Bliebe End ohne Fehler, zeigte die
// Seite einen abgeschlossenen Vorgang, und niemand sähe, dass nichts passiert
// ist.
func TestFehlschlagKommtAmEndeAn(t *testing.T) {
	jetzt := time.Now()
	m := &mitschrift{}
	mgr := newTestManager(t.TempDir(), selfSignedHolder(t, jetzt.Add(24*time.Hour)),
		&fakeIssuer{err: errors.New("challenge bereitstellen: kein Zugang")}, jetzt)
	mgr.report = reporter{p: m}

	if _, err := mgr.runObtain(context.Background()); err == nil {
		t.Fatal("kein Fehler")
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if m.beendet != 1 {
		t.Fatalf("End %d-mal", m.beendet)
	}
	if m.fehler == nil || !strings.Contains(m.fehler.Error(), "kein Zugang") {
		t.Errorf("End meldete %v", m.fehler)
	}
}

// Ohne Progress bleibt der Bezug stumm und läuft trotzdem. Der Manager arbeitet
// auch ohne Oberfläche — beim ersten Start des Dienstes etwa.
func TestOhneProgressKeinAbsturz(t *testing.T) {
	jetzt := time.Now()
	certPEM, keyPEM := makeCertFor(t, "panel.example.test", jetzt.Add(90*24*time.Hour))
	mgr := newTestManager(t.TempDir(), selfSignedHolder(t, jetzt.Add(24*time.Hour)),
		&fakeIssuer{certPEM: certPEM, keyPEM: keyPEM}, jetzt)

	if _, err := mgr.runObtain(context.Background()); err != nil {
		t.Fatalf("Bezug ohne Progress: %v", err)
	}
}

// TestProgressOhneGeheimnisse ist die Zusage aus dem Kommentar an Progress: Die
// gemeldeten Zeilen gehen in einen Browser und bleiben im Puffer stehen. Der
// Challenge-Wert ist der Beweis, mit dem sich die Ausstellung erschleichen
// ließe, solange er gilt; das Anbieter-Token ist ein dauerhafter Schlüssel.
// Beides darf dort nie auftauchen.
func TestProgressOhneGeheimnisse(t *testing.T) {
	const challengeWert = "GEHEIMER-CHALLENGE-WERT-4711"

	m := &mitschrift{}
	setter := &fakeSetter{}
	solver := newDNS01Solver(setter, discardLogger(), reporter{p: m})
	solver.waitTimeout = 20 * time.Millisecond
	solver.pollEvery = time.Millisecond
	// Nie sichtbar: Damit läuft der Weg bis in die Frist hinein, der die
	// meisten Zeilen erzeugt.
	solver.lookupTXT = func(context.Context, string) ([]string, error) {
		return nil, errors.New("nicht auflösbar")
	}

	ctx := context.Background()
	if err := solver.present(ctx, "panel.example.test", "token-abc", challengeWert); err != nil {
		t.Fatal(err)
	}
	if err := solver.cleanup(ctx, "panel.example.test", "token-abc", challengeWert); err != nil {
		t.Fatal(err)
	}

	// Geprüft wird nur, was hier auch wirklich durchfließt: der Challenge-Wert
	// und das Token der Challenge. Ein Anbieter-Token kommt an dieser Stelle
	// gar nicht vorbei — es dort trotzdem zu suchen wäre eine Zusicherung, die
	// nichts prüft.
	protokoll := m.text()
	for _, geheim := range []string{challengeWert, "token-abc"} {
		if strings.Contains(protokoll, geheim) {
			t.Errorf("der Verlauf enthält ein Geheimnis (%q):\n%s", geheim, protokoll)
		}
	}
	// Der Name des Records gehört hingegen hinein — ohne ihn kann niemand
	// nachsehen, ob der Eintrag angekommen ist.
	if !strings.Contains(protokoll, "_acme-challenge.panel.example.test") {
		t.Errorf("der Verlauf nennt den Record nicht:\n%s", protokoll)
	}
}

// Das Warten auf die DNS-Ausbreitung ist der langsamste Abschnitt — bis zu zwei
// Minuten. Er muss ein Lebenszeichen geben, sonst hält man einen laufenden
// Vorgang für einen hängenden. Und wenn die Frist verstreicht, muss dastehen,
// dass trotzdem weitergemacht wird.
func TestAusbreitungMeldetWartenUndAblauf(t *testing.T) {
	m := &mitschrift{}
	solver := newDNS01Solver(&fakeSetter{}, discardLogger(), reporter{p: m})
	solver.waitTimeout = 20 * time.Millisecond
	solver.pollEvery = time.Millisecond
	solver.lookupTXT = func(context.Context, string) ([]string, error) {
		return nil, errors.New("nicht auflösbar")
	}

	if err := solver.present(context.Background(), "panel.example.test", "t", "wert"); err != nil {
		t.Fatal(err)
	}

	protokoll := m.text()
	if !strings.Contains(protokoll, "warte auf Sichtbarkeit") {
		t.Errorf("kein Lebenszeichen während des Wartens:\n%s", protokoll)
	}
	if !strings.Contains(protokoll, "noch nicht sichtbar") {
		t.Errorf("das Verstreichen der Frist wird nicht gemeldet:\n%s", protokoll)
	}

	// Eine Zeile je Versuch wäre keine Auskunft, sondern eine Wand aus Text:
	// bei zwei Minuten und vier Sekunden Takt dreißig Stück.
	if n := strings.Count(protokoll, "warte auf Sichtbarkeit"); n != 1 {
		t.Errorf("%d-mal gewartet gemeldet, erwartet einmal", n)
	}
}

// Wird der Record sichtbar, gehört die gebrauchte Zeit in den Verlauf: Sie ist
// die Antwort auf "woran hängt es eigentlich" beim nächsten Mal.
func TestAusbreitungMeldetSichtbarkeit(t *testing.T) {
	m := &mitschrift{}
	solver := newDNS01Solver(&fakeSetter{}, discardLogger(), reporter{p: m})
	solver.lookupTXT = func(context.Context, string) ([]string, error) {
		return []string{"wert"}, nil
	}

	if err := solver.present(context.Background(), "panel.example.test", "t", "wert"); err != nil {
		t.Fatal(err)
	}
	if protokoll := m.text(); !strings.Contains(protokoll, "sichtbar nach") {
		t.Errorf("die Dauer bis zur Sichtbarkeit fehlt:\n%s", protokoll)
	}
}

// Zwei Bezüge gleichzeitig gäbe es ohne Sperre: Der Knopf und die Erneuerung im
// Hintergrund laufen in verschiedenen Goroutinen und schrieben in dasselbe
// Verzeichnis. Im Verlauf liefen ihre Zeilen ineinander.
func TestNurEinBezugGleichzeitig(t *testing.T) {
	jetzt := time.Now()
	certPEM, keyPEM := makeCertFor(t, "panel.example.test", jetzt.Add(90*24*time.Hour))

	// Der Issuer hält an, bis der Test ihn losschickt — so überlappen sich die
	// beiden Aufrufe garantiert, statt nur meistens.
	tor := make(chan struct{})
	iss := &blockierenderIssuer{certPEM: certPEM, keyPEM: keyPEM, tor: tor}

	m := &mitschrift{}
	mgr := newTestManager(t.TempDir(), selfSignedHolder(t, jetzt.Add(24*time.Hour)), iss, jetzt)
	mgr.report = reporter{p: m}

	var wg sync.WaitGroup
	for range 2 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			_, _ = mgr.runObtain(context.Background())
		}()
	}
	close(tor)
	wg.Wait()

	if n := iss.gleichzeitigMax(); n > 1 {
		t.Errorf("%d Bezüge liefen gleichzeitig", n)
	}
	m.mu.Lock()
	defer m.mu.Unlock()
	if len(m.begonn) != 2 || m.beendet != 2 {
		t.Errorf("Begin %d, End %d — erwartet je zwei nacheinander", len(m.begonn), m.beendet)
	}
}

// blockierenderIssuer zählt, wie viele Bezüge sich überlappen.
type blockierenderIssuer struct {
	certPEM, keyPEM []byte
	tor             chan struct{}

	mu     sync.Mutex
	drin   int
	maxima int
}

func (b *blockierenderIssuer) obtain(context.Context, []string) ([]byte, []byte, error) {
	<-b.tor

	b.mu.Lock()
	b.drin++
	if b.drin > b.maxima {
		b.maxima = b.drin
	}
	b.mu.Unlock()

	time.Sleep(5 * time.Millisecond)

	b.mu.Lock()
	b.drin--
	b.mu.Unlock()

	return b.certPEM, b.keyPEM, nil
}

func (b *blockierenderIssuer) gleichzeitigMax() int {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.maxima
}
