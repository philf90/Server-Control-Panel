<script lang="ts">
  // Webserver — Stufe 0.6, erster Schritt.
  //
  // Die Seite beantwortet drei Fragen, und die dritte ist die, wegen der es sie
  // zuerst gibt: Ist nginx da? Läuft der Dienst? Und WER HÄLT PORT 80 UND 443?
  //
  // Der Installationsknopf ist die einzige Aktion dieses Moduls, die einen
  // Server im Betrieb umbringen kann: `apt-get install nginx` startet nginx,
  // nginx bindet Port 80, und ein Webserver, der dort lief, ist weg. Deshalb
  // steht der Knopf hier NUR, wenn der Server sagt, dass die Ports bekannt und
  // frei sind — und deshalb prüft der Server es beim Klick noch einmal. Zwischen
  // dem Laden der Seite und dem Klick liegt beliebig viel Zeit.
  //
  // Drei Lagen, drei verschiedene Antworten, und keine zwei davon dürfen gleich
  // aussehen: nginx fehlt und die Ports sind frei (apt hilft), es läuft schon
  // ein fremder Webserver (das Panel hält sich raus und sagt warum), die
  // Belegung ist unbekannt (das Panel weiß nichts und tut deshalb nichts).
  // Welche gilt, entscheidet der Server und schickt den Satz fertig mit; die
  // Seite legt keine eigene Auslegung daneben.
  //
  // Was ein fremder Webserver NICHT bedeutet: dass hier nichts mehr geht. Seine
  // Konfiguration bleibt über den Dateimanager erreichbar — das Modul nimmt
  // nichts weg, es fasst nur nichts an, was ihm nicht gehört.
  //
  // Seit Schritt 4 hat das Modul Flächen mit eigenen Adressen — Sites und
  // Portbelegung —, gebaut wie bei Docker: Die Liste steht in lib/ziele.ts,
  // damit Seitenleiste und Befehlspalette dieselbe kennen. Die drei
  // Zustandskarten bleiben auf JEDER Fläche oben stehen. Das ist Absicht: Ihre
  // Auskunft ist die Voraussetzung für alles darunter — eine leere Sitesliste
  // erklärt sich nur zusammen mit „nginx fehlt" oder „ein fremder Webserver
  // hält Port 80". Sie kosten drei Prozessaufrufe und keinen `nginx -T`.
  import Sitesliste from "../komponenten/Sites.svelte";
  import Sitezertifikate from "../komponenten/Sitezertifikate.svelte";
  import Vorgangsplatte from "../komponenten/Vorgangsplatte.svelte";
  import { AbgemeldetFehler, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { verweis, weg } from "../lib/weg.svelte";
  import { alleZiele } from "../lib/ziele";
  import { Vorgang } from "../lib/vorgang.svelte";
  import type { Webserver } from "../lib/typen";

  let daten = $state<Webserver | null>(null);
  let fehler = $state("");
  let arbeitet = $state(false);

  const vorgang = new Vorgang("webserver-install");

  async function laden() {
    fehler = "";
    try {
      const frisch = await api.webserver();
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
      const { job } = await api.nginxEinspielen();
      // An die Antwort anhängen und nicht später abfragen: Der Vorgang läuft
      // bereits, und eine Runde später wäre er bei einem schnellen apt-Lauf
      // schon vorbei — der Strom ginge nie auf.
      vorgang.setzen(job);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      // Hier landet auch die Ablehnung des Servers (409), wenn zwischen dem
      // Laden der Seite und dem Klick jemand einen Webserver gestartet hat. Die
      // Meldung nennt Port und Programm; danach den Zustand neu holen, damit
      // die Seite zeigt, was jetzt gilt.
      fehler = e instanceof Error ? e.message : t.fehler.laden;
      void laden();
    } finally {
      arbeitet = false;
    }
  }

  const laeuftVorgang = $derived(vorgang.job?.laeuft ?? false);
  const lauscher = $derived(daten?.lauscher ?? []);
  const fremde = $derived(daten?.fremd ?? []);
  /** adressen sind die Bindungsadressen ohne Wiederholungen — siehe die dritte
   *  Karte. */
  const adressen = $derived([...new Set(lauscher.map((l) => l.adresse))]);

  /** flaechen sind die Unterseiten dieses Moduls — dieselbe Liste, aus der die
   *  Seitenleiste ihre eingerückten Punkte baut. */
  const flaechen = $derived(alleZiele.find((z) => z.id === "webserver")?.kinder ?? []);

  /** portzustand ist die Beschriftung der dritten Karte. Drei Werte, weil es
   *  drei Lagen gibt — „unbekannt" ist kein „frei". */
  const portzustand = $derived.by(() => {
    if (!daten) return { text: t.webserver.offen, stufe: "info" };
    if (!daten.ports_geprueft) return { text: t.webserver.unbekannt, stufe: "warn" };
    if (fremde.length) return { text: fremde.join(", "), stufe: "warn" };
    if (lauscher.length) return { text: t.webserver.eigen, stufe: "gut" };
    return { text: t.webserver.frei, stufe: "info" };
  });
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.apps}</div>
    <div class="h1">{t.ziele.webserver}</div>
  </div>
  <div class="schub"></div>
  {#if daten?.version}
    <span class="marke">{daten.version}</span>
  {/if}
</div>

<p class="wesen">{t.webserver.wesen}</p>

{#if fehler}<p class="warnung" role="alert">{fehler}</p>{/if}
{#if daten?.fehler}<p class="warnung">{daten.fehler}</p>{/if}

{#if !daten}
  <p class="detail">{t.webserver.laedt}</p>
{:else}
  <div class="karten">
    <div class="karte">
      <div class="kopf">{t.webserver.server}</div>
      <div class="wert">
        <span class="zustand {daten.installiert ? 'gut' : 'warn'}">
          <i></i>{daten.installiert ? daten.version || "nginx" : t.webserver.fehlt}
        </span>
      </div>
      <div class="unter">
        {#if daten.installiert}
          {t.webserver.paket}: {daten.paket || t.webserver.ausApt}
        {/if}
      </div>
    </div>

    <!-- Ohne nginx steht der Dienst auf „—" und nicht auf „läuft nicht": Zu
         einem Programm, das es nicht gibt, ist die Frage nicht gestellt. -->
    <div class="karte">
      <div class="kopf">{t.webserver.dienst}</div>
      <div class="wert">
        {#if !daten.installiert}
          <span class="zustand info"><i></i>{t.webserver.offen}</span>
        {:else}
          <span class="zustand {daten.dienst_aktiv ? 'gut' : 'schlecht'}">
            <i></i>{daten.dienst_aktiv ? t.webserver.laeuft : t.webserver.tot}
          </span>
        {/if}
      </div>
      <div class="unter">{daten.installiert ? "nginx.service" : ""}</div>
    </div>

    <div class="karte">
      <div class="kopf">{t.webserver.ports}</div>
      <div class="wert">
        <span class="zustand {portzustand.stufe}"><i></i>{portzustand.text}</span>
      </div>
      <!-- Unter der Karte steht die Adresse und nicht die ANZAHL: „2" allein
           beantwortet keine Frage, und die Zahl steht ohnehin in der Tabelle
           darunter. Was hier hilft, ist, ob die Bindung von außen erreichbar
           ist. -->
      <!-- Ohne Wiederholungen: Ein Server hört meist auf beiden Webports und
           dort noch je einmal auf IPv4 und IPv6. „0.0.0.0 · 0.0.0.0 · [::]" ist
           keine bessere Auskunft als „0.0.0.0 · [::]" — die Zuordnung Adresse zu
           Port steht in der Tabelle unter „Portbelegung". -->
      <div class="unter">
        {#if daten.ports_geprueft && adressen.length}
          {adressen.join(" · ")}
        {/if}
      </div>
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
        {t.webserver.einspielen}
      </button>
    {/if}
    <!-- Bei gestopptem Dienst führt der Weg auf die Dienstseite und nicht auf
         einen apt-Lauf — dort steht auch das Journal, in dem der Grund steht. -->
    {#if daten.installiert && !daten.dienst_aktiv}
      <a class="knopf leise" href="/dienste" onclick={(e) => verweis(e, "/dienste")}>
        {t.webserver.zuDiensten}
      </a>
    {/if}
    <!-- Läuft ein fremder Webserver, ist der Dateimanager der Weg zu seiner
         Konfiguration. Das Modul nimmt nichts weg. -->
    {#if fremde.length}
      <a class="knopf leise" href="/dateien" onclick={(e) => verweis(e, "/dateien")}>
        {t.webserver.zuDateien}
      </a>
    {/if}
  </div>

  {#if !daten.darf_aendern}
    <p class="hinweis">{t.webserver.nurOwner}</p>
  {/if}

  <!-- Der Umschaltstreifen. Er steht NUR in der schmalen Ansicht: Breit
       übernehmen die Unterpunkte der Seitenleiste diese Aufgabe, und zwei
       sichtbare Navigationen für dieselbe Sache wären eine zu viel. Die Liste
       kommt aus lib/ziele.ts — es soll eine Stelle geben, an der steht, welche
       Flächen dieses Modul hat. -->
  {#if flaechen.length}
    <nav class="streifen" aria-label={t.ziele.webserver}>
      {#each flaechen as f (f.id)}
        <a
          href={f.href}
          onclick={(e) => verweis(e, f.href)}
          class:an={f.id === "webserver/" + weg.unterseite}
          aria-current={f.id === "webserver/" + weg.unterseite ? "page" : undefined}
        >
          {f.label}
        </a>
      {/each}
    </nav>
  {/if}

  {#if weg.unterseite === ""}
    <h2>{t.webserver.sites}</h2>
    <!-- Ohne nginx wird die Liste gar nicht erst geholt: `nginx -T` gibt es
         dann nicht, und ein „command not found" wäre die Antwort auf eine
         Frage, die niemand gestellt hat. -->
    {#if daten.installiert}
      <Sitesliste />
    {:else}
      <p class="detail">{t.webserver.sitesOhneNginx}</p>
    {/if}

    <div class="platte">
      <b>{t.webserver.imBau}</b>
      <p>{t.webserver.imBauDetail}</p>
    </div>
  {:else if weg.unterseite === "zertifikate"}
    <h2>{t.webserver.zertifikate}</h2>
    {#if daten.installiert}
      <Sitezertifikate />
    {:else}
      <p class="detail">{t.webserver.sitesOhneNginx}</p>
    {/if}
  {:else if weg.unterseite === "ports"}
    <!-- Die Belegung als Tabelle, nicht nur als Kartentext. Grundsatz IV: Was
         das Panel weiß, sagt es — und wer wissen will, warum oben kein Knopf
         steht, soll die Zeile sehen, aus der das folgt.

         Die drei Fälle sind hier ausgeschrieben und fallen nicht auf eine leere
         Fläche zusammen: „ungeprüft" und „frei" sehen sonst beide wie eine
         Tabelle aus, die es nicht gibt — und genau der Unterschied entscheidet
         über den Installationsknopf. -->
    <h2>{t.webserver.belegung}</h2>
    {#if !daten.ports_geprueft}
      <p class="hinweis">{t.webserver.portsUngeprueft}</p>
    {:else if lauscher.length === 0}
      <p class="detail">{t.webserver.portsFrei}</p>
    {:else}
      <div class="tabelle-rahmen">
        <table class="tabelle">
          <thead>
            <tr>
              <th>{t.webserver.spaltePort}</th>
              <th>{t.webserver.spalteAdresse}</th>
              <th>{t.webserver.spalteProzess}</th>
            </tr>
          </thead>
          <tbody>
            {#each lauscher as l (l.port + l.adresse + l.prozess)}
              <tr>
                <td data-spalte={t.webserver.spaltePort}>{l.port}</td>
                <td data-spalte={t.webserver.spalteAdresse}>{l.adresse}</td>
                <td data-spalte={t.webserver.spalteProzess}>
                  {l.prozess || t.webserver.unbenannt}
                  <span class="marke klein">
                    {l.eigen ? t.webserver.markeVerwaltet : t.webserver.fremd}
                  </span>
                </td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {/if}
  {:else}
    <!-- Ein zweites Segment, das es nicht gibt. Nicht stillschweigend auf die
         Sites fallen: Das sähe aus, als hätte der Verweis gestimmt. -->
    <p class="detail">{t.webserver.flaecheUnbekannt}</p>
    <a class="knopf leise" href="/webserver" onclick={(e) => verweis(e, "/webserver")}>
      {t.ziele.webserverSites}
    </a>
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
     Ausgangspunkt statt auto-fit — dieselbe Überlegung wie auf der Dockerseite:
     Bei minmax(0, 1fr) hängt das Ergebnis sonst an der Restbreite, und auf einem
     Telefon stünden drei Karten zu je hundert Pixeln nebeneinander. */
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

  /* Karten, Platte und Zwischenüberschrift. Wortgleich zu Docker.svelte, und
     das ist kein Versehen: Svelte begrenzt Stile auf die Komponente, in der sie
     stehen. Eine gemeinsame Datei wäre die sauberere Antwort — sie gehört
     dann aber für alle Module auf einmal gezogen und nicht nebenbei beim Bau
     einer neuen Seite. Ohne diese Regeln stand die Fläche als nackter Text da;
     gefunden hat das ein Bildschirmfoto, kein Test. */
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
    margin-top: 1.5rem;
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

  .marke.klein {
    margin-left: 0.4rem;
    font-size: 0.72rem;
  }
</style>
