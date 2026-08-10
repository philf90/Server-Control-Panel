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

  /**
   * Die Systeme, in denen hier eine Datenbank entstehen darf.
   *
   * **Die Wahl erscheint nur, wenn es etwas zu wählen gibt.** Ein einzelnes
   * Feld mit einem einzigen Eintrag ist keine Wahl, sondern eine Zeile, die
   * jeder überliest — und auf den allermeisten Servern gibt es nur MariaDB.
   *
   * Jeder Eintrag bringt sein eigenes Präfix mit, weil es zwei verschiedene
   * sind: der Systembenutzer in MariaDB, die gewürfelte Kennung in PostgreSQL.
   */
  engines: { value: string; label: string; prefix: string; collations: boolean }[]
}>()

const form = useForm({
  label: '',
  engine: props.engines[0]?.value ?? '',
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
/*
 * Das Präfix des gewählten Systems — und nicht das des Abonnements.
 *
 * In MariaDB sind beide dasselbe (`p1001`), in PostgreSQL nicht (`x7f3a…`).
 * Wer hier `props.subscription.prefix` nähme, zeigte beim Umschalten weiter den
 * MariaDB-Namen an — und der Kunde trüge ihn in seine Anwendung ein.
 */
const gewaehlt = computed(() => props.engines.find((e) => e.value === form.engine) ?? props.engines[0])
const prefix = computed(() => gewaehlt.value?.prefix ?? props.subscription.prefix)

/**
 * Ob das gewählte System eine Sortierung wählen lässt.
 *
 * **Die Antwort steht am System und nicht an seiner Stelle in der Liste.** Der
 * erste Entwurf verglich mit `engines[0]` — eine Annahme über die Reihenfolge,
 * die beim ersten Umsortieren still falsch geworden wäre.
 */
const waehltSortierung = computed(() => gewaehlt.value?.collations === true)

const fullName = computed(() => `${prefix.value}_${form.label || '…'}`)
const fullUser = computed(() => `${prefix.value}_${form.user_label || '…'}`)
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
        <!-- Die Wahl steht **vor** dem Namen, weil sie ihn ändert: Das Präfix
             im Hinweis darunter hängt am System. -->
        <template v-if="props.engines.length > 1">
          <label class="field">
            <span>System</span>
            <select v-model="form.engine">
              <option v-for="e in props.engines" :key="e.value" :value="e.value">{{ e.label }}</option>
            </select>
          </label>
          <p v-if="form.errors.engine" class="error">{{ form.errors.engine }}</p>
          <p class="hint">
            Beide Systeme stehen auf demselben Server und zählen zusammen auf
            das Kontingent. Nachträglich lässt sich eine Datenbank nicht
            umziehen — dafür gibt es Sichern und Zurückspielen.
          </p>
        </template>

        <label class="field">
          <span>Name</span>
          <input v-model="form.label" type="text" placeholder="shop" autocomplete="off" required>
        </label>
        <p v-if="form.errors.label" class="error">{{ form.errors.label }}</p>
        <p class="hint">
          Heisst auf dem Server <span class="ident">{{ fullName }}</span> — das Präfix
          gehört zum Abonnement und wird vergeben, nicht gewählt.
          Kleinbuchstaben, Ziffern und Unterstrich, beginnend mit einem
          Buchstaben, höchstens sechzehn Zeichen.
        </p>

        <label v-if="waehltSortierung" class="field">
          <span>Sortierung</span>
          <select v-model="form.collation">
            <option v-for="c in props.collations" :key="c" :value="c">{{ c }}</option>
          </select>
        </label>
        <p v-if="form.errors.collation" class="error">{{ form.errors.collation }}</p>
        <p v-if="waehltSortierung" class="hint">
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
          Heisst auf dem Server <span class="ident">{{ fullUser }}</span> und darf sich
          zunächst nur vom Server selbst anmelden. Leer lassen, wenn kein Zugang
          entstehen soll.
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
