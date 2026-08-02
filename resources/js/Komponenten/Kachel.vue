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

export interface Punkt {
  x: number
  y: number
  t: string
  v: string
}

export interface Verlauf {
  hat: boolean
  punkte: Punkt[]
}

const props = defineProps<{
  label: string
  wert: string
  einheit?: string
  unterzeile?: string
  verlauf?: Verlauf | null
}>()

const feld = ref<SVGSVGElement | null>(null)
const gezeigt = ref<Punkt | null>(null)

const linie = computed(() => {
  const punkte = props.verlauf?.punkte ?? []
  return punkte.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x} ${p.y}`).join(' ')
})

const flaeche = computed(() => {
  const punkte = props.verlauf?.punkte ?? []
  if (punkte.length === 0) return ''
  return `${linie.value} L100 32 L0 32 Z`
})

const letzter = computed<Punkt | null>(() => {
  const punkte = props.verlauf?.punkte ?? []
  return punkte.length > 0 ? punkte[punkte.length - 1] : null
})

/*
 * Der viewBox ist 100 Einheiten breit und wird auf die Kachelbreite gezogen.
 * Die Zeigerposition muss denselben Weg zurück — deshalb die Umrechnung über
 * die tatsächliche Breite und nicht über die Koordinaten des Ereignisses.
 */
function naechster(ereignis: PointerEvent): void {
  const punkte = props.verlauf?.punkte ?? []
  if (punkte.length === 0 || !feld.value) return

  const kasten = feld.value.getBoundingClientRect()
  if (kasten.width === 0) return

  const x = ((ereignis.clientX - kasten.left) / kasten.width) * 100
  let treffer: Punkt | null = null
  let abstand = Number.POSITIVE_INFINITY

  for (const punkt of punkte) {
    const d = Math.abs(punkt.x - x)
    if (d < abstand) {
      abstand = d
      treffer = punkt
    }
  }

  gezeigt.value = treffer
}
</script>

<template>
  <div class="kachel">
    <div class="label">{{ label }}</div>
    <div class="wert zahl">
      {{ wert }}<small v-if="einheit">{{ einheit }}</small>
    </div>

    <div class="unterzeile zahl">
      <template v-if="gezeigt">{{ gezeigt.t }} · {{ gezeigt.v }}</template>
      <template v-else>{{ unterzeile }}</template>
    </div>

    <div v-if="verlauf?.hat" class="verlauf">
      <svg
        ref="feld"
        viewBox="0 0 100 32"
        preserveAspectRatio="none"
        role="img"
        :aria-label="`Verlauf ${label}, zuletzt ${letzter?.v ?? wert}`"
        @pointermove="naechster"
        @pointerleave="gezeigt = null"
      >
        <line x1="0" y1="16" x2="100" y2="16" class="raster" vector-effect="non-scaling-stroke" />
        <path :d="flaeche" class="fuellung" />
        <path :d="linie" class="strich" vector-effect="non-scaling-stroke" />
        <circle v-if="letzter" :cx="letzter.x" :cy="letzter.y" r="1.6" class="ende" />
        <circle v-if="gezeigt" :cx="gezeigt.x" :cy="gezeigt.y" r="1.8" class="marke" />
      </svg>
    </div>
    <div v-else class="verlauf leer">noch keine Messwerte</div>
  </div>
</template>

<style scoped>
.kachel {
  background: var(--bereich);
  border: 1px solid var(--bereich-rand);
  border-radius: 3px;
  padding: var(--polster);
  min-width: 0;
}

.label {
  font-size: 10.5px;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--text-ruhig);
}

.wert {
  font-family: var(--font-mono);
  font-size: 22px;
  letter-spacing: -0.02em;
  color: var(--text-stark);
  margin-top: 3px;
  display: flex;
  align-items: baseline;
  gap: 3px;
}

.wert small {
  font-size: 11px;
  color: var(--text-ruhig);
}

.unterzeile {
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--text-schwach);
  height: 16px;
  overflow: hidden;
  white-space: nowrap;
}

.verlauf {
  height: 34px;
  margin-top: 6px;
}

.verlauf svg {
  display: block;
  width: 100%;
  height: 34px;
  touch-action: none;
}

.verlauf.leer {
  font-size: 11px;
  color: var(--text-schwach);
  display: flex;
  align-items: center;
}

.raster {
  stroke: var(--bereich-rand);
  stroke-width: 1;
}

.fuellung {
  fill: var(--akzent-flaeche);
}

.strich {
  fill: none;
  stroke: var(--akzent);
  stroke-width: 1.5;
  stroke-linejoin: round;
  stroke-linecap: round;
}

.ende,
.marke {
  fill: var(--akzent);
}
</style>
