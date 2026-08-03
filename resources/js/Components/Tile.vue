<script setup lang="ts">
/*
 * Die Verlaufskachel — der eine Baustein, den das Panel aus dem Vorgänger
 * übernimmt (§4.6, §7.2 des Plans). Übernommen ist das Prinzip, nicht der
 * Code:
 *
 *   1. Keine Diagramm-Bibliothek. Ein SVG, ein Pfad, eine Ablesung.
 *   2. Der Server liefert fertige Stützstellen samt Beschriftung und Einheit.
 *      Hier wird nicht gerechnet, hier wird gezeichnet und gesucht.
 *   3. Kein Diagramm ohne Ablesung. Wer auf die Linie zeigt, sieht Zeitpunkt
 *      und Wert; sonst wäre die Linie Dekoration.
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
  <div class="tile">
    <div class="label">{{ label }}</div>
    <div class="value num">
      {{ value }}<small v-if="unit">{{ unit }}</small>
    </div>

    <div class="subline num">
      <template v-if="hovered">{{ hovered.t }} · {{ hovered.v }}</template>
      <template v-else>{{ subline }}</template>
    </div>

    <div v-if="series?.has" class="series">
      <svg
        ref="field"
        viewBox="0 0 100 32"
        preserveAspectRatio="none"
        role="img"
        :aria-label="`Series ${label}, zuletzt ${last?.v ?? value}`"
        @pointermove="nearest"
        @pointerleave="hovered = null"
      >
        <line x1="0" y1="16" x2="100" y2="16" class="grid" vector-effect="non-scaling-stroke" />
        <path :d="area" class="fill" />
        <path :d="line" class="stroke" vector-effect="non-scaling-stroke" />
        <circle v-if="last" :cx="last.x" :cy="last.y" r="1.6" class="endpoint" />
        <circle v-if="hovered" :cx="hovered.x" :cy="hovered.y" r="1.8" class="badge" />
      </svg>
    </div>
    <div v-else class="series empty">noch keine Messwerte</div>
  </div>
</template>

<style scoped>
.tile {
  background: var(--surface);
  border: 1px solid var(--surface-border);
  border-radius: 3px;
  padding: var(--padding);
  min-width: 0;
}

.label {
  font-size: var(--text-label);
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--text-muted);
}

.value {
  font-family: var(--font-mono);
  font-size: var(--text-metric);
  letter-spacing: -0.02em;
  color: var(--text-strong);
  margin-top: 3px;
  display: flex;
  align-items: baseline;
  gap: 3px;
}

.value small {
  font-size: var(--text-small);
  color: var(--text-muted);
}

.subline {
  font-family: var(--font-mono);
  font-size: var(--text-small);
  color: var(--text-faint);
  height: 16px;
  overflow: hidden;
  white-space: nowrap;
}

.series {
  height: 34px;
  margin-top: 6px;
}

.series svg {
  display: block;
  width: 100%;
  height: 34px;
  touch-action: none;
}

.series.leer {
  font-size: var(--text-small);
  color: var(--text-faint);
  display: flex;
  align-items: center;
}

.grid {
  stroke: var(--surface-border);
  stroke-width: 1;
}

.fill {
  fill: var(--accent-surface);
}

.stroke {
  fill: none;
  stroke: var(--accent);
  stroke-width: 1.5;
  stroke-linejoin: round;
  stroke-linecap: round;
}

.endpoint,
.badge {
  fill: var(--accent);
}
</style>
