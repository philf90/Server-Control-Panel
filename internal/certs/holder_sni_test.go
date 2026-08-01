package certs

import (
	"crypto/tls"
	"path/filepath"
	"testing"
)

// Die SNI-Auswahl des Halters.
//
// Geprüft wird mit ECHTEN Zertifikaten und nicht mit einer Attrappe: Die
// Auswahl hängt an den SANs, und ob die richtig gelesen werden, ist genau die
// Frage. Ein Test gegen eine Attrappe prüfte die Attrappe.

// paarMitNamen baut ein selbstsigniertes Paar für die gewünschten Namen.
func paarMitNamen(t *testing.T, namen ...string) tls.Certificate {
	t.Helper()
	dir := t.TempDir()
	cert := filepath.Join(dir, "server.crt")
	key := filepath.Join(dir, "server.key")
	if _, err := EnsurePair(cert, key, namen); err != nil {
		t.Fatal(err)
	}
	paar, err := tls.LoadX509KeyPair(cert, key)
	if err != nil {
		t.Fatal(err)
	}
	return paar
}

// hole fragt den Halter nach einem Namen — so, wie es der TLS-Handshake tut.
func hole(t *testing.T, h *Holder, name string) *tls.Certificate {
	t.Helper()
	c, err := h.GetCertificate(&tls.ClientHelloInfo{ServerName: name})
	if err != nil {
		t.Fatalf("GetCertificate(%q): %v", name, err)
	}
	return c
}

func TestHolderWaehltNachSNI(t *testing.T) {
	panel := paarMitNamen(t, "panel.example.test")
	shop := paarMitNamen(t, "shop.example.test")

	h := NewHolder(panel)
	if err := h.SetzeSite("shop", shop); err != nil {
		t.Fatalf("SetzeSite: %v", err)
	}

	if !sameLeaf(hole(t, h, "shop.example.test"), &shop) {
		t.Error("für shop.example.test kam nicht das Zertifikat der Site")
	}
	if !sameLeaf(hole(t, h, "panel.example.test"), &panel) {
		t.Error("für panel.example.test kam nicht das Panelzertifikat")
	}
}

// Der Rückfall: Ein unbekannter Name bekommt das Panelzertifikat, keinen
// Abbruch. Ein Browser zeigt dann eine Warnung, die man lesen und verstehen
// kann; ein Abbruch sieht aus wie ein toter Server.
func TestHolderFaelltAufDasPanelZurueck(t *testing.T) {
	panel := paarMitNamen(t, "panel.example.test")
	h := NewHolder(panel)
	if err := h.SetzeSite("shop", paarMitNamen(t, "shop.example.test")); err != nil {
		t.Fatal(err)
	}

	for _, name := range []string{"fremd.example.test", "", "nur.eine.ip"} {
		if !sameLeaf(hole(t, h, name), &panel) {
			t.Errorf("für %q kam nicht das Panelzertifikat", name)
		}
	}
	// Und ohne ClientHello überhaupt — den Fall gibt es in Tests und bei
	// Werkzeugen, die die Struktur nicht füllen.
	c, err := h.GetCertificate(nil)
	if err != nil || !sameLeaf(c, &panel) {
		t.Errorf("ohne ClientHello muss das Panelzertifikat kommen: %v", err)
	}
}

// Die Regel, an der alles hängt: Das Panel verliert seinen eigenen Namen nie an
// eine Site. Ohne diese Reihenfolge könnte eine Site auf den Namen des Panels
// angelegt werden und dessen TLS übernehmen — wer das versehentlich tut,
// sperrt sich aus der Oberfläche aus, mit der er es zurücknehmen müsste.
func TestHolderPanelGewinntGegenEineSite(t *testing.T) {
	panel := paarMitNamen(t, "panel.example.test")
	frecheSite := paarMitNamen(t, "panel.example.test", "shop.example.test")

	h := NewHolder(panel)
	if err := h.SetzeSite("shop", frecheSite); err != nil {
		t.Fatal(err)
	}

	if !sameLeaf(hole(t, h, "panel.example.test"), &panel) {
		t.Error("eine Site hat dem Panel seinen eigenen Namen weggenommen")
	}
	// Ihren eigenen Namen bedient sie weiterhin.
	if !sameLeaf(hole(t, h, "shop.example.test"), &frecheSite) {
		t.Error("die Site bedient ihren eigenen Namen nicht mehr")
	}
}

// Ein Platzhalter deckt GENAU EINE Ebene ab. Das ist keine Feinheit, sondern
// die Regel aus RFC 6125 — wer großzügiger sucht, liefert ein Zertifikat aus,
// dem die Gegenseite gleich darauf widerspricht.
func TestHolderPlatzhalterDecktGenauEineEbene(t *testing.T) {
	panel := paarMitNamen(t, "panel.example.test")
	stern := paarMitNamen(t, "*.kunden.test")

	h := NewHolder(panel)
	if err := h.SetzeSite("kunden", stern); err != nil {
		t.Fatal(err)
	}

	if !sameLeaf(hole(t, h, "a.kunden.test"), &stern) {
		t.Error("der Platzhalter greift für a.kunden.test nicht")
	}
	// Zwei Ebenen: NICHT gedeckt — es kommt der Rückfall.
	if sameLeaf(hole(t, h, "a.b.kunden.test"), &stern) {
		t.Error("der Platzhalter greift für zwei Ebenen — dem widerspricht jeder Browser")
	}
	// Die nackte Domain: ebenfalls nicht gedeckt. Die Eigenschaft, die am
	// häufigsten überrascht.
	if sameLeaf(hole(t, h, "kunden.test"), &stern) {
		t.Error("*.kunden.test deckt kunden.test nicht ab")
	}
}

// Der genaue Name gewinnt gegen den Platzhalter. Wer für eine einzelne
// Subdomain ein eigenes Zertifikat hinterlegt, will genau das ausgeliefert
// bekommen.
func TestHolderGenauerNameGewinntGegenPlatzhalter(t *testing.T) {
	panel := paarMitNamen(t, "panel.example.test")
	stern := paarMitNamen(t, "*.kunden.test")
	einzeln := paarMitNamen(t, "gross.kunden.test")

	h := NewHolder(panel)
	if err := h.SetzeSite("alle", stern); err != nil {
		t.Fatal(err)
	}
	if err := h.SetzeSite("gross", einzeln); err != nil {
		t.Fatal(err)
	}

	if !sameLeaf(hole(t, h, "gross.kunden.test"), &einzeln) {
		t.Error("der genaue Name muss gegen den Platzhalter gewinnen")
	}
	if !sameLeaf(hole(t, h, "klein.kunden.test"), &stern) {
		t.Error("für die übrigen greift weiter der Platzhalter")
	}
}

// Erneuern heißt ersetzen, nicht danebenlegen: Sonst führte der Halter zwei
// Zertifikate für dieselbe Site, und welches ausgeliefert wird, hinge am
// Zufall.
func TestHolderErneuernErsetzt(t *testing.T) {
	h := NewHolder(paarMitNamen(t, "panel.example.test"))
	if err := h.SetzeSite("shop", paarMitNamen(t, "shop.example.test")); err != nil {
		t.Fatal(err)
	}
	neu := paarMitNamen(t, "shop.example.test")
	if err := h.SetzeSite("shop", neu); err != nil {
		t.Fatal(err)
	}

	if k := h.Kennungen(); len(k) != 1 || k[0] != "shop" {
		t.Errorf("Kennungen = %v, erwartet genau [shop]", k)
	}
	if !sameLeaf(hole(t, h, "shop.example.test"), &neu) {
		t.Error("nach dem Erneuern kam das alte Zertifikat")
	}
}

func TestHolderEntfernen(t *testing.T) {
	panel := paarMitNamen(t, "panel.example.test")
	h := NewHolder(panel)
	if err := h.SetzeSite("shop", paarMitNamen(t, "shop.example.test")); err != nil {
		t.Fatal(err)
	}
	h.EntferneSite("shop")

	if len(h.Kennungen()) != 0 {
		t.Errorf("nach dem Entfernen bleibt %v", h.Kennungen())
	}
	// Danach greift der Rückfall — der Name ist nicht mehr belegt.
	if !sameLeaf(hole(t, h, "shop.example.test"), &panel) {
		t.Error("nach dem Entfernen kam nicht der Rückfall")
	}
}

// Zwei Sites für denselben Namen: Eine Regel muss es geben, und sie muss
// vorhersagbar sein. Ohne sie hinge das Ergebnis an der Reihenfolge einer Map
// — ein Server, der bei jedem Neustart ein anderes Zertifikat ausliefert, ist
// nicht zu beurteilen.
func TestHolderBeiGleichemNamenGewinntDieErsteKennung(t *testing.T) {
	erste := paarMitNamen(t, "doppelt.test")
	zweite := paarMitNamen(t, "doppelt.test")

	// In beiden Reihenfolgen eingetragen — das Ergebnis muss dasselbe sein.
	h1 := NewHolder(paarMitNamen(t, "panel.example.test"))
	_ = h1.SetzeSite("aaa", erste)
	_ = h1.SetzeSite("bbb", zweite)

	h2 := NewHolder(paarMitNamen(t, "panel.example.test"))
	_ = h2.SetzeSite("bbb", zweite)
	_ = h2.SetzeSite("aaa", erste)

	if !sameLeaf(hole(t, h1, "doppelt.test"), &erste) {
		t.Error("erwartet das Zertifikat der alphabetisch ersten Kennung")
	}
	if !sameLeaf(hole(t, h2, "doppelt.test"), &erste) {
		t.Error("die Reihenfolge des Eintragens darf das Ergebnis nicht ändern")
	}
}

// Groß- und Kleinschreibung und der abschließende Punkt sind in DNS
// bedeutungslos. Ein Client, der „SHOP.Example.Test." schickt, ist kein
// Sonderfall, sondern erlaubt.
func TestHolderNamenWerdenVereinheitlicht(t *testing.T) {
	shop := paarMitNamen(t, "shop.example.test")
	h := NewHolder(paarMitNamen(t, "panel.example.test"))
	if err := h.SetzeSite("shop", shop); err != nil {
		t.Fatal(err)
	}

	for _, name := range []string{"SHOP.example.test", "shop.example.test.", "Shop.Example.Test."} {
		if !sameLeaf(hole(t, h, name), &shop) {
			t.Errorf("%q wurde nicht wiedererkannt", name)
		}
	}
}

func TestHolderLehntUnbrauchbaresAb(t *testing.T) {
	h := NewHolder(paarMitNamen(t, "panel.example.test"))

	if err := h.SetzeSite("", paarMitNamen(t, "shop.example.test")); err == nil {
		t.Error("eine leere Kennung muss abgelehnt werden")
	}
	// Ein Zertifikat ohne lesbares Blatt ließe sich nie auswählen. Es
	// stillschweigend abzulegen hieße, einen Eintrag zu führen, der nie zum Zug
	// kommt.
	if err := h.SetzeSite("kaputt", tls.Certificate{}); err == nil {
		t.Error("ein Zertifikat ohne Inhalt muss abgelehnt werden")
	}
	if len(h.Kennungen()) != 0 {
		t.Errorf("abgelehnte Zertifikate dürfen nicht im Halter landen: %v", h.Kennungen())
	}
}

// Das Blatt wird eingelesen, auch wenn der Aufrufer es nicht getan hat.
// tls.X509KeyPair lässt Leaf leer, und die meisten Aufrufer im Projekt gehen
// diesen Weg — ohne geparstes Blatt bliebe von der Auswahl stillschweigend nur
// der Rückfall übrig.
func TestHolderLiestDasBlattSelbst(t *testing.T) {
	roh := paarMitNamen(t, "shop.example.test")
	// Der Fall wird hergestellt statt übersprungen: Neuere Go-Fassungen füllen
	// Leaf in LoadX509KeyPair selbst, ältere und X509KeyPair nicht. Ein Test,
	// der sich davon abhängig macht, prüft die Go-Fassung und nicht den Halter.
	roh.Leaf = nil

	h := NewHolder(paarMitNamen(t, "panel.example.test"))
	if err := h.SetzeSite("shop", roh); err != nil {
		t.Fatalf("SetzeSite: %v", err)
	}
	if !sameLeaf(hole(t, h, "shop.example.test"), &roh) {
		t.Error("ohne geparstes Blatt wurde die Site nicht gefunden")
	}
}
