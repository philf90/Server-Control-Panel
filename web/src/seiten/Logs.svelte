<script lang="ts">
  // Logs: das Journal, abgefragt oder verfolgt.
  //
  // Die zweite Seite mit einem Strom — und einem anderen als beim Paketvorgang.
  // Ein Vorgang hat ein Ende, das der Server bestimmt; man sieht ihm zu, weil man
  // wissen will, wie er ausgeht. Ein Journal hat kein Ende; man sieht ihm zu,
  // weil man wissen will, was gerade passiert. Daraus folgt der Zuschnitt dieser
  // Seite:
  //
  //   - Die Filter stehen in der Adresse. Ein Verweis auf „nginx, ab heute, nur
  //     Fehler" ist damit teilbar — dieselbe Regel wie bei der Auswahl in den
  //     Diensten, und derselbe Grund.
  //   - Verfolgen ist ein Schalter und keine Vorgabe. Wer die Seite öffnet, will
  //     meist lesen, was war; wer zusehen will, sagt es. Und ein Strom hält einen
  //     journalctl-Prozess auf dem Server, den man nicht ungefragt aufmacht.
  //   - Beim Verlassen der Seite wird angehalten. Ein Vorgang läuft weiter, weil
  //     sein Abbruch schadet; ein Journal nicht, weil sein Weiterlaufen nur kostet.
  import { AbgemeldetFehler, api } from "../lib/api";
  import { Journalstrom } from "../lib/journal.svelte";
  import { t } from "../lib/texte";
  import { weg } from "../lib/weg.svelte";
  import type { Logs } from "../lib/typen";

  let daten = $state<Logs | null>(null);
  let fehler = $state("");
  let laedt = $state(false);

  const strom = new Journalstrom();

  // Die Filter kommen aus der Adresse und gehen dorthin zurück. Als eigene
  // Zustände daneben, weil ein Textfeld beim Tippen nicht bei jedem Buchstaben
  // die Adresse ändern soll — übernommen wird beim Abschicken.
  const unit = $derived(weg.parameter.unit ?? "");
  const stufe = $derived(weg.parameter.priority ?? "");
  const seit = $derived(weg.parameter.since ?? "");
  const suche = $derived(weg.parameter.q ?? "");

  let sucheFeld = $state("");
  // Das Feld folgt der Adresse, wenn sie sich von außen ändert (Zurück-Knopf),
  // aber nicht während des Tippens.
  $effect(() => {
    sucheFeld = weg.parameter.q ?? "";
  });

  /** suchpfad baut die Abfragezeichenkette. Eine Stelle für Abfrage und Strom:
   *  Zeigte der Strom andere Filter als die Liste, wäre eine Stufenbeschränkung
   *  beim Umschalten wirkungslos. */
  const suchpfad = $derived.by(() => {
    const p = new URLSearchParams();
    if (unit) p.set("unit", unit);
    if (stufe) p.set("priority", stufe);
    if (seit) p.set("since", seit);
    if (suche) p.set("q", suche);
    return p.toString();
  });

  function setzen(name: string, wert: string) {
    // Beim Ändern eines Filters wird angehalten: Der laufende Strom trägt die
    // alten Filter, und ihn weiterlaufen zu lassen hieße, unter einer neuen
    // Überschrift alte Zeilen zu zeigen.
    strom.loesen();
    weg.setze(name, wert);
  }

  // Neu abfragen, sobald sich die Filter ändern — auch über den Zurück-Knopf.
  $effect(() => {
    const pfad = suchpfad;
    void laden(pfad);
  });

  async function laden(pfad: string) {
    laedt = true;
    fehler = "";
    try {
      const frisch = await api.logs(pfad);
      daten = frisch;
      strom.setzen(frisch.zeilen);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      laedt = false;
    }
  }

  function umschalten() {
    if (strom.verfolgt) {
      strom.loesen();
      return;
    }
    strom.anhaengen(suchpfad);
  }

  // Beim Verlassen der Seite anhalten. Ohne das läuft auf dem Server ein
  // journalctl weiter, dem niemand mehr zusieht.
  $effect(() => () => strom.loesen());

  // Ist der Strom mit einem Verbindungsfehler ausgegangen, kann der Grund die
  // Obergrenze der Zuschauer sein. Die Abfrage weiß es — sie bringt
  // `folger_frei` mit.
  $effect(() => {
    if (strom.fehler === "verbindung") void laden(suchpfad);
  });

  const stufen = [
    { wert: "", label: t.logs.alleStufen },
    { wert: "3", label: t.logs.abFehler },
    { wert: "4", label: t.logs.abWarnung },
    { wert: "6", label: t.logs.abInfo },
  ];

  const zeitraeume = [
    { wert: "", label: t.logs.ohneGrenze },
    { wert: "-1h", label: t.logs.letzteStunde },
    { wert: "-6h", label: t.logs.letzte6h },
    { wert: "-24h", label: t.logs.letzte24h },
    { wert: "today", label: t.logs.heute },
    { wert: "-7d", label: t.logs.letzte7t },
  ];

  const belegt = $derived(daten !== null && !daten.folger_frei && !strom.verfolgt);
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.betrieb} / {t.ziele.logs}</div>
    <div class="h1">{t.ziele.logs}</div>
  </div>
  <div class="schub"></div>
  {#if daten}
    <span class="marke">
      {t.logs.zeilenZahl(strom.zeilen.length)}
      {#if daten.abfrage.anzahl}· {t.logs.holt(daten.abfrage.anzahl)}{/if}
    </span>
  {/if}
</div>

<div class="filter">
  <label>
    <span>{t.logs.unit}</span>
    <select value={unit} onchange={(e) => setzen("unit", e.currentTarget.value)}>
      <option value="">{t.logs.alleUnits}</option>
      <!-- Die aktuelle Unit steht auch dann in der Auswahl, wenn das Journal sie
           nicht mehr kennt: Ein geteilter Verweis auf eine Unit ohne frische
           Einträge soll nicht still auf „alle" zurückfallen. -->
      {#if unit && !(daten?.units ?? []).includes(unit)}
        <option value={unit}>{unit}</option>
      {/if}
      {#each daten?.units ?? [] as u (u)}
        <option value={u}>{u}</option>
      {/each}
    </select>
  </label>

  <label>
    <span>{t.logs.stufe}</span>
    <select value={stufe} onchange={(e) => setzen("priority", e.currentTarget.value)}>
      {#each stufen as s (s.wert)}
        <option value={s.wert}>{s.label}</option>
      {/each}
    </select>
  </label>

  <label>
    <span>{t.logs.zeitraum}</span>
    <select value={seit} onchange={(e) => setzen("since", e.currentTarget.value)}>
      {#each zeitraeume as z (z.wert)}
        <option value={z.wert}>{z.label}</option>
      {/each}
    </select>
  </label>

  <!-- Ein Formular, damit Enter abschickt. Die Freitextsuche läuft auf dem
       Server über einen einfachen Vergleich und nicht als regulärer Ausdruck —
       eine Suche, die jemand versehentlich teuer macht, wäre ein Fußangel. -->
  <form
    class="suchform"
    onsubmit={(e) => {
      e.preventDefault();
      setzen("q", sucheFeld.trim());
    }}
  >
    <label>
      <span>{t.logs.suche}</span>
      <input bind:value={sucheFeld} type="search" placeholder={t.logs.suchePlatzhalter} />
    </label>
    <button type="submit" class="knopf leise">{t.logs.suchen}</button>
  </form>

  <div class="schub"></div>

  <!-- Eine eigene Klasse und nicht nur `knopf`: In dieser Zeile steht noch der
       Knopf der Suche, und ein Test, der „den Knopf im Filter" anspricht, träfe
       den falschen. -->
  <button
    type="button"
    class="knopf verfolgen"
    class:leise={!strom.verfolgt}
    disabled={belegt}
    onclick={umschalten}
  >
    {#if strom.verfolgt}
      <span class="puls" aria-hidden="true"></span>{t.logs.anhalten}
    {:else}
      {t.logs.verfolgen}
    {/if}
  </button>
</div>

{#if belegt}
  <p class="warnung">{t.logs.zuVieleZuschauer}</p>
{/if}
{#if fehler}
  <p class="warnung" role="alert">{fehler}</p>
{/if}
{#if daten?.fehler}
  <p class="warnung">{daten.fehler}</p>
{/if}
{#if strom.fehler && strom.fehler !== "verbindung"}
  <p class="warnung" role="alert">{strom.fehler}</p>
{/if}
{#if strom.luecken > 0}
  <!-- Eine Lücke, die niemand sieht, ist schlimmer als eine, die dasteht. -->
  <p class="hinweis">{t.logs.luecke(strom.luecken)}</p>
{/if}

{#if laedt && strom.zeilen.length === 0}
  <p class="detail">{t.logs.laedt}</p>
{:else if strom.zeilen.length === 0}
  <div class="leer">
    <p><b>{t.logs.keine}</b></p>
    <p class="detail">{t.logs.keineDetail}</p>
  </div>
{:else}
  <div class="tabelle-rahmen">
    <table class="tabelle">
      <thead>
        <tr>
          <th>{t.logs.zeit}</th>
          <th>{t.logs.stufe}</th>
          <th>{t.logs.unit}</th>
          <th>{t.logs.nachricht}</th>
        </tr>
      </thead>
      <tbody>
        <!-- Der Schlüssel enthält den Index: Zwei Zeilen derselben Unit können in
             derselben Mikrosekunde denselben Text haben (ein wiederholter
             Fehler), und ein doppelter Schlüssel wäre ein Fehler beim Zeichnen. -->
        {#each strom.zeilen as zeile, i (`${zeile.at}-${i}`)}
          <tr class:eng={zeile.ernst}>
            <td data-spalte={t.logs.zeit} class="zahlenspalte zeit">{zeile.zeit ?? zeile.at}</td>
            <td data-spalte={t.logs.stufe}>
              <!-- Rot ab Fehler, bernstein bei Warnung, sonst neutral. Und immer
                   mit dem Wort daneben: Farbe trägt Zustand, aber nie allein. -->
              <span
                class="zustand"
                class:schlecht={zeile.ernst}
                class:warn={!zeile.ernst && zeile.stufe_nr === 4}
              >
                <i aria-hidden="true"></i>{zeile.stufe}
              </span>
            </td>
            <td data-spalte={t.logs.unit} class="pfad gedaempft">{zeile.unit || "—"}</td>
            <td data-spalte={t.logs.nachricht} class="nachricht">{zeile.nachricht}</td>
          </tr>
        {/each}
      </tbody>
    </table>
  </div>
{/if}

<style>
  .filter {
    display: flex;
    align-items: flex-end;
    gap: 0.7rem;
    flex-wrap: wrap;
    margin-bottom: 0.9rem;
  }

  .filter label {
    display: grid;
    gap: 0.2rem;
    font-size: 0.7rem;
    color: var(--tx-faint);
  }

  .filter select,
  .filter input {
    background: var(--surface);
    border: 1px solid var(--line2);
    border-radius: 8px;
    padding: 0.35rem 0.6rem;
    color: var(--tx);
    font: 0.82rem var(--sans);
    max-width: 100%;
  }

  .filter select {
    /* Eine Unit-Liste kann hundert Einträge haben; das Feld darf davon nicht
     * breit werden. */
    max-width: 14rem;
  }

  .suchform {
    display: flex;
    align-items: flex-end;
    gap: 0.4rem;
  }

  .schub {
    flex: 1;
  }

  .puls {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #14100a;
    animation: pochen 1.4s ease-in-out infinite;
  }

  @keyframes pochen {
    0%,
    100% {
      opacity: 0.3;
    }
    50% {
      opacity: 1;
    }
  }

  /* Die Zeit bleibt schmal und in Mono, damit die Spalte nicht bei jeder Zeile
   * ihre Breite ändert. Linksbündig, obwohl sie eine Zahlenspalte ist: Sie hat
   * immer dieselbe Länge, und rechtsbündig stand sie mit hundert Pixel Abstand
   * hinter ihrer Überschrift. */
  .zeit {
    text-align: left;
    width: 1%;
  }

  /* Die Nachricht nimmt den restlichen Platz. Ohne das verteilt der Browser die
   * freie Breite auf alle vier Spalten, und die Zeit steht in einer Spalte, die
   * dreimal so breit ist wie ihr Inhalt. */
  .nachricht {
    width: 100%;
  }

  /* Die Nachricht ist der Grund, die Seite zu öffnen — sie bekommt den Platz.
   * Umbrechen und nicht abschneiden: Eine abgeschnittene Fehlermeldung ist
   * keine. */
  .nachricht {
    font-family: var(--mono);
    font-size: 0.78rem;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    min-width: 18rem;
  }

  .leer {
    display: grid;
    gap: 0.4rem;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 1.6rem 1rem;
    text-align: center;
  }

  .detail {
    color: var(--tx-mut);
    font: 0.82rem var(--mono);
  }

  .warnung {
    font-size: 0.82rem;
    color: var(--err);
    margin-bottom: 0.7rem;
  }

  .hinweis {
    font-size: 0.82rem;
    color: var(--accent);
    margin-bottom: 0.7rem;
  }

  @media (max-width: 600px) {
    .nachricht {
      min-width: 0;
    }
  }
</style>
