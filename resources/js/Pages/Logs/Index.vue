<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, reactive, watch } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'
import { counted } from '../../Composables/useCounted'
import { formatBytes } from '../../bytes'

/*
 * Die Protokolle des Servers.
 *
 * **Quelle und Filter stehen in der Adresszeile**, nicht im Zustand der Seite
 * — dieselbe Entscheidung wie beim Protokoll (`Audit/Index.vue`): Ein Blick
 * lässt sich damit weitergeben und als Lesezeichen behalten, und das
 * Herunterladen bekommt dieselben Werte mit, ohne sie ein zweites Mal
 * einzusammeln.
 *
 * **Keine Wahrheitswerte in der Adresse.** `router.get` legt seine Werte in
 * die URL, und dort ist alles eine Zeichenkette: Aus `false` wird das Wort
 * `"false"`, und Laravels Regel `boolean` nimmt kein Wort (`docs/66`). Diese
 * Seite hat deshalb einen Textfilter und kein Kästchen.
 */

interface Source {
  key: string
  kind: string
  label: string
  origin: string
  exists: boolean | null
  size: number | null
  modified_display: string | null
}

const props = defineProps<{
  sources: Source[]
  source: string
  lines: number
  filter: string
  result: {
    lines: string[]
    exists: boolean
    note: string | null
    matched: number
    window: number
    truncated: boolean
  }
  error: string | null
}>()

const auswahl = reactive({ source: props.source, filter: props.filter })

let timer: ReturnType<typeof setTimeout> | undefined

watch(auswahl, () => {
  if (timer) clearTimeout(timer)

  // Entprellt, weil der Filter beim Tippen läuft und jede Anfrage den Agenten
  // eine Datei lesen lässt.
  timer = setTimeout(() => {
    router.get('/logs', { ...auswahl, lines: props.lines }, { preserveState: true, replace: true })
  }, 300)
})

const gewaehlt = computed(() => props.sources.find((s) => s.key === props.source))

function mehr(): void {
  router.get('/logs', { ...auswahl, lines: Math.min(500, props.lines * 2) })
}

function ladeUrl(): string {
  const query = new URLSearchParams({ ...auswahl, lines: String(props.lines) })

  return `/logs/download?${query.toString()}`
}

/*
 * Die Grösse einer Protokolldatei.
 *
 * **`formatBytes` und keine eigene Staffel.** Hier stand beim Bau von A5 eine
 * dritte Fassung derselben Umrechnung — und eine schlechtere: ohne
 * Tausendertrennung, ohne GB, mit `toFixed` statt der deutschen Schreibweise.
 * Eine Datei von 1,2 GB las sich als „1234.6 MB".
 *
 * Gefunden hat es `SizeUnitTest`, der genau dafür existiert. Dass er es erst
 * jetzt gemeldet hat, liegt nicht an ihm: Die CI läuft auf `push` nur für
 * `main`, und auf diesem Zweig ist sie bis heute kein einziges Mal gefahren.
 *
 * > **Ein Wächter, den man nicht fährt, ist von einem, den es nicht gibt, nicht
 * > zu unterscheiden.**
 *
 * `null` heisst „nicht gemessen" — die Unterscheidung trifft der Aufrufer, weil
 * nur er weiss, wie sie an seiner Stelle heisst.
 */
function groesse(bytes: number | null): string {
  return bytes === null ? '—' : formatBytes(bytes)
}
</script>

<template>
  <Head title="Logs" />

  <PanelLayout title="Logs" subline="Die Protokolle dieses Servers">
    <template #actions>
      <!--
        **„Angezeigtes sichern" und nicht „Herunterladen".** Die Antwort des
        Agenten ist auf knapp ein Megabyte begrenzt, ein Zugriffsprotokoll ist
        ein Vielfaches davon. Ein Knopf, der die ganze Datei verspräche, gäbe
        stillschweigend die letzten Zeilen.

          Ein Knopf, der mehr verspricht, als der Weg dahinter trägt, ist eine
          Zusage und keine Bequemlichkeit.
      -->
      <a :href="ladeUrl()" class="button">Angezeigtes sichern</a>
    </template>

    <div class="sections">
      <Section title="Quelle" full>
        <div class="filter">
          <label class="field">
            <span>Protokoll</span>
            <select v-model="auswahl.source">
              <option v-for="s in props.sources" :key="s.key" :value="s.key">{{ s.label }}</option>
            </select>
          </label>

          <label class="field">
            <span>Filter</span>
            <input v-model="auswahl.filter" type="text" placeholder="z. B. error">
          </label>
        </div>

        <!--
          Woher die Zeilen kommen, steht als Kennung da — ein Pfad oder ein
          Unitname. Der Betreiber soll dieselbe Datei über SSH wiederfinden.
        -->
        <p v-if="gewaehlt" class="breadcrumb ident">
          {{ gewaehlt.origin }}
          <template v-if="gewaehlt.kind === 'file' && gewaehlt.exists">
            · {{ groesse(gewaehlt.size) }}
            <template v-if="gewaehlt.modified_display">· zuletzt {{ gewaehlt.modified_display }}</template>
          </template>
        </p>
      </Section>

      <Section title="Zeilen" full>
        <!--
          **Fünf verschiedene Auskünfte, und keine sieht aus wie eine andere.**
          Der Agent antwortet nicht · das Journal gibt es auf diesem Server
          nicht · die Datei gibt es noch nicht · es gibt sie und sie ist leer ·
          der Filter passt auf nichts. Eine leere Liste für alle fünf wäre die
          bequeme Lösung — und sähe im ersten Fall aus, als sei alles in
          Ordnung.

            Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts
            zu tun".
        -->
        <p v-if="props.error" class="notice critical">
          Der Agent antwortet nicht: {{ props.error }}
        </p>

        <template v-else>
          <p v-if="props.result.note" class="notice">{{ props.result.note }}</p>

          <p v-if="!props.result.exists && props.result.lines.length === 0" class="empty">
            <template v-if="gewaehlt?.kind === 'journal'">
              Für diese Unit steht nichts im Journal.
            </template>
            <template v-else>
              Dieses Protokoll gibt es noch nicht. Es entsteht, sobald etwas hineingeschrieben wird.
            </template>
          </p>

          <p v-else-if="props.result.lines.length === 0" class="empty">
            <template v-if="props.filter">Auf diesen Filter passt keine Zeile.</template>
            <template v-else>Das Protokoll ist leer.</template>
          </p>

          <template v-else>
            <pre class="output log">{{ props.result.lines.join('\n') }}</pre>

            <div class="button-row footer-row">
              <!--
                Der Satz nennt beide Zahlen, weil eine allein etwas Falsches
                sagt: `matched` sind die Treffer im **gelesenen Fenster** und
                nicht die Zeilen der Datei.
              -->
              <p class="quiet">
                {{ counted(props.result.lines.length, 'Zeile', 'Zeilen') }}
                <template v-if="props.result.truncated">
                  von {{ counted(props.result.matched, 'Treffer', 'Treffern') }}
                </template>
                · gelesen wurden die letzten
                {{ counted(props.result.window, 'Zeile', 'Zeilen') }}
              </p>

              <button v-if="props.lines < 500" type="button" class="button" @click="mehr">
                Mehr Zeilen ({{ props.lines }} → {{ Math.min(500, props.lines * 2) }})
              </button>
            </div>
          </template>
        </template>
      </Section>
    </div>
  </PanelLayout>
</template>

<style scoped>
/*
 * Das Protokoll rollt in beide Richtungen und bricht keine Zeile um.
 *
 * Dieselbe Entscheidung und derselbe Grund wie bei `Domains/Logs.vue`: Eine
 * umgebrochene Zeile eines Protokolls ist unlesbar, weil man nicht mehr
 * erkennt, wo ein Eintrag anfängt. Auf 390 px rollt sie waagerecht — wie die
 * Tabellen unter `.scrolls`.
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

.footer-row {
  align-items: baseline;
  justify-content: space-between;
  margin-top: var(--gap);
}
</style>
