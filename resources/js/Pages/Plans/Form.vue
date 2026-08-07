<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import Section from '../../Components/Section.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import FormErrors from '../../Components/FormErrors.vue'

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

  /**
   * Zurückgebaute Abonnements — im Panel unsichtbar, für den Fremdschlüssel
   * vorhanden. Sie halten den Plan fest, und deshalb steht die Zahl hier.
   */
  withdrawn: number

  /** Die Pläne, an die die Grabsteine übergehen können — leer ohne Grabsteine. */
  targets: { id: number; name: string }[]
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

/*
 * Wohin die Grabsteine gehen.
 *
 * Vorbelegt mit dem ersten Ziel und nicht leer: Ein leeres Pflichtfeld neben
 * einem Knopf ist eine Falle, die erst nach dem Klick zuschnappt. Wer ein
 * anderes will, wechselt es — die Auswahl steht direkt daneben.
 */
const transferTo = ref<number | null>(props.targets[0]?.id ?? null)

/*
 * Löschbar ist ein Plan, an dem kein lebendes Abonnement mehr hängt — und für
 * dessen Grabsteine ein Ziel dasteht.
 *
 * Gefragt wird dieselbe Frage wie in `PlanController::destroy()`. Vorher hing
 * der Knopf an gar nichts und die Prüfung an der Zahl der *sichtbaren*
 * Abonnements; ein Plan mit einem Grabstein sah damit löschbar aus und
 * antwortete mit einem 500er.
 */
const removable = computed(
  () => props.subscriptions === 0 && (props.withdrawn === 0 || transferTo.value !== null),
)

const target = computed(() => props.targets.find((t) => t.id === transferTo.value))

function remove(): void {
  if (!props.plan || !removable.value) return

  // **Die Rückfrage nennt die Übertragung.** „Plan löschen?" wäre die halbe
  // Wahrheit: Es wandern dabei Zeilen an einen anderen Plan, und das ist der
  // Teil, den man vorher gelesen haben will.
  const frage = props.withdrawn === 0
    ? `Plan ${props.plan.name} löschen?`
    : `Plan ${props.plan.name} löschen und ${props.withdrawn === 1 ? 'ein zurückgebautes Abonnement' : `${props.withdrawn} zurückgebaute Abonnements`} an ${target.value?.name} übertragen?`

  if (!window.confirm(frage)) return

  router.delete(`/plans/${props.plan.id}`, { data: { transfer_to: transferTo.value } })
}
</script>

<template>
  <Head :title="editing ? 'Plan bearbeiten' : 'Plan anlegen'" />

  <PanelLayout
    :title="editing ? `Plan ${props.plan?.name}` : 'Plan anlegen'"
    :subline="editing ? `${props.subscriptions} Abonnements gebunden` : 'Vorlage für die Kontingente eines Abonnements'"
  >
    <template #breadcrumb>
      <Link href="/plans" class="link">Pläne</Link>
    </template>

    <p v-if="fieldError('plan')" class="notice critical">
      <span>{{ fieldError('plan') }}</span>
    </p>

    <!--
      Die Warnung steht über dem Formular und nicht daneben: Eine Änderung an
      einem Plan mit gebundenen Abonnements wirkt sofort auf alle. Sie erscheint
      nur, wenn tatsächlich welche daran hängen — eine Warnung, die immer da
      steht, liest nach der dritten Bearbeitung niemand mehr.
    -->
    <p v-if="editing && props.subscriptions > 0" class="notice warn">
      <span>
        An diesem Plan hängen <b>{{ props.subscriptions }}</b> Abonnements.
        Gesenkte Grenzen verbieten das Anlegen weiterer Objekte; vorhandene
        bleiben bestehen.
      </span>
    </p>

    <!--
      **Warum der Löschknopf fehlt, steht dort, wo er fehlt.** Ein Knopf, der
      wortlos verschwindet, ist für den Betreiber dasselbe wie einer, der nicht
      funktioniert — er sucht ihn und findet nichts. Diese Meldung erscheint
      nur, wenn ausschliesslich Grabsteine im Weg stehen: Hängen noch lebende
      Abonnements daran, sagt das die Warnung darüber schon.
    -->
    <p v-if="editing && props.subscriptions === 0 && props.withdrawn > 0" class="notice neutral">
      <span>
        <!--
          **Einzahl und Mehrzahl, und der Fall Eins ist der häufige.** Genau ein
          Grabstein ist der Normalfall, wenn jemand ein Abonnement zum
          Ausprobieren angelegt und wieder zurückgebaut hat — und in der ersten
          Fassung stand dort „1 zurückgebaute Abonnements". Aufgefallen ist es
          auf dem Bild, nicht beim Schreiben.
        -->
        <template v-if="props.withdrawn === 1">
          An diesem Plan hängt noch ein zurückgebautes Abonnement. Es ist aus dem
          Panel verschwunden, seine Zeile bleibt aber liegen, damit sein
          Systembenutzer nicht ein zweites Mal vergeben wird.
        </template>
        <template v-else>
          An diesem Plan hängen noch <b>{{ props.withdrawn }}</b> zurückgebaute
          Abonnements. Sie sind aus dem Panel verschwunden, ihre Zeilen bleiben
          aber liegen, damit ihre Systembenutzer nicht ein zweites Mal vergeben
          werden.
        </template>

        <!--
          **Auch dieser Satz zählt mit.** „Beim Löschen gehen sie über" nach
          „seine Zeile bleibt liegen" ist ein Numerusfehler — das Bild bei 390px
          hat ihn gezeigt, der Fliesstext beim Schreiben nicht.
        -->
        <template v-if="props.targets.length > 0 && props.withdrawn === 1">
          Beim Löschen geht sie an den Plan über, der unten neben dem Knopf
          steht.
        </template>
        <template v-else-if="props.targets.length > 0">
          Beim Löschen gehen sie an den Plan über, der unten neben dem Knopf
          steht.
        </template>
        <template v-else>
          Der Plan lässt sich deshalb nicht löschen: Es gibt keinen zweiten, an
          den sie übergehen könnten. Legen Sie zuerst einen weiteren Plan an.
        </template>
      </span>
    </p>

    <FormErrors />

    <form class="form" @submit.prevent="submit">
      <Section title="Plan">
        <label class="field">
          <span>Name</span>
          <input v-model="form.name" type="text" required>
        </label>
        <p v-if="form.errors.name" class="error">{{ form.errors.name }}</p>

        <label class="field">
          <span>Beschreibung</span>
          <input v-model="form.description" type="text">
        </label>
        <p class="hint">Erscheint in der Liste. Wofür dieses Paket gedacht ist.</p>

        <label class="toggle">
          <input v-model="form.is_default" type="checkbox">
          <span>
            Standardplan
            <small class="hint">
              Der Plan, den ein neues Abonnement bekommt. Es gibt genau einen;
              das Setzen hier nimmt ihn dem bisherigen.
            </small>
          </span>
        </label>
      </Section>

      <!--
        Die Kontingente nehmen die ganze Zeile und stehen darin in Spalten.
        Untereinander in einem halben Grundriss waren es 1400px Lauflänge, und
        daneben blieb die halbe Seite leer — im Browser gesehen. Zwölf Posten
        sind eine Liste zum Überfliegen und keine Folge von Schritten.
      -->
      <Section title="Kontingente" full>
        <div class="item-grid">
          <div v-for="entry in props.catalog.quotas" :key="entry.key" class="item">
            <template v-if="entry.selection">
              <span class="label">{{ entry.label }}</span>
              <div class="choices">
                <label v-for="version in props.catalog.php_versions" :key="version" class="toggle">
                  <input
                    type="checkbox"
                    :checked="versions(entry.key).includes(version)"
                    @change="toggleVersion(entry.key, version, ($event.target as HTMLInputElement).checked)"
                  >
                  <span class="ident">{{ version }}</span>
                </label>
              </div>
            </template>

            <template v-else>
              <label class="label" :for="`quota-${entry.key}`">{{ entry.label }}</label>
              <div class="with-unit">
                <input
                  :id="`quota-${entry.key}`"
                  v-model.number="form.quotas[entry.key] as number"
                  type="number"
                  :min="entry.minimum"
                  :max="entry.maximum"
                  :disabled="isUnlimited(entry.key)"
                  required
                >
                <span v-if="entry.unit" class="unit">{{ entry.unit }}</span>

                <!--
                  „unbegrenzt" steht neben dem Feld und nicht darüber: Es ist die
                  Alternative zu dieser einen Zahl und nicht eine zweite
                  Einstellung. Angehakt blendet es das Feld ab — gestrichelter
                  Rand aus app.css, weil ein Feld ohne Wirkung kein Bedienelement
                  mehr ist.
                -->
                <label v-if="entry.unlimited" class="toggle">
                  <input
                    type="checkbox"
                    :checked="isUnlimited(entry.key)"
                    @change="toggleUnlimited(entry, ($event.target as HTMLInputElement).checked)"
                  >
                  <span>unbegrenzt</span>
                </label>
              </div>
            </template>

              <p class="hint">{{ entry.hint }}</p>
            <p v-if="fieldError(`quotas.${entry.key}`)" class="error">{{ fieldError(`quotas.${entry.key}`) }}</p>
          </div>
        </div>
      </Section>

      <Section title="Freigaben">
        <label v-for="entry in props.catalog.features" :key="entry.key" class="toggle">
          <input v-model="form.features[entry.key]" type="checkbox">
          <span>
            {{ entry.label }}
            <small class="hint">{{ entry.hint }}</small>
          </span>
        </label>
      </Section>

      <div class="button-row">
        <button type="submit" class="button primary" :disabled="form.processing">
          {{ form.processing ? 'Wird gespeichert …' : editing ? 'Speichern' : 'Anlegen' }}
        </button>
        <Link href="/plans" class="button">Abbrechen</Link>

        <!--
          Die Auswahl steht neben dem Knopf und nicht oben bei der Meldung: Sie
          gehört zu dieser einen Aktion und zu keiner anderen auf der Seite.
          Ohne Grabsteine gibt es sie nicht — ein Feld, das ohne Anlass
          dasteht, beantwortet eine Frage, die niemand gestellt hat.
        -->
        <label v-if="editing && props.subscriptions === 0 && props.targets.length > 0" class="field inline">
          <span>Übertragen auf</span>
          <select v-model="transferTo">
            <option v-for="t in props.targets" :key="t.id" :value="t.id">{{ t.name }}</option>
          </select>
        </label>

        <button v-if="editing && removable" type="button" class="button danger" @click="remove">Löschen</button>
      </div>
    </form>
  </PanelLayout>
</template>

