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
  import Composeformular from "./Composeformular.svelte";
  import Rueckfrage from "./Rueckfrage.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, Composeabgelehnt, api } from "../lib/api";
  import { t } from "../lib/texte";
  import type { Griff } from "../lib/editorkern";
  import type { Umgebungsform, Umgebungszeile } from "../lib/composeform";
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
            spiegelNachziehen();
          },
        });
        spiegel = inhalt;
      } catch (e) {
        kernFehler = e instanceof Error ? e.message : t.dateien.editorNichtGeladen;
      }
    })();
  });

  // ────────────────────────────────── Das Formular und seine Verdrahtung ───
  //
  // Die Richtung, in die man das lesen muss: Es gibt EINE Wahrheit, und das ist
  // der Text im Editor. „spiegel" ist nur eine Kopie davon für das Formular,
  // und er wird verzögert nachgezogen — ein Parser je Tastendruck wäre Arbeit
  // für ein Ergebnis, das im nächsten Anschlag schon wieder gilt.
  //
  // In die andere Richtung schreibt das Formular nie in den Spiegel allein: Es
  // erzeugt einen neuen Text und legt ihn in den Editor. Dass der daraufhin
  // seinerseits den Spiegel nachzieht, ist kein Kreis, sondern die Bestätigung
  // — er zieht denselben Text nach, und dabei bleibt es.

  type Modell = typeof import("../lib/composeform");

  let ansicht = $state<"beides" | "felder" | "text">("beides");
  /** spiegel ist der Text, wie das Formular ihn sieht. Nicht „text": So heißt
   *  die Eigenschaft mit dem Ausgangsinhalt, und die ändert sich nie. */
  let spiegel = $state("");
  let modell = $state<Modell | null>(null);
  let modellFehler = $state("");
  let stift: ReturnType<typeof setTimeout> | null = null;

  function spiegelNachziehen() {
    if (stift) clearTimeout(stift);
    stift = setTimeout(() => {
      stift = null;
      spiegel = griff?.inhalt() ?? "";
    }, 200);
  }

  /** modellLaden holt den Brocken mit der yaml-Bibliothek nach — wie
   *  editorkern und aus demselben Grund. Wer nur die Datei bearbeitet, lädt ihn
   *  nie.
   *
   *  Kein $effect: Ein Effekt, der „modell" liest und asynchron setzt, ist genau
   *  die Bauart, mit der diese Datei schon einmal in eine Schleife gelaufen ist
   *  (siehe die beiden Effekte oben). Ein Aufruf an den zwei Stellen, an denen
   *  er gebraucht wird, kann das nicht. */
  async function modellLaden() {
    if (modell || modellFehler) return;
    try {
      modell = await import("../lib/composeform");
    } catch (e) {
      modellFehler = e instanceof Error ? e.message : t.dateien.editorNichtGeladen;
    }
  }

  if (ansicht !== "text") void modellLaden();

  /** ansichtWaehlen schaltet um. Der Parameter heißt „wahl" und nicht „neu":
   *  So heißt schon die Eigenschaft, die „Stack anlegen" von „Stack ändern"
   *  unterscheidet, und eine verdeckte Eigenschaft ist in dieser Datei schon
   *  einmal teuer geworden. */
  function ansichtWaehlen(wahl: "beides" | "felder" | "text") {
    ansicht = wahl;
    if (wahl !== "text") void modellLaden();
  }

  const aufbau = $derived(modell ? modell.lies(spiegel) : null);

  /** anwenden legt einen vom Formular erzeugten Text in den Editor.
   *
   *  Die Gleichheitsprüfung ist die Bremse: Das Modell gibt bei jeder Änderung,
   *  die es nicht ausführen kann, den EINGABETEXT zurück — und ein
   *  „ersetzen" mit demselben Inhalt würde die Schreibmarke im Texteditor
   *  trotzdem an den Anfang setzen. */
  function anwenden(neu: string) {
    if (neu === spiegel) return;
    spiegel = neu;
    griff?.ersetzen(neu);
    bearbeitet = true;
    meldung = "";
  }

  function feldAendern(dienst: string, feld: "image" | "restart" | "command", wert: string) {
    if (modell) anwenden(modell.setzeFeld(spiegel, dienst, feld, wert));
  }

  function listeAendern(
    dienst: string,
    feld: "ports" | "volumes" | "depends_on" | "networks",
    werte: string[],
  ) {
    if (modell) anwenden(modell.setzeListe(spiegel, dienst, feld, werte));
  }

  function umgebungAendern(dienst: string, zeilen: Umgebungszeile[], form: Umgebungsform) {
    if (modell) anwenden(modell.setzeUmgebung(spiegel, dienst, zeilen, form));
  }

  function dienstAnlegen(name: string) {
    if (modell) anwenden(modell.dienstAnlegen(spiegel, name));
  }

  function dienstEntfernen(name: string) {
    if (modell) anwenden(modell.dienstEntfernen(spiegel, name));
  }

  $effect(() => () => {
    if (stift) clearTimeout(stift);
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

  <!-- Die Ansichtswahl. „beides" ist die Vorgabe, weil die Fläche genau davon
       lebt: Wer ein Feld ändert, soll sehen, was in der Datei geschieht — und
       wer die Datei ändert, soll sehen, wie das Formular es liest. -->
  <div class="ansichtswahl" role="group" aria-label={t.docker.formAnsicht}>
    {#each [{ k: "felder" as const, b: t.docker.formTitel }, { k: "beides" as const, b: t.docker.formBeides }, { k: "text" as const, b: t.docker.formText }] as w (w.k)}
      <button
        type="button"
        class="knopf leise klein"
        class:gewaehlt={ansicht === w.k}
        aria-pressed={ansicht === w.k}
        onclick={() => ansichtWaehlen(w.k)}
      >
        {w.b}
      </button>
    {/each}
  </div>

  {#if modellFehler}<p class="warnung">{modellFehler}</p>{/if}

  <!-- Beide Flächen bleiben im Baum und werden nur verborgen. Den Texteditor
       aus dem Baum zu nehmen hieße, ihn zu zerstören und beim Zurückschalten
       neu zu bauen — samt Verlust der Rücknahmegeschichte. -->
  <div class="flaechen" class:zwei={ansicht === "beides"}>
    <div class="formularseite" class:versteckt={ansicht === "text"}>
      {#if aufbau}
        <Composeformular
          {aufbau}
          {feldAendern}
          {listeAendern}
          {umgebungAendern}
          {dienstAnlegen}
          {dienstEntfernen}
        />
      {:else if !modellFehler}
        <p class="detail">{t.docker.laedt}</p>
      {/if}
    </div>
    <div class="kasten" bind:this={kasten} class:versteckt={ansicht === "felder"}></div>
  </div>

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

  .ansichtswahl {
    display: flex;
    gap: 0.3rem;
    flex-wrap: wrap;
  }

  .ansichtswahl .gewaehlt {
    border-color: var(--accent-dim);
    color: var(--tx);
  }

  .flaechen {
    display: grid;
    gap: 0.6rem;
    align-items: start;
  }

  /* Nebeneinander erst, wenn es dafür reicht. Zwei Spalten auf einem Telefon
     wären zwei zu schmale Spalten. */
  @media (min-width: 1100px) {
    .flaechen.zwei {
      grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }
  }

  /* Der Bezugsrahmen für die Rasterabfragen im Formular: Es soll sich nach
     dieser Spalte richten und nicht nach dem Fenster. */
  .formularseite {
    min-width: 0;
    container-type: inline-size;
  }

  .versteckt {
    display: none;
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
