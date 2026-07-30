<script lang="ts">
  // Die Rückfrage vor einer zerstörenden Aktion — die Bildschirmfassung von
  // docs/14-bestaetigungen.md.
  //
  // Der Text kommt vom Server. Diese Komponente formuliert nichts und entscheidet
  // nichts: Sie zeigt, was der Handler geantwortet hat, und schickt die Aktion
  // erneut. Ein selbstgebautes POST ohne das Feld tut weiterhin nichts, und wenn
  // dieser Dialog sich irrt, ist das kein Sicherheitsproblem — dieselbe
  // Arbeitsteilung wie bei der Pfadwache.
  //
  // Ein echtes <dialog> mit showModal() und nicht ein <div> mit Schleier: Der
  // Browser bringt den Fokusfang, die oberste Ebene und Escape mit. Das
  // nachzubauen ist die Stelle, an der Tastaturbedienung still verloren geht.
  import { t } from "../lib/texte";
  import type { Bestaetigung } from "../lib/typen";

  let {
    frage,
    laeuft = false,
    bestaetigen,
    abbrechen,
  }: {
    frage: Bestaetigung;
    laeuft?: boolean;
    bestaetigen: (getippt: string) => void;
    abbrechen: () => void;
  } = $props();

  let dialog: HTMLDialogElement | undefined = $state();
  let getippt = $state("");

  // Bei Stufe 3 bleibt der Knopf gesperrt, bis das Wort stimmt. EqualFold wie
  // auf dem Server: Auf einem Telefon macht die Tastatur aus "vm" gern "Vm".
  const passt = $derived(
    frage.tippen === "" ||
      getippt.trim().toLocaleLowerCase() === frage.tippen.toLocaleLowerCase(),
  );

  $effect(() => {
    // Nicht showModal() im Markup, sondern hier: Die Methode gibt es nur am
    // fertig eingehängten Element.
    if (dialog && !dialog.open) dialog.showModal();
  });
</script>

<!-- oncancel fängt Escape ab. Ohne das schließt der Browser den Dialog, aber die
     Komponente bleibt eingehängt — beim nächsten Versuch stünde ein
     geschlossener Dialog da, und der Knopf wirkte kaputt. -->
<dialog
  bind:this={dialog}
  class="rueckfrage"
  aria-labelledby="rueckfrage-titel"
  oncancel={(e) => {
    e.preventDefault();
    abbrechen();
  }}
>
  <h2 id="rueckfrage-titel">{frage.titel}</h2>
  <p class="frage">{frage.frage}</p>

  {#if frage.fehler}
    <p class="fehler" role="alert">{frage.fehler}</p>
  {/if}

  {#if frage.punkte.length > 0}
    <ul class="punkte">
      {#each frage.punkte as punkt (punkt)}
        <li>{punkt}</li>
      {/each}
    </ul>
  {/if}

  {#if frage.tippen}
    <label class="tippen">
      <span>{frage.tippen_hinweis}</span>
      <!-- svelte-ignore a11y_autofocus -->
      <input
        bind:value={getippt}
        type="text"
        autocomplete="off"
        spellcheck="false"
        autofocus
      />
    </label>
  {/if}

  <div class="knoepfe">
    <!-- Der gefährliche Knopf bekommt den Fokus nicht: Bei Stufe 2 steht er auf
         „abbrechen", bei Stufe 3 im Eingabefeld. Ein Dialog, in dem Enter sofort
         zerstört, ist keine Rückfrage. -->
    <button type="button" class="knopf leise" onclick={abbrechen} disabled={laeuft}>
      {t.rueckfrage.abbrechen}
    </button>
    <button
      type="button"
      class="knopf gefahr"
      onclick={() => bestaetigen(getippt)}
      disabled={!passt || laeuft}
    >
      {laeuft ? t.rueckfrage.laeuft : frage.knopf}
    </button>
  </div>
</dialog>

<style>
  .rueckfrage {
    width: min(30rem, calc(100vw - 2rem));
    background: var(--surface);
    color: var(--tx);
    border: 1px solid var(--line2);
    border-radius: 12px;
    padding: 1.1rem 1.2rem 1rem;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.55);
  }

  .rueckfrage::backdrop {
    background: rgba(0, 0, 0, 0.6);
  }

  h2 {
    font-size: 1rem;
    font-weight: 650;
    margin-bottom: 0.4rem;
  }

  .frage {
    font-size: 0.9rem;
    color: var(--tx);
  }

  .fehler {
    margin-top: 0.6rem;
    font-size: 0.82rem;
    color: var(--err);
  }

  .punkte {
    list-style: none;
    margin-top: 0.7rem;
    display: grid;
    gap: 0.3rem;
    font-size: 0.83rem;
    color: var(--tx-mut);
  }

  .punkte li {
    padding-left: 0.9rem;
    text-indent: -0.9rem;
  }

  .punkte li::before {
    content: "· ";
    color: var(--tx-faint);
  }

  .tippen {
    display: grid;
    gap: 0.3rem;
    margin-top: 0.9rem;
    font-size: 0.78rem;
    color: var(--tx-mut);
  }

  .tippen input {
    background: var(--bg);
    border: 1px solid var(--line2);
    border-radius: 7px;
    padding: 0.4rem 0.6rem;
    color: var(--tx);
    font: 0.9rem var(--mono);
  }

  .knoepfe {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    margin-top: 1.1rem;
  }
</style>
