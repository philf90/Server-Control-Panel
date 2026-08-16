<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import FileTreeNode from './FileTreeNode.vue'
import { PanelRequestError, ask } from '../Composables/usePanelRequest'

/**
 * Der Verzeichnisbaum des Abonnements.
 *
 * ## Er hat zwei Aufgaben, und das ist Absicht
 *
 * `docs/51 §8` verlangt „Baum links, Liste rechts". Bis P6 Schritt 5g stand
 * dort nur die Zeile „Die Krümel sind der Baum" — als Entscheidung
 * hingeschrieben, ohne dass sie je eine war.
 *
 * Der Betreiber hat ihn am 15. August 2026 als **Zielwähler** verlangt: Beim
 * Kopieren und Verschieben soll man das Ziel sehen und nicht tippen. Damit
 * bekommt derselbe Baum zwei Rollen — navigieren und auswählen —, und das ist
 * billiger als zwei Bäume:
 *
 * > **Zwei Bausteine, die dasselbe zeigen, zeigen es beim nächsten Umbau
 * > verschieden.**
 *
 * Was sie unterscheidet, ist eine einzige Angabe: `picking`. Beim Navigieren
 * führt ein Klick zur Liste, beim Auswählen setzt er das Ziel.
 *
 * ## Warum er nachlädt statt alles zu holen
 *
 * Ein Abonnement kann zehntausend Verzeichnisse haben. Sie beim Öffnen der
 * Seite alle zu holen hiesse, für einen Baum zu bezahlen, von dem man drei Äste
 * ansieht — und der Agent müsste dafür den ganzen Baum durchlaufen, im Chroot,
 * mit einem `scandir` je Verzeichnis.
 *
 * Geholt wird deshalb je Ast beim Aufklappen, über `files.tree`. Was schon
 * offen war, bleibt offen: Der Baum merkt sich seine Äste selbst und lädt sie
 * nicht neu, wenn die Liste daneben navigiert.
 *
 * ## Die Ablage liegt hier und nicht im Ast
 *
 * {@link ./FileTreeNode.vue} ruft sich selbst auf, hält aber **nichts** — was
 * geladen und was offen ist, steht hier. Zwei Ebenen mit je eigenem Vorrat
 * würden dasselbe Verzeichnis zweimal laden und zu verschiedenen Zeiten
 * vergessen.
 *
 * ## Und warum er ohne Inertia arbeitet
 *
 * Eine Inertia-Antwort wechselt die Seite. Ein Ast, der aufklappt, wechselt
 * nichts — und nähme dabei jeden anderen offenen Ast mit. Der Weg dafür steht
 * in `usePanelRequest.ts` und ist derselbe wie bei der Datenbankkonsole.
 */
const props = defineProps<{
  subscriptionId: number

  /** Der Pfad, der als „hier bin ich" markiert wird. */
  current: string

  /**
   * Wählt der Betrachter ein Ziel aus, statt zu navigieren?
   *
   * Der einzige Unterschied zwischen den beiden Rollen dieses Bausteins.
   */
  picking?: boolean
}>()

const emit = defineEmits<{ open: [string]; pick: [string] }>()

interface Node {
  name: string
  path: string
  children: boolean
}

/**
 * Was unter welchem Pfad liegt — `undefined` heisst „noch nie gefragt".
 *
 * Der Unterschied zu einer leeren Liste ist der ganze Zweck dieser Ablage: „Ich
 * weiss es nicht" und „da ist nichts" sehen im Baum verschieden aus, und wer
 * sie verwechselt, malt einen Aufklapper an einen leeren Ast oder umgekehrt.
 */
const geladen = ref<Record<string, Node[]>>({})
const offen = ref<Record<string, boolean>>({})
const laedt = ref<Record<string, boolean>>({})

/**
 * Was schiefging — als Satz und nicht als leerer Ast.
 *
 * Ein Ast, der sich öffnet und nichts zeigt, sieht aus wie ein leeres
 * Verzeichnis. Wenn er in Wahrheit nicht gelesen werden konnte, ist das eine
 * Behauptung, die der Baum nicht belegen kann.
 */
const fehler = ref<string | null>(null)

async function load(path: string): Promise<void> {
  if (geladen.value[path] !== undefined || laedt.value[path]) return

  laedt.value = { ...laedt.value, [path]: true }

  try {
    const antwort = await ask<{ directories: Node[] }>(
      `/subscriptions/${props.subscriptionId}/files/tree`,
      { path },
    )

    geladen.value = { ...geladen.value, [path]: antwort.directories }
    fehler.value = null
  } catch (ausnahme) {
    fehler.value =
      ausnahme instanceof PanelRequestError
        ? ausnahme.message
        : 'Der Baum liess sich nicht laden.'
  } finally {
    const rest = { ...laedt.value }
    delete rest[path]
    laedt.value = rest
  }
}

function toggle(node: Node): void {
  const jetzt = !offen.value[node.path]
  offen.value = { ...offen.value, [node.path]: jetzt }

  if (jetzt) void load(node.path)
}

/**
 * Die Äste, die zum aktuellen Pfad führen, aufklappen.
 *
 * **Ein Baum, der den Ort nicht zeigt, an dem man steht, ist eine Landkarte
 * ohne Kreuz.** Beim Öffnen der Seite und nach jeder Navigation wird der Weg
 * dorthin geladen — Ebene für Ebene, weil jede die nächste erst kennt, wenn
 * sie da ist.
 */
async function reveal(path: string): Promise<void> {
  const teile = path.split('/').filter(Boolean)
  let bisher = ''

  await load('/')

  for (const teil of teile) {
    bisher += '/' + teil
    offen.value = { ...offen.value, [bisher]: true }
    await load(bisher)
  }
}

onMounted(() => void reveal(props.current))

watch(() => props.current, (pfad) => void reveal(pfad))

function choose(node: Node): void {
  if (props.picking === true) {
    emit('pick', node.path)

    return
  }

  emit('open', node.path)
}
</script>

<template>
  <nav class="file-tree" aria-labelledby="file-tree-title">
    <!--
      **Die Überschrift steht sichtbar da und ist zugleich der Name für den
      Screenreader.** Vorher war sie ein `aria-label` — für das Auge gab es
      nichts, und unter 720px steht der Baum direkt über der Krümelspur: Wer die
      Seite auf einem Telefon öffnet, las „Abo-Wurzel" zweimal untereinander,
      einmal als Baumwurzel und einmal als Krümel (`docs/55`, Befund 23).

      > **Zwei Namen für zwei verschiedene Dinge nützen nichts, wenn keiner der
      > beiden dasteht.**

      `aria-labelledby` und nicht beides nebeneinander: Ein sichtbarer Titel und
      ein `aria-label` sind zwei Fassungen desselben Satzes, und die zweite ist
      die, die veraltet.
    -->
    <p id="file-tree-title" class="tree-title">
      {{ props.picking === true ? 'Ziel wählen' : 'Verzeichnisse' }}
    </p>

    <p v-if="fehler !== null" class="notice warn">{{ fehler }}</p>

    <ul class="branch">
      <!--
        Die Wurzel steht als eigener Eintrag und nicht als Überschrift: Sie ist
        ein Ziel wie jedes andere, und beim Verschieben will man auch dorthin.
      -->
      <li>
        <div class="twig">
          <span class="knob"></span>
          <button
            type="button"
            class="link"
            :class="{ here: props.current === '/' }"
            :aria-current="props.current === '/' ? 'true' : undefined"
            @click="props.picking === true ? emit('pick', '/') : emit('open', '/')"
          >Abo-Wurzel</button>
        </div>

        <FileTreeNode
          v-if="geladen['/'] !== undefined"
          :nodes="geladen['/']"
          :loaded="geladen"
          :open="offen"
          :current="props.current"
          :on-toggle="toggle"
          :on-choose="choose"
        />
      </li>
    </ul>
  </nav>
</template>
