// Der Weg: welche Seite steht, und was darauf ausgewählt ist.
//
// Ein eigener Router von etwa achtzig Zeilen und keine Bibliothek. Das Panel hat
// eine Handvoll flacher Seiten und keine verschachtelten Layouts, keine
// Datenlader pro Route, keine Übergänge — was eine Router-Bibliothek trägt,
// braucht hier niemand, und sie wäre die zweite Stelle, an der die Liste der
// Seiten steht (die erste ist lib/ziele.ts).
//
// Alles unter /v2/ liefert derselbe Handler dieselbe Hülle aus
// (internal/httpd/handlers_v2.go). Ein Neuladen auf /v2/dienste?unit=nginx
// funktioniert deshalb — die Auswahl in der Adresse ist teilbar und kein
// Zustand, der beim ersten Aufruf fehlt.

/** BASIS ist der Pfad, unter dem die neue Oberfläche liegt. Sie steht neben der
 *  alten und nicht an ihrer Stelle; mit dem Umschalten wird daraus "" und diese
 *  Datei ist die einzige, die es merkt. */
export const BASIS = "/v2";

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
};

/** angekuendigt sind die Module, die es noch nicht gibt, die aber im Menü stehen.
 *
 *  Sie brauchen einen eigenen Zustand, weil die Alternative schlechter ist: Bis
 *  hierher zeigten sie auf /v2/ und landeten stillschweigend auf der Übersicht —
 *  ein Klick auf „Docker", der die Startseite bringt, sieht wie ein Fehler aus.
 *  Der Wert ist die Fassung, mit der das Modul kommt; die Seite sagt es. */
export const angekuendigt: Record<string, string> = {
  cron: "0.5",
  docker: "0.6",
  webserver: "0.7",
  datenbanken: "0.8",
  backups: "0.9",
};

/** modul ist die Kennung hinter /v2/… — für die Seite „bald" die Auskunft,
 *  welches Modul gemeint war. */
export function modulAus(pfad: string): string {
  const rest = pfad.startsWith(BASIS) ? pfad.slice(BASIS.length) : pfad;
  return rest.replace(/^\/+|\/+$/g, "");
}

function seiteAus(pfad: string): Seite {
  // Ohne Schrägstriche vergleichen: /v2/dienste und /v2/dienste/ sind dieselbe
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
      this.parameter = paramAus(location.search);
    });
  }

  /** gehe wechselt die Seite ohne Neuladen. Fremde Ziele — die alte Oberfläche
   *  unter / — gibt es an den Browser weiter; ein Router, der sie abfängt, würde
   *  eine leere Seite zeigen. Die Antwort sagt, ob übernommen wurde. */
  gehe(href: string): boolean {
    if (!href.startsWith(BASIS + "/") && href !== BASIS) return false;
    const ziel = new URL(href, location.origin);
    history.pushState(null, "", ziel.pathname + ziel.search);
    this.seite = seiteAus(ziel.pathname);
    this.modul = modulAus(ziel.pathname);
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
