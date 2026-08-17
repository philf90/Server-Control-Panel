<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import PanelLayout from '../../Layouts/PanelLayout.vue'

interface Run {
  id: number
  started_at: string | null
  duration_ms: number
  exit_code: number | null
  status: string
  status_label: string
  tone: string
  output: string | null
  truncated: boolean
}

const props = defineProps<{
  subscription: { id: number; name: string }
  job: { id: number; label: string; expression: string; spoken: string | null }
  runs: Run[]
  keep: number
}>()

/**
 * Die Dauer als Text — Millisekunden, Sekunden, Minuten.
 *
 * `8 ms` und `1.2 s` sagen dasselbe verschieden gut. Eine Zahl in
 * Millisekunden ist bei einem Lauf über eine Stunde nicht mehr zu lesen.
 */
function dauer(ms: number): string {
  if (ms < 1000) {
    return `${ms} ms`
  }

  if (ms < 60_000) {
    return `${(ms / 1000).toFixed(1)} s`
  }

  return `${Math.round(ms / 60_000)} min`
}
</script>

<template>
  <Head :title="`Läufe — ${props.job.label}`" />

  <PanelLayout title="Läufe" :subline="`${props.job.label} — ${props.subscription.name}`">
    <div class="sections">
      <section class="section">
        <div class="section-head"><h2>Der Job</h2></div>

        <table class="pairs">
          <tbody>
            <tr>
              <td class="quiet">Zeitplan</td>
              <td class="right">
                <template v-if="props.job.spoken">{{ props.job.spoken }}</template>
                <span v-else class="ident">{{ props.job.expression }}</span>
              </td>
            </tr>
          </tbody>
        </table>

        <p class="section-note">
          Aufgehoben werden die letzten {{ props.keep }} Läufe je Job; ältere fallen beim
          Einpflegen weg. Die Ausgabe wird bei 64 KB gekappt.
        </p>

        <p>
          <Link :href="`/subscriptions/${props.subscription.id}/cron`" class="link">
            Zurück zu den Cronjobs
          </Link>
        </p>
      </section>

      <section class="section">
        <div class="section-head"><h2>Aufgezeichnet</h2></div>

        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th>Begonnen</th>
                <th>Dauer</th>
                <th>Ergebnis</th>
                <th>Ausgabe</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="run in props.runs" :key="run.id">
                <td data-column="Begonnen" class="cell-name">{{ run.started_at ?? '—' }}</td>

                <td data-column="Dauer" class="quiet">
                  <!--
                    Ein übersprungener Lauf hat keine Dauer — er hat nie
                    stattgefunden. „0 ms“ läse sich wie „ging sehr schnell“.
                  -->
                  <template v-if="run.status === 'skipped'">—</template>
                  <template v-else>{{ dauer(run.duration_ms) }}</template>
                </td>

                <td data-column="Ergebnis">
                  <span class="badge" :class="run.tone">{{ run.status_label }}</span>
                  <!--
                    Der Rückgabewert steht daneben und nicht statt der Marke: Ein
                    „124“ ist für den Kunden keine Auskunft, „Zeit überschritten“
                    schon — und bei einem übersprungenen Lauf gibt es gar keinen.
                  -->
                  <span v-if="run.exit_code !== null && run.status !== 'ok'" class="quiet">
                    Rückgabewert <span class="ident">{{ run.exit_code }}</span>
                  </span>
                </td>

                <td data-column="Ausgabe">
                  <!--
                    **Ein `div` im `.output`, und das ist keine Verzierung.**
                    Hier stand `<pre class="output">` mit einem Kommentar
                    darüber, `.output` rolle selbst und breche um. Das war
                    schlicht falsch: In `app.css` trägt **`.output > div`** die
                    Regeln `white-space: pre-wrap` und `word-break: break-word`,
                    der Behälter selbst nur `overflow-y`. Ohne das innere `div`
                    gilt `white-space: pre`, und eine Ausgabezeile ohne
                    Leerzeichen schiebt die ganze Seite.

                    Gemessen mit dem Aufsatz aus `docs/58 §12`: Mit `pre`
                    steht `white-space: pre`, mit dem inneren `div`
                    `pre-wrap`/`break-word` — der Unterschied ist belegt.

                    > **Ein Kommentar, der eine Eigenschaft behauptet, prüft sie
                    > nicht — er macht nur unwahrscheinlicher, dass jemand
                    > nachsieht.**

                    **Was damit noch nicht behoben ist:** Die Zelle bleibt
                    3163 px breit, weil `table-layout: auto` sie auf ihre
                    Inhaltsbreite zieht und `pre-wrap` erst an einer *begrenzten*
                    Breite umbricht. Der Dokumentüberlauf ist 0 px, weil
                    `.scrolls` das aufnimmt — genau der Fall aus `docs/48`:

                    > **Eine Zelle, die rollen darf, hat keine Obergrenze — sie
                    > hat nur keine Zahl, die sich beschwert.**

                    Das gehört als Regel nach `app.css` und nicht hierher; es ist
                    als offener Punkt benannt.
                  -->
                  <div v-if="run.output" class="output"><div>{{ run.output }}</div></div>
                  <span v-else class="quiet">keine Ausgabe</span>

                  <span v-if="run.truncated" class="quiet">
                    Die Ausgabe ist bei 64 KB abgeschnitten.
                  </span>
                </td>
              </tr>

              <tr v-if="props.runs.length === 0">
                <td colspan="4" class="quiet">
                  Für diesen Job ist noch kein Lauf aufgezeichnet. Läufe werden alle fünf
                  Minuten eingesammelt.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>
  </PanelLayout>
</template>
