<script setup lang="ts">
/*
 * Der Anteil als Balken, mit der Zahl daneben.
 *
 * **Warum es diese Komponente gibt.** Es gab drei Fassungen davon — `.bar`
 * auf der Übersicht, `.balken` mit `.fuellung` am Abonnement, und eine dritte
 * in der Dateisystemtabelle. Alle drei zeichneten dasselbe, und zwei davon
 * schnitten bei 100 % nicht ab.
 *
 * **Der Balken wird bei 100 abgeschnitten, die Zahl daneben nicht.** Eine
 * Quota lässt sich überschreiten — sie wird gesenkt, während Daten liegen,
 * oder ein Prozess schreibt mit root-Rechten daran vorbei. Ein Balken, der
 * über seinen Rahmen hinausläuft, ist ein Darstellungsfehler; „118 %" daneben
 * ist die Auskunft. Beides zusammen ist die Wahrheit.
 *
 * **Die Schwelle kommt von aussen und wird hier nicht erfunden.** Ab wann ein
 * Dateisystem „eng" ist, ist eine Aussage über den Betrieb und keine über die
 * Darstellung — der Server kennt sie, diese Komponente nicht.
 */
const props = withDefaults(defineProps<{
  /** Der Anteil in Prozent. Darf über 100 liegen. */
  percent: number

  /** Nahe an der Grenze — der Balken trägt die Warnfarbe. */
  tight?: boolean

  /** Die Grenze ist überschritten. */
  over?: boolean

  /** Über die volle Breite, wenn der Wert die Hauptsache der Seite ist. */
  wide?: boolean
}>(), {
  tight: false,
  over: false,
  wide: false,
})

const filled = `${Math.max(0, Math.min(100, props.percent))}%`
</script>

<template>
  <div class="bar-row">
    <!--
      Die Ränge stehen als Objektschlüssel, damit `ClassReachTest` sie prüfen
      kann — dieselbe Begründung wie bei der Zustandsmarke.

      `role="img"` mit Beschriftung: Ein Balken ohne Text ist für eine
      Vorlesesoftware nichts. Die Zahl daneben steht zwar da, aber sie steht
      als eigenes Element und nicht als Beschriftung des Balkens.
    -->
    <div
      class="bar"
      :class="{ tight, over, wide }"
      role="img"
      :aria-label="`${percent} Prozent belegt`"
    >
      <i :style="{ width: filled }" />
    </div>

    <span class="bar-value">{{ percent }} %</span>
  </div>
</template>
