<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

interface PhpOption {
  version: string
  selectable: boolean
  reason: string | null
}

const props = defineProps<{
  domain: {
    id: number
    name: string
    type: string
    type_label: string
    status: string
    status_label: string
    pending: boolean
    document_root: string | null
    document_root_path: string | null
    php_version: string | null
    php_settings: Record<string, string>
    nginx_directives: string[]
    redirect_target: string | null
    redirect_kind: string | null
    parent: string | null
    subscription: string | null
    subscription_id: number
    removable: boolean
    log_dir: string | null
    is_redirect: boolean
  }
  php: PhpOption[]
  caps: Record<string, number | null>
  settings: string[]
  directives: string[]
  may: { update: boolean; update_php: boolean; delete: boolean; view_logs: boolean }
  operations: { id: number; task: string | null; status_label: string; created_at: string | null }[]
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
  document_root: props.domain.document_root ?? '',
  php_version: props.domain.php_version ?? '',
  php_settings: { ...props.domain.php_settings },
  nginx_directives: props.domain.nginx_directives.join('\n'),
  redirect_target: props.domain.redirect_target ?? '',
  redirect_kind: props.domain.redirect_kind ?? 'temporary',
})

/*
 * Der Platzhalter nennt die Einheit.
 *
 * „höchstens 64" stand hier zuerst und sagt nicht, wovon — im Browser
 * nachgesehen, und genau daran gestolpert. Sekunden bei der Laufzeit, MB bei
 * allem, was eine Größe ist.
 */
function platzhalter(key: string): string {
  const deckel = props.caps[key]

  if (deckel === null || deckel === undefined) return 'Vorgabe des Servers'

  return `höchstens ${deckel} ${key === 'max_execution_time' ? 'Sekunden' : 'MB'}`
}

function speichern(): void {
  // Die Direktiven kommen als Textfeld und gehen als Liste: Eine leere Zeile
  // wäre für den Agenten eine Direktive ohne Inhalt.
  form
    .transform((data) => ({
      ...data,
      nginx_directives: String(data.nginx_directives)
        .split('\n')
        .map((zeile) => zeile.trim())
        .filter((zeile) => zeile !== ''),
    }))
    .patch(`/domains/${props.domain.id}`)
}

/*
 * Zwei Sätze und der Pfad in der Rückfrage.
 *
 * Das Verzeichnis geht mit — so ist es festgelegt, und es gibt noch keine
 * Sicherungen. Wer den Pfad vor dem Bestätigen liest, entfernt nicht die
 * falsche Domain.
 */
function entfernen(): void {
  const pfad = props.domain.document_root_path
  const frage = pfad === null
    ? `${props.domain.name} entfernen? Server-Block und Protokolle werden gelöscht.`
    : `${props.domain.name} entfernen? Server-Block, Protokolle und das Verzeichnis ${pfad} werden gelöscht. Es gibt keine Sicherung.`

  if (!window.confirm(frage)) return

  router.delete(`/domains/${props.domain.id}`)
}
</script>

<template>
  <Head :title="props.domain.name" />

  <PanelLayout :title="props.domain.name" :subline="`${props.domain.type_label} · ${props.domain.status_label}`">
    <p v-if="props.domain.pending" class="hinweis-block">
      An dieser Domain läuft gerade ein Vorgang. Bis er durch ist, lässt sich
      nichts ändern — der Zustand folgt dem Server und nicht dem Formular.
    </p>

    <section class="block">
      <h2 class="section">Stammdaten</h2>
      <dl>
        <dt>Abonnement</dt>
        <dd>
          <Link :href="`/subscriptions/${props.domain.subscription_id}`">
            {{ props.domain.subscription ?? '—' }}
          </Link>
        </dd>
        <dt v-if="props.domain.parent">Gehört zu</dt>
        <dd v-if="props.domain.parent">{{ props.domain.parent }}</dd>
        <dt>Verzeichnis</dt>
        <dd class="fest">{{ props.domain.document_root_path ?? '—' }}</dd>
        <dt>PHP</dt>
        <dd>
          <template v-if="props.domain.is_redirect">leitet weiter</template>
          <template v-else>{{ props.domain.php_version ?? 'ohne Handler' }}</template>
        </dd>
        <dt v-if="props.domain.log_dir">Protokolle</dt>
        <dd v-if="props.domain.log_dir" class="fest">{{ props.domain.log_dir }}</dd>
      </dl>

      <div class="knopfreihe">
        <Link v-if="props.may.view_logs" class="knopf" :href="`/domains/${props.domain.id}/logs`">Protokolle</Link>
        <button
          v-if="props.may.delete && props.domain.removable"
          type="button"
          class="knopf gefahr"
          :disabled="props.domain.pending"
          @click="entfernen"
        >
          Entfernen
        </button>
      </div>

      <p v-if="!props.domain.removable" class="hinweis">
        Die Hauptdomain gehört zum Abonnement und wird mit ihm entfernt.
      </p>
    </section>

    <form v-if="props.may.update" class="block maske" @submit.prevent="speichern">
      <h2 class="section">Auslieferung</h2>

      <fieldset :disabled="props.domain.pending">
        <legend>Verzeichnis und Handler</legend>

        <label v-if="props.domain.type !== 'alias'">Verzeichnis
          <input v-model="form.document_root" type="text" autocomplete="off">
          <small v-if="form.errors.document_root" class="fehler">{{ form.errors.document_root }}</small>
          <small class="hinweis">Relativ zum Abonnement, ohne führenden Schrägstrich.</small>
        </label>

        <label v-if="props.domain.type !== 'alias'">PHP-Version
          <select v-model="form.php_version">
            <option value="">ohne Handler — nur statische Dateien</option>
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
        </label>

        <label>Weiterleitung
          <input v-model="form.redirect_target" type="url" placeholder="leer = keine" autocomplete="off">
          <small v-if="form.errors.redirect_target" class="fehler">{{ form.errors.redirect_target }}</small>
        </label>

        <label v-if="form.redirect_target !== ''">Art der Weiterleitung
          <select v-model="form.redirect_kind">
            <option value="temporary">vorübergehend (302)</option>
            <option value="permanent">dauerhaft (301)</option>
          </select>
        </label>
      </fieldset>

      <!--
        Die PHP-Einstellungen stehen nur da, wenn der Plan sie freigibt und das
        Konto sie ändern darf. Ein abgeblendetes Feld wäre die Auskunft „das
        gibt es, du darfst nur nicht" — richtig, aber hier ohne Nutzen: Wer sie
        braucht, wendet sich an den Betreiber.
      -->
      <fieldset v-if="props.may.update_php && props.domain.type !== 'alias'" :disabled="props.domain.pending">
        <legend>PHP-Einstellungen dieser Domain</legend>

        <label v-for="key in props.settings" :key="key">{{ key }}
          <input
            v-model="form.php_settings[key]"
            type="text"
            autocomplete="off"
            :placeholder="platzhalter(key)"
          >
        </label>

        <small v-if="form.errors.php_settings" class="fehler">{{ form.errors.php_settings }}</small>
        <small class="hinweis">
          Leer lassen heißt: Vorgabe des Servers. Die Grenzen kommen aus dem Plan;
          <code>open_basedir</code> und die Abschottung stehen im Pool und lassen
          sich hier nicht ändern.
        </small>
      </fieldset>

      <fieldset :disabled="props.domain.pending">
        <legend>Eigene nginx-Direktiven</legend>

        <label>Eine je Zeile
          <textarea v-model="form.nginx_directives" rows="4" spellcheck="false" />
          <small v-if="form.errors.nginx_directives" class="fehler">{{ form.errors.nginx_directives }}</small>
          <small class="hinweis">
            Erlaubt sind: {{ props.directives.join(', ') }}. Keine Blöcke, ein
            Semikolon am Ende. Was einen Pfad oder einen Empfänger bestimmt,
            steht nicht darauf.
          </small>
        </label>
      </fieldset>

      <p v-if="allgemein" class="fehler">{{ allgemein }}</p>

      <div class="knopfreihe">
        <button type="submit" class="knopf wichtig" :disabled="form.processing || props.domain.pending">
          {{ form.processing ? 'wird übernommen …' : 'Übernehmen' }}
        </button>
      </div>
    </form>

    <section v-if="props.operations.length > 0" class="block">
      <h2 class="section">Vorgänge</h2>
      <div class="rollt">
        <table class="stapelt">
          <thead>
            <tr><th>#</th><th>Aufgabe</th><th>Zustand</th><th>Angelegt</th></tr>
          </thead>
          <tbody>
            <tr v-for="op in props.operations" :key="op.id">
              <td data-spalte="Nummer"><Link :href="`/operations/${op.id}`">{{ op.id }}</Link></td>
              <td data-spalte="Aufgabe" class="fest">{{ op.task }}</td>
              <td data-spalte="Zustand">{{ op.status_label }}</td>
              <td data-spalte="Angelegt">{{ op.created_at ?? '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </PanelLayout>
</template>

<style scoped>
.block { margin-top: var(--block-gap); }
.block:first-child { margin-top: 0; }
.section { font-size: var(--block-heading-size); font-weight: 600; letter-spacing: -0.01em; color: var(--text-strong); margin: 0 0 var(--block-heading-gap); }
.hinweis-block { max-width: 544px; margin: 0 0 var(--gap); padding: 8px 11px; font-size: var(--text-table); color: var(--warn); background: var(--warn-surface); border-radius: 6px; }
dl { display: grid; grid-template-columns: auto 1fr; gap: 4px 14px; margin: 0 0 var(--gap); font-size: var(--text-table); }
dt { color: var(--text-muted); }
dd { margin: 0; color: var(--text); }
.fest { font-family: var(--font-mono); }
.maske { display: flex; flex-direction: column; gap: var(--gap); max-width: 544px; }
fieldset { display: flex; flex-direction: column; gap: 10px; padding: var(--padding); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 8px; }
legend { padding: 0 5px; font-size: var(--text-small); color: var(--text-muted); }
label { display: flex; flex-direction: column; gap: 3px; font-size: var(--text-small); color: var(--text-muted); }
input, select, textarea { padding: 6px 8px; font: inherit; font-size: var(--text-input); color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 5px; }
textarea { font-family: var(--font-mono); resize: vertical; }
code { font-family: var(--font-mono); }
.hinweis { font-size: var(--text-label); color: var(--text-faint); line-height: 1.45; }
.fehler { font-size: var(--text-small); color: var(--critical); }
table { width: 100%; border-collapse: collapse; font-size: var(--text-table); }
th { text-align: left; color: var(--text-muted); font-weight: 600; }
th, td { padding: 6px 8px; border-bottom: 1px solid var(--line); }
</style>
