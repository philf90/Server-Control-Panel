// Der Journalstrom.
//
// Sieht dem Vorgang (lib/vorgang.svelte.ts) ähnlich und ist etwas anderes. Der
// Unterschied ist nicht die Technik — beide hängen an einem Ereignisstrom —,
// sondern die Bedeutung, und daran hängen drei Entscheidungen:
//
//   - Ein Vorgang hat ein Ende, das der Server bestimmt. Ein Journal hat keines:
//     Es endet, wenn niemand mehr zusieht. Deshalb gibt es hier ein „anhalten"
//     und dort keines.
//   - Ein Vorgang wird zu Ende gesehen, ein Journal begleitet. Deshalb hält der
//     Vorgang alle Zeilen und das Journal nur die letzten: Ein Journal, das eine
//     Stunde mitläuft, würde den Speicher des Browsers füllen.
//   - Ein Vorgang wird beim Verlassen der Seite weitergeführt und beim
//     Zurückkommen vorgefunden. Ein Journal wird beim Verlassen beendet — es
//     hält einen journalctl-Prozess auf dem Server, und dafür soll niemand
//     zahlen, der nicht zusieht.
//
// Deshalb ist es eine eigene Klasse und keine Erweiterung von Vorgang. Sie
// hätten drei gemeinsame Zeilen und sechs verschiedene.

import type { Logzeile } from "./typen";

/** maxZeilen ist die Menge, die im Browser bleibt.
 *
 *  Ein Journal auf einem beschäftigten Server schreibt hunderte Zeilen je
 *  Minute. Alles zu behalten wäre eine Anzeige, die nach einer Stunde langsam
 *  wird und nach drei den Tab abschießt — und niemand liest die 40 000. Zeile
 *  von oben. Wer weiter zurück will, filtert oder erhöht die Anzahl der
 *  Abfrage. */
const maxZeilen = 2000;

export class Journalstrom {
  /** verfolgt sagt, ob der Strom offen ist. */
  verfolgt = $state(false);
  /** zeilen sind die neuesten zuerst — dieselbe Ordnung wie in der Abfrage,
   *  damit die Liste beim Umschalten nicht die Richtung wechselt. */
  zeilen = $state<Logzeile[]>([]);
  /** fehler ist ein Fehler des Zusehens. „Zu viele Zuschauer" ist einer davon,
   *  und er hat nichts damit zu tun, ob das Journal etwas hergibt. */
  fehler = $state("");
  /** luecken zählt Zeilen, die der Server verworfen hat, weil das Journal
   *  schneller schrieb als die Leitung übertrug. Sie zu verschweigen wäre eine
   *  Lücke, die niemand sieht. */
  luecken = $state(0);

  #quelle: EventSource | null = null;

  /** anhaengen öffnet den Strom mit den Filtern der aktuellen Abfrage.
   *
   *  suchpfad ist die fertige Abfragezeichenkette (ohne Fragezeichen) — dieselbe
   *  wie bei der Abfrage, damit der Strom nicht mehr zeigt als die Liste vorher
   *  hergab. */
  anhaengen(suchpfad: string): void {
    this.loesen();
    this.fehler = "";
    this.luecken = 0;
    // Die Liste leeren, bevor der Strom sie füllt. Der Strom bringt seinen
    // eigenen Rückblick mit — dieselben letzten N Einträge, die die Abfrage schon
    // geliefert hat (journalctl --follow --lines N). Ohne das Leeren stand jede
    // Zeile zweimal da, und bei 200 geholten Zeilen sah die Seite nach einem
    // Klick auf „verfolgen" wie 400 Ereignisse aus. Gesehen hat das ein
    // Bildschirmfoto, kein Test.
    this.zeilen = [];

    const quelle = new EventSource(`/api/v1/logs/follow?${suchpfad}`);
    this.#quelle = quelle;
    this.verfolgt = true;

    quelle.addEventListener("zeile", (e) => {
      const zeile = JSON.parse((e as MessageEvent<string>).data) as Logzeile;
      // Vorn anfügen und hinten abschneiden. Ein unbegrenztes Array wäre der
      // Speicher des Browsers als Journalarchiv.
      this.zeilen = [zeile, ...this.zeilen].slice(0, maxZeilen);
    });

    quelle.addEventListener("luecke", (e) => {
      this.luecken += Number(JSON.parse((e as MessageEvent<string>).data)) || 0;
    });

    quelle.addEventListener("fehler", (e) => {
      this.fehler = JSON.parse((e as MessageEvent<string>).data) as string;
    });

    quelle.addEventListener("ende", () => {
      // Der Server hat den Strom beendet — bei einem Journal heißt das, dass
      // journalctl weg ist. Kein automatischer Neuversuch: Er würde bei einem
      // System ohne journald in einer Endlosschleife laufen.
      this.loesen();
    });

    quelle.onerror = () => {
      if (quelle.readyState === EventSource.CLOSED) {
        // Hier landet auch die Abweisung mit 429: EventSource sagt nicht, warum
        // — es kennt den Statuscode nicht. Der Text bleibt deshalb allgemein,
        // und die Seite fragt danach die Abfrage neu, die `folger_frei` mitbringt.
        this.fehler = "verbindung";
        this.loesen();
      }
    };
  }

  /** loesen hält den Strom an. Beim Verlassen der Seite aufzurufen: Er hält
   *  einen journalctl-Prozess auf dem Server. */
  loesen(): void {
    this.#quelle?.close();
    this.#quelle = null;
    this.verfolgt = false;
  }

  /** setzen übernimmt das Ergebnis einer Abfrage als Ausgangsstand. */
  setzen(zeilen: Logzeile[]): void {
    this.zeilen = zeilen.slice(0, maxZeilen);
  }
}
