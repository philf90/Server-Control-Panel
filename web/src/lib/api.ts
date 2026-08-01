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
  Bestand,
  Containerliste,
  ContainerDetail,
  Containerantwort,
  DienstDetail,
  Docker,
  Webserver,
  Siteliste,
  Siteentwurf,
  Siteantwort,
  Sitebefund,
  Sitezertifikate,
  Ziele,
  EigenesKonto,
  Firewall,
  FirewallAntwort,
  Job,
  Logs,
  Kontoantwort2,
  Kontoauftrag2,
  Ordnerauswahl,
  Pakete,
  PasskeyBeginn,
  Panelantwort,
  Panelauftrag,
  Panelzugaenge,
  Pruefung,
  Regel,
  Schluesselliste,
  Signale,
  Sitzung,
  Imageupdates,
  Portliste,
  Stackliste,
  StackDetail,
  Stackschreibantwort,
  Composebefund,
  Composepruefung,
  Panelupdate,
  Uebersicht,
  Updateantwort,
  Updatestand,
  Textantwort,
  Textauftrag,
  Systembenutzer,
  Kontoantwort,
  Kontoauftrag,
  Umfang,
  Uploadantwort,
  Verlaeufe,
  VorgangGestartet,
  Zertifikat,
  Zertifikatantwort,
  Zertifikatauftrag,
  Cronauftrag,
  Timerlauf,
  Zeitplaene,
  Zeitplanantwort,
  Tokens,
  Tokenantwort,
  Tokenauftrag,
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

/** VerbotenFehler steht für 403: Die Rolle darf das nicht.
 *
 *  Ein eigener Typ, weil die Oberfläche daraus etwas anderes machen muss als aus
 *  einem Ladefehler. „Die Daten konnten nicht geladen werden" mit einem Knopf
 *  „Erneut versuchen" ist bei einer Rechtefrage die falsche Auskunft in zwei
 *  Punkten: Der Grund ist nicht ein Fehlschlag, und der Knopf führt nie zu einem
 *  anderen Ergebnis. Die Meldung des Servers trägt den Grund. */
export class VerbotenFehler extends Error {
  constructor(meldung: string) {
    super(meldung);
    this.name = "VerbotenFehler";
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

/** Composeabgelehnt steht für die Antwort, wenn der Compose-Prüfer einen Stack
 *  ablehnt.
 *
 *  Ein eigener Typ und nicht ein Fehlertext, weil die Auskunft strukturiert ist
 *  und genau darin ihren Wert hat: Dienst, Feld, Wert und Grund je Fund. „Der
 *  Stack wurde abgelehnt" schickte jemanden auf die Suche in einer Datei, die er
 *  gerade geschrieben hat.
 *
 *  Der Unterschied zu Pruefungabgelehnt (Dateimanager) ist die Wirkung: Dort
 *  wurde geschrieben UND zurückgerollt, hier wurde NICHT geschrieben. */
export class Composeabgelehnt extends Error {
  constructor(
    public readonly meldung: string,
    public readonly pruefung: Composepruefung,
    public readonly befunde: Composebefund[],
  ) {
    super(meldung);
    this.name = "Composeabgelehnt";
  }
}

/** Siteabgelehnt steht für die Antwort, wenn der Site-Prüfer einen Entwurf
 *  ablehnt.
 *
 *  Wie Composeabgelehnt ein eigener Typ und kein Fehlertext: Die Auskunft ist
 *  strukturiert — Feld, Wert und Grund je Fund —, und die Oberfläche kann sie
 *  neben das Feld stellen, in dem der Fehler steckt.
 *
 *  ungeprueft steht daneben und ist nicht dasselbe: „Das kennt der Prüfer nicht"
 *  ist keine Ablehnung, aber auch kein „in Ordnung". */
export class Siteabgelehnt extends Error {
  constructor(
    public readonly meldung: string,
    public readonly befunde: Sitebefund[],
    public readonly ungeprueft: string[],
  ) {
    super(meldung);
    this.name = "Siteabgelehnt";
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

// WebAuthn arbeitet mit ArrayBuffers, JSON kann keine tragen — an dieser Grenze
// wird umgerechnet.
//
// Dieselben zwei Funktionen stehen in internal/ui/static/passkey-login.js und
// passkey-reset.js: Die Anmeldung und der Weg über ein vergessenes Passwort sind
// server-gerenderte Seiten und laden dieses Bundle nicht. Sie sind so klein und
// so festgelegt (RFC 4648 §5), dass eine geteilte Fassung nur eine Abhängigkeit
// zwischen zwei Flächen wäre, die sonst nichts miteinander teilen — und die
// Anmeldeseite darf von der Fläche dahinter nichts brauchen.

function b64urlZuPuffer(s: string): ArrayBuffer {
  const gefuellt = s.replace(/-/g, "+").replace(/_/g, "/").padEnd(
    s.length + ((4 - (s.length % 4)) % 4),
    "=",
  );
  const roh = atob(gefuellt);
  const bytes = new Uint8Array(roh.length);
  for (let i = 0; i < roh.length; i++) bytes[i] = roh.charCodeAt(i);
  return bytes.buffer;
}

function pufferZuB64url(puffer: ArrayBuffer): string {
  const bytes = new Uint8Array(puffer);
  let s = "";
  for (const b of bytes) s += String.fromCharCode(b);
  return btoa(s).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
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
    // 403 ist kein Fehlschlag, sondern eine Auskunft über die Rolle. Wer sie als
    // Ladefehler zeigt, stellt einen Knopf daneben, der nie ein anderes Ergebnis
    // bringt.
    if (antwort.status === 403) throw new VerbotenFehler(meldung);
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

/** stackSchreiben ist der Schreibweg der Stacks — mit eigenem Fehlerpfad für die
 *  eine Antwort, die kein Fehler ist, sondern ein Urteil.
 *
 *  Nicht über anfrage(): Dort wird ein !ok-Rumpf auf `fehler` reduziert, und
 *  genau die Befunde gingen dabei verloren, auf die es ankommt. 409 bleibt der
 *  Rückfrageweg — den nimmt anfrage() ebenso, und hier steht er noch einmal,
 *  weil dieser Weg an anfrage() vorbeigeht.
 *
 *  Dasselbe Muster wie bei textSpeichern und aus demselben Grund. */
async function stackSchreiben(
  methode: "POST" | "PUT",
  pfad: string,
  koerper: Record<string, unknown>,
): Promise<Stackschreibantwort> {
  const antwort = await fetch(`/api/v1${pfad}`, {
    method: methode,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-CSRF-Token": token,
    },
    credentials: "same-origin",
    body: JSON.stringify(koerper),
  });
  if (antwort.status === 401) throw new AbgemeldetFehler();
  if (antwort.status === 409) {
    const rumpf = (await antwort.json()) as { bestaetigung?: Bestaetigung; fehler?: string };
    if (rumpf.bestaetigung) throw new BestaetigungNoetig(rumpf.bestaetigung);
    throw new Error(rumpf.fehler ?? `HTTP ${antwort.status}`);
  }
  if (!antwort.ok) {
    const rumpf = (await antwort.json()) as Stackschreibantwort;
    // Nur wenn Befunde dabeistehen, ist es das Urteil des Prüfers. Ein 403 der
    // Rollenprüfung ist ein gewöhnlicher Fehler.
    if (rumpf.befunde && rumpf.befunde.length > 0) {
      throw new Composeabgelehnt(rumpf.fehler ?? "abgelehnt", rumpf.pruefung, rumpf.befunde);
    }
    if (antwort.status === 403) throw new VerbotenFehler(rumpf.fehler ?? "verboten");
    throw new Error(rumpf.fehler ?? `HTTP ${antwort.status}`);
  }
  return (await antwort.json()) as Stackschreibantwort;
}

/** siteSchreiben ist der Anfrageweg der Schreibrouten des Webservers.
 *
 *  Ein eigener statt anfrage(), aus demselben Grund wie bei den Stacks: Die
 *  Ablehnung des Prüfers steht STRUKTURIERT im Körper der 400 — Feld und Grund
 *  je Fund —, und genau darin liegt ihr Wert. „Der Entwurf wurde abgelehnt"
 *  schickte jemanden auf die Suche in einem Formular, das er gerade ausgefüllt
 *  hat. */
async function siteSchreiben(
  pfad: string,
  methode: string,
  koerper: unknown,
): Promise<Siteantwort> {
  const antwort = await fetch(`/api/v1${pfad}`, {
    method: methode,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-CSRF-Token": token,
    },
    credentials: "same-origin",
    body: JSON.stringify(koerper),
  });
  if (antwort.status === 401) throw new AbgemeldetFehler();
  if (antwort.status === 409) {
    const rumpf = (await antwort.json()) as { bestaetigung?: Bestaetigung; fehler?: string };
    if (rumpf.bestaetigung) throw new BestaetigungNoetig(rumpf.bestaetigung);
    // Ein 409 ohne Rückfrage ist der Fassungskonflikt: Die Site wurde
    // zwischenzeitlich geändert. Der Text sagt, was zu tun ist.
    throw new Error(rumpf.fehler ?? `HTTP ${antwort.status}`);
  }
  if (!antwort.ok) {
    const rumpf = (await antwort.json()) as Siteantwort & { fehler?: string };
    // Nur wenn Ablehnungen dabeistehen, ist es das Urteil des Prüfers. Ein 403
    // der Rollenprüfung ist ein gewöhnlicher Fehler.
    const ablehnungen = rumpf.pruefung?.ablehnungen ?? [];
    if (ablehnungen.length > 0) {
      throw new Siteabgelehnt(rumpf.meldung || "abgelehnt", ablehnungen, rumpf.pruefung?.ungeprueft ?? []);
    }
    if (antwort.status === 403) throw new VerbotenFehler(rumpf.fehler ?? "verboten");
    throw new Error(rumpf.fehler ?? `HTTP ${antwort.status}`);
  }
  return (await antwort.json()) as Siteantwort;
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

  /** docker liefert den Zustand der Container-Laufzeit — einschließlich eines
   *  laufenden Vorgangs, damit die Seite nach einem Neuladen den Auszug
   *  vorfindet und nicht behauptet, es sei nichts los. */
  docker: () => anfrage<Docker>("/docker"),
  /** dockerEinspielen ist Stufe 1: Ein Paket aus den Quellen der Distribution
   *  zu installieren nimmt nichts weg und sperrt niemanden aus. Die Route liegt
   *  hinter der Owner-Rolle. */
  dockerEinspielen: () => anfrage<VorgangGestartet>("/docker/install", { method: "POST" }),

  /** webserver liefert den Zustand des Webservers samt Portbelegung. */
  webserver: () => anfrage<Webserver>("/webserver"),
  /** nginxEinspielen darf der Server ablehnen, und zwar nicht nur wegen der
   *  Rolle: Hört auf Port 80 oder 443 schon jemand, kommt 409 zurück. Der
   *  Knopf verschwindet zwar vorher — aber zwischen dem Laden der Seite und dem
   *  Klick liegt beliebig viel Zeit, und die Sperre sitzt deshalb im Server. */
  nginxEinspielen: () => anfrage<VorgangGestartet>("/webserver/install", { method: "POST" }),
  /** webserverSites liefert die Serverblöcke, die nginx wirklich ausliefert —
   *  aus `nginx -T` und nicht aus den Dateien. Verwaltete und fremde in einer
   *  Liste, unterschieden im Feld herkunft. */
  webserverSites: () => anfrage<Siteliste>("/webserver/sites"),
  /** webserverSite liefert den Inhalt EINER verwalteten Site. Für eine fremde
   *  antwortet der Server 404: Das Panel schreibt sie nicht, und ein Editor
   *  wäre die Zusage, es doch zu tun. */
  webserverSite: (name: string) =>
    anfrage<{ name: string; inhalt: string }>(
      `/webserver/sites/${encodeURIComponent(name)}`,
    ),
  /** siteSpeichern legt an oder ändert — was davon, entscheidet der Server aus
   *  dem Zustand auf der Platte. `fassung` ist der Hash der Datei, von der der
   *  Browser ausging; leer heißt „neu". Stimmt er nicht mehr, antwortet der
   *  Server 409, statt eine fremde Änderung zu überschreiben. */
  siteSpeichern: (name: string, entwurf: Siteentwurf, bestaetigt = false, getippt = "") =>
    siteSchreiben(`/webserver/sites/${encodeURIComponent(name)}`, "PUT", {
      ...entwurf,
      bestaetigt,
      getippt,
    }),
  /** siteSchalten: Einschalten ist Stufe 1, Abschalten Stufe 2 — danach
   *  antwortet die Domain nicht mehr. */
  siteSchalten: (name: string, an: boolean, bestaetigt = false, getippt = "") =>
    siteSchreiben(`/webserver/sites/${encodeURIComponent(name)}/schalten`, "POST", {
      an,
      bestaetigt,
      getippt,
    }),
  /** siteLoeschen ist Stufe 3 mit dem Namen der Site und läuft OHNE Probe: Ein
   *  Rückweg, der die Datei zurückholt und das Zertifikat verwaisen lässt, wäre
   *  schlechter als keiner. */
  siteLoeschen: (name: string, bestaetigt = false, getippt = "") =>
    siteSchreiben(`/webserver/sites/${encodeURIComponent(name)}`, "DELETE", {
      bestaetigt,
      getippt,
    }),
  /** webserverZiele sammelt, worauf eine Site zeigen kann: laufende Container
   *  mit veröffentlichten Ports und vorhandene PHP-FPM-Sockets. Eine Auskunft,
   *  keine Pflicht — ohne Docker ist die Liste leer und man tippt. */
  webserverZiele: () => anfrage<Ziele>("/webserver/ziele"),
  /** webserverZertifikate liefert den Zertifikatsstand je Site — samt dem
   *  Grund, wenn keins da ist. „Kein Zertifikat" ohne Grund ist die Auskunft,
   *  mit der niemand etwas anfangen kann. */
  webserverZertifikate: () => anfrage<Sitezertifikate>("/webserver/zertifikate"),
  /** siteZertBeziehen stößt den Bezug an. Stufe 1: Er ändert nichts an der
   *  Konfiguration — er legt ein Zertifikat ab oder scheitert. */
  siteZertBeziehen: (name: string) =>
    anfrage<{ meldung: string }>(
      `/webserver/sites/${encodeURIComponent(name)}/zertifikat`,
      { method: "POST" },
    ),
  /** siteProbeBestaetigen beendet die Frist. Ohne Rückfrage: Bestätigen ist die
   *  Zustimmung zu etwas, das gerade schon gilt. */
  siteProbeBestaetigen: () =>
    siteSchreiben("/webserver/sites/bestaetigen", "POST", {}),
  /** container liefert die vollständige Liste. Gefiltert wird im Browser: Ein
   *  Server hat selten mehr als ein paar Dutzend Container, und beim Tippen ist
   *  das Ergebnis dann sofort da. */
  container: () => anfrage<Containerliste>("/docker/containers"),
  containerDetail: (id: string) =>
    anfrage<ContainerDetail>(`/docker/containers/${encodeURIComponent(id)}`),
  /** containerAktion: start, stop, restart, pause, unpause, remove. Die Stufe
   *  der Rückfrage entscheidet der Server — stoppen ist Stufe 2, einen
   *  LAUFENDEN Container zu entfernen Stufe 3 mit seinem Namen. */
  bestand: () => anfrage<Bestand>("/docker/bestand"),
  imageEntfernen: (id: string, bestaetigt = false) =>
    anfrage<AktionAntwort>(`/docker/images/${encodeURIComponent(id)}/remove`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ aktion: "remove", bestaetigt, getippt: "" }),
    }),
  /** volumeEntfernen ist Stufe 3 mit dem Volumenamen: Was darin liegt, ist
   *  danach weg, und kein Rückweg holt es zurück. */
  volumeEntfernen: (name: string, bestaetigt = false, getippt = "") =>
    anfrage<AktionAntwort>(`/docker/volumes/${encodeURIComponent(name)}/remove`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ aktion: "remove", bestaetigt, getippt }),
    }),
  netzEntfernen: (id: string, bestaetigt = false) =>
    anfrage<AktionAntwort>(`/docker/networks/${encodeURIComponent(id)}/remove`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ aktion: "remove", bestaetigt, getippt: "" }),
    }),
  /** aufraeumen läuft als Vorgang: Auf einem Server mit fünfzig Gigabyte
   *  Images dauert es Minuten. Der freigegebene Platz steht danach als
   *  Anmerkung am Vorgang. */
  aufraeumen: (art: string, alle = false, bestaetigt = false, getippt = "") =>
    anfrage<VorgangGestartet>("/docker/prune", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ art, alle, bestaetigt, getippt }),
    }),
  containerAktion: (id: string, aktion: string, bestaetigt = false, getippt = "") =>
    anfrage<Containerantwort>(`/docker/containers/${encodeURIComponent(id)}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ aktion, bestaetigt, getippt }),
    }),
  /** ports listet alle veröffentlichten Ports mit ihrem Urteil — abgeglichen
   *  mit der Firewall. Das Urteil rechnet der Server: Ein Port, den ufw nicht
   *  kennt, ist trotzdem offen, und diese Aussage soll es nur einmal geben. */
  ports: () => anfrage<Portliste>("/docker/ports"),

  /** imageupdates liefert den ZWISCHENGESPEICHERTEN Stand. Dieser Aufruf
   *  fragt keine Registry — sonst verbrauchte ein offener Tab die Ratengrenze
   *  im Hintergrund. */
  imageupdates: () => anfrage<Imageupdates>("/docker/updates"),
  /** updatePruefungStarten stößt den Vergleich mit den Registries an. Höchstens
   *  einmal am Tag; danach antwortet der Server mit 429 und dem Zeitpunkt, ab
   *  dem es wieder geht. */
  updatePruefungStarten: () =>
    anfrage<VorgangGestartet>("/docker/updates/check", { method: "POST" }),

  /** stacks liefert die Compose-Projekte, verwaltete wie fremde. */
  stacks: () => anfrage<Stackliste>("/docker/stacks"),
  /** stackDetail nimmt einen NAMEN und keinen Pfad. Wo die Compose-Datei liegt,
   *  sagt Docker oder das verwaltete Verzeichnis — käme der Pfad von hier, wäre
   *  der Endpunkt ein Weg, jede Datei des Servers zu lesen. */
  stackDetail: (name: string) =>
    anfrage<StackDetail>(`/docker/stacks/${encodeURIComponent(name)}`),
  /** stackAnlegen legt einen neuen Stack an. Ein schon vergebener Name ist 409
   *  und kein Überschreiben — wer „anlegen" drückt, erwartet das nicht. */
  stackAnlegen: (name: string, text: string, bestaetigt = false, getippt = "") =>
    stackSchreiben("POST", "/docker/stacks", { name, text, bestaetigt, getippt }),
  /** stackSpeichern schreibt die Compose-Datei.
   *
   *  Lehnt der Compose-Prüfer ab, antwortet der Server mit 400 UND den Befunden
   *  — Dienst, Feld, Wert, Grund. Geschrieben wurde dann nichts. */
  stackSpeichern: (name: string, text: string, bestaetigt = false, getippt = "") =>
    stackSchreiben("PUT", `/docker/stacks/${encodeURIComponent(name)}`, {
      text,
      bestaetigt,
      getippt,
    }),
  /** stackAktion: up, down, pull, restart, loeschen. Die Stufe der Rückfrage
   *  entscheidet der Server — „up" ist Stufe 1, außer der Prüfer meldet einen
   *  Bind-Mount nach draußen; dann Stufe 3 mit dem Stack-Namen. */
  stackAktion: (
    name: string,
    aktion: string,
    mitVolumes = false,
    bestaetigt = false,
    getippt = "",
  ) =>
    anfrage<VorgangGestartet>(`/docker/stacks/${encodeURIComponent(name)}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ aktion, mit_volumes: mitVolumes, bestaetigt, getippt }),
    }),

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

  // ------------------------------------------------------- Systembenutzer ---

  systembenutzer: () => anfrage<Systembenutzer>("/system-users"),

  /** schluessel holt die authorized_keys eines Kontos. Eigener Aufruf, weil die
   *  Liste für dreißig Dienstkonten nichts mitschleppen soll, was nur beim
   *  Anklicken eines einzelnen interessiert. */
  schluessel: (konto: string) =>
    anfrage<Schluesselliste>(`/system-users/${encodeURIComponent(konto)}/keys`),

  /** kontoHandlung ist der eine Aufruf für alle verändernden Endpunkte —
   *  dieselbe Überlegung wie bei dateiHandlung: Der Rückweg über
   *  BestaetigungNoetig ist derselbe, und fünf Fassungen wären fünf Stellen, an
   *  denen die Bestätigung nicht durchgereicht wird.
   *
   *  wohin ist der Pfad hinter /system-users: "" zum Anlegen, sonst
   *  "/{name}/locked", "/{name}/delete", "/{name}/keys" oder
   *  "/{name}/keys/remove". */
  kontoHandlung: (
    wohin: string,
    felder: Partial<Kontoauftrag>,
    bestaetigt = false,
    getippt = "",
  ) =>
    anfrage<Kontoantwort>(`/system-users${wohin}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        name: "",
        notiz: "",
        schale: "",
        gruppen: [],
        schluessel: "",
        fingerprint: "",
        gesperrt: false,
        home_entfernen: false,
        ...felder,
        bestaetigt,
        getippt,
      }),
    }),

  // ----------------------------------------------------------------- Update ---

  panelupdate: () => anfrage<Panelupdate>("/update"),

  /** updatestand ist der Poller. Er wird auch dann gefragt, wenn der Dienst
   *  gerade neu startet — dann scheitert der Aufruf, und DAS ist der Normalfall
   *  dieses Moduls und kein Fehler. Der Aufrufer behandelt es entsprechend. */
  updatestand: () => anfrage<Updatestand>("/update/status"),

  updatePruefen: () =>
    anfrage<Updateantwort>("/update/check", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: "{}",
    }),

  /** updateHandlung stößt Einspielen oder Rückweg an. wohin ist "/apply" oder
   *  "/rollback". Beide antworten 202: angenommen, nicht ausgeführt — der Vorgang
   *  läuft in einer eigenen Unit weiter und beendet dabei diesen Dienst. */
  updateHandlung: (wohin: string, bestaetigt = false, getippt = "") =>
    anfrage<Updateantwort>(`/update${wohin}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ bestaetigt, getippt }),
    }),

  // ------------------------------------------------------------- Zertifikat ---

  zertifikat: () => anfrage<Zertifikat>("/certificate"),

  /** zertifikatSpeichern übernimmt die Einstellungen. Wirft BestaetigungNoetig
   *  beim Rückschritt auf ein selbstsigniertes Zertifikat — danach warnt jeder
   *  Browser, und das ist ein Fall für eine Rückfrage. */
  zertifikatSpeichern: (felder: Partial<Zertifikatauftrag>, bestaetigt = false, getippt = "") =>
    anfrage<Zertifikatantwort>("/certificate", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        modus: "selfsigned",
        email: "",
        namenstext: "",
        pruefmethode: "",
        anbieter: "",
        hook_setzen: "",
        hook_aufraeumen: "",
        token: "",
        testverzeichnis: false,
        ...felder,
        bestaetigt,
        getippt,
      }),
    }),

  /** zertifikatBeziehen stößt einen sofortigen Bezug an. Der Verlauf kommt über
   *  den Vorgangsstrom (/api/v1/jobs/certificate/events) — derselbe Weg wie beim
   *  Paketvorgang. */
  zertifikatBeziehen: () =>
    anfrage<Zertifikatantwort>("/certificate/obtain", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: "{}",
    }),

  // --------------------------------------------------------- Eigenes Konto ---

  konto: () => anfrage<EigenesKonto>("/account"),

  /** kontoHandlung2 ist der eine Aufruf für die verändernden Endpunkte des
   *  eigenen Kontos.
   *
   *  wohin ist der Pfad hinter /account: "/password", "/recovery-codes", "/2fa",
   *  "/2fa/confirm", "/2fa/cancel", "/sessions/revoke", "/sessions/revoke-others"
   *  oder "/passkeys/{id}/rename" und "/passkeys/{id}/delete".
   *
   *  Erneuert die Handlung die Sitzung — das tut die Passwortänderung, weil sie
   *  ALLE Sitzungen des Kontos beendet, auch die eigene —, kommt ein frisches
   *  CSRF-Token in der Antwort. Es wird hier übernommen und nicht vom Aufrufer:
   *  Wer das vergäße, hätte eine Oberfläche, die nach einer geglückten Änderung
   *  bei jedem weiteren Aufruf „Sitzungstoken passt nicht" meldet. */
  async kontoHandlung2(
    wohin: string,
    felder: Partial<Kontoauftrag2>,
    bestaetigt = false,
    getippt = "",
  ): Promise<Kontoantwort2> {
    const antwort = await anfrage<Kontoantwort2>(`/account${wohin}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        passwort: "",
        neu: "",
        neu_wiederholt: "",
        code: "",
        sitzung: "",
        name: "",
        ...felder,
        bestaetigt,
        getippt,
      }),
    });
    if (antwort.csrf) token = antwort.csrf;
    return antwort;
  },

  /** passkeyAnlegen führt die WebAuthn-Zeremonie durch: Optionen holen, das
   *  Gerät fragen, den Nachweis einschicken.
   *
   *  Die Umrechnung base64url ↔ ArrayBuffer steht hier, weil sie hierher gehört:
   *  WebAuthn arbeitet mit Puffern, JSON kann keine tragen. Das ist keine
   *  Eigenheit dieses Panels, sondern der Schnittstelle — und es ist genau die
   *  Stelle, an der eine eigene Umsetzung schiefgeht.
   *
   *  Der Server prüft den Nachweis; dass er das durch die ganze Kette hindurch
   *  tut, ist mit einem virtuellen Authenticator im Browser nachgewiesen
   *  (internal/httpd/passkey_e2e_test.go). Hier wird nichts geprüft und nichts
   *  entschieden. */
  async passkeyAnlegen(name: string, passwort: string): Promise<Kontoantwort2> {
    if (!window.PublicKeyCredential || !navigator.credentials) {
      throw new Error(
        "Dieser Browser kennt keine Passkeys. Die Anmeldung mit Passwort und " +
          "zweitem Faktor bleibt unverändert.",
      );
    }
    const beginn = await anfrage<PasskeyBeginn>("/account/passkeys/register/begin", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ passwort, name }),
    });

    // Die Optionen kommen durchgereicht, wie go-webauthn sie baut: Was hier
    // umgerechnet wird, sind genau die Felder, die als Puffer verlangt werden.
    const optionen = beginn.optionen as {
      challenge: string;
      user: { id: string };
      excludeCredentials?: { id: string; type: string; transports?: string[] }[];
    };
    const publicKey = {
      ...optionen,
      challenge: b64urlZuPuffer(optionen.challenge),
      user: { ...optionen.user, id: b64urlZuPuffer(optionen.user.id) },
      excludeCredentials: optionen.excludeCredentials?.map((c) => ({
        ...c,
        id: b64urlZuPuffer(c.id),
      })),
    } as PublicKeyCredentialCreationOptions;

    const cred = (await navigator.credentials.create({ publicKey })) as PublicKeyCredential | null;
    if (!cred) throw new Error("Das Gerät hat keinen Passkey geliefert.");
    const antwortDesGeraets = cred.response as AuthenticatorAttestationResponse;

    const nachweis = {
      id: cred.id,
      rawId: pufferZuB64url(cred.rawId),
      type: cred.type,
      clientExtensionResults: cred.getClientExtensionResults?.() ?? {},
      response: {
        clientDataJSON: pufferZuB64url(antwortDesGeraets.clientDataJSON),
        attestationObject: pufferZuB64url(antwortDesGeraets.attestationObject),
        transports: antwortDesGeraets.getTransports?.() ?? [],
      },
    };

    const antwort = await anfrage<Kontoantwort2>("/account/passkeys/register/finish", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ticket: beginn.ticket, name, nachweis }),
    });
    if (antwort.csrf) token = antwort.csrf;
    return antwort;
  },

  // -------------------------------------------------------- Panel-Zugänge ---

  panelzugaenge: () => anfrage<Panelzugaenge>("/panel-users"),

  /** panelHandlung ist der eine Aufruf für alle verändernden Endpunkte.
   *
   *  wohin ist der Pfad hinter /panel-users: "" zum Anlegen, sonst
   *  "/{id}/disabled", "/{id}/delete", "/{id}/reset-password", "/{id}/reset-2fa"
   *  oder "/{id}/reset-passkeys".
   *
   *  eigenes_passwort steht im Körper und nirgends sonst: Es wird nicht
   *  gespeichert, nicht in den Zustand geschrieben und nach dem Aufruf im
   *  Eingabefeld gelöscht. */
  panelHandlung: (
    wohin: string,
    felder: Partial<Panelauftrag>,
    bestaetigt = false,
    getippt = "",
  ) =>
    anfrage<Panelantwort>(`/panel-users${wohin}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        name: "",
        rolle: "",
        gesperrt: false,
        eigenes_passwort: "",
        ...felder,
        bestaetigt,
        getippt,
      }),
    }),

  // ------------------------------------------------------------ API-Tokens ---

  tokens: () => anfrage<Tokens>("/tokens"),

  /** tokenAnlegen legt einen Token an. Die Antwort trägt den Klartext GENAU
   *  EINMAL — die Seite zeigt ihn in einem Dialog, der geschlossen werden muss,
   *  und schreibt ihn nirgends hin. */
  tokenAnlegen: (auftrag: Tokenauftrag, bestaetigt = false, getippt = "") =>
    anfrage<Tokenantwort>("/tokens", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ...auftrag, bestaetigt, getippt }),
    }),

  tokenWiderrufen: (id: number, bestaetigt = false, getippt = "") =>
    anfrage<Tokenantwort>(`/tokens/${id}/revoke`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ bestaetigt, getippt }),
    }),

  // ------------------------------------------------------------- Zeitpläne ---

  zeitplaene: () => anfrage<Zeitplaene>("/schedules"),

  /** cronSpeichern legt einen verwalteten Eintrag an oder ersetzt ihn.
   *
   *  Die Rückfragestufe steht nicht hier, sondern im Handler: Ein Eintrag als
   *  root fragt mit dem Hostnamen zurück, einer als anderer Benutzer mit einem
   *  zweiten Klick. Eine zweite Liste davon im Browser wäre die Stelle, an der
   *  eine neue Stufe stillschweigend niedriger ausfällt. */
  cronSpeichern: (auftrag: Cronauftrag, bestaetigt = false, getippt = "") =>
    anfrage<Zeitplanantwort>("/schedules/cron", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ ...auftrag, bestaetigt, getippt }),
    }),

  cronLoeschen: (name: string, bestaetigt = false, getippt = "") =>
    anfrage<Zeitplanantwort>(`/schedules/cron/${encodeURIComponent(name)}/delete`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ bestaetigt, getippt }),
    }),

  /** timerLauf fragt nach dem letzten Lauf der Unit, die der Timer auslöst. */
  timerLauf: (unit: string) =>
    anfrage<Timerlauf>(`/schedules/timers/${encodeURIComponent(unit)}/run`),

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
