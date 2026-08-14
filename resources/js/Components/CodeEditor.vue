<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

/**
 * Der Editor mit Syntaxhervorhebung.
 *
 * **Er ist die erste Frontend-Abhängigkeit dieses Projekts**, und das ist eine
 * Entscheidung des Betreibers vom 14. August 2026 (`docs/51 §3`,
 * Entscheidung 1). `docs/20 §4.6` hält für die Kennzahlen fest, warum es sonst
 * keine gibt — eine Bibliothek würde nachbauen und dabei schwerer machen. Beim
 * Editor kostet die Regel am meisten, deshalb fällt sie hier und nur hier.
 *
 * Drei Auflagen hängen daran, und alle drei stehen im Code und nicht nur im
 * Plan:
 *
 * **1. Nachgeladen, nicht mitgeliefert.** Der Import steht in `onMounted` und
 * nicht oben — Vite macht daraus ein eigenes Bündel, und wer nie eine Datei
 * bearbeitet, lädt nichts davon. `FrontendDependencyTest` rechnet nach, dass
 * die Trennung wirklich stattfindet.
 *
 * **2. Keine Farbe aus der Bibliothek.** CodeMirrors Themes bringen ihre
 * eigenen Werte mit; hier wird stattdessen eine `HighlightStyle` mit
 * **Klassennamen** statt Farben definiert (`class:` statt `color:`). Was daraus
 * wird, steht in `resources/css/app.css` — dieselbe Stelle wie für alles
 * andere, und damit gilt auch hier: ein Hexwert in einer Komponente ist ein
 * Fehler und keine Ausnahme.
 *
 * **3. Ein Rückweg, der ohne sie auskommt.** Lädt das Bündel nicht — kein Netz,
 * ein Fehler beim Auflösen —, bleibt das `textarea` darunter stehen und
 * funktioniert. Der Editor ist eine Verbesserung und keine Voraussetzung:
 * Sonst hinge das Speichern einer `.htaccess` an einer Bibliothek.
 */
const props = defineProps<{
  modelValue: string
  filename: string
  readonly: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [string] }>()

const host = ref<HTMLDivElement | null>(null)
const ready = ref(false)

/**
 * Was aufgeräumt werden muss.
 *
 * Der Typ steht hier von Hand und kommt nicht aus einem `import type` von
 * CodeMirror: Ein Typimport wäre zur Laufzeit zwar nichts, aber er zöge die
 * Bibliothek in die Abhängigkeitsverfolgung dieser Datei — und damit
 * möglicherweise ins gemeinsame Bündel. Auflage 1 hängt daran.
 */
let view: { destroy(): void; state: { doc: { toString(): string } } } | null = null

/**
 * Eine CodeMirror-Erweiterung, ohne ihren Typ zu importieren.
 *
 * `import type { Extension } from '@codemirror/state'` wäre genauer und stünde
 * Auflage 1 im Weg: Ein Typimport zieht die Bibliothek in die
 * Abhängigkeitsverfolgung dieser Datei, und was dort steht, kann im gemeinsamen
 * Bündel landen. Die Genauigkeit ist hier nichts wert — diese Werte werden
 * durchgereicht und nie angefasst.
 */
type Extension = unknown

/**
 * Die Sprache am Namen ablesen.
 *
 * Eine Datei ohne bekannte Endung bekommt keine Hervorhebung und nicht die
 * falsche — `.env` als JavaScript einzufärben wäre schlechter als gar nichts,
 * weil es behauptet, etwas verstanden zu haben.
 */
async function language(name: string): Promise<Extension[]> {
  const suffix = name.split('.').pop()?.toLowerCase() ?? ''

  if (['php', 'phtml'].includes(suffix)) return [(await import('@codemirror/lang-php')).php()]
  if (['html', 'htm', 'twig'].includes(suffix)) return [(await import('@codemirror/lang-html')).html()]
  if (['css', 'scss', 'less'].includes(suffix)) return [(await import('@codemirror/lang-css')).css()]
  if (['js', 'ts', 'mjs', 'cjs'].includes(suffix)) return [(await import('@codemirror/lang-javascript')).javascript()]
  if (['json', 'lock'].includes(suffix)) return [(await import('@codemirror/lang-json')).json()]

  return []
}

onMounted(async () => {
  if (host.value === null) return

  try {
    const [{ EditorView, keymap, lineNumbers, highlightActiveLine }, { EditorState }, { HighlightStyle, syntaxHighlighting, indentUnit }, { defaultKeymap, history, historyKeymap }, { tags }] =
      await Promise.all([
        import('@codemirror/view'),
        import('@codemirror/state'),
        import('@codemirror/language'),
        import('@codemirror/commands'),
        import('@lezer/highlight'),
      ])

    /*
     * Klassen statt Farben — der Punkt, an dem Auflage 2 hängt.
     *
     * `class:` lässt CodeMirror die Marke setzen und die Farbe uns. Stünde
     * hier `color: '#…'`, wären die Farben dieses Editors die einzigen im
     * ganzen Panel, die nicht aus `app.css` kommen — und in einem der beiden
     * Themes vermutlich unlesbar.
     */
    const marks = HighlightStyle.define([
      { tag: tags.keyword, class: 'tok-keyword' },
      { tag: [tags.string, tags.special(tags.string)], class: 'tok-string' },
      { tag: [tags.comment, tags.lineComment, tags.blockComment], class: 'tok-comment' },
      { tag: [tags.number, tags.bool, tags.null], class: 'tok-number' },
      { tag: [tags.function(tags.variableName), tags.definition(tags.variableName)], class: 'tok-name' },
      { tag: [tags.tagName, tags.propertyName, tags.attributeName], class: 'tok-tag' },
      { tag: [tags.operator, tags.punctuation], class: 'tok-operator' },
      { tag: tags.invalid, class: 'tok-invalid' },
    ])

    const state = EditorState.create({
      doc: props.modelValue,
      extensions: [
        lineNumbers(),
        highlightActiveLine(),
        history(),
        keymap.of([...defaultKeymap, ...historyKeymap]),
        syntaxHighlighting(marks),
        indentUnit.of('    '),
        EditorState.readOnly.of(props.readonly),
        EditorView.editable.of(!props.readonly),
        ...(await language(props.filename) as never[]),

        // Der einzige Weg zurück in das Formular. Ohne ihn stünde im
        // `useForm` weiter der Text von vor der Bearbeitung — und Speichern
        // schriebe die Datei unverändert zurück, mit einer Erfolgsmeldung.
        EditorView.updateListener.of((update: { docChanged: boolean; state: { doc: { toString(): string } } }) => {
          if (update.docChanged) emit('update:modelValue', update.state.doc.toString())
        }),
      ],
    })

    view = new EditorView({ state, parent: host.value }) as unknown as typeof view
    ready.value = true
  } catch {
    // Auflage 3: Lädt das Bündel nicht, bleibt das `textarea` stehen. Kein
    // Fehlerbanner — der Kunde merkt nur, dass die Farben fehlen, und kann
    // trotzdem arbeiten.
    ready.value = false
  }
})

/*
 * Ein Wechsel von aussen — etwa nach einem Fehlschlag beim Speichern — soll im
 * Editor ankommen. Der Vergleich verhindert die Schleife: Was der Editor selbst
 * gesendet hat, kommt hier wieder an und darf ihn nicht neu setzen.
 */
watch(
  () => props.modelValue,
  (value) => {
    if (view !== null && value !== view.state.doc.toString()) {
      // Neu aufsetzen ist hier billiger als eine Transaktion zu bauen, und es
      // passiert höchstens einmal je Formularantwort.
      ready.value = false
    }
  },
)

onBeforeUnmount(() => {
  view?.destroy()
  view = null
})
</script>

<template>
  <div>
    <div v-show="ready" ref="host" class="editor"></div>

    <!--
      Der Rückweg aus Auflage 3, und er ist nicht `v-if`: Das `textarea` muss im
      Formular stehen, solange der Editor nicht bereit ist — auch dann, wenn er
      es nie wird.
    -->
    <textarea
      v-show="!ready"
      class="code"
      rows="24"
      spellcheck="false"
      :readonly="props.readonly"
      :value="props.modelValue"
      @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
    ></textarea>
  </div>
</template>
