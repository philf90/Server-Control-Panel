<script lang="ts">
  // Benutzer & SSH: Konten des Wirtsystems und ihre Schlüssel.
  //
  // Die Werkbank wie bei den Diensten — Liste links, Inspektor rechts. Was dieses
  // Modul davon unterscheidet:
  //
  //  1. **Zwei Kontenarten, und der Unterschied steht oben.** Ein Systemkonto
  //     kommt über SSH auf die Maschine, ein Panelkonto in diese Fläche. Wer das
  //     verwechselt, legt ein Konto an, das nichts kann — oder eines, das mehr
  //     kann als gedacht.
  //  2. **Ein Konto ohne Schlüssel ist eine Auffälligkeit, keine Kleinigkeit.**
  //     Diese Konten haben kein Passwort; ohne Schlüssel kommt niemand herein.
  //     Der Zähler dafür ist ein Filter, weil die Zahl eine Handlung nach sich
  //     zieht.
  //  3. **Der letzte Schlüssel bekommt eine eigene Frage.** Ihn zu entfernen legt
  //     den Zugang still. Der Server stellt sie — hier steht nur, dass sie kommt.
  import Inspektor from "../komponenten/Inspektor.svelte";
  import Rueckfrage from "../komponenten/Rueckfrage.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { normal } from "../lib/ziele";
  import { weg } from "../lib/weg.svelte";
  import type {
    Bestaetigung,
    Kontoauftrag,
    Kontohandgriff,
    Schluesselliste,
    Systembenutzer,
    Systemkonto,
  } from "../lib/typen";

  let { darfSchreiben = false }: { darfSchreiben?: boolean } = $props();

  let daten = $state<Systembenutzer | null>(null);
  let fehler = $state("");
  let filter = $state("");
  let nurArt = $state<"" | "mensch" | "dienst" | "gesperrt" | "ohne">("");

  let schluessel = $state<Schluesselliste | null>(null);
  let schluesselFehler = $state("");

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
  let neuNotiz = $state("");
  let neuSchale = $state("");
  let neuGruppen = $state<string[]>([]);
  let neuSchluessel = $state("");
  /** schluesselFeld ist die Eingabe im Inspektor. */
  let schluesselFeld = $state("");
  let homeEntfernen = $state(false);

  const gewaehlt = $derived(weg.parameter.konto ?? "");
  const konto = $derived(daten?.konten.find((k) => k.name === gewaehlt) ?? null);

  const gefiltert = $derived.by(() => {
    const alle = daten?.konten ?? [];
    const b = normal(filter.trim());
    return alle.filter((k) => {
      switch (nurArt) {
        case "mensch":
        case "dienst":
          if (k.art !== nurArt) return false;
          break;
        case "gesperrt":
          if (!k.locked) return false;
          break;
        case "ohne":
          if (!k.ohne_schluessel) return false;
          break;
      }
      if (!b) return true;
      // Auch in Notiz und Gruppen suchen: Wer „www" tippt, sucht das Konto des
      // Webservers, und der Name allein sagt das nicht immer.
      return (
        normal(k.name).includes(b) ||
        normal(k.comment).includes(b) ||
        (k.groups ?? []).some((g) => normal(g).includes(b))
      );
    });
  });

  async function laden() {
    fehler = "";
    try {
      daten = await api.systembenutzer();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  // Die Schlüssel folgen der Auswahl. Eigener Aufruf, weil die Liste für dreißig
  // Dienstkonten nicht dreißig Schlüsseldateien mitschleppen soll.
  $effect(() => {
    const name = gewaehlt;
    schluesselFeld = "";
    homeEntfernen = false;
    if (!name) {
      schluessel = null;
      schluesselFehler = "";
      return;
    }
    if (schluessel?.konto === name) return;
    void schluesselHolen(name);
  });

  async function schluesselHolen(name: string) {
    schluesselFehler = "";
    try {
      schluessel = await api.schluessel(name);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      schluessel = null;
      schluesselFehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  function waehlen(name: string) {
    meldungenLeeren();
    weg.setzeAlle({ konto: name }, !gewaehlt);
  }

  function schliessen() {
    meldungenLeeren();
    weg.setzeAlle({ konto: "" }, false);
  }

  function meldungenLeeren() {
    meldung = "";
    hinweis = "";
    handlungFehler = "";
  }

  function kann(h: Kontohandgriff): boolean {
    return konto?.aktionen.includes(h) ?? false;
  }

  /** handlung führt eine verändernde Handlung aus und behandelt die Rückfrage —
   *  dasselbe Muster wie im Dateimodul, und aus demselben Grund: Der zweite Anlauf
   *  ist DERSELBE Aufruf mit bestaetigt=true. */
  async function handlung(
    marke: string,
    wohin: string,
    felder: Partial<Kontoauftrag>,
    nachher: (name: string) => void,
  ) {
    laufend = marke;
    meldungenLeeren();

    const lauf = async (bestaetigt: boolean, getippt: string) => {
      const antwort = await api.kontoHandlung(wohin, felder, bestaetigt, getippt);
      offeneFrage = null;
      meldung = antwort.meldung;
      hinweis = antwort.hinweis ?? "";
      await laden();
      nachher(antwort.konto?.name ?? "");
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
      // Ohne Bedingung: Steht die Rückfrage noch, wäre ihr Knopf sonst für immer
      // gesperrt.
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

  function anlegen(e: SubmitEvent) {
    e.preventDefault();
    const name = neuName.trim();
    if (!name) return;
    void handlung(
      "anlegen",
      "",
      {
        name,
        notiz: neuNotiz.trim(),
        schale: neuSchale,
        gruppen: neuGruppen,
        schluessel: neuSchluessel.trim(),
      },
      (angelegt) => {
        anlegenOffen = false;
        neuName = "";
        neuNotiz = "";
        neuSchluessel = "";
        neuGruppen = [];
        if (angelegt) weg.setzeAlle({ konto: angelegt }, !gewaehlt);
      },
    );
  }

  function sperren(gesperrt: boolean) {
    if (!konto) return;
    void handlung(
      gesperrt ? "sperren" : "entsperren",
      `/${encodeURIComponent(konto.name)}/locked`,
      { gesperrt },
      () => {},
    );
  }

  function loeschen() {
    if (!konto) return;
    void handlung(
      "loeschen",
      `/${encodeURIComponent(konto.name)}/delete`,
      { home_entfernen: homeEntfernen },
      () => weg.setzeAlle({ konto: "" }, false),
    );
  }

  function schluesselHinzu(e: SubmitEvent) {
    e.preventDefault();
    if (!konto || !schluesselFeld.trim()) return;
    const name = konto.name;
    void handlung(
      "schluessel",
      `/${encodeURIComponent(name)}/keys`,
      { schluessel: schluesselFeld.trim() },
      () => {
        schluesselFeld = "";
        void schluesselHolen(name);
      },
    );
  }

  function schluesselWeg(fingerprint: string) {
    if (!konto) return;
    const name = konto.name;
    void handlung(
      "entfernen",
      `/${encodeURIComponent(name)}/keys/remove`,
      { fingerprint },
      () => void schluesselHolen(name),
    );
  }

  laden();
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.sicherheit} / {t.ziele.benutzer}</div>
    <div class="h1">{t.ziele.benutzer}</div>
  </div>
  <div class="schub"></div>
  {#if daten && daten.zaehler.ohne_schluessel > 0}
    <span class="marke warn">
      {daten.zaehler.ohne_schluessel} {t.konten.ohneSchluessel}
    </span>
  {/if}
</div>

<p class="wesen">{t.konten.wesen}</p>

{#if fehler && !daten}
  <div class="hinweis">
    <p>{t.fehler.laden}</p>
    <p class="detail">{fehler}</p>
    <button class="knopf" onclick={laden}>{t.fehler.erneut}</button>
  </div>
{:else if !daten}
  <p class="detail">{t.konten.laedt}</p>
{:else}
  {#if daten.fehler}
    <p class="band warn" role="status">{daten.fehler}</p>
  {/if}

  <div class="werkzeuge">
    <label class="suche">
      <span class="nur-vorlese">{t.konten.suchen}</span>
      <input
        bind:value={filter}
        type="search"
        placeholder={t.konten.suchen}
        autocomplete="off"
        spellcheck="false"
      />
    </label>

    <!-- Die Zähler sind die Filter. „ohne Schlüssel" ist der, um den es geht:
         Diese Konten kommen nicht auf den Server. -->
    <div class="stufen" role="group" aria-label={t.konten.zustand}>
      <button type="button" class:an={nurArt === ""} onclick={() => (nurArt = "")}>
        {t.konten.alle} <b>{daten.zaehler.gesamt}</b>
      </button>
      <button
        type="button"
        class:an={nurArt === "mensch"}
        onclick={() => (nurArt = nurArt === "mensch" ? "" : "mensch")}
      >
        {t.konten.menschen} <b>{daten.zaehler.menschen}</b>
      </button>
      <button
        type="button"
        class:an={nurArt === "dienst"}
        onclick={() => (nurArt = nurArt === "dienst" ? "" : "dienst")}
      >
        {t.konten.dienste} <b>{daten.zaehler.dienste}</b>
      </button>
      {#if daten.zaehler.gesperrt > 0}
        <button
          type="button"
          class:an={nurArt === "gesperrt"}
          onclick={() => (nurArt = nurArt === "gesperrt" ? "" : "gesperrt")}
        >
          {t.konten.gesperrt} <b>{daten.zaehler.gesperrt}</b>
        </button>
      {/if}
      {#if daten.zaehler.ohne_schluessel > 0}
        <button
          type="button"
          class:an={nurArt === "ohne"}
          onclick={() => (nurArt = nurArt === "ohne" ? "" : "ohne")}
        >
          {t.konten.ohneSchluessel} <b>{daten.zaehler.ohne_schluessel}</b>
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
        + {t.konten.anlegen}
      </button>
    {/if}
  </div>

  {#if anlegenOffen}
    <form class="anlegen" onsubmit={anlegen}>
      <b>{t.konten.anlegenTitel}</b>
      <p class="detail">{t.konten.anlegenHinweis}</p>

      <label>
        <span>{t.konten.name}</span>
        <!-- svelte-ignore a11y_autofocus -->
        <input
          bind:value={neuName}
          type="text"
          autocomplete="off"
          spellcheck="false"
          autofocus
          placeholder={t.konten.namePlatzhalter}
        />
      </label>
      <label>
        <span>{t.konten.notiz}</span>
        <input bind:value={neuNotiz} type="text" placeholder={t.konten.notizPlatzhalter} />
      </label>
      <!-- Auswahlfelder und kein Freitext: Die Werte kommen aus /etc/shells und
           /etc/group — denselben Quellen, gegen die der Server prüft. -->
      {#if daten.schalen.length > 0}
        <label>
          <span>{t.konten.schale}</span>
          <select bind:value={neuSchale}>
            <option value="">—</option>
            {#each daten.schalen as sch (sch)}
              <option value={sch}>{sch}</option>
            {/each}
          </select>
        </label>
      {/if}
      {#if daten.gruppen.length > 0}
        <label>
          <span>{t.konten.gruppen}</span>
          <select bind:value={neuGruppen} multiple size="4">
            {#each daten.gruppen as g (g)}
              <option value={g}>{g}</option>
            {/each}
          </select>
        </label>
      {/if}
      <label class="breit">
        <span>{t.konten.schluesselFeld}</span>
        <textarea
          bind:value={neuSchluessel}
          rows="3"
          spellcheck="false"
          placeholder={t.konten.schluesselPlatzhalter}
        ></textarea>
      </label>

      <div class="aktionen">
        <button type="submit" class="knopf" disabled={!neuName.trim() || laufend !== ""}>
          {t.konten.anlegen}
        </button>
        <button type="button" class="knopf leise" onclick={() => (anlegenOffen = false)}>
          {t.konten.abbrechen}
        </button>
        <span class="detail">{t.konten.schluesselOptional}</span>
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
            <th>{t.konten.name}</th>
            <th>{t.konten.uid}</th>
            <th>{t.konten.schluesselSpalte}</th>
            <th>{t.konten.schale}</th>
            <th>{t.konten.zustand}</th>
          </tr>
        </thead>
        <tbody>
          {#if gefiltert.length === 0}
            <tr><td colspan="5" class="gedaempft">{t.konten.nichts}</td></tr>
          {:else}
            {#each gefiltert as k (k.name)}
              <tr class:gewaehlt={k.name === gewaehlt}>
                <td data-spalte={t.konten.name}>
                  <button type="button" class="zeile" onclick={() => waehlen(k.name)}>
                    {k.name}
                  </button>
                  {#if k.comment}
                    <span class="notiz">{k.comment}</span>
                  {/if}
                </td>
                <td data-spalte={t.konten.uid} class="zahl gedaempft">{k.uid}</td>
                <td data-spalte={t.konten.schluesselSpalte} class="zahl">
                  {#if k.ohne_schluessel}
                    <!-- Die Auffälligkeit steht in der Zeile: Diese Konten
                         kommen nicht auf den Server. -->
                    <span class="zustand warn"><i aria-hidden="true"></i>0</span>
                  {:else}
                    <span class="gedaempft">{k.ssh_keys}</span>
                  {/if}
                </td>
                <td data-spalte={t.konten.schale} class="pfad gedaempft">{k.shell || "—"}</td>
                <td data-spalte={t.konten.zustand}>
                  <span class="zustand {k.zustand}">
                    <i aria-hidden="true"></i>{k.locked ? t.konten.gesperrt : t.konten.entsperrt}
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
        zustandText={konto?.locked ? t.konten.gesperrt : ""}
        marke={konto?.art === "mensch" ? t.konten.menschen : t.konten.dienste}
        {schliessen}
      >
        {#snippet kinder()}
          {#if !konto}
            <p class="detail">{t.konten.laedt}</p>
          {:else}
            <dl class="kv">
              <dt>{t.konten.uid}</dt>
              <dd class="zahl">{konto.uid} / {konto.gid}</dd>
              {#if konto.comment}
                <dt>{t.konten.notiz}</dt>
                <dd>{konto.comment}</dd>
              {/if}
              <dt>{t.konten.home}</dt>
              <dd class="pfad">{konto.home || "—"}</dd>
              <dt>{t.konten.schale}</dt>
              <dd class="pfad">{konto.shell || "—"}</dd>
              <dt>{t.konten.gruppen}</dt>
              <dd>{(konto.groups ?? []).join(", ") || "—"}</dd>
            </dl>

            {#if konto.ohne_schluessel}
              <p class="warnung">{t.konten.ohneSchluesselWarnung}</p>
            {:else if konto.art === "dienst"}
              <p class="detail">{t.konten.dienstkonto}</p>
            {/if}
            {#if konto.protected}
              <p class="detail">{t.konten.geschuetzt}</p>
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
              <p class="detail">{t.konten.nurLesen}</p>
            {:else}
              <div class="aktionen">
                {#if kann("sperren")}
                  <button
                    type="button"
                    class="knopf leise"
                    disabled={laufend !== ""}
                    onclick={() => sperren(true)}
                  >
                    {t.konten.handgriff.sperren}
                  </button>
                {/if}
                {#if kann("entsperren")}
                  <button
                    type="button"
                    class="knopf"
                    disabled={laufend !== ""}
                    onclick={() => sperren(false)}
                  >
                    {t.konten.handgriff.entsperren}
                  </button>
                {/if}
                {#if kann("loeschen")}
                  <button
                    type="button"
                    class="knopf gefahr"
                    disabled={laufend !== ""}
                    onclick={loeschen}
                  >
                    {t.konten.handgriff.loeschen}
                  </button>
                {/if}
              </div>
              {#if kann("loeschen")}
                <!-- Der Schalter steht NEBEN dem Knopf und nicht im Dialog: Er
                     ändert, was gelöscht wird, und die Rückfrage nennt dann die
                     Folge. Umgekehrt müsste der Dialog eine Einstellung tragen,
                     und ein Dialog, in dem man etwas einstellt, ist kein
                     Innehalten mehr. -->
                <label class="schalter">
                  <input type="checkbox" bind:checked={homeEntfernen} />
                  {t.konten.homeEntfernen}
                </label>
              {/if}
            {/if}

            <!-- Die Schlüssel. Sie stehen im Inspektor und nicht in der Liste,
                 weil ein Fingerprint 60 Zeichen hat und die Tabelle damit
                 unlesbar wäre. -->
            {#if kann("schluessel") || (schluessel && schluessel.schluessel.length > 0)}
              <div class="schluesselblock">
                <b>{t.konten.schluesselTitel}</b>
                {#if schluesselFehler}
                  <p class="warnung">{schluesselFehler}</p>
                {:else if !schluessel}
                  <p class="detail">{t.konten.laedt}</p>
                {:else}
                  <p class="detail">{t.konten.schluesselDatei(schluessel.datei)}</p>
                  {#if schluessel.schluessel.length === 0}
                    <p class="detail">{t.konten.keineSchluessel}</p>
                  {:else}
                    {#if schluessel.schluessel.length === 1 && darfSchreiben}
                      <p class="anmerkung">{t.konten.letzterSchluessel}</p>
                    {/if}
                    <ul class="schluesselliste">
                      {#each schluessel.schluessel as k (k.fingerprint)}
                        <li>
                          <div class="kopf">
                            <span class="typ">{k.type}</span>
                            {#if k.staerke}
                              <span class="marke" class:warn={k.schwach}>{k.staerke}</span>
                            {/if}
                            {#if k.comment}
                              <span class="detail">{k.comment}</span>
                            {/if}
                          </div>
                          <code>{k.fingerprint}</code>
                          {#if darfSchreiben}
                            <button
                              type="button"
                              class="knopf leise klein"
                              disabled={laufend !== ""}
                              onclick={() => schluesselWeg(k.fingerprint)}
                            >
                              {t.konten.schluesselEntfernen}
                            </button>
                          {/if}
                        </li>
                      {/each}
                    </ul>
                  {/if}

                  {#if darfSchreiben && kann("schluessel")}
                    <form class="schluesselmaske" onsubmit={schluesselHinzu}>
                      <label>
                        <span class="nur-vorlese">{t.konten.schluesselFeld}</span>
                        <textarea
                          bind:value={schluesselFeld}
                          rows="3"
                          spellcheck="false"
                          placeholder={t.konten.schluesselPlatzhalter}
                        ></textarea>
                      </label>
                      <button
                        type="submit"
                        class="knopf klein"
                        disabled={!schluesselFeld.trim() || laufend !== ""}
                      >
                        {t.konten.schluesselHinzu}
                      </button>
                    </form>
                  {/if}
                {/if}
              </div>
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

  .anlegen > label.breit {
    grid-template-columns: 1fr;
  }

  .anlegen input,
  .anlegen select,
  .anlegen textarea {
    background: var(--bg);
    border: 1px solid var(--line2);
    border-radius: 7px;
    padding: 0.3rem 0.55rem;
    color: var(--tx);
    font: 0.8rem var(--mono);
    min-width: 0;
    width: 100%;
  }

  .anlegen textarea {
    resize: vertical;
    overflow-wrap: anywhere;
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

  .notiz {
    display: block;
    color: var(--tx-faint);
    font-size: 0.72rem;
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

  .schalter {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    color: var(--tx-mut);
    font-size: 0.78rem;
    cursor: pointer;
  }

  .schluesselblock > b {
    display: block;
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
    margin-bottom: 0.3rem;
  }

  .schluesselliste {
    list-style: none;
    display: grid;
    gap: 0.5rem;
    margin: 0.5rem 0;
  }

  .schluesselliste li {
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.5rem 0.6rem;
    display: grid;
    gap: 0.3rem;
    justify-items: start;
    min-width: 0;
  }

  .schluesselliste .kopf {
    display: flex;
    align-items: baseline;
    gap: 0.4rem;
    flex-wrap: wrap;
  }

  .schluesselliste .typ {
    font: 650 0.76rem var(--mono);
  }

  .schluesselliste code {
    font: 0.72rem var(--mono);
    color: var(--tx-mut);
    overflow-wrap: anywhere;
  }

  .schluesselmaske {
    display: grid;
    gap: 0.4rem;
    justify-items: start;
  }

  .schluesselmaske textarea {
    background: var(--bg);
    border: 1px solid var(--line2);
    border-radius: 7px;
    padding: 0.3rem 0.55rem;
    color: var(--tx);
    font: 0.76rem var(--mono);
    width: 100%;
    resize: vertical;
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

  /* Eine Anmerkung ist kein Fehler: „das Konto hat noch keinen Schlüssel" ist
   * eine Auskunft über den Zustand, nicht über einen Fehlschlag. */
  .anmerkung {
    color: var(--accent);
  }
</style>
