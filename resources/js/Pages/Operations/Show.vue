<script setup lang="ts">
/*
 * Ein Vorgang mit seiner Ausgabe.
 *
 * **Der Strom läuft nur, solange der Vorgang offen ist.** Ein fertiger Vorgang
 * ändert sich nicht mehr; eine Verbindung dafür offen zu halten belegte einen
 * PHP-FPM-Arbeiter für nichts. Die Ausgabe steht dann schon vollständig in den
 * Eigenschaften der Seite.
 */
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import Section from '../../Components/Section.vue'
import Badge from '../../Components/Badge.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import { useOperationStream } from '../../Composables/useOperationStream'
import { useConfirmation } from '../../Composables/useConfirmation'

const { ask } = useConfirmation()

interface Operation {
  id: number
  type: string
  label: string
  status: string
  status_label: string
  open: boolean
  progress: number
  message: string | null
  account: string | null
  started_at: string | null
  finished_at: string | null
  cancel_requested: boolean
  payload: Record<string, unknown> | null
  result: Record<string, unknown> | null

  /* Der Vorbehalt, unter dem ein gelungener Lauf gelungen ist — wie in der Liste. */
  warning: string | null
  output: string

  /**
   * Von welcher Seite aus der Vorgang ausgelöst wurde — als Pfad, oder `null`.
   *
   * `null` heisst „von keiner": Die Zertifikatsautomatik und der
   * Cron-Einsammler setzen ohne Sitzung ab.
   */
  origin: string | null

  /**
   * Wovon er handelt — oder `null`, wenn von nichts Einzelnem.
   *
   * `path` ist eigens `null`-fähig: Eine Sicherung hat keine eigene Seite, und
   * wenn ihre Datenbank fort ist, führt der Verweis nirgendwohin. Dann steht
   * der Name ohne Verknüpfung da — das ist eine Auskunft und keine Sackgasse.
   */
  subject: { label: string; name: string; path: string | null } | null
}

const props = defineProps<{ operation: Operation }>()

const live = props.operation.open ? useOperationStream(props.operation.id) : null

// Was beim Laden schon dastand, plus was seither dazukam. Der Strom nimmt die
// Ausgabe ab dem Zeichen auf, das der Browser zuletzt gesehen hat — er schickt
// den bisherigen Text also nicht noch einmal.
const output = computed(() => props.operation.output + (live?.output.value ?? ''))

const status = computed(() => live?.state.value?.status ?? props.operation.status)
const label = computed(() => live?.state.value?.status_label ?? props.operation.status_label)
const progress = computed(() => live?.state.value?.progress ?? props.operation.progress)
const message = computed(() => live?.state.value?.message ?? props.operation.message)

const open = computed(() => live?.state.value?.open ?? props.operation.open)

// „Noch" ist eine Zusage, dass etwas kommt, und an einem fertigen Vorgang ist
// sie falsch: Vorgang 449 stand am 8. August 2026 auf „fertig" und darunter
// „Noch keine Ausgabe." — die Seite kannte den Zustand und benutzte ihn für
// diesen Satz nicht (docs/36 §22.3p).
const emptyOutput = computed(() => (open.value ? 'Noch keine Ausgabe.' : 'Keine Ausgabe.'))

// Auch die Zeiten kommen aus dem Kanal, sobald er etwas geschickt hat. Ohne das
// stünde an einem fertigen Vorgang „Begonnen —": Die erste Antwort entsteht,
// während er noch in der Warteschlange steht (docs/36 §22.3m).
const startedAt = computed(() => live?.state.value?.started_at ?? props.operation.started_at)
const finishedAt = computed(() => live?.state.value?.finished_at ?? props.operation.finished_at)

const rang = computed<'ok' | 'warn' | 'critical' | 'neutral'>(() => {
  if (status.value === 'succeeded') return 'ok'
  if (status.value === 'running' || status.value === 'queued') return 'warn'
  if (status.value === 'failed' || status.value === 'cancelled') return 'critical'

  return 'neutral'
})

/*
 * **Der Vorbehalt, und zwar auch für den, der zusieht.**
 *
 * Er steht im Ergebnis, und das Ergebnis kommt aus zwei Richtungen: aus den
 * Eigenschaften der Seite, die seit dem Laden feststehen, und aus dem
 * abschliessenden Ereignis des Stroms. Ohne das zweite sähe ein Zuschauer den
 * Vorbehalt erst beim Neuladen — die Seite war schon geladen, als es ihn noch
 * nicht gab.
 */
const warnung = computed<string | null>(() => {
  const ausStrom = live?.result.value?.warning

  return typeof ausStrom === 'string' ? ausStrom : props.operation.warning
})

/*
 * **Die Farbe der Meldung folgt ihrem Inhalt und nicht dem Zustand.**
 *
 * Bis zum 30. August stand hier `rang === 'critical' ? 'critical' : 'ok'`. Ein
 * gelungener Lauf ist `ok`, also grün — und ein Vorbehalt auf einem gelungenen
 * Lauf wurde damit in der Farbe gemalt, die sagt, es sei nichts zu sehen. Die
 * Liste zeigte dieselbe Auskunft bernsteinfarben (`docs/88`, Befund 8).
 *
 * > **Dieselbe Auskunft in zwei Farben sagt zweimal etwas anderes — und die
 * > grüne gewinnt, weil sie oben steht.**
 *
 * Das nahm die Entscheidung des Betreibers vom 28. August zurück: Der Zustand
 * bleibt, der **Vorbehalt wird sichtbar**.
 */
const notizart = computed<'ok' | 'warn' | 'critical'>(() => {
  if (rang.value === 'critical') return 'critical'

  return warnung.value === null ? 'ok' : 'warn'
})

// Der Wunsch bleibt stehen, bis die Seite neu geladen wird — der Ereignisstrom
// überträgt ihn nicht, weil er den Zustand des Vorgangs meldet und nicht den
// Zustand dieses Knopfes.
const cancelRequested = ref(props.operation.cancel_requested)

function cancel(): void {
  ask('Diesen Vorgang abbrechen?', 'Abbrechen lassen', () => {
    cancelRequested.value = true
    router.post(`/operations/${props.operation.id}/cancel`, {}, { preserveScroll: true })
  })
}

/*
 * **Die Beschriftung ist der Pfad ohne seine Frage — der Verweis trägt sie.**
 *
 * Gemessen am 31. August 2026 bei 390 px an
 * `/updates?nur=sicherheit&herkunft=security.debian.org&name=linux-image-amd64`:
 * Der volle Pfad nimmt **drei Zeilen** über dem Seitentitel. Er schiebt nichts —
 * `.ident` bricht —, aber drei Zeilen Filterwerte sind keine Ortsangabe.
 *
 * > **Eine Beschriftung, die den ganzen Zustand nennt, sagt nicht mehr, wo man
 * > war — sie sagt nur, dass es kompliziert war.**
 *
 * Der Verweis behält die Frage: Wer zurückgeht, findet seinen Filter wieder.
 * Gezeigt wird, was er wiedererkennt.
 */
const herkunft = computed(() => (props.operation.origin ?? '').split('?')[0])

const box = ref<HTMLElement | null>(null)

// Mitlaufen lassen, solange etwas kommt. Ohne das müsste beim Zusehen jemand
// von Hand scrollen, und genau dann ist der Vorgang das, worauf er wartet.
watch(output, () => {
  requestAnimationFrame(() => {
    if (box.value) box.value.scrollTop = box.value.scrollHeight
  })
})
</script>

<template>
  <Head :title="`Vorgang ${props.operation.id}`" />

  <PanelLayout :title="props.operation.label">
    <!--
      **Der Weg zurück steht vor der Vorgangsliste und nicht dahinter.**

      Bis zum 31. August 2026 trug dieser Brotkrümel eine einzige Verknüpfung —
      `Vorgänge`, also die Liste *aller* Vorgänge. Wer eine Domain anlegte, fand
      von hier nicht zur Domain; wer Pakete einspielte, nicht zurück zu den
      Updates. Einundzwanzig Weiterleitungen aus sieben Controllern enden hier.

      Gemeldet hat es der Betreiber beim **Erklären**: Die Antwort auf „wie
      drücke ich denselben Knopf noch einmal" lautete „mit dem Zurück-Knopf des
      Browsers".

      > Ein Weg, den man nur erklären kann, indem man den Browser zu Hilfe
      > nimmt, ist keiner, den die Anwendung anbietet.

      **Der Pfad steht als Kennung und nicht als Beschriftung.** Plan §7.2 führt
      Monospace ausdrücklich für Pfade; eine Zuordnung Pfad → Menütitel wäre
      eine zweite Fassung der Navigation, und die zweite veraltet.

      Was das **nicht** behebt — dass man überhaupt weggetragen wird — steht in
      `docs/92` und ist für P9 vorgemerkt.
    -->
    <template #breadcrumb>
      <template v-if="props.operation.origin">
        <Link :href="props.operation.origin" class="link">
          ← <span class="ident">{{ herkunft }}</span>
        </Link> ·
      </template>
      <Link href="/operations" class="link">Vorgänge</Link> ·
      <span class="ident">{{ props.operation.type }}</span> · Nummer {{ props.operation.id }}
    </template>

    <template #actions>
      <Badge :kind="rang" :running="open">{{ label }}</Badge>
      <button v-if="open" type="button" class="button danger" :disabled="cancelRequested" @click="cancel">
        {{ cancelRequested ? 'Abbruch angefordert …' : 'Abbrechen' }}
      </button>
    </template>

    <!--
      Der ehrliche Zwischenzustand. „Abgebrochen" steht erst da, wenn es
      zutrifft: Zwischen dem Wunsch und dem Ende des Programms auf dem Server
      liegen ein, zwei Sekunden, und in dieser Zeit läuft es noch.
    -->
    <p v-if="cancelRequested && open" class="notice warn">
      Der Abbruch ist angefordert. Der Vorgang endet, sobald der Agent das
      laufende Programm beendet hat.
    </p>

    <p v-if="message" class="notice" :class="notizart">{{ message }}</p>

    <div class="sections">
      <Section title="Gegenstand">
        <table class="pairs">
          <tbody>
            <tr><td class="quiet">Aufgabe</td><td class="right ident name">{{ props.operation.type }}</td></tr>

            <!--
              **Wovon der Vorgang handelt.** `subject_type` und `subject_id`
              gibt es seit dem 4. August 2026, und bis zum 31. hat sie keine
              Oberfläche gelesen — derselbe Fall wie `context` im Protokoll:
              Ein Feld, das geschrieben und nie gelesen wird, ist von aussen
              nicht von einem zu unterscheiden, das es nicht gibt.
            -->
            <tr v-if="props.operation.subject">
              <td class="quiet">{{ props.operation.subject.label }}</td>

              <!--
                **`ident` steht an der Zelle und nicht am Verweis.** Der erste
                Wurf schrieb `<td class="right">` mit einem `<a class="link
                ident">` darin — gemessen bei 390 px schob das die Seite um
                **59 px** aus dem Bild. `table.pairs td.right.ident` löst die
                Zelle aus ihrem `flex: none` und erlaubt den Umbruch; eine
                Kennung, die nur *in* der Zelle steht, erreicht diese Ausnahme
                nicht, und `td .ident { white-space: nowrap }` gewinnt.

                > Eine Ausnahme, die für die Zelle geschrieben ist, gilt nicht
                > für das, was in ihr steht — und beide sehen im Markup gleich
                > aus.

                Der Verweis erbt die Schrift über `.link { font: inherit }`.
              -->
              <td class="right ident">
                <Link v-if="props.operation.subject.path" :href="props.operation.subject.path" class="link">
                  {{ props.operation.subject.name }}
                </Link>
                <span v-else>{{ props.operation.subject.name }}</span>
              </td>
            </tr>
            <tr>
              <td class="quiet">Zustand</td>
              <td class="right"><Badge :kind="rang" :running="open">{{ label }}</Badge></td>
            </tr>
            <tr><td class="quiet">Ausgelöst von</td><td class="right name">{{ props.operation.account ?? '—' }}</td></tr>
            <tr><td class="quiet">Begonnen</td><td class="right">{{ startedAt ?? '—' }}</td></tr>
            <tr><td class="quiet">Beendet</td><td class="right">{{ finishedAt ?? '—' }}</td></tr>
          </tbody>
        </table>

        <div class="progress"><i :style="{ width: `${progress}%` }" /></div>
        <p class="section-note">Fortschritt {{ progress }} %</p>
      </Section>

      <Section title="Argumente" note="Was das Panel dem Agenten geschickt hat — typisiert und nicht als Kommandozeile.">
        <pre class="output facts">{{ JSON.stringify(props.operation.payload ?? {}, null, 2) }}</pre>
      </Section>

      <Section title="Ausgabe" full>
        <pre ref="box" class="output long">{{ output || emptyOutput }}</pre>
      </Section>

      <Section v-if="props.operation.result" title="Ergebnis" full>
        <pre class="output facts">{{ JSON.stringify(props.operation.result, null, 2) }}</pre>
      </Section>
    </div>
  </PanelLayout>
</template>

<style scoped>
/*
 * Form und Farbe der Ausgabe stehen in app.css — hier nur, wie hoch sie ist.
 * Die Argumente sind kurz und sollen nicht rollen; die Ausgabe eines Vorgangs
 * ist beliebig lang und bekommt eine Grenze, damit die Seite darunter
 * erreichbar bleibt.
 */
.output {
  margin: 0;
}

.facts {
  color: var(--text-muted);
}

.long {
  max-height: 420px;
}
</style>
