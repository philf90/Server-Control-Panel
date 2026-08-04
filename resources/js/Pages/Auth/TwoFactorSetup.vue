<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
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
    <div v-if="recoveryCodes" class="codes">
      <h2>Wiederherstellungscodes</h2>
      <p>
        Jetzt notieren oder ausdrucken. Sie werden nicht wieder angezeigt —
        auch das Panel kennt sie ab jetzt nicht mehr. Jeder Code gilt einmal.
      </p>
      <ul>
        <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
      </ul>
    </div>

    <section v-if="!props.active" class="karte">
      <h2>Einrichten</h2>
      <p>
        Diesen Schlüssel in einer Authenticator-App hinterlegen und danach den
        angezeigten Code eintragen. Erst dann gilt der zweite Faktor.
      </p>
      <p class="secret">{{ props.secret }}</p>
      <p class="uri">{{ props.uri }}</p>

      <form @submit.prevent="setup.post('/settings/two-factor')">
        <CodeField v-model="setup.code" label="Code aus der App" :error="setup.errors.code" />
        <button type="submit" class="knopf wichtig" :disabled="setup.processing">Bestätigen</button>
      </form>
    </section>

    <section v-else class="karte">
      <h2>Aktiv</h2>
      <p>
        Der zweite Faktor ist eingerichtet.
        Es sind noch {{ props.remainingRecoveryCodes }} Wiederherstellungscodes übrig.
      </p>

      <form @submit.prevent="off.delete('/settings/two-factor')">
        <CodeField
          v-model="off.code"
          label="Code zum Abschalten"
          hint="Ohne gültigen Code bleibt der zweite Faktor an — auch für den, der schon angemeldet ist."
          :error="off.errors.code"
        />
        <button type="submit" class="knopf gefahr" :disabled="off.processing">Abschalten</button>
      </form>
    </section>
  </PanelLayout>
</template>

<style scoped>
.karte, .codes { max-width: 544px; padding: var(--padding); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 8px; margin-bottom: var(--gap); }
.codes { border-color: var(--warn); background: var(--warn-surface); }
h2 { margin: 0 0 6px; font-size: var(--text-body); color: var(--text-strong); }
p { margin: 0 0 10px; font-size: var(--text-table); color: var(--text-muted); line-height: 1.5; }
.secret { font-family: var(--font-mono); font-size: var(--text-body); color: var(--text-strong); letter-spacing: .08em; word-break: break-all; }
.uri { font-family: var(--font-mono); font-size: var(--text-label); color: var(--text-faint); word-break: break-all; }
ul { margin: 0; padding-left: 19px; font-family: var(--font-mono); font-size: var(--text-body); color: var(--text-strong); }
form { display: flex; flex-direction: column; gap: 12px; max-width: 288px; }
form .knopf { align-self: flex-start; }
</style>
