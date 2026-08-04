<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import PanelLayout from '../../Layouts/PanelLayout.vue'

interface Row {
  id: number
  name: string
  type_label: string
  status: string
  status_label: string
  php_version: string | null
  is_redirect: boolean
  subscription: string | null
  subscription_id: number
}

const props = defineProps<{
  domains: { data: Row[]; total: number }
}>()
</script>

<template>
  <Head title="Domains" />

  <PanelLayout title="Domains" :subline="`${props.domains.total} insgesamt`">
    <div class="rollt">
      <table class="stapelt">
        <thead>
          <tr>
            <th>Domain</th><th>Sorte</th><th>Abonnement</th><th>PHP</th><th>Zustand</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in props.domains.data" :key="row.id">
            <td data-spalte="Domain"><Link :href="`/domains/${row.id}`">{{ row.name }}</Link></td>
            <td data-spalte="Sorte">{{ row.type_label }}</td>
            <td data-spalte="Abonnement">
              <Link :href="`/subscriptions/${row.subscription_id}`">{{ row.subscription ?? '—' }}</Link>
            </td>
            <!--
              Eine Weiterleitung hat keinen Handler und braucht keinen: nginx
              antwortet selbst. „—" wäre hier dieselbe Anzeige wie bei einer
              Domain, der die PHP-Version fehlt, und das sind zwei verschiedene
              Dinge.
            -->
            <td data-spalte="PHP">
              <template v-if="row.is_redirect">leitet weiter</template>
              <template v-else>{{ row.php_version ?? '—' }}</template>
            </td>
            <td data-spalte="Zustand" :data-status="row.status">{{ row.status_label }}</td>
          </tr>
          <tr v-if="props.domains.data.length === 0">
            <td colspan="5">Noch keine Domain.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </PanelLayout>
</template>

<style scoped>
table { width: 100%; border-collapse: collapse; font-size: var(--text-table); }
th { text-align: left; color: var(--text-muted); font-weight: 600; }
th, td { padding: 6px 8px; border-bottom: 1px solid var(--line); }
td[data-status='suspended'] { color: var(--warn); }
td[data-status='provisioning'], td[data-status='removing'] { color: var(--accent); }
</style>
