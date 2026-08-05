<script setup lang="ts">
/*
 * Die Verlaufskachel — der eine Baustein, den das Panel aus dem Vorgänger
 * übernimmt (§4.6 des Plans). Übernommen ist das Prinzip, nicht der Code:
 *
 *   1. Keine Diagramm-Bibliothek. Ein SVG, ein Pfad, eine Ablesung.
 *   2. Der Server liefert fertige Stützstellen samt Beschriftung und Einheit.
 *      Hier wird nicht gerechnet, hier wird gezeichnet und gesucht.
 *   3. Kein Diagramm ohne Ablesung. Wer auf die Linie zeigt, sieht Zeitpunkt
 *      und Wert; sonst wäre die Linie Dekoration.
 *
 * **Zwei tote Klassen, die hier neun Monate standen.** Das Template schrieb
 * `class="series empty"` für den leeren Zustand — das CSS kannte `.series.leer`.
 * Und es schrieb `class="value num"` an der grossen Zahl: `.num` gab es
 * nirgends. Die Marke sollte ihr Tabellenziffern geben; app.css setzte sie
 * über `.zahl`, und die Klasse hiess anders. Die eine grosse Zahl, für die
 * eine Kachel überhaupt da ist, stand damit in Proportionalziffern, und zwei
 * Kacheln nebeneinander hatten ihre Ziffern nicht auf derselben Linie.
 *
 * Beides sind Zeichenketten, die auf nichts zeigten, und beides hat kein Lauf
 * gemeldet. Seit `ClassReachTest` gibt es das nicht mehr; die Tabellenziffern
 * stehen jetzt am `body` und lassen sich nirgends mehr vergessen.
 */
import { computed, ref } from 'vue'

export interface Point {
  x: number
  y: number
  t: string
  v: string
}

export interface Series {
  has: boolean
  points: Point[]
}

const props = defineProps<{
  label: string
  value: string
  unit?: string
  subline?: string
  series?: Series | null
}>()

const field = ref<SVGSVGElement | null>(null)
const hovered = ref<Point | null>(null)

const line = computed(() => {
  const points = props.series?.points ?? []

  return points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x} ${p.y}`).join(' ')
})

const area = computed(() => {
  const points = props.series?.points ?? []
  if (points.length === 0) return ''

  return `${line.value} L100 32 L0 32 Z`
})

const last = computed<Point | null>(() => {
  const points = props.series?.points ?? []

  return points.length > 0 ? points[points.length - 1] : null
})

/*
 * Der viewBox ist 100 Einheiten breit und wird auf die Kachelbreite gezogen.
 * Die Zeigerposition muss denselben Weg zurück — deshalb die Umrechnung über
 * die tatsächliche Breite und nicht über die Koordinaten des Ereignisses.
 */
function nearest(event: PointerEvent): void {
  const points = props.series?.points ?? []
  if (points.length === 0 || !field.value) return

  const box = field.value.getBoundingClientRect()
  if (box.width === 0) return

  const x = ((event.clientX - box.left) / box.width) * 100
  let match: Point | null = null
  let distance = Number.POSITIVE_INFINITY

  for (const point of points) {
    const d = Math.abs(point.x - x)

    if (d < distance) {
      distance = d
      match = point
    }
  }

  hovered.value = match
}
</script>

<template>
  <div class="kachel">
    <div class="kachel-label">{{ label }}</div>

    <div class="kachel-wert">
      {{ value }}<small v-if="unit">{{ unit }}</small>
    </div>

    <!--
      Dieselbe Zeile trägt zweierlei: für gewöhnlich die Einordnung, beim
      Zeigen die Ablesung. Zwei Zeilen untereinander wären eine, die meistens
      leer ist — und die Kachel spränge, sobald sie sich füllt.
    -->
    <div class="kachel-sub" :data-ablesung="hovered !== null">
      <template v-if="hovered">{{ hovered.t }} · {{ hovered.v }}</template>
      <template v-else>{{ subline }}</template>
    </div>

    <div v-if="series?.has" class="verlauf">
      <svg
        ref="field"
        viewBox="0 0 100 32"
        preserveAspectRatio="none"
        role="img"
        :aria-label="`Verlauf ${label}, zuletzt ${last?.v ?? value}`"
        @pointermove="nearest"
        @pointerleave="hovered = null"
      >
        <line x1="0" y1="16" x2="100" y2="16" class="gitter" vector-effect="non-scaling-stroke" />
        <path :d="area" class="flaeche" />
        <path :d="line" class="linie" vector-effect="non-scaling-stroke" />
        <circle v-if="last" :cx="last.x" :cy="last.y" r="2" class="ende" />
        <circle v-if="hovered" :cx="hovered.x" :cy="hovered.y" r="2.6" class="zeiger" />
      </svg>
    </div>

    <!-- Der leere Zustand steht in derselben Höhe wie die Kurve, damit eine
         Kachel ohne Messwerte die Reihe nicht verkürzt. -->
    <p v-else class="verlauf ohne">noch keine Messwerte</p>
  </div>
</template>

<style scoped>
.verlauf {
  height: 46px;
  margin-top: 12px;
}

.verlauf svg {
  display: block;
  width: 100%;
  height: 46px;
  cursor: crosshair;

  /* Ohne das rollt ein Telefon die Seite, sobald jemand über die Kurve
     streicht, statt sie abzulesen. */
  touch-action: none;
}

.ohne {
  display: flex;
  align-items: center;
  margin: 12px 0 0;
  font-size: var(--text-small);
  color: var(--text-muted);
}

.gitter {
  stroke: var(--line);
  stroke-width: 1;
}

.flaeche {
  fill: var(--accent);

  /*
   * Die Fläche unter der Linie als Deckung und nicht als eigene Marke.
   *
   * `--accent-surface` wäre die naheliegende Wahl und die falsche: Sie ist
   * die getönte Fläche eines Bedienelements und liegt deshalb höher. Unter
   * einer Kurve, die über eine Kachelbreite läuft, wird daraus ein Block, der
   * die Linie erschlägt.
   */
  opacity: 0.11;
}

.linie {
  fill: none;
  stroke: var(--accent);
  stroke-width: 1.8;
  stroke-linejoin: round;
  stroke-linecap: round;
}

.ende,
.zeiger {
  fill: var(--accent);
}
</style>
