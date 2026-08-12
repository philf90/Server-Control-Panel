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
import Section from '../../Components/Section.vue'
import Badge from '../../Components/Badge.vue'
import PasswordFields from '../../Components/PasswordFields.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import FormErrors from '../../Components/FormErrors.vue'

declare global {
  interface Window {
    /* Gesetzt vom Skript im Kopf der Seite (resources/views/app.blade.php).
       Es muss dort stehen und nicht hier: Es läuft vor dem ersten Zeichnen,
       dieses Bündel erst danach. */
    srvpanelTheme?: (modus: 'system' | 'light' | 'dark') => void
  }
}

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
    theme: 'dark' | 'light' | null
    last_login_at: string | null
    last_login_ip: string | null
  }
  impersonating: boolean
}>()

const page = usePage<{ passwordPolicy: PasswordPolicy }>()
const policy = computed(() => page.props.passwordPolicy)

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

/*
 * Die Darstellung wird beim Klick gespeichert und nicht über einen
 * „Speichern"-Knopf.
 *
 * Sie zeigt ihre Wirkung sofort — man sieht am Ergebnis, ob man sie haben
 * will. Ein Knopf darunter hiesse: umschalten, ansehen, und dann noch einmal
 * bestätigen, was man längst sieht. `preserveScroll`, damit die Seite bei der
 * Antwort nicht nach oben springt; die Auswahl steht weit unten.
 */
const themes: { wert: 'dark' | 'light' | null; name: string }[] = [
  { wert: null, name: 'System' },
  { wert: 'light', name: 'Hell' },
  { wert: 'dark', name: 'Dunkel' },
]

const darstellung = useForm<{ theme: 'dark' | 'light' | null }>({ theme: props.profile.theme })

function saveTheme(wahl: 'dark' | 'light' | null): void {
  darstellung.theme = wahl
  darstellung.put('/settings/theme', {
    preserveScroll: true,
    /*
     * Das Umschalten muss von Hand geschehen — und das ist keine Bequemlichkeit.
     * `data-theme` steht am `<html>`, und das Gerüst rendert Inertia bei einer
     * Navigation nie neu: Die Seite wechselt, der Rahmen bleibt stehen. Ohne
     * diese Zeile täte ein Klick auf „Dunkel" sichtbar gar nichts, bis jemand
     * die Seite neu lädt. Der Server hat dann längst das Richtige gespeichert,
     * und genau deshalb fällt so etwas in einem Test nicht auf.
     */
    onSuccess: () => window.srvpanelTheme?.(wahl ?? 'system'),
  })
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
    <!--
      Während „Anmelden als" wird gar kein Formular gezeigt. Der Server weist
      es ohnehin ab (siehe ProfileController); hier steht der Grund, damit
      niemand ihn erraten muss.
    -->
    <p v-if="props.impersonating" class="notice warn">
      <span>
        Sie arbeiten gerade in fremder Sicht. Das Konto eines Kunden lässt sich
        von hier aus nicht ändern — kehren Sie dafür in die Verwaltung zurück.
      </span>
    </p>

    <div class="sections">
      <Section v-if="!props.impersonating" title="Konto">
        <FormErrors />

        <form @submit.prevent="saveAccount">
          <label class="field">
            <span>Anzeigename</span>
            <input v-model="konto.name" type="text" required :aria-invalid="Boolean(konto.errors.name)">
          </label>

          <label class="field">
            <span>Anmeldeadresse</span>
            <input v-model="konto.email" type="email" autocomplete="username" required :aria-invalid="Boolean(konto.errors.email)">
          </label>
          <p class="hint">Mit dieser Adresse melden Sie sich an.</p>

          <label class="field">
            <span>Aktuelles Passwort</span>
            <input v-model="konto.current_password" type="password" autocomplete="current-password" required :aria-invalid="Boolean(konto.errors.current_password)">
          </label>
          <p class="hint">
            Auch für eine Änderung am Namen — sonst genügte ein unbeaufsichtigter
            Rechner, um die Anmeldeadresse umzuschreiben.
          </p>

          <div class="button-row">
            <button type="submit" class="button primary" :disabled="konto.processing">
              {{ konto.processing ? 'Wird gespeichert …' : 'Speichern' }}
            </button>
          </div>
        </form>
      </Section>

      <Section v-if="!props.impersonating" title="Passwort ändern">
        <form @submit.prevent="savePassword">
          <label class="field">
            <span>Aktuelles Passwort</span>
            <input v-model="passwort.current_password" type="password" autocomplete="current-password" required :aria-invalid="Boolean(passwort.errors.current_password)">
          </label>

          <PasswordFields
            v-model="passwort.password"
            v-model:confirmation="passwort.password_confirmation"
            :requirements="policy.requirements"
            :minimum="policy.minimum"
            :error="passwort.errors.password"
            label="Neues Passwort"
          />

          <p class="hint">
            Nach dem Wechsel werden alle anderen Sitzungen abgemeldet. Diese
            hier bleibt bestehen.
          </p>

          <div class="button-row">
            <button type="submit" class="button primary" :disabled="passwort.processing">
              {{ passwort.processing ? 'Wird geändert …' : 'Passwort ändern' }}
            </button>
          </div>
        </form>
      </Section>

      <!--
        Die Darstellung steht bei den Angaben zum Konto und nicht unter den
        Servereinstellungen: Sie gilt für dieses eine Konto und nicht für den
        Server. Wer sie dort suchte, suchte etwas, das alle beträfe.
      -->
      <Section title="Darstellung">
        <!--
          Die drei Knöpfe sind eine Wahl und keine drei Aktionen — deshalb
          stehen sie in einer Reihe und tragen `.aktiv` statt `.wichtig`. Wie
          ein gewählter aussieht, steht in app.css.
        -->
        <div class="button-row spaced">
          <button
            v-for="option in themes"
            :key="String(option.wert)"
            type="button"
            class="button"
            :class="{ active: props.profile.theme === option.wert }"
            :aria-pressed="props.profile.theme === option.wert"
            :disabled="darstellung.processing || props.impersonating"
            @click="saveTheme(option.wert)"
          >{{ option.name }}</button>
        </div>

        <p class="hint">
          „System" übernimmt, was Ihr Betriebssystem gerade vorgibt, und
          wechselt mit. Die Wahl gilt für dieses Konto, auch an einem anderen
          Rechner.
        </p>
      </Section>

      <Section title="Sicherheit">
        <table class="pairs">
          <tbody>
            <tr>
              <td class="quiet">Zweiter Faktor</td>
              <td class="right">
                <Badge :kind="props.profile.two_factor ? 'ok' : 'neutral'">
                  {{ props.profile.two_factor ? 'eingerichtet' : 'nicht eingerichtet' }}
                </Badge>
              </td>
              <td class="right">
                <Link href="/settings/two-factor" class="link">verwalten</Link>
              </td>
            </tr>
            <tr>
              <td class="quiet">Letzte Anmeldung</td>
              <td class="right">{{ props.profile.last_login_at ?? '—' }}</td>
            </tr>
            <tr>
              <td class="quiet">Von</td>
              <td class="right ident">{{ props.profile.last_login_ip ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </Section>
    </div>
  </PanelLayout>
</template>

<style scoped>
/* Die Wahl der Darstellung braucht denselben Abstand nach oben wie ein Feld —
   sonst klebt die erste Knopfreihe an der Bereichslinie. */
.spaced {
  margin-top: 16px;
}
</style>
