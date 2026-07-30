<script lang="ts">
  // Ein Dateisystem, das an mehreren Stellen hängt, ist EIN Eintrag zum
  // Aufklappen. Die systemd-Härtung der eigenen Unit hängt Teile von / mehrfach
  // ein; ohne Zusammenfassung stünde dieselbe Platte sieben Mal in der Liste,
  // jedes Mal mit denselben Zahlen. Verschwiegen wird nichts — die weiteren
  // Stellen stehen darunter.
  //
  // Die weiteren Stellen tragen die Zahlen des Dateisystems, an dem sie hängen.
  // Getrennte Zahlen zu zeigen hieße, sich welche auszudenken.
  //
  // Anders als in der alten Oberfläche ist der Umschalter hier ein Knopf und
  // keine Checkbox mit :has() — dort war das nötig, weil die Seite ohne
  // JavaScript tragen musste. Hier trägt sie es nicht mehr, und ein Knopf sagt
  // einem Vorleseprogramm auch, was er tut.
  import type { Filesystem } from "../lib/typen";
  import { byteText } from "../lib/formate";
  import { t } from "../lib/texte";

  let { dateisysteme = [] }: { dateisysteme?: Filesystem[] } = $props();

  // Aufgeklappte Einhängepunkte. Der Pfad ist der Schlüssel und nicht der
  // Index: Nach einer Messung kann sich die Reihenfolge ändern, und dann wäre
  // plötzlich ein anderes Dateisystem offen.
  let offen = $state(new Set<string>());

  function umschalten(mount: string) {
    const neu = new Set(offen);
    if (neu.has(mount)) {
      neu.delete(mount);
    } else {
      neu.add(mount);
    }
    offen = neu;
  }

  const eng = (pct: number) => pct >= 90;
</script>

<section class="liste">
  <div class="tabelle-titel" id="fs-titel">{t.uebersicht.dateisysteme}</div>
  <div class="tabelle-rahmen">
  <table class="tabelle" aria-labelledby="fs-titel">
    <thead>
      <tr>
        <th>{t.uebersicht.einhaengepunkt}</th>
        <th>{t.uebersicht.geraet}</th>
        <th>{t.uebersicht.auslastung}</th>
        <th class="zahlenspalte">{t.uebersicht.belegt}</th>
        <th class="zahlenspalte">{t.uebersicht.inodes}</th>
      </tr>
    </thead>
    <tbody>
      {#each dateisysteme as fs (fs.mount)}
        <tr class:eng={eng(fs.used_pct)}>
          <td data-spalte={t.uebersicht.einhaengepunkt}>
            <span class="pfad">{fs.mount}</span>
            {#if fs.also_at && fs.also_at.length > 0}
              <button
                type="button"
                class="mehr"
                aria-expanded={offen.has(fs.mount)}
                onclick={() => umschalten(fs.mount)}
              >
                {offen.has(fs.mount) ? "▾" : "▸"}
                {t.uebersicht.weitereStellen(fs.also_at.length)}
              </button>
            {/if}
          </td>
          <td data-spalte={t.uebersicht.geraet} class="pfad gedaempft">{fs.device}</td>
          <td data-spalte={t.uebersicht.auslastung}>
            <progress
              class="balken"
              class:eng={eng(fs.used_pct)}
              max="100"
              value={fs.used_pct.toFixed(1)}
              aria-hidden="true"
            ></progress>
            <span class="anteil">{fs.used_pct.toFixed(1)} %</span>
          </td>
          <td data-spalte={t.uebersicht.belegt} class="zahlenspalte">
            {byteText(fs.used)} <span class="gedaempft">{t.uebersicht.von} {byteText(fs.total)}</span>
          </td>
          <td data-spalte={t.uebersicht.inodes} class="zahlenspalte" class:knapp={eng(fs.inodes_pct)}>
            {fs.inodes_pct.toFixed(1)} %
          </td>
        </tr>

        {#if offen.has(fs.mount) && fs.also_at}
          {#each fs.also_at as auch (auch)}
            <tr class="zweig" class:eng={eng(fs.used_pct)}>
              <td data-spalte={t.uebersicht.einhaengepunkt}>
                <span class="zweigpfeil" aria-hidden="true">↳</span>
                <span class="pfad">{auch}</span>
              </td>
              <td data-spalte={t.uebersicht.geraet} class="pfad gedaempft">{fs.device}</td>
              <td data-spalte={t.uebersicht.auslastung} class="gedaempft">
                {t.uebersicht.dieselbePlatte}
              </td>
              <td data-spalte={t.uebersicht.belegt} class="zahlenspalte gedaempft">
                {byteText(fs.used)}
              </td>
              <td data-spalte={t.uebersicht.inodes} class="zahlenspalte gedaempft">
                {fs.inodes_pct.toFixed(1)} %
              </td>
            </tr>
          {/each}
        {/if}
      {:else}
        <tr>
          <td colspan="5" class="gedaempft">{t.uebersicht.keineDateisysteme}</td>
        </tr>
      {/each}
    </tbody>
  </table>
  </div>
</section>

<style>
  /* EINE Wurzel je Komponente. Steht sie im Gitter der Übersicht, ist jedes
     Wurzelelement eine eigene Gitterzelle — Titel und Tabelle rutschten sonst
     nebeneinander statt übereinander. Im DOM-Test fiel das nicht auf, weil
     beide Elemente da waren; gesehen hat es erst ein Bildschirmfoto. */
  .liste {
    min-width: 0;
  }

  .mehr {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    margin-left: 0.5rem;
    font: 0.72rem var(--sans);
    color: var(--tx-mut);
    background: none;
    border: none;
    padding: 0.1rem 0.2rem;
    cursor: pointer;
  }

  .mehr:hover {
    color: var(--accent);
  }

  .anteil {
    display: block;
    font: 0.72rem var(--mono);
    font-variant-numeric: tabular-nums;
    color: var(--tx-mut);
    margin-top: 0.2rem;
  }

  .zweigpfeil {
    color: var(--tx-faint);
    margin-right: 0.3rem;
  }

  .knapp {
    color: var(--err);
  }

  @media (max-width: 600px) {
    .anteil {
      display: inline;
      margin: 0;
    }
  }
</style>
