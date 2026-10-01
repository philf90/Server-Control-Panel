<script setup lang="ts">
/*
 * Das Zeichen des Panels — oder das Logo des Betreibers (B6).
 *
 * **Eine Komponente und nicht zwei `v-if`.** Die Marke steht an zwei Stellen:
 * in der Seitenleiste und auf der Anmeldeseite. Zwei Fassungen der Frage
 * „Logo oder Zeichen?" liefen beim nächsten Feld auseinander, und welche der
 * beiden dann die richtige ist, sähe man erst auf der Seite, die man gerade
 * nicht ansieht.
 *
 * **Ein Logo ersetzt das Zeichen *und* den Namen.** Wer ein Logo hochlädt, hat
 * entschieden, wie seine Marke aussieht; daneben weiter „SrvPanel" zu
 * schreiben ergäbe zwei Marken auf einer Zeile. Der Name geht dabei nicht
 * verloren — er steht im `alt` und damit dort, wo ihn ein Vorleser findet.
 *
 * > **Ein Bild ohne Alternativtext ist für den, der es nicht sieht, keine
 * > Marke, sondern eine Lücke.**
 *
 * **Wie gross das Logo steht, entscheidet der Ort und keine Fahne.** Auf der
 * Anmeldeseite ist es das einzige Bild, in der Seitenleiste steht es neben
 * einer Navigation — das ist eine Aussage über die Umgebung, und die gehört
 * dem Stylesheet. Ein `large`-Schalter hier wäre dieselbe Aussage ein zweites
 * Mal, an einer Stelle, die den Ort gar nicht kennt.
 *
 * **Dasselbe gilt für den Namen, und das hat bis zum 1. Oktober 2026 nicht
 * gestimmt.** Hier stand ein `<b>`, und seine Gestalt in der Seitenleiste
 * stand als `.row b` im gescopten Block von `PanelLayout`. Eine gescopte Regel
 * trifft die Elemente der eigenen Vorlage und die Wurzel einer eingesetzten
 * Komponente — diese hier hat zwei Wurzeln. Seit B6 erbte der Name deshalb die
 * Schrift der Seite, im hellen Thema 1,76:1 auf der Leiste (`docs/139 §8`).
 * Die Regel steht jetzt in `app.css` (`.rail .brand-name`), neben der für das
 * Logo. Und ohne `<b>` nimmt der Name auf der Anmeldeseite wieder das Gewicht
 * der Überschrift, in der er steht, statt `bolder` darauf.
 */
import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import MarkIcon from './MarkIcon.vue'

const props = defineProps<{
  /* Die Kantenlänge des eingebauten Zeichens. Das Logo misst app.css. */
  size: number

}>()

const page = usePage()

/*
 * Der Rückfall steht hier und nicht im Server: Eine Seite, die ohne geteilte
 * Nutzlast gerendert wird — ein Fehlerbild etwa —, soll eine Marke haben und
 * keine leere Stelle.
 */
const brand = computed(
  () =>
    (page.props.brand as { name: string; logo: string | null } | undefined) ?? {
      name: 'SrvPanel',
      logo: null,
    },
)
</script>

<template>
  <img v-if="brand.logo" class="brand-logo" :src="brand.logo" :alt="brand.name" />
  <template v-else>
    <MarkIcon :size="props.size" />
    <span class="brand-name">{{ brand.name }}</span>
  </template>
</template>
