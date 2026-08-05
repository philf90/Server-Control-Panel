<script setup lang="ts">
/*
 * Die Zustandsmarke — Punkt und Wort.
 *
 * **Warum es diese Komponente gibt.** Es gab vier Fassungen davon: `.badge`
 * auf der Übersicht, `.chip` im Entwurf, `.marke` am Abonnement und
 * `.status` auf der Kundenfläche — je nach Seite eine andere Form für
 * dieselbe Aussage.
 *
 * **Farbe ist nie der einzige Träger.** Neben der Farbe steht immer ein Wort,
 * und der Punkt davor trägt dieselbe Farbe wie die Schrift. Rund acht Prozent
 * der männlichen Nutzer lesen eine rote Fläche nicht als Signal; für sie ist
 * „gescheitert" die Auskunft und die Farbe nur die Bestätigung.
 *
 * **Vier Ränge, mehr braucht kein Zustand:**
 *
 *   ok         läuft, ist eingerichtet, ist frei
 *   warn       läuft noch, ist gesperrt, weicht ab — jemand sollte hinsehen
 *   critical   gescheitert, nicht erreichbar — jemand muss handeln
 *   neutral    nicht installiert, nicht gesetzt — kein Zustand, eine Abwesenheit
 */
withDefaults(defineProps<{
  kind: 'ok' | 'warn' | 'critical' | 'neutral'

  /**
   * Ein Vorgang, der gerade läuft: Der Punkt pulst.
   *
   * Nur für etwas, das sich ohne Zutun ändert — sonst ist Bewegung in einer
   * Liste mit zwanzig Zeilen keine Auskunft, sondern Unruhe.
   */
  running?: boolean
}>(), { running: false })
</script>

<template>
  <!--
    Die vier Ränge stehen als Objektschlüssel und nicht als `:class="kind"`.
    Das ist umständlicher zu lesen und der Grund ist ein gemessener: Nur so
    sieht `ClassReachTest`, welche Klassen hier entstehen können, und prüft,
    dass es sie in app.css gibt. Käme die Klasse aus der Variablen, wäre ein
    Tippfehler im Rang eine Marke ohne Farbe — sichtbar erst im Browser, und
    dann nur, wenn jemand genau diesen Zustand vor sich hat.
  -->
  <span
    class="badge"
    :class="{
      ok: kind === 'ok',
      warn: kind === 'warn',
      critical: kind === 'critical',
      neutral: kind === 'neutral',
      running,
    }"
  >
    <slot />
  </span>
</template>
