<script setup lang="ts">
/*
 * Der QR-Code zum zweiten Faktor.
 *
 * ## Warum eine Bibliothek und warum nur für die Matrix
 *
 * Was an einem QR-Code schwer ist, ist Reed-Solomon: die Fehlerkorrektur, die
 * Maskenwahl und die Bitfolge nach ISO/IEC 18004. Das schreibt man nicht selbst.
 * Was leicht ist, ist das Zeichnen — und genau das gehört uns, weil `docs/20
 * §7.2` verlangt, dass Form, Grösse und Farbe aus diesem Repo kommen.
 *
 * `uqr` liefert deshalb nur das boolesche Raster. Gemessen vor der Auswahl:
 * **null transitive Abhängigkeiten**, MIT, 79 KB entpackt, eigene Typen — und
 * seine 45×45-Matrix ist **Bit für Bit** dieselbe wie die einer unabhängigen
 * Umsetzung (`@paulmillr/qr`). Eine dritte weicht nur in der Maske ab, was
 * erlaubt ist: Sucherquadrate und Taktlinien sind dort 147/147 und 89/89 gleich.
 *
 * ## Ein Pfad und nicht 2025 Rechtecke
 *
 * Jedes dunkle Modul als eigenes `<rect>` wären bei Version 7 über zweitausend
 * Elemente im Dokument. Sie stehen deshalb als **ein** `<path>`; jedes Modul
 * trägt vier Befehle, und der Browser zeichnet einmal.
 *
 * ## Der Ruhebereich steht in der Matrix
 *
 * Vier Module ringsum, wie ISO/IEC 18004 sie verlangt. Sie als Polsterung im
 * CSS zu geben wäre die zweite Fassung derselben Angabe — und die zweite
 * veraltet, sobald jemand am Rand schraubt.
 *
 * ## Dunkel auf hell, in beiden Themes
 *
 * Die Begründung steht bei `--qr-dark` in app.css: Invertiert scheitert der
 * Code an vielen Lesegeräten. Das ist die eine Stelle dieses Panels, an der ein
 * Baustein dem Theme **nicht** folgt, und sie ist dort aufgeschrieben.
 */
import { computed } from 'vue'
import { encode } from 'uqr'

const props = defineProps<{
  /**
   * Die `otpauth://`-Adresse — **dieselbe**, die darunter als Text steht.
   *
   * Sie kommt vom Server und wird hier nicht zusammengesetzt: Zwei
   * Konstruktionen derselben Adresse liefen auseinander, und die falsche wäre
   * die, die niemand liest. `QrSourceTest` hält das.
   */
  uri: string
}>()

const code = computed(() => {
  /*
   * `ecc: 'M'` — die mittlere Stufe. Sie verträgt rund 15 % Schaden und ist
   * das, was Authenticator-Apps üblicherweise erwarten; eine höhere Stufe
   * machte den Code dichter, ohne dass ein Bildschirm etwas davon hätte.
   */
  const { size, data } = encode(props.uri, { ecc: 'M', border: 4 })

  const teile: string[] = []

  for (let zeile = 0; zeile < size; zeile++) {
    for (let spalte = 0; spalte < size; spalte++) {
      if (data[zeile][spalte]) {
        teile.push(`M${spalte} ${zeile}h1v1h-1z`)
      }
    }
  }

  return { size, pfad: teile.join('') }
})
</script>

<template>
  <!--
    `role="img"` mit Beschriftung: Für eine Vorlesesoftware ist ein Raster aus
    Modulen nichts. Was sie ansagen soll, ist wozu es da ist — und darunter
    steht die Adresse als Text, also der Weg, der ohne Kamera funktioniert.
  -->
  <svg
    class="qr"
    :viewBox="`0 0 ${code.size} ${code.size}`"
    role="img"
    aria-label="QR-Code für die Authenticator-App"
    shape-rendering="crispEdges"
  >
    <rect class="qr-ground" :width="code.size" :height="code.size" />
    <path class="qr-modules" :d="code.pfad" />
  </svg>
</template>
