<script setup lang="ts">
/*
 * Die Ankündigungen des Betreibers als Streifen (A14, `docs/103 §4`).
 *
 * **Eine Komponente für drei Orte** — das Panel, die Anmeldeseite und die
 * Zweitfaktorseite. Die beiden letzten tragen `PanelLayout` nicht; ohne diese
 * Komponente stünde dasselbe Markup dort ein zweites und drittes Mal, und die
 * Zeilenklammer wäre beim nächsten Umbau an einer davon fort.
 *
 * **Sie gestaltet nichts selbst.** Die Regeln stehen in `app.css` unter
 * `.band` — eine Komponente, die ihr eigenes Aussehen mitbringt, ist derselbe
 * Fehler wie ein Hexwert (`CLAUDE.md`).
 */
import { Link } from '@inertiajs/vue3'

defineProps<{
  items: { id: number; badge: string; rank: string; body: string }[]
}>()
</script>

<template>
  <!--
    **Das ganze Band ist der Verweis.**

    Der erste Wurf setzte ein „mehr" ans Ende des Textes, innerhalb der
    Zeilenklammer — und damit schnitt die Klammer es genau dann weg, wenn der
    Text lang ist, also im einzigen Fall, für den es da war.

    Ein eigenes Flexkind daneben kostet bei 390 px eine ganze Zeile je Band
    (gemessen an genau dieser Stelle für das Rangwort, `docs/81 §2.3q` M9), und
    Punkt 3 des Abnahmekriteriums ist ein Ausschlusskriterium über diese Höhe.

    Die Fläche selbst kostet nichts und ist nie abgeschnitten.
  -->
  <Link
    v-for="hinweis in items"
    :key="hinweis.id"
    class="band"
    :class="hinweis.badge"
    :href="`/announcements/${hinweis.id}`"
  >
    <!--
      **Das Rangwort steht im Textfluss und nicht daneben.** Als eigenes
      Flexkind bricht es bei 390 px in eine eigene Zeile und kostet 21 px je
      Band — drei Ankündigungen 264 px statt der 195, die `docs/81 §2.3q` M8
      als Budget gemessen hat.
    -->
    <span class="clamped"><b class="rank">{{ hinweis.rank }}</b> {{ hinweis.body }}</span>
  </Link>
</template>
