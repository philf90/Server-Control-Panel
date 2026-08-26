<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
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
 * Das abgetippte Wort — für die Fragen, bei denen ein Ja nicht genügt.
 *
 * **Der erste Fall ist der Neustart** (`docs/81 §7`, Falle 8). Er nimmt jede
 * Kundenseite dieses Servers für zwei Minuten mit, und er ist auf zwei Seiten
 * ein Klick weit entfernt. Ein Ja/Nein davor kostet genau die eine Bewegung,
 * die ein Fehlgriff ohnehin ist.
 */
const typed = ref('')

const feld = ref<HTMLInputElement | null>(null)

/**
 * Darf der Knopf gedrückt werden?
 *
 * **Das ist die Anzeige und nicht die Schranke.** Ein abgeschalteter Knopf ist
 * ein Zustand im Browser, und wer die Anfrage selbst schickt, sieht ihn nie —
 * geprüft wird der Name deshalb noch einmal auf dem Server.
 *
 * > **Was der Geprüfte selbst zurücknehmen kann, ist keine Schranke, sondern
 * > eine Voreinstellung.**
 */
const ready = computed((): boolean => {
  const frage = pending.value

  return frage === null || frage.challenge === null || typed.value.trim() === frage.challenge
})

/*
 * **Die Frage holt sich ins Bild.** Gedrückt wird der Knopf an einer Zeile weit
 * unten, gefragt wird oben — und mit `preserveScroll` springt die Seite nicht
 * mit. Auf einem iPhone sah das aus, als täte der Knopf nichts (`docs/55`,
 * Befund 19); genau der Eindruck, den Befund 15 und 16 schon zweimal erzeugt
 * haben.
 */
watch(pending, (offen) => {
  /*
   * **Geleert wird bei jedem Wechsel und nicht beim Schliessen.** Sonst stünde
   * die Antwort auf die vorige Frage noch im Feld, wenn die nächste kommt — und
   * bei zwei Fragen mit demselben Wort wäre der Knopf sofort drückbar.
   */
  typed.value = ''

  if (offen !== null) {
    void nextTick(() => {
      bringIntoView(block.value)

      // Wer abtippen soll, soll nicht erst klicken müssen.
      feld.value?.focus()
    })
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

    <!--
      **Das Feld steht zwischen Frage und Knopf und nicht daneben.**

      Es ist die Bedingung des Knopfes, und es wird gelesen, bevor er gedrückt
      wird. Rechts daneben stünde es bei 390 px unter ihm.

      **Ohne `autocapitalize` schlägt es auf dem Telefon fehl.** iOS setzt den
      ersten Buchstaben eines leeren Feldes gross; aus „cloudsrv24.de" würde
      „Cloudsrv24.de", und der Vergleich schlüge fehl, ohne dass irgendetwas
      sichtbar falsch aussähe.
    -->
    <label v-if="pending.challenge !== null" class="field">
      <span>Zur Bestätigung eingeben: <span class="ident">{{ pending.challenge }}</span></span>
      <input
        ref="feld"
        v-model="typed"
        type="text"
        autocapitalize="none"
        autocomplete="off"
        autocorrect="off"
        spellcheck="false"
      >
    </label>

    <div class="button-row">
      <button
        type="button"
        :class="pending.destructive ? 'button danger' : 'button primary'"
        :disabled="!ready"
        @click="accept(typed.trim())"
      >
        {{ pending.verb }}
      </button>
      <button type="button" class="button" @click="dismiss">Abbrechen</button>
    </div>
  </div>
</template>
