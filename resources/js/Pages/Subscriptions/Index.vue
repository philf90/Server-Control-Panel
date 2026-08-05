<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import Badge from '../../Components/Badge.vue'
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

function rang(status: string): 'ok' | 'warn' | 'critical' | 'neutral' {
  if (status === 'active') return 'ok'
  if (status === 'suspended') return 'warn'
  if (status === 'cancelled') return 'critical'

  return 'neutral'
}

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
    <template #actions>
      <Link href="/subscriptions/create" class="button primary">Abonnement anlegen</Link>
    </template>

    <div class="scrolls">
      <table class="stacks">
        <thead>
          <tr>
            <th>Name</th><th>Kunde</th><th>Plan</th><th>Systembenutzer</th>
            <th>Speicher</th><th>Zustand</th><th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in props.subscriptions" :key="row.id">
            <td data-column="Name" class="ident name">
              <Link :href="`/subscriptions/${row.id}`" class="link">{{ row.name }}</Link>
            </td>
            <td data-column="Kunde">{{ row.customer ?? '—' }}</td>
            <td data-column="Plan" class="quiet">{{ row.plan ?? '—' }}</td>
            <td data-column="Systembenutzer" class="ident quiet">{{ row.system_user ?? '—' }}</td>

            <!--
              Ab 90 Prozent trägt der Speicher eine Marke statt einer
              eingefärbten Zelle. Die Zahl steht darin — Farbe ist nicht der
              einzige Träger.
            -->
            <td data-column="Speicher">
              <Badge v-if="row.percent !== null && row.percent >= 90" kind="warn">
                {{ verbrauch(row) }}
              </Badge>
              <template v-else>{{ verbrauch(row) }}</template>
            </td>

            <td data-column="Zustand">
              <Badge :kind="rang(row.status)">{{ row.status_label }}</Badge>
            </td>

            <!--
              Der Knopf steht hier, obwohl der Name schon ein Link ist.

              Er war es zuerst allein, und das war ein Bruch zu den Kunden und
              den Plänen: Dort steht in jeder Zeile ein „Bearbeiten", hier
              musste man wissen, dass der Name klickbar ist und dass dahinter
              die Bearbeitung liegt. Wer eine Liste überfliegt, sucht einen
              Knopf und keinen Link.

              Der Name bleibt trotzdem ein Link: Er führt auf die Abo-Seite mit
              Speicher, Kontingenten und Vorgängen, und das ist etwas anderes
              als das Formular.
            -->
            <td>
              <Link :href="`/subscriptions/${row.id}/edit`" class="button small">Bearbeiten</Link>
            </td>
          </tr>
          <tr v-if="props.subscriptions.length === 0">
            <td colspan="7" class="quiet">
              Noch kein Abonnement. Es braucht einen Kunden und einen Plan —
              beide gibt es unter Verwaltung.
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </PanelLayout>
</template>
