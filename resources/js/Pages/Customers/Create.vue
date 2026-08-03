<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import PasswordFields from '../../Components/PasswordFields.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

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
    <form class="maske" @submit.prevent="submit">
      <fieldset>
        <legend>Vertragspartner</legend>

        <label>Kundennummer
          <input :value="props.nextNumber" type="text" readonly tabindex="-1">
          <small class="hinweis">
            Wird beim Anlegen vergeben. Steht bis dahin ein anderer Kunde davor,
            rückt die Nummer nach.
          </small>
        </label>

        <label>Vorname
          <input v-model="form.first_name" type="text" required>
          <small v-if="form.errors.first_name" class="fehler">{{ form.errors.first_name }}</small>
        </label>
        <label>Nachname
          <input v-model="form.last_name" type="text" required>
          <small v-if="form.errors.last_name" class="fehler">{{ form.errors.last_name }}</small>
        </label>
        <label>E-Mail
          <input v-model="form.email" type="email" required>
          <small v-if="form.errors.email" class="fehler">{{ form.errors.email }}</small>
        </label>
        <label>Telefon <input v-model="form.phone" type="text"></label>
      </fieldset>

      <fieldset>
        <legend>Anmeldekonto</legend>

        <label>Anmeldeadresse
          <input v-model="form.login_email" type="email" autocomplete="off" required>
          <small v-if="form.errors.login_email" class="fehler">{{ form.errors.login_email }}</small>
        </label>

        <PasswordFields
          v-model="form.password"
          v-model:confirmation="form.password_confirmation"
          :requirements="policy.requirements"
          :minimum="policy.minimum"
          :error="form.errors.password"
        />
      </fieldset>

      <button type="submit" class="knopf wichtig" :disabled="form.processing">
        {{ form.processing ? 'Wird angelegt …' : 'Anlegen' }}
      </button>
    </form>
  </PanelLayout>
</template>

<style scoped>
.maske { display: flex; flex-direction: column; gap: var(--gap); max-width: 544px; }
fieldset { display: flex; flex-direction: column; gap: 8px; padding: var(--padding); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 8px; }
legend { padding: 0 5px; font-size: var(--text-small); color: var(--text-muted); }
label { display: flex; flex-direction: column; gap: 3px; font-size: var(--text-small); color: var(--text-muted); }
input { padding: 6px 8px; font: inherit; font-size: var(--text-input); color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 5px; }
input[readonly] { color: var(--text-muted); background: var(--surface-border); border-color: transparent; cursor: default; }
.fehler { font-size: var(--text-small); color: var(--critical); }
.hinweis { font-size: var(--text-label); color: var(--text-faint); }
.maske > .knopf { align-self: flex-start; }
</style>
