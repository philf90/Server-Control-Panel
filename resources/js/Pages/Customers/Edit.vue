<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import PanelLayout from '../../Layouts/PanelLayout.vue'

const props = defineProps<{
  customer: {
    id: number
    number: string
    first_name: string
    last_name: string
    email: string
    phone: string | null
    street: string | null
    postal_code: string | null
    city: string | null
    country: string | null
    notes: string | null
  }
}>()

/*
 * Die Kundennummer steht auf der Seite und nicht im Formular.
 *
 * Sie ist der Bezeichner, unter dem der Kunde in Rechnungen auftaucht — sie zu
 * ändern hiesse, zwei Belege desselben Vorgangs unter zwei Nummern zu führen.
 * Aus demselben Grund vergibt sie der Server beim Anlegen und nicht der
 * Betreiber.
 */
const form = useForm({
  first_name: props.customer.first_name,
  last_name: props.customer.last_name,
  email: props.customer.email,
  phone: props.customer.phone ?? '',
  street: props.customer.street ?? '',
  postal_code: props.customer.postal_code ?? '',
  city: props.customer.city ?? '',
  country: props.customer.country ?? '',
  notes: props.customer.notes ?? '',
})
</script>

<template>
  <Head :title="`${props.customer.first_name} ${props.customer.last_name}`" />

  <PanelLayout title="Kunde bearbeiten" :subline="props.customer.number">
    <form class="maske" @submit.prevent="form.patch(`/customers/${props.customer.id}`)">
      <fieldset>
        <legend>Vertragspartner</legend>

        <label>Kundennummer
          <input :value="props.customer.number" type="text" readonly tabindex="-1">
          <small class="hinweis">
            Steht in Rechnungen und Verzeichnisnamen und lässt sich deshalb nicht ändern.
          </small>
        </label>

        <div class="paar">
          <label>Vorname
            <input v-model="form.first_name" type="text" required>
            <small v-if="form.errors.first_name" class="fehler">{{ form.errors.first_name }}</small>
          </label>
          <label>Nachname
            <input v-model="form.last_name" type="text" required>
            <small v-if="form.errors.last_name" class="fehler">{{ form.errors.last_name }}</small>
          </label>
        </div>

        <label>E-Mail
          <input v-model="form.email" type="email" required>
          <small v-if="form.errors.email" class="fehler">{{ form.errors.email }}</small>
          <small class="hinweis">
            Die Adresse des Vertragspartners. Die Anmeldeadresse gehört zum
            Konto und wird unter „Mein Konto" geändert.
          </small>
        </label>

        <label>Telefon
          <input v-model="form.phone" type="text">
          <small v-if="form.errors.phone" class="fehler">{{ form.errors.phone }}</small>
        </label>
      </fieldset>

      <fieldset>
        <legend>Anschrift</legend>

        <label>Straße und Hausnummer
          <input v-model="form.street" type="text">
        </label>

        <div class="paar plz">
          <label>PLZ
            <input v-model="form.postal_code" type="text">
          </label>
          <label>Ort
            <input v-model="form.city" type="text">
          </label>
        </div>

        <label>Land
          <input v-model="form.country" type="text" maxlength="2" placeholder="DE">
          <small v-if="form.errors.country" class="fehler">{{ form.errors.country }}</small>
          <small class="hinweis">Zwei Buchstaben nach ISO 3166-1, etwa DE, AT oder CH.</small>
        </label>
      </fieldset>

      <fieldset>
        <legend>Notiz</legend>

        <label>Vermerk
          <textarea v-model="form.notes" rows="4"></textarea>
          <small class="hinweis">
            Nur für den Betreiber sichtbar. Der Kunde sieht diesen Text nicht.
          </small>
        </label>
      </fieldset>

      <div class="knopfreihe">
        <button type="submit" class="knopf wichtig" :disabled="form.processing">
          {{ form.processing ? 'Wird gespeichert …' : 'Speichern' }}
        </button>
        <Link :href="`/customers/${props.customer.id}`" class="knopf">Abbrechen</Link>
      </div>
    </form>
  </PanelLayout>
</template>

<style scoped>
.maske { display: flex; flex-direction: column; gap: var(--gap); max-width: 544px; }
fieldset { display: flex; flex-direction: column; gap: 10px; padding: var(--padding); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 8px; }
legend { padding: 0 5px; font-size: var(--text-small); color: var(--text-muted); }
label { display: flex; flex-direction: column; gap: 3px; font-size: var(--text-small); color: var(--text-muted); }
.paar { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.paar.plz { grid-template-columns: 120px 1fr; }
input, textarea { padding: 6px 8px; font: inherit; font-size: var(--text-input); color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 5px; }
textarea { resize: vertical; }
input[readonly] { font-family: var(--font-mono); color: var(--text-muted); background: var(--surface-border); border-color: transparent; cursor: default; }
.hinweis { font-size: var(--text-label); color: var(--text-faint); line-height: 1.45; }
.fehler { font-size: var(--text-small); color: var(--critical); }

/* docs/24: unter 480px stehen zwei Felder nicht mehr nebeneinander. */
@media (max-width: 480px) {
  .paar, .paar.plz { grid-template-columns: 1fr; }
}
</style>
