<script lang="ts">
  // Die Schale: Statusband, Seitenleiste, Inhalt, Protokollzeile — auf jeder
  // Seite gleich. Ein Router kommt mit der zweiten Seite; solange es eine gibt,
  // wäre er Gerüst ohne Last.
  import Seitenleiste from "./komponenten/Seitenleiste.svelte";
  import Statusband from "./komponenten/Statusband.svelte";
  import Protokollzeile from "./komponenten/Protokollzeile.svelte";
  import Symbolvorrat from "./komponenten/Symbolvorrat.svelte";
  import Befehlspalette from "./komponenten/Befehlspalette.svelte";
  import UebersichtSeite from "./seiten/Uebersicht.svelte";
  import { AbgemeldetFehler, api } from "./lib/api";
  import { live } from "./lib/live.svelte";
  import { t } from "./lib/texte";
  import type { Signale, Uebersicht, Verlaeufe } from "./lib/typen";

  let uebersicht = $state<Uebersicht | null>(null);
  let verlaeufe = $state<Verlaeufe | null>(null);
  let signale = $state<Signale | null>(null);
  let signalFehler = $state(false);
  let fehler = $state("");
  let abgemeldet = $state(false);

  async function laden() {
    fehler = "";
    try {
      // Die Sitzung zuerst: Sie liefert das CSRF-Token, das schreibende Aufrufe
      // ab der nächsten Stufe brauchen, und sie ist die Stelle, an der eine
      // abgelaufene Sitzung als erstes auffällt.
      await api.sitzung();
      uebersicht = await api.uebersicht();
      verlaeufe = await api.verlaeufe();
      live.starten();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) {
        abgemeldet = true;
        return;
      }
      fehler = e instanceof Error ? e.message : t.fehler.laden;
      return;
    }

    await signaleLaden();
  }

  // Der Handlungsbedarf hat einen EIGENEN Fehlerweg, und das ist der Kern der
  // Sache: Seine Erhebung ruft systemctl und prüft die Neustartmarkierung, sie
  // kann also hängen oder scheitern, wo der Rest längst steht. Ein gemeinsames
  // catch hätte die ganze Seite geleert, weil ein Systemaufruf nicht antwortet —
  // und damit genau den Fehler wiederholt, den die alte Übersicht schon einmal
  // hatte, als sie den Handlungsbedarf beim Rendern erhob. Fehlt das Signal,
  // fehlt eben das Signal; die Zahlen bleiben.
  async function signaleLaden() {
    signalFehler = false;
    try {
      signale = await api.signale();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) {
        abgemeldet = true;
        return;
      }
      signalFehler = true;
    }
  }

  laden();
</script>

<Symbolvorrat />
<Befehlspalette />

{#if abgemeldet}
  <div class="mitte">
    <p>{t.fehler.abgemeldet}</p>
    <a class="knopf" href="/login">Zur Anmeldung</a>
  </div>
{:else}
  <div class="schale">
    <Statusband name={uebersicht?.name ?? ""} uptime={uebersicht?.snapshot?.uptime ?? ""} />
    <Seitenleiste aktiv="uebersicht" />

    <main class="inhalt">
      {#if fehler}
        <div class="mitte">
          <p>{t.fehler.laden}</p>
          <p class="detail">{fehler}</p>
          <button class="knopf" onclick={laden}>{t.fehler.erneut}</button>
        </div>
      {:else if uebersicht}
        <UebersichtSeite {uebersicht} {verlaeufe} {signale} {signalFehler} erneutErheben={signaleLaden} />
      {:else}
        <p class="detail">{t.live.warte}</p>
      {/if}
    </main>

    <Protokollzeile befehl={uebersicht?.letzter_befehl ?? null} />
  </div>
{/if}

<style>
  .mitte {
    display: grid;
    place-items: center;
    gap: 0.8rem;
    padding: 3rem 1rem;
    text-align: center;
  }

  .detail {
    color: var(--tx-mut);
    font: 0.82rem var(--mono);
  }

  .knopf {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font: 600 0.8rem var(--sans);
    background: var(--accent);
    border: 1px solid var(--accent);
    border-radius: 8px;
    padding: 0.4rem 0.9rem;
    color: #14100a;
    text-decoration: none;
    cursor: pointer;
  }
</style>
