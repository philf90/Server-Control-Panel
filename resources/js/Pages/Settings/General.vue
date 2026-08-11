<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'
import FormErrors from '../../Components/FormErrors.vue'
import Section from '../../Components/Section.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

/*
 * Was für den ganzen Server gilt und zu keinem Dienst gehört (docs/40).
 *
 * **Warum es diese Seite gibt.** Der Plan verlangte „ein Feld in
 * Einstellungen", und es gab keinen Ort dafür: Die fünf vorhandenen Seiten sind
 * themengebunden, und das Profil gehört einem Konto. Die Anzeigezone ist
 * serverweit — eine Seite mit einem Feld ist wenig, aber der Ort fehlte, und
 * ihn beim ersten Bedarf anzulegen ist billiger, als das Feld irgendwo
 * unterzubringen, wo es niemand sucht.
 */
const props = defineProps<{
  timezone: string
  label: string
  zones: string[]
  example: { utc: string; display: string | null }
}>()

const form = useForm({ timezone: props.timezone })

function save(): void {
  form.put('/settings/general', { preserveScroll: true })
}
</script>

<template>
  <Head title="Allgemein" />

  <PanelLayout title="Allgemein" subline="Was für den ganzen Server gilt">
    <FormErrors />

    <Section title="Anzeigezeit">
      <!--
        Der Satz oben und nicht am Feld: Wer hierherkommt, will zuerst wissen,
        was sich ändert — und was ausdrücklich nicht.
      -->
      <p class="hint">
        Zeitpunkte werden im Panel in dieser Zone angezeigt. <strong>Gespeichert
        wird weiter in UTC</strong>, und der Export des Protokolls bleibt
        ebenfalls UTC: Ein Zeitstempel ohne Zone in einer Datei, die drei Jahre
        liegt, wird gelesen, wenn der Server längst umgezogen ist.
      </p>

      <div class="field">
        <label for="timezone">Zeitzone</label>
        <!--
          Eine Auswahl und kein Freitext: Der Wert geht in `setTimezone()`, und
          ein unbekannter Name wirft dort — mitten im Aufbau einer Seite
          (docs/40 §4).
        -->
        <select id="timezone" v-model="form.timezone">
          <option v-for="zone in props.zones" :key="zone" :value="zone">{{ zone }}</option>
        </select>
      </div>

      <!--
        **Die Gegenprobe steht neben dem Feld.** Dieselbe Zeit zweimal — was in
        der Datenbank steht und was auf der Seite stünde. Ohne sie ist die
        Auswahl eine Behauptung; genau daran hing der Anlass für diese Seite:
        Ein Zeitstempel, den man falsch liest, sieht aus wie eine Auskunft.
      -->
      <table class="pairs">
        <tbody>
          <tr>
            <td>Gespeichert</td>
            <td class="right ident">{{ props.example.utc }} UTC</td>
          </tr>
          <tr>
            <td>Angezeigt</td>
            <td class="right ident">{{ props.example.display ?? '—' }} {{ props.label }}</td>
          </tr>
        </tbody>
      </table>

      <div class="button-row">
        <button type="button" class="button" :disabled="form.processing" @click="save">
          Speichern
        </button>
      </div>
    </Section>
  </PanelLayout>
</template>
