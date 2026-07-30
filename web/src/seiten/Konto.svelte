<script lang="ts">
  // Das eigene Konto: Passwort, zweiter Faktor, Wiederherstellungscodes,
  // Passkeys, offene Sitzungen.
  //
  // KEINE Werkbank. Die anderen Module verwalten viele gleichartige Dinge und
  // brauchen Liste plus Inspektor; hier gibt es genau ein Konto und fünf
  // verschiedene Handgriffe daran. Die Form ist deshalb ein Stapel benannter
  // Blöcke, jeder mit seinem Satz darüber, WARUM es ihn gibt — das ist Grundsatz
  // V: Die Oberfläche erklärt sich an der Stelle, an der etwas geschieht.
  //
  // Vier Dinge sind hier anders als in jedem anderen Modul:
  //
  //  1. **Die eigene Sitzung geht bei der Passwortänderung mit.** Der Server
  //     baut sie neu auf und schickt ein frisches Sitzungstoken; lib/api.ts
  //     übernimmt es. Ohne das wäre die Oberfläche nach einer geglückten
  //     Änderung bei jedem weiteren Aufruf abgemeldet.
  //  2. **Der halbe Wechsel des zweiten Faktors liegt auf dem SERVER.** Nach
  //     einem Neuladen steht er wieder da, mit der Frist daneben. Deshalb gibt es
  //     hier auch einen Knopf zum Abbrechen — in einer Einzelseiten-Anwendung ist
  //     „die Seite verlassen" kein Vorgang mehr.
  //  3. **Der Passkey braucht das Gerät.** Zwischen zwei Aufrufen spricht der
  //     Browser mit dem Authenticator. Was hier steht, ist der Satz „bitte am
  //     Gerät bestätigen" und die Behandlung des Abbruchs — die Zeremonie selbst
  //     liegt in lib/api.ts, die Prüfung auf dem Server.
  //  4. **Codes und Einmalpasswörter stehen genau einmal da.** Sie kommen in
  //     einen Dialog, der geschlossen werden muss, nicht in ein Band, das beim
  //     nächsten Klick verschwindet.
  import Rueckfrage from "../komponenten/Rueckfrage.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, api } from "../lib/api";
  import { t } from "../lib/texte";
  import type { Bestaetigung, EigenesKonto, Kontoauftrag2, Passkey } from "../lib/typen";

  let daten = $state<EigenesKonto | null>(null);
  let fehler = $state("");
  let abgemeldet = $state(false);

  let laufend = $state("");
  let meldung = $state("");
  let hinweis = $state("");
  let handlungFehler = $state("");
  let offeneFrage = $state<{
    frage: Bestaetigung;
    weiter: (getippt: string) => Promise<void>;
  } | null>(null);

  /** Die Eingaben. Alle Passwortfelder werden nach jedem Aufruf geleert — auch
   *  nach einem gescheiterten: Ein gefülltes Feld verleitet zum zweiten Versuch
   *  mit demselben falschen Wort. */
  let aktuell = $state("");
  let neu = $state("");
  let neuWiederholt = $state("");
  let faktorPasswort = $state("");
  let faktorCode = $state("");
  let passkeyPasswort = $state("");
  let passkeyName = $state("");
  /** benenntUm ist die Kennung des Passkeys, dessen Name gerade bearbeitet wird. */
  let benenntUm = $state<number | null>(null);
  let neuerName = $state("");

  /** gezeigteCodes sind Wiederherstellungscodes aus der letzten Antwort. Sie
   *  stehen in einem Dialog, der geschlossen werden muss, weil sie kein zweites
   *  Mal kommen. */
  let gezeigteCodes = $state<string[] | null>(null);
  let codesDialog: HTMLDialogElement | undefined = $state();
  let kopiert = $state(false);

  const wechsel = $derived(daten?.wechsel ?? null);

  $effect(() => {
    if (gezeigteCodes && codesDialog && !codesDialog.open) codesDialog.showModal();
  });

  async function laden() {
    fehler = "";
    try {
      daten = await api.konto();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) {
        abgemeldet = true;
        return;
      }
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  function meldungenLeeren() {
    meldung = "";
    hinweis = "";
    handlungFehler = "";
  }

  function feldernLeeren() {
    aktuell = "";
    neu = "";
    neuWiederholt = "";
    faktorPasswort = "";
    faktorCode = "";
    passkeyPasswort = "";
  }

  /** handlung führt eine verändernde Handlung aus und behandelt die Rückfrage —
   *  dasselbe Muster wie in den anderen Modulen. */
  async function handlung(
    marke: string,
    wohin: string,
    felder: Partial<Kontoauftrag2>,
    nachher: () => void = () => {},
  ) {
    laufend = marke;
    meldungenLeeren();

    const lauf = async (bestaetigt: boolean, getippt: string) => {
      const antwort = await api.kontoHandlung2(wohin, felder, bestaetigt, getippt);
      offeneFrage = null;
      meldung = antwort.meldung;
      hinweis = antwort.hinweis ?? "";
      if (antwort.codes && antwort.codes.length > 0) {
        gezeigteCodes = antwort.codes;
        kopiert = false;
      }
      // Abgemeldet: Die eigene Sitzung ist beendet. Nicht weiterladen — die
      // Antwort auf den nächsten Aufruf wäre 401, und daraus würde ein
      // Ladefehler statt einer klaren Auskunft.
      if (antwort.abgemeldet) {
        abgemeldet = true;
        return;
      }
      if (antwort.konto) daten = antwort.konto;
      nachher();
    };

    try {
      await lauf(false, "");
    } catch (e) {
      if (e instanceof AbgemeldetFehler) {
        abgemeldet = true;
        return;
      }
      if (e instanceof BestaetigungNoetig) {
        offeneFrage = { frage: e.bestaetigung, weiter: (wort) => lauf(true, wort) };
        return;
      }
      handlungFehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      laufend = "";
      feldernLeeren();
    }
  }

  async function frageBestaetigen(getippt: string) {
    const jetzt = offeneFrage;
    if (!jetzt) return;
    laufend = laufend || "frage";
    try {
      await jetzt.weiter(getippt);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) {
        abgemeldet = true;
        return;
      }
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

  function passwortAendern(e: SubmitEvent) {
    e.preventDefault();
    void handlung("passwort", "/password", {
      passwort: aktuell,
      neu,
      neu_wiederholt: neuWiederholt,
    });
  }

  function faktorStarten(e: SubmitEvent) {
    e.preventDefault();
    void handlung("faktor", "/2fa", { passwort: faktorPasswort });
  }

  function faktorBestaetigen(e: SubmitEvent) {
    e.preventDefault();
    void handlung("faktor-fertig", "/2fa/confirm", { code: faktorCode });
  }

  function faktorAbbrechen() {
    void handlung("faktor-ab", "/2fa/cancel", {});
  }

  function codesNeu() {
    void handlung("codes", "/recovery-codes", {});
  }

  function sitzungBeenden(id: string) {
    void handlung("sitzung-" + id, "/sessions/revoke", { sitzung: id });
  }

  function andereBeenden() {
    void handlung("andere", "/sessions/revoke-others", {});
  }

  /** passkeyHinterlegen ist der einzige Handgriff, der nicht über handlung läuft:
   *  Zwischen den zwei Aufrufen spricht der Browser mit dem Gerät, und in dieser
   *  Zeit soll ein eigener Satz stehen. Ein Abbruch am Gerät ist außerdem kein
   *  Fehler des Panels und wird nicht als solcher gemeldet. */
  async function passkeyHinterlegen(e: SubmitEvent) {
    e.preventDefault();
    laufend = "passkey";
    meldungenLeeren();
    hinweis = t.konto.amGeraet;
    const passwort = passkeyPasswort;
    try {
      const antwort = await api.passkeyAnlegen(passkeyName.trim(), passwort);
      meldung = antwort.meldung;
      hinweis = antwort.hinweis ?? "";
      if (antwort.konto) daten = antwort.konto;
      passkeyName = "";
    } catch (err) {
      if (err instanceof AbgemeldetFehler) {
        abgemeldet = true;
        return;
      }
      hinweis = "";
      // NotAllowedError heißt: am Gerät abgebrochen oder abgelaufen. Das ist eine
      // Entscheidung des Bedieners und keine Panne — als rote Fehlermeldung wäre
      // es eine Beschuldigung.
      if (err instanceof DOMException && err.name === "NotAllowedError") {
        meldung = t.konto.passkeyAbgebrochen;
      } else {
        handlungFehler = err instanceof Error ? err.message : t.fehler.laden;
      }
    } finally {
      laufend = "";
      passkeyPasswort = "";
    }
  }

  function umbenennenStarten(p: Passkey) {
    benenntUm = p.id;
    neuerName = p.name;
  }

  function umbenennenSpeichern(e: SubmitEvent) {
    e.preventDefault();
    const id = benenntUm;
    if (id === null) return;
    void handlung("umbenennen", `/passkeys/${id}/rename`, { name: neuerName }, () => {
      benenntUm = null;
    });
  }

  function passkeyEntfernen(p: Passkey) {
    void handlung("passkey-" + p.id, `/passkeys/${p.id}/delete`, {});
  }

  async function codesKopieren() {
    if (!gezeigteCodes) return;
    try {
      await navigator.clipboard.writeText(gezeigteCodes.join("\n"));
      kopiert = true;
    } catch {
      // Die Zwischenablage braucht einen sicheren Ursprung und die Erlaubnis des
      // Browsers. Fehlt beides, bleiben die Codes lesbar auf dem Schirm.
      kopiert = false;
    }
  }

  function codesSchliessen() {
    codesDialog?.close();
    gezeigteCodes = null;
    kopiert = false;
  }

  laden();
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.betrieb} / {t.ziele.konto}</div>
    <div class="h1">{daten?.name ?? t.ziele.konto}</div>
  </div>
  <div class="schub"></div>
  {#if daten}
    <span class="marke">{daten.rolle}</span>
  {/if}
</div>

<p class="wesen">{t.konto.wesen}</p>

{#if abgemeldet}
  <!-- Kein Ladefehler: Die Sitzung ist beendet, und das war in den meisten Fällen
       Absicht — die eigene Sitzung beendet oder das Passwort geändert. -->
  <div class="hinweis">
    <p>{t.konto.abgemeldet}</p>
    <a class="knopf" href="/login">{t.konto.zurAnmeldung}</a>
  </div>
{:else if fehler && !daten}
  <div class="hinweis">
    <p>{t.fehler.laden}</p>
    <p class="detail">{fehler}</p>
    <button class="knopf" onclick={laden}>{t.fehler.erneut}</button>
  </div>
{:else if !daten}
  <p class="detail">{t.konto.laedt}</p>
{:else}
  {#if daten.wechselzwang}
    <p class="band warn" role="status">{t.konto.wechselzwang}</p>
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
    <!-- Der Überblick. Nur Auskünfte, keine Handgriffe. -->
    <section class="platte">
      <dl class="kv">
        <dt>{t.konto.rolle}</dt>
        <dd>{daten.rolle} — {daten.rolle_was}</dd>
        <dt>{t.konto.angelegt}</dt>
        <dd>{daten.angelegt}</dd>
        <dt>{t.konto.codesOffen}</dt>
        <dd class:warnwert={daten.codes_offen === 0}>
          {daten.codes_offen === 0
            ? t.konto.codesKeine
            : t.konto.codesZahl(daten.codes_offen)}
        </dd>
      </dl>
      {#if daten.codes_offen === 0}
        <p class="warnung">{t.konto.codesWarnung}</p>
      {/if}
    </section>

    <!-- Passwort. -->
    <section class="platte">
      <b>{t.konto.passwortTitel}</b>
      <p class="detail">{t.konto.aktuellWarum}</p>
      <form onsubmit={passwortAendern}>
        <label>
          <span>{t.konto.aktuell}</span>
          <input id="pw-aktuell" bind:value={aktuell} type="password" autocomplete="current-password" />
        </label>
        <label>
          <span>{t.konto.neu}</span>
          <input id="pw-neu" bind:value={neu} type="password" autocomplete="new-password" />
        </label>
        <label>
          <span>{t.konto.neuWiederholt}</span>
          <input id="pw-neu2" bind:value={neuWiederholt} type="password" autocomplete="new-password" />
        </label>
        <div class="aktionen">
          <button
            type="submit"
            class="knopf"
            disabled={!aktuell || !neu || laufend !== ""}
          >
            {t.konto.passwortAendern}
          </button>
          <span class="detail">{t.konto.passwortFolge}</span>
        </div>
      </form>
    </section>

    <!-- Zweiter Faktor. Der begonnene Wechsel steht hier, wenn es einen gibt —
         der Zustand liegt auf dem Server und übersteht ein Neuladen. -->
    <section class="platte" class:offen={wechsel !== null}>
      <b>{t.konto.faktorTitel}</b>
      {#if wechsel}
        <p class="anmerkung">
          {t.konto.wechselOffen} · {t.konto.wechselBis(wechsel.laeuft_ab)}
        </p>
        <p class="detail">{t.konto.wechselScannen}</p>
        <div class="zeremonie">
          <!-- Das Bild kommt vom Server und nicht als data:-URI: Sonst stünde das
               Geheimnis ein zweites Mal in der Antwort. -->
          <img src={wechsel.qr} alt={t.konto.qrAlt} width="180" height="180" />
          <div class="geheimnis">
            <span class="detail">{t.konto.geheimnis}</span>
            <code>{wechsel.geheimnis_text}</code>
          </div>
        </div>
        <form onsubmit={faktorBestaetigen}>
          <label>
            <span>{t.konto.code}</span>
            <input
              id="f2-code"
              bind:value={faktorCode}
              type="text"
              inputmode="numeric"
              autocomplete="one-time-code"
              spellcheck="false"
            />
          </label>
          <div class="aktionen">
            <button type="submit" class="knopf" disabled={!faktorCode || laufend !== ""}>
              {t.konto.faktorBestaetigen}
            </button>
            <button
              type="button"
              class="knopf leise"
              disabled={laufend !== ""}
              onclick={faktorAbbrechen}
            >
              {t.konto.faktorAbbrechen}
            </button>
          </div>
          <p class="detail">{t.konto.faktorFolge}</p>
        </form>
      {:else}
        <p class="detail">
          {daten.zweiter_faktor ? t.konto.faktorGut : ""}
          {t.konto.faktorWarum}
        </p>
        <form onsubmit={faktorStarten}>
          <label>
            <span>{t.konto.aktuell}</span>
            <input id="f2-pass" bind:value={faktorPasswort} type="password" autocomplete="current-password" />
          </label>
          <div class="aktionen">
            <button
              type="submit"
              class="knopf leise"
              disabled={!faktorPasswort || laufend !== ""}
            >
              {t.konto.faktorWechseln}
            </button>
          </div>
        </form>
      {/if}
    </section>

    <!-- Wiederherstellungscodes. -->
    <section class="platte">
      <b>{t.konto.codesTitel}</b>
      <p class="detail">{t.konto.codesWarum}</p>
      <div class="aktionen">
        <button
          type="button"
          class="knopf leise"
          disabled={laufend !== ""}
          onclick={codesNeu}
        >
          {t.konto.codesNeu}
        </button>
        <span class="detail">
          {daten.codes_offen === 0
            ? t.konto.codesKeine
            : t.konto.codesZahl(daten.codes_offen)}
        </span>
      </div>
    </section>

    <!-- Passkeys. -->
    <section class="platte">
      <b>{t.konto.passkeysTitel}</b>
      {#if !daten.passkeys_moeglich}
        <p class="detail">{t.konto.passkeysAus}</p>
      {:else}
        <p class="detail">{t.konto.passkeysWarum}</p>
        {#if daten.passkeys.length === 0}
          <p class="detail">{t.konto.passkeysKeine}</p>
        {:else}
          <ul class="passkeys">
            {#each daten.passkeys as p (p.id)}
              <li>
                {#if benenntUm === p.id}
                  <form class="umbenennen" onsubmit={umbenennenSpeichern}>
                    <label>
                      <span class="nur-vorlese">{t.konto.passkeyName}</span>
                      <!-- svelte-ignore a11y_autofocus -->
                      <input bind:value={neuerName} type="text" autofocus />
                    </label>
                    <button type="submit" class="knopf klein" disabled={laufend !== ""}>
                      {t.konto.speichern}
                    </button>
                    <button
                      type="button"
                      class="knopf leise klein"
                      onclick={() => (benenntUm = null)}
                    >
                      {t.konto.abbrechen}
                    </button>
                  </form>
                {:else}
                  <div class="kopf">
                    <b class="name">{p.name}</b>
                    <span class="marke" class:warn={!p.synced}>
                      {p.synced ? t.konto.synced : t.konto.gebunden}
                    </span>
                  </div>
                  <span class="detail">
                    {p.angelegt} · {p.zuletzt || t.konto.nieBenutzt}
                  </span>
                  <div class="aktionen">
                    <button
                      type="button"
                      class="knopf leise klein"
                      onclick={() => umbenennenStarten(p)}
                    >
                      {t.konto.umbenennen}
                    </button>
                    <button
                      type="button"
                      class="knopf leise klein"
                      disabled={laufend !== ""}
                      onclick={() => passkeyEntfernen(p)}
                    >
                      {t.konto.entfernen}
                    </button>
                  </div>
                {/if}
              </li>
            {/each}
          </ul>
        {/if}

        <form onsubmit={passkeyHinterlegen}>
          <label>
            <span>{t.konto.passkeyName}</span>
            <input
              id="pk-name"
              bind:value={passkeyName}
              type="text"
              autocomplete="off"
              placeholder={t.konto.passkeyNamePlatzhalter}
            />
          </label>
          <label>
            <span>{t.konto.aktuell}</span>
            <input id="pk-pass" bind:value={passkeyPasswort} type="password" autocomplete="current-password" />
          </label>
          <div class="aktionen">
            <button
              type="submit"
              class="knopf"
              disabled={!passkeyPasswort || laufend !== ""}
            >
              {t.konto.passkeyAnlegen}
            </button>
          </div>
        </form>
      {/if}
    </section>

    <!-- Offene Sitzungen. -->
    <section class="platte breit">
      <b>{t.konto.sitzungenTitel}</b>
      <p class="detail">{t.konto.sitzungenWarum}</p>

      <div class="tabelle-rahmen">
        <table class="tabelle">
          <thead>
            <tr>
              <th>{t.konto.von}</th>
              <th>{t.konto.programm}</th>
              <th>{t.konto.seit}</th>
              <th>{t.konto.zuletzt}</th>
              <th>{t.konto.laeuftAb}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {#each daten.sitzungen as sz (sz.id)}
              <tr class:gewaehlt={sz.diese}>
                <td data-spalte={t.konto.von}>
                  <span class="pfad">{sz.ip || "—"}</span>
                  {#if sz.diese}
                    <span class="marke">{t.konto.diese}</span>
                  {/if}
                </td>
                <td data-spalte={t.konto.programm}>{sz.programm}</td>
                <td data-spalte={t.konto.seit} class="gedaempft">{sz.seit}</td>
                <td data-spalte={t.konto.zuletzt} class="gedaempft">{sz.zuletzt}</td>
                <td data-spalte={t.konto.laeuftAb} class="gedaempft">{sz.laeuft_ab}</td>
                <!-- data-spalte leer wie bei der Firewall: In der Kartenansicht
                     braucht ein Knopf, der „abmelden" heißt, keinen Spaltennamen
                     davor. -->
                <td data-spalte="">
                  <!-- Die eigene Sitzung zu beenden IST ein Abmelden. Der Knopf
                       heißt deshalb so — „beenden" wäre hier eine Untertreibung
                       darüber, was gleich passiert. -->
                  <button
                    type="button"
                    class="knopf leise klein"
                    disabled={laufend !== ""}
                    onclick={() => sitzungBeenden(sz.id)}
                  >
                    {sz.diese ? t.konto.abmelden : t.konto.beenden}
                  </button>
                </td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>

      <div class="aktionen">
        {#if daten.andere > 0}
          <button
            type="button"
            class="knopf leise"
            disabled={laufend !== ""}
            onclick={andereBeenden}
          >
            {t.konto.andereBeenden(daten.andere)}
          </button>
        {:else}
          <span class="detail">{t.konto.keineAnderen}</span>
        {/if}
      </div>
    </section>
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

<!-- Die Wiederherstellungscodes. Ein Dialog und kein Band: Sie kommen kein
     zweites Mal, und ein Band verschwindet beim nächsten Klick, ohne dass es
     jemandem auffällt. Escape ist abgefangen. -->
{#if gezeigteCodes}
  <dialog
    bind:this={codesDialog}
    class="codes"
    aria-labelledby="codes-titel"
    oncancel={(e) => e.preventDefault()}
  >
    <h2 id="codes-titel">{t.konto.codesTitel}</h2>
    <p class="warnung">{t.konto.codesEinmal}</p>
    <ul class="liste">
      {#each gezeigteCodes as code (code)}
        <li>{code}</li>
      {/each}
    </ul>
    <div class="aktionen">
      <button type="button" class="knopf leise" onclick={codesKopieren}>
        {kopiert ? t.konto.kopiert : t.konto.kopieren}
      </button>
      <button type="button" class="knopf" onclick={codesSchliessen}>
        {t.konto.verstanden}
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

  /* Zwei Spalten breit, eine schmal. Die Sitzungen bekommen die ganze Breite:
   * Sechs Spalten in einer halben Breite wären eine Tabelle, die man schiebt. */
  .bloecke {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(22rem, 1fr));
    gap: 0.8rem;
    align-items: start;
  }

  .platte.breit {
    grid-column: 1 / -1;
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

  /* Ein offener Wechsel des zweiten Faktors ist ein Zustand, in dem etwas
   * aussteht. Der Rahmen sagt es, damit man ihn nicht übersieht und den halben
   * Wechsel liegen lässt. */
  .platte.offen {
    border-color: var(--accent-dim);
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

  form label {
    display: grid;
    grid-template-columns: 11rem 1fr;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8rem;
    color: var(--tx-mut);
  }

  form input {
    background: var(--bg);
    border: 1px solid var(--line2);
    border-radius: 7px;
    padding: 0.32rem 0.55rem;
    color: var(--tx);
    font: 0.82rem var(--mono);
    width: 100%;
    min-width: 0;
  }

  .zeremonie {
    display: flex;
    gap: 0.9rem;
    align-items: center;
    flex-wrap: wrap;
  }

  .zeremonie img {
    background: #fff;
    border-radius: 8px;
    padding: 6px;
    flex: none;
  }

  .geheimnis {
    display: grid;
    gap: 0.2rem;
    min-width: 0;
  }

  .geheimnis code {
    font: 0.9rem var(--mono);
    letter-spacing: 0.06em;
    user-select: all;
    overflow-wrap: anywhere;
  }

  .passkeys {
    list-style: none;
    display: grid;
    gap: 0.5rem;
    margin: 0.2rem 0;
  }

  .passkeys li {
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.5rem 0.6rem;
    display: grid;
    gap: 0.3rem;
    justify-items: start;
    min-width: 0;
  }

  .passkeys .kopf {
    display: flex;
    align-items: baseline;
    gap: 0.4rem;
    flex-wrap: wrap;
  }

  .passkeys .name {
    font: 650 0.84rem var(--sans);
    text-transform: none;
    letter-spacing: normal;
    color: var(--tx);
    overflow-wrap: anywhere;
  }

  .umbenennen {
    display: flex;
    gap: 0.4rem;
    align-items: center;
    flex-wrap: wrap;
    width: 100%;
  }

  .umbenennen label {
    display: contents;
  }

  .umbenennen input {
    width: 12rem;
  }

  .aktionen {
    display: flex;
    gap: 0.45rem;
    flex-wrap: wrap;
    min-width: 0;
    align-items: center;
  }

  :global(table.tabelle tr.gewaehlt) {
    background: var(--surface2);
  }

  .codes {
    background: var(--surface);
    border: 1px solid var(--accent-dim);
    border-radius: var(--r);
    padding: 1.1rem 1.2rem;
    max-width: 32rem;
    color: var(--tx);
    display: grid;
    gap: 0.6rem;
  }

  .codes::backdrop {
    background: rgba(0, 0, 0, 0.6);
  }

  .codes h2 {
    font-size: 0.95rem;
    font-weight: 650;
  }

  /* Die Codes in zwei Spalten, groß und einzeilig: Sie werden abgeschrieben. */
  .codes .liste {
    list-style: none;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
    gap: 0.3rem 0.8rem;
    background: var(--bg);
    border: 1px solid var(--line2);
    border-radius: 8px;
    padding: 0.6rem 0.7rem;
  }

  .codes .liste li {
    font: 0.92rem var(--mono);
    letter-spacing: 0.04em;
    user-select: all;
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

  .warnung,
  .anmerkung {
    font-size: 0.82rem;
    overflow-wrap: anywhere;
    min-width: 0;
  }

  .warnung {
    color: var(--err);
  }

  /* Eine Anmerkung ist kein Fehler: „Wechsel begonnen" ist eine Auskunft über
   * einen Zustand. */
  .anmerkung {
    color: var(--accent);
  }

  .warnwert {
    color: var(--err);
  }
</style>
