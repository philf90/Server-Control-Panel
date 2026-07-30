// Ein laufender Vorgang, an dem die Oberfläche hängt.
//
// Grundsatz III aus docs/15-neuordnung.md: Handlungen sind quittiert. Eine
// Aktion, die Minuten dauert, ist kein Klick mit Rückmeldung am Ende, sondern
// ein Vorgang mit Ausgabe, Dauer und Ergebnis.
//
// Die Klasse hier ist der Beobachter dazu, und sie steht bewusst außerhalb der
// Komponente: Der Vorgang läuft auf dem Server weiter, wenn jemand die Seite
// verlässt. Wer zurückkommt, soll ihn vorfinden — deshalb fragt sie beim
// Anhängen erst die Ressource und hängt sich nur dann an den Strom, wenn dort
// wirklich etwas läuft.
//
// Der Strom sagt nur „vorbei". Ob es geglückt ist, wie lange es dauerte und ob
// eine Anmerkung dazu gehört, steht in der Ressource — deshalb wird sie am Ende
// noch einmal gefragt. Zwei Fassungen dieser Auskunft (eine im Ereignis, eine in
// der Ressource) liefen auseinander, und dann sagte die Zeile über dem Auszug
// etwas anderes als der Auszug.

import { api } from "./api";
import type { Job } from "./typen";

export class Vorgang {
  /** job ist der Zustand, wie der Server ihn sieht — null heißt: noch keiner. */
  job = $state<Job | null>(null);
  /** zeilen wächst aus dem Strom. Getrennt von job.zeilen, weil der Strom die
   *  Zeilen einzeln liefert und die Ressource sie am Stück; sie hier zu
   *  sammeln ist die einzige Fassung, die immer vollständig ist. */
  zeilen = $state<string[]>([]);
  /** fehler ist ein Fehler des Zusehens, nicht des Vorgangs. Die zwei
   *  auseinanderzuhalten ist wichtig: „Der Strom ist abgerissen" heißt nicht,
   *  dass apt gescheitert ist. */
  fehler = $state("");

  #quelle: EventSource | null = null;
  readonly #art: string;

  constructor(art: string) {
    this.#art = art;
  }

  /** setzen übernimmt einen Zustand, den ein anderer Aufruf schon geliefert hat
   *  — etwa die Paketantwort, die den laufenden Vorgang mitbringt. Damit steht
   *  die Platte beim Aufbau der Seite sofort da, ohne eine zweite Runde. */
  setzen(job: Job | null): void {
    this.job = job;
    this.zeilen = job?.zeilen ?? [];
    if (job && job.laeuft) this.anhaengen();
  }

  /** anhaengen öffnet den Strom, falls er nicht schon offen ist. */
  anhaengen(): void {
    if (this.#quelle) return;
    this.fehler = "";

    const quelle = new EventSource(`/api/v1/jobs/${encodeURIComponent(this.#art)}/events`);
    this.#quelle = quelle;

    quelle.addEventListener("output", (e) => {
      this.zeilen = [...this.zeilen, JSON.parse((e as MessageEvent<string>).data) as string];
    });

    quelle.addEventListener("end", () => {
      // Erst schließen, dann fragen: Sonst versucht der Browser sofort einen
      // neuen Verbindungsaufbau, weil er ein geschlossenes Ereignisfeld als
      // Abbruch behandelt.
      this.loesen();
      void this.holen();
    });

    quelle.onerror = () => {
      // EventSource baut von selbst neu auf, solange es kann. Ein Fehler ist
      // deshalb nur dann einer, wenn die Verbindung endgültig zu ist.
      if (quelle.readyState === EventSource.CLOSED) {
        this.loesen();
        void this.holen();
      }
    };
  }

  /** holen fragt den Zustand ab. Das ist die verbindliche Auskunft. */
  async holen(): Promise<void> {
    try {
      const job = await api.job(this.#art);
      this.job = job;
      // Die Zeilen der Ressource nur übernehmen, wenn sie mehr sind: Der Strom
      // hat unter Umständen schon mehr gesehen, und der Puffer des Servers ist
      // bei 5000 Zeilen begrenzt.
      if (job && job.zeilen.length > this.zeilen.length) this.zeilen = job.zeilen;
      if (job?.laeuft) this.anhaengen();
    } catch (e) {
      this.fehler = e instanceof Error ? e.message : "Der Vorgang ist nicht abfragbar.";
    }
  }

  /** loesen schließt den Strom. Beim Verlassen der Seite aufzurufen — ein
   *  offenes Ereignisfeld hält eine Verbindung, und der Server hält einen
   *  Abonnenten dafür. */
  loesen(): void {
    this.#quelle?.close();
    this.#quelle = null;
  }
}
