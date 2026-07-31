// Das Modell hinter dem Compose-Formular — der zweite Leser der Datei.
//
// Diese Datei wird AUSSCHLIESSLICH über ein dynamisches import() geladen (siehe
// komponenten/Composeeditor.svelte), aus demselben Grund wie editorkern.ts: Vite
// macht daraus einen eigenen Brocken samt der yaml-Bibliothek, und wer die
// Docker-Seite nur ansieht, lädt ihn nie.
//
// ── Der Entwurf in drei Sätzen ────────────────────────────────────────────────
//
//  1. **Der TEXT ist die Wahrheit, das Formular ist abgeleitet.** Diese Datei
//     hält keinen Zustand. Jede Änderung aus dem Formular ist: Text parsen,
//     einen Knoten anfassen, Text zurückgeben. Es gibt deshalb kein Dokument,
//     das veralten kann, und keine Lage, in der Formular und Editor
//     auseinanderlaufen — sie können es gar nicht, weil nur einer von beiden
//     etwas hält.
//
//  2. **Geändert wird chirurgisch, nie neu ausgegeben.** Ein vorhandener Skalar
//     bekommt einen neuen Wert, statt durch einen neuen ersetzt zu werden: Nur
//     so überleben die Kommentare, die daran hängen. Für dieses Panel ist das
//     keine Feinheit — Entscheidung E7 in docs/17-docker.md sagt über die
//     Vorlagen wörtlich, die Kommentare seien „der eigentliche Inhalt". Ein
//     Formular, das sie beim ersten Klick auffrisst, hätte die Vorlagen
//     entwertet.
//
//  3. **Was dieser Leser nicht sicher kann, sagt er.** Er ist nicht Compose. Er
//     kennt keine Anker, kein `extends`, keine Merge-Keys, keine
//     Mehrdokumentdateien — und statt sie halb darzustellen, sperrt er den
//     betroffenen Dienst und nennt den Grund. Dieselbe Haltung wie beim
//     Compose-Prüfer, wo „nicht geprüft" nie „in Ordnung" heißt
//     (internal/privops/composepruef.go).
//
// ── Und die Grenze, die bleibt ────────────────────────────────────────────────
//
// Dieser Leser ist der ZWEITE. Der erste ist der Prüfer auf dem Server, und nur
// er entscheidet, was gespeichert und gestartet wird. Das Formular kann irren;
// es kann nichts durchlassen. Wo beide dieselbe Datei verschieden sehen, gilt
// der Server — und genau deshalb darf das Formular nichts verstecken, was es
// nicht versteht.

import {
  isAlias,
  isMap,
  isScalar,
  isSeq,
  parseAllDocuments,
  parseDocument,
  visit,
  type Document,
  type Node,
  type YAMLMap,
  type YAMLSeq,
} from "yaml";

/** dargestellteFelder sind die Felder eines Dienstes, die das Formular zeigt und
 *  schreibt. Alles andere bleibt unangetastet — und wird benannt, damit niemand
 *  glaubt, das Formular zeige den ganzen Dienst. */
const dargestellteFelder = [
  "image",
  "restart",
  "command",
  "ports",
  "volumes",
  "environment",
  "depends_on",
  "networks",
] as const;

/** Portzeile ist eine Zeile unter „ports".
 *
 *  „einfach" trennt die kurze Zeichenkettenform, die das Formular bearbeiten
 *  kann, von der langen Abbildungsform (`{target: 80, published: 8080}`). Die
 *  lange wird angezeigt und nicht angefasst. */
export type Portzeile = {
  adresse: string;
  wirt: string;
  container: string;
  protokoll: string;
  roh: string;
  einfach: boolean;
};

/** Volumezeile ist eine Zeile unter „volumes" — kurze Form `quelle:ziel:modus`. */
export type Volumezeile = {
  quelle: string;
  ziel: string;
  modus: string;
  roh: string;
  einfach: boolean;
};

export type Umgebungszeile = { schluessel: string; wert: string };

/** Umgebungsform merkt sich, in welcher Schreibweise „environment" dasteht.
 *
 *  Compose kennt beide, und ein Formular, das immer die eigene schreibt, würde
 *  eine gepflegte Datei bei der ersten Änderung umformatieren. */
export type Umgebungsform = "abbildung" | "liste";

export type Dienstform = {
  name: string;
  image: string;
  restart: string;
  command: string;
  ports: Portzeile[];
  volumes: Volumezeile[];
  umgebung: Umgebungszeile[];
  umgebungsform: Umgebungsform;
  abhaengig: string[];
  netze: string[];
  /** weitereFelder sind die Felder dieses Dienstes, die das Formular gar nicht
   *  kennt. Sie stehen in der Fläche als Satz — ein Formular, das schweigt,
   *  behauptet, es zeige alles. */
  weitereFelder: string[];
  /** unbedienbar sind Felder, die das Formular KENNT, die hier aber in einer
   *  Gestalt dastehen, die es nicht bearbeiten kann: „command" als Liste,
   *  „depends_on" als Abbildung mit Bedingungen, ein Port in der langen Form.
   *
   *  Sie sind der Grund, warum diese Liste getrennt von weitereFelder steht: Ein
   *  „depends_on", das der Leser nicht als Liste bekommt, würde sonst als leere
   *  Liste im Formular erscheinen — und ein Speichern schriebe die Bedingungen
   *  weg, die niemand gesehen hat. Genau dieser Fall ist beim Schreiben der
   *  Prüfungen aufgefallen. */
  unbedienbar: string[];
  /** gesperrt heißt: anzeigen ja, ändern nein. */
  gesperrt: boolean;
  grund: string;
};

export type Aufbau = {
  /** lesbar sagt, ob der Text überhaupt als YAML durchging. Ist er es nicht,
   *  friert die Fläche ein, statt aus einem halben Dokument zu schreiben. */
  lesbar: boolean;
  fehler: string;
  /** gesperrt gilt für das ganze Dokument (Anker, mehrere Dokumente …). */
  gesperrt: boolean;
  grund: string;
  dienste: Dienstform[];
};

// ------------------------------------------------------------------ Lesen ---

/** lies zerlegt den Text in das Modell. Reine Funktion, kein Zustand. */
export function lies(text: string): Aufbau {
  const leer: Aufbau = { lesbar: true, fehler: "", gesperrt: false, grund: "", dienste: [] };

  // Mehrere Dokumente in einer Datei: Compose liest nur das erste, andere
  // Werkzeuge nicht. Das Formular fasst so etwas nicht an.
  const alle = parseAllDocuments(text);
  if (alle.length > 1) {
    return {
      ...leer,
      gesperrt: true,
      grund:
        "Diese Datei enthält mehrere YAML-Dokumente. Das Formular ändert sie nicht — " +
        "welches davon gilt, entscheidet nicht es, sondern Compose.",
    };
  }

  const doc = parseDocument(text);
  if (doc.errors.length > 0) {
    return { ...leer, lesbar: false, fehler: doc.errors[0].message };
  }

  // Anker und Aliasse. Sie sind der Grund, warum der Prüfer auf dem Server gegen
  // die GERENDERTE Fassung läuft (Entscheidung E4): Was ein Anker hineinzieht,
  // sieht man der Rohdatei nicht an. Ein Formular, das an einer Datei mit Ankern
  // einzelne Knoten verschiebt, kann eine Bedeutung ändern, die es nie gesehen
  // hat.
  if (hatAnkerOderAlias(doc)) {
    return {
      ...leer,
      gesperrt: true,
      grund:
        "Diese Datei benutzt YAML-Anker oder Aliasse. Was sie hineinziehen, sieht das " +
        "Formular nicht — es zeigt die Datei deshalb nur an. Im Texteditor lässt sie sich " +
        "wie gewohnt ändern.",
    };
  }

  const wurzel = doc.contents;
  if (!isMap(wurzel)) {
    // Eine leere Datei ist kein Fehler, sondern der Anfang: Das Formular bietet
    // dann „Dienst hinzufügen" an und legt „services" selbst an.
    if (text.trim() === "") return leer;
    return {
      ...leer,
      gesperrt: true,
      grund: "Diese Datei ist keine Compose-Abbildung — das Formular fasst sie nicht an.",
    };
  }

  const dienste = wurzel.get("services", true);
  if (dienste === undefined || dienste === null) return leer;
  if (!isMap(dienste)) {
    return {
      ...leer,
      gesperrt: true,
      grund: "„services“ ist keine Abbildung von Namen auf Dienste.",
    };
  }

  return { ...leer, dienste: dienste.items.map((paar) => liesDienst(paar)) };
}

function hatAnkerOderAlias(doc: Document.Parsed): boolean {
  let gefunden = false;
  visit(doc, {
    Node(_, knoten) {
      const k = knoten as { anchor?: string };
      if (k.anchor) gefunden = true;
      if (isAlias(knoten)) gefunden = true;
    },
  });
  return gefunden;
}

function liesDienst(paar: { key: unknown; value: unknown }): Dienstform {
  const name = skalartext(paar.key);
  const leer: Dienstform = {
    name,
    image: "",
    restart: "",
    command: "",
    ports: [],
    volumes: [],
    umgebung: [],
    umgebungsform: "abbildung",
    abhaengig: [],
    netze: [],
    weitereFelder: [],
    unbedienbar: [],
    gesperrt: false,
    grund: "",
  };

  const wert = paar.value;
  if (!isMap(wert)) {
    return { ...leer, gesperrt: true, grund: "Dieser Dienst ist keine Abbildung." };
  }
  const karte = wert as YAMLMap;

  // extends und Merge-Keys ziehen Inhalt von woanders herein. Was das Formular
  // hier anzeigte, wäre die halbe Wahrheit — und was es schriebe, könnte die
  // andere Hälfte überstimmen, ohne es zu wissen.
  const schluessel = karte.items.map((p) => skalartext(p.key));
  if (schluessel.includes("extends")) {
    return {
      ...leer,
      gesperrt: true,
      grund:
        "Dieser Dienst erbt über „extends“ von einem anderen. Das Formular sieht nur, " +
        "was hier steht, und ändert ihn deshalb nicht.",
    };
  }
  if (schluessel.includes("<<")) {
    return {
      ...leer,
      gesperrt: true,
      grund: "Dieser Dienst benutzt einen Merge-Key. Das Formular ändert ihn nicht.",
    };
  }

  const umgebung = liesUmgebung(karte.get("environment", true));

  return {
    ...leer,
    image: skalartext(karte.get("image", true)),
    restart: skalartext(karte.get("restart", true)),
    command: skalartext(karte.get("command", true)),
    ports: folgentexte(karte.get("ports", true)).map(liesPort),
    volumes: folgentexte(karte.get("volumes", true)).map(liesVolume),
    umgebung: umgebung.zeilen,
    umgebungsform: umgebung.form,
    abhaengig: folgentexte(karte.get("depends_on", true)),
    netze: folgentexte(karte.get("networks", true)),
    weitereFelder: schluessel.filter(
      (s) => !(dargestellteFelder as readonly string[]).includes(s),
    ),
    unbedienbar: unbedienbareFelder(karte),
  };
}

/** unbedienbareFelder sammelt die Felder, die der Leser kennt und in dieser
 *  Gestalt trotzdem nicht bearbeiten kann.
 *
 *  Die Prüfung ist bewusst „vorhanden, aber falsche Gestalt" und nicht „leer":
 *  Ein fehlendes Feld ist kein Problem, ein vorhandenes in unerwarteter Form
 *  schon — es sähe im Formular aus wie ein leeres. */
function unbedienbareFelder(karte: YAMLMap): string[] {
  const aus: string[] = [];
  const da = (feld: string) => {
    const k = karte.get(feld, true);
    return k !== undefined && k !== null ? k : null;
  };

  for (const feld of ["image", "restart", "command"]) {
    const k = da(feld);
    // „command" als Liste ist die häufigste davon und völlig üblich:
    // command: ["nginx", "-g", "daemon off;"]. Das Textfeld kann sie nicht
    // abbilden, ohne beim Zurückschreiben aus der Liste eine Zeichenkette zu
    // machen.
    if (k !== null && !isScalar(k)) aus.push(feld);
  }
  for (const feld of ["ports", "volumes", "depends_on", "networks"]) {
    const k = da(feld);
    if (k !== null && !isSeq(k)) aus.push(feld);
  }
  const umgebung = da("environment");
  if (umgebung !== null && !isMap(umgebung) && !isSeq(umgebung)) aus.push("environment");

  // Einzelne Zeilen in der langen Form: „ports: [{target: 80, published: 8080}]".
  // Die Folge selbst ist bedienbar, diese Zeile nicht — das Formular zeigt sie
  // als Rohtext und lässt sie in Ruhe. Genannt wird das Feld trotzdem, weil
  // „bedienbar bis auf eine Zeile" niemand am Formular ansieht.
  for (const feld of ["ports", "volumes", "depends_on", "networks"]) {
    const k = da(feld);
    if (k !== null && isSeq(k) && (k as YAMLSeq).items.some((e) => !isScalar(e))) {
      if (!aus.includes(feld)) aus.push(feld);
    }
  }
  return aus;
}

/** folgentexte liest eine Folge von Zeichenketten.
 *
 *  Eine Abbildung statt einer Folge (etwa „depends_on" mit condition, oder
 *  „networks" mit Aliassen) liefert eine LEERE Liste und landet über
 *  weitereFelder in der Anzeige — nicht als halbe Liste, aus der ein Speichern
 *  die Bedingungen wegwürfe. Ein Eintrag, der keine Zeichenkette ist, wird als
 *  Rohtext durchgereicht. */
function folgentexte(knoten: unknown): string[] {
  if (!isSeq(knoten)) return [];
  return (knoten as YAMLSeq).items.map((e) => (isScalar(e) ? String(e.value ?? "") : ""));
}

function liesUmgebung(knoten: unknown): { zeilen: Umgebungszeile[]; form: Umgebungsform } {
  if (isMap(knoten)) {
    return {
      form: "abbildung",
      zeilen: (knoten as YAMLMap).items.map((p) => ({
        schluessel: skalartext(p.key),
        wert: skalartext(p.value),
      })),
    };
  }
  if (isSeq(knoten)) {
    return {
      form: "liste",
      zeilen: folgentexte(knoten).map((zeile) => {
        const trenn = zeile.indexOf("=");
        if (trenn < 0) return { schluessel: zeile, wert: "" };
        return { schluessel: zeile.slice(0, trenn), wert: zeile.slice(trenn + 1) };
      }),
    };
  }
  return { form: "abbildung", zeilen: [] };
}

/** liesPort zerlegt die kurze Form: [adresse:][wirt:]container[/protokoll].
 *
 *  Von rechts gelesen, weil eine IPv6-Adresse Doppelpunkte enthält und ein Zerlegen
 *  von links sie zerschnitte. */
export function liesPort(roh: string): Portzeile {
  const zeile: Portzeile = {
    adresse: "",
    wirt: "",
    container: "",
    protokoll: "",
    roh,
    einfach: true,
  };
  if (roh === "") return { ...zeile, einfach: false };

  let rest = roh;
  const schraeg = rest.lastIndexOf("/");
  if (schraeg >= 0) {
    zeile.protokoll = rest.slice(schraeg + 1);
    rest = rest.slice(0, schraeg);
  }

  const teile = rest.split(":");
  switch (teile.length) {
    case 1:
      zeile.container = teile[0];
      break;
    case 2:
      zeile.wirt = teile[0];
      zeile.container = teile[1];
      break;
    default:
      zeile.container = teile[teile.length - 1];
      zeile.wirt = teile[teile.length - 2];
      zeile.adresse = teile.slice(0, teile.length - 2).join(":");
  }
  return zeile;
}

/** schreibPort setzt die kurze Form wieder zusammen. */
export function schreibPort(z: Portzeile): string {
  if (!z.einfach) return z.roh;
  let aus = z.container;
  if (z.wirt !== "") aus = z.wirt + ":" + aus;
  if (z.adresse !== "") aus = z.adresse + ":" + aus;
  if (z.protokoll !== "") aus += "/" + z.protokoll;
  return aus;
}

/** liesVolume zerlegt die kurze Form: [quelle:]ziel[:modus]. */
export function liesVolume(roh: string): Volumezeile {
  const zeile: Volumezeile = { quelle: "", ziel: "", modus: "", roh, einfach: true };
  if (roh === "") return { ...zeile, einfach: false };

  const teile = roh.split(":");
  switch (teile.length) {
    case 1:
      zeile.ziel = teile[0];
      break;
    case 2:
      zeile.quelle = teile[0];
      zeile.ziel = teile[1];
      break;
    case 3:
      zeile.quelle = teile[0];
      zeile.ziel = teile[1];
      zeile.modus = teile[2];
      break;
    default:
      // Mehr Doppelpunkte, als die kurze Form kennt. Nicht raten: anzeigen und
      // in Ruhe lassen.
      return { ...zeile, einfach: false };
  }
  return zeile;
}

export function schreibVolume(z: Volumezeile): string {
  if (!z.einfach) return z.roh;
  let aus = z.ziel;
  if (z.quelle !== "") aus = z.quelle + ":" + aus;
  if (z.modus !== "") aus += ":" + z.modus;
  return aus;
}

function skalartext(knoten: unknown): string {
  if (isScalar(knoten)) {
    const w = (knoten as { value: unknown }).value;
    return w === null || w === undefined ? "" : String(w);
  }
  return "";
}

// --------------------------------------------------------------- Schreiben ---

/** aenderung ist ein Handgriff am Dokument. Alle Schreiber gehen durch
 *  „umschreiben", damit Parsen, Formatwahl und Rückgabe an EINER Stelle stehen. */
type aenderung = (dienste: YAMLMap, doc: Document.Parsed) => void;

/** umschreiben ist der einzige Weg, auf dem diese Datei Text erzeugt.
 *
 *  Scheitert irgendetwas — unlesbares YAML, gesperrtes Dokument, fehlender
 *  Dienst —, kommt der EINGABETEXT unverändert zurück. Ein Schreiber, der im
 *  Zweifel etwas Eigenes ausgibt, wäre die Fassung, die jemandem beim Tippen die
 *  halbe Datei ersetzt. */
function umschreiben(text: string, tue: aenderung): string {
  const aufbau = lies(text);
  if (!aufbau.lesbar || aufbau.gesperrt) return text;

  const doc = parseDocument(text);
  if (doc.errors.length > 0) return text;

  let wurzel = doc.contents;
  if (!isMap(wurzel)) {
    if (text.trim() !== "") return text;
    doc.contents = doc.createNode({}) as Node;
    wurzel = doc.contents;
    if (!isMap(wurzel)) return text;
  }

  let dienste = (wurzel as YAMLMap).get("services", true);
  if (dienste === undefined || dienste === null) {
    (wurzel as YAMLMap).set("services", doc.createNode({}));
    dienste = (wurzel as YAMLMap).get("services", true);
  }
  if (!isMap(dienste)) return text;

  tue(dienste as YAMLMap, doc);
  return ausgeben(doc, text);
}

/** ausgeben serialisiert und hält dabei zwei Dinge fest, die sonst verloren
 *  gingen.
 *
 *  lineWidth: 0 schaltet den Zeilenumbruch ab. Ohne das faltet yaml lange
 *  Zeichenketten bei 80 Zeichen um — ein „command", das man nie angefasst hat,
 *  stünde nach dem ersten Klick woanders.
 *
 *  indent kommt aus der Datei selbst. yaml schreibt sonst immer zwei Leerzeichen
 *  und formatierte eine mit vier eingerückte Datei bei der ersten Änderung
 *  vollständig um. */
function ausgeben(doc: Document.Parsed, quelle: string): string {
  return doc.toString({ lineWidth: 0, indent: einrueckung(quelle) });
}

/** einrueckung rät die Einrücktiefe aus der Datei — die erste eingerückte Zeile
 *  unter einem Schlüssel entscheidet. Findet sich keine, bleibt es bei zwei. */
export function einrueckung(text: string): number {
  for (const zeile of text.split("\n")) {
    const treffer = /^( +)\S/.exec(zeile);
    if (treffer) {
      const n = treffer[1].length;
      // yaml erlaubt 1 bis 8; alles andere wäre eine Datei, deren Einrückung
      // ohnehin uneinheitlich ist.
      if (n >= 1 && n <= 8) return n;
    }
  }
  return 2;
}

/** setzeSkalar ist der chirurgische Kern.
 *
 *  Der vorhandene Knoten wird WEITERBENUTZT und bekommt nur einen neuen Wert.
 *  Damit bleiben Kommentar, Anführungszeichenstil und Stellung erhalten. Ein
 *  karte.set() legte stattdessen einen neuen Knoten an, und der Kommentar am
 *  alten wäre weg. */
function setzeSkalar(karte: YAMLMap, schluessel: string, wert: string) {
  if (wert === "") {
    karte.delete(schluessel);
    return;
  }
  const vorhanden = karte.get(schluessel, true);
  if (isScalar(vorhanden)) {
    (vorhanden as { value: unknown }).value = wert;
    return;
  }
  karte.set(schluessel, wert);
}

/** setzeFolge gleicht eine Liste zeilenweise ab, statt sie neu zu schreiben.
 *
 *  Zeilen, die bleiben, behalten ihren Knoten samt Kommentar. Nur was sich
 *  wirklich geändert hat, wird angefasst; überzählige Zeilen fallen von hinten
 *  weg. Eine neu ausgegebene Liste hätte alle Kommentare gekostet, auch die an
 *  Zeilen, die niemand angerührt hat. */
function setzeFolge(karte: YAMLMap, schluessel: string, werte: string[], doc: Document.Parsed) {
  const gefiltert = werte.filter((w) => w !== "");
  if (gefiltert.length === 0) {
    karte.delete(schluessel);
    return;
  }

  const vorhanden = karte.get(schluessel, true);
  if (!isSeq(vorhanden)) {
    karte.set(schluessel, doc.createNode(gefiltert));
    return;
  }

  const folge = vorhanden as YAMLSeq;
  for (let i = 0; i < gefiltert.length; i++) {
    const alt = folge.items[i];
    if (i < folge.items.length && isScalar(alt)) {
      if (String((alt as { value: unknown }).value ?? "") !== gefiltert[i]) {
        (alt as { value: unknown }).value = gefiltert[i];
      }
      continue;
    }
    if (i < folge.items.length) {
      folge.items[i] = doc.createNode(gefiltert[i]);
      continue;
    }
    folge.items.push(doc.createNode(gefiltert[i]));
  }
  folge.items.length = gefiltert.length;
}

/** setzeFeld ändert ein einzelnes Textfeld eines Dienstes. */
export function setzeFeld(
  text: string,
  dienst: string,
  feld: "image" | "restart" | "command",
  wert: string,
): string {
  return umschreiben(text, (dienste) => {
    const karte = dienste.get(dienst, true);
    if (isMap(karte)) setzeSkalar(karte as YAMLMap, feld, wert);
  });
}

/** setzeListe ändert eine der Listen eines Dienstes. */
export function setzeListe(
  text: string,
  dienst: string,
  feld: "ports" | "volumes" | "depends_on" | "networks",
  werte: string[],
): string {
  return umschreiben(text, (dienste, doc) => {
    const karte = dienste.get(dienst, true);
    if (isMap(karte)) setzeFolge(karte as YAMLMap, feld, werte, doc);
  });
}

/** setzeUmgebung schreibt „environment" in der Schreibweise zurück, in der es
 *  dasteht. */
export function setzeUmgebung(
  text: string,
  dienst: string,
  zeilen: Umgebungszeile[],
  form: Umgebungsform,
): string {
  return umschreiben(text, (dienste, doc) => {
    const knoten = dienste.get(dienst, true);
    if (!isMap(knoten)) return;
    const karte = knoten as YAMLMap;

    const gefiltert = zeilen.filter((z) => z.schluessel !== "");
    if (gefiltert.length === 0) {
      karte.delete("environment");
      return;
    }

    if (form === "liste") {
      setzeFolge(
        karte,
        "environment",
        gefiltert.map((z) => (z.wert === "" ? z.schluessel : z.schluessel + "=" + z.wert)),
        doc,
      );
      return;
    }

    const vorhanden = karte.get("environment", true);
    if (!isMap(vorhanden)) {
      const neu: Record<string, string> = {};
      for (const z of gefiltert) neu[z.schluessel] = z.wert;
      karte.set("environment", doc.createNode(neu));
      return;
    }

    // Zuerst die Schlüssel, die es nicht mehr gibt — danach die übrigen setzen.
    // Andersherum liefe man Gefahr, einen gerade umbenannten Schlüssel wieder zu
    // löschen.
    const umgebung = vorhanden as YAMLMap;
    const behalten = new Set(gefiltert.map((z) => z.schluessel));
    for (const p of [...umgebung.items]) {
      if (!behalten.has(skalartext(p.key))) umgebung.delete(skalartext(p.key));
    }
    for (const z of gefiltert) setzeSkalarErlaubtLeer(umgebung, z.schluessel, z.wert);
  });
}

/** setzeSkalarErlaubtLeer ist setzeSkalar für Umgebungsvariablen.
 *
 *  Eigene Fassung, weil hier ein leerer Wert etwas bedeutet: „DEBUG=" setzt die
 *  Variable auf leer, und das ist etwas anderes, als sie nicht zu setzen. Beim
 *  Feld „image" wäre dieselbe Regel falsch — dort heißt leer „weg damit". */
function setzeSkalarErlaubtLeer(karte: YAMLMap, schluessel: string, wert: string) {
  const vorhanden = karte.get(schluessel, true);
  if (isScalar(vorhanden)) {
    (vorhanden as { value: unknown }).value = wert;
    return;
  }
  karte.set(schluessel, wert);
}

/** dienstAnlegen hängt einen Dienst an.
 *
 *  Mit „restart: unless-stopped" wie alle drei Vorlagen: Ein Dienst ohne
 *  Neustartregel ist nach dem nächsten Neustart des Servers weg, und das ist der
 *  häufigste Anfängerfehler in einer Compose-Datei. */
export function dienstAnlegen(text: string, name: string): string {
  return umschreiben(text, (dienste, doc) => {
    if (name === "" || dienste.get(name, true) !== undefined) return;
    // „image" steht leer da und wird nicht weggelassen: Das Feld soll im
    // Formular vorhanden sein, und die Datei sagt damit selbst, was ihr fehlt.
    dienste.set(name, doc.createNode({ image: "", restart: "unless-stopped" }));
  });
}

/** dienstEntfernen nimmt einen Dienst samt allem heraus, was darunter steht. */
export function dienstEntfernen(text: string, name: string): string {
  return umschreiben(text, (dienste) => {
    dienste.delete(name);
  });
}

/** dienstNamen ist die Prüfung für den Namen eines neuen Dienstes. Compose
 *  verlangt dieselbe Form wie für ein Projekt. */
export function gueltigerDienstname(name: string): boolean {
  return /^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/.test(name);
}
