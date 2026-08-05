<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import Marke from '../../Components/Marke.vue'
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

function rang(status: string): 'ok' | 'warn' | 'kritisch' | 'neutral' {
  if (status === 'active') return 'ok'
  if (status === 'suspended') return 'warn'
  if (status === 'cancelled') return 'kritisch'

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
    <template #aktion>
      <Link href="/subscriptions/create" class="knopf wichtig">Abonnement anlegen</Link>
    </template>

    <div class="rollt">
      <table class="stapelt">
        <thead>
          <tr>
            <th>Name</th><th>Kunde</th><th>Plan</th><th>Systembenutzer</th>
            <th>Speicher</th><th>Zustand</th><th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in props.subscriptions" :key="row.id">
            <td data-spalte="Name" class="kennung name">
              <Link :href="`/subscriptions/${row.id}`" class="verweis">{{ row.name }}</Link>
            </td>
            <td data-spalte="Kunde">{{ row.customer ?? '—' }}</td>
            <td data-spalte="Plan" class="stumm">{{ row.plan ?? '—' }}</td>
            <td data-spalte="Systembenutzer" class="kennung stumm">{{ row.system_user ?? '—' }}</td>

            <!--
              Ab 90 Prozent trägt der Speicher eine Marke statt einer
              eingefärbten Zelle. Die Zahl steht darin — Farbe ist nicht der
              einzige Träger.
            -->
            <td data-spalte="Speicher">
              <Marke v-if="row.percent !== null && row.percent >= 90" art="warn">
                {{ verbrauch(row) }}
              </Marke>
              <template v-else>{{ verbrauch(row) }}</template>
            </td>

            <td data-spalte="Zustand">
              <Marke :art="rang(row.status)">{{ row.status_label }}</Marke>
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
              <Link :href="`/subscriptions/${row.id}/edit`" class="knopf klein">Bearbeiten</Link>
            </td>
          </tr>
          <tr v-if="props.subscriptions.length === 0">
            <td colspan="7" class="stumm">
              Noch kein Abonnement. Es braucht einen Kunden und einen Plan —
              beide gibt es unter Verwaltung.
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </PanelLayout>
</template>
