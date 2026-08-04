<script setup lang="ts">
/*
 * Das Zeichen des Panels: drei gestapelte Balken.
 *
 * **Warum es hier als Quelltext steht und nicht als Datei geladen wird.**
 * Dasselbe wie beim Auge: Ein `<img src="…">` wäre ein zweiter Aufruf für drei
 * Rechtecke — und vor allem könnte es seine Farbe nicht von der Umgebung
 * erben. Genau das braucht es hier.
 *
 * **Eine Fassung für beide Themes.** Geliefert wurde das Zeichen dreifach: mit
 * dunklen Balken für hellen Grund, mit hellen für dunklen, und einfarbig. Drei
 * Dateien in der Oberfläche hiessen, an jeder Stelle die richtige auszuwählen
 * — und irgendwann die falsche. Stattdessen steht es einmal hier: Die beiden
 * unteren Balken nehmen `currentColor` und laufen damit über `--text-strong`
 * mit dem Theme mit, der obere trägt `--mark-accent`, das je Theme den
 * passenden Blauton führt. Die farbigen Dateien liegen unter
 * `resources/images/` für alles ausserhalb der Oberfläche.
 *
 * **Einfarbig ging nicht.** Der Versuch, das ganze Zeichen in der Akzentfarbe
 * zu zeichnen, machte aus dem untersten Balken — er steht auf halber Deckung —
 * ein schmutziges Braun auf Amber. Das sah nach Fehler aus und nicht nach
 * Gestaltung; nachgesehen im Browser bei 22 px, nicht im Entwurf.
 *
 * Die halbe Deckung ist Teil des Zeichens und keine Abstufung, die etwas
 * bedeutet: Es liest sich als Liste, die weitergeht, nicht als etwas, das
 * abgeschaltet wäre.
 */
withDefaults(defineProps<{ size?: number }>(), { size: 22 })
</script>

<template>
  <svg
    class="marke"
    viewBox="0 0 64 64"
    :width="size"
    :height="size"
    role="img"
    aria-label="SrvPanel"
  >
    <rect x="2" y="5.5" width="60" height="13" rx="4" class="balken-oben" />
    <rect x="2" y="25.5" width="44" height="13" rx="4" fill="currentColor" />
    <rect x="2" y="45.5" width="28" height="13" rx="4" fill="currentColor" opacity="0.5" />
  </svg>
</template>

<style scoped>
.marke {
  display: block;
  /* `flex: none`: In der Seitenleiste steht das Zeichen neben Schriftzug und
     Version, und ohne das schrumpft es zu einem Streifen, sobald die Zeile eng
     wird. Derselbe Fehler wie beim Quadrat davor — deshalb steht er hier. */
  flex: none;
}

.balken-oben {
  fill: var(--mark-accent);
}
</style>
