// Baut den Editor-Bundle nach internal/ui/static/editor/cm.js.
//
// Das Ergebnis liegt im Repository, damit das Deployment bei einer Datei bleibt
// und niemand für einen Go-Build eine Node-Kette braucht. Ein eingecheckter
// Bundle, den niemand nachbauen kann, wäre allerdings Fremdcode ohne
// Herkunftsnachweis — deshalb sind alle Fassungen in package.json exakt
// festgeschrieben, und ein CI-Job baut ihn nach und vergleicht byteweise.
//
// Aufruf: make editor

import { build } from "esbuild";
import { mkdirSync, statSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const hier = dirname(fileURLToPath(import.meta.url));
const ziel = join(hier, "..", "..", "internal", "ui", "static", "editor");
mkdirSync(ziel, { recursive: true });

const ergebnis = await build({
  entryPoints: [join(hier, "editor.mjs")],
  outfile: join(ziel, "cm.js"),
  bundle: true,
  minify: true,
  format: "iife",
  // Ein fester Zielwert statt "esnext": Sonst hängt das Ergebnis von der
  // esbuild-Fassung ab und der byteweise Vergleich in der CI schlägt an, ohne
  // dass sich etwas geändert hätte.
  target: ["es2020"],
  // Keine Sourcemap: Sie wäre größer als der Bundle selbst und im Binary
  // eingebettet nutzlos.
  sourcemap: false,
  legalComments: "none",
  logLevel: "info",
});

if (ergebnis.errors.length > 0) {
  process.exit(1);
}

const groesse = statSync(join(ziel, "cm.js")).size;
console.log(`cm.js: ${(groesse / 1024).toFixed(1)} KiB`);

// Eine Obergrenze, damit "schlank" auch hier eine Zusage bleibt. Der Bundle
// steckt im Binary, und dessen Grenze liegt bei 30 MB.
const grenze = 500 * 1024;
if (groesse > grenze) {
  console.error(`cm.js ist größer als ${grenze / 1024} KiB — bitte den Umfang der Erweiterungen prüfen.`);
  process.exit(1);
}
