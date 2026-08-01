// Der Weg: welche Seite steht, und was darauf ausgewählt ist.
//
// Ein eigener Router von etwa achtzig Zeilen und keine Bibliothek. Das Panel hat
// eine Handvoll flacher Seiten und keine verschachtelten Layouts, keine
// Datenlader pro Route, keine Übergänge — was eine Router-Bibliothek trägt,
// braucht hier niemand, und sie wäre die zweite Stelle, an der die Liste der
// Seiten steht (die erste ist lib/ziele.ts).
//
// Alles unter / liefert derselbe Handler dieselbe Hülle aus
// (internal/httpd/handlers_v2.go). Ein Neuladen auf /dienste?unit=nginx
// funktioniert deshalb — die Auswahl in der Adresse ist teilbar und kein
// Zustand, der beim ersten Aufruf fehlt.

/** BASIS ist der Pfad, unter dem die neue Oberfläche liegt.
 *
 *  Seit dem Umschalten (0.4.0) ist er leer: Die Oberfläche liegt an der Wurzel.
 *  Bis dahin war er "/v2" — und dass diese Datei die einzige war, die es merken
 *  musste, war der Zweck der Konstante.
 *
 *  Ein leerer Wert heißt für gehe(): Jeder Pfad, der mit "/" beginnt und nicht
 *  ausdrücklich fremd ist, gehört dieser Anwendung. Fremd sind nur noch die
 *  server-gerenderten Seiten vor der Anmeldung — siehe die Liste weiter unten.
 *
 *  Hier stand daneben eine Konstante ALT = "/alt/" für die eingefrorene alte
 *  Fläche. Sie war eine Fassung lang als Rückweg erreichbar (0.4.0) und ist mit
 *  0.4.1 abgebaut. */
export const BASIS = "";

/** Seite ist die Kennung der stehenden Seite — dieselbe wie die id des Ziels in
 *  lib/ziele.ts, damit die Seitenleiste ohne eine zweite Zuordnung weiß, welcher
 *  Punkt hervorgehoben ist. */
export type Seite =
  | "uebersicht"
  | "dienste"
  | "pakete"
  | "logs"
  | "firewall"
  | "dateien"
  | "audit"
  | "benutzer"
  | "zugaenge"
  | "konto"
  | "zertifikate"
  | "panelupdate"
  | "zeitplaene"
  | "tokens"
  | "docker"
  | "bald";

/** gebauteSeiten sind die Kennungen, die eine eigene Seite haben. Als Objekt und
 *  nicht als switch, damit die Liste einmal steht — sie ist auch die Antwort auf
 *  die Frage, was schon da ist. */
const gebauteSeiten: Record<string, Seite> = {
  dienste: "dienste",
  pakete: "pakete",
  logs: "logs",
  firewall: "firewall",
  dateien: "dateien",
  audit: "audit",
  benutzer: "benutzer",
  zugaenge: "zugaenge",
  konto: "konto",
  zertifikate: "zertifikate",
  updates: "panelupdate",
  cron: "zeitplaene",
  tokens: "tokens",
  docker: "docker",
};

/** angekuendigt sind die Module, die es noch nicht gibt, die aber im Menü stehen.
 *
 *  Sie brauchen einen eigenen Zustand, weil die Alternative schlechter ist: Bis
 *  hierher zeigten sie auf / und landeten stillschweigend auf der Übersicht —
 *  ein Klick auf „Docker", der die Startseite bringt, sieht wie ein Fehler aus.
 *  Der Wert ist die Fassung, mit der das Modul kommt; die Seite sagt es.
 *
 *  Die Zahlen stehen in docs/16-neukonzeption.md §5 und sind hier ihre zweite
 *  Fassung — sie standen bis 0.4.1 um eine Stufe zu hoch (Docker „ab 0.6"
 *  statt 0.5), weil Cron beim Schreiben noch als eigene Stufe gezählt wurde.
 *  Eine Auskunft, die sich um eine Fassung irrt, ist schlimmer als keine: Sie
 *  ist genau die Sorte Angabe, die niemand nachprüft. Wer hier etwas ändert,
 *  ändert es dort mit. */
export const angekuendigt: Record<string, string> = {
  webserver: "0.6",
  datenbanken: "0.7",
  backups: "0.8",
};

/** ohneBasis schneidet das Präfix und die Schrägstriche an den Rändern ab. */
function ohneBasis(pfad: string): string {
  const rest = pfad.startsWith(BASIS) ? pfad.slice(BASIS.length) : pfad;
  return rest.replace(/^\/+|\/+$/g, "");
}

/** modul ist das ERSTE Segment hinter / — für die Seite „bald" die Auskunft,
 *  welches Modul gemeint war.
 *
 *  Bis 0.5.1 gab diese Funktion den ganzen Rest zurück. Das ging, solange kein
 *  Modul eine Unterseite hatte: „/dateien/irgendwas" gab es nicht, die Auswahl
 *  stand immer in der Abfrage. Mit den Unterseiten von Docker gäbe „docker/ports"
 *  weder ein Ziel in der Seitenleiste noch einen Treffer in gebauteSeiten — der
 *  Menüpunkt verlöre seine Hervorhebung und die Seite fiele auf den Rückfall. */
export function modulAus(pfad: string): string {
  const erstes = ohneBasis(pfad).split("/")[0];
  return erstes ?? "";
}

/** unterAus ist der Rest hinter dem Modul: „ports" bei /docker/ports, leer bei
 *  /docker. Leer heißt „die Vorgabe des Moduls" und nicht „unbekannt" — deshalb
 *  eine Zeichenkette und kein null. */
export function unterAus(pfad: string): string {
  const teile = ohneBasis(pfad).split("/");
  return teile.slice(1).join("/");
}

function seiteAus(pfad: string): Seite {
  // Ohne Schrägstriche vergleichen: /dienste und /dienste/ sind dieselbe
  // Seite, und ein Verweis mit oder ohne den letzten Strich soll nicht der
  // Unterschied zwischen einer Seite und dem leeren Zustand sein.
  const rest = modulAus(pfad);
  if (gebauteSeiten[rest]) return gebauteSeiten[rest];
  if (angekuendigt[rest]) return "bald";
  return "uebersicht";
}

class Weg {
  seite = $state<Seite>(seiteAus(location.pathname));
  /** modul ist die Kennung aus der Adresse. Für die gebauten Seiten dasselbe wie
   *  seite; für „bald" die einzige Auskunft darüber, welches Modul gemeint war. */
  modul = $state<string>(modulAus(location.pathname));
  /** unterseite ist das zweite Segment der Adresse — die Fläche innerhalb eines
   *  Moduls. Leer heißt: die Vorgabe des Moduls. */
  unterseite = $state<string>(unterAus(location.pathname));
  /** parameter ist die Abfrage der Adresse als einfaches Objekt. Als Objekt und
   *  nicht als URLSearchParams, weil Svelte Änderungen an einem Objekt
   *  beobachtet und an einer Instanz mit interner Liste nicht. */
  parameter = $state<Record<string, string>>(paramAus(location.search));

  constructor() {
    // Zurück und Vorwärts im Browser sind Teil der Bedienung, nicht ein Fall,
    // der nebenbei auch noch funktionieren soll: Der Zurück-Knopf schließt den
    // Inspektor, und auf einem Telefon ist er der Weg, den man nimmt.
    window.addEventListener("popstate", () => {
      this.seite = seiteAus(location.pathname);
      this.modul = modulAus(location.pathname);
      this.unterseite = unterAus(location.pathname);
      this.parameter = paramAus(location.search);
    });
  }

  /** gehe wechselt die Seite ohne Neuladen. Fremde Ziele — die alte Oberfläche
   *  unter / — gibt es an den Browser weiter; ein Router, der sie abfängt, würde
   *  eine leere Seite zeigen. Die Antwort sagt, ob übernommen wurde. */
  gehe(href: string): boolean {
    if (!eigenesZiel(href)) return false;
    const ziel = new URL(href, location.origin);
    history.pushState(null, "", ziel.pathname + ziel.search);
    this.seite = seiteAus(ziel.pathname);
    this.modul = modulAus(ziel.pathname);
    this.unterseite = unterAus(ziel.pathname);
    this.parameter = paramAus(ziel.search);
    // Nach einem Seitenwechsel steht der Blick sonst dort, wo er auf der
    // vorigen Seite war — in der Mitte einer Liste, die es nicht mehr gibt.
    window.scrollTo({ top: 0 });
    return true;
  }

  /** setze schreibt einen Abfrageparameter. Leerer Wert entfernt ihn.
   *
   *  ersetzen entscheidet über den Verlauf, und das ist der überlegte Teil: Die
   *  erste Auswahl auf einer Seite ist ein Schritt (pushState) — der
   *  Zurück-Knopf schließt dann den Inspektor. Der Wechsel von einer Auswahl zur
   *  nächsten ersetzt (replaceState), sonst müsste man nach zehn angesehenen
   *  Diensten zehnmal zurück, um die Seite zu verlassen. */
  setze(name: string, wert: string): void {
    const vorher = this.parameter[name] ?? "";
    if (vorher === wert) return;

    const ziel = new URL(location.href);
    if (wert) {
      ziel.searchParams.set(name, wert);
    } else {
      ziel.searchParams.delete(name);
    }

    const ersetzen = vorher !== "" && wert !== "";
    if (ersetzen) {
      history.replaceState(null, "", ziel.pathname + ziel.search);
    } else {
      history.pushState(null, "", ziel.pathname + ziel.search);
    }
    this.parameter = paramAus(ziel.search);
  }

  /** setzeAlle schreibt mehrere Parameter auf einmal und entscheidet den Verlauf
   *  selbst. Leerer Wert entfernt den Parameter.
   *
   *  Zwei Gründe für diese Fassung neben setze:
   *
   *   1. Ein Wechsel des Verzeichnisses im Dateimanager ändert drei Parameter —
   *      Pfad, Auswahl, Suchbegriff. Mit drei setze-Aufrufen wären das drei
   *      Einträge im Verlauf, und der Zurück-Knopf käme in einen Zwischenzustand,
   *      den nie jemand gesehen hat.
   *   2. setze entscheidet den Verlauf aus dem vorigen Wert („erste Auswahl ist
   *      ein Schritt"). Beim Blättern durch Verzeichnisse ist das falsch: Jeder
   *      Schritt hinein ist einer, und man will Ebene für Ebene zurück. Deshalb
   *      sagt der Aufrufer es hier ausdrücklich. */
  setzeAlle(werte: Record<string, string>, schritt = false): void {
    const ziel = new URL(location.href);
    let geaendert = false;
    for (const [name, wert] of Object.entries(werte)) {
      if ((this.parameter[name] ?? "") === wert) continue;
      geaendert = true;
      if (wert) {
        ziel.searchParams.set(name, wert);
      } else {
        ziel.searchParams.delete(name);
      }
    }
    if (!geaendert) return;

    if (schritt) {
      history.pushState(null, "", ziel.pathname + ziel.search);
    } else {
      history.replaceState(null, "", ziel.pathname + ziel.search);
    }
    this.parameter = paramAus(ziel.search);
  }

  /** zurueck nimmt einen Schritt im Verlauf zurück — der Weg, auf dem der
   *  Inspektor geschlossen wird, damit Escape und der Zurück-Knopf dasselbe
   *  tun. */
  zurueck(): void {
    history.back();
  }
}

/** eigenesZiel sagt, ob dieser Pfad von der eigenen Anwendung bedient wird.
 *
 *  Mit leerem BASIS ist die Frage umgekehrt zu vorher: Nicht „liegt er unter
 *  /v2?", sondern „ist er ausdrücklich fremd?". Fremd sind die
 *  server-gerenderten Dauerseiten (Anmeldung, Erstinstallation, Abmelden,
 *  erzwungener Wechsel) und alles unter /api/ und /static/. Sie abzufangen hieße,
 *  eine leere Seite zu zeigen — der eigene Router hat für sie keine Ansicht.
 *
 *  Bis 0.4.1 stand hier zusätzlich /alt/, die eingefrorene alte Fläche. Sie ist
 *  abgebaut; ihre Pfade sind jetzt gewöhnliche 404. */
const fremd = ["/login", "/logout", "/setup", "/account/password-change",
  "/api/", "/static/", "/healthz", "/events"];

function eigenesZiel(href: string): boolean {
  if (!href.startsWith("/")) return false;
  return !fremd.some((f) => href === f || href.startsWith(f));
}

function paramAus(suche: string): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of new URLSearchParams(suche)) out[k] = v;
  return out;
}

export const weg = new Weg();

/** verweis fängt Klicks auf interne Verweise ab, damit aus einem <a> eine
 *  Wegewahl ohne Neuladen wird — und ein Klick mit gedrückter Steuerungstaste
 *  oder mit der mittleren Maustaste weiter einen neuen Tab öffnet. Das
 *  auszulassen ist die häufigste Art, mit einem eigenen Router die
 *  Verweisfunktion des Browsers zu verlieren. */
export function verweis(e: MouseEvent, href: string): void {
  if (e.defaultPrevented || e.button !== 0) return;
  if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
  if (weg.gehe(href)) e.preventDefault();
}
