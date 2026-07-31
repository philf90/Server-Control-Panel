<script lang="ts">
  // Die Containerwerkbank: Liste links, Inspektor rechts — das Muster aus
  // docs/16-neukonzeption.md §8.4, festgelegt am Modul Dienste.
  //
  // Drei Dinge kommen fertig vom Server und werden hier NICHT nachgerechnet:
  // die Zustandsstufe (die Farbe), die Auffälligkeit und die Handgriffe, die
  // zum Zustand passen. Rechnete der Browser sie nach, gäbe es zwei Auslegungen
  // davon, was „auffällig" heißt — und die Übersicht nähme die andere.
  import Inspektor from "./Inspektor.svelte";
  import Rueckfrage from "./Rueckfrage.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { weg } from "../lib/weg.svelte";
  import type { Bestaetigung, Containerliste, ContainerDetail } from "../lib/typen";

  let daten = $state<Containerliste | null>(null);
  let detail = $state<ContainerDetail | null>(null);
  let fehler = $state("");
  let meldung = $state("");
  let suche = $state("");
  let stufe = $state("alle");
  let laufendeAktion = $state("");

  let offeneFrage = $state<{
    frage: Bestaetigung;
    tun: (getippt: string) => Promise<void>;
  } | null>(null);

  // Die Auswahl steht in der Adresse: Ein Verweis auf einen bestimmten Container
  // ist damit teilbar, ein Neuladen zeigt denselben Zustand, und der
  // Zurück-Knopf schließt den Inspektor.
  const gewaehlt = $derived(weg.parameter.container ?? "");

  async function laden() {
    fehler = "";
    try {
      daten = await api.container();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  void laden();

  $effect(() => {
    const id = gewaehlt;
    if (!id) {
      detail = null;
      return;
    }
    // Überspringen, wenn das Detail schon steht: Sonst blitzt der Inspektor nach
    // jeder Aktion auf, weil die Antwort ihn ohnehin mitbringt.
    if (detail?.id === id) return;
    void (async () => {
      try {
        detail = await api.containerDetail(id);
      } catch (e) {
        if (e instanceof AbgemeldetFehler) throw e;
        fehler = e instanceof Error ? e.message : t.fehler.laden;
      }
    })();
  });

  function waehlen(id: string) {
    weg.setze("container", id);
  }

  function schliessen() {
    weg.setze("container", "");
  }

  const gefiltert = $derived(
    (daten?.zeilen ?? []).filter((c) => {
      if (stufe === "laufend" && c.zustand !== "running") return false;
      if (stufe === "gestoppt" && (c.zustand === "running" || c.zustand === "paused")) return false;
      if (stufe === "auffaellig" && !c.auffaellig) return false;
      const q = suche.trim().toLowerCase();
      if (!q) return true;
      return (
        c.name.toLowerCase().includes(q) ||
        c.image.toLowerCase().includes(q) ||
        c.stack.toLowerCase().includes(q)
      );
    }),
  );

  async function ausfuehren(aktion: string, bestaetigt: boolean, getippt: string) {
    if (!detail) return;
    laufendeAktion = aktion;
    fehler = "";
    meldung = "";
    try {
      const antwort = await api.containerAktion(detail.id, aktion, bestaetigt, getippt);
      offeneFrage = null;
      meldung = antwort.meldung;
      if (antwort.detail) {
        detail = antwort.detail;
      } else {
        // Beim Entfernen gibt es kein Detail mehr — den Container gibt es nicht
        // mehr. Der Inspektor schließt, statt einen Zustand zu zeigen, den
        // niemand mehr abfragen kann.
        schliessen();
      }
      await laden();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      if (e instanceof BestaetigungNoetig) {
        offeneFrage = {
          frage: e.bestaetigung,
          tun: (getippt: string) => ausfuehren(aktion, true, getippt),
        };
        return;
      }
      offeneFrage = null;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      laufendeAktion = "";
    }
  }

  const darfAendern = $derived(daten?.darf_aendern ?? false);
</script>

{#if fehler}<p class="warnung" role="alert">{fehler}</p>{/if}
{#if daten?.fehler}<p class="warnung">{daten.fehler}</p>{/if}
{#if meldung}<p class="meldung" role="status">{meldung}</p>{/if}

{#if !daten}
  <p class="detail">{t.docker.laedt}</p>
{:else if daten.zeilen.length === 0}
  <p class="detail">{t.docker.keine}</p>
{:else}
  <div class="werkzeuge">
    <input
      type="search"
      class="feld"
      placeholder={t.docker.suchen}
      aria-label={t.docker.suchen}
      bind:value={suche}
    />
    <!-- Die Zähler sind die Filter. Grundsatz II: Jede Zahl ist ein Griff. -->
    <div class="stufen">
      {#each [["alle", t.docker.alle, daten.zaehler.alle], ["laufend", t.docker.laufend, daten.zaehler.laufend], ["gestoppt", t.docker.gestoppt, daten.zaehler.gestoppt], ["auffaellig", t.docker.auffaellig, daten.zaehler.auffaellig]] as [wert, label, zahl] (wert)}
        <button
          type="button"
          class="stufe"
          class:aktiv={stufe === wert}
          onclick={() => (stufe = String(wert))}
        >
          {label} <b>{zahl}</b>
        </button>
      {/each}
    </div>
  </div>

  <div class="werkbank" class:allein={!gewaehlt}>
    <div class="tabelle-rahmen">
      <table class="tabelle">
        <thead>
          <tr>
            <th>{t.docker.spalteName}</th>
            <th>{t.docker.spalteImage}</th>
            <th>{t.docker.spalteStack}</th>
            <th>{t.docker.spalteZustand}</th>
            <th>{t.docker.spaltePorts}</th>
          </tr>
        </thead>
        <tbody>
          {#each gefiltert as c (c.id)}
            <tr class:gewaehlt={c.id === gewaehlt} onclick={() => waehlen(c.id)}>
              <td data-spalte={t.docker.spalteName}>
                <button type="button" class="verweis">{c.name}</button>
              </td>
              <td data-spalte={t.docker.spalteImage}><span class="mono">{c.image}</span></td>
              <td data-spalte={t.docker.spalteStack}>{c.stack || "—"}</td>
              <td data-spalte={t.docker.spalteZustand}>
                <!-- Der Punkt gehört dazu: Die Klasse .zustand färbt ein <i>,
                     nicht den Text. Ohne ihn stünde der Zustand da wie jede
                     andere Spalte, und „Farbe trägt Zustand" gälte hier nicht. -->
                <span class="zustand {c.zustand_stufe}"><i></i>{c.status || c.zustand}</span>
              </td>
              <td data-spalte={t.docker.spaltePorts}><span class="mono">{c.ports || "—"}</span></td>
            </tr>
          {:else}
            <tr><td colspan="5">{t.docker.nichts}</td></tr>
          {/each}
        </tbody>
      </table>
    </div>

    {#if gewaehlt && detail}
      <Inspektor
        titel={detail.name}
        zustand={detail.zustand_stufe}
        zustandText={detail.status || detail.zustand}
        marke={detail.kurz}
        {schliessen}
      >
        {#snippet kinder()}
          {#if detail.privilegiert}
            <!-- Die Angabe, die auf dieser Seite am meisten zählt. Sie steht
                 oben und nicht in der Liste der Paare: Ein privilegierter
                 Container ist root auf dem Wirt. -->
            <p class="warnung">{t.docker.privilegiertWarum}</p>
          {/if}

          <dl class="paare">
            <dt>{t.docker.spalteImage}</dt>
            <dd class="mono">{detail.image}</dd>
            {#if detail.stack}
              <dt>{t.docker.spalteStack}</dt>
              <dd>{detail.stack} · {detail.dienst}</dd>
            {/if}
            <dt>{t.docker.neustartregel}</dt>
            <dd>{detail.neustartregel || "no"}</dd>
            {#if detail.exit_code >= 0}
              <dt>{t.docker.exitCode}</dt>
              <dd class="mono">{detail.exit_code}</dd>
            {/if}
            {#if detail.gesundheit}
              <dt>Health</dt>
              <dd>{detail.gesundheit}</dd>
            {/if}
            {#if detail.benutzer}
              <dt>{t.docker.benutzer}</dt>
              <dd class="mono">{detail.benutzer}</dd>
            {/if}
            {#if detail.befehl}
              <dt>{t.docker.befehl}</dt>
              <dd class="mono">{detail.befehl}</dd>
            {/if}
            <dt>{t.docker.umgebung}</dt>
            <dd title={t.docker.umgebungWarum}>{detail.umgebung}</dd>
            {#if detail.netze.length}
              <dt>{t.docker.netze}</dt>
              <dd>{detail.netze.join(" · ")}</dd>
            {/if}
          </dl>

          {#if detail.stats}
            <h3>{t.docker.stats}</h3>
            <dl class="paare">
              <dt>{t.docker.cpu}</dt>
              <dd class="mono">{detail.stats.cpu}</dd>
              <dt>{t.docker.speicher}</dt>
              <dd class="mono">{detail.stats.speicher} ({detail.stats.speicher_prozent})</dd>
              <dt>{t.docker.netz}</dt>
              <dd class="mono">{detail.stats.netz}</dd>
              <dt>{t.docker.platte}</dt>
              <dd class="mono">{detail.stats.platte}</dd>
              <dt>{t.docker.prozesse}</dt>
              <dd class="mono">{detail.stats.pids}</dd>
            </dl>
          {/if}

          {#if detail.mounts.length}
            <h3>{t.docker.mounts}</h3>
            <ul class="mounts">
              {#each detail.mounts as m (m.ziel)}
                <li>
                  <span class="art">{m.bind ? t.docker.bind : t.docker.volume}</span>
                  <span class="mono">{m.quelle} → {m.ziel}</span>
                  {#if !m.schreibbar}<span class="leise-marke">{t.docker.nurLesen}</span>{/if}
                </li>
              {/each}
            </ul>
          {/if}

          {#if darfAendern}
            <div class="aktionen">
              {#each detail.aktionen as a (a)}
                <button
                  type="button"
                  class="knopf {a === 'remove' ? 'gefahr' : 'leise'} klein"
                  disabled={laufendeAktion !== ""}
                  onclick={() => ausfuehren(a, false, "")}
                >
                  {t.docker.aktion[a] ?? a}
                </button>
              {/each}
            </div>
          {:else}
            <p class="hinweis">{t.docker.nurOwner}</p>
          {/if}

          <h3>{t.docker.protokoll}</h3>
          {#if detail.zeilen.length === 0}
            <p class="detail">{t.docker.keinProtokoll}</p>
          {:else}
            <pre class="auszug">{detail.zeilen.join("\n")}</pre>
          {/if}
        {/snippet}
      </Inspektor>
    {/if}
  </div>
{/if}

{#if offeneFrage}
  <Rueckfrage
    frage={offeneFrage.frage}
    laeuft={laufendeAktion !== ""}
    bestaetigen={(getippt) => offeneFrage?.tun(getippt) ?? Promise.resolve()}
    abbrechen={() => (offeneFrage = null)}
  />
{/if}

<style>
  .werkzeuge {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
    margin-bottom: 0.75rem;
  }

  .stufen {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
  }

  .stufe {
    background: var(--surface);
    border: 1px solid var(--line);
    color: var(--tx-mut);
    border-radius: 999px;
    padding: 0.25rem 0.7rem;
    font-size: 0.78rem;
    cursor: pointer;
  }

  .stufe.aktiv {
    color: var(--tx);
    border-color: var(--akzent);
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

  .mounts {
    list-style: none;
    display: grid;
    gap: 0.35rem;
    font-size: 0.8rem;
  }

  .mounts .art {
    color: var(--tx-faint);
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-right: 0.4rem;
  }

  .leise-marke {
    color: var(--tx-faint);
    font-size: 0.72rem;
    margin-left: 0.4rem;
  }

  .aktionen {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    margin-top: 1rem;
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
