/*
 * Der Zustand eines Prozesses — in Worten statt als Buchstabe.
 *
 * **Anlass ist eine Frage des Betreibers am 11. September 2026:** In der
 * Prozesstabelle der Übersicht stand ein nacktes `S`, und nirgends stand,
 * wofür es steht. Gesucht war zuerst eine Legende — gemessen war die Antwort
 * eine andere.
 *
 * **Die Erklärung war schon da und wurde weggeworfen.** `/proc/<pid>/status`
 * führt die Zeile als `State:\tS (sleeping)`; `SystemInfo::processes()` hat
 * davon `substr(…, 0, 1)` behalten. Das Wort, nach dem gefragt wurde, las der
 * Agent bereits und schnitt es eine Zeile vor der Anzeige ab.
 *
 * > **Ein Feld, das gelesen und dann gekürzt wird, ist von einem, das es nicht
 * > gibt, für den Leser nicht zu unterscheiden.**
 *
 * **Und es ist dieselbe Familie wie Befund 5 aus dem A2-Nachlauf** (`docs/91
 * §13`): Die Übersicht druckte `active_state` roh — „active", wo die
 * Dienste-Seite „läuft" sagt. Dort war der Rohwert von systemd, hier vom
 * Kernel; `ServicesViewTest` hält den einen, und für den anderen gab es
 * nichts.
 *
 * > **Dieselbe Grösse in zwei Fassungen anzuzeigen ist keine doppelte
 * > Auskunft, sondern eine widersprüchliche.**
 *
 * ## Warum eine Abbildung und keine Legende
 *
 * Eine Legende unter der Tabelle wäre eine **zweite Stelle**, die veraltet,
 * sobald der Kernel einen Zustand dazubekommt — und bei 390 px stünde sie
 * Bildschirme entfernt von dem Wert, den sie erklärt. Ein `title`-Tooltip
 * erreicht auf dem Telefon niemanden. Das Wort selbst braucht beides nicht.
 *
 * ## Der Rückfall erfindet nichts
 *
 * Kennt diese Abbildung einen Buchstaben nicht, steht das Wort da, das der
 * **Kernel** selbst dazu schreibt (`state_text`), und erst wenn auch das fehlt,
 * der Buchstabe. In keinem der drei Fälle entsteht eine Auskunft, die niemand
 * erhoben hat.
 *
 * > **Ein Rückfall, der immer etwas liefert, macht aus „unbekannt" eine falsche
 * > Auskunft.** Deshalb steht hier nie „unbekannt" und nie ein geratenes Wort.
 *
 * ## Was gemessen ist und was nicht
 *
 * Gemessen am 11. September 2026 über alle Prozesse eines Containers: `S
 * (sleeping)` 37×, `I (idle)` 38×, `R (running)` 2×. Die übrigen Buchstaben
 * stammen aus `task_state_array` in Linux' `fs/proc/array.c` und sind hier
 * **nicht** beobachtet worden — sie stehen für den Fall, dass sie auftreten,
 * und der Rückfall trägt alles, was daneben liegt.
 */

/** Was diese Abbildung von einer Prozesszeile braucht. */
export type ProcessLike = {
  /** Der Buchstabe aus `/proc/<pid>/status`. */
  state: string

  /** Das Wort des Kernels dazu, ohne Klammern — leer, wenn die Zeile keines trug. */
  state_text: string
}

/**
 * Buchstabe → deutsches Wort.
 *
 * **Die Sätze sind kurz gehalten, weil die Spalte es sein muss.** Unter 720 px
 * ist die Zelle gestapelt und trägt ihre Beschriftung daneben; ein Halbsatz
 * würde dort umbrechen und die Zeile in die Höhe ziehen.
 */
const WORDS: Record<string, string> = {
  R: 'läuft',
  S: 'schläft',
  D: 'wartet auf Platte',
  I: 'untätig',
  T: 'angehalten',
  t: 'vom Debugger angehalten',
  Z: 'Zombie',
  X: 'beendet',
  P: 'geparkt',
}

/**
 * Was in der Zustandsspalte steht.
 *
 * Drei Stufen, und keine erfindet etwas: das deutsche Wort, sonst das des
 * Kernels, sonst der Buchstabe.
 */
export function zustand(process: ProcessLike): string {
  const wort = WORDS[process.state]

  if (wort !== undefined) {
    return wort
  }

  return process.state_text !== '' ? process.state_text : process.state
}
