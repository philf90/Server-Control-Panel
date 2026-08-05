<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import Bereich from '../../Components/Bereich.vue'
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
    <template #pfad>
      <Link href="/settings/profile" class="verweis">Mein Konto</Link>
    </template>

    <div class="bereiche">
      <!--
        Die Codes stehen ganz oben und über die volle Breite: Sie erscheinen
        genau einmal, und wer sie übersieht, muss den zweiten Faktor neu
        einrichten. Ein Bereich neben zweien anderen wäre für diesen einen
        Augenblick zu leise.
      -->
      <Bereich v-if="recoveryCodes" titel="Wiederherstellungscodes" voll>
        <p class="meldung warn">
          <span>
            Jetzt notieren oder ausdrucken. Sie werden nicht wieder angezeigt —
            auch das Panel kennt sie ab jetzt nicht mehr. Jeder Code gilt
            einmal.
          </span>
        </p>

        <ul class="codes">
          <li v-for="code in recoveryCodes" :key="code" class="kennung">{{ code }}</li>
        </ul>
      </Bereich>

      <Bereich v-if="!props.active" titel="Einrichten">
        <p class="erklaer">
          Diesen Schlüssel in einer Authenticator-App hinterlegen und danach den
          angezeigten Code eintragen. Erst dann gilt der zweite Faktor.
        </p>

        <table class="paare">
          <tbody>
            <tr><td class="stumm">Schlüssel</td><td class="rechts kennung">{{ props.secret }}</td></tr>
            <tr><td class="stumm">Adresse</td><td class="rechts kennung">{{ props.uri }}</td></tr>
          </tbody>
        </table>

        <form @submit.prevent="setup.post('/settings/two-factor')">
          <CodeField v-model="setup.code" label="Code aus der App" :error="setup.errors.code" />

          <div class="knopfreihe abstand">
            <button type="submit" class="knopf wichtig" :disabled="setup.processing">Bestätigen</button>
          </div>
        </form>
      </Bereich>

      <Bereich v-else titel="Abschalten">
        <p class="erklaer">
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

          <div class="knopfreihe abstand">
            <button type="submit" class="knopf gefahr" :disabled="off.processing">Abschalten</button>
          </div>
        </form>
      </Bereich>
    </div>
  </PanelLayout>
</template>

<style scoped>
/*
 * Die Codes stehen in Spalten und nicht in einer Aufzählung.
 *
 * Man liest sie ab und tippt sie irgendwo ein — untereinander in einer langen
 * Reihe verliert man die Zeile. Monospace kommt aus `.kennung` in app.css.
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

.abstand {
  margin-top: var(--gap);
}
</style>
