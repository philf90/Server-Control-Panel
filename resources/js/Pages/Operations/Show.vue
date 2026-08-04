<script setup lang="ts">
/*
 * Ein Vorgang mit seiner Ausgabe.
 *
 * **Der Strom läuft nur, solange der Vorgang offen ist.** Ein fertiger Vorgang
 * ändert sich nicht mehr; eine Verbindung dafür offen zu halten belegte einen
 * PHP-FPM-Arbeiter für nichts. Die Ausgabe steht dann schon vollständig in den
 * Eigenschaften der Seite.
 */
import { Head, Link, router } from '@inertiajs/vue3'
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
  cancel_requested: boolean
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

const open = computed(() => live?.state.value?.open ?? props.operation.open)

// Der Wunsch bleibt stehen, bis die Seite neu geladen wird — der Ereignisstrom
// überträgt ihn nicht, weil er den Zustand des Vorgangs meldet und nicht den
// Zustand dieses Knopfes.
const cancelRequested = ref(props.operation.cancel_requested)

function cancel(): void {
  if (!window.confirm('Diesen Vorgang abbrechen?')) return

  cancelRequested.value = true
  router.post(`/operations/${props.operation.id}/cancel`, {}, { preserveScroll: true })
}

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
    <div class="leiste">
      <Link href="/operations">← Alle Vorgänge</Link>

      <button v-if="open" type="button" class="knopf gefahr" :disabled="cancelRequested" @click="cancel">
        {{ cancelRequested ? 'Abbruch angefordert …' : 'Abbrechen' }}
      </button>
    </div>

    <!--
      Der ehrliche Zwischenzustand. „Abgebrochen" steht erst da, wenn es
      zutrifft: Zwischen dem Wunsch und dem Ende des Programms auf dem Server
      liegen ein, zwei Sekunden, und in dieser Zeit läuft es noch.
    -->
    <p v-if="cancelRequested && open" class="wartet">
      Der Abbruch ist angefordert. Der Vorgang endet, sobald der Agent das
      laufende Programm beendet hat.
    </p>

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
.leiste { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin: 0 0 var(--gap); font-size: var(--text-small); }
.wartet { margin: 0 0 var(--gap); padding: 8px 11px; font-size: var(--text-small); color: var(--warn); background: var(--warn-surface); border-radius: 6px; }
.kopf { display: flex; flex-wrap: wrap; gap: 24px; margin: 0 0 var(--gap); }
.kopf dt { font-size: var(--text-label); text-transform: uppercase; letter-spacing: .08em; color: var(--text-faint); }
.kopf dd { margin: 2px 0 0; font-size: var(--text-table); color: var(--text); }
.kopf dd[data-status='failed'] { color: var(--warn); }
.kopf dd[data-status='running'], .kopf dd[data-status='queued'] { color: var(--accent); }
.meldung { padding: 8px 11px; font-size: var(--text-table); border-radius: 6px; background: var(--surface); }
.meldung[data-status='failed'] { color: var(--warn); background: var(--warn-surface); }
h2 { margin: calc(var(--gap) * 1.5) 0 6px; font-size: var(--text-small); font-weight: 600; color: var(--text-muted); }
pre { margin: 0; padding: 10px 11px; font-family: var(--font-mono); font-size: var(--text-small); line-height: 1.5; white-space: pre-wrap; word-break: break-word; background: var(--surface); border: 1px solid var(--line); border-radius: 6px; }
.ausgabe { max-height: 416px; overflow-y: auto; }
.daten { color: var(--text-muted); }
</style>
