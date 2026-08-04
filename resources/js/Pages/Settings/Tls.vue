<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed } from 'vue'
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
    <p v-if="!props.certificate.present" class="fehler-block">
      {{ props.certificate.reason ?? 'Es liegt kein Zertifikat vor.' }}
    </p>

    <template v-else>
      <!--
        Die Warnungen stehen über den Angaben und nicht daneben: Wer diese
        Seite öffnet, hat meistens genau eine Frage, und wenn etwas nicht
        stimmt, ist das die Antwort darauf.
      -->
      <p v-if="abgelaufen" class="fehler-block">
        Das Zertifikat ist am {{ datum(props.certificate.valid_to) }} abgelaufen.
        Der Browser lässt die Verbindung nicht mehr ohne Weiteres zu.
      </p>
      <p v-else-if="knapp" class="warnung">
        Das Zertifikat läuft in {{ tage }} Tagen ab. Die tägliche Prüfung
        (<code>srvpanel-tls.timer</code>) erneuert es von selbst; passiert das
        nicht, steht der Grund im Journal des Dienstes.
      </p>

      <p v-if="fehlende.length > 0" class="warnung">
        Dieser Rechner ist auch unter {{ fehlende.join(', ') }} erreichbar —
        das Zertifikat deckt das nicht ab. Eine neue Adresse stellt es nicht
        von selbst neu aus; auf einem Server mit Docker gäbe das jede Woche ein
        neues Zertifikat samt neuer Warnung im Browser.
      </p>

      <section class="karte">
        <dl>
          <dt>Ausgestellt für</dt>
          <dd class="fest">{{ props.certificate.subject || '—' }}</dd>

          <dt>Aussteller</dt>
          <dd>
            <span class="fest">{{ props.certificate.issuer || '—' }}</span>
            <span v-if="props.certificate.self_signed" class="marke">selbstsigniert</span>
          </dd>

          <dt>Gültig</dt>
          <dd>
            {{ datum(props.certificate.valid_from) }} bis {{ datum(props.certificate.valid_to) }}
            <span v-if="tage !== null && tage >= 0" class="rest">noch {{ tage }} Tage</span>
          </dd>

          <dt>Namen</dt>
          <dd class="fest">{{ props.certificate.names?.dns.join(', ') || '—' }}</dd>

          <dt>Adressen</dt>
          <dd class="fest">{{ props.certificate.names?.ip.join(', ') || '—' }}</dd>

          <dt>Datei</dt>
          <dd class="fest">{{ props.certificate.path }}</dd>
        </dl>
      </section>

      <p v-if="props.certificate.self_signed" class="hinweis-block">
        Ein selbstsigniertes Zertifikat kennt kein Browser — die Warnung beim
        ersten Aufruf gehört dazu. Ein Zertifikat von Let's Encrypt kommt mit
        der Ausbaustufe P4; bis dahin ist die Verbindung verschlüsselt, aber
        nicht bestätigt.
      </p>
    </template>

    <div class="knopfreihe">
      <button type="button" class="knopf" @click="neuAusstellen">Neu ausstellen</button>
    </div>
  </PanelLayout>
</template>

<style scoped>
.karte { max-width: 640px; padding: var(--padding); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 8px; }
dl { display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; margin: 0; font-size: var(--text-table); }
dt { color: var(--text-muted); }
dd { margin: 0; color: var(--text); word-break: break-word; }
.fest { font-family: var(--font-mono); }
.marke { margin-left: 8px; padding: 1px 5px; font-family: var(--font-sans); font-size: var(--text-label); color: var(--warn); background: var(--warn-surface); border-radius: 3px; }
.rest { margin-left: 8px; font-size: var(--text-small); color: var(--text-faint); }
.warnung, .fehler-block, .hinweis-block { max-width: 640px; margin: 0 0 var(--gap); padding: 8px 11px; font-size: var(--text-table); border-radius: 6px; line-height: 1.5; }
.warnung { color: var(--warn); background: var(--warn-surface); }
.fehler-block { color: var(--critical); background: var(--critical-surface); }
.hinweis-block { margin: var(--gap) 0 0; color: var(--text-muted); background: var(--surface); border: 1px solid var(--surface-border); }
code { font-family: var(--font-mono); }
.knopfreihe { margin-top: var(--gap); }

/* Unter 480px hat die zweispaltige Liste keinen Platz mehr: Ein Wert wie
   „/etc/srvpanel/tls/panel.crt" bräuchte dort mehr als die halbe Breite. */
@media (max-width: 480px) {
  dl { grid-template-columns: 1fr; gap: 2px; }
  dt { margin-top: 8px; }
  dt:first-child { margin-top: 0; }
}
</style>
