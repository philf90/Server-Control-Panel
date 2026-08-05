<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import Marke from '../../Components/Marke.vue'
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

/*
 * Der Zustand als Rang und nicht als Farbe an der Zelle.
 *
 * Vorher stand `td[data-status='suspended'] { color: var(--warn) }` auf jeder
 * Liste einzeln — vier Seiten, vier Fassungen, und in einer davon fehlte der
 * Zustand „zurückgezogen" ganz. Der Rang steht jetzt an einer Stelle, und die
 * Marke bringt neben der Farbe ein Wort mit.
 */
function rang(status: string): 'ok' | 'warn' | 'kritisch' | 'neutral' {
  if (status === 'active') return 'ok'
  if (status === 'suspended') return 'warn'
  if (status === 'cancelled') return 'kritisch'

  return 'neutral'
}

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
    <template #aktion>
      <Link href="/customers/create" class="knopf wichtig">Kunde anlegen</Link>
    </template>

    <!--
      Kein Bereich mit eigener Überschrift: Diese Seite *ist* das Verzeichnis,
      und „Kunden → Verzeichnis" wäre dieselbe Angabe zweimal. Ein Bereich
      lohnt sich, wo mehrere Listen auf einer Seite stehen.
    -->
    <div class="rollt">
      <table class="stapelt">
        <thead>
          <tr>
            <th>Nummer</th><th>Name</th><th>E-Mail</th>
            <th class="rechts">Abos</th><th>Zustand</th><th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in props.customers.data" :key="row.id">
            <td data-spalte="Nummer" class="kennung">
              <Link :href="`/customers/${row.id}`" class="verweis">{{ row.number }}</Link>
            </td>
            <td data-spalte="Name" class="name">{{ row.name }}</td>
            <td data-spalte="E-Mail" class="stumm">{{ row.email }}</td>
            <td data-spalte="Abos" class="rechts">{{ row.subscriptions }}</td>
            <td data-spalte="Zustand">
              <Marke :art="rang(row.status)">{{ row.status_label }}</Marke>
            </td>
            <td>
              <div class="knopfreihe">
                <Link :href="`/customers/${row.id}/edit`" class="knopf klein">Bearbeiten</Link>
                <button
                  v-if="row.accounts > 0"
                  type="button"
                  class="knopf klein"
                  @click="impersonate(row)"
                >
                  Anmelden als
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="props.customers.data.length === 0">
            <td colspan="6" class="stumm">Noch kein Kunde angelegt.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </PanelLayout>
</template>
