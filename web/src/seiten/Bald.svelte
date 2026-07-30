<script lang="ts">
  // Die Seite für ein Modul, das es noch nicht gibt.
  //
  // Sie ist der Ersatz für eine Warze: Cron, Docker, Webserver, Datenbanken und
  // Backups stehen im Menü, weil sie zum Leitbild gehören — aber sie zeigten bis
  // 0.4.0-rc.2 auf /v2/ und landeten stillschweigend auf der Übersicht. Ein
  // Klick auf „Docker", der die Startseite bringt, sieht wie ein Fehler aus, und
  // in einem Panel ist „sieht wie ein Fehler aus" nicht harmlos: Es ist die
  // Stelle, an der man anfängt, der Oberfläche nicht mehr zu glauben.
  //
  // Was hier steht, ist deshalb keine Vertröstung, sondern eine Auskunft: welches
  // Modul, mit welcher Fassung, und was heute an seiner Stelle geht.
  import { angekuendigt, verweis, weg } from "../lib/weg.svelte";
  import { alleZiele } from "../lib/ziele";
  import { t } from "../lib/texte";

  const ziel = $derived(alleZiele.find((z) => z.id === weg.modul));
  const fassung = $derived(angekuendigt[weg.modul] ?? "");

  // Was heute an seiner Stelle geht. Nicht für jedes Modul gibt es das, und dann
  // steht hier nichts — ein erfundener Ersatzweg wäre schlimmer als keiner.
  const heute: Record<string, { text: string; href: string; label: string }> = {
    cron: {
      text: "Systemd-Timer sind Units: Ihren Zustand und ihre letzte Ausführung zeigt heute schon die Dienstliste.",
      href: "/v2/dienste",
      label: t.ziele.dienste,
    },
    docker: {
      text: "Läuft dockerd als Dienst, sind Zustand und Journal dieses Dienstes schon sichtbar.",
      href: "/v2/dienste",
      label: t.ziele.dienste,
    },
    webserver: {
      text: "Konfigurationsdateien lassen sich heute über die Dateien bearbeiten, und der Dienst darüber neu laden.",
      href: "/v2/dateien",
      label: t.ziele.dateien,
    },
    datenbanken: {
      text: "Der Datenbankdienst selbst — Zustand, Speicher, Journal — steht in der Dienstliste.",
      href: "/v2/dienste",
      label: t.ziele.dienste,
    },
    backups: {
      text: "Bis dahin ist eine Sicherung Handarbeit. Was das Panel selbst sichert, steht in der Ausrollanleitung im Repository.",
      href: "/v2/dateien",
      label: t.ziele.dateien,
    },
  };
  const ersatz = $derived(heute[weg.modul]);
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{ziel?.gruppe ?? ""}</div>
    <div class="h1">{ziel?.label ?? weg.modul}</div>
  </div>
  <div class="schub"></div>
  {#if fassung}
    <span class="marke">{t.bald.ab(fassung)}</span>
  {/if}
</div>

<div class="platte">
  <p class="satz">{t.bald.satz(ziel?.label ?? weg.modul, fassung)}</p>
  <p class="detail">{t.bald.warum}</p>

  {#if ersatz}
    <div class="ersatz">
      <b>{t.bald.heute}</b>
      <p>{ersatz.text}</p>
      <a class="knopf leise" href={ersatz.href} onclick={(e) => verweis(e, ersatz.href)}>
        {t.bald.zu(ersatz.label)}
      </a>
    </div>
  {/if}
</div>

<style>
  .platte {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 1.4rem 1.5rem;
    max-width: 46rem;
    display: grid;
    gap: 0.8rem;
  }

  .satz {
    font-size: 0.95rem;
    color: var(--tx);
  }

  .detail {
    color: var(--tx-mut);
    font-size: 0.84rem;
    line-height: 1.6;
  }

  .ersatz {
    border-top: 1px solid var(--line);
    padding-top: 0.9rem;
    display: grid;
    gap: 0.5rem;
    justify-items: start;
  }

  .ersatz b {
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
  }

  .ersatz p {
    color: var(--tx-mut);
    font-size: 0.84rem;
    line-height: 1.6;
  }
</style>
