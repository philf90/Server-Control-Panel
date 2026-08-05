<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { reactive, watch } from 'vue'
import Bereich from '../../Components/Bereich.vue'
import Marke from '../../Components/Marke.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

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
  ip_address: string | null
}

const props = defineProps<{
  events: { data: Row[]; current_page: number; last_page: number; total: number }
  filters: Record<string, string>
  results: { value: string; label: string }[]
}>()

function rang(ergebnis: string): 'ok' | 'warn' | 'kritisch' | 'neutral' {
  if (ergebnis === 'success') return 'ok'
  if (ergebnis === 'denied') return 'warn'
  if (ergebnis === 'failure') return 'kritisch'

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

  <PanelLayout title="Protokoll" :subline="`${events.total} Einträge`">
    <template #aktion>
      <a :href="exportUrl()" class="knopf">Als CSV herunterladen</a>
    </template>

    <div class="bereiche">
      <Bereich titel="Auswahl" voll>
        <div class="filter">
          <label class="feld"><span>Von</span><input v-model="filters.from" type="date"></label>
          <label class="feld"><span>Bis</span><input v-model="filters.to" type="date"></label>
          <label class="feld"><span>Aktion</span><input v-model="filters.action" type="text" placeholder="auth."></label>
          <label class="feld">
            <span>Ergebnis</span>
            <select v-model="filters.result">
              <option value="">alle</option>
              <option v-for="r in results" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
          </label>
          <label class="feld"><span>IP</span><input v-model="filters.ip" type="text"></label>
        </div>
      </Bereich>

      <Bereich titel="Einträge" voll>
        <div class="rollt">
          <table class="stapelt">
            <thead>
              <tr>
                <th>Zeitpunkt</th><th>Aktion</th><th>Ergebnis</th><th>Ziel</th><th>IP</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in events.data" :key="row.id">
                <td data-spalte="Zeitpunkt" class="stumm">{{ row.created_at }}</td>
                <td data-spalte="Aktion" class="kennung name">{{ row.action }}</td>
                <td data-spalte="Ergebnis">
                  <Marke :art="rang(row.result)">{{ row.result_label }}</Marke>
                </td>
                <td data-spalte="Ziel" class="stumm">{{ row.target ?? '—' }}</td>
                <td data-spalte="IP" class="kennung stumm">{{ row.ip_address ?? '—' }}</td>
              </tr>
              <tr v-if="events.data.length === 0">
                <td colspan="5" class="stumm">Keine Einträge für diese Auswahl.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Bereich>
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

.filter > .feld {
  flex: 1 1 180px;
  margin-top: 0;
}
</style>
