// Zugriff auf /api/v1. Ein dünner Umschlag um fetch, kein Datenframework:
// Die Oberfläche liest wenige Ressourcen — ein Cache mit Invalidierungsregeln
// würde hier mehr Fragen stellen als beantworten.

import type { Sitzung, Uebersicht, Verlaeufe } from "./typen";

/** AbgemeldetFehler steht für die eine Antwort, die nicht wie ein Fehler
 *  behandelt werden darf: Die Sitzung ist weg, und die Oberfläche muss zur
 *  Anmeldung führen statt eine Fehlermeldung zu zeigen. */
export class AbgemeldetFehler extends Error {
  constructor() {
    super("nicht angemeldet");
    this.name = "AbgemeldetFehler";
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
};
