<script lang="ts">
  // Dateien: Krumenpfad und Liste links, Inspektor rechts — dieselbe Werkbank
  // wie bei den Diensten. Was dieses Modul davon unterscheidet, sind drei Dinge:
  //
  //  1. Der Ort steht in der Adresse und ist ein Schritt im Verlauf. Beim
  //     Hineinwechseln in einen Ordner drückt niemand „zurück", um die Seite zu
  //     verlassen — er will eine Ebene höher. Deshalb pushState je Ebene
  //     (weg.setzeAlle mit schritt=true), und nicht das Muster der Dienste, wo
  //     nur die erste Auswahl ein Schritt ist.
  //  2. Gefiltert wird NICHT im Browser. Bei den Diensten kommt die Liste einmal
  //     und das Tippen filtert sofort; hier ist die Liste bei zweitausend
  //     Einträgen gekürzt, und ein Browserfilter darüber behauptete „kein
  //     Treffer" für eine Datei, die es gibt. Die Suche geht deshalb an den
  //     Server — sie sucht auch in Unterordnern, was ein Browserfilter nie
  //     könnte.
  //  3. Ein Eintrag kann gesperrt sein. Er ist dann sichtbar, sein Inhalt aber
  //     nie: kein Download, kein Editor, kein Kopieren. Das steht an der Zeile
  //     und im Inspektor, nicht in einer Fehlermeldung nach dem Klick.
  import Inspektor from "../komponenten/Inspektor.svelte";
  import Vorgangsplatte from "../komponenten/Vorgangsplatte.svelte";
  import { AbgemeldetFehler, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { Vorgang } from "../lib/vorgang.svelte";
  import { verweis, weg } from "../lib/weg.svelte";
  import type { Dateidetail, Dateiliste, Eintrag, Handgriff, Sortierung } from "../lib/typen";

  let { darfSchreiben = false }: { darfSchreiben?: boolean } = $props();

  let daten = $state<Dateiliste | null>(null);
  let fehler = $state("");
  let laedt = $state(false);
  /** suchfeld ist der Text im Eingabefeld. Getrennt vom Parameter in der
   *  Adresse, weil die Suche erst beim Absenden läuft: Ein Aufruf je Buchstabe
   *  liefe zehn Sekunden lang über einen Verzeichnisbaum. */
  let suchfeld = $state("");

  let detail = $state<Dateidetail | null>(null);
  let detailFehler = $state("");
  let detailLaeuft = $state(false);

  const vorgang = new Vorgang("files");

  const pfad = $derived(weg.parameter.pfad ?? "");
  const gewaehlt = $derived(weg.parameter.eintrag ?? "");
  const begriff = $derived(weg.parameter.q ?? "");
  const sortierung = $derived((weg.parameter.sort ?? "name") as Sortierung);
  const absteigend = $derived(weg.parameter.desc === "1");
  const versteckt = $derived(weg.parameter.versteckt === "1");

  // Die Liste folgt der Adresse, nicht dem Klick — damit ein Neuladen auf
  // ?pfad=/etc/nginx dasselbe zeigt und der Zurück-Knopf wirkt.
  $effect(() => {
    void laden(pfad, begriff, sortierung, absteigend, versteckt);
  });

  async function laden(
    p: string,
    q: string,
    sort: Sortierung,
    desc: boolean,
    hidden: boolean,
  ) {
    laedt = true;
    fehler = "";
    try {
      const antwort = await api.dateien(p, { sort, absteigend: desc, versteckt: hidden, q });
      daten = antwort;
      suchfeld = antwort.suche;
      // Der Server kennt den Ort besser als die Adresse: Ohne Parameter beginnt
      // er im ersten sichtbaren Bereich, und ohne diese Zeile stünde im
      // Krumenpfad ein Ort und in der Adresse keiner — der nächste Klick auf
      // „eine Ebene höher" ginge dann ins Leere.
      if (!p && antwort.pfad) weg.setzeAlle({ pfad: antwort.pfad }, false);
      vorgang.setzen(antwort.vorgang);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      laedt = false;
    }
  }

  // Das Detail folgt ebenfalls der Adresse.
  $effect(() => {
    const ziel = gewaehlt;
    if (!ziel) {
      detail = null;
      detailFehler = "";
      return;
    }
    if (detail?.eintrag.path === ziel) return;
    void detailHolen(ziel);
  });

  async function detailHolen(ziel: string) {
    detailLaeuft = true;
    detailFehler = "";
    try {
      detail = await api.eintrag(ziel);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      detail = null;
      detailFehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      detailLaeuft = false;
    }
  }

  /** hinein wechselt das Verzeichnis. Ein Schritt im Verlauf, und Auswahl wie
   *  Suchbegriff fallen dabei weg: Beides bezog sich auf den alten Ort. */
  function hinein(ziel: string) {
    weg.setzeAlle({ pfad: ziel, eintrag: "", q: "" }, true);
  }

  function waehlen(e: Eintrag) {
    // Ein Ordner wird betreten, eine Datei ausgewählt. Ein Doppelklick als
    // Unterschied wäre auf einem Telefon nicht bedienbar; die Art des Eintrags
    // ist die verlässlichere Auskunft darüber, was gemeint ist. Der Inspektor
    // eines Ordners ist über die Spalte „Art" erreichbar.
    if (e.kind === "ordner") {
      hinein(e.path);
      return;
    }
    weg.setzeAlle({ eintrag: e.path }, !gewaehlt);
  }

  function schliessen() {
    weg.setzeAlle({ eintrag: "" }, false);
  }

  function sortieren(feld: Sortierung) {
    // Ein Klick auf die schon aktive Spalte dreht die Richtung. Kein Schritt im
    // Verlauf: Zehnmal sortieren und dann zehnmal zurück wäre kein Gewinn.
    const dreht = sortierung === feld && !absteigend;
    weg.setzeAlle({ sort: feld === "name" ? "" : feld, desc: dreht ? "1" : "" }, false);
  }

  function versteckteUmschalten() {
    weg.setzeAlle({ versteckt: versteckt ? "" : "1" }, false);
  }

  function suchen(e: SubmitEvent) {
    e.preventDefault();
    weg.setzeAlle({ q: suchfeld.trim(), eintrag: "" }, true);
  }

  function sucheBeenden() {
    suchfeld = "";
    weg.setzeAlle({ q: "", eintrag: "" }, true);
  }

  function kann(h: Handgriff): boolean {
    return detail?.aktionen.includes(h) ?? false;
  }

  function artKlasse(e: Eintrag): string {
    if (e.sensitive) return "warn";
    if (e.kind === "verweis" && e.link_broken) return "schlecht";
    return "";
  }

  const sortPfeil = (feld: Sortierung) =>
    sortierung !== feld ? "" : absteigend ? " ↓" : " ↑";
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.betrieb} / {t.ziele.dateien}</div>
    <div class="h1">{t.ziele.dateien}</div>
  </div>
  <div class="schub"></div>
  {#if daten}
    {#if daten.frei_text}
      <span class="marke" class:warn={daten.frei_knapp}>
        {daten.frei_text} {daten.frei_knapp ? t.dateien.freiKnapp : t.dateien.frei}
      </span>
    {/if}
  {/if}
</div>

{#if fehler && !daten}
  <div class="hinweis">
    <p>{t.fehler.laden}</p>
    <p class="detail">{fehler}</p>
    <button
      class="knopf"
      onclick={() => laden(pfad, begriff, sortierung, absteigend, versteckt)}
    >
      {t.fehler.erneut}
    </button>
  </div>
{:else if !daten}
  <p class="detail">{t.dateien.laedt}</p>
{:else}
  <!-- Die Bereiche sind die Einstiegspunkte. Sie stehen über allem, weil sie die
       Antwort auf „wo darf ich überhaupt hin" sind — und weil ein Pfad, der
       außerhalb liegt, gar nicht erst getippt werden soll. -->
  <div class="bereiche" role="group" aria-label={t.dateien.wurzeln}>
    <span class="etikett">{t.dateien.wurzeln}</span>
    {#each daten.wurzeln as wurzel (wurzel)}
      <button
        type="button"
        class:an={pfad === wurzel}
        class:schreib={daten.schreibwurzeln.includes(wurzel)}
        onclick={() => hinein(wurzel)}
      >
        {wurzel}
      </button>
    {/each}
  </div>

  {#each daten.warnungen as warnung (warnung.path)}
    <!-- Fast immer eine alte systemd-Härtung nach einem Selbstupdate. Ohne diesen
         Hinweis sucht man den Fehler im Panel statt in der Unit. -->
    <p class="band warn" role="status">
      <b>{warnung.path}</b> — {warnung.reason || t.dateien.nichtBeschreibbar}
    </p>
  {/each}

  <nav class="krumen" aria-label={t.dateien.name}>
    {#each daten.krumen as krume, i (krume.path)}
      <!-- Der Trenner erst ab dem zweiten Glied: Das erste IST der Schrägstrich,
           und „/ / tmp" sieht wie ein Fehler in der Pfadzusammensetzung aus. -->
      {#if i > 1}<span class="trenner" aria-hidden="true">/</span>{/if}
      <button
        type="button"
        class:jetzt={krume.path === daten.pfad}
        onclick={() => hinein(krume.path)}
      >
        {krume.name}
      </button>
    {/each}
  </nav>

  <div class="werkzeuge">
    <!-- Ein Formular und kein Tippfilter: Die Suche läuft über den Baum und
         braucht Sekunden. Enter ist der Auslöser, und das ist ehrlicher als ein
         Feld, das bei jedem Buchstaben eine Anfrage schickt. -->
    <form class="suche" onsubmit={suchen}>
      <label>
        <span class="nur-vorlese">{t.dateien.suchenHier}</span>
        <input
          bind:value={suchfeld}
          type="search"
          placeholder={t.dateien.suchen}
          autocomplete="off"
          spellcheck="false"
        />
      </label>
      <button type="submit" class="knopf leise klein">{t.dateien.suchen}</button>
      {#if begriff}
        <button type="button" class="knopf leise klein" onclick={sucheBeenden}>
          {t.dateien.suchenBeenden}
        </button>
      {/if}
    </form>

    <div class="schub"></div>

    <label class="schalter">
      <input type="checkbox" checked={versteckt} onchange={versteckteUmschalten} />
      {t.dateien.versteckte}
    </label>

    {#if daten.eltern}
      <button type="button" class="knopf leise klein" onclick={() => hinein(daten.eltern)}>
        ↑ {t.dateien.hoch}
      </button>
    {/if}
  </div>

  {#if begriff}
    <p class="band info" role="status">
      {t.dateien.suchergebnis(begriff, daten.gesamt)}
      {#if daten.gekuerzt}
        — {daten.gekuerzt_grund}
      {/if}
    </p>
  {:else if daten.gekuerzt}
    <p class="band warn" role="status">{daten.gekuerzt_grund}</p>
  {/if}

  {#if vorgang.job}
    <Vorgangsplatte {vorgang} />
  {/if}

  {#if fehler}
    <p class="band schlecht" role="alert">{fehler}</p>
  {/if}

  <div class="werkbank" class:allein={!gewaehlt}>
    <div class="tabelle-rahmen" class:blass={laedt}>
      <table class="tabelle">
        <thead>
          <tr>
            <th>
              <button type="button" class="spalte" onclick={() => sortieren("name")}>
                {t.dateien.name}{sortPfeil("name")}
              </button>
            </th>
            <th class="rechts">
              <button type="button" class="spalte" onclick={() => sortieren("size")}>
                {t.dateien.groesse}{sortPfeil("size")}
              </button>
            </th>
            <th>
              <button type="button" class="spalte" onclick={() => sortieren("time")}>
                {t.dateien.geaendert}{sortPfeil("time")}
              </button>
            </th>
            <th>{t.dateien.rechte}</th>
            <th>{t.dateien.eigentuemer}</th>
          </tr>
        </thead>
        <tbody>
          {#if daten.eintraege.length === 0}
            <tr>
              <td colspan="5" class="gedaempft">
                {begriff ? t.dateien.nichtsGefunden : t.dateien.leer}
              </td>
            </tr>
          {:else}
            {#each daten.eintraege as eintrag (eintrag.path)}
              <tr class:gewaehlt={eintrag.path === gewaehlt}>
                <td data-spalte={t.dateien.name}>
                  <!-- Ein eigener Behälter um alles, was zum Namen gehört. Unter
                       600 px ist die Zelle selbst ein Flexkasten mit der
                       Spaltenbeschriftung davor; ohne diesen Behälter wären Name,
                       Marke und Ort drei Geschwister in derselben Reihe, und der
                       Name wurde auf drei Zeilen gequetscht
                       („schlues|sel.geh|eim"). Gesehen hat das ein
                       Bildschirmfoto, nicht der DOM-Test — dort stand alles da. -->
                  <div class="namenszelle">
                    <span class="namenskopf">
                      <button
                        type="button"
                        class="zeile"
                        class:ordner={eintrag.kind === "ordner"}
                        onclick={() => waehlen(eintrag)}
                      >
                        <!-- Der Schrägstrich hinten und nicht vorn: „schreibbar/"
                             ist die Schreibweise, in der jeder einen Ordner
                             erkennt, und ein führender Strich sähe wie ein
                             absoluter Pfad aus. -->
                        {eintrag.name}{#if eintrag.kind === "ordner"}<span aria-hidden="true">/</span>{/if}
                      </button>
                      {#if eintrag.sensitive}
                        <span class="zustand warn" title={eintrag.sensitive_reason}>
                          <i aria-hidden="true"></i>{t.dateien.gesperrt}
                        </span>
                      {/if}
                    </span>
                    {#if eintrag.kind === "verweis"}
                      <span class="verweisziel {artKlasse(eintrag)}">
                        → {eintrag.link_target}
                      </span>
                    {/if}
                    <!-- Ein Suchergebnis steht quer über Unterordner. Ohne den Ort
                         wäre die Liste eine Sammlung gleichnamiger Dateien, von
                         denen keine auffindbar ist. -->
                    {#if begriff}
                      <span class="ort">{eintrag.path}</span>
                    {/if}
                  </div>
                </td>
                <td data-spalte={t.dateien.groesse} class="rechts zahl gedaempft">
                  {eintrag.groesse_text || "—"}
                </td>
                <td data-spalte={t.dateien.geaendert} class="gedaempft">
                  {eintrag.geaendert_text || "—"}
                </td>
                <td data-spalte={t.dateien.rechte} class="zahl gedaempft">
                  {eintrag.mode_octal}
                </td>
                <td data-spalte={t.dateien.eigentuemer} class="gedaempft">
                  {eintrag.owner}:{eintrag.group}
                </td>
              </tr>
            {/each}
          {/if}
        </tbody>
      </table>
    </div>

    {#if gewaehlt}
      <Inspektor
        titel={detail?.eintrag.name ?? gewaehlt}
        zustand={detail ? artKlasse(detail.eintrag) : ""}
        zustandText={detail?.eintrag.sensitive ? t.dateien.gesperrt : ""}
        marke={detail?.eintrag.art ?? ""}
        {schliessen}
      >
        {#snippet kinder()}
          {#if detailLaeuft && !detail}
            <p class="detail">{t.dateien.laedt}</p>
          {:else if detailFehler && !detail}
            <p class="warnung">{detailFehler}</p>
            <button class="knopf leise" onclick={() => detailHolen(gewaehlt)}>
              {t.fehler.erneut}
            </button>
          {:else if detail}
            <dl class="kv">
              <dt>{t.dateien.name}</dt>
              <dd class="pfad">{detail.eintrag.path}</dd>
              <dt>{t.dateien.art}</dt>
              <dd>{detail.eintrag.art}</dd>
              {#if detail.eintrag.kind !== "ordner"}
                <dt>{t.dateien.groesse}</dt>
                <dd class="zahl">{detail.eintrag.groesse_text}</dd>
              {/if}
              {#if detail.mass_text}
                <dt>{t.dateien.inhaltZaehlung}</dt>
                <dd>{detail.mass_text}</dd>
              {/if}
              <dt>{t.dateien.geaendert}</dt>
              <dd>{detail.eintrag.geaendert_text || "—"}</dd>
              <dt>{t.dateien.eigentuemer}</dt>
              <dd>{detail.eintrag.owner}:{detail.eintrag.group}</dd>
              <dt>{t.dateien.rechte}</dt>
              <dd class="zahl">{detail.rechte.octal} · {detail.rechte.symbolic}</dd>
              {#if detail.eintrag.link_target}
                <dt>{t.dateien.verweisAuf}</dt>
                <dd class="pfad">{detail.eintrag.link_target}</dd>
              {/if}
            </dl>

            {#if detail.eintrag.link_broken}
              <p class="warnung">{t.dateien.verweisGebrochen}</p>
            {/if}
            {#if detail.eintrag.sensitive}
              <p class="warnung">
                {detail.eintrag.sensitive_reason || t.dateien.gesperrtErklaerung}
              </p>
            {/if}

            <!-- Die Rechte in Worten. „0755“ sagt nur denen etwas, die es ohnehin
                 wissen; „Eigentümer — darf lesen, ändern und ausführen“ ist die
                 Auskunft, die eine Entscheidung trägt.
                 Zwei Spalten und kein Satz: Der Text aus privops ist als Wert
                 einer Zeile formuliert („darf lesen“), nicht als Prädikat zu
                 einem Subjekt. Aneinandergereiht ergäbe er „alle anderen darf
                 lesen“ — grammatisch falsch, und die Ursache läge dann in der
                 Darstellung und nicht im Text. -->
            <div class="rechteblock">
              <b>{t.dateien.rechte}</b>
              <dl>
                {#each detail.rechte.roles as rolle (rolle.key)}
                  <dt>{rolle.label}</dt>
                  <dd>{rolle.text}</dd>
                {/each}
              </dl>
              {#each detail.rechte.specials.filter((s) => s.set) as sonder (sonder.key)}
                <p class="sonder"><b>{sonder.label}</b> — {sonder.text}</p>
              {/each}
            </div>

            <div class="aktionen">
              {#if kann("oeffnen")}
                <button
                  type="button"
                  class="knopf"
                  onclick={() => hinein(detail!.eintrag.path)}
                >
                  {t.dateien.handgriff.oeffnen}
                </button>
              {/if}
              <!-- Ein echter Verweis und kein fetch: Der Browser soll den
                   Download-Manager bekommen und nicht der Speicher des Tabs eine
                   Datei von zwei Gigabyte. Deshalb auch kein onclick-Abfang. -->
              {#if kann("herunterladen")}
                <a class="knopf leise" href={api.herunterladen(detail.eintrag.path)}>
                  {t.dateien.handgriff.herunterladen}
                </a>
              {/if}
              {#if kann("archiv")}
                <a class="knopf leise" href={api.archiv(detail.eintrag.path)}>
                  {t.dateien.handgriff.archiv}
                </a>
              {/if}
            </div>

            {#if !darfSchreiben}
              <p class="detail">{t.dateien.nurLesen}</p>
            {:else if !detail.eintrag.writable}
              <p class="detail">{t.dateien.nichtBeschreibbar}</p>
            {/if}
          {/if}
        {/snippet}
      </Inspektor>
    {/if}
  </div>

  <p class="fuss">
    {t.dateien.inhalt(daten.zaehler.ordner, daten.zaehler.dateien, daten.zaehler.bytes_text)}
    {#if daten.zaehler.gesperrt > 0}
      · {daten.zaehler.gesperrt} {t.dateien.gesperrt}
    {/if}
    <!-- Der Weg in die alte Oberfläche bleibt sichtbar, solange der Editor und
         die Schreibvorgänge dort noch mehr können. Ihn zu verschweigen hieße,
         jemanden mit einer halben Fläche allein zu lassen. -->
    · <a href={`/files?path=${encodeURIComponent(daten.pfad)}`}>{t.dateien.alteAnsicht}</a>
  </p>
{/if}

<style>
  .bereiche {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    flex-wrap: wrap;
    margin-bottom: 0.7rem;
  }

  .etikett {
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
    margin-right: 0.2rem;
  }

  .bereiche button {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 7px;
    padding: 0.28rem 0.55rem;
    color: var(--tx-mut);
    font: 0.76rem var(--mono);
    cursor: pointer;
  }

  /* Ein beschreibbarer Bereich ist etwas anderes als ein nur sichtbarer, und der
   * Unterschied entscheidet, ob ein Handgriff angeboten wird. Er gehört deshalb
   * an den Einstiegspunkt und nicht erst in die Fehlermeldung. */
  .bereiche button.schreib {
    color: var(--tx);
  }

  .bereiche button.an {
    border-color: var(--accent-dim);
    color: var(--accent);
  }

  .krumen {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.1rem;
    margin-bottom: 0.7rem;
    font: 0.82rem var(--mono);
  }

  .krumen button {
    background: none;
    border: none;
    padding: 0.1rem 0.2rem;
    color: var(--tx-mut);
    font: inherit;
    cursor: pointer;
    border-radius: 4px;
  }

  .krumen button:hover {
    color: var(--accent);
  }

  .krumen button.jetzt {
    color: var(--tx);
    font-weight: 650;
  }

  .krumen .trenner {
    color: var(--tx-faint);
  }

  .werkzeuge {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    flex-wrap: wrap;
    margin-bottom: 0.8rem;
  }

  .suche {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    flex-wrap: wrap;
  }

  .suche input {
    background: var(--surface);
    border: 1px solid var(--line2);
    border-radius: 8px;
    padding: 0.35rem 0.7rem;
    color: var(--tx);
    font: 0.84rem var(--sans);
    width: 14rem;
    max-width: 100%;
  }

  .schalter {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    color: var(--tx-mut);
    font-size: 0.78rem;
    cursor: pointer;
  }

  .band {
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.5rem 0.7rem;
    margin-bottom: 0.7rem;
    font-size: 0.8rem;
    color: var(--tx-mut);
  }

  .band.warn {
    border-color: var(--accent-dim);
    color: var(--accent);
  }

  .band.schlecht {
    border-color: var(--err);
    color: var(--err);
  }

  .band b {
    font-family: var(--mono);
  }

  /* Während eine neue Liste geholt wird, bleibt die alte stehen und wird blass.
   * Sie durch „lädt …" zu ersetzen ließe die Seite bei jedem Ordnerwechsel
   * springen — und der Krumenpfad, an dem man sich orientiert, verschwände mit. */
  .blass {
    opacity: 0.5;
  }

  .spalte {
    background: none;
    border: none;
    padding: 0;
    color: inherit;
    font: inherit;
    cursor: pointer;
  }

  .spalte:hover {
    color: var(--accent);
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

  .zeile.ordner {
    color: var(--accent);
    font-weight: 650;
  }

  :global(table.tabelle tr.gewaehlt) {
    background: var(--surface2);
  }

  .namenszelle {
    display: grid;
    gap: 0.15rem;
    min-width: 0;
  }

  /* flex-wrap statt eines Umbruchs je Bildschirmbreite: Die Marke rutscht auf
   * ihre eigene Zeile, wenn der Name den Platz braucht — und bleibt daneben,
   * wenn er kurz ist. Eine Regel für beide Breiten. */
  .namenskopf {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.45rem;
    min-width: 0;
  }

  .verweisziel,
  .ort {
    display: block;
    color: var(--tx-faint);
    font: 0.72rem var(--mono);
    overflow-wrap: anywhere;
  }

  .verweisziel.schlecht {
    color: var(--err);
  }

  .rechts {
    text-align: right;
  }

  .rechteblock b {
    display: block;
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
    margin-bottom: 0.3rem;
  }

  .rechteblock dl {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.2rem 0.7rem;
    font-size: 0.8rem;
  }

  .rechteblock dt {
    color: var(--tx);
    white-space: nowrap;
  }

  .rechteblock dd {
    color: var(--tx-mut);
  }

  .rechteblock .sonder {
    margin-top: 0.4rem;
    font-size: 0.76rem;
    color: var(--accent);
  }

  .aktionen {
    display: flex;
    gap: 0.45rem;
    flex-wrap: wrap;
  }

  .fuss {
    margin-top: 0.8rem;
    color: var(--tx-faint);
    font: 0.76rem var(--mono);
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
    font: 0.82rem var(--mono);
  }

  .warnung {
    font-size: 0.82rem;
    color: var(--err);
  }
</style>
