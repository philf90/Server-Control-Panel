// CodeMirror — der einzige Ort im Projekt, an dem es importiert wird.
//
// Diese Datei wird AUSSCHLIESSLICH über ein dynamisches import() geladen (siehe
// komponenten/Editor.svelte). Das ist keine Stilfrage, sondern der Grund, warum
// sie eine eigene Datei ist: Vite macht daraus einen eigenen Brocken, und der
// wird erst geholt, wenn jemand eine Datei bearbeitet. Ein Panel, das für die
// Übersicht 350 KiB Editor mitlädt, wäre auf einer schlechten Leitung eine
// Zumutung — und zwar für alle, nicht nur für die, die editieren.
//
// Warum CodeMirror hier im Vite-Bundle liegt und nicht als eigenständiges Skript
// daneben. Es lag einmal daneben: packaging/editor/ baute ein eigenes cm.js für
// die Editorseite der alten Oberfläche, mit eigenem Lockfile und eigenem
// Reproduzierbarkeits-Job. Diese Kette ist mit ihr abgebaut, und zwar aus drei
// Gründen, die auch gegen ihre Rückkehr sprechen:
//
//   * Getippt statt data-Attribute. Die alte Fassung war eine IIFE, die eine
//     <textarea> ersetzte und über data-Attribute gesteuert wurde — in einer SPA
//     hieße das, einen Zustand außerhalb von Svelte zu halten und ihn beim
//     Wechsel des Eintrags von Hand aufzuräumen.
//   * EIN Bauweg. Zwei Node-Ketten mit zwei Lockfiles und zwei
//     Reproduzierbarkeits-Jobs sind zwei Gelegenheiten, dass eine davon
//     veraltet.
//   * Eine Auswahl an Modulen, nicht zwei, die auseinanderlaufen können.
//
// Bewusst NICHT "basic-setup": Das Paket zieht Autovervollständigung, Linter,
// Suchleiste und Faltung mit hinein — Funktionen, die ein Panel zum Bearbeiten
// einer Konfigurationsdatei nicht braucht und die den Brocken verdoppeln. Was
// hier steht, ist die Auswahl, die tatsächlich benutzt wird.
//
// Zur Content-Security-Policy — die Stelle, an der dieses Projekt schon zweimal
// gescheitert ist, und an der es beim ersten Anlauf dieses Moduls wieder
// gescheitert wäre:
//
// CodeMirror trägt seine Stilregeln NICHT über CSSOM in ein vorhandenes
// Stylesheet ein, sondern legt ein eigenes <style>-Element an (style-mod). Unter
// `style-src 'self'` verwirft Chromium das, und der Editor bleibt ungestylt —
// kein Rahmen, keine Monoschrift, keine Zeilennummernspalte. Der Browsertest hat
// es gemessen; die erste Fassung dieses Kommentars behauptete das Gegenteil, und
// sie war falsch.
//
// Der Ausweg ist ein Nonce für genau dieses Element: Der Server zieht ihn je
// Antwort neu, legt ihn in ein <meta> der Hülle und nennt ihn in der Richtlinie
// (internal/httpd/handlers_v2.go). CodeMirror gibt ihn über EditorView.cspNonce
// an style-mod weiter. Nicht 'unsafe-inline': Damit wäre jeder eingeschleuste
// Stil erlaubt, und Stile können Inhalte verdecken oder Eingaben abfließen lassen
// — etwa über einen Hintergrundbild-Selektor auf einem Passwortfeld.
//
// Dieselbe Lösung wie auf der Editorseite der alten Oberfläche. Sie steht dort
// seit 0.3.0 und ist begründet bei cspMitStilNonce in middleware.go.

import { EditorState, type Extension } from "@codemirror/state";
import {
  EditorView,
  keymap,
  lineNumbers,
  highlightActiveLine,
  highlightActiveLineGutter,
  highlightSpecialChars,
  drawSelection,
  rectangularSelection,
} from "@codemirror/view";
import { defaultKeymap, history, historyKeymap, indentWithTab } from "@codemirror/commands";
import {
  syntaxHighlighting,
  defaultHighlightStyle,
  bracketMatching,
  indentUnit,
  StreamLanguage,
} from "@codemirror/language";
import { yaml } from "@codemirror/lang-yaml";
import { json } from "@codemirror/lang-json";
import { shell } from "@codemirror/legacy-modes/mode/shell";
import { properties } from "@codemirror/legacy-modes/mode/properties";
import { nginx } from "@codemirror/legacy-modes/mode/nginx";
import { dockerFile } from "@codemirror/legacy-modes/mode/dockerfile";
import { toml } from "@codemirror/legacy-modes/mode/toml";

/** sprache wählt die Hervorhebung. Die Zuordnung kommt vom Server, weil dort der
 *  ganze Pfad bekannt ist: /etc/nginx/sites-enabled/beispiel hat keine Endung,
 *  ist aber nginx-Syntax. */
function sprache(name: string): Extension {
  switch (name) {
    case "yaml":
      return yaml();
    case "json":
      return json();
    case "shell":
      return StreamLanguage.define(shell);
    case "ini":
      return StreamLanguage.define(properties);
    case "nginx":
      return StreamLanguage.define(nginx);
    case "dockerfile":
      return StreamLanguage.define(dockerFile);
    case "toml":
      return StreamLanguage.define(toml);
    default:
      return [];
  }
}

// Die Farben kommen aus den Marken des Gestaltungssystems (app.css), damit der
// Editor nicht sein eigenes Thema mitbringt: Ein helles Schema wird dort einmal
// abgeleitet und gilt dann auch hier.
const thema = EditorView.theme(
  {
    "&": {
      fontSize: "0.82rem",
      border: "1px solid var(--line)",
      borderRadius: "8px",
      backgroundColor: "var(--bg)",
      color: "var(--tx)",
      // Nicht mehr als der Bildschirm: Ein Editor, der die Seite auf
      // viertausend Zeilen streckt, verliert die Knöpfe unter sich.
      maxHeight: "62vh",
    },
    "&.cm-focused": {
      outline: "2px solid var(--accent-dim)",
      outlineOffset: "1px",
    },
    ".cm-scroller": {
      fontFamily: "var(--mono)",
      lineHeight: "1.55",
      overflow: "auto",
    },
    ".cm-gutters": {
      backgroundColor: "var(--surface)",
      color: "var(--tx-faint)",
      border: "none",
      borderRight: "1px solid var(--line)",
    },
    ".cm-activeLineGutter": {
      backgroundColor: "var(--surface2)",
      color: "var(--tx-mut)",
    },
    ".cm-activeLine": { backgroundColor: "rgba(255, 255, 255, 0.03)" },
    ".cm-cursor, .cm-dropCursor": { borderLeftColor: "var(--accent)" },
    "&.cm-focused .cm-selectionBackground, .cm-selectionBackground, ::selection": {
      backgroundColor: "var(--surface3)",
    },
    ".cm-content": { caretColor: "var(--accent)" },
  },
  { dark: true },
);

/** Griff ist, was die Komponente von einem laufenden Editor braucht. Absichtlich
 *  klein: Alles Weitere — Sprache, Konflikt, Speichern — ist Sache von Svelte,
 *  und ein Griff, der mehr kann, wäre die zweite Stelle mit Zustand. */
export type Griff = {
  /** inhalt liest den aktuellen Text. */
  inhalt(): string;
  /** ersetzen setzt den Text neu — nach einem Konflikt, wenn der fremde Stand
   *  übernommen wird. */
  ersetzen(text: string): void;
  /** fokus setzt den Schreibzeiger in den Editor. */
  fokus(): void;
  /** zerstoeren gibt den Editor frei. MUSS beim Verlassen aufgerufen werden:
   *  CodeMirror hängt Horcher am Fenster, und ohne das bleiben sie samt dem
   *  ganzen Zustand liegen. */
  zerstoeren(): void;
};

/** erzeuge baut einen Editor in das gegebene Element.
 *
 *  beiAenderung wird bei jeder Änderung gerufen — die Komponente hängt daran das
 *  „ungespeichert"-Kennzeichen. Nicht der Inhalt wird durchgereicht, sondern nur
 *  die Tatsache: Ihn bei jedem Tastendruck durch Svelte zu schleifen hieße, den
 *  Text zweimal zu halten und bei 4000 Zeilen jedes Zeichen zu kopieren. */
/** stilNonce liest den Wert, den der Server in die Hülle gelegt hat.
 *
 *  Fehlt er, wird er nicht erfunden: Dann läuft der Editor ohne Nonce, die
 *  Richtlinie verwirft seine Stile, und das ist im Browsertest sichtbar. Ein
 *  stillschweigender Rückfall auf „irgendetwas" wäre die schlechtere Antwort —
 *  er würde denselben Fehler erzeugen und ihn zusätzlich verstecken. */
function stilNonce(): string {
  return (
    document.querySelector<HTMLMetaElement>('meta[name="csp-nonce"]')?.content ?? ""
  );
}

export function erzeuge(
  ziel: HTMLElement,
  opt: { inhalt: string; sprache: string; beiAenderung: () => void },
): Griff {
  const nonce = stilNonce();

  const ansicht = new EditorView({
    parent: ziel,
    state: EditorState.create({
      doc: opt.inhalt,
      extensions: [
        // Muss VOR allem stehen, was Stile mitbringt: style-mod legt sein
        // <style>-Element beim ersten Anhängen an, und der Nonce muss dann schon
        // bekannt sein.
        ...(nonce ? [EditorView.cspNonce.of(nonce)] : []),
        lineNumbers(),
        highlightActiveLineGutter(),
        highlightActiveLine(),
        // Unsichtbares sichtbar: Ein NBSP in einer Konfigurationsdatei ist ein
        // Fehler, den man sonst nicht findet.
        highlightSpecialChars(),
        history(),
        drawSelection(),
        rectangularSelection(),
        bracketMatching(),
        syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
        // Tabulator rückt ein, statt den Fokus zu verlassen. In einem Editor ist
        // das die Erwartung; die Tastaturbedienung des Panels bleibt über Escape
        // und die Schaltflächen erreichbar.
        keymap.of([...defaultKeymap, ...historyKeymap, indentWithTab]),
        indentUnit.of("  "),
        sprache(opt.sprache),
        thema,
        EditorView.updateListener.of((u) => {
          if (u.docChanged) opt.beiAenderung();
        }),
      ],
    }),
  });

  return {
    inhalt: () => ansicht.state.doc.toString(),
    ersetzen(text: string) {
      ansicht.dispatch({
        changes: { from: 0, to: ansicht.state.doc.length, insert: text },
      });
    },
    fokus: () => ansicht.focus(),
    zerstoeren: () => ansicht.destroy(),
  };
}
