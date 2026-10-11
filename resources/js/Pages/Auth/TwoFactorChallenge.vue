<script setup lang="ts">
import Bands from '../../Components/Bands.vue'
import { Head, useForm } from '@inertiajs/vue3'
import CodeField from '../../Components/CodeField.vue'
import FormErrors from '../../Components/FormErrors.vue'
import { useDismissedBands, type AnnouncementBand } from '../../Composables/useDismissedBands'

/*
 * Der zweite Schritt. Bewusst ohne Navigation: Wer hier steht, ist noch nicht
 * angemeldet.
 */
const props = defineProps<{ incidents: AnnouncementBand[] }>()

/*
 * **Eine Störung lässt sich auch hier wegklicken** (11. Oktober 2026), und
 * gemerkt wird sie wie im Panel — nur unter `guest`: Vor der Anmeldung gibt es
 * kein Konto, dem sie gehören könnte. Nach der Anmeldung steht dieselbe
 * Störung im Panel wieder da, bis das Konto sie selbst wegklickt; denselben
 * Browser kann vorher jemand anderes benutzt haben.
 */
const { visible: visibleIncidents, dismiss: dismissIncident } = useDismissedBands('guest').announcements(
  () => props.incidents,
)

const form = useForm({ code: '' })

function submit(): void {
  form.post('/two-factor', { onFinish: () => form.reset('code') })
}
</script>

<template>
  <Head title="Bestätigung" />

  <!--
    Störungen des Betreibers, ganz oben (A14, `docs/103 §4.4`). Diese Seite
    trägt `PanelLayout` nicht — deshalb steht der Streifen hier ein zweites
    Mal, und deshalb ist er eine Komponente und kein Markup.
  -->
  <div class="signin-frame">
    <!--
      **Die Hülle steht hier und nicht in `Bands.vue`.** Im Panel trägt
      dieselbe `.bands` auch den Balken für „Anmelden als" und nimmt
      `grid-row: 1`; zwei Geschwister mit derselben Rasterzeile wären der
      M2-Befund zurück (`docs/81 §2.3q`). Sie kann also nicht in die Komponente
      ziehen.

      Hier fehlte sie bis zum 6. September, und der Abnahmelauf hat es am Bild
      gefunden: Das Band lag bündig am Bildschirmrand statt eingerückt, und
      zwei Störungen hätten ohne `gap` aneinandergeklebt.

      > **Eine Hülle, die in der aufrufenden Vorlage steht statt in der
      > Komponente, gibt es so oft, wie jemand daran denkt.**

      `BandsHullTest` hält es jetzt.
    -->
    <div v-if="visibleIncidents.length" class="bands">
      <Bands :items="visibleIncidents" @dismiss="dismissIncident" />
    </div>

  <main class="signin">
    <FormErrors />

    <form class="sheet" @submit.prevent="submit">
      <h1>Bestätigung</h1>

      <p class="hint">
        Der sechsstellige Code aus Ihrer Authenticator-App. Wenn Sie kein Gerät
        zur Hand haben, geht auch einer Ihrer Wiederherstellungscodes.
      </p>

      <CodeField
        v-model="form.code"
        autofocus
        hint="Sechs Ziffern — oder ein Wiederherstellungscode in der Form ABCDE-FGHIJ."
        :error="form.errors.code"
      />

      <div class="button-row">
        <button type="submit" class="button primary" :disabled="form.processing">
          {{ form.processing ? 'Einen Moment …' : 'Bestätigen' }}
        </button>
      </div>
    </form>
  </main>
  </div>
</template>
