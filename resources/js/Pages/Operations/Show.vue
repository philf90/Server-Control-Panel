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
import Bereich from '../../Components/Bereich.vue'
import Marke from '../../Components/Marke.vue'
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

const rang = computed<'ok' | 'warn' | 'kritisch' | 'neutral'>(() => {
  if (status.value === 'succeeded') return 'ok'
  if (status.value === 'running' || status.value === 'queued') return 'warn'
  if (status.value === 'failed' || status.value === 'cancelled') return 'kritisch'

  return 'neutral'
})

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

  <PanelLayout :title="props.operation.label">
    <template #pfad>
      <Link href="/operations" class="verweis">Vorgänge</Link> ·
      <span class="kennung">{{ props.operation.type }}</span> · Nummer {{ props.operation.id }}
    </template>

    <template #aktion>
      <Marke :art="rang" :laeuft="open">{{ label }}</Marke>
      <button v-if="open" type="button" class="knopf gefahr" :disabled="cancelRequested" @click="cancel">
        {{ cancelRequested ? 'Abbruch angefordert …' : 'Abbrechen' }}
      </button>
    </template>

    <!--
      Der ehrliche Zwischenzustand. „Abgebrochen" steht erst da, wenn es
      zutrifft: Zwischen dem Wunsch und dem Ende des Programms auf dem Server
      liegen ein, zwei Sekunden, und in dieser Zeit läuft es noch.
    -->
    <p v-if="cancelRequested && open" class="meldung warn">
      Der Abbruch ist angefordert. Der Vorgang endet, sobald der Agent das
      laufende Programm beendet hat.
    </p>

    <p v-if="message" class="meldung" :class="rang === 'kritisch' ? 'kritisch' : 'ok'">{{ message }}</p>

    <div class="bereiche">
      <Bereich titel="Gegenstand">
        <table class="paare">
          <tbody>
            <tr><td class="stumm">Aufgabe</td><td class="rechts kennung name">{{ props.operation.type }}</td></tr>
            <tr>
              <td class="stumm">Zustand</td>
              <td class="rechts"><Marke :art="rang" :laeuft="open">{{ label }}</Marke></td>
            </tr>
            <tr><td class="stumm">Ausgelöst von</td><td class="rechts name">{{ props.operation.account ?? '—' }}</td></tr>
            <tr><td class="stumm">Begonnen</td><td class="rechts">{{ props.operation.started_at ?? '—' }}</td></tr>
            <tr><td class="stumm">Beendet</td><td class="rechts">{{ props.operation.finished_at ?? '—' }}</td></tr>
          </tbody>
        </table>

        <div class="fortschritt"><i :style="{ width: `${progress}%` }" /></div>
        <p class="erklaer">Fortschritt {{ progress }} %</p>
      </Bereich>

      <Bereich titel="Argumente" erklaerung="Was das Panel dem Agenten geschickt hat — typisiert und nicht als Kommandozeile.">
        <pre class="ausgabe daten">{{ JSON.stringify(props.operation.payload ?? {}, null, 2) }}</pre>
      </Bereich>

      <Bereich titel="Ausgabe" voll>
        <pre ref="box" class="ausgabe lang">{{ output || 'Noch keine Ausgabe.' }}</pre>
      </Bereich>

      <Bereich v-if="props.operation.result" titel="Ergebnis" voll>
        <pre class="ausgabe daten">{{ JSON.stringify(props.operation.result, null, 2) }}</pre>
      </Bereich>
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
.ausgabe {
  margin: 0;
}

.daten {
  color: var(--text-muted);
}

.lang {
  max-height: 420px;
}
</style>
