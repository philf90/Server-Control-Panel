/*
 * Eine Byte-Zahl als Text — in der Einheit, die sie unterscheidbar lässt.
 *
 * **Der Anlass ist der dritte Abnahmelauf vom 8. August 2026.** Die Messung
 * legte bis dahin gerundete Megabyte ab, und die Oberfläche zeigte für jede
 * Datenbank unter einem Megabyte „0 MB" — dasselbe wie für eine leere. Das ist
 * genau die Unterscheidung, gegen deren Verlust der Agent argumentiert: Wer den
 * belegten Platz nachsieht, sucht meist etwas, das er vermisst (docs/36
 * §22.3j).
 *
 * **Eine Fassung und nicht zwei.** Die Liste und die Einzelansicht hatten je
 * ihre eigene `size()`-Funktion mit demselben Inhalt. Zwei Fassungen derselben
 * Regel heissen: Die eine wird nachgezogen und die andere nicht — und welche
 * das ist, weiss man erst hinterher. `SizeUnitTest` besteht darauf, dass keine
 * Seite selbst rechnet.
 *
 * `null` heisst „noch nie gemessen" und ist etwas anderes als eine gemessene
 * Null; die Unterscheidung trifft der Aufrufer, weil nur er weiss, wie sie an
 * seiner Stelle heisst.
 */
export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`

  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024).toLocaleString('de-DE')} KB`

  if (bytes < 1024 * 1024 * 1024) {
    return `${(bytes / (1024 * 1024)).toLocaleString('de-DE', { maximumFractionDigits: 1 })} MB`
  }

  return `${(bytes / (1024 * 1024 * 1024)).toLocaleString('de-DE', { maximumFractionDigits: 1 })} GB`
}
