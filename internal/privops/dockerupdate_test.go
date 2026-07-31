package privops

import (
	"context"
	"strings"
	"testing"
)

// Die Update-Prüfung.
//
// Der Kern dieser Tests ist NICHT, dass ein Update gefunden wird — das ist der
// leichte Teil. Der Kern ist, dass keines gemeldet wird, wo keines belegt ist:
// Eine Prüfung, die bei jedem Mehrarchitektur-Image „veraltet" meldet, meldet
// es bei fast jedem Image, jeden Tag, und wird nach einer Woche nicht mehr
// gelesen.

// Aufgezeichnete Ausgabe von "docker manifest inspect --verbose" für ein Image
// mit EINER Architektur. Descriptor.digest ist hier dieselbe Kennung, die lokal
// in RepoDigests steht — der Vergleich trägt.
const manifestEinzeln = `{
  "Ref": "docker.io/library/eigen:1.0@sha256:1111111111111111111111111111111111111111111111111111111111111111",
  "Descriptor": {
    "mediaType": "application/vnd.docker.distribution.manifest.v2+json",
    "digest": "sha256:1111111111111111111111111111111111111111111111111111111111111111",
    "size": 1234
  },
  "SchemaV2Manifest": {"schemaVersion": 2}
}`

// Und für eine MANIFESTLISTE. Die Kennungen darin sind die der einzelnen
// Plattformen; die der Liste — und nur die liegt lokal — steht nirgends.
const manifestListe = `[
  {
    "Ref": "docker.io/library/nginx:alpine@sha256:aaaa000000000000000000000000000000000000000000000000000000000000",
    "Descriptor": {
      "mediaType": "application/vnd.docker.distribution.manifest.v2+json",
      "digest": "sha256:aaaa000000000000000000000000000000000000000000000000000000000000",
      "platform": {"architecture": "amd64", "os": "linux"}
    }
  },
  {
    "Ref": "docker.io/library/nginx:alpine@sha256:bbbb000000000000000000000000000000000000000000000000000000000000",
    "Descriptor": {
      "mediaType": "application/vnd.docker.distribution.manifest.v2+json",
      "digest": "sha256:bbbb000000000000000000000000000000000000000000000000000000000000",
      "platform": {"architecture": "arm64", "os": "linux"}
    }
  }
]`

func TestParseManifestInspect(t *testing.T) {
	digest, mehrarchig, grund := parseManifestInspect(manifestEinzeln)
	if mehrarchig || grund != "" {
		t.Fatalf("ein einzelnes Manifest ist verwertbar: mehrarchig=%v grund=%q", mehrarchig, grund)
	}
	if !strings.HasPrefix(digest, "sha256:1111") {
		t.Errorf("die Kennung fehlt: %q", digest)
	}

	// Der wichtigste Fall der Datei: Eine Manifestliste gibt die Kennung, die
	// lokal liegt, NICHT her — und das muss als solches durchkommen.
	digest, mehrarchig, grund = parseManifestInspect(manifestListe)
	if !mehrarchig {
		t.Error("eine Manifestliste wurde nicht als solche erkannt — der Vergleich " +
			"mit einer Plattform-Kennung meldete immer ein Update")
	}
	if digest != "" {
		t.Errorf("aus einer Manifestliste darf keine Kennung kommen, kam aber %q", digest)
	}
	if grund != "" {
		t.Errorf("mehrarchig ist kein Fehler, sondern eine Eigenschaft: %q", grund)
	}

	// Unlesbares ist ein Grund und keine Kennung.
	for _, murks := range []string{"", "   ", "kein json", "{"} {
		if d, _, g := parseManifestInspect(murks); d != "" || g == "" {
			t.Errorf("%q ergab Kennung %q und Grund %q", murks, d, g)
		}
	}
}

// Ohne buildx und mit einem Mehrarchitektur-Image meldet das Panel „nicht
// geprüft" — nicht „veraltet" und nicht „aktuell".
func TestUpdatePruefenMeldetMehrarchitekturAlsUngeprueft(t *testing.T) {
	f := newFakeRunner()
	f.responses["docker image inspect"] = Result{
		Stdout: `["nginx@sha256:cccc000000000000000000000000000000000000000000000000000000000000"]`,
	}
	f.responses["docker buildx"] = Result{ExitCode: 125, Stderr: "docker: 'buildx' is not a docker command."}
	f.responses["docker manifest inspect"] = Result{Stdout: manifestListe}
	s := NewSystemWithRunner(f)

	stand, err := s.DockerUpdatePruefen(context.Background(), "nginx:alpine")
	if err != nil {
		t.Fatalf("DockerUpdatePruefen: %v", err)
	}
	if stand.Geprueft {
		t.Error("ohne belastbaren Vergleich darf nichts als geprüft gelten")
	}
	if stand.Neu {
		t.Error("ohne Prüfung darf kein Update gemeldet werden — das ist der Fehler, " +
			"der die ganze Fläche unlesbar macht")
	}
	if !strings.Contains(stand.Grund, "Mehrarchitektur") {
		t.Errorf("der Grund nennt die Ursache nicht: %q", stand.Grund)
	}
}

// Mit buildx trägt der Vergleich auch bei Mehrarchitektur: buildx nennt die
// Kennung der Manifestliste unmittelbar.
func TestUpdatePruefenNimmtBuildxWennDa(t *testing.T) {
	lokal := "sha256:cccc000000000000000000000000000000000000000000000000000000000000"
	fern := "sha256:dddd000000000000000000000000000000000000000000000000000000000000"

	f := newFakeRunner()
	f.responses["docker image inspect"] = Result{Stdout: `["nginx@` + lokal + `"]`}
	f.responses["docker buildx"] = Result{Stdout: fern + "\n"}
	s := NewSystemWithRunner(f)

	stand, err := s.DockerUpdatePruefen(context.Background(), "nginx:alpine")
	if err != nil {
		t.Fatalf("DockerUpdatePruefen: %v", err)
	}
	if !stand.Geprueft || !stand.Neu {
		t.Errorf("verschiedene Kennungen heißen: neu. %+v", stand)
	}
	if stand.Weg != "buildx" {
		t.Errorf("Weg = %q, erwartet buildx", stand.Weg)
	}

	// Gleiche Kennung heißt: aktuell.
	f.responses["docker buildx"] = Result{Stdout: lokal + "\n"}
	stand, _ = s.DockerUpdatePruefen(context.Background(), "nginx:alpine")
	if !stand.Geprueft || stand.Neu {
		t.Errorf("gleiche Kennungen heißen: aktuell. %+v", stand)
	}
}

// Ein Image ohne Registry-Kennung — selbst gebaut oder aus einer Datei
// geladen — lässt sich nicht vergleichen. Das ist eine Eigenschaft und kein
// Fehler, und es darf nicht als „aktuell" durchgehen.
func TestUpdatePruefenOhneRegistryherkunft(t *testing.T) {
	f := newFakeRunner()
	f.responses["docker image inspect"] = Result{Stdout: `[]`}
	s := NewSystemWithRunner(f)

	stand, err := s.DockerUpdatePruefen(context.Background(), "eigenbau:1.0")
	if err != nil {
		t.Fatalf("DockerUpdatePruefen: %v", err)
	}
	if stand.Geprueft || stand.Neu {
		t.Errorf("ohne Registry-Kennung gibt es nichts zu vergleichen: %+v", stand)
	}
	if stand.Grund == "" {
		t.Error("der Grund fehlt")
	}
	// Und es wurde gar nicht erst gefragt: Ein Registry-Aufruf für ein Image,
	// das nirgends liegt, ist eine Abfrage gegen die Ratengrenze für nichts.
	for _, ruf := range aufrufeVon(f) {
		if strings.Contains(ruf, "manifest") || strings.Contains(ruf, "buildx") {
			t.Errorf("es wurde trotzdem gefragt: %q", ruf)
		}
	}
}

// Die Kennung eines gleichnamigen Images aus einer ANDEREN Registry ist der
// falsche Vergleichswert. Passt nichts, gilt „keine Kennung".
func TestUpdatePruefenNimmtNurDasPassendeRepository(t *testing.T) {
	f := newFakeRunner()
	f.responses["docker image inspect"] = Result{
		Stdout: `["ghcr.io/fremd/nginx@sha256:eeee000000000000000000000000000000000000000000000000000000000000"]`,
	}
	s := NewSystemWithRunner(f)

	stand, _ := s.DockerUpdatePruefen(context.Background(), "nginx:alpine")
	if stand.LokalDigest != "" {
		t.Errorf("die Kennung eines fremden Repositories wurde genommen: %q", stand.LokalDigest)
	}
}

// Die Ratengrenze bekommt einen eigenen Satz: Sie bestimmt das Verhalten des
// Panels — angezeigt und nicht wiederholt.
func TestRegistryGrund(t *testing.T) {
	faelle := []struct{ meldung, enthaelt string }{
		{"toomanyrequests: You have reached your pull rate limit", "Ratengrenze"},
		{"unauthorized: authentication required", "Anmeldung"},
		{"manifest unknown", "gibt es in der Registry nicht mehr"},
		{"", "nicht geantwortet"},
	}
	for _, f := range faelle {
		if got := registryGrund(f.meldung); !strings.Contains(got, f.enthaelt) {
			t.Errorf("registryGrund(%q) = %q, erwartet etwas mit %q", f.meldung, got, f.enthaelt)
		}
	}
}

func TestRepoAus(t *testing.T) {
	faelle := map[string]string{
		"nginx:alpine":                  "nginx",
		"nginx":                         "nginx",
		"ghcr.io/o/n:1.2":               "ghcr.io/o/n",
		"reg.example:5000/team/app:2.0": "reg.example:5000/team/app",
		// Ein Ref mit Kennung: Der Namensteil zählt.
		"nginx@sha256:abc": "nginx",
	}
	for ref, erwartet := range faelle {
		if got := repoAus(ref); got != erwartet {
			t.Errorf("repoAus(%q) = %q, erwartet %q", ref, got, erwartet)
		}
	}
}

// „update" ist zwei Kommandos, und die Reihenfolge ist die Zusage: Scheitert
// das Ziehen, wird NICHT hochgefahren. Ein „up" nach einem gescheiterten
// „pull" startete die alte Fassung neu und sähe aus wie ein geglücktes Update.
func TestStackUpdateFaehrtNachGescheitertemZiehenNichtHoch(t *testing.T) {
	wurzel := stacksIn(t)
	eigenerStack(t, wurzel, "web", "services:\n  web:\n    image: nginx\n", true)

	f := newFakeRunner()
	f.responses["docker compose ls"] = Result{Stdout: "[]"}
	// Ohne Docker scheitert das Rendern; dann prüft der Prüfer die Rohdatei, und
	// die ist in Ordnung. Genau dieser Weg ist gemeint — die Prüfung läuft auch
	// vor „update", und sie soll hier durchgehen.
	f.responses["docker compose --file"] = Result{ExitCode: 127}
	f.responses["docker compose --project-name web --file "+wurzel+"/web/"+stackDatei+" pull"] = Result{
		ExitCode: 1, Stderr: "toomanyrequests: rate limit",
	}
	s := NewSystemWithRunner(f)

	if _, err := s.StackAusfuehren(context.Background(), "web", StackUpdate, false, 0, nil); err == nil {
		t.Fatal("ein gescheitertes Ziehen muss den Vorgang beenden")
	}
	for _, ruf := range aufrufeVon(f) {
		if strings.Contains(ruf, " up ") || strings.HasSuffix(ruf, " up") {
			t.Errorf("nach dem gescheiterten Ziehen lief trotzdem: %q", ruf)
		}
	}
}

// Und im Regelfall laufen beide, in dieser Reihenfolge.
func TestStackUpdateZiehtUndFaehrtHoch(t *testing.T) {
	wurzel := stacksIn(t)
	eigenerStack(t, wurzel, "web", "services:\n  web:\n    image: nginx\n", true)

	f := newFakeRunner()
	f.responses["docker compose ls"] = Result{Stdout: "[]"}
	f.responses["docker compose --file"] = Result{ExitCode: 127}
	s := NewSystemWithRunner(f)

	if _, err := s.StackAusfuehren(context.Background(), "web", StackUpdate, false, 0, nil); err != nil {
		t.Fatalf("StackAusfuehren: %v", err)
	}
	var pullBei, upBei = -1, -1
	for i, ruf := range aufrufeVon(f) {
		if strings.HasSuffix(ruf, " pull") {
			pullBei = i
		}
		if strings.Contains(ruf, " up --detach") {
			upBei = i
		}
	}
	if pullBei < 0 || upBei < 0 {
		t.Fatalf("pull oder up fehlt: %v", aufrufeVon(f))
	}
	if pullBei > upBei {
		t.Error("erst ziehen, dann hochfahren — die Reihenfolge ist die Zusage")
	}
}
