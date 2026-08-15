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
import Section from '../../Components/Section.vue'
import Badge from '../../Components/Badge.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import { useOperationStream } from '../../Composables/useOperationStream'
import { useConfirmation } from '../../Composables/useConfirmation'

const { ask } = useConfirmation()

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
const label = computed(() => live?.state.value?.status_label ?? props.operation.status_label)
const progress = computed(() => live?.state.value?.progress ?? props.operation.progress)
const message = computed(() => live?.state.value?.message ?? props.operation.message)

const open = computed(() => live?.state.value?.open ?? props.operation.open)

// „Noch" ist eine Zusage, dass etwas kommt, und an einem fertigen Vorgang ist
// sie falsch: Vorgang 449 stand am 8. August 2026 auf „fertig" und darunter
// „Noch keine Ausgabe." — die Seite kannte den Zustand und benutzte ihn für
// diesen Satz nicht (docs/36 §22.3p).
const emptyOutput = computed(() => (open.value ? 'Noch keine Ausgabe.' : 'Keine Ausgabe.'))

// Auch die Zeiten kommen aus dem Kanal, sobald er etwas geschickt hat. Ohne das
// stünde an einem fertigen Vorgang „Begonnen —": Die erste Antwort entsteht,
// während er noch in der Warteschlange steht (docs/36 §22.3m).
const startedAt = computed(() => live?.state.value?.started_at ?? props.operation.started_at)
const finishedAt = computed(() => live?.state.value?.finished_at ?? props.operation.finished_at)

const rang = computed<'ok' | 'warn' | 'critical' | 'neutral'>(() => {
  if (status.value === 'succeeded') return 'ok'
  if (status.value === 'running' || status.value === 'queued') return 'warn'
  if (status.value === 'failed' || status.value === 'cancelled') return 'critical'

  return 'neutral'
})

// Der Wunsch bleibt stehen, bis die Seite neu geladen wird — der Ereignisstrom
// überträgt ihn nicht, weil er den Zustand des Vorgangs meldet und nicht den
// Zustand dieses Knopfes.
const cancelRequested = ref(props.operation.cancel_requested)

function cancel(): void {
  ask('Diesen Vorgang abbrechen?', 'Abbrechen lassen', () => {
    cancelRequested.value = true
    router.post(`/operations/${props.operation.id}/cancel`, {}, { preserveScroll: true })
  })
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

  <PanelLayout :title="props.operation.label">
    <template #breadcrumb>
      <Link href="/operations" class="link">Vorgänge</Link> ·
      <span class="ident">{{ props.operation.type }}</span> · Nummer {{ props.operation.id }}
    </template>

    <template #actions>
      <Badge :kind="rang" :running="open">{{ label }}</Badge>
      <button v-if="open" type="button" class="button danger" :disabled="cancelRequested" @click="cancel">
        {{ cancelRequested ? 'Abbruch angefordert …' : 'Abbrechen' }}
      </button>
    </template>

    <!--
      Der ehrliche Zwischenzustand. „Abgebrochen" steht erst da, wenn es
      zutrifft: Zwischen dem Wunsch und dem Ende des Programms auf dem Server
      liegen ein, zwei Sekunden, und in dieser Zeit läuft es noch.
    -->
    <p v-if="cancelRequested && open" class="notice warn">
      Der Abbruch ist angefordert. Der Vorgang endet, sobald der Agent das
      laufende Programm beendet hat.
    </p>

    <p v-if="message" class="notice" :class="rang === 'critical' ? 'critical' : 'ok'">{{ message }}</p>

    <div class="sections">
      <Section title="Gegenstand">
        <table class="pairs">
          <tbody>
            <tr><td class="quiet">Aufgabe</td><td class="right ident name">{{ props.operation.type }}</td></tr>
            <tr>
              <td class="quiet">Zustand</td>
              <td class="right"><Badge :kind="rang" :running="open">{{ label }}</Badge></td>
            </tr>
            <tr><td class="quiet">Ausgelöst von</td><td class="right name">{{ props.operation.account ?? '—' }}</td></tr>
            <tr><td class="quiet">Begonnen</td><td class="right">{{ startedAt ?? '—' }}</td></tr>
            <tr><td class="quiet">Beendet</td><td class="right">{{ finishedAt ?? '—' }}</td></tr>
          </tbody>
        </table>

        <div class="progress"><i :style="{ width: `${progress}%` }" /></div>
        <p class="section-note">Fortschritt {{ progress }} %</p>
      </Section>

      <Section title="Argumente" note="Was das Panel dem Agenten geschickt hat — typisiert und nicht als Kommandozeile.">
        <pre class="output facts">{{ JSON.stringify(props.operation.payload ?? {}, null, 2) }}</pre>
      </Section>

      <Section title="Ausgabe" full>
        <pre ref="box" class="output long">{{ output || emptyOutput }}</pre>
      </Section>

      <Section v-if="props.operation.result" title="Ergebnis" full>
        <pre class="output facts">{{ JSON.stringify(props.operation.result, null, 2) }}</pre>
      </Section>
    </div>
  </PanelLayout>
</template>

<style scoped>
/*
 * Form und Farbe der Ausgabe stehen in app.css — hier nur, wie hoch sie ist.
 * Die Argumente sind kurz und sollen nicht rollen; die Ausgabe eines Vorgangs
 * ist beliebig lang und bekommt eine Grenze, damit die Seite darunter
 * erreichbar bleibt.
 */
.output {
  margin: 0;
}

.facts {
  color: var(--text-muted);
}

.long {
  max-height: 420px;
}
</style>
