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
                    **`.output` scrollt selbst und bricht um.** `docs/48` hat
                    gemessen, was eine Zelle anrichtet, die rollen darf und keine
                    Obergrenze hat: 5710 px Inhalt bei einem Dokumentüberlauf von
                    0 px — zehn Bildschirme Rollen durch eine einzige Zelle.

                    > **Eine Zelle, die rollen darf, hat keine Obergrenze — sie
                    > hat nur keine Zahl, die sich beschwert.**
                  -->
                  <pre v-if="run.output" class="output">{{ run.output }}</pre>
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
