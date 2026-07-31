<script lang="ts">
  // Die Stackwerkbank: Liste links, Inspektor rechts — dasselbe Muster wie bei
  // den Containern (docs/16-neukonzeption.md §8.4).
  //
  // Seit Schritt 5 ist sie auch schreibend: anlegen, bearbeiten, starten,
  // herunterfahren, Abbilder holen, neu starten, löschen. Die Grenze zieht der
  // Compose-Prüfer auf dem Server — diese Fläche zeigt sein Urteil, sie fällt
  // es nicht. Eine Prüfung im Browser wäre eine Bequemlichkeit; die Bedingung
  // steht in privops.
  //
  // Der Unterschied, der die Seite prägt, ist „verwaltet" gegen „fremd": Nur was
  // unter /opt/asylum/stacks liegt und den Marker trägt, wird das Panel je
  // schreiben. Das steht als Spalte da und nicht als Fußnote — sonst sucht
  // jemand später einen Knopf, den es mit Absicht nicht gibt.
  import Composeeditor from "./Composeeditor.svelte";
  import Inspektor from "./Inspektor.svelte";
  import Rueckfrage from "./Rueckfrage.svelte";
  import Vorgangsplatte from "./Vorgangsplatte.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { weg } from "../lib/weg.svelte";
  import { Vorgang } from "../lib/vorgang.svelte";
  import type { Bestaetigung, Stackliste, StackDetail } from "../lib/typen";

  let daten = $state<Stackliste | null>(null);
  let detail = $state<StackDetail | null>(null);
  let fehler = $state("");
  let meldung = $state("");
  let suche = $state("");
  let laufendeAktion = $state("");
  /** editor ist "" (zu), "neu" (anlegen) oder der Name eines Stacks. */
  let editor = $state("");

  let offeneFrage = $state<{
    frage: Bestaetigung;
    tun: (getippt: string) => Promise<void>;
  } | null>(null);

  const vorgang = new Vorgang("docker-stack");

  $effect(() => () => vorgang.loesen());

  // Nach dem Ende eines Vorgangs alles neu holen: Was danach gilt, sagt der
  // Server. Die Seite weiß nicht, ob „up" geglückt ist — sie weiß nur, dass
  // der Lauf vorbei ist.
  let liefZuvor = $state(false);
  $effect(() => {
    const laeuft = vorgang.job?.laeuft ?? false;
    if (liefZuvor && !laeuft) {
      void laden();
      const name = gewaehlt;
      if (name) void detailHolen(name, true);
    }
    liefZuvor = laeuft;
  });

  // Die Auswahl steht in der Adresse: teilbar, überlebt ein Neuladen, und der
  // Zurück-Knopf schließt den Inspektor.
  const gewaehlt = $derived(weg.parameter.stack ?? "");

  async function laden() {
    fehler = "";
    try {
      const frisch = await api.stacks();
      daten = frisch;
      vorgang.setzen(frisch.job);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  async function detailHolen(name: string, erzwingen = false) {
    if (!erzwingen && detail?.name === name) return;
    try {
      detail = await api.stackDetail(name);
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
      editor = editor === "neu" ? editor : "";
      return;
    }
    void detailHolen(name);
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

  // ausfuehren schickt einen Handgriff ab.
  //
  // Die Rückfrage kommt vom Server und ihre Stufe ebenso: „up" ist Stufe 1,
  // außer der Prüfer meldet einen Bind-Mount nach draußen — dann Stufe 3 mit
  // dem Stack-Namen. Diese Fläche kennt die Regel nicht und soll sie nicht
  // kennen; sie zeigt die Frage, die kommt.
  async function ausfuehren(
    name: string,
    aktion: string,
    mitVolumes = false,
    bestaetigt = false,
    getippt = "",
  ) {
    laufendeAktion = aktion;
    fehler = "";
    meldung = "";
    try {
      const { job, meldung: satz } = await api.stackAktion(
        name,
        aktion,
        mitVolumes,
        bestaetigt,
        getippt,
      );
      offeneFrage = null;
      meldung = satz;
      // An die Antwort anhängen und nicht später abfragen: Der Vorgang läuft
      // bereits, und eine Runde später wäre er bei einem schnellen „pull" schon
      // vorbei — der Strom ginge nie auf.
      vorgang.setzen(job);
      if (aktion === "loeschen") schliessen();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      if (e instanceof BestaetigungNoetig) {
        offeneFrage = {
          frage: e.bestaetigung,
          tun: (wort: string) => ausfuehren(name, aktion, mitVolumes, true, wort),
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
  const laeuftVorgang = $derived(vorgang.job?.laeuft ?? false);

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
{#if meldung}<p class="meldung" role="status">{meldung}</p>{/if}

<Vorgangsplatte {vorgang} />

{#if editor}
  <Composeeditor
    neu={editor === "neu"}
    name={editor === "neu" ? "" : editor}
    text={editor === "neu" ? "" : (detail?.text ?? "")}
    vorlagen={daten?.vorlagen ?? []}
    schliessen={() => (editor = "")}
    gespeichert={(name) => {
      editor = "";
      void laden();
      weg.setze("stack", name);
      void detailHolen(name, true);
    }}
  />
{/if}

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
    <div class="schub"></div>
    {#if darfAendern}
      <button type="button" class="knopf klein" onclick={() => (editor = "neu")}>
        {t.docker.stackAnlegen}
      </button>
    {/if}
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

          {#if darfAendern}
            <div class="aktionen">
              <button
                type="button"
                class="knopf leise klein"
                disabled={laufendeAktion !== "" || laeuftVorgang}
                onclick={() => ausfuehren(detail.name, "up")}
              >
                {t.docker.stackUp}
              </button>
              <button
                type="button"
                class="knopf leise klein"
                disabled={laufendeAktion !== "" || laeuftVorgang || !detail.gestartet}
                onclick={() => ausfuehren(detail.name, "restart")}
              >
                {t.docker.stackRestart}
              </button>
              <button
                type="button"
                class="knopf leise klein"
                disabled={laufendeAktion !== "" || laeuftVorgang}
                onclick={() => ausfuehren(detail.name, "pull")}
              >
                {t.docker.stackPull}
              </button>
              <button
                type="button"
                class="knopf leise klein"
                disabled={laufendeAktion !== "" || laeuftVorgang || !detail.gestartet}
                onclick={() => ausfuehren(detail.name, "down")}
              >
                {t.docker.stackDown}
              </button>
              <!-- Mit Volumes ist ein eigener Knopf und keine Ankreuzbox: Was
                   Daten löscht, soll man drücken müssen und nicht nebenbei
                   angehakt haben. -->
              <button
                type="button"
                class="knopf gefahr klein"
                disabled={laufendeAktion !== "" || laeuftVorgang || !detail.gestartet}
                onclick={() => ausfuehren(detail.name, "down", true)}
              >
                {t.docker.stackDownVolumes}
              </button>
              {#if detail.verwaltet}
                <button
                  type="button"
                  class="knopf leise klein"
                  disabled={laufendeAktion !== "" || laeuftVorgang}
                  onclick={() => (editor = detail.name)}
                >
                  {t.docker.stackBearbeiten}
                </button>
                <button
                  type="button"
                  class="knopf gefahr klein"
                  disabled={laufendeAktion !== "" || laeuftVorgang}
                  onclick={() => ausfuehren(detail.name, "loeschen")}
                >
                  {t.docker.stackLoeschen}
                </button>
              {/if}
            </div>
            {#if !detail.verwaltet}
              <p class="hinweis">{t.docker.stackFremdNichtAenderbar}</p>
            {/if}
          {:else}
            <p class="hinweis">{t.docker.nurOwner}</p>
          {/if}

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

{#if offeneFrage}
  <Rueckfrage
    frage={offeneFrage.frage}
    laeuft={laufendeAktion !== ""}
    bestaetigen={(getippt) => offeneFrage?.tun(getippt) ?? Promise.resolve()}
    abbrechen={() => (offeneFrage = null)}
  />
{/if}

<style>
  .aktionen {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    margin-top: 1rem;
  }

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
