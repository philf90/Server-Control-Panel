<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import Badge from '../../Components/Badge.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Pager from '../../Components/Pager.vue'

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

  /* Was der Betrachter mit dieser Zeile tun darf — vom Server entschieden. */
  can: { update: boolean }
}

const props = defineProps<{
  subscriptions: { data: Row[]; current_page: number; last_page: number; total: number }

  /**
   * Was der Betrachter auf dieser Seite tun darf.
   *
   * **Warum das vom Server kommt.** Ein Kunde sah „Abonnement anlegen" und in
   * jeder Zeile „Bearbeiten"; beides endete mit einem 403. Ein `v-if` auf den
   * Kontotyp wäre hier eine zweite Fassung der Policy — und die zweite Fassung
   * ist die, die veraltet. Der Server entscheidet, die Seite zeichnet.
   */
  can: { create: boolean }
}>()

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

  <PanelLayout title="Abonnements" :subline="`${props.subscriptions.total} angelegt`">
    <template #actions>
      <Link v-if="props.can.create" href="/subscriptions/create" class="button primary">
        Abonnement anlegen
      </Link>
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
          <tr v-for="row in props.subscriptions.data" :key="row.id">
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
            <!--
              Die Zelle bleibt auch ohne Knopf stehen: Ohne sie hätte die Zeile
              eine Spalte weniger als der Kopf, und auf der schmalen Fläche
              rutschte die Zuordnung von `data-column` um eins.
            -->
            <td>
              <Link
                v-if="row.can.update"
                :href="`/subscriptions/${row.id}/edit`"
                class="button small"
              >Bearbeiten</Link>
            </td>
          </tr>
          <tr v-if="props.subscriptions.data.length === 0">
            <td colspan="7" class="quiet">
              Noch kein Abonnement. Es braucht einen Kunden und einen Plan —
              beide gibt es unter Verwaltung.
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Pager :page="props.subscriptions.current_page" :pages="props.subscriptions.last_page" />
  </PanelLayout>
</template>
