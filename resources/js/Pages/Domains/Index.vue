<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import Badge from '../../Components/Badge.vue'
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

function rang(status: string): 'ok' | 'warn' | 'critical' | 'neutral' {
  if (status === 'active') return 'ok'
  if (status === 'provisioning' || status === 'removing') return 'warn'
  if (status === 'failed') return 'critical'

  return 'neutral'
}
</script>

<template>
  <Head title="Domains" />

  <PanelLayout title="Domains" :subline="`${props.domains.total} insgesamt`">
    <div class="scrolls">
      <table class="stacks">
        <thead>
          <tr>
            <th>Domain</th><th>Sorte</th><th>Abonnement</th><th>PHP</th><th>Zustand</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in props.domains.data" :key="row.id">
            <td data-column="Domain" class="ident name">
              <Link :href="`/domains/${row.id}`" class="link">{{ row.name }}</Link>
            </td>
            <td data-column="Sorte" class="quiet">{{ row.type_label }}</td>
            <td data-column="Abonnement">
              <Link :href="`/subscriptions/${row.subscription_id}`" class="link">
                {{ row.subscription ?? '—' }}
              </Link>
            </td>

            <!--
              Eine Weiterleitung hat keinen Handler und braucht keinen: nginx
              antwortet selbst. „—" wäre hier dieselbe Anzeige wie bei einer
              Domain, der die PHP-Version fehlt, und das sind zwei verschiedene
              Dinge.
            -->
            <td data-column="PHP">
              <template v-if="row.is_redirect">
                <span class="quiet">leitet weiter</span>
              </template>
              <template v-else>{{ row.php_version ?? '—' }}</template>
            </td>

            <td data-column="Zustand">
              <Badge :kind="rang(row.status)">{{ row.status_label }}</Badge>
            </td>
          </tr>
          <tr v-if="props.domains.data.length === 0">
            <td colspan="5" class="quiet">Noch keine Domain.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </PanelLayout>
</template>
