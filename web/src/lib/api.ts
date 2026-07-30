// Zugriff auf /api/v1. Ein dünner Umschlag um fetch, kein Datenframework:
// Die Oberfläche liest wenige Ressourcen — ein Cache mit Invalidierungsregeln
// würde hier mehr Fragen stellen als beantworten.

import type {
  AktionAntwort,
  Bestaetigung,
  Dienste,
  DienstAktion,
  DienstDetail,
  Job,
  Pakete,
  Signale,
  Sitzung,
  Uebersicht,
  Umfang,
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
