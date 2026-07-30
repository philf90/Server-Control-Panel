<script lang="ts">
  // Der Editor: eine Datei bearbeiten, prüfen lassen, speichern.
  //
  // Er steht als eigene Fläche über der Werkbank und nicht im Inspektor. Der
  // Grund ist Platz: Eine Konfigurationsdatei liest man in Zeilen von achtzig
  // Zeichen, und ein Drawer von 26 rem bricht jede zweite um. Auf der eigenen
  // Fläche bleibt der Krumenpfad stehen, also auch der Weg zurück.
  //
  // Drei Dinge trägt diese Komponente, und alle drei sind Zusagen des Servers,
  // die hier nur sichtbar werden:
  //
  //  1. Zeilenenden bleiben. crlf und ohne_schlussumbruch gehen unverändert
  //     zurück; der Editor arbeitet immer in LF.
  //  2. Ein Konflikt überschreibt nicht. Der Server antwortet 412, und diese
  //     Fläche zeigt dann BEIDE Stände — den eigenen im Editor, den fremden zur
  //     Übernahme. Erst ein zweiter, ausdrücklicher Klick überschreibt.
  //  3. Lehnt das Prüfprogramm ab, ist der Vorzustand zurückgeschrieben. Die
  //     Ausgabe des Programms steht wörtlich da, nicht zusammengefasst — sie ist
  //     die einzige Auskunft darüber, was falsch ist.
  import { AbgemeldetFehler, Pruefungabgelehnt, Textkonflikt, api } from "../lib/api";
  import { t } from "../lib/texte";
  import type { Griff } from "../lib/editorkern";
  import type { Dateitext, Pruefung } from "../lib/typen";

  let {
    pfad,
    darfSchreiben = false,
    schliessen,
    gespeichert,
  }: {
    pfad: string;
    darfSchreiben?: boolean;
    schliessen: () => void;
    /** gespeichert meldet der Seite, dass sich etwas geändert hat — sie holt dann
     *  die Liste neu (Größe und Zeitpunkt stehen darin). */
    gespeichert: () => void;
  } = $props();

  let text = $state<Dateitext | null>(null);
  let ladeFehler = $state("");
  let kasten: HTMLDivElement | undefined = $state();
  let griff = $state<Griff | null>(null);
  /** kernFehler steht, wenn der nachgeladene Brocken nicht kommt. Ein eigener
   *  Weg, weil er anders zu beheben ist als ein Serverfehler: neu laden. */
  let kernFehler = $state("");

  let geaendert = $state(false);
  let speichert = $state(false);
  let meldung = $state("");
  let fehler = $state("");
  let pruefung = $state<Pruefung | null>(null);
  let zurueck = $state("");
  /** konflikt trägt den fremden Stand von der Platte. Solange er steht, ist
   *  „speichern" zu „überschreiben" geworden. */
  let konflikt = $state<{ meldung: string; fremd: Dateitext } | null>(null);

  // Laden und Aufbauen in einem Effekt: Der Editor entsteht erst, wenn Inhalt
  // UND Element da sind, und er wird beim Wechsel des Pfades vollständig
  // abgebaut. Ohne das Abbauen bliebe die alte Ansicht mit ihren Fensterhorchern
  // liegen — und beim zweiten Öffnen stünden zwei Editoren übereinander.
  $effect(() => {
    const ziel = pfad;
    let abgebrochen = false;

    void (async () => {
      try {
        const geladen = await api.text(ziel);
        if (abgebrochen) return;
        text = geladen;
      } catch (e) {
        if (e instanceof AbgemeldetFehler) throw e;
        if (!abgebrochen) ladeFehler = e instanceof Error ? e.message : t.fehler.laden;
      }
    })();

    return () => {
      abgebrochen = true;
      griff?.zerstoeren();
      griff = null;
      text = null;
      geaendert = false;
      meldung = "";
      fehler = "";
      pruefung = null;
      zurueck = "";
      konflikt = null;
      ladeFehler = "";
      kernFehler = "";
    };
  });

  // Der zweite Effekt hängt den Editor ein, sobald beides steht. Getrennt vom
  // ersten, weil das Element erst nach dem Rendern existiert — und weil das
  // dynamische import() hier steht: Es ist die Stelle, an der der Brocken
  // tatsächlich gebraucht wird, und keine Zeile früher.
  $effect(() => {
    if (!text || !kasten || griff) return;
    const inhalt = text.inhalt;
    const spr = text.sprache;
    const el = kasten;

    void (async () => {
      try {
        const kern = await import("../lib/editorkern");
        // In der Zwischenzeit kann der Pfad gewechselt haben.
        if (!kasten || kasten !== el || griff) return;
        griff = kern.erzeuge(el, {
          inhalt,
          sprache: spr,
          beiAenderung: () => {
            geaendert = true;
            // Die Meldung des letzten Speichervorgangs gilt nicht mehr, sobald
            // wieder getippt wird. Sie stehen zu lassen hieße, „Gespeichert" über
            // ungespeicherten Änderungen anzuzeigen.
            meldung = "";
          },
        });
      } catch (e) {
        kernFehler = e instanceof Error ? e.message : t.dateien.editorNichtGeladen;
      }
    })();
  });

  async function speichern(ueberschreiben = false) {
    if (!text || !griff) return;
    speichert = true;
    meldung = "";
    fehler = "";
    pruefung = null;
    zurueck = "";
    try {
      const antwort = await api.textSpeichern({
        pfad: text.eintrag.path,
        inhalt: griff.inhalt(),
        hash: text.hash,
        crlf: text.crlf,
        ohne_schlussumbruch: text.ohne_schlussumbruch,
        ueberschreiben,
      });
      konflikt = null;
      geaendert = false;
      meldung = antwort.meldung;
      pruefung = antwort.pruefung ?? null;
      // Der neue Stand — vor allem der neue Hash. Ohne ihn liefe das nächste
      // Speichern in einen Konflikt mit der eigenen Änderung.
      text = antwort.text;
      gespeichert();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      if (e instanceof Textkonflikt) {
        // Der eigene Inhalt bleibt im Editor stehen. Das ist der Kern: Die
        // eigene Arbeit geht nicht verloren, nur weil jemand anders gespeichert
        // hat.
        konflikt = { meldung: e.meldung, fremd: e.jetzt };
        // Der Hash wandert auf den Stand der Platte — sonst wäre auch der zweite
        // Versuch ein Konflikt.
        text = { ...text, hash: e.jetzt.hash };
        return;
      }
      if (e instanceof Pruefungabgelehnt) {
        fehler = e.meldung;
        pruefung = e.pruefung;
        zurueck = e.zurueck;
        // Der Stand nach dem Rückweg trägt einen frischen Hash: Ein korrigierter
        // zweiter Versuch soll nicht in einen Konflikt laufen, den der Rückweg
        // selbst erzeugt hat.
        if (e.text?.hash) text = { ...text, hash: e.text.hash };
        return;
      }
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      speichert = false;
    }
  }

  /** fremdenStandUebernehmen wirft die eigene Fassung weg und lädt die von der
   *  Platte. Der ehrliche zweite Weg aus einem Konflikt — und er verlangt keine
   *  Rückfrage, weil die eigene Fassung noch nirgends stand. */
  function fremdenStandUebernehmen() {
    if (!konflikt || !griff) return;
    griff.ersetzen(konflikt.fremd.inhalt);
    text = konflikt.fremd;
    konflikt = null;
    geaendert = false;
    meldung = t.dateien.fremdUebernommen;
  }
</script>

<div class="editor">
  <header>
    <div class="wo">
      <span class="name">{text?.eintrag.name ?? pfad.split("/").pop()}</span>
      {#if text}
        <span class="pfad">{text.verzeichnis}</span>
      {/if}
    </div>
    {#if text?.sprache}
      <span class="marke">{text.sprache}</span>
    {/if}
    {#if text?.crlf}
      <!-- Sichtbar, weil es eine Zusage ist: Der Editor arbeitet in LF und
           schreibt CRLF zurück. Ohne diesen Hinweis sieht ein Diff später aus
           wie eine Änderung an jeder Zeile. -->
      <span class="marke">CRLF</span>
    {/if}
    {#if geaendert}
      <span class="marke warn">{t.dateien.ungespeichert}</span>
    {/if}
    <div class="schub"></div>
    <button type="button" class="knopf leise klein" onclick={schliessen}>
      {t.dateien.editorSchliessen}
    </button>
  </header>

  {#if ladeFehler}
    <p class="warnung" role="alert">{ladeFehler}</p>
  {:else if !text}
    <p class="detail">{t.dateien.laedt}</p>
  {:else}
    {#if text.pruefbar}
      <!-- Die Zusage VOR dem Speichern und nicht erst im Ergebnis: Wer weiß, dass
           sshd -t die Datei prüft und ein Fehler zurückgerollt wird, editiert
           anders. -->
      <p class="band info">{t.dateien.wirdGeprueft(text.werkzeug ?? "")}</p>
    {/if}

    {#if konflikt}
      <div class="band schlecht" role="alert">
        <p>{konflikt.meldung}</p>
        <button type="button" class="knopf leise klein" onclick={fremdenStandUebernehmen}>
          {t.dateien.fremdenStandLaden}
        </button>
      </div>
    {/if}

    {#if fehler}
      <div class="band schlecht" role="alert">
        <p>{fehler}</p>
        {#if pruefung?.ausgabe}
          <!-- Wörtlich und nicht zusammengefasst: Die Ausgabe des Prüfprogramms
               ist die einzige Auskunft darüber, WAS falsch ist. Grundsatz IV,
               „Das Panel verschweigt nichts." -->
          <pre>{pruefung.ausgabe}</pre>
        {/if}
        {#if zurueck}
          <p class="zurueck">{zurueck}</p>
        {/if}
      </div>
    {/if}

    {#if meldung}
      <p class="band gut" role="status">{meldung}</p>
    {/if}

    {#if kernFehler}
      <p class="warnung" role="alert">{t.dateien.editorNichtGeladen} {kernFehler}</p>
    {/if}

    <!-- Der Kasten steht immer, auch bevor der Brocken da ist: Sonst wäre das
         Element beim Eintreffen nicht vorhanden und der Editor bekäme kein
         Zuhause. -->
    <div class="kasten" bind:this={kasten}></div>

    <div class="fuss">
      {#if darfSchreiben}
        <button
          type="button"
          class="knopf"
          disabled={speichert || !griff}
          onclick={() => speichern(konflikt !== null)}
        >
          {#if speichert}
            {t.dateien.speichertGerade}
          {:else if konflikt}
            {t.dateien.ueberschreiben2}
          {:else}
            {t.dateien.speichern}
          {/if}
        </button>
      {:else}
        <p class="detail">{t.dateien.nurLesen}</p>
      {/if}
      <div class="schub"></div>
      <span class="detail">{t.dateien.grenzeEditor(text.max_edit_text)}</span>
    </div>
  {/if}
</div>

<style>
  .editor {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 0.8rem 0.9rem 0.9rem;
    display: grid;
    gap: 0.7rem;
    min-width: 0;
  }

  header {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    flex-wrap: wrap;
    min-width: 0;
  }

  .wo {
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
    flex-wrap: wrap;
    min-width: 0;
  }

  .name {
    font: 650 0.92rem var(--mono);
  }

  .pfad {
    color: var(--tx-faint);
    font: 0.76rem var(--mono);
    overflow-wrap: anywhere;
  }

  .kasten {
    min-width: 0;
  }

  .band {
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.5rem 0.7rem;
    font-size: 0.8rem;
    color: var(--tx-mut);
    display: grid;
    gap: 0.45rem;
    justify-items: start;
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .band.info {
    border-color: var(--line2);
  }

  .band.gut {
    border-color: var(--ok);
    color: var(--ok);
  }

  .band.schlecht {
    border-color: var(--err);
    color: var(--err);
  }

  .band pre {
    width: 100%;
    max-height: 12rem;
    overflow: auto;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 6px;
    padding: 0.5rem 0.6rem;
    color: var(--tx);
    font: 0.76rem var(--mono);
    white-space: pre-wrap;
  }

  .band .zurueck {
    color: var(--tx-mut);
  }

  .fuss {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
  }

  .detail {
    color: var(--tx-mut);
    font: 0.78rem var(--mono);
  }

  .warnung {
    font-size: 0.82rem;
    color: var(--err);
    overflow-wrap: anywhere;
  }
</style>
