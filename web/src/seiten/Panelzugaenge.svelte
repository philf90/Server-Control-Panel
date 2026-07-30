<script lang="ts">
  // Panel-Zugänge: die Konten DIESER Oberfläche.
  //
  // Die Werkbank wie bei den Systemkonten — Liste links, Inspektor rechts. Was
  // dieses Modul davon unterscheidet, sind vier Dinge, und alle vier sind
  // Schranken:
  //
  //  1. **Das eigene Konto hat hier keine Handgriffe.** Nicht weil es verboten
  //     wäre, sondern weil sie woanders hingehören: Passwort und zweiter Faktor
  //     stehen auf der Kontoseite, sperren oder löschen wäre ein
  //     Selbstausschluss. Die Zeile ist markiert, damit das Fehlen erklärt ist.
  //  2. **Das letzte Owner-Konto bleibt.** Die Liste sagt es an der Zeile, der
  //     Server lehnt es ohnehin ab. Ein Knopf, der zuverlässig verweigert, ist
  //     die schlechteste der möglichen Antworten.
  //  3. **Zurücksetzen verlangt das EIGENE Passwort.** Das Feld steht offen im
  //     Inspektor und nicht in einem Dialog, der erst nach dem Klick kommt: Die
  //     Bedingung soll vor dem Handgriff sichtbar sein, nicht als Fehlermeldung
  //     danach.
  //  4. **Ein Einmalpasswort steht genau einmal da.** Es kommt in einen eigenen
  //     Dialog, der geschlossen werden MUSS — nicht in ein Band, das beim
  //     nächsten Klick verschwindet und niemandem auffällt.
  import Inspektor from "../komponenten/Inspektor.svelte";
  import Rueckfrage from "../komponenten/Rueckfrage.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, VerbotenFehler, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { normal } from "../lib/ziele";
  import { weg } from "../lib/weg.svelte";
  import type {
    Bestaetigung,
    Panelauftrag,
    Panelhandgriff,
    Panelkonto,
    Panelzugaenge,
  } from "../lib/typen";

  let { darfSchreiben = false }: { darfSchreiben?: boolean } = $props();

  let daten = $state<Panelzugaenge | null>(null);
  let fehler = $state("");
  /** verboten heißt: Die Rolle darf das nicht. Ein anderer Zustand als ein
   *  Ladefehler, weil er einen anderen Ausgang hat — es gibt keinen zweiten
   *  Versuch, der ein anderes Ergebnis bringt. Der Menüpunkt fehlt für diese
   *  Rollen; hier landet nur, wer den Pfad von Hand aufruft oder einen Verweis
   *  bekommen hat. */
  let verboten = $state("");
  let filter = $state("");
  let nurWas = $state<"" | "owner" | "gesperrt" | "offen">("");

  let laufend = $state("");
  let meldung = $state("");
  let hinweis = $state("");
  let handlungFehler = $state("");
  let offeneFrage = $state<{
    frage: Bestaetigung;
    weiter: (getippt: string) => Promise<void>;
  } | null>(null);

  /** anlegenOffen ist die Maske über der Liste. */
  let anlegenOffen = $state(false);
  let neuName = $state("");
  let neuRolle = $state("");

  /** eigenesPasswort ist die zweite Schranke vor jeder Zurücksetzung.
   *
   *  Es steht in einer Variablen und nicht in der Adresse, nicht im
   *  Sitzungsspeicher und nicht in einem Formular, das der Browser ausfüllen darf
   *  (autocomplete="off" am Feld). Nach jedem Aufruf wird es geleert — auch nach
   *  einem gescheiterten: Ein Feld, das nach einem 403 noch gefüllt ist, verleitet
   *  zum zweiten Versuch mit demselben falschen Wort. */
  let eigenesPasswort = $state("");

  /** gezeigtesPasswort ist das Einmalpasswort aus der letzten Antwort. Es steht in
   *  einem Dialog, der geschlossen werden muss, weil es kein zweites Mal kommt. */
  let gezeigtesPasswort = $state<{ passwort: string; konto: string; hinweis: string } | null>(
    null,
  );
  let passwortDialog: HTMLDialogElement | undefined = $state();
  let kopiert = $state(false);

  const gewaehlt = $derived(weg.parameter.zugang ?? "");
  const konto = $derived(
    daten?.konten.find((k) => String(k.id) === gewaehlt) ?? null,
  );

  const gefiltert = $derived.by(() => {
    const alle = daten?.konten ?? [];
    const b = normal(filter.trim());
    return alle.filter((k) => {
      switch (nurWas) {
        case "owner":
          if (k.rolle !== "owner") return false;
          break;
        case "gesperrt":
          if (!k.gesperrt) return false;
          break;
        case "offen":
          if (!k.einmalpasswort && k.zweiter_faktor) return false;
          break;
      }
      if (!b) return true;
      return normal(k.name).includes(b) || normal(k.rolle).includes(b);
    });
  });

  $effect(() => {
    // Der Dialog erst am eingehängten Element: showModal() gibt es vorher nicht.
    if (gezeigtesPasswort && passwortDialog && !passwortDialog.open) {
      passwortDialog.showModal();
    }
  });

  // Ein Wechsel der Auswahl leert das Passwortfeld. Ohne das stünde es beim
  // nächsten Konto noch gefüllt da, und der nächste Klick träfe ein anderes Ziel,
  // als der Tippende im Kopf hatte.
  $effect(() => {
    void gewaehlt;
    eigenesPasswort = "";
  });

  async function laden() {
    fehler = "";
    try {
      daten = await api.panelzugaenge();
      if (!neuRolle && daten.rollen.length > 0) {
        // Die harmloseste Rolle als Vorbelegung: Wer die Auswahl übersieht, legt
        // ein Konto an, das lesen darf — nicht eines, das alles darf.
        neuRolle = daten.rollen[daten.rollen.length - 1].wert;
      }
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      if (e instanceof VerbotenFehler) {
        verboten = e.message;
        return;
      }
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  function waehlen(id: number) {
    meldungenLeeren();
    weg.setzeAlle({ zugang: String(id) }, !gewaehlt);
  }

  function schliessen() {
    meldungenLeeren();
    weg.setzeAlle({ zugang: "" }, false);
  }

  function meldungenLeeren() {
    meldung = "";
    hinweis = "";
    handlungFehler = "";
  }

  function kann(h: Panelhandgriff): boolean {
    return konto?.aktionen.includes(h) ?? false;
  }

  /** handlung führt eine verändernde Handlung aus und behandelt die Rückfrage —
   *  dasselbe Muster wie in den anderen Modulen, und aus demselben Grund: Der
   *  zweite Anlauf ist DERSELBE Aufruf mit bestaetigt=true. */
  async function handlung(
    marke: string,
    wohin: string,
    felder: Partial<Panelauftrag>,
    nachher: (konto: Panelkonto | undefined) => void,
  ) {
    laufend = marke;
    meldungenLeeren();

    const lauf = async (bestaetigt: boolean, getippt: string) => {
      const antwort = await api.panelHandlung(wohin, felder, bestaetigt, getippt);
      offeneFrage = null;
      meldung = antwort.meldung;
      hinweis = antwort.hinweis ?? "";
      if (antwort.einmalpasswort) {
        gezeigtesPasswort = {
          passwort: antwort.einmalpasswort,
          konto: antwort.neues_konto || antwort.konto?.name || "",
          hinweis: antwort.hinweis ?? "",
        };
        kopiert = false;
        // Der Hinweis wandert MIT in den Dialog und bleibt nicht auch darunter
        // stehen. Zweimal derselbe Satz auf einem Schirm liest sich wie ein
        // Fehler, und der Dialog ist der Ort, an dem er gelesen wird — er ist
        // modal.
        hinweis = "";
      }
      await laden();
      nachher(antwort.konto);
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
      // Das eigene Passwort wird in jedem Fall geleert — auch nach einem 403.
      eigenesPasswort = "";
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

  function anlegen(e: SubmitEvent) {
    e.preventDefault();
    const name = neuName.trim();
    if (!name || !neuRolle) return;
    void handlung("anlegen", "", { name, rolle: neuRolle }, (angelegt) => {
      anlegenOffen = false;
      neuName = "";
      if (angelegt) weg.setzeAlle({ zugang: String(angelegt.id) }, !gewaehlt);
    });
  }

  function sperren(gesperrt: boolean) {
    if (!konto) return;
    void handlung(
      gesperrt ? "sperren" : "freigeben",
      `/${konto.id}/disabled`,
      { gesperrt },
      () => {},
    );
  }

  function loeschen() {
    if (!konto) return;
    void handlung("loeschen", `/${konto.id}/delete`, {}, () =>
      weg.setzeAlle({ zugang: "" }, false),
    );
  }

  /** zuruecksetzen ist der Weg für alle drei Zurücksetzungen. Sie unterscheiden
   *  sich nur im Pfad — die Schranke davor ist dieselbe. */
  function zuruecksetzen(was: "password" | "2fa" | "passkeys") {
    if (!konto || !eigenesPasswort) return;
    void handlung(
      was,
      `/${konto.id}/reset-${was}`,
      { eigenes_passwort: eigenesPasswort },
      () => {},
    );
  }

  async function passwortKopieren() {
    if (!gezeigtesPasswort) return;
    try {
      await navigator.clipboard.writeText(gezeigtesPasswort.passwort);
      kopiert = true;
    } catch {
      // Die Zwischenablage braucht einen sicheren Ursprung und die Erlaubnis des
      // Browsers. Fehlt beides, bleibt das Passwort lesbar auf dem Schirm — der
      // Knopf ist eine Bequemlichkeit und nicht der Weg.
      kopiert = false;
    }
  }

  function passwortSchliessen() {
    passwortDialog?.close();
    gezeigtesPasswort = null;
    kopiert = false;
  }

  laden();
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.sicherheit} / {t.ziele.zugaenge}</div>
    <div class="h1">{t.ziele.zugaenge}</div>
  </div>
  <div class="schub"></div>
  {#if daten && daten.zaehler.offen > 0}
    <span class="marke warn">{daten.zaehler.offen} {t.zugaenge.offen}</span>
  {/if}
</div>

<p class="wesen">{t.zugaenge.wesen}</p>

{#if verboten}
  <!-- Kein Knopf „Erneut versuchen": Er brächte nie ein anderes Ergebnis. Was
       stattdessen dasteht, ist der Grund — und der Weg zurück ist die
       Seitenleiste, in der dieser Punkt für diese Rolle gar nicht steht. -->
  <div class="hinweis">
    <p>{verboten}</p>
    <p class="detail">{t.zugaenge.nurOwner}</p>
  </div>
{:else if fehler && !daten}
  <div class="hinweis">
    <p>{t.fehler.laden}</p>
    <p class="detail">{fehler}</p>
    <button class="knopf" onclick={laden}>{t.fehler.erneut}</button>
  </div>
{:else if !daten}
  <p class="detail">{t.zugaenge.laedt}</p>
{:else}
  <div class="werkzeuge">
    <label class="suche">
      <span class="nur-vorlese">{t.zugaenge.suchen}</span>
      <input
        bind:value={filter}
        type="search"
        placeholder={t.zugaenge.suchen}
        autocomplete="off"
        spellcheck="false"
      />
    </label>

    <!-- Die Zähler sind die Filter — Grundsatz II: jede Zahl ist ein Griff.
         „Einrichtung offen" ist der, um den es geht: Diese Konten sind noch
         nicht fertig. -->
    <div class="stufen" role="group" aria-label={t.zugaenge.zustand}>
      <button type="button" class:an={nurWas === ""} onclick={() => (nurWas = "")}>
        {t.zugaenge.alle} <b>{daten.zaehler.gesamt}</b>
      </button>
      <button
        type="button"
        class:an={nurWas === "owner"}
        onclick={() => (nurWas = nurWas === "owner" ? "" : "owner")}
      >
        {t.zugaenge.owner} <b>{daten.zaehler.owner}</b>
      </button>
      {#if daten.zaehler.gesperrt > 0}
        <button
          type="button"
          class:an={nurWas === "gesperrt"}
          onclick={() => (nurWas = nurWas === "gesperrt" ? "" : "gesperrt")}
        >
          {t.zugaenge.gesperrt} <b>{daten.zaehler.gesperrt}</b>
        </button>
      {/if}
      {#if daten.zaehler.offen > 0}
        <button
          type="button"
          class:an={nurWas === "offen"}
          onclick={() => (nurWas = nurWas === "offen" ? "" : "offen")}
        >
          {t.zugaenge.offen} <b>{daten.zaehler.offen}</b>
        </button>
      {/if}
    </div>

    <div class="schub"></div>

    {#if darfSchreiben}
      <button
        type="button"
        class="knopf leise klein"
        class:an={anlegenOffen}
        onclick={() => (anlegenOffen = !anlegenOffen)}
      >
        + {t.zugaenge.anlegen}
      </button>
    {/if}
  </div>

  {#if anlegenOffen}
    <form class="anlegen" onsubmit={anlegen}>
      <b>{t.zugaenge.anlegenTitel}</b>
      <!-- Warum es hier kein Passwortfeld gibt. Der Satz beantwortet die Frage,
           die jeder stellt, der eines erwartet. -->
      <p class="detail">{t.zugaenge.anlegenHinweis}</p>

      <label>
        <span>{t.zugaenge.name}</span>
        <!-- svelte-ignore a11y_autofocus -->
        <input
          bind:value={neuName}
          type="text"
          autocomplete="off"
          spellcheck="false"
          autofocus
          placeholder={t.zugaenge.namePlatzhalter}
        />
      </label>
      <label>
        <span>{t.zugaenge.rolle}</span>
        <!-- Die Erklärung steht IM Auswahlfeld: „admin" allein sagt nicht, was
             es bedeutet, und eine Rolle zu vergeben ist die folgenreichste
             Entscheidung auf dieser Seite. -->
        <select bind:value={neuRolle}>
          {#each daten.rollen as r (r.wert)}
            <option value={r.wert}>{r.wert} — {r.was}</option>
          {/each}
        </select>
      </label>

      <div class="aktionen">
        <button type="submit" class="knopf" disabled={!neuName.trim() || laufend !== ""}>
          {t.zugaenge.anlegen}
        </button>
        <button type="button" class="knopf leise" onclick={() => (anlegenOffen = false)}>
          {t.zugaenge.abbrechen}
        </button>
      </div>
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
            <th>{t.zugaenge.name}</th>
            <th>{t.zugaenge.rolle}</th>
            <th>{t.zugaenge.passkeysSpalte}</th>
            <th>{t.zugaenge.letzteAnmeldung}</th>
            <th>{t.zugaenge.zustand}</th>
          </tr>
        </thead>
        <tbody>
          {#if gefiltert.length === 0}
            <tr><td colspan="5" class="gedaempft">{t.zugaenge.nichts}</td></tr>
          {:else}
            {#each gefiltert as k (k.id)}
              <tr class:gewaehlt={String(k.id) === gewaehlt}>
                <td data-spalte={t.zugaenge.name}>
                  <div class="namenszelle">
                    <button type="button" class="zeile" onclick={() => waehlen(k.id)}>
                      {k.name}
                    </button>
                    {#if k.ich}
                      <!-- Die eigene Zeile ist markiert, damit das Fehlen der
                           Handgriffe erklärt ist und nicht wie eine Panne
                           aussieht. -->
                      <span class="marke">{t.zugaenge.ich}</span>
                    {/if}
                  </div>
                </td>
                <td data-spalte={t.zugaenge.rolle}>
                  <span class="rolle">{k.rolle}</span>
                </td>
                <td data-spalte={t.zugaenge.passkeysSpalte} class="zahl gedaempft">
                  {daten.passkeys_moeglich ? k.passkeys : "—"}
                </td>
                <td data-spalte={t.zugaenge.letzteAnmeldung} class="gedaempft">
                  {k.letzte_anmeldung || t.zugaenge.nie}
                </td>
                <td data-spalte={t.zugaenge.zustand}>
                  <span class="zustand {k.zustand}">
                    <i aria-hidden="true"></i>{k.zustand_text}
                  </span>
                </td>
              </tr>
            {/each}
          {/if}
        </tbody>
      </table>
    </div>

    {#if gewaehlt}
      <Inspektor
        titel={konto?.name ?? gewaehlt}
        zustand={konto?.zustand ?? ""}
        zustandText={konto?.zustand_text ?? ""}
        marke={konto?.rolle ?? ""}
        {schliessen}
      >
        {#snippet kinder()}
          {#if !konto}
            <p class="detail">{t.zugaenge.laedt}</p>
          {:else}
            <dl class="kv">
              <dt>{t.zugaenge.rolle}</dt>
              <dd>{konto.rolle} — {konto.rolle_was}</dd>
              <dt>{t.zugaenge.angelegt}</dt>
              <dd>{konto.angelegt}</dd>
              <dt>{t.zugaenge.letzteAnmeldung}</dt>
              <dd>{konto.letzte_anmeldung || t.zugaenge.nie}</dd>
              <dt>{t.zugaenge.passkeysSpalte}</dt>
              <dd>
                {#if !daten.passkeys_moeglich}
                  {t.zugaenge.passkeysAus}
                {:else if konto.passkeys === 0}
                  {t.zugaenge.keinePasskeys}
                {:else}
                  {t.zugaenge.passkeysAnzahl(konto.passkeys)}
                {/if}
              </dd>
            </dl>

            {#if konto.ich}
              <p class="anmerkung">{t.zugaenge.ichHinweis}</p>
            {/if}
            {#if konto.letzter_owner}
              <p class="detail">{t.zugaenge.letzterOwner}</p>
            {/if}
            {#if konto.einmalpasswort}
              <p class="anmerkung">{t.zugaenge.einmalpasswortOffen}</p>
            {:else if !konto.zweiter_faktor}
              <p class="anmerkung">{t.zugaenge.keinZweiterFaktor}</p>
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

            {#if !darfSchreiben}
              <p class="detail">{t.zugaenge.nurLesen}</p>
            {:else if konto.aktionen.length > 0}
              <!-- Eigene Klasse neben .aktionen: Der Block darunter hat auch eine
                   Knopfreihe, und „die Handgriffe" und „die Zurücksetzungen" sind
                   zwei verschiedene Dinge — für das Auge und für den Browsertest,
                   der sie auseinanderhalten muss. -->
              <div class="aktionen handgriffe">
                {#if kann("sperren")}
                  <button
                    type="button"
                    class="knopf leise"
                    disabled={laufend !== ""}
                    onclick={() => sperren(true)}
                  >
                    {t.zugaenge.handgriff.sperren}
                  </button>
                {/if}
                {#if kann("freigeben")}
                  <button
                    type="button"
                    class="knopf"
                    disabled={laufend !== ""}
                    onclick={() => sperren(false)}
                  >
                    {t.zugaenge.handgriff.freigeben}
                  </button>
                {/if}
                {#if kann("loeschen")}
                  <button
                    type="button"
                    class="knopf gefahr"
                    disabled={laufend !== ""}
                    onclick={loeschen}
                  >
                    {t.zugaenge.handgriff.loeschen}
                  </button>
                {/if}
              </div>

              <!-- Die drei Zurücksetzungen und ihre Schranke, in einem Block.
                   Das Feld steht ÜBER den Knöpfen und nicht in einem Dialog
                   dahinter: Die Bedingung soll vor dem Handgriff sichtbar sein
                   und nicht als Fehlermeldung danach kommen. -->
              {#if kann("passwort") || kann("zweiter-faktor") || kann("passkeys")}
                <div class="schranke">
                  <b>{t.zugaenge.eigenesPasswort}</b>
                  <p class="detail">{t.zugaenge.eigenesPasswortWarum}</p>
                  <!-- Kein <form>: Enter im Feld hätte sonst eine der drei
                       Zurücksetzungen ausgelöst, und welche, wäre die Reihenfolge
                       im Markup. -->
                  <label>
                    <span class="nur-vorlese">{t.zugaenge.eigenesPasswort}</span>
                    <input
                      bind:value={eigenesPasswort}
                      type="password"
                      autocomplete="off"
                    />
                  </label>
                  <div class="aktionen">
                    {#if kann("passwort")}
                      <button
                        type="button"
                        class="knopf leise"
                        disabled={!eigenesPasswort || laufend !== ""}
                        onclick={() => zuruecksetzen("password")}
                      >
                        {t.zugaenge.handgriff.passwort}
                      </button>
                    {/if}
                    {#if kann("zweiter-faktor")}
                      <button
                        type="button"
                        class="knopf leise"
                        disabled={!eigenesPasswort || laufend !== ""}
                        onclick={() => zuruecksetzen("2fa")}
                      >
                        {t.zugaenge.handgriff["zweiter-faktor"]}
                      </button>
                    {/if}
                    {#if kann("passkeys")}
                      <button
                        type="button"
                        class="knopf leise"
                        disabled={!eigenesPasswort || laufend !== ""}
                        onclick={() => zuruecksetzen("passkeys")}
                      >
                        {t.zugaenge.handgriff.passkeys}
                      </button>
                    {/if}
                  </div>
                </div>
              {/if}
            {/if}
          {/if}
        {/snippet}
      </Inspektor>
    {/if}
  </div>
{/if}

{#if offeneFrage}
  <Rueckfrage
    frage={offeneFrage.frage}
    laeuft={laufend !== ""}
    bestaetigen={frageBestaetigen}
    abbrechen={() => (offeneFrage = null)}
  />
{/if}

<!-- Das Einmalpasswort. Ein Dialog und kein Band, aus einem Grund: Es kommt kein
     zweites Mal, und ein Band verschwindet beim nächsten Klick, ohne dass es
     jemandem auffällt. Der Dialog lässt sich nur über den Knopf schließen —
     Escape ist abgefangen. -->
{#if gezeigtesPasswort}
  <dialog
    bind:this={passwortDialog}
    class="einmal"
    aria-labelledby="einmal-titel"
    oncancel={(e) => e.preventDefault()}
  >
    <h2 id="einmal-titel">{t.zugaenge.einmalpasswortTitel}</h2>
    {#if gezeigtesPasswort.konto}
      <p class="fuer">{t.zugaenge.fuer(gezeigtesPasswort.konto)}</p>
    {/if}
    <output class="wort">{gezeigtesPasswort.passwort}</output>
    <p class="warnung">{t.zugaenge.einmalpasswortEinmal}</p>
    {#if gezeigtesPasswort.hinweis}
      <p class="detail">{gezeigtesPasswort.hinweis}</p>
    {/if}
    <div class="aktionen">
      <button type="button" class="knopf leise" onclick={passwortKopieren}>
        {kopiert ? t.zugaenge.kopiert : t.zugaenge.kopieren}
      </button>
      <button type="button" class="knopf" onclick={passwortSchliessen}>
        {t.zugaenge.verstanden}
      </button>
    </div>
  </dialog>
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
    width: 13rem;
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

  .anlegen {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 0.9rem 1rem;
    margin-bottom: 0.8rem;
    display: grid;
    gap: 0.55rem;
    max-width: 40rem;
  }

  .anlegen > b {
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
  }

  .anlegen > label {
    display: grid;
    grid-template-columns: 9rem 1fr;
    align-items: start;
    gap: 0.5rem;
    font-size: 0.8rem;
    color: var(--tx-mut);
  }

  .anlegen input,
  .anlegen select {
    background: var(--bg);
    border: 1px solid var(--line2);
    border-radius: 7px;
    padding: 0.3rem 0.55rem;
    color: var(--tx);
    font: 0.8rem var(--mono);
    min-width: 0;
    width: 100%;
  }

  /* Der Name und die Marke „das ist Ihr Konto" nebeneinander, und umbrechend:
   * In der Kartenansicht auf schmalen Schirmen ist die Zelle selbst ein
   * Flex-Kasten, und ohne den eigenen Rahmen quetschte die Marke den Namen auf
   * drei Zeilen. */
  .namenszelle {
    display: flex;
    align-items: baseline;
    gap: 0.4rem;
    flex-wrap: wrap;
    min-width: 0;
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

  .rolle {
    font: 0.78rem var(--mono);
    color: var(--tx-mut);
  }

  :global(table.tabelle tr.gewaehlt) {
    background: var(--surface2);
  }

  .aktionen {
    display: flex;
    gap: 0.45rem;
    flex-wrap: wrap;
    min-width: 0;
    align-items: center;
  }

  .schranke {
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.6rem 0.7rem;
    display: grid;
    gap: 0.4rem;
    min-width: 0;
  }

  .schranke > b {
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
  }

  .schranke input {
    background: var(--surface);
    border: 1px solid var(--line2);
    border-radius: 7px;
    padding: 0.3rem 0.55rem;
    color: var(--tx);
    font: 0.8rem var(--mono);
    width: 100%;
    min-width: 0;
  }

  .einmal {
    background: var(--surface);
    border: 1px solid var(--accent-dim);
    border-radius: var(--r);
    padding: 1.1rem 1.2rem;
    max-width: 30rem;
    color: var(--tx);
    display: grid;
    gap: 0.6rem;
  }

  .einmal::backdrop {
    background: rgba(0, 0, 0, 0.6);
  }

  .einmal h2 {
    font-size: 0.95rem;
    font-weight: 650;
  }

  .einmal .fuer {
    color: var(--tx-mut);
    font-size: 0.82rem;
  }

  /* Das Passwort groß, einzeilig und in Monospace: Es wird abgeschrieben oder
   * markiert, und beides ist mit Proportionalschrift fehleranfällig. */
  .einmal .wort {
    display: block;
    background: var(--bg);
    border: 1px solid var(--line2);
    border-radius: 8px;
    padding: 0.6rem 0.7rem;
    font: 1.05rem var(--mono);
    letter-spacing: 0.04em;
    user-select: all;
    overflow-wrap: anywhere;
  }

  .band {
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.5rem 0.7rem;
    margin-bottom: 0.7rem;
    font-size: 0.8rem;
    color: var(--tx-mut);
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

  /* Eine Anmerkung ist kein Fehler: „der zweite Faktor ist noch nicht
   * eingerichtet" ist eine Auskunft über den Zustand, nicht über einen
   * Fehlschlag. */
  .anmerkung {
    color: var(--accent);
  }
</style>
