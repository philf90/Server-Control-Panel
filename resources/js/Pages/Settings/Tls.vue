<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import Bereich from '../../Components/Bereich.vue'
import Marke from '../../Components/Marke.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

interface Certificate {
  present: boolean
  reason?: string
  path?: string
  subject?: string
  issuer?: string
  self_signed?: boolean
  valid_from?: number
  valid_to?: number
  names?: { dns: string[]; ip: string[] }
  missing?: { dns: string[]; ip: string[] }
}

const props = defineProps<{ certificate: Certificate }>()

/*
 * Die Restlaufzeit in Tagen, abgerundet.
 *
 * Abgerundet, weil aufgerundet in genau dem Fall schmeichelt, in dem es
 * darauf ankommt: Ein Zertifikat mit zwölf Stunden Restlaufzeit steht sonst
 * mit „1 Tag" da und klingt wie etwas, das bis morgen Zeit hat.
 */
const tage = computed(() => {
  if (!props.certificate.valid_to) return null

  return Math.floor((props.certificate.valid_to * 1000 - Date.now()) / 86400000)
})

const abgelaufen = computed(() => tage.value !== null && tage.value < 0)
const knapp = computed(() => tage.value !== null && tage.value >= 0 && tage.value <= 30)

function datum(zeit?: number): string {
  return zeit ? new Date(zeit * 1000).toLocaleDateString('de-DE') : '—'
}

const fehlende = computed(() => [
  ...(props.certificate.missing?.dns ?? []),
  ...(props.certificate.missing?.ip ?? []),
])

function neuAusstellen(): void {
  if (!window.confirm(
    'Neues Zertifikat ausstellen? nginx wird danach neu geladen, und der Browser '
    + 'warnt beim nächsten Aufruf erneut — ein selbstsigniertes Zertifikat kennt er nicht.',
  )) return

  router.post('/settings/tls')
}
</script>

<template>
  <Head title="Zertifikat" />

  <PanelLayout title="Zertifikat" subline="Die Verbindung zu dieser Oberfläche">
    <template #aktion>
      <Marke v-if="props.certificate.present" :art="abgelaufen ? 'kritisch' : knapp ? 'warn' : 'ok'">
        <template v-if="abgelaufen">abgelaufen</template>
        <template v-else-if="tage !== null">noch {{ tage }} Tage</template>
        <template v-else>vorhanden</template>
      </Marke>
      <button type="button" class="knopf" @click="neuAusstellen">Neu ausstellen</button>
    </template>

    <p v-if="!props.certificate.present" class="meldung kritisch">
      <span>{{ props.certificate.reason ?? 'Es liegt kein Zertifikat vor.' }}</span>
    </p>

    <template v-else>
      <!--
        Die Warnungen stehen über den Angaben und nicht daneben: Wer diese
        Seite öffnet, hat meistens genau eine Frage, und wenn etwas nicht
        stimmt, ist das die Antwort darauf.
      -->
      <p v-if="abgelaufen" class="meldung kritisch">
        <span>
          Das Zertifikat ist am {{ datum(props.certificate.valid_to) }}
          abgelaufen. Der Browser lässt die Verbindung nicht mehr ohne Weiteres
          zu.
        </span>
      </p>
      <p v-else-if="knapp" class="meldung warn">
        <span>
          Das Zertifikat läuft in <b>{{ tage }}</b> Tagen ab. Die tägliche
          Prüfung (<span class="kennung">srvpanel-tls.timer</span>) erneuert es
          von selbst; passiert das nicht, steht der Grund im Journal des
          Dienstes.
        </span>
      </p>

      <p v-if="fehlende.length > 0" class="meldung warn">
        <span>
          Dieser Rechner ist auch unter
          <span class="kennung">{{ fehlende.join(', ') }}</span> erreichbar —
          das Zertifikat deckt das nicht ab. Eine neue Adresse stellt es nicht
          von selbst neu aus; auf einem Server mit Docker gäbe das jede Woche
          ein neues Zertifikat samt neuer Warnung im Browser.
        </span>
      </p>

      <div class="bereiche">
        <Bereich titel="Ausstellung">
          <table class="paare">
            <tbody>
              <tr>
                <td class="stumm">Ausgestellt für</td>
                <td class="rechts kennung">{{ props.certificate.subject || '—' }}</td>
              </tr>
              <tr>
                <td class="stumm">Aussteller</td>
                <td class="rechts kennung">{{ props.certificate.issuer || '—' }}</td>
              </tr>
              <tr v-if="props.certificate.self_signed">
                <td class="stumm">Art</td>
                <td class="rechts"><Marke art="warn">selbstsigniert</Marke></td>
              </tr>
              <tr>
                <td class="stumm">Gültig ab</td>
                <td class="rechts">{{ datum(props.certificate.valid_from) }}</td>
              </tr>
              <tr>
                <td class="stumm">Gültig bis</td>
                <td class="rechts">{{ datum(props.certificate.valid_to) }}</td>
              </tr>
              <tr>
                <td class="stumm">Datei</td>
                <td class="rechts kennung">{{ props.certificate.path }}</td>
              </tr>
            </tbody>
          </table>
        </Bereich>

        <Bereich titel="Gilt für" erklaerung="Namen und Adressen, unter denen dieses Zertifikat anerkannt wird.">
          <table class="paare">
            <tbody>
              <tr>
                <td class="stumm">Namen</td>
                <td class="rechts kennung">{{ props.certificate.names?.dns.join(', ') || '—' }}</td>
              </tr>
              <tr>
                <td class="stumm">Adressen</td>
                <td class="rechts kennung">{{ props.certificate.names?.ip.join(', ') || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </Bereich>
      </div>

      <p v-if="props.certificate.self_signed" class="meldung neutral nachtrag">
        <span>
          Ein selbstsigniertes Zertifikat kennt kein Browser — die Warnung beim
          ersten Aufruf gehört dazu. Ein Zertifikat von Let's Encrypt kommt mit
          der Ausbaustufe P4; bis dahin ist die Verbindung verschlüsselt, aber
          nicht bestätigt.
        </span>
      </p>
    </template>
  </PanelLayout>
</template>

<style scoped>
/* Diese eine Meldung steht am Fuss der Seite und nicht darüber: Sie ordnet
   ein, was oben steht, und ist keine Antwort auf die Frage, mit der jemand
   hierherkommt. */
.nachtrag {
  margin-top: var(--block-gap);
  margin-bottom: 0;
}
</style>
