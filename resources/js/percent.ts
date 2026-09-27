/*
 * Ein Anteil als Text — mit deutschem Komma und höchstens einer Stelle danach.
 *
 * **Der Anlass ist der Abnahmelauf von „Platte voll" am 27. September 2026**
 * (`docs/137 §7`). Die Übersicht zeigte „25.9 %" neben „722,3 MiB": Die Grösse
 * kommt fertig formatiert vom Server, der Anteil als Zahl, und `{{ }}` schreibt
 * eine Zahl in der Schreibweise von JavaScript hin. Beim Beheben fand sich
 * dieselbe rohe Ausgabe in der Liste der Abonnements.
 *
 * **Eine Fassung und nicht zwei**, aus demselben Grund wie bei `bytes.ts`:
 * `PercentFormatTest` besteht darauf, dass keine Seite einen Anteil roh
 * ausgibt — auch keinen ganzzahligen wie den Fortschritt eines Vorgangs, denn
 * eine Liste der Ausdrücke, die „sicher ganzzahlig" sind, wäre die Stelle, die
 * veraltet. Das Zeichen dahinter schreibt der Aufrufer — neben einem Balken
 * heisst es „%", in einer Beschriftung für die Vorlesesoftware „Prozent".
 */
export function formatPercent(percent: number): string {
  return percent.toLocaleString('de-DE', { maximumFractionDigits: 1 })
}
