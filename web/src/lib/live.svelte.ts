// Der Live-Kanal als Zustand, den Komponenten abonnieren.
//
// Der SSE-Hub des Servers bleibt unverändert — er wird von der alten und der
// neuen Oberfläche gemeinsam gelesen. Um die Wiederverbindung kümmert sich der
// Browser; EventSource versucht es von allein weiter. Deshalb steht hier keine
// eigene Rückzugsstaffel, sondern nur das Mitschreiben des Zustands, damit die
// Oberfläche „getrennt" anzeigen kann statt stillzustehen.

import type { Snapshot } from "./typen";

class LiveStand {
  snapshot = $state<Snapshot | null>(null);
  verbunden = $state(false);
  /** gestartet verhindert zwei EventSource-Verbindungen, wenn mehrere
   *  Komponenten den Kanal brauchen. */
  #quelle: EventSource | null = null;

  starten(): void {
    if (this.#quelle) return;

    const quelle = new EventSource("/events");
    this.#quelle = quelle;

    quelle.addEventListener("open", () => {
      this.verbunden = true;
    });

    quelle.addEventListener("metrics", (ereignis) => {
      try {
        this.snapshot = JSON.parse((ereignis as MessageEvent<string>).data) as Snapshot;
        this.verbunden = true;
      } catch {
        // Eine unlesbare Nachricht verwirft den Stand nicht: Die Seite zeigt
        // dann eben den vorigen Wert weiter, was besser ist als eine leere
        // Kachel.
      }
    });

    quelle.addEventListener("error", () => {
      // EventSource meldet hier auch die abgelaufene Sitzung (der Server
      // antwortet dann mit 401). Beides sieht gleich aus, und beides heißt für
      // die Anzeige: gerade keine frischen Zahlen.
      this.verbunden = false;
    });
  }

  beenden(): void {
    this.#quelle?.close();
    this.#quelle = null;
    this.verbunden = false;
  }
}

export const live = new LiveStand();
