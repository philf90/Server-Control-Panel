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
defineProps<{
  items: { id: number; badge: string; rank: string; body: string }[]
}>()
</script>

<template>
  <div
    v-for="hinweis in items"
    :key="hinweis.id"
    class="band"
    :class="hinweis.badge"
  >
    <!--
      **Das Rangwort steht im Textfluss und nicht daneben.** Als eigenes
      Flexkind bricht es bei 390 px in eine eigene Zeile und kostet 21 px je
      Band — drei Ankündigungen 264 px statt der 195, die `docs/81 §2.3q` M8
      als Budget gemessen hat.
    -->
    <span class="clamped"><b class="rank">{{ hinweis.rank }}</b> {{ hinweis.body }}</span>
  </div>
</template>
