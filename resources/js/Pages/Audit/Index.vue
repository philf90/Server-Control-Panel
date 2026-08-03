<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { reactive, watch } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

/*
 * Das Protokoll.
 *
 * Die Filter stehen in der Adresszeile, nicht im Zustand der Seite: Ein
 * gefilterter Blick laesst sich damit weitergeben und als Lesezeichen
 * behalten — und der Export bekommt dieselben Werte mit, ohne dass sie ein
 * zweites Mal eingesammelt werden muessen.
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
    <section class="protokoll">
      <header>
        <a :href="exportUrl()" class="knopf">Als CSV herunterladen</a>
      </header>

      <div class="filter">
        <label>Von <input v-model="filters.from" type="date"></label>
        <label>Bis <input v-model="filters.to" type="date"></label>
        <label>Aktion <input v-model="filters.action" type="text" placeholder="auth."></label>
        <label>
          Ergebnis
          <select v-model="filters.result">
            <option value="">alle</option>
            <option v-for="r in results" :key="r.value" :value="r.value">{{ r.label }}</option>
          </select>
        </label>
        <label>IP <input v-model="filters.ip" type="text"></label>
      </div>

      <table class="stapelt">
        <thead>
          <tr>
            <th>Zeitpunkt</th><th>Aktion</th><th>Ergebnis</th><th>Ziel</th><th>IP</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in events.data" :key="row.id">
            <td data-spalte="Zeitpunkt">{{ row.created_at }}</td>
            <td data-spalte="Aktion">{{ row.action }}</td>
            <td data-spalte="Ergebnis" :data-ergebnis="row.result">{{ row.result_label }}</td>
            <td data-spalte="Ziel">{{ row.target ?? '—' }}</td>
            <td data-spalte="IP">{{ row.ip_address ?? '—' }}</td>
          </tr>
          <tr v-if="events.data.length === 0">
            <td colspan="5">Keine Einträge für diese Auswahl.</td>
          </tr>
        </tbody>
      </table>
    </section>
  </PanelLayout>
</template>

<style scoped>
.protokoll { display: flex; flex-direction: column; gap: var(--gap); }
header { display: flex; justify-content: flex-end; }
.filter { display: flex; flex-wrap: wrap; gap: 12px; }
.filter label { display: flex; flex-direction: column; gap: 3px; font-size: var(--text-small); color: var(--text-muted); }
.filter input, .filter select {
  padding: 5px 6px; font: inherit; font-size: var(--text-input); color: var(--text);
  background: var(--bg); border: 1px solid var(--line); border-radius: 5px;
}

/*
 * Auf der schmalen Fläche stehen die Filter untereinander und über die volle
 * Breite. Nebeneinander mit `flex-wrap` ergäben sie auf 390px vier Zeilen mit
 * je einem angeschnittenen Feld — ein Datumsfeld ist im Browser breiter, als
 * es aussieht.
 */
@media (max-width: 720px) {
  .filter { flex-direction: column; gap: 10px; }
  .filter label { width: 100%; }
  .filter input, .filter select { width: 100%; min-height: var(--tap); }
}
table { width: 100%; border-collapse: collapse; font-size: var(--text-table); }
th { text-align: left; color: var(--text-muted); font-weight: 600; }
th, td { padding: 6px 8px; border-bottom: 1px solid var(--line); }
td[data-ergebnis='failure'] { color: var(--critical); }
td[data-ergebnis='denied'] { color: var(--warn); }
</style>
