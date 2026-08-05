<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import Bereich from '../../Components/Bereich.vue'
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

  <PanelLayout title="Kunde bearbeiten">
    <template #pfad>
      <Link href="/customers" class="verweis">Kunden</Link> ·
      <Link :href="`/customers/${props.customer.id}`" class="verweis">
        {{ props.customer.first_name }} {{ props.customer.last_name }}
      </Link>
    </template>

    <form class="maske" @submit.prevent="form.patch(`/customers/${props.customer.id}`)">
      <Bereich titel="Vertragspartner">
        <label class="feld">
          <span>Kundennummer</span>
          <input :value="props.customer.number" type="text" readonly tabindex="-1">
        </label>
        <p class="hinweis">
          Steht in Rechnungen und Verzeichnisnamen und lässt sich deshalb nicht ändern.
        </p>

        <div class="paar">
          <label class="feld">
            <span>Vorname</span>
            <input v-model="form.first_name" type="text" required>
          </label>
          <label class="feld">
            <span>Nachname</span>
            <input v-model="form.last_name" type="text" required>
          </label>
        </div>
        <p v-if="form.errors.first_name" class="fehler">{{ form.errors.first_name }}</p>
        <p v-if="form.errors.last_name" class="fehler">{{ form.errors.last_name }}</p>

        <label class="feld">
          <span>E-Mail</span>
          <input v-model="form.email" type="email" required>
        </label>
        <p v-if="form.errors.email" class="fehler">{{ form.errors.email }}</p>
        <p class="hinweis">
          Die Adresse des Vertragspartners. Die Anmeldeadresse gehört zum Konto
          und wird unter „Mein Konto" geändert.
        </p>

        <label class="feld">
          <span>Telefon</span>
          <input v-model="form.phone" type="text">
        </label>
        <p v-if="form.errors.phone" class="fehler">{{ form.errors.phone }}</p>
      </Bereich>

      <Bereich titel="Anschrift">
        <label class="feld">
          <span>Straße und Hausnummer</span>
          <input v-model="form.street" type="text">
        </label>

        <!--
          Die PLZ bekommt einen schmaleren Grundriss als der Ort: Fünf Ziffern
          in einem Feld von halber Zeilenbreite sehen aus, als fehle etwas.
        -->
        <div class="paar">
          <label class="feld schmal">
            <span>PLZ</span>
            <input v-model="form.postal_code" type="text">
          </label>
          <label class="feld">
            <span>Ort</span>
            <input v-model="form.city" type="text">
          </label>
        </div>

        <label class="feld">
          <span>Land</span>
          <input v-model="form.country" type="text" maxlength="2" placeholder="DE">
        </label>
        <p v-if="form.errors.country" class="fehler">{{ form.errors.country }}</p>
        <p class="hinweis">Zwei Buchstaben nach ISO 3166-1, etwa DE, AT oder CH.</p>
      </Bereich>

      <Bereich titel="Notiz">
        <label class="feld">
          <span>Vermerk</span>
          <textarea v-model="form.notes" rows="5"></textarea>
        </label>
        <p class="hinweis">
          Nur für den Betreiber sichtbar. Der Kunde sieht diesen Text nicht.
        </p>
      </Bereich>

      <div class="knopfreihe">
        <button type="submit" class="knopf wichtig" :disabled="form.processing">
          {{ form.processing ? 'Wird gespeichert …' : 'Speichern' }}
        </button>
        <Link :href="`/customers/${props.customer.id}`" class="knopf">Abbrechen</Link>
      </div>
    </form>
  </PanelLayout>
</template>
