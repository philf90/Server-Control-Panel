# Fremdcode im Editor-Bundle

`internal/ui/static/editor/cm.js` ist ein gebündeltes Erzeugnis aus den unten
genannten Paketen. Es liegt im Repository, damit ein Go-Build ohne Node-Kette
auskommt und das Deployment bei einer Datei bleibt.

Ein eingecheckter Bundle, den niemand nachbauen kann, wäre Fremdcode ohne
Herkunftsnachweis. Deshalb:

- alle Fassungen sind in `package.json` exakt festgeschrieben (kein `^`),
- `package-lock.json` liegt daneben und wird über `npm ci` verwendet,
- ein CI-Job (`editor` in `.github/workflows/ci.yml`) baut den Bundle nach und
  vergleicht ihn byteweise mit dem eingecheckten Stand.

Neu bauen: `make editor`.

## Im Bundle enthalten (Laufzeit)

Alle unter der **MIT-Lizenz**. Urheber sind Marijn Haverbeke und die jeweiligen
Mitwirkenden der CodeMirror- und Lezer-Projekte.

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
| `@lezer/common` | 1.5.2 | MIT |
| `@lezer/highlight` | 1.2.3 | MIT |
| `@lezer/json` | 1.0.3 | MIT |
| `@lezer/lr` | 1.4.10 | MIT |
| `@lezer/yaml` | 1.0.4 | MIT |
| `@marijn/find-cluster-break` | 1.0.3 | MIT |
| `crelt` | 1.0.7 | MIT |
| `style-mod` | 4.1.3 | MIT |
| `w3c-keyname` | 2.2.8 | MIT |

`@codemirror/autocomplete` steht nicht in `package.json`: Es kommt als
Abhängigkeit von `lang-json` und `lang-yaml` mit. Die Vervollständigung selbst
ist in `editor.mjs` nicht eingeschaltet.

## Nur zum Bauen

| Paket | Fassung | Lizenz |
|---|---|---|
| `esbuild` | 0.28.1 | MIT |

Der Lizenztext der MIT-Lizenz liegt in jedem der Pakete unter `LICENSE`; er ist
inhaltlich derselbe wie der in `../../LICENSE` genannten Apache-2.0-Lizenz
*nicht* — Apache-2.0 gilt für dieses Projekt, MIT für den Fremdcode. Beide sind
miteinander vereinbar; MIT-Code darf in einem Apache-2.0-Werk weiterverteilt
werden, solange Urhebervermerk und Lizenztext erhalten bleiben. Genau dafür ist
diese Datei da.
