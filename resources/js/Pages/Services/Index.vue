<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'
import { counted } from '../../Composables/useCounted'

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

/**
 * Der Rang einer Zeile — er entscheidet die Farbe.
 *
 * **Ein Timer ohne nächsten Termin ist kaputt, obwohl er `active` meldet.** Das
 * ist der Satz, um den es auf dieser Seite geht: `ActiveState` steht beim
 * gesunden wie beim kaputten Timer auf `active` (gemessen gegen systemd 255,
 * `docs/89 §3`). Wer die Farbe an `active_state` hängt, malt beide grün.
 *
 * **Und derselbe Satz spiegelverkehrt, gemessen auf `cloudsrv24` am 31. August
 * 2026:** Ein Dienst, den ein Timer startet, steht zwischen seinen Läufen auf
 * `inactive` — vier der eigenen zwölf sind so gebaut. Wer die Farbe allein an
 * `active_state` hängt, malt den gesunden Server viermal rot.
 *
 * `failed` bleibt davon unberührt: Ein oneshot-Dienst, dessen letzter Lauf
 * scheiterte, meldet `failed` und nicht `inactive` — gemessen, mit einem
 * eigenen Prüfkörper je Fall.
 */
function rang(zeile: Unit): 'ok' | 'warn' | 'critical' | 'neutral' {
  if (!zeile.present) return 'neutral'
  if (zeile.kind === 'timer' && zeile.has_next === false) return 'critical'
  if (zeile.active_state === 'active') return 'ok'
  if (zeile.active_state === 'activating') return 'warn'
  if (zeile.scheduled === true && zeile.active_state === 'inactive') return 'ok'
  return 'critical'
}

/**
 * Was in der Zustandsspalte steht.
 *
 * Für einen kaputten Timer ein **Satz** und keine Zahl: Das Abnahmekriterium
 * verlangt, dass er erkennbar ist, ohne dass man etwas deuten muss.
 */
function zustand(zeile: Unit): string {
  if (!zeile.present) return 'nicht installiert'
  if (zeile.kind === 'timer' && zeile.has_next === false) return 'kein nächster Termin'
  if (zeile.active_state === 'active') return zeile.sub_state === 'running' ? 'läuft' : 'bereit'
  if (zeile.active_state === 'activating') return 'startet neu'
  if (zeile.active_state === 'failed') return 'fehlgeschlagen'
  if (zeile.scheduled === true && zeile.active_state === 'inactive') return 'wartet auf seinen Timer'
  return 'gestoppt'
}

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
    <p v-else class="notice">Alle Dienste laufen, und jeder Timer hat einen Termin.</p>

    <div class="sections">
      <Section title="Dienste" full>
        <div class="scrolls">
          <table>
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
                <td><span class="ident">{{ zeile.unit }}</span></td>
                <td><span class="badge" :class="rang(zeile)">{{ zustand(zeile) }}</span></td>
                <td>{{ zeile.pid === null || zeile.pid === 0 ? '—' : zeile.pid }}</td>
                <td>{{ zeile.restarts === null ? '—' : zeile.restarts }}</td>
                <td>{{ zeile.description || '—' }}</td>
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
          <table>
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
                <td><span class="ident">{{ zeile.unit }}</span></td>
                <td><span class="badge" :class="rang(zeile)">{{ zustand(zeile) }}</span></td>
                <td>{{ termin(zeile) }}</td>
                <td><span class="ident">{{ zeile.triggers || '—' }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>
    </div>
  </PanelLayout>
</template>
