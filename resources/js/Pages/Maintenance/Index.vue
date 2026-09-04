<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import FormErrors from '../../Components/FormErrors.vue'
import Section from '../../Components/Section.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

/**
 * Der Wartungsmodus — A12, `docs/101 §6`.
 *
 * **`until` kommt als Ortszeit an und geht als Ortszeit hinaus.** Die
 * Umrechnung nach UTC macht `Clock` und niemand sonst; eine zweite hier wäre
 * die Fassung, die veraltet.
 */
const props = defineProps<{
  maintenance: { enabled: boolean; until: string | null; zone: string }
}>()

const form = useForm({
  enabled: props.maintenance.enabled,
  until: props.maintenance.until ?? '',
})

/**
 * Ein- und ausschalten sind **dasselbe Formular** und nicht zwei.
 *
 * Der Zustand ist eine Eigenschaft des Servers, nicht zwei Handlungen: Wer
 * zwei Knöpfe baut, muss entscheiden, welcher gerade gilt, und diese
 * Entscheidung ist eine zweite Fassung des Zustands daneben.
 */
function absenden(an: boolean): void {
  form.enabled = an
  form.post('/maintenance', { preserveScroll: true })
}
</script>

<template>
  <Head title="Wartungsmodus" />

  <PanelLayout title="Wartungsmodus" subline="Alle Kundenwebsites vorübergehend abschalten">
    <FormErrors />

    <!--
      **Der Zustand steht oben und nicht am Knopf.** Was gerade gilt, ist die
      erste Frage, mit der jemand diese Seite aufruft — und die Antwort darf
      nicht aus der Beschriftung eines Knopfes erschlossen werden müssen.
    -->
    <p v-if="props.maintenance.enabled" class="notice critical">
      <span>
        Der Wartungsmodus ist <strong>eingeschaltet</strong>. Alle Kundenwebsites
        antworten mit 503; das Panel und die Zertifikatsprüfung bleiben erreichbar.
      </span>
    </p>

    <p v-else class="notice">
      Der Wartungsmodus ist ausgeschaltet. Alle Websites werden normal ausgeliefert.
    </p>

    <div class="sections">
      <Section title="Schalten">
        <!--
          **Die Zeitangabe ist eine Auskunft und keine Steuerung.** Nichts
          schaltet zu diesem Zeitpunkt ab — das war eine Entscheidung: Ein
          Fenster, dessen Ende ein Zeitgeber herstellt, endet nicht, wenn der
          Zeitgeber ausfällt, und dann bliebe jede Website unbegrenzt auf 503.
        -->
        <label class="field">
          <span>Voraussichtlich bis</span>
          <input
            v-model="form.until"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            placeholder="2026-09-04 16:00"
            :aria-invalid="Boolean(form.errors.until)"
          >
        </label>

        <p class="hint">
          Erscheint auf der Wartungsseite: „Voraussichtlich ab … Uhr wieder erreichbar."
          Leer lassen, wenn keine Angabe gemacht werden soll. Zeiten in
          {{ props.maintenance.zone }}.
        </p>

        <!--
          **Kein Zeitgeber, und das steht hier statt in einer Fussnote.** Wer
          das Feld ausfüllt, erwartet sonst, dass es etwas tut.
        -->
        <p class="hint">
          Die Angabe schaltet nichts ab und nichts wieder an — sie ist nur der Satz
          auf der Wartungsseite. Ausgeschaltet wird von Hand. Ist die Zeit
          überschritten und der Modus noch an, meldet es die Bestandsdiagnose.
        </p>

        <div class="button-row">
          <button
            v-if="!props.maintenance.enabled"
            type="button"
            class="button danger"
            :disabled="form.processing"
            @click="absenden(true)"
          >
            Wartungsmodus einschalten
          </button>

          <button
            v-else
            type="button"
            class="button primary"
            :disabled="form.processing"
            @click="absenden(false)"
          >
            Wartungsmodus ausschalten
          </button>

          <!--
            **Die Zeit lässt sich ändern, ohne zu schalten.** Sonst müsste man
            aus- und wieder einschalten, um einen Satz zu korrigieren — und
            dazwischen wären alle Websites für einen Moment erreichbar.
          -->
          <button
            v-if="props.maintenance.enabled"
            type="button"
            class="button"
            :disabled="form.processing"
            @click="absenden(true)"
          >
            Nur die Zeitangabe übernehmen
          </button>
        </div>
      </Section>
    </div>
  </PanelLayout>
</template>
