<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import Badge from '../../Components/Badge.vue'
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

/*
 * Drei Zustände und drei Ränge.
 *
 * Installiert und gestoppt ist kein Fehler: Ein PHP-FPM ohne Pool startet
 * nicht, und ohne Abonnement in dieser Version gibt es keinen. Deshalb
 * „neutral" und nicht „warn" — eine Warnung schickt jemanden auf die Suche
 * nach einem Problem, das keines ist.
 */
function rang(v: Version): 'ok' | 'neutral' {
  return v.installed && v.active === true ? 'ok' : 'neutral'
}

function zustand(v: Version): string {
  if (!v.installed) return 'nicht installiert'
  if (v.active === true) return 'FPM läuft'
  if (v.active === false) return 'FPM steht'

  return 'installiert'
}
</script>

<template>
  <Head title="PHP-Versionen" />

  <PanelLayout title="PHP-Versionen" subline="Was auf diesem Server für Kundenwebsites bereitsteht">
    <p v-if="props.error" class="notice warn">
      <span>
        Der Agent antwortet nicht: {{ props.error }}
        <template v-if="props.checked_at">
          Angezeigt wird der Stand vom {{ props.checked_at }}.
        </template>
      </span>
    </p>

    <p class="notice neutral">
      <span>
        Kunden wählen aus diesen Versionen, soweit ihr Plan sie freigibt. Eine
        Version, die ein Plan hergibt und die hier fehlt, erscheint im
        Domainformular abgeblendet — der Kunde sieht damit, dass die Lücke am
        Server liegt und nicht an seinem Vertrag.
      </span>
    </p>

    <div class="scrolls">
      <table class="stacks">
        <thead>
          <tr>
            <th>Version</th>
            <th>Ausgabe</th>
            <th>Zustand</th>
            <th class="right">Pools</th>
            <th class="right">Domains</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="v in props.versions" :key="v.version">
            <td data-column="Version" class="ident name">{{ v.version }}</td>
            <td data-column="Ausgabe" class="ident quiet">{{ v.release ?? '—' }}</td>

            <td data-column="Zustand">
              <Badge :kind="rang(v)">{{ zustand(v) }}</Badge>
            </td>

            <td data-column="Pools" class="right">{{ v.pools ?? '—' }}</td>
            <td data-column="Domains" class="right">{{ props.usage[v.version] ?? 0 }}</td>

            <td data-column="" class="right">
              <!--
                Rot am Entfernen und nicht am Installieren. Zuerst stand es
                andersherum, und im Browser sah man sofort, dass es falsch ist:
                Eine Version dazuzunehmen kostet Platz, eine wegzunehmen kann
                Websites stilllegen.
              -->
              <button
                v-if="!v.installed"
                type="button"
                class="button small"
                :disabled="läuft !== null"
                @click="installieren(v)"
              >
                {{ läuft === v.version ? 'wird angelegt …' : 'Installieren' }}
              </button>
              <button
                v-else
                type="button"
                class="button small danger"
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

    <p v-if="!props.live && !props.error" class="section-note">
      Stand vom {{ props.checked_at ?? '—' }}
    </p>
  </PanelLayout>
</template>
