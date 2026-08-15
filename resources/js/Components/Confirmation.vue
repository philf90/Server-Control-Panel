<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { useConfirmation } from '../Composables/useConfirmation'
import { bringIntoView } from '../scroll'

/*
 * Die Rückfrage vor einer Handlung, die etwas kostet — auf der Seite.
 *
 * **Der Fall, der diese Komponente ausgelöst hat.** Am 15. August 2026 kam auf
 * einem iPhone keine einzige Rückfrage dieses Panels an (`docs/55`, Befund 16).
 * Sie standen alle in `window.confirm`, und Safari darf die Dialoge einer Seite
 * abschalten, nachdem sie mehrere gezeigt hat — `confirm()` gibt danach ohne ein
 * Zeichen `false` zurück. Achtzehn Aktionen taten damit nichts.
 *
 * **Warum sie hier steht und nicht je Seite.** Genau wie die grüne Meldung und
 * die Fehlerzusammenfassung (`docs/19 §6`): ein Ort, an dem die Seite spricht.
 * Achtzehn eigene Fassungen wären achtzehn Gelegenheiten, sie verschieden zu
 * bauen — und eine davon würde vergessen.
 *
 * **Und es ist keine Modale.** Sie schwebt nicht über dem Inhalt und fängt
 * keinen Fokus ein; sie steht im Fluss, wie jede andere Meldung. `docs/53`
 * Befund 8 hat das für den Rechte-Editor entschieden, und dieselbe Entscheidung
 * gilt hier.
 */
const { pending, accept, dismiss } = useConfirmation()

const block = ref<HTMLElement | null>(null)

/*
 * **Die Frage holt sich ins Bild.** Gedrückt wird der Knopf an einer Zeile weit
 * unten, gefragt wird oben — und mit `preserveScroll` springt die Seite nicht
 * mit. Auf einem iPhone sah das aus, als täte der Knopf nichts (`docs/55`,
 * Befund 19); genau der Eindruck, den Befund 15 und 16 schon zweimal erzeugt
 * haben.
 */
watch(pending, (offen) => {
  if (offen !== null) {
    void nextTick(() => bringIntoView(block.value))
  }
})
</script>

<template>
  <!--
    `role="alertdialog"` und nicht `alert`: Hier steht eine Frage, die eine
    Antwort erwartet, und der Unterschied ist für jemanden, der die Seite hört,
    der ganze Sinn dieses Blocks.
  -->
  <div
    v-if="pending !== null"
    ref="block"
    class="confirmation"
    role="alertdialog"
    tabindex="-1"
    aria-labelledby="confirmation-question"
  >
    <p id="confirmation-question">
      <b>{{ pending.lines[0] }}</b>
      <template v-for="(zeile, index) in pending.lines.slice(1)" :key="index">
        <br>{{ zeile }}
      </template>
    </p>

    <div class="button-row">
      <button
        type="button"
        :class="pending.destructive ? 'button danger' : 'button primary'"
        @click="accept"
      >
        {{ pending.verb }}
      </button>
      <button type="button" class="button" @click="dismiss">Abbrechen</button>
    </div>
  </div>
</template>
