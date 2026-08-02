<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import PanelLayout from '../../Layouts/PanelLayout.vue'

const props = defineProps<{ suggestedNumber: string }>()

/*
 * Kunde und Anmeldekonto in einem Formular.
 *
 * Sie entstehen zusammen in einer Transaktion — ein Kunde ohne Konto ist ein
 * Datensatz, mit dem niemand etwas anfangen kann. Deshalb steht auch das
 * Passwort hier und nicht in einem zweiten Schritt, den jemand überspringt.
 */
const form = useForm({
  number: props.suggestedNumber,
  company: '',
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
          <input v-model="form.number" type="text" required>
          <small v-if="form.errors.number">{{ form.errors.number }}</small>
        </label>

        <label>Firma <input v-model="form.company" type="text"></label>
        <label>Vorname
          <input v-model="form.first_name" type="text" required>
          <small v-if="form.errors.first_name">{{ form.errors.first_name }}</small>
        </label>
        <label>Nachname
          <input v-model="form.last_name" type="text" required>
          <small v-if="form.errors.last_name">{{ form.errors.last_name }}</small>
        </label>
        <label>E-Mail
          <input v-model="form.email" type="email" required>
          <small v-if="form.errors.email">{{ form.errors.email }}</small>
        </label>
        <label>Telefon <input v-model="form.phone" type="text"></label>
      </fieldset>

      <fieldset>
        <legend>Anmeldekonto</legend>

        <label>Anmeldeadresse
          <input v-model="form.login_email" type="email" autocomplete="off" required>
          <small v-if="form.errors.login_email">{{ form.errors.login_email }}</small>
        </label>

        <label>Passwort
          <input v-model="form.password" type="password" autocomplete="new-password" required>
          <small v-if="form.errors.password">{{ form.errors.password }}</small>
        </label>

        <label>Passwort wiederholen
          <input v-model="form.password_confirmation" type="password" autocomplete="new-password" required>
        </label>

        <p class="hinweis">Mindestens zwölf Zeichen. Der Kunde kann es später ändern.</p>
      </fieldset>

      <button type="submit" :disabled="form.processing">
        {{ form.processing ? 'Wird angelegt …' : 'Anlegen' }}
      </button>
    </form>
  </PanelLayout>
</template>

<style scoped>
.maske { display: flex; flex-direction: column; gap: var(--gap); max-width: 34rem; }
fieldset { display: flex; flex-direction: column; gap: .5rem; padding: var(--padding); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 8px; }
legend { padding: 0 .3rem; font-size: .8rem; color: var(--text-muted); }
label { display: flex; flex-direction: column; gap: .2rem; font-size: .8rem; color: var(--text-muted); }
input { padding: .4rem .5rem; font: inherit; font-size: .9rem; color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 5px; }
small { font-size: .75rem; color: var(--critical); }
.hinweis { margin: 0; font-size: .75rem; color: var(--text-faint); }
button { align-self: flex-start; padding: .5rem 1rem; font: inherit; font-weight: 600; color: var(--accent-on); background: var(--accent); border: 0; border-radius: 6px; cursor: pointer; }
button:disabled { opacity: .6; cursor: default; }
</style>
