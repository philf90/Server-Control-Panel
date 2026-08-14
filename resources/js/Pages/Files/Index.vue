<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
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
              <button
                v-if="props.can.edit && entry.writable"
                type="button"
                class="button quiet"
                @click="remove(entry)"
              >
                Entfernen
              </button>
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
