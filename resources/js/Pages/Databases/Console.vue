<script setup lang="ts">
/*
 * Das Datenbankmanagement — Tabellen und Struktur (`docs/46 §11`, Schritt 4).
 *
 * **Zwei lesende Ansichten auf einer Seite und nicht auf zweien.** Wer eine
 * Tabelle öffnet, will ihre Struktur sehen und danach die nächste Tabelle —
 * eine eigene Adresse je Tabelle machte aus jedem Blick einen Seitenwechsel.
 * Welche Tabelle offen ist, hält deshalb diese Seite und nicht die Adresse
 * (`DatabaseController::console()`).
 *
 * **Die Zeilenansicht kommt mit Schritt 5.** Sie ist der schwierigste Baustein
 * dieses Panels — eine Tabelle mit unbekannter Spaltenzahl — und braucht ihre
 * eigene Messung bei 390 px.
 */
import { Head, Link } from '@inertiajs/vue3'
import { onMounted, ref } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'
import { ask, ConsoleError } from '../../Composables/useConsole'
import { formatBytes } from '../../bytes'

interface TableRow {
  schema: string
  name: string

  /** `table` oder `view` — beide Systeme melden dieselben zwei Wörter. */
  kind: string

  /**
   * Die geschätzte Zeilenzahl, **oder `null` für „unbekannt"**.
   *
   * PostgreSQL meldet `-1` für eine nie analysierte Tabelle, MariaDB `NULL` für
   * eine Sicht; der Agent macht aus beidem `null`. Eine `0` an dieser Stelle
   * wäre schlimmer als keine Zahl, weil sie wie eine Antwort aussieht
   * (`docs/46 §9`).
   */
  rows: number | null
  bytes: number
  key: boolean
}

interface ColumnRow {
  name: string
  type: string
  nullable: boolean
  default: string | null
  key: boolean

  /** Eine binäre Spalte zeigt die Tabellenansicht als Länge (`docs/46 §8.2`). */
  binary: boolean
}

interface IndexRow {
  name: string
  columns: string
  unique: boolean
  primary: boolean
}

const props = defineProps<{
  database: {
    id: number
    name: string
    label: string
    engine: string
    engine_label: string
    subscription: string | null
  }
}>()

const tables = ref<TableRow[]>([])
const columns = ref<ColumnRow[]>([])
const indexes = ref<IndexRow[]>([])

/** Die geöffnete Tabelle, oder `null`, solange keine offen ist. */
const openTable = ref<string | null>(null)

const loadingTables = ref(true)
const loadingTable = ref(false)

/*
 * **Ein Fehlersatz, und er steht oben** (`docs/19 §6`). Nicht je Abschnitt einer
 * und nicht am Element, das ihn ausgelöst hat: Wer zwei Meldungen sieht, sucht
 * zwei Ursachen.
 */
const failure = ref<string | null>(null)

function report(error: unknown): void {
  failure.value =
    error instanceof ConsoleError
      ? error.message
      : 'Die Konsole ist nicht erreichbar. Bitte die Seite neu laden.'
}

async function loadTables(): Promise<void> {
  loadingTables.value = true
  failure.value = null

  try {
    const antwort = await ask<{ tables: TableRow[] }>(props.database.id, 'tables')
    tables.value = antwort.tables
  } catch (error) {
    report(error)
  } finally {
    loadingTables.value = false
  }
}

/**
 * Struktur und Indexe einer Tabelle.
 *
 * **Zwei Anfragen und nicht eine.** Die Spaltenliste holt der Agent bei jedem
 * Blättern, Filtern und Schreiben; die Indexe braucht nur diese Ansicht. Sie
 * laufen deshalb nebeneinander und nicht nacheinander — die zweite wartet sonst
 * auf einen befristeten Zugang, den die erste schon wieder abgeräumt hat.
 */
async function openStructure(table: string): Promise<void> {
  openTable.value = table
  loadingTable.value = true
  failure.value = null
  columns.value = []
  indexes.value = []

  try {
    const [struktur, register] = await Promise.all([
      ask<{ columns: ColumnRow[] }>(props.database.id, 'columns', { table }),
      ask<{ indexes: IndexRow[] }>(props.database.id, 'indexes', { table }),
    ])

    columns.value = struktur.columns
    indexes.value = register.indexes
  } catch (error) {
    report(error)
  } finally {
    loadingTable.value = false
  }
}

function closeStructure(): void {
  openTable.value = null
  columns.value = []
  indexes.value = []
  failure.value = null
}

/** „unbekannt" und nicht „0" — siehe {@link TableRow.rows}. */
function formatRows(value: number | null): string {
  return value === null ? 'unbekannt' : value.toLocaleString('de-DE')
}

onMounted(loadTables)
</script>

<template>
  <Head :title="`Konsole — ${props.database.label}`" />

  <PanelLayout title="Konsole" :subline="props.database.name">
    <template #breadcrumb>
      <Link href="/databases" class="link">Datenbanken</Link> ·
      <Link :href="`/databases/${props.database.id}`" class="link">
        {{ props.database.label }}
      </Link>
    </template>

    <p v-if="failure !== null" class="notice critical">
      <span>{{ failure }}</span>
    </p>

    <div class="sections">
      <Section title="Tabellen" full>
        <p v-if="loadingTables" class="empty">Wird geladen …</p>

        <p v-else-if="tables.length === 0" class="empty">
          In dieser Datenbank gibt es noch keine Tabelle.
        </p>

        <div v-else class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th>Tabelle</th>
                <th>Art</th>
                <th class="right">Zeilen</th>
                <th class="right">Grösse</th>
                <th>Schlüssel</th>
                <th>Aktion</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="table in tables" :key="`${table.schema}.${table.name}`">
                <td data-column="Tabelle" class="ident name">{{ table.name }}</td>
                <td data-column="Art">{{ table.kind === 'view' ? 'Sicht' : 'Tabelle' }}</td>
                <td data-column="Zeilen" class="right" :class="table.rows === null ? 'quiet' : ''">
                  {{ formatRows(table.rows) }}
                </td>
                <td data-column="Grösse" class="right">{{ formatBytes(table.bytes) }}</td>
                <td data-column="Schlüssel" :class="table.key ? '' : 'quiet'">
                  {{ table.key ? 'ja' : 'keiner' }}
                </td>
                <td data-column="Aktion">
                  <div class="button-row">
                    <button
                      type="button"
                      class="button"
                      :class="openTable === table.name ? 'active' : ''"
                      @click="openStructure(table.name)"
                    >
                      Struktur
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>

      <!--
        **Zwei Bereiche und nicht einer**, und der Name der Tabelle steht in
        keinem von beiden Titeln.

        Hier stand ein einziger Bereich `Struktur — {{ openTable }}` mit beiden
        Tabellen darin. Der Bildschirmfoto-Durchgang auf `cloudsrv24` hat daran
        zwei Dinge gefunden:

        1. Der Titel schob die Seite bei 390px um **99px** aus dem Bild — ein
           Tabellenname darf 63 Zeichen lang sein, und eine Kennung hat keine
           Leerzeichen, an denen sie bräche. Er steht jetzt als `.ident` unter
           der Überschrift, wo er brechen darf und ausserdem in der Schrift
           erscheint, die für Kennungen vorgesehen ist (`docs/19`).
        2. Spalten- und Indextabelle standen ohne Abstand und ohne Beschriftung
           untereinander und lasen sich als **eine** Tabelle: Auf die Zeile
           `anhang` folgte unmittelbar der Kopf `INDEX | SPALTEN | ART`.

        Der zweite Fund ist der, den keine Messung finden kann — nichts lief
        über, nichts war abgeschnitten. Es sah nur falsch aus.
      -->
      <template v-if="openTable !== null">
        <Section title="Spalten" full>
          <p class="section-note">
            Tabelle <span class="ident">{{ openTable }}</span>
          </p>

          <p v-if="loadingTable" class="empty">Wird geladen …</p>

          <div v-else class="scrolls">
            <table class="stacks">
              <thead>
                <tr>
                  <th>Spalte</th>
                  <th>Typ</th>
                  <th>Leer erlaubt</th>
                  <th>Vorgabe</th>
                  <th>Schlüssel</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="column in columns" :key="column.name">
                  <td data-column="Spalte" class="ident name">{{ column.name }}</td>
                  <td data-column="Typ" class="ident">
                    {{ column.type }}
                    <span v-if="column.binary" class="quiet">· binär</span>
                  </td>
                  <td data-column="Leer erlaubt">{{ column.nullable ? 'ja' : 'nein' }}</td>
                  <!--
                    **`NULL` und die leere Zeichenkette sind zwei Dinge**, und
                    das gilt auch für eine Vorgabe: `DEFAULT ''` gibt es
                    (`docs/46 §10.1`). „keine" steht deshalb gedämpft da und
                    nicht als leere Zelle, in der man nichts sieht.
                  -->
                  <td
                    data-column="Vorgabe"
                    class="ident"
                    :class="column.default === null ? 'quiet' : ''"
                  >
                    {{ column.default ?? 'keine' }}
                  </td>
                  <td data-column="Schlüssel" :class="column.key ? '' : 'quiet'">
                    {{ column.key ? 'Primärschlüssel' : '—' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </Section>

        <Section title="Indexe" full>
          <p v-if="loadingTable" class="empty">Wird geladen …</p>

          <p v-else-if="indexes.length === 0" class="empty">
            Diese Tabelle hat keinen Index. Eine Sortierung über eine ihrer Spalten liest sie
            vollständig und kann in das Zeitlimit von fünf Sekunden laufen.
          </p>

          <div v-else class="scrolls">
            <table class="stacks">
              <thead>
                <tr>
                  <th>Index</th>
                  <th>Spalten</th>
                  <th>Art</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="index in indexes" :key="index.name">
                  <td data-column="Index" class="ident name">{{ index.name }}</td>
                  <!--
                    Die Reihenfolge der Spalten ist keine Kosmetik: Ein Index
                    über `(kunde, datum)` hilft einer Sortierung nach `kunde`,
                    einer nach `datum` nicht.
                  -->
                  <td data-column="Spalten" class="ident">{{ index.columns }}</td>
                  <td data-column="Art">
                    {{ index.primary ? 'Primärschlüssel' : index.unique ? 'eindeutig' : 'einfach' }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="button-row">
            <button type="button" class="button" @click="closeStructure()">Schliessen</button>
          </div>
        </Section>
      </template>
    </div>
  </PanelLayout>
</template>
