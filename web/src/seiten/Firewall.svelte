<script lang="ts">
  // Firewall: das Modul mit Grundsatz VI — „Was schiefgehen kann, hat einen
  // Rückweg."
  //
  // Es ist das einzige, bei dem ein Fehler den Zugang zum Panel kostet, und zwar
  // aus der Seite heraus, auf der man ihn zurücknehmen könnte. Deshalb ist die
  // Reihenfolge auf dieser Seite anders als überall sonst: Steht eine Probe aus,
  // steht sie ganz oben, vor dem Zustand und vor der Liste. Wer hereinkommt,
  // während eine Frist läuft, muss zuerst den Knopf sehen, der sie beendet.
  //
  // Der Entwurf der Regeln liegt im Browser, die Wahrheit auf dem Server. Beides
  // getrennt zu halten ist hier wichtiger als sonst: Wer eine Zeile hinzufügt und
  // nicht übernimmt, hat nichts geändert — und die Seite darf ihm nicht das
  // Gegenteil suggerieren, indem sie den Entwurf wie den Zustand aussehen lässt.
  import Rueckfrage from "../komponenten/Rueckfrage.svelte";
  import Vorgangsplatte from "../komponenten/Vorgangsplatte.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, api } from "../lib/api";
  import { Probelauf } from "../lib/probe.svelte";
  import { t } from "../lib/texte";
  import { Vorgang } from "../lib/vorgang.svelte";
  import type { Bestaetigung, Firewall, Regel, RegelZeile } from "../lib/typen";

  let { darfSchreiben = false }: { darfSchreiben?: boolean } = $props();

  let daten = $state<Firewall | null>(null);
  let fehler = $state("");
  let meldung = $state("");
  let laufendeAktion = $state("");

  /** entwurf ist der bearbeitete Regelsatz. Er wird aus dem Zustand des Servers
   *  gesetzt und danach nur von der Bedienung verändert — nicht mehr vom Server,
   *  solange jemand daran arbeitet. Sonst verschwände eine gerade getippte Zeile,
   *  weil eine Antwort hereinkommt. */
  let entwurf = $state<RegelZeile[]>([]);
  let bearbeitet = $state(false);

  const vorgang = new Vorgang("firewall-install");

  // Die Probe: Anzeige hier, Wahrheit im Server. Läuft die Frist ab, wird der
  // Zustand einmal neu geholt — was dann gilt, sagt der Server.
  const probe = new Probelauf(() => {
    meldung = "";
    void laden();
  });

  let offeneFrage = $state<{
    frage: Bestaetigung;
    tun: (getippt: string) => Promise<void>;
  } | null>(null);

  async function laden() {
    fehler = "";
    try {
      const frisch = await api.firewall();
      uebernehmen(frisch);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  function uebernehmen(frisch: Firewall) {
    daten = frisch;
    probe.setzen(frisch.probe.offen, frisch.probe.gegenstand, frisch.probe.rest_sekunden);
    vorgang.setzen(frisch.job);
    // Den Entwurf nur übernehmen, wenn niemand daran gearbeitet hat. Wer eine
    // Zeile getippt und noch nicht übernommen hat, verlöre sie sonst durch eine
    // Antwort, die mit seiner Eingabe nichts zu tun hat.
    if (!bearbeitet) entwurf = frisch.zeilen.map((z) => ({ ...z }));
  }

  $effect(() => () => {
    probe.anhalten();
    vorgang.loesen();
  });

  function zeileHinzu() {
    bearbeitet = true;
    entwurf = [
      ...entwurf,
      { port: 0, protokoll: "tcp", quelle: "", notiz: "", fest: false, vorschlag: false, hinweis: "" },
    ];
  }

  function zeileWeg(i: number) {
    bearbeitet = true;
    entwurf = entwurf.filter((_, j) => j !== i);
  }

  /** vorschlagAnnehmen macht aus einem Vorschlag eine gewöhnliche Zeile. Er ist
   *  bis dahin nicht Teil des Regelsatzes — nur ein Hinweis darauf, was fehlt. */
  function vorschlagAnnehmen(i: number) {
    bearbeitet = true;
    entwurf = entwurf.map((z, j) => (j === i ? { ...z, vorschlag: false } : z));
  }

  function aendern(i: number, feld: keyof Regel, wert: string) {
    bearbeitet = true;
    entwurf = entwurf.map((z, j) => {
      if (j !== i) return z;
      if (feld === "port") return { ...z, port: Number(wert) || 0 };
      return { ...z, [feld]: wert };
    });
  }

  /** zuUebernehmen sind die Zeilen, die tatsächlich gelten sollen: alles außer
   *  den noch nicht angenommenen Vorschlägen. */
  const zuUebernehmen = $derived(
    entwurf
      .filter((z) => !z.vorschlag)
      .map((z): Regel => ({
        port: z.port,
        protokoll: z.protokoll,
        quelle: z.quelle.trim(),
        notiz: z.notiz.trim(),
      })),
  );

  async function starten(
    name: string,
    aufruf: (bestaetigt: boolean, getippt: string) => Promise<{ meldung: string; zustand?: Firewall }>,
  ) {
    laufendeAktion = name;
    meldung = "";
    fehler = "";
    try {
      const antwort = await aufruf(false, "");
      offeneFrage = null;
      fertig(antwort);
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
              fertig(antwort);
            } catch (zweite) {
              if (zweite instanceof AbgemeldetFehler) throw zweite;
              if (zweite instanceof BestaetigungNoetig) {
                offeneFrage = { frage: zweite.bestaetigung, tun: offeneFrage!.tun };
                return;
              }
              offeneFrage = null;
              fehler = zweite instanceof Error ? zweite.message : t.fehler.laden;
              void laden();
            } finally {
              laufendeAktion = "";
            }
          },
        };
        return;
      }
      fehler = e instanceof Error ? e.message : t.fehler.laden;
      // Nach einem Fehler den Zustand neu holen: Bei einer abgewiesenen
      // Aktivierung ist die Meldung die halbe Auskunft — die andere Hälfte ist,
      // dass der Panel-Port weiter fehlt.
      void laden();
    } finally {
      laufendeAktion = "";
    }
  }

  function fertig(antwort: { meldung: string; zustand?: Firewall }) {
    // Die Meldung nur zeigen, wenn KEINE Probe läuft. Sonst stünde dieselbe
    // Aussage zweimal auf der Seite — einmal im Probeband mit der Uhr, einmal
    // darunter in Grün. Grün ist außerdem das falsche Zeichen für etwas, das
    // sich in 60 Sekunden von selbst zurücknimmt.
    meldung = antwort.zustand?.probe.offen ? "" : antwort.meldung;
    // Nach einer übernommenen Änderung ist der Entwurf nicht mehr „bearbeitet":
    // Er ist jetzt der Zustand.
    bearbeitet = false;
    if (antwort.zustand) {
      uebernehmen(antwort.zustand);
    } else {
      void laden();
    }
  }

  async function bestaetigen() {
    laufendeAktion = "confirm";
    fehler = "";
    try {
      const antwort = await api.probeBestaetigen();
      meldung = antwort.meldung;
      uebernehmen(antwort.zustand);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      // Auch ein 409 landet hier — die Frist war schon abgelaufen. Das ist keine
      // Panne der Bedienung, sondern die Auskunft, dass zurückgerollt wurde.
      fehler = e instanceof Error ? e.message : t.fehler.laden;
      void laden();
    } finally {
      laufendeAktion = "";
    }
  }

  const arbeitet = $derived(laufendeAktion !== "");
  const protokolle = ["tcp", "udp"];

  laden();
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.sicherheit} / {t.ziele.firewall}</div>
    <div class="h1">{t.ziele.firewall}</div>
  </div>
  <div class="schub"></div>
  {#if daten}
    <span class="zustand" class:gut={daten.aktiv} class:warn={!daten.aktiv}>
      <i aria-hidden="true"></i>{daten.aktiv ? t.firewall.ein : t.firewall.aus}
    </span>
    <span class="marke">{daten.regelwerk}</span>
  {/if}
</div>

{#if !daten && !fehler}
  <p class="detail">{t.firewall.laedt}</p>
{:else if daten}
  {#if probe.offen}
    <!-- Ganz oben, vor allem anderen: Wer hereinkommt, während eine Frist läuft,
         muss zuerst den Knopf sehen, der sie beendet. Das ist der einzige Ort im
         Panel, an dem Untätigkeit etwas rückgängig macht. -->
    <section class="probe" role="alert">
      <div class="uhr zahl" class:knapp={probe.rest <= 15}>{probe.rest}</div>
      <div class="text">
        <b>{t.firewall.probeTitel(probe.gegenstand)}</b>
        <span class="detail">{t.firewall.probeDetail}</span>
      </div>
      {#if darfSchreiben}
        <button type="button" class="knopf" disabled={arbeitet} onclick={bestaetigen}>
          {t.firewall.bestaetigen}
        </button>
      {/if}
    </section>
  {/if}

  {#if meldung}<p class="meldung" role="status">{meldung}</p>{/if}
  {#if fehler}<p class="warnung" role="alert">{fehler}</p>{/if}
  {#if daten.fehler}<p class="warnung">{daten.fehler}</p>{/if}
  {#if daten.anmerkung}<p class="hinweis">{daten.anmerkung}</p>{/if}

  <Vorgangsplatte {vorgang} />

  {#if !daten.installiert}
    <!-- Ohne ufw gibt es nichts zu verwalten. Der einzige sinnvolle Knopf ist
         der, der es einspielt — alles andere wäre eine Oberfläche für ein
         Programm, das nicht da ist. -->
    <div class="leer">
      <p><b>{t.firewall.nichtInstalliert}</b></p>
      <p class="detail">{t.firewall.nichtInstalliertDetail}</p>
      {#if darfSchreiben}
        <button
          type="button"
          class="knopf"
          disabled={arbeitet || (vorgang.job?.laeuft ?? false)}
          onclick={() => starten("install", () => api.ufwEinspielen())}
        >
          {t.firewall.einspielen}
        </button>
      {/if}
    </div>
  {:else}
    {#if !daten.panel_port_offen}
      <!-- Die Sicherung, sichtbar gemacht: Ohne diese Regel verweigert der
           Server das Einschalten. Es zu verschweigen und den Knopf trotzdem
           anzubieten hieße, jemanden gegen eine Wand laufen zu lassen. -->
      <p class="warnung">{t.firewall.panelPortFehlt(daten.panel_port)}</p>
    {/if}

    <div class="aktionen">
      {#if darfSchreiben}
        <button
          type="button"
          class="knopf"
          disabled={arbeitet || !bearbeitet}
          onclick={() => starten("regeln", (b) => api.regelnUebernehmen(zuUebernehmen, b))}
        >
          {t.firewall.uebernehmen}
        </button>
        <button type="button" class="knopf leise" disabled={arbeitet} onclick={zeileHinzu}>
          {t.firewall.zeileHinzu}
        </button>
        <div class="schub"></div>
        {#if daten.aktiv}
          <button
            type="button"
            class="knopf gefahr"
            disabled={arbeitet}
            onclick={() => starten("aus", (b, g) => api.ufwSchalten(false, b, g))}
          >
            {t.firewall.ausschalten}
          </button>
        {:else}
          <button
            type="button"
            class="knopf"
            disabled={arbeitet || !daten.panel_port_offen}
            onclick={() => starten("ein", (b, g) => api.ufwSchalten(true, b, g))}
          >
            {t.firewall.einschalten}
          </button>
        {/if}
      {:else}
        <p class="detail">{t.firewall.nurLesen}</p>
      {/if}
    </div>

    {#if bearbeitet}
      <!-- Der Entwurf gilt noch nicht. Das muss dastehen, sonst hält jemand die
           bearbeitete Liste für den Zustand des Servers. -->
      <p class="hinweis">{t.firewall.entwurfOffen}</p>
    {/if}

    <div class="tabelle-rahmen">
      <table class="tabelle">
        <thead>
          <tr>
            <th>{t.firewall.port}</th>
            <th>{t.firewall.protokoll}</th>
            <th>{t.firewall.quelle}</th>
            <th>{t.firewall.notiz}</th>
            {#if darfSchreiben}<th></th>{/if}
          </tr>
        </thead>
        <tbody>
          {#each entwurf as zeile, i (`${i}`)}
            <tr class:vorschlag={zeile.vorschlag}>
              <td data-spalte={t.firewall.port} class="zahlenspalte">
                {#if zeile.fest || zeile.vorschlag || !darfSchreiben}
                  <span class="pfad">{zeile.port}</span>
                {:else}
                  <input
                    class="klein zahl"
                    type="number"
                    min="1"
                    max="65535"
                    value={zeile.port || ""}
                    oninput={(e) => aendern(i, "port", e.currentTarget.value)}
                  />
                {/if}
              </td>
              <td data-spalte={t.firewall.protokoll}>
                {#if zeile.fest || zeile.vorschlag || !darfSchreiben}
                  <span class="gedaempft">{zeile.protokoll}</span>
                {:else}
                  <select
                    value={zeile.protokoll}
                    onchange={(e) => aendern(i, "protokoll", e.currentTarget.value)}
                  >
                    {#each protokolle as p (p)}<option value={p}>{p}</option>{/each}
                  </select>
                {/if}
              </td>
              <td data-spalte={t.firewall.quelle}>
                {#if zeile.fest || zeile.vorschlag || !darfSchreiben}
                  <span class="gedaempft">{zeile.quelle || t.firewall.ueberall}</span>
                {:else}
                  <input
                    type="text"
                    placeholder={t.firewall.ueberall}
                    value={zeile.quelle}
                    oninput={(e) => aendern(i, "quelle", e.currentTarget.value)}
                  />
                {/if}
              </td>
              <td data-spalte={t.firewall.notiz}>
                {#if zeile.fest || zeile.vorschlag || !darfSchreiben}
                  <span class="gedaempft">{zeile.notiz || "—"}</span>
                {:else}
                  <input
                    type="text"
                    value={zeile.notiz}
                    oninput={(e) => aendern(i, "notiz", e.currentTarget.value)}
                  />
                {/if}
                {#if zeile.hinweis}
                  <span class="detail zeilenhinweis">{zeile.hinweis}</span>
                {/if}
              </td>
              {#if darfSchreiben}
                <td data-spalte="" class="zahlenspalte">
                  {#if zeile.vorschlag}
                    <button
                      type="button"
                      class="knopf leise winzig"
                      onclick={() => vorschlagAnnehmen(i)}
                    >
                      {t.firewall.annehmen}
                    </button>
                  {:else if !zeile.fest}
                    <button
                      type="button"
                      class="knopf gefahr winzig"
                      onclick={() => zeileWeg(i)}
                    >
                      {t.firewall.entfernen}
                    </button>
                  {:else}
                    <span class="detail">{t.firewall.fest}</span>
                  {/if}
                </td>
              {/if}
            </tr>
          {/each}
        </tbody>
      </table>
    </div>
  {/if}
{:else}
  <p class="warnung" role="alert">{fehler}</p>
{/if}

{#if offeneFrage}
  <Rueckfrage
    frage={offeneFrage.frage}
    laeuft={arbeitet}
    bestaetigen={(getippt) => offeneFrage!.tun(getippt)}
    abbrechen={() => {
      offeneFrage = null;
      laufendeAktion = "";
    }}
  />
{/if}

<style>
  /* Die Probe ist die auffälligste Fläche der Seite. Bernstein und nicht rot:
   * Es ist kein Fehler, sondern eine Frist — rot wäre eine Meldung, die nichts
   * von einem verlangt. */
  .probe {
    display: flex;
    align-items: center;
    gap: 0.9rem;
    background: var(--surface);
    border: 1px solid var(--accent);
    border-radius: var(--r);
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

  /* Unter fünfzehn Sekunden wird die Zahl rot. Die Farbe trägt hier keine neue
   * Aussage, sondern dieselbe dringlicher — daneben steht weiter der Satz. */
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

  .aktionen {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    flex-wrap: wrap;
    margin-bottom: 0.8rem;
  }

  .schub {
    flex: 1;
  }

  table input,
  table select {
    background: var(--bg);
    border: 1px solid var(--line2);
    border-radius: 6px;
    padding: 0.2rem 0.4rem;
    color: var(--tx);
    font: 0.8rem var(--sans);
    max-width: 100%;
  }

  table input.klein {
    width: 6rem;
  }

  table input.zahl {
    font-family: var(--mono);
  }

  /* Ein Vorschlag ist blasser: Er gilt nicht, er wird angeboten. */
  tr.vorschlag {
    opacity: 0.72;
  }

  .zeilenhinweis {
    display: block;
    margin-top: 0.2rem;
    font-size: 0.72rem;
  }

  .winzig {
    font-size: 0.7rem;
    padding: 0.1rem 0.45rem;
  }

  .leer {
    display: grid;
    justify-items: center;
    gap: 0.5rem;
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

  .hinweis {
    font-size: 0.82rem;
    color: var(--accent);
    margin-bottom: 0.7rem;
  }

  /* In der Kartenansicht steht die Beschriftung links und der Wert rechts. Ein
   * Eingabefeld mit seiner Vorgabebreite von etwa zwanzig Zeichen passt dort
   * nicht mehr hin und wird am Kartenrand beschnitten — es soll den Platz
   * nehmen, der übrig ist. */
  @media (max-width: 600px) {
    table input,
    table select {
      width: 100%;
      min-width: 0;
    }
  }
</style>
