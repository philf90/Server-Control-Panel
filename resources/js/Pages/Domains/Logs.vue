<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import { counted } from '../../Composables/useCounted'
import { formatBytes } from '../../bytes'

const props = defineProps<{
  domain: { id: number; name: string }
  kind: string
  lines: number
  log: {
    lines: string[]
    exists: boolean
    path: string | null

    // Die Grösse der Datei. Gesendet, seit es die Operation gibt, und bis zum
    // 14. September 2026 von niemandem gezeigt.
    size: number

    // Hat das Fenster den Anfang der Datei erreicht, und hat der Bytedeckel
    // zugeschlagen. Zwei Felder und nicht eines: Sie sind die beiden Gründe,
    // aus denen ein Fenster unvollständig sein kann, und die Abhilfe für den
    // einen lässt den anderen stehen (`docs/914 §2`).
    complete: boolean
    capped: boolean
  }

  // **Neben der Antwort und nicht in ihr.** `props.log` ist, was der Agent
  // gesagt hat; ein Fehlschlag heisst, dass er nichts gesagt hat.
  error: string | null
}>()

function zeige(kind: string): void {
  router.get(`/domains/${props.domain.id}/logs`, { kind, lines: props.lines }, { preserveState: false })
}

function mehr(): void {
  router.get(`/domains/${props.domain.id}/logs`, { kind: props.kind, lines: Math.min(500, props.lines * 2) })
}

/**
 * Ob „Mehr Zeilen" überhaupt etwas bewirkt — jeder der drei Teile ist gemessen.
 *
 * **`! complete`** — der Anfang der Datei ist schon erreicht; eine grössere
 * Anfrage liefert dieselben Zeilen (`docs/919 §1`, M11: 36 Zeilen bei
 * `lines=100` wie bei `lines=500`).
 *
 * **`! capped`** — und das ist der Teil, den niemand vermuten würde: Greift der
 * Bytedeckel, liefert eine grössere Anfrage **byteweise dasselbe**. Gemessen an
 * einer Datei mit 6000 Zeichen je Zeile: 87 Zeilen bei `lines` 100, 200 und
 * 500, jedes Mal ab derselben Zeile (M9). Der Deckel sitzt auf den Bytes, und
 * die Zahl der Zeilen verschiebt ihn nicht.
 *
 * **`lines < 500`** — `WebLogsTail::MAX_LINES` ist die Grenze der Operation.
 *
 * **Und `truncated` von `/logs` gehört nicht hierher.** Dort ist das Fenster
 * immer 500 Zeilen gross und `lines` schneidet nur das Ergebnis; hier **ist**
 * `lines` das Fenster, und der Knopf liest wirklich weiter zurück (M10: 100 →
 * 200 → 400 Zeilen, erste Zeile 301 → 201 → 1). Dieselbe Bedingung an zwei
 * Seiten wäre die zweite Fassung einer Regel, die hier etwas anderes bedeutet.
 */
const mehrDa = computed(() => !props.log.complete && !props.log.capped && props.lines < 500)
</script>

<template>
  <Head :title="`Protokolle · ${props.domain.name}`" />

  <PanelLayout :title="props.domain.name" subline="Protokolle">
    <template #breadcrumb>
      <Link href="/domains" class="link">Domains</Link> ·
      <Link :href="`/domains/${props.domain.id}`" class="link">{{ props.domain.name }}</Link>
    </template>

    <template #actions>
      <!--
        Die beiden Umschalter sind eine Wahl und keine Rangfolge: Der gewählte
        trägt `.aktiv` — Akzentrand und getönte Fläche —, nicht `.wichtig`.
        Sonst stünde in einer Zweierreihe eine Hauptsache, und die Reihe ist
        keine.
      -->
      <button type="button" class="button" :class="{ active: props.kind === 'access' }" @click="zeige('access')">
        Zugriffe
      </button>
      <button type="button" class="button" :class="{ active: props.kind === 'error' }" @click="zeige('error')">
        Fehler
      </button>
    </template>

    <!--
      Drei verschiedene Auskünfte, und keine sieht aus wie eine andere: Der
      Agent antwortet nicht, die Datei gibt es noch nicht, die Datei ist leer.
      Eine leere Liste für alle drei wäre die bequeme Lösung — und sähe im
      ersten Fall aus, als sei alles in Ordnung.
    -->
    <p v-if="props.error" class="notice critical">
      Der Agent antwortet nicht: {{ props.error }}
    </p>

    <p v-else-if="!props.log.exists" class="empty">
      Für diese Domain gibt es noch kein Protokoll. Es entsteht mit dem ersten
      Zugriff.
    </p>

    <p v-else-if="props.log.lines.length === 0" class="empty">
      Das Protokoll ist leer.
    </p>

    <template v-else>
      <!--
        Woher die Zeilen kommen und wie gross die Datei ist. Beides steht in der
        Antwort; die Grösse hat sie bis zum 14. September 2026 niemand gezeigt.
      -->
      <p class="breadcrumb ident">{{ props.log.path }} · {{ formatBytes(props.log.size) }}</p>

      <pre class="output log">{{ props.log.lines.join('\n') }}</pre>

      <div class="button-row footer-row">
        <!--
          **Drei Zustände und drei Sätze, keiner mit Einschüben.** Sie schliessen
          einander aus: `capped` setzt voraus, dass der Leser nicht am Anfang der
          Datei steht, ist mit `complete` also nie zugleich wahr.

          Der gedeckelte Fall ist der, den es ohne die Messrunde nicht gäbe — von
          aussen sieht er Zeichen für Zeichen aus wie eine kurze Datei, und er
          trifft genau die Protokolle, die man liest, wenn etwas kaputt ist: Ein
          nginx-`error.log` mit Stacktraces liegt regelmässig über der gemessenen
          Schwelle (`docs/919 §1`, M2).

            Eine Seite, die nichts über ihre Grenze sagt, lässt den Leser
            annehmen, dass es keine gibt.
        -->
        <p class="quiet">
          {{ counted(props.log.lines.length, 'Zeile', 'Zeilen') }}
          <template v-if="props.log.complete">· das ist die ganze Datei.</template>
          <template v-else-if="props.log.capped">
            · weiter zurück wurde nicht gelesen; das Fenster ist auch in Bytes
            begrenzt.
          </template>
          <template v-else>· die Datei ist länger.</template>
        </p>

        <button v-if="mehrDa" type="button" class="button" @click="mehr">
          Mehr Zeilen ({{ props.lines }} → {{ Math.min(500, props.lines * 2) }})
        </button>
      </div>
    </template>
  </PanelLayout>
</template>

<style scoped>
/*
 * Das Protokoll rollt in beide Richtungen und bricht keine Zeile um.
 *
 * Eine umgebrochene Zeile eines Zugriffsprotokolls ist unlesbar: Man erkennt
 * nicht mehr, wo ein Eintrag anfängt. Auf 390px rollt es waagerecht — dieselbe
 * Entscheidung wie bei den Tabellen unter `.scrolls`.
 *
 * Form und Farbe kommen aus `.output` in app.css; hier steht nur, was dieses
 * eine Protokoll davon unterscheidet.
 */
.log {
  margin: 0;
  max-height: 60dvh;
  overflow: auto;
  white-space: pre;
}

/*
 * Satz links, Knopf rechts — und auf der schmalen Ansicht untereinander, weil
 * `.button-row` umbricht. `baseline`, damit der Satz nicht an der Oberkante des
 * Knopfes hängt.
 */
.footer-row {
  align-items: baseline;
  justify-content: space-between;
  margin-top: var(--gap);
}
</style>
