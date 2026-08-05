<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import Bereich from '../../Components/Bereich.vue'
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
    <template #pfad>
      <Link href="/subscriptions" class="verweis">Abonnements</Link>
    </template>

    <p v-if="props.customers.length === 0 || props.plans.length === 0" class="meldung warn">
      Es braucht mindestens einen Kunden und einen Plan. Beide finden sich unter Verwaltung.
    </p>

    <form class="maske" @submit.prevent="form.post('/subscriptions')">
      <Bereich titel="Zuordnung">
        <label class="feld">
          <span>Kunde</span>
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
        </label>
        <p v-if="form.errors.customer_id" class="fehler">{{ form.errors.customer_id }}</p>

        <label class="feld">
          <span>Plan</span>
          <select v-model="form.plan_id" required>
            <option v-for="p in props.plans" :key="p.id" :value="p.id">
              {{ p.label }}{{ p.is_default ? ' (Standard)' : '' }}
            </option>
          </select>
        </label>
        <p v-if="form.errors.plan_id" class="fehler">{{ form.errors.plan_id }}</p>
      </Bereich>

      <Bereich titel="Abonnement">
        <label class="feld">
          <span>Name</span>
          <input v-model="form.name" type="text" placeholder="kunde-example.de" autocomplete="off" required>
        </label>
        <p v-if="form.errors.name" class="fehler">{{ form.errors.name }}</p>
        <p class="hinweis">
          Wird zum Verzeichnis <span class="kennung">/var/www/vhosts/&lt;name&gt;</span>.
          Kleinbuchstaben, Ziffern, Punkt und Bindestrich; Anfang und Ende
          alphanumerisch.
        </p>

        <label class="feld">
          <span>Systembenutzer</span>
          <input :value="props.nextUser" type="text" readonly tabindex="-1">
        </label>
        <p class="hinweis">
          Wird beim Anlegen vergeben. Er bleibt auch nach einem Rückbau
          verbraucht — sonst erbte ein späteres Abonnement seine UID.
        </p>
      </Bereich>

      <div class="knopfreihe">
        <button type="submit" class="knopf wichtig" :disabled="form.processing || props.plans.length === 0">
          {{ form.processing ? 'Wird eingereiht …' : 'Anlegen' }}
        </button>
        <Link href="/subscriptions" class="knopf">Abbrechen</Link>
      </div>
    </form>
  </PanelLayout>
</template>
