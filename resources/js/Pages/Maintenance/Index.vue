<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import FormErrors from '../../Components/FormErrors.vue'
import Section from '../../Components/Section.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

/**
 * Der Wartungsmodus — A12, `docs/101 §6`.
 *
 * **Datum und Uhrzeit kommen als Ortszeit an und gehen als Ortszeit hinaus.**
 * Die Umrechnung nach UTC macht `Clock` und niemand sonst; eine zweite hier
 * wäre die Fassung, die veraltet. Zusammengesetzt wird die Steuerung — auch das
 * wäre hier eine zweite Fassung des Formats.
 */
const props = defineProps<{
  maintenance: { enabled: boolean; until_date: string; until_time: string; zone: string }
}>()

const form = useForm({
  enabled: props.maintenance.enabled,
  until_date: props.maintenance.until_date,
  until_time: props.maintenance.until_time,
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

          **Zwei Felder mit den Typen, die das Format hergeben** — gemeldet vom
          Betreiber am 4. September 2026: Das Textfeld mit `inputmode="numeric"`
          öffnete auf dem iPhone die Zifferntastatur, und die kennt weder
          Bindestrich noch Doppelpunkt noch Leerzeichen. `Y-m-d H:i` war dort
          nicht tippbar — nicht umständlich, sondern gar nicht.

          `type="date"` und `type="time"` bringen den passenden Auswähler mit
          und zeigen das Datum in der Schreibweise des Geräts — deutsch also
          `04.09.2026`, während der Wert `2026-09-04` bleibt.
        -->
        <label class="field">
          <span>Voraussichtlich bis</span>
          <input
            v-model="form.until_date"
            type="date"
            :aria-invalid="Boolean(form.errors.until_date)"
          >
        </label>

        <label class="field">
          <span>Uhrzeit</span>
          <input
            v-model="form.until_time"
            type="time"
            :aria-invalid="Boolean(form.errors.until_time)"
          >
        </label>

        <!--
          **Die Zone steht unmittelbar am Feld und nicht am Ende eines Satzes
          über etwas anderes.** In der Bilderrunde vom 4. September stand sie
          hinter zwei Sätzen zur Wirkung der Angabe — wer eine Uhrzeit eintippt,
          liest das nicht mehr. Bei einer Zeitangabe ist die Zone die
          wichtigste Nebenauskunft, und sie gehört dorthin, wo getippt wird.
        -->
        <p class="hint">
          Zeiten in {{ props.maintenance.zone }}. Leer lassen, wenn keine Angabe
          gemacht werden soll.
        </p>

        <!--
          **Kein Zeitgeber, und das steht hier statt in einer Fussnote.** Wer
          das Feld ausfüllt, erwartet sonst, dass es etwas tut.

          **Ein Absatz und nicht zwei:** Der erste Wurf sagte „Erscheint auf der
          Wartungsseite" und drei Zeilen später noch einmal „sie ist nur der Satz
          auf der Wartungsseite". Zwei Absätze über dieselbe Sache lesen sich wie
          zwei Sachen.
        -->
        <p class="hint">
          Der Satz auf der Wartungsseite lautet dann „Voraussichtlich ab … Uhr
          wieder erreichbar." Die Angabe schaltet nichts ab und nichts wieder an —
          ausgeschaltet wird von Hand. Ist die Zeit überschritten und der Modus
          noch an, meldet es die Bestandsdiagnose.
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
