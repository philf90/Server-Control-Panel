<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import PanelLayout from '../../Layouts/PanelLayout.vue'

const props = defineProps<{
  customers: { id: number; label: string; suspended: boolean }[]
  plans: { id: number; label: string; is_default: boolean }[]
  nextUser: string
}>()

/*
 * Der Systembenutzer steht im Formular, ist aber kein Feld.
 *
 * Er wird beim Anlegen vergeben — wie die Kundennummer, und aus einem
 * schärferen Grund: An ihm hängt eine UID auf dem Dateisystem. Wäre er frei
 * wählbar, liesse sich über ihn ein bestehendes Konto berühren; der Agent
 * weist das ab, aber es hat im Panel nichts zu suchen.
 */
/*
 * Der Vorschlag überspringt gesperrte Kunden: Stünde einer oben im Feld,
 * bekäme man beim Absenden eine Fehlermeldung über eine Auswahl, die man nie
 * getroffen hat.
 */
const form = useForm({
  customer_id: props.customers.find((c) => !c.suspended)?.id ?? null,
  plan_id: props.plans.find((p) => p.is_default)?.id ?? props.plans[0]?.id ?? null,
  name: '',
})
</script>

<template>
  <Head title="Abonnement anlegen" />

  <PanelLayout title="Abonnement anlegen" subline="Systembenutzer, Verzeichnis und Quota entstehen als Vorgang">
    <p v-if="props.customers.length === 0 || props.plans.length === 0" class="hinweis-block">
      Es braucht mindestens einen Kunden und einen Plan. Beide finden sich unter Verwaltung.
    </p>

    <form class="maske" @submit.prevent="form.post('/subscriptions')">
      <fieldset>
        <legend>Zuordnung</legend>

        <label>Kunde
          <select v-model="form.customer_id" required>
            <option
              v-for="c in props.customers"
              :key="c.id"
              :value="c.id"
              :disabled="c.suspended"
            >
              {{ c.label }}{{ c.suspended ? ' · gesperrt' : '' }}
            </option>
          </select>
          <small v-if="form.errors.customer_id" class="fehler">{{ form.errors.customer_id }}</small>
        </label>

        <label>Plan
          <select v-model="form.plan_id" required>
            <option v-for="p in props.plans" :key="p.id" :value="p.id">
              {{ p.label }}{{ p.is_default ? ' (Standard)' : '' }}
            </option>
          </select>
          <small v-if="form.errors.plan_id" class="fehler">{{ form.errors.plan_id }}</small>
        </label>
      </fieldset>

      <fieldset>
        <legend>Abonnement</legend>

        <label>Name
          <input v-model="form.name" type="text" placeholder="kunde-example.de" autocomplete="off" required>
          <small v-if="form.errors.name" class="fehler">{{ form.errors.name }}</small>
          <small class="hinweis">
            Wird zum Verzeichnis <code>/var/www/vhosts/&lt;name&gt;</code>. Kleinbuchstaben,
            Ziffern, Punkt und Bindestrich; Anfang und Ende alphanumerisch.
          </small>
        </label>

        <label>Systembenutzer
          <input :value="props.nextUser" type="text" readonly tabindex="-1">
          <small class="hinweis">
            Wird beim Anlegen vergeben. Er bleibt auch nach einem Rückbau verbraucht —
            sonst erbte ein späteres Abonnement seine UID.
          </small>
        </label>
      </fieldset>

      <button type="submit" class="knopf wichtig" :disabled="form.processing || props.plans.length === 0">
        {{ form.processing ? 'Wird eingereiht …' : 'Anlegen' }}
      </button>
    </form>
  </PanelLayout>
</template>

<style scoped>
.hinweis-block { max-width: 544px; margin: 0 0 var(--gap); padding: 8px 11px; font-size: var(--text-table); color: var(--warn); background: var(--warn-surface); border-radius: 6px; }
.maske { display: flex; flex-direction: column; gap: var(--gap); max-width: 544px; }
fieldset { display: flex; flex-direction: column; gap: 10px; padding: var(--padding); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 8px; }
legend { padding: 0 5px; font-size: var(--text-small); color: var(--text-muted); }
label { display: flex; flex-direction: column; gap: 3px; font-size: var(--text-small); color: var(--text-muted); }
input, select { padding: 6px 8px; font: inherit; font-size: var(--text-input); color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 5px; }
input[readonly] { font-family: var(--font-mono); color: var(--text-muted); background: var(--surface-border); border-color: transparent; cursor: default; }
code { font-family: var(--font-mono); }
.hinweis { font-size: var(--text-label); color: var(--text-faint); line-height: 1.45; }
.fehler { font-size: var(--text-small); color: var(--critical); }
.maske > .knopf { align-self: flex-start; }
</style>
