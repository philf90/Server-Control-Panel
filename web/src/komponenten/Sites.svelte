<script lang="ts">
  // Die Sitesliste — was nginx WIRKLICH ausliefert — und der Schreibpfad dazu.
  //
  // Die Quelle ist `nginx -T` und nicht das Verzeichnis sites-enabled: Dieselbe
  // Entscheidung wie beim Compose-Prüfer (docs/17-docker.md E4). Eine
  // Konfiguration mit `include` zeigt in den Dateien nicht, was der Server
  // daraus macht — und ein Serverblock, den niemand sieht, ist genau der, der
  // eine Domain wegnimmt. Dazu kommen die eigenen Dateien des Panels, damit eine
  // abgeschaltete Site sichtbar bleibt; was davon ausgeliefert wird, sagt
  // weiterhin nur nginx (Feld `ausgeliefert`).
  //
  // Die Trennung verwaltet/fremd ist die Zusage des Moduls und nicht Zierrat:
  // Dieselbe Trennung wie bei nftables, fremden Crontabs und fremden
  // Compose-Projekten. Was das Panel nicht geschrieben hat, zeigt es an und
  // fasst es nicht an — deshalb öffnet ein Klick auf eine fremde Zeile ein
  // Formular ohne Knöpfe und mit dem Satz, warum.
  //
  // Der Fall, wegen dessen es das Feld `gelesen` gibt: `nginx -T` läuft nur bei
  // gültiger Konfiguration. Ist sie kaputt, kommt eine LEERE Liste zurück — und
  // die sieht aus wie ein Server ohne Sites. Die beiden verlangen
  // entgegengesetzte Handgriffe, deshalb sagt der Server, welcher der beiden
  // Fälle vorliegt, und die Fläche zeigt es an erster Stelle.
  //
  // Die PROBE steht ganz oben, vor allem anderen. Sie ist die einzige Stelle
  // dieser Fläche, an der Untätigkeit etwas rückgängig macht — wer hereinkommt,
  // während eine Frist läuft, muss zuerst den Knopf sehen, der sie beendet.
  import Rueckfrage from "./Rueckfrage.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, Siteabgelehnt, api } from "../lib/api";
  import { Probelauf } from "../lib/probe.svelte";
  import { t } from "../lib/texte";
  import { verweis, weg } from "../lib/weg.svelte";
  import type {
    Bestaetigung,
    Site,
    Siteantwort,
    Sitebefund,
    Siteliste,
    Ziele,
  } from "../lib/typen";

  let daten = $state<Siteliste | null>(null);
  let fehler = $state("");
  let meldung = $state("");
  let arbeitet = $state(false);
  let offeneFrage = $state<{ frage: Bestaetigung; tun: (getippt: string) => void } | null>(null);
  /** befunde sind die Ablehnungen des Prüfers zur zuletzt versuchten Fassung. */
  let befunde = $state<Sitebefund[]>([]);
  let ungeprueft = $state<string[]>([]);
  /** ziele sind die Vorschläge aus dem Bestand — laufende Container und
   *  FPM-Sockets. Sie werden EINMAL beim Öffnen des Formulars geholt und nicht
   *  bei jedem Laden der Liste: Sie kosten einen docker-Aufruf, und wer nur
   *  hinsieht, soll ihn nicht bezahlen. */
  let ziele = $state<Ziele | null>(null);

  // Die Probe: Anzeige hier, Wahrheit im Server. Läuft die Frist ab, wird der
  // Zustand einmal neu geholt — was dann gilt, sagt der Server.
  const probe = new Probelauf(() => void laden());

  async function laden() {
    fehler = "";
    try {
      const frisch = await api.webserverSites();
      daten = frisch;
      probe.setzen(frisch.probe.offen, frisch.probe.gegenstand, frisch.probe.sekunden);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  void laden();
  $effect(() => () => probe.anhalten());

  const sites = $derived(daten?.sites ?? []);
  const darfAendern = $derived(daten?.darf_aendern ?? false);

  // Die Auswahl steht in der ADRESSE und nicht in einer Variablen: Damit ist sie
  // teilbar, sie übersteht ein Neuladen, und der Zurück-Knopf schließt das
  // Formular. Dasselbe Muster wie bei den Diensten und im Dateimanager.
  const auswahl = $derived(weg.parameter.site ?? "");
  const gewaehlt = $derived(sites.find((s) => s.name === auswahl) ?? null);
  const legtAn = $derived(auswahl === "+");

  /** entwurf ist das Formular. Er wird aus der Auswahl gesetzt und danach nur
   *  von der Bedienung verändert — nicht mehr vom Server, solange jemand daran
   *  arbeitet. Sonst verschwände ein gerade getipptes Feld, weil eine Antwort
   *  hereinkommt. */
  let entwurf = $state({
    name: "",
    domains: "",
    zielart: "proxy",
    ziel: "",
    php_socket: "",
    tls: false,
    http_umleitung: false,
    fassung: "",
  });
  let zuletztGeladen = $state("");

  $effect(() => {
    const schluessel = auswahl;
    if (schluessel === zuletztGeladen) return;
    zuletztGeladen = schluessel;
    befunde = [];
    ungeprueft = [];
    meldung = "";

    if (schluessel === "+") {
      entwurf = {
        name: "",
        domains: "",
        zielart: "proxy",
        ziel: "",
        php_socket: "",
        tls: false,
        http_umleitung: false,
        fassung: "",
      };
      return;
    }
    const s = sites.find((x) => x.name === schluessel);
    if (!s) return;
    entwurf = {
      name: s.name,
      domains: (s.domains ?? []).join("\n"),
      zielart: s.zielart || "proxy",
      ziel: s.ziel,
      // Der Socket steht nicht in der Liste — der Parser liest ihn nicht aus
      // dem location-Block. Er bleibt leer und wird beim Speichern neu
      // gefordert; das ist ehrlicher als ein geratener Pfad.
      php_socket: "",
      tls: s.tls,
      // Ob http auf https umgeleitet wird, lässt sich aus der Liste nicht
      // ablesen — sie zeigt das Ziel des 443-Blocks. Beim Speichern entscheidet
      // ohnehin dieses Feld; die Vorbelegung folgt deshalb dem üblichen Fall.
      http_umleitung: s.tls,
      fassung: "",
    };
  });

  function waehle(name: string) {
    weg.setze("site", name);
  }

  // Die Vorschläge erst holen, wenn ein Formular offen ist: Sie kosten einen
  // docker-Aufruf, und die Liste allein braucht ihn nicht.
  $effect(() => {
    if ((legtAn || gewaehlt) && ziele === null) void zieleLaden();
  });

  async function zieleLaden() {
    try {
      ziele = await api.webserverZiele();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      // Kein Fehler an der Fläche: Ohne Vorschläge tippt man die Adresse, und
      // eine rote Zeile über einem funktionierenden Formular wäre die falsche
      // Auskunft.
      ziele = { vorschlaege: [], anmerkung: "", fehler: t.webserver.vorschlaegeOhneDocker };
    }
  }

  /** uebernimm setzt Zielart und Ziel aus einem Vorschlag. Bei PHP wandert der
   *  Wert in das Socketfeld — dort steht er, und das Verzeichnis bleibt, wie es
   *  ist: Welches ausgeliefert wird, weiß nur der Betreiber. */
  function uebernimm(v: { zielart: string; ziel: string }) {
    entwurf.zielart = v.zielart;
    if (v.zielart === "php") {
      entwurf.php_socket = v.ziel;
      return;
    }
    entwurf.ziel = v.ziel;
  }

  /** zustandVon ist das Wort in der Zustandsspalte. Drei Werte, weil es drei
   *  Lagen gibt: abgeschaltet (jemand wollte es so), nicht ausgeliefert (die
   *  Datei liegt da und nginx liest sie nicht) und aktiv. */
  function zustandVon(s: Site): { text: string; stufe: string } {
    if (s.aus) return { text: t.webserver.zustandAus, stufe: "warn" };
    if (!s.ausgeliefert) return { text: t.webserver.zustandStill, stufe: "schlecht" };
    return { text: t.webserver.zustandAktiv, stufe: "gut" };
  }

  /** ausfuehren fasst den Weg zusammen, den jede Aktion nimmt: ausführen, bei
   *  einer Rückfrage den Dialog öffnen, danach neu laden. Ohne diese Klammer
   *  stünde die Behandlung der Rückfrage viermal da — und die vierte wäre
   *  irgendwann eine andere. */
  async function ausfuehren(
    tun: (bestaetigt: boolean, getippt: string) => Promise<Siteantwort>,
    bestaetigt = false,
    getippt = "",
  ) {
    arbeitet = true;
    fehler = "";
    try {
      const antwort = await tun(bestaetigt, getippt);
      offeneFrage = null;
      befunde = [];
      ungeprueft = antwort.pruefung?.ungeprueft ?? [];
      // Die Meldung nur zeigen, wenn KEINE Probe läuft: Sonst stünde dieselbe
      // Aussage zweimal auf der Seite — einmal im Probeband mit der Uhr, einmal
      // als Zeile darunter.
      meldung = antwort.probe.offen ? "" : antwort.meldung;
      // Das Formular verlassen, damit die Liste den neuen Stand trägt. Bei einer
      // gerade gelöschten Site führte es sonst ins Leere.
      weg.setze("site", "");
      await laden();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      if (e instanceof BestaetigungNoetig) {
        // Der zweite Anlauf trägt bestaetigt=true. Die erste Anfrage darf es
        // nicht: Sonst liefe jede Aktion ohne Rückfrage, und der Dialog wäre
        // Zierrat.
        offeneFrage = {
          frage: e.bestaetigung,
          tun: (wort) => void ausfuehren(tun, true, wort),
        };
        return;
      }
      offeneFrage = null;
      if (e instanceof Siteabgelehnt) {
        // Die Befunde sind das eigentliche Ergebnis: „abgelehnt" ohne Feld und
        // Grund schickte jemanden auf die Suche in einem Formular, das er
        // gerade ausgefüllt hat. Deshalb hier KEINE rote Zeile obendrauf.
        befunde = e.befunde;
        ungeprueft = e.ungeprueft;
        return;
      }
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      arbeitet = false;
    }
  }

  function speichern() {
    const name = (legtAn ? entwurf.name : auswahl).trim();
    void ausfuehren((bestaetigt, getippt) =>
      api.siteSpeichern(
        name,
        {
          // Eine Domain je Zeile — Kommata werden mitgenommen, weil sie jeder
          // zweite von woandersher kopiert.
          domains: entwurf.domains
            .split(/[\n,]/)
            .map((d) => d.trim())
            .filter(Boolean),
          zielart: entwurf.zielart,
          ziel: entwurf.ziel.trim(),
          php_socket: entwurf.php_socket.trim(),
          tls: entwurf.tls,
          http_umleitung: entwurf.http_umleitung,
          fassung: entwurf.fassung,
        },
        bestaetigt,
        getippt,
      ),
    );
  }
</script>

{#if fehler}<p class="warnung" role="alert">{fehler}</p>{/if}

{#if !daten}
  <p class="detail">{t.webserver.sitesLaedt}</p>
{:else}
  {#if probe.offen}
    <!-- Ganz oben, vor allem anderen: Der einzige Ort dieser Fläche, an dem
         Untätigkeit etwas rückgängig macht. -->
    <section class="probe" role="alert">
      <div class="uhr" class:knapp={probe.rest <= 15}>{probe.rest}</div>
      <div class="text">
        <b>{t.webserver.probeTitel(probe.gegenstand)}</b>
        <span class="detail">{t.webserver.probeDetail}</span>
      </div>
      {#if darfAendern}
        <button
          type="button"
          class="knopf"
          disabled={arbeitet}
          onclick={() => void ausfuehren(() => api.siteProbeBestaetigen())}
        >
          {t.webserver.probeBestaetigen}
        </button>
      {/if}
    </section>
  {/if}

  {#if meldung}<p class="meldung" role="status">{meldung}</p>{/if}

  <!-- Bei unlesbarer Konfiguration steht die Meldung von nginx ZUERST und als
       Warnung. Sie ist die einzige Auskunft, mit der sich der Fehler finden
       lässt — „unknown directive" nennt Datei und Zeile. -->
  {#if !daten.gelesen}
    <p class="warnung" role="alert">{daten.anmerkung}</p>
    {#if daten.fehler}
      <pre class="meldung-roh">{daten.fehler}</pre>
    {/if}
    <a class="knopf leise" href="/dateien" onclick={(e) => verweis(e, "/dateien")}>
      {t.webserver.zuDateien}
    </a>
  {:else}
    <p class="hinweis">{t.webserver.sitesNurLesend}</p>

    {#if daten.anmerkung}
      <p class="hinweis">{daten.anmerkung}</p>
    {/if}

    <div class="kopfreihe">
      {#if sites.length}
        <div class="zaehler">
          <span>{t.webserver.zaehlerVerwaltet} <b>{daten.zaehler.verwaltet}</b></span>
          <span>{t.webserver.zaehlerFremd} <b>{daten.zaehler.fremd}</b></span>
        </div>
      {/if}
      <div class="schub"></div>
      {#if darfAendern}
        <button type="button" class="knopf" onclick={() => waehle("+")}>
          {t.webserver.anlegen}
        </button>
      {/if}
    </div>

    {#if sites.length}
      <div class="tabelle-rahmen">
        <table class="tabelle">
          <thead>
            <tr>
              <th>{t.webserver.spalteSite}</th>
              <th>{t.webserver.spalteDomains}</th>
              <th>{t.webserver.spalteZiel}</th>
              <th>{t.webserver.spaltePorts}</th>
              <th>{t.webserver.spalteHerkunft}</th>
            </tr>
          </thead>
          <tbody>
            {#each sites as s (s.datei + "|" + s.name)}
              <tr class:an={s.name === auswahl}>
                <td data-spalte={t.webserver.spalteSite}>
                  <!-- Ein Knopf und kein Verweis: Die Auswahl ändert die Abfrage
                       derselben Seite, nicht das Ziel. -->
                  <button type="button" class="zeilenknopf" onclick={() => waehle(s.name)}>
                    {s.name}
                  </button>
                  <span class="leise">{s.datei}</span>
                </td>
                <td data-spalte={t.webserver.spalteDomains}>
                  <!-- Ein Serverblock ohne server_name ist kein Fehler: Er ist
                       der Vorgabeblock für alles, was sonst nicht passt. Ihn
                       leer zu lassen sähe nach einem Lesefehler aus. -->
                  {#if s.domains?.length}
                    <span class="mono">{s.domains.join(" · ")}</span>
                  {:else}
                    <span class="leise">{t.webserver.ohneDomain}</span>
                  {/if}
                </td>
                <td data-spalte={t.webserver.spalteZiel}>
                  {s.zielsatz}
                  {#if s.anmerkung}<span class="leise">{s.anmerkung}</span>{/if}
                </td>
                <td data-spalte={t.webserver.spaltePorts}>
                  <span class="mono">{(s.ports ?? []).join(", ")}</span>
                  {#if s.tls}<span class="leise-marke">{t.webserver.tls}</span>{/if}
                </td>
                <td data-spalte={t.webserver.spalteHerkunft}>
                  <!-- Zwei Aussagen in einer Zelle, und sie sind verschieden:
                       woher der Block kommt, und ob er gerade wirkt. -->
                  <span class="zustand {s.herkunft === 'verwaltet' ? 'gut' : 'info'}">
                    <i></i>{s.herkunft}
                  </span>
                  {#if s.herkunft === "verwaltet"}
                    {@const z = zustandVon(s)}
                    <span class="zustand {z.stufe} klein"><i></i>{z.text}</span>
                  {/if}
                </td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {/if}

    {#if legtAn || gewaehlt}
      <section class="form">
        <h3>{legtAn ? t.webserver.neueSite : gewaehlt?.name}</h3>

        {#if gewaehlt && gewaehlt.herkunft !== "verwaltet"}
          <p class="hinweis">{t.webserver.fremdNichtAenderbar}</p>
          <a class="knopf leise" href="/dateien" onclick={(e) => verweis(e, "/dateien")}>
            {t.webserver.zuDateien}
          </a>
        {:else}
          {#if befunde.length}
            <div class="befunde" role="alert">
              <b>{t.webserver.abgelehnt}</b>
              <ul>
                {#each befunde as b (b.feld + b.wert + b.grund)}
                  <li><span class="mono">{b.feld}</span> — {b.grund}</li>
                {/each}
              </ul>
            </div>
          {/if}
          {#if ungeprueft.length}
            <p class="hinweis">
              {t.webserver.ungeprueft}
              {ungeprueft.join(" ")}
            </p>
          {/if}

          <label>
            <span>{t.webserver.feldName}</span>
            <!-- Nachträglich nicht änderbar: Die Kennung bestimmt den
                 Dateinamen UND das Verzeichnis des Zertifikats. Sie zu ändern
                 hieße, beides zu verschieben — und das ist keine Änderung,
                 sondern eine neue Site. -->
            <input
              type="text"
              bind:value={entwurf.name}
              disabled={!legtAn || !darfAendern}
              autocomplete="off"
            />
            <small>{t.webserver.feldNameHinweis}</small>
          </label>

          <label>
            <span>{t.webserver.feldDomains}</span>
            <textarea rows="3" bind:value={entwurf.domains} disabled={!darfAendern}></textarea>
            <small>{t.webserver.feldDomainsHinweis}</small>
          </label>

          <label>
            <span>{t.webserver.feldZielart}</span>
            <select bind:value={entwurf.zielart} disabled={!darfAendern}>
              <option value="proxy">{t.webserver.zielartProxy}</option>
              <option value="statisch">{t.webserver.zielartStatisch}</option>
              <option value="php">{t.webserver.zielartPHP}</option>
              <option value="umleitung">{t.webserver.zielartUmleitung}</option>
            </select>
          </label>

          <label>
            <span>{t.webserver.feldZiel}</span>
            <input type="text" bind:value={entwurf.ziel} disabled={!darfAendern} autocomplete="off" />
            <small>
              {entwurf.zielart === "statisch"
                ? t.webserver.zielHinweisStatisch
                : entwurf.zielart === "php"
                  ? t.webserver.zielHinweisPHP
                  : entwurf.zielart === "umleitung"
                    ? t.webserver.zielHinweisUmleitung
                    : t.webserver.zielHinweisProxy}
            </small>
          </label>

          {#if entwurf.zielart === "php"}
            <label>
              <span>{t.webserver.feldSocket}</span>
              <input
                type="text"
                bind:value={entwurf.php_socket}
                disabled={!darfAendern}
                autocomplete="off"
              />
              <small>{t.webserver.feldSocketHinweis}</small>
            </label>
          {/if}

          <!-- Die Vorschläge aus dem Bestand. Sie sind mehr als Bequemlichkeit:
               Wer die Adresse abtippt, vertippt sich, und ein vertippter Port
               ist der häufigste Grund für eine Site, die 502 antwortet.

               Der unbequeme Teil steht daneben: Ein Container auf 0.0.0.0 ist
               schon jetzt aus dem Netz erreichbar, und ein Proxy davor ändert
               das nicht. -->
          {#if darfAendern && ziele}
            <div class="vorschlaege">
              <b>{t.webserver.vorschlaege}</b>
              {#if ziele.fehler}
                <small>{ziele.fehler}</small>
              {:else if !ziele.vorschlaege?.length}
                <small>{t.webserver.vorschlaegeLeer}</small>
              {:else}
                {#if ziele.anmerkung}<small>{ziele.anmerkung}</small>{/if}
                <ul>
                  {#each ziele.vorschlaege as v (v.zielart + v.ziel)}
                    <li>
                      <button type="button" class="knopf leise" onclick={() => uebernimm(v)}>
                        {t.webserver.uebernehmen}
                      </button>
                      <span class="titel">{v.titel}</span>
                      <span class="mono">{v.ziel}</span>
                      <span class="leise">{v.detail}</span>
                      {#if v.warnung}<span class="warn">{v.warnung}</span>{/if}
                    </li>
                  {/each}
                </ul>
              {/if}
            </div>
          {/if}

          <label class="schalter">
            <input type="checkbox" bind:checked={entwurf.tls} disabled={!darfAendern} />
            <span>{t.webserver.feldTLS}</span>
          </label>
          <small class="unter">{t.webserver.feldTLSHinweis}</small>

          {#if entwurf.tls}
            <label class="schalter">
              <input
                type="checkbox"
                bind:checked={entwurf.http_umleitung}
                disabled={!darfAendern}
              />
              <span>{t.webserver.feldUmleitung}</span>
            </label>
          {/if}

          {#if darfAendern}
            <div class="aktionen">
              <button type="button" class="knopf" disabled={arbeitet} onclick={speichern}>
                {t.webserver.speichern}
              </button>
              {#if gewaehlt}
                <button
                  type="button"
                  class="knopf leise"
                  disabled={arbeitet}
                  onclick={() =>
                    void ausfuehren((bestaetigt, getippt) =>
                      api.siteSchalten(gewaehlt.name, gewaehlt.aus, bestaetigt, getippt),
                    )}
                >
                  {gewaehlt.aus ? t.webserver.einschalten : t.webserver.abschalten}
                </button>
                <button
                  type="button"
                  class="knopf gefahr"
                  disabled={arbeitet}
                  onclick={() =>
                    void ausfuehren((bestaetigt, getippt) =>
                      api.siteLoeschen(gewaehlt.name, bestaetigt, getippt),
                    )}
                >
                  {t.webserver.loeschen}
                </button>
              {/if}
              <button type="button" class="knopf leise" onclick={() => weg.setze("site", "")}>
                {t.webserver.abbrechen}
              </button>
            </div>
          {/if}
        {/if}
      </section>
    {/if}
  {/if}
{/if}

{#if offeneFrage}
  <Rueckfrage
    frage={offeneFrage.frage}
    laeuft={arbeitet}
    bestaetigen={(getippt) => offeneFrage!.tun(getippt)}
    abbrechen={() => (offeneFrage = null)}
  />
{/if}

<style>
  /* Die Probe ist die auffälligste Fläche. Bernstein und nicht rot: Es ist kein
     Fehler, sondern eine Frist — rot wäre eine Meldung, die nichts von einem
     verlangt. Wortgleich zur Firewallseite, weil es dieselbe Zusage ist. */
  .probe {
    display: flex;
    align-items: center;
    gap: 0.9rem;
    background: var(--surface);
    border: 1px solid var(--accent);
    border-radius: 12px;
    padding: 0.8rem 0.9rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
  }

  .uhr {
    font: 650 1.6rem var(--mono);
    font-variant-numeric: tabular-nums;
    color: var(--accent);
    min-width: 2.4rem;
    text-align: right;
  }

  /* Unter fünfzehn Sekunden wird die Zahl rot. Die Farbe trägt keine neue
     Aussage, sondern dieselbe dringlicher — daneben steht weiter der Satz. */
  .uhr.knapp {
    color: var(--err);
  }

  .probe .text {
    display: grid;
    gap: 0.15rem;
    min-width: 0;
    margin-right: auto;
  }

  .probe b {
    font-size: 0.92rem;
    font-weight: 650;
  }

  .kopfreihe {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-wrap: wrap;
    margin: 0.8rem 0 0.5rem;
  }

  .schub {
    flex: 1;
  }

  .zaehler {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    font-size: 0.78rem;
    color: var(--tx-mut);
  }

  .zaehler b {
    color: var(--tx);
  }

  /* Zwei Hinweise hintereinander brauchen einen Abstand, sonst lesen sie sich
     als ein Absatz — und der zweite trägt die Zusage des Moduls. */
  .hinweis + .hinweis {
    margin-top: 0.5rem;
  }

  .leise {
    display: block;
    color: var(--tx-faint);
    font-size: 0.76rem;
  }

  .leise-marke {
    color: var(--tx-faint);
    font-size: 0.72rem;
    margin-left: 0.4rem;
    border: 1px solid var(--line2);
    border-radius: 999px;
    padding: 0.05rem 0.4rem;
  }

  .zustand.klein {
    margin-left: 0.5rem;
    font-size: 0.76rem;
  }

  /* Der Name als Knopf, aber wie ein Verweis aussehend: Er wählt aus, er führt
     nicht weg. Ein <a> wäre die falsche Zusage — mittlere Maustaste öffnete
     einen Tab, in dem nichts ausgewählt ist. */
  .zeilenknopf {
    background: none;
    border: 0;
    padding: 0;
    font: inherit;
    color: var(--tx);
    cursor: pointer;
    text-align: left;
  }

  .zeilenknopf:hover {
    text-decoration: underline;
  }

  tr.an {
    background: color-mix(in srgb, var(--accent) 8%, transparent);
  }

  .form {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 1rem 1.1rem;
    margin-top: 1.2rem;
    max-width: 46rem;
    display: grid;
    gap: 0.7rem;
  }

  .form h3 {
    font-size: 0.95rem;
    font-weight: 650;
    margin: 0;
  }

  .form label {
    display: grid;
    gap: 0.25rem;
    font-size: 0.82rem;
  }

  .form label > span {
    color: var(--tx-mut);
  }

  .form small,
  .unter {
    color: var(--tx-faint);
    font-size: 0.74rem;
    line-height: 1.5;
  }

  .form label.schalter {
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  .form input[type="text"],
  .form textarea,
  .form select {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    color: var(--tx);
    font: 0.86rem var(--mono);
    padding: 0.4rem 0.55rem;
  }

  .form input:disabled,
  .form textarea:disabled,
  .form select:disabled {
    opacity: 0.6;
  }

  .aktionen {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.3rem;
  }

  .vorschlaege {
    display: grid;
    gap: 0.35rem;
    border-top: 1px solid var(--line);
    padding-top: 0.7rem;
  }

  .vorschlaege b {
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
  }

  .vorschlaege ul {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    gap: 0.4rem;
  }

  .vorschlaege li {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 0.5rem;
    font-size: 0.8rem;
  }

  .vorschlaege .titel {
    font-weight: 650;
  }

  /* Die Warnung bricht auf eine eigene Zeile: Sie ist der Satz, wegen dessen es
     diese Liste gibt, und am Rand abgeschnitten wäre sie wertlos. */
  .vorschlaege .warn {
    flex-basis: 100%;
    color: var(--warn, var(--accent));
    font-size: 0.76rem;
    line-height: 1.5;
  }

  /* Die Befunde des Prüfers: rot umrandet, aber mit Feld und Grund je Zeile.
     „Abgelehnt" allein schickte jemanden auf die Suche in einer Konfiguration,
     die er nicht geschrieben hat. */
  .befunde {
    border: 1px solid var(--err);
    border-radius: 10px;
    padding: 0.6rem 0.8rem;
    display: grid;
    gap: 0.3rem;
  }

  .befunde b {
    font-size: 0.82rem;
  }

  .befunde ul {
    margin: 0;
    padding-left: 1.1rem;
    font-size: 0.8rem;
    color: var(--tx-mut);
    line-height: 1.6;
  }

  /* Die Meldung von nginx im Klartext und umbruchfähig: Sie enthält Pfad und
     Zeilennummer, und ein am Rand abgeschnittener Pfad ist keine Auskunft. */
  .meldung-roh {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 0.7rem 0.9rem;
    font: 0.78rem/1.5 var(--mono);
    color: var(--tx-mut);
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    max-width: 52rem;
    margin-bottom: 0.8rem;
  }
</style>
