// Die Navigationsziele des Panels — an einer Stelle.
//
// Sie standen zuerst in Seitenleiste.svelte. Mit der Befehlspalette gibt es
// einen zweiten Leser, und zwei Listen desselben Menüs laufen auseinander:
// Ein neues Modul erscheint dann in der Leiste, aber nicht in der Suche, und
// niemandem fällt auf, warum es sich nicht finden lässt.
//
// Es gibt zwei Arten von Zielen, und der Unterschied gehört in die Adresse:
//
//   * gebaut (neu: true) — eine eigene Seite.
//   * angekündigt — ein Modul, das es noch nicht gibt (Docker, Webserver,
//     Datenbanken, Backups). Sie zeigten bis 0.4.0-rc.2 auf / und landeten
//     stillschweigend auf der Übersicht; das sah wie ein Fehler aus. Jetzt haben
//     sie einen eigenen Pfad und eine Seite, die sagt, mit welcher Fassung sie
//     kommen (lib/weg.svelte.ts, `angekuendigt`).
//
// Es war einmal eine dritte Art: „vorhanden, aber noch nicht übertragen" —
// Verweise in die alte Oberfläche für Module, die es hier noch nicht gab. Mit dem
// Umschalten (0.4.0) fiel sie weg, weil alle Ziele gebaut waren; mit dem Abbau
// (0.4.1) gibt es das Ziel nicht mehr, auf das sie gezeigt hätte.

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
  /** nurOwner blendet das Ziel für andere Rollen aus — Menü und Palette.
   *
   *  Das ist Bedienhilfe und keine Sicherheitsmaßnahme: Die Route selbst liegt
   *  hinter apiOwner und antwortet 403, egal was hier steht. Aber ein Menüpunkt,
   *  der zuverlässig „vorbehalten" sagt, ist kein Menüpunkt — er ist eine
   *  Einladung, es trotzdem zu versuchen. Die alte Oberfläche hielt es genauso
   *  ({{if .IsOwner}} in ihrer Symbolschiene). */
  nurOwner?: boolean;
  /** Wörter, unter denen jemand sucht, die aber nicht im Namen stehen.
   *
   *  Das ist der Unterschied zwischen einer Palette und einer Liste: Wer
   *  „nginx" tippt, denkt an den Webserver, und wer „ssl" tippt, sucht das
   *  Zertifikat. Ohne diese Wörter findet die Suche nur, was man schon weiß. */
  auch?: string[];
  /** kinder sind die Flächen innerhalb eines Moduls, jede mit eigenem Pfad.
   *
   *  Warum überhaupt: Die Docker-Seite trug bis 0.5.1 sechs Abschnitte
   *  untereinander. Auf einem betriebsüblichen Server sind das rund dreizehn
   *  Bildschirme — und schlimmer als die Länge ist, dass jeder Abschnitt beim
   *  Öffnen seine eigenen docker-Aufrufe macht. Wer einen Container neu starten
   *  will, bezahlt sonst den ganzen Bestand mit.
   *
   *  Warum in dieser Datei und nicht als Reiterleiste in der Seite: Ein Kind ist
   *  ein Ziel wie jedes andere — es hat eine Adresse, es steht in der
   *  Seitenleiste, und die Befehlspalette findet es. Eine Reiterleiste wäre eine
   *  zweite Navigation, die keine der drei Eigenschaften hätte.
   *
   *  Aufgeklappt wird NICHT von Hand: Die Kinder erscheinen, solange man im
   *  Modul ist, und verschwinden danach. Ein Umschalter wäre ein zweiter
   *  Zustand, und sein schlechter Fall wäre ein zugeklapptes Modul, in dem man
   *  gerade steht — nichts hervorgehoben, nichts zu sehen.
   *
   *  Das Kind mit dem Pfad des Elternteils ist die Vorgabe des Moduls. Es steht
   *  ausdrücklich da, statt sich aus der Abwesenheit zu ergeben: „Stacks" ist
   *  eine Fläche mit einem Namen und nicht „Docker ohne Zusatz". */
  kinder?: Ziel[];
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
        href: "/",
        gruppe: t.bereiche.system,
        neu: true,
        auch: ["dashboard", "start", "telemetrie", "cpu", "speicher", "last", "netz"],
      },
      {
        id: "dienste",
        label: t.ziele.dienste,
        symbol: "zahnrad",
        href: "/dienste",
        gruppe: t.bereiche.system,
        neu: true,
        auch: ["systemd", "units", "service", "neustart"],
      },
      {
        id: "pakete",
        label: t.ziele.pakete,
        symbol: "kiste",
        href: "/pakete",
        gruppe: t.bereiche.system,
        neu: true,
        auch: ["apt", "updates", "aktualisierung", "upgrade", "sicherheitsupdates"],
      },
      {
        id: "cron",
        label: t.ziele.cron,
        symbol: "uhr",
        href: "/cron",
        gruppe: t.bereiche.system,
        neu: true,
        auch: ["zeitplan", "crontab", "timer", "geplant", "nachts", "regelmaessig"],
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
        href: "/docker",
        gruppe: t.bereiche.apps,
        neu: true,
        auch: ["container", "compose", "stack", "image", "podman"],
        kinder: [
          {
            id: "docker/",
            label: t.ziele.dockerStacks,
            symbol: "container",
            href: "/docker",
            gruppe: t.ziele.docker,
            neu: true,
            auch: ["compose", "projekt", "yaml"],
          },
          {
            id: "docker/container",
            label: t.ziele.dockerContainer,
            symbol: "container",
            href: "/docker/container",
            gruppe: t.ziele.docker,
            neu: true,
            auch: ["logs", "statistik", "neustart"],
          },
          {
            id: "docker/ports",
            label: t.ziele.dockerPorts,
            symbol: "container",
            href: "/docker/ports",
            gruppe: t.ziele.docker,
            neu: true,
            auch: ["veroeffentlicht", "firewall", "erreichbar", "ufw"],
          },
          {
            id: "docker/updates",
            label: t.ziele.dockerUpdates,
            symbol: "container",
            href: "/docker/updates",
            gruppe: t.ziele.docker,
            neu: true,
            auch: ["aktualitaet", "registry", "digest", "neuere fassung"],
          },
          {
            id: "docker/bestand",
            label: t.ziele.dockerBestand,
            symbol: "container",
            href: "/docker/bestand",
            gruppe: t.ziele.docker,
            neu: true,
            auch: ["images", "volumes", "netze", "platte", "aufraeumen", "prune"],
          },
        ],
      },
      {
        id: "webserver",
        label: t.ziele.webserver,
        symbol: "globus",
        href: "/webserver",
        gruppe: t.bereiche.apps,
        neu: true,
        // "caddy" bleibt als Suchwort stehen, obwohl das Panel Caddy nicht
        // verwaltet: Wer danach sucht, soll die Seite finden — dort steht dann
        // die ehrliche Antwort, nämlich welcher Webserver läuft und dass das
        // Panel ihn nicht anfasst. Ein Suchwort, das ins Leere führt, wäre die
        // schlechtere der beiden Auskünfte. Dasselbe gilt für apache.
        auch: ["nginx", "caddy", "apache", "vhost", "site", "domain", "proxy", "port 80"],
      },
      {
        id: "datenbanken",
        label: t.ziele.datenbanken,
        symbol: "datenbank",
        href: "/datenbanken",
        gruppe: t.bereiche.apps,
        auch: ["mysql", "mariadb", "postgres", "postgresql", "dump", "sql"],
      },
      {
        id: "backups",
        label: t.ziele.backups,
        symbol: "archiv",
        href: "/backups",
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
        href: "/firewall",
        gruppe: t.bereiche.sicherheit,
        neu: true,
        auch: ["ufw", "nftables", "port", "regel", "freigabe"],
      },
      {
        id: "benutzer",
        label: t.ziele.benutzer,
        symbol: "personen",
        href: "/benutzer",
        gruppe: t.bereiche.sicherheit,
        neu: true,
        auch: ["ssh", "schluessel", "key", "authorized_keys", "systembenutzer", "konten"],
      },
      {
        // Direkt neben „Benutzer & SSH", und das ist Absicht: Die beiden
        // Kontenarten sind die häufigste Verwechslung im Panel, und zwei
        // Menüpunkte nebeneinander sagen mehr über den Unterschied als jeder
        // Erklärsatz auf einer der beiden Seiten.
        id: "zugaenge",
        label: t.ziele.zugaenge,
        symbol: "schluessel",
        href: "/zugaenge",
        gruppe: t.bereiche.sicherheit,
        neu: true,
        nurOwner: true,
        auch: ["panel", "rollen", "owner", "admin", "passkey", "2fa", "totp", "sperren"],
      },
      {
        id: "tokens",
        label: t.ziele.tokens,
        symbol: "marke",
        href: "/tokens",
        gruppe: t.bereiche.sicherheit,
        neu: true,
        nurOwner: true,
        auch: ["api", "token", "bearer", "skript", "automatisierung", "cli"],
      },
      {
        id: "zertifikate",
        label: t.ziele.zertifikate,
        symbol: "siegel",
        href: "/zertifikate",
        gruppe: t.bereiche.sicherheit,
        neu: true,
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
        href: "/dateien",
        gruppe: t.bereiche.betrieb,
        neu: true,
        auch: ["dateimanager", "editor", "upload", "pfad", "verzeichnis"],
      },
      {
        id: "logs",
        label: t.ziele.logs,
        symbol: "zeilen",
        href: "/logs",
        gruppe: t.bereiche.betrieb,
        neu: true,
        auch: ["journal", "journalctl", "protokoll", "meldungen"],
      },
      {
        id: "audit",
        label: t.ziele.audit,
        symbol: "buch",
        href: "/audit",
        gruppe: t.bereiche.betrieb,
        neu: true,
        auch: ["revision", "wer", "nachvollziehen", "verlauf"],
      },
      {
        // Hieß bis 0.4.0-rc.3 „Einstellungen" und führte auf /users — die
        // Kontenliste. Der Name versprach etwas anderes, als dahinter stand, und
        // seit es „Panel-Zugänge" gibt, wäre er auch noch doppelt. Was bleibt, ist
        // das eigene Konto: Passwort, zweiter Faktor, Passkeys, Sitzungen.
        //
        // Jede Rolle sieht diesen Punkt: Sein eigenes Konto verwaltet jeder.
        id: "konto",
        label: t.ziele.konto,
        symbol: "person",
        href: "/konto",
        gruppe: t.bereiche.betrieb,
        neu: true,
        auch: ["passwort", "2fa", "totp", "passkey", "sitzungen", "abmelden", "profil"],
      },
      {
        // Die Updates des PANELS, nicht die des Systems — die stehen unter
        // „Pakete". Der Punkt stand bis 0.4.0-rc.3 nur in der Schiene der alten
        // Oberfläche und hatte in der neuen keinen; die Suchwörter halten die
        // beiden auseinander.
        id: "updates",
        label: t.ziele.update,
        symbol: "pfeil-hoch",
        href: "/updates",
        gruppe: t.bereiche.betrieb,
        neu: true,
        auch: ["selbstupdate", "panel", "fassung", "version", "rollback", "rückweg", "signatur"],
      },
    ],
  },
];

/** alleZiele ist die flache Liste für die Suche — Module UND ihre Flächen.
 *
 *  Die Kinder gehören hier hinein und nicht nur in die Seitenleiste: Sonst
 *  fände ⌘K „Bestand" nicht, und die Palette wäre wieder das, was sie nicht sein
 *  soll — eine zweite, unvollständige Fassung des Menüs. Genau das begründet der
 *  Kopf dieser Datei. */
export const alleZiele: Ziel[] = gruppen.flatMap((g) =>
  g.ziele.flatMap((z) => [z, ...(z.kinder ?? [])]),
);

/** kindZu findet die Fläche zu einem Modul und einer Unterseite.
 *
 *  Leere Unterseite heißt: das Kind mit dem Pfad des Moduls, also die Vorgabe.
 *  Gibt es zu dem Segment kein Kind, kommt undefined zurück — die Seite
 *  entscheidet dann, ob sie auf die Vorgabe fällt oder etwas anderes sagt. */
export function kindZu(modul: string, unterseite: string): Ziel | undefined {
  const eltern = alleZiele.find((z) => z.id === modul);
  if (!eltern?.kinder) return undefined;
  return eltern.kinder.find((k) => k.id === modul + "/" + unterseite);
}

/** sichtbareZiele lässt weg, was die Rolle nicht erreicht.
 *
 *  Einmal hier und nicht in der Leiste UND in der Palette: Zwei Filter derselben
 *  Regel laufen auseinander, und der übersehene wäre die Palette — dort fällt ein
 *  Ziel zu viel niemandem auf, bis es angeklickt wird. */
export function sichtbareZiele(istOwner: boolean, ziele: Ziel[] = alleZiele): Ziel[] {
  return ziele.filter((z) => !z.nurOwner || istOwner);
}

/** sichtbareGruppen ist dasselbe für die Seitenleiste. Eine Gruppe, die dadurch
 *  leer wird, fällt mit weg — eine Überschrift ohne Punkte darunter sieht wie ein
 *  Fehler aus. */
export function sichtbareGruppen(istOwner: boolean): Gruppe[] {
  return gruppen
    .map((g) => ({
      titel: g.titel,
      // Die Kinder mitfiltern und nicht nur die Module: Ein Modul, das jede
      // Rolle sieht, kann eine Fläche haben, die nur der Owner erreicht. Heute
      // gibt es den Fall nicht — die Regel steht hier, damit der erste, der ihn
      // baut, ihn nicht erfinden muss.
      ziele: sichtbareZiele(istOwner, g.ziele).map((z) =>
        z.kinder ? { ...z, kinder: sichtbareZiele(istOwner, z.kinder) } : z,
      ),
    }))
    .filter((g) => g.ziele.length > 0);
}

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
