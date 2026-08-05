<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import Bereich from '../../Components/Bereich.vue'
import Marke from '../../Components/Marke.vue'
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

function rang(status: string): 'ok' | 'warn' | 'kritisch' | 'neutral' {
  if (status === 'succeeded') return 'ok'
  if (status === 'running' || status === 'queued') return 'warn'
  if (status === 'failed' || status === 'cancelled') return 'kritisch'

  return 'neutral'
}

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
    <div class="bereiche">
      <Bereich
        v-if="props.tasks.length > 0"
        titel="Auslösen"
        voll
        erklaerung="Jede Aufgabe schickt eine typisierte Operation an den Agenten. Was etwas
                    ändert, fragt vorher zurück."
      >
        <!--
          Eine Liste und keine Tabelle.

          Sie sah aus wie eine Paartabelle — Name, Beschreibung, ein Knopf —
          und ist keine: Der Katalog zählt Dinge auf, die man tun kann, und
          nicht Werte, die man vergleicht. Die Frage vor der Wahl eines
          Tabellenmusters ist, ob die Daten überhaupt tabellarisch sind
          (docs/24 §5).
        -->
        <ul class="aufgaben">
          <li v-for="task in props.tasks" :key="task.key">
            <div class="text">
              <b>{{ task.label }}</b>
              <p class="beschreibung">{{ task.description }}</p>
            </div>

            <div class="knopfreihe">
              <!--
                `aria-label` statt einer sichtbaren Beschriftung: Die Aufgabe
                steht daneben, ein zweites „PHP-Version" davor wäre für
                Sehende Doppelung. Ohne Beschriftung wäre das Feld für eine
                Vorleseausgabe ein Auswahlfeld ohne Namen.
              -->
              <label v-if="task.choices.length > 0" class="feld wahl">
                <select
                  v-model="argumente[task.key]"
                  :aria-label="task.argument_label ?? undefined"
                  :disabled="starting !== null"
                >
                  <option v-for="wahl in task.choices" :key="wahl" :value="wahl">{{ wahl }}</option>
                </select>
              </label>

              <button
                type="button"
                class="knopf"
                :class="{ gefahr: task.mutating }"
                :disabled="starting !== null"
                @click="start(task)"
              >
                {{ starting === task.key ? 'wird angelegt …' : 'Ausführen' }}
              </button>
            </div>
          </li>
        </ul>
      </Bereich>

      <Bereich titel="Verlauf" voll>
        <div class="rollt">
          <table class="stapelt">
            <thead>
              <tr>
                <th>Nummer</th><th>Aufgabe</th><th>Zustand</th>
                <th>Ausgelöst von</th><th>Begonnen</th><th>Beendet</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in props.operations.data" :key="row.id">
                <td data-spalte="Nummer" class="kennung">
                  <Link :href="`/operations/${row.id}`" class="verweis">{{ row.id }}</Link>
                </td>
                <td data-spalte="Aufgabe" class="mehrzeilig">
                  <Link :href="`/operations/${row.id}`" class="verweis">{{ row.label }}</Link>
                  <span class="op">{{ row.type }}</span>
                </td>
                <td data-spalte="Zustand">
                  <Marke :art="rang(row.status)" :laeuft="row.open">
                    {{ row.status_label }}<template v-if="row.open"> · {{ row.progress }} %</template>
                  </Marke>
                </td>
                <td data-spalte="Ausgelöst von" class="stumm">{{ row.account ?? '—' }}</td>
                <td data-spalte="Begonnen" class="stumm">{{ row.started_at ?? '—' }}</td>
                <td data-spalte="Beendet" class="stumm">{{ row.finished_at ?? '—' }}</td>
              </tr>
              <tr v-if="props.operations.data.length === 0">
                <td colspan="6" class="stumm">Noch kein Vorgang.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Bereich>
    </div>
  </PanelLayout>
</template>

<style scoped>
.aufgaben {
  margin: 0;
  padding: 0;
  list-style: none;
}

/*
 * Aufgabe links, Aktion rechts — und untereinander, sobald es eng wird.
 *
 * Vorher stand dafür ein eigener Haltepunkt in dieser Datei: „unter 720px
 * untereinander, weil für die Beschreibung sonst eine Spalte von wenigen
 * Zeichen bliebe". Mit einer Mindestbreite je Teil erledigt der Fluss das
 * selbst, und der Haltepunkt entfällt — die Beschreibung ist hier der Teil,
 * der sagt, welcher Befehl auf dem Server ankommt.
 */
.aufgaben li {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px 20px;
  padding: 14px 0;
  border-bottom: 1px solid var(--line);
}

.aufgaben li:last-child {
  border-bottom: 0;
}

.text {
  flex: 1 1 320px;
  min-width: 0;
}

.text b {
  font-weight: 620;
  color: var(--text-strong);
}

.beschreibung {
  margin: 3px 0 0;
  font-size: var(--text-small);
  color: var(--text-muted);
  max-width: 68ch;
}

/*
 * Das Auswahlfeld neben dem Knopf trägt keine Beschriftung über sich — es
 * steht in einer Reihe mit ihm und nicht in einem Formular. `.feld` gibt ihm
 * trotzdem Form und Rand aus app.css; nur der Aussenabstand fällt weg, den
 * ein Feld in einer Maske hätte.
 */
.wahl {
  margin-top: 0;
  min-width: 0;
}

.op {
  display: block;
  font-family: var(--font-mono);
  font-size: var(--text-label);
  color: var(--text-muted);
}
</style>
