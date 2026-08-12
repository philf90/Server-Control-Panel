<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import Section from '../../Components/Section.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import FormErrors from '../../Components/FormErrors.vue'

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
    <template #breadcrumb>
      <Link href="/subscriptions" class="link">Abonnements</Link>
    </template>

    <p v-if="props.customers.length === 0 || props.plans.length === 0" class="notice warn">
      Es braucht mindestens einen Kunden und einen Plan. Beide finden sich unter Verwaltung.
    </p>

    <FormErrors />

    <form class="form" @submit.prevent="form.post('/subscriptions')">
      <Section title="Zuordnung">
        <label class="field">
          <span>Kunde</span>
          <select v-model="form.customer_id" required :aria-invalid="Boolean(form.errors.customer_id)">
            <option
              v-for="c in props.customers"
              :key="c.id"
              :value="c.id"
              :disabled="c.suspended"
            >
              {{ c.label }}{{ c.suspended ? ' · gesperrt' : '' }}
            </option>
          </select>
        </label>

        <label class="field">
          <span>Plan</span>
          <select v-model="form.plan_id" required :aria-invalid="Boolean(form.errors.plan_id)">
            <option v-for="p in props.plans" :key="p.id" :value="p.id">
              {{ p.label }}{{ p.is_default ? ' (Standard)' : '' }}
            </option>
          </select>
        </label>
      </Section>

      <Section title="Abonnement">
        <label class="field">
          <span>Name</span>
          <input v-model="form.name" type="text" placeholder="kunde-example.de" autocomplete="off" required :aria-invalid="Boolean(form.errors.name)">
        </label>
        <p class="hint">
          Wird zum Verzeichnis <span class="ident">/var/www/vhosts/&lt;name&gt;</span>.
          Kleinbuchstaben, Ziffern, Punkt und Bindestrich; Anfang und Ende
          alphanumerisch.
        </p>

        <label class="field">
          <span>Systembenutzer</span>
          <input :value="props.nextUser" type="text" readonly tabindex="-1">
        </label>
        <p class="hint">
          Wird beim Anlegen vergeben. Er bleibt auch nach einem Rückbau
          verbraucht — sonst erbte ein späteres Abonnement seine UID.
        </p>
      </Section>

      <div class="button-row">
        <button type="submit" class="button primary" :disabled="form.processing || props.plans.length === 0">
          {{ form.processing ? 'Wird eingereiht …' : 'Anlegen' }}
        </button>
        <Link href="/subscriptions" class="button">Abbrechen</Link>
      </div>
    </form>
  </PanelLayout>
</template>
