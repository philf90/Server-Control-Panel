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
