<script lang="ts">
  // Selbstupdate und Rückweg — die gefährlichste Fläche des Panels und die
  // einzige, die ihren eigenen Dienst neu startet.
  //
  // Daraus folgt die ganze Bauart dieser Seite. Ein Ereignisstrom wäre hier
  // falsch: Er überlebt den Neustart nicht. Stattdessen fragt sie
  // /api/v1/update/status im Sekundentakt, und der entscheidende Teil ist, wie
  // sie mit dem SCHEITERN dieses Aufrufs umgeht — denn genau das ist der
  // Normalfall mitten im Vorgang.
  //
  // Drei Zustände hält sie auseinander, und keine zwei davon dürfen gleich
  // aussehen:
  //
  //  1. **Es läuft, und der Dienst antwortet noch.** Der Verlauf wächst.
  //  2. **Es läuft, und der Dienst antwortet nicht.** Das ist der Neustart. Die
  //     Seite sagt das ausdrücklich und fragt weiter. Als Fehlermeldung wäre es
  //     eine Lüge über den Vorgang — und der Bediener würde neu laden, während
  //     unter ihm das Binary getauscht wird.
  //  3. **Der Dienst antwortet wieder.** Jetzt entscheidet die FASSUNG: Eine
  //     andere als die zu Beginn heißt „durch", dieselbe heißt „es ist etwas
  //     schiefgegangen, der Verlauf sagt was". Die Fassung kommt aus dem neuen
  //     Programm und ist damit die verlässlichste Auskunft, die es gibt.
  import Rueckfrage from "../komponenten/Rueckfrage.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, api } from "../lib/api";
  import { t } from "../lib/texte";
  import type { Bestaetigung, Panelupdate } from "../lib/typen";

  let daten = $state<Panelupdate | null>(null);
  let fehler = $state("");
  let laufend = $state("");
  let meldung = $state("");
  let hinweis = $state("");
  let handlungFehler = $state("");
  let offeneFrage = $state<{
    frage: Bestaetigung;
    weiter: (getippt: string) => Promise<void>;
  } | null>(null);

  /** zeilen ist der Verlauf, wie der Poller ihn sieht. */
  let zeilen = $state<string[]>([]);
  /** beobachtet heißt: Es läuft ein Vorgang, und wir fragen den Stand ab. */
  let beobachtet = $state(false);
  /** stumm heißt: Der letzte Aufruf ist gescheitert. Das ist der Neustart und
   *  kein Fehler — deshalb ein eigener Zustand und keine Fehlermeldung. */
  let stumm = $state(false);
  /** fassungVorher ist die Fassung, mit der der Vorgang begonnen hat. Der
   *  Vergleich damit ist die einzige verlässliche Erfolgsmeldung. */
  let fassungVorher = $state("");
  /** ergebnis steht, wenn der Dienst wieder antwortet: durch oder unverändert. */
  let ergebnis = $state("");

  let uhr: ReturnType<typeof setInterval> | undefined;

  const kannEinspielen = $derived(
    (daten?.darf_ausloesen ?? false) && (daten?.update_da ?? false) && !beobachtet,
  );
  const kannZurueck = $derived(
    (daten?.darf_ausloesen ?? false) && (daten?.rueckweg_moeglich ?? false) && !beobachtet,
  );

  async function laden() {
    fehler = "";
    try {
      const u = await api.panelupdate();
      daten = u;
      zeilen = u.zeilen;
      // Ein Lauf, der noch läuft, wird auch dann beobachtet, wenn ihn jemand
      // anderes angestoßen hat oder die Seite zwischenzeitlich neu geladen wurde:
      // Der Zustand liegt auf dem Server.
      if (u.laeuft && !beobachtet) beobachten(u.fassung);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  /** beobachten startet den Poller. Er läuft, bis der Dienst mit einer anderen
   *  Fassung antwortet oder der Server „läuft nicht mehr" sagt. */
  function beobachten(vorher: string) {
    beobachtet = true;
    stumm = false;
    ergebnis = "";
    fassungVorher = vorher;
    uhr ??= setInterval(() => void takt(), 1000);
  }

  function aufhoeren() {
    if (uhr !== undefined) {
      clearInterval(uhr);
      uhr = undefined;
    }
    beobachtet = false;
    stumm = false;
  }

  async function takt() {
    try {
      const stand = await api.updatestand();
      // Wir hatten die Verbindung verloren und haben sie wieder — jetzt
      // entscheidet die Fassung, was daraus wird.
      if (stumm) {
        stumm = false;
        ergebnis =
          stand.fassung === fassungVorher
            ? t.update.unveraendert(stand.fassung)
            : t.update.wiederDa(stand.fassung);
      }
      zeilen = stand.zeilen;
      if (!stand.laeuft) {
        // Fertig. Der vollständige Zustand kommt aus der Ressource — der Poller
        // trägt bewusst nur das Nötigste.
        aufhoeren();
        if (!ergebnis) {
          ergebnis =
            stand.fassung === fassungVorher
              ? t.update.unveraendert(stand.fassung)
              : t.update.wiederDa(stand.fassung);
        }
        await laden();
      }
    } catch {
      // Der Aufruf ist gescheitert. Mitten in einem Update heißt das: Der Dienst
      // startet neu. Kein Fehler, keine Abmeldung — weiterfragen. Auch ein 401
      // wird hier nicht behandelt: Während des Neustarts antwortet niemand, und
      // eine Weiterleitung zur Anmeldung mitten im Vorgang wäre das Letzte, was
      // hilft.
      stumm = true;
    }
  }

  $effect(() => () => aufhoeren());

  function meldungenLeeren() {
    meldung = "";
    hinweis = "";
    handlungFehler = "";
    ergebnis = "";
  }

  async function pruefen() {
    laufend = "pruefen";
    meldungenLeeren();
    try {
      const antwort = await api.updatePruefen();
      meldung = antwort.meldung;
      hinweis = antwort.hinweis ?? "";
      if (antwort.update) {
        daten = antwort.update;
        zeilen = antwort.update.zeilen;
      }
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      handlungFehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      laufend = "";
    }
  }

  /** handlung stößt Einspielen oder Rückweg an und beginnt danach zu beobachten. */
  function handlung(marke: string, wohin: string) {
    laufend = marke;
    meldungenLeeren();
    const vorher = daten?.fassung ?? "";

    const lauf = async (bestaetigt: boolean, getippt: string) => {
      const antwort = await api.updateHandlung(wohin, bestaetigt, getippt);
      offeneFrage = null;
      meldung = antwort.meldung;
      hinweis = antwort.hinweis ?? "";
      if (antwort.update) {
        daten = antwort.update;
        zeilen = antwort.update.zeilen;
      }
      // Ab hier kann die Verbindung jederzeit abreißen.
      beobachten(vorher);
    };

    void (async () => {
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
    })();
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

  laden();
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.betrieb} / {t.ziele.update}</div>
    <div class="h1">{t.ziele.update}</div>
  </div>
  <div class="schub"></div>
  {#if daten}
    <span class="marke" class:warn={daten.update_da}>{daten.fassung}</span>
  {/if}
</div>

<p class="wesen">{t.update.wesen}</p>

{#if fehler && !daten}
  <div class="hinweis">
    <p>{t.fehler.laden}</p>
    <p class="detail">{fehler}</p>
    <button class="knopf" onclick={laden}>{t.fehler.erneut}</button>
  </div>
{:else if !daten}
  <p class="detail">{t.update.laedt}</p>
{:else}
  <!-- Der laufende Vorgang steht ganz oben, weil er alles andere überstrahlt.
       „stumm" ist der Neustart und kein Fehler — als rote Zeile würde jemand neu
       laden, während unter ihm das Binary getauscht wird. -->
  {#if beobachtet}
    <p class="band" class:warn={!stumm} class:info={stumm} role="status">
      {#if stumm}
        {t.update.wartetAufNeustart}
      {:else}
        {t.update.laeuft(daten.ziel || "—")}
      {/if}
    </p>
  {/if}
  {#if ergebnis}
    <p class="band gut" role="status">{ergebnis}</p>
  {/if}
  {#if meldung}
    <p class="band gut" role="status">{meldung}</p>
  {/if}
  {#if hinweis}
    <p class="band warn" role="status">{hinweis}</p>
  {/if}
  {#if handlungFehler}
    <p class="band schlecht" role="alert">{handlungFehler}</p>
  {/if}

  <div class="bloecke">
    <!-- Was läuft, und was im Kanal steht. -->
    <section class="platte">
      <b>{t.update.standTitel}</b>
      <dl class="kv">
        <dt>{t.update.fassung}</dt>
        <dd class="zahl gross">{daten.fassung}</dd>
        <dt>{t.update.kanal}</dt>
        <dd>{daten.kanal}</dd>
        <dt>{t.update.quelle}</dt>
        <dd class="pfad klein">{daten.quelle}</dd>
        <dt>{t.update.geprueftAm}</dt>
        <dd>{daten.geprueft_am || t.update.nieGeprueft}</dd>
        {#if daten.verfuegbar}
          <dt>{t.update.verfuegbar}</dt>
          <dd>
            <span class="zahl">{daten.verfuegbar}</span>
            {#if daten.dringlichkeit === "security"}
              <span class="marke warn">{t.update.sicherheit}</span>
            {/if}
          </dd>
        {/if}
        {#if daten.erschienen}
          <dt>{t.update.erschienen}</dt>
          <dd>{daten.erschienen}</dd>
        {/if}
      </dl>

      {#if daten.pruef_fehler}
        <p class="warnung">{t.update.prueffehler} {daten.pruef_fehler}</p>
      {:else if !daten.geprueft_am}
        <p class="detail">{t.update.nichtGeprueft}</p>
      {:else if !daten.update_da}
        <p class="detail">{t.update.aktuell}</p>
      {/if}

      {#if daten.notizen}
        <!-- Ein echter Verweis nach draußen: Er verlässt das Panel, deshalb
             rel="noreferrer" und ein neuer Tab. -->
        <p class="detail">
          <a href={daten.notizen} target="_blank" rel="noreferrer noopener">
            {t.update.notizenLink}
          </a>
        </p>
      {/if}

      <div class="aktionen">
        <button
          type="button"
          class="knopf leise"
          disabled={laufend !== "" || beobachtet}
          onclick={pruefen}
        >
          {t.update.pruefen}
        </button>
      </div>
    </section>

    <!-- Aktualisieren und Rückweg. -->
    <section class="platte">
      <b>{t.update.einspielenTitel}</b>
      {#if !daten.darf_ausloesen}
        <p class="detail">{t.update.nurOwner}</p>
      {:else}
        <p class="detail">{t.update.einspielenWarum}</p>
        <div class="aktionen">
          <button
            type="button"
            class="knopf"
            disabled={!kannEinspielen || laufend !== ""}
            onclick={() => handlung("apply", "/apply")}
          >
            {daten.verfuegbar
              ? t.update.einspielen(daten.verfuegbar)
              : t.update.einspielenUnbekannt}
          </button>
          {#if !daten.geprueft_am}
            <span class="detail">{t.update.erstPruefen}</span>
          {:else if !daten.update_da}
            <span class="detail">{t.update.aktuell}</span>
          {/if}
        </div>

        <div class="trenner"></div>

        <b>{t.update.rueckwegTitel}</b>
        <p class="detail">{t.update.rueckwegWarum}</p>
        {#if daten.rueckweg_moeglich}
          <dl class="kv">
            <dt>{t.update.vorher}</dt>
            <dd class="zahl">{daten.vorher}</dd>
          </dl>
          <div class="aktionen">
            <button
              type="button"
              class="knopf gefahr"
              disabled={!kannZurueck || laufend !== ""}
              onclick={() => handlung("rollback", "/rollback")}
            >
              {t.update.rueckweg(daten.vorher)}
            </button>
          </div>
        {:else}
          <p class="detail">{t.update.keineSicherung}</p>
        {/if}
      {/if}
    </section>
  </div>

  <!-- Der Verlauf. Die rohe Ausgabe des Update-Laufs, nicht eine
       Zusammenfassung davon — Grundsatz IV. -->
  <section class="platte breit">
    <b>{t.update.verlaufTitel}</b>
    {#if zeilen.length === 0}
      <p class="detail">{t.update.keinVerlauf}</p>
    {:else}
      <pre class="auszug">{zeilen.join("\n")}</pre>
    {/if}
  </section>
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

  .bloecke {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr));
    gap: 0.8rem;
    align-items: start;
    margin-bottom: 0.8rem;
  }

  .platte {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 0.9rem 1rem;
    display: grid;
    gap: 0.5rem;
    min-width: 0;
  }

  .platte > b {
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
  }

  .trenner {
    border-top: 1px solid var(--line);
    margin: 0.3rem 0;
  }

  .gross {
    font-size: 1.1rem;
  }

  .aktionen {
    display: flex;
    gap: 0.45rem;
    flex-wrap: wrap;
    min-width: 0;
    align-items: center;
  }

  /* Der Auszug wie bei der Vorgangsplatte: fester Kasten, scrollbar, Monospace.
   * Die Zeilen sind Programmausgabe und werden nicht umgebrochen. */
  .auszug {
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.6rem 0.7rem;
    font: 0.76rem var(--mono);
    color: var(--tx-mut);
    max-height: 22rem;
    overflow: auto;
    white-space: pre;
    margin: 0;
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

  /* Der Neustart ist ein Zustand und keine Warnung: blau statt bernstein. Wer
   * hier Rot oder Bernstein sieht, lädt neu — und genau das soll er nicht. */
  .band.info {
    border-color: var(--info);
    color: var(--info);
  }

  .band.schlecht {
    border-color: var(--err);
    color: var(--err);
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
    font-size: 0.8rem;
    overflow-wrap: anywhere;
  }

  .detail a {
    color: var(--accent);
  }

  .klein {
    font-size: 0.72rem;
  }

  .warnung {
    font-size: 0.82rem;
    color: var(--err);
    overflow-wrap: anywhere;
    min-width: 0;
  }
</style>
