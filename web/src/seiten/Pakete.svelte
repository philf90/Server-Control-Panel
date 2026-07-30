<script lang="ts">
  // Pakete: das erste Modul mit einer Handlung, die Minuten dauert.
  //
  // Deshalb steht hier die Vorgangsplatte, und deshalb ist die Reihenfolge auf
  // der Seite eine andere als bei den Diensten: erst der Handlungsbedarf
  // (Neustart steht aus), dann was gerade läuft, dann die Liste. Wer die Seite
  // öffnet, während apt arbeitet, soll das sehen, bevor er noch einmal drückt.
  //
  // Der Vorgang läuft auf dem Server weiter, wenn jemand die Seite verlässt.
  // Diese Seite ist ihm nur zugesehen — sie startet ihn und hängt sich an, mehr
  // nicht. Ein abgebrochenes apt-get hinterlässt ein halb konfiguriertes System;
  // das darf nicht davon abhängen, ob ein Tab offen bleibt.
  import Rueckfrage from "../komponenten/Rueckfrage.svelte";
  import Vorgangsplatte from "../komponenten/Vorgangsplatte.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { Vorgang } from "../lib/vorgang.svelte";
  import { normal } from "../lib/ziele";
  import type { Bestaetigung, Job, Pakete } from "../lib/typen";

  let {
    darfSchreiben = false,
    istOwner = false,
  }: { darfSchreiben?: boolean; istOwner?: boolean } = $props();

  let daten = $state<Pakete | null>(null);
  let fehler = $state("");
  let meldung = $state("");
  let filter = $state("");
  let nurSicherheit = $state(false);
  let laufendeAktion = $state("");

  // Der Beobachter des Paketvorgangs. Einer für die Lebensdauer der Seite: Zwei
  // wären zwei Ereignisfelder auf denselben Strom.
  const vorgang = new Vorgang("packages");

  // offeneFrage hält die Rückfrage zusammen mit dem, was sie bestätigen soll.
  // Ein Dialog, der nicht weiß, was er bestätigt, kann nichts ausführen.
  let offeneFrage = $state<{
    frage: Bestaetigung;
    tun: (getippt: string) => Promise<void>;
  } | null>(null);

  const gefiltert = $derived.by(() => {
    const alle = daten?.pakete ?? [];
    const b = normal(filter.trim());
    return alle.filter((p) => {
      if (nurSicherheit && !p.sicherheit) return false;
      return !b || normal(p.name).includes(b) || normal(p.quelle).includes(b);
    });
  });

  async function laden() {
    fehler = "";
    try {
      daten = await api.pakete();
      // Den Vorgang aus derselben Antwort übernehmen: Läuft einer, steht die
      // Platte sofort da und hängt sich an den Strom.
      vorgang.setzen(daten.job);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  // Beim Verlassen der Seite den Strom schließen. Ohne das bleibt ein
  // Ereignisfeld offen, und der Server hält einen Abonnenten dafür.
  $effect(() => () => vorgang.loesen());

  /** starten führt eine Aktion aus und behandelt die Rückfrage des Servers.
   *
   *  Eine Funktion für alle drei Wege, weil sich nur der eine Aufruf
   *  unterscheidet: Die Behandlung der Rückfrage, der Meldung und des Fehlers
   *  ist dieselbe, und dreimal geschrieben wäre sie zweimal falsch. */
  async function starten(
    name: string,
    aufruf: (bestaetigt: boolean, getippt: string) => Promise<{ meldung: string; job?: Job }>,
  ) {
    laufendeAktion = name;
    meldung = "";
    fehler = "";
    try {
      const antwort = await aufruf(false, "");
      offeneFrage = null;
      meldung = antwort.meldung;
      uebernehmen(antwort.job);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      if (e instanceof BestaetigungNoetig) {
        offeneFrage = {
          frage: e.bestaetigung,
          tun: async (getippt: string) => {
            laufendeAktion = name;
            try {
              const antwort = await aufruf(true, getippt);
              offeneFrage = null;
              meldung = antwort.meldung;
              uebernehmen(antwort.job);
            } catch (zweite) {
              if (zweite instanceof AbgemeldetFehler) throw zweite;
              // Auch der zweite Anlauf kann zurückkommen: bei Stufe 3, wenn das
              // getippte Wort nicht passte. Dann trägt die Frage das Feld
              // `fehler`, und der Dialog bleibt mit dem Grund stehen.
              if (zweite instanceof BestaetigungNoetig) {
                offeneFrage = { frage: zweite.bestaetigung, tun: offeneFrage!.tun };
                return;
              }
              offeneFrage = null;
              fehler = zweite instanceof Error ? zweite.message : t.fehler.laden;
            } finally {
              laufendeAktion = "";
            }
          },
        };
        return;
      }
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      // Ohne Bedingung zurücksetzen. Der erste Anlauf ist hier vorbei — auch
      // dann, wenn der Server zurückgefragt hat: In diesem Augenblick läuft
      // nichts, es steht nur ein Dialog. Mit einer Bedingung („nur wenn keine
      // Frage offen ist") blieb die Marke stehen, und der bestätigende Knopf im
      // Dialog war für immer gesperrt — er hielt die Aktion für laufend.
      // Gesehen hat das der Browsertest.
      laufendeAktion = "";
    }
  }

  /** uebernehmen hängt sich an den Vorgang, den die Antwort mitbringt.
   *
   *  Aus der Antwort und nicht über einen Abruf danach: Der Server hat den
   *  Vorgang gerade angelegt, er läuft also — der Strom kann sofort auf. Erst
   *  abzufragen wäre eine Runde später, und bei einem Vorgang, der in der
   *  Zwischenzeit fertig wird, käme die Antwort „läuft nicht" und der Strom
   *  ginge nie auf. Bei apt-get update über einen schnellen Spiegel ist das der
   *  Normalfall, nicht die Ausnahme.
   *
   *  Der Neustart bringt keinen Vorgang mit — er ist keiner. */
  function uebernehmen(job: Job | undefined) {
    if (!job) return;
    vorgang.setzen(job);
  }

  async function neuLesen() {
    try {
      const frisch = await api.pakete();
      daten = frisch;
    } catch {
      /* Die Liste bleibt der vorige Stand; der Vorgang steht darüber. */
    }
  }

  // Ist der Vorgang durchgelaufen, will die Liste neu gelesen werden: Nach einem
  // Update ist sie leer, nach einem Listenabgleich meist länger. Ohne das stünde
  // die alte Liste unter einem Vorgang, der sagt, dass er fertig ist.
  let zuletztFertig = $state(true);
  $effect(() => {
    const laeuft = vorgang.job?.laeuft ?? false;
    if (!laeuft && !zuletztFertig) {
      zuletztFertig = true;
      // Die Startmeldung („Die Paketlisten werden geholt.") weg, sobald die
      // Platte das Ergebnis zeigt. Zwei Aussagen zum selben Vorgang, von denen
      // eine veraltet ist, sind schlechter als eine.
      meldung = "";
      void neuLesen();
    }
    if (laeuft) zuletztFertig = false;
  });

  const arbeitet = $derived(laufendeAktion !== "" || (vorgang.job?.laeuft ?? false));

  laden();
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.system} / {t.ziele.pakete}</div>
    <div class="h1">{t.ziele.pakete}</div>
  </div>
  <div class="schub"></div>
  {#if daten}
    <span class="marke">
      {t.pakete.updates(daten.zaehler.gesamt)}
      {#if daten.zaehler.sicherheit > 0}
        · {t.pakete.sicherheitsupdates(daten.zaehler.sicherheit)}
      {/if}
    </span>
  {/if}
</div>

{#if !daten && !fehler}
  <p class="detail">{t.pakete.laedt}</p>
{:else}
  {#if daten?.neustart.erforderlich}
    <!-- Erst der Handlungsbedarf. Er steht über allem anderen, weil ein
         ausstehender Neustart bedeutet, dass eingespielte Updates noch nicht
         wirken — und das ist die wichtigste Aussage der Seite. -->
    <div class="neustart">
      <span class="zustand warn"><i aria-hidden="true"></i></span>
      <div class="text">
        <b>{t.pakete.neustartTitel}</b>
        {#if daten.neustart.pakete.length > 0}
          <span class="detail">
            {t.pakete.neustartWegen} {daten.neustart.pakete.join(", ")}
          </span>
        {/if}
      </div>
      {#if istOwner}
        <button
          type="button"
          class="knopf gefahr"
          disabled={arbeitet}
          onclick={() =>
            starten("reboot", (b, g) => api.neustarten(b, g))}
        >
          {t.pakete.neustartKnopf}
        </button>
      {:else}
        <span class="detail">{t.pakete.neustartNurOwner}</span>
      {/if}
    </div>
  {/if}

  <Vorgangsplatte {vorgang} />

  {#if meldung}
    <p class="meldung" role="status">{meldung}</p>
  {/if}
  {#if fehler}
    <p class="warnung" role="alert">{fehler}</p>
  {/if}
  {#if daten?.fehler}
    <p class="warnung">{daten.fehler}</p>
  {/if}

  {#if darfSchreiben}
    <div class="aktionen">
      <button
        type="button"
        class="knopf leise"
        disabled={arbeitet}
        onclick={() => starten("refresh", () => api.paketlistenHolen())}
      >
        {t.pakete.listenHolen}
      </button>
      {#if daten && daten.zaehler.gesamt > 0}
        <button
          type="button"
          class="knopf"
          disabled={arbeitet}
          onclick={() => starten("alle", (b) => api.einspielen("alle", "", b))}
        >
          {t.pakete.alleEinspielen}
        </button>
      {/if}
      {#if daten && daten.zaehler.sicherheit > 0}
        <button
          type="button"
          class="knopf leise"
          disabled={arbeitet}
          onclick={() => starten("sicherheit", (b) => api.einspielen("sicherheit", "", b))}
        >
          {t.pakete.nurSicherheit}
        </button>
      {/if}
    </div>
  {:else}
    <p class="detail">{t.pakete.nurLesen}</p>
  {/if}

  {#if daten && daten.zaehler.gesamt === 0}
    <div class="leer">
      <p><b>{t.pakete.keine}</b></p>
      <p class="detail">{t.pakete.keineDetail}</p>
    </div>
  {:else if daten}
    <div class="werkzeuge">
      <label class="suche">
        <span class="nur-vorlese">{t.pakete.suchen}</span>
        <input
          bind:value={filter}
          type="search"
          placeholder={t.pakete.suchen}
          autocomplete="off"
          spellcheck="false"
        />
      </label>
      {#if daten.zaehler.sicherheit > 0}
        <div class="stufen">
          <!-- „alle" und nicht „Update": Bei 2 Paketen, davon 1 aus einer
               Sicherheitsquelle, läse sich „Update 2" als zwei gewöhnliche
               Updates neben einem Sicherheitsupdate — es sind aber zwei
               insgesamt. -->
          <button type="button" class:an={!nurSicherheit} onclick={() => (nurSicherheit = false)}>
            {t.pakete.alle} <b>{daten.zaehler.gesamt}</b>
          </button>
          <button type="button" class:an={nurSicherheit} onclick={() => (nurSicherheit = true)}>
            {t.pakete.sicherheit} <b>{daten.zaehler.sicherheit}</b>
          </button>
        </div>
      {/if}
    </div>

    <div class="tabelle-rahmen">
      <table class="tabelle">
        <thead>
          <tr>
            <th>{t.pakete.paket}</th>
            <th>{t.pakete.version}</th>
            <th>{t.pakete.quelle}</th>
            <th>{t.pakete.art}</th>
            {#if darfSchreiben}<th></th>{/if}
          </tr>
        </thead>
        <tbody>
          {#if gefiltert.length === 0}
            <tr><td colspan={darfSchreiben ? 5 : 4} class="gedaempft">{t.pakete.nichts}</td></tr>
          {:else}
            {#each gefiltert as paket (paket.name)}
              <tr class:eng={paket.sicherheit}>
                <td data-spalte={t.pakete.paket} class="pfad">{paket.name}</td>
                <!-- Von und Nach in einer Zelle: Getrennt wären es zwei Spalten,
                     die man nebeneinander lesen muss, um den Unterschied zu
                     sehen. Der Pfeil sagt es in einem Blick. -->
                <td data-spalte={t.pakete.version} class="pfad">
                  <span class="gedaempft">{paket.von}</span> → {paket.nach}
                </td>
                <td data-spalte={t.pakete.quelle} class="gedaempft">{paket.quelle || "—"}</td>
                <td data-spalte={t.pakete.art}>
                  {#if paket.sicherheit}
                    <span class="zustand schlecht">
                      <i aria-hidden="true"></i>{t.pakete.sicherheit}
                    </span>
                  {:else}
                    <span class="gedaempft">{t.pakete.normal}</span>
                  {/if}
                </td>
                {#if darfSchreiben}
                  <td data-spalte="" class="zahlenspalte">
                    <!-- Ein einzelnes Paket ist ein gezielter Klick in seiner
                         Zeile — Stufe 1, keine Rückfrage. -->
                    <button
                      type="button"
                      class="knopf leise klein"
                      disabled={arbeitet}
                      onclick={() =>
                        starten("einzeln:" + paket.name, () =>
                          api.einspielen("einzeln", paket.name, false),
                        )}
                    >
                      {t.pakete.einzelnEinspielen}
                    </button>
                  </td>
                {/if}
              </tr>
            {/each}
          {/if}
        </tbody>
      </table>
    </div>
  {/if}
{/if}

{#if offeneFrage}
  <Rueckfrage
    frage={offeneFrage.frage}
    laeuft={laufendeAktion !== ""}
    bestaetigen={(getippt) => offeneFrage!.tun(getippt)}
    abbrechen={() => {
      offeneFrage = null;
      laufendeAktion = "";
    }}
  />
{/if}

<style>
  .neustart {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    flex-wrap: wrap;
    background: var(--surface);
    border: 1px solid var(--accent-dim);
    border-radius: var(--r);
    padding: 0.7rem 0.85rem;
    margin-bottom: 1rem;
  }

  .neustart .text {
    display: grid;
    gap: 0.15rem;
    min-width: 0;
    margin-right: auto;
  }

  .neustart b {
    font-size: 0.9rem;
    font-weight: 650;
  }

  .aktionen {
    display: flex;
    gap: 0.45rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
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
    width: 15rem;
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

  .klein {
    font-size: 0.72rem;
    padding: 0.15rem 0.5rem;
  }

  .leer {
    display: grid;
    gap: 0.4rem;
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    padding: 1.6rem 1rem;
    text-align: center;
  }

  .detail {
    color: var(--tx-mut);
    font: 0.82rem var(--mono);
  }

  .meldung {
    font-size: 0.82rem;
    color: var(--ok);
    margin-bottom: 0.7rem;
  }

  .warnung {
    font-size: 0.82rem;
    color: var(--err);
    margin-bottom: 0.7rem;
  }
</style>
