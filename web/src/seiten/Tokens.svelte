<script lang="ts">
  // API-Tokens: Zugänge für Skripte.
  //
  // Diese Seite hat eine Aufgabe, die keine andere hat: Sie zeigt ein Geheimnis
  // GENAU EINMAL. Alles daran ist darauf ausgerichtet:
  //
  //  1. **Der Token steht in einem Dialog, der geschlossen werden MUSS.** Nicht
  //     in einem Band, das beim nächsten Klick verschwindet — dasselbe Muster wie
  //     beim Einmalpasswort der Panel-Zugänge, und aus demselben Grund: Ein Band
  //     fällt niemandem auf, und danach ist der Token weg.
  //  2. **Der Dialog zeigt gleich, WIE man ihn benutzt** — mit dem fertigen
  //     curl-Aufruf samt Hostnamen. Grundsatz V: Die Oberfläche erklärt sich dort,
  //     wo etwas geschieht. Ohne das Beispiel muss man an genau dem Punkt, an dem
  //     man das Geheimnis in der Hand hält, eine Dokumentation suchen.
  //  3. **Die Seite schreibt den Token nirgends hin.** Nicht in die Adresse, nicht
  //     in den Sitzungsspeicher, nicht in eine Variable, die eine Liste überlebt.
  //     Nach dem Schließen des Dialogs ist er fort.
  //
  // Und sie NENNT, was ein Token nicht kann: Die gesperrten Flächen stehen als
  // eigener Block da. Wer einen Token für die Kontoverwaltung sucht, soll
  // erfahren, dass es den nicht gibt und warum — nicht durch einen 403 in einer
  // Woche.
  import Rueckfrage from "../komponenten/Rueckfrage.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, VerbotenFehler, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { normal } from "../lib/ziele";
  import type { Bestaetigung, Tokenauftrag, Tokens, Tokenzeile } from "../lib/typen";

  let { host = "" }: { host?: string } = $props();

  let daten = $state<Tokens | null>(null);
  let fehler = $state("");
  /** verboten heißt: Die Rolle darf das nicht. Ein anderer Zustand als ein
   *  Ladefehler, weil er einen anderen Ausgang hat — es gibt keinen zweiten
   *  Versuch, der ein anderes Ergebnis bringt. */
  let verboten = $state("");
  let filter = $state("");

  let laufend = $state("");
  let meldung = $state("");
  let handlungFehler = $state("");
  let offeneFrage = $state<{
    frage: Bestaetigung;
    weiter: (getippt: string) => Promise<void>;
  } | null>(null);

  /** Das Formular. */
  let formularOffen = $state(false);
  let fName = $state("");
  let fNurLesen = $state(true);
  let fScopes = $state<string[]>([]);
  let fTage = $state(30);

  /** gezeigterToken ist der Klartext aus der letzten Antwort. Er steht in einem
   *  Dialog, der geschlossen werden muss, weil er kein zweites Mal kommt — und er
   *  wird beim Schließen aus dem Zustand entfernt. */
  let gezeigterToken = $state<{ token: string; name: string; hinweis: string } | null>(null);
  let tokenDialog: HTMLDialogElement | undefined = $state();
  let kopiert = $state(false);

  const gefiltert = $derived.by(() => {
    const alle = daten?.tokens ?? [];
    const b = normal(filter.trim());
    if (!b) return alle;
    return alle.filter(
      (z) =>
        normal(z.name).includes(b) ||
        normal(z.konto).includes(b) ||
        normal(z.prefix).includes(b) ||
        z.scopes.some((s) => normal(s).includes(b)),
    );
  });

  /** offene sind die Tokens, die eine Entscheidung offenlassen: abgelaufen, ohne
   *  Ablauf, bald ablaufend oder nie benutzt. Die Zahl steht oben — Grundsatz II:
   *  jede Zahl ist ein Griff, und diese ist der Grund, die Seite zu öffnen. */
  const offene = $derived((daten?.tokens ?? []).filter((z) => z.zustand !== "gut").length);

  $effect(() => {
    // Der Dialog erst am eingehängten Element: showModal() gibt es vorher nicht.
    if (gezeigterToken && tokenDialog && !tokenDialog.open) {
      tokenDialog.showModal();
    }
  });

  async function laden() {
    fehler = "";
    try {
      daten = await api.tokens();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      if (e instanceof VerbotenFehler) {
        verboten = e.message;
        return;
      }
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  function meldungenLeeren() {
    meldung = "";
    handlungFehler = "";
  }

  function formularUmschalten() {
    meldungenLeeren();
    formularOffen = !formularOffen;
    if (formularOffen) {
      fName = "";
      fNurLesen = true;
      fScopes = [];
      fTage = daten?.fristen[0]?.tage ?? 30;
    }
  }

  function scopeUmschalten(wert: string) {
    fScopes = fScopes.includes(wert)
      ? fScopes.filter((s) => s !== wert)
      : [...fScopes, wert];
  }

  /** handlung führt eine verändernde Handlung aus und behandelt die Rückfrage —
   *  dasselbe Muster wie in den anderen Modulen: Der zweite Anlauf ist DERSELBE
   *  Aufruf mit bestaetigt=true. */
  async function handlung(
    marke: string,
    ruf: (bestaetigt: boolean, getippt: string) => Promise<{
      meldung: string;
      token?: string;
      hinweis?: string;
    }>,
    name: string,
    nachher: () => void,
  ) {
    laufend = marke;
    meldungenLeeren();

    const lauf = async (bestaetigt: boolean, getippt: string) => {
      const antwort = await ruf(bestaetigt, getippt);
      offeneFrage = null;
      if (antwort.token) {
        // Der Klartext geht DIREKT in den Dialog und nicht erst in eine
        // Meldungszeile: Er soll nur an einer Stelle stehen, und die muss
        // geschlossen werden.
        gezeigterToken = {
          token: antwort.token,
          name,
          hinweis: antwort.hinweis ?? t.tokens.einmalWarnung,
        };
        kopiert = false;
        meldung = antwort.meldung;
      } else {
        meldung = antwort.meldung;
      }
      await laden();
      nachher();
    };

    try {
      await lauf(false, "");
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      if (e instanceof BestaetigungNoetig) {
        offeneFrage = { frage: e.bestaetigung, weiter: (wort) => lauf(true, wort) };
        return;
      }
      handlungFehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      laufend = "";
    }
  }

  async function frageBestaetigen(getippt: string) {
    const jetzt = offeneFrage;
    if (!jetzt) return;
    laufend = laufend || "frage";
    try {
      await jetzt.weiter(getippt);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      if (e instanceof BestaetigungNoetig) {
        offeneFrage = { frage: e.bestaetigung, weiter: jetzt.weiter };
        return;
      }
      offeneFrage = null;
      handlungFehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      laufend = "";
    }
  }

  function anlegen(ev: SubmitEvent) {
    ev.preventDefault();
    const auftrag: Tokenauftrag = {
      name: fName.trim(),
      scopes: fScopes,
      nur_lesen: fNurLesen,
      tage: fTage,
    };
    if (!auftrag.name) return;
    void handlung(
      "anlegen",
      (b, g) => api.tokenAnlegen(auftrag, b, g),
      auftrag.name,
      () => {
        formularOffen = false;
      },
    );
  }

  function widerrufen(z: Tokenzeile) {
    void handlung("widerrufen", (b, g) => api.tokenWiderrufen(z.id, b, g), z.name, () => {});
  }

  async function tokenKopieren() {
    if (!gezeigterToken) return;
    try {
      await navigator.clipboard.writeText(gezeigterToken.token);
      kopiert = true;
    } catch {
      // Die Zwischenablage braucht einen sicheren Ursprung und die Erlaubnis des
      // Browsers. Fehlt beides, bleibt der Token lesbar auf dem Schirm — der
      // Knopf ist eine Bequemlichkeit und nicht der Weg.
      kopiert = false;
    }
  }

  function tokenSchliessen() {
    tokenDialog?.close();
    // Der Klartext verschwindet aus dem Zustand. Er soll nach dem Schließen nicht
    // mehr im Speicher der Seite liegen.
    gezeigterToken = null;
    kopiert = false;
  }

  laden();
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.sicherheit} / {t.ziele.tokens}</div>
    <div class="h1">{t.ziele.tokens}</div>
  </div>
  <div class="schub"></div>
  {#if daten && offene > 0}
    <span class="marke warn">{offene} zu prüfen</span>
  {/if}
</div>

<p class="wesen">{t.tokens.wesen}</p>

{#if verboten}
  <!-- Kein Knopf „Erneut versuchen": Er brächte nie ein anderes Ergebnis. -->
  <div class="hinweis">
    <p>{verboten}</p>
    <p class="detail">{t.tokens.nurOwner}</p>
  </div>
{:else if fehler && !daten}
  <div class="hinweis">
    <p>{t.fehler.laden}</p>
    <p class="detail">{fehler}</p>
    <button class="knopf" onclick={laden}>{t.fehler.erneut}</button>
  </div>
{:else if !daten}
  <p class="detail">{t.tokens.laedt}</p>
{:else}
  <div class="werkzeuge">
    <label class="suche">
      <span class="nur-vorlese">{t.tokens.suchen}</span>
      <input
        bind:value={filter}
        type="search"
        placeholder={t.tokens.suchen}
        autocomplete="off"
        spellcheck="false"
      />
    </label>
    <div class="schub"></div>
    <button
      type="button"
      class="knopf leise klein"
      class:an={formularOffen}
      onclick={formularUmschalten}
    >
      + {t.tokens.anlegen}
    </button>
  </div>

  {#if formularOffen}
    <form class="anlegen" onsubmit={anlegen}>
      <b>{t.tokens.anlegenTitel}</b>

      <label>
        <span>{t.tokens.name}</span>
        <!-- svelte-ignore a11y_autofocus -->
        <input
          bind:value={fName}
          type="text"
          autocomplete="off"
          spellcheck="false"
          autofocus
          placeholder={t.tokens.namePlatzhalter}
        />
        <small>{t.tokens.nameHinweis}</small>
      </label>

      <fieldset>
        <legend>{t.tokens.rechte}</legend>
        <!-- Nur-Lesen ist die Vorbelegung: Wer die Auswahl übersieht, bekommt den
             engeren Token, nicht den weiteren. -->
        <label class="schalter">
          <input type="radio" bind:group={fNurLesen} value={true} />
          <span>{t.tokens.nurLesen}</span>
        </label>
        <label class="schalter">
          <input type="radio" bind:group={fNurLesen} value={false} />
          <span>{t.tokens.lesenUndSchreiben}</span>
        </label>
        <small>{t.tokens.rechteHinweis}</small>
      </fieldset>

      <fieldset>
        <legend>{t.tokens.flaechen}</legend>
        <div class="flaechen">
          {#each daten.familien as f (f.wert)}
            <label class="kaestchen" title={f.was}>
              <input
                type="checkbox"
                checked={fScopes.includes(f.wert)}
                onchange={() => scopeUmschalten(f.wert)}
              />
              <span>{f.wert}</span>
            </label>
          {/each}
        </div>
        <small>{t.tokens.flaechenHinweis}</small>
      </fieldset>

      <label>
        <span>{t.tokens.fristFeld}</span>
        <select bind:value={fTage}>
          {#each daten.fristen as f (f.tage)}
            <option value={f.tage}>{f.name}</option>
          {/each}
        </select>
        <small>{t.tokens.fristHinweis}</small>
      </label>

      <div class="aktionen">
        <button type="submit" class="knopf" disabled={!fName.trim() || laufend !== ""}>
          {t.tokens.anlegen}
        </button>
        <button type="button" class="knopf leise" onclick={() => (formularOffen = false)}>
          {t.tokens.abbrechen}
        </button>
      </div>
    </form>
  {/if}

  {#if meldung}
    <p class="band gut" role="status">{meldung}</p>
  {/if}
  {#if handlungFehler}
    <p class="band schlecht" role="alert">{handlungFehler}</p>
  {/if}

  <div class="tabelle-rahmen">
    <table class="tabelle">
      <thead>
        <tr>
          <th>{t.tokens.name}</th>
          <th>{t.tokens.konto}</th>
          <th>{t.tokens.umfang}</th>
          <th>{t.tokens.frist}</th>
          <th>{t.tokens.zuletzt}</th>
          <th>{t.tokens.zustandSpalte}</th>
          <th>{t.tokens.handgriff}</th>
        </tr>
      </thead>
      <tbody>
        {#if gefiltert.length === 0}
          <tr>
            <td colspan="7" class="gedaempft">
              {daten.tokens.length === 0 ? t.tokens.nichts : t.tokens.nichtsGefiltert}
            </td>
          </tr>
        {:else}
          {#each gefiltert as z (z.id)}
            <tr>
              <td data-spalte={t.tokens.name}>
                <div class="namenszelle">
                  <b>{z.name}</b>
                  <!-- Der sichtbare Anfang: Wer drei Tokens in drei Skripten
                       liegen hat, erkennt daran, welcher welcher ist. -->
                  <code class="anfang">{daten.praefix}{z.prefix}…</code>
                </div>
              </td>
              <td data-spalte={t.tokens.konto}>
                <span class="rolle">{z.konto}</span>
                <span class="gedaempft">{z.rolle}</span>
                {#if z.ich}<span class="marke">ich</span>{/if}
              </td>
              <td data-spalte={t.tokens.umfang}>
                <div class="umfang">
                  <span class:nurlesen={z.nur_lesen}>
                    {z.nur_lesen ? t.tokens.nurLesen : t.tokens.lesenUndSchreiben}
                  </span>
                  <span class="gedaempft">
                    {z.scopes.length === 0 ? t.tokens.alleFlaechen : z.scopes.join(", ")}
                  </span>
                </div>
              </td>
              <td data-spalte={t.tokens.frist} class="gedaempft">
                {z.frist || t.tokens.ohneAblauf}
              </td>
              <td data-spalte={t.tokens.zuletzt} class="gedaempft">
                {#if z.nie_benutzt}
                  {t.tokens.nieBenutzt}
                {:else}
                  {z.zuletzt_am}
                  {#if z.zuletzt_von}<span class="anfang">{z.zuletzt_von}</span>{/if}
                {/if}
              </td>
              <td data-spalte={t.tokens.zustandSpalte}>
                <span class="zustand {z.zustand}" title={z.zustand_text}>
                  <i aria-hidden="true"></i>{z.zustand_text}
                </span>
              </td>
              <td data-spalte={t.tokens.handgriff}>
                <button
                  type="button"
                  class="knopf gefahr klein"
                  disabled={laufend !== ""}
                  onclick={() => widerrufen(z)}
                >
                  {t.tokens.widerrufen}
                </button>
              </td>
            </tr>
          {/each}
        {/if}
      </tbody>
    </table>
  </div>

  <!-- Was ein Token NICHT kann. Ein eigener Block und keine Fußnote: Wer einen
       Token für die Kontoverwaltung sucht, soll es hier erfahren und nicht durch
       einen 403 in einer Woche. -->
  <section class="platte gesperrt">
    <b>{t.tokens.gesperrtTitel}</b>
    <div class="marken">
      {#each daten.gesperrt as g (g)}
        <span class="marke">{g}</span>
      {/each}
    </div>
    <p class="detail">{t.tokens.gesperrtHinweis}</p>
  </section>
{/if}

{#if gezeigterToken}
  <!-- Der Dialog MUSS geschlossen werden: kein Escape, kein Klick daneben. Der
       Token kommt kein zweites Mal, und ein Dialog, der sich versehentlich
       schließt, nimmt ihn mit. Dasselbe Muster wie beim Einmalpasswort. -->
  <dialog
    bind:this={tokenDialog}
    class="einmal"
    aria-labelledby="token-titel"
    oncancel={(e) => e.preventDefault()}
  >
    <h2 id="token-titel">{t.tokens.einmalTitel}: {gezeigterToken.name}</h2>
    <p class="warnung">{gezeigterToken.hinweis}</p>

    <div class="geheimnis">
      <code>{gezeigterToken.token}</code>
    </div>

    <b class="benutzung">{t.tokens.benutzung}</b>
    <pre class="beispiel">{t.tokens.benutzungBefehl(
        host || "panel.example",
        gezeigterToken.token,
      )}</pre>

    <div class="knoepfe">
      <button type="button" class="knopf leise" onclick={tokenKopieren}>
        {kopiert ? t.tokens.kopiert : t.tokens.kopieren}
      </button>
      <button type="button" class="knopf" onclick={tokenSchliessen}>
        {t.tokens.einmalSchliessen}
      </button>
    </div>
  </dialog>
{/if}

{#if offeneFrage}
  <Rueckfrage
    frage={offeneFrage.frage}
    laeuft={laufend !== ""}
    bestaetigen={frageBestaetigen}
    abbrechen={() => (offeneFrage = null)}
  />
{/if}

<style>
  .wesen {
    color: var(--tx-mut);
    font-size: 0.85rem;
    margin-bottom: 1rem;
    max-width: 70ch;
  }

  .werkzeuge {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
    margin-bottom: 0.8rem;
  }

  .suche input {
    min-width: 16rem;
  }

  .schub {
    flex: 1;
  }

  .anlegen {
    display: grid;
    gap: 0.7rem;
    border: 1px solid var(--li);
    border-radius: 6px;
    padding: 1rem;
    margin-bottom: 1rem;
    max-width: 64ch;
  }

  .anlegen label {
    display: grid;
    gap: 0.2rem;
  }

  .anlegen label.schalter,
  .anlegen label.kaestchen {
    display: flex;
    align-items: center;
    gap: 0.4rem;
  }

  .anlegen small {
    color: var(--tx-mut);
    font-size: 0.75rem;
  }

  fieldset {
    border: 1px solid var(--li);
    border-radius: 4px;
    padding: 0.6rem 0.8rem;
    display: grid;
    gap: 0.3rem;
  }

  legend {
    font-size: 0.78rem;
    color: var(--tx-mut);
    padding: 0 0.3rem;
  }

  /* Die Flächen als Gitter: Sechzehn Kästchen in einer Spalte wären eine Liste,
   * durch die man scrollt, statt einer Auswahl, die man überblickt. */
  .flaechen {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));
    gap: 0.15rem 0.6rem;
  }

  .flaechen span {
    font: 0.76rem var(--mono);
  }

  .namenszelle {
    display: grid;
    gap: 0.1rem;
  }

  .anfang {
    font: 0.72rem var(--mono);
    color: var(--tx-mut);
  }

  .umfang {
    display: grid;
    gap: 0.1rem;
    font-size: 0.8rem;
  }

  /* Nur-Lesen ist der harmlose Fall und wird deshalb NICHT hervorgehoben. Der
   * hervorgehobene ist der schreibende — das ist der, den man beim Durchsehen
   * einer Liste finden will. */
  .umfang span:not(.nurlesen):not(.gedaempft) {
    color: var(--accent);
  }

  .platte.gesperrt {
    border: 1px solid var(--li);
    border-radius: 6px;
    padding: 0.9rem 1rem;
    margin-top: 1.5rem;
    display: grid;
    gap: 0.5rem;
    max-width: 80ch;
  }

  .marken {
    display: flex;
    gap: 0.35rem;
    flex-wrap: wrap;
  }

  dialog.einmal {
    width: min(46rem, calc(100vw - 2rem));
    background: var(--surface);
    color: var(--tx);
    border: 1px solid var(--line2);
    border-radius: 12px;
    padding: 1.1rem 1.2rem 1rem;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.55);
  }

  dialog.einmal::backdrop {
    background: rgba(0, 0, 0, 0.6);
  }

  dialog.einmal h2 {
    font-size: 1rem;
    margin: 0 0 0.5rem;
  }

  .geheimnis {
    border: 1px solid var(--accent-dim);
    border-radius: 4px;
    padding: 0.6rem 0.7rem;
    margin: 0.6rem 0;
    background: var(--bg);
  }

  .geheimnis code {
    font: 0.85rem var(--mono);
    color: var(--accent);
    /* Ein Token ist lang. Umbrechen statt scrollen: Wer ihn abschreibt, soll ihn
     * ganz sehen. */
    overflow-wrap: anywhere;
    user-select: all;
  }

  .benutzung {
    font-size: 0.82rem;
  }

  .beispiel {
    border: 1px solid var(--li);
    border-radius: 4px;
    padding: 0.5rem 0.6rem;
    margin: 0.3rem 0 0.8rem;
    font: 0.72rem var(--mono);
    color: var(--tx-mut);
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }

  .knoepfe {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
  }

  .band {
    border: 1px solid var(--li);
    border-radius: 4px;
    padding: 0.5rem 0.7rem;
    margin-bottom: 0.8rem;
    font-size: 0.82rem;
    color: var(--tx-mut);
  }

  .band.gut {
    border-color: var(--ok);
    color: var(--ok);
  }

  .band.schlecht {
    border-color: var(--err);
    color: var(--err);
  }

  .knopf.an {
    border-color: var(--accent-dim);
    color: var(--accent);
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
    font-size: 0.78rem;
    overflow-wrap: anywhere;
  }

  .warnung {
    color: var(--accent);
    font-size: 0.82rem;
  }
</style>
