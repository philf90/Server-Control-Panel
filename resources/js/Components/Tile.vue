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

  /**
   * Der letzte Wert liegt über der Schwelle — die Kurve trägt die Warnfarbe.
   *
   * Die Schwelle kommt vom Server und steht nicht hier: Ab wann eine
   * Auslastung eng ist, ist eine Aussage über den Betrieb und keine über die
   * Darstellung (OverviewController::tiles).
   */
  warns: boolean

  points: Point[]
}

/**
 * Die zweite Richtung derselben Kennzahl — heute nur beim Netz.
 *
 * **Warum das eine zweite Kurve ist und keine zweite Kachel.** Eingehend und
 * ausgehend sind dieselbe Größe an derselben Leitung; nebeneinander in zwei
 * Kacheln müsste man zwei Achsen im Kopf zusammenrechnen, um zu sehen, welche
 * Richtung gerade die Leitung füllt. In einer Kachel liegen sie
 * übereinander — und genau das ist die Frage, die man an eine Netzkurve hat.
 *
 * Der Wert hier ist der **letzte** der zweiten Reihe; die Kachel rechnet
 * nichts, das steht in `OverviewController::tiles()`.
 */
export interface Second {
  /** „ausgehend" — steht in der Zeile unter der Zahl und in der Ablesung. */
  label: string

  /** Der letzte Wert, fertig formatiert. */
  value: string

  /**
   * Ihre eigene Einheit — sie muss nicht dieselbe sein wie oben.
   *
   * Beide Kurven teilen sich die Achse, damit das Bild nicht lügt; die Zahlen
   * teilen sich die Vorsilbe nicht. „0,0 MB/s" für 12,9 kB/s eingehend wäre
   * eine richtige Geometrie und ein falscher Messwert.
   */
  unit: string

  series: Series
}

const props = defineProps<{
  label: string
  value: string
  unit?: string
  subline?: string
  series?: Series | null
  second?: Second | null
}>()

const field = ref<SVGSVGElement | null>(null)

/*
 * Gezeigt wird der **Index** und nicht der Punkt.
 *
 * Beide Reihen kommen aus derselben Datei, mit derselben Eindampfung und
 * damit mit derselben Zeitachse: Stelle i ist in beiden derselbe Zeitpunkt.
 * Über den Index liest die Ablesung beide Richtungen ab; mit einem Punkt in
 * der Hand ginge das nur für eine.
 */
const hoveredAt = ref<number | null>(null)

/**
 * Auf welcher der beiden Kurven der Zeiger steht.
 *
 * **Warum die Ablesung nur eine zeigt.** Gemessen: In die Beizeile passen bei
 * 1440px rund 25 Zeichen je Zeile. „09:26 · eingehend 12,6 kB/s · ausgehend
 * 84,2 MB/s" braucht drei — die Kachel wüchse beim Zeigen um zwei Zeilen. Wer
 * auf eine Kurve zeigt, will diese Kurve ablesen; die andere Zahl steht im
 * Ruhezustand daneben.
 */
const hoveredOn = ref<'first' | 'second'>('first')

const hovered = computed<Point | null>(() => {
  const points = props.series?.points ?? []

  return hoveredAt.value === null ? null : (points[hoveredAt.value] ?? null)
})

const hoveredSecond = computed<Point | null>(() => {
  const points = props.second?.series.points ?? []

  return hoveredAt.value === null ? null : (points[hoveredAt.value] ?? null)
})

function path(points: Point[]): string {
  return points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x} ${p.y}`).join(' ')
}

const line = computed(() => path(props.series?.points ?? []))

const secondLine = computed(() => path(props.second?.series.points ?? []))

const secondLast = computed<Point | null>(() => {
  const points = props.second?.series.points ?? []

  return points.length > 0 ? points[points.length - 1] : null
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
  let match: number | null = null
  let distance = Number.POSITIVE_INFINITY

  for (const [index, point] of points.entries()) {
    const d = Math.abs(point.x - x)

    if (d < distance) {
      distance = d
      match = index
    }
  }

  hoveredAt.value = match

  /*
   * Und bei zwei Kurven: welche ist gemeint. Der viewBox ist 32 Einheiten
   * hoch und wird auf 46px gezogen — die Zeigerhöhe muss denselben Weg
   * zurück, sonst zeigt die Ablesung bei jedem Bildschirm eine andere Kurve.
   */
  if (props.second && match !== null && box.height > 0) {
    const y = ((event.clientY - box.top) / box.height) * 32
    const oben = props.series?.points[match]?.y ?? 0
    const unten = props.second.series.points[match]?.y ?? 0

    hoveredOn.value = Math.abs(unten - y) < Math.abs(oben - y) ? 'second' : 'first'
  }
}
</script>

<template>
  <div class="tile">
    <div class="tile-label">{{ label }}</div>

    <div class="tile-value">
      {{ value }}<small v-if="unit">{{ unit }}</small>
    </div>

    <!--
      Dieselbe Zeile trägt zweierlei: für gewöhnlich die Einordnung, beim
      Zeigen die Ablesung. Zwei Zeilen untereinander wären eine, die meistens
      leer ist — und die Kachel spränge, sobald sie sich füllt.

      Bei zwei Richtungen hält `.paired` zwei Zeilen frei, weil beide Zustände
      dort zweizeilig sind. Die Ablesung nennt **eine** Richtung — die, auf
      der der Zeiger steht — und nennt sie beim Namen: Die Zuordnung über die
      Kurvenfarbe zu machen wäre genau der Fehler, den WCAG 1.4.1 meint, und
      beide Richtungen zusammen brauchten drei Zeilen (gemessen bei 1440px).
    -->
    <div class="tile-sub" :class="{ paired: second }" :data-readout="hovered !== null">
      <template v-if="hovered && second && hoveredSecond">
        {{ hovered.t }} ·
        {{ hoveredOn === 'second' ? second.label : subline }}
        {{ hoveredOn === 'second' ? hoveredSecond.v : hovered.v }}
      </template>
      <template v-else-if="hovered">{{ hovered.t }} · {{ hovered.v }}</template>
      <!-- Das Leerzeichen vor der Einheit steht hier absichtlich: Ohne es las
           sich die Zeile als „ausgehend 65.981.645B/s". Die grosse Zahl hat
           dafür ein `<small>` mit Abstand, diese hier hat keins. -->
      <template v-else-if="second">{{ subline }} · {{ second.label }} {{ second.value }} {{ second.unit }}</template>
      <template v-else>{{ subline }}</template>
    </div>

    <!--
      **Der Unterschied zwischen bunt und bedeutend.** Die Kurve wechselt die
      Farbe, wenn der letzte Wert über der Schwelle liegt — „Datenträger" bei
      91 % ist nicht dieselbe Auskunft wie „CPU" bei 23 %. Fünf Kurven in fünf
      Farben wären Dekoration; eine, die als einzige warnt, ist eine Meldung.

      `tight` als Objektschlüssel und nicht als Ausdruck, damit
      `ClassReachTest` die Klasse sieht.
    -->
    <div v-if="series?.has" class="trend" :class="{ tight: series.warns }">
      <svg
        ref="field"
        viewBox="0 0 100 32"
        preserveAspectRatio="none"
        role="img"
        :aria-label="
          second
            ? `Verlauf ${label}, ${subline} zuletzt ${last?.v ?? value}, ${second.label} zuletzt ${secondLast?.v ?? second.value}`
            : `Verlauf ${label}, zuletzt ${last?.v ?? value}`
        "
        @pointermove="nearest"
        @pointerleave="hoveredAt = null"
      >
        <line x1="0" y1="16" x2="100" y2="16" class="grid" vector-effect="non-scaling-stroke" />
        <path :d="area" class="area" />
        <path :d="line" class="line" vector-effect="non-scaling-stroke" />

        <!--
          Die zweite Richtung: gestrichelt, ohne Fläche und in der zweiten
          Farbe. Drei Unterschiede und nicht nur einer — die Farbe allein
          trüge ihn für niemanden mit einer Rot-Grün-Schwäche.

          Sie liegt **über** der ersten, weil ausgehender Verkehr auf einem
          Webserver der grössere ist: darunter verschwände sie unter der
          Fläche der ersten.
        -->
        <path
          v-if="second"
          :d="secondLine"
          class="line second"
          :class="{ tight: second.series.warns }"
          vector-effect="non-scaling-stroke"
        />

        <circle v-if="last" :cx="last.x" :cy="last.y" r="2" class="end" />
        <circle
          v-if="secondLast && second"
          :cx="secondLast.x"
          :cy="secondLast.y"
          r="2"
          class="end second"
          :class="{ tight: second.series.warns }"
        />
        <circle v-if="hovered" :cx="hovered.x" :cy="hovered.y" r="2.6" class="cursor" />
        <circle
          v-if="hoveredSecond"
          :cx="hoveredSecond.x"
          :cy="hoveredSecond.y"
          r="2.6"
          class="cursor second"
          :class="{ tight: second?.series.warns }"
        />
      </svg>
    </div>

    <!-- Der leere Zustand steht in derselben Höhe wie die Kurve, damit eine
         Kachel ohne Messwerte die Reihe nicht verkürzt. -->
    <p v-else class="trend blank">noch keine Messwerte</p>
  </div>
</template>

<style scoped>
.trend {
  height: 46px;
  margin-top: 12px;
}

.trend svg {
  display: block;
  width: 100%;
  height: 46px;
  cursor: crosshair;

  /* Ohne das rollt ein Telefon die Seite, sobald jemand über die Kurve
     streicht, statt sie abzulesen. */
  touch-action: none;
}

.blank {
  display: flex;
  align-items: center;
  margin: 12px 0 0;
  font-size: var(--text-small);
  color: var(--text-muted);
}

.grid {
  stroke: var(--line);
  stroke-width: 1;
}

.area {
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

.line {
  fill: none;
  stroke: var(--accent);
  stroke-width: 1.8;
  stroke-linejoin: round;
  stroke-linecap: round;
}

.end,
.cursor {
  fill: var(--accent);
}

/*
 * Über der Schwelle trägt die ganze Kurve die Warnfarbe — Linie, Fläche und
 * Endpunkt zusammen.
 *
 * Nur die Linie umzufärben hatte im Browser nicht genügt: Die Fläche darunter
 * blieb im Akzent, und aus beidem wurde ein Verlauf, der nach einem
 * Darstellungsfehler aussah statt nach einer Warnung.
 */
.trend.tight .line:not(.second) {
  stroke: var(--warn);
}

.trend.tight .area,
.trend.tight .end:not(.second),
.trend.tight .cursor:not(.second) {
  fill: var(--warn);
}

/*
 * Die zweite Richtung — dritter Baustein neben Farbe und fehlender Fläche.
 *
 * **Drei Unterschiede und nicht einer.** Die beiden Kurven trennen sich in
 * Farbton, Strichart und darin, dass nur die erste eine Fläche unter sich hat.
 * Rechnerisch liegen Akzent und zweite Farbe im Helligkeitsverhältnis bei
 * 1,85:1 (hell) und 1,49:1 (dunkel) — wer Farbtöne schlecht unterscheidet,
 * sähe zwei gleich helle Linien. Der Strich löst das ohne Farbe (WCAG 1.4.1).
 *
 * `:not(.second)` oben ist der Grund, warum die Warnung der ersten Kurve die
 * zweite nicht mitfärbt: Sie haben getrennte Schwellen, weil eine Leitung in
 * eine Richtung voll sein kann und in die andere leer.
 */
.line.second {
  stroke: var(--accent-second);

  /*
   * Sechs Bildpunkte Strich, vier Lücke — und **Bildpunkte** sind hier das
   * Entscheidende.
   *
   * Zweimal danebengegriffen, beide Male aus demselben Grund: Die Linie trägt
   * `vector-effect="non-scaling-stroke"`, damit sie überall gleich dick ist.
   * Damit rechnet aber auch das Strichmuster im Bildschirmraum und nicht in
   * Nutzerkoordinaten. Ein `stroke-dasharray: 2 1.6` sind dann zwei Bildpunkte
   * Strich — im Bild war das keine gestrichelte Linie, sondern eine, die
   * unsauber gezeichnet aussah. Der Umweg über `pathLength="100"` half aus
   * demselben Grund nicht: Er ändert die Nutzerkoordinaten, und die zählen
   * hier nicht.
   */
  stroke-dasharray: 6 4;
}

.end.second,
.cursor.second {
  fill: var(--accent-second);
}

.line.second.tight {
  stroke: var(--warn);
}

.end.second.tight,
.cursor.second.tight {
  fill: var(--warn);
}
</style>
