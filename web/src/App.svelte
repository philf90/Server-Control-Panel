<script lang="ts">
  // Die Schale: Statusband, Seitenleiste, Inhalt, Protokollzeile — auf jeder
  // Seite gleich. Grundsatz I aus docs/15-neuordnung.md: Der Zustand geht nie
  // weg, auch nicht beim Seitenwechsel.
  //
  // Welche Seite darin steht, sagt lib/weg.svelte.ts. Der Wechsel lädt die
  // Schale nicht neu: Statusband und Live-Kanal bleiben stehen, und das ist der
  // eigentliche Gewinn gegenüber der alten Oberfläche — die Zahlen oben
  // flackerten bei jedem Klick, weil jede Seite neu kam.
  import Seitenleiste from "./komponenten/Seitenleiste.svelte";
  import Statusband from "./komponenten/Statusband.svelte";
  import Protokollzeile from "./komponenten/Protokollzeile.svelte";
  import Symbolvorrat from "./komponenten/Symbolvorrat.svelte";
  import Befehlspalette from "./komponenten/Befehlspalette.svelte";
  import UebersichtSeite from "./seiten/Uebersicht.svelte";
  import DiensteSeite from "./seiten/Dienste.svelte";
  import PaketeSeite from "./seiten/Pakete.svelte";
  import LogsSeite from "./seiten/Logs.svelte";
  import FirewallSeite from "./seiten/Firewall.svelte";
  import DateienSeite from "./seiten/Dateien.svelte";
  import AuditSeite from "./seiten/Audit.svelte";
  import BaldSeite from "./seiten/Bald.svelte";
  import { AbgemeldetFehler, api } from "./lib/api";
  import { live } from "./lib/live.svelte";
  import { t } from "./lib/texte";
  import { weg } from "./lib/weg.svelte";
  import type { Signale, Sitzung, Uebersicht, Verlaeufe } from "./lib/typen";

  let sitzung = $state<Sitzung | null>(null);
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
      // brauchen, und sie ist die Stelle, an der eine abgelaufene Sitzung als
      // erstes auffällt. Sie bestimmt außerdem, ob die Module ihre Schaltknöpfe
      // zeigen — ein Konto mit Leserecht bekommt sie nicht angeboten.
      sitzung = await api.sitzung();
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
    <Seitenleiste />

    <main class="inhalt">
      {#if fehler}
        <div class="mitte">
          <p>{t.fehler.laden}</p>
          <p class="detail">{fehler}</p>
          <button class="knopf" onclick={laden}>{t.fehler.erneut}</button>
        </div>
      {:else if weg.seite === "dienste"}
        <!-- Die Dienstseite wartet nicht auf die Übersicht: Sie holt ihre eigene
             Liste, und die Sitzung ist das einzige, was sie von hier braucht.
             Bis die steht, sind die Knöpfe aus — nicht sichtbar und wirkungslos,
             sondern gar nicht da. -->
        <DiensteSeite darfSchreiben={sitzung?.darf_schreiben ?? false} />
      {:else if weg.seite === "pakete"}
        <PaketeSeite
          darfSchreiben={sitzung?.darf_schreiben ?? false}
          istOwner={sitzung?.ist_owner ?? false}
        />
      {:else if weg.seite === "logs"}
        <!-- Die Logseite braucht die Sitzung nicht: Lesen darf jede Rolle. -->
        <LogsSeite />
      {:else if weg.seite === "firewall"}
        <FirewallSeite darfSchreiben={sitzung?.darf_schreiben ?? false} />
      {:else if weg.seite === "dateien"}
        <DateienSeite darfSchreiben={sitzung?.darf_schreiben ?? false} />
      {:else if weg.seite === "audit"}
        <!-- Das Protokoll braucht die Sitzung nicht: Lesen darf jede Rolle, und
             verändern kann es niemand. -->
        <AuditSeite />
      {:else if weg.seite === "bald"}
        <!-- Ein Modul, das es noch nicht gibt. Es braucht nichts von hier — die
             Seite sagt nur, mit welcher Fassung es kommt. -->
        <BaldSeite />
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
</style>
