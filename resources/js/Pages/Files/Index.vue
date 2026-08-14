<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import FormErrors from '../../Components/FormErrors.vue'
import PermissionEditor from '../../Components/PermissionEditor.vue'
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
const rename = useForm({ from: '', to: '' })
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

function startRename(entry: Entry): void {
  const wanted = window.prompt(`Neuer Name für „${entry.name}"`, entry.name)

  if (wanted === null || wanted === '' || wanted === entry.name) return

  rename.from = entry.path
  rename.to = here(wanted)
  rename.post(`/subscriptions/${props.subscription.id}/files/move`, { preserveScroll: true })
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
    data: { path: entry.path, recursive: entry.type === 'directory' },
    preserveScroll: true,
  })
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

    <nav class="crumbs" aria-label="Pfad">
      <button type="button" class="link" @click="open('/')">Abo-Wurzel</button>
      <template v-for="crumb in crumbs" :key="crumb.path">
        <span class="quiet" aria-hidden="true">/</span>
        <button type="button" class="link" @click="open(crumb.path)">{{ crumb.name }}</button>
      </template>
    </nav>

    <p v-if="props.truncated" class="notice warn">
      Dieses Verzeichnis hat mehr Einträge, als die Liste zeigt. Angezeigt wird der Anfang.
    </p>

    <div class="scrolls">
      <table class="stacks">
        <thead>
          <tr>
            <th>Name</th><th>Grösse</th><th>Rechte</th><th>Geändert</th><th>Griffe</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="props.path !== '/'">
            <td data-column="Name" colspan="5">
              <button type="button" class="link" @click="open(parent)">… eine Ebene höher</button>
            </td>
          </tr>

          <tr v-for="entry in props.entries" :key="entry.path">
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
            <td colspan="5" class="quiet">Dieses Verzeichnis ist leer.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </PanelLayout>
</template>
