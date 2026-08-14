/*
 * Eine Menge mit dem Wort, das zu ihr passt.
 *
 * **Anlass ist „geschätzt 1 Zeilen"** — im Abnahmelauf von P5c auf einer Tabelle
 * mit genau einer Zeile gesehen, auf beiden Systemen (`docs/48 §3.3`). Das Wort
 * war an die Zahl geklebt, weil beim Schreiben eine Tabelle mit 16384 Zeilen
 * offen war.
 *
 * > **Ein Plural, der immer stimmt, stimmt nur, solange niemand eine Zeile
 * > anlegt.**
 *
 * Die Einzahl ist im Betrieb der Normalfall und in der Entwicklung der
 * Sonderfall: eine frisch angelegte Tabelle, ein einziger Treffer, ein
 * Abonnement. Deshalb fällt sie beim Bauen nicht auf und beim Benutzen sofort.
 *
 * **Und sie stand an drei Stellen falsch, nicht an einer.** Der Wächter zu
 * diesem Fund (`CountedNounTest`) hat beim ersten Lauf ausser der Konsole noch
 * das Protokoll („1 Einträge") und die Planvorlage („1 Abonnements gebunden")
 * gemeldet — beide seit P2 im Repo, beide von niemandem bemerkt. Deshalb steht
 * die Entscheidung hier und nicht in der Seite, die sie gerade braucht.
 *
 * > **Ein Fehler, der an drei Stellen unabhängig gemacht wurde, ist keine
 * > Unachtsamkeit, sondern eine fehlende Stelle.**
 */

/** Die Zahl, wie diese Oberfläche Zahlen schreibt. */
export function formatCount(value: number): string {
    return value.toLocaleString('de-DE')
}

/**
 * Die Menge und ihr Wort.
 *
 * **Beide Wörter werden übergeben und keines abgeleitet.** Im Deutschen gibt es
 * dafür keine Regel: `Zeile` wird zu `Zeilen`, `Zugang` zu `Zugänge`, `Treffer`
 * bleibt. Wer die Mehrzahl rechnen wollte, bekäme „1 Zugangs" statt einer
 * Entscheidung.
 */
export function counted(value: number, one: string, many: string): string {
    return `${formatCount(value)} ${value === 1 ? one : many}`
}
