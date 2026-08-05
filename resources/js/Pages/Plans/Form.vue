<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import Bereich from '../../Components/Bereich.vue'
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
    <template #pfad>
      <Link href="/plans" class="verweis">Pläne</Link>
    </template>

    <p v-if="fieldError('plan')" class="meldung kritisch">
      <span>{{ fieldError('plan') }}</span>
    </p>

    <!--
      Die Warnung steht über dem Formular und nicht daneben: Eine Änderung an
      einem Plan mit gebundenen Abonnements wirkt sofort auf alle. Sie erscheint
      nur, wenn tatsächlich welche daran hängen — eine Warnung, die immer da
      steht, liest nach der dritten Bearbeitung niemand mehr.
    -->
    <p v-if="editing && props.subscriptions > 0" class="meldung warn">
      <span>
        An diesem Plan hängen <b>{{ props.subscriptions }}</b> Abonnements.
        Gesenkte Grenzen verbieten das Anlegen weiterer Objekte; vorhandene
        bleiben bestehen.
      </span>
    </p>

    <form class="maske" @submit.prevent="submit">
      <Bereich titel="Plan">
        <label class="feld">
          <span>Name</span>
          <input v-model="form.name" type="text" required>
        </label>
        <p v-if="form.errors.name" class="fehler">{{ form.errors.name }}</p>

        <label class="feld">
          <span>Beschreibung</span>
          <input v-model="form.description" type="text">
        </label>
        <p class="hinweis">Erscheint in der Liste. Wofür dieses Paket gedacht ist.</p>

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
      </Bereich>

      <!--
        Die Kontingente nehmen die ganze Zeile und stehen darin in Spalten.
        Untereinander in einem halben Grundriss waren es 1400px Lauflänge, und
        daneben blieb die halbe Seite leer — im Browser gesehen. Zwölf Posten
        sind eine Liste zum Überfliegen und keine Folge von Schritten.
      -->
      <Bereich titel="Kontingente" voll>
        <div class="posten-raster">
          <div v-for="entry in props.catalog.quotas" :key="entry.key" class="posten">
            <template v-if="entry.selection">
              <span class="beschriftung">{{ entry.label }}</span>
              <div class="auswahl">
                <label v-for="version in props.catalog.php_versions" :key="version" class="schalter">
                  <input
                    type="checkbox"
                    :checked="versions(entry.key).includes(version)"
                    @change="toggleVersion(entry.key, version, ($event.target as HTMLInputElement).checked)"
                  >
                  <span class="kennung">{{ version }}</span>
                </label>
              </div>
            </template>

            <template v-else>
              <label class="beschriftung" :for="`quota-${entry.key}`">{{ entry.label }}</label>
              <div class="mit-einheit">
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

                <!--
                  „unbegrenzt" steht neben dem Feld und nicht darüber: Es ist die
                  Alternative zu dieser einen Zahl und nicht eine zweite
                  Einstellung. Angehakt blendet es das Feld ab — gestrichelter
                  Rand aus app.css, weil ein Feld ohne Wirkung kein Bedienelement
                  mehr ist.
                -->
                <label v-if="entry.unlimited" class="schalter">
                  <input
                    type="checkbox"
                    :checked="isUnlimited(entry.key)"
                    @change="toggleUnlimited(entry, ($event.target as HTMLInputElement).checked)"
                  >
                  <span>unbegrenzt</span>
                </label>
              </div>
            </template>

              <p class="hinweis">{{ entry.hint }}</p>
            <p v-if="fieldError(`quotas.${entry.key}`)" class="fehler">{{ fieldError(`quotas.${entry.key}`) }}</p>
          </div>
        </div>
      </Bereich>

      <Bereich titel="Freigaben">
        <label v-for="entry in props.catalog.features" :key="entry.key" class="schalter">
          <input v-model="form.features[entry.key]" type="checkbox">
          <span>
            {{ entry.label }}
            <small class="hinweis">{{ entry.hint }}</small>
          </span>
        </label>
      </Bereich>

      <div class="knopfreihe">
        <button type="submit" class="knopf wichtig" :disabled="form.processing">
          {{ form.processing ? 'Wird gespeichert …' : editing ? 'Speichern' : 'Anlegen' }}
        </button>
        <Link href="/plans" class="knopf">Abbrechen</Link>
        <button v-if="editing" type="button" class="knopf gefahr" @click="remove">Löschen</button>
      </div>
    </form>
  </PanelLayout>
</template>

