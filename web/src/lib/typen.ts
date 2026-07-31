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

// -------------------------------------------------------------------- Audit ---

/** Auditzeile ist ein Eintrag des Revisionsprotokolls. */
export type Auditzeile = {
  id: number;
  zeit: string;
  /** at ist der Tag (2026-07-30) zum Gruppieren; zeit die Anzeige. */
  at: string;
  akteur: string;
  aktion: string;
  /** familie ist der Teil der Aktion vor dem ersten Punkt — die Zuordnung zum
   *  Modul. Der Server bildet sie, damit Filterleiste und Zeile dieselbe Regel
   *  benutzen. */
  familie: string;
  ziel: string;
  ergebnis: "ok" | "denied" | "error";
  /** stufe ist die Klasse für die Einfärbung. „denied" ist eine Warnung und kein
   *  Fehler: Es heißt, dass die Politik gegriffen hat. */
  stufe: "gut" | "warn" | "schlecht";
  ip: string;
  detail: string;
};

/** Audit ist die Antwort von GET /api/v1/audit. */
export type Audit = {
  zeilen: Auditzeile[];
  /** weiter ist die ID für die nächste Seite, 0 am Ende. Geblättert wird über
   *  eine ID und nicht über einen Versatz: Das Protokoll wächst, während man
   *  darin liest. */
  weiter: number;
  akteure: string[];
  familien: string[];
  filter: {
    akteur: string;
    familie: string;
    ergebnis: string;
    suche: string;
  };
};

// ------------------------------------------------------- Systembenutzer ---

/** Systemkonto ist ein Konto des WIRTSYSTEMS — nicht des Panels. Der Unterschied
 *  ist wichtig: Ein Systemkonto kommt über SSH auf den Server, ein Panelkonto in
 *  diese Fläche. */
export type Systemkonto = {
  name: string;
  uid: number;
  gid: number;
  comment: string;
  home: string;
  shell: string;
  groups: string[] | null;
  locked: boolean;
  system: boolean;
  /** protected: Das Konto lässt sich über das Panel nicht sperren oder löschen
   *  (root). Die Prüfung sitzt in privops und greift ohnehin — aber eine
   *  Oberfläche, die „löschen" anbietet und dann verweigert, ist die schlechteste
   *  der möglichen Antworten. */
  protected: boolean;
  ssh_keys: number;
  has_shell: boolean;
  /** art ordnet ein: „mensch" mit Anmeldeschale, „dienst" ohne. */
  art: "mensch" | "dienst";
  zustand: "gut" | "warn" | "schlecht";
  /** ohne_schluessel heißt: Dieses Konto kann sich nicht anmelden. Nur bei
   *  Menschenkonten gesetzt — bei einem Dienstkonto ist es die Bauart. */
  ohne_schluessel: boolean;
  aktionen: Kontohandgriff[];
};

export type Kontohandgriff = "sperren" | "entsperren" | "loeschen" | "schluessel";

/** Systembenutzer ist die Antwort von GET /api/v1/system-users. */
export type Systembenutzer = {
  konten: Systemkonto[];
  zaehler: {
    gesamt: number;
    menschen: number;
    dienste: number;
    gesperrt: number;
    ohne_schluessel: number;
  };
  /** schalen und gruppen füllen die Auswahlfelder. Sie kommen aus /etc/shells
   *  und /etc/group — denselben Quellen, gegen die der Server prüft. Ein
   *  Auswahlfeld, das etwas vorschlägt, das der Server ablehnt, wäre die
   *  schlechteste Bedienhilfe. */
  schalen: string[];
  gruppen: string[];
  fehler: string;
};

/** Schluessel ist ein Eintrag aus authorized_keys. */
export type Schluessel = {
  type: string;
  comment: string;
  fingerprint: string;
  bits: number;
  /** staerke ist eine Einschätzung in Worten — „2048 Bit RSA" ist für die
   *  meisten keine Aussage, „nach heutigem Maß knapp" schon. */
  staerke: string;
  schwach: boolean;
};

export type Schluesselliste = {
  konto: string;
  schluessel: Schluessel[];
  /** datei ist der Ort, an dem sie stehen. Wer den Zugang verliert, muss wissen,
   *  wo er von Hand nachsehen kann. */
  datei: string;
};

/** Kontoauftrag ist der Körper der verändernden Endpunkte. */
export type Kontoauftrag = {
  name: string;
  notiz: string;
  schale: string;
  gruppen: string[];
  schluessel: string;
  fingerprint: string;
  /** gesperrt ist der GEWÜNSCHTE Zustand und kein Umschalter: Bei zwei offenen
   *  Browserfenstern ist „umschalten" nicht bestimmt. */
  gesperrt: boolean;
  home_entfernen: boolean;
};

export type Kontoantwort = {
  meldung: string;
  konto?: Systemkonto;
  /** hinweis ist eine Anmerkung, die kein Fehler ist — „das Konto hat noch
   *  keinen Schlüssel und kommt damit nicht auf den Server". */
  hinweis?: string;
};

// -------------------------------------------------------- Panel-Zugänge ---

/** Panelkonto ist ein Konto DIESER Oberfläche — nicht des Wirtsystems.
 *
 *  Was hier NICHT steht, ist die Aussage des Typs: kein Passwort-Hash, kein
 *  TOTP-Geheimnis. Der Server zählt die Felder ausdrücklich auf, statt store.User
 *  mit `json:"-"` an den heiklen Stellen einzubetten — kommt dort ein Feld dazu,
 *  wandert es nicht mit. Diese Seite hier ist die Gegenprobe: Sie kennt nichts
 *  anderes. */
export type Panelkonto = {
  id: number;
  name: string;
  rolle: string;
  /** rolle_was erklärt die Rolle in einem Satzteil. „admin" sagt nicht, was es
   *  bedeutet. */
  rolle_was: string;
  zustand: "gut" | "warn" | "schlecht";
  zustand_text: string;
  gesperrt: boolean;
  zweiter_faktor: boolean;
  /** einmalpasswort heißt: Das aktuelle Passwort kommt aus einer Zurücksetzung
   *  und wird bei der nächsten Anmeldung ersetzt. */
  einmalpasswort: boolean;
  passkeys: number;
  angelegt: string;
  /** letzte_anmeldung ist leer, wenn sich das Konto noch nie angemeldet hat. Das
   *  ist eine Aussage und kein fehlender Wert. */
  letzte_anmeldung: string;
  /** ich markiert das eigene Konto. Es hat hier keine Handgriffe: Wer sein
   *  eigenes Passwort ändern will, tut das auf der Kontoseite, und sperren oder
   *  löschen wäre ein Selbstausschluss. */
  ich: boolean;
  /** letzter_owner heißt: Dieses Konto darf nicht gelöscht werden, weil danach
   *  niemand mehr Konten verwalten könnte. */
  letzter_owner: boolean;
  aktionen: Panelhandgriff[];
};

export type Panelhandgriff =
  | "sperren"
  | "freigeben"
  | "loeschen"
  | "passwort"
  | "zweiter-faktor"
  | "passkeys";

/** Panelzugaenge ist die Antwort von GET /api/v1/panel-users. */
export type Panelzugaenge = {
  konten: Panelkonto[];
  /** ich ist die Kennung des eigenen Kontos — damit die eigene Zeile ohne
   *  Namensvergleich erkennbar ist. */
  ich: number;
  zaehler: {
    gesamt: number;
    owner: number;
    gesperrt: number;
    /** offen zählt Konten, deren Einrichtung noch nicht durch ist. Die Zahl, die
     *  eine Nachfrage nach sich zieht. */
    offen: number;
  };
  rollen: { wert: string; was: string }[];
  /** passkeys_moeglich sagt, ob dieses Panel überhaupt Passkeys kennt. Ist es
   *  abgeschaltet, fehlt der Handgriff — statt eines Knopfes, der nichts
   *  findet. */
  passkeys_moeglich: boolean;
};

/** Panelauftrag ist der Körper der verändernden Endpunkte. */
export type Panelauftrag = {
  name: string;
  rolle: string;
  /** gesperrt ist der GEWÜNSCHTE Zustand und kein Umschalter. */
  gesperrt: boolean;
  /** eigenes_passwort ist das Passwort DES OWNERS, nicht des Zielkontos: die
   *  zweite Schranke vor jeder Zurücksetzung. Ein übernommenes Cookie allein soll
   *  keine fremden Konten übernehmen können. */
  eigenes_passwort: string;
};

export type Panelantwort = {
  meldung: string;
  konto?: Panelkonto;
  /** einmalpasswort steht GENAU EINMAL hier und nirgends sonst: nicht im
   *  Protokoll, nicht in einer zweiten Antwort. Wer es verliert, setzt erneut
   *  zurück. */
  einmalpasswort?: string;
  neues_konto?: string;
  hinweis?: string;
};

// --------------------------------------------------------- Eigenes Konto ---

/** EigenesKonto ist die Antwort von GET /api/v1/account. */
export type EigenesKonto = {
  name: string;
  rolle: string;
  rolle_was: string;
  angelegt: string;
  zweiter_faktor: boolean;
  /** codes_offen ist die Zahl der noch unbenutzten Wiederherstellungscodes. Bei
   *  0 ist der Weg zurück ins Konto verstellt, wenn das Telefon abhandenkommt —
   *  deshalb steht die Zahl überhaupt da. */
  codes_offen: number;
  wechselzwang: boolean;
  sitzungen: Sitzungszeile[];
  andere: number;
  passkeys_moeglich: boolean;
  passkeys: Passkey[];
  /** wechsel steht, wenn ein Wechsel des zweiten Faktors angefangen ist. Der
   *  Zustand liegt auf dem SERVER, nicht im Browser: Nach einem Neuladen ist das
   *  die einzige Auskunft darüber, dass noch etwas offen ist. */
  wechsel?: ZweiterFaktorWechsel;
};

/** Sitzungszeile ist eine offene Sitzung des eigenen Kontos.
 *
 *  Die Liste ist mehr als Bequemlichkeit: Ein entwendetes Sitzungscookie
 *  hinterlässt sonst keine Spur, die dem Betroffenen auffiele. */
export type Sitzungszeile = {
  id: string;
  kurz: string;
  ip: string;
  programm: string;
  seit: string;
  zuletzt: string;
  laeuft_ab: string;
  /** diese markiert die Sitzung, in der man gerade sitzt. Sie zu beenden ist ein
   *  Abmelden, und die Oberfläche sagt das auch so. */
  diese: boolean;
};

export type ZweiterFaktorWechsel = {
  geheimnis: string;
  geheimnis_text: string;
  uri: string;
  /** qr ist der PFAD zum Bild, nicht das Bild. Die Richtlinie erlaubt beides
   *  (img-src 'self' data:), aber ein data:-URI hätte das Geheimnis ein zweites
   *  Mal in der Antwort stehen lassen. */
  qr: string;
  laeuft_ab: string;
};

export type Passkey = {
  id: number;
  name: string;
  /** synced: geräteübergreifend gesichert (Cloud-Passkey) oder an ein Gerät
   *  gebunden. Der Unterschied gehört in die Anzeige — ein gerätegebundener
   *  Schlüssel ist mit dem Gerät verloren. */
  synced: boolean;
  angelegt: string;
  /** zuletzt ist leer, wenn sich mit dem Passkey noch niemand angemeldet hat.
   *  Ein hinterlegter Schlüssel, der nie benutzt wurde, ist ungeprüft. */
  zuletzt: string;
};

/** Kontoauftrag2 ist der Körper der verändernden Endpunkte des eigenen Kontos.
 *
 *  Der Name mit der Zwei ist keine Verlegenheit: „Kontoauftrag" ist an die
 *  Systemkonten vergeben, und dieselbe Verwechslung, die die Oberfläche
 *  auseinanderhält, soll sich hier nicht durch gleiche Namen wieder einschleichen.
 *  Gelesen wird der Typ nur an einer Stelle. */
export type Kontoauftrag2 = {
  /** passwort ist das AKTUELLE Passwort — die Schranke vor jeder Änderung an
   *  einem Anmeldeweg. Es wird nicht gespeichert, nicht in die Adresse
   *  geschrieben und nach dem Aufruf im Feld gelöscht. */
  passwort: string;
  neu: string;
  neu_wiederholt: string;
  code: string;
  sitzung: string;
  name: string;
};

export type Kontoantwort2 = {
  meldung: string;
  konto?: EigenesKonto;
  /** codes stehen GENAU EINMAL hier. Wer sie verliert, erzeugt neue. */
  codes?: string[];
  /** csrf ist ein frisches Sitzungstoken. Gesetzt, wenn die Handlung die eigene
   *  Sitzung erneuert hat — nach einer Passwortänderung sind alle Sitzungen des
   *  Kontos beendet, auch die eigene. Ohne dieses Feld schlüge der nächste
   *  schreibende Aufruf fehl, nach einer Handlung, die geglückt ist. */
  csrf?: string;
  /** abgemeldet heißt: Diese Sitzung ist beendet. Die Oberfläche führt dann zur
   *  Anmeldung. */
  abgemeldet?: boolean;
  hinweis?: string;
};

/** PasskeyBeginn ist die Antwort von …/passkeys/register/begin.
 *
 *  optionen sind die Optionen für navigator.credentials.create, durchgereicht wie
 *  go-webauthn sie baut — bewusst nicht nachgebaut: Eine Nachbildung wäre eine
 *  zweite Stelle, die bei jeder Erweiterung des Standards nachgezogen werden
 *  müsste. */
export type PasskeyBeginn = {
  ticket: string;
  optionen: Record<string, unknown>;
};

// ------------------------------------------------------------- Zertifikat ---

/** Wahl ist ein wählbarer Wert mit seiner Erklärung. Vom Server, weil dort die
 *  Bedingungen bekannt sind: HTTP-01 braucht Port 80, DNS-01 einen Anbieter. */
export type Wahl = { wert: string; name: string; was: string };

/** Zertifikat ist die Antwort von GET /api/v1/certificate. */
export type Zertifikat = {
  /** modus ist die EINSTELLUNG ("selfsigned" | "acme"), quelle die Herkunft des
   *  gerade ausgelieferten Zertifikats. Beides fällt auseinander, solange kein
   *  Bezug geglückt ist — und genau dieser Zwischenzustand ist der, den jemand
   *  erklärt bekommen möchte. */
  modus: string;
  quelle: string;
  zustand: "gut" | "warn" | "schlecht";
  zustand_text: string;

  datei: string;
  inhaber: string;
  aussteller: string;
  namen: string[];
  fingerprint: string;
  gueltig_ab: string;
  gueltig_bis: string;
  /** tage_uebrig kann negativ sein. */
  tage_uebrig: number;
  selbstsigniert: boolean;
  lesefehler: string;

  email: string;
  /** namenstext ist die Eingabefassung: ein Name je Zeile. Leer heißt „der
   *  vollqualifizierte Rechnername". */
  namenstext: string;
  geltende_namen: string[];
  pruefmethode: string;
  anbieter: string;
  hook_setzen: string;
  hook_aufraeumen: string;
  /** token_hinterlegt: Das Token selbst kommt nie zurück, sein Vorhandensein
   *  schon — sonst müsste man es bei jedem Speichern neu eingeben. */
  token_hinterlegt: boolean;
  testverzeichnis: boolean;
  /** verwaltete_datei ist die Datei, in der die Einstellungen landen. Sie steht
   *  in der Oberfläche, weil das Panel nichts versteckt. */
  verwaltete_datei: string;

  bezug_laeuft: boolean;
  bezug_zeit: string;
  bezug_fehler: string;
  job: Job | null;

  pruefmethoden: Wahl[];
  anbieter_liste: Wahl[];
};

/** Zertifikatauftrag ist der Körper von POST /api/v1/certificate. */
export type Zertifikatauftrag = {
  modus: string;
  email: string;
  namenstext: string;
  pruefmethode: string;
  anbieter: string;
  hook_setzen: string;
  hook_aufraeumen: string;
  /** token leer heißt: das hinterlegte behalten. Ein leeres Feld darf keinen
   *  funktionierenden Zugang löschen. */
  token: string;
  testverzeichnis: boolean;
};

export type Zertifikatantwort = {
  meldung: string;
  zertifikat?: Zertifikat;
  hinweis?: string;
};

// ----------------------------------------------------------------- Update ---

/** Panelupdate ist die Antwort von GET /api/v1/update. */
export type Panelupdate = {
  /** fassung ist die LAUFENDE Fassung. Sie ist die Antwort auf die Frage, ob ein
   *  Update durch ist: Wer nach dem Neustart eine andere zurückbekommt, weiß es —
   *  denn sie kommt aus dem neuen Programm. */
  fassung: string;
  kanal: string;
  quelle: string;
  /** geprueft_am ist leer, solange in dieser Laufzeit nicht geprüft wurde. Das
   *  heißt „noch nicht gefragt" und nicht „kein Update". */
  geprueft_am: string;
  verfuegbar: string;
  erschienen: string;
  notizen: string;
  dringlichkeit: string;
  update_da: boolean;
  pruef_fehler: string;
  laeuft: boolean;
  ziel: string;
  zeilen: string[];
  vorher: string;
  rueckweg_moeglich: boolean;
  /** darf_ausloesen kommt vom Server: Nur die Owner-Rolle darf Update und
   *  Rückweg anstoßen. Die Oberfläche soll die Regel nicht ein zweites Mal
   *  kennen. */
  darf_ausloesen: boolean;
};

/** Updatestand ist die Antwort des Pollers. Absichtlich klein — sie wird im
 *  Sekundentakt gefragt, auch während der Dienst neu startet. */
export type Updatestand = {
  fassung: string;
  laeuft: boolean;
  ziel: string;
  zeilen: string[];
};

export type Updateantwort = {
  meldung: string;
  update?: Panelupdate;
  hinweis?: string;
};

// ------------------------------------------------------------- Zeitpläne ---

/** Zeitplaene ist die Antwort von GET /api/v1/schedules — Cron und Timer in
 *  einem Aufruf, weil sie eine Frage beantworten: Was läuft hier von allein? */
export type Zeitplaene = {
  cron: Croneintrag[];
  timer: Timer[];
  rahmen: Zeitplanrahmen;
  /** luecken sind Quellen, die sich nicht lesen ließen. Sie stehen in der
   *  Antwort, weil eine unvollständige Liste als vollständig ausgegeben Grundsatz
   *  IV bricht — das Panel versteckt nichts, auch nicht sein eigenes Unwissen. */
  luecken: string[];
  /** timer_fehler steht, wenn systemctl nicht antwortete. Auf einem System ohne
   *  systemd ist das der Normalfall, und die Cron-Hälfte bleibt interessant. */
  timer_fehler: string;
};

export type Croneintrag = {
  quelle: string;
  zeile: number;
  schedule: string;
  /** schedule_text ist derselbe Zeitplan in Worten — vom Server, damit es nur
   *  eine Auslegung der fünf Felder gibt. */
  schedule_text: string;
  user: string;
  command: string;
  kommentar: string;
  /** verwaltet heißt: Diese Datei trägt den Marker des Panels und darf
   *  geschrieben werden. Alles andere ist Auskunft. */
  verwaltet: boolean;
  name: string;
  /** art ist "zeile" für eine Crontab-Zeile, "skript" für eine Datei in einem
   *  run-parts-Verzeichnis. */
  art: string;
  deaktiviert: boolean;
  /** stufe ist die Rückfragestufe, die dieser Eintrag verlangt — vom Server
   *  gerechnet. Zwei Rechnungen derselben Sicherheitsregel laufen auseinander. */
  stufe: number;
};

export type Timer = {
  unit: string;
  loest: string;
  beschreibung: string;
  aktiv: string;
  enabled: string;
  /** naechster und letzter sind RFC-3339-Zeitpunkte; leer heißt „nicht bekannt".
   *  Ein Timer, der noch nie lief, hat keinen letzten Lauf, und einer, der
   *  abgeschaltet ist, keinen nächsten. */
  naechster: string;
  letzter: string;
  plan: string;
  persistent: boolean;
};

export type Zeitplanrahmen = {
  benutzer: string[];
  vorlagen: Zeitplanvorlage[];
  verzeichnis: string;
  darf_aendern: boolean;
};

export type Zeitplanvorlage = { name: string; schedule: string; text: string };

export type Cronauftrag = {
  name: string;
  schedule: string;
  user: string;
  command: string;
  kommentar: string;
  aktiv: boolean;
};

export type Zeitplanantwort = { meldung: string; hinweis?: string };

/** Timerlauf ist das Ergebnis des letzten Laufs der Unit, die ein Timer
 *  auslöst — nicht des Timers: Der glückt immer, sobald er auslöst. */
export type Timerlauf = {
  unit: string;
  ergebnis: string;
  exit_code: number;
  geglueckt: boolean;
  zeilen: Logzeile[];
};

// ----------------------------------------------------------- API-Tokens ---

/** Tokens ist die Antwort von GET /api/v1/tokens. */
export type Tokens = {
  tokens: Tokenzeile[];
  /** familien sind die Flächen, für die ein Token gelten kann — mit einem Satz
   *  dazu. „schedules" sagt einem Menschen nichts, und wer einen Token
   *  einschränkt, muss wissen, was er damit abschaltet. */
  familien: Tokenfamilie[];
  /** gesperrt sind die Flächen, die kein Token erreicht. Sie stehen in der
   *  Antwort, damit die Oberfläche sie NENNEN kann statt sie zu verschweigen. */
  gesperrt: string[];
  fristen: Tokenfrist[];
  praefix: string;
};

export type Tokenfamilie = { wert: string; was: string };
export type Tokenfrist = { tage: number; name: string };

export type Tokenzeile = {
  id: number;
  name: string;
  /** prefix ist der sichtbare Anfang. Er erlaubt keine Anmeldung und macht die
   *  Liste benutzbar: Wer drei Tokens in drei Skripten liegen hat, erkennt
   *  daran, welcher welcher ist. */
  prefix: string;
  konto: string;
  rolle: string;
  ich: boolean;
  scopes: string[];
  nur_lesen: boolean;
  angelegt: string;
  /** frist ist leer für „ohne Ablauf" — ein eigener Zustand, kein Datum in
   *  ferner Zukunft. */
  frist: string;
  abgelaufen: boolean;
  tage_bis_ablauf: number;
  zuletzt_am: string;
  zuletzt_von: string;
  nie_benutzt: boolean;
  zustand: string;
  zustand_text: string;
};

export type Tokenauftrag = {
  name: string;
  scopes: string[];
  nur_lesen: boolean;
  /** tage ist die Laufzeit; 0 heißt „ohne Ablauf". */
  tage: number;
};

/** Tokenantwort trägt den Klartext GENAU EINMAL. Danach gibt es ihn nicht mehr:
 *  In der Datenbank steht der Hash, und es gibt keinen Endpunkt, der ihn
 *  zurückgäbe. */
export type Tokenantwort = { meldung: string; token?: string; hinweis?: string };
