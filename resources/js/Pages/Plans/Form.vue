<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

interface QuotaEntry {
  key: string
  label: string
  hint: string
  unit: string | null
  selection: boolean
  unlimited: boolean
  minimum: number
  maximum: number
}

interface FeatureEntry {
  key: string
  label: string
  hint: string
}

type QuotaValue = number | null | string[]

const props = defineProps<{
  plan: { id: number; name: string } | null
  values: {
    name: string
    description: string
    is_default: boolean
    quotas: Record<string, QuotaValue>
    features: Record<string, boolean>
  }
  catalog: { quotas: QuotaEntry[]; features: FeatureEntry[]; php_versions: string[] }
  subscriptions: number
}>()

/*
 * Dieses Formular kennt kein einziges Kontingent beim Namen.
 *
 * Es rendert, was im Katalog steht — Beschriftung, Einheit, Grenzen und die
 * Frage, ob „unbegrenzt" für dieses Kontingent überhaupt zulässig ist, kommen
 * alle aus App\Support\Plans\Quota. Ein neues Kontingent braucht deshalb keine
 * Zeile hier. Der umgekehrte Weg — neun Felder von Hand — hätte bedeutet, dass
 * Katalog und Formular auseinanderlaufen können, und zwar still: Ein Feld, das
 * hier fehlt, käme beim Speichern als „nicht gesetzt" an.
 */
const form = useForm({
  name: props.values.name,
  description: props.values.description,
  is_default: props.values.is_default,
  quotas: { ...props.values.quotas },
  features: { ...props.values.features },
})

const editing = computed(() => props.plan !== null)

function isUnlimited(key: string): boolean {
  return form.quotas[key] === null
}

/*
 * Beim Abwählen von „unbegrenzt" muss ein Wert her, und zwar einer, der
 * gültig ist. Das Minimum ist der einzige, der das für jedes Kontingent ist —
 * 64 MB beim Speicher, 0 bei allem, was auch 0 sein darf.
 */
function toggleUnlimited(entry: QuotaEntry, unlimited: boolean): void {
  form.quotas[entry.key] = unlimited ? null : entry.minimum
}

function versions(key: string): string[] {
  const value = form.quotas[key]

  return Array.isArray(value) ? value : []
}

function toggleVersion(key: string, version: string, on: boolean): void {
  const chosen = new Set(versions(key))

  if (on) chosen.add(version)
  else chosen.delete(version)

  // Über den Katalog sortiert und nicht in Klickreihenfolge: Sonst steht
  // dieselbe Auswahl je nach Bedienung anders da und sieht nach einer
  // Änderung aus, wo keine ist.
  form.quotas[key] = props.catalog.php_versions.filter((v) => chosen.has(v))
}

function fieldError(key: string): string | undefined {
  return (form.errors as Record<string, string>)[key]
}

function submit(): void {
  if (props.plan) form.put(`/plans/${props.plan.id}`)
  else form.post('/plans')
}

function remove(): void {
  if (!props.plan) return

  if (!window.confirm(`Plan ${props.plan.name} löschen?`)) return

  router.delete(`/plans/${props.plan.id}`)
}
</script>

<template>
  <Head :title="editing ? 'Plan bearbeiten' : 'Plan anlegen'" />

  <PanelLayout
    :title="editing ? `Plan ${props.plan?.name}` : 'Plan anlegen'"
    :subline="editing ? `${props.subscriptions} Abonnements gebunden` : 'Vorlage für die Kontingente eines Abonnements'"
  >
    <!--
      Die Warnung steht über dem Formular und nicht daneben: Eine Änderung an
      einem Plan mit gebundenen Abonnements wirkt sofort auf alle. Sie erscheint
      nur, wenn tatsächlich welche daran hängen — eine Warnung, die immer da
      steht, liest nach der dritten Bearbeitung niemand mehr.
    -->
    <p v-if="editing && props.subscriptions > 0" class="warnung">
      An diesem Plan hängen {{ props.subscriptions }} Abonnements. Gesenkte Grenzen
      verbieten das Anlegen weiterer Objekte; vorhandene bleiben bestehen.
    </p>

    <p v-if="fieldError('plan')" class="fehler-block">{{ fieldError('plan') }}</p>

    <form class="maske" @submit.prevent="submit">
      <fieldset>
        <legend>Plan</legend>

        <label>Name
          <input v-model="form.name" type="text" required>
          <small v-if="form.errors.name" class="fehler">{{ form.errors.name }}</small>
        </label>

        <label>Beschreibung
          <input v-model="form.description" type="text">
          <small class="hinweis">Erscheint in der Liste. Wofür dieses Paket gedacht ist.</small>
        </label>

        <label class="schalter">
          <input v-model="form.is_default" type="checkbox">
          <span>
            Standardplan
            <small class="hinweis">
              Der Plan, den ein neues Abonnement bekommt. Es gibt genau einen;
              das Setzen hier nimmt ihn dem bisherigen.
            </small>
          </span>
        </label>
      </fieldset>

      <fieldset>
        <legend>Kontingente</legend>

        <div v-for="entry in props.catalog.quotas" :key="entry.key" class="feld">
          <template v-if="entry.selection">
            <span class="beschriftung">{{ entry.label }}</span>
            <div class="auswahl">
              <label v-for="version in props.catalog.php_versions" :key="version" class="version">
                <input
                  type="checkbox"
                  :checked="versions(entry.key).includes(version)"
                  @change="toggleVersion(entry.key, version, ($event.target as HTMLInputElement).checked)"
                >
                <span>{{ version }}</span>
              </label>
            </div>
          </template>

          <template v-else>
            <label class="beschriftung" :for="`quota-${entry.key}`">{{ entry.label }}</label>
            <div class="zeile">
              <input
                :id="`quota-${entry.key}`"
                v-model.number="form.quotas[entry.key] as number"
                type="number"
                :min="entry.minimum"
                :max="entry.maximum"
                :disabled="isUnlimited(entry.key)"
                required
              >
              <span v-if="entry.unit" class="einheit">{{ entry.unit }}</span>
              <label v-if="entry.unlimited" class="unbegrenzt">
                <input
                  type="checkbox"
                  :checked="isUnlimited(entry.key)"
                  @change="toggleUnlimited(entry, ($event.target as HTMLInputElement).checked)"
                >
                <span>unbegrenzt</span>
              </label>
            </div>
          </template>

          <small class="hinweis">{{ entry.hint }}</small>
          <small v-if="fieldError(`quotas.${entry.key}`)" class="fehler">{{ fieldError(`quotas.${entry.key}`) }}</small>
        </div>
      </fieldset>

      <fieldset>
        <legend>Freigaben</legend>

        <label v-for="entry in props.catalog.features" :key="entry.key" class="schalter">
          <input v-model="form.features[entry.key]" type="checkbox">
          <span>
            {{ entry.label }}
            <small class="hinweis">{{ entry.hint }}</small>
          </span>
        </label>
      </fieldset>

      <div class="aktionen">
        <button type="submit" :disabled="form.processing">
          {{ form.processing ? 'Wird gespeichert …' : editing ? 'Speichern' : 'Anlegen' }}
        </button>
        <button v-if="editing" type="button" class="loeschen" @click="remove">Löschen</button>
      </div>
    </form>
  </PanelLayout>
</template>

<style scoped>
.warnung { max-width: 640px; margin: 0 0 var(--gap); padding: 8px 11px; font-size: var(--text-table); color: var(--warn); background: var(--warn-surface); border-radius: 6px; }
.fehler-block { max-width: 640px; margin: 0 0 var(--gap); padding: 8px 11px; font-size: var(--text-table); color: var(--critical); background: var(--critical-surface); border-radius: 6px; }
.maske { display: flex; flex-direction: column; gap: var(--gap); max-width: 640px; }
fieldset { display: flex; flex-direction: column; gap: 10px; padding: var(--padding); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 8px; }
legend { padding: 0 5px; font-size: var(--text-small); color: var(--text-muted); }
label { display: flex; flex-direction: column; gap: 3px; font-size: var(--text-small); color: var(--text-muted); }
.feld { display: flex; flex-direction: column; gap: 3px; }
.beschriftung { font-size: var(--text-small); color: var(--text-muted); }
.zeile { display: flex; align-items: center; gap: 8px; }
.zeile input[type='number'] { width: 130px; }
input[type='text'], input[type='number'] { padding: 6px 8px; font: inherit; font-size: var(--text-input); font-variant-numeric: tabular-nums; color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 5px; }
input:disabled { color: var(--text-faint); background: var(--surface-border); border-color: transparent; }
.einheit { font-size: var(--text-small); color: var(--text-faint); }
.unbegrenzt, .version, .schalter { flex-direction: row; align-items: flex-start; gap: 6px; }
.unbegrenzt span, .version span { font-size: var(--text-small); color: var(--text-muted); }
.schalter > span { display: flex; flex-direction: column; gap: 2px; font-size: var(--text-table); color: var(--text); }
.auswahl { display: flex; flex-wrap: wrap; gap: 14px; padding: 2px 0; }
.version { font-variant-numeric: tabular-nums; }
.hinweis { font-size: var(--text-label); color: var(--text-faint); line-height: 1.45; }
.fehler { font-size: var(--text-small); color: var(--critical); }
.aktionen { display: flex; align-items: center; gap: 12px; }
button[type='submit'] { padding: 8px 16px; font: inherit; font-weight: 600; color: var(--accent-on); background: var(--accent); border: 0; border-radius: 6px; cursor: pointer; }
button[type='submit']:disabled { opacity: .6; cursor: default; }
.loeschen { padding: 8px 14px; font: inherit; font-size: var(--text-table); color: var(--critical); background: transparent; border: 1px solid var(--critical); border-radius: 6px; cursor: pointer; }
</style>
