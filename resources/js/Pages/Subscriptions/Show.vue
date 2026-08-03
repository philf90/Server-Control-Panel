<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import PanelLayout from '../../Layouts/PanelLayout.vue'

const props = defineProps<{
  subscription: {
    id: number
    name: string
    customer: string | null
    customer_id: number
    plan: string | null
    system_user: string | null
    root: string
    status: string
    status_label: string
    suspended_at: string | null
  }
  quotas: { key: string; label: string; value: string; differs: boolean }[]
  features: { label: string; granted: boolean }[]
  operations: { id: number; task: string | null; status_label: string; created_at: string | null }[]
}>()

function suspend(): void {
  if (!window.confirm(`${props.subscription.name} sperren? Webseiten und Zugänge sind danach aus, die Daten bleiben.`)) return
  router.post(`/subscriptions/${props.subscription.id}/suspend`)
}

function resume(): void {
  router.post(`/subscriptions/${props.subscription.id}/resume`)
}

/*
 * Zwei Rückfragen und nicht eine.
 *
 * Der Rückbau löscht als root einen Verzeichnisbaum, und es gibt noch keine
 * Sicherungen. Ein einzelnes „Wirklich?" beantwortet man im Vorbeigehen; den
 * Namen abzutippen ist die kleinste Hürde, die eine bewusste Handlung
 * verlangt.
 */
function remove(): void {
  const eingabe = window.prompt(
    `Rückbau von ${props.subscription.name}: Verzeichnis, Systembenutzer und Quota werden entfernt. `
    + 'Es gibt keine Sicherung. Zum Bestätigen den Namen eintippen:',
  )

  if (eingabe !== props.subscription.name) return

  router.delete(`/subscriptions/${props.subscription.id}`)
}
</script>

<template>
  <Head :title="props.subscription.name" />

  <PanelLayout :title="props.subscription.name" :subline="props.subscription.status_label">
    <section class="block">
      <h2 class="section">Stammdaten</h2>
      <dl>
        <dt>Kunde</dt>
        <dd><Link :href="`/customers/${props.subscription.customer_id}`">{{ props.subscription.customer ?? '—' }}</Link></dd>
        <dt>Plan</dt>
        <dd>{{ props.subscription.plan ?? '—' }}</dd>
        <dt>Systembenutzer</dt>
        <dd class="fest">{{ props.subscription.system_user ?? '—' }}</dd>
        <dt>Verzeichnis</dt>
        <dd class="fest">{{ props.subscription.root }}</dd>
        <dt v-if="props.subscription.suspended_at">Gesperrt seit</dt>
        <dd v-if="props.subscription.suspended_at">{{ props.subscription.suspended_at }}</dd>
      </dl>

      <div class="aktionen">
        <button v-if="props.subscription.status === 'active'" type="button" @click="suspend">Sperren</button>
        <button v-if="props.subscription.status === 'suspended'" type="button" @click="resume">Entsperren</button>
        <button
          v-if="props.subscription.status !== 'provisioning'"
          type="button"
          class="rueckbau"
          @click="remove"
        >
          Zurückbauen
        </button>
      </div>
    </section>

    <section class="block">
      <h2 class="section">Kontingente</h2>
      <div class="rollt">
        <table>
          <thead>
            <tr><th>Kontingent</th><th>Stand</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="q in props.quotas" :key="q.key">
              <td>{{ q.label }}</td>
              <td class="zahl">{{ q.value }}</td>
              <td><span v-if="q.differs" class="marke">abweichend vom Plan</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="block">
      <h2 class="section">Freigaben</h2>
      <ul class="freigaben">
        <li v-for="f in props.features" :key="f.label" :data-frei="f.granted">
          {{ f.granted ? '✓' : '✗' }} {{ f.label }}
        </li>
      </ul>
    </section>

    <section class="block">
      <h2 class="section">Vorgänge</h2>
      <table class="stapelt">
        <thead>
          <tr><th>Nummer</th><th>Aufgabe</th><th>Zustand</th><th>Angelegt</th></tr>
        </thead>
        <tbody>
          <tr v-for="o in props.operations" :key="o.id">
            <td data-spalte="Nummer"><Link :href="`/operations/${o.id}`">{{ o.id }}</Link></td>
            <td data-spalte="Aufgabe" class="fest">{{ o.task ?? '—' }}</td>
            <td data-spalte="Zustand">{{ o.status_label }}</td>
            <td data-spalte="Angelegt">{{ o.created_at ?? '—' }}</td>
          </tr>
          <tr v-if="props.operations.length === 0">
            <td colspan="4">Noch kein Vorgang für dieses Abonnement.</td>
          </tr>
        </tbody>
      </table>
    </section>
  </PanelLayout>
</template>

<style scoped>
.block { margin-top: var(--block-gap); }
.block:first-child { margin-top: 0; }
.section { font-size: var(--block-heading-size); font-weight: 600; letter-spacing: -0.01em; color: var(--text-strong); margin: 0 0 var(--block-heading-gap); }
dl { display: grid; grid-template-columns: auto 1fr; gap: 4px 14px; margin: 0; font-size: var(--text-table); }
dt { color: var(--text-muted); }
dd { margin: 0; color: var(--text); }
.fest { font-family: var(--font-mono); }
.aktionen { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
.aktionen button { padding: 6px 12px; font: inherit; font-size: var(--text-table); min-height: var(--tap); color: var(--text); background: transparent; border: 1px solid var(--line); border-radius: 6px; cursor: pointer; }
.aktionen .rueckbau { color: var(--critical); border-color: var(--critical); }
table { width: 100%; border-collapse: collapse; font-size: var(--text-table); }
th { text-align: left; color: var(--text-muted); font-weight: 600; }
th, td { padding: 6px 8px; border-bottom: 1px solid var(--line); }
.marke { padding: 1px 5px; font-size: var(--text-label); color: var(--warn); background: var(--warn-surface); border-radius: 3px; }
.freigaben { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 3px; font-size: var(--text-table); }
.freigaben li[data-frei='true'] { color: var(--ok); }
.freigaben li[data-frei='false'] { color: var(--text-faint); }
</style>
