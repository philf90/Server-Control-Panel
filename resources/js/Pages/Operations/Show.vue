<script setup lang="ts">
/*
 * Ein Vorgang mit seiner Ausgabe.
 *
 * **Der Strom läuft nur, solange der Vorgang offen ist.** Ein fertiger Vorgang
 * ändert sich nicht mehr; eine Verbindung dafür offen zu halten belegte einen
 * PHP-FPM-Arbeiter für nichts. Die Ausgabe steht dann schon vollständig in den
 * Eigenschaften der Seite.
 */
import { Head, Link } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import { useOperationStream } from '../../Composables/useOperationStream'

interface Operation {
  id: number
  type: string
  label: string
  status: string
  status_label: string
  open: boolean
  progress: number
  message: string | null
  account: string | null
  started_at: string | null
  finished_at: string | null
  payload: Record<string, unknown> | null
  result: Record<string, unknown> | null
  output: string
}

const props = defineProps<{ operation: Operation }>()

const live = props.operation.open ? useOperationStream(props.operation.id) : null

// Was beim Laden schon dastand, plus was seither dazukam. Der Strom nimmt die
// Ausgabe ab dem Zeichen auf, das der Browser zuletzt gesehen hat — er schickt
// den bisherigen Text also nicht noch einmal.
const output = computed(() => props.operation.output + (live?.output.value ?? ''))

const status = computed(() => live?.state.value?.status ?? props.operation.status)
const label = computed(() => live?.state.value?.label ?? props.operation.status_label)
const progress = computed(() => live?.state.value?.progress ?? props.operation.progress)
const message = computed(() => live?.state.value?.message ?? props.operation.message)

const box = ref<HTMLElement | null>(null)

// Mitlaufen lassen, solange etwas kommt. Ohne das müsste beim Zusehen jemand
// von Hand scrollen, und genau dann ist der Vorgang das, worauf er wartet.
watch(output, () => {
  requestAnimationFrame(() => {
    if (box.value) box.value.scrollTop = box.value.scrollHeight
  })
})
</script>

<template>
  <Head :title="`Vorgang ${props.operation.id}`" />

  <PanelLayout :title="props.operation.label" :subline="`Vorgang ${props.operation.id} · ${props.operation.type}`">
    <p class="zurueck"><Link href="/operations">← Alle Vorgänge</Link></p>

    <dl class="kopf">
      <div><dt>Zustand</dt><dd :data-status="status">{{ label }}</dd></div>
      <div><dt>Fortschritt</dt><dd>{{ progress }} %</dd></div>
      <div><dt>Ausgelöst von</dt><dd>{{ props.operation.account ?? '—' }}</dd></div>
      <div><dt>Begonnen</dt><dd>{{ props.operation.started_at ?? '—' }}</dd></div>
      <div><dt>Beendet</dt><dd>{{ props.operation.finished_at ?? '—' }}</dd></div>
    </dl>

    <p v-if="message" class="meldung" :data-status="status">{{ message }}</p>

    <h2>Argumente</h2>
    <pre class="daten">{{ JSON.stringify(props.operation.payload ?? {}, null, 2) }}</pre>

    <h2>Ausgabe</h2>
    <pre ref="box" class="ausgabe">{{ output || 'Noch keine Ausgabe.' }}</pre>

    <template v-if="props.operation.result">
      <h2>Ergebnis</h2>
      <pre class="daten">{{ JSON.stringify(props.operation.result, null, 2) }}</pre>
    </template>
  </PanelLayout>
</template>

<style scoped>
.zurueck { margin: 0 0 var(--gap); font-size: .8rem; }
.kopf { display: flex; flex-wrap: wrap; gap: 1.5rem; margin: 0 0 var(--gap); }
.kopf dt { font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; color: var(--text-faint); }
.kopf dd { margin: .15rem 0 0; font-size: .85rem; color: var(--text); }
.kopf dd[data-status='failed'] { color: var(--warn); }
.kopf dd[data-status='running'], .kopf dd[data-status='queued'] { color: var(--accent); }
.meldung { padding: .5rem .7rem; font-size: .85rem; border-radius: 6px; background: var(--surface); }
.meldung[data-status='failed'] { color: var(--warn); background: var(--warn-surface); }
h2 { margin: calc(var(--gap) * 1.5) 0 .4rem; font-size: .8rem; font-weight: 600; color: var(--text-muted); }
pre { margin: 0; padding: .6rem .7rem; font-family: var(--font-mono); font-size: .78rem; line-height: 1.5; white-space: pre-wrap; word-break: break-word; background: var(--surface); border: 1px solid var(--line); border-radius: 6px; }
.ausgabe { max-height: 26rem; overflow-y: auto; }
.daten { color: var(--text-muted); }
</style>
