<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import Marke from '../../Components/Marke.vue'
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

function rang(status: string): 'ok' | 'warn' | 'kritisch' | 'neutral' {
  if (status === 'active') return 'ok'
  if (status === 'provisioning' || status === 'removing') return 'warn'
  if (status === 'failed') return 'kritisch'

  return 'neutral'
}
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
            <td data-spalte="Domain" class="kennung name">
              <Link :href="`/domains/${row.id}`" class="verweis">{{ row.name }}</Link>
            </td>
            <td data-spalte="Sorte" class="stumm">{{ row.type_label }}</td>
            <td data-spalte="Abonnement">
              <Link :href="`/subscriptions/${row.subscription_id}`" class="verweis">
                {{ row.subscription ?? '—' }}
              </Link>
            </td>

            <!--
              Eine Weiterleitung hat keinen Handler und braucht keinen: nginx
              antwortet selbst. „—" wäre hier dieselbe Anzeige wie bei einer
              Domain, der die PHP-Version fehlt, und das sind zwei verschiedene
              Dinge.
            -->
            <td data-spalte="PHP">
              <template v-if="row.is_redirect">
                <span class="stumm">leitet weiter</span>
              </template>
              <template v-else>{{ row.php_version ?? '—' }}</template>
            </td>

            <td data-spalte="Zustand">
              <Marke :art="rang(row.status)">{{ row.status_label }}</Marke>
            </td>
          </tr>
          <tr v-if="props.domains.data.length === 0">
            <td colspan="5" class="stumm">Noch keine Domain.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </PanelLayout>
</template>
