<script lang="ts">
  // Zeitpläne: Cron-Einträge und systemd-Timer.
  //
  // Die Seite beantwortet eine Frage — was läuft hier von allein? — und das
  // bestimmt ihren Schnitt: Cron und Timer stehen untereinander auf einer Seite
  // und nicht in zwei Reitern. Wer wissen will, was nachts geschieht, weiß
  // vorher nicht, ob es eine Crontab-Zeile oder ein Timer ist.
  //
  // Vier Dinge sind hier anders als in den übrigen Modulen, und alle vier haben
  // denselben Grund: Ein Cron-Eintrag IST ein Shell-Befehl.
  //
  //  1. **Der rohe Zeitplan und der Satz stehen beide da.** Der Satz kommt vom
  //     Server (privops.ScheduleText), damit es nur eine Auslegung der fünf
  //     Felder gibt. Wo die Worte nicht reichen, sagt er das — statt zu raten.
  //  2. **Fremde Einträge sind Auskunft und tragen keine Knöpfe.** Sie zeigen
  //     statt der Handgriffe ihre Quelle: Das ist der Weg, sie zu ändern.
  //  3. **Der Befehl steht in der Rückfrage.** Nicht „wirklich anlegen?", sondern
  //     Zeit, Benutzer und Befehl — alle drei Fehler kommen vor.
  //  4. **Abschalten ist ein eigener Handgriff, nicht ein Sonderfall von
  //     Löschen.** Ein abgeschalteter Eintrag bleibt lesbar, und einen Befehl
  //     wieder abzuschreiben ist Arbeit.
  //
  // Timer sind lesend. Sie zu schalten geht über die Dienste — ein Timer ist eine
  // Unit, und dafür gibt es dort dieselbe Allowlist und dieselbe Rückfrage. Die
  // Seite verweist dorthin statt einen zweiten Weg dafür zu bauen.
  import Inspektor from "../komponenten/Inspektor.svelte";
  import Rueckfrage from "../komponenten/Rueckfrage.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { normal } from "../lib/ziele";
  import { weg } from "../lib/weg.svelte";
  import type {
    Bestaetigung,
    Croneintrag,
    Cronauftrag,
    Timerlauf,
    Zeitplaene,
  } from "../lib/typen";

  // Diese Seite bekommt KEINE Rechte-Eigenschaft von der Schale. „darf
  // schreiben" ist hier die falsche Frage: Ein Cron-Eintrag ist eine Shell-Zeile,
  // und das Schreiben liegt deshalb bei der Owner-Rolle und nicht beim
  // Schreibrecht. Die Antwort auf die richtige Frage steht in der eigenen Antwort
  // des Servers (rahmen.darf_aendern) — an einer Stelle gerechnet, nicht an zwei.

  let daten = $state<Zeitplaene | null>(null);
  let fehler = $state("");
  let filter = $state("");
  let nurWas = $state<"" | "eigene" | "fremde" | "aus">("");

  let laufend = $state("");
  let meldung = $state("");
  let hinweis = $state("");
  let handlungFehler = $state("");
  let offeneFrage = $state<{
    frage: Bestaetigung;
    weiter: (getippt: string) => Promise<void>;
  } | null>(null);

  /** Das Formular. Es dient dem Anlegen UND dem Ändern: Ein verwalteter Eintrag
   *  ist genau eine Datei, und Ändern heißt sie neu schreiben — dieselbe Vorgabe,
   *  derselbe Weg. Ein zweites Formular „bearbeiten" würde denselben Aufruf mit
   *  anderen Worten anbieten. */
  let formularOffen = $state(false);
  let fName = $state("");
  let fPlan = $state("17 3 * * *");
  let fUser = $state("root");
  let fBefehl = $state("");
  let fBeschreibung = $state("");
  let fAktiv = $state(true);
  /** aendertName ist gesetzt, wenn das Formular einen bestehenden Eintrag
   *  überschreibt. Dann bleibt das Namensfeld gesperrt: Ein geänderter Name legt
   *  eine ZWEITE Datei an und lässt die erste laufen — der häufigste Weg, sich
   *  zwei Nachtläufe einzurichten, wo einer gemeint war. */
  let aendertName = $state("");

  /** lauf ist das Ergebnis des letzten Laufs des ausgewählten Timers. Es kommt
   *  auf Abruf und nicht mit der Liste: Es kostet einen systemctl-Aufruf und ein
   *  Journal je Unit, und auf einem gewöhnlichen Debian stehen dort zwanzig. */
  let lauf = $state<Timerlauf | null>(null);
  let laufFehler = $state("");

  const gewaehlt = $derived(weg.parameter.plan ?? "");
  const gewaehlterTimer = $derived(weg.parameter.timer ?? "");

  /** eintragSchluessel ist die Kennung einer Zeile in der Adresse. Der Name
   *  genügt nicht: Fremde Einträge haben keinen, und zwei Zeilen in derselben
   *  Datei müssen unterscheidbar bleiben. Quelle plus Zeilennummer ist beides. */
  function schluessel(e: Croneintrag): string {
    return e.zeile > 0 ? `${e.quelle}:${e.zeile}` : e.quelle;
  }

  const eintrag = $derived(
    daten?.cron.find((e) => schluessel(e) === gewaehlt) ?? null,
  );
  const timer = $derived(
    daten?.timer.find((tm) => tm.unit === gewaehlterTimer) ?? null,
  );

  const zaehler = $derived.by(() => {
    const alle = daten?.cron ?? [];
    return {
      gesamt: alle.length,
      eigene: alle.filter((e) => e.verwaltet).length,
      fremde: alle.filter((e) => !e.verwaltet).length,
      aus: alle.filter((e) => e.deaktiviert).length,
    };
  });

  const gefiltert = $derived.by(() => {
    const alle = daten?.cron ?? [];
    const b = normal(filter.trim());
    return alle.filter((e) => {
      switch (nurWas) {
        case "eigene":
          if (!e.verwaltet) return false;
          break;
        case "fremde":
          if (e.verwaltet) return false;
          break;
        case "aus":
          if (!e.deaktiviert) return false;
          break;
      }
      if (!b) return true;
      return (
        normal(e.command).includes(b) ||
        normal(e.user).includes(b) ||
        normal(e.schedule).includes(b) ||
        normal(e.schedule_text).includes(b) ||
        normal(e.kommentar).includes(b) ||
        normal(e.quelle).includes(b)
      );
    });
  });

  // Ein Wechsel des ausgewählten Timers verwirft das alte Ergebnis. Ohne das
  // stünde der Lauf des vorigen Timers unter dem Namen des neuen — die Sorte
  // Fehler, die niemand bemerkt, weil sie plausibel aussieht.
  $effect(() => {
    void gewaehlterTimer;
    lauf = null;
    laufFehler = "";
  });

  async function laden() {
    fehler = "";
    try {
      daten = await api.zeitplaene();
      // Der erste Benutzer der Liste als Vorbelegung, falls root fehlt. Auf
      // einem System ohne root-Shell wäre das Formular sonst mit einem Konto
      // vorbelegt, das es nicht gibt.
      if (daten.rahmen.benutzer.length > 0 && !daten.rahmen.benutzer.includes(fUser)) {
        fUser = daten.rahmen.benutzer[0];
      }
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  function meldungenLeeren() {
    meldung = "";
    hinweis = "";
    handlungFehler = "";
  }

  function waehlen(e: Croneintrag) {
    meldungenLeeren();
    weg.setzeAlle({ plan: schluessel(e), timer: "" }, !gewaehlt);
  }

  function schliessen() {
    meldungenLeeren();
    weg.setzeAlle({ plan: "" }, false);
  }

  function timerWaehlen(unit: string) {
    weg.setzeAlle({ timer: unit, plan: "" }, !gewaehlterTimer);
  }

  function timerSchliessen() {
    weg.setzeAlle({ timer: "" }, false);
  }

  /** formularOeffnen füllt das Formular — leer zum Anlegen, aus einem Eintrag
   *  zum Ändern. */
  function formularOeffnen(vorlage: Croneintrag | null) {
    meldungenLeeren();
    formularOffen = true;
    if (!vorlage) {
      aendertName = "";
      fName = "";
      fPlan = "17 3 * * *";
      fUser = daten?.rahmen.benutzer[0] ?? "root";
      fBefehl = "";
      fBeschreibung = "";
      fAktiv = true;
      return;
    }
    aendertName = vorlage.name;
    fName = vorlage.name;
    fPlan = vorlage.schedule;
    fUser = vorlage.user;
    fBefehl = vorlage.command;
    fBeschreibung = vorlage.kommentar;
    fAktiv = !vorlage.deaktiviert;
  }

  function formularSchliessen() {
    formularOffen = false;
    aendertName = "";
  }

  /** handlung führt eine verändernde Handlung aus und behandelt die Rückfrage —
   *  dasselbe Muster wie in den anderen Modulen: Der zweite Anlauf ist DERSELBE
   *  Aufruf mit bestaetigt=true. */
  async function handlung(
    marke: string,
    ruf: (bestaetigt: boolean, getippt: string) => Promise<{ meldung: string; hinweis?: string }>,
    nachher: () => void,
  ) {
    laufend = marke;
    meldungenLeeren();

    const lauf = async (bestaetigt: boolean, getippt: string) => {
      const antwort = await ruf(bestaetigt, getippt);
      offeneFrage = null;
      meldung = antwort.meldung;
      hinweis = antwort.hinweis ?? "";
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

  function speichern(ev: SubmitEvent) {
    ev.preventDefault();
    const auftrag: Cronauftrag = {
      name: fName.trim(),
      schedule: fPlan.trim(),
      user: fUser,
      command: fBefehl.trim(),
      kommentar: fBeschreibung.trim(),
      aktiv: fAktiv,
    };
    if (!auftrag.name || !auftrag.schedule || !auftrag.command) return;
    void handlung(
      "speichern",
      (b, g) => api.cronSpeichern(auftrag, b, g),
      () => {
        formularSchliessen();
        // Nach dem Speichern steht der Eintrag ausgewählt da — mit dem Zeitplan
        // in Worten. Das ist die Bestätigung, die zählt: Wer sich beim Feld
        // vertippt hat, sieht es an dem Satz und nicht erst am nächsten Morgen.
        const neu = (daten?.cron ?? []).find(
          (e) => e.verwaltet && e.name === auftrag.name,
        );
        if (neu) weg.setzeAlle({ plan: schluessel(neu), timer: "" }, false);
      },
    );
  }

  /** schalten schreibt denselben Eintrag mit umgekehrtem aktiv-Feld. Es ist kein
   *  eigener Endpunkt: Ein verwalteter Eintrag ist eine Datei, und sie neu zu
   *  schreiben ist der einzige Weg, sie zu ändern. */
  function schalten(e: Croneintrag, aktiv: boolean) {
    void handlung(
      aktiv ? "ein" : "aus",
      (b, g) =>
        api.cronSpeichern(
          {
            name: e.name,
            schedule: e.schedule,
            user: e.user,
            command: e.command,
            kommentar: e.kommentar,
            aktiv,
          },
          b,
          g,
        ),
      () => {},
    );
  }

  function loeschen(e: Croneintrag) {
    void handlung(
      "loeschen",
      (b, g) => api.cronLoeschen(e.name, b, g),
      () => weg.setzeAlle({ plan: "" }, false),
    );
  }

  async function laufHolen(unit: string) {
    laufFehler = "";
    laufend = "lauf";
    try {
      lauf = await api.timerLauf(unit);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      laufFehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      laufend = "";
    }
  }

  /** zeitpunkt formatiert einen RFC-3339-Zeitpunkt. Leer bleibt leer: Ein Timer
   *  ohne nächsten Lauf hat keinen, und ein Datum dafür wäre eine Behauptung. */
  function zeitpunkt(wert: string): string {
    if (!wert) return "";
    const d = new Date(wert);
    if (Number.isNaN(d.getTime())) return wert;
    return d.toLocaleString();
  }

  laden();
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.system} / {t.ziele.cron}</div>
    <div class="h1">{t.ziele.cron}</div>
  </div>
  <div class="schub"></div>
  {#if daten && zaehler.eigene > 0}
    <span class="marke">{zaehler.eigene} {t.zeitplaene.eigene}</span>
  {/if}
</div>

<p class="wesen">{t.zeitplaene.wesen}</p>

{#if fehler && !daten}
  <div class="hinweis">
    <p>{t.fehler.laden}</p>
    <p class="detail">{fehler}</p>
    <button class="knopf" onclick={laden}>{t.fehler.erneut}</button>
  </div>
{:else if !daten}
  <p class="detail">{t.zeitplaene.laedt}</p>
{:else}
  {#if daten.luecken.length > 0}
    <!-- Was nicht gelesen werden konnte, steht da. Eine unvollständige Liste als
         vollständig auszugeben wäre der Bruch von Grundsatz IV. -->
    <div class="band warn" role="status">
      <b>{t.zeitplaene.luecken}</b>
      <ul>
        {#each daten.luecken as l (l)}<li>{l}</li>{/each}
      </ul>
    </div>
  {/if}

  <div class="werkzeuge">
    <label class="suche">
      <span class="nur-vorlese">{t.zeitplaene.suchen}</span>
      <input
        bind:value={filter}
        type="search"
        placeholder={t.zeitplaene.suchen}
        autocomplete="off"
        spellcheck="false"
      />
    </label>

    <!-- Die Zähler sind die Filter — Grundsatz II: jede Zahl ist ein Griff. -->
    <div class="stufen" role="group" aria-label={t.zeitplaene.herkunft}>
      <button type="button" class:an={nurWas === ""} onclick={() => (nurWas = "")}>
        {t.zeitplaene.alle} <b>{zaehler.gesamt}</b>
      </button>
      {#if zaehler.eigene > 0}
        <button
          type="button"
          class:an={nurWas === "eigene"}
          onclick={() => (nurWas = nurWas === "eigene" ? "" : "eigene")}
        >
          {t.zeitplaene.eigene} <b>{zaehler.eigene}</b>
        </button>
      {/if}
      {#if zaehler.fremde > 0}
        <button
          type="button"
          class:an={nurWas === "fremde"}
          onclick={() => (nurWas = nurWas === "fremde" ? "" : "fremde")}
        >
          {t.zeitplaene.fremde} <b>{zaehler.fremde}</b>
        </button>
      {/if}
      {#if zaehler.aus > 0}
        <button
          type="button"
          class:an={nurWas === "aus"}
          onclick={() => (nurWas = nurWas === "aus" ? "" : "aus")}
        >
          {t.zeitplaene.aus} <b>{zaehler.aus}</b>
        </button>
      {/if}
    </div>

    <div class="schub"></div>

    {#if daten.rahmen.darf_aendern}
      <button
        type="button"
        class="knopf leise klein"
        class:an={formularOffen}
        onclick={() => (formularOffen ? formularSchliessen() : formularOeffnen(null))}
      >
        + {t.zeitplaene.anlegen}
      </button>
    {/if}
  </div>

  {#if formularOffen}
    <form class="anlegen" onsubmit={speichern}>
      <b>{aendertName ? `${t.zeitplaene.aendern}: ${aendertName}` : t.zeitplaene.anlegenTitel}</b>

      <label>
        <span>{t.zeitplaene.name}</span>
        <input
          bind:value={fName}
          type="text"
          autocomplete="off"
          spellcheck="false"
          disabled={aendertName !== ""}
          placeholder={t.zeitplaene.namePlatzhalter}
        />
        <small>{t.zeitplaene.nameHinweis}</small>
      </label>

      <label>
        <span>{t.zeitplaene.plan}</span>
        <input bind:value={fPlan} type="text" autocomplete="off" spellcheck="false" />
        <small>{t.zeitplaene.planHinweis}</small>
      </label>

      <!-- Die Vorlagen kommen vom Server, samt Satz. Sie sind der Unterschied
           zwischen „fünf Felder ausfüllen" und „nachts um drei wählen". -->
      <div class="vorlagen" role="group" aria-label={t.zeitplaene.vorlagen}>
        {#each daten.rahmen.vorlagen as v (v.schedule)}
          <button
            type="button"
            class="knopf leise klein"
            class:an={fPlan === v.schedule}
            title={v.text}
            onclick={() => (fPlan = v.schedule)}
          >
            {v.name}
          </button>
        {/each}
      </div>

      <label>
        <span>{t.zeitplaene.benutzer}</span>
        <select bind:value={fUser}>
          {#each daten.rahmen.benutzer as b (b)}
            <option value={b}>{b}</option>
          {/each}
        </select>
        <small>{t.zeitplaene.benutzerHinweis}</small>
      </label>

      <label>
        <span>{t.zeitplaene.befehl}</span>
        <input bind:value={fBefehl} type="text" autocomplete="off" spellcheck="false" />
        <small>{t.zeitplaene.befehlHinweis}</small>
      </label>

      <label>
        <span>{t.zeitplaene.beschreibung}</span>
        <input bind:value={fBeschreibung} type="text" autocomplete="off" />
        <small>{t.zeitplaene.beschreibungHinweis}</small>
      </label>

      <label class="schalter">
        <input bind:checked={fAktiv} type="checkbox" />
        <span>{t.zeitplaene.aktiv}</span>
      </label>
      <small>{t.zeitplaene.aktivHinweis}</small>

      <div class="aktionen">
        <button
          type="submit"
          class="knopf"
          disabled={!fName.trim() || !fPlan.trim() || !fBefehl.trim() || laufend !== ""}
        >
          {t.zeitplaene.speichern}
        </button>
        <button type="button" class="knopf leise" onclick={formularSchliessen}>
          {t.zeitplaene.abbrechen}
        </button>
      </div>
      <small>{t.zeitplaene.schreibtNach(daten.rahmen.verzeichnis)}</small>
    </form>
  {/if}

  {#if meldung && !gewaehlt}
    <p class="band gut" role="status">{meldung}</p>
  {/if}
  {#if hinweis && !gewaehlt}
    <p class="band warn" role="status">{hinweis}</p>
  {/if}
  {#if handlungFehler && !gewaehlt}
    <p class="band schlecht" role="alert">{handlungFehler}</p>
  {/if}

  <div class="werkbank" class:allein={!gewaehlt}>
    <div class="tabelle-rahmen">
      <table class="tabelle">
        <thead>
          <tr>
            <th>{t.zeitplaene.wann}</th>
            <th>{t.zeitplaene.wer}</th>
            <th>{t.zeitplaene.was}</th>
            <th>{t.zeitplaene.herkunft}</th>
          </tr>
        </thead>
        <tbody>
          {#if gefiltert.length === 0}
            <tr><td colspan="4" class="gedaempft">{t.zeitplaene.nichts}</td></tr>
          {:else}
            {#each gefiltert as e (schluessel(e))}
              <tr class:gewaehlt={schluessel(e) === gewaehlt} class:aus={e.deaktiviert}>
                <td data-spalte={t.zeitplaene.wann}>
                  <button type="button" class="zeile" onclick={() => waehlen(e)}>
                    <!-- Der Satz oben, das rohe Feld darunter. Beides: Der Satz
                         ist die Lesehilfe, das Feld ist die Wahrheit. -->
                    <span class="satz">{e.schedule_text || e.schedule}</span>
                    <span class="roh" title={t.zeitplaene.rohHinweis}>{e.schedule}</span>
                  </button>
                </td>
                <td data-spalte={t.zeitplaene.wer}>
                  <span class="rolle">{e.user}</span>
                </td>
                <td data-spalte={t.zeitplaene.was}>
                  <!-- Befehl und Marke untereinander und nicht nebeneinander: Auf
                       einem Telefon ist die Zelle schmal, die Marke bricht nicht
                       um, und der Befehl bekam dann eine Spalte von einem Zeichen
                       Breite. Gesehen hat das das Bildschirmfoto bei 375 Pixeln. -->
                  <div class="befehlszelle">
                    <code class="befehl">{e.command}</code>
                    {#if e.deaktiviert}
                      <span class="marke">{t.zeitplaene.abgeschaltet}</span>
                    {/if}
                  </div>
                </td>
                <td data-spalte={t.zeitplaene.herkunft} class="gedaempft">
                  {#if e.verwaltet}
                    <span class="marke">{t.zeitplaene.eigene}</span>
                  {:else}
                    <span class="pfad">{e.quelle}</span>
                  {/if}
                </td>
              </tr>
            {/each}
          {/if}
        </tbody>
      </table>
    </div>

    {#if gewaehlt}
      <Inspektor
        titel={eintrag?.name || eintrag?.schedule || gewaehlt}
        marke={eintrag?.verwaltet ? t.zeitplaene.eigene : t.zeitplaene.fremde}
        {schliessen}
      >
        {#snippet kinder()}
          {#if !eintrag}
            <p class="detail">{t.zeitplaene.laedt}</p>
          {:else}
            <dl class="kv">
              <dt>{t.zeitplaene.wann}</dt>
              <dd>
                {eintrag.schedule_text || t.zeitplaene.keinSatz}
                <span class="roh">{eintrag.schedule}</span>
              </dd>
              <dt>{t.zeitplaene.wer}</dt>
              <dd>{eintrag.user}</dd>
              <dt>{t.zeitplaene.was}</dt>
              <dd><code class="befehl">{eintrag.command}</code></dd>
              {#if eintrag.kommentar}
                <dt>{t.zeitplaene.beschreibung}</dt>
                <dd>{eintrag.kommentar}</dd>
              {/if}
              <dt>{t.zeitplaene.herkunft}</dt>
              <dd>
                <span class="pfad">{eintrag.quelle}</span>
                {#if eintrag.zeile > 0}
                  <span class="gedaempft">{t.zeitplaene.zeileIn(eintrag.zeile)}</span>
                {/if}
              </dd>
            </dl>

            {#if eintrag.art === "skript"}
              <p class="anmerkung">{t.zeitplaene.skript}</p>
            {:else if !eintrag.verwaltet}
              <!-- Fremde Einträge sind Auskunft. Statt der Handgriffe steht da,
                   wo sie zu ändern sind — das ist der Weg, und das Panel nimmt
                   ihn niemandem weg. -->
              <p class="anmerkung">
                {t.zeitplaene.nurAuskunft} <span class="pfad">{eintrag.quelle}</span>.
              </p>
            {/if}

            {#if meldung}
              <p class="meldung" role="status">{meldung}</p>
            {/if}
            {#if hinweis}
              <p class="anmerkung" role="status">{hinweis}</p>
            {/if}
            {#if handlungFehler}
              <p class="warnung" role="alert">{handlungFehler}</p>
            {/if}

            {#if eintrag.verwaltet}
              {#if !daten.rahmen.darf_aendern}
                <p class="detail">{t.zeitplaene.nurLesen}</p>
                <p class="detail">{t.zeitplaene.nurOwner}</p>
              {:else}
                <div class="aktionen handgriffe">
                  <button
                    type="button"
                    class="knopf leise"
                    disabled={laufend !== ""}
                    onclick={() => formularOeffnen(eintrag)}
                  >
                    {t.zeitplaene.aendern}
                  </button>
                  {#if eintrag.deaktiviert}
                    <button
                      type="button"
                      class="knopf"
                      disabled={laufend !== ""}
                      onclick={() => schalten(eintrag, true)}
                    >
                      {t.zeitplaene.einschalten}
                    </button>
                  {:else}
                    <button
                      type="button"
                      class="knopf leise"
                      disabled={laufend !== ""}
                      onclick={() => schalten(eintrag, false)}
                    >
                      {t.zeitplaene.ausschalten}
                    </button>
                  {/if}
                  <button
                    type="button"
                    class="knopf gefahr"
                    disabled={laufend !== ""}
                    onclick={() => loeschen(eintrag)}
                  >
                    {t.zeitplaene.loeschen}
                  </button>
                </div>
              {/if}
            {/if}
          {/if}
        {/snippet}
      </Inspektor>
    {/if}
  </div>

  <!-- ---------------------------------------------------------- Timer ---- -->

  <h2 class="h2" id="timer">{t.zeitplaene.timerTitel}</h2>
  <p class="wesen">{t.zeitplaene.timerWesen}</p>

  {#if daten.timer_fehler}
    <div class="band warn" role="status">
      <b>{t.zeitplaene.timerFehler}</b>
      <p class="detail">{daten.timer_fehler}</p>
    </div>
  {:else if daten.timer.length === 0}
    <p class="detail">{t.zeitplaene.timerNichts}</p>
  {:else}
    <div class="werkbank" class:allein={!gewaehlterTimer}>
      <div class="tabelle-rahmen">
        <table class="tabelle">
          <thead>
            <tr>
              <th>{t.zeitplaene.timerUnit}</th>
              <th>{t.zeitplaene.timerLoest}</th>
              <th>{t.zeitplaene.timerNaechster}</th>
              <th>{t.zeitplaene.timerLetzter}</th>
            </tr>
          </thead>
          <tbody>
            {#each daten.timer as tm (tm.unit)}
              <tr class:gewaehlt={tm.unit === gewaehlterTimer}>
                <td data-spalte={t.zeitplaene.timerUnit}>
                  <button type="button" class="zeile" onclick={() => timerWaehlen(tm.unit)}>
                    <span class="satz">{tm.unit}</span>
                    {#if tm.beschreibung}
                      <span class="roh">{tm.beschreibung}</span>
                    {/if}
                  </button>
                </td>
                <td data-spalte={t.zeitplaene.timerLoest} class="gedaempft">
                  <code class="befehl">{tm.loest || "—"}</code>
                </td>
                <td data-spalte={t.zeitplaene.timerNaechster} class="gedaempft">
                  {zeitpunkt(tm.naechster) || t.zeitplaene.timerUnbekannt}
                </td>
                <td data-spalte={t.zeitplaene.timerLetzter} class="gedaempft">
                  {zeitpunkt(tm.letzter) || t.zeitplaene.timerNie}
                </td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>

      {#if gewaehlterTimer}
        <Inspektor
          titel={timer?.unit ?? gewaehlterTimer}
          zustandText={timer?.aktiv ?? ""}
          schliessen={timerSchliessen}
        >
          {#snippet kinder()}
            {#if !timer}
              <p class="detail">{t.zeitplaene.laedt}</p>
            {:else}
              <dl class="kv">
                <dt>{t.zeitplaene.timerLoest}</dt>
                <dd><code class="befehl">{timer.loest || "—"}</code></dd>
                {#if timer.plan}
                  <dt>{t.zeitplaene.plan}</dt>
                  <dd><span class="roh">{timer.plan}</span></dd>
                {/if}
                <dt>{t.zeitplaene.timerNaechster}</dt>
                <dd>{zeitpunkt(timer.naechster) || t.zeitplaene.timerUnbekannt}</dd>
                <dt>{t.zeitplaene.timerLetzter}</dt>
                <dd>{zeitpunkt(timer.letzter) || t.zeitplaene.timerNie}</dd>
              </dl>

              {#if timer.persistent}
                <p class="anmerkung">{t.zeitplaene.timerPersistent}</p>
              {/if}

              <div class="aktionen handgriffe">
                <button
                  type="button"
                  class="knopf leise"
                  disabled={laufend !== ""}
                  onclick={() => laufHolen(timer.loest || timer.unit)}
                >
                  {t.zeitplaene.laufFragen}
                </button>
                <!-- Kein eigener Schaltweg: Ein Timer ist eine Unit, und
                     start/stop/enable/disable stehen bei den Diensten — dieselbe
                     Allowlist, dieselbe Rückfrage. Ein zweiter Weg dafür wäre
                     eine zweite Stelle, an der die Rückfrage stimmen muss. -->
                <a class="knopf leise" href="/v2/dienste?unit={timer.unit}">
                  {t.zeitplaene.timerZuDenDiensten}
                </a>
              </div>

              {#if laufFehler}
                <p class="warnung" role="alert">{laufFehler}</p>
              {:else if lauf}
                <h3 class="h3">{t.zeitplaene.laufTitel}</h3>
                <p class:meldung={lauf.geglueckt} class:warnung={!lauf.geglueckt}>
                  {#if lauf.ergebnis === ""}
                    {t.zeitplaene.laufUnbekannt}
                  {:else if lauf.geglueckt}
                    {t.zeitplaene.laufGeglueckt}
                  {:else}
                    {t.zeitplaene.laufGescheitert(lauf.exit_code)} — {lauf.ergebnis}
                  {/if}
                </p>
                {#if lauf.zeilen.length > 0}
                  <pre class="journal">{lauf.zeilen.map((z) => z.nachricht).join("\n")}</pre>
                {/if}
              {/if}
            {/if}
          {/snippet}
        </Inspektor>
      {/if}
    </div>
  {/if}
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

  .h2 {
    font-size: 1.05rem;
    margin: 2rem 0 0.3rem;
  }

  .h3 {
    font-size: 0.9rem;
    margin: 1rem 0 0.3rem;
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
    gap: 0.6rem;
    border: 1px solid var(--li);
    border-radius: 6px;
    padding: 1rem;
    margin-bottom: 1rem;
    max-width: 60ch;
  }

  .anlegen label {
    display: grid;
    gap: 0.2rem;
  }

  .anlegen label.schalter {
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  .anlegen small {
    color: var(--tx-mut);
    font-size: 0.75rem;
  }

  .vorlagen {
    display: flex;
    gap: 0.35rem;
    flex-wrap: wrap;
  }

  /* Der Satz oben, das rohe Feld darunter — in einer Zelle, damit die Spalte
   * „wann" beides trägt und die Tabelle nicht eine Spalte mehr braucht.
   *
   * Die Knopfeigenschaften (kein Rahmen, kein Hintergrund, geerbte Farbe) sind
   * NICHT Beiwerk: .zeile ist keine Klasse aus app.css, sondern in jedem Modul
   * eigens gesetzt. Ohne sie stand hier ein Knopf mit den Vorgaben des Browsers —
   * hellgraue Schrift in einem Kästchen auf dunklem Grund, also unlesbar. Gesehen
   * hat das das Bildschirmfoto des Browsertests, kein Nachdenken. */
  .zeile {
    display: grid;
    gap: 0.1rem;
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

  .zeile:hover .satz {
    color: inherit;
  }

  .satz {
    color: var(--tx);
  }

  .roh {
    color: var(--tx-mut);
    font: 0.72rem var(--mono);
  }

  .befehlszelle {
    display: grid;
    gap: 0.25rem;
    justify-items: start;
    min-width: 0;
  }

  .befehl {
    font: 0.76rem var(--mono);
    overflow-wrap: anywhere;
    min-width: 0;
  }

  .pfad {
    font: 0.72rem var(--mono);
    overflow-wrap: anywhere;
  }

  /* Ein abgeschalteter Eintrag ist blasser, aber nicht weg. Er läuft nicht, und
   * das ist eine Auskunft — kein Grund, ihn zu verstecken. */
  tr.aus .satz,
  tr.aus .befehl {
    color: var(--tx-mut);
  }

  .journal {
    border: 1px solid var(--li);
    border-radius: 4px;
    padding: 0.5rem;
    font: 0.72rem var(--mono);
    max-height: 14rem;
    overflow: auto;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }

  .band {
    border: 1px solid var(--li);
    border-radius: 4px;
    padding: 0.5rem 0.7rem;
    margin-bottom: 0.8rem;
    font-size: 0.82rem;
    color: var(--tx-mut);
  }

  .band ul {
    margin: 0.3rem 0 0 1rem;
    font: 0.75rem var(--mono);
  }

  .band.gut {
    border-color: var(--ok);
    color: var(--ok);
  }

  .band.warn {
    border-color: var(--accent-dim);
    color: var(--accent);
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
    font: 0.8rem var(--mono);
    overflow-wrap: anywhere;
  }

  .meldung,
  .warnung,
  .anmerkung {
    font-size: 0.82rem;
    overflow-wrap: anywhere;
    min-width: 0;
  }

  .meldung {
    color: var(--ok);
  }

  .warnung {
    color: var(--err);
  }

  /* Eine Anmerkung ist kein Fehler: „dieser Eintrag gehört nicht dem Panel" ist
   * eine Auskunft über die Herkunft, nicht über einen Fehlschlag. */
  .anmerkung {
    color: var(--accent);
  }
</style>
