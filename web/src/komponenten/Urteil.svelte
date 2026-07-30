<script lang="ts">
  // Grundsatz V: erst das Urteil, dann die Zahlen. Ein Satz beantwortet die
  // Frage, mit der man die Seite öffnet — läuft alles, oder braucht etwas
  // Aufmerksamkeit? Gezählt und formuliert wird auf dem Server (urteilAus in
  // handlers_app.go), damit alte und neue Oberfläche denselben Satz sagen.
  import type { Urteil } from "../lib/typen";
  import { t } from "../lib/texte";

  let {
    urteil = null,
    fehler = false,
    erneut,
  }: {
    urteil?: Urteil | null;
    fehler?: boolean;
    erneut?: () => void;
  } = $props();
</script>

<div class="urteil" class:gut={urteil?.level === "ok"} class:unbekannt={fehler || !urteil}>
  <span class="punkt" aria-hidden="true"></span>
  {#if fehler}
    <!-- Gescheiterte Erhebung ist NICHT dasselbe wie „alles in Ordnung". Wer
         das verwechselt, baut ein Panel, das schweigt, wenn es klemmt. -->
    <b>{t.uebersicht.urteilUnbekannt}</b>
    <span class="gedaempft">{t.uebersicht.urteilUnbekanntDetail}</span>
    {#if erneut}
      <button type="button" class="nochmal" onclick={erneut}>{t.fehler.erneut}</button>
    {/if}
  {:else if urteil}
    <b>{urteil.titel}</b>
    <span class="gedaempft">{urteil.sub}</span>
  {:else}
    <!-- Solange die Erhebung läuft, steht hier kein Urteil. „Alles läuft
         normal" zu behaupten, bevor man nachgesehen hat, wäre die schlechteste
         der möglichen Antworten. -->
    <span class="gedaempft">{t.uebersicht.urteilLaeuft}</span>
  {/if}
</div>

<style>
  .urteil {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 0.6rem 0.9rem;
    margin-bottom: 1rem;
    font-size: 0.9rem;
  }

  .punkt {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    flex: none;
    background: var(--accent);
  }

  .urteil.gut .punkt {
    background: var(--ok);
  }

  /* Unbekannt trägt keine Zustandsfarbe: Grün wäre eine Behauptung, Rot ein
   * Alarm, den niemand erhoben hat. */
  .urteil.unbekannt .punkt {
    background: var(--tx-faint);
  }

  .nochmal {
    margin-left: auto;
    font: 600 0.76rem var(--sans);
    color: var(--accent);
    background: none;
    border: none;
    cursor: pointer;
    padding: 0.1rem 0.3rem;
    white-space: nowrap;
  }

  b {
    font-weight: 600;
  }

  .gedaempft {
    color: var(--tx-mut);
  }
</style>
