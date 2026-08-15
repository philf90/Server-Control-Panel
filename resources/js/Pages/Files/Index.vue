<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import FormErrors from '../../Components/FormErrors.vue'
import FileTree from '../../Components/FileTree.vue'
import PermissionEditor from '../../Components/PermissionEditor.vue'
import { counted } from '../../Composables/useCounted'
import { formatBytes } from '../../bytes'

interface Entry {
  name: string
  path: string
  type: 'file' | 'directory' | 'link'
  size: number
  mode: number
  modified_at: number
  target: string | null
  readable: boolean
  writable: boolean
}

const props = defineProps<{
  subscription: { id: number; name: string }
  path: string
  entries: Entry[]
  truncated: boolean

  /**
   * Welche Verzeichnisse der Webserver ausliefert.
   *
   * Sie kommen vom Server, weil nur er sie kennt: `httpdocs` ist der
   * DocumentRoot der Hauptdomain, jede weitere heisst wie ihre Domain. Eine
   * Seite, die `httpdocs` hineinschriebe, gäbe für den einen Ordner die
   * richtige Auskunft und für den daneben wortgleich die falsche.
   */
  documentRoots: string[]

  can: { edit: boolean }
}>()

/*
 * Die Krümel oben. Sie entstehen aus dem Pfad und nicht aus einer mitgelieferten
 * Liste — zwei Fassungen desselben Weges wären zwei Gelegenheiten, sie
 * auseinanderlaufen zu lassen.
 */
const crumbs = computed(() => {
  const parts = props.path.split('/').filter(Boolean)

  return parts.map((name, index) => ({
    name,
    path: '/' + parts.slice(0, index + 1).join('/'),
  }))
})

const parent = computed(() => {
  const parts = props.path.split('/').filter(Boolean)
  parts.pop()

  return '/' + parts.join('/')
})

function open(path: string): void {
  router.get(`/subscriptions/${props.subscription.id}/files`, { path }, { preserveScroll: true })
}

/*
 * Die Rechte als `rwxr-x---`.
 *
 * Als Zahl wäre es kürzer und für den Kunden unlesbar; als Zahl **und** Text
 * wäre es zweimal dasselbe. Der Text steht hier, die Zahl im Formular, das sie
 * ändert — dort ist sie das, was eingegeben wird.
 */
function rights(mode: number): string {
  const set = ['r', 'w', 'x']

  return [6, 3, 0]
    .map((shift) => set.map((c, i) => ((mode >> shift) & (4 >> i) ? c : '-')).join(''))
    .join('')
}

/*
 * Die Grösse kommt aus `bytes.ts` und wird hier nicht gerechnet.
 *
 * **Diese Seite hatte ihre eigene Umrechnung, und `SizeUnitTest` hat sie
 * gefunden.** Der Wächter steht seit dem dritten Abnahmelauf: Liste und
 * Einzelansicht hatten je eine `size()`-Funktion mit demselben Inhalt, und zwei
 * Fassungen derselben Regel heissen, dass die eine nachgezogen wird und die
 * andere nicht.
 *
 * Was hier bleibt, ist die Entscheidung, die nur diese Seite treffen kann:
 * Ein Verzeichnis und ein Verweis haben keine Inhaltsgrösse. „0 B" behauptete,
 * sie seien leer.
 */
function size(entry: Entry): string {
  return entry.type === 'file' ? formatBytes(entry.size) : '—'
}

function moment(seconds: number): string {
  return new Date(seconds * 1000).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' })
}

/*
 * Ein Ziel im aktuellen Verzeichnis.
 *
 * Der Kunde gibt einen **Namen** ein, keinen Pfad — der Pfad entsteht hier aus
 * dem Verzeichnis, in dem er steht. Das ist keine Schranke (die hält das Chroot
 * im Agenten), sondern die Bedienung: Wer in `httpdocs` steht und „bilder"
 * eingibt, meint `httpdocs/bilder`.
 */
function here(name: string): string {
  return (props.path === '/' ? '' : props.path) + '/' + name
}

const newDirectory = useForm({ path: '' })

/**
 * Eine neue, leere Datei.
 *
 * **Sie geht an dieselbe Adresse wie das Speichern aus dem Editor.**
 * `files.write` legt an, was es nicht gibt, und sagt in seiner Antwort, ob es
 * das getan hat — der Weg danach hängt an dieser Auskunft und nicht an einem
 * Feld im Formular.
 *
 * Der Inhalt ist leer und bleibt es: Wer eine Datei anlegt, will etwas
 * hineinschreiben, und dafür ist der Editor da. Ein zweites Textfeld hier wäre
 * eine zweite Stelle, an der man Dateiinhalte tippt.
 */
const newFile = useForm({ path: '', content: '' })

const upload = useForm<{ path: string; files: File[] }>({ path: '', files: [] })
const rename = useForm({ path: '', name: '' })
const modeForm = useForm({ path: '', mode: 0 })

/* Welches Formular gerade offen ist — höchstens eines. */
const open_ = ref<'directory' | 'file' | 'upload' | null>(null)

function submitDirectory(): void {
  newDirectory
    .transform((data) => ({ ...data, path: here(data.path) }))
    .post(`/subscriptions/${props.subscription.id}/files/directory`, {
      preserveScroll: true,
      onSuccess: () => { newDirectory.reset(); open_.value = null },
    })
}

function submitFile(): void {
  newFile
    .transform((data) => ({ ...data, path: here(data.path) }))
    .put(`/subscriptions/${props.subscription.id}/files`, {
      preserveScroll: true,
      onSuccess: () => { newFile.reset(); open_.value = null },
    })
}

/**
 * Hochladen — eine Datei oder viele.
 *
 * **`path` ist das Verzeichnis und nicht mehr der vollständige Pfad.** Vorher
 * setzte diese Seite den Zielpfad aus dem Dateinamen zusammen; bei mehreren
 * Dateien wäre das ein Pfad für alle gewesen. Wie die einzelne Datei heisst,
 * entscheidet jetzt der Server aus ihrem Namen.
 */
function submitUpload(): void {
  if (upload.files.length === 0) return

  upload
    .transform((data) => ({ ...data, path: props.path }))
    .post(`/subscriptions/${props.subscription.id}/files/upload`, {
      preserveScroll: true,
      forceFormData: true,
      onSuccess: () => { upload.reset(); open_.value = null },
    })
}

/**
 * Umbenennen — der Name geht als Name und nicht als Pfad.
 *
 * **Hier stand `here(wanted)`.** Diese Seite setzte den Zielpfad selbst
 * zusammen und schickte ihn an `files/move`; seit die Mehrfachauswahl dort ein
 * **Verzeichnis** erwartet, wäre das dasselbe Feld mit zwei Bedeutungen. Wie der
 * Eintrag danach heisst, entscheidet jetzt der Server aus dem Namen — dieselbe
 * Verschiebung wie beim Hochladen mehrerer Dateien.
 */
function startRename(entry: Entry): void {
  const wanted = window.prompt(`Neuer Name für „${entry.name}"`, entry.name)

  if (wanted === null || wanted === '' || wanted === entry.name) return

  rename.path = entry.path
  rename.name = wanted
  rename.post(`/subscriptions/${props.subscription.id}/files/rename`, { preserveScroll: true })
}

/**
 * Welcher Eintrag gerade seine Rechte zeigt — `null`, wenn keiner.
 *
 * **Hier stand ein `window.prompt`.** Er verlangte eine Oktalzahl, erklärte
 * nichts und brachte einen Systemdialog mit, der keine Farbe aus `app.css`
 * nimmt (`docs/53`, Befund 8). Der Ersatz ist ein Bereich auf der Seite:
 * Dieses Panel hat keine Modalen, und es bekommt auch für diesen einen Fall
 * keine.
 */
const chmodFor = ref<Entry | null>(null)

/**
 * Liegt dieser Eintrag in einem Verzeichnis, das der Webserver ausliefert?
 *
 * Entscheidet, ob {@see PermissionEditor} den Satz über den Webserver zeigt.
 * Ein Ordner **ist** sein DocumentRoot oder liegt darin; beides zählt.
 */
function served(entry: Entry): boolean {
  return props.documentRoots.some(
    (root) => entry.path === root || entry.path.startsWith(root + '/'),
  )
}

function startChmod(entry: Entry): void {
  chmodFor.value = chmodFor.value?.path === entry.path ? null : entry

  if (chmodFor.value !== null) {
    modeForm.path = entry.path
    modeForm.mode = entry.mode & 0o777
  }
}

function submitChmod(): void {
  modeForm.post(`/subscriptions/${props.subscription.id}/files/chmod`, {
    preserveScroll: true,
    onSuccess: () => { chmodFor.value = null },
  })
}

/*
 * Entpacken bietet sich nur an, wo es etwas zu entpacken gibt.
 *
 * Am Inhalt zu erkennen wäre genauer als an der Endung — das tut der Agent
 * auch. Für die Frage, ob ein Knopf erscheint, reicht die Endung: Ein Archiv
 * ohne passenden Namen wird über den Griff nicht angeboten und lässt sich
 * trotzdem entpacken, sobald es richtig heisst.
 */
function isArchive(entry: Entry): boolean {
  return entry.type === 'file' && /\.(zip|tar|tar\.gz|tgz)$/i.test(entry.name)
}

function extract(entry: Entry): void {
  router.post(`/subscriptions/${props.subscription.id}/files/extract`, {
    path: entry.path,
    target: props.path,
  })
}

function search(): void {
  const wanted = window.prompt('Wonach soll unterhalb dieses Verzeichnisses gesucht werden?')

  if (wanted === null || wanted === '') return

  router.get(`/subscriptions/${props.subscription.id}/files/search`, { query: wanted, path: props.path })
}

function remove(entry: Entry): void {
  const question = entry.type === 'directory'
    ? `„${entry.name}" mitsamt Inhalt entfernen?`
    : `„${entry.name}" entfernen?`

  if (!window.confirm(question)) return

  router.delete(`/subscriptions/${props.subscription.id}/files`, {
    data: { paths: [entry.path], recursive: entry.type === 'directory' },
    preserveScroll: true,
  })
}

/*
 * ────────────────────────────────────────────────────────────────────────────
 * Die Mehrfachauswahl (P6 Schritt 5h)
 * ────────────────────────────────────────────────────────────────────────────
 */

/**
 * Was angehakt ist — als Pfade und nicht als Einträge.
 *
 * Ein Pfad überlebt eine neue Liste, ein Eintragsobjekt nicht: Inertia liefert
 * bei jeder Navigation frische Objekte, und eine Auswahl aus den alten zeigte
 * danach auf nichts.
 */
const selected = ref<string[]>([])

/**
 * Welche Aktion gerade auf ein Ziel wartet — `null`, wenn keine.
 *
 * **Der Baum ist der Zielwähler** (Entscheidung des Betreibers vom 15. August
 * 2026). Ein Textfeld für den Zielpfad wäre die kürzere Fassung und die
 * schlechtere: Wer ein Ziel tippt, tippt sich vertippt, und die Absage kommt
 * erst nach dem Abschicken.
 */
const picking = ref<'copy' | 'move' | null>(null)

/**
 * Beim Wechsel des Verzeichnisses fällt die Auswahl weg.
 *
 * **Sonst entfernt sie Einträge, die niemand mehr sieht.** Eine Auswahl, die
 * eine Navigation überlebt, ist eine Liste von Pfaden aus einem anderen
 * Verzeichnis — und die Knopfreihe darüber sagt „3 Einträge ausgewählt", während
 * die Tabelle darunter keinen einzigen Haken zeigt.
 *
 * > **Eine Auswahl, die man nicht mehr sieht, ist keine Auswahl mehr — nur eine
 * > Zahl, die noch da ist.**
 */
watch(() => props.path, () => {
  selected.value = []
  picking.value = null
})

function toggleSelected(path: string, on: boolean): void {
  selected.value = on
    ? [...selected.value, path]
    : selected.value.filter((einzeln) => einzeln !== path)
}

/**
 * Der Haken in der Kopfzeile.
 *
 * Er hat nur zwei Zustände und keinen dritten für „einige": Ein Ankreuzfeld, das
 * `indeterminate` zeigt, braucht einen Griff auf das DOM-Element, und die
 * Auskunft steht in der Zeile daneben ohnehin als Zahl.
 */
const allSelected = computed<boolean>({
  get: () => props.entries.length > 0 && selected.value.length === props.entries.length,
  set: (on: boolean) => {
    selected.value = on ? props.entries.map((entry) => entry.path) : []
  },
})

const chosenDirectories = computed(
  () => props.entries.filter((entry) => entry.type === 'directory' && selected.value.includes(entry.path)),
)

/**
 * Welche der ausgewählten Einträge eine Domain ausliefern.
 *
 * **Eine Warnung und keine zweite Durchsetzung.** Das Gerüst des Abonnements —
 * `httpdocs`, `logs`, `conf` und die anderen — schützt der Agent
 * (`Files\Scheme`). Der DocumentRoot einer **weiteren** Domain heisst wie die
 * Domain, steht in keiner festen Liste, und der Agent müsste dafür die
 * vhost-Dateien lesen. Das Panel kennt die Namen und sagt deshalb, was
 * passiert; entfernen darf der Kunde sein Verzeichnis.
 */
const chosenRoots = computed(
  () => selected.value.filter((path) => props.documentRoots.includes(path)),
)

/**
 * Die Rückfrage vor einem Griff, der mehrere Einträge trifft.
 *
 * **Sie nennt, was der Betrachter nicht sieht.** In der Tabelle steht neben
 * jedem Haken ein Name; was dort *nicht* steht, ist die Zahl der Verzeichnisse
 * (deren Inhalt mitgeht) und die Frage, ob eines davon eine Domain ausliefert.
 * Beides entscheidet, ob der Klick harmlos ist.
 */
function confirmed(verb: string): boolean {
  const zeilen = [`${counted(selected.value.length, 'Eintrag', 'Einträge')} ${verb}?`]

  const ordner = chosenDirectories.value.length

  if (ordner === 1) {
    zeilen.push('Darunter ist ein Verzeichnis — sein Inhalt geht mit.')
  } else if (ordner > 1) {
    zeilen.push(`Darunter sind ${ordner} Verzeichnisse — ihr Inhalt geht mit.`)
  }

  if (chosenRoots.value.length === 1) {
    zeilen.push(`${chosenRoots.value[0]} liefert eine Domain aus. Die Seite ist danach nicht mehr erreichbar.`)
  } else if (chosenRoots.value.length > 1) {
    zeilen.push(
      `${chosenRoots.value.length} der ausgewählten Verzeichnisse liefern Domains aus. `
      + 'Diese Seiten sind danach nicht mehr erreichbar.',
    )
  }

  return window.confirm(zeilen.join('\n\n'))
}

function removeSelected(): void {
  if (selected.value.length === 0 || !confirmed('entfernen')) return

  router.delete(`/subscriptions/${props.subscription.id}/files`, {
    data: { paths: selected.value, recursive: chosenDirectories.value.length > 0 },
    preserveScroll: true,
    onSuccess: () => { selected.value = [] },
  })
}

/**
 * Packen — die Auswahl in **ein** Archiv.
 *
 * Der Name wird gefragt und nicht geraten: `auswahl.zip` neben `auswahl.zip`
 * wäre beim zweiten Mal eine Absage, und ein Zeitstempel im Namen ist eine
 * Erfindung des Panels über etwas, das der Kunde benennt.
 */
function packSelected(): void {
  if (selected.value.length === 0) return

  const name = window.prompt('Wie soll das Archiv heissen?', 'auswahl.zip')

  if (name === null || name === '') return

  router.post(
    `/subscriptions/${props.subscription.id}/files/compress`,
    { paths: selected.value, target: here(name) },
    { preserveScroll: true, onSuccess: () => { selected.value = [] } },
  )
}

/**
 * Das Ziel steht fest — kopieren oder verschieben.
 *
 * **Das Ziel ist ein Verzeichnis, kein Pfad.** Wie der einzelne Eintrag darin
 * heisst, entscheidet der Server aus seinem Namen; bei mehreren Quellen wäre ein
 * vollständiger Zielpfad **ein** Pfad für alle — der letzte gewönne, die anderen
 * wären fort, und der Vorgang meldete Erfolg.
 */
function pick(target: string): void {
  const aktion = picking.value

  if (aktion === null || selected.value.length === 0) return

  router.post(
    `/subscriptions/${props.subscription.id}/files/${aktion}`,
    { paths: selected.value, to: target },
    {
      preserveScroll: true,
      onSuccess: () => { selected.value = [] },
      onFinish: () => { picking.value = null },
    },
  )
}
</script>

<template>
  <Head :title="`Dateien — ${props.subscription.name}`" />

  <PanelLayout title="Dateien" :subline="props.subscription.name">
    <FormErrors />

    <!--
      Die Krümel sind der Baum. `docs/46 §20.11` hat gemessen, was ein langer
      Name in einer Überschrift anrichtet — deshalb steht der Pfad hier als
      umbrechende Zeile und nicht im Seitentitel.
    -->
    <template #actions>
      <div v-if="props.can.edit" class="button-row">
        <button type="button" class="button" @click="open_ = open_ === 'directory' ? null : 'directory'">
          Verzeichnis anlegen
        </button>
        <button type="button" class="button" @click="open_ = open_ === 'file' ? null : 'file'">
          Datei anlegen
        </button>
        <button type="button" class="button primary" @click="open_ = open_ === 'upload' ? null : 'upload'">
          Datei hochladen
        </button>
      </div>
      <button type="button" class="button" @click="search">Suchen</button>
    </template>

    <!--
      Die Beschriftung steht sichtbar dabei und nicht nur als `aria-label` —
      am 7. August 2026 hat der Betreiber genau das an der Domainauswahl
      gemeldet, und es gilt hier genauso.
    -->
    <form v-if="open_ === 'directory'" class="button-row" @submit.prevent="submitDirectory">
      <label class="field inline">
        <span>Name des Verzeichnisses</span>
        <input v-model="newDirectory.path" type="text" autocomplete="off" required />
      </label>
      <button type="submit" class="button primary" :disabled="newDirectory.processing">Anlegen</button>
    </form>

    <!--
      Die neue Datei entsteht leer und der Editor öffnet sich danach. Ein
      Textfeld hier wäre eine zweite Stelle, an der man Dateiinhalte tippt —
      und die zweite ist die, die den Editor nicht hat.
    -->
    <form v-if="open_ === 'file'" class="button-row" @submit.prevent="submitFile">
      <label class="field inline">
        <span>Name der Datei</span>
        <input v-model="newFile.path" type="text" autocomplete="off" required />
      </label>
      <button type="submit" class="button primary" :disabled="newFile.processing">Anlegen</button>
    </form>

    <form v-if="open_ === 'upload'" class="button-row" @submit.prevent="submitUpload">
      <!--
        `multiple`, und die Rückmeldung dazu steht im Controller: Was zählt, ist
        nicht die Schleife, sondern der Fall, in dem Datei 7 von 20 die Quota
        reisst und die anderen neunzehn schon dort liegen.
      -->
      <label class="field inline">
        <span>Dateien</span>
        <input
          type="file"
          multiple
          required
          @change="upload.files = Array.from(($event.target as HTMLInputElement).files ?? [])"
        />
      </label>
      <button type="submit" class="button primary" :disabled="upload.processing || upload.files.length === 0">
        Hochladen
      </button>
      <!--
        Der Fortschritt kommt von Inertia und nicht von einer eigenen Zählung:
        Eine zweite Fassung derselben Zahl wäre die, die stehenbleibt.
      -->
      <span v-if="upload.progress" class="quiet">{{ upload.progress.percentage }} %</span>
    </form>

    <!--
      **Die Rechte stehen auf der Seite und nicht in einem Systemdialog.**

      Sie stehen hier oben und nicht in der Tabellenzeile: Bei 390px bricht die
      Tabelle in Kärtchen um, und ein Formular in einer Zelle bräuchte dort
      eine zweite Form. Der Name des Eintrags steht dabei, weil sonst nicht
      abzulesen wäre, wessen Rechte gerade offen sind.
    -->
    <form v-if="chmodFor !== null" @submit.prevent="submitChmod">
      <p class="path-line">Rechte für {{ chmodFor.name }}</p>

      <PermissionEditor
        v-model="modeForm.mode"
        :is-directory="chmodFor.type === 'directory'"
        :served="served(chmodFor)"
      />

      <div class="button-row">
        <button type="submit" class="button primary" :disabled="modeForm.processing">Speichern</button>
        <button type="button" class="button" @click="chmodFor = null">Abbrechen</button>
      </div>
    </form>

    <!--
      **Baum links, Liste rechts** (`docs/51 §8`). Bis Schritt 5g stand hier nur
      der Satz „Die Krümel sind der Baum" — als Entscheidung hingeschrieben,
      ohne dass sie je eine war.

      `.split` bringt den Haltepunkt und die beiden `min-width: 0` mit, die dort
      teuer erkauft sind: Ohne sie schiebt die Seite bei 800px um 242px, und
      zwar **nur** dort — bei 720 und 1440 ist sie sauber.
    -->
    <div class="split">
      <div class="aside">
        <!--
          **Derselbe Baum in zwei Rollen.** Beim Navigieren führt ein Klick zur
          Liste, beim Auswählen setzt er das Ziel — der Unterschied ist eine
          einzige Angabe. Zwei Bäume wären zwei Bausteine, die dasselbe zeigen,
          und beim nächsten Umbau zeigten sie es verschieden.
        -->
        <FileTree
          :subscription-id="props.subscription.id"
          :current="props.path"
          :picking="picking !== null"
          @open="open"
          @pick="pick"
        />
      </div>

      <div>
    <nav class="crumbs" aria-label="Pfad">
      <button type="button" class="link" @click="open('/')">Abo-Wurzel</button>
      <template v-for="crumb in crumbs" :key="crumb.path">
        <span class="quiet" aria-hidden="true">/</span>
        <button type="button" class="link" @click="open(crumb.path)">{{ crumb.name }}</button>
      </template>
    </nav>

    <!--
      **Was mit der Auswahl geschehen kann, steht über der Auswahl.**

      Nicht in der Zeile: Ein Griff je Zeile beantwortet die Frage „was tue ich
      mit dieser Datei", und die Mehrfachauswahl stellt eine andere. Und nicht
      unten: Bei zwanzig Zeilen stünde der Knopf ausserhalb des Bildes, während
      der letzte Haken gesetzt wird.
    -->
    <div v-if="props.can.edit && selected.length > 0" class="selection">
      <span v-if="picking === null">
        {{ counted(selected.length, 'Eintrag', 'Einträge') }} ausgewählt
      </span>
      <span v-else>
        Ziel im Baum wählen — {{ counted(selected.length, 'Eintrag', 'Einträge') }}
        {{ picking === 'copy' ? 'kopieren' : 'verschieben' }}
      </span>

      <div class="button-row">
        <template v-if="picking === null">
          <button type="button" class="button small" @click="picking = 'copy'">Kopieren</button>
          <button type="button" class="button small" @click="picking = 'move'">Verschieben</button>
          <button type="button" class="button small" @click="packSelected">Als Zip packen</button>
          <button type="button" class="button small danger" @click="removeSelected">Entfernen</button>
          <!--
            **„Alle auswählen" steht hier und nicht nur im Spaltenkopf.**
            `.stacks thead` ist unter 720px aus dem Bild geschoben — der Haken
            oben links gibt es auf dem Telefon also gar nicht, und ohne diesen
            Knopf müsste man dort zwanzig Zeilen einzeln anhaken.
          -->
          <button
            v-if="selected.length < props.entries.length"
            type="button"
            class="button small"
            @click="allSelected = true"
          >
            Alle auswählen
          </button>
          <button type="button" class="button small" @click="selected = []">Auswahl aufheben</button>
        </template>
        <button v-else type="button" class="button small" @click="picking = null">Abbrechen</button>
      </div>
    </div>

    <p v-if="props.truncated" class="notice warn">
      Dieses Verzeichnis hat mehr Einträge, als die Liste zeigt. Angezeigt wird der Anfang.
    </p>

    <div class="scrolls">
      <table class="stacks">
        <thead>
          <tr>
            <!--
              Der Haken in der Kopfzeile wählt alles, was die Liste **zeigt** —
              nicht alles, was im Verzeichnis liegt. Bei einer gekürzten Liste
              stünde sonst „alle ausgewählt" über einer Auswahl, die den Rest
              nicht kennt.
            -->
            <th v-if="props.can.edit">
              <input
                v-model="allSelected"
                type="checkbox"
                class="check"
                :aria-label="allSelected ? 'Auswahl aufheben' : 'Alle auswählen'"
              />
            </th>
            <th>Name</th><th>Grösse</th><th>Rechte</th><th>Geändert</th><th>Griffe</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="props.path !== '/'">
            <td data-column="Name" :colspan="props.can.edit ? 6 : 5">
              <button type="button" class="link" @click="open(parent)">… eine Ebene höher</button>
            </td>
          </tr>

          <tr v-for="entry in props.entries" :key="entry.path">
            <!--
              **Der Haken steht an jeder Zeile und nicht nur an den beschreibbaren.**
              Ob ein Eintrag sich entfernen lässt, entscheidet der Kernel — und was
              dabei herauskommt, steht danach je Eintrag in der Rückmeldung. Ein
              Haken, der bei `conf/` fehlt, sähe aus wie ein Anzeigefehler; eine
              Absage mit Grund ist eine Auskunft.
            -->
            <td v-if="props.can.edit" data-column="Auswahl">
              <input
                type="checkbox"
                class="check"
                :checked="selected.includes(entry.path)"
                :aria-label="`${entry.name} auswählen`"
                @change="toggleSelected(entry.path, ($event.target as HTMLInputElement).checked)"
              />
            </td>

            <!--
              `cell-name` und nicht `ident`: Ein Dateiname darf 255 Zeichen lang
              sein, und `td .ident { white-space: nowrap }` machte die Tabelle in
              `docs/46 §20.13` 5710px breit — zehn Bildschirme Rollen durch eine
              einzige Zelle, ohne dass die Überlaufmessung eine Zahl liefert.
            -->
            <td data-column="Name" class="cell-name">
              <button
                v-if="entry.type === 'directory'"
                type="button"
                class="link"
                @click="open(entry.path)"
              >
                {{ entry.name }}/
              </button>

              <Link
                v-else-if="entry.type === 'file' && entry.readable"
                :href="`/subscriptions/${props.subscription.id}/files/edit?path=${encodeURIComponent(entry.path)}`"
                class="link"
              >
                {{ entry.name }}
              </Link>

              <span v-else>{{ entry.name }}</span>

              <!--
                Ein Verweis wird als Verweis gezeigt und sein Ziel dazu. Im
                Chroot zeigt er meistens ins Leere, wenn der Kunde ihn von
                aussen angelegt hat — und genau das soll ablesbar sein.
              -->
              <span v-if="entry.type === 'link'" class="quiet"> → {{ entry.target ?? '?' }}</span>
            </td>

            <td data-column="Grösse">{{ size(entry) }}</td>
            <td data-column="Rechte" class="ident quiet">{{ rights(entry.mode) }}</td>
            <td data-column="Geändert" class="quiet">{{ moment(entry.modified_at) }}</td>

            <td data-column="Griffe">
              <!--
                Der Knopf erscheint nur, wenn der Betrachter ihn drücken darf —
                und die Antwort darauf kommt aus derselben Policy, die ihn
                später abweist (`AbilityReachTest`). `writable` kommt aus dem
                Dateisystem und ist die zweite Bedingung: `conf/` gehört root,
                und daran ändert keine Berechtigung im Panel etwas.
              -->
              <div v-if="props.can.edit && entry.writable" class="button-row">
                <button type="button" class="button small" @click="startRename(entry)">Umbenennen</button>
                <button
                  v-if="entry.type !== 'link'"
                  type="button"
                  class="button small"
                  @click="startChmod(entry)"
                >
                  Rechte
                </button>
                <button
                  v-if="isArchive(entry)"
                  type="button"
                  class="button small"
                  @click="extract(entry)"
                >
                  Entpacken
                </button>
                <button type="button" class="button small" @click="remove(entry)">Entfernen</button>
              </div>
              <span v-else class="quiet">—</span>
            </td>
          </tr>

          <tr v-if="props.entries.length === 0">
            <td :colspan="props.can.edit ? 6 : 5" class="quiet">Dieses Verzeichnis ist leer.</td>
          </tr>
        </tbody>
      </table>
        </div>
      </div>
    </div>
  </PanelLayout>
</template>
