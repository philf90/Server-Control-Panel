<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import PanelLayout from '../../Layouts/PanelLayout.vue'

interface Row {
  id: number
  name: string
  customer: string | null
  plan: string | null
  system_user: string | null
  status: string
  status_label: string
}

const props = defineProps<{ subscriptions: Row[] }>()
</script>

<template>
  <Head title="Abonnements" />

  <PanelLayout title="Abonnements" :subline="`${props.subscriptions.length} angelegt`">
    <header class="kopf">
      <Link href="/subscriptions/create" class="knopf wichtig">Abonnement anlegen</Link>
    </header>

    <table class="stapelt">
      <thead>
        <tr><th>Name</th><th>Kunde</th><th>Plan</th><th>Systembenutzer</th><th>Zustand</th></tr>
      </thead>
      <tbody>
        <tr v-for="row in props.subscriptions" :key="row.id">
          <td data-spalte="Name"><Link :href="`/subscriptions/${row.id}`">{{ row.name }}</Link></td>
          <td data-spalte="Kunde">{{ row.customer ?? '—' }}</td>
          <td data-spalte="Plan">{{ row.plan ?? '—' }}</td>
          <td data-spalte="Systembenutzer" class="benutzer">{{ row.system_user ?? '—' }}</td>
          <td data-spalte="Zustand" :data-status="row.status">{{ row.status_label }}</td>
        </tr>
        <tr v-if="props.subscriptions.length === 0">
          <td colspan="5">
            Noch kein Abonnement. Es braucht einen Kunden und einen Plan — beide
            gibt es unter Verwaltung.
          </td>
        </tr>
      </tbody>
    </table>
  </PanelLayout>
</template>

<style scoped>
.kopf { display: flex; justify-content: flex-end; margin-bottom: var(--gap); }
table { width: 100%; border-collapse: collapse; font-size: var(--text-table); }
th { text-align: left; color: var(--text-muted); font-weight: 600; }
th, td { padding: 6px 8px; border-bottom: 1px solid var(--line); }
.benutzer { font-family: var(--font-mono); }
td[data-status='suspended'] { color: var(--warn); }
td[data-status='provisioning'] { color: var(--text-faint); }
td[data-status='cancelled'] { color: var(--critical); }
</style>
