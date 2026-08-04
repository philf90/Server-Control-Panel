<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
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

  <PanelLayout :title="`${props.subscription.name} bearbeiten`" subline="Plan und Kontingente">
    <!--
      Der Hinweis steht über dem Formular, weil er das Ergebnis des Absendens
      betrifft: Eine geänderte Speichergrenze ist kein Datensatz, sondern ein
      Vorgang auf dem Server, und danach steht man auf der Vorgangsseite und
      nicht wieder hier.
    -->
    <p class="hinweis-block">
      Eine geänderte Speichergrenze wird als Vorgang auf das Dateisystem
      angewandt (<code>setquota</code>). Alle übrigen Kontingente gelten beim
      nächsten Anlegen eines Objekts und lösen keinen Vorgang aus.
    </p>

    <form class="maske" @submit.prevent="submit">
      <fieldset>
        <legend>Plan</legend>

        <label>Plan
          <select v-model.number="form.plan_id">
            <option v-for="p in props.plans" :key="p.id" :value="p.id">{{ p.label }}</option>
          </select>
          <small class="hinweis">
            Der Plan gibt jedes Kontingent vor, das unten nicht abweicht. Ein
            Wechsel wirkt sofort auf alle geerbten Werte.
          </small>
          <small v-if="fehler('plan_id')" class="fehler">{{ fehler('plan_id') }}</small>
        </label>
      </fieldset>

      <fieldset>
        <legend>Kontingente</legend>

        <div v-for="entry in props.quotas" :key="entry.key" class="feld">
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
            <div v-if="entry.selection" class="auswahl">
              <label v-for="version in props.phpVersions" :key="version" class="version">
                <input
                  type="checkbox"
                  :checked="versionen(entry.key).includes(version)"
                  @change="versionUmschalten(entry.key, version, ($event.target as HTMLInputElement).checked)"
                >
                <span>{{ version }}</span>
              </label>
            </div>

            <div v-else class="zeile">
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

            <small class="hinweis">{{ entry.hint }}</small>
          </template>

          <small v-if="fehler(`overrides.${entry.key}`)" class="fehler">{{ fehler(`overrides.${entry.key}`) }}</small>
        </div>
      </fieldset>

      <div class="knopfreihe">
        <button type="submit" class="knopf wichtig" :disabled="form.processing">
          {{ form.processing ? 'Wird gespeichert …' : 'Speichern' }}
        </button>
        <Link class="knopf" :href="`/subscriptions/${props.subscription.id}`">Abbrechen</Link>
      </div>
    </form>
  </PanelLayout>
</template>

<style scoped>
.hinweis-block { max-width: 640px; margin: 0 0 var(--gap); padding: 8px 11px; font-size: var(--text-table); color: var(--text-muted); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 6px; line-height: 1.5; }
code { font-family: var(--font-mono); color: var(--text); }
.maske { display: flex; flex-direction: column; gap: var(--gap); max-width: 640px; }
fieldset { display: flex; flex-direction: column; gap: 12px; padding: var(--padding); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 8px; }
legend { padding: 0 5px; font-size: var(--text-small); color: var(--text-muted); }
label { display: flex; flex-direction: column; gap: 3px; font-size: var(--text-small); color: var(--text-muted); }
.feld { display: flex; flex-direction: column; gap: 5px; }
.schalter { flex-direction: row; align-items: flex-start; gap: 7px; }
.schalter > span { display: flex; flex-direction: column; gap: 2px; font-size: var(--text-table); color: var(--text); }
.zeile { display: flex; align-items: center; gap: 8px; padding-left: 20px; }
.zeile input[type='number'] { width: 130px; }
select, input[type='number'] { padding: 6px 8px; font: inherit; font-size: var(--text-input); font-variant-numeric: tabular-nums; color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 5px; }
select { max-width: 320px; }
.einheit { font-size: var(--text-small); color: var(--text-faint); }
.auswahl { display: flex; flex-wrap: wrap; gap: 14px; padding: 2px 0 2px 20px; }
.version { flex-direction: row; align-items: center; gap: 6px; font-variant-numeric: tabular-nums; }
.version span { font-size: var(--text-small); color: var(--text-muted); }
.hinweis { font-size: var(--text-label); color: var(--text-faint); line-height: 1.45; }
.fehler { font-size: var(--text-small); color: var(--critical); }

/* Unter 480px hat der eingerückte Wert keinen Platz mehr — dort steht er
   linksbündig wie alles andere. */
@media (max-width: 480px) {
  .zeile, .auswahl { padding-left: 0; }
}
</style>
