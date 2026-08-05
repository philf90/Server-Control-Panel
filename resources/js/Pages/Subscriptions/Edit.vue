<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import Bereich from '../../Components/Bereich.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

interface QuotaEntry {
  key: string
  label: string
  hint: string
  unit: string | null
  selection: boolean
  minimum: number
  maximum: number
  plan_value: string
  override: number | string[] | null
}

const props = defineProps<{
  subscription: { id: number; name: string; plan_id: number; status: string }
  plans: { id: number; label: string }[]
  quotas: QuotaEntry[]
  phpVersions: string[]
}>()

/*
 * Ein Kontingent hat hier zwei Zustände und nicht einen Wert.
 *
 * „Gilt der Plan" ist etwas anderes als „gilt zufällig derselbe Wert wie im
 * Plan": Das erste zieht mit, wenn der Plan geändert wird, das zweite nicht.
 * Ein einzelnes Zahlenfeld könnte den Unterschied nicht ausdrücken — es stünde
 * dort immer eine Zahl, und niemand wüsste, ob sie geerbt oder gesetzt ist.
 * Deshalb je Kontingent ein Schalter und dahinter das Feld.
 *
 * Geschickt wird nur, was abweicht. Der Schlüssel eines abgewählten
 * Kontingents fehlt in `overrides`, und genau das heisst auf der Gegenseite
 * „gilt der Plan" (App\Support\Plans\Quotas::overrides).
 */
const form = useForm<{ plan_id: number; overrides: Record<string, number | string[] | null> }>({
  plan_id: props.subscription.plan_id,
  overrides: Object.fromEntries(
    props.quotas.filter((q) => q.override !== null).map((q) => [q.key, q.override as number | string[]]),
  ),
})

function abweichend(key: string): boolean {
  return Object.prototype.hasOwnProperty.call(form.overrides, key)
}

/*
 * Beim Anschalten braucht das Feld einen gültigen Ausgangswert. Das Minimum
 * ist der einzige, der das für jedes Kontingent ist — und es ist ehrlicher als
 * der Planwert: Wer abweichen will, soll den Wert eintragen und nicht den
 * geerbten stehen lassen, der dann als Übersteuerung festgeschrieben wäre.
 */
function umschalten(entry: QuotaEntry, an: boolean): void {
  const overrides = { ...form.overrides }

  if (an) overrides[entry.key] = entry.selection ? [...props.phpVersions] : entry.minimum
  else delete overrides[entry.key]

  form.overrides = overrides
}

function versionen(key: string): string[] {
  const value = form.overrides[key]

  return Array.isArray(value) ? value : []
}

function versionUmschalten(key: string, version: string, an: boolean): void {
  const gewaehlt = new Set(versionen(key))

  if (an) gewaehlt.add(version)
  else gewaehlt.delete(version)

  // Über den Katalog sortiert und nicht in Klickreihenfolge — sonst steht
  // dieselbe Auswahl je nach Bedienung anders da.
  form.overrides = { ...form.overrides, [key]: props.phpVersions.filter((v) => gewaehlt.has(v)) }
}

function fehler(key: string): string | undefined {
  return (form.errors as Record<string, string>)[key]
}

function submit(): void {
  form.patch(`/subscriptions/${props.subscription.id}`)
}
</script>

<template>
  <Head :title="`${props.subscription.name} bearbeiten`" />

  <PanelLayout title="Plan und Kontingente">
    <template #pfad>
      <Link href="/subscriptions" class="verweis">Abonnements</Link> ·
      <Link :href="`/subscriptions/${props.subscription.id}`" class="verweis">
        {{ props.subscription.name }}
      </Link>
    </template>

    <!--
      Der Hinweis steht über dem Formular, weil er das Ergebnis des Absendens
      betrifft: Eine geänderte Speichergrenze ist kein Datensatz, sondern ein
      Vorgang auf dem Server, und danach steht man auf der Vorgangsseite und
      nicht wieder hier.
    -->
    <p class="meldung neutral">
      <span>
        Eine geänderte Speichergrenze wird als Vorgang auf das Dateisystem
        angewandt (<span class="kennung">setquota</span>). Alle übrigen
        Kontingente gelten beim nächsten Anlegen eines Objekts und lösen keinen
        Vorgang aus.
      </span>
    </p>

    <form class="maske" @submit.prevent="submit">
      <Bereich titel="Plan">
        <label class="feld">
          <span>Plan</span>
          <select v-model.number="form.plan_id">
            <option v-for="p in props.plans" :key="p.id" :value="p.id">{{ p.label }}</option>
          </select>
        </label>
        <p v-if="fehler('plan_id')" class="fehler">{{ fehler('plan_id') }}</p>
        <p class="hinweis">
          Der Plan gibt jedes Kontingent vor, das unten nicht abweicht. Ein
          Wechsel wirkt sofort auf alle geerbten Werte.
        </p>
      </Bereich>

      <!--
        Dieselbe Spaltenaufteilung wie im Formular eines Plans: Zwölf
        Kontingente untereinander sind eine Seite zum Rollen, nebeneinander
        eine Liste zum Überfliegen.
      -->
      <Bereich titel="Kontingente" voll>
        <div class="posten-raster">
          <div v-for="entry in props.quotas" :key="entry.key" class="posten">
            <label class="schalter">
              <input
                type="checkbox"
                :checked="abweichend(entry.key)"
                @change="umschalten(entry, ($event.target as HTMLInputElement).checked)"
              >
              <span>
                {{ entry.label }}
                <small class="hinweis">Vom Plan: {{ entry.plan_value }}</small>
              </span>
            </label>

            <template v-if="abweichend(entry.key)">
              <div v-if="entry.selection" class="auswahl abhaengig">
                <label v-for="version in props.phpVersions" :key="version" class="schalter">
                  <input
                    type="checkbox"
                    :checked="versionen(entry.key).includes(version)"
                    @change="versionUmschalten(entry.key, version, ($event.target as HTMLInputElement).checked)"
                  >
                  <span class="kennung">{{ version }}</span>
                </label>
              </div>

              <div v-else class="mit-einheit abhaengig">
                <input
                  :id="`quota-${entry.key}`"
                  v-model.number="form.overrides[entry.key] as number"
                  type="number"
                  :min="entry.minimum"
                  :max="entry.maximum"
                  required
                >
                <span v-if="entry.unit" class="einheit">{{ entry.unit }}</span>
              </div>

              <p class="hinweis abhaengig">{{ entry.hint }}</p>
            </template>

            <p v-if="fehler(`overrides.${entry.key}`)" class="fehler">{{ fehler(`overrides.${entry.key}`) }}</p>
          </div>
        </div>
      </Bereich>

      <div class="knopfreihe">
        <button type="submit" class="knopf wichtig" :disabled="form.processing">
          {{ form.processing ? 'Wird gespeichert …' : 'Speichern' }}
        </button>
        <Link class="knopf" :href="`/subscriptions/${props.subscription.id}`">Abbrechen</Link>
      </div>
    </form>
  </PanelLayout>
</template>

