<script setup lang="ts">
/*
 * Das x eines Bandes — gewünscht vom Betreiber am 11. Oktober 2026.
 *
 * **Es steht neben dem Band und nicht darin.** Zwei der vier Bänder sind
 * selbst ein Verweis: die Ankündigung (`Bands.vue`, „das ganze Band ist der
 * Verweis") und die Wartung. Ein Knopf in einem `<a>` ist kein gültiges HTML,
 * und ein Klick darauf ginge an den Verweis weiter. Die Hülle `.band-item`
 * trägt deshalb beides nebeneinander, und `app.css` legt das x über die
 * rechte obere Ecke des Bandes.
 *
 * **Was weggeklickt wird, entscheidet nicht dieser Knopf.** Er meldet den
 * Klick; ob das Band bis zu seiner nächsten Änderung fort bleibt oder nur bis
 * zum nächsten Seitenwechsel, entscheidet die Stelle, die das Band kennt
 * (`useDismissedBands.ts`).
 *
 * **Ein Zeichen ohne Wort, und das ist hier die Ausnahme.** Neben den Zeichen
 * aus `ActionIcon.vue` steht immer ihr Wort. Das x eines Hinweises ist das eine
 * Zeichen, das jeder ohne Wort liest; den Namen für die Vorlesesoftware trägt
 * `aria-label`.
 */
defineEmits<{ dismiss: [] }>()
</script>

<template>
  <button type="button" class="band-dismiss" aria-label="Ausblenden" @click="$emit('dismiss')">
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M6 6l12 12M18 6L6 18" />
    </svg>
  </button>
</template>
