<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

interface Version {
  version: string
  installed: boolean
  unit: string
  active: boolean | null
  pools: number | null
  release: string | null
}

const props = defineProps<{
  versions: Version[]
  live: boolean
  error: string | null
  checked_at: string | null
  usage: Record<string, number>
}>()

const läuft = ref<string | null>(null)

function starte(task: string, version: string, frage: string): void {
  if (läuft.value) return
  if (!window.confirm(frage)) return

  läuft.value = version
  router.post('/operations', { task, argument: version }, {
    onFinish: () => {
      läuft.value = null
    },
  })
}

function installieren(v: Version): void {
  starte(
    'php.version.install',
    v.version,
    `PHP ${v.version} installieren?\n\nDie Pakete kommen aus deb.sury.org. Der geteilte Standard-Pool der Distribution wird danach abgeschaltet.`,
  )
}

function entfernen(v: Version): void {
  const benutzt = props.usage[v.version] ?? 0

  starte(
    'php.version.remove',
    v.version,
    benutzt > 0
      ? `PHP ${v.version} entfernen? ${benutzt} Domain(s) laufen darauf — der Agent wird das abweisen.`
      : `PHP ${v.version} entfernen?\n\nDie Konfiguration unter /etc/php/${v.version} bleibt liegen.`,
  )
}
</script>

<template>
  <Head title="PHP-Versionen" />

  <PanelLayout title="PHP-Versionen" subline="Was auf diesem Server für Kundenwebsites bereitsteht">
    <p v-if="props.error" class="fehler-block">
      Der Agent antwortet nicht: {{ props.error }}
      <template v-if="props.checked_at">
        Angezeigt wird der Stand vom {{ props.checked_at }}.
      </template>
    </p>

    <p class="hinweis-block">
      Kunden wählen aus diesen Versionen, soweit ihr Plan sie freigibt. Eine
      Version, die ein Plan hergibt und die hier fehlt, erscheint im
      Domainformular abgeblendet — der Kunde sieht damit, dass die Lücke am
      Server liegt und nicht an seinem Vertrag.
    </p>

    <div class="rollt">
      <table class="stapelt">
        <thead>
          <tr>
            <th>Version</th><th>Zustand</th><th>Pools</th><th>Domains</th><th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="v in props.versions" :key="v.version">
            <td data-spalte="Version">
              <b>{{ v.version }}</b>
              <span v-if="v.release" class="genau">{{ v.release }}</span>
            </td>

            <td data-spalte="Zustand" :data-installiert="v.installed">
              <template v-if="!v.installed">nicht installiert</template>
              <template v-else-if="v.active === true">installiert · FPM läuft</template>
              <!--
                Installiert und gestoppt ist kein Fehler: Ein PHP-FPM ohne Pool
                startet nicht, und ohne Abonnement in dieser Version gibt es
                keinen. Der Satz daneben sagt das, sonst sucht jemand nach
                einem Problem, das keines ist.
              -->
              <template v-else-if="v.active === false">installiert · FPM steht</template>
              <template v-else>installiert</template>
            </td>

            <td data-spalte="Pools">{{ v.pools ?? '—' }}</td>
            <td data-spalte="Domains">{{ props.usage[v.version] ?? 0 }}</td>

            <td data-spalte="">
              <!--
                Rot am Entfernen und nicht am Installieren. Zuerst stand es
                andersherum, und im Browser sah man sofort, dass es falsch ist:
                Eine Version dazuzunehmen kostet Platz, eine wegzunehmen kann
                Websites stilllegen.
              -->
              <button
                v-if="!v.installed"
                type="button"
                class="knopf"
                :disabled="läuft !== null"
                @click="installieren(v)"
              >
                {{ läuft === v.version ? 'wird angelegt …' : 'Installieren' }}
              </button>
              <button
                v-else
                type="button"
                class="knopf gefahr"
                :disabled="läuft !== null"
                @click="entfernen(v)"
              >
                {{ läuft === v.version ? 'wird angelegt …' : 'Entfernen' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="!props.live && !props.error" class="gemessen">
      Stand vom {{ props.checked_at ?? '—' }}
    </p>
  </PanelLayout>
</template>

<style scoped>
.hinweis-block { max-width: 640px; margin: 0 0 var(--gap); padding: 8px 11px; font-size: var(--text-table); color: var(--text-muted); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 6px; line-height: 1.5; }
.fehler-block { max-width: 640px; margin: 0 0 var(--gap); padding: 8px 11px; font-size: var(--text-table); color: var(--warn); background: var(--warn-surface); border-radius: 6px; line-height: 1.5; }
table { width: 100%; border-collapse: collapse; font-size: var(--text-table); }
th { text-align: left; color: var(--text-muted); font-weight: 600; }
th, td { padding: 6px 8px; border-bottom: 1px solid var(--line); }
td b { font-family: var(--font-mono); font-size: var(--text-table); color: var(--text-strong); }
.genau { display: block; font-family: var(--font-mono); font-size: var(--text-label); color: var(--text-faint); }
td[data-installiert='false'] { color: var(--text-faint); }
.gemessen { margin: 6px 0 0; font-size: var(--text-label); color: var(--text-faint); }
</style>
