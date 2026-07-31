<script lang="ts">
  // Der Compose-Editor: eine compose.yaml anlegen oder ändern.
  //
  // Er ist die Fläche, auf der der Compose-Prüfer sichtbar wird — und das ist
  // seine eigentliche Aufgabe. Drei Zusagen des Servers werden hier gezeigt,
  // nicht hier getroffen:
  //
  //  1. **Eine Ablehnung hat nichts geschrieben.** Anders als beim Dateimanager,
  //     wo eine Datei geschrieben und zurückgerollt wird, kommt der abgelehnte
  //     Text hier gar nicht erst auf die Platte. Der Text bleibt im Editor
  //     stehen, damit man die Zeile reparieren kann, auf die der Befund zeigt.
  //  2. **Ein Befund erklärt sich.** Dienst, Feld, Wert und Grund stehen
  //     wörtlich da. Sie zusammenzufassen hieße, die Auskunft wegzuwerfen, auf
  //     die es ankommt.
  //  3. **„Nicht geprüft" ist nicht „in Ordnung".** Lässt sich die Datei nicht
  //     als Compose lesen oder war Docker nicht erreichbar, sagt die Fläche das
  //     — statt zu schweigen und damit Zustimmung zu behaupten.
  //
  // CodeMirror kommt über denselben Weg wie im Dateimanager: dynamisch
  // nachgeladen aus lib/editorkern, mit demselben Nonce-Pfad. Der Brocken liegt
  // ohnehin im Bündel; ihn hier ein zweites Mal einzubinden hieße, ihn zweimal
  // auszuliefern.
  import Rueckfrage from "./Rueckfrage.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, Composeabgelehnt, api } from "../lib/api";
  import { t } from "../lib/texte";
  import type { Griff } from "../lib/editorkern";
  import type {
    Bestaetigung,
    Composebefund,
    Composepruefung,
    Stackvorlage,
  } from "../lib/typen";

  let {
    name = "",
    text = "",
    vorlagen = [],
    neu = false,
    schliessen,
    gespeichert,
  }: {
    /** name ist beim Ändern gesetzt und beim Anlegen leer — dann steht ein
     *  Namensfeld über dem Editor. */
    name?: string;
    text?: string;
    vorlagen?: Stackvorlage[];
    neu?: boolean;
    schliessen: () => void;
    gespeichert: (name: string) => void;
  } = $props();

  let kasten: HTMLDivElement | undefined = $state();
  let griff = $state<Griff | null>(null);
  let kernFehler = $state("");
  /** bearbeitet sperrt den Vorlagenwechsel. Sobald jemand geschrieben hat, darf
   *  kein Klick daneben seine Arbeit ersetzen.
   *
   *  Nicht „getippt": So heißt schon das bestätigende Wort einer Rückfrage der
   *  Stufe 3, und die Verwechslung wäre in speichern() eine still verschluckte
   *  Bestätigung. */
  let bearbeitet = $state(false);

  let neuerName = $state("");
  /** vorlage ist die gewählte Kennung. Leer heißt „noch keine gewählt" — dann
   *  gilt die erste. Sie hier aus den Vorlagen vorzubelegen ginge auch, läse
   *  aber eine Eigenschaft im Zustandsinitialisierer, und der läuft genau
   *  einmal: Kämen die Vorlagen später an, bliebe die Auswahl leer. */
  let vorlage = $state("");
  let speichert = $state(false);
  let fehler = $state("");
  let meldung = $state("");
  let pruefung = $state<Composepruefung | null>(null);
  let befunde = $state<Composebefund[]>([]);

  let offeneFrage = $state<{
    frage: Bestaetigung;
    tun: (getippt: string) => Promise<void>;
  } | null>(null);

  const gewaehlteVorlage = $derived(
    vorlagen.find((v) => v.kennung === vorlage) ?? vorlagen[0],
  );

  // Der Ausgangstext: beim Ändern die Datei, beim Anlegen die gewählte Vorlage.
  const starttext = $derived(neu ? (gewaehlteVorlage?.text ?? "") : text);

  // ZWEI Effekte, und die Trennung ist der Punkt — nicht Geschmack.
  //
  // Der erste hängt am Ausgangstext und räumt auf; der zweite baut den Editor,
  // sobald das Element steht. Standen sie zusammen, entstünde eine Schleife, die
  // sich nicht von selbst beendet: Der Effekt liest `griff` und setzt ihn, seine
  // Aufräumfunktion setzt ihn zurück auf null — und jede dieser Änderungen lässt
  // ihn erneut laufen. Weil das Setzen aus einem asynchronen Rückruf kommt,
  // greift Sveltes Tiefenerkennung nicht: Es gibt keinen Fehler, die Seite dreht
  // sich einfach weiter. Gefunden hat es der Browsertest, der beim Schließen des
  // Editors stehen blieb — ohne Meldung, bis die Testuhr ablief. Derselbe
  // Zuschnitt steht im Dateimanager (komponenten/Editor.svelte).
  $effect(() => {
    // Die Abhängigkeit ausdrücklich lesen: Ein Vorlagenwechsel soll den Editor
    // neu aufbauen.
    void starttext;
    return () => {
      griff?.zerstoeren();
      griff = null;
    };
  });

  $effect(() => {
    if (!kasten || griff) return;
    const el = kasten;
    const inhalt = starttext;

    void (async () => {
      try {
        const kern = await import("../lib/editorkern");
        if (!kasten || kasten !== el || griff) return;
        griff = kern.erzeuge(el, {
          inhalt,
          sprache: "yaml",
          beiAenderung: () => {
            // Die Meldung des letzten Speichervorgangs gilt nicht mehr, sobald
            // wieder getippt wird. Sie stehen zu lassen hieße, „Gespeichert"
            // über ungespeicherten Änderungen anzuzeigen.
            meldung = "";
            bearbeitet = true;
          },
        });
      } catch (e) {
        kernFehler = e instanceof Error ? e.message : t.dateien.editorNichtGeladen;
      }
    })();
  });

  // Ein Vorlagenwechsel baut den Editor neu auf — und geht nur, solange nichts
  // getippt wurde. Den Text eines bearbeiteten Editors zu ersetzen wäre der
  // bequemere Weg und der schlechtere: Er verlöre die Arbeit ohne Vorwarnung.
  // Ein Bestätigungsdialog wäre die dritte Möglichkeit; die Oberfläche des
  // Panels benutzt dafür keine Browserdialoge (docs/15-neuordnung.md).
  function vorlageWechseln(kennung: string) {
    vorlage = kennung;
    griff?.zerstoeren();
    griff = null;
  }

  async function speichern(bestaetigt = false, getippt = "") {
    if (!griff) return;
    const zielname = neu ? neuerName.trim() : name;
    if (!zielname) {
      fehler = t.docker.stackNameFehlt;
      return;
    }
    speichert = true;
    fehler = "";
    meldung = "";
    befunde = [];
    try {
      const antwort = neu
        ? await api.stackAnlegen(zielname, griff.inhalt(), bestaetigt, getippt)
        : await api.stackSpeichern(zielname, griff.inhalt(), bestaetigt, getippt);
      offeneFrage = null;
      meldung = antwort.meldung;
      pruefung = antwort.pruefung;
      gespeichert(zielname);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      if (e instanceof BestaetigungNoetig) {
        offeneFrage = {
          frage: e.bestaetigung,
          tun: (getippt: string) => speichern(true, getippt),
        };
        return;
      }
      offeneFrage = null;
      if (e instanceof Composeabgelehnt) {
        // Der Text bleibt stehen: Der Befund zeigt auf eine Zeile, und die will
        // man reparieren, nicht neu tippen.
        fehler = t.docker.prueferAbgelehnt;
        pruefung = e.pruefung;
        befunde = e.befunde;
        return;
      }
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      speichert = false;
    }
  }

  const aussen = $derived((pruefung?.befunde ?? []).filter((b) => b.art === "aussen"));
  const hinweise = $derived((pruefung?.befunde ?? []).filter((b) => b.art === "hinweis"));
</script>

<section class="editor" aria-label={neu ? t.docker.stackNeuTitel : name}>
  <div class="kopf">
    <h3>{neu ? t.docker.stackNeuTitel : name}</h3>
    <div class="schub"></div>
    <button type="button" class="knopf leise klein" onclick={schliessen}>
      {t.docker.stackAbbrechen}
    </button>
    <button
      type="button"
      class="knopf klein"
      disabled={speichert || !griff}
      onclick={() => speichern()}
    >
      {t.docker.stackSpeichern}
    </button>
  </div>

  {#if neu}
    <div class="felder">
      <label class="feldzeile">
        <span>{t.docker.stackNameFeld}</span>
        <input
          type="text"
          class="feld"
          bind:value={neuerName}
          placeholder="mein-stack"
          autocomplete="off"
        />
      </label>
      <label class="feldzeile">
        <span>{t.docker.stackVorlage}</span>
        <select
          class="feld"
          value={gewaehlteVorlage?.kennung ?? ""}
          disabled={bearbeitet}
          onchange={(e) => vorlageWechseln(e.currentTarget.value)}
        >
          {#each vorlagen as v (v.kennung)}
            <option value={v.kennung}>{v.titel}</option>
          {/each}
        </select>
      </label>
    </div>
    <p class="detail">{t.docker.stackNameHinweis}</p>
    {#if bearbeitet}
      <p class="detail">{t.docker.vorlageGesperrt}</p>
    {/if}
    {#if gewaehlteVorlage}
      <p class="detail">{gewaehlteVorlage.beschreibung}</p>
    {/if}
  {/if}

  {#if fehler}<p class="warnung" role="alert">{fehler}</p>{/if}
  {#if meldung}<p class="meldung" role="status">{meldung}</p>{/if}
  {#if kernFehler}<p class="warnung">{kernFehler}</p>{/if}

  {#if befunde.length}
    <!-- Die Ablehnungen. Sie stehen ÜBER dem Editor und nicht darunter: Wer
         gerade abgelehnt wurde, soll den Grund sehen, ohne zu scrollen. -->
    <div class="befunde abgelehnt">
      <b>{t.docker.prueferAblehnung}</b>
      <ul>
        {#each befunde as b (b.dienst + b.feld + b.wert)}
          <li>
            <span class="stelle">{b.dienst} · {b.feld}</span>
            {#if b.wert}<code>{b.wert}</code>{/if}
            <span class="grund">{b.grund}</span>
          </li>
        {/each}
      </ul>
    </div>
  {/if}

  <div class="kasten" bind:this={kasten}></div>

  {#if pruefung}
    <div class="pruefung">
      <b>{t.docker.prueferTitel}</b>
      {#if !pruefung.geprueft}
        <p class="warnung">{t.docker.prueferNichtGeprueft}</p>
        {#if pruefung.meldung}<pre class="auszug">{pruefung.meldung}</pre>{/if}
      {:else}
        {#if !pruefung.gerendert}
          <!-- Der ehrliche Vorbehalt: Ohne Rendern können Anker, extends und
               env_file an der Prüfung vorbei. Ihn wegzulassen hieße, eine
               halbe Prüfung als ganze auszugeben. -->
          <p class="hinweis">{t.docker.prueferNurRoh}</p>
        {/if}
        {#if pruefung.dienste.length}
          <p class="detail">{t.docker.prueferDienste}: {pruefung.dienste.join(" · ")}</p>
        {/if}
        {#if aussen.length}
          <div class="befunde">
            <b>{t.docker.prueferAussen}</b>
            <ul>
              {#each aussen as b (b.dienst + b.wert)}
                <li>
                  <span class="stelle">{b.dienst}</span>
                  <code>{b.wert}</code>
                  <span class="grund">{b.grund}</span>
                </li>
              {/each}
            </ul>
          </div>
        {/if}
        {#if hinweise.length}
          <div class="befunde">
            <b>{t.docker.prueferHinweise}</b>
            <ul>
              {#each hinweise as b (b.dienst + b.feld)}
                <li>
                  <span class="stelle">{b.dienst} · {b.feld}</span>
                  <span class="grund">{b.grund}</span>
                </li>
              {/each}
            </ul>
          </div>
        {/if}
        {#if !aussen.length && !hinweise.length}
          <p class="detail">{t.docker.prueferOK}</p>
        {/if}
      {/if}
    </div>
  {/if}
</section>

{#if offeneFrage}
  <Rueckfrage
    frage={offeneFrage.frage}
    laeuft={speichert}
    bestaetigen={(getippt) => offeneFrage?.tun(getippt) ?? Promise.resolve()}
    abbrechen={() => (offeneFrage = null)}
  />
{/if}

<style>
  .editor {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 0.9rem 1rem;
    display: grid;
    gap: 0.6rem;
    margin-bottom: 1rem;
  }

  .kopf {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
  }

  h3 {
    font-size: 0.9rem;
    font-weight: 600;
  }

  .felder {
    display: grid;
    gap: 0.5rem;
  }

  @media (min-width: 700px) {
    .felder {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  .feldzeile {
    display: grid;
    gap: 0.25rem;
    font-size: 0.78rem;
    color: var(--tx-mut);
  }

  .kasten {
    border: 1px solid var(--line);
    border-radius: 8px;
    overflow: hidden;
    min-height: 18rem;
  }

  .pruefung {
    display: grid;
    gap: 0.4rem;
  }

  .pruefung > b,
  .befunde > b {
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
  }

  .befunde {
    display: grid;
    gap: 0.3rem;
  }

  .befunde ul {
    list-style: none;
    display: grid;
    gap: 0.4rem;
  }

  .befunde li {
    font-size: 0.8rem;
    line-height: 1.5;
  }

  .befunde .stelle {
    font: 0.78rem var(--mono);
    color: var(--tx);
    margin-right: 0.4rem;
  }

  .befunde code {
    font: 0.76rem var(--mono);
    color: var(--tx-mut);
    margin-right: 0.4rem;
  }

  .befunde .grund {
    display: block;
    color: var(--tx-mut);
  }

  /* Die Ablehnung ist der einzige Block dieser Fläche mit eigener Farbe. Sie
     ist die Auskunft, wegen der jemand hier steht. */
  .befunde.abgelehnt {
    border-left: 3px solid var(--err);
    padding-left: 0.7rem;
  }

  .auszug {
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.6rem 0.7rem;
    font: 0.76rem var(--mono);
    color: var(--tx-mut);
    max-height: 12rem;
    overflow: auto;
    white-space: pre-wrap;
    word-break: break-word;
  }
</style>
