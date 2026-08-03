<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import PanelLayout from '../../Layouts/PanelLayout.vue'

interface Row {
  id: number
  name: string
  description: string | null
  is_default: boolean
  subscriptions: number
  summary: { label: string; value: string }[]
  features: string[]
}

const props = defineProps<{ plans: Row[] }>()


/*
 * Die Zahl der gebundenen Abonnements steht in der Liste und nicht erst im
 * Formular. Sie ist die eine Angabe, die entscheidet, wie gefährlich eine
 * Änderung an diesem Plan ist — und wer sie erst sieht, nachdem er auf
 * „Bearbeiten" geklickt hat, hat sich die Frage vorher nicht gestellt.
 */
</script>

<template>
  <Head title="Pläne" />

  <PanelLayout title="Pläne" :subline="`${props.plans.length} angelegt`">

    <header class="kopf">
      <Link href="/plans/create" class="knopf wichtig">Plan anlegen</Link>
    </header>

    <table class="stapelt">
      <thead>
        <tr>
          <th>Name</th>
          <th v-for="spalte in props.plans[0]?.summary ?? []" :key="spalte.label">{{ spalte.label }}</th>
          <th>Freigaben</th>
          <th>Abos</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in props.plans" :key="row.id">
          <td data-spalte="Plan" class="mehrzeilig">
            <Link :href="`/plans/${row.id}/edit`">{{ row.name }}</Link>
            <span v-if="row.is_default" class="marke">Standard</span>
            <p v-if="row.description" class="beschreibung">{{ row.description }}</p>
          </td>
          <!--
            Die Beschriftung kommt hier aus den Daten und nicht aus dem
            Quelltext: Welche drei Kontingente in der Liste stehen, entscheidet
            der Katalog. Ein festes `data-spalte` wäre die vierte Stelle, an
            der dieselbe Beschriftung stünde.
          -->
          <td v-for="spalte in row.summary" :key="spalte.label" :data-spalte="spalte.label" class="zahl">{{ spalte.value }}</td>
          <td data-spalte="Freigaben" class="freigaben">{{ row.features.length > 0 ? row.features.join(', ') : 'keine' }}</td>
          <td data-spalte="Abos" class="zahl">{{ row.subscriptions }}</td>
          <td><Link :href="`/plans/${row.id}/edit`" class="knopf klein">Bearbeiten</Link></td>
        </tr>
        <tr v-if="props.plans.length === 0">
          <td colspan="6">
            Noch kein Plan angelegt. Ohne Plan lässt sich kein Abonnement anlegen —
            er trägt dessen Kontingente.
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
th, td { padding: 6px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
.marke { margin-left: 6px; padding: 1px 5px; font-size: var(--text-label); color: var(--accent); background: var(--accent-surface); border-radius: 3px; }
.beschreibung { margin: 2px 0 0; font-size: var(--text-small); color: var(--text-faint); }
.freigaben { color: var(--text-muted); }
</style>
