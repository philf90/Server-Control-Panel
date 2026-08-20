<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { useConfirmation } from '../../Composables/useConfirmation'
import FormErrors from '../../Components/FormErrors.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import { bringIntoView } from '../../scroll'

interface Job {
  id: number
  label: string
  command: string
  active: boolean
  schedule: Record<string, string>
  expression: string
  spoken: string | null
  next_due: string | null
}

const props = defineProps<{
  subscription: { id: number; name: string; system_user: string | null; usable: boolean }
  jobs: Job[]
  quota: { used: number; limit: number | null }
  server_zone: string
  display_zone: string
  can: { manage: boolean }
}>()

const { ask } = useConfirmation()

const bearbeitet = ref<number | null>(null)

const form = useForm({
  label: '',
  command: '',
  minute: '*',
  hour: '*',
  day_of_month: '*',
  month: '*',
  day_of_week: '*',
  active: true,
})

/**
 * Die Schnellwahl — Entscheidung 4 des Betreibers (`docs/60 §12`).
 *
 * **Sie füllt die fünf Felder und ist keine zweite Darstellung.** Ein eigener
 * Speicherwert „täglich" neben den Feldern wäre eine zweite Fassung desselben
 * Zeitplans, und die zweite ist die, die veraltet. Nach einem Klick steht in den
 * Feldern, was gilt — und der Kunde kann es weiter von Hand ändern.
 *
 * **Und die Beschriftung ist der Satz.** Damit braucht die Schnellwahl keine
 * Übersetzung: Was der Knopf sagt, ist das, was er einstellt.
 */
const vorlagen = [
  { name: 'jede Minute', felder: { minute: '*', hour: '*', day_of_month: '*', month: '*', day_of_week: '*' } },
  { name: 'jede Stunde zur Minute 0', felder: { minute: '0', hour: '*', day_of_month: '*', month: '*', day_of_week: '*' } },
  { name: 'jeden Tag um 03:15', felder: { minute: '15', hour: '3', day_of_month: '*', month: '*', day_of_week: '*' } },
  { name: 'montags bis freitags um 09:00', felder: { minute: '0', hour: '9', day_of_month: '*', month: '*', day_of_week: '1-5' } },
  { name: 'sonntags um 04:00', felder: { minute: '0', hour: '4', day_of_month: '*', month: '*', day_of_week: '0' } },
  { name: 'am 1. jedes Monats um 05:00', felder: { minute: '0', hour: '5', day_of_month: '1', month: '*', day_of_week: '*' } },
]

function vorlageWaehlen(felder: Record<string, string>): void {
  Object.assign(form, felder)
}

/**
 * Der Ausdruck, wie er entsteht — fünf Felder mit Leerzeichen dazwischen.
 *
 * **Hier steht bewusst keine Übersetzung.** Den Satz baut `App\Support\Cron\Spoken`
 * auf dem Server; ihn hier ein zweites Mal zu bauen hiesse, dieselbe Regel in
 * zwei Sprachen zu pflegen — und die zweite ist die, die von der ersten
 * abweicht. Was hier steht, ist keine Regel, sondern eine Verkettung.
 *
 * > **Eine Zusammenfügung darf doppelt stehen, eine Regel nicht.**
 *
 * Den Satz zu einem gespeicherten Job zeigt die Liste; für den Entwurf nennt die
 * Schnellwahl ihn in ihrer eigenen Beschriftung.
 */
const ausdruck = computed(
  () => `${form.minute} ${form.hour} ${form.day_of_month} ${form.month} ${form.day_of_week}`,
)

/**
 * Der Umschalter auf die Eingabe des ganzen Ausdrucks.
 *
 * **Ein Kästchen und kein neuer Baustein.** Ein Umschalter mit zwei
 * beschrifteten Hälften wäre eine neue Klasse in `app.css`, eine neue Regel und
 * ein neuer Wächter — für einen Zustand, der zwei Werte hat. `.toggle` steht auf
 * dieser Seite schon (der Schalter „Aktiv"), seine Geometrie ist bei 390 px
 * gemessen, und ein Kästchen *ist* ein Schalter zwischen zwei Zuständen.
 */
const experte = ref(false)

/**
 * Der ganze Ausdruck als **Sicht** auf die fünf Felder — nicht als zweiter Wert.
 *
 * **Das ist die Bedingung, unter der es dieses Feld gibt.** Ein eigener
 * Speicherwert „Ausdruck" neben den Feldern wäre eine zweite Fassung desselben
 * Zeitplans, und die zweite ist die, die veraltet — derselbe Grund, aus dem die
 * Schnellwahl die Felder füllt, statt sich zu merken, dass „täglich" gewählt
 * wurde. Gespeichert wird weiter, was in den fünf Feldern steht; der Server
 * bekommt kein neues Feld und braucht keine zweite Prüfung.
 *
 * > **Eine Zusammenfügung darf doppelt stehen, eine Regel nicht.**
 *
 * **Was bei zu wenigen oder zu vielen Stücken passiert, ist Absicht.** Fehlende
 * Felder werden leer, überzählige landen im letzten — und beides weist der
 * Server ab, mit dem Satz, den er auch sonst schreibt. Die Alternative wäre,
 * hier zu urteilen: Dann stünde die Regel zweimal da, und der Kunde bekäme je
 * nach Weg eine andere Antwort.
 *
 * > **Eine Eingabe, die stillschweigend etwas wegwirft, macht aus einem Fehler
 * > des Benutzers ein Rätsel.**
 */
const freierAusdruck = computed({
  get: (): string => ausdruck.value,
  set: (wert: string): void => {
    const teile = wert.trim() === '' ? [] : wert.trim().split(/\s+/)

    form.minute = teile[0] ?? ''
    form.hour = teile[1] ?? ''
    form.day_of_month = teile[2] ?? ''
    form.month = teile[3] ?? ''
    form.day_of_week = teile.slice(4).join(' ')
  },
})

/**
 * Ob der Server an *irgendeinem* der fünf Felder etwas auszusetzen hatte.
 *
 * In der Expertenansicht gibt es die fünf Felder nicht, also kann keines von
 * ihnen rot werden. Der Satz dazu steht ohnehin oben in der Zusammenfassung
 * (`docs/19 §6`); das eine Feld trägt nur `aria-invalid`, damit die
 * Vorlesesoftware es findet.
 */
const zeitplanFalsch = computed(
  () => ['minute', 'hour', 'day_of_month', 'month', 'day_of_week']
    .some((feld) => Boolean(form.errors[feld as 'minute'])),
)

const voll = computed(() => props.quota.limit !== null && props.quota.used >= props.quota.limit)

function bearbeiten(job: Job): void {
  bearbeitet.value = job.id
  form.label = job.label
  form.command = job.command
  form.active = job.active
  Object.assign(form, job.schedule)
  form.clearErrors()
}

const formBlock = ref<HTMLElement | null>(null)

/**
 * Zum Formular gehen — und dabei sicherstellen, dass es zum Anlegen dasteht.
 *
 * **Der Bereich „Job anlegen" war bei 390 px nur durch Rollen zu erreichen**
 * (`docs/64`, Befund 13): Er ist der dritte von drei Bereichen, und dazwischen
 * liegt die Jobliste mit bis zu zehn Kärtchen. Wer einen Job anlegen wollte,
 * musste an ihnen vorbei — und nichts sagte ihm, dass dort etwas ist.
 *
 * **Der Griff steht in der Kopfzeile der Jobliste**, also dort, wo man nach
 * „noch einer" sucht, und nicht dort, wo man ihn schliesslich findet.
 *
 * `bearbeitet` wird zurückgesetzt: Wer „Job anlegen" drückt, meint anlegen,
 * auch wenn gerade ein anderer Job im Formular steht.
 *
 * Bei 1440 px steht das Formular ohnehin im Bild; `bringIntoView` rollt dann
 * nicht, sondern setzt nur den Fokus — für den Tastaturweg genau richtig.
 */
function zumFormular(): void {
  bearbeitet.value = null

  void nextTick(() => bringIntoView(formBlock.value))
}

/*
 * **Der Bereich, der aufgeht, holt sich ins Bild** — hier nach *unten*.
 *
 * „Ändern" steht in der Jobliste, das Formular darunter. Bei 390px ist es
 * ausserhalb des Bildes, und der Betreiber hat denselben Satz gesagt wie beim
 * Dateimanager (`docs/64`, Befund 10): *Man hat das Gefühl, es passiert
 * nichts.* Dort ging der Bereich oben auf, hier unten — für den Bedienenden
 * ist es dasselbe.
 *
 * > **Eine Behebung, die die Richtung nennt statt das Ziel, ist beim nächsten
 * > Fall die Hälfte wert.**
 *
 * `fullyVisible()` in `scroll.ts` prüft beide Ränder; „ins Bild holen" ist
 * deshalb schon die richtige Beschreibung und „nach oben rollen" wäre die
 * falsche gewesen.
 */
watch(bearbeitet, (offen) => {
  if (offen !== null) {
    void nextTick(() => bringIntoView(formBlock.value))
  }
})

function abbrechen(): void {
  bearbeitet.value = null
  form.reset()
  form.clearErrors()
}

function speichern(): void {
  const ziel = `/subscriptions/${props.subscription.id}/cron`

  if (bearbeitet.value !== null) {
    form.put(`${ziel}/${bearbeitet.value}`, { preserveScroll: true, onSuccess: () => abbrechen() })

    return
  }

  form.post(ziel, { preserveScroll: true, onSuccess: () => form.reset() })
}

// Kein `confirm()`: Safari darf die Dialoge einer Seite abschalten, und danach
// tut der Knopf wortlos nichts. `BrowserDialogTest` besteht darauf.
function entfernen(job: Job): void {
  ask(
    `Den Cronjob „${job.label}“ entfernen?\n\nSeine aufgezeichneten Läufe gehen mit.`,
    'Entfernen',
    () => router.delete(`/subscriptions/${props.subscription.id}/cron/${job.id}`, { preserveScroll: true }),
  )
}
</script>

<template>
  <Head :title="`Cronjobs — ${props.subscription.name}`" />

  <PanelLayout title="Cronjobs" :subline="props.subscription.name">
    <FormErrors />

    <div class="sections">
      <section class="section">
        <div class="section-head"><h2>Zeitplan und Zeitzone</h2></div>

        <p>
          Cronjobs laufen als <span class="ident">{{ props.subscription.system_user ?? '—' }}</span>
          und können alles, was dieser Benutzer darf.
        </p>

        <!--
          **Der wichtigste Satz dieser Seite, und er ist gemessen.** cron rechnet
          in der Zeit der Maschine; das Panel zeigt Zeitstempel sonst in der
          eingestellten Anzeigezone (`docs/40`). `CRON_TZ`, mit dem man das
          angleichen könnte, gibt es in diesem cron nicht — gemessen in
          `docs/60 §11`, dort wird es als gewöhnliche Umgebungsvariable
          durchgereicht.

          > **Zwei Zeiten auf einer Seite, von denen nur eine beschriftet ist,
          > sind eine Falle mit Erklärung daneben.**

          Der Satz steht einmal hier statt in jeder Zeile der Liste.
        -->
        <p class="notice neutral">
          <span>
            Der <b>Zeitplan</b> gilt in der Zeit des Servers
            (<span class="ident">{{ props.server_zone }}</span>).
            <template v-if="props.server_zone !== props.display_zone">
              Zeitpunkte in der Liste — etwa „nächste Fälligkeit“ — stehen dagegen in Ihrer
              Anzeigezone (<span class="ident">{{ props.display_zone }}</span>).
            </template>
          </span>
        </p>

        <!--
          Entscheidung 3 des Betreibers: Ein gesperrtes Abonnement pausiert seine
          Jobs. Ohne diesen Satz stünden sie als „aktiv“ da und liefen nicht —
          und das sähe aus wie ein Fehler des Servers.
        -->
        <p v-if="!props.subscription.usable" class="notice warn">
          <span>
            Dieses Abonnement ist nicht aktiv. Die Zeitpläne bleiben gespeichert, laufen
            aber nicht — sie beginnen wieder, sobald es fortgesetzt wird.
          </span>
        </p>

        <p class="section-note">
          Nach dem Speichern kann es bis zu einer Minute dauern, bis ein Zeitplan gilt:
          cron liest seine Dateien einmal je Minute neu.
        </p>
      </section>

      <section class="section">
        <div class="section-head">
          <h2>Jobs</h2>
          <span class="pager-state">
            {{ props.quota.used }}
            <template v-if="props.quota.limit !== null">von {{ props.quota.limit }}</template>
          </span>
          <button type="button" class="button small" @click="zumFormular">Job anlegen</button>
        </div>

        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th>Beschriftung</th>
                <th>Zeitplan</th>
                <th>Nächste Fälligkeit</th>
                <th>Zustand</th>
                <th v-if="props.can.manage">Aktion</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="job in props.jobs" :key="job.id">
                <td data-column="Beschriftung" class="cell-name">{{ job.label }}</td>

                <!--
                  **Der Satz, wenn es einen gibt — sonst der Ausdruck.** `Spoken`
                  gibt `null` zurück für alles, was sich nicht sicher übersetzen
                  lässt; dann steht hier der Ausdruck, und niemand liest eine
                  Behauptung, die nur meistens stimmt.
                -->
                <td data-column="Zeitplan">
                  <template v-if="job.spoken">{{ job.spoken }}</template>
                  <span v-else class="ident">{{ job.expression }}</span>
                </td>

                <td data-column="Nächste Fälligkeit" class="quiet">
                  <template v-if="!job.active">—</template>
                  <template v-else>{{ job.next_due ?? '—' }}</template>
                </td>

                <td data-column="Zustand">
                  <span class="badge" :class="job.active ? 'ok' : 'neutral'">
                    {{ job.active ? 'aktiv' : 'pausiert' }}
                  </span>
                </td>

                <td data-column="Aktion">
                  <div class="button-row">
                    <Link :href="`/subscriptions/${props.subscription.id}/cron/${job.id}/runs`" class="link">
                      Läufe
                    </Link>
                    <button type="button" class="button" @click="bearbeiten(job)">Ändern</button>
                    <button type="button" class="button danger" @click="entfernen(job)">Entfernen</button>
                  </div>
                </td>
              </tr>

              <tr v-if="props.jobs.length === 0">
                <td colspan="5" class="quiet">Für dieses Abonnement ist noch kein Cronjob eingerichtet.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section ref="formBlock" class="section" tabindex="-1">
        <div class="section-head">
          <h2>{{ bearbeitet === null ? 'Job anlegen' : 'Job ändern' }}</h2>
        </div>

        <p v-if="voll && bearbeitet === null" class="notice warn">
          <span>
            Das Kontingent dieses Plans ist ausgeschöpft. Entfernen Sie einen Job,
            um einen neuen anzulegen.
          </span>
        </p>

        <form class="form" @submit.prevent="speichern">
          <div class="field">
            <label for="label">Beschriftung</label>
            <input
              id="label"
              v-model="form.label"
              type="text"
              maxlength="120"
              :aria-invalid="form.errors.label ? 'true' : undefined"
            >
            <p class="hint">Wofür der Job da ist — damit später jemand weiss, was er entfernen darf.</p>
          </div>

          <div class="field">
            <label for="command">Befehl</label>
            <textarea
              id="command"
              v-model="form.command"
              rows="3"
              :aria-invalid="form.errors.command ? 'true' : undefined"
            />
            <p class="hint">
              Läuft über <span class="ident">/bin/sh</span>. Der Suchpfad ist
              <span class="ident">/usr/local/bin:/usr/bin:/bin</span> — geben Sie eigene
              Programme mit vollem Pfad an.
            </p>
          </div>

          <fieldset class="field">
            <legend>Schnellwahl</legend>
            <!--
              Die Beschriftung ist der Satz: Was der Knopf sagt, ist das, was er
              einstellt. Damit braucht die Schnellwahl keine eigene Übersetzung.
            -->
            <div class="button-row">
              <button
                v-for="vorlage in vorlagen"
                :key="vorlage.name"
                type="button"
                class="button"
                @click="vorlageWaehlen(vorlage.felder)"
              >
                {{ vorlage.name }}
              </button>
            </div>
          </fieldset>

          <fieldset class="field">
            <legend>Zeitplan (Serverzeit)</legend>

            <!--
              **Der Umschalter steht über beiden Ansichten und nicht in einer.**
              Wer ihn sucht, sucht ihn dort, wo der Zeitplan anfängt — und er
              gehört zu beiden Zuständen gleichermassen.

              Die Schnellwahl darüber wirkt in beiden: Sie füllt die fünf
              Felder, und der freie Ausdruck ist eine Sicht auf genau die.
            -->
            <div class="field">
              <label class="toggle">
                <input v-model="experte" type="checkbox">
                <span>Den Zeitplan als Ausdruck eingeben</span>
              </label>
            </div>

            <div v-if="experte" class="field">
              <label for="expression">Ausdruck</label>
              <input
                id="expression"
                v-model="freierAusdruck"
                type="text"
                class="ident"
                spellcheck="false"
                placeholder="*/15 * * * *"
                :aria-invalid="zeitplanFalsch ? 'true' : undefined"
              >
            </div>

            <div v-else class="field-row">
              <div class="field">
                <label for="minute">Minute</label>
                <input id="minute" v-model="form.minute" type="text" class="ident"
                       :aria-invalid="form.errors.minute ? 'true' : undefined">
              </div>
              <div class="field">
                <label for="hour">Stunde</label>
                <input id="hour" v-model="form.hour" type="text" class="ident"
                       :aria-invalid="form.errors.hour ? 'true' : undefined">
              </div>
              <div class="field">
                <label for="day_of_month">Tag des Monats</label>
                <input id="day_of_month" v-model="form.day_of_month" type="text" class="ident"
                       :aria-invalid="form.errors.day_of_month ? 'true' : undefined">
              </div>
              <div class="field">
                <label for="month">Monat</label>
                <input id="month" v-model="form.month" type="text" class="ident"
                       :aria-invalid="form.errors.month ? 'true' : undefined">
              </div>
              <div class="field">
                <label for="day_of_week">Wochentag</label>
                <input id="day_of_week" v-model="form.day_of_week" type="text" class="ident"
                       :aria-invalid="form.errors.day_of_week ? 'true' : undefined">
              </div>
            </div>

            <p class="hint">
              Erlaubt sind <span class="ident literal">*</span>, Zahlen, Spannen
              (<span class="ident literal">9-17</span>), Listen (<span class="ident literal">1,4</span>)
              und Schritte (<span class="ident literal">*/15</span>). Der Wochentag zählt von 0
              (Sonntag) bis 7 (auch Sonntag).
              <template v-if="experte">
                Fünf Felder, durch Leerzeichen getrennt: Minute, Stunde, Tag des Monats,
                Monat, Wochentag.
              </template>
              <template v-else>
                Ergibt: <span class="ident">{{ ausdruck }}</span>
              </template>
            </p>
          </fieldset>

          <div class="field">
            <!--
              **`toggle` und nicht `check`.** Hier stand `class="check"` am
              `<label>` — und `.check` ist in `app.css` die Klasse des
              **Kästchens selbst**: `flex: none`, 17×17 px. Das Label bekam damit
              17 px Breite, und die Beschriftung stand wortweise untereinander
              am Seitenende.

              Der Dokumentüberlauf war dabei **0 px**. Gefunden hat es die
              Aufnahme bei 390 px und nicht die Zahl.

              > **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur
              > einen Betrachter.**
            -->
            <label class="toggle">
              <input v-model="form.active" type="checkbox">
              <span>Aktiv — der Job läuft nach seinem Zeitplan</span>
            </label>
          </div>

          <div class="button-row">
            <button type="submit" class="button primary" :disabled="form.processing || (voll && bearbeitet === null)">
              {{ bearbeitet === null ? 'Anlegen' : 'Speichern' }}
            </button>
            <button v-if="bearbeitet !== null" type="button" class="button" @click="abbrechen">
              Abbrechen
            </button>
          </div>
        </form>
      </section>
    </div>
  </PanelLayout>
</template>
