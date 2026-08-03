<script setup lang="ts">
/*
 * Das Auge zum Ein- und Ausblenden eines Passworts.
 *
 * **Warum kein Emoji.** Hier standen 👁 und 🙈. Drei Gründe, warum das nicht
 * geht: Ein Emoji wird von der Schriftart des Betriebssystems gezeichnet und
 * sieht auf jeder Plattform anders aus — auf Windows bunt, auf macOS
 * dreidimensional, auf einem Linux-Server mit dünner Schriftausstattung
 * mitunter als leeres Rechteck. Es nimmt keine Textfarbe an und steht damit
 * neben einer Oberfläche, in der Farbe etwas bedeutet. Und der Affe, der sich
 * die Augen zuhält, ist ein Witz an einer Stelle, an der jemand ein Passwort
 * für ein Kundenkonto setzt.
 *
 * Ein Pfad, `currentColor`, `stroke` statt `fill`: Das Zeichen erbt Farbe und
 * Größe vom Knopf und sieht überall gleich aus.
 *
 * **Eigene Geometrie, keine fremde Bibliothek.** Zwei Bögen und ein Kreis
 * sind kein Grund für eine Abhängigkeit — und eine Icon-Bibliothek bringt
 * eine Lizenz mit, die zur AGPL passen muss.
 */
defineProps<{
  /** Verdeckt statt sichtbar: Auge mit Strich. */
  off?: boolean
}>()
</script>

<template>
  <svg
    class="auge-symbol"
    viewBox="0 0 24 24"
    width="16"
    height="16"
    fill="none"
    stroke="currentColor"
    stroke-width="1.7"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
  >
    <!-- Die Linse: zwei gespiegelte Bögen, oben und unten. -->
    <path d="M2.5 12c2.9-4.4 6.1-6.6 9.5-6.6s6.6 2.2 9.5 6.6c-2.9 4.4-6.1 6.6-9.5 6.6S5.4 16.4 2.5 12z" />
    <circle cx="12" cy="12" r="2.9" />
    <!-- Der Strich steht diagonal darüber und nicht als zweites Zeichen
         daneben: So bleibt die Grundform erkennbar, und der Zustand liest sich
         als „dasselbe, nur aus".

         Von links oben nach rechts unten, weil er in jedem gängigen
         Symbolsatz so läuft. Andersherum ist er nicht falsch, aber er kostet
         den Bruchteil einer Sekunde, in der jemand hinsieht statt zu klicken. -->
    <path v-if="off" d="M4 4 20 20" />
  </svg>
</template>

<style scoped>
.auge-symbol {
  display: block;
  margin: 0 auto;
}
</style>
