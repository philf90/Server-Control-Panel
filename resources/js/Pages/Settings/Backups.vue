<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import FormErrors from '../../Components/FormErrors.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'

/*
 * Was der Server von sich aus sichert (P8 Schritt 9 und 10).
 *
 * **Wie viele Stände bleiben, steht nicht auf dieser Seite.** Das ist ein
 * Kontingent des Plans und je Abonnement übersteuerbar; eine Zahl hier wäre
 * eine zweite Fassung derselben Regel — und die zweite ist die, die veraltet.
 */
const props = defineProps<{
  backups: { automatic: boolean; before_removal: boolean }
}>()

const form = useForm({
  automatic: props.backups.automatic,
  before_removal: props.backups.before_removal,
})
</script>

<template>
  <Head title="Automatische Sicherung" />

  <PanelLayout title="Automatische Sicherung" subline="Was der Server von sich aus sichert">
    <FormErrors />

    <form class="form" @submit.prevent="form.put('/settings/backups')">
      <div class="sections">
        <Section title="Nächtlich sichern">
          <label class="toggle">
            <input v-model="form.automatic" type="checkbox">
            <span>Jede Nacht eine Sicherung je Abonnement anlegen</span>
          </label>

          <p class="hint">
            Betroffen sind aktive Abonnements, deren Plan die Funktion
            „Sicherungen" freigibt. Wie viele Stände bleiben, steht als
            Kontingent im Plan; entsteht eine weitere, geht die älteste.
          </p>

          <!--
            **Abgeräumt wird auch ohne den Schalter, und das gehört gesagt.**
            Ein Betreiber, der die Automatik auslässt, erwartet sonst, dass
            die Aufbewahrungszahl erst dann gilt — und wundert sich, wo seine
            von Hand angelegten Stände geblieben sind.
          -->
          <p class="hint">
            Der nächtliche Lauf räumt in jedem Fall über die Aufbewahrung hinaus
            ab, auch wenn hier nichts angehakt ist — und auch Sicherungen, die
            von Hand angelegt wurden.
          </p>
        </Section>

        <Section title="Vor dem Rückbau sichern">
          <label class="toggle">
            <input v-model="form.before_removal" type="checkbox">
            <span>Beim Löschen eines Abonnements zuerst eine Sicherung anlegen</span>
          </label>

          <p class="hint">
            Der Rückbau ist der eine Griff dieses Panels, der nichts
            zurücklässt. Die Sicherung läuft vor ihm fertig und überlebt ihn —
            sie steht danach unter den Sicherungen ohne Abonnement und lässt
            sich zurückspielen.
          </p>

          <p class="hint">
            Sie kostet Zeit: Das Löschen ist erst fertig, wenn die Sicherung es
            ist. Bei einem grossen Abonnement sind das Minuten.
          </p>
        </Section>
      </div>

      <!--
        **Die Knopfreihe steht neben `.sections` und nicht darin.** Dieselbe
        Regel wie auf der Zertifikatsseite, und hier hat die Bilderrunde sie
        bezahlt: Bei 1440 px ist `.sections` ein Raster, und als Kind darin
        wurde die Reihe eine **dritte Spalte** — „Speichern" stand oben rechts
        neben der Überschrift des zweiten Bereichs statt unter dem Formular.
        Bei 390 px stapelt dasselbe Raster, und dort sah es richtig aus.

        Keine Zahl hat sich beschwert: `dokument = 0`, nichts schiebt, nichts
        rollt.

        `Settings/Access.vue` trägt dieselbe Form und fällt nicht auf — es hat
        **einen** Bereich, und dann landet die Reihe ohnehin darunter.

        > **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
        > Betrachter.**
      -->
      <div class="button-row">
        <button type="submit" class="button primary" :disabled="form.processing">
          {{ form.processing ? 'Einen Moment …' : 'Speichern' }}
        </button>
      </div>
    </form>
  </PanelLayout>
</template>
