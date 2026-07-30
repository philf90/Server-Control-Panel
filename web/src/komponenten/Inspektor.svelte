<script lang="ts">
  // Der Inspektor: Details zum ausgewählten Eintrag, neben der Liste statt an
  // ihrer Stelle.
  //
  // Das ist die Form, die sieben weitere Module übernehmen — Docker, Sites,
  // Datenbanken, Backups, Cron, Firewall, Dateien. Deshalb steckt die Hülle
  // hier und nicht in der Dienstseite: Titel, Kopfzeile, Schließen, das
  // Verhalten auf schmalen Schirmen und die Tastaturbedienung sind für alle
  // dieselben. Was hineinkommt, ist Sache des Moduls.
  //
  // Warum kein Seitenwechsel wie in der alten Oberfläche: Wer einen Dienst
  // neustartet, will danach die Liste sehen — mit der neuen Zeile darin und
  // nicht als frisch geladene Seite, auf der er die Stelle wiederfinden muss.
  import { t } from "../lib/texte";
  import type { Snippet } from "svelte";

  let {
    titel,
    zustand = "",
    zustandText = "",
    marke = "",
    schliessen,
    kinder,
  }: {
    titel: string;
    /** zustand ist eine der Klassen aus app.css: gut, warn, schlecht, info. */
    zustand?: string;
    zustandText?: string;
    marke?: string;
    schliessen: () => void;
    kinder: Snippet;
  } = $props();

  function taste(e: KeyboardEvent) {
    if (e.key !== "Escape") return;
    // Ein offener Dialog besitzt Escape. Ohne diese Zeile schloss ein Escape im
    // Rückfrage-Dialog auch den Inspektor darunter: Der Dialog nimmt die Taste
    // als `cancel`, der Horcher hier bekommt dasselbe keydown am Fenster, und
    // danach war die Rückfrage abgebrochen UND die Auswahl weg. Gesehen hat das
    // der Browsertest, kein Nachdenken.
    if (document.querySelector("dialog[open]")) return;
    // Escape hängt am Fenster und nicht an der Platte, weil der Fokus nach einem
    // Klick in der Liste dort steht und nicht hier.
    schliessen();
  }
</script>

<svelte:window onkeydown={taste} />

<!-- aria-label statt einer Überschrift außerhalb: Die Region trägt den Namen des
     Eintrags, damit ein Vorleseprogramm beim Hineinspringen sagt, worüber es
     spricht. -->
<section class="inspektor" aria-label="{t.inspektor.titel}: {titel}">
  <header>
    {#if zustandText}
      <span class="zustand {zustand}" title={zustandText}>
        <i aria-hidden="true"></i>
        <span class="nur-vorlese">{zustandText}</span>
      </span>
    {/if}
    <h2 class="pfad">{titel}</h2>
    {#if marke}<span class="marke">{marke}</span>{/if}
    <button type="button" class="zu" onclick={schliessen} aria-label={t.inspektor.schliessen}>
      <span aria-hidden="true">✕</span>
    </button>
  </header>

  <div class="rumpf">
    {@render kinder()}
  </div>
</section>

<style>
  .inspektor {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    /* Der Inspektor bleibt stehen, während die Liste scrollt — die Zeile, um
     * die es geht, und ihre Details sollen gleichzeitig zu sehen sein. */
    position: sticky;
    top: 0.6rem;
    align-self: start;
    min-width: 0;
    overflow: hidden;
  }

  header {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.7rem 0.85rem;
    border-bottom: 1px solid var(--line);
    background: var(--surface2);
  }

  h2 {
    font-size: 0.92rem;
    font-weight: 650;
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .pfad {
    font-family: var(--mono);
  }

  .zu {
    margin-left: auto;
    flex: none;
    background: none;
    border: none;
    color: var(--tx-faint);
    font-size: 0.9rem;
    cursor: pointer;
    padding: 0.15rem 0.3rem;
    border-radius: 5px;
  }

  .zu:hover {
    color: var(--tx);
    background: var(--surface3);
  }

  .rumpf {
    padding: 0.85rem;
    display: grid;
    gap: 0.9rem;
    min-width: 0;
  }
</style>
