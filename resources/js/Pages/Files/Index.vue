<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

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
 * Grössen in Bytes bis 1024, danach in KB/MB/GB.
 *
 * Verzeichnisse und Verweise bekommen einen Strich und keine 0: Die Grösse
 * eines Verzeichniseintrags ist eine Zahl über die Verwaltung des Dateisystems
 * und keine über den Inhalt, und 0 würde behaupten, es sei leer.
 */
function size(entry: Entry): string {
  if (entry.type !== 'file') return '—'
  if (entry.size < 1024) return `${entry.size} B`

  const units = ['KB', 'MB', 'GB', 'TB']
  let value = entry.size / 1024
  let unit = 0

  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }

  return `${value.toFixed(1)} ${units[unit]}`
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
const upload = useForm<{ path: string; file: File | null }>({ path: '', file: null })
const rename = useForm({ from: '', to: '' })
const modeForm = useForm({ path: '', mode: 0 })

/* Welches Formular gerade offen ist — höchstens eines. */
const open_ = ref<'directory' | 'upload' | null>(null)

function submitDirectory(): void {
  newDirectory
    .transform((data) => ({ ...data, path: here(data.path) }))
    .post(`/subscriptions/${props.subscription.id}/files/directory`, {
      preserveScroll: true,
      onSuccess: () => { newDirectory.reset(); open_.value = null },
    })
}

function submitUpload(): void {
  const chosen = upload.file

  if (chosen === null) return

  upload
    .transform((data) => ({ ...data, path: here(chosen.name) }))
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

function startChmod(entry: Entry): void {
  /*
   * Oktal eingeben und oktal anzeigen.
   *
   * `parseInt(x, 8)` und nicht `Number(x)`: „644" als Dezimalzahl wäre 644 und
   * damit ausserhalb der zwölf Bits — der Agent wiese es ab, und der Kunde
   * läse eine Meldung über eine Zahl, die er so nie gemeint hat.
   */
  const wanted = window.prompt(`Rechte für „${entry.name}" (oktal)`, entry.mode.toString(8).padStart(3, '0'))

  if (wanted === null) return

  const mode = parseInt(wanted, 8)

  if (Number.isNaN(mode)) return

  modeForm.path = entry.path
  modeForm.mode = mode
  modeForm.post(`/subscriptions/${props.subscription.id}/files/chmod`, { preserveScroll: true })
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

    <form v-if="open_ === 'upload'" class="button-row" @submit.prevent="submitUpload">
      <label class="field inline">
        <span>Datei</span>
        <input
          type="file"
          required
          @change="upload.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
        />
      </label>
      <button type="submit" class="button primary" :disabled="upload.processing || upload.file === null">
        Hochladen
      </button>
      <!--
        Der Fortschritt kommt von Inertia und nicht von einer eigenen Zählung:
        Eine zweite Fassung derselben Zahl wäre die, die stehenbleibt.
      -->
      <span v-if="upload.progress" class="quiet">{{ upload.progress.percentage }} %</span>
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
                <button type="button" class="button quiet" @click="startRename(entry)">Umbenennen</button>
                <button
                  v-if="entry.type !== 'link'"
                  type="button"
                  class="button quiet"
                  @click="startChmod(entry)"
                >
                  Rechte
                </button>
                <button
                  v-if="isArchive(entry)"
                  type="button"
                  class="button quiet"
                  @click="extract(entry)"
                >
                  Entpacken
                </button>
                <button type="button" class="button quiet" @click="remove(entry)">Entfernen</button>
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
