<script lang="ts">
  // Zertifikat und ACME.
  //
  // Zwei Teile, und die Reihenfolge ist die Aussage: oben, was gerade
  // ausgeliefert wird, darunter, wie es bezogen wird. Wer die Seite öffnet, will
  // in den meisten Fällen das Erste wissen.
  //
  // Vier Dinge unterscheiden dieses Modul:
  //
  //  1. **Einstellung und Wirklichkeit fallen auseinander.** „acme"
  //     eingestellt heißt nicht „acme ausgeliefert" — bis der erste Bezug glückt,
  //     bleibt das selbstsignierte aktiv. Beides steht da, und der Zwischenzustand
  //     ist benannt; ohne das sucht jemand den Fehler an der falschen Stelle.
  //  2. **Das Formular zeigt nur, was zur Wahl passt.** DNS-01 braucht einen
  //     Anbieter, Hook braucht zwei Pfade, Cloudflare ein Token. Felder, die zur
  //     getroffenen Wahl nichts beitragen, stehen nicht da — sie wären eine
  //     Aufforderung, etwas einzutragen, das nichts bewirkt.
  //  3. **Das Token wird nie zurückgezeigt**, nur dass eines hinterlegt ist. Ein
  //     leeres Feld behält es.
  //  4. **Der Bezug ist ein Vorgang**, kein Klick mit Rückmeldung. Er läuft
  //     Minuten und über denselben Strom wie der Paketvorgang.
  import Vorgangsplatte from "../komponenten/Vorgangsplatte.svelte";
  import Rueckfrage from "../komponenten/Rueckfrage.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { Vorgang } from "../lib/vorgang.svelte";
  import type { Bestaetigung, Zertifikat } from "../lib/typen";

  let { darfSchreiben = false }: { darfSchreiben?: boolean } = $props();

  let daten = $state<Zertifikat | null>(null);
  let fehler = $state("");
  let laufend = $state("");
  let meldung = $state("");
  let hinweis = $state("");
  let handlungFehler = $state("");
  let offeneFrage = $state<{
    frage: Bestaetigung;
    weiter: (getippt: string) => Promise<void>;
  } | null>(null);

  // Der Beobachter des Bezugs. Einer für die Lebensdauer der Seite; er hängt sich
  // nur an den Strom, wenn wirklich etwas läuft.
  const vorgang = new Vorgang("certificate");
  $effect(() => () => vorgang.loesen());

  /** Die Eingaben. Sie werden beim Laden aus der Antwort gefüllt und danach nicht
   *  mehr überschrieben, solange die Seite steht: Ein Neuladen im Hintergrund darf
   *  nicht wegnehmen, was jemand gerade tippt. */
  let modus = $state("selfsigned");
  let email = $state("");
  let namenstext = $state("");
  let pruefmethode = $state("");
  let anbieter = $state("");
  let hookSetzen = $state("");
  let hookAufraeumen = $state("");
  let token = $state("");
  /** zuletztAnbieter merkt, für welchen Anbieter das Zugangsfeld gerade steht.
   *
   *  Beim Wechsel wird es geleert und mit der Vorlage des neuen Anbieters
   *  gefüllt. Ohne das stünden nach einem Wechsel von OVH zu Hetzner noch vier
   *  OVH-Zeilen im Feld — und die gingen beim Speichern mit. */
  let zuletztAnbieter = $state("");
  let testverzeichnis = $state(false);
  let gefuellt = false;

  const istACME = $derived(modus === "acme");
  // DNS-01 verlangt einen Anbieter. Bei „automatisch" ist er zulässig, aber nicht
  // nötig — dann entscheidet der Server nach dem, was eingerichtet ist.
  const brauchtAnbieter = $derived(istACME && pruefmethode === "dns-01");
  const zeigtAnbieter = $derived(istACME && pruefmethode !== "http-01");

  /** gewaehlt ist der Eintrag des Anbieters aus der Antwort des Servers.
   *
   *  Aus ihm kommt beides: der erklärende Satz und die Liste der Felder, die
   *  seine Zugangsdatei tragen muss. Die Oberfläche führt darüber keine eigene
   *  Liste — sonst stünde jeder neue Anbieter an zwei Stellen, und eine davon
   *  fehlte irgendwann. */
  const gewaehlt = $derived(daten?.anbieter_liste.find((a) => a.wert === anbieter));

  /** zeigtZugang: alles außer „keiner" und dem Hook braucht Zugangsdaten. Der
   *  Hook hat stattdessen zwei Programmpfade — er ist der einzige Anbieter, der
   *  kein Geheimnis des Panels hält. */
  const zeigtZugang = $derived(zeigtAnbieter && anbieter !== "" && anbieter !== "hook");

  /** zugangFelder sind die Zeilen, die der Anbieter erwartet. Leer heißt: genau
   *  ein Geheimnis, und dann genügt eine Zeile. */
  const zugangFelder = $derived(gewaehlt?.felder ?? []);
  const mehrzeilig = $derived(zugangFelder.length > 0);

  /** zugangVorlage füllt das mehrzeilige Feld vor.
   *
   *  Nicht bloß Bequemlichkeit: netcup will drei Zeilen und OVH vier, und die
   *  Namen muss man sonst aus dem erklärenden Satz abschreiben. Ein Feld, in
   *  dem das Gerüst schon steht, hat genau einen falsch tippbaren Teil — den
   *  Wert. */
  const zugangVorlage = $derived(
    (gewaehlt?.vorlage ?? zugangFelder).map((f) => `${f} = `).join("\n"),
  );

  /** zugangKuer sind die Zeilen der Vorlage, die NICHT pflicht sind.
   *
   *  Sie brauchen einen eigenen Satz. Ohne ihn steht „Erwartet werden 3 Zeilen"
   *  über einem Feld mit vier — und wer das liest, sucht den Fehler bei sich.
   *  Gefunden hat das ein Bildschirmfoto, kein Test. */
  const zugangKuer = $derived(
    (gewaehlt?.vorlage ?? []).filter((f) => !zugangFelder.includes(f)),
  );

  $effect(() => {
    if (anbieter === zuletztAnbieter) return;
    zuletztAnbieter = anbieter;
    // Nur die Vorlage setzen, nicht überschreiben, was jemand schon getippt
    // hat: Der Effekt läuft auch beim ersten Aufbau der Seite.
    token = mehrzeilig ? zugangVorlage : "";
  });

  async function laden() {
    fehler = "";
    try {
      const z = await api.zertifikat();
      daten = z;
      vorgang.setzen(z.job);
      if (!gefuellt) {
        gefuellt = true;
        modus = z.modus;
        email = z.email;
        namenstext = z.namenstext;
        pruefmethode = z.pruefmethode;
        anbieter = z.anbieter;
        hookSetzen = z.hook_setzen;
        hookAufraeumen = z.hook_aufraeumen;
        testverzeichnis = z.testverzeichnis;
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

  function speichern(e: SubmitEvent) {
    e.preventDefault();
    laufend = "speichern";
    meldungenLeeren();

    // Geschickt wird, was ZU SEHEN ist, und nicht der letzte Zustand jedes
    // Feldes. Wer erst Cloudflare wählt und dann auf HTTP-01 zurückgeht, hat
    // keinen Anbieter mehr eingestellt — das Feld ist weg. Ohne diese Umsetzung
    // ginge der unsichtbare Wert mit, und der Server lehnte mit „für Cloudflare
    // wird ein Token gebraucht" ab: eine Begründung für ein Feld, das gar nicht
    // dasteht. Gefunden hat das der Browsertest.
    const gewaehlterAnbieter = zeigtAnbieter ? anbieter : "";
    const lauf = async (bestaetigt: boolean, getippt: string) => {
      const antwort = await api.zertifikatSpeichern(
        {
          modus,
          email,
          namenstext,
          pruefmethode,
          anbieter: gewaehlterAnbieter,
          hook_setzen: gewaehlterAnbieter === "hook" ? hookSetzen : "",
          hook_aufraeumen: gewaehlterAnbieter === "hook" ? hookAufraeumen : "",
          // Für JEDEN Anbieter mit Zugangsdatei, nicht mehr nur für Cloudflare.
          // Der Hook hat keine — er bekommt zwei Programmpfade.
          token: gewaehlterAnbieter !== "" && gewaehlterAnbieter !== "hook" ? token : "",
          testverzeichnis,
        },
        bestaetigt,
        getippt,
      );
      offeneFrage = null;
      meldung = antwort.meldung;
      hinweis = antwort.hinweis ?? "";
      // Die Zugangsdaten nach dem Speichern aus dem Zustand nehmen: Sie stehen
      // jetzt in ihrer Datei, und im Feld hätten sie nur noch die Wirkung, beim
      // nächsten Speichern erneut geschrieben zu werden. Zurück bleibt die
      // Vorlage — ein leeres Feld sähe aus, als sei nichts hinterlegt.
      token = mehrzeilig ? zugangVorlage : "";
      if (antwort.zertifikat) daten = antwort.zertifikat;
    };

    void (async () => {
      try {
        await lauf(false, "");
      } catch (err) {
        if (err instanceof AbgemeldetFehler) throw err;
        if (err instanceof BestaetigungNoetig) {
          offeneFrage = { frage: err.bestaetigung, weiter: (wort) => lauf(true, wort) };
          return;
        }
        handlungFehler = err instanceof Error ? err.message : t.fehler.laden;
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

  async function beziehen() {
    laufend = "beziehen";
    meldungenLeeren();
    try {
      const antwort = await api.zertifikatBeziehen();
      meldung = antwort.meldung;
      hinweis = antwort.hinweis ?? "";
      if (antwort.zertifikat) {
        daten = antwort.zertifikat;
        vorgang.setzen(antwort.zertifikat.job);
      }
      // Der Vorgang ist gerade angelegt worden; er läuft, also anhängen. setzen
      // tut das nur, wenn der Job schon als laufend gemeldet ist — beim
      // allerersten Bezug ist das Rennen sonst knapp.
      vorgang.anhaengen();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      handlungFehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      laufend = "";
    }
  }

  laden();
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.sicherheit} / {t.ziele.zertifikate}</div>
    <div class="h1">{t.ziele.zertifikate}</div>
  </div>
  <div class="schub"></div>
  {#if daten}
    <span class="zustand {daten.zustand}">
      <i aria-hidden="true"></i>{daten.zustand_text}
    </span>
  {/if}
</div>

<p class="wesen">{t.zert.wesen}</p>

{#if fehler && !daten}
  <div class="hinweis">
    <p>{t.fehler.laden}</p>
    <p class="detail">{fehler}</p>
    <button class="knopf" onclick={laden}>{t.fehler.erneut}</button>
  </div>
{:else if !daten}
  <p class="detail">{t.zert.laedt}</p>
{:else}
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
    <!-- Was gerade ausgeliefert wird. -->
    <section class="platte">
      <b>{t.zert.zustand}</b>
      {#if daten.lesefehler}
        <p class="warnung">{t.zert.lesefehler} {daten.lesefehler}</p>
        <dl class="kv">
          <dt>{t.zert.datei}</dt>
          <dd class="pfad">{daten.datei}</dd>
        </dl>
      {:else}
        <dl class="kv">
          <dt>{t.zert.quelle}</dt>
          <dd>{daten.quelle}</dd>
          <dt>{t.zert.gueltig}</dt>
          <dd>
            {t.zert.bis(daten.gueltig_ab, daten.gueltig_bis)}
            <span class="rest" class:knapp={daten.tage_uebrig < 14}>
              {t.zert.tage(daten.tage_uebrig)}
            </span>
          </dd>
          <dt>{t.zert.namen}</dt>
          <dd class="pfad">{daten.namen.join(", ") || "—"}</dd>
          <dt>{t.zert.aussteller}</dt>
          <dd>{daten.aussteller}</dd>
          <dt>{t.zert.fingerprint}</dt>
          <dd class="pfad klein">{daten.fingerprint}</dd>
          <dt>{t.zert.datei}</dt>
          <dd class="pfad">{daten.datei}</dd>
        </dl>

        <!-- Der Zwischenzustand: eingestellt, aber noch nichts bezogen. -->
        {#if daten.modus === "acme" && daten.selbstsigniert}
          <p class="anmerkung">{t.zert.nochNichtBezogen}</p>
        {:else if daten.selbstsigniert}
          <p class="anmerkung">{t.zert.selbstsigniertWarnung}</p>
        {/if}
        {#if daten.testverzeichnis}
          <p class="anmerkung">{t.zert.testverzeichnisAktiv}</p>
        {/if}
      {/if}
    </section>

    <!-- Wie es bezogen wird. -->
    <section class="platte">
      <b>{t.zert.modusTitel}</b>
      {#if !darfSchreiben}
        <p class="detail">{t.zert.nurLesen}</p>
        <dl class="kv">
          <dt>{t.zert.modusTitel}</dt>
          <dd>{daten.modus === "acme" ? t.zert.acme : t.zert.selbstsigniert}</dd>
          {#if daten.modus === "acme"}
            <dt>{t.zert.email}</dt>
            <dd>{daten.email || "—"}</dd>
            <dt>{t.zert.namenFeld}</dt>
            <dd class="pfad">{daten.geltende_namen.join(", ") || "—"}</dd>
          {/if}
        </dl>
      {:else}
        <form onsubmit={speichern}>
          <!-- Die Wahl der Bezugsart als zwei Schaltflächen mit Erklärung, nicht
               als Auswahlfeld: Es sind zwei Möglichkeiten mit sehr verschiedenen
               Folgen, und die Folge gehört neben die Wahl. -->
          <div class="wahl" role="radiogroup" aria-label={t.zert.modusTitel}>
            <label class:an={modus === "selfsigned"}>
              <input type="radio" bind:group={modus} value="selfsigned" />
              <span class="name">{t.zert.selbstsigniert}</span>
              <span class="detail">{t.zert.selbstsigniertWas}</span>
            </label>
            <label class:an={modus === "acme"}>
              <input type="radio" bind:group={modus} value="acme" />
              <span class="name">{t.zert.acme}</span>
              <span class="detail">{t.zert.acmeWas}</span>
            </label>
          </div>

          {#if istACME}
            <label class="feld">
              <span>{t.zert.email}</span>
              <input
                id="zert-email"
                bind:value={email}
                type="email"
                autocomplete="off"
                placeholder="admin@example.com"
              />
            </label>
            <p class="detail eingerueckt">{t.zert.emailWarum}</p>

            <label class="feld">
              <span>{t.zert.namenFeld}</span>
              <textarea id="zert-namen" bind:value={namenstext} rows="3" spellcheck="false"
              ></textarea>
            </label>
            <p class="detail eingerueckt">{t.zert.namenWarum}</p>
            <!-- Aufgelöst, damit niemand raten muss, was „leer" bedeutet. -->
            {#if daten.geltende_namen.length > 0}
              <p class="detail eingerueckt">
                {t.zert.geltend} <code>{daten.geltende_namen.join(", ")}</code>
              </p>
            {:else}
              <p class="anmerkung eingerueckt">{t.zert.keineNamen}</p>
            {/if}

            <label class="feld">
              <span>{t.zert.pruefmethode}</span>
              <select id="zert-methode" bind:value={pruefmethode}>
                {#each daten.pruefmethoden as m (m.wert)}
                  <option value={m.wert}>{m.name} — {m.was}</option>
                {/each}
              </select>
            </label>

            <!-- Nur was zur Wahl passt: Ein Anbieterfeld bei HTTP-01 wäre eine
                 Aufforderung, etwas einzustellen, das nichts bewirkt. -->
            {#if zeigtAnbieter}
              <label class="feld">
                <span>{t.zert.anbieter}</span>
                <!-- Nur der Name. Bis 0.5 stand die Erklärung mit im Eintrag,
                     und bei zwei kurzen Sätzen ging das; mit sieben Anbietern
                     und ihren Hinweisen ist ein Auswahlfeld daraus, das nach
                     dreißig Zeichen abschneidet — „OVH — Schlüssel aus der
                     OVH-API-Kor…". Die Erklärung steht darunter, wo sie
                     vollständig hinpasst und ohnehin schon stand. -->
                <select id="zert-anbieter" bind:value={anbieter}>
                  {#each daten.anbieter_liste as a (a.wert)}
                    <option value={a.wert}>{a.name}</option>
                  {/each}
                </select>
              </label>
            {/if}

            {#if zeigtAnbieter && anbieter === "hook"}
              <label class="feld">
                <span>{t.zert.hookSetzen}</span>
                <input
                  id="zert-hook-setzen"
                  bind:value={hookSetzen}
                  type="text"
                  spellcheck="false"
                  placeholder="/usr/local/bin/dns-set"
                />
              </label>
              <label class="feld">
                <span>{t.zert.hookAufraeumen}</span>
                <input
                  id="zert-hook-aufraeumen"
                  bind:value={hookAufraeumen}
                  type="text"
                  spellcheck="false"
                  placeholder="/usr/local/bin/dns-clean"
                />
              </label>
              <p class="detail eingerueckt">{t.zert.hookWarum}</p>
            {/if}

            <!-- Ein Feld für alle Anbieter mit Zugangsdatei. Einzeilig, solange
                 genau ein Geheimnis gebraucht wird (Cloudflare, Hetzner,
                 DigitalOcean, IPv64); mehrzeilig, sobald der Anbieter mehrere
                 Einträge verlangt (acme-dns, netcup, OVH).

                 Welcher Fall gilt, sagt der Server über Wahl.felder. Ein
                 einzeiliges Feld für netcup wäre auf eine Weise falsch, die
                 erst beim Speichern auffiele — und dann mit einer Meldung über
                 ein Feld, das gar nicht dasteht. -->
            {#if zeigtZugang}
              <label class="feld">
                <span>{t.zert.token}</span>
                {#if mehrzeilig}
                  <!-- Kein type="password": Bei mehreren Zeilen wären Punkte
                       nicht zu lesen und nicht zu korrigieren. Der Schutz
                       liegt darin, dass der Wert nie zurückkommt — nicht
                       darin, ihn beim Tippen zu verbergen. -->
                  <textarea
                    id="zert-token"
                    bind:value={token}
                    rows={Math.max(3, (gewaehlt?.vorlage ?? zugangFelder).length)}
                    spellcheck="false"
                    autocomplete="off"
                  ></textarea>
                {:else}
                  <input id="zert-token" bind:value={token} type="password" autocomplete="off" />
                {/if}
              </label>
              {#if mehrzeilig}
                <p class="detail eingerueckt">{t.zert.zugangFelder(zugangFelder)}</p>
                {#if zugangKuer.length}
                  <p class="detail eingerueckt">{t.zert.zugangKuer(zugangKuer)}</p>
                {/if}
              {:else}
                <p class="detail eingerueckt">{t.zert.zugangEinzeilig}</p>
              {/if}
              {#if gewaehlt?.was}
                <p class="detail eingerueckt">{gewaehlt.was}</p>
              {/if}
              {#if daten.token_hinterlegt}
                <p class="detail eingerueckt">{t.zert.tokenHinterlegt}</p>
              {/if}
              <p class="detail eingerueckt">{t.zert.tokenWarum}</p>
            {/if}

            <label class="schalter">
              <input id="zert-staging" type="checkbox" bind:checked={testverzeichnis} />
              {t.zert.testverzeichnis}
            </label>
            <p class="detail eingerueckt">{t.zert.testverzeichnisWarum}</p>

            {#if brauchtAnbieter && anbieter === ""}
              <p class="anmerkung">{daten.anbieter_liste[0].was}</p>
            {/if}
          {/if}

          <div class="aktionen">
            <button type="submit" class="knopf" disabled={laufend !== ""}>
              {t.zert.speichern}
            </button>
            <!-- Das Panel versteckt nichts: Wo die Einstellungen landen, steht da. -->
            <span class="detail">{t.zert.verwalteteDatei(daten.verwaltete_datei)}</span>
          </div>
        </form>
      {/if}
    </section>
  </div>

  <!-- Der Bezug. Eigene Platte unter den beiden, weil der Verlauf breit ist. -->
  <section class="platte breit">
    <b>{t.zert.bezugTitel}</b>
    <p class="detail">{t.zert.bezugWarum}</p>
    {#if daten.bezug_zeit}
      <p class="detail">{t.zert.bezugZuletzt(daten.bezug_zeit)}</p>
    {/if}
    {#if daten.bezug_fehler}
      <p class="warnung">{t.zert.bezugFehler} {daten.bezug_fehler}</p>
    {/if}
    {#if darfSchreiben}
      <div class="aktionen">
        <button
          type="button"
          class="knopf leise"
          disabled={daten.modus !== "acme" || daten.bezug_laeuft || laufend !== ""}
          onclick={beziehen}
        >
          {t.zert.beziehen}
        </button>
        {#if daten.modus !== "acme"}
          <span class="detail">{t.zert.bezugNurACME}</span>
        {:else if daten.bezug_laeuft}
          <span class="detail">{t.zert.bezugLaeuft}</span>
        {/if}
      </div>
    {/if}

    <Vorgangsplatte {vorgang} />
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
    grid-template-columns: repeat(auto-fit, minmax(24rem, 1fr));
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

  form {
    display: grid;
    gap: 0.5rem;
    min-width: 0;
  }

  /* Die Bezugsart als zwei Karten: Die Folge steht neben der Wahl und nicht in
   * einer Erklärzeile darunter, die zu beiden gehören könnte. */
  .wahl {
    display: grid;
    gap: 0.4rem;
  }

  .wahl label {
    display: grid;
    grid-template-columns: auto 1fr;
    grid-template-areas: "radio name" ". detail";
    gap: 0.1rem 0.5rem;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.5rem 0.6rem;
    cursor: pointer;
  }

  .wahl label.an {
    border-color: var(--accent-dim);
  }

  .wahl input {
    grid-area: radio;
    align-self: center;
  }

  .wahl .name {
    grid-area: name;
    font: 650 0.84rem var(--sans);
    color: var(--tx);
  }

  .wahl .detail {
    grid-area: detail;
  }

  .feld {
    display: grid;
    grid-template-columns: 11rem 1fr;
    align-items: start;
    gap: 0.5rem;
    font-size: 0.8rem;
    color: var(--tx-mut);
  }

  .feld input,
  .feld select,
  .feld textarea {
    background: var(--bg);
    border: 1px solid var(--line2);
    border-radius: 7px;
    padding: 0.32rem 0.55rem;
    color: var(--tx);
    font: 0.82rem var(--mono);
    width: 100%;
    min-width: 0;
  }

  .feld textarea {
    resize: vertical;
    overflow-wrap: anywhere;
  }

  /* Die Begründung steht unter dem Feld und auf dessen Höhe eingerückt: So
   * gehört sie sichtbar zum Feld darüber und nicht zum nächsten. */
  .eingerueckt {
    margin-left: 11.5rem;
  }

  @media (max-width: 700px) {
    .feld {
      grid-template-columns: 1fr;
    }

    .eingerueckt {
      margin-left: 0;
    }
  }

  .schalter {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    color: var(--tx-mut);
    font-size: 0.8rem;
    cursor: pointer;
  }

  .rest {
    font: 0.78rem var(--mono);
    color: var(--tx-mut);
    margin-left: 0.4rem;
  }

  .rest.knapp {
    color: var(--accent);
  }

  .aktionen {
    display: flex;
    gap: 0.45rem;
    flex-wrap: wrap;
    min-width: 0;
    align-items: center;
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

  .detail code {
    font: 0.78rem var(--mono);
    color: var(--tx);
  }

  .klein {
    font-size: 0.72rem;
  }

  .warnung,
  .anmerkung {
    font-size: 0.82rem;
    overflow-wrap: anywhere;
    min-width: 0;
  }

  .warnung {
    color: var(--err);
  }

  /* Eine Anmerkung ist kein Fehler: „noch kein Zertifikat bezogen" ist eine
   * Auskunft über einen Zwischenzustand. */
  .anmerkung {
    color: var(--accent);
  }
</style>
