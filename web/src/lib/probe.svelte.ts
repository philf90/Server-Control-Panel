// Die Probezeit — Grundsatz VI aus docs/16-neukonzeption.md: „Was schiefgehen
// kann, hat einen Rückweg."
//
// Eine Firewall-Änderung gilt zunächst auf Probe. Ohne Bestätigung binnen
// 60 Sekunden stellt der SERVER den vorherigen Stand wieder her. Diese Klasse
// ist nur die Anzeige davon, und das ist der wichtigste Satz über sie:
//
//   Verbindlich ist der Wächter im Server (internal/httpd/jobs.go,
//   firewallGuard). Er läuft weiter, wenn der Tab geschlossen wird, wenn der
//   Rechner einschläft, und auch dann, wenn das Panel gerade nicht mehr
//   erreichbar ist — das ist der Fall, für den er gebaut ist.
//
// Zwei Fallen, die hier ausdrücklich umgangen werden:
//
//  1. **Nicht sekundenweise herunterzählen.** Ein Zähler, der bei jedem Takt
//     eins abzieht, geht falsch, sobald der Browser den Takt drosselt (Tab im
//     Hintergrund: höchstens einmal pro Sekunde, oft seltener) oder der Rechner
//     schläft. Gerechnet wird deshalb aus einem festen Ablaufzeitpunkt.
//  2. **Bei null nicht raten.** Läuft die Frist ab, wird der Zustand EINMAL neu
//     geholt. Was dann gilt, sagt der Server — hier zu vermuten „jetzt ist
//     zurückgerollt" hieße, ein Ergebnis anzuzeigen, das niemand geprüft hat.

/** Probelauf zählt eine laufende Frist herunter. */
export class Probelauf {
  /** rest sind die Sekunden, die die Anzeige nennt. */
  rest = $state(0);
  offen = $state(false);
  gegenstand = $state("");

  #bis = 0;
  #takt: ReturnType<typeof setInterval> | null = null;
  #beiAblauf: () => void;

  /** beiAblauf läuft genau einmal, wenn die Frist durch ist. Dort gehört das
   *  Neuholen des Zustands hin. */
  constructor(beiAblauf: () => void) {
    this.#beiAblauf = beiAblauf;
  }

  /** setzen übernimmt den Zustand, den der Server geliefert hat. */
  setzen(offen: boolean, gegenstand: string, restSekunden: number): void {
    this.gegenstand = gegenstand;

    if (!offen || restSekunden <= 0) {
      this.anhalten();
      return;
    }

    // Der Ablaufzeitpunkt, nicht die Restdauer, ist der gemerkte Wert. Daraus
    // ergibt sich die Anzeige bei jedem Takt neu, und ein verschluckter Takt
    // kostet keine Sekunde — er verschiebt nur, wann man es sieht.
    this.#bis = Date.now() + restSekunden * 1000;
    this.offen = true;
    this.rest = restSekunden;

    if (this.#takt !== null) return;
    this.#takt = setInterval(() => this.#tick(), 250);
  }

  #tick(): void {
    const rest = Math.ceil((this.#bis - Date.now()) / 1000);
    if (rest > 0) {
      this.rest = rest;
      return;
    }
    // Erst anhalten, dann melden: Der Rückruf holt den Zustand, und der setzt
    // die Probe neu — ein noch laufender Takt würde dazwischenfunken.
    this.anhalten();
    this.#beiAblauf();
  }

  /** anhalten beendet die Anzeige. Beim Verlassen der Seite aufzurufen — ein
   *  Intervall, das niemand abbestellt, läuft bis zum Seitenwechsel weiter. */
  anhalten(): void {
    if (this.#takt !== null) {
      clearInterval(this.#takt);
      this.#takt = null;
    }
    this.offen = false;
    this.rest = 0;
  }
}
