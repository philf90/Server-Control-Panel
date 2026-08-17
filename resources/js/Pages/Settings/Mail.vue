<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import Section from '../../Components/Section.vue'
import EyeIcon from '../../Components/EyeIcon.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import FormErrors from '../../Components/FormErrors.vue'

interface Encryption {
  value: string
  label: string
}

const props = defineProps<{
  mail: {
    host: string
    port: number
    encryption: string
    username: string
    from_address: string
    from_name: string
    password_set: boolean
  }
  encryptions: Encryption[]
  usable: boolean
}>()

/*
 * Das Passwortfeld ist leer, auch wenn eines hinterlegt ist.
 *
 * Der Server schickt es nicht mit — es stünde sonst im Quelltext dieser Seite.
 * Leer lassen heisst deshalb „unverändert"; wer es wirklich entfernen will,
 * setzt das Häkchen. Ohne diese Unterscheidung räumte jedes Speichern des
 * Ports die Anmeldung am Relay ab.
 */
const form = useForm({
  host: props.mail.host,
  port: props.mail.port,
  encryption: props.mail.encryption,
  username: props.mail.username,
  password: '',
  password_clear: false,
  from_address: props.mail.from_address,
  from_name: props.mail.from_name,
})

const zeigen = ref(false)

/*
 * Der übliche Port zur gewählten Verschlüsselung. Er wird vorgeschlagen und
 * nicht erzwungen: Es gibt Relays auf 2525, und ein Feld, das den eingegebenen
 * Wert überschreibt, ist ärgerlicher als eine falsche Voreinstellung.
 */
function onEncryption(): void {
  const üblich: Record<string, number> = { tls: 587, ssl: 465, none: 25 }
  const bekannt = Object.values(üblich)

  if (bekannt.includes(form.port)) form.port = üblich[form.encryption] ?? form.port
}

function submit(): void {
  form.put('/settings/mail', { onSuccess: () => form.reset('password', 'password_clear') })
}

function test(): void {
  router.post('/settings/mail/test', {}, { preserveScroll: true })
}
</script>

<template>
  <Head title="Mailversand" />

  <PanelLayout title="Mailversand" subline="SMTP-Relay für Nachrichten des Panels">
    <p v-if="!props.usable" class="notice warn">
      <span>
        Noch kein Relay hinterlegt. Bis dahin verschickt das Panel nichts —
        Einmal-Links und Warnungen entstehen, erreichen aber niemanden.
      </span>
    </p>

    <FormErrors />

    <form class="form" @submit.prevent="submit">
      <Section title="Relay">
        <label class="field">
          <span>Server</span>
          <input v-model="form.host" type="text" autocomplete="off" placeholder="mail.example.net" required :aria-invalid="Boolean(form.errors.host)">
        </label>

        <div class="field-row">
          <label class="field">
            <span>Verschlüsselung</span>
            <select v-model="form.encryption" @change="onEncryption">
              <option v-for="e in props.encryptions" :key="e.value" :value="e.value">{{ e.label }}</option>
            </select>
          </label>

          <label class="field narrow">
            <span>Port</span>
            <input v-model.number="form.port" type="number" min="1" max="65535" required :aria-invalid="Boolean(form.errors.port)">
          </label>
        </div>
      </Section>

      <Section title="Anmeldung">
        <label class="field">
          <span>Benutzername</span>
          <input v-model="form.username" type="text" autocomplete="off">
        </label>
        <p class="hint">Leer lassen, wenn das Relay im eigenen Netz ohne Anmeldung arbeitet.</p>

        <label class="field">
          <span>Passwort</span>
          <span class="with-reveal">
            <input
              :aria-invalid="Boolean(form.errors.password)"
              v-model="form.password"
              :type="zeigen ? 'text' : 'password'"
              autocomplete="new-password"
              :placeholder="props.mail.password_set ? 'gespeichert — leer lassen, um es zu behalten' : ''"
              :disabled="form.password_clear"
            >
            <button
              type="button"
              class="reveal"
              :aria-label="zeigen ? 'Passwort verbergen' : 'Passwort anzeigen'"
              :aria-pressed="zeigen"
              @click.prevent="zeigen = !zeigen"
            >
              <EyeIcon :off="zeigen" />
            </button>
          </span>
        </label>

        <label v-if="props.mail.password_set" class="toggle">
          <input v-model="form.password_clear" type="checkbox">
          <span>Hinterlegtes Passwort entfernen</span>
        </label>
      </Section>

      <Section title="Absender">
        <label class="field">
          <span>Adresse</span>
          <input v-model="form.from_address" type="email" autocomplete="off" placeholder="panel@example.net" required :aria-invalid="Boolean(form.errors.from_address)">
        </label>
        <p class="hint">
          Muss beim Relay als Absender zulässig sein. Viele Anbieter weisen
          alles ab, was nicht zum angemeldeten Konto gehört.
        </p>

        <label class="field">
          <span>Anzeigename</span>
          <input v-model="form.from_name" type="text" required :aria-invalid="Boolean(form.errors.from_name)">
        </label>
      </Section>

      <div class="button-row">
        <button type="submit" class="button primary" :disabled="form.processing">
          {{ form.processing ? 'Wird gespeichert …' : 'Speichern' }}
        </button>
        <button type="button" class="button" :disabled="!props.usable" @click="test">Testmail an mich</button>
      </div>
    </form>
  </PanelLayout>
</template>

