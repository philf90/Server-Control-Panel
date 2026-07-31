// Baut die Oberfläche nach internal/ui/dist/, von wo sie ins Binary eingebettet
// wird. Das Ergebnis liegt im Repository — aus demselben Grund wie beim
// Editor-Bundle: Ein Go-Build soll keine Node-Kette brauchen. Damit der
// eingecheckte Stand nachprüfbar bleibt, baut ein CI-Job ihn nach und
// vergleicht byteweise; jede Einstellung hier, die das Ergebnis von der
// Umgebung abhängig machen würde, ist deshalb ein Fehler.
//
// Nachgewiesen ist die Reproduzierbarkeit über drei Fälle: zwei Läufe
// hintereinander, ein Lauf aus einem anderen Verzeichnispfad und ein Lauf nach
// frischem `npm ci` — alle drei ergeben byteweise dasselbe.
//
// Aufruf: make ui

import { defineConfig } from "vite";
import { svelte } from "@sveltejs/vite-plugin-svelte";

export default defineConfig({
  plugins: [svelte()],

  // Die Oberfläche liegt seit dem Umschalten (0.4.0) an der Wurzel. Der Pfad
  // steht auch in den Asset-Verweisen des erzeugten index.html — und ein zweites
  // Mal in internal/httpd/handlers_v2.go, wo der Server sie ausliefert. Läufen
  // die beiden auseinander, kommt eine weiße Seite mit 404 auf das Bundle.
  base: "/",

  build: {
    outDir: "../internal/ui/dist",
    // outDir liegt außerhalb des Projektverzeichnisses; ohne diese Zusage
    // weigert sich Vite, dort zu löschen.
    emptyOutDir: true,

    // Ein fester Zielwert statt "esnext" — dieselbe Überlegung wie beim
    // Editor-Bundle: Sonst hängt das Ergebnis von der Werkzeugfassung ab und
    // der byteweise Vergleich schlägt an, ohne dass sich etwas geändert hat.
    target: "es2022",

    // Keine Sourcemap: Sie wäre größer als das Bundle und im Binary nutzlos.
    sourcemap: false,

    // Der Polyfill für modulepreload wird als INLINE-Skript eingefügt. Die
    // Richtlinie des Panels erlaubt nur `script-src 'self'` — das Skript würde
    // verworfen.
    //
    // Diese Zeile stand hier als Vorsorge, solange es keine dynamischen Importe
    // gab. Seit dem Editor gibt es einen (lib/editorkern.ts wird von
    // komponenten/Editor.svelte nachgeladen), und damit ist sie in Betrieb: Ohne
    // sie wäre die neue Oberfläche an derselben Stelle gescheitert wie der Editor
    // der alten — und zwar erst im Browser, nicht im Build.
    modulePreload: { polyfill: false },

    // Die Größe des gepackten Ergebnisses zu messen kostet Zeit und sagt hier
    // nichts: Ausgeliefert wird aus dem Binary, nicht über einen Webserver mit
    // eigener Kompression.
    reportCompressedSize: false,
  },
});
