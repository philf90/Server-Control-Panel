package httpd

import (
	"context"
	"encoding/json"
	"net/http/httptest"
	"os"
	"os/exec"
	"strings"
	"testing"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/store"
)

// TestPasswortpruefungBrowser vergleicht die Anzeige mit der verbindlichen
// Prüfung.
//
// Die Regeln stehen zweimal: in auth.CheckPasswordPolicy, das ablehnt, und in
// passwort.js, das beim Tippen Haken setzt. Das ist unvermeidlich — eine Anzeige
// ohne Serverrunde braucht die Regel im Browser —, aber es ist genau die Art
// Doppelung, die auseinanderläuft. Dann zeigt die Seite grün und der Server sagt
// nein, und niemand versteht, warum.
//
// Deshalb dieselbe Tabelle durch beide Wege: Go urteilt, der Browser zeigt, und
// der Test vergleicht Regel für Regel.
//
// Bewusst hinter einer Umgebungsvariablen: braucht Node und Chromium.
//
//	ASYLUM_PASSWORT_E2E=1 \
//	  ASYLUM_NODE=/opt/node22/bin/node \
//	  ASYLUM_NODE_PATH=/opt/node22/lib/node_modules \
//	  ASYLUM_CHROMIUM=/opt/pw-browsers/chromium-1194/chrome-linux/chrome \
//	  go test ./internal/httpd -run TestPasswortpruefungBrowser -v
func TestPasswortpruefungBrowser(t *testing.T) {
	if os.Getenv("ASYLUM_PASSWORT_E2E") == "" {
		t.Skip("ohne ASYLUM_PASSWORT_E2E nichts zu tun (braucht Node und Chromium)")
	}
	chromium := os.Getenv("ASYLUM_CHROMIUM")
	if chromium == "" {
		t.Skip("ASYLUM_CHROMIUM (Pfad zum Browser) nicht gesetzt")
	}
	node := envOr("ASYLUM_NODE", "node")

	const name = "philipp"
	s := newTestServer(t)
	user := addUser(t, s, name, store.RoleOwner)
	cookie, _ := login(t, s, user)

	// Der Treiber sieht die Prüfliste auf der Seite des erzwungenen Wechsels an.
	// Sie steht nur einem Konto offen, dem ein Passwort vergeben wurde — sonst
	// leitet requireAuth ins Panel weiter, und der Treiber fände keine .pwcheck.
	hash, err := auth.HashPassword("Einmalpasswort xyz")
	if err != nil {
		t.Fatal(err)
	}
	if err := s.db.SetTemporaryPassword(context.Background(), user.ID, hash); err != nil {
		t.Fatal(err)
	}

	proben := []string{
		"kurz",                    // zu kurz
		"korrekt pferd batterie",  // in Ordnung
		strings.Repeat("a", 12),   // bloße Wiederholung
		"abcdefghijklmn",          // Folge
		"meinPhilippPasswort",     // enthält den Anmeldenamen
		"PHILIPP ist mein Name!!", // enthält ihn in anderer Schreibweise
		"T7#vq!Zm2Lw9pR4x",        // stark
		"überlange Passphrase mit Umlauten und Ümläuten dazu", // mehrere Bytes je Zeichen
	}
	roh, err := json.Marshal(proben)
	if err != nil {
		t.Fatal(err)
	}

	ts := httptest.NewServer(s.Handler())
	defer ts.Close()

	cmd := exec.Command(node, "testdata/passwort_e2e.js")
	cmd.Env = append(os.Environ(),
		"ASYLUM_E2E_URL="+ts.URL,
		"ASYLUM_E2E_COOKIE="+cookie.Name+"="+cookie.Value,
		"ASYLUM_E2E_PROBEN="+string(roh),
		"ASYLUM_CHROMIUM="+chromium,
	)
	if p := os.Getenv("ASYLUM_NODE_PATH"); p != "" {
		cmd.Env = append(cmd.Env, "NODE_PATH="+p)
	}

	ausgabe, err := cmd.CombinedOutput()
	t.Logf("Treiber:\n%s", ausgabe)
	if err != nil {
		t.Fatalf("Browsertreiber: %v", err)
	}

	type stand struct {
		Wort   string            `json:"wort"`
		Balken int               `json:"balken"`
		Klasse string            `json:"klasse"`
		Regeln map[string]string `json:"regeln"`
		Min    int               `json:"min"`
		Max    int               `json:"max"`
		Name   string            `json:"name"`
	}
	var e struct {
		Verstoesse []string `json:"verstoesse"`
		Leer       stand    `json:"leer"`
		Ergebnisse []stand  `json:"ergebnisse"`
	}
	if err := json.Unmarshal([]byte(letzteZeile(string(ausgabe))), &e); err != nil {
		t.Fatalf("Ausgabe des Treibers unlesbar: %v", err)
	}

	if len(e.Verstoesse) > 0 {
		t.Errorf("die Content-Security-Policy hat etwas verworfen:\n  %s", strings.Join(e.Verstoesse, "\n  "))
	}
	if len(e.Ergebnisse) != len(proben) {
		t.Fatalf("%d Ergebnisse, erwartet %d", len(e.Ergebnisse), len(proben))
	}

	// Die Zahlen der Richtlinie kommen aus dem Markup und müssen die geltenden
	// sein — sonst prüft der Browser gegen andere Grenzen als der Server.
	if e.Leer.Min != auth.MinPasswordLength || e.Leer.Max != auth.MaxPasswordBytes {
		t.Errorf("Markup nennt min=%d max=%d, geltend sind %d und %d",
			e.Leer.Min, e.Leer.Max, auth.MinPasswordLength, auth.MaxPasswordBytes)
	}
	if e.Leer.Name != name {
		t.Errorf("Anmeldename im Markup = %q, erwartet %q", e.Leer.Name, name)
	}
	// Vor der ersten Eingabe ist keine Regel beurteilt.
	for key, zustand := range e.Leer.Regeln {
		if zustand != "neutral" {
			t.Errorf("vor der Eingabe steht die Regel %s auf %q — das sieht aus wie eine Ablehnung", key, zustand)
		}
	}

	for i, probe := range proben {
		got := e.Ergebnisse[i]
		goErr := auth.CheckPasswordPolicy(name, probe)

		// Regel für Regel: Was der Server bemängelt, muss die Seite als verletzt
		// zeigen — und umgekehrt.
		verletzt := ""
		for key, zustand := range got.Regeln {
			if zustand == "verletzt" {
				verletzt = key
			}
		}
		if goErr != nil && verletzt == "" {
			t.Errorf("%q: Go lehnt ab (%v), die Seite zeigt keine verletzte Regel", probe, goErr)
		}
		if goErr == nil && verletzt != "" {
			t.Errorf("%q: Go nimmt es an, die Seite zeigt %s als verletzt", probe, verletzt)
		}

		// Und das Wort daneben darf nicht loben, was abgelehnt wird.
		lobt := got.Wort == "gut" || got.Wort == "stark" || got.Wort == "mittel"
		if goErr != nil && lobt {
			t.Errorf("%q: Go lehnt ab, die Seite sagt %q", probe, got.Wort)
		}
		if goErr == nil && !lobt {
			t.Errorf("%q: Go nimmt es an, die Seite sagt %q", probe, got.Wort)
		}
		if got.Balken <= 0 {
			t.Errorf("%q: der Balken steht auf %d", probe, got.Balken)
		}
	}
}
