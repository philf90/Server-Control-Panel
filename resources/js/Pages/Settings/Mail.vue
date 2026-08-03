<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import EyeIcon from '../../Components/EyeIcon.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

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

const page = usePage()
const flash = computed(() => page.props.flash as Record<string, string> | undefined)

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
    <p v-if="flash?.success" class="erfolg">{{ flash.success }}</p>
    <p v-if="flash?.error" class="fehler-block">{{ flash.error }}</p>

    <p v-if="!props.usable" class="hinweis-block">
      Noch kein Relay hinterlegt. Bis dahin verschickt das Panel nichts —
      Einmal-Links und Warnungen entstehen, erreichen aber niemanden.
    </p>

    <form class="maske" @submit.prevent="submit">
      <fieldset>
        <legend>Relay</legend>

        <label>Server
          <input v-model="form.host" type="text" autocomplete="off" placeholder="mail.example.net" required>
          <small v-if="form.errors.host" class="fehler">{{ form.errors.host }}</small>
        </label>

        <div class="paar">
          <label>Verschlüsselung
            <select v-model="form.encryption" @change="onEncryption">
              <option v-for="e in props.encryptions" :key="e.value" :value="e.value">{{ e.label }}</option>
            </select>
          </label>

          <label>Port
            <input v-model.number="form.port" type="number" min="1" max="65535" required>
            <small v-if="form.errors.port" class="fehler">{{ form.errors.port }}</small>
          </label>
        </div>
      </fieldset>

      <fieldset>
        <legend>Anmeldung</legend>

        <label>Benutzername
          <input v-model="form.username" type="text" autocomplete="off">
          <small class="hinweis">Leer lassen, wenn das Relay im eigenen Netz ohne Anmeldung arbeitet.</small>
        </label>

        <label>Passwort
          <span class="mit-auge">
            <input
              v-model="form.password"
              :type="zeigen ? 'text' : 'password'"
              autocomplete="new-password"
              :placeholder="props.mail.password_set ? 'gespeichert — leer lassen, um es zu behalten' : ''"
              :disabled="form.password_clear"
            >
            <button type="button" class="auge" :aria-label="zeigen ? 'Passwort verbergen' : 'Passwort anzeigen'" @click="zeigen = !zeigen">
              <EyeIcon :off="zeigen" />
            </button>
          </span>
          <small v-if="form.errors.password" class="fehler">{{ form.errors.password }}</small>
        </label>

        <label v-if="props.mail.password_set" class="schalter">
          <input v-model="form.password_clear" type="checkbox">
          <span>Hinterlegtes Passwort entfernen</span>
        </label>
      </fieldset>

      <fieldset>
        <legend>Absender</legend>

        <label>Adresse
          <input v-model="form.from_address" type="email" autocomplete="off" placeholder="panel@example.net" required>
          <small v-if="form.errors.from_address" class="fehler">{{ form.errors.from_address }}</small>
          <small class="hinweis">
            Muss beim Relay als Absender zulässig sein. Viele Anbieter weisen
            alles ab, was nicht zum angemeldeten Konto gehört.
          </small>
        </label>

        <label>Anzeigename
          <input v-model="form.from_name" type="text" required>
          <small v-if="form.errors.from_name" class="fehler">{{ form.errors.from_name }}</small>
        </label>
      </fieldset>

      <div class="aktionen">
        <button type="submit" :disabled="form.processing">
          {{ form.processing ? 'Wird gespeichert …' : 'Speichern' }}
        </button>
        <button type="button" class="pruefen" :disabled="!props.usable" @click="test">Testmail an mich</button>
      </div>
    </form>
  </PanelLayout>
</template>

<style scoped>
.erfolg { max-width: 544px; padding: 8px 11px; font-size: var(--text-table); color: var(--ok); background: var(--ok-surface); border-radius: 6px; }
.fehler-block { max-width: 544px; padding: 8px 11px; font-size: var(--text-table); color: var(--critical); background: var(--critical-surface); border-radius: 6px; word-break: break-word; }
.hinweis-block { max-width: 544px; padding: 8px 11px; font-size: var(--text-table); color: var(--warn); background: var(--warn-surface); border-radius: 6px; }
.maske { display: flex; flex-direction: column; gap: var(--gap); max-width: 544px; }
fieldset { display: flex; flex-direction: column; gap: 10px; padding: var(--padding); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 8px; }
legend { padding: 0 5px; font-size: var(--text-small); color: var(--text-muted); }
label { display: flex; flex-direction: column; gap: 3px; font-size: var(--text-small); color: var(--text-muted); }
.paar { display: grid; grid-template-columns: 1fr 130px; gap: 10px; }
input, select { padding: 6px 8px; font: inherit; font-size: var(--text-input); color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 5px; }
input:disabled { color: var(--text-faint); background: var(--surface-border); border-color: transparent; }
.mit-auge { display: flex; gap: 6px; }
.mit-auge input { flex: 1; min-width: 0; }
.auge { display: grid; place-items: center; width: 34px; color: var(--text-faint); background: transparent; border: 1px solid var(--line); border-radius: 5px; cursor: pointer; }
.auge:hover { color: var(--text-strong); }
.schalter { flex-direction: row; align-items: center; gap: 6px; font-size: var(--text-table); color: var(--text); }
.hinweis { font-size: var(--text-label); color: var(--text-faint); line-height: 1.45; }
.fehler { font-size: var(--text-small); color: var(--critical); }
.aktionen { display: flex; align-items: center; gap: 12px; }
button[type='submit'] { padding: 8px 16px; font: inherit; font-weight: 600; color: var(--accent-on); background: var(--accent); border: 0; border-radius: 6px; cursor: pointer; }
button[type='submit']:disabled { opacity: .6; cursor: default; }
.pruefen { padding: 8px 14px; font: inherit; font-size: var(--text-table); color: var(--text); background: transparent; border: 1px solid var(--line); border-radius: 6px; cursor: pointer; }
.pruefen:disabled { color: var(--text-faint); cursor: default; }

/* docs/24: unter 480px stehen zwei Felder nicht mehr nebeneinander. */
@media (max-width: 480px) {
  .paar { grid-template-columns: 1fr; }
  .aktionen { flex-direction: column; align-items: stretch; }
  .aktionen button { min-height: var(--tap); }
}
</style>
