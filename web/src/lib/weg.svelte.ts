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
export type Seite = "uebersicht" | "dienste" | "pakete" | "logs";

function seiteAus(pfad: string): Seite {
  const rest = pfad.startsWith(BASIS) ? pfad.slice(BASIS.length) : pfad;
  // Ohne Schrägstriche vergleichen: /v2/dienste und /v2/dienste/ sind dieselbe
  // Seite, und ein Verweis mit oder ohne den letzten Strich soll nicht der
  // Unterschied zwischen einer Seite und dem leeren Zustand sein.
  switch (rest.replace(/^\/+|\/+$/g, "")) {
    case "dienste":
      return "dienste";
    case "pakete":
      return "pakete";
    case "logs":
      return "logs";
    default:
      return "uebersicht";
  }
}

class Weg {
  seite = $state<Seite>(seiteAus(location.pathname));
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
