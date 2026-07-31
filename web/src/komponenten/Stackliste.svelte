<script lang="ts">
  // Die Stackwerkbank: Liste links, Inspektor rechts — dasselbe Muster wie bei
  // den Containern (docs/16-neukonzeption.md §8.4).
  //
  // In dieser Fassung ist sie rein lesend. Der Inspektor zeigt die
  // Compose-Datei als Text und nicht im Editor, und es gibt keinen Knopf, der
  // etwas ändert: Anlegen, Bearbeiten und Starten kommen mit dem nächsten
  // Schritt, zusammen mit dem Compose-Prüfer. Ein Editor ohne Prüfer wäre genau
  // die Reihenfolge, die dieses Modul sich verboten hat.
  //
  // Der Unterschied, der die Seite prägt, ist „verwaltet" gegen „fremd": Nur was
  // unter /opt/asylum/stacks liegt und den Marker trägt, wird das Panel je
  // schreiben. Das steht als Spalte da und nicht als Fußnote — sonst sucht
  // jemand später einen Knopf, den es mit Absicht nicht gibt.
  import Inspektor from "./Inspektor.svelte";
  import { AbgemeldetFehler, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { weg } from "../lib/weg.svelte";
  import type { Stackliste, StackDetail } from "../lib/typen";

  let daten = $state<Stackliste | null>(null);
  let detail = $state<StackDetail | null>(null);
  let fehler = $state("");
  let suche = $state("");

  // Die Auswahl steht in der Adresse: teilbar, überlebt ein Neuladen, und der
  // Zurück-Knopf schließt den Inspektor.
  const gewaehlt = $derived(weg.parameter.stack ?? "");

  async function laden() {
    fehler = "";
    try {
      daten = await api.stacks();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  void laden();

  $effect(() => {
    const name = gewaehlt;
    if (!name) {
      detail = null;
      return;
    }
    if (detail?.name === name) return;
    void (async () => {
      try {
        detail = await api.stackDetail(name);
      } catch (e) {
        if (e instanceof AbgemeldetFehler) throw e;
        fehler = e instanceof Error ? e.message : t.fehler.laden;
      }
    })();
  });

  function waehlen(name: string) {
    weg.setze("stack", name);
  }

  function schliessen() {
    weg.setze("stack", "");
  }

  const gefiltert = $derived(
    (daten?.zeilen ?? []).filter((s) => {
      const q = suche.trim().toLowerCase();
      if (!q) return true;
      return (
        s.name.toLowerCase().includes(q) ||
        s.dienste.some((d) => d.toLowerCase().includes(q))
      );
    }),
  );

  // Der Satz zum Zustand steht an einer Stelle, weil er zweimal gebraucht wird:
  // in der Zeile und über dem Inspektor.
  function zustandssatz(s: { gestartet: boolean; laufend: number; gesamt: number; status: string }) {
    if (!s.gestartet) return t.docker.nieGestartet;
    if (s.gesamt === 0) return s.status;
    return t.docker.vonWieviel(s.laufend, s.gesamt);
  }
</script>

{#if fehler}<p class="warnung" role="alert">{fehler}</p>{/if}
{#if daten?.fehler}<p class="warnung">{daten.fehler}</p>{/if}

{#if !daten}
  <p class="detail">{t.docker.laedt}</p>
{:else if daten.zeilen.length === 0}
  <p class="detail">{t.docker.stacksLeer}</p>
{:else}
  <div class="werkzeuge">
    <input
      type="search"
      class="feld"
      placeholder={t.docker.stacksSuchen}
      aria-label={t.docker.stacksSuchen}
      bind:value={suche}
    />
    <!-- Die Zähler sind hier Auskunft und kein Filter: Bei einer Handvoll
         Stacks wäre ein Filter über vier Zeilen ein Griff, der nichts spart. -->
    <div class="zaehler">
      <span>{t.docker.verwaltet} <b>{daten.zaehler.verwaltet}</b></span>
      <span>{t.docker.fremd} <b>{daten.zaehler.fremd}</b></span>
      <span>{t.docker.auffaellig} <b>{daten.zaehler.auffaellig}</b></span>
    </div>
  </div>

  <div class="werkbank" class:allein={!gewaehlt}>
    <div class="tabelle-rahmen">
      <table class="tabelle">
        <thead>
          <tr>
            <th>{t.docker.spalteName}</th>
            <th>{t.docker.spalteDienste}</th>
            <th>{t.docker.spalteStatus}</th>
            <th>{t.docker.spalteHerkunft}</th>
          </tr>
        </thead>
        <tbody>
          {#each gefiltert as s (s.name)}
            <tr class:gewaehlt={s.name === gewaehlt} onclick={() => waehlen(s.name)}>
              <td data-spalte={t.docker.spalteName}>
                <button type="button" class="verweis">{s.name}</button>
              </td>
              <td data-spalte={t.docker.spalteDienste}>
                {s.dienste.length ? s.dienste.join(" · ") : "—"}
              </td>
              <td data-spalte={t.docker.spalteStatus}>
                <!-- Der Punkt gehört dazu: .zustand färbt ein <i>, nicht den
                     Text. Ohne ihn stünde der Zustand da wie jede andere
                     Spalte. -->
                <span class="zustand {s.zustand_stufe}"><i></i>{zustandssatz(s)}</span>
              </td>
              <td data-spalte={t.docker.spalteHerkunft}>
                {s.verwaltet ? t.docker.verwaltet : t.docker.fremd}
              </td>
            </tr>
          {:else}
            <tr><td colspan="4">{t.docker.stacksNichts}</td></tr>
          {/each}
        </tbody>
      </table>
    </div>

    {#if gewaehlt && detail}
      <Inspektor
        titel={detail.name}
        zustand={detail.zustand_stufe}
        zustandText={zustandssatz(detail)}
        marke={detail.verwaltet ? t.docker.verwaltet : t.docker.fremd}
        {schliessen}
      >
        {#snippet kinder()}
          {#if !detail.verwaltet}
            <p class="hinweis">{t.docker.fremdWarum}</p>
          {/if}

          <dl class="paare">
            <dt>{t.docker.stackDatei}</dt>
            <dd class="mono">{detail.datei || "—"}</dd>
            <dt>{t.docker.spalteStatus}</dt>
            <dd>{detail.status || "—"}</dd>
            {#if detail.dienste.length}
              <dt>{t.docker.spalteDienste}</dt>
              <dd>{detail.dienste.join(" · ")}</dd>
            {/if}
          </dl>

          <h3>{t.docker.stackContainer}</h3>
          {#if detail.container.length === 0}
            <p class="detail">{t.docker.keineContainer}</p>
          {:else}
            <ul class="dienste">
              {#each detail.container as c (c.id)}
                <li>
                  <span class="zustand {c.zustand_stufe}"><i></i>{c.dienst || c.name}</span>
                  <span class="mono">{c.image}</span>
                  <span class="leise-marke">{c.status || c.zustand}</span>
                </li>
              {/each}
            </ul>
          {/if}

          <h3>{t.docker.stackDatei}</h3>
          {#if detail.fehler}
            <p class="detail">{t.docker.keineDatei}</p>
          {:else}
            {#if detail.gekuerzt}
              <p class="hinweis">{t.docker.stackGekuerzt}</p>
            {/if}
            <pre class="auszug">{detail.text}</pre>
          {/if}
        {/snippet}
      </Inspektor>
    {/if}
  </div>
{/if}

<style>
  .werkzeuge {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
    margin-bottom: 0.75rem;
  }

  .zaehler {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    font-size: 0.78rem;
    color: var(--tx-mut);
  }

  .zaehler b {
    color: var(--tx);
  }

  .verweis {
    background: none;
    border: 0;
    padding: 0;
    color: inherit;
    font: inherit;
    cursor: pointer;
    text-align: left;
  }

  h3 {
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
    margin-top: 1rem;
  }

  .dienste {
    list-style: none;
    display: grid;
    gap: 0.35rem;
    font-size: 0.8rem;
  }

  .leise-marke {
    color: var(--tx-faint);
    font-size: 0.72rem;
    margin-left: 0.4rem;
  }

  .auszug {
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.6rem 0.7rem;
    font: 0.76rem var(--mono);
    color: var(--tx-mut);
    max-height: 22rem;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
  }
</style>
