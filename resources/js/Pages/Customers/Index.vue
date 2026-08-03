<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

interface Row {
  id: number
  number: string
  name: string
  email: string
  status: string
  status_label: string
  subscriptions: number
  accounts: number
}

const props = defineProps<{ customers: { data: Row[]; total: number } }>()

const page = usePage()
const success = computed(() => (page.props.flash as Record<string, string> | undefined)?.success)

function impersonate(row: Row): void {
  // Bestätigung vor dem Wechsel: Er ändert, in wessen Namen jede folgende
  // Aktion im Protokoll steht.
  if (!window.confirm(`In die Sicht von ${row.name} wechseln?`)) return
  router.post(`/customers/${row.id}/impersonate`)
}
</script>

<template>
  <Head title="Kunden" />

  <PanelLayout title="Kunden" :subline="`${props.customers.total} angelegt`">
    <p v-if="success" class="erfolg">{{ success }}</p>

    <header class="kopf">
      <Link href="/customers/create" class="anlegen">Kunde anlegen</Link>
    </header>

    <table>
      <thead>
        <tr><th>Nummer</th><th>Name</th><th>E-Mail</th><th>Abos</th><th>Zustand</th><th></th></tr>
      </thead>
      <tbody>
        <tr v-for="row in props.customers.data" :key="row.id">
          <td><Link :href="`/customers/${row.id}`">{{ row.number }}</Link></td>
          <td>{{ row.name }}</td>
          <td>{{ row.email }}</td>
          <td>{{ row.subscriptions }}</td>
          <td :data-status="row.status">{{ row.status_label }}</td>
          <td>
            <button v-if="row.accounts > 0" type="button" @click="impersonate(row)">Anmelden als</button>
          </td>
        </tr>
        <tr v-if="props.customers.data.length === 0">
          <td colspan="6">Noch kein Kunde angelegt.</td>
        </tr>
      </tbody>
    </table>
  </PanelLayout>
</template>

<style scoped>
.erfolg { padding: 8px 11px; font-size: var(--text-table); color: var(--ok); background: var(--ok-surface); border-radius: 6px; }
.kopf { display: flex; justify-content: flex-end; margin-bottom: var(--gap); }
.anlegen { font-size: var(--text-table); color: var(--accent); }
table { width: 100%; border-collapse: collapse; font-size: var(--text-table); }
th { text-align: left; color: var(--text-muted); font-weight: 600; }
th, td { padding: 6px 8px; border-bottom: 1px solid var(--line); }
td[data-status='suspended'] { color: var(--warn); }
button { padding: 3px 8px; font: inherit; font-size: var(--text-small); color: var(--text); background: transparent; border: 1px solid var(--line); border-radius: 5px; cursor: pointer; }
</style>
