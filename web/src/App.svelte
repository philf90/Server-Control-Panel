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
  import SystembenutzerSeite from "./seiten/Systembenutzer.svelte";
  import PanelzugaengeSeite from "./seiten/Panelzugaenge.svelte";
  import KontoSeite from "./seiten/Konto.svelte";
  import ZertifikatSeite from "./seiten/Zertifikat.svelte";
  import PanelupdateSeite from "./seiten/Panelupdate.svelte";
  import ZeitplaeneSeite from "./seiten/Zeitplaene.svelte";
  import TokensSeite from "./seiten/Tokens.svelte";
  import DockerSeite from "./seiten/Docker.svelte";
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
<Befehlspalette istOwner={sitzung?.ist_owner ?? false} />

{#if abgemeldet}
  <div class="mitte">
    <p>{t.fehler.abgemeldet}</p>
    <a class="knopf" href="/login">Zur Anmeldung</a>
  </div>
{:else}
  <div class="schale">
    <Statusband name={uebersicht?.name ?? ""} uptime={uebersicht?.snapshot?.uptime ?? ""} />
    <Seitenleiste istOwner={sitzung?.ist_owner ?? false} />

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
      {:else if weg.seite === "benutzer"}
        <SystembenutzerSeite darfSchreiben={sitzung?.darf_schreiben ?? false} />
      {:else if weg.seite === "zugaenge"}
        <!-- Die Panel-Zugänge liegen hinter der Owner-Rolle, und zwar auf dem
             Server: Jede der sieben Routen antwortet sonst 403. Der Menüpunkt
             fehlt für andere Rollen; wer den Pfad trotzdem aufruft, bekommt hier
             die Ladefehlermeldung der Seite mit dem Satz des Servers darin. Eine
             zweite Rollenprüfung an dieser Stelle wäre die Stelle, an der beide
             Listen auseinanderlaufen. -->
        <PanelzugaengeSeite darfSchreiben={sitzung?.darf_schreiben ?? false} />
      {:else if weg.seite === "tokens"}
        <!-- Die Tokenseite liegt hinter der Owner-Rolle, und zwar auf dem Server:
             Alle drei Routen antworten sonst 403. Der Hostname geht mit, weil der
             Dialog mit dem frischen Token gleich den fertigen curl-Aufruf zeigt —
             an genau dem Punkt, an dem man das Geheimnis in der Hand hält, soll
             niemand eine Dokumentation suchen müssen. -->
        <TokensSeite host={uebersicht?.host?.fqdn || uebersicht?.host?.hostname || ""} />
      {:else if weg.seite === "zeitplaene"}
        <!-- Zeitpläne: Lesen darf jede Rolle, Schreiben nur der Owner. Die Seite
             holt das aus ihrer eigenen Antwort (rahmen.darf_aendern) und nicht aus
             der Sitzung: Ein Cron-Eintrag ist eine Shell-Zeile, und wer einen
             anlegen darf, ist eine engere Frage als „darf schreiben". -->
        <ZeitplaeneSeite />
      {:else if weg.seite === "docker"}
        <!-- Docker: Lesen darf jede Rolle, bedienen nur der Owner. Die Seite
             holt das aus ihrer eigenen Antwort (darf_aendern) und nicht aus der
             Sitzung — „darf schreiben" ist hier die falsche Frage: Ein Container
             mit Zugriff auf das Wirtsdateisystem ist root auf dem Server. -->
        <DockerSeite />
      {:else if weg.seite === "zertifikate"}
        <ZertifikatSeite darfSchreiben={sitzung?.darf_schreiben ?? false} />
      {:else if weg.seite === "panelupdate"}
        <!-- Die Updateseite holt ihre Rechte aus ihrer eigenen Antwort: Nur die
             Owner-Rolle darf auslösen, und diese Regel steht auf dem Server. -->
        <PanelupdateSeite />
      {:else if weg.seite === "konto"}
        <!-- Die Kontoseite bekommt keine Rechte-Eigenschaft: Sein EIGENES Konto
             verwaltet jede Rolle, auch „readonly". Was sie braucht, holt sie
             selbst; die Schranke ist das aktuelle Passwort und nicht die Rolle. -->
        <KontoSeite />
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
