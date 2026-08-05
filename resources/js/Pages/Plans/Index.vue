<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import Marke from '../../Components/Marke.vue'
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
</script>

<template>
  <Head title="Pläne" />

  <PanelLayout title="Pläne" :subline="`${props.plans.length} angelegt`">
    <template #aktion>
      <Link href="/plans/create" class="knopf wichtig">Plan anlegen</Link>
    </template>

    <div class="rollt">
      <table class="stapelt">
        <thead>
          <tr>
            <th>Name</th>
            <th v-for="spalte in props.plans[0]?.summary ?? []" :key="spalte.label" class="rechts">
              {{ spalte.label }}
            </th>
            <th>Freigaben</th>

            <!--
              Die Zahl der gebundenen Abonnements steht in der Liste und nicht
              erst im Formular. Sie ist die eine Angabe, die entscheidet, wie
              gefährlich eine Änderung an diesem Plan ist — und wer sie erst
              sieht, nachdem er auf „Bearbeiten" geklickt hat, hat sich die
              Frage vorher nicht gestellt.
            -->
            <th class="rechts">Abos</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in props.plans" :key="row.id">
            <td data-spalte="Plan" class="mehrzeilig">
              <span class="titelzeile">
                <Link :href="`/plans/${row.id}/edit`" class="verweis">{{ row.name }}</Link>
                <Marke v-if="row.is_default" art="neutral">Standard</Marke>
              </span>
              <p v-if="row.description" class="beschreibung">{{ row.description }}</p>
            </td>

            <!--
              Die Beschriftung kommt hier aus den Daten und nicht aus dem
              Quelltext: Welche Kontingente in der Liste stehen, entscheidet der
              Katalog. Ein festes `data-spalte` wäre die vierte Stelle, an der
              dieselbe Beschriftung stünde.
            -->
            <td
              v-for="spalte in row.summary"
              :key="spalte.label"
              :data-spalte="spalte.label"
              class="rechts"
            >
              {{ spalte.value }}
            </td>

            <td data-spalte="Freigaben" class="stumm">
              {{ row.features.length > 0 ? row.features.join(', ') : 'keine' }}
            </td>
            <td data-spalte="Abos" class="rechts">{{ row.subscriptions }}</td>
            <td><Link :href="`/plans/${row.id}/edit`" class="knopf klein">Bearbeiten</Link></td>
          </tr>
          <tr v-if="props.plans.length === 0">
            <td colspan="6" class="stumm">
              Noch kein Plan angelegt. Ohne Plan lässt sich kein Abonnement
              anlegen — er trägt dessen Kontingente.
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </PanelLayout>
</template>

<style scoped>
/* Name und Standardmarke in einer Zeile, die Beschreibung darunter. Die Zelle
   trägt `.mehrzeilig`, damit sie auf der schmalen Fläche nicht an den rechten
   Rand rutscht (docs/24 §5). */
.titelzeile {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.beschreibung {
  margin: 3px 0 0;
  font-size: var(--text-small);
  color: var(--text-muted);
}
</style>
