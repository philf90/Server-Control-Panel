// Ohne diese Datei meldet vite-plugin-svelte bei jedem Bau, dass es keine
// findet, und arbeitet mit Vorgaben. Die Vorgaben sind richtig — aber eine
// Warnung, die bei jedem Lauf erscheint und nie etwas bedeutet, gewöhnt einem
// das Hinsehen ab.
export default {
  compilerOptions: {
    // Runen ausdrücklich: Der gesamte Code hier benutzt $state und $derived,
    // und im gemischten Betrieb würde eine Datei ohne Rune still in den alten
    // Modus fallen.
    runes: true,
  },
};
