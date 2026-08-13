<script setup lang="ts">
/*
 * Das Datenbankmanagement (`docs/46 §11`) — Tabellen, Struktur, Zeilen.
 *
 * **Drei lesende Ansichten auf einer Seite und nicht auf dreien.** Wer eine
 * Tabelle öffnet, will ihre Struktur sehen, dann ihre Zeilen, dann die nächste
 * Tabelle — eine eigene Adresse je Ansicht machte aus jedem Blick einen
 * Seitenwechsel. Was offen ist, hält deshalb diese Seite und nicht die Adresse
 * (`DatabaseController::console()`).
 *
 * **Die Zeilenansicht ist der schwierigste Baustein dieses Panels**, und zwar
 * aus einem Grund, den die beiden anderen nicht haben: Die Spaltenzahl ist
 * unbekannt. Sie rollt deshalb waagerecht (`.rows` in `app.css`) — die einzige
 * Stelle hier, an der das richtig ist — und braucht ihre Messung an **zwei**
 * Orten: am Dokument, wo sie 0 sein muss, und am Rollbehälter, wo sie es nicht
 * sein darf.
 */
import { Head, Link } from '@inertiajs/vue3'
import { computed, onMounted, ref } from 'vue'
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

/**
 * Eine Seite Zeilen.
 *
 * **Kein `total`, und das ist Entscheidung 5, Punkt 2.** Was zurückkommt, ist
 * `more` — die einundfünfzigste Zeile, die gezählt und nicht ausgeliefert
 * wurde. Ein `count(*)` über eine gefilterte Spalte ohne Index ist genau die
 * Abfrage, die ins Zeitlimit läuft, und sie liefe bei **jedem** Seitenaufruf.
 */
interface Page {
  columns: string[]

  /**
   * Die Zeilen.
   *
   * **Eine Zahl steht nur in einer binären Spalte**, und dort ist sie die Länge
   * und nicht der Wert (`docs/46 §8.2`). Alle anderen Spalten kommen als Text
   * zurück, weil der Agent sie für die Kürzung ohnehin nach `text` wandelt —
   * eine `bigint`-Spalte also auch. Das ist kein Zufall, auf den man sich
   * verlässt, sondern die Form von `Console::selectList()`.
   */
  rows: Record<string, string | number | null>[]

  /** Die Spalten, in denen mindestens ein Wert bei 512 Zeichen gekürzt wurde. */
  truncated: string[]
  more: boolean
}

interface Filter {
  column: string
  operator: string
  value: string
}

/**
 * Die drei Filteroperatoren (Entscheidung 5, Punkt 1).
 *
 * **Die Werte gehören dem Agenten** (`Pg\Console::OPERATORS`) — hier stehen sie
 * mit ihrer deutschen Beschriftung, und mehr darf hier nicht stehen. Ein
 * vierter Eintrag ohne Gegenstück dort wäre kein Bonus, sondern ein Befund:
 * Jeder Operator ist ein weiterer Weg, auf dem die Maskierung falsch sein kann,
 * an der einzigen Stelle dieses Projekts, an der Kundentext in eine Anweisung
 * geht (`docs/46 §7`).
 *
 * `ist leer` deckt `NULL` und die leere Zeichenkette zusammen ab — wer nach der
 * einen sucht, sucht in aller Regel nach beiden. Sie auseinanderzuhalten ist
 * Aufgabe der Anzeige und des Schreibwegs, nicht des Filters.
 */
const OPERATORS = [
  { value: 'equals', label: 'ist gleich' },
  { value: 'contains', label: 'enthält' },
  { value: 'empty', label: 'ist leer' },
]

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

/**
 * Welches Ziel dazu offen ist.
 *
 * **Drei und nicht zwei**, seit der Baum da ist. Spalten und Indexe waren eine
 * Ansicht mit zwei Bereichen; im Baum sind sie zwei Blätter, und zwei Blätter,
 * die dasselbe öffnen, sind eine Lüge über die Navigation. Nebenbei kostet
 * jedes Ziel damit genau eine Katalogfrage statt zweier — und jede ist ein
 * befristeter Zugang (§11.1).
 */
const openView = ref<'columns' | 'indexes' | 'rows' | null>(null)

/**
 * Die aufgeklappten Zweige.
 *
 * **Ein Satz von Namen und kein Feld von Zuständen an den Tabellen.** Wer die
 * Tabellenliste neu lädt, bekommt neue Objekte; ein Zustand, der an ihnen
 * hängt, wäre danach fort. Der Name überlebt das.
 */
const expanded = ref<Set<string>>(new Set())

/** Der Baum selbst — für die Tastaturbedienung, die in der Vorlage steht. */
const tree = ref<HTMLElement | null>(null)

/**
 * Der Punkt im Baum, auf dem der Tabulator landet.
 *
 * **Ein Baum ist **eine** Tabulatorstation und nicht zwanzig.** Wer sich mit
 * `Tab` durch die Seite bewegt, soll den Baum in einem Schritt betreten und in
 * einem verlassen — sonst tabbt er sich bei zwanzig Tabellen durch zwanzig
 * Knöpfe, bevor er den Inhalt erreicht. Innen bewegen die Pfeile.
 *
 * **Und die Station wandert mit.** Der erste Wurf hielt sie fest am ersten
 * Zweig: Wer den Baum verliess und zurückkam, stand wieder oben statt dort, wo
 * er war. Das gehört zum Muster und ist keine Feinheit — es ist der Unterschied
 * zwischen „ich war hier" und „fang von vorn an".
 */
const tabStop = ref('')

/** Die Inhaltsspalte — auf schmaler Fläche steht sie unterhalb des Baums. */
const content = ref<HTMLElement | null>(null)

const loadingTables = ref(true)
const loadingTable = ref(false)

/* ------------------------------------------------------------ Zeilenansicht */

const page = ref<Page | null>(null)
const order = ref('')
const descending = ref(false)
const offset = ref(0)

/** Der angewandte Filter — `null`, solange keiner gesetzt ist. */
const filter = ref<Filter | null>(null)

/** Der Entwurf in der Filterzeile, der erst mit „Filtern" gilt. */
const draft = ref<Filter>({ column: '', operator: 'equals', value: '' })

/**
 * Die Versätze, von denen aus geblättert wurde.
 *
 * **Ein Stapel und keine Rechnung.** „Zurück" könnte auch `offset` um die Zahl
 * der gezeigten Zeilen verringern — auf der letzten Seite sind das aber
 * weniger als eine volle Seite, und man landete mitten in der vorigen. Der
 * Stapel weiss es genau und muss die Seitengrösse nicht kennen.
 */
const trail = ref<number[]>([])

/* ------------------------------------------------------------ Zelleinzelsicht */

const cell = ref<{ column: string; value: string | null; truncated: boolean; bytes: number } | null>(null)
const loadingCell = ref(false)

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

/** Die Spalten des Primärschlüssels — ohne sie lässt sich keine Zelle öffnen. */
const keyColumns = computed((): ColumnRow[] => columns.value.filter((c) => c.key))

/**
 * Was in der Blätterleiste steht.
 *
 * **„mehr als 50" und keine Trefferzahl.** Die Zahl links ist die erste und die
 * letzte Zeile dieser Seite; rechts steht entweder die genaue Gesamtzahl — dann
 * ist diese Seite die letzte — oder eben nicht.
 */
const pageState = computed((): string => {
  if (page.value === null || page.value.rows.length === 0) {
    return 'keine Zeile'
  }

  const von = offset.value + 1
  const bis = offset.value + page.value.rows.length

  return page.value.more ? `Zeilen ${von}–${bis} von mehr als ${bis}` : `Zeilen ${von}–${bis} von ${bis}`
})

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

function reset(table: string): void {
  openTable.value = table
  failure.value = null
  columns.value = []
  indexes.value = []
  page.value = null
  cell.value = null
  order.value = ''
  descending.value = false
  offset.value = 0
  trail.value = []
  filter.value = null
  draft.value = { column: '', operator: 'equals', value: '' }
}

/**
 * Die Spalten einer Tabelle.
 *
 * **Ohne die Indexe**, seit es den Baum gibt. Vorher holten beide zusammen, weil
 * beide auf einer Seite standen; jetzt sind es zwei Ziele, und wer nach den
 * Spalten fragt, soll nicht die Indexe bezahlen. Jede Katalogfrage ist ein
 * befristeter Zugang (§11.1).
 */
async function openColumns(table: string): Promise<void> {
  reset(table)
  openView.value = 'columns'
  loadingTable.value = true

  try {
    const struktur = await ask<{ columns: ColumnRow[] }>(props.database.id, 'columns', { table })
    columns.value = struktur.columns
  } catch (error) {
    report(error)
  } finally {
    loadingTable.value = false
    reveal()
  }
}

/** Die Indexe einer Tabelle. */
async function openIndexes(table: string): Promise<void> {
  reset(table)
  openView.value = 'indexes'
  loadingTable.value = true

  try {
    const register = await ask<{ indexes: IndexRow[] }>(props.database.id, 'indexes', { table })
    indexes.value = register.indexes
  } catch (error) {
    report(error)
  } finally {
    loadingTable.value = false
    reveal()
  }
}

/**
 * Die Zeilen einer Tabelle.
 *
 * **Die Spaltenliste kommt mit**, obwohl der Agent sie für die Abfrage selbst
 * holt: Die Anzeige braucht sie für zweierlei, das die Zeilen nicht mitbringen
 * — welche Spalte binär ist (dort steht eine Länge und kein Wert) und welche
 * zum Primärschlüssel gehört (ohne ihn lässt sich keine Zelle öffnen).
 */
async function openRows(table: string): Promise<void> {
  reset(table)
  openView.value = 'rows'
  loadingTable.value = true

  try {
    const struktur = await ask<{ columns: ColumnRow[] }>(props.database.id, 'columns', { table })
    columns.value = struktur.columns

    // Sortiert wird über den Schlüssel, wenn es einen gibt — das ist die
    // einzige Spalte, für die ein Index sicher vorhanden ist, und damit die
    // einzige, bei der die erste Seite nicht ins Zeitlimit laufen kann.
    order.value = (keyColumns.value[0] ?? columns.value[0])?.name ?? ''

    if (order.value !== '') {
      await loadPage()
    }
  } catch (error) {
    report(error)
  } finally {
    loadingTable.value = false
    reveal()
  }
}

/**
 * Den Inhalt ins Bild holen — aber nur, wenn er nicht daneben steht.
 *
 * **Ohne das tut ein Klick auf dem Telefon scheinbar nichts.** Unter 720px liegt
 * der Inhalt *unter* dem Baum, und zwanzig Zweige sind rund 930px hoch: Wer
 * „Zeilen" wählt, sieht die Zeilen erst nach zwei Bildschirmen Rollen. Ab 720px
 * steht der Inhalt daneben und ist längst zu sehen; dort wäre ein Sprung eine
 * Bewegung ohne Anlass.
 *
 * Die Breite kommt aus `matchMedia` und nicht aus einer Zahl in einer
 * Bedingung — es ist derselbe Haltepunkt wie in `app.css`, und zwei Fassungen
 * davon wären eine zu viel. Dass es genau dieser ist, hält `MobileLayoutTest`
 * fest.
 */
function reveal(): void {
  if (window.matchMedia('(min-width: 720px)').matches) {
    return
  }

  content.value?.scrollIntoView({ block: 'start', behavior: 'smooth' })
}

async function loadPage(): Promise<void> {
  if (openTable.value === null || order.value === '') {
    return
  }

  loadingTable.value = true
  failure.value = null

  try {
    page.value = await ask<Page>(props.database.id, 'rows', {
      table: openTable.value,
      order: order.value,
      direction: descending.value ? 'desc' : 'asc',
      offset: offset.value,
      ...(filter.value === null ? {} : { filter: filter.value }),
    })
  } catch (error) {
    report(error)
  } finally {
    loadingTable.value = false
  }
}

/** Nach einer Spalte sortieren; ein zweiter Klick dreht die Richtung. */
async function sortBy(column: string): Promise<void> {
  if (order.value === column) {
    descending.value = !descending.value
  } else {
    order.value = column
    descending.value = false
  }

  offset.value = 0
  trail.value = []
  cell.value = null
  await loadPage()
}

async function applyFilter(): Promise<void> {
  const entwurf = draft.value

  filter.value =
    entwurf.column === '' ? null : { ...entwurf, value: entwurf.operator === 'empty' ? '' : entwurf.value }

  offset.value = 0
  trail.value = []
  cell.value = null
  await loadPage()
}

async function clearFilter(): Promise<void> {
  draft.value = { column: '', operator: 'equals', value: '' }
  await applyFilter()
}

async function forward(): Promise<void> {
  if (page.value === null || !page.value.more) {
    return
  }

  trail.value.push(offset.value)
  offset.value += page.value.rows.length
  cell.value = null
  await loadPage()
}

async function back(): Promise<void> {
  offset.value = trail.value.pop() ?? 0
  cell.value = null
  await loadPage()
}

/**
 * Der ganze Wert einer Zelle — der Ausweg aus der Kürzung.
 *
 * Ohne ihn wäre eine bei 512 Zeichen abgeschnittene Zelle eine Sackgasse: Sie
 * ist nach `docs/46 §10.1` zum Schreiben gesperrt, und der Rest wäre auf keinem
 * Weg mehr zu sehen.
 */
async function openCell(column: string, row: Record<string, string | number | null>): Promise<void> {
  if (openTable.value === null) {
    return
  }

  const key: Record<string, string> = {}

  for (const spalte of keyColumns.value) {
    key[spalte.name] = String(row[spalte.name] ?? '')
  }

  loadingCell.value = true
  cell.value = null
  failure.value = null

  try {
    const antwort = await ask<{ value: string | null; truncated: boolean; bytes: number }>(
      props.database.id,
      'cell',
      { table: openTable.value, key, column },
    )

    cell.value = { column, ...antwort }
  } catch (error) {
    report(error)
  } finally {
    loadingCell.value = false
  }
}

/**
 * Einen Zweig auf- oder zuklappen.
 *
 * **Es wird dabei nichts geholt**, und das ist der Kern von §11.1: Die drei
 * Ziele darunter sind Beschriftungen und keine Daten. Geladen wird erst, wenn
 * jemand eines wählt — für **eine** Tabelle. Ein „alles aufklappen" gibt es
 * deshalb nicht; es wäre ein Knopf, der zwanzig Datenbankrollen anlegt.
 */
function toggle(table: string): void {
  const offen = new Set(expanded.value)

  if (offen.has(table)) {
    offen.delete(table)
  } else {
    offen.add(table)
  }

  expanded.value = offen
}

/**
 * Die Tastaturbedienung eines Baums.
 *
 * **Sie liest den Baum aus dem Dokument und nicht aus dem Zustand.** Welche
 * Knoten sichtbar sind, hängt daran, welche Zweige offen sind — und das steht
 * bereits im DOM. Eine zweite Fassung davon in einer Liste wäre die, die
 * veraltet, sobald jemand die Vorlage umstellt.
 *
 * Rechts klappt auf oder geht hinein, links klappt zu oder geht heraus; das ist
 * das Muster, das ein Screenreader hier erwartet (`aria-expanded` sagt an, was
 * die Pfeile tun).
 */
/**
 * Trägt dieser Punkt die Tabulatorstation?
 *
 * Solange niemand im Baum war, ist es der erste Zweig — irgendwo muss man
 * hineinkommen. Danach ist es der zuletzt besuchte Punkt.
 */
function stops(key: string): boolean {
  return tabStop.value === '' ? key === tables.value[0]?.name : tabStop.value === key;
}

/** Wer den Fokus bekommt, wird zur Station. */
function remember(event: FocusEvent): void {
  const punkt = (event.target as HTMLElement | null)?.closest<HTMLElement>('[role="treeitem"]');

  if (punkt !== null && punkt !== undefined) {
    tabStop.value = punkt.dataset.stop ?? punkt.dataset.table ?? '';
  }
}

function navigate(event: KeyboardEvent): void {
  const wurzel = tree.value

  if (wurzel === null) {
    return
  }

  const punkte = [...wurzel.querySelectorAll<HTMLElement>('[role="treeitem"]')]
    .filter((el) => el.offsetParent !== null)

  const hier = punkte.indexOf(document.activeElement as HTMLElement)

  if (hier === -1) {
    return
  }

  const springe = (ziel: number): void => {
    event.preventDefault()
    punkte[Math.max(0, Math.min(punkte.length - 1, ziel))]?.focus()
  }

  const auf = document.activeElement as HTMLElement
  const zweig = auf.getAttribute('aria-expanded')
  const name = auf.dataset.table ?? ''

  switch (event.key) {
    case 'ArrowDown':
      return springe(hier + 1)

    case 'ArrowUp':
      return springe(hier - 1)

    case 'Home':
      return springe(0)

    case 'End':
      return springe(punkte.length - 1)

    case 'ArrowRight':
      if (zweig === 'false') {
        event.preventDefault()
        return toggle(name)
      }

      if (zweig === 'true') {
        return springe(hier + 1)
      }

      return

    case 'ArrowLeft':
      if (zweig === 'true') {
        event.preventDefault()
        return toggle(name)
      }

      // Auf einem Blatt geht es zum Zweig darüber — das ist der nächste Knoten
      // nach oben, der `aria-expanded` trägt.
      if (zweig === null) {
        for (let i = hier - 1; i >= 0; i--) {
          if (punkte[i].hasAttribute('aria-expanded')) {
            return springe(i)
          }
        }
      }
  }
}

function closeTable(): void {
  openTable.value = null
  openView.value = null
  columns.value = []
  indexes.value = []
  page.value = null
  cell.value = null
  failure.value = null
}

/** „unbekannt" und nicht „0" — siehe {@link TableRow.rows}. */
function formatRows(value: number | null): string {
  return value === null ? 'unbekannt' : value.toLocaleString('de-DE')
}

/**
 * Die vier Angaben zur offenen Tabelle — Art, Zeilen, Grösse, Schlüssel.
 *
 * **Sie standen bis Schritt 5b im Verzeichnis und stehen jetzt im Inhalt.** Der
 * Baum trägt nur den Namen (§11.1): Der erste Entwurf hatte sie rechts daneben,
 * und in einer 280px schmalen Spalte quetschte das den Tabellennamen auf ein
 * Zeichen je Zeile. Weglassen war keine Möglichkeit — „hat diese Tabelle einen
 * Schlüssel" entscheidet, ob es die Zelleinzelsicht gibt, und ab Schritt 6, ob
 * man sie ändern kann.
 *
 * > **Was aus der Navigation fällt, muss im Inhalt landen — sonst ist es
 * > weggefallen.**
 */
const openFacts = computed((): string => {
  const tabelle = tables.value.find((t) => t.name === openTable.value)

  if (tabelle === undefined) {
    return ''
  }

  const angaben = [
    // **„unbekannt Zeilen" war kein Deutsch.** `formatRows(null)` gibt das Wort
    // „unbekannt" zurück, und ich hatte blind „ Zeilen" angehängt. Die Zahl
    // trägt ihre Einheit, das Wort trägt seinen Satz.
    tabelle.rows === null ? 'Zeilenzahl unbekannt' : `${formatRows(tabelle.rows)} Zeilen`,
  ]

  /*
   * **Eine Sicht bekommt keine Grösse**, und das ist der dritte Fall derselben
   * Falle in dieser Stufe. Eine Sicht speichert nichts; der Katalog meldet
   * dafür 0, und „0 B" liest sich wie „leer" statt wie „gibt es nicht".
   *
   * > **Eine 0, die für „nichts da" steht, sieht aus wie eine Antwort.**
   *
   * Vorher: die geschätzte Zeilenzahl (`docs/46 §9`) und die Länge einer
   * binären Spalte mit `NULL` (§20.16). Dreimal dieselbe Ursache — eine Zahl,
   * die es gibt, für eine Angabe, die es nicht gibt.
   */
  if (tabelle.kind !== 'view') {
    angaben.push(formatBytes(tabelle.bytes))
  }

  angaben.push(tabelle.key ? 'mit Schlüssel' : 'ohne Schlüssel')

  return angaben.join(' · ')
})

/**
 * „Tabelle" oder „Sicht" — das Wort vor dem Namen.
 *
 * **Vorher stand hier immer „Tabelle", und die Angaben dahinter sagten „Sicht".**
 * Zwei Wörter Abstand, und sie widersprachen sich. Gefunden auf einem Bild des
 * Durchgangs zu Schritt 5b.
 *
 * > **Eine Beschriftung, die einen Wert wiederholt, der daneben steht, ist
 * > nicht doppelt — sie ist die zweite Fassung.**
 */
const openKind = computed((): string =>
  tables.value.find((t) => t.name === openTable.value)?.kind === 'view' ? 'Sicht' : 'Tabelle',
)

function isBinary(column: string): boolean {
  return columns.value.some((c) => c.name === column && c.binary)
}

/**
 * Die Länge, auf die in einer Spalte gekürzt wurde — je Spalte, in der es
 * überhaupt geschah.
 *
 * **Nicht die 512 aus dem Agenten.** Sie hier hinzuschreiben wäre eine zweite
 * Fassung derselben Regel, und die zweite ist die, die veraltet. Gekürzt wird
 * auf eine feste Länge; in einer Spalte, in der gekürzt wurde, ist das genau die
 * längste, die auf dieser Seite vorkommt.
 */
const cuts = computed((): Record<string, number> => {
  const laengen: Record<string, number> = {}

  for (const column of page.value?.truncated ?? []) {
    laengen[column] = Math.max(
      ...(page.value?.rows ?? []).map((row) =>
        typeof row[column] === 'string' ? row[column].length : 0,
      ),
    )
  }

  return laengen
})

/**
 * Wurde **diese** Zelle gekürzt? Dann führt ein Weg zum ganzen Wert.
 *
 * **Je Zelle und nicht je Spalte.** `truncated` nennt die Spalte, in der es
 * geschah — in einer Textspalte mit einem langen Wert bekämen sonst alle
 * fünfzig Zeilen den Knopf, auch die mit drei Zeichen. Er stünde dann neben
 * Werten, die vollständig dastehen, und zwar in der engsten Ansicht dieses
 * Panels.
 */
function isTruncated(column: string, value: string): boolean {
  return column in cuts.value && value.length >= cuts.value[column]
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

    <!--
      Der Grundriss dieser Seite ist der einzige des Panels mit zwei Spalten.

      Unter 720px liegt der Inhalt unter dem Baum, darüber daneben — **eine
      Form für beide Breiten** (§11.1, Entscheidung 1). Baum unten und Tabelle
      oben wären zwei Fassungen derselben Ansicht, und die zweite ist die, die
      veraltet.
    -->
    <div class="split">
      <div class="sections aside">
        <Section title="Tabellen" full>
          <p v-if="loadingTables" class="empty">Wird geladen …</p>

          <p v-else-if="tables.length === 0" class="empty">
            In dieser Datenbank gibt es noch keine Tabelle.
          </p>

          <!--
            **Ein Baum und keine Liste von Knöpfen.** `role="tree"`,
            `role="treeitem"` und `aria-expanded` sind das, woran ein
            Screenreader den Zusammenhang erkennt — wer den Baum *sieht*,
            bemerkt ihr Fehlen nie. `TreeSemanticsTest` besteht darauf.

            Die `<li>` tragen `role="none"`: Zwischen einem `tree` und seinen
            `treeitem` darf nichts mit eigener Rolle stehen, und ein `<li>`
            bringt `listitem` mit.
          -->
          <ul v-else ref="tree" class="tree" role="tree" @keydown="navigate" @focusin="remember">
            <li v-for="table in tables" :key="`${table.schema}.${table.name}`" role="none">
              <button
                type="button"
                class="node"
                role="treeitem"
                :data-table="table.name"
                :aria-expanded="expanded.has(table.name)"
                :tabindex="stops(table.name) ? 0 : -1"
                @click="toggle(table.name)"
              >
                <!-- Das Zeichen sagt dasselbe wie `aria-expanded`; zweimal
                     vorgelesen wäre es eine Angabe zu viel. -->
                <span class="arrow" aria-hidden="true">
                  {{ expanded.has(table.name) ? '▾' : '▸' }}
                </span>
                <span class="label">{{ table.name }}</span>
              </button>

              <!--
                **Drei Ziele und keine Daten** (§11.1). Spalten als Blätter
                müssten Typ, Leer-erlaubt, Vorgabe und Schlüssel weglassen —
                vier von fünf Angaben — und wären eine zweite, schlechtere
                Strukturansicht. Eine Seite Zeilen passt in keinen Knoten; der
                Baum ruft sie auf.
              -->
              <ul v-if="expanded.has(table.name)" role="group">
                <li role="none">
                  <button
                    type="button"
                    class="leaf"
                    role="treeitem"
                    :tabindex="stops(`${table.name}/columns`) ? 0 : -1"
                    :data-stop="`${table.name}/columns`"
                    :class="openTable === table.name && openView === 'columns' ? 'active' : ''"
                    @click="openColumns(table.name)"
                  >
                    Spalten
                  </button>
                </li>
                <li role="none">
                  <button
                    type="button"
                    class="leaf"
                    role="treeitem"
                    :tabindex="stops(`${table.name}/indexes`) ? 0 : -1"
                    :data-stop="`${table.name}/indexes`"
                    :class="openTable === table.name && openView === 'indexes' ? 'active' : ''"
                    @click="openIndexes(table.name)"
                  >
                    Indexe
                  </button>
                </li>
                <li role="none">
                  <button
                    type="button"
                    class="leaf"
                    role="treeitem"
                    :tabindex="stops(`${table.name}/rows`) ? 0 : -1"
                    :data-stop="`${table.name}/rows`"
                    :class="openTable === table.name && openView === 'rows' ? 'active' : ''"
                    @click="openRows(table.name)"
                  >
                    Zeilen
                  </button>
                </li>
              </ul>
            </li>
          </ul>
        </Section>
      </div>

      <div ref="content" class="sections">
        <!--
          **Kein „links".** Der Baum steht nur ab 720px daneben; darunter steht
          er *oben*, und der Satz schickte auf dem Telefon in die falsche
          Richtung. Gefunden auf dem ersten Bild des Durchgangs zu Schritt 5b —
          von keinem Wächter, denn der Satz war grammatisch, deutsch und
          freundlich.
        -->
        <p v-if="openTable === null" class="empty">
          Wählen Sie eine Tabelle und dann, was Sie von ihr sehen möchten.
        </p>

      <!--
        Der Name der Tabelle steht in keinem Bereichstitel — er darf 63 Zeichen
        lang sein und schob die Seite bei 390px um 99px aus dem Bild
        (`docs/46 §20.11`). Er steht als Kennung in der Beizeile, und dort
        stehen seit Schritt 5b auch die vier Angaben, die der Baum nicht trägt.
      -->
      <template v-if="openTable !== null && openView === 'columns'">
        <Section title="Spalten" full>
          <p class="section-note">
            {{ openKind }} <span class="ident">{{ openTable }}</span> · {{ openFacts }}
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

          <div class="button-row">
            <button type="button" class="button" @click="closeTable()">Schliessen</button>
          </div>
        </Section>
      </template>

      <template v-if="openTable !== null && openView === 'indexes'">
        <Section title="Indexe" full>
          <p class="section-note">
            {{ openKind }} <span class="ident">{{ openTable }}</span> · {{ openFacts }}
          </p>

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
            <button type="button" class="button" @click="closeTable()">Schliessen</button>
          </div>
        </Section>
      </template>

      <!--
        Die Zeilenansicht.

        **Sie rollt waagerecht und stapelt nicht** — als einzige Tabelle dieses
        Panels. Die Begründung steht bei `.rows` in `app.css`: Bei unbekannter
        Spaltenzahl ist der Vergleich zwischen den Zeilen der Zweck, und
        Kärtchen nähmen ihn weg.
      -->
      <Section v-if="openTable !== null && openView === 'rows'" title="Zeilen" full>
        <p class="section-note">
          {{ openKind }} <span class="ident">{{ openTable }}</span> · {{ openFacts }}
        </p>

        <div class="filter">
          <label class="field">
            <span>Spalte</span>
            <select v-model="draft.column">
              <option value="">alle Zeilen</option>
              <option v-for="column in columns" :key="column.name" :value="column.name">
                {{ column.name }}
              </option>
            </select>
          </label>

          <label class="field">
            <span>Vergleich</span>
            <select v-model="draft.operator" :disabled="draft.column === ''">
              <option v-for="operator in OPERATORS" :key="operator.value" :value="operator.value">
                {{ operator.label }}
              </option>
            </select>
          </label>

          <!--
            **Bei „ist leer" verschwindet das Feld, es wird nicht abgeblendet.**
            Ein Wert, den der Vergleich nicht benutzt, ist kein leeres Feld,
            sondern gar keines.
          -->
          <label v-if="draft.column !== '' && draft.operator !== 'empty'" class="field">
            <span>Wert</span>
            <input v-model="draft.value" type="text" />
          </label>

          <div class="button-row">
            <button type="button" class="button primary" @click="applyFilter()">Filtern</button>
            <button v-if="filter !== null" type="button" class="button" @click="clearFilter()">
              Zurücksetzen
            </button>
          </div>
        </div>

        <p v-if="loadingTable" class="empty">Wird geladen …</p>

        <p v-else-if="page === null || page.rows.length === 0" class="empty">
          {{ filter === null ? 'Diese Tabelle ist leer.' : 'Keine Zeile passt zu diesem Filter.' }}
        </p>

        <template v-else>
          <div class="scrolls">
            <table class="rows">
              <thead>
                <tr>
                  <th v-for="column in page.columns" :key="column">
                    <!--
                      Der Spaltenkopf ist der Sortierknopf. Ein eigener Knopf
                      daneben verdoppelte die Breite jeder Spalte, und die
                      Breite ist hier das knappe Gut.
                    -->
                    <button
                      type="button"
                      class="button small"
                      :class="order === column ? 'active' : ''"
                      @click="sortBy(column)"
                    >
                      {{ column }}
                      <span v-if="order === column">{{ descending ? '↓' : '↑' }}</span>
                    </button>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, index) in page.rows" :key="index">
                  <!--
                    **Der Wert steht in einem `div` und trägt kein `.ident`.**
                    Beides hat denselben Grund und steht bei `.rows .cell` in
                    `app.css`: `td .ident` verbietet den Umbruch — richtig für
                    eine Kennung, falsch für einen Wert von 512 Zeichen —, und
                    ein `max-width` auf einer Tabellenzelle gilt nicht.
                  -->
                  <td v-for="column in page.columns" :key="column">
                    <div class="cell">
                      <!--
                        **`NULL` steht vor allem anderen, und das war ein
                        Fehler.** Hier kam die binäre Spalte zuerst, und ihre
                        Länge ist für `NULL` eben `NULL` — `Number(null ?? 0)`
                        machte daraus `0`, und jede leere Zelle einer
                        `BLOB`-Spalte las sich als „binär · 0 B". Damit war sie
                        von einem tatsächlich leeren Blob nicht mehr zu
                        unterscheiden, und genau das verlangt Kriterium 2.
                        Gefunden im Bildschirmfoto-Durchgang zu Schritt 5, auf
                        einer Tabelle, in der `anhang` in jeder Zeile leer war.

                        > **Eine 0, die für „nichts da" steht, sieht aus wie
                        > eine Antwort.**

                        Es ist derselbe Fund wie bei der geschätzten Zeilenzahl
                        in `docs/46 §9` — dort hiess die falsche Antwort
                        „0 Zeilen", hier „0 B".
                      -->
                      <span v-if="row[column] === null" class="quiet">NULL</span>

                      <!--
                        **Eine binäre Spalte trägt ihre Länge und keinen Wert**
                        (`docs/46 §8.2`). Der Wert ist gar nicht erst abgefragt
                        worden — ein `BLOB` mit ungültigem UTF-8 machte sonst
                        die ganze Zeile unlesbar.
                      -->
                      <span v-else-if="isBinary(column)" class="quiet">
                        binär · {{ formatBytes(Number(row[column])) }}
                      </span>

                      <template v-else>
                        {{ row[column] }}
                        <button
                          v-if="isTruncated(column, String(row[column])) && keyColumns.length > 0"
                          type="button"
                          class="button small"
                          :aria-label="`Ganzen Wert von ${column} zeigen`"
                          @click="openCell(column, row)"
                        >
                          …
                        </button>
                      </template>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="pager">
            <div>
              <button
                type="button"
                class="button"
                :disabled="trail.length === 0"
                @click="back()"
              >
                Zurück
              </button>
            </div>
            <p class="pager-state">{{ pageState }}</p>
            <div class="right">
              <button type="button" class="button" :disabled="!page.more" @click="forward()">
                Weiter
              </button>
            </div>
          </div>
        </template>

        <div class="button-row">
          <button type="button" class="button" @click="closeTable()">Schliessen</button>
        </div>
      </Section>

      <!--
        Die Zelleinzelsicht — ein Bereich und kein Dialog.

        Dieses Panel hat keinen modalen Dialog, und diese Ansicht ist kein
        Anlass, den ersten einzuführen: Sie ist eine dritte Auskunft zur offenen
        Tabelle, genau wie Struktur und Indexe, und steht deshalb da, wo die
        auch stehen.
      -->
      <Section v-if="loadingCell || cell !== null" title="Zelle" full>
        <p v-if="loadingCell" class="empty">Wird geladen …</p>

        <template v-else-if="cell !== null">
          <!--
            **Die Grösse ist die des ganzen Wertes**, auch wenn hier ein
            gekürzter steht: Der Agent misst sie in der Datenbank und nicht an
            dem, was er ausliefert. Neben „gekürzt" ist sie damit die Auskunft
            darüber, wie viel fehlt.

            Die Grenze selbst steht hier absichtlich nicht als Zahl — sie gehört
            dem Agenten (`Console::CELL_FULL_LIMIT`), und eine zweite Fassung
            wäre die, die veraltet.
          -->
          <p class="section-note">
            Spalte <span class="ident">{{ cell.column }}</span> ·
            {{ formatBytes(cell.bytes) }}
            <template v-if="cell.truncated"> · <b>gekürzt</b> </template>
          </p>

          <p v-if="cell.value === null" class="empty">Diese Zelle ist NULL.</p>
          <pre v-else class="cell-value">{{ cell.value }}</pre>

          <div class="button-row">
            <button type="button" class="button" @click="cell = null">Schliessen</button>
          </div>
        </template>
        </Section>
      </div>
    </div>
  </PanelLayout>
</template>
