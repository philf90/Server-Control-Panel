<script setup lang="ts">
/*
 * Aus welchen Netzen sich ein Verwaltungskonto anmelden darf.
 *
 * **Die eigene Adresse steht oben.** Ohne sie schreibt der Betreiber ein Netz
 * hin und erfährt erst beim Speichern, dass es ihn nicht enthält — der Server
 * weist das ab, aber eine Ablehnung, die man vorher hätte sehen können, ist
 * eine verlorene Runde.
 *
 * **Leer heisst „von überall".** Das steht auch dann da, wenn die Liste leer
 * ist: Ein Formular ohne Zeilen und ohne Satz sieht aus wie eines, das nicht
 * geladen hat.
 */
import { Head, useForm } from '@inertiajs/vue3'
import FormErrors from '../../Components/FormErrors.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'

const props = defineProps<{
  networks: string[]
  address: string | null
  covered: boolean
}>()

/*
 * Eine leere Zeile am Ende, damit immer ein Feld zum Tippen dasteht. Ein
 * „Zeile hinzufügen"-Knopf für den häufigsten Fall — der ersten Zeile — wäre
 * ein Klick für etwas, das ohnehin jeder als Nächstes tut.
 */
const form = useForm({ networks: [...props.networks, ''] })

function add(): void {
  form.networks.push('')
}

function remove(index: number): void {
  form.networks.splice(index, 1)

  if (form.networks.length === 0) form.networks.push('')
}

/*
 * Trägt diese Zeile einen Fehler?
 *
 * **Die Ablage ist nach `networks.0`, `networks.1` … geschlüsselt**, und der
 * Typ von `form.errors` kennt nur die Felder des Formulars. Ein Zugriff über
 * eine gebaute Zeichenkette ist deshalb `any` — die Umgehung steht hier einmal
 * und nicht in jeder Zeile des Formulars.
 */
function fehlerhaft(index: number): boolean {
  return Boolean((form.errors as Record<string, string | undefined>)[`networks.${index}`])
}

function submit(): void {
  form
    .transform((data) => ({
      networks: data.networks.map((n) => n.trim()).filter((n) => n !== ''),
    }))
    .put('/settings/access', {
      onSuccess: () => form.reset(),
    })
}
</script>

<template>
  <Head title="Zugang" />

  <PanelLayout title="Zugang" subline="Aus welchen Netzen sich Verwaltungskonten anmelden dürfen">
    <FormErrors />

    <div class="sections">
      <form class="form" @submit.prevent="submit">
        <Section title="Netze">
          <p class="hint">
            Ist die Liste leer, ist die Anmeldung von überall möglich. Steht
            mindestens ein Netz darin, kommen Verwaltungskonten nur noch von
            dort herein — <b>Kundenkonten sind nie betroffen</b>.
          </p>

          <p class="hint">
            Ihre Adresse ist <span class="ident">{{ props.address ?? 'unbekannt' }}</span
            ><template v-if="props.networks.length > 0 && !props.covered">
              — und sie liegt in keinem der gespeicherten Netze.</template
            >. Schreiben Sie ein einzelnes Gerät als
            <span class="ident literal">192.0.2.7</span>, ein Netz als
            <span class="ident literal">192.0.2.0/24</span>.
          </p>

          <!--
            **Die Zeilen brauchen eine Hülle, die verteilt.** `.section` tut es
            nicht (`gap: normal`), und ohne sie standen die Zeilen bei 390 px auf
            **0 px** Abstand — gemessen, nicht geschätzt: Was auf dem Bild wie
            ein Abstand aussah, waren die Ränder der Felder.

            > Ein Abstand, den man sieht, ist nicht derselbe wie einer, den ein
            > Behälter gibt.
          -->
          <div class="rows">
            <div v-for="(_, index) in form.networks" :key="index" class="row">
              <label class="field">
                <span class="sr">Netz {{ index + 1 }}</span>
                <input
                  v-model="form.networks[index]"
                  type="text"
                  spellcheck="false"
                  placeholder="192.0.2.0/24"
                  :aria-invalid="fehlerhaft(index)"
                >
              </label>
              <button type="button" class="button small" @click="remove(index)">Entfernen</button>
            </div>

            <div class="button-row">
              <button type="button" class="button small" @click="add">Netz hinzufügen</button>
            </div>
          </div>
        </Section>

        <div class="button-row">
          <button type="submit" class="button primary" :disabled="form.processing">
            {{ form.processing ? 'Wird gespeichert …' : 'Speichern' }}
          </button>
        </div>
      </form>
    </div>
  </PanelLayout>
</template>

<style scoped>
/*
 * Feld und Knopf in einer Zeile, der Knopf am unteren Rand des Feldes. `end`
 * und nicht `center`: Das Feld trägt keine sichtbare Beschriftung, wächst aber
 * mit einer Fehlermeldung nach unten — zentriert stünde der Knopf dann
 * irgendwo in der Mitte.
 */
/*
 * Die Hülle, die verteilt. `.section` tut es nicht — sie ist kein Flexbehälter,
 * und ihre Kinder stehen ohne diese Zeile auf 0 px.
 */
.rows {
  display: flex;
  flex-direction: column;
  gap: var(--gap);
}

.row {
  display: flex;
  align-items: flex-end;
  gap: var(--gap);
}

.row .field {
  flex: 1;
  min-width: 0;
}

/* Nur für Vorlesesoftware: Die Zeilen haben keine sichtbare Beschriftung. */
.sr {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}
</style>
