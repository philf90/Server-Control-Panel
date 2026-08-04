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
  used_mb: number | null
  percent: number | null
}

const props = defineProps<{ subscriptions: Row[] }>()

/*
 * Der Verbrauch als Text, an einer Stelle.
 *
 * `null` heisst „noch nicht gemessen" und nicht „null Byte" — das eine ist
 * eine fehlende Auskunft, das andere eine. Ein leeres Feld sähe für beides
 * gleich aus, und die Liste ist genau der Ort, an dem man den Unterschied
 * braucht: Ein Server, auf dem seit Tagen niemand misst, fällt sonst nicht auf.
 */
function verbrauch(row: Row): string {
  if (row.used_mb === null) return 'nicht gemessen'

  const wert = row.used_mb.toLocaleString('de-DE')

  return row.percent === null ? `${wert} MB` : `${wert} MB · ${row.percent} %`
}
</script>

<template>
  <Head title="Abonnements" />

  <PanelLayout title="Abonnements" :subline="`${props.subscriptions.length} angelegt`">
    <header class="kopf">
      <Link href="/subscriptions/create" class="knopf wichtig">Abonnement anlegen</Link>
    </header>

    <table class="stapelt">
      <thead>
        <tr><th>Name</th><th>Kunde</th><th>Plan</th><th>Systembenutzer</th><th>Speicher</th><th>Zustand</th></tr>
      </thead>
      <tbody>
        <tr v-for="row in props.subscriptions" :key="row.id">
          <td data-spalte="Name"><Link :href="`/subscriptions/${row.id}`">{{ row.name }}</Link></td>
          <td data-spalte="Kunde">{{ row.customer ?? '—' }}</td>
          <td data-spalte="Plan">{{ row.plan ?? '—' }}</td>
          <td data-spalte="Systembenutzer" class="benutzer">{{ row.system_user ?? '—' }}</td>
          <td data-spalte="Speicher" class="zahl" :data-voll="row.percent !== null && row.percent >= 90">
            {{ verbrauch(row) }}
          </td>
          <td data-spalte="Zustand" :data-status="row.status">{{ row.status_label }}</td>
        </tr>
        <tr v-if="props.subscriptions.length === 0">
          <td colspan="6">
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
.benutzer, .zahl { font-family: var(--font-mono); font-variant-numeric: tabular-nums; }

/* Ab 90 Prozent gefärbt — und daneben steht die Zahl. Farbe ist hier nicht
   der einzige Träger (§7.2). */
td[data-voll='true'] { color: var(--warn); }
td[data-status='suspended'] { color: var(--warn); }
td[data-status='provisioning'] { color: var(--text-faint); }
td[data-status='cancelled'] { color: var(--critical); }
</style>
