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
    <div class="kopf">
      <div class="wahl">
        <button
          type="button"
          :class="['knopf', { aktiv: props.kind === 'access' }]"
          @click="zeige('access')"
        >
          Zugriffe
        </button>
        <button
          type="button"
          :class="['knopf', { aktiv: props.kind === 'error' }]"
          @click="zeige('error')"
        >
          Fehler
        </button>
      </div>

      <Link class="knopf" :href="`/domains/${props.domain.id}`">Zur Domain</Link>
    </div>

    <!--
      Drei verschiedene Auskünfte, und keine sieht aus wie eine andere: Der
      Agent antwortet nicht, die Datei gibt es noch nicht, die Datei ist leer.
      Eine leere Liste für alle drei wäre die bequeme Lösung — und sähe im
      ersten Fall aus, als sei alles in Ordnung.
    -->
    <p v-if="props.log.error" class="fehler-block">
      Der Agent antwortet nicht: {{ props.log.error }}
    </p>

    <p v-else-if="!props.log.exists" class="hinweis-block">
      Für diese Domain gibt es noch kein Protokoll. Es entsteht mit dem ersten
      Zugriff.
    </p>

    <p v-else-if="props.log.lines.length === 0" class="hinweis-block">
      Das Protokoll ist leer.
    </p>

    <template v-else>
      <p class="pfad fest">{{ props.log.path }}</p>

      <pre class="protokoll">{{ props.log.lines.join('\n') }}</pre>

      <div class="knopfreihe">
        <button v-if="props.lines < 500" type="button" class="knopf" @click="mehr">
          Mehr Zeilen ({{ props.lines }} → {{ Math.min(500, props.lines * 2) }})
        </button>
      </div>
    </template>
  </PanelLayout>
</template>

<style scoped>
.kopf { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: var(--gap); }
.wahl { display: flex; gap: 6px; }
.pfad { margin: 0 0 6px; font-size: var(--text-label); color: var(--text-faint); }
.fest { font-family: var(--font-mono); }

/*
 * Das Protokoll rollt in beide Richtungen und bricht keine Zeile um.
 *
 * Eine umgebrochene Zeile eines Zugriffsprotokolls ist unlesbar: Man erkennt
 * nicht mehr, wo ein Eintrag anfängt. Auf 390px rollt es waagerecht — dieselbe
 * Entscheidung wie bei den Tabellen unter `.rollt`.
 */
.protokoll {
  margin: 0; padding: 10px 12px; max-height: 60dvh; overflow: auto;
  font-family: var(--font-mono); font-size: var(--text-label); line-height: 1.55;
  color: var(--text); background: var(--surface);
  border: 1px solid var(--surface-border); border-radius: 8px;
  white-space: pre;
}
.hinweis-block { max-width: 544px; margin: 0; padding: 8px 11px; font-size: var(--text-table); color: var(--text-muted); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 6px; }
.fehler-block { max-width: 544px; margin: 0; padding: 8px 11px; font-size: var(--text-table); color: var(--warn); background: var(--warn-surface); border-radius: 6px; }
.knopfreihe { margin-top: var(--gap); }
</style>
