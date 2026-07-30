// Die Navigationsziele des Panels — an einer Stelle.
//
// Sie standen zuerst in Seitenleiste.svelte. Mit der Befehlspalette gibt es
// einen zweiten Leser, und zwei Listen desselben Menüs laufen auseinander:
// Ein neues Modul erscheint dann in der Leiste, aber nicht in der Suche, und
// niemandem fällt auf, warum es sich nicht finden lässt.
//
// Solange die neue Oberfläche neben der alten läuft, gibt es drei Arten von
// Zielen, und der Unterschied gehört in die Adresse:
//
//   * gebaut (neu: true) — eigene Seite unter /v2/….
//   * vorhanden, aber noch nicht übertragen — Verweis auf die alte Oberfläche
//     unter /. Kein toter Verweis, und der Weg zurück ist immer da.
//   * angekündigt — ein Modul, das es noch nicht gibt (Cron, Docker,
//     Webserver, Datenbanken, Backups). Sie zeigten bis 0.4.0-rc.2 auf /v2/ und
//     landeten stillschweigend auf der Übersicht; das sah wie ein Fehler aus.
//     Jetzt haben sie einen eigenen Pfad und eine Seite, die sagt, mit welcher
//     Fassung sie kommen (lib/weg.svelte.ts, `angekuendigt`).
//
// Mit dem Umschalten wandern die href-Werte der zweiten Art auf /v2-Pfade —
// dann steht die Änderung an genau dieser Stelle.

import { t } from "./texte";

export type Ziel = {
  id: string;
  label: string;
  symbol: string;
  href: string;
  /** Der Bereich, unter dem das Ziel in der Seitenleiste steht. */
  gruppe: string;
  /** Schon in der neuen Oberfläche gebaut? Die Palette sagt es dazu, damit
   *  niemand rätselt, warum eine Seite anders aussieht als die davor. */
  neu?: boolean;
  /** Wörter, unter denen jemand sucht, die aber nicht im Namen stehen.
   *
   *  Das ist der Unterschied zwischen einer Palette und einer Liste: Wer
   *  „nginx" tippt, denkt an den Webserver, und wer „ssl" tippt, sucht das
   *  Zertifikat. Ohne diese Wörter findet die Suche nur, was man schon weiß. */
  auch?: string[];
};

export type Gruppe = { titel: string; ziele: Ziel[] };

export const gruppen: Gruppe[] = [
  {
    titel: t.bereiche.system,
    ziele: [
      {
        id: "uebersicht",
        label: t.ziele.uebersicht,
        symbol: "messuhr",
        href: "/v2/",
        gruppe: t.bereiche.system,
        neu: true,
        auch: ["dashboard", "start", "telemetrie", "cpu", "speicher", "last", "netz"],
      },
      {
        id: "dienste",
        label: t.ziele.dienste,
        symbol: "zahnrad",
        href: "/v2/dienste",
        gruppe: t.bereiche.system,
        neu: true,
        auch: ["systemd", "units", "service", "neustart"],
      },
      {
        id: "pakete",
        label: t.ziele.pakete,
        symbol: "kiste",
        href: "/v2/pakete",
        gruppe: t.bereiche.system,
        neu: true,
        auch: ["apt", "updates", "aktualisierung", "upgrade", "sicherheitsupdates"],
      },
      {
        id: "cron",
        label: t.ziele.cron,
        symbol: "uhr",
        href: "/v2/cron",
        gruppe: t.bereiche.system,
        auch: ["zeitplan", "crontab", "timer", "geplant"],
      },
    ],
  },
  {
    titel: t.bereiche.apps,
    ziele: [
      {
        id: "docker",
        label: t.ziele.docker,
        symbol: "container",
        href: "/v2/docker",
        gruppe: t.bereiche.apps,
        auch: ["container", "compose", "stack", "image", "podman"],
      },
      {
        id: "webserver",
        label: t.ziele.webserver,
        symbol: "globus",
        href: "/v2/webserver",
        gruppe: t.bereiche.apps,
        auch: ["nginx", "caddy", "vhost", "site", "domain", "proxy"],
      },
      {
        id: "datenbanken",
        label: t.ziele.datenbanken,
        symbol: "datenbank",
        href: "/v2/datenbanken",
        gruppe: t.bereiche.apps,
        auch: ["mysql", "mariadb", "postgres", "postgresql", "dump", "sql"],
      },
      {
        id: "backups",
        label: t.ziele.backups,
        symbol: "archiv",
        href: "/v2/backups",
        gruppe: t.bereiche.apps,
        auch: ["restic", "sicherung", "restore", "wiederherstellen"],
      },
    ],
  },
  {
    titel: t.bereiche.sicherheit,
    ziele: [
      {
        id: "firewall",
        label: t.ziele.firewall,
        symbol: "schild",
        href: "/v2/firewall",
        gruppe: t.bereiche.sicherheit,
        neu: true,
        auch: ["ufw", "nftables", "port", "regel", "freigabe"],
      },
      {
        id: "benutzer",
        label: t.ziele.benutzer,
        symbol: "personen",
        href: "/v2/benutzer",
        gruppe: t.bereiche.sicherheit,
        neu: true,
        auch: ["ssh", "schluessel", "key", "authorized_keys", "systembenutzer", "konten"],
      },
      {
        id: "zertifikate",
        label: t.ziele.zertifikate,
        symbol: "siegel",
        href: "/certificate",
        gruppe: t.bereiche.sicherheit,
        auch: ["tls", "ssl", "acme", "lets encrypt", "letsencrypt", "https"],
      },
    ],
  },
  {
    titel: t.bereiche.betrieb,
    ziele: [
      {
        id: "dateien",
        label: t.ziele.dateien,
        symbol: "ordner",
        href: "/v2/dateien",
        gruppe: t.bereiche.betrieb,
        neu: true,
        auch: ["dateimanager", "editor", "upload", "pfad", "verzeichnis"],
      },
      {
        id: "logs",
        label: t.ziele.logs,
        symbol: "zeilen",
        href: "/v2/logs",
        gruppe: t.bereiche.betrieb,
        neu: true,
        auch: ["journal", "journalctl", "protokoll", "meldungen"],
      },
      {
        id: "audit",
        label: t.ziele.audit,
        symbol: "buch",
        href: "/v2/audit",
        gruppe: t.bereiche.betrieb,
        neu: true,
        auch: ["revision", "wer", "nachvollziehen", "verlauf"],
      },
      {
        id: "einstellungen",
        label: t.ziele.einstellungen,
        symbol: "regler",
        href: "/users",
        gruppe: t.bereiche.betrieb,
        auch: ["panel", "konto", "rollen", "port", "update", "token"],
      },
    ],
  },
];

/** alleZiele ist die flache Liste für die Suche. */
export const alleZiele: Ziel[] = gruppen.flatMap((g) => g.ziele);

/** normal macht Suchbegriff und Ziel vergleichbar: Kleinbuchstaben, Umlaute
 *  aufgelöst. Wer „zertifikate" ohne Umlaut oder „ubersicht" tippt, soll finden,
 *  was er meint — sonst ist die Suche eine Rechtschreibprüfung. */
export function normal(s: string): string {
  return s
    .toLowerCase()
    .replaceAll("ä", "a")
    .replaceAll("ö", "o")
    .replaceAll("ü", "u")
    .replaceAll("ß", "ss");
}

/** suche filtert die Ziele. Leerer Begriff heißt: alle, in Menüreihenfolge.
 *
 *  Bewertet wird in drei Stufen, damit das Naheliegende oben steht: Der Name
 *  beginnt mit dem Begriff, der Name enthält ihn, oder eines der Suchwörter
 *  passt. Ohne diese Ordnung stünde bei „da" die Datenbank über den Dateien —
 *  oder umgekehrt, je nach Menüreihenfolge, und das wäre Zufall. */
export function suche(begriff: string, ziele: Ziel[] = alleZiele): Ziel[] {
  const b = normal(begriff.trim());
  if (!b) return ziele;

  const bewertet: { ziel: Ziel; rang: number }[] = [];
  for (const ziel of ziele) {
    const label = normal(ziel.label);
    let rang = -1;
    if (label.startsWith(b)) {
      rang = 0;
    } else if (label.includes(b)) {
      rang = 1;
    } else if (ziel.auch?.some((w) => normal(w).includes(b))) {
      rang = 2;
    } else if (normal(ziel.gruppe).includes(b)) {
      rang = 3;
    }
    if (rang >= 0) bewertet.push({ ziel, rang });
  }

  // Stabil sortieren: Bei gleichem Rang bleibt die Menüreihenfolge.
  return bewertet.sort((x, y) => x.rang - y.rang).map((e) => e.ziel);
}
