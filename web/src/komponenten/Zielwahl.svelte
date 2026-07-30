<script lang="ts">
  // Die Zielauswahl beim Kopieren und Verschieben: ein Ordnerbrowser im Dialog.
  //
  // Warum kein Textfeld: Bis 0.3.0 war das Ziel eines. Ein Tippfehler wurde erst
  // beim Absenden zu einer Meldung, und „/srv/date" statt „/srv/daten" legt im
  // Zweifel nichts an, sondern benennt um. Auswählbar ist hier nur, was der
  // Server genannt hat — und ob dort etwas landen darf, sagt er ebenfalls.
  //
  // Das ist eine BEDIENHILFE und keine Sicherheitsgrenze. Geprüft wird beim
  // Ausführen, in der Pfadwache. Ein selbstgebautes POST kommt an dieser Auswahl
  // vorbei und an der Wache nicht.
  import { AbgemeldetFehler, api } from "../lib/api";
  import { t } from "../lib/texte";
  import type { Ordnerauswahl } from "../lib/typen";

  let {
    start,
    titel,
    knopf,
    waehlen,
    abbrechen,
  }: {
    /** start ist der Ordner, in dem die Auswahl aufgeht — meist der, in dem der
     *  Eintrag liegt. Von dort ist der Weg kurz. */
    start: string;
    titel: string;
    knopf: string;
    waehlen: (pfad: string) => void;
    abbrechen: () => void;
  } = $props();

  let dialog: HTMLDialogElement | undefined = $state();
  // Der Anfangswert IST hier gemeint: `start` sagt, wo die Auswahl aufgeht,
  // danach blättert der Bediener selbst. Würde `ort` dem Wert folgen, sprang die
  // Auswahl zurück, sobald die Seite darunter etwas neu lädt.
  // svelte-ignore state_referenced_locally
  let ort = $state(start);
  let daten = $state<Ordnerauswahl | null>(null);
  let fehler = $state("");

  $effect(() => {
    if (dialog && !dialog.open) dialog.showModal();
  });

  $effect(() => {
    void holen(ort);
  });

  async function holen(pfad: string) {
    fehler = "";
    try {
      daten = await api.ordner(pfad);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }
</script>

<dialog
  bind:this={dialog}
  class="zielwahl"
  aria-labelledby="zielwahl-titel"
  oncancel={(e) => {
    e.preventDefault();
    abbrechen();
  }}
>
  <h2 id="zielwahl-titel">{titel}</h2>

  {#if fehler}
    <p class="warnung" role="alert">{fehler}</p>
  {/if}

  {#if daten}
    <!-- Die Schreibbereiche als Sprungmarken: Von dort aus findet man jedes
         erlaubte Ziel, ohne einen Pfad zu kennen. -->
    <div class="marken">
      {#each daten.wurzeln as wurzel (wurzel)}
        <button type="button" class:an={ort === wurzel} onclick={() => (ort = wurzel)}>
          {wurzel}
        </button>
      {/each}
    </div>

    <nav class="krumen">
      {#each daten.krumen as krume, i (krume.path)}
        {#if i > 1}<span aria-hidden="true">/</span>{/if}
        <button type="button" class:jetzt={krume.path === daten.pfad} onclick={() => (ort = krume.path)}>
          {krume.name}
        </button>
      {/each}
    </nav>

    <ul class="ordner">
      {#if daten.eltern}
        <li>
          <button type="button" class="hoch" onclick={() => (ort = daten!.eltern)}>
            ↑ {t.dateien.hoch}
          </button>
        </li>
      {/if}
      {#each daten.ordner as o (o.pfad)}
        <li>
          <!-- Hineinsehen darf man überall, wählen nur, wo geschrieben werden
               kann. Einen nicht beschreibbaren Ordner auszugrauen wäre falsch: Das
               Ziel liegt oft darunter. -->
          <button type="button" onclick={() => (ort = o.pfad)}>
            {o.name}/
          </button>
          {#if o.gesperrt}
            <span class="marke warn">{t.dateien.gesperrt}</span>
          {:else if !o.beschreibbar}
            <span class="marke">{t.dateien.nurDurchsehen}</span>
          {/if}
        </li>
      {/each}
      {#if daten.ordner.length === 0}
        <li class="leer">{t.dateien.keineUnterordner}</li>
      {/if}
    </ul>

    {#if daten.gekuerzt}
      <p class="detail">{t.dateien.zielGekuerzt}</p>
    {/if}

    <p class="gewaehlt">
      <b>{t.dateien.zielIst}</b>
      <span class="pfad">{daten.pfad}</span>
    </p>
    {#if !daten.beschreibbar}
      <p class="detail warnung">{t.dateien.zielNichtBeschreibbar}</p>
    {/if}
  {:else}
    <p class="detail">{t.dateien.laedt}</p>
  {/if}

  <div class="knoepfe">
    <button type="button" class="knopf leise" onclick={abbrechen}>
      {t.rueckfrage.abbrechen}
    </button>
    <button
      type="button"
      class="knopf"
      disabled={!daten?.beschreibbar}
      onclick={() => waehlen(daten!.pfad)}
    >
      {knopf}
    </button>
  </div>
</dialog>

<style>
  .zielwahl {
    background: var(--surface);
    border: 1px solid var(--line2);
    border-radius: var(--r);
    color: var(--tx);
    padding: 1.1rem 1.2rem;
    max-width: 34rem;
    width: calc(100vw - 2rem);
    display: grid;
    gap: 0.7rem;
  }

  .zielwahl::backdrop {
    background: rgba(0, 0, 0, 0.6);
  }

  h2 {
    font-size: 0.95rem;
    font-weight: 650;
  }

  .marken {
    display: flex;
    gap: 0.3rem;
    flex-wrap: wrap;
  }

  .marken button {
    background: var(--surface2);
    border: 1px solid var(--line);
    border-radius: 7px;
    padding: 0.24rem 0.5rem;
    color: var(--tx-mut);
    font: 0.74rem var(--mono);
    cursor: pointer;
  }

  .marken button.an {
    border-color: var(--accent-dim);
    color: var(--accent);
  }

  .krumen {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.1rem;
    font: 0.78rem var(--mono);
    color: var(--tx-faint);
  }

  .krumen button {
    background: none;
    border: none;
    padding: 0.1rem 0.2rem;
    color: var(--tx-mut);
    font: inherit;
    cursor: pointer;
  }

  .krumen button.jetzt {
    color: var(--tx);
    font-weight: 650;
  }

  .ordner {
    list-style: none;
    display: grid;
    gap: 0.1rem;
    max-height: 16rem;
    overflow-y: auto;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.4rem 0.5rem;
  }

  .ordner li {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    min-width: 0;
  }

  .ordner button {
    background: none;
    border: none;
    padding: 0.15rem 0;
    color: var(--tx);
    font: 0.8rem var(--mono);
    cursor: pointer;
    text-align: left;
    overflow-wrap: anywhere;
    min-width: 0;
  }

  .ordner button:hover {
    color: var(--accent);
  }

  .ordner .hoch {
    color: var(--tx-mut);
  }

  .ordner .leer {
    color: var(--tx-faint);
    font: 0.78rem var(--mono);
  }

  .gewaehlt {
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
    flex-wrap: wrap;
    font-size: 0.8rem;
    min-width: 0;
  }

  .gewaehlt b {
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
  }

  .gewaehlt .pfad {
    font-family: var(--mono);
    overflow-wrap: anywhere;
  }

  .knoepfe {
    display: flex;
    gap: 0.45rem;
    justify-content: flex-end;
    flex-wrap: wrap;
  }

  .detail {
    color: var(--tx-mut);
    font: 0.78rem var(--mono);
  }

  .warnung {
    color: var(--err);
  }
</style>
