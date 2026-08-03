<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
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
        <label>Code aus der App
          <input v-model="setup.code" type="text" inputmode="numeric" required>
        </label>
        <small v-if="setup.errors.code">{{ setup.errors.code }}</small>
        <button type="submit" :disabled="setup.processing">Bestätigen</button>
      </form>
    </section>

    <section v-else class="karte">
      <h2>Aktiv</h2>
      <p>
        Der zweite Faktor ist eingerichtet.
        Es sind noch {{ props.remainingRecoveryCodes }} Wiederherstellungscodes übrig.
      </p>

      <form @submit.prevent="off.delete('/settings/two-factor')">
        <label>Zum Abschalten einen gültigen Code eintragen
          <input v-model="off.code" type="text" inputmode="numeric" required>
        </label>
        <small v-if="off.errors.code">{{ off.errors.code }}</small>
        <button type="submit" :disabled="off.processing">Abschalten</button>
      </form>
    </section>
  </PanelLayout>
</template>

<style scoped>
.karte, .codes { max-width: 34rem; padding: var(--padding); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 8px; margin-bottom: var(--gap); }
.codes { border-color: var(--warn); background: var(--warn-surface); }
h2 { margin: 0 0 .4rem; font-size: .9rem; color: var(--text-strong); }
p { margin: 0 0 .6rem; font-size: .85rem; color: var(--text-muted); line-height: 1.5; }
.secret { font-family: var(--font-mono); font-size: .95rem; color: var(--text-strong); letter-spacing: .08em; word-break: break-all; }
.uri { font-family: var(--font-mono); font-size: .7rem; color: var(--text-faint); word-break: break-all; }
ul { margin: 0; padding-left: 1.2rem; font-family: var(--font-mono); font-size: .9rem; color: var(--text-strong); }
form { display: flex; flex-direction: column; gap: .4rem; }
label { display: flex; flex-direction: column; gap: .2rem; font-size: .8rem; color: var(--text-muted); }
input { padding: .4rem .5rem; font: inherit; color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 5px; }
small { font-size: .75rem; color: var(--critical); }
button { align-self: flex-start; padding: .45rem .9rem; font: inherit; font-weight: 600; color: var(--accent-on); background: var(--accent); border: 0; border-radius: 6px; cursor: pointer; }
</style>
