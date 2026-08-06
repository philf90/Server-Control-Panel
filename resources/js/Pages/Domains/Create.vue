<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import Section from '../../Components/Section.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import FormErrors from '../../Components/FormErrors.vue'

interface PhpOption {
  version: string
  selectable: boolean
  reason: string | null
}

const props = defineProps<{
  subscription: { id: number; name: string }
  parents: { id: number; name: string }[]
  php: PhpOption[]
  counts: Record<string, { label: string; used: number; limit: number | null }>
}>()

/*
 * Ein Fehler, der zu keinem Feld gehört.
 *
 * Der Dienst meldet unter `domain`, was die Domain als Ganzes betrifft — ein
 * gesperrtes Abonnement, eine Hauptdomain, die nicht einzeln geht. Er steht
 * nicht in `form.errors`, weil es kein Feld dieses Namens gibt; er kommt aus
 * der Seite.
 */
const allgemein = computed(() => {
  const errors = usePage().props.errors as Record<string, string> | undefined

  return errors?.domain ?? null
})

const form = useForm({
  type: 'addon',
  name: '',
  parent_domain_id: props.parents[0]?.id ?? null,
  document_root: '',
  php_version: props.php.find((o) => o.selectable)?.version ?? '',
  redirect_target: '',
  redirect_kind: 'temporary',
})

const brauchtEltern = computed(() => form.type === 'subdomain' || form.type === 'alias')

// Ein Alias liefert aus dem Verzeichnis seiner Elterndomain aus; eine
// Weiterleitung sucht nie eine Datei. In beiden Fällen ist ein Feld für das
// DocumentRoot ein Feld, das nichts bewirkt.
const eigenesVerzeichnis = computed(() => form.type !== 'alias' && form.redirect_target === '')
</script>

<template>
  <Head title="Domain anlegen" />

  <PanelLayout title="Domain anlegen" :subline="props.subscription.name">
    <template #breadcrumb>
      <Link href="/subscriptions" class="link">Abonnements</Link> ·
      <Link :href="`/subscriptions/${props.subscription.id}`" class="link">
        {{ props.subscription.name }}
      </Link>
    </template>

    <!--
      Ein Fehler, der keinem Feld gehört, steht als Meldung über dem Formular
      und nicht darunter: Unter dem Absendeknopf hätte er dieselbe Stelle wie
      die Fehler der einzelnen Felder und sähe aus, als betreffe er das letzte
      davon.
    -->
    <p v-if="allgemein" class="notice critical">
      <span>{{ allgemein }}</span>
    </p>

    <p class="notice neutral">
      <span>
        <template v-for="(stand, key) in props.counts" :key="key">
          {{ stand.label }}: <b>{{ stand.used }}</b> von {{ stand.limit ?? 'unbegrenzt' }}.
        </template>
        Aliasse zählen auf kein Kontingent.
      </span>
    </p>

    <FormErrors />

    <form class="form" @submit.prevent="form.post(`/subscriptions/${props.subscription.id}/domains`)">
      <Section title="Sorte und Name">
        <label class="field">
          <span>Sorte</span>
          <select v-model="form.type">
            <option value="addon">Zusatzdomain</option>
            <option value="subdomain">Subdomain</option>
            <option value="alias">Alias</option>
          </select>
        </label>
        <p v-if="form.errors.type" class="error">{{ form.errors.type }}</p>

        <label v-if="brauchtEltern" class="field">
          <span>Gehört zu</span>
          <select v-model="form.parent_domain_id" required>
            <option v-for="p in props.parents" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </label>
        <template v-if="brauchtEltern">
          <p v-if="form.errors.parent_domain_id" class="error">{{ form.errors.parent_domain_id }}</p>
          <p class="hint">
            Eine Subdomain muss unterhalb dieser Domain liegen. Ein Alias darf
            jeden Namen tragen — er ist ein zweiter Name für dieselben Inhalte.
          </p>
        </template>

        <label class="field">
          <span>Name</span>
          <input v-model="form.name" type="text" placeholder="beispiel.de" autocomplete="off" required>
        </label>
        <p v-if="form.errors.name" class="error">{{ form.errors.name }}</p>
        <p class="hint">
          Kleinbuchstaben, Ziffern, Punkt und Bindestrich. Umlautdomains in
          Punycode (<span class="ident">xn--…</span>).
        </p>
      </Section>

      <Section v-if="eigenesVerzeichnis" title="Auslieferung">
        <label class="field">
          <span>Verzeichnis</span>
          <input v-model="form.document_root" type="text" :placeholder="form.name || 'beispiel.de'" autocomplete="off">
        </label>
        <p v-if="form.errors.document_root" class="error">{{ form.errors.document_root }}</p>
        <p class="hint">
          Relativ zum Abonnement. Leer lassen für ein Verzeichnis mit dem Namen
          der Domain.
        </p>

        <label class="field">
          <span>PHP-Version</span>
          <select v-model="form.php_version">
            <!--
              Abgeblendete Versionen bleiben sichtbar: Der Plan gibt sie her,
              der Server hat sie nicht. Wer sie gar nicht sähe, hielte die
              Lücke für eine Frage seines Vertrags.
            -->
            <option
              v-for="option in props.php"
              :key="option.version"
              :value="option.version"
              :disabled="!option.selectable"
            >
              {{ option.version }}<template v-if="option.reason"> · {{ option.reason }}</template>
            </option>
          </select>
        </label>
        <p v-if="form.errors.php_version" class="error">{{ form.errors.php_version }}</p>
        <p v-if="props.php.length === 0" class="hint">
          Der Plan gibt keine PHP-Version frei. Diese Domain liefert dann nur
          statische Dateien aus.
        </p>
      </Section>

      <Section v-if="form.type !== 'alias'" title="Weiterleitung">
        <label class="field">
          <span>Ziel</span>
          <input v-model="form.redirect_target" type="url" placeholder="https://ziel.de/" autocomplete="off">
        </label>
        <p v-if="form.errors.redirect_target" class="error">{{ form.errors.redirect_target }}</p>
        <p class="hint">
          Leer lassen, wenn diese Domain eigene Dateien ausliefert. Mit Ziel
          antwortet nginx selbst — ohne Verzeichnis und ohne PHP.
        </p>

        <template v-if="form.redirect_target !== ''">
          <label class="field">
            <span>Art</span>
            <select v-model="form.redirect_kind">
              <option value="temporary">vorübergehend (302)</option>
              <option value="permanent">dauerhaft (301)</option>
            </select>
          </label>
          <p class="hint">
            Eine dauerhafte Weiterleitung merkt sich der Browser. Nach einer
            Rücknahme rufen Besucher noch lange das alte Ziel auf.
          </p>
        </template>
      </Section>

      <div class="button-row">
        <button type="submit" class="button primary" :disabled="form.processing">
          {{ form.processing ? 'Wird angelegt …' : 'Anlegen' }}
        </button>
        <Link class="button" :href="`/subscriptions/${props.subscription.id}`">Abbrechen</Link>
      </div>
    </form>
  </PanelLayout>
</template>
