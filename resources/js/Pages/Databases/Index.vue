<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import Badge from '../../Components/Badge.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Pager from '../../Components/Pager.vue'
import { formatBytes } from '../../bytes'

interface User {
  id: number
  name: string
  host: string
  remote: boolean
  status: string
  status_label: string
}

interface Row {
  id: number
  name: string
  label: string
  status: string
  status_label: string
  engine: string
  engine_label: string
  collation: string
  size_bytes: number | null
  size_measured_at: string | null
  subscription: string | null
  subscription_id: number | null
  orphaned: boolean
  users: User[]
}

const props = defineProps<{
  databases: { data: Row[]; current_page: number; last_page: number; total: number }

  /**
   * Die Abonnements, in denen der Betrachter eine Datenbank anlegen darf.
   *
   * Leer beim Betreiber — dieselbe Begründung wie bei den Domains: Die
   * Abkürzung führt in ein bestimmtes Abonnement, und er hat davon Hunderte.
   */
  creatable: { id: number; name: string }[]

}>()

const chosen = ref<number | null>(props.creatable[0]?.id ?? null)



function createDatabase(): void {
  if (chosen.value !== null) router.visit(`/subscriptions/${chosen.value}/databases/create`)
}

function rang(status: string): 'ok' | 'warn' | 'critical' | 'neutral' {
  if (status === 'active') return 'ok'
  if (status === 'provisioning' || status === 'removing') return 'warn'

  return 'neutral'
}

/*
 * „noch nie gemessen" ist etwas anderes als „0 MB" (docs/26 §8, docs/36 §9).
 *
 * Die Unterscheidung steht hier und nicht im Server, weil der Server beides
 * ehrlich liefert: `null` und `0`. Sie hier zusammenfallen zu lassen wäre die
 * Stelle, an der aus einer fehlenden Messung eine leere Datenbank wird.
 */
function size(row: Row): string {
  if (row.size_bytes === null) return 'nicht gemessen'

  return formatBytes(row.size_bytes)
}
</script>

<template>
  <Head title="Datenbanken" />

  <PanelLayout title="Datenbanken" :subline="`${props.databases.total} insgesamt`">
    <template #actions>
      <Link
        v-if="props.creatable.length === 1"
        :href="`/subscriptions/${props.creatable[0].id}/databases/create`"
        class="button primary"
      >
        Datenbank anlegen
      </Link>

      <!-- Mehrere Abonnements: erst wohin, dann anlegen. Die Beschriftung steht
           sichtbar dabei und nicht nur als `aria-label` — der Befund vom
           7. August 2026 an derselben Stelle in Domains/Index. -->
      <form v-else-if="props.creatable.length > 1" class="button-row" @submit.prevent="createDatabase">
        <label class="field inline">
          <span>Abonnement</span>
          <select v-model="chosen">
            <option v-for="s in props.creatable" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <button type="submit" class="button primary">Datenbank anlegen</button>
      </form>
    </template>

    <div class="scrolls">
      <table class="stacks">
        <thead>
          <tr>
            <th>Datenbank</th>
            <!--
              **Die Spalte steht immer da, auch wenn nur ein System benutzt
              wird.** Bis rc.4 hing sie an `shows_engine`: einer Frage an den
              Bestand, die der Server beantwortete. Die Spalte kam damit erst
              dazu, wenn die erste PostgreSQL-Datenbank entstand — und sie
              verschwände wieder, wenn die letzte gelöscht wird.

              **Eine Tabelle, deren Spalten vom Inhalt abhängen, ist zweimal
              dieselbe Tabelle.** Wer sie kennt, muss sie neu lesen; wer eine
              Aufnahme davon hat, hat eine von zweien. Der Betreiber hat das
              beim Testlauf zu rc.4 entschieden.
            -->
            <th>System</th>
            <th>Abonnement</th><th>Zugänge</th><th>Belegt</th><th>Zustand</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in props.databases.data" :key="row.id">
            <td data-column="Datenbank" class="ident name">
              <Link :href="`/databases/${row.id}`" class="link">{{ row.name }}</Link>
            </td>

            <td data-column="System">
              <Badge kind="neutral">{{ row.engine_label }}</Badge>
            </td>

            <td data-column="Abonnement">
              <!-- Eine verwaiste Datenbank hat kein Abonnement mehr, aber einen
                   abgeschriebenen Namen (docs/36 §5). Ihn wegzulassen hiesse,
                   dass niemand mehr sagen kann, wessen Daten dort liegen. -->
              <Link v-if="row.subscription_id !== null" :href="`/subscriptions/${row.subscription_id}`" class="link">
                {{ row.subscription }}
              </Link>
              <span v-else class="quiet">{{ row.subscription ?? '—' }} (zurückgebaut)</span>
            </td>

            <td data-column="Zugänge">
              <span v-if="row.users.length === 0" class="quiet">keiner</span>
              <span v-else class="ident">{{ row.users.length }}</span>
            </td>

            <td data-column="Belegt" :class="row.size_bytes === null ? 'quiet' : ''">{{ size(row) }}</td>

            <td data-column="Zustand">
              <Badge :kind="rang(row.status)">{{ row.status_label }}</Badge>
            </td>
          </tr>
          <tr v-if="props.databases.data.length === 0">
            <td colspan="6" class="quiet">Noch keine Datenbank.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <Pager :page="props.databases.current_page" :pages="props.databases.last_page" />
  </PanelLayout>
</template>
