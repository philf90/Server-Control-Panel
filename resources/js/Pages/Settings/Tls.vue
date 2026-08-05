<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import Section from '../../Components/Section.vue'
import Badge from '../../Components/Badge.vue'
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
    <template #actions>
      <Badge v-if="props.certificate.present" :kind="abgelaufen ? 'critical' : knapp ? 'warn' : 'ok'">
        <template v-if="abgelaufen">abgelaufen</template>
        <template v-else-if="tage !== null">noch {{ tage }} Tage</template>
        <template v-else>vorhanden</template>
      </Badge>
      <button type="button" class="button" @click="neuAusstellen">Neu ausstellen</button>
    </template>

    <p v-if="!props.certificate.present" class="notice critical">
      <span>{{ props.certificate.reason ?? 'Es liegt kein Zertifikat vor.' }}</span>
    </p>

    <template v-else>
      <!--
        Die Warnungen stehen über den Angaben und nicht daneben: Wer diese
        Seite öffnet, hat meistens genau eine Frage, und wenn etwas nicht
        stimmt, ist das die Antwort darauf.
      -->
      <p v-if="abgelaufen" class="notice critical">
        <span>
          Das Zertifikat ist am {{ datum(props.certificate.valid_to) }}
          abgelaufen. Der Browser lässt die Verbindung nicht mehr ohne Weiteres
          zu.
        </span>
      </p>
      <p v-else-if="knapp" class="notice warn">
        <span>
          Das Zertifikat läuft in <b>{{ tage }}</b> Tagen ab. Die tägliche
          Prüfung (<span class="ident">srvpanel-tls.timer</span>) erneuert es
          von selbst; passiert das nicht, steht der Grund im Journal des
          Dienstes.
        </span>
      </p>

      <p v-if="fehlende.length > 0" class="notice warn">
        <span>
          Dieser Rechner ist auch unter
          <span class="ident">{{ fehlende.join(', ') }}</span> erreichbar —
          das Zertifikat deckt das nicht ab. Eine neue Adresse stellt es nicht
          von selbst neu aus; auf einem Server mit Docker gäbe das jede Woche
          ein neues Zertifikat samt neuer Warnung im Browser.
        </span>
      </p>

      <div class="sections">
        <Section title="Ausstellung">
          <table class="pairs">
            <tbody>
              <tr>
                <td class="quiet">Ausgestellt für</td>
                <td class="right ident">{{ props.certificate.subject || '—' }}</td>
              </tr>
              <tr>
                <td class="quiet">Aussteller</td>
                <td class="right ident">{{ props.certificate.issuer || '—' }}</td>
              </tr>
              <tr v-if="props.certificate.self_signed">
                <td class="quiet">Art</td>
                <td class="right"><Badge kind="warn">selbstsigniert</Badge></td>
              </tr>
              <tr>
                <td class="quiet">Gültig ab</td>
                <td class="right">{{ datum(props.certificate.valid_from) }}</td>
              </tr>
              <tr>
                <td class="quiet">Gültig bis</td>
                <td class="right">{{ datum(props.certificate.valid_to) }}</td>
              </tr>
              <tr>
                <td class="quiet">Datei</td>
                <td class="right ident">{{ props.certificate.path }}</td>
              </tr>
            </tbody>
          </table>
        </Section>

        <Section title="Gilt für" note="Namen und Adressen, unter denen dieses Zertifikat anerkannt wird.">
          <table class="pairs">
            <tbody>
              <tr>
                <td class="quiet">Namen</td>
                <td class="right ident">{{ props.certificate.names?.dns.join(', ') || '—' }}</td>
              </tr>
              <tr>
                <td class="quiet">Adressen</td>
                <td class="right ident">{{ props.certificate.names?.ip.join(', ') || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </Section>
      </div>

      <p v-if="props.certificate.self_signed" class="notice neutral postscript">
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
.postscript {
  margin-top: var(--block-gap);
  margin-bottom: 0;
}
</style>
