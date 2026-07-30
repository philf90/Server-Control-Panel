<script lang="ts">
  // Die Platte, auf der ein Vorgang zu sehen ist: Zustandszeile oben, Auszug
  // darunter.
  //
  // Grundsatz IV, „Das Panel verschweigt nichts": Der Auszug ist die rohe
  // Ausgabe des Befehls, nicht eine Zusammenfassung davon. Bis 0.3.0 sammelte
  // das Panel die zwanzig Zeilen von apt-get update und verwarf sie; übrig
  // blieb im Fehlerfall die erste stderr-Zeile, und wer wissen wollte, welche
  // Quelle klemmt, musste sich über SSH anmelden.
  import { t } from "../lib/texte";
  import type { Vorgang } from "../lib/vorgang.svelte";

  let { vorgang }: { vorgang: Vorgang } = $props();

  const job = $derived(vorgang.job);

  let kasten: HTMLElement | undefined = $state();
  // Am Ende bleiben, aber nur solange der Betrachter dort ist. Wer nach oben
  // gescrollt hat, liest eine Zeile — ihn dabei nach unten zu reißen ist die
  // ärgerlichste Eigenschaft einer solchen Anzeige.
  let amEnde = $state(true);

  function beimScrollen() {
    if (!kasten) return;
    const rest = kasten.scrollHeight - kasten.scrollTop - kasten.clientHeight;
    amEnde = rest < 24;
  }

  $effect(() => {
    // Von vorgang.zeilen lesen, damit dieser Effekt bei jeder neuen Zeile läuft.
    vorgang.zeilen.length;
    if (kasten && amEnde) kasten.scrollTop = kasten.scrollHeight;
  });

  const stufe = $derived.by(() => {
    if (!job) return "";
    if (job.laeuft) return "info";
    if (job.gescheitert) return "schlecht";
    if (job.hinweis) return "warn";
    return "gut";
  });

  const zustandText = $derived.by(() => {
    if (!job) return "";
    if (job.laeuft) return t.vorgang.laeuft;
    if (job.gescheitert) return t.vorgang.gescheitert;
    if (job.hinweis) return t.vorgang.teils;
    return t.vorgang.fertig;
  });
</script>

{#if job}
  <section class="vorgang" aria-label={job.titel}>
    <header>
      <span class="zustand {stufe}">
        <i aria-hidden="true"></i>{zustandText}
      </span>
      <b>{job.titel}</b>
      <!-- Als ein Ausdruck und nicht als {#if}-Block: Svelte schneidet den
           Leerraum am Ende eines Blocks weg, und dann stand da „von philipp ·0 s". -->
      <span class="mut">
        {job.akteur ? `${t.vorgang.von} ${job.akteur} · ` : ""}{job.dauer_text}
      </span>
      {#if job.laeuft}
        <!-- Ein Wort, kein Kreisel: Ein sich drehendes Rad sagt „es passiert
             etwas", die Zeilen darunter sagen was. -->
        <span class="puls" aria-hidden="true"></span>
      {/if}
    </header>

    {#if job.gescheitert && job.fehler}
      <p class="fehler" role="alert">{job.fehler}</p>
    {/if}
    {#if job.hinweis}
      <p class="hinweis" role="status">{job.hinweis}</p>
    {/if}
    {#if vorgang.fehler}
      <p class="hinweis">{t.vorgang.stromWeg} {vorgang.fehler}</p>
    {/if}

    {#if vorgang.zeilen.length > 0}
      <!-- tabindex, damit der Kasten mit der Tastatur scrollbar ist: Er ist ein
           eigener Scrollbereich, und ohne Fokus kommt man mit den Pfeiltasten
           nicht hinein. Svelte hält das für falsch, weil das Element nicht
           bedienbar ist — hier ist die Regel es: WCAG 2.1.1 verlangt, dass
           scrollbarer Inhalt mit der Tastatur erreichbar ist, und ein
           Ausgabefenster mit tausend Zeilen ist genau das.
           aria-live="off": Ein Vorleseprogramm soll nicht jede apt-Zeile
           dazwischenreden. Wer den Auszug lesen will, springt hinein. -->
      <!-- svelte-ignore a11y_no_noninteractive_tabindex -->
      <div
        class="auszug"
        bind:this={kasten}
        onscroll={beimScrollen}
        tabindex="0"
        role="log"
        aria-label={t.vorgang.auszug}
        aria-live="off"
      >
        {#each vorgang.zeilen as zeile, i (i)}<div class="zeile">{zeile}</div>{/each}
      </div>
      {#if !amEnde}
        <button type="button" class="zumEnde" onclick={() => { amEnde = true; if (kasten) kasten.scrollTop = kasten.scrollHeight; }}>
          {t.vorgang.zumEnde}
        </button>
      {/if}
    {:else if job.laeuft}
      <p class="mut">{t.vorgang.wartetAufAusgabe}</p>
    {/if}
  </section>
{/if}

<style>
  .vorgang {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 0.8rem 0.85rem;
    display: grid;
    gap: 0.6rem;
    margin-bottom: 1rem;
    min-width: 0;
  }

  header {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    flex-wrap: wrap;
    font-size: 0.84rem;
  }

  header b {
    font-weight: 650;
  }

  .mut {
    color: var(--tx-mut);
    font: 0.78rem var(--mono);
  }

  /* Der Puls ist die einzige Bewegung auf der Seite und deshalb eindeutig: Hier
   * läuft etwas. Bei prefers-reduced-motion steht er still — sichtbar bleibt er,
   * weil er auch ohne Takt ein gefüllter Punkt ist. */
  .puls {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--info);
    animation: pochen 1.4s ease-in-out infinite;
  }

  @keyframes pochen {
    0%,
    100% {
      opacity: 0.25;
    }
    50% {
      opacity: 1;
    }
  }

  .fehler {
    font-size: 0.82rem;
    color: var(--err);
  }

  .hinweis {
    font-size: 0.82rem;
    color: var(--accent);
  }

  .auszug {
    max-height: 18rem;
    overflow: auto;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.5rem 0.6rem;
    font: 0.76rem/1.5 var(--mono);
    color: var(--tx-mut);
  }

  /* Die Zeile bricht nicht um, sie scrollt. Eine umgebrochene apt-Zeile liest
   * sich wie zwei Zeilen, und der Auszug soll dem entsprechen, was im Terminal
   * stand. */
  .zeile {
    white-space: pre;
  }

  .zumEnde {
    justify-self: start;
    background: var(--surface3);
    border: 1px solid var(--line2);
    border-radius: 7px;
    padding: 0.2rem 0.55rem;
    color: var(--tx-mut);
    font: 0.74rem var(--sans);
    cursor: pointer;
  }
</style>
