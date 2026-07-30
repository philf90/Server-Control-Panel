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

/** Logzeile ist eine Journalzeile.
 *
 *  Dieselbe Form im Dienst-Inspektor und auf der Logseite. Die Felder `zeit` und
 *  `at` stehen beide da: `zeit` ist die fertige Anzeige mit fester Breite, `at`
 *  der rohe Wert. Im Inspektor ist `at` schon die fertige Zeit — dort trug das
 *  Feld sie von Anfang an, und es umzubenennen hieße, die alte Fassung zu
 *  ändern. `zeit` ist deshalb optional. */
export type Logzeile = {
  at: string;
  zeit?: string;
  unit?: string;
  stufe: string;
  stufe_nr?: number;
  nachricht: string;
  ernst: boolean;
};

/** Logs ist die Antwort von GET /api/v1/logs. */
export type Logs = {
  zeilen: Logzeile[];
  units: string[];
  /** abfrage ist, was der Server verstanden hat — nicht, was gefragt wurde. Wer
   *  eine Grenze überschreitet, deren Deckel er nicht kennt, soll sehen, was
   *  tatsächlich gefragt wurde. */
  abfrage: {
    unit: string;
    stufe: number;
    seit: string;
    suche: string;
    anzahl: number;
  };
  fehler: string;
  /** folger_frei sagt, ob noch ein Strom offen sein darf. Damit kann die
   *  Oberfläche den Knopf gleich richtig zeigen, statt ihn anzubieten und
   *  abgewiesen zu werden. */
  folger_frei: boolean;
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

/** Regel ist eine Firewall-Regel für eingehenden Verkehr. */
export type Regel = {
  port: number;
  protokoll: string;
  quelle: string;
  notiz: string;
};

/** RegelZeile ist eine Regel mit dem, was die Oberfläche über sie wissen muss. */
export type RegelZeile = Regel & {
  /** fest heißt: Diese Regel darf nicht weg — es ist die des Panels. */
  fest: boolean;
  /** vorschlag heißt: Die Regel gibt es noch nicht, sie wäre aber sinnvoll —
   *  etwa der Port, auf dem sshd laut Konfiguration lauscht. */
  vorschlag: boolean;
  hinweis: string;
};

/** Probe ist die laufende Probezeit — Grundsatz VI.
 *
 *  rest_sekunden ist die Frist, wie der SERVER sie sieht. Der Browser zählt
 *  davon herunter, damit man sie sieht; verbindlich bleibt der Wächter im
 *  Server, und der läuft weiter, auch wenn niemand zusieht. */
export type Probe = {
  offen: boolean;
  gegenstand: string;
  rest_sekunden: number;
};

/** Firewall ist die Antwort von GET /api/v1/firewall. */
export type Firewall = {
  regelwerk: string;
  aktiv: boolean;
  verwaltet: boolean;
  installiert: boolean;
  anmerkung: string;
  zeilen: RegelZeile[];
  probe: Probe;
  panel_port: number;
  panel_port_offen: boolean;
  offene_zugaenge: string;
  rechnername: string;
  job: Job | null;
  fehler: string;
};

/** FirewallAntwort ist die Antwort auf eine Änderung: Meldung und der neu
 *  gelesene Zustand — dasselbe Muster wie bei den Diensten. */
export type FirewallAntwort = {
  meldung: string;
  zustand: Firewall;
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

// ------------------------------------------------------------------ Dateien ---

/** Dateiart ist das, womit man es zu tun hat. Die Unterscheidung ist mehr als
 *  Kosmetik: Nur eine reguläre Datei wird gelesen, bearbeitet oder
 *  heruntergeladen — ein open() auf eine FIFO blockiert unbegrenzt. Die Wörter
 *  kommen aus privops.FileKind. */
export type Dateiart = "datei" | "ordner" | "verweis" | "sonstiges";

/** Handgriff ist eine Aktion an einem Eintrag. Der Server sagt, welche passen;
 *  die Liste hier hält die Wörter fest, damit ein Tippfehler beim Vergleich
 *  auffällt und nicht still zu einem fehlenden Knopf wird. */
export type Handgriff =
  | "oeffnen"
  | "herunterladen"
  | "archiv"
  | "bearbeiten"
  | "umbenennen"
  | "kopieren"
  | "verschieben"
  | "rechte"
  | "loeschen"
  | "anlegen"
  | "hochladen";

/** Krume ist ein Glied des klickbaren Pfades. */
export type Krume = { name: string; path: string };

/** Eintrag ist eine Zeile der Dateiliste. Die Felder mit englischen Namen kommen
 *  unverändert aus privops.FileEntry — sie trägt ihre JSON-Namen selbst, und sie
 *  hier umzubenennen hieße, sie an zwei Stellen zu pflegen. */
export type Eintrag = {
  name: string;
  path: string;
  kind: Dateiart;
  size: number;
  mode_octal: string;
  mode_text: string;
  uid: number;
  gid: number;
  owner: string;
  group: string;
  mod_time: string;
  link_target?: string;
  link_broken?: boolean;
  /** gesperrt: Der Pfad steht auf der Sperrliste. Er ist sichtbar, sein Inhalt
   *  wird aber nie gelesen, geschrieben oder ausgeliefert. */
  sensitive?: boolean;
  sensitive_reason?: string;
  /** writable: Der Eintrag liegt unter einer Schreibwurzel. Nur dann bietet die
   *  Oberfläche verändernde Handgriffe an. */
  writable: boolean;
  groesse_text: string;
  geaendert_text: string;
  art: string;
};

/** Wurzelstand ist das Ergebnis der Selbstprüfung eines Schreibbereichs. */
export type Wurzelstand = {
  path: string;
  exists: boolean;
  writable: boolean;
  reason?: string;
};

/** Mass ist die Zählung unterhalb eines Pfades — die Grundlage der Rückfrage vor
 *  einem rekursiven Eingriff. */
export type Mass = {
  files: number;
  dirs: number;
  symlinks: number;
  bytes: number;
  /** gesperrt und mounts lehnen einen rekursiven Eingriff ab: Ein Löschen von
   *  /etc darf nicht /etc/shadow mitnehmen, und eines von /mnt nicht die
   *  eingehängte Platte leeren. */
  sensitive: number;
  mounts: number;
  truncated: boolean;
};

/** Dateiliste ist die Antwort von GET /api/v1/files — für ein Verzeichnis wie
 *  für ein Suchergebnis. Dieselbe Form für beides, weil die Oberfläche beides in
 *  derselben Tabelle zeigt. */
export type Dateiliste = {
  pfad: string;
  ordner: Eintrag;
  eltern: string;
  krumen: Krume[];
  wurzeln: string[];
  schreibwurzeln: string[];
  eintraege: Eintrag[];
  /** gesamt ist die Zahl der Einträge VOR der Kürzung. */
  gesamt: number;
  gekuerzt: boolean;
  gekuerzt_grund: string;
  /** suche trägt den Begriff, wenn die Liste ein Suchergebnis ist. */
  suche: string;
  sortierung: Sortierung;
  absteigend: boolean;
  versteckt: boolean;
  zaehler: {
    ordner: number;
    dateien: number;
    verweise: number;
    sonstiges: number;
    bytes: number;
    bytes_text: string;
    gesperrt: number;
  };
  frei: number;
  frei_text: string;
  frei_knapp: boolean;
  warnungen: Wurzelstand[];
  /** vorgang ist ein laufender oder gerade beendeter Dateivorgang. Er gehört in
   *  diese Antwort, weil ein rekursives Löschen die Liste ändert, während man
   *  sie ansieht. */
  vorgang: Job | null;
};

/** Sortierung der Liste. Verzeichnisse stehen immer vorn — das entscheidet der
 *  Server, nicht dieses Feld. */
export type Sortierung = "name" | "size" | "time";

/** Recht ist ein einzelnes Bit in Worten. */
export type Recht = { key: string; label: string; short: string; set: boolean };
export type Rechterolle = { key: string; label: string; text: string; rights: Recht[] };
export type Sonderbit = { key: string; label: string; set: boolean; text: string };

/** Rechte ist die Aufschlüsselung einer Rechteangabe. „0755" sagt nur denen
 *  etwas, die es ohnehin wissen. */
export type Rechte = {
  octal: string;
  symbolic: string;
  roles: Rechterolle[];
  specials: Sonderbit[];
};

/** Dateidetail ist die Antwort von GET /api/v1/files/entry. */
export type Dateidetail = {
  eintrag: Eintrag;
  ordner: string;
  krumen: Krume[];
  mass?: Mass;
  mass_text?: string;
  rechte: Rechte;
  benutzer: string[];
  gruppen: string[];
  schreibwurzeln: string[];
  aktionen: Handgriff[];
  max_edit: number;
  max_edit_text: string;
  max_upload: number;
  max_upload_text: string;
};

/** Ordnerzeile ist ein Unterverzeichnis in der Zielauswahl. */
export type Ordnerzeile = {
  name: string;
  pfad: string;
  beschreibbar: boolean;
  gesperrt: boolean;
};

/** Ordnerauswahl ist die Antwort von GET /api/v1/files/dirs — die Grundlage der
 *  Zielwahl beim Kopieren und Verschieben. Auswählbar ist nur, was dieser
 *  Endpunkt genannt hat; geprüft wird trotzdem serverseitig. */
export type Ordnerauswahl = {
  pfad: string;
  eltern: string;
  krumen: Krume[];
  beschreibbar: boolean;
  wurzeln: string[];
  ordner: Ordnerzeile[];
  gekuerzt: boolean;
};

/** Dateihandlung ist eine verändernde Handlung — der Endpunkt heißt so. */
export type Dateihandlung =
  | "mkdir"
  | "touch"
  | "rename"
  | "copy"
  | "move"
  | "delete"
  | "mode";

/** Dateiauftrag ist der Körper einer verändernden Anfrage. Ein Typ für alle
 *  Handlungen: Die Felder überschneiden sich fast vollständig, und was eine
 *  Handlung nicht braucht, bleibt leer. */
export type Dateiauftrag = {
  /** pfad ist das Ziel. Bei mkdir und touch das Verzeichnis, in dem angelegt
   *  wird; sonst der Eintrag selbst. */
  pfad: string;
  name: string;
  /** ziel ist das Zielverzeichnis beim Kopieren und Verschieben. */
  ziel: string;
  rechte: string;
  eigentuemer: string;
  gruppe: string;
  rekursiv: boolean;
};

/** Dateiantwort ist die Antwort auf eine ausgeführte Handlung. */
export type Dateiantwort = {
  meldung: string;
  /** eintrag ist der neu gelesene Zustand des Ziels. Fehlt nach dem Löschen —
   *  es gibt ihn dann nicht mehr. */
  eintrag?: Dateidetail;
  /** ordner ist der Ort, den die Liste danach zeigen soll. */
  ordner: string;
  /** vorgang ist gesetzt, wenn die Handlung im Hintergrund läuft (202). Dann
   *  steht der Zustand des Ziels erst fest, wenn der Vorgang fertig ist. */
  vorgang?: Job;
};

/** Uploadantwort ist die Antwort des Upload-Endpunkts. Die englischen Namen
 *  stammen aus der alten Oberfläche — es ist derselbe Handler, und ihn
 *  umzubenennen hieße, die alte Seite mitzuändern. */
export type Uploadantwort = {
  ok?: boolean;
  error?: string;
  entries?: Eintrag[];
};

// ------------------------------------------------------------------- Editor ---

/** Dateitext ist eine Textdatei, wie der Editor sie sieht. */
export type Dateitext = {
  eintrag: Eintrag;
  /** inhalt hat Zeilenenden in LF. crlf sagt, wie die Datei aussah — und der
   *  Wert geht unverändert zurück, damit sie so bleibt. Ein Editor, der aus 4000
   *  CRLF-Zeilen stillschweigend LF macht, schiebt den Unterschied in ein Diff,
   *  das niemand lesen kann. */
  inhalt: string;
  /** hash ist der SHA-256 des Inhalts auf der Platte. Er geht beim Speichern
   *  zurück und wird verglichen: Wurde die Datei zwischenzeitlich von außen
   *  geändert, antwortet der Server 412 statt zu überschreiben. */
  hash: string;
  crlf: boolean;
  ohne_schlussumbruch: boolean;
  sprache: string;
  /** pruefbar sagt, ob es für diese Datei ein Prüfprogramm gibt. Die Oberfläche
   *  nennt es VOR dem Speichern. */
  pruefbar: boolean;
  werkzeug?: string;
  verzeichnis: string;
  /** max_edit ist die Obergrenze des EDITORS, nicht die Größe dieser Datei. */
  max_edit: number;
  max_edit_text: string;
};

/** Pruefung ist das Ergebnis des Prüfprogramms nach dem Schreiben. */
export type Pruefung = {
  geprueft: boolean;
  ok: boolean;
  werkzeug: string;
  /** ausgabe ist die Meldung des Programms, wörtlich. Sie ist die einzige
   *  Auskunft darüber, WAS falsch ist — zusammenfassen hieße, sie wegzuwerfen. */
  ausgabe: string;
};

/** Textauftrag ist der Körper von POST /api/v1/files/text. */
export type Textauftrag = {
  pfad: string;
  inhalt: string;
  hash: string;
  crlf: boolean;
  ohne_schlussumbruch: boolean;
  /** ueberschreiben löst einen Konflikt bewusst auf. Die Oberfläche setzt es
   *  erst, nachdem sie ihn gezeigt hat. */
  ueberschreiben: boolean;
};

/** Textantwort ist die Antwort auf ein geglücktes Speichern. */
export type Textantwort = {
  meldung: string;
  text: Dateitext;
  pruefung?: Pruefung;
};
