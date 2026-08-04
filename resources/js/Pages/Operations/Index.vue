<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

interface Row {
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
}

interface TaskEntry {
  key: string
  label: string
  description: string
  mutating: boolean
  argument_label: string | null
  choices: string[]
}

const props = defineProps<{
  operations: { data: Row[]; total: number }
  tasks: TaskEntry[]
}>()

// Verhindert den Doppelklick, der zwei gleiche Vorgänge anlegt. Kein Ersatz
// für eine Prüfung auf dem Server — dort ist ein zweiter Reload harmlos, hier
// wäre er nur verwirrend.
const starting = ref<string | null>(null)

// Die gewählten Argumente, je Aufgabe. Vorbelegt mit der ersten Möglichkeit —
// ein leeres Auswahlfeld neben einem Knopf wäre eine Aufgabe, die beim ersten
// Druck mit einem Fehler antwortet.
const argumente = ref<Record<string, string>>(
  Object.fromEntries(
    props.tasks.filter((task) => task.choices.length > 0).map((task) => [task.key, task.choices[0]]),
  ),
)

function start(task: TaskEntry): void {
  if (starting.value) return

  const argument = task.choices.length > 0 ? argumente.value[task.key] : null

  // Rückfrage nur bei Aufgaben, die etwas ändern. Bei einer Zustandsabfrage
  // wäre sie Gewöhnung an das Wegklicken — und damit weniger wert an der
  // Stelle, an der sie zählt. Das gewählte Argument steht mit darin: „PHP
  // entfernen?" und „PHP 8.1 entfernen?" sind zwei verschiedene Fragen.
  const frage = argument === null ? task.label : `${task.label}: ${argument}`

  if (task.mutating && !window.confirm(`${frage}?\n\n${task.description}`)) return

  starting.value = task.key
  router.post('/operations', { task: task.key, argument }, {
    onFinish: () => {
      starting.value = null
    },
  })
}
</script>

<template>
  <Head title="Vorgänge" />

  <PanelLayout title="Vorgänge" :subline="`${props.operations.total} insgesamt`">
    <section v-if="props.tasks.length > 0" class="katalog">
      <h2>Auslösen</h2>
      <ul>
        <li v-for="task in props.tasks" :key="task.key">
          <div class="text">
            <b>{{ task.label }}</b>
            <span>{{ task.description }}</span>
          </div>
          <div class="aktion">
            <!--
              `aria-label` statt einer sichtbaren Beschriftung: Die Aufgabe
              steht als Überschrift daneben, ein zweites „PHP-Version" davor
              wäre für Sehende Doppelung. Ohne Beschriftung wäre das Feld für
              eine Vorleseausgabe ein Auswahlfeld ohne Namen.
            -->
            <select
              v-if="task.choices.length > 0"
              v-model="argumente[task.key]"
              :aria-label="task.argument_label ?? undefined"
              :disabled="starting !== null"
            >
              <option v-for="wahl in task.choices" :key="wahl" :value="wahl">{{ wahl }}</option>
            </select>
            <button
              type="button"
              :class="['knopf', { gefahr: task.mutating }]"
              :disabled="starting !== null"
              @click="start(task)"
            >
              {{ starting === task.key ? 'wird angelegt …' : 'Ausführen' }}
            </button>
          </div>
        </li>
      </ul>
    </section>

    <table class="stapelt">
      <thead>
        <tr>
          <th>#</th><th>Aufgabe</th><th>Zustand</th><th>Ausgelöst von</th><th>Begonnen</th><th>Beendet</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in props.operations.data" :key="row.id">
          <td data-spalte="Nummer"><Link :href="`/operations/${row.id}`">{{ row.id }}</Link></td>
          <td data-spalte="Aufgabe">
            <Link :href="`/operations/${row.id}`">{{ row.label }}</Link>
            <span class="op">{{ row.type }}</span>
          </td>
          <td data-spalte="Zustand" :data-status="row.status">
            {{ row.status_label }}<template v-if="row.open"> · {{ row.progress }} %</template>
          </td>
          <td data-spalte="Ausgelöst von">{{ row.account ?? '—' }}</td>
          <td data-spalte="Begonnen">{{ row.started_at ?? '—' }}</td>
          <td data-spalte="Beendet">{{ row.finished_at ?? '—' }}</td>
        </tr>
        <tr v-if="props.operations.data.length === 0">
          <td colspan="6">Noch kein Vorgang.</td>
        </tr>
      </tbody>
    </table>
  </PanelLayout>
</template>

<style scoped>
/* Dieselben Marken wie auf der Übersicht — Abstand und Überschrift stehen in
   app.css, damit die Gliederung über die Module hinweg dieselbe bleibt. */
.katalog { margin-bottom: var(--block-gap); }
.katalog h2 { margin: 0 0 var(--block-heading-gap); font-size: var(--block-heading-size); font-weight: 600; letter-spacing: -.01em; color: var(--text-strong); }
.katalog ul { margin: 0; padding: 0; list-style: none; border: 1px solid var(--line); border-radius: 6px; }
.katalog li { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 8px 11px; border-bottom: 1px solid var(--line); }
.katalog li:last-child { border-bottom: 0; }
.text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.text b { font-size: var(--text-table); font-weight: 600; color: var(--text-strong); }
.text span { font-size: var(--text-small); color: var(--text-muted); }
.aktion { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.aktion select {
  padding: 5px 6px; font: inherit; font-size: var(--text-input); color: var(--text);
  background: var(--bg); border: 1px solid var(--line); border-radius: 5px;
}

/*
 * Auf der schmalen Fläche steht die Aktion unter dem Text. Nebeneinander
 * bliebe für die Beschreibung eine Spalte von wenigen Zeichen — und die
 * Beschreibung ist hier der Teil, der sagt, welcher Befehl auf dem Server
 * ankommt.
 */
@media (max-width: 720px) {
  .katalog li { flex-direction: column; align-items: stretch; gap: 10px; }
  .aktion { justify-content: flex-end; }
  .aktion select { min-height: var(--tap); }
}
table { width: 100%; border-collapse: collapse; font-size: var(--text-table); }
th { text-align: left; color: var(--text-muted); font-weight: 600; }
th, td { padding: 6px 8px; border-bottom: 1px solid var(--line); }
.op { display: block; font-family: var(--font-mono); font-size: var(--text-label); color: var(--text-faint); }
td[data-status='failed'] { color: var(--warn); }
td[data-status='running'], td[data-status='queued'] { color: var(--accent); }
</style>
