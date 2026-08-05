<script setup lang="ts">
/*
 * Ein Bereich — der Baustein, aus dem jede Seite besteht.
 *
 * **Warum es diese Komponente gibt.** Vier Seiten haben ihre eigene Fassung
 * davon mitgebracht (`.block` plus `.section`), und es gab davon zwei
 * unvereinbare: einmal eine Überschrift in Versalien mit `--text-label`,
 * einmal eine in `--block-heading-size` mit einer Haarlinie. Dieselbe Sache,
 * viermal geschrieben, verschieden — und beim fünften Modul wäre eine fünfte
 * dazugekommen.
 *
 * **Was ein Bereich in „Kontor" ist:** eine Überschrift, eine Linie und
 * Inhalt. Keine Karte. Eine Karte kostet je Bereich rund 40px Innenabstand,
 * und die fehlen am Ende als Zeilen — genau der Vorwurf, an dem „Werkstatt"
 * 2026 gescheitert ist.
 *
 * **Die Breite ist eine Eigenschaft des Inhalts, nicht der Seite.** Ein
 * Bereich mit einer Beschreibungsliste braucht einen Grundriss, einer mit
 * einer fünfspaltigen Tabelle anderthalb, und einer, der die Zeile für sich
 * haben muss, die ganze. Deshalb `weit` und `voll` hier und nicht als
 * Rasterangabe auf der Seite: Wer die Tabelle um eine Spalte erweitert, ändert
 * die Breite an derselben Stelle mit.
 */
withDefaults(defineProps<{
  titel: string

  /** Anderthalb Grundrisse — für eine Tabelle mit mehr als drei Spalten. */
  weit?: boolean

  /** Die ganze Zeile — für die lange Liste am Ende einer Seite. */
  voll?: boolean

  /** Ein Satz unter der Überschrift, der die Zahlen darunter einordnet. */
  erklaerung?: string
}>(), {
  weit: false,
  voll: false,
  erklaerung: undefined,
})
</script>

<template>
  <!--
    `weit` und `voll` stehen als Objektschlüssel und nicht als Ausdruck
    (`:class="breite"`): So sieht `ClassReachTest`, welche Klassen hier
    entstehen können, und prüft, dass es sie in app.css gibt. Eine
    Zeichenkette, die aus einer Variablen kommt, kann er nicht prüfen — und
    genau solche Zeichenketten sind in diesem Projekt schon dreimal ins Leere
    gelaufen.
  -->
  <section class="bereich" :class="{ weit, voll }">
    <div class="bkopf">
      <h2>{{ titel }}</h2>

      <!-- Rechts am Bereichskopf steht, was diesen Bereich betrifft: ein
           Knopf, ein Verweis auf die volle Liste, eine Angabe zur Sortierung.
           Nicht mehr als eines. -->
      <slot name="aktion" />
    </div>

    <p v-if="erklaerung" class="erklaer">{{ erklaerung }}</p>

    <slot />
  </section>
</template>
