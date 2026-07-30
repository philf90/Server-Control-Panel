<script lang="ts">
  // Die Befehlspalette. Sie war der offene Punkt aus docs/15-neuordnung.md:
  // „Sie braucht einen eigenen Suchindex über Dienste, Dateien und Regeln und
  // bleibt der optionale Teil des Entwurfs." Mit der Einzelseiten-Anwendung ist
  // sie erstmals sauber baubar — ohne Skript in der Seite, das die Richtlinie
  // verwirft.
  //
  // Diese Fassung sucht die Navigationsziele. Dienste, Dateien und Regeln kommen
  // dazu, sobald die Module sie über /api/v1 anbieten; die Suche ist dafür so
  // geschnitten, dass eine zweite Quelle nur eine weitere Liste ist.
  import { sichtbareZiele, suche, type Ziel } from "../lib/ziele";
  import { palette } from "../lib/palette.svelte";
  import { weg } from "../lib/weg.svelte";
  import { t } from "../lib/texte";

  let { istOwner = false }: { istOwner?: boolean } = $props();

  let begriff = $state("");
  let auswahl = $state(0);
  let feld: HTMLInputElement | undefined = $state();
  let liste: HTMLElement | undefined = $state();

  const offen = $derived(palette.offen);
  // Dieselbe Rollenprüfung wie in der Leiste, und aus demselben Grund: Ein Ziel,
  // das nur 403 antwortet, ist kein Treffer.
  const treffer = $derived(suche(begriff, sichtbareZiele(istOwner)));

  // Beim Öffnen leer anfangen. Ein stehengebliebener Begriff von vorhin ist
  // nicht Bequemlichkeit, sondern eine Trefferliste, die nicht zur Absicht passt.
  $effect(() => {
    if (palette.offen) {
      begriff = "";
      auswahl = 0;
    }
  });

  // Die Auswahl darf nie außerhalb der Trefferliste stehen: Nach dem Tippen ist
  // die Liste kürzer, und ein Enter würde ins Leere greifen.
  $effect(() => {
    if (auswahl >= treffer.length) auswahl = Math.max(0, treffer.length - 1);
  });

  function schliessen() {
    palette.schliessen();
  }

  function waehlen(ziel: Ziel | undefined) {
    if (!ziel) return;
    palette.schliessen();
    // Erst der Router, dann der Browser: Ziele der neuen Oberfläche wechseln
    // ohne Neuladen, die noch auf / zeigenden laden ganz normal. weg.gehe sagt,
    // ob es sein Ziel war — eine zweite Prüfung des Pfades hier wäre dieselbe
    // Regel an einer zweiten Stelle.
    if (!weg.gehe(ziel.href)) window.location.href = ziel.href;
  }

  function tasteGlobal(e: KeyboardEvent) {
    // ⌘K auf dem Mac, Strg+K sonst. preventDefault, weil Firefox mit Strg+K in
    // seine eigene Suchleiste springt.
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "k") {
      e.preventDefault();
      palette.umschalten();
      return;
    }
    // Escape hängt am Fenster und nicht am Dialog: So schließt es auch, wenn der
    // Fokus per Tab in der Trefferliste steht. Ein Tastenhorcher auf dem
    // Dialog-Element wäre außerdem falsch — es nimmt keinen Fokus an, und Svelte
    // weist darauf hin.
    if (palette.offen && e.key === "Escape") {
      e.preventDefault();
      schliessen();
    }
  }

  // Pfeile und Enter am Eingabefeld, wo getippt wird.
  function tasteImFeld(e: KeyboardEvent) {
    switch (e.key) {
      case "ArrowDown":
        e.preventDefault();
        if (treffer.length > 0) auswahl = (auswahl + 1) % treffer.length;
        break;
      case "ArrowUp":
        e.preventDefault();
        if (treffer.length > 0) auswahl = (auswahl - 1 + treffer.length) % treffer.length;
        break;
      case "Home":
        e.preventDefault();
        auswahl = 0;
        break;
      case "End":
        e.preventDefault();
        auswahl = Math.max(0, treffer.length - 1);
        break;
      case "Enter":
        e.preventDefault();
        waehlen(treffer[auswahl]);
        break;
    }
  }

  // Das Feld bekommt den Fokus, sobald die Palette offen ist. Und die gewählte
  // Zeile bleibt im Bild, auch wenn man sich mit den Pfeiltasten hinausbewegt.
  $effect(() => {
    if (offen) feld?.focus();
  });

  $effect(() => {
    if (!offen || !liste) return;
    liste.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: "nearest" });
  });
</script>

<svelte:window onkeydown={tasteGlobal} />

{#if offen}
  <!-- Der Schleier schließt auf Klick. Er trägt role="presentation": Ein Klick
       daneben ist dasselbe wie Escape, und ein Knopf drumherum würde die
       Tastaturreihenfolge um einen Halt verlängern, den niemand braucht.
       Geprüft wird das Ziel des Klicks und nicht mit stopPropagation auf der
       Palette gearbeitet — ein Klickhorcher auf dem Dialog müsste sonst einen
       Tastenhorcher daneben haben, den er nicht braucht. -->
  <div
    class="schleier"
    onclick={(e) => {
      if (e.target === e.currentTarget) schliessen();
    }}
    role="presentation"
  >
    <!-- tabindex="-1": Der Dialog nimmt keinen Fokus über Tab, muss ihn aber
         annehmen können — sonst steht ein aria-modal ohne Anker im Baum. -->
    <div
      class="palette"
      role="dialog"
      aria-modal="true"
      aria-label={t.palette.titel}
      tabindex="-1"
    >
      <div class="feldzeile">
        <span class="lupe" aria-hidden="true">⌕</span>
        <!-- svelte-ignore a11y_autofocus -->
        <input
          bind:this={feld}
          bind:value={begriff}
          onkeydown={tasteImFeld}
          type="text"
          class="feld"
          placeholder={t.palette.platzhalter}
          aria-label={t.palette.platzhalter}
          aria-controls="palette-liste"
          aria-activedescendant={treffer.length > 0 ? `palette-treffer-${auswahl}` : undefined}
          autocomplete="off"
          spellcheck="false"
        />
        <kbd>esc</kbd>
      </div>

      {#if treffer.length === 0}
        <p class="leer">{t.palette.nichts}</p>
      {:else}
        <ul class="treffer" id="palette-liste" role="listbox" aria-label={t.palette.titel} bind:this={liste}>
          {#each treffer as ziel, i (ziel.id)}
            <li
              id="palette-treffer-{i}"
              role="option"
              aria-selected={i === auswahl}
              class:an={i === auswahl}
            >
              <!-- Ein Knopf und kein Verweis: Die Auswahl läuft über waehlen(),
                   damit Maus und Tastatur denselben Weg nehmen. -->
              <button type="button" onclick={() => waehlen(ziel)} onmouseenter={() => (auswahl = i)}>
                <svg aria-hidden="true"><use href="#sym-{ziel.symbol}" /></svg>
                <span class="label">{ziel.label}</span>
                {#if ziel.neu}<span class="neu">{t.palette.neu}</span>{/if}
                <span class="gruppe">{ziel.gruppe}</span>
              </button>
            </li>
          {/each}
        </ul>
      {/if}

      <div class="fusszeile">
        <span><kbd>↑</kbd><kbd>↓</kbd> {t.palette.waehlen}</span>
        <span><kbd>⏎</kbd> {t.palette.oeffnen}</span>
        <span class="hinweis">{t.palette.spaeter}</span>
      </div>
    </div>
  </div>
{/if}

<style>
  .schleier {
    position: fixed;
    inset: 0;
    z-index: 50;
    background: rgba(0, 0, 0, 0.55);
    display: grid;
    /* Nicht mittig: Eine Palette, die beim Tippen kürzer wird, würde sonst
       hüpfen. Oben verankert bleibt das Feld an seiner Stelle. */
    align-content: start;
    justify-items: center;
    padding: 12vh 1rem 1rem;
  }

  .palette {
    width: min(38rem, 100%);
    background: var(--surface);
    border: 1px solid var(--line2);
    border-radius: 12px;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5);
    overflow: hidden;
  }

  .feldzeile {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.7rem 0.9rem;
    border-bottom: 1px solid var(--line);
  }

  .lupe {
    color: var(--tx-faint);
    font-size: 1.1rem;
    flex: none;
  }

  .feld {
    flex: 1;
    min-width: 0;
    background: none;
    border: none;
    color: var(--tx);
    font: 1rem var(--sans);
    outline: none;
  }

  .feld::placeholder {
    color: var(--tx-faint);
  }

  kbd {
    font: 0.68rem var(--mono);
    color: var(--tx-mut);
    border: 1px solid var(--line2);
    border-radius: 5px;
    padding: 0.1rem 0.35rem;
    flex: none;
  }

  .treffer {
    list-style: none;
    max-height: 42vh;
    overflow-y: auto;
    padding: 0.3rem;
  }

  .treffer button {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    width: 100%;
    background: none;
    border: none;
    border-radius: 8px;
    padding: 0.5rem 0.6rem;
    color: var(--tx-mut);
    font: 0.9rem var(--sans);
    text-align: left;
    cursor: pointer;
  }

  .treffer svg {
    width: 16px;
    height: 16px;
    flex: none;
    opacity: 0.85;
  }

  .label {
    color: var(--tx);
  }

  li.an button {
    background: var(--surface2);
  }

  li.an .label {
    color: var(--accent);
  }

  .neu {
    font: 600 0.62rem var(--mono);
    letter-spacing: 0.04em;
    color: var(--accent);
    border: 1px solid var(--accent-dim);
    border-radius: 4px;
    padding: 0.05rem 0.3rem;
    flex: none;
  }

  .gruppe {
    margin-left: auto;
    font-size: 0.72rem;
    color: var(--tx-faint);
    flex: none;
  }

  .leer {
    padding: 1.2rem 0.9rem;
    color: var(--tx-mut);
    font-size: 0.88rem;
    text-align: center;
  }

  .fusszeile {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.5rem 0.9rem;
    border-top: 1px solid var(--line);
    background: var(--surface2);
    font-size: 0.72rem;
    color: var(--tx-faint);
  }

  .fusszeile kbd {
    margin-right: 0.25rem;
  }

  .hinweis {
    margin-left: auto;
    text-align: right;
  }

  @media (max-width: 600px) {
    .schleier {
      padding: 4vh 0.6rem 0.6rem;
    }

    .hinweis {
      display: none;
    }
  }
</style>
