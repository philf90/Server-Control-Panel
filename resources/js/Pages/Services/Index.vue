<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'
import { counted } from '../../Composables/useCounted'
import { rang, zustand } from '../../Composables/useUnitState'

/**
 * Eine Zeile, wie `system.units.list` sie liefert.
 *
 * `pid`, `restarts` und `since` sind `null`, wenn die Unit das Feld gar nicht
 * kennt — ein Timer hat keine PID. Das ist etwas anderes als eine gemessene
 * Null, und die Anzeige darf beides nicht gleich aussehen lassen.
 */
type Unit = {
  unit: string
  kind: string
  role: string
  own: boolean
  controlled: boolean
  present: boolean
  description: string
  active_state: string
  sub_state: string
  unit_file_state: string
  pid: number | null
  restarts: number | null
  since: string | null
  triggers: string | null
  has_next: boolean | null

  /**
   * Ob ein Timer diesen Dienst startet — `null` bei allem, was kein Dienst ist.
   *
   * Vier der eigenen zwölf sind `Type=oneshot` und stehen zwischen ihren Läufen
   * auf `inactive`. Ohne dieses Feld sähe der gesunde Server aus wie ein
   * kaputter.
   */
  scheduled: boolean | null

  /**
   * Der nächste Termin, **fertig formatiert vom Server**.
   *
   * Nicht als Zahl: `toLocaleString` im Browser nimmt die Zone des Betrachters,
   * und die Anzeigezone dieses Panels steht in den Einstellungen (`docs/40`).
   * Wer hier rechnet, hat eine zweite Fassung dieser Entscheidung gebaut, und
   * die zweite ist die, die auseinanderläuft.
   */
  next_elapse: string | null
}

const props = defineProps<{
  services: Unit[]
  timers: Unit[]
  live: boolean
  error: string | null
}>()

/*
 * **`rang` und `zustand` stehen seit dem 31. August 2026 in
 * `useUnitState`.** Sie standen hier, und die Übersicht hatte eine zweite,
 * ärmere Fassung — Befund 5 aus `docs/91 §13`. Eine Stufe, die eine zweite
 * Anzeige für dieselbe Sache baut, erzeugt die Abweichung, die sie danach
 * halten muss.
 */

/**
 * Der nächste Termin.
 *
 * `—` heisst „es gibt keinen", `unbekannt` heisst „es gibt einen, und das Datum
 * hat niemand geliefert". Der Unterschied ist der zwischen einem Schaden und
 * einer Lücke im Messmittel, und er darf nicht dieselbe Zelle füllen.
 */
function termin(zeile: Unit): string {
  if (zeile.has_next === false) return '—'

  return zeile.next_elapse ?? 'unbekannt'
}

const kaputt = computed(() => props.timers.filter((t) => t.present && t.has_next === false).length)
/**
 * Wie viele Dienste nicht tun, was sie sollen.
 *
 * **Gezählt wird über `rang` und nicht über `active_state`.** Eine zweite
 * Fassung derselben Regel ist die, die veraltet: Die erste Fassung dieser Zeile
 * fragte `active_state !== 'active'` und meldete damit auf einem gesunden
 * Server „4 Dienste laufen nicht", während dieselben vier Zeilen daneben
 * längst grün waren.
 */
const gestoppt = computed(() => props.services.filter((s) => rang(s) === 'critical').length)
</script>

<template>
  <Head title="Dienste" />

  <PanelLayout title="Dienste" subline="Was auf diesem Server läuft — und welcher Timer keinen Termin mehr hat">
    <p v-if="!live" class="notice critical">
      Der Agent antwortet nicht{{ error ? `: ${error}` : '' }}. Die Zustände unten fehlen
      deshalb — nicht, weil nichts läuft, sondern weil niemand geantwortet hat.
    </p>

    <p v-else-if="kaputt > 0" class="notice warn">
      {{ counted(kaputt, 'Timer hat', 'Timer haben') }} keinen nächsten Termin und meldet
      trotzdem „active".
    </p>

    <p v-else-if="gestoppt > 0" class="notice warn">
      {{ counted(gestoppt, 'Dienst läuft', 'Dienste laufen') }} nicht.
    </p>

    <!--
      **Keine grüne Meldung.** Die grüne Marke gehört dem Layout: Erfolg ist eine
      Aussage über einen Vorgang, und hier steht ein Zustand. Ein Satz ohne Farbe
      sagt dasselbe und nimmt der grünen Meldung ihre Bedeutung nicht weg.

      Der Klassenname steht hier bewusst nicht ausgeschrieben: `FieldErrorTest`
      sucht ihn als Zeichenkette, und ein Kommentar, der die Regel zitiert,
      verletzt sie für einen Wächter, der Wörter liest.
    -->
    <!--
      **„In Ordnung" und „läuft" sind seit dem 31. August zweierlei.** Hier stand
      „Alle Dienste laufen", und auf `cloudsrv24` stand der Satz drei Zeilen über
      vier Diensten, die gerade nicht liefen — weil ihr Timer sie startet und sie
      dazwischen warten.

      Eine Behebung ändert, was ein Wort bedeutet; die Sätze, die es benutzen,
      ändert sie nicht mit.
    -->
    <p v-else class="notice">Jeder Dienst ist in Ordnung, und jeder Timer hat einen Termin.</p>

    <div class="sections">
      <Section title="Dienste" full>
        <div class="scrolls">
          <!--
            **`stacks`, und das war eine Auslassung und keine Entscheidung.**
            Fünfundzwanzig Tabellen dieses Panels tragen es, diese und die der
            Timer trugen es als einzige nicht — und der Kommentar in `app.css`
            nennt „Dienste" ausdrücklich als `scrolls`-Fall, während die
            Übersicht ihre Dienstetabelle seit jeher stapelt.

            Gemessen auf `cloudsrv24` bei 390 px: Die Tabelle ist 1005 px breit
            bei 358 px sichtbar, und „kein nächster Termin" — der Satz, an dem
            das Abnahmekriterium von A2 hängt — ragte zehn Pixel über den Rand.
            Das Dokument schob dabei nicht; ein Rollbehälter hat keine
            Obergrenze, er hat nur keine Zahl, die sich beschwert.
          -->
          <table class="stacks">
            <thead>
              <tr>
                <th>Unit</th>
                <th>Zustand</th>
                <th>PID</th>
                <th>Neustarts</th>
                <th>Beschreibung</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="zeile in services" :key="zeile.unit">
                <td data-column="Unit"><span class="ident">{{ zeile.unit }}</span></td>
                <td data-column="Zustand"><span class="badge" :class="rang(zeile)">{{ zustand(zeile) }}</span></td>
                <td data-column="PID">{{ zeile.pid === null || zeile.pid === 0 ? '—' : zeile.pid }}</td>
                <td data-column="Neustarts">{{ zeile.restarts === null ? '—' : zeile.restarts }}</td>
                <td data-column="Beschreibung" class="quiet">{{ zeile.description || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>

      <Section
        title="Timer"
        full
        note="Ein Timer ohne nächsten Termin ist abgeschaltet und meldet trotzdem „active“. Deshalb steht hier der Termin und nicht der Zustand von systemd."
      >
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th>Unit</th>
                <th>Zustand</th>
                <th>Nächster Termin</th>
                <th>Startet</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="zeile in timers" :key="zeile.unit">
                <td data-column="Unit"><span class="ident">{{ zeile.unit }}</span></td>
                <td data-column="Zustand"><span class="badge" :class="rang(zeile)">{{ zustand(zeile) }}</span></td>
                <td data-column="Nächster Termin">{{ termin(zeile) }}</td>
                <td data-column="Startet"><span class="ident">{{ zeile.triggers || '—' }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>
    </div>
  </PanelLayout>
</template>
