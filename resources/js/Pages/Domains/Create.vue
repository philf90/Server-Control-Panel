<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

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
    <p class="hinweis-block">
      <template v-for="(stand, key) in props.counts" :key="key">
        {{ stand.label }}: {{ stand.used }} von {{ stand.limit ?? 'unbegrenzt' }}.
      </template>
      Aliasse zählen auf kein Kontingent.
    </p>

    <form class="maske" @submit.prevent="form.post(`/subscriptions/${props.subscription.id}/domains`)">
      <fieldset>
        <legend>Sorte und Name</legend>

        <label>Sorte
          <select v-model="form.type">
            <option value="addon">Zusatzdomain</option>
            <option value="subdomain">Subdomain</option>
            <option value="alias">Alias</option>
          </select>
          <small v-if="form.errors.type" class="fehler">{{ form.errors.type }}</small>
        </label>

        <label v-if="brauchtEltern">Gehört zu
          <select v-model="form.parent_domain_id" required>
            <option v-for="p in props.parents" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
          <small v-if="form.errors.parent_domain_id" class="fehler">{{ form.errors.parent_domain_id }}</small>
          <small class="hinweis">
            Eine Subdomain muss unterhalb dieser Domain liegen. Ein Alias darf jeden
            Namen tragen — er ist ein zweiter Name für dieselben Inhalte.
          </small>
        </label>

        <label>Name
          <input v-model="form.name" type="text" placeholder="beispiel.de" autocomplete="off" required>
          <small v-if="form.errors.name" class="fehler">{{ form.errors.name }}</small>
          <small class="hinweis">
            Kleinbuchstaben, Ziffern, Punkt und Bindestrich. Umlautdomains in
            Punycode (<code>xn--…</code>).
          </small>
        </label>
      </fieldset>

      <fieldset v-if="eigenesVerzeichnis">
        <legend>Auslieferung</legend>

        <label>Verzeichnis
          <input v-model="form.document_root" type="text" :placeholder="form.name || 'beispiel.de'" autocomplete="off">
          <small v-if="form.errors.document_root" class="fehler">{{ form.errors.document_root }}</small>
          <small class="hinweis">
            Relativ zum Abonnement. Leer lassen für ein Verzeichnis mit dem Namen
            der Domain.
          </small>
        </label>

        <label>PHP-Version
          <select v-model="form.php_version">
            <option
              v-for="option in props.php"
              :key="option.version"
              :value="option.version"
              :disabled="!option.selectable"
            >
              {{ option.version }}<template v-if="option.reason"> · {{ option.reason }}</template>
            </option>
          </select>
          <small v-if="form.errors.php_version" class="fehler">{{ form.errors.php_version }}</small>
          <!--
            Abgeblendete Versionen bleiben sichtbar: Der Plan gibt sie her, der
            Server hat sie nicht. Wer sie gar nicht sähe, hielte die Lücke für
            eine Frage seines Vertrags.
          -->
          <small v-if="props.php.length === 0" class="hinweis">
            Der Plan gibt keine PHP-Version frei. Diese Domain liefert dann nur
            statische Dateien aus.
          </small>
        </label>
      </fieldset>

      <fieldset v-if="form.type !== 'alias'">
        <legend>Weiterleitung</legend>

        <label>Ziel
          <input v-model="form.redirect_target" type="url" placeholder="https://ziel.de/" autocomplete="off">
          <small v-if="form.errors.redirect_target" class="fehler">{{ form.errors.redirect_target }}</small>
          <small class="hinweis">
            Leer lassen, wenn diese Domain eigene Dateien ausliefert. Mit Ziel
            antwortet nginx selbst — ohne Verzeichnis und ohne PHP.
          </small>
        </label>

        <label v-if="form.redirect_target !== ''">Art
          <select v-model="form.redirect_kind">
            <option value="temporary">vorübergehend (302)</option>
            <option value="permanent">dauerhaft (301)</option>
          </select>
          <small class="hinweis">
            Eine dauerhafte Weiterleitung merkt sich der Browser. Nach einer
            Rücknahme rufen Besucher noch lange das alte Ziel auf.
          </small>
        </label>
      </fieldset>

      <p v-if="allgemein" class="fehler">{{ allgemein }}</p>

      <div class="knopfreihe">
        <button type="submit" class="knopf wichtig" :disabled="form.processing">
          {{ form.processing ? 'wird angelegt …' : 'Anlegen' }}
        </button>
        <a class="knopf" :href="`/subscriptions/${props.subscription.id}`">Abbrechen</a>
      </div>
    </form>
  </PanelLayout>
</template>

<style scoped>
/* Dieselben Marken wie im Formular für Abonnements — die Gliederung soll
   über die Module hinweg dieselbe bleiben. */
.hinweis-block { max-width: 544px; margin: 0 0 var(--gap); padding: 8px 11px; font-size: var(--text-table); color: var(--text-muted); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 6px; }
.maske { display: flex; flex-direction: column; gap: var(--gap); max-width: 544px; }
fieldset { display: flex; flex-direction: column; gap: 10px; padding: var(--padding); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 8px; }
legend { padding: 0 5px; font-size: var(--text-small); color: var(--text-muted); }
label { display: flex; flex-direction: column; gap: 3px; font-size: var(--text-small); color: var(--text-muted); }
input, select { padding: 6px 8px; font: inherit; font-size: var(--text-input); color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 5px; }
code { font-family: var(--font-mono); }
.hinweis { font-size: var(--text-label); color: var(--text-faint); line-height: 1.45; }
.fehler { font-size: var(--text-small); color: var(--critical); }
</style>
