<script setup lang="ts">
/**
 * Ein Ast des Verzeichnisbaums — und seine Kinder, über sich selbst.
 *
 * **Getrennt von {@link ./FileTree.vue}, weil die Ablage nicht mitrekursieren
 * darf.** Der erste Wurf hatte zwei Ebenen im Markup und die Begründung „das
 * reicht zum Navigieren". Das war falsch und leicht zu widerlegen:
 * `httpdocs/wp-content/themes/mein-theme` sind vier.
 *
 * > **Eine Begründung, die man an einem gewöhnlichen Pfad widerlegen kann, ist
 * > keine.**
 *
 * Diese Komponente ruft sich selbst auf. Was sie **nicht** tut, ist eine eigene
 * Ablage zu halten: `loaded` und `open` kommen von oben durch, und deshalb
 * weiss jede Ebene, was die anderen schon geholt haben. Zwei Ebenen mit je
 * eigenem Vorrat würden dasselbe Verzeichnis zweimal laden und zu verschiedenen
 * Zeiten vergessen.
 *
 * Die Griffe kommen als Funktionen und nicht als `emit`: Ein Ereignis müsste
 * durch jede Ebene weitergereicht werden, und bei fünf Ebenen ist das fünfmal
 * dieselbe Zeile — die vierte davon ist die, die jemand vergisst.
 */
interface Node {
  name: string
  path: string
  children: boolean
}

const props = defineProps<{
  nodes: Node[]
  loaded: Record<string, Node[]>
  open: Record<string, boolean>
  current: string
  onToggle: (node: Node) => void
  onChoose: (node: Node) => void
}>()
</script>

<template>
  <ul class="branch">
    <li v-for="node in props.nodes" :key="node.path">
      <div class="twig">
        <!--
          Der Aufklapper steht nur da, wo etwas darunter liegt — das sagt der
          Agent in `children`. Ein Aufklapper, der sich öffnet und nichts zeigt,
          ist eine Zusage, die der Baum nicht halten kann.

          Wo keiner steht, steht trotzdem ein Platzhalter derselben Breite:
          Sonst rückt jeder Ast ohne Kinder um seine Breite nach links, und die
          Einrückung sagt dann etwas über den Inhalt statt über die Tiefe.
        -->
        <button
          v-if="node.children"
          type="button"
          class="knob"
          :aria-expanded="props.open[node.path] === true"
          :aria-label="`${node.name} auf- oder zuklappen`"
          @click="props.onToggle(node)"
        >{{ props.open[node.path] === true ? '−' : '+' }}</button>
        <span v-else class="knob"></span>

        <button
          type="button"
          class="link"
          :class="{ here: props.current === node.path }"
          :aria-current="props.current === node.path ? 'true' : undefined"
          @click="props.onChoose(node)"
        >{{ node.name }}</button>
      </div>

      <FileTreeNode
        v-if="props.open[node.path] === true && props.loaded[node.path] !== undefined"
        :nodes="props.loaded[node.path]"
        :loaded="props.loaded"
        :open="props.open"
        :current="props.current"
        :on-toggle="props.onToggle"
        :on-choose="props.onChoose"
      />
    </li>
  </ul>
</template>
