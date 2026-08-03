<script setup lang="ts">
/*
 * Das eigene Konto.
 *
 * Der Zuschnitt folgt dem, was hier tatsächlich zu entscheiden ist: Name und
 * Anmeldeadresse gehören zusammen und ändern sich selten; das Passwort ist ein
 * eigener Vorgang mit eigenen Folgen (andere Sitzungen fliegen raus). Zwei
 * Formulare statt eines — ein gemeinsames „Speichern" über beidem hiesse, dass
 * ein Tippfehler im Namen einen Passwortwechsel mitschleppt.
 */
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import PasswordFields from '../../Components/PasswordFields.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

interface PasswordPolicy {
  minimum: number
  requirements: { key: string; label: string }[]
}

const props = defineProps<{
  profile: {
    name: string
    email: string
    type_label: string
    two_factor: boolean
    last_login_at: string | null
    last_login_ip: string | null
  }
  impersonating: boolean
}>()

const page = usePage<{ passwordPolicy: PasswordPolicy; flash: { success?: string } }>()
const policy = computed(() => page.props.passwordPolicy)
const success = computed(() => page.props.flash?.success)

const konto = useForm({
  name: props.profile.name,
  email: props.profile.email,
  current_password: '',
})

const passwort = useForm({
  current_password: '',
  password: '',
  password_confirmation: '',
})

function saveAccount(): void {
  konto.patch('/settings/profile', { onFinish: () => konto.reset('current_password') })
}

function savePassword(): void {
  passwort.put('/settings/password', {
    onFinish: () => passwort.reset('current_password', 'password', 'password_confirmation'),
  })
}
</script>

<template>
  <Head title="Mein Konto" />

  <PanelLayout title="Mein Konto" :subline="props.profile.type_label">
    <p v-if="success" class="erfolg">{{ success }}</p>

    <!--
      Während „Anmelden als" wird gar kein Formular gezeigt. Der Server weist
      es ohnehin ab (siehe ProfileController); hier steht der Grund, damit
      niemand ihn erraten muss.
    -->
    <p v-if="props.impersonating" class="gesperrt">
      Sie arbeiten gerade in fremder Sicht. Das Konto eines Kunden lässt sich
      von hier aus nicht ändern — kehren Sie dafür in die Verwaltung zurück.
    </p>

    <template v-else>
      <section class="block">
        <h2 class="section">Konto</h2>

        <form class="maske" @submit.prevent="saveAccount">
          <label>Anzeigename
            <input v-model="konto.name" type="text" required>
            <small v-if="konto.errors.name" class="fehler">{{ konto.errors.name }}</small>
          </label>

          <label>Anmeldeadresse
            <input v-model="konto.email" type="email" autocomplete="username" required>
            <small v-if="konto.errors.email" class="fehler">{{ konto.errors.email }}</small>
            <small class="hinweis">Mit dieser Adresse melden Sie sich an.</small>
          </label>

          <label>Aktuelles Passwort
            <input v-model="konto.current_password" type="password" autocomplete="current-password" required>
            <small v-if="konto.errors.current_password" class="fehler">{{ konto.errors.current_password }}</small>
            <small class="hinweis">
              Auch für eine Änderung am Namen — sonst genügte ein unbeaufsichtigter
              Rechner, um die Anmeldeadresse umzuschreiben.
            </small>
          </label>

          <button type="submit" class="knopf wichtig" :disabled="konto.processing">
            {{ konto.processing ? 'Wird gespeichert …' : 'Speichern' }}
          </button>
        </form>
      </section>

      <section class="block">
        <h2 class="section">Passwort ändern</h2>

        <form class="maske" @submit.prevent="savePassword">
          <label>Aktuelles Passwort
            <input v-model="passwort.current_password" type="password" autocomplete="current-password" required>
            <small v-if="passwort.errors.current_password" class="fehler">{{ passwort.errors.current_password }}</small>
          </label>

          <PasswordFields
            v-model="passwort.password"
            v-model:confirmation="passwort.password_confirmation"
            :requirements="policy.requirements"
            :minimum="policy.minimum"
            :error="passwort.errors.password"
            label="Neues Passwort"
          />

          <p class="hinweis">
            Nach dem Wechsel werden alle anderen Sitzungen abgemeldet. Diese hier bleibt bestehen.
          </p>

          <button type="submit" class="knopf wichtig" :disabled="passwort.processing">
            {{ passwort.processing ? 'Wird geändert …' : 'Passwort ändern' }}
          </button>
        </form>
      </section>
    </template>

    <section class="block">
      <h2 class="section">Sicherheit</h2>

      <dl class="werte">
        <dt>Zweiter Faktor</dt>
        <dd>
          <span :class="['marke', props.profile.two_factor ? 'an' : 'aus']">
            {{ props.profile.two_factor ? 'eingerichtet' : 'nicht eingerichtet' }}
          </span>
          <Link href="/settings/two-factor">verwalten</Link>
        </dd>

        <dt>Letzte Anmeldung</dt>
        <dd>{{ props.profile.last_login_at ?? '—' }}</dd>

        <dt>Von</dt>
        <dd>{{ props.profile.last_login_ip ?? '—' }}</dd>
      </dl>
    </section>
  </PanelLayout>
</template>

<style scoped>
.block { margin-top: var(--block-gap); }
.block:first-of-type { margin-top: 0; }
.section { margin: 0 0 var(--block-heading-gap); font-size: var(--block-heading-size); font-weight: 600; letter-spacing: -.01em; color: var(--text-strong); }

.maske { display: flex; flex-direction: column; gap: 10px; max-width: 448px; padding: var(--padding); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 8px; }
label { display: flex; flex-direction: column; gap: 3px; font-size: var(--text-small); color: var(--text-muted); }
input { padding: 6px 8px; font: inherit; font-size: var(--text-input); color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 5px; }
.fehler { font-size: var(--text-small); color: var(--critical); }
.hinweis { margin: 0; font-size: var(--text-label); color: var(--text-faint); }


.erfolg { margin: 0 0 var(--gap); padding: 8px 11px; font-size: var(--text-table); color: var(--ok); background: var(--ok-surface); border-radius: 5px; }
.gesperrt { margin: 0; padding: 11px 13px; max-width: 448px; font-size: var(--text-table); color: var(--warn); background: var(--warn-surface); border: 1px solid var(--warn); border-radius: 5px; }

.werte { display: grid; grid-template-columns: auto 1fr; gap: 5px 16px; margin: 0; max-width: 448px; font-size: var(--text-table); }
.werte dt { color: var(--text-muted); }
.werte dd { margin: 0; display: flex; align-items: center; gap: 10px; color: var(--text); }
.marke { font-size: var(--text-label); padding: 1px 7px; border-radius: 999px; }
.marke.an { color: var(--ok); background: var(--ok-surface); }
.marke.aus { color: var(--text-muted); background: var(--surface-border); }
form .knopf { align-self: flex-start; }
</style>
