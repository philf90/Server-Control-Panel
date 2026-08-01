package privops

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// Tests des Schreibpfades. Echte Dateien in einem Wegwerfverzeichnis und keine
// Attrappe — die Arbeit dieser Familie IST das Schreiben: atomarer Tausch,
// Marker, Rücknahme nach abgelehnter Prüfung, Umbenennen beim Abschalten. Ein
// Test gegen eine Attrappe berührte davon nichts.

// siteWerkstatt legt conf.d auf ein Wegwerfverzeichnis um und liefert einen
// System mit vorbereitetem nginx.
func siteWerkstatt(t *testing.T) (*System, *fakeRunner, string) {
	t.Helper()
	acmeVerzeichnisse(t)
	f := newFakeRunner()
	nginxOK(f)
	return NewSystemWithRunner(f), f, filepath.Dir(acmeDropinPfad)
}

// lageMitPanel ist die Lage, in der die meisten Fälle spielen: Das Panel hört
// auf 8443, sonst nichts Besonderes.
func lageMitPanel() SiteLage { return SiteLage{PanelPort: 8443} }

// leerenDump lässt `nginx -T` eine gültige, aber leere Konfiguration melden.
// Ohne ihn hielte SiteApply die Konfiguration für unlesbar und schriebe nichts.
func leererDump(f *fakeRunner) {
	f.responses["nginx -T"] = Result{Stdout: "# configuration file /etc/nginx/nginx.conf:\nevents {}\n"}
}

func TestSiteApplySchreibtPruefetUndLaedt(t *testing.T) {
	s, f, dir := siteWerkstatt(t)
	leererDump(f)

	erg, err := s.SiteApply(context.Background(), gueltig(), lageMitPanel(), "")
	if err != nil {
		t.Fatalf("SiteApply: %v", err)
	}

	pfad := filepath.Join(dir, "asylum-shop.conf")
	if erg.Datei != pfad {
		t.Errorf("Datei = %q, erwartet %q", erg.Datei, pfad)
	}
	b, err := os.ReadFile(pfad)
	if err != nil {
		t.Fatalf("die Datei fehlt: %v", err)
	}
	if !strings.HasPrefix(string(b), nginxMarker) {
		t.Error("der Marker fehlt — das Panel erkennt seine eigene Datei nicht wieder")
	}
	if erg.Fassung != Fassungshash(string(b)) {
		t.Error("die zurückgegebene Fassung passt nicht zur geschriebenen Datei")
	}
	// Die Rücknahme muss sagen, dass es die Datei vorher NICHT gab — sonst
	// stellte die Probe eine Fassung wieder her, die es nie gegeben hat.
	if erg.Ruecknahme.Hatte {
		t.Error("die Rücknahme behauptet einen Vorzustand, den es nicht gab")
	}

	// Die Kette, und in dieser Reihenfolge: erst prüfen, dann laden.
	var kette []string
	for _, c := range f.calls {
		kette = append(kette, c.Name+" "+strings.Join(c.Args, " "))
	}
	pruef, laden := -1, -1
	for i, k := range kette {
		if k == "nginx -t" {
			pruef = i
		}
		if strings.HasPrefix(k, "systemctl reload") {
			laden = i
		}
	}
	if pruef < 0 || laden < 0 || pruef > laden {
		t.Errorf("die Kette stimmt nicht (prüfen=%d, laden=%d): %v", pruef, laden, kette)
	}
}

// Der wichtigste Test dieser Datei: Lehnt nginx ab, darf nichts liegen bleiben.
// Eine abgelehnte Datei in conf.d nimmt den nächsten Reload mit — von wem auch
// immer er kommt —, und der Fehler wäre unserer und sähe nach einem fremden aus.
func TestSiteApplyNimmtNachAbgelehnterPruefungZurueck(t *testing.T) {
	s, f, dir := siteWerkstatt(t)
	leererDump(f)
	f.responses["nginx -t"] = Result{
		ExitCode: 1,
		Stderr:   "nginx: [emerg] duplicate listen options\n",
	}

	_, err := s.SiteApply(context.Background(), gueltig(), lageMitPanel(), "")
	if err == nil {
		t.Fatal("eine abgelehnte Konfiguration wurde als Erfolg gemeldet")
	}
	if !strings.Contains(err.Error(), "duplicate listen") {
		t.Errorf("die Meldung von nginx fehlt: %v", err)
	}
	if _, statErr := os.Stat(filepath.Join(dir, "asylum-shop.conf")); !os.IsNotExist(statErr) {
		t.Error("die abgelehnte Datei liegt noch da — der nächste Reload stürbe daran")
	}
	// Und nicht neu geladen: Ein Reload nach einer abgelehnten Prüfung wäre der
	// Versuch, genau das zu tun, was gerade als kaputt gemeldet wurde.
	for _, c := range f.calls {
		if c.Name == "systemctl" && len(c.Args) > 0 && c.Args[0] == "reload" {
			t.Error("nach abgelehnter Prüfung wurde trotzdem neu geladen")
		}
	}
}

// Bei einer Änderung muss der vorherige INHALT zurückkommen, nicht nur die
// Datei verschwinden.
func TestSiteApplyStelltDenVorherigenInhaltWiederHer(t *testing.T) {
	s, f, dir := siteWerkstatt(t)
	leererDump(f)
	pfad := filepath.Join(dir, "asylum-shop.conf")

	erg, err := s.SiteApply(context.Background(), gueltig(), lageMitPanel(), "")
	if err != nil {
		t.Fatalf("erster Lauf: %v", err)
	}
	alt, _ := os.ReadFile(pfad)

	f.responses["nginx -t"] = Result{ExitCode: 1, Stderr: "nginx: [emerg] kaputt\n"}
	e := gueltig()
	e.Ziel = "http://127.0.0.1:4000"
	if _, err := s.SiteApply(context.Background(), e, lageMitPanel(), erg.Fassung); err == nil {
		t.Fatal("die abgelehnte Änderung wurde als Erfolg gemeldet")
	}

	neu, err := os.ReadFile(pfad)
	if err != nil {
		t.Fatalf("die Datei ist verschwunden: %v", err)
	}
	if string(neu) != string(alt) {
		t.Errorf("der Vorzustand kam nicht zurück:\n%s", neu)
	}
}

// Zwei offene Fenster, zwei Bearbeitungen — die zweite darf die erste nicht
// stillschweigend überschreiben.
func TestSiteApplyErkenntEineFremdeAenderung(t *testing.T) {
	s, f, dir := siteWerkstatt(t)
	leererDump(f)

	if _, err := s.SiteApply(context.Background(), gueltig(), lageMitPanel(), ""); err != nil {
		t.Fatalf("erster Lauf: %v", err)
	}
	// Jemand anders schreibt dazwischen.
	pfad := filepath.Join(dir, "asylum-shop.conf")
	if err := os.WriteFile(pfad, []byte(nginxMarker+"\n# von auswärts\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	_, err := s.SiteApply(context.Background(), gueltig(), lageMitPanel(), "veralteter-hash")
	if !errors.Is(err, ErrSiteFassung) {
		t.Fatalf("Fehler = %v, erwartet ErrSiteFassung", err)
	}
	b, _ := os.ReadFile(pfad)
	if !strings.Contains(string(b), "von auswärts") {
		t.Error("die fremde Fassung wurde trotzdem überschrieben")
	}
}

// Ein leerer Hash heißt „die Datei soll neu sein". Gibt es sie schon, ist das
// eine falsche Annahme und kein Grund zum Überschreiben.
func TestSiteApplyLeererHashUeberschreibtNicht(t *testing.T) {
	s, f, _ := siteWerkstatt(t)
	leererDump(f)

	if _, err := s.SiteApply(context.Background(), gueltig(), lageMitPanel(), ""); err != nil {
		t.Fatalf("erster Lauf: %v", err)
	}
	if _, err := s.SiteApply(context.Background(), gueltig(), lageMitPanel(), ""); !errors.Is(err, ErrSiteFassung) {
		t.Fatalf("Fehler = %v, erwartet ErrSiteFassung", err)
	}
}

// Eine Datei ohne Marker gehört dem Panel nicht — auch nicht an diesem Platz
// und unter diesem Namen.
func TestSiteApplyRuehrtEineFremdeDateiNichtAn(t *testing.T) {
	s, f, dir := siteWerkstatt(t)
	leererDump(f)
	pfad := filepath.Join(dir, "asylum-shop.conf")
	fremd := "server { server_name shop.example.com; }\n"
	if err := os.WriteFile(pfad, []byte(fremd), 0o644); err != nil {
		t.Fatal(err)
	}

	if _, err := s.SiteApply(context.Background(), gueltig(), lageMitPanel(), ""); err == nil {
		t.Fatal("eine Datei ohne Marker wurde überschrieben")
	}
	b, _ := os.ReadFile(pfad)
	if string(b) != fremd {
		t.Error("die fremde Datei wurde verändert")
	}
}

// Der abgelehnte Entwurf darf gar nicht erst bis zur Platte kommen.
func TestSiteApplySchreibtNichtsNachAblehnung(t *testing.T) {
	s, f, dir := siteWerkstatt(t)
	leererDump(f)

	e := gueltig()
	e.Zielart, e.Ziel = "statisch", "/etc"

	erg, err := s.SiteApply(context.Background(), e, lageMitPanel(), "")
	if !errors.Is(err, ErrSiteAbgelehnt) {
		t.Fatalf("Fehler = %v, erwartet ErrSiteAbgelehnt", err)
	}
	if len(erg.Pruefung.Ablehnungen) == 0 {
		t.Error("die Ablehnung wird nicht begründet")
	}
	if _, statErr := os.Stat(filepath.Join(dir, "asylum-shop.conf")); !os.IsNotExist(statErr) {
		t.Error("trotz Ablehnung wurde geschrieben")
	}
	// Und kein einziges Kommando: Bis auf das Lesen der Lage darf nichts
	// gelaufen sein.
	for _, c := range f.calls {
		if c.Name == "nginx" && len(c.Args) > 0 && c.Args[0] == "-t" {
			t.Error("nach der Ablehnung wurde trotzdem geprüft")
		}
	}
}

// Ohne lesbare Konfiguration wird nicht geschrieben: „nicht geprüft" ist kein
// „frei", und ein zweiter Block für denselben Namen ist genau der Fehler, der
// sich später nicht mehr erklären lässt.
func TestSiteApplySchreibtNichtBeiUnlesbarerKonfiguration(t *testing.T) {
	s, f, dir := siteWerkstatt(t)
	f.responses["nginx -T"] = Result{ExitCode: 1, Stderr: "nginx: [emerg] unknown directive\n"}

	if _, err := s.SiteApply(context.Background(), gueltig(), lageMitPanel(), ""); err == nil {
		t.Fatal("bei unlesbarer Konfiguration wurde geschrieben")
	}
	if _, statErr := os.Stat(filepath.Join(dir, "asylum-shop.conf")); !os.IsNotExist(statErr) {
		t.Error("trotz unlesbarer Konfiguration wurde geschrieben")
	}
}

// Die eigenen Namen behält eine Site beim Ändern. Ein Prüfer, der das nicht
// auseinanderhält, ließe keine einzige Änderung durch.
func TestSiteApplyBeanstandetDieEigenenNamenNicht(t *testing.T) {
	s, f, _ := siteWerkstatt(t)
	leererDump(f)

	erg, err := s.SiteApply(context.Background(), gueltig(), lageMitPanel(), "")
	if err != nil {
		t.Fatalf("erster Lauf: %v", err)
	}
	// Jetzt steht die Site in der eigenen Liste. Dieselben Namen noch einmal —
	// das ist eine Änderung, kein Zusammenstoß.
	e := gueltig()
	e.Ziel = "http://127.0.0.1:4000"
	if _, err := s.SiteApply(context.Background(), e, lageMitPanel(), erg.Fassung); err != nil {
		t.Fatalf("die eigene Site wurde als Namenskonflikt abgelehnt: %v", err)
	}
}

// ------------------------------------------------------------- Abschalten ---

func TestSiteSchaltenBenenntUmUndZurueck(t *testing.T) {
	s, f, dir := siteWerkstatt(t)
	leererDump(f)
	if _, err := s.SiteApply(context.Background(), gueltig(), lageMitPanel(), ""); err != nil {
		t.Fatalf("anlegen: %v", err)
	}
	an := filepath.Join(dir, "asylum-shop.conf")
	aus := an + siteAusEndung
	vorher, _ := os.ReadFile(an)

	if _, err := s.SiteSchalten(context.Background(), "shop", false); err != nil {
		t.Fatalf("abschalten: %v", err)
	}
	if _, err := os.Stat(an); !os.IsNotExist(err) {
		t.Error("nach dem Abschalten liegt die Datei weiter als .conf da — nginx läse sie")
	}
	nachher, err := os.ReadFile(aus)
	if err != nil {
		t.Fatalf("die abgeschaltete Datei fehlt: %v", err)
	}
	// Der Inhalt bleibt unverändert: Eine Site, die beim Abschalten
	// umgeschrieben würde, käme als etwas anderes wieder.
	if string(nachher) != string(vorher) {
		t.Error("die abgeschaltete Datei wurde verändert")
	}

	if _, err := s.SiteSchalten(context.Background(), "shop", true); err != nil {
		t.Fatalf("einschalten: %v", err)
	}
	if _, err := os.Stat(an); err != nil {
		t.Errorf("nach dem Einschalten fehlt die Datei: %v", err)
	}
}

// Eine abgeschaltete Site muss in der Liste bleiben. Was in keiner Liste steht,
// lässt sich auch nicht wieder einschalten.
func TestSiteListZeigtAbgeschalteteSites(t *testing.T) {
	s, f, _ := siteWerkstatt(t)
	leererDump(f)
	if _, err := s.SiteApply(context.Background(), gueltig(), lageMitPanel(), ""); err != nil {
		t.Fatalf("anlegen: %v", err)
	}
	if _, err := s.SiteSchalten(context.Background(), "shop", false); err != nil {
		t.Fatalf("abschalten: %v", err)
	}

	bestand, err := s.SiteList(context.Background())
	if err != nil {
		t.Fatalf("SiteList: %v", err)
	}
	var gefunden *Site
	for i := range bestand.Sites {
		if bestand.Sites[i].Name == "shop" {
			gefunden = &bestand.Sites[i]
		}
	}
	if gefunden == nil {
		t.Fatalf("die abgeschaltete Site fehlt in der Liste: %+v", bestand.Sites)
	}
	if !gefunden.Aus {
		t.Error("die Site steht in der Liste, gilt aber nicht als abgeschaltet")
	}
	if gefunden.Ausgeliefert {
		t.Error("eine abgeschaltete Site gilt als ausgeliefert")
	}
	if !gefunden.Verwaltet {
		t.Error("die eigene Site gilt nicht als verwaltet")
	}
	// Und die Angaben stehen weiter da: Wer sie wieder einschalten will, muss
	// sehen, was er einschaltet.
	if len(gefunden.Domains) == 0 || gefunden.Ziel == "" {
		t.Errorf("die abgeschaltete Site hat keine Angaben mehr: %+v", *gefunden)
	}
}

// Eine Site mit TLS besteht aus ZWEI Serverblöcken in einer Datei. Ohne das
// Falten stünde sie zweimal in der Liste — mit zwei Löschknöpfen für dieselbe
// Sache.
func TestSiteListFaltetDieBloeckeEinerDatei(t *testing.T) {
	s, f, _ := siteWerkstatt(t)
	leererDump(f)

	e := gueltig()
	e.TLS = true
	e.Zertifikat, e.Schluessel = "/etc/ssl/c.pem", "/etc/ssl/k.pem"
	if _, err := s.SiteApply(context.Background(), e, lageMitPanel(), ""); err != nil {
		t.Fatalf("anlegen: %v", err)
	}

	bestand, err := s.SiteList(context.Background())
	if err != nil {
		t.Fatalf("SiteList: %v", err)
	}
	var treffer []Site
	for _, si := range bestand.Sites {
		if si.Name == "shop" {
			treffer = append(treffer, si)
		}
	}
	if len(treffer) != 1 {
		t.Fatalf("die Site steht %d-mal in der Liste, erwartet einmal: %+v", len(treffer), treffer)
	}
	if !treffer[0].TLS {
		t.Error("die gefaltete Site trägt kein TLS")
	}
	if !enthaeltZahl(treffer[0].Ports, 80) || !enthaeltZahl(treffer[0].Ports, 443) {
		t.Errorf("die Ports beider Blöcke fehlen: %v", treffer[0].Ports)
	}
	if treffer[0].Zielart != "proxy" {
		t.Errorf("Zielart = %q, erwartet proxy", treffer[0].Zielart)
	}
}

// ---------------------------------------------------------------- Löschen ---

func TestSiteRemoveLoeschtBeideFassungen(t *testing.T) {
	s, f, dir := siteWerkstatt(t)
	leererDump(f)
	if _, err := s.SiteApply(context.Background(), gueltig(), lageMitPanel(), ""); err != nil {
		t.Fatalf("anlegen: %v", err)
	}
	// Eine abgeschaltete Fassung daneben — sie darf nicht zurückbleiben.
	if err := os.WriteFile(filepath.Join(dir, "asylum-shop.conf"+siteAusEndung),
		[]byte(nginxMarker+"\n"), 0o644); err != nil {
		t.Fatal(err)
	}

	if _, err := s.SiteRemove(context.Background(), "shop"); err != nil {
		t.Fatalf("SiteRemove: %v", err)
	}
	for _, name := range []string{"asylum-shop.conf", "asylum-shop.conf" + siteAusEndung} {
		if _, err := os.Stat(filepath.Join(dir, name)); !os.IsNotExist(err) {
			t.Errorf("%s liegt noch da", name)
		}
	}
}

func TestSiteRemoveRuehrtFremdesNichtAn(t *testing.T) {
	s, f, dir := siteWerkstatt(t)
	leererDump(f)
	pfad := filepath.Join(dir, "asylum-fremd.conf")
	if err := os.WriteFile(pfad, []byte("server { }\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := s.SiteRemove(context.Background(), "fremd"); err == nil {
		t.Fatal("eine Datei ohne Marker wurde gelöscht")
	}
	if _, err := os.Stat(pfad); err != nil {
		t.Errorf("die fremde Datei ist weg: %v", err)
	}
}

// --------------------------------------------------------------- Rückweg ---

func TestSiteRestoreStelltWiederHer(t *testing.T) {
	s, f, dir := siteWerkstatt(t)
	leererDump(f)
	pfad := filepath.Join(dir, "asylum-shop.conf")

	erg, err := s.SiteApply(context.Background(), gueltig(), lageMitPanel(), "")
	if err != nil {
		t.Fatalf("anlegen: %v", err)
	}
	// Die Rücknahme des Anlegens löscht die Datei wieder.
	if err := s.SiteRestore(context.Background(), erg.Ruecknahme); err != nil {
		t.Fatalf("SiteRestore: %v", err)
	}
	if _, err := os.Stat(pfad); !os.IsNotExist(err) {
		t.Error("die Rücknahme hat die neue Datei stehen lassen")
	}
}

// Der Pfad in der Rücknahme kommt durch die Schicht darüber. Er wird geprüft und
// nicht geglaubt — sonst wäre der Rückweg ein Schreibzugriff auf einen
// beliebigen Pfad.
func TestSiteRestorePrueftDenPfad(t *testing.T) {
	s, _, _ := siteWerkstatt(t)
	ziel := filepath.Join(t.TempDir(), "fremd.conf")

	err := s.SiteRestore(context.Background(), SiteRuecknahme{
		Datei: ziel, Inhalt: "was auch immer", Hatte: true,
	})
	if err == nil {
		t.Fatal("ein beliebiger Pfad wurde als Rücknahme angenommen")
	}
	if _, statErr := os.Stat(ziel); !os.IsNotExist(statErr) {
		t.Error("die Rücknahme hat außerhalb von conf.d geschrieben")
	}
}
