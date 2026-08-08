<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import Section from '../../Components/Section.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import FormErrors from '../../Components/FormErrors.vue'

const props = defineProps<{
  subscription: { id: number; name: string; prefix: string }
  collations: string[]
  quota: { used: number; limit: number | null }
}>()

const form = useForm({
  label: '',
  collation: props.collations[0] ?? 'utf8mb4_unicode_ci',

  /*
   * Ein Zugang gleich mit, und er ist vorbelegt.
   *
   * Eine Datenbank ohne Zugang ist ein Schema, in das niemand hineinkommt —
   * das ist kein sinnvoller Anfangszustand, sondern ein halber. Wer den zweiten
   * Schritt trotzdem einzeln gehen will, leert das Feld.
   */
  user_label: 'user',
})

/*
 * Der vollständige Name, während getippt wird.
 *
 * **Er steht da, weil der Kunde ihn braucht** — er trägt ihn gleich in die
 * Konfigurationsdatei seiner Anwendung ein, und dort heisst die Datenbank
 * `p1001_shop` und nicht `shop`. Ein Formular, das nur nach dem Zusatz fragt
 * und den fertigen Namen erst auf der nächsten Seite zeigt, schickt ihn zurück.
 */
const fullName = computed(() => `${props.subscription.prefix}_${form.label || '…'}`)
const fullUser = computed(() => `${props.subscription.prefix}_${form.user_label || '…'}`)
</script>

<template>
  <Head title="Datenbank anlegen" />

  <PanelLayout title="Datenbank anlegen" :subline="props.subscription.name">
    <template #breadcrumb>
      <Link href="/databases" class="link">Datenbanken</Link> ·
      <Link :href="`/subscriptions/${props.subscription.id}`" class="link">
        {{ props.subscription.name }}
      </Link>
    </template>

    <p class="notice neutral">
      <span>
        Datenbanken: <b>{{ props.quota.used }}</b> von {{ props.quota.limit ?? 'unbegrenzt' }}.
        Datenbankbenutzer zählen nicht getrennt.
      </span>
    </p>

    <FormErrors />

    <form class="form" @submit.prevent="form.post(`/subscriptions/${props.subscription.id}/databases`)">
      <Section title="Datenbank">
        <label class="field">
          <span>Name</span>
          <input v-model="form.label" type="text" placeholder="shop" autocomplete="off" required>
        </label>
        <p v-if="form.errors.label" class="error">{{ form.errors.label }}</p>
        <p class="hint">
          Heisst auf dem Server <span class="ident">{{ fullName }}</span> — das Präfix ist
          der Systembenutzer des Abonnements und wird vergeben, nicht gewählt.
          Kleinbuchstaben, Ziffern und Unterstrich, beginnend mit einem
          Buchstaben, höchstens sechzehn Zeichen.
        </p>

        <label class="field">
          <span>Sortierung</span>
          <select v-model="form.collation">
            <option v-for="c in props.collations" :key="c" :value="c">{{ c }}</option>
          </select>
        </label>
        <p v-if="form.errors.collation" class="error">{{ form.errors.collation }}</p>
        <p class="hint">
          Der Zeichensatz ist immer <span class="ident">utf8mb4</span>.
          <span class="ident">unicode_ci</span> sortiert nach dem
          Unicode-Algorithmus, <span class="ident">general_ci</span> ist
          schneller und schlichter, <span class="ident">bin</span> vergleicht
          Byte für Byte.
        </p>
      </Section>

      <Section title="Zugang">
        <label class="field">
          <span>Benutzername</span>
          <input v-model="form.user_label" type="text" placeholder="user" autocomplete="off">
        </label>
        <p v-if="form.errors.user_label" class="error">{{ form.errors.user_label }}</p>
        <p class="hint">
          Heisst auf dem Server <span class="ident">{{ fullUser }}</span> und darf sich nur
          von <span class="ident">localhost</span> anmelden. Leer lassen, wenn
          zunächst kein Zugang entstehen soll.
        </p>

        <!-- Die Ansage steht **vor** dem Absenden und nicht erst auf der Seite
             mit dem Passwort. Wer sie danach liest, hat den Kasten mit dem
             Passwort vielleicht schon weggeklickt. -->
        <p class="notice warn">
          <span>
            Das Passwort wird erzeugt und danach <b>genau einmal</b> angezeigt.
            Es wird nirgends gespeichert — wer es verliert, setzt ein neues und
            trägt es in seine Anwendung ein.
          </span>
        </p>
      </Section>

      <div class="button-row">
        <button type="submit" class="button primary" :disabled="form.processing">Anlegen</button>
        <Link href="/databases" class="button">Abbrechen</Link>
      </div>
    </form>
  </PanelLayout>
</template>
