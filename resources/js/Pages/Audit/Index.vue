<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { reactive, watch } from 'vue'
import Section from '../../Components/Section.vue'
import Badge from '../../Components/Badge.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Pager from '../../Components/Pager.vue'
import { counted } from '../../Composables/useCounted'

/*
 * Das Protokoll.
 *
 * Die Filter stehen in der Adresszeile, nicht im Zustand der Seite: Ein
 * gefilterter Blick lässt sich damit weitergeben und als Lesezeichen behalten
 * — und der Export bekommt dieselben Werte mit, ohne dass sie ein zweites Mal
 * eingesammelt werden müssen.
 */

interface Row {
  id: number
  created_at: string | null
  action: string
  result: string
  result_label: string
  account_id: number | null
  acting_as_account_id: number | null
  subscription_id: number | null
  target: string | null
  details: string | null
  ip_address: string | null
}

const props = defineProps<{
  events: { data: Row[]; current_page: number; last_page: number; total: number }
  filters: Record<string, string>
  results: { value: string; label: string }[]
}>()

function rang(ergebnis: string): 'ok' | 'warn' | 'critical' | 'neutral' {
  if (ergebnis === 'success') return 'ok'
  if (ergebnis === 'denied') return 'warn'
  if (ergebnis === 'failure') return 'critical'

  return 'neutral'
}

const filters = reactive({
  from: props.filters.from ?? '',
  to: props.filters.to ?? '',
  action: props.filters.action ?? '',
  result: props.filters.result ?? '',
  ip: props.filters.ip ?? '',
})

let timer: ReturnType<typeof setTimeout> | undefined

watch(filters, () => {
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => {
    router.get('/audit', { ...filters }, { preserveState: true, replace: true })
  }, 300)
})

function exportUrl(): string {
  const query = new URLSearchParams(
    Object.entries(filters).filter(([, value]) => value !== ''),
  )

  return `/audit/export?${query.toString()}`
}
</script>

<template>
  <Head title="Protokoll" />

  <PanelLayout title="Protokoll" :subline="counted(events.total, 'Eintrag', 'Einträge')">
    <template #actions>
      <a :href="exportUrl()" class="button">Als CSV herunterladen</a>
    </template>

    <div class="sections">
      <Section title="Auswahl" full>
        <div class="filter">
          <label class="field"><span>Von</span><input v-model="filters.from" type="date"></label>
          <label class="field"><span>Bis</span><input v-model="filters.to" type="date"></label>
          <label class="field"><span>Aktion</span><input v-model="filters.action" type="text" placeholder="auth."></label>
          <label class="field">
            <span>Ergebnis</span>
            <select v-model="filters.result">
              <option value="">alle</option>
              <option v-for="r in results" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
          </label>
          <label class="field"><span>IP</span><input v-model="filters.ip" type="text"></label>
        </div>
      </Section>

      <Section title="Einträge" full>
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th>Zeitpunkt</th><th>Aktion</th><th>Ergebnis</th><th>Ziel</th>
                <th>Einzelheiten</th><th>IP</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in events.data" :key="row.id">
                <td data-column="Zeitpunkt" class="quiet">{{ row.created_at }}</td>
                <td data-column="Aktion" class="ident name">{{ row.action }}</td>
                <td data-column="Ergebnis">
                  <Badge :kind="rang(row.result)">{{ row.result_label }}</Badge>
                </td>
                <td data-column="Ziel" class="quiet">{{ row.target ?? '—' }}</td>

                <!--
                  **Der Zusammenhang** (`docs/66`, Befund 7). Bis zum 21. August
                  wurde er geschrieben und nirgends gelesen: Das Protokoll sagte
                  über P6 die Art der Handlung und nie ihren Gegenstand —
                  `file.removed` ohne die Datei, `sftp.key.remove` ohne den
                  Schlüssel.

                  Den Satz baut `AuditQuery`, damit Liste und Export denselben
                  lesen. Hier steht keine Zusammensetzung.
                -->
                <td data-column="Einzelheiten" class="quiet">{{ row.details ?? '—' }}</td>
                <td data-column="IP" class="ident quiet">{{ row.ip_address ?? '—' }}</td>
              </tr>
              <tr v-if="events.data.length === 0">
                <td colspan="6" class="quiet">Keine Einträge für diese Auswahl.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <Pager :page="props.events.current_page" :pages="props.events.last_page" />
      </Section>
    </div>
  </PanelLayout>
</template>

<style scoped>
/*
 * Die Filter stehen nebeneinander, solange sie nebeneinander passen.
 *
 * Vorher gab es dafür eine eigene Regel für die schmale Fläche: „untereinander
 * und über die volle Breite", weil `flex-wrap` auf 390px vier Zeilen mit je
 * einem angeschnittenen Feld ergab — ein Datumsfeld ist im Browser breiter,
 * als es aussieht. Mit einer Mindestbreite je Feld erledigt der Fluss das
 * selbst, und der Haltepunkt entfällt.
 */
.filter {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
}

.filter > .field {
  flex: 1 1 180px;
  margin-top: 0;
}
</style>
