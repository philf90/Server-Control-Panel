<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import Section from '../../Components/Section.vue'
import PasswordFields from '../../Components/PasswordFields.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import FormErrors from '../../Components/FormErrors.vue'

interface PasswordPolicy {
  minimum: number
  requirements: { key: string; label: string }[]
}

const props = defineProps<{ nextNumber: string }>()

const page = usePage<{ passwordPolicy: PasswordPolicy }>()
const policy = computed(() => page.props.passwordPolicy)

/*
 * Kunde und Anmeldekonto in einem Formular.
 *
 * Sie entstehen zusammen in einer Transaktion — ein Kunde ohne Konto ist ein
 * Datensatz, mit dem niemand etwas anfangen kann. Deshalb steht auch das
 * Passwort hier und nicht in einem zweiten Schritt, den jemand überspringt.
 *
 * **Die Kundennummer steht nicht im Formular**, obwohl sie darauf zu sehen ist.
 * Sie ist der Bezeichner, unter dem der Kunde später in Rechnungen,
 * Verzeichnisnamen und Systembenutzern auftaucht; ein Feld dafür heißt, dass
 * jemand sie doppelt vergeben oder mit einem Schrägstrich darin anlegen kann.
 * Der Server erzeugt sie beim Anlegen — was hier steht, ist eine Vorschau.
 */
const form = useForm({
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  login_email: '',
  password: '',
  password_confirmation: '',
})

function submit(): void {
  form.post('/customers', { onFinish: () => form.reset('password', 'password_confirmation') })
}
</script>

<template>
  <Head title="Kunde anlegen" />

  <PanelLayout title="Kunde anlegen" subline="Vertragspartner und erstes Anmeldekonto">
    <template #breadcrumb>
      <Link href="/customers" class="link">Kunden</Link>
    </template>

    <FormErrors />

    <form class="form" @submit.prevent="submit">
      <Section title="Vertragspartner">
        <label class="field">
          <span>Kundennummer</span>
          <input :value="props.nextNumber" type="text" readonly tabindex="-1">
        </label>
        <p class="hint">
          Wird beim Anlegen vergeben. Steht bis dahin ein anderer Kunde davor,
          rückt die Nummer nach.
        </p>

        <div class="field-row">
          <label class="field">
            <span>Vorname</span>
            <input v-model="form.first_name" type="text" required>
          </label>
          <label class="field">
            <span>Nachname</span>
            <input v-model="form.last_name" type="text" required>
          </label>
        </div>
        <p v-if="form.errors.first_name" class="error">{{ form.errors.first_name }}</p>
        <p v-if="form.errors.last_name" class="error">{{ form.errors.last_name }}</p>

        <label class="field">
          <span>E-Mail</span>
          <input v-model="form.email" type="email" required>
        </label>
        <p v-if="form.errors.email" class="error">{{ form.errors.email }}</p>

        <label class="field">
          <span>Telefon</span>
          <input v-model="form.phone" type="text">
        </label>
      </Section>

      <Section title="Anmeldekonto">
        <label class="field">
          <span>Anmeldeadresse</span>
          <input v-model="form.login_email" type="email" autocomplete="off" required>
        </label>
        <p v-if="form.errors.login_email" class="error">{{ form.errors.login_email }}</p>

        <PasswordFields
          v-model="form.password"
          v-model:confirmation="form.password_confirmation"
          :requirements="policy.requirements"
          :minimum="policy.minimum"
          :error="form.errors.password"
        />
      </Section>

      <div class="button-row">
        <button type="submit" class="button primary" :disabled="form.processing">
          {{ form.processing ? 'Wird angelegt …' : 'Anlegen' }}
        </button>
        <Link href="/customers" class="button">Abbrechen</Link>
      </div>
    </form>
  </PanelLayout>
</template>
