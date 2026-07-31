<script lang="ts">
  // Docker — Schritt 1 der Fassung 0.5: der Zustand der Laufzeit und ihre
  // Installation.
  //
  // Die Seite hat in dieser Fassung genau eine Aufgabe, und die ist der Grund,
  // warum sie schon jetzt existiert: Fehlt Docker, bietet das Panel es an,
  // statt eine Kommandozeile zum Abtippen zu drucken. Dieselbe Antwort, die die
  // Firewall seit rc.4 gibt.
  //
  // Drei Zustände, drei verschiedene Antworten — und keine zwei davon dürfen
  // gleich aussehen: Docker fehlt (apt hilft), Docker ist da und antwortet
  // nicht (der Dienst hilft), Compose fehlt (apt hilft wieder). Welche Antwort
  // gilt, entscheidet der Server und schickt sie fertig mit; die Seite legt
  // keine eigene Auslegung daneben.
  import Containerwerkbank from "../komponenten/Containerliste.svelte";
  import Vorgangsplatte from "../komponenten/Vorgangsplatte.svelte";
  import { AbgemeldetFehler, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { verweis } from "../lib/weg.svelte";
  import { Vorgang } from "../lib/vorgang.svelte";
  import type { Docker } from "../lib/typen";

  let daten = $state<Docker | null>(null);
  let fehler = $state("");
  let arbeitet = $state(false);

  const vorgang = new Vorgang("docker-install");

  async function laden() {
    fehler = "";
    try {
      const frisch = await api.docker();
      daten = frisch;
      vorgang.setzen(frisch.job);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  void laden();

  $effect(() => () => vorgang.loesen());

  // Nach dem Ende des Vorgangs den Zustand neu holen: Was danach gilt, sagt der
  // Server. Die Seite selbst weiß nicht, ob apt geglückt ist — sie weiß nur,
  // dass der Lauf vorbei ist.
  let liefZuvor = $state(false);
  $effect(() => {
    const laeuft = vorgang.job?.laeuft ?? false;
    if (liefZuvor && !laeuft) void laden();
    liefZuvor = laeuft;
  });

  async function einspielen() {
    arbeitet = true;
    fehler = "";
    try {
      const { job } = await api.dockerEinspielen();
      // An die Antwort anhängen und nicht später abfragen: Der Vorgang läuft
      // bereits, und eine Runde später wäre er bei einem schnellen apt-Lauf
      // schon vorbei — der Strom ginge nie auf.
      vorgang.setzen(job);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      arbeitet = false;
    }
  }

  const laeuftVorgang = $derived(vorgang.job?.laeuft ?? false);
  const knopftext = $derived(
    daten?.installiert ? t.docker.composeEinspielen : t.docker.einspielen,
  );
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.apps}</div>
    <div class="h1">{t.ziele.docker}</div>
  </div>
  <div class="schub"></div>
  {#if daten?.server_version}
    <span class="marke">{daten.server_version}</span>
  {/if}
</div>

<p class="wesen">{t.docker.wesen}</p>

{#if fehler}<p class="warnung" role="alert">{fehler}</p>{/if}
{#if daten?.fehler}<p class="warnung">{daten.fehler}</p>{/if}

{#if !daten}
  <p class="detail">{t.docker.laedt}</p>
{:else}
  <div class="karten">
    <div class="karte">
      <div class="kopf">{t.docker.laufzeit}</div>
      <div class="wert">
        <span class="zustand {daten.installiert ? 'gut' : 'warn'}">
          <i></i>{daten.installiert ? daten.client_version || t.docker.da : t.docker.fehlt}
        </span>
      </div>
      <div class="unter">
        {#if daten.installiert}
          {t.docker.paket}: {daten.paket || t.docker.ausApt}
        {/if}
      </div>
    </div>

    <!-- Ohne Docker stehen Daemon und Compose auf „—" und nicht auf „kaputt":
         Zu einem Programm, das es nicht gibt, ist keine der beiden Fragen
         gestellt. Sie rot zu färben machte aus einem Befund drei, und wer die
         Seite liest, suchte an zwei Stellen, an denen nichts ist. -->
    <div class="karte">
      <div class="kopf">{t.docker.daemon}</div>
      <div class="wert">
        {#if !daten.installiert}
          <span class="zustand info"><i></i>{t.docker.offen}</span>
        {:else}
          <span class="zustand {daten.daemon_laeuft ? 'gut' : 'schlecht'}">
            <i></i>{daten.daemon_laeuft ? t.docker.laeuft : t.docker.tot}
          </span>
        {/if}
      </div>
      <div class="unter">
        {#if daten.daemon_laeuft && daten.server_version}
          {daten.server_version}
        {/if}
      </div>
    </div>

    <div class="karte">
      <div class="kopf">{t.docker.compose}</div>
      <div class="wert">
        {#if !daten.installiert}
          <span class="zustand info"><i></i>{t.docker.offen}</span>
        {:else}
          <span class="zustand {daten.compose_verfuegbar ? 'gut' : 'warn'}">
            <i></i>{daten.compose_verfuegbar ? daten.compose_version || t.docker.da : t.docker.fehlt}
          </span>
        {/if}
      </div>
      <div class="unter"></div>
    </div>
  </div>

  {#if daten.anmerkung}
    <p class="hinweis">{daten.anmerkung}</p>
  {/if}

  <Vorgangsplatte {vorgang} />

  <div class="aktionen">
    {#if daten.einspielbar && daten.darf_aendern}
      <button
        type="button"
        class="knopf"
        disabled={arbeitet || laeuftVorgang}
        onclick={einspielen}
      >
        {knopftext}
      </button>
    {/if}
    <!-- Bei totem Daemon führt der Weg auf die Dienstseite und nicht auf einen
         apt-Lauf. Ein Verweis statt eines Knopfes, weil das Starten eines
         Dienstes dort hingehört, wo auch sein Journal steht. -->
    {#if daten.installiert && !daten.daemon_laeuft}
      <a class="knopf leise" href="/dienste" onclick={(e) => verweis(e, "/dienste")}>
        {t.docker.zuDiensten}
      </a>
    {/if}
  </div>

  {#if !daten.darf_aendern}
    <p class="hinweis">{t.docker.nurOwner}</p>
  {/if}

  {#if daten.daemon_laeuft}
    <!-- Container gibt es nur, wenn der Daemon antwortet. Die Liste zu laden,
         während er tot ist, brächte eine Fehlermeldung unter einer Karte, die
         die Ursache schon nennt. -->
    <h2>{t.docker.container}</h2>
    <Containerwerkbank />
  {/if}

  <!-- Was noch fehlt, steht da. Eine Seite, die den Zustand zeigt und sonst
       nichts, sieht sonst aus wie ein Modul, das kaputt ist. -->
  <div class="platte">
    <b>{t.docker.imBau}</b>
    <p>{t.docker.imBauDetail}</p>
  </div>
{/if}

<style>
  .wesen {
    color: var(--tx-mut);
    font-size: 0.86rem;
    line-height: 1.6;
    max-width: 52rem;
    margin-bottom: 1rem;
  }

  /* Schmal untereinander, breit nebeneinander. Ausdrücklich eine Spalte als
     Ausgangspunkt statt auto-fit: Bei drei Karten mit minmax(0, 1fr) hängt das
     Ergebnis sonst an der Restbreite, und auf einem Telefon stünden drei Karten
     zu je hundert Pixeln nebeneinander. */
  .karten {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 0.75rem;
    margin-bottom: 1rem;
  }

  @media (min-width: 700px) {
    .karten {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
  }

  .karte {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 0.9rem 1rem;
    display: grid;
    gap: 0.35rem;
  }

  .kopf {
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
  }

  .wert {
    font: 1.1rem var(--mono);
  }

  .unter {
    font-size: 0.78rem;
    color: var(--tx-mut);
    min-height: 1rem;
  }

  .aktionen {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin: 1rem 0;
  }

  h2 {
    font-size: 0.95rem;
    font-weight: 600;
    margin: 1.5rem 0 0.75rem;
  }

  .platte {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 1rem 1.1rem;
    max-width: 46rem;
    display: grid;
    gap: 0.4rem;
  }

  .platte b {
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
  }

  .platte p {
    color: var(--tx-mut);
    font-size: 0.84rem;
    line-height: 1.6;
  }
</style>
