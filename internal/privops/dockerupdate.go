package privops

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"
)

// Update-Prüfung für Images — Schritt 7 aus docs/17-docker.md.
//
// Die Frage lautet: Gibt es zu dem Tag, den ein Container benutzt, in der
// Registry etwas Neueres als das, was hier liegt? Verglichen werden dazu
// Kennungen (Digests) und keine Fassungsnummern — „nginx:alpine" ist ein
// beweglicher Zeiger, und die Nummer dahinter ändert sich, ohne dass der Tag
// sich ändert.
//
// **Diese Datei ist vorsichtiger gebaut als der Rest des Moduls, und dafür gibt
// es einen Grund.** Ein Parser, der einen Container falsch färbt, ist ein
// Schönheitsfehler. Eine Update-Prüfung, die falsch „veraltet" meldet, ist
// schlimmer als keine: Sie meldet es bei JEDEM Image, sie meldet es jeden Tag,
// und nach einer Woche liest niemand mehr hin — auch nicht, wenn sie einmal
// recht hat.
//
// Die Falle heißt Mehrarchitektur. Ein gezogenes „nginx:alpine" trägt lokal die
// Kennung der MANIFESTLISTE (RepoDigests). „docker manifest inspect --verbose"
// gibt für eine solche Liste ein Feld je Plattform zurück, und jedes davon trägt
// die Kennung des PLATTFORM-Manifests — eine andere Kennung. Wer beide
// vergleicht, findet immer einen Unterschied und meldet immer ein Update.
//
// Deshalb die Regel dieser Datei: **Ohne belastbaren Vergleich wird „nicht
// geprüft" gemeldet, nie „veraltet".** Dieselbe Haltung wie in configcheck.go,
// nur mit mehr Anlass.
//
// Zwei Wege stehen zur Verfügung, und der erste ist der gute:
//
//  1. "docker buildx imagetools inspect" nennt die Kennung der Manifestliste
//     unmittelbar. Damit ist der Vergleich belastbar, auch bei
//     Mehrarchitektur-Images. buildx ist ein Unterkommando desselben Binaries
//     — kein neuer Allowlist-Eintrag, keine neue Abhängigkeit —, aber es ist
//     nicht überall installiert (in Debian ein eigenes Paket).
//  2. "docker manifest inspect --verbose" genügt nur, wenn das Image EINE
//     Architektur hat: Dann steht dort dieselbe Kennung, die auch lokal liegt.
//     Bei einer Manifestliste gibt es sie nicht her — und dann sagt das Panel
//     das, statt zu raten.

// Updatestand ist das Ergebnis der Prüfung für ein Image.
type Updatestand struct {
	// Ref ist das Image, wie der Container es nennt: "nginx:alpine".
	Ref string `json:"ref"`
	// LokalDigest ist die Kennung, die hier liegt; FernDigest die in der
	// Registry. Beide stehen da, weil ein Vergleich ohne seine Eingangswerte
	// nicht nachprüfbar ist.
	LokalDigest string `json:"lokal_digest"`
	FernDigest  string `json:"fern_digest"`
	// Geprueft sagt, ob ein belastbarer Vergleich zustande kam. Ohne ihn ist Neu
	// bedeutungslos — und genau diese Trennung ist der Kern dieser Datei.
	Geprueft bool `json:"geprueft"`
	// Neu heißt: In der Registry liegt etwas anderes als hier. Nur gültig, wenn
	// Geprueft stimmt.
	Neu bool `json:"neu"`
	// Grund erklärt, warum nicht geprüft wurde: Ratengrenze, kein Zugang,
	// Mehrarchitektur ohne buildx, ein Image ohne Registry-Herkunft.
	Grund string `json:"grund,omitempty"`
	// Weg nennt, womit geprüft wurde ("buildx" oder "manifest"). Er steht da,
	// damit ein Befund später nachvollziehbar ist — die beiden Wege haben
	// verschiedene Schwächen.
	Weg string `json:"weg,omitempty"`
}

// DockerUpdatePruefen vergleicht ein Image mit der Registry.
//
// Ein Aufruf je Image. Das Bündeln übernimmt die Schicht darüber, weil dort
// auch die Ratengrenze sitzt: Wie oft überhaupt geprüft werden darf, ist keine
// Frage der Kommandozeile.
func (s *System) DockerUpdatePruefen(ctx context.Context, ref string) (Updatestand, error) {
	if err := ValidateImageRef(ref); err != nil {
		return Updatestand{}, err
	}
	stand := Updatestand{Ref: ref}

	// Ein Image ohne Registry-Herkunft lässt sich nicht vergleichen: selbst
	// gebaut, oder aus einem tar geladen. Das ist kein Fehler, sondern eine
	// Eigenschaft — und sie gehört gesagt statt als „aktuell" durchgereicht.
	lokal, err := s.lokalerDigest(ctx, ref)
	if err != nil {
		return Updatestand{}, err
	}
	if lokal == "" {
		stand.Grund = "Dieses Image trägt keine Registry-Kennung — es wurde hier gebaut " +
			"oder aus einer Datei geladen. Ein Vergleich mit einer Registry gibt es dafür nicht."
		return stand, nil
	}
	stand.LokalDigest = lokal

	// Erst buildx: Es nennt die Kennung der Manifestliste und ist damit der
	// einzige Weg, der auch bei Mehrarchitektur-Images stimmt.
	if fern, ok, grund := s.digestUeberBuildx(ctx, ref); ok {
		stand.FernDigest, stand.Geprueft, stand.Weg = fern, true, "buildx"
		stand.Neu = fern != lokal
		return stand, nil
	} else if grund != "" {
		// Ein echter Fehler von buildx (Ratengrenze, kein Zugang) ist die
		// Antwort — dann muss der zweite Weg nicht auch noch daran scheitern.
		stand.Grund = grund
		return stand, nil
	}

	fern, mehrarchig, grund := s.digestUeberManifest(ctx, ref)
	switch {
	case grund != "":
		stand.Grund = grund
	case mehrarchig:
		stand.Grund = "Mehrarchitektur-Image: Lokal liegt die Kennung der Manifestliste, " +
			"und die gibt „docker manifest inspect\" nicht her. Für einen belastbaren " +
			"Vergleich fehlt „docker buildx\" (Paket docker-buildx)."
	case fern == "":
		stand.Grund = "Die Registry hat keine Kennung zurückgegeben."
	default:
		stand.FernDigest, stand.Geprueft, stand.Weg = fern, true, "manifest"
		stand.Neu = fern != lokal
	}
	return stand, nil
}

// lokalerDigest liest die Registry-Kennung des lokalen Images.
//
// RepoDigests ist ein Feld, weil dasselbe Image unter mehreren Namen liegen
// kann. Gesucht wird der Eintrag, dessen Repository zum gefragten Ref passt —
// die Kennung eines gleichnamigen Images aus einer anderen Registry wäre der
// falsche Vergleichswert.
func (s *System) lokalerDigest(ctx context.Context, ref string) (string, error) {
	res, err := s.run(ctx, Command{
		Name: "docker",
		Args: []string{"image", "inspect", "--format", "{{json .RepoDigests}}", "--", ref},
	})
	if err != nil {
		return "", err
	}
	if res.ExitCode != 0 {
		return "", fmt.Errorf("docker image inspect %s: %s", ref, ersteAusgabezeile(res))
	}

	var digests []string
	if err := json.Unmarshal([]byte(strings.TrimSpace(res.Stdout)), &digests); err != nil {
		// Unlesbare Ausgabe heißt hier „keine Kennung" und nicht „Fehler": Der
		// Aufrufer macht daraus „nicht geprüft" mit Grund, und das ist die
		// richtige Antwort. Ein Fehler nach oben brächte die ganze Prüfung zu
		// Fall, weil ein einzelnes Image seltsam antwortet.
		return "", nil //nolint:nilerr // die leere Kennung IST die Antwort
	}
	repo := repoAus(ref)
	for _, d := range digests {
		name, kennung, ok := strings.Cut(d, "@")
		if !ok {
			continue
		}
		if repoAus(name) == repo {
			return kennung, nil
		}
	}
	// Kein passender Eintrag: Das Image liegt unter einem anderen Namen. Als
	// „keine Kennung" behandeln statt eine fremde zu nehmen.
	return "", nil
}

// digestUeberBuildx fragt buildx nach der Kennung der Manifestliste.
//
// Drei Ausgänge: gefunden, „buildx gibt es nicht" (dann ist grund leer und der
// Aufrufer nimmt den zweiten Weg), oder ein echter Fehler mit Grund.
func (s *System) digestUeberBuildx(ctx context.Context, ref string) (digest string, ok bool, grund string) {
	res, err := s.run(ctx, Command{
		Name: "docker",
		Args: []string{"buildx", "imagetools", "inspect", "--format", "{{.Manifest.Digest}}", ref},
	})
	if err != nil {
		return "", false, ""
	}
	if res.ExitCode != 0 {
		meldung := ersteAusgabezeile(res)
		// Fehlt buildx, ist das kein Befund, sondern der Anlass für den zweiten
		// Weg. Docker meldet das als unbekanntes Kommando.
		if fehltBuildx(meldung) {
			return "", false, ""
		}
		return "", false, registryGrund(meldung)
	}
	kennung := ersteAusgabezeileText(res.Stdout)
	if !strings.HasPrefix(kennung, "sha256:") {
		return "", false, ""
	}
	return kennung, true, ""
}

// digestUeberManifest fragt "docker manifest inspect --verbose".
//
// Der Rückgabewert mehrarchig ist der wichtige: Bei einer Manifestliste gibt
// dieses Kommando die Kennung der Liste NICHT her, und ein Vergleich der
// Plattform-Kennung mit der lokalen Listen-Kennung meldete immer ein Update.
func (s *System) digestUeberManifest(ctx context.Context, ref string) (digest string, mehrarchig bool, grund string) {
	res, err := s.run(ctx, Command{
		Name: "docker",
		Args: []string{"manifest", "inspect", "--verbose", ref},
	})
	if err != nil {
		return "", false, err.Error()
	}
	if res.ExitCode != 0 {
		return "", false, registryGrund(ersteAusgabezeile(res))
	}
	return parseManifestInspect(res.Stdout)
}

// parseManifestInspect liest die Ausgabe von "docker manifest inspect --verbose".
//
// Zwei Formen, und der Unterschied entscheidet über die Verwertbarkeit:
//
//	{"Ref":"docker.io/library/eigen:1.0@sha256:abc…",
//	 "Descriptor":{"mediaType":"application/vnd.docker.distribution.manifest.v2+json",
//	               "digest":"sha256:abc…","size":1234}, …}
//
// Ein einzelnes Objekt heißt: eine Architektur. Dann ist Descriptor.digest
// dieselbe Kennung, die lokal in RepoDigests steht, und der Vergleich trägt.
//
//	[{"Ref":"…@sha256:amd64…","Descriptor":{…,"platform":{"architecture":"amd64"}}, …},
//	 {"Ref":"…@sha256:arm64…","Descriptor":{…,"platform":{"architecture":"arm64"}}, …}]
//
// Ein Feld heißt: Manifestliste. Die Kennungen darin sind die der einzelnen
// Plattformen; die der Liste — und nur die liegt lokal — steht nirgends.
func parseManifestInspect(out string) (digest string, mehrarchig bool, grund string) {
	out = strings.TrimSpace(out)
	if out == "" {
		return "", false, "Die Registry hat nichts zurückgegeben."
	}
	if strings.HasPrefix(out, "[") {
		return "", true, ""
	}

	var roh struct {
		Descriptor struct {
			Digest    string `json:"digest"`
			MediaType string `json:"mediaType"`
		} `json:"Descriptor"`
	}
	if json.Unmarshal([]byte(out), &roh) != nil {
		return "", false, "Die Antwort der Registry war nicht lesbar."
	}
	// Auch ein einzelnes Objekt kann eine Manifestliste beschreiben — dann sagt
	// es der Medientyp, und die Kennung darin ist zwar die der Liste, aber
	// Docker liefert diese Form nicht durchgängig. Sie wird angenommen, weil sie
	// dann die richtige ist.
	if roh.Descriptor.Digest == "" {
		return "", false, "Die Antwort der Registry nannte keine Kennung."
	}
	return roh.Descriptor.Digest, false, ""
}

// fehltBuildx erkennt, dass das Unterkommando nicht da ist.
func fehltBuildx(meldung string) bool {
	m := strings.ToLower(meldung)
	return strings.Contains(m, "is not a docker command") ||
		strings.Contains(m, "unknown command") ||
		strings.Contains(m, "not a valid subcommand") ||
		strings.Contains(m, "executable file not found")
}

// registryGrund übersetzt die Meldung der Registry in einen Satz.
//
// Die Ratengrenze bekommt einen eigenen, weil sie das Verhalten des Panels
// bestimmt: Sie wird ANGEZEIGT und nicht wiederholt. Ein Panel, das bei „rate
// limit" gleich noch einmal fragt, ist der Grund für die Grenze.
func registryGrund(meldung string) string {
	m := strings.ToLower(meldung)
	switch {
	case strings.Contains(m, "toomanyrequests") || strings.Contains(m, "rate limit"):
		return "Die Registry hat die Abfrage wegen ihrer Ratengrenze abgewiesen. " +
			"Docker Hub zählt anonyme Abfragen; die Prüfung läuft erst morgen wieder."
	case strings.Contains(m, "unauthorized") || strings.Contains(m, "authentication required"):
		return "Die Registry verlangt eine Anmeldung. Zugangsdaten hält dieses Panel nicht."
	case strings.Contains(m, "manifest unknown") || strings.Contains(m, "not found"):
		return "Diesen Tag gibt es in der Registry nicht mehr."
	case meldung == "":
		return "Die Registry hat nicht geantwortet."
	default:
		return meldung
	}
}

// repoAus nimmt den Namensteil eines Refs, ohne Tag und ohne Kennung.
//
// "nginx:alpine" → "nginx", "ghcr.io/o/n:1.2" → "ghcr.io/o/n". Der Doppelpunkt
// im Registry-Namen mit Port ("reg:5000/n:1") ist der Grund, warum von hinten
// gesucht wird — und nur bis zum letzten Schrägstrich.
func repoAus(ref string) string {
	if i := strings.IndexByte(ref, '@'); i >= 0 {
		ref = ref[:i]
	}
	schraeg := strings.LastIndexByte(ref, '/')
	if i := strings.LastIndexByte(ref, ':'); i > schraeg {
		ref = ref[:i]
	}
	// Docker Hub schreibt lokal "nginx", in RepoDigests aber ebenfalls "nginx" —
	// beides bleibt unverändert. Ein voll qualifizierter Name aus einer anderen
	// Registry bleibt es auch.
	return ref
}

// ersteAusgabezeileText nimmt die erste nichtleere Zeile eines Textes.
func ersteAusgabezeileText(s string) string {
	for _, zeile := range strings.Split(s, "\n") {
		if z := strings.TrimSpace(zeile); z != "" {
			return z
		}
	}
	return ""
}
