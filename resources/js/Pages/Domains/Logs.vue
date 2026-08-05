<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import PanelLayout from '../../Layouts/PanelLayout.vue'

const props = defineProps<{
  domain: { id: number; name: string }
  kind: string
  lines: number
  log: { lines: string[]; exists: boolean; error: string | null; path: string | null }
}>()

function zeige(kind: string): void {
  router.get(`/domains/${props.domain.id}/logs`, { kind, lines: props.lines }, { preserveState: false })
}

function mehr(): void {
  router.get(`/domains/${props.domain.id}/logs`, { kind: props.kind, lines: Math.min(500, props.lines * 2) })
}
</script>

<template>
  <Head :title="`Protokolle · ${props.domain.name}`" />

  <PanelLayout :title="props.domain.name" subline="Protokolle">
    <template #breadcrumb>
      <Link href="/domains" class="link">Domains</Link> ·
      <Link :href="`/domains/${props.domain.id}`" class="link">{{ props.domain.name }}</Link>
    </template>

    <template #actions>
      <!--
        Die beiden Umschalter sind eine Wahl und keine Rangfolge: Der gewählte
        trägt `.aktiv` — Akzentrand und getönte Fläche —, nicht `.wichtig`.
        Sonst stünde in einer Zweierreihe eine Hauptsache, und die Reihe ist
        keine.
      -->
      <button type="button" class="button" :class="{ active: props.kind === 'access' }" @click="zeige('access')">
        Zugriffe
      </button>
      <button type="button" class="button" :class="{ active: props.kind === 'error' }" @click="zeige('error')">
        Fehler
      </button>
    </template>

    <!--
      Drei verschiedene Auskünfte, und keine sieht aus wie eine andere: Der
      Agent antwortet nicht, die Datei gibt es noch nicht, die Datei ist leer.
      Eine leere Liste für alle drei wäre die bequeme Lösung — und sähe im
      ersten Fall aus, als sei alles in Ordnung.
    -->
    <p v-if="props.log.error" class="notice critical">
      Der Agent antwortet nicht: {{ props.log.error }}
    </p>

    <p v-else-if="!props.log.exists" class="empty">
      Für diese Domain gibt es noch kein Protokoll. Es entsteht mit dem ersten
      Zugriff.
    </p>

    <p v-else-if="props.log.lines.length === 0" class="empty">
      Das Protokoll ist leer.
    </p>

    <template v-else>
      <p class="breadcrumb ident">{{ props.log.path }}</p>

      <pre class="output log">{{ props.log.lines.join('\n') }}</pre>

      <div class="button-row footer-row">
        <button v-if="props.lines < 500" type="button" class="button" @click="mehr">
          Mehr Zeilen ({{ props.lines }} → {{ Math.min(500, props.lines * 2) }})
        </button>
      </div>
    </template>
  </PanelLayout>
</template>

<style scoped>
/*
 * Das Protokoll rollt in beide Richtungen und bricht keine Zeile um.
 *
 * Eine umgebrochene Zeile eines Zugriffsprotokolls ist unlesbar: Man erkennt
 * nicht mehr, wo ein Eintrag anfängt. Auf 390px rollt es waagerecht — dieselbe
 * Entscheidung wie bei den Tabellen unter `.scrolls`.
 *
 * Form und Farbe kommen aus `.output` in app.css; hier steht nur, was dieses
 * eine Protokoll davon unterscheidet.
 */
.log {
  margin: 0;
  max-height: 60dvh;
  overflow: auto;
  white-space: pre;
}

.footer-row {
  margin-top: var(--gap);
}
</style>
