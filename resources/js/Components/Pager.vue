<script setup lang="ts">
/*
 * Blättern durch ein Verzeichnis.
 *
 * **Warum es diese Komponente gibt.** Vier Controller paginierten, und keine
 * einzige Seite zeigte einen Weg zur zweiten. Vom Protokoll waren 76 Einträge
 * da und 50 zu sehen; von den Vorgängen — der Liste, die man ansieht, wenn
 * etwas nicht stimmt — ebenso. Kein Fehler, keine Meldung, nur eine Liste, die
 * nach fünfzig Zeilen aufhört.
 *
 * **Vor und Zurück, keine Seitenzahlenleiste.** Eine Leiste „1 2 … 7 8 9 … 42"
 * bricht auf 390px entweder um oder braucht Abkürzungslogik, und die ist eine
 * klassische Quelle für Zählfehler. Bei fünfzig Zeilen je Seite hat eine reale
 * Installation ein bis drei Seiten; wer eine bestimmte Zeile sucht, filtert.
 *
 * **Die Gesamtzahl steht hier nicht.** Sie steht schon in der Beizeile der
 * Seite („76 Einträge"). Zweimal dieselbe Zahl auf einem Bildschirm ist eine
 * Angabe, die man liest und nicht braucht.
 */
import { Link, usePage } from '@inertiajs/vue3'

const props = defineProps<{
  /** Die Seite, auf der man steht — beginnend bei 1. */
  page: number

  /** Wie viele es insgesamt sind. */
  pages: number
}>()

const seite = usePage()

/*
 * Die Adresse der Nachbarseite — mit allem, was schon in der Adresszeile
 * steht.
 *
 * Die Filter des Protokolls stehen als Abfrage in der Adresse und nicht im
 * Zustand der Seite. Ein Verweis, der nur `?page=2` setzt, würfe sie weg: Man
 * filtert auf „fehlgeschlagen", blättert weiter und steht wieder in der
 * ungefilterten Liste. Deshalb wird die vorhandene Abfrage übernommen und nur
 * `page` ersetzt.
 *
 * Auf Seite 1 fällt der Parameter ganz weg — `?page=1` ist dieselbe Seite mit
 * einer längeren Adresse, und die landet so im Verlauf des Browsers.
 */
function adresse(nummer: number): string {
  const [pfad, abfrage] = seite.url.split('?')
  const werte = new URLSearchParams(abfrage ?? '')

  if (nummer <= 1) werte.delete('page')
  else werte.set('page', String(nummer))

  const rest = werte.toString()

  return rest === '' ? pfad : `${pfad}?${rest}`
}
</script>

<template>
  <!--
    Nur wenn es etwas zu blättern gibt. Eine Zeile „Seite 1 von 1" unter jeder
    kurzen Liste wäre eine Auskunft ohne Aussage.

    Die leeren `<span>` halten ihre Spalte: Ohne sie rutschte die Angabe in der
    Mitte um eine Knopfbreite, sobald man von Seite 1 auf 2 geht — auf einer
    Seite, die sonst gleich bleibt, sieht das nach einem Fehler aus.
  -->
  <nav v-if="props.pages > 1" class="pager" aria-label="Blättern">
    <Link
      v-if="props.page > 1"
      :href="adresse(props.page - 1)"
      class="button small"
      rel="prev"
    >
      Zurück
    </Link>
    <span v-else />

    <p class="pager-state" aria-live="polite">Seite {{ props.page }} von {{ props.pages }}</p>

    <Link
      v-if="props.page < props.pages"
      :href="adresse(props.page + 1)"
      class="button small"
      rel="next"
    >
      Weiter
    </Link>
    <span v-else />
  </nav>
</template>
