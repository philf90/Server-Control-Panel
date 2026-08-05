<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import Section from '../../Components/Section.vue'
import Badge from '../../Components/Badge.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

interface Certificate {
  present: boolean
  reason?: string
  path?: string
  acme?: boolean
  subject?: string
  issuer?: string
  self_signed?: boolean
  valid_from?: number
  valid_to?: number
  names?: { dns: string[]; ip: string[] }
  missing?: { dns: string[]; ip: string[] }
}

const props = defineProps<{
  certificate: Certificate
  acme: { contact: string | null; directory: string; staging: boolean; configured: boolean }
  directories: { value: string; label: string }[]
}>()

/*
 * Das Formular, ohne das nichts bestellt wird.
 *
 * Die Kontaktadresse wird nicht geraten: An sie schreibt die
 * Zertifizierungsstelle, wenn ein Zertifikat abzulaufen droht. Solange sie
 * fehlt, tut TLS still nichts — und „still nichts" ist der Zustand, den
 * niemand von aussen erkennt.
 */
const form = useForm({
  contact: props.acme.contact ?? '',
  directory: props.acme.directory,
})

function speichern(): void {
  form.put('/settings/tls/acme', { preserveScroll: true })
}

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

/*
 * Der Knopf stellt das **selbstsignierte** neu aus, nicht das von Let's
 * Encrypt.
 *
 * Solange nur die Notlösung dalag, war das dasselbe und die Beschriftung
 * durfte kurz sein. Seit daneben ein Zertifikat von Let's Encrypt liegen kann,
 * ist es das nicht mehr: Der Knopf erneuert dann den *Rückweg*, und im Browser
 * ändert sich nichts. Ein Knopf, der sichtbar nichts tut, ist genau der
 * Fehler, den dieses Projekt schon einmal gemacht hat — deshalb sagen
 * Beschriftung und Rückfrage beide, worum es geht.
 */
function neuAusstellen(): void {
  const nachfrage = props.certificate.acme
    ? 'Das selbstsignierte Zertifikat neu ausstellen? Es ist der Rückweg für den Fall, '
      + 'dass kein Name mehr auf diesen Server zeigt. Ausgeliefert wird weiterhin das '
      + 'Zertifikat von Let’s Encrypt — im Browser ändert sich nichts.'
    : 'Neues Zertifikat ausstellen? nginx wird danach neu geladen, und der Browser '
      + 'warnt beim nächsten Aufruf erneut — ein selbstsigniertes Zertifikat kennt er nicht.'

  if (!window.confirm(nachfrage)) return

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
      <button type="button" class="button" @click="neuAusstellen">
        {{ props.certificate.acme ? 'Rückweg erneuern' : 'Neu ausstellen' }}
      </button>
    </template>

    <p v-if="!props.certificate.present" class="notice critical">
      <span>{{ props.certificate.reason ?? 'Es liegt kein Zertifikat vor.' }}</span>
    </p>

    <!--
      Ohne Kontaktadresse bestellt das Panel nichts — für keine Domain. Das
      ist die Auskunft, die von aussen niemand bekommt: Es passiert schlicht
      nichts, und nichts meldet sich. Deshalb steht sie ganz oben.
    -->
    <p v-if="!props.acme.configured" class="notice warn">
      <span>
        Ohne Kontaktadresse werden keine Zertifikate bestellt — weder für diese
        Oberfläche noch für eine Kundendomain. An diese Adresse schreibt die
        Zertifizierungsstelle, wenn ein Zertifikat abzulaufen droht.
      </span>
    </p>

    <p v-else-if="props.acme.staging" class="notice warn">
      <span>
        Der <b>Testbetrieb</b> ist eingestellt. Ausgestellt wird weiter, aber
        kein Browser kennt die Wurzel dahinter — jede Domain zeigt die Warnung.
        Für die Oberfläche selbst wird im Testbetrieb gar nichts bestellt: Ein
        solches Zertifikat käme mit erzwungenem HTTPS, und die Warnung liesse
        sich danach nicht mehr wegklicken.
      </span>
    </p>

    <template v-if="props.certificate.present">
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
              <tr>
                <td class="quiet">Art</td>
                <td class="right">
                  <Badge v-if="props.certificate.acme" kind="ok">von Let’s Encrypt</Badge>
                  <Badge v-else-if="props.certificate.self_signed" kind="warn">selbstsigniert</Badge>
                  <Badge v-else kind="neutral">hinterlegt</Badge>
                </td>
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
          ersten Aufruf gehört dazu. Die Verbindung ist verschlüsselt, aber
          nicht bestätigt. Sobald eine Kontaktadresse eingetragen ist, die
          Zertifizierungsstelle auf produktiv steht und ein Name auf diesen
          Server zeigt, holt die tägliche Prüfung ein Zertifikat von Let’s
          Encrypt — und diese Seite zeigt es dann hier.
        </span>
      </p>

      <p v-else-if="props.certificate.acme" class="notice neutral postscript">
        <span>
          Ausgeliefert wird das Zertifikat von Let’s Encrypt. Das
          selbstsignierte bleibt daneben liegen und bleibt gültig — es ist der
          Rückweg für den Fall, dass unter dem Namen dieses Servers nichts mehr
          steht.
        </span>
      </p>
    </template>

    <form class="form" @submit.prevent="speichern">
      <Section title="Let’s Encrypt" note="Ohne diese beiden Angaben bestellt das Panel nichts.">
        <label class="field">
          <span>Kontaktadresse</span>
          <input v-model="form.contact" type="email" autocomplete="off" placeholder="post@example.de" required>
        </label>
        <p v-if="form.errors.contact" class="error">{{ form.errors.contact }}</p>
        <p v-else class="hint">
          Sie wird nicht aus dem ersten Adminkonto abgeleitet: Ein Zertifikat ist
          eine Behauptung darüber, wer man ist, und die Adresse dazu gehört
          gesetzt.
        </p>

        <label class="field">
          <span>Zertifizierungsstelle</span>
          <select v-model="form.directory">
            <option v-for="d in props.directories" :key="d.value" :value="d.value">{{ d.label }}</option>
          </select>
        </label>
        <p v-if="form.errors.directory" class="error">{{ form.errors.directory }}</p>
        <p v-else class="hint">
          Produktiv sind fünf Fehlversuche je Stunde die Grenze. Wer einen neuen
          Server einrichtet, bleibt so lange im Testbetrieb, bis eine Domain
          wirklich hierher zeigt.
        </p>

        <div class="button-row">
          <button type="submit" class="button primary" :disabled="form.processing">
            {{ form.processing ? 'Wird gespeichert …' : 'Speichern' }}
          </button>
        </div>
      </Section>
    </form>
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
