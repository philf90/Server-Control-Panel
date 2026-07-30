<script lang="ts">
  // Vier Gruppen statt einer Liste gleichrangiger Punkte: System, Apps,
  // Sicherheit, Betrieb. Der Zuschnitt kommt aus docs/16-neukonzeption.md 8.2
  // und trägt die Module, die in 0.6 bis 0.9 dazukommen.
  //
  // Solange die neue Oberfläche neben der alten läuft, zeigen die Ziele ohne
  // eigene Seite auf die alte unter / — kein toter Verweis, und der Weg zurück
  // ist immer da.
  import { t } from "../lib/texte";

  let { aktiv = "uebersicht" }: { aktiv?: string } = $props();

  type Ziel = { id: string; label: string; symbol: string; href: string };

  const gruppen: { titel: string; ziele: Ziel[] }[] = [
    {
      titel: t.bereiche.system,
      ziele: [
        { id: "uebersicht", label: t.ziele.uebersicht, symbol: "messuhr", href: "/v2/" },
        { id: "dienste", label: t.ziele.dienste, symbol: "zahnrad", href: "/services" },
        { id: "pakete", label: t.ziele.pakete, symbol: "kiste", href: "/packages" },
        { id: "cron", label: t.ziele.cron, symbol: "uhr", href: "/v2/" },
      ],
    },
    {
      titel: t.bereiche.apps,
      ziele: [
        { id: "docker", label: t.ziele.docker, symbol: "container", href: "/v2/" },
        { id: "webserver", label: t.ziele.webserver, symbol: "globus", href: "/v2/" },
        { id: "datenbanken", label: t.ziele.datenbanken, symbol: "datenbank", href: "/v2/" },
        { id: "backups", label: t.ziele.backups, symbol: "archiv", href: "/v2/" },
      ],
    },
    {
      titel: t.bereiche.sicherheit,
      ziele: [
        { id: "firewall", label: t.ziele.firewall, symbol: "schild", href: "/firewall" },
        { id: "benutzer", label: t.ziele.benutzer, symbol: "personen", href: "/system-users" },
        { id: "zertifikate", label: t.ziele.zertifikate, symbol: "siegel", href: "/certificate" },
      ],
    },
    {
      titel: t.bereiche.betrieb,
      ziele: [
        { id: "dateien", label: t.ziele.dateien, symbol: "ordner", href: "/files" },
        { id: "logs", label: t.ziele.logs, symbol: "zeilen", href: "/logs" },
        { id: "audit", label: t.ziele.audit, symbol: "buch", href: "/audit" },
        { id: "einstellungen", label: t.ziele.einstellungen, symbol: "regler", href: "/settings" },
      ],
    },
  ];
</script>

<aside class="seitenleiste">
  {#each gruppen as gruppe (gruppe.titel)}
    <div class="gruppe">
      <b>{gruppe.titel}</b>
      <nav>
        {#each gruppe.ziele as ziel (ziel.id)}
          <a
            href={ziel.href}
            class:an={ziel.id === aktiv}
            aria-current={ziel.id === aktiv ? "page" : undefined}
          >
            <svg aria-hidden="true"><use href="#sym-{ziel.symbol}" /></svg>
            <span>{ziel.label}</span>
          </a>
        {/each}
      </nav>
    </div>
  {/each}
</aside>

<style>
  .seitenleiste {
    background: var(--surface);
    border-right: 1px solid var(--line);
    padding: 0.9rem 0 0.6rem;
    overflow-y: auto;
  }

  .gruppe {
    margin-bottom: 1rem;
  }

  .gruppe > b {
    display: block;
    font-size: 0.64rem;
    font-weight: 650;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--tx-faint);
    padding: 0 1rem 0.35rem;
  }

  nav {
    display: flex;
    flex-direction: column;
  }

  a {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    color: var(--tx-mut);
    text-decoration: none;
    font-size: 0.85rem;
    padding: 0.4rem 1rem;
    border-left: 2px solid transparent;
  }

  a svg {
    width: 16px;
    height: 16px;
    flex: none;
    opacity: 0.85;
  }

  a:hover {
    color: var(--tx);
    background: var(--surface2);
  }

  a.an {
    color: var(--accent);
    border-left-color: var(--accent);
    background: linear-gradient(90deg, rgba(232, 163, 61, 0.1), transparent);
  }

  /* Schmal wird aus der Leiste eine Symbolschiene. Die Beschriftung verschwindet
   * für das Auge, bleibt aber für Vorleseprogramme stehen. */
  @media (max-width: 900px) {
    .gruppe > b {
      display: none;
    }

    a span {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip-path: inset(50%);
      white-space: nowrap;
    }

    a {
      justify-content: center;
      padding: 0.5rem 0;
    }
  }
</style>
