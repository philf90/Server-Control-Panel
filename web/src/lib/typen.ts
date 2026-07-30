// Die Formen, die der Server liefert. Sie spiegeln die JSON-Tags von
// internal/metrics und internal/httpd/api_v1.go — läuft eines von beiden
// weg, fällt es hier auf, sobald ein Feld gelesen wird.

export type CPU = {
  total: number;
  per_core: number[];
  iowait: number;
  steal: number;
};

export type Memory = {
  total: number;
  available: number;
  used: number;
  used_pct: number;
  swap_total: number;
  swap_used: number;
};

export type Filesystem = {
  mount: string;
  device: string;
  type: string;
  also_at?: string[];
  total: number;
  used: number;
  used_pct: number;
  inodes_used: number;
  inodes_pct: number;
};

export type Interface = {
  name: string;
  rx_bytes: number;
  tx_bytes: number;
  rx_rate: number;
  tx_rate: number;
  addrs: string[];
  physical: boolean;
  primary: boolean;
};

export type Process = {
  pid: number;
  name: string;
  user: string;
  cpu_pct: number;
  rss: number;
  rss_pct: number;
  command: string;
};

/** Snapshot ist die Nutzlast des Live-Kanals — unverändert dieselbe, die die
 *  alte Oberfläche liest. Der Kanal wird geteilt, nicht ersetzt. */
export type Snapshot = {
  at: string;
  cpu: CPU;
  memory: Memory;
  load: [number, number, number];
  uptime: string;
  filesystems: Filesystem[];
  interfaces: Interface[];
  top_processes: Process[];
};

export type Host = {
  hostname: string;
  fqdn: string;
  kernel: string;
  distro: string;
  cores: number;
  arch: string;
};

/** Befehl ist ein Eintrag des privops-Journals für die Protokollzeile. */
export type Befehl = {
  at: string;
  zeile: string;
  exit: number;
  dauer_text: string;
  gescheitert: boolean;
};

/** Wert ist ein Kachelwert: die große Zahl und die kleine Einheit daneben. */
export type Wert = {
  wert: string;
  einheit: string;
};

/** Sitzung ist die Antwort von GET /api/v1/session: wer angemeldet ist, was
 *  das Konto darf, und das CSRF-Token für schreibende Aufrufe. */
export type Sitzung = {
  benutzer: string;
  rolle: "owner" | "admin" | "readonly";
  darf_schreiben: boolean;
  ist_owner: boolean;
  csrf: string;
};

/** Uebersicht ist die Antwort von GET /api/v1/overview. */
export type Uebersicht = {
  host: Host;
  name: string;
  snapshot: Snapshot | null;
  /** Vom Server formatierte Kachelwerte — dort stehen Einheit, Rundung und
   *  Sprache an einer Stelle. */
  werte: {
    cpu: Wert;
    memory: Wert;
    load: Wert;
    netz: Wert;
  };
  netz_name: string;
  letzter_befehl: Befehl | null;
};

/** Urteil ist der Satz über dem Handlungsbedarf. Gezählt und formuliert wird
 *  auf dem Server, damit alte und neue Oberfläche denselben Satz sagen. */
export type Urteil = {
  level: "ok" | "warn";
  titel: string;
  sub: string;
};

/** Signal ist ein Punkt des Handlungsbedarfs. */
export type Signal = {
  level: "crit" | "warn";
  tag: string;
  titel: string;
  detail: string;
  aktion_label: string;
  aktion_href: string;
  vorrangig: boolean;
};

/** Signale ist die Antwort von GET /api/v1/signals. */
export type Signale = {
  urteil: Urteil;
  signale: Signal[];
};

/** Zustand ist der eine Wert, den die Liste einfärbt. Gebildet wird er auf dem
 *  Server aus zwei systemd-Feldern (Active und Sub) — damit die Liste, die
 *  Zähler und der Handlungsbedarf dieselben Dienste als gescheitert zählen. */
export type Zustand = "laeuft" | "gescheitert" | "aus";

/** Dienst ist eine Zeile der Dienstliste. */
export type Dienst = {
  unit: string;
  name: string;
  beschreibung: string;
  zustand: Zustand;
  aktiv: string;
  unterzustand: string;
  laden: string;
  autostart: string;
};

/** Logzeile ist eine Journalzeile im Inspektor. */
export type Logzeile = {
  at: string;
  stufe: string;
  nachricht: string;
  ernst: boolean;
};

/** DienstAktion ist eine der sechs erlaubten Aktionen aus der privops-Liste.
 *  Mehr gibt es nicht — es existiert kein Weg, eine beliebige
 *  systemctl-Unteraktion durchzureichen. */
export type DienstAktion = "start" | "stop" | "restart" | "reload" | "enable" | "disable";

/** DienstDetail ist die Antwort von GET /api/v1/services/{unit}. */
export type DienstDetail = Dienst & {
  seit: string;
  haupt_pid: number;
  speicher: string;
  speicher_bytes: number;
  aufgaben: number;
  unit_datei: string;
  logzeilen: Logzeile[];
  aktionen: DienstAktion[];
};

/** Dienste ist die Antwort von GET /api/v1/services. */
export type Dienste = {
  dienste: Dienst[];
  zaehler: {
    gesamt: number;
    laeuft: number;
    gescheitert: number;
    aus: number;
  };
};

/** Job ist ein langlaufender Vorgang: Paketlisten holen, Updates einspielen,
 *  später ein Abbild ziehen oder ein Backup prüfen.
 *
 *  laeuft und gescheitert sind zwei Felder und nicht ein Wort: „läuft noch" und
 *  „ist gescheitert" schließen sich aus, aber „fertig und geglückt" ist der
 *  Zustand, in dem beide falsch sind — und den will die Oberfläche ohne
 *  Fallunterscheidung erkennen. */
export type Job = {
  art: string;
  titel: string;
  akteur: string;
  laeuft: boolean;
  gescheitert: boolean;
  fehler: string;
  /** hinweis ist eine Anmerkung zum Ergebnis, die kein Fehler ist — der
   *  Teilerfolg von apt-get update etwa. */
  hinweis: string;
  zeilen: string[];
  start: string;
  dauer_text: string;
};

/** Paket ist eine Zeile der Paketliste. */
export type Paket = {
  name: string;
  von: string;
  nach: string;
  quelle: string;
  architektur: string;
  sicherheit: boolean;
};

/** Pakete ist die Antwort von GET /api/v1/packages. */
export type Pakete = {
  pakete: Paket[];
  zaehler: { gesamt: number; sicherheit: number };
  neustart: { erforderlich: boolean; pakete: string[] };
  /** job ist der laufende oder letzte Paketvorgang — in dieser Antwort, damit
   *  die Seite mit einem Aufruf vollständig ist. */
  job: Job | null;
  /** rechnername ist das Wort, das beim Neustart getippt werden muss. */
  rechnername: string;
  /** fehler ist gesetzt, wenn die Liste nicht zu lesen war. Die
   *  Neustartmarkierung und ein laufender Vorgang gelten trotzdem. */
  fehler: string;
};

/** Umfang eines Updates. Ein Wort statt zweier Wahrheitswerte — „alles und nur
 *  Sicherheit" wäre ein Zustand, den es nicht gibt. */
export type Umfang = "alle" | "sicherheit" | "einzeln";

/** VorgangGestartet ist die Antwort auf einen gestarteten Vorgang (202). */
export type VorgangGestartet = {
  meldung: string;
  job: Job;
};

/** Bestaetigung ist der Text einer Rückfrage, wie der Server sie stellt.
 *
 *  Sie kommt vom Server und wird nicht im Browser formuliert: Der Handler führt
 *  nichts aus, solange `bestaetigt` fehlt, und schickt stattdessen diesen Text.
 *  Damit steht die Frage einmal — dort, wo sie auch erzwungen wird. Siehe
 *  docs/14-bestaetigungen.md. */
export type Bestaetigung = {
  titel: string;
  frage: string;
  punkte: string[];
  knopf: string;
  /** Leer heißt Stufe 2: ein zweiter Klick genügt. Gefüllt heißt Stufe 3 — das
   *  Wort muss getippt werden. */
  tippen: string;
  tippen_hinweis: string;
  fehler?: string;
};

/** AktionAntwort ist die Antwort auf eine ausgeführte Aktion: die Meldung und
 *  der neu gelesene Zustand des Ziels. */
export type AktionAntwort = {
  meldung: string;
  detail: DienstDetail;
};

/** Punkt ist eine Stützstelle eines Verlaufs: Stelle im 100×34-Feld und die
 *  fertigen Texte für die Ablesung. Gerechnet wird auf dem Server. */
export type Punkt = {
  x: number;
  y: number;
  t: string;
  v: string;
};

export type Verlauf = {
  path: string;
  dot: string;
  points: Punkt[];
  has: boolean;
};

/** Verlaeufe ist die Antwort von GET /api/v1/metrics/history. */
export type Verlaeufe = {
  cpu: Verlauf;
  memory: Verlauf;
  load: Verlauf;
  netz: Verlauf;
};
