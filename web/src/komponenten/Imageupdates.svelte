<script lang="ts">
  // Die Update-Prüfung für Images.
  //
  // Sie ist eine AUSKUNFT und kein Automat (Entscheidung E5 in
  // docs/17-docker.md): Das Panel sagt, dass es etwas Neues gibt, und der
  // Mensch drückt den Knopf. Ein Panel, das nachts von allein Images tauscht,
  // ist ein Panel, das nachts von allein etwas kaputt macht.
  //
  // Drei Zahlen und nicht zwei, und die dritte ist die wichtigste: „nicht
  // geprüft" ist weder „aktuell" noch „veraltet". Wo kein belastbarer Vergleich
  // zustande kam — Mehrarchitektur ohne buildx, ein selbst gebautes Image, die
  // Ratengrenze —, sagt die Fläche das, statt zu raten. Eine Prüfung, die im
  // Zweifel Alarm schlägt, schlägt ihn bei fast jedem Image und wird nach einer
  // Woche nicht mehr gelesen.
  //
  // Der Griff ist der STACK und nicht das Image: „docker pull" allein ändert
  // nichts an dem, was läuft. Deshalb steht neben einer Zeile mit neuer Fassung
  // „Stack aktualisieren" — das ist pull und up in einem Vorgang.
  import Rueckfrage from "./Rueckfrage.svelte";
  import Vorgangsplatte from "./Vorgangsplatte.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { Vorgang } from "../lib/vorgang.svelte";
  import type { Imageupdates, Bestaetigung } from "../lib/typen";

  let daten = $state<Imageupdates | null>(null);
  let fehler = $state("");
  let meldung = $state("");
  let arbeitet = $state("");

  let offeneFrage = $state<{
    frage: Bestaetigung;
    tun: (getippt: string) => Promise<void>;
  } | null>(null);

  const pruefung = new Vorgang("docker-updates");
  const stackvorgang = new Vorgang("docker-stack");

  $effect(() => () => {
    pruefung.loesen();
    stackvorgang.loesen();
  });

  async function laden() {
    fehler = "";
    try {
      const frisch = await api.imageupdates();
      daten = frisch;
      pruefung.setzen(frisch.job);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  void laden();

  // Nach dem Ende des Prüflaufs neu holen: Was gefunden wurde, sagt der Server.
  let liefZuvor = $state(false);
  $effect(() => {
    const laeuft = pruefung.job?.laeuft ?? false;
    if (liefZuvor && !laeuft) void laden();
    liefZuvor = laeuft;
  });

  async function pruefen() {
    arbeitet = "pruefen";
    fehler = "";
    meldung = "";
    try {
      const { job } = await api.updatePruefungStarten();
      pruefung.setzen(job);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      arbeitet = "";
    }
  }

  async function aktualisieren(stack: string, bestaetigt = false, getippt = "") {
    arbeitet = stack;
    fehler = "";
    meldung = "";
    try {
      const { job, meldung: satz } = await api.stackAktion(
        stack,
        "update",
        false,
        bestaetigt,
        getippt,
      );
      offeneFrage = null;
      meldung = satz;
      stackvorgang.setzen(job);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      if (e instanceof BestaetigungNoetig) {
        offeneFrage = {
          frage: e.bestaetigung,
          tun: (wort: string) => aktualisieren(stack, true, wort),
        };
        return;
      }
      offeneFrage = null;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      arbeitet = "";
    }
  }

  const darfAendern = $derived(daten?.darf_aendern ?? false);
  const laeuft = $derived(
    (pruefung.job?.laeuft ?? false) || (stackvorgang.job?.laeuft ?? false),
  );
</script>

{#if fehler}<p class="warnung" role="alert">{fehler}</p>{/if}
{#if daten?.fehler}<p class="warnung">{daten.fehler}</p>{/if}
{#if meldung}<p class="meldung" role="status">{meldung}</p>{/if}

<p class="detail">{t.docker.updatesWesen}</p>

<Vorgangsplatte vorgang={pruefung} />
<Vorgangsplatte vorgang={stackvorgang} />

{#if !daten}
  <p class="detail">{t.docker.laedt}</p>
{:else}
  <div class="werkzeuge">
    {#if daten.geprueft}
      <span class="zaehler">
        <span>{t.docker.updatesNeu} <b>{daten.neu}</b></span>
        <span>{t.docker.updatesAktuell} <b>{daten.aktuell}</b></span>
        <span>{t.docker.updatesUngeprueft} <b>{daten.ungeprueft}</b></span>
      </span>
      <span class="leise">{t.docker.updatesGeprueftAm} {daten.geprueft}</span>
    {/if}
    <div class="schub"></div>
    {#if darfAendern}
      <!-- Der Knopf bleibt sichtbar, wenn die Ratengrenze greift — nur gesperrt
           und mit dem Zeitpunkt daneben. Ein verschwundener Knopf wäre ein
           Rätsel; ein gesperrter mit Grund ist eine Auskunft. -->
      <button
        type="button"
        class="knopf klein"
        disabled={!daten.darf_pruefen || arbeitet !== "" || laeuft}
        onclick={pruefen}
      >
        {t.docker.updatesPruefen}
      </button>
    {/if}
  </div>

  {#if daten.naechste_fruehestens}
    <p class="hinweis">{t.docker.updatesWiederAb} {daten.naechste_fruehestens}</p>
  {/if}

  {#if !daten.geprueft}
    <p class="detail">{t.docker.updatesNieGeprueft}</p>
  {:else}
    {#if daten.ungeprueft > 0}
      <p class="hinweis">{t.docker.updatesUngeprueftWarum}</p>
    {/if}

    <div class="tabelle-rahmen">
      <table class="tabelle">
        <thead>
          <tr>
            <th>{t.docker.spalteImageRef}</th>
            <th>{t.docker.spalteStand}</th>
            <th>{t.docker.spalteGebrauch}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {#each daten.zeilen as z (z.ref)}
            <tr>
              <td data-spalte={t.docker.spalteImageRef}>
                <span class="mono">{z.ref}</span>
              </td>
              <td data-spalte={t.docker.spalteStand}>
                <!-- Drei Zustände, drei Farben — und „nicht geprüft" ist eine
                     eigene Aussage und keine Abwesenheit. Der Punkt gehört
                     dazu: .zustand färbt ein <i>, nicht den Text. -->
                {#if !z.geprueft}
                  <span class="zustand warn" title={z.grund}>
                    <i></i>{t.docker.updatesUngeprueft}
                  </span>
                {:else if z.neu}
                  <span class="zustand schlecht">
                    <i></i>{t.docker.updatesNeu}
                  </span>
                  {#if z.lokal_kurz && z.fern_kurz}
                    <span class="leise mono">{z.lokal_kurz} → {z.fern_kurz}</span>
                  {/if}
                {:else}
                  <span class="zustand gut"><i></i>{t.docker.updatesAktuell}</span>
                {/if}
              </td>
              <td data-spalte={t.docker.spalteGebrauch}>
                {#if z.stacks.length}
                  {z.stacks.join(" · ")}
                {:else if z.container.length}
                  <span class="leise">{z.container.join(" · ")}</span>
                {:else}
                  —
                {/if}
              </td>
              <td data-spalte="">
                {#if z.neu && darfAendern && z.stacks.length}
                  {#each z.stacks as stack (stack)}
                    <button
                      type="button"
                      class="knopf leise klein"
                      disabled={arbeitet !== "" || laeuft}
                      onclick={() => aktualisieren(stack)}
                    >
                      {t.docker.updatesAktualisieren}
                    </button>
                  {/each}
                {:else if z.neu && z.stacks.length === 0}
                  <span class="leise">{t.docker.updatesKeinGriff}</span>
                {/if}
              </td>
            </tr>
          {/each}
        </tbody>
      </table>
    </div>
  {/if}
{/if}

{#if offeneFrage}
  <Rueckfrage
    frage={offeneFrage.frage}
    laeuft={arbeitet !== ""}
    bestaetigen={(getippt) => offeneFrage?.tun(getippt) ?? Promise.resolve()}
    abbrechen={() => (offeneFrage = null)}
  />
{/if}

<style>
  .werkzeuge {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
    margin-bottom: 0.6rem;
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

  .leise {
    color: var(--tx-faint);
    font-size: 0.76rem;
    margin-left: 0.4rem;
  }
</style>
