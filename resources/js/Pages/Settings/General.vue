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

  /*
   * Die Zeit des Servers als fertige Sätze (A11). Die Zuordnung Zustand → Satz
   * steht in `App\Support\Time\ServerTime` — hier stünde sie ein zweites Mal,
   * und die zweite Fassung ist die, die veraltet.
   */
  time: {
    zone: string
    service: string
    synchronized: string
    clock: string
    now: string
    display: string
  }

  hostname: string
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
        **Die Zeit des Servers steht neben der Anzeigezeit** (A11, `docs/106`),
        und genau deshalb steht sie hier und nicht auf einer eigenen Seite:
        `docs/80` verlangt sie *neben* der Anzeigezone, „weil die beiden sonst
        verwechselt werden".

        Der Bereich ist reines Lesen und liegt trotzdem im Formular — das ist
        gültiges Markup und hält die Reihenfolge, auf die es ankommt. Er nimmt
        nichts entgegen, also hat er auch keinen Knopf; die eine Hauptsache des
        Formulars steht unten.
      -->
      <Section title="Zeit des Servers">
        <p class="hint">
          Diese Angaben kommen vom Server und lassen sich hier nicht ändern.
          <strong>Die Zeitzone des Servers ist etwas anderes als die Anzeigezeit
          darüber</strong> — die letzte Zeile zeigt denselben Augenblick in
          beiden.
        </p>

        <table class="pairs">
          <tbody>
            <tr>
              <td>Zeitzone des Servers</td>
              <td class="right ident">{{ props.time.zone }}</td>
            </tr>
            <tr>
              <td>Zeitabgleich</td>
              <td class="right">{{ props.time.service }}</td>
            </tr>
            <tr>
              <td>Uhr abgeglichen</td>
              <td class="right">{{ props.time.synchronized }}</td>
            </tr>
            <tr>
              <td>Hardware-Uhr</td>
              <td class="right">{{ props.time.clock }}</td>
            </tr>
            <tr>
              <td>Jetzt auf dem Server</td>
              <td class="right ident">{{ props.time.now }}</td>
            </tr>
            <tr>
              <td>Dasselbe in der Anzeigezeit</td>
              <td class="right ident">{{ props.time.display }}</td>
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

        <!--
          **Der Bereich sagt selbst, warum der Name kein Feld ist** (`docs/80`).
          Ein Hinweis, der eine fehlende Handlung erklärt, ist billiger als der
          Weg, auf dem jemand sie sucht.
        -->
        <p class="hint">
          Der <strong>Rechnername</strong> lässt sich hier nicht ändern: Er steckt
          im Zertifikat des Panels, in den vhosts und im DNS-Abgleich, und ein
          Wechsel nimmt alle drei mit.
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
            <!--
              **Der Rechnername steht hier und nicht bei der Zeit** (A11): Der
              Bereich beantwortet, wie dieser Server heisst und wo er
              erreichbar ist.

              **Ändern ist kein Knopf**, und der Hinweis darunter sagt warum —
              der Name steckt in Zertifikaten, vhosts und dem DNS-Abgleich
              (`docs/80`).
            -->
            <tr>
              <td>Rechnername</td>
              <td class="right ident">{{ props.hostname }}</td>
            </tr>
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
