<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import Bereich from '../../Components/Bereich.vue'
import Marke from '../../Components/Marke.vue'
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

function rang(status: string): 'ok' | 'warn' | 'kritisch' | 'neutral' {
  if (status === 'active') return 'ok'
  if (status === 'provisioning' || status === 'removing') return 'warn'
  if (status === 'failed') return 'kritisch'

  return 'neutral'
}

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

  <PanelLayout :title="props.domain.name" :subline="props.domain.type_label">
    <template #pfad>
      <Link href="/domains" class="verweis">Domains</Link> ·
      <Link :href="`/subscriptions/${props.domain.subscription_id}`" class="verweis">
        {{ props.domain.subscription ?? '—' }}
      </Link>
    </template>

    <template #aktion>
      <Marke :art="rang(props.domain.status)" :laeuft="props.domain.pending">
        {{ props.domain.status_label }}
      </Marke>
      <Link v-if="props.may.view_logs" class="knopf" :href="`/domains/${props.domain.id}/logs`">
        Protokolle
      </Link>
      <button
        v-if="props.may.delete && props.domain.removable"
        type="button"
        class="knopf gefahr"
        :disabled="props.domain.pending"
        @click="entfernen"
      >
        Entfernen
      </button>
    </template>

    <p v-if="props.domain.pending" class="meldung warn">
      An dieser Domain läuft gerade ein Vorgang. Bis er durch ist, lässt sich
      nichts ändern — der Zustand folgt dem Server und nicht dem Formular.
    </p>

    <div class="bereiche">
      <Bereich titel="Stammdaten">
        <table class="paare">
          <tbody>
            <tr>
              <td class="stumm">Abonnement</td>
              <td class="rechts">
                <Link :href="`/subscriptions/${props.domain.subscription_id}`" class="verweis">
                  {{ props.domain.subscription ?? '—' }}
                </Link>
              </td>
            </tr>
            <tr v-if="props.domain.parent">
              <td class="stumm">Gehört zu</td>
              <td class="rechts kennung name">{{ props.domain.parent }}</td>
            </tr>
            <tr>
              <td class="stumm">Verzeichnis</td>
              <td class="rechts kennung">{{ props.domain.document_root_path ?? '—' }}</td>
            </tr>
            <tr>
              <td class="stumm">PHP</td>
              <td class="rechts">
                <template v-if="props.domain.is_redirect">leitet weiter</template>
                <template v-else>{{ props.domain.php_version ?? 'ohne Handler' }}</template>
              </td>
            </tr>
            <tr v-if="props.domain.log_dir">
              <td class="stumm">Protokolle</td>
              <td class="rechts kennung">{{ props.domain.log_dir }}</td>
            </tr>
          </tbody>
        </table>

        <p v-if="!props.domain.removable" class="erklaer">
          Die Hauptdomain gehört zum Abonnement und wird mit ihm entfernt.
        </p>
      </Bereich>

      <Bereich v-if="props.operations.length > 0" titel="Vorgänge">
        <div class="rollt">
          <table class="stapelt">
            <thead>
              <tr><th>Nummer</th><th>Aufgabe</th><th>Zustand</th><th>Angelegt</th></tr>
            </thead>
            <tbody>
              <tr v-for="op in props.operations" :key="op.id">
                <td data-spalte="Nummer" class="kennung">
                  <Link :href="`/operations/${op.id}`" class="verweis">{{ op.id }}</Link>
                </td>
                <td data-spalte="Aufgabe" class="kennung name">{{ op.task }}</td>
                <td data-spalte="Zustand" class="stumm">{{ op.status_label }}</td>
                <td data-spalte="Angelegt" class="stumm">{{ op.created_at ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Bereich>
    </div>

    <form v-if="props.may.update" @submit.prevent="speichern">
      <div class="bereiche maske-oben">
        <Bereich titel="Verzeichnis und Handler">
          <!--
            Das `<fieldset>` bleibt, obwohl es keinen Rahmen mehr trägt: Es
            schaltet mit einem Attribut jedes Feld darin ab, solange ein Vorgang
            läuft. Die Gliederung macht der Bereich, die Sperre das Fieldset —
            zwei Aufgaben, die vorher ein Element hatte.
          -->
          <fieldset :disabled="props.domain.pending">
            <label v-if="props.domain.type !== 'alias'" class="feld">
              <span>Verzeichnis</span>
              <input v-model="form.document_root" type="text" autocomplete="off">
            </label>
            <p v-if="form.errors.document_root" class="fehler">{{ form.errors.document_root }}</p>
            <p v-if="props.domain.type !== 'alias'" class="hinweis">
              Relativ zum Abonnement, ohne führenden Schrägstrich.
            </p>

            <label v-if="props.domain.type !== 'alias'" class="feld">
              <span>PHP-Version</span>
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
            </label>
            <p v-if="form.errors.php_version" class="fehler">{{ form.errors.php_version }}</p>

            <label class="feld">
              <span>Weiterleitung</span>
              <input v-model="form.redirect_target" type="url" placeholder="leer = keine" autocomplete="off">
            </label>
            <p v-if="form.errors.redirect_target" class="fehler">{{ form.errors.redirect_target }}</p>

            <label v-if="form.redirect_target !== ''" class="feld">
              <span>Art der Weiterleitung</span>
              <select v-model="form.redirect_kind">
                <option value="temporary">vorübergehend (302)</option>
                <option value="permanent">dauerhaft (301)</option>
              </select>
            </label>
          </fieldset>
        </Bereich>

        <!--
          Die PHP-Einstellungen stehen nur da, wenn der Plan sie freigibt und
          das Konto sie ändern darf. Ein abgeblendetes Feld wäre die Auskunft
          „das gibt es, du darfst nur nicht" — richtig, aber hier ohne Nutzen:
          Wer sie braucht, wendet sich an den Betreiber.
        -->
        <Bereich
          v-if="props.may.update_php && props.domain.type !== 'alias'"
          titel="PHP-Einstellungen dieser Domain"
        >
          <fieldset :disabled="props.domain.pending">
            <label v-for="key in props.settings" :key="key" class="feld">
              <span class="kennung">{{ key }}</span>
              <input
                v-model="form.php_settings[key]"
                type="text"
                autocomplete="off"
                :placeholder="platzhalter(key)"
              >
            </label>
          </fieldset>

          <p v-if="form.errors.php_settings" class="fehler">{{ form.errors.php_settings }}</p>
          <p class="hinweis">
            Leer lassen heißt: Vorgabe des Servers. Die Grenzen kommen aus dem
            Plan; <span class="kennung">open_basedir</span> und die Abschottung
            stehen im Pool und lassen sich hier nicht ändern.
          </p>
        </Bereich>

        <Bereich titel="Eigene nginx-Direktiven" voll>
          <fieldset :disabled="props.domain.pending">
            <label class="feld">
              <span>Eine je Zeile</span>
              <textarea v-model="form.nginx_directives" rows="4" spellcheck="false" class="kennungsfeld" />
            </label>
          </fieldset>

          <p v-if="form.errors.nginx_directives" class="fehler">{{ form.errors.nginx_directives }}</p>
          <p class="hinweis">
            Erlaubt sind: {{ props.directives.join(', ') }}. Keine Blöcke, ein
            Semikolon am Ende. Was einen Pfad oder einen Empfänger bestimmt,
            steht nicht darauf.
          </p>
        </Bereich>
      </div>

      <p v-if="allgemein" class="fehler">{{ allgemein }}</p>

      <div class="knopfreihe abschluss">
        <button type="submit" class="knopf wichtig" :disabled="form.processing || props.domain.pending">
          {{ form.processing ? 'wird übernommen …' : 'Übernehmen' }}
        </button>
      </div>
    </form>
  </PanelLayout>
</template>

<style scoped>
/*
 * Das Fieldset trägt nur noch seine Aufgabe und kein Aussehen: Es schaltet die
 * Felder darin ab. Ohne diese Zeilen brächte der Browser seinen eigenen Rahmen
 * mit — den einzigen im ganzen Panel.
 */
fieldset {
  margin: 0;
  padding: 0;
  border: 0;
  min-width: 0;
}

.maske-oben {
  margin-top: var(--block-gap);
}

.kennungsfeld {
  font-family: var(--font-mono);
}

.abschluss {
  margin-top: var(--block-gap);
}
</style>
