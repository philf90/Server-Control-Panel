// Formatierung für Werte, die der Live-Kanal roh liefert.
//
// ACHTUNG, hier liegt eine bewusste Doppelung: Dieselbe Formatierung steht in
// Go (internal/ui/ui.go — formatBytes, formatRate). Der Live-Kanal überträgt
// den Snapshot roh, weil ihn die alte und die neue Oberfläche gemeinsam lesen;
// wer die Zahl fortschreibt, muss sie also selbst setzen. Beim ersten Aufbau
// der Seite kommen die Werte dagegen fertig formatiert aus
// /api/v1/overview — dort ist der Server die Wahrheit.
//
// Laufen die beiden Fassungen auseinander, springt die Zahl beim ersten
// Live-Ereignis sichtbar um. Genau dieser Fehler steckte in der alten
// Oberfläche: Go schnitt beim Wandeln nach uint64 ab, der Browser nicht, und
// unter 1 KiB stand „385.76365553133 B/s" (siehe CHANGELOG zu rc.6). Deshalb
// rundet byteText unter 1024 ausdrücklich.

const einheiten = ["KiB", "MiB", "GiB", "TiB", "PiB"];

export function byteText(b: number): string {
  if (!Number.isFinite(b) || b < 0) return "0 B";
  if (b < 1024) return `${Math.round(b)} B`;
  let wert = b;
  let i = -1;
  do {
    wert /= 1024;
    i++;
  } while (wert >= 1024 && i < einheiten.length - 1);
  return `${wert.toFixed(1)} ${einheiten[i]}`;
}

export function rateText(bytesPerSecond: number): string {
  if (!Number.isFinite(bytesPerSecond) || bytesPerSecond < 1) return "0 B/s";
  return `${byteText(bytesPerSecond)}/s`;
}

/** Prozent mit einer Stelle und Zeichen — wie prozentText in Go. Für die
 *  große Zahl einer Kachel ohne Zeichen siehe Uebersicht.svelte: Dort stehen
 *  Zahl und Einheit getrennt, weil die Kachel sie verschieden groß setzt. */
export function prozentText(v: number): string {
  return `${v.toFixed(1)} %`;
}

/** Die Schnittstelle, über die dieser Rechner am Netz hängt — dieselbe Wahl
 *  wie Snapshot.PrimaryInterface() in Go, damit Kachel und Verlauf dieselbe
 *  Karte zählen. Auf einem Server mit Docker wäre die erste sonst docker0. */
export function hauptSchnittstelle<T extends { primary: boolean }>(
  ifs: T[],
): T | undefined {
  return ifs.find((i) => i.primary) ?? ifs[0];
}
