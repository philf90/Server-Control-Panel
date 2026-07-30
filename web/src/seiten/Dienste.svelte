<script lang="ts">
  // Dienste: Liste links, Inspektor rechts, Auswahl in der Adresse.
  //
  // Das erste Modul der neuen Oberfläche neben der Übersicht — und damit die
  // Form, die die weiteren übernehmen. Drei Entscheidungen tragen sie:
  //
  //  1. Die Liste kommt einmal und wird im Browser gefiltert. Beim Tippen ist
  //     das Ergebnis sofort da, statt einmal pro Buchstabe systemctl zu rufen.
  //  2. Die Auswahl steht in der Adresse (?unit=…). Ein Verweis auf einen
  //     bestimmten Dienst ist damit teilbar, und der Zurück-Knopf schließt den
  //     Inspektor statt die Seite zu verlassen.
  //  3. Aktionen antworten mit dem NEU GELESENEN Zustand. Die Oberfläche rät
  //     nach einem Neustart nichts — sie zeigt, was der Server gerade sieht.
  import Inspektor from "../komponenten/Inspektor.svelte";
  import Rueckfrage from "../komponenten/Rueckfrage.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { normal } from "../lib/ziele";
  import { weg } from "../lib/weg.svelte";
  import type {
    Bestaetigung,
    Dienst,
    DienstAktion,
    DienstDetail,
    Dienste,
    Zustand,
  } from "../lib/typen";

  let { darfSchreiben = false }: { darfSchreiben?: boolean } = $props();

  let daten = $state<Dienste | null>(null);
  let fehler = $state("");
  let filter = $state("");
  let nurZustand = $state<Zustand | "">("");

  let detail = $state<DienstDetail | null>(null);
  let detailFehler = $state("");
  let detailLaeuft = $state(false);
  let aktionLaeuft = $state("");
  let meldung = $state("");
  // offeneFrage hält die Rückfrage des Servers zusammen mit der Aktion, auf die
  // sie sich bezieht. Beides zusammen, weil ein Dialog, der nicht weiß, was er
  // bestätigt, nichts ausführen kann.
  let offeneFrage = $state<{ frage: Bestaetigung; aktion: DienstAktion } | null>(null);

  const gewaehlt = $derived(weg.parameter.unit ?? "");

  const gefiltert = $derived.by(() => {
    const alle = daten?.dienste ?? [];
    const b = normal(filter.trim());
    return alle.filter((d) => {
      if (nurZustand && d.zustand !== nurZustand) return false;
      if (!b) return true;
      // Auch in der Beschreibung suchen: Wer „web" tippt, sucht nginx, und der
      // Name der Unit sagt das nicht.
      return normal(d.name).includes(b) || normal(d.beschreibung).includes(b);
    });
  });

  async function laden() {
    fehler = "";
    try {
      daten = await api.dienste();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  // Das Detail folgt der Adresse und nicht dem Klick. Damit lädt ein Neuladen
  // auf ?unit=nginx.service denselben Zustand, und der Zurück-Knopf wirkt.
  $effect(() => {
    const unit = gewaehlt;
    if (!unit) {
      detail = null;
      detailFehler = "";
      return;
    }
    // Schon geladen? Dann nicht erneut fragen — sonst blitzt der Inspektor nach
    // jeder Aktion auf, weil die Antwort der Aktion das Detail schon setzt.
    if (detail?.unit === unit) return;
    detailHolen(unit);
  });

  async function detailHolen(unit: string) {
    detailLaeuft = true;
    detailFehler = "";
    try {
      detail = await api.dienst(unit);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      detail = null;
      detailFehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      detailLaeuft = false;
    }
  }

  function waehlen(unit: string) {
    meldung = "";
    weg.setze("unit", unit);
  }

  function schliessen() {
    meldung = "";
    weg.setze("unit", "");
  }

  async function ausfuehren(aktion: DienstAktion, bestaetigt = false, getippt = "") {
    if (!detail) return;
    const unit = detail.unit;
    aktionLaeuft = aktion;
    meldung = "";
    detailFehler = "";
    try {
      const antwort = await api.dienstAktion(unit, aktion, bestaetigt, getippt);
      offeneFrage = null;
      meldung = antwort.meldung;
      if (antwort.detail.unit) detail = antwort.detail;
      // Die Liste danach neu holen: Zustand und Zähler haben sich geändert, und
      // eine Liste, die den alten Zustand zeigt, ist schlimmer als eine, die
      // kurz nichts zeigt — sie sieht richtig aus.
      await laden();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      if (e instanceof BestaetigungNoetig) {
        // Auch der zweite Anlauf kann zurückkommen: bei Stufe 3, wenn das
        // getippte Wort nicht passte. Dann trägt die Frage das Feld `fehler`
        // und der Dialog bleibt stehen.
        offeneFrage = { frage: e.bestaetigung, aktion };
        return;
      }
      detailFehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      aktionLaeuft = "";
    }
  }

  function zustandKlasse(z: Zustand): string {
    switch (z) {
      case zustandLaeuft:
        return "gut";
      case zustandGescheitert:
        return "schlecht";
      default:
        return "";
    }
  }

  // Die drei Wörter des Servers als Konstanten, damit ein Tippfehler im
  // Vergleich nicht still zu „kein Treffer" wird.
  const zustandLaeuft = "laeuft";
  const zustandGescheitert = "gescheitert";

  function zustandText(z: Zustand): string {
    return t.dienste.zustand[z];
  }

  laden();
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.system} / {t.ziele.dienste}</div>
    <div class="h1">{t.ziele.dienste}</div>
  </div>
  <div class="schub"></div>
  {#if daten}
    <span class="marke">
      {t.dienste.units(daten.zaehler.gesamt)}
      {#if daten.zaehler.gescheitert > 0}
        · {t.dienste.gescheiterte(daten.zaehler.gescheitert)}
      {/if}
    </span>
  {/if}
</div>

{#if fehler}
  <div class="hinweis">
    <p>{t.fehler.laden}</p>
    <p class="detail">{fehler}</p>
    <button class="knopf" onclick={laden}>{t.fehler.erneut}</button>
  </div>
{:else if !daten}
  <p class="detail">{t.dienste.laedt}</p>
{:else}
  <div class="werkzeuge">
    <label class="suche">
      <span class="nur-vorlese">{t.dienste.suchen}</span>
      <input
        bind:value={filter}
        type="search"
        placeholder={t.dienste.suchen}
        autocomplete="off"
        spellcheck="false"
      />
    </label>

    <!-- Die Zähler sind die Filter. Eine Zahl, die man ansieht und nicht
         anklicken kann, ist eine verschenkte Handhabe — Grundsatz II aus
         docs/15-neuordnung.md: jede Zahl ist ein Griff. -->
    <div class="stufen" role="group" aria-label={t.dienste.filtern}>
      <button
        type="button"
        class:an={nurZustand === ""}
        onclick={() => (nurZustand = "")}
      >
        {t.dienste.alle} <b>{daten.zaehler.gesamt}</b>
      </button>
      <button
        type="button"
        class:an={nurZustand === "gescheitert"}
        onclick={() => (nurZustand = nurZustand === "gescheitert" ? "" : "gescheitert")}
      >
        {t.dienste.zustand.gescheitert} <b>{daten.zaehler.gescheitert}</b>
      </button>
      <button
        type="button"
        class:an={nurZustand === "laeuft"}
        onclick={() => (nurZustand = nurZustand === "laeuft" ? "" : "laeuft")}
      >
        {t.dienste.zustand.laeuft} <b>{daten.zaehler.laeuft}</b>
      </button>
      <button
        type="button"
        class:an={nurZustand === "aus"}
        onclick={() => (nurZustand = nurZustand === "aus" ? "" : "aus")}
      >
        {t.dienste.zustand.aus} <b>{daten.zaehler.aus}</b>
      </button>
    </div>
  </div>

  <div class="werkbank" class:allein={!gewaehlt}>
    <div class="tabelle-rahmen">
      <table class="tabelle">
        <thead>
          <tr>
            <th>{t.dienste.unit}</th>
            <th>{t.dienste.zustandSpalte}</th>
            <th>{t.dienste.autostart}</th>
            <th>{t.dienste.beschreibung}</th>
          </tr>
        </thead>
        <tbody>
          {#if gefiltert.length === 0}
            <tr>
              <td colspan="4" class="gedaempft">{t.dienste.nichts}</td>
            </tr>
          {:else}
            {#each gefiltert as dienst (dienst.unit)}
              <tr class:gewaehlt={dienst.unit === gewaehlt}>
                <td data-spalte={t.dienste.unit}>
                  <!-- Ein Knopf und kein Verweis: Das Ziel ist dieselbe Seite mit
                       einem anderen Abfrageparameter, und den setzt weg.setze —
                       damit die erste Auswahl ein Schritt im Verlauf ist und der
                       Wechsel zur nächsten keiner. -->
                  <button type="button" class="zeile" onclick={() => waehlen(dienst.unit)}>
                    {dienst.name}
                  </button>
                </td>
                <td data-spalte={t.dienste.zustandSpalte}>
                  <span class="zustand {zustandKlasse(dienst.zustand)}">
                    <i aria-hidden="true"></i>{zustandText(dienst.zustand)}
                  </span>
                </td>
                <!-- Ein Strich statt einer leeren Zelle: In der Kartenansicht
                     unter 600 px stünde sonst eine Beschriftung ohne Wert, und
                     das sieht wie ein Fehler beim Laden aus. -->
                <td data-spalte={t.dienste.autostart} class="gedaempft">
                  {dienst.autostart || "—"}
                </td>
                <td data-spalte={t.dienste.beschreibung} class="gedaempft">
                  {dienst.beschreibung || "—"}
                </td>
              </tr>
            {/each}
          {/if}
        </tbody>
      </table>
    </div>

    {#if gewaehlt}
      <Inspektor
        titel={detail?.unit ?? gewaehlt}
        zustand={detail ? zustandKlasse(detail.zustand) : ""}
        zustandText={detail ? zustandText(detail.zustand) : ""}
        marke={detail?.autostart ?? ""}
        {schliessen}
      >
        {#snippet kinder()}
          {#if detailLaeuft && !detail}
            <p class="detail">{t.dienste.laedt}</p>
          {:else if detailFehler && !detail}
            <p class="detail warnung">{detailFehler}</p>
            <button class="knopf leise" onclick={() => detailHolen(gewaehlt)}>
              {t.fehler.erneut}
            </button>
          {:else if detail}
            <dl class="kv">
              <dt>{t.dienste.beschreibung}</dt>
              <dd>{detail.beschreibung || "—"}</dd>
              <dt>{t.dienste.zustandSpalte}</dt>
              <!-- Die rohen systemd-Wörter, verbunden nur wenn beide da sind:
                   Ein Trennstrich ohne zweiten Teil sieht wie ein fehlender
                   Wert aus. Bei manchen Units ist Sub leer. -->
              <dd>{[detail.aktiv, detail.unterzustand].filter(Boolean).join(" · ")}</dd>
              {#if detail.seit}
                <dt>{t.dienste.seit}</dt>
                <dd>{detail.seit}</dd>
              {/if}
              {#if detail.haupt_pid > 0}
                <dt>{t.dienste.pid}</dt>
                <dd class="zahl">{detail.haupt_pid}{#if detail.aufgaben > 0} · {t.dienste.aufgaben(detail.aufgaben)}{/if}</dd>
              {/if}
              {#if detail.speicher}
                <dt>{t.dienste.speicher}</dt>
                <dd class="zahl">{detail.speicher}</dd>
              {/if}
              {#if detail.unit_datei}
                <dt>{t.dienste.unitDatei}</dt>
                <dd class="pfad">{detail.unit_datei}</dd>
              {/if}
            </dl>

            {#if meldung}
              <p class="meldung" role="status">{meldung}</p>
            {/if}
            {#if detailFehler}
              <p class="warnung" role="alert">{detailFehler}</p>
            {/if}

            {#if darfSchreiben}
              <div class="aktionen">
                {#each detail.aktionen as aktion (aktion)}
                  <button
                    type="button"
                    class="knopf"
                    class:leise={aktion !== "start" && aktion !== "restart"}
                    class:gefahr={aktion === "stop"}
                    disabled={aktionLaeuft !== ""}
                    onclick={() => ausfuehren(aktion)}
                  >
                    {aktionLaeuft === aktion ? t.dienste.laeuft : t.dienste.aktion[aktion]}
                  </button>
                {/each}
              </div>
            {:else}
              <!-- Die Knöpfe fehlen zu lassen, ohne zu sagen warum, sieht wie
                   ein halb gebautes Modul aus. -->
              <p class="detail">{t.dienste.nurLesen}</p>
            {/if}

            <div class="journal">
              <b>{t.dienste.journal}</b>
              {#if detail.logzeilen.length === 0}
                <p class="detail">{t.dienste.keineZeilen}</p>
              {:else}
                <ol>
                  {#each detail.logzeilen as zeile, i (`${zeile.at}-${i}`)}
                    <li class:ernst={zeile.ernst}>
                      <span class="zeit">{zeile.at}</span>
                      <span class="text">{zeile.nachricht}</span>
                    </li>
                  {/each}
                </ol>
              {/if}
            </div>
          {/if}
        {/snippet}
      </Inspektor>
    {/if}
  </div>
{/if}

{#if offeneFrage}
  <Rueckfrage
    frage={offeneFrage.frage}
    laeuft={aktionLaeuft !== ""}
    bestaetigen={(getippt) => ausfuehren(offeneFrage!.aktion, true, getippt)}
    abbrechen={() => (offeneFrage = null)}
  />
{/if}

<style>
  .werkzeuge {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    flex-wrap: wrap;
    margin-bottom: 0.8rem;
  }

  .suche input {
    background: var(--surface);
    border: 1px solid var(--line2);
    border-radius: 8px;
    padding: 0.35rem 0.7rem;
    color: var(--tx);
    font: 0.84rem var(--sans);
    width: 15rem;
    max-width: 100%;
  }

  .stufen {
    display: flex;
    gap: 0.35rem;
    flex-wrap: wrap;
  }

  .stufen button {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 7px;
    padding: 0.3rem 0.6rem;
    color: var(--tx-mut);
    font: 0.75rem var(--sans);
    cursor: pointer;
  }

  .stufen button b {
    font-family: var(--mono);
    font-variant-numeric: tabular-nums;
    color: var(--tx);
    margin-left: 0.2rem;
  }

  .stufen button.an {
    border-color: var(--accent-dim);
    color: var(--accent);
  }

  /* Die Zeile ist der Griff. Ein Knopf, der wie Text aussieht: Er nimmt Fokus
   * an und lässt sich mit der Tastatur auslösen, ohne dass die Tabelle wie ein
   * Formular aussieht. */
  .zeile {
    background: none;
    border: none;
    padding: 0;
    color: var(--tx);
    font: 0.82rem var(--mono);
    cursor: pointer;
    text-align: left;
    overflow-wrap: anywhere;
  }

  .zeile:hover {
    color: var(--accent);
  }

  :global(table.tabelle tr.gewaehlt) {
    background: var(--surface2);
  }

  :global(table.tabelle tr.gewaehlt) .zeile {
    color: var(--accent);
  }

  .aktionen {
    display: flex;
    gap: 0.45rem;
    flex-wrap: wrap;
  }

  .journal b {
    display: block;
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
    margin-bottom: 0.35rem;
  }

  .journal ol {
    list-style: none;
    display: grid;
    gap: 0.15rem;
    max-height: 14rem;
    overflow-y: auto;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.5rem 0.6rem;
    font: 0.76rem var(--mono);
  }

  .journal li {
    display: flex;
    gap: 0.55rem;
  }

  .journal .zeit {
    color: var(--tx-faint);
    flex: none;
  }

  .journal .text {
    color: var(--tx-mut);
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .journal li.ernst .text {
    color: var(--err);
  }

  .hinweis {
    display: grid;
    place-items: center;
    gap: 0.7rem;
    padding: 2.5rem 1rem;
    text-align: center;
  }

  .detail {
    color: var(--tx-mut);
    font: 0.82rem var(--mono);
  }

  .meldung {
    font-size: 0.82rem;
    color: var(--ok);
  }

  .warnung {
    font-size: 0.82rem;
    color: var(--err);
  }
</style>
