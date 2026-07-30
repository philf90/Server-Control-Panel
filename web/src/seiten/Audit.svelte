<script lang="ts">
  // Audit: das Revisionsprotokoll, gefiltert und geblättert.
  //
  // Drei Dinge unterscheiden diese Seite von den anderen Listen des Panels, und
  // alle drei folgen daraus, dass das Protokoll unbegrenzt wächst:
  //
  //  1. Gefiltert wird auf dem SERVER. Ein Browserfilter über einem Ausschnitt
  //     behauptete „kein Treffer" für einen Eintrag, den es gibt — und genau
  //     danach sucht man hier: nach etwas von vorletzter Woche.
  //  2. Geblättert wird angehängt, nicht ersetzt. „Weitere 100 laden" hängt unten
  //     an, damit der Zusammenhang stehen bleibt. Ein Seitenwechsel, der die
  //     Liste austauscht, verliert die Zeile, wegen der man weitergeblättert hat.
  //  3. Es gibt keinen Knopf, der etwas ändert. Das ist keine fehlende Hälfte,
  //     sondern die Aussage des Moduls — und sie steht als Satz über der Liste,
  //     weil ein Protokoll ohne diese Zusage keines ist.
  import { AbgemeldetFehler, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { weg } from "../lib/weg.svelte";
  import type { Audit, Auditzeile } from "../lib/typen";

  let daten = $state<Audit | null>(null);
  /** zeilen wächst beim Blättern. Getrennt von daten.zeilen, weil daten immer die
   *  LETZTE Seite ist und diese Liste alle bisherigen zusammen. */
  let zeilen = $state<Auditzeile[]>([]);
  let fehler = $state("");
  let laedt = $state(false);
  let laedtMehr = $state(false);
  let suchfeld = $state("");
  /** offen ist die Zeile, deren Einzelheiten ausgeklappt sind. Nur eine: Zwei
   *  offene Blöcke in einer Protokollliste sind schwerer zu lesen als einer. */
  let offen = $state<number | null>(null);

  const akteur = $derived(weg.parameter.akteur ?? "");
  const familie = $derived(weg.parameter.familie ?? "");
  const ergebnis = $derived(weg.parameter.ergebnis ?? "");
  const begriff = $derived(weg.parameter.q ?? "");

  const filterAktiv = $derived(!!(akteur || familie || ergebnis || begriff));

  function suchpfad(vor = 0): string {
    const p = new URLSearchParams();
    if (akteur) p.set("akteur", akteur);
    if (familie) p.set("familie", familie);
    if (ergebnis) p.set("ergebnis", ergebnis);
    if (begriff) p.set("q", begriff);
    if (vor > 0) p.set("vor", String(vor));
    return p.toString();
  }

  // Die Liste folgt der Adresse: Ein Verweis auf „alles, was philipp am
  // Dateimanager abgelehnt bekam" ist damit teilbar.
  $effect(() => {
    void laden(suchpfad());
  });

  async function laden(pfad: string) {
    laedt = true;
    fehler = "";
    try {
      const antwort = await api.audit(pfad);
      daten = antwort;
      // Bei einer neuen Abfrage ersetzen, nicht anhängen.
      zeilen = antwort.zeilen;
      suchfeld = antwort.filter.suche;
      offen = null;
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      laedt = false;
    }
  }

  async function mehr() {
    if (!daten?.weiter) return;
    laedtMehr = true;
    try {
      const antwort = await api.audit(suchpfad(daten.weiter));
      // Anhängen und die neue Seite als „letzte" merken: weiter kommt daraus.
      zeilen = [...zeilen, ...antwort.zeilen];
      daten = antwort;
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      laedtMehr = false;
    }
  }

  /** setze schreibt einen Filter in die Adresse. Kein Schritt im Verlauf: Wer
   *  fünf Filter ausprobiert, will mit „zurück" die Seite verlassen und nicht
   *  vier Zwischenstände durchlaufen. */
  function setze(name: string, wert: string) {
    weg.setzeAlle({ [name]: wert }, false);
  }

  function suchen(e: SubmitEvent) {
    e.preventDefault();
    setze("q", suchfeld.trim());
  }

  function zuruecksetzen() {
    suchfeld = "";
    weg.setzeAlle({ akteur: "", familie: "", ergebnis: "", q: "" }, false);
  }
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.betrieb} / {t.ziele.audit}</div>
    <div class="h1">{t.ziele.audit}</div>
  </div>
  <div class="schub"></div>
  {#if zeilen.length > 0}
    <span class="marke">{t.audit.gefiltert(zeilen.length)}</span>
  {/if}
</div>

<p class="wesen">{t.audit.wesen}</p>

{#if fehler && zeilen.length === 0}
  <div class="hinweis">
    <p>{t.fehler.laden}</p>
    <p class="detail">{fehler}</p>
    <button class="knopf" onclick={() => laden(suchpfad())}>{t.fehler.erneut}</button>
  </div>
{:else if !daten}
  <p class="detail">{t.audit.laedt}</p>
{:else}
  <div class="werkzeuge">
    <!-- Auswahlfelder und keine Textfelder: Die Namen kommen aus dem Protokoll
         selbst. Wer sich vertippt, bekommt sonst „keine Treffer" und schließt
         daraus, dass nichts geschehen ist. -->
    <label>
      <span class="nur-vorlese">{t.audit.akteur}</span>
      <select value={akteur} onchange={(e) => setze("akteur", e.currentTarget.value)}>
        <option value="">{t.audit.alleAkteure}</option>
        {#each daten.akteure as a (a)}
          <option value={a}>{a}</option>
        {/each}
      </select>
    </label>

    <label>
      <span class="nur-vorlese">{t.audit.aktion}</span>
      <select value={familie} onchange={(e) => setze("familie", e.currentTarget.value)}>
        <option value="">{t.audit.alleFamilien}</option>
        {#each daten.familien as f (f)}
          <option value={f}>{f}</option>
        {/each}
      </select>
    </label>

    <!-- Die drei Ergebnisse als Knöpfe und nicht als Auswahl: Es sind genau drei,
         und „abgelehnt" ist der, den man tatsächlich sucht. Grundsatz II: jede
         Zahl ist ein Griff — hier jeder Zustand. -->
    <div class="stufen" role="group" aria-label={t.audit.ergebnis}>
      <button type="button" class:an={ergebnis === ""} onclick={() => setze("ergebnis", "")}>
        {t.audit.alleErgebnisse}
      </button>
      {#each ["ok", "denied", "error"] as wert (wert)}
        <button
          type="button"
          class:an={ergebnis === wert}
          onclick={() => setze("ergebnis", ergebnis === wert ? "" : wert)}
        >
          {t.audit.ergebnisse[wert]}
        </button>
      {/each}
    </div>

    <form class="suche" onsubmit={suchen}>
      <label>
        <span class="nur-vorlese">{t.audit.suchen}</span>
        <input
          bind:value={suchfeld}
          type="search"
          placeholder={t.audit.suchen}
          autocomplete="off"
          spellcheck="false"
        />
      </label>
      <button type="submit" class="knopf leise klein">{t.audit.suchenKurz}</button>
    </form>

    {#if filterAktiv}
      <button type="button" class="knopf leise klein" onclick={zuruecksetzen}>
        {t.audit.zuruecksetzen}
      </button>
    {/if}
  </div>

  {#if fehler}
    <p class="band schlecht" role="alert">{fehler}</p>
  {/if}

  <div class="tabelle-rahmen" class:blass={laedt}>
    <table class="tabelle">
      <thead>
        <tr>
          <th>{t.audit.zeit}</th>
          <th>{t.audit.akteur}</th>
          <th>{t.audit.aktion}</th>
          <th>{t.audit.ziel}</th>
          <th>{t.audit.ergebnis}</th>
        </tr>
      </thead>
      <tbody>
        {#if zeilen.length === 0}
          <tr>
            <td colspan="5" class="gedaempft">
              {filterAktiv ? t.audit.nichts : t.audit.leer}
            </td>
          </tr>
        {:else}
          {#each zeilen as zeile (zeile.id)}
            <tr class:gewaehlt={offen === zeile.id}>
              <td data-spalte={t.audit.zeit} class="zahl gedaempft">{zeile.zeit}</td>
              <td data-spalte={t.audit.akteur}>{zeile.akteur}</td>
              <td data-spalte={t.audit.aktion}>
                <!-- Die Aktion ist der Griff: Ein Klick klappt die Einzelheiten
                     auf. Sie stehen nicht in der Zeile, weil ein Detail bis 1024
                     Zeichen lang sein darf und die Liste dann keine mehr wäre. -->
                <button
                  type="button"
                  class="zeile"
                  aria-expanded={offen === zeile.id}
                  onclick={() => (offen = offen === zeile.id ? null : zeile.id)}
                >
                  {zeile.aktion}
                </button>
              </td>
              <td data-spalte={t.audit.ziel} class="pfad gedaempft">{zeile.ziel || "—"}</td>
              <td data-spalte={t.audit.ergebnis}>
                <span class="zustand {zeile.stufe}">
                  <i aria-hidden="true"></i>{t.audit.ergebnisse[zeile.ergebnis] ?? zeile.ergebnis}
                </span>
              </td>
            </tr>
            {#if offen === zeile.id}
              <tr class="einzelheiten">
                <td colspan="5">
                  <dl class="kv">
                    <dt>{t.audit.detail}</dt>
                    <dd>{zeile.detail || "—"}</dd>
                    <dt>{t.audit.ip}</dt>
                    <dd class="zahl">{zeile.ip || "—"}</dd>
                    <dt>{t.audit.ziel}</dt>
                    <dd class="pfad">{zeile.ziel || "—"}</dd>
                  </dl>
                </td>
              </tr>
            {/if}
          {/each}
        {/if}
      </tbody>
    </table>
  </div>

  <div class="fuss">
    {#if daten.weiter}
      <button type="button" class="knopf leise" disabled={laedtMehr} onclick={mehr}>
        {laedtMehr ? t.audit.laedtMehr : t.audit.mehr}
      </button>
    {:else if zeilen.length > 0}
      <!-- Gesagt und nicht verschwiegen: Ein Knopf, der einfach verschwindet,
           lässt offen, ob es weitergeht oder ob etwas hakt. -->
      <span class="detail">{t.audit.ende}</span>
    {/if}
  </div>
{/if}

<style>
  .wesen {
    color: var(--tx-mut);
    font-size: 0.82rem;
    margin-bottom: 0.8rem;
    max-width: 52rem;
  }

  .werkzeuge {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
    margin-bottom: 0.8rem;
  }

  .werkzeuge select,
  .suche input {
    background: var(--surface);
    border: 1px solid var(--line2);
    border-radius: 8px;
    padding: 0.32rem 0.6rem;
    color: var(--tx);
    font: 0.82rem var(--sans);
    max-width: 100%;
  }

  .suche {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    flex-wrap: wrap;
  }

  .suche input {
    width: 14rem;
    font-family: var(--mono);
    font-size: 0.8rem;
  }

  .stufen {
    display: flex;
    gap: 0.3rem;
    flex-wrap: wrap;
  }

  .stufen button {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 7px;
    padding: 0.28rem 0.55rem;
    color: var(--tx-mut);
    font: 0.75rem var(--sans);
    cursor: pointer;
  }

  .stufen button.an {
    border-color: var(--accent-dim);
    color: var(--accent);
  }

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

  .einzelheiten td {
    background: var(--bg);
    padding: 0.6rem 0.8rem;
  }

  .einzelheiten :global(dl.kv dd) {
    overflow-wrap: anywhere;
  }

  /* Während eine neue Seite kommt, bleibt die alte stehen und wird blass. Sie
   * durch „lädt …" zu ersetzen ließe die Seite bei jedem Filterwechsel springen. */
  .blass {
    opacity: 0.5;
  }

  .band {
    border: 1px solid var(--err);
    border-radius: 8px;
    padding: 0.5rem 0.7rem;
    margin-bottom: 0.7rem;
    font-size: 0.8rem;
    color: var(--err);
  }

  .fuss {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin-top: 0.8rem;
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
    font: 0.8rem var(--mono);
  }
</style>
