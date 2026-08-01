<script lang="ts">
  // Docker — ein Modul mit fünf Flächen, jede mit eigener Adresse.
  //
  // Bis 0.5.1 war es eine Seite mit sechs Abschnitten untereinander. Der
  // Bauplan (docs/17-docker.md §6) hatte Reiter vorgesehen; gebaut wurde eine
  // lange Seite, und aufgefallen ist es nicht, weil die Attrappe des
  // Browsertests zwei Stacks und vier Container hat. Auf einem betriebsüblichen
  // Server sind es rund dreizehn Bildschirme — und schwerer wiegt, dass jeder
  // Abschnitt beim Öffnen seine eigenen docker-Aufrufe macht.
  //
  // Die Flächen stehen als eingerückte Punkte unter „Docker" in der
  // Seitenleiste; ihre Liste steht in lib/ziele.ts, damit die Befehlspalette
  // dieselbe kennt. Schmal, wo die Leiste eine Symbolschiene ist, tritt der
  // Umschaltstreifen weiter unten an ihre Stelle.
  //
  // Oben bleibt die Aufgabe, wegen der die Seite schon vor allem anderen
  // existierte: Fehlt Docker, bietet das Panel es an, statt eine Kommandozeile
  // zum Abtippen zu drucken. Drei Zustände, drei verschiedene Antworten — und
  // keine zwei davon dürfen gleich aussehen: Docker fehlt (apt hilft), Docker
  // ist da und antwortet nicht (der Dienst hilft), Compose fehlt (apt hilft
  // wieder). Welche Antwort gilt, entscheidet der Server und schickt sie fertig
  // mit; die Seite legt keine eigene Auslegung daneben.
  import Imagepruefung from "../komponenten/Imageupdates.svelte";
  import Bestandsansicht from "../komponenten/Bestand.svelte";
  import Containerwerkbank from "../komponenten/Containerliste.svelte";
  import Ereignisstrom from "../komponenten/Ereignisse.svelte";
  import Portuebersicht from "../komponenten/Ports.svelte";
  import Stackwerkbank from "../komponenten/Stackliste.svelte";
  import Vorgangsplatte from "../komponenten/Vorgangsplatte.svelte";
  import { AbgemeldetFehler, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { verweis, weg } from "../lib/weg.svelte";
  import { alleZiele } from "../lib/ziele";
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

  /** flaechen sind die Unterseiten dieses Moduls — dieselbe Liste, aus der die
   *  Seitenleiste ihre eingerückten Punkte baut. */
  const flaechen = $derived(alleZiele.find((z) => z.id === "docker")?.kinder ?? []);

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
    <!-- Der Umschaltstreifen. Er steht NUR in der schmalen Ansicht: Breit
         übernehmen die Unterpunkte der Seitenleiste diese Aufgabe, und zwei
         sichtbare Navigationen für dieselbe Sache wären eine zu viel. Schmal
         ist die Leiste eine Symbolschiene ohne Beschriftungen — dort hätten
         eingerückte Unterpunkte keine sichtbare Einrückung.

         Die Liste kommt aus lib/ziele.ts und nicht aus dieser Datei: Es soll
         eine Stelle geben, an der steht, welche Flächen dieses Modul hat. -->
    {#if flaechen.length}
      <nav class="streifen" aria-label={t.ziele.docker}>
        {#each flaechen as f (f.id)}
          <a
            href={f.href}
            onclick={(e) => verweis(e, f.href)}
            class:an={f.id === "docker/" + weg.unterseite}
            aria-current={f.id === "docker/" + weg.unterseite ? "page" : undefined}
          >
            {f.label}
          </a>
        {/each}
      </nav>
    {/if}

    <!-- Eine Fläche je Adresse, und nur sie wird eingehängt. Das ist nicht bloß
         Ordnung: Jeder dieser Abschnitte holt beim Einhängen seine eigenen
         Daten, und die meisten davon sind docker-Prozesse. Bis 0.5.1 standen
         alle sechs untereinander auf einer Seite — ein Aufruf kostete auf einem
         Server mit vierzig Containern rund fünfzig Prozessaufrufe, davon
         fünfundvierzig für den Bestand, den man selten sehen will. -->
    {#if weg.unterseite === ""}
      <!-- Stacks sind die Vorgabe: Sie sind das führende Objekt dieses Moduls
           (docs/16-neukonzeption.md §5). Wer einen Server mit Compose betreibt,
           denkt in Projekten und nicht in einzelnen Containern. -->
      <h2>{t.docker.stacks}</h2>
      <Stackwerkbank />
    {:else if weg.unterseite === "container"}
      <h2>{t.docker.container}</h2>
      <Containerwerkbank />

      <!-- Der Ereignisstrom steht bei den Containern und nicht auf einer
           eigenen Fläche: Er beantwortet „warum ist der Container um 3 Uhr neu
           gestartet", und diese Frage stellt man, während man den Container
           ansieht. Zugeklappt, weil er einen docker-Prozess hält. -->
      <h2>{t.docker.ereignisse}</h2>
      <Ereignisstrom />

      <!-- Die zurückgestellte Container-Shell gehört hierher und nicht unter
           jede Fläche des Moduls. -->
      <div class="platte">
        <b>{t.docker.imBau}</b>
        <p>{t.docker.imBauDetail}</p>
      </div>
    {:else if weg.unterseite === "ports"}
      <h2>{t.docker.ports}</h2>
      <Portuebersicht />
    {:else if weg.unterseite === "updates"}
      <h2>{t.docker.updates}</h2>
      <Imagepruefung />
    {:else if weg.unterseite === "bestand"}
      <h2>{t.docker.bestand}</h2>
      <Bestandsansicht />
    {:else}
      <!-- Ein zweites Segment, das es nicht gibt. Nicht stillschweigend auf die
           Stacks fallen: Das sähe aus, als hätte der Verweis gestimmt. -->
      <p class="detail">{t.docker.flaecheUnbekannt}</p>
      <a class="knopf leise" href="/docker" onclick={(e) => verweis(e, "/docker")}>
        {t.ziele.dockerStacks}
      </a>
    {/if}
  {/if}
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

  /* Der Umschaltstreifen: nur schmal. Breit steht dieselbe Navigation in der
     Seitenleiste, und zwei sichtbare Wege zur selben Fläche sind einer zu viel.
     overflow-x am Streifen selbst und nicht am Körper — die Regel „kein
     waagerechtes Scrollen bei 375 px" gilt für die Seite, nicht für ein Band,
     das ausdrücklich zum Schieben da ist. */
  .streifen {
    display: none;
  }

  @media (max-width: 900px) {
    .streifen {
      display: flex;
      gap: 0.3rem;
      overflow-x: auto;
      margin-bottom: 0.9rem;
      padding-bottom: 0.3rem;
    }

    .streifen a {
      flex: none;
      color: var(--tx-mut);
      text-decoration: none;
      font-size: 0.8rem;
      padding: 0.3rem 0.7rem;
      border: 1px solid var(--line);
      border-radius: 999px;
      white-space: nowrap;
    }

    .streifen a.an {
      color: var(--tx);
      border-color: var(--accent-dim);
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
