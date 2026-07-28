// Der Editor der Dateimanager-Seite.
//
// Bewusst nicht "basic-setup": Das Paket zieht Autovervollständigung, Linter,
// Suchleiste und Faltung mit hinein — Funktionen, die ein Panel zum Bearbeiten
// einer Konfigurationsdatei nicht braucht und die den Bundle verdoppeln. Was
// hier steht, ist die Auswahl, die tatsächlich benutzt wird.
//
// Der Editor ersetzt eine vorhandene <textarea>. Ohne dieses Skript bleibt sie
// stehen und das Formular funktioniert unverändert — nur ohne Zeilennummern und
// ohne Hervorhebung. Beim Absenden wird der Inhalt in die Textarea
// zurückgeschrieben; das Formular schickt weiterhin dasselbe Feld.

import { EditorState } from "@codemirror/state";
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

// sprache wählt die Hervorhebung. Die Zuordnung kommt vom Server (data-sprache),
// weil dort der ganze Pfad bekannt ist: /etc/nginx/sites-enabled/beispiel hat
// keine Endung, ist aber nginx.
function sprache(name) {
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

// Die Farben kommen aus den Marken des Panels, damit Hell- und Dunkelmodus ohne
// zweites Thema mitgehen. CodeMirror trägt seine Regeln über CSSOM ein
// (insertRule), was die Content-Security-Policy nicht betrifft — geprüft in
// Chromium gegen die unveränderte Richtlinie des Panels.
const thema = EditorView.theme({
  "&": {
    fontSize: "0.85rem",
    border: "1px solid var(--line)",
    borderRadius: "8px",
    backgroundColor: "var(--card)",
    color: "var(--fg)",
    maxHeight: "70vh",
  },
  ".cm-scroller": {
    fontFamily: "ui-monospace, SFMono-Regular, Menlo, Consolas, monospace",
    lineHeight: "1.5",
    overflow: "auto",
  },
  ".cm-gutters": {
    backgroundColor: "var(--bg)",
    color: "var(--muted)",
    border: "none",
    borderRight: "1px solid var(--line2)",
  },
  ".cm-activeLine": { backgroundColor: "var(--line2)" },
  ".cm-activeLineGutter": { backgroundColor: "var(--line2)", color: "var(--fg)" },
  ".cm-cursor, .cm-dropCursor": { borderLeftColor: "var(--fg)" },
  "&.cm-focused": { outline: "2px solid var(--accent)", outlineOffset: "1px" },
  "&.cm-focused .cm-selectionBackground, .cm-selectionBackground, ::selection": {
    backgroundColor: "var(--ok-bg)",
  },
  ".cm-specialChar": { color: "var(--danger)" },
});

function starten() {
  const feld = document.getElementById("editor-inhalt");
  if (!feld) return;

  const nonce = feld.dataset.nonce || "";

  const halter = document.createElement("div");
  halter.id = "editor-halter";
  feld.parentNode.insertBefore(halter, feld);
  // Die Textarea bleibt im Formular, nur unsichtbar: Sie ist das Feld, das
  // abgeschickt wird. So bleibt der Weg ohne Skript derselbe Weg.
  feld.style.display = "none";

  const sicht = new EditorView({
    parent: halter,
    state: EditorState.create({
      doc: feld.value,
      extensions: [
        lineNumbers(),
        highlightActiveLineGutter(),
        // Steuerzeichen sichtbar machen: Ein unsichtbares Zeichen in einer
        // Konfigurationsdatei ist eine Fehlersuche von Stunden.
        highlightSpecialChars(),
        history(),
        drawSelection(),
        rectangularSelection(),
        highlightActiveLine(),
        bracketMatching(),
        syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
        // Zwei Leerzeichen als Einzug: YAML verträgt keine Tabulatoren, und
        // YAML ist der häufigste Fall in diesem Panel.
        indentUnit.of("  "),
        keymap.of([...defaultKeymap, ...historyKeymap, indentWithTab]),
        thema,
        // Der Nonce kommt vom Server und gilt nur für diese Antwort. Ohne ihn
        // verwirft die Content-Security-Policy das Stil-Element, das CodeMirror
        // anlegt — im Browser nachgemessen, der Editor bliebe ungestylt.
        nonce ? EditorView.cspNonce.of(nonce) : [],
        sprache(feld.dataset.sprache || ""),
      ],
    }),
  });

  const formular = feld.form;
  if (formular) {
    formular.addEventListener("submit", () => {
      feld.value = sicht.state.doc.toString();
    });
  }

  // Strg+S speichert, statt die Seite zu sichern — die Erwartung jedes
  // Editors.
  sicht.dom.addEventListener("keydown", (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === "s") {
      e.preventDefault();
      if (formular) formular.requestSubmit();
    }
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", starten);
} else {
  starten();
}
