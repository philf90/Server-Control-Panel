<script lang="ts">
  // Der Ereignisstrom von Docker als aufklappbarer Bereich.
  //
  // Kein eigener Reiter, sondern ein Bereich, der zugeklappt beginnt — und das
  // ist eine Entscheidung und keine Platzfrage: Der Strom hält einen
  // docker-Prozess auf dem Server, und dafür soll niemand zahlen, der die Seite
  // nur geöffnet hat. Erst der Klick öffnet ihn, das Verlassen der Fläche
  // schließt ihn. Dieselbe Haltung wie beim Journalstrom
  // (lib/journal.svelte.ts).
  //
  // Er beantwortet eine Frage, die kein Zustand beantwortet: „Warum ist der
  // Container um 3 Uhr neu gestartet." Der Zustand sagt, dass er seit vier
  // Stunden läuft; was um 3 Uhr geschah, sagt nur der Strom.
  import { t } from "../lib/texte";
  import type { Dockerereignis } from "../lib/typen";

  /** maxEreignisse ist die Menge, die im Browser bleibt. Ein beschäftigter
   *  Server schreibt hunderte je Minute; alles zu behalten wäre eine Anzeige,
   *  die nach einer Stunde langsam wird. Dieselbe Grenze und dieselbe
   *  Begründung wie beim Journal. */
  const maxEreignisse = 500;

  let offen = $state(false);
  let zeilen = $state<Dockerereignis[]>([]);
  let fehler = $state("");
  let verworfen = $state(0);
  let quelle: EventSource | null = null;

  function oeffnen() {
    if (quelle) return;
    fehler = "";
    verworfen = 0;
    zeilen = [];

    const q = new EventSource("/api/v1/docker/events");
    quelle = q;

    q.addEventListener("ereignis", (e) => {
      const ereignis = JSON.parse((e as MessageEvent<string>).data) as Dockerereignis;
      // Vorn anfügen und hinten abschneiden: Das Neueste steht oben, weil man
      // beim Öffnen wissen will, was gerade geschieht.
      zeilen = [ereignis, ...zeilen].slice(0, maxEreignisse);
    });

    q.addEventListener("verworfen", (e) => {
      verworfen = Number(JSON.parse((e as MessageEvent<string>).data) as string);
    });

    q.addEventListener("fehler", (e) => {
      fehler = JSON.parse((e as MessageEvent<string>).data) as string;
    });

    q.addEventListener("ende", () => schliessen());

    q.onerror = () => {
      // EventSource baut von selbst neu auf, solange es kann. Ein Fehler ist
      // deshalb nur dann einer, wenn die Verbindung endgültig zu ist — und dann
      // ist die häufigste Ursache die Schranke von vier Zuschauern.
      if (q.readyState === EventSource.CLOSED) {
        fehler = t.docker.ereignisFolgerVoll;
        schliessen();
      }
    };
  }

  function schliessen() {
    quelle?.close();
    quelle = null;
  }

  function umschalten() {
    offen = !offen;
    if (offen) oeffnen();
    else schliessen();
  }

  // Beim Verlassen der Fläche zumachen. Ohne das liefe auf dem Server ein
  // docker-Prozess weiter, dem niemand zusieht.
  $effect(() => () => schliessen());
</script>

<div class="ereignisse">
  <button type="button" class="kopf" onclick={umschalten} aria-expanded={offen}>
    <span class="pfeil" class:auf={offen}>›</span>
    {t.docker.ereignisseZeigen}
  </button>

  {#if offen}
    <p class="detail">{t.docker.ereignisseWesen}</p>
    {#if fehler}<p class="warnung" role="alert">{fehler}</p>{/if}
    {#if verworfen > 0}<p class="hinweis">{t.docker.ereignisVerworfen(verworfen)}</p>{/if}

    {#if zeilen.length === 0}
      <p class="detail">{t.docker.ereignisseWarte}</p>
    {:else}
      <div class="tabelle-rahmen">
        <table class="tabelle">
          <thead>
            <tr>
              <th>{t.docker.spalteZeit}</th>
              <th>{t.docker.spalteAktion}</th>
              <th>{t.docker.spalteObjekt}</th>
            </tr>
          </thead>
          <tbody>
            {#each zeilen as e, i (e.zeit + e.aktion + e.objekt + i)}
              <tr>
                <td data-spalte={t.docker.spalteZeit}><span class="mono">{e.zeit}</span></td>
                <td data-spalte={t.docker.spalteAktion}>
                  <!-- Der Punkt gehört dazu: .zustand färbt ein <i>, nicht den
                       Text. Ohne ihn sähe der Befund aus wie jede andere
                       Zeile — und genau ihn sucht jemand hier. -->
                  <span class="zustand {e.ernst ? 'schlecht' : 'info'}">
                    <i></i>{e.aktion}
                  </span>
                  {#if e.zusatz}<span class="leise">{e.zusatz}</span>{/if}
                </td>
                <td data-spalte={t.docker.spalteObjekt}>
                  {e.objekt}
                  {#if e.stack}<span class="leise">{e.stack} · {e.dienst}</span>{/if}
                  <span class="leise">{e.art}</span>
                </td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {/if}
  {/if}
</div>

<style>
  .ereignisse {
    display: grid;
    gap: 0.5rem;
    margin-bottom: 1rem;
  }

  .kopf {
    background: none;
    border: 0;
    padding: 0;
    color: var(--tx-mut);
    font: inherit;
    font-size: 0.82rem;
    cursor: pointer;
    text-align: left;
    justify-self: start;
    display: flex;
    align-items: center;
    gap: 0.35rem;
  }

  .kopf:hover {
    color: var(--tx);
  }

  .pfeil {
    display: inline-block;
    transition: transform 0.12s ease;
  }

  .pfeil.auf {
    transform: rotate(90deg);
  }

  .leise {
    color: var(--tx-faint);
    font-size: 0.76rem;
    margin-left: 0.4rem;
  }
</style>
