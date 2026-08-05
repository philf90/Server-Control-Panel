<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import Section from '../../Components/Section.vue'
import CodeField from '../../Components/CodeField.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

const props = defineProps<{
  active: boolean
  secret?: string
  uri?: string
  remainingRecoveryCodes?: number
}>()

const page = usePage()

/*
 * Die Wiederherstellungscodes kommen einmal über die Kurzmeldung und werden
 * nirgends abgelegt — auch nicht hier im Zustand der Seite. Wer sie beim
 * Neuladen verliert, muss den zweiten Faktor neu einrichten. Das ist der
 * Preis dafür, dass auch das Panel sie nicht mehr kennt.
 */
const recoveryCodes = computed(
  () => (page.props.flash as Record<string, unknown> | undefined)?.recoveryCodes as string[] | undefined,
)

const setup = useForm({ code: '' })
const off = useForm({ code: '' })
</script>

<template>
  <Head title="Zweiter Faktor" />

  <PanelLayout title="Zweiter Faktor" subline="Einmalkennwörter aus einer Authenticator-App">
    <template #breadcrumb>
      <Link href="/settings/profile" class="link">Mein Konto</Link>
    </template>

    <div class="sections">
      <!--
        Die Codes stehen ganz oben und über die volle Breite: Sie erscheinen
        genau einmal, und wer sie übersieht, muss den zweiten Faktor neu
        einrichten. Ein Bereich neben zweien anderen wäre für diesen einen
        Augenblick zu leise.
      -->
      <Section v-if="recoveryCodes" title="Wiederherstellungscodes" full>
        <p class="notice warn">
          <span>
            Jetzt notieren oder ausdrucken. Sie werden nicht wieder angezeigt —
            auch das Panel kennt sie ab jetzt nicht mehr. Jeder Code gilt
            einmal.
          </span>
        </p>

        <ul class="codes">
          <li v-for="code in recoveryCodes" :key="code" class="ident">{{ code }}</li>
        </ul>
      </Section>

      <Section v-if="!props.active" title="Einrichten">
        <p class="section-note">
          Diesen Schlüssel in einer Authenticator-App hinterlegen und danach den
          angezeigten Code eintragen. Erst dann gilt der zweite Faktor.
        </p>

        <table class="pairs">
          <tbody>
            <tr><td class="quiet">Schlüssel</td><td class="right ident">{{ props.secret }}</td></tr>
            <tr><td class="quiet">Adresse</td><td class="right ident">{{ props.uri }}</td></tr>
          </tbody>
        </table>

        <form @submit.prevent="setup.post('/settings/two-factor')">
          <CodeField v-model="setup.code" label="Code aus der App" :error="setup.errors.code" />

          <div class="button-row spaced">
            <button type="submit" class="button primary" :disabled="setup.processing">Bestätigen</button>
          </div>
        </form>
      </Section>

      <Section v-else title="Abschalten">
        <p class="section-note">
          Der zweite Faktor ist eingerichtet. Es sind noch
          {{ props.remainingRecoveryCodes }} Wiederherstellungscodes übrig.
        </p>

        <form @submit.prevent="off.delete('/settings/two-factor')">
          <CodeField
            v-model="off.code"
            label="Code zum Abschalten"
            hint="Ohne gültigen Code bleibt der zweite Faktor an — auch für den, der schon angemeldet ist."
            :error="off.errors.code"
          />

          <div class="button-row spaced">
            <button type="submit" class="button danger" :disabled="off.processing">Abschalten</button>
          </div>
        </form>
      </Section>
    </div>
  </PanelLayout>
</template>

<style scoped>
/*
 * Die Codes stehen in Spalten und nicht in einer Aufzählung.
 *
 * Man liest sie ab und tippt sie irgendwo ein — untereinander in einer langen
 * Reihe verliert man die Zeile. Monospace kommt aus `.ident` in app.css.
 */
.codes {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 4px 20px;
  margin: 16px 0 0;
  padding: 0;
  list-style: none;
  font-size: var(--text-body);
  color: var(--text-strong);
}

.spaced {
  margin-top: var(--gap);
}
</style>
