// Zugriff auf /api/v1. Ein dünner Umschlag um fetch, kein Datenframework:
// Die Oberfläche liest wenige Ressourcen — ein Cache mit Invalidierungsregeln
// würde hier mehr Fragen stellen als beantworten.

import type {
  AktionAntwort,
  Audit,
  Bestaetigung,
  Dateiantwort,
  Dateiauftrag,
  Dateidetail,
  Dateihandlung,
  Dateiliste,
  Dateitext,
  Dienste,
  DienstAktion,
  DienstDetail,
  Firewall,
  FirewallAntwort,
  Job,
  Logs,
  Ordnerauswahl,
  Pakete,
  Pruefung,
  Regel,
  Signale,
  Sitzung,
  Uebersicht,
  Textantwort,
  Textauftrag,
  Umfang,
  Uploadantwort,
  Verlaeufe,
  VorgangGestartet,
} from "./typen";

/** AbgemeldetFehler steht für die eine Antwort, die nicht wie ein Fehler
 *  behandelt werden darf: Die Sitzung ist weg, und die Oberfläche muss zur
 *  Anmeldung führen statt eine Fehlermeldung zu zeigen. */
export class AbgemeldetFehler extends Error {
  constructor() {
    super("nicht angemeldet");
    this.name = "AbgemeldetFehler";
  }
}

/** BestaetigungNoetig trägt die Rückfrage, die der Server stellt, statt die
 *  Aktion auszuführen. Ein Fehlerobjekt und kein Rückgabewert, weil der
 *  Aufrufweg derselbe bleibt: Wer eine Aktion auslöst, erwartet ein Ergebnis —
 *  und alles andere ist ein Abbruch, den er behandeln muss. */
export class BestaetigungNoetig extends Error {
  constructor(public readonly bestaetigung: Bestaetigung) {
    super(bestaetigung.frage);
    this.name = "BestaetigungNoetig";
  }
}

/** Textkonflikt steht für die Antwort 412 des Editors: Die Datei wurde
 *  zwischenzeitlich von außen geändert.
 *
 *  Ein eigener Fehlertyp und nicht der Rückweg über BestaetigungNoetig, obwohl
 *  beides „nicht ausgeführt, entscheide" heißt. Der Grund ist, was danach
 *  geschieht: Eine Rückfrage bestätigt man und die Aktion läuft wie geplant; ein
 *  Konflikt hat ZWEI Auswege — die eigene Fassung durchsetzen oder die fremde
 *  übernehmen. Ein Dialog mit einem Knopf hätte den zweiten verschluckt. */
export class Textkonflikt extends Error {
  constructor(
    public readonly meldung: string,
    public readonly jetzt: Dateitext,
  ) {
    super(meldung);
    this.name = "Textkonflikt";
  }
}

/** Pruefungabgelehnt steht für die Antwort, wenn das Prüfprogramm des Systems die
 *  Datei ablehnt.
 *
 *  Sie ist die wichtigste Antwort dieses Moduls, und deshalb ein eigener Typ: Die
 *  Datei wurde geschrieben UND wieder zurückgerollt. „Fehler beim Speichern"
 *  wäre hier die schädlichste Auskunft — der Bediener würde erneut speichern. */
export class Pruefungabgelehnt extends Error {
  constructor(
    public readonly meldung: string,
    public readonly pruefung: Pruefung,
    public readonly zurueck: string,
    public readonly text: Dateitext | null,
  ) {
    super(meldung);
    this.name = "Pruefungabgelehnt";
  }
}

// Das CSRF-Token steht NICHT in einem Cookie. Das Panel hält es serverseitig in
// der Sitzungszeile und legte es bisher in jede gerenderte Seite; eine SPA
// bekommt kein gerendertes HTML und holt es deshalb über /api/v1/session.
// Geschickt wird es als Kopfzeile X-CSRF-Token — denselben Weg nimmt schon der
// Datei-Upload, der den Körper als Strom liest und das Formular nicht parsen
// kann (siehe internal/httpd/handlers_files_upload.go).
let token = "";

export function csrfToken(): string {
  return token;
}

async function anfrage<T>(pfad: string, init?: RequestInit): Promise<T> {
  const schreibend = init?.method !== undefined && init.method !== "GET";

  const antwort = await fetch(`/api/v1${pfad}`, {
    ...init,
    headers: {
      Accept: "application/json",
      ...(schreibend ? { "X-CSRF-Token": token } : {}),
      ...init?.headers,
    },
    // Die Sitzung hängt am Cookie; ohne diese Angabe schickt fetch es bei
    // manchen Einbettungen nicht mit.
    credentials: "same-origin",
  });

  if (antwort.status === 401) {
    throw new AbgemeldetFehler();
  }
  // 409 heißt: Die Anfrage war in Ordnung, sie ist nur nicht bestätigt. Das ist
  // kein Fehler, sondern eine Frage — und sie darf nicht als Fehlermeldung
  // erscheinen, sonst hat der Bediener eine rote Zeile statt eines Dialogs.
  if (antwort.status === 409) {
    const rumpf = (await antwort.json()) as { bestaetigung?: Bestaetigung };
    if (rumpf.bestaetigung) throw new BestaetigungNoetig(rumpf.bestaetigung);
    throw new Error(`HTTP ${antwort.status}`);
  }
  if (!antwort.ok) {
    // Der Server antwortet unter /api/ immer mit JSON, auch im Fehlerfall.
    // Käme doch etwas anderes, ist der Statuscode die belastbarere Aussage als
    // ein halb geparster Rumpf.
    let meldung = `HTTP ${antwort.status}`;
    try {
      const rumpf = (await antwort.json()) as { fehler?: string };
      if (rumpf.fehler) meldung = rumpf.fehler;
    } catch {
      /* Statuscode genügt. */
    }
    throw new Error(meldung);
  }
  return (await antwort.json()) as T;
}

/** anfrageOderLeer behandelt 204 als „nichts da" statt als Fehler.
 *
 *  Nötig für Vorgänge: Bevor jemand das erste Mal auf „Listen holen" gedrückt
 *  hat, gibt es keinen. Das ist ein Zustand, den die Oberfläche zeigt (nämlich
 *  gar nichts), und kein Fehler, den sie melden müsste. */
async function anfrageOderLeer<T>(pfad: string, init?: RequestInit): Promise<T | null> {
  const antwort = await fetch(`/api/v1${pfad}`, {
    ...init,
    headers: { Accept: "application/json", ...init?.headers },
    credentials: "same-origin",
  });
  if (antwort.status === 204) return null;
  if (antwort.status === 401) throw new AbgemeldetFehler();
  if (!antwort.ok) throw new Error(`HTTP ${antwort.status}`);
  return (await antwort.json()) as T;
}

export const api = {
  /** sitzung holt Konto, Rolle und CSRF-Token und merkt sich das Token für
   *  alle schreibenden Aufrufe. Muss vor dem ersten davon gelaufen sein. */
  async sitzung(): Promise<Sitzung> {
    const s = await anfrage<Sitzung>("/session");
    token = s.csrf;
    return s;
  },
  uebersicht: () => anfrage<Uebersicht>("/overview"),
  verlaeufe: () => anfrage<Verlaeufe>("/metrics/history"),
  // Eigener Aufruf, weil die Erhebung systemctl anfasst und echte Zeit kostet.
  // Die Oberfläche zeigt die Kacheln, während er noch läuft.
  signale: () => anfrage<Signale>("/signals"),

  /** job liefert den Zustand eines Vorgangs, oder null, wenn noch keiner
   *  gelaufen ist. Der Server antwortet dann mit 204 — die Ressource gibt es,
   *  sie ist nur leer. */
  job: (art: string) => anfrageOderLeer<Job>(`/jobs/${encodeURIComponent(art)}`),

  firewall: () => anfrage<Firewall>("/firewall"),
  /** regelnUebernehmen schickt den VOLLSTÄNDIGEN gewünschten Regelsatz, nicht
   *  eine einzelne Änderung. Damit ist der Zustand danach eindeutig, auch wenn
   *  zwei Personen gleichzeitig arbeiten. Stufe 2. */
  regelnUebernehmen: (regeln: Regel[], bestaetigt = false) =>
    anfrage<FirewallAntwort>("/firewall/rules", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ regeln, bestaetigt, getippt: "" }),
    }),
  /** ufwSchalten: Einschalten ist Stufe 2 (die Probe fängt den Fehler),
   *  Ausschalten Stufe 3 mit dem Hostnamen (es gibt keine Probe dafür). */
  ufwSchalten: (aktiv: boolean, bestaetigt = false, getippt = "") =>
    anfrage<FirewallAntwort>("/firewall/active", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ aktiv, bestaetigt, getippt }),
    }),
  /** probeBestaetigen beendet die Frist. Ohne Rückfrage: Bestätigen ist die
   *  Zustimmung zu etwas, das gerade schon gilt. */
  probeBestaetigen: () => anfrage<FirewallAntwort>("/firewall/confirm", { method: "POST" }),
  ufwEinspielen: () => anfrage<VorgangGestartet>("/firewall/install", { method: "POST" }),

  /** logs fragt das Journal ab. Die Filter stehen als Abfragezeichenkette in
   *  der Adresse — dieselbe, die der Strom bekommt, damit er nicht mehr zeigt
   *  als die Liste vorher hergab. */
  logs: (suchpfad = "") => anfrage<Logs>(`/logs${suchpfad ? `?${suchpfad}` : ""}`),

  pakete: () => anfrage<Pakete>("/packages"),
  paketlistenHolen: () =>
    anfrage<VorgangGestartet>("/packages/refresh", { method: "POST" }),
  /** einspielen startet ein Update. Wirft BestaetigungNoetig bei „alle" und
   *  „sicherheit"; ein einzelnes Paket ist ein gezielter Klick und läuft
   *  ohne Rückfrage. */
  einspielen: (umfang: Umfang, paket = "", bestaetigt = false) =>
    anfrage<VorgangGestartet>("/packages/upgrade", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ umfang, paket, bestaetigt, getippt: "" }),
    }),
  /** neustarten ist Stufe 3: Das getippte Wort ist der Hostname. */
  neustarten: (bestaetigt = false, getippt = "") =>
    anfrage<{ meldung: string }>("/system/reboot", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ umfang: "alle", paket: "", bestaetigt, getippt }),
    }),

  // ---------------------------------------------------------------- Dateien ---
  //
  // Der Pfad wandert als Abfrageparameter und nicht im Pfadsegment: Ein Pfad
  // enthält Schrägstriche, und ein doppelt kodiertes Segment wäre die Stelle, an
  // der ein Verzeichnisname mit Sonderzeichen irgendwann nicht mehr auffindbar
  // ist. Der Server prüft ihn ohnehin in der Pfadwache, nicht am Muster.

  /** dateien liest ein Verzeichnis oder — mit `q` — sucht darunter. Dieselbe
   *  Antwortform für beides. */
  dateien: (pfad: string, opt: { sort?: string; absteigend?: boolean; versteckt?: boolean; q?: string } = {}) => {
    const p = new URLSearchParams();
    if (pfad) p.set("pfad", pfad);
    if (opt.sort && opt.sort !== "name") p.set("sort", opt.sort);
    if (opt.absteigend) p.set("desc", "1");
    if (opt.versteckt) p.set("versteckt", "1");
    if (opt.q) p.set("q", opt.q);
    const suchpfad = p.toString();
    return anfrage<Dateiliste>(`/files${suchpfad ? `?${suchpfad}` : ""}`);
  },

  /** eintrag holt das Detail eines Eintrags: Rechte in Worten, die Zählung eines
   *  Baums, die Namen für chown. Eigener Aufruf, weil die Liste das für
   *  zweitausend Zeilen nicht mitschleppen soll. */
  eintrag: (pfad: string) =>
    anfrage<Dateidetail>(`/files/entry?${new URLSearchParams({ pfad })}`),

  /** ordner liefert die Unterverzeichnisse für die Zielauswahl. */
  ordner: (pfad: string, versteckt = false) => {
    const p = new URLSearchParams();
    if (pfad) p.set("pfad", pfad);
    if (versteckt) p.set("versteckt", "1");
    return anfrage<Ordnerauswahl>(`/files/dirs${p.toString() ? `?${p}` : ""}`);
  },

  /** herunterladen und archiv sind Adressen und keine Aufrufe: Der Browser holt
   *  sie selbst, damit der Download-Manager sie bekommt und nicht der Speicher
   *  des Tabs. Deshalb geben sie eine Zeichenkette zurück. */
  herunterladen: (pfad: string) => `/api/v1/files/download?${new URLSearchParams({ pfad })}`,
  archiv: (pfad: string) => `/api/v1/files/archive?${new URLSearchParams({ pfad })}`,

  /** dateiHandlung ist der eine Aufruf für alle verändernden Endpunkte.
   *
   *  Ein Aufruf und nicht acht: Der Körper ist derselbe, der Rückweg über
   *  BestaetigungNoetig ist derselbe, und acht Fassungen wären acht Stellen, an
   *  denen `bestaetigt` fehlen kann. Welche Handlung welche Rückfrage hat, steht
   *  ausschließlich im Handler — eine zweite Liste davon hier wäre die Stelle, an
   *  der eine neue zerstörende Handlung ohne Rückfrage durchrutscht. */
  dateiHandlung: (
    handlung: Dateihandlung,
    felder: Partial<Dateiauftrag>,
    bestaetigt = false,
    getippt = "",
  ) =>
    anfrage<Dateiantwort>(`/files/${handlung}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        pfad: "",
        name: "",
        ziel: "",
        rechte: "",
        eigentuemer: "",
        gruppe: "",
        rekursiv: false,
        ...felder,
        bestaetigt,
        getippt,
      }),
    }),

  /** hochladen schickt Dateien als Multipart.
   *
   *  Nicht über anfrage(): Der Körper ist ein FormData, und ein
   *  Content-Type-Kopf, den wir selbst setzen, verlöre die Grenzmarke. Der Token
   *  steht deshalb in der Kopfzeile — denselben Weg nimmt der Handler, der den
   *  Körper Teil für Teil streamt und ihn nicht als Formular parsen kann.
   *
   *  Die Reihenfolge der Felder ist sicherheitsrelevant: `dir` steht vor den
   *  Dateien, damit der Handler das Ziel kennt, bevor das erste Byte Inhalt
   *  fließt. FormData behält die Einfügereihenfolge. */
  hochladen: async (dir: string, dateien: File[], ueberschreiben = false) => {
    const form = new FormData();
    form.set("dir", dir);
    if (ueberschreiben) form.set("overwrite", "1");
    for (const d of dateien) form.append("datei", d, d.name);

    const antwort = await fetch("/api/v1/files/upload", {
      method: "POST",
      headers: { Accept: "application/json", "X-CSRF-Token": token },
      credentials: "same-origin",
      body: form,
    });
    if (antwort.status === 401) throw new AbgemeldetFehler();
    const rumpf = (await antwort.json()) as Uploadantwort;
    if (!antwort.ok || rumpf.error) {
      throw new Error(rumpf.error || `HTTP ${antwort.status}`);
    }
    return rumpf;
  },

  /** text holt eine Datei für den Editor. */
  text: (pfad: string) => anfrage<Dateitext>(`/files/text?${new URLSearchParams({ pfad })}`),

  /** textSpeichern schreibt zurück — mit eigenem Fehlerweg für die beiden
   *  Antworten, die keine Fehler sind, sondern Entscheidungen.
   *
   *  Nicht über anfrage(): Dort trägt 409 schon eine Bedeutung (Rückfrage), und
   *  ein !ok-Rumpf wird auf `fehler` reduziert. Hier braucht die Oberfläche mehr —
   *  den fremden Stand beim Konflikt (412) und die Ausgabe des Prüfprogramms samt
   *  Rückweg bei einer Ablehnung (400). Beides in `fehler` zu quetschen hieße,
   *  die Auskunft wegzuwerfen, auf die es ankommt. */
  async textSpeichern(auftrag: Textauftrag): Promise<Textantwort> {
    const antwort = await fetch("/api/v1/files/text", {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-CSRF-Token": token,
      },
      credentials: "same-origin",
      body: JSON.stringify(auftrag),
    });
    if (antwort.status === 401) throw new AbgemeldetFehler();

    if (antwort.status === 412) {
      const rumpf = (await antwort.json()) as { fehler: string; jetzt: Dateitext };
      throw new Textkonflikt(rumpf.fehler, rumpf.jetzt);
    }
    if (!antwort.ok) {
      const rumpf = (await antwort.json()) as {
        fehler?: string;
        pruefung?: Pruefung;
        zurueck?: string;
        text?: Dateitext;
      };
      // Nur wenn eine Prüfung dabeisteht, ist es die Ablehnung des
      // Prüfprogramms. Ein 403 der Pfadwache ist ein gewöhnlicher Fehler.
      if (rumpf.pruefung) {
        throw new Pruefungabgelehnt(
          rumpf.fehler ?? "abgelehnt",
          rumpf.pruefung,
          rumpf.zurueck ?? "",
          rumpf.text ?? null,
        );
      }
      throw new Error(rumpf.fehler ?? `HTTP ${antwort.status}`);
    }
    return (await antwort.json()) as Textantwort;
  },

  /** audit holt eine Seite des Revisionsprotokolls. Gefiltert wird auf dem
   *  Server: Das Protokoll wächst unbegrenzt, und ein Filter über einem
   *  Ausschnitt behauptete „kein Treffer" für einen Eintrag, den es gibt. */
  audit: (suchpfad = "") => anfrage<Audit>(`/audit${suchpfad ? `?${suchpfad}` : ""}`),

  dienste: () => anfrage<Dienste>("/services"),
  dienst: (unit: string) => anfrage<DienstDetail>(`/services/${encodeURIComponent(unit)}`),

  /** dienstAktion führt eine Aktion aus. Wirft BestaetigungNoetig, wenn der
   *  Server zurückfragt; derselbe Aufruf mit bestaetigt=true führt sie dann aus.
   *
   *  Die Rückfrage wird bewusst nicht im Browser vorweggenommen: Welche Aktion
   *  welche Stufe hat, steht im Handler, und eine zweite Liste davon hier wäre
   *  die Stelle, an der eine neue zerstörende Aktion ohne Rückfrage
   *  durchrutscht. */
  dienstAktion: (unit: string, aktion: DienstAktion, bestaetigt = false, getippt = "") =>
    anfrage<AktionAntwort>(`/services/${encodeURIComponent(unit)}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ aktion, bestaetigt, getippt }),
    }),
};
