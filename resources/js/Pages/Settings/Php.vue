<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import Badge from '../../Components/Badge.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import { useConfirmation } from '../../Composables/useConfirmation'

const { ask } = useConfirmation()

interface Version {
  version: string
  installed: boolean
  unit: string
  active: boolean | null
  pools: number | null
  release: string | null

  // `null` heisst „unbekannt" (kein Agent), `[]` heisst „nichts fehlt".
  missing: string[] | null

  // Die andere Hälfte derselben Antwort: was da ist.
  present: string[] | null
}

const props = defineProps<{
  versions: Version[]
  live: boolean
  error: string | null
  checked_at: string | null
  usage: Record<string, number>
}>()

const läuft = ref<string | null>(null)

function starte(task: string, version: string, frage: string, verb: string): void {
  if (läuft.value) return

  ask(frage, verb, () => {
    läuft.value = version
    router.post('/operations', { task, argument: version }, {
      onFinish: () => {
        läuft.value = null
      },
    })
  })
}

function installieren(v: Version): void {
  starte(
    'php.version.install',
    v.version,
    `PHP ${v.version} installieren?\n\nDie Pakete kommen aus deb.sury.org. Der geteilte Standard-Pool der Distribution wird danach abgeschaltet.`,
    'Installieren',
  )
}

/*
 * Nachinstallieren, was einer vorhandenen Version fehlt.
 *
 * **Dieselbe Aufgabe wie beim Installieren, und das ist Absicht.**
 * `php.version.install` läuft seit P5b auf den gewünschten Paketsatz zu, statt
 * beim vorhandenen Handler abzubrechen — ein zweiter Knopf mit einer zweiten
 * Operation wäre dieselbe Regel ein zweites Mal.
 *
 * **Der Neustart steht in der Rückfrage.** Ein laufender FPM lädt eine neu
 * installierte Erweiterung nicht von selbst; er wird dabei neu gestartet, und
 * das kostet die Anfragen, die gerade unterwegs sind. Wer das nicht vorher
 * liest, erfährt es aus dem Fehlerprotokoll seiner Kunden.
 */
function ergaenzen(v: Version): void {
  const fehlt = (v.missing ?? []).join(', ')

  starte(
    'php.version.install',
    v.version,
    `PHP ${v.version} ergänzen?\n\nEs fehlt: ${fehlt}\n\nLäuft der Handler dieser Version, wird er dabei neu gestartet — sonst lädt er die Erweiterung nicht.`,
    'Ergänzen',
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
    'Entfernen',
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

            <!--
              **`multiline`, sobald mehr als die Marke darin steht.** Die
              Klasse gibt es seit dem Optik-Rework, und ihr Kommentar in
              app.css beschreibt genau diesen Fehler: *Beschriftung und Inhalt
              nebeneinander lassen den Rest an den rechten Rand rutschen und
              dort umbrechen; sie gehören untereinander.* Ohne sie standen bei
              390px „fehlt" und „vorhanden" nebeneinander in je einer schmalen
              Spalte, und `bcmath` brach als `bcma` / `th` — eine Kennung
              mitten im Wort.

              Bedingt und nicht immer: Eine Zelle mit nur einer Marke ist keine
              mehrzeilige.
            -->
            <td data-column="Zustand" :class="{ multiline: !!(v.missing?.length || v.present?.length) }">
              <Badge :kind="rang(v)">{{ zustand(v) }}</Badge>

              <!--
                **Was fehlt, steht neben dem Zustand und nicht nur am Knopf.**
                Ein Knopf „Ergänzen" ohne die Angabe, was ergänzt wird, ist
                eine Aufforderung ohne Auskunft — und die Frage, die jemand
                hier hat, lautet „warum ist die Version nicht vollständig".
              -->
              <p v-if="v.installed && v.missing && v.missing.length > 0" class="quiet">
                fehlt: <span class="ident">{{ v.missing.join(', ') }}</span>
              </p>

              <!--
                **Und was da ist, steht daneben.** „Fehlt: pgsql" verschwindet,
                sobald es getan ist — und danach sagte diese Spalte nichts mehr
                darüber, was die Version kann. Eine Zustandsspalte, die nur den
                Mangel kennt, ist bei jedem gesunden Zustand leer; der
                Betreiber hat es am 9. August 2026 auf dem Server verlangt.
              -->
              <p v-if="v.installed && v.present && v.present.length > 0" class="quiet">
                vorhanden: <span class="ident">{{ v.present.join(', ') }}</span>
              </p>
            </td>

            <td data-column="Pools" class="right">{{ v.pools ?? '—' }}</td>
            <td data-column="Domains" class="right">{{ props.usage[v.version] ?? 0 }}</td>

            <td data-column="" class="right">
              <!--
                Rot am Entfernen und nicht am Installieren. Zuerst stand es
                andersherum, und im Browser sah man sofort, dass es falsch ist:
                Eine Version dazuzunehmen kostet Platz, eine wegzunehmen kann
                Websites stilllegen.

                **Und „Ergänzen" steht vor „Entfernen".** Eine Version, der
                etwas fehlt, ist installiert — der Knopf, der sie vollständig
                macht, gehört an dieselbe Stelle wie der, der sie geholt hätte.

                **Die Reihe kam mit dem zweiten Knopf.** Bis P5b trug jede
                Zeile genau einen, und der Abstand war nie eine Frage; mit
                „Ergänzen" neben „Entfernen" klebten sie aneinander.
                `.button-row` ist die Antwort, die dieses Repo dafür schon hat
                — dieselbe wie in `Customers/Index.vue`.
              -->
              <div class="button-row">
                <button
                  v-if="v.installed && v.missing && v.missing.length > 0"
                  type="button"
                  class="button small"
                  :disabled="läuft !== null"
                  @click="ergaenzen(v)"
                >
                  {{ läuft === v.version ? 'wird angelegt …' : 'Ergänzen' }}
                </button>
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
              </div>
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
