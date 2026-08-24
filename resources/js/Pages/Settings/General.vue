<script setup lang="ts">
import { useForm, Head } from '@inertiajs/vue3'
import FormErrors from '../../Components/FormErrors.vue'
import Section from '../../Components/Section.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Idents from '../../Components/Idents.vue'

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
  addresses: { derived: string[]; override: string[]; effective: string[] }
}>()

const form = useForm({
  timezone: props.timezone,
  dns_addresses: props.addresses.override.join('\n'),
})

function submit(): void {
  form.put('/settings/general', { preserveScroll: true })
}
</script>

<template>
  <Head title="Allgemein" />

  <PanelLayout title="Allgemein" subline="Was für den ganzen Server gilt">
    <FormErrors />

    <form class="form" @submit.prevent="submit">
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

        <!--
          Eine Auswahl und kein Freitext: Der Wert geht in `setTimezone()`, und
          ein unbekannter Name wirft dort — mitten im Aufbau einer Seite
          (docs/40 §4).

          Und das Feld steht **in** seiner Beschriftung, nicht daneben
          (`FormLabelTest`): Ein `<select>` zeigt immer einen gültigen Wert und
          sieht deshalb nie leer aus — wer es überliest, trifft seine Vorgabe.
        -->
        <label class="field">
          <span>Zeitzone</span>
          <select v-model="form.timezone">
            <option v-for="zone in props.zones" :key="zone" :value="zone">{{ zone }}</option>
          </select>
        </label>

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

      </Section>

      <!--
        **Der Ort ist gewählt und nicht geraten.** „Welche Adressen sollen meine
        Domains tragen?" ist eine Frage über den Server und nicht über einen
        Dienst — „DNS-Zugang" daneben führt Zugangsdaten für Bestellungen über
        DNS-01 und ist ein anderes Thema.

        Bis zum 22. August gab es diesen Bereich nicht, und
        `Settings::saveDnsAddresses()` hatte keinen Aufrufer: Die Übersteuerung
        war entschieden (`docs/72 §2.1a`), gebaut war nur die Ableitung.
        Gefunden in der Zwischenabnahme (`docs/74`, Befund 2).
      -->
      <Section title="Adressen dieses Servers">
        <p class="hint">
          Der DNS-Abgleich hält die Einträge einer Domain gegen diese Adressen.
          <strong>Leer heisst „nimm die abgeleiteten"</strong> — eingetragen wird
          nur, wo die Ableitung nicht geht: hinter NAT, einer Floating-IP oder
          einem Lastverteiler ist die Adresse, unter der ein Server von aussen
          erreichbar ist, von innen nicht zu erfahren.
        </p>

        <label class="field">
          <span>Eingetragene Adressen</span>
          <textarea
            v-model="form.dns_addresses"
            rows="3"
            spellcheck="false"
            placeholder="eine Adresse je Zeile"
            :aria-invalid="Boolean(form.errors.dns_addresses)"
          />
        </label>

        <!--
          **Beide Listen stehen da** (`docs/72 §2.1a`). Eine eingetragene
          Adresse ist eine im Panel gemerkte Fassung eines Serverzustands und
          kann veralten; wer nur das Ergebnis zeigt, macht aus einer alten
          Eintragung eine falsche Auskunft über jede Domain.
        -->
        <table class="pairs">
          <tbody>
            <tr>
              <td>Abgeleitet</td>
              <td class="right ident"><Idents :values="props.addresses.derived" /></td>
            </tr>
            <tr>
              <td>Verglichen wird gegen</td>
              <td class="right ident"><Idents :values="props.addresses.effective" /></td>
            </tr>
          </tbody>
        </table>
      </Section>

      <!--
        **Ein Knopf für beide Bereiche, weil es ein Formular ist.**
        `ButtonStyleTest` besteht darauf: „Es gibt je Formular eine Hauptsache."
        Beim ersten Wurf stand er zweimal da — einmal je Bereich —, und damit
        hätte die Seite zwei Hauptsachen gehabt und trotzdem beide Felder auf
        einmal gespeichert.
      -->
      <div class="button-row">
        <button type="submit" class="button primary" :disabled="form.processing">
          {{ form.processing ? 'Wird gespeichert …' : 'Speichern' }}
        </button>
      </div>
    </form>
  </PanelLayout>
</template>
