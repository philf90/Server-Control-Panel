<script lang="ts">
  // Die größten Prozesse nach CPU und Speicher. Der vollständige Befehl steht
  // als Titel an der Zeile: In die Tabelle passt er nicht, und ohne ihn sagt
  // „python3" nichts darüber, was da läuft.
  import type { Process } from "../lib/typen";
  import { byteText } from "../lib/formate";
  import { t } from "../lib/texte";

  let { prozesse = [] }: { prozesse?: Process[] } = $props();
</script>

<section class="liste">
  <div class="tabelle-titel" id="proz-titel">{t.uebersicht.prozesse}</div>
  <div class="tabelle-rahmen">
  <table class="tabelle" aria-labelledby="proz-titel">
    <thead>
      <tr>
        <th>{t.uebersicht.prozess}</th>
        <th>{t.uebersicht.benutzer}</th>
        <th class="zahlenspalte">{t.kacheln.cpu}</th>
        <th class="zahlenspalte">{t.uebersicht.speicher}</th>
      </tr>
    </thead>
    <tbody>
      {#each prozesse as p (p.pid)}
        <tr>
          <td data-spalte={t.uebersicht.prozess}>
            <span class="pfad" title={p.command || p.name}>{p.name}</span>
            <span class="pid gedaempft">{p.pid}</span>
          </td>
          <td data-spalte={t.uebersicht.benutzer} class="gedaempft">{p.user}</td>
          <td data-spalte={t.kacheln.cpu} class="zahlenspalte">{p.cpu_pct.toFixed(1)} %</td>
          <td data-spalte={t.uebersicht.speicher} class="zahlenspalte">
            {byteText(p.rss)} <span class="gedaempft">{p.rss_pct.toFixed(1)} %</span>
          </td>
        </tr>
      {:else}
        <tr>
          <td colspan="4" class="gedaempft">{t.uebersicht.keineProzesse}</td>
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

  .pid {
    font: 0.72rem var(--mono);
    margin-left: 0.5rem;
  }
</style>
