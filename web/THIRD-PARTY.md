# Fremdcode in der Oberfläche

`internal/ui/dist/` ist ein gebündeltes Erzeugnis aus den unten genannten
Paketen. Es liegt im Repository, damit ein Go-Build ohne Node-Kette auskommt und
das Deployment bei einem Binary bleibt.

Ein eingechecktes Bundle, das niemand nachbauen kann, wäre Fremdcode ohne
Herkunftsnachweis. Deshalb gilt hier dieselbe Ordnung wie beim Editor-Bundle der
alten Oberfläche (`packaging/editor/THIRD-PARTY.md`):

- alle Fassungen sind in `package.json` exakt festgeschrieben (kein `^`),
- `package-lock.json` liegt daneben und wird über `npm ci` verwendet,
- ein CI-Job (`ui` in `.github/workflows/ci.yml`) baut das Bundle nach und
  vergleicht es Datei für Datei mit dem eingecheckten Stand.

Nachgewiesen ist die Reproduzierbarkeit über drei Fälle: zwei Läufe
hintereinander, ein Lauf aus einem anderen Verzeichnispfad und ein Lauf nach
frischem `npm ci` — alle drei ergeben byteweise dasselbe.

Neu bauen: `make ui`.

## Kein CDN, und das ist strukturell erzwungen

Die Richtlinie des Panels lautet `default-src none` mit `script-src self` und
`style-src self`. Ein Aufruf an einen fremden Host wäre damit nicht möglich —
nicht als Absprache, sondern weil der Browser ihn verwirft. Alles, was die
Oberfläche braucht, steckt im Binary.

Eine Ausnahme trägt der Editor, und sie ist benannt: CodeMirror legt für seine
Stilregeln ein `<style>`-Element an. Es bekommt einen Nonce, den der Server je
Antwort neu zieht (`internal/httpd/handlers_v2.go`). Nicht `unsafe-inline` — die
Begründung dagegen steht bei `cspMitStilNonce` in
`internal/httpd/middleware.go`.

## Enthalten (Laufzeit)

37 Pakete. Svelte und seine Abhängigkeiten tragen die Oberfläche,
CodeMirror und Lezer den Editor — der wird als eigener Brocken nachgeladen und
liegt nicht im Hauptbündel (`web/src/lib/editorkern.ts`).

| Paket | Fassung | Lizenz |
|---|---|---|
| `@codemirror/autocomplete` | 6.20.3 | MIT |
| `@codemirror/commands` | 6.10.4 | MIT |
| `@codemirror/lang-json` | 6.0.2 | MIT |
| `@codemirror/lang-yaml` | 6.1.3 | MIT |
| `@codemirror/language` | 6.12.4 | MIT |
| `@codemirror/legacy-modes` | 6.5.3 | MIT |
| `@codemirror/state` | 6.7.1 | MIT |
| `@codemirror/view` | 6.43.7 | MIT |
| `@jridgewell/gen-mapping` | 0.3.13 | MIT |
| `@jridgewell/remapping` | 2.3.5 | MIT |
| `@jridgewell/resolve-uri` | 3.1.2 | MIT |
| `@jridgewell/sourcemap-codec` | 1.5.5 | MIT |
| `@jridgewell/trace-mapping` | 0.3.31 | MIT |
| `@lezer/common` | 1.5.2 | MIT |
| `@lezer/highlight` | 1.2.3 | MIT |
| `@lezer/json` | 1.0.3 | MIT |
| `@lezer/lr` | 1.4.10 | MIT |
| `@lezer/yaml` | 1.0.4 | MIT |
| `@marijn/find-cluster-break` | 1.0.3 | MIT |
| `@sveltejs/acorn-typescript` | 1.0.11 | MIT |
| `@types/estree` | 1.0.9 | MIT |
| `@types/trusted-types` | 2.0.7 | MIT |
| `acorn` | 8.18.0 | MIT |
| `aria-query` | 5.3.1 | Apache-2.0 |
| `axobject-query` | 4.1.0 | Apache-2.0 |
| `clsx` | 2.1.1 | MIT |
| `crelt` | 1.0.7 | MIT |
| `devalue` | 5.8.2 | MIT |
| `esm-env` | 1.2.2 | MIT |
| `esrap` | 2.3.0 | MIT |
| `is-reference` | 3.0.3 | MIT |
| `locate-character` | 3.0.0 | MIT |
| `magic-string` | 0.30.21 | MIT |
| `style-mod` | 4.1.3 | MIT |
| `svelte` | 5.56.8 | MIT |
| `w3c-keyname` | 2.2.8 | MIT |
| `zimmerframe` | 1.1.4 | MIT |

Alle unter der **MIT-Lizenz**, mit zwei Ausnahmen unter **Apache-2.0**:
`aria-query` und `axobject-query`. Sie kommen mit dem Svelte-Compiler und
werden nur zur Bauzeit gebraucht — im ausgelieferten Bundle stehen sie nicht.
Urheber sind Rich Harris und die Mitwirkenden des Svelte-Projekts sowie Marijn
Haverbeke und die Mitwirkenden der CodeMirror- und Lezer-Projekte.

## Nur zur Bauzeit

| Paket | Fassung | Zweck |
|---|---|---|
| `vite` | 8.1.5 | Bündler |
| `@sveltejs/vite-plugin-svelte` | 7.2.0 | Svelte-Übersetzung |

Sie stehen in `devDependencies` und wandern nicht ins Erzeugnis. Ihre eigenen
Abhängigkeiten sind über `package-lock.json` festgeschrieben.
