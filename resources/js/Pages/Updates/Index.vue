<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'
import Badge from '../../Components/Badge.vue'
import { counted } from '../../Composables/useCounted'

/*
 * Der Paketstand dieses Servers und die Quellen, aus denen er kommt.
 *
 * **Warum beides auf einer Seite steht:** Die zweite Liste erklärt die erste.
 * „0 Aktualisierungen" hat zwei sehr verschiedene Gründe — der Server ist
 * aktuell, oder apt kommt an seine Quellen nicht heran —, und die Zahl allein
 * sieht in beiden Fällen gleich aus.
 *
 *   Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu tun".
 *
 * **Diese Seite liest nur.** Der Knopf, der aktualisiert, kommt in Schritt 6;
 * er braucht `systemd-run`, damit ein Neustart des Panels den Lauf nicht
 * mitnimmt.
 */

interface Package {
  name: string
  old: string | null
  new: string
  origins: string[]
  architecture: string | null
  security: boolean
}

interface Entry {
  stanza: number
  enabled: boolean
  targets: number
  types: string
  uris: string
  suites: string
  components: string
  key: { kind: string; path: string | null }
}

const props = defineProps<{
  packages: {
    upgradable: Package[]
    removals: string[]
    held: string[]
    security: number
    fresh: number
    reboot: { required: boolean | null; packages: string[] }
    leftovers: string[]
  } | null

  sources: {
    targets: { file: string | null; stanza: number | null; fields: Record<string, string> }[]
    files: { path: string; format: string; entries: Entry[] }[]
  } | null

  errors: Record<string, string>
  page_size: number
}>()

/*
 * **Die Kacheln stehen auch dann da, wenn sie null sagen.** Eine Reihe, deren
 * Kacheln je nach Bestand erscheinen und verschwinden, ist zweimal dieselbe
 * Reihe: Wer sie kennt, muss sie neu lesen. Dieselbe Entscheidung wie bei der
 * Spalte „System" in der Datenbankliste.
 */
const kacheln = computed(() => {
  const p = props.packages

  if (p === null) return []

  return [
    { key: 'upgradable', label: 'Aktualisierbar', value: String(p.upgradable.length) },
    { key: 'security', label: 'davon Sicherheit', value: String(p.security) },
    { key: 'held', label: 'Zurückgehalten', value: String(p.held.length) },
    { key: 'removals', label: 'Würde entfernt', value: String(p.removals.length) },
  ]
})

/*
 * **Geblättert wird, und das ist ein Befund aus der Bilderrunde.** Die erste
 * Fassung zeigte alle 145 Zeilen; bei 390 px war die Seite **29 412 px** hoch —
 * fünfunddreissig Telefonschirme. Die Messung war dabei grün: `dokument=0`,
 * Gegenprobe 200/200, `schiebt=0`.
 *
 *   Eine Messung, die nur waagerecht misst, sagt über die Höhe nichts.
 *
 * Die Seitengrösse kommt aus `Page::SIZE` und nicht von hier — dieselbe Zahl,
 * mit der jede andere Liste dieses Panels blättert.
 */
const seite = ref(1)

const seiten = computed(() =>
  Math.max(1, Math.ceil((props.packages?.upgradable.length ?? 0) / props.page_size)),
)

const sichtbar = computed(() => {
  const alle = props.packages?.upgradable ?? []
  const von = (seite.value - 1) * props.page_size

  return alle.slice(von, von + props.page_size)
})

/*
 * **Die Beschriftung nennt den Ausschnitt und nicht die Seitenzahl.** „1–50 von
 * 145" beantwortet, was jemand wissen will; „Seite 1 von 3" lässt ihn rechnen.
 */
const stand = computed(() => {
  const gesamt = props.packages?.upgradable.length ?? 0

  if (gesamt === 0) return ''

  const von = (seite.value - 1) * props.page_size + 1

  return `${von}–${Math.min(von + props.page_size - 1, gesamt)} von ${gesamt}`
})

// Ändert sich der Bestand unter der Blätterung, darf die Seitenzahl nicht
// stehenbleiben — sonst zeigt sie auf einen Ausschnitt, den es nicht gibt.
watch(seiten, (jetzt) => {
  if (seite.value > jetzt) seite.value = jetzt
})

/**
 * Was der dritte Zustand einer Quelle bedeutet.
 *
 * **Drei und nicht zwei.** „Kein Ziel" heisst nicht „abgeschaltet": Eine
 * Quelle, für die apt keinen Index geholt hat — weil sie neu ist oder weil das
 * Holen scheitert —, fehlt bei den Zielen genauso wie eine abgeschaltete. Von
 * den Zielen allein sind die beiden nicht zu unterscheiden, und deshalb liest
 * der Agent `Enabled:` aus der Datei.
 *
 *   Zwei Zustände, die von einer Seite gleich aussehen, brauchen die zweite
 *   Seite — nicht eine Vermutung.
 */
function zustand(eintrag: Entry): { kind: 'ok' | 'warn' | 'neutral'; text: string } {
  if (!eintrag.enabled) return { kind: 'neutral', text: 'abgeschaltet' }
  if (eintrag.targets === 0) return { kind: 'warn', text: 'kein Index' }

  return { kind: 'ok', text: counted(eintrag.targets, 'Ziel', 'Ziele') }
}

/** Woher der Schlüssel kommt — als Satz und nicht als Kennung. */
function schluessel(eintrag: Entry): string {
  if (eintrag.key.kind === 'path') return eintrag.key.path ?? ''
  if (eintrag.key.kind === 'embedded') return 'in der Datei'

  return 'keiner'
}

/*
 * **Drei Zustände und nicht zwei.** `null` heisst „nicht feststellbar" — auf
 * diesem Server ist `update-notifier-common` nicht installiert, und dann fehlt
 * `/run/reboot-required`, weil niemand sie schreibt. Das ist etwas anderes als
 * „kein Neustart nötig", und wer beides gleich anzeigt, beruhigt ohne Grund.
 */
const neustart = computed(() => {
  const r = props.packages?.reboot

  if (r === undefined) return null
  if (r.required === true) return { kind: 'warn' as const, text: 'Ein Neustart steht aus' }
  if (r.required === false) return { kind: 'ok' as const, text: 'Kein Neustart nötig' }

  return {
    kind: 'neutral' as const,
    text: 'Nicht feststellbar — das Paket update-notifier-common ist nicht installiert',
  }
})
</script>

<template>
  <Head title="Updates" />

  <PanelLayout title="Updates" subline="Der Paketstand dieses Servers und seine Quellen">
    <!--
      Kein Zusatz für „steht unter dem Seitenkopf": Das erledigt
      `.page-head + .tiles` in app.css, und eine eigene Klasse dafür wäre
      deren zweite Fassung.
    -->
    <div class="tiles">
      <div v-for="k in kacheln" :key="k.key" class="tile">
        <span class="tile-label">{{ k.label }}</span>
        <span class="tile-value">{{ k.value }}</span>
      </div>
    </div>

    <div class="sections">
      <Section title="Pakete" full>
        <p v-if="props.errors.packages" class="notice critical">
          Der Paketstand liess sich nicht ermitteln: {{ props.errors.packages }}
        </p>

        <template v-else-if="props.packages">
          <!--
            **Der ganze Satz steht in einem `<span>`.** `.notice` ist eine
            Flexbox; ein Textknoten neben einem Element ergäbe zwei Flexkinder,
            und bei 390 px stünde dann ein Wort von fünf Pixeln Breite neben
            dem Rest des Satzes. Der Überlauf dabei ist 0 — die Messung der
            Bilderrunde findet das nicht, `NoticeChildrenTest` schon.
          -->
          <p v-if="neustart" class="notice" :class="neustart.kind">
            <span>
              {{ neustart.text }}
              <template v-if="props.packages.reboot.packages.length > 0">
                — {{ props.packages.reboot.packages.join(', ') }}
              </template>
            </span>
          </p>

          <!--
            **Zurückgehalten und „würde entfernt" stehen als Satz da und nicht
            nur als Zahl in der Kachel.** Beides sind Namen, die der Betreiber
            braucht, um zu entscheiden; eine Zahl allein sagt ihm, dass etwas
            ist, und nicht was.
          -->
          <p v-if="props.packages.held.length > 0" class="notice warn">
            <span>
              {{ counted(props.packages.held.length, 'Paket wird', 'Pakete werden') }} zurückgehalten:
              <span class="ident">{{ props.packages.held.join(', ') }}</span>
            </span>
          </p>

          <p v-if="props.packages.removals.length > 0" class="notice warn">
            <span>
              Ein vollständiges Update würde
              {{ counted(props.packages.removals.length, 'ein Paket', 'Pakete') }} entfernen:
              <span class="ident">{{ props.packages.removals.join(', ') }}</span>
            </span>
          </p>

          <!--
            **Auch die Einzahl entscheidet `counted()` und nicht diese Vorlage.**
            Ein `? 'wartet' : 'warten'` hier wäre die zweite Fassung derselben
            Regel, und die zweite ist die, die beim Nachziehen vergessen wird.
          -->
          <p v-if="props.packages.leftovers.length > 0" class="notice warn">
            <span>
              {{ counted(props.packages.leftovers.length, 'Eine Konfigurationsdatei unter /etc wartet', 'Konfigurationsdateien unter /etc warten') }}
              auf eine Entscheidung:
              <span class="ident">{{ props.packages.leftovers.join(', ') }}</span>
            </span>
          </p>

          <p v-if="props.packages.upgradable.length === 0" class="empty">
            Es steht keine Aktualisierung an.
          </p>

          <div v-else class="scrolls">
            <table class="stacks">
              <thead>
                <tr>
                  <th>Paket</th>
                  <th>Installiert</th>
                  <th>Neu</th>
                  <th>Herkunft</th>
                </tr>
              </thead>

              <tbody>
                <tr v-for="paket in sichtbar" :key="paket.name">
                  <td data-column="Paket" class="ident">
                    {{ paket.name }}
                    <Badge v-if="paket.security" kind="warn">Sicherheit</Badge>
                  </td>

                  <!--
                    **Eine Neuinstallation hat keine alte Fassung**, und das
                    steht als Wort da. Ein leeres Feld läse sich wie ein
                    fehlender Wert; hier ist die Abwesenheit die Auskunft.
                  -->
                  <td data-column="Installiert" class="ident">
                    <span v-if="paket.old !== null">{{ paket.old }}</span>
                    <span v-else class="quiet">kommt neu dazu</span>
                  </td>

                  <td data-column="Neu" class="ident">{{ paket.new }}</td>

                  <!--
                    **Alle Herkünfte und nicht die erste.** apt nennt die
                    Aktualisierungssuite zuerst und die Sicherheitssuite danach;
                    wer nur die erste zeigt, verschweigt genau die, deretwegen
                    die Marke danebensteht.
                  -->
                  <td data-column="Herkunft" class="ident">
                    <span v-if="paket.origins.length > 0">{{ paket.origins.join(', ') }}</span>
                    <span v-else class="quiet">keine</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div v-if="seiten > 1" class="pager">
            <div>
              <button type="button" class="button" :disabled="seite === 1" @click="seite--">
                Zurück
              </button>
            </div>

            <p class="pager-state">{{ stand }}</p>

            <div class="right">
              <button type="button" class="button" :disabled="seite === seiten" @click="seite++">
                Weiter
              </button>
            </div>
          </div>
        </template>
      </Section>

      <Section title="Paketquellen" full>
        <p v-if="props.errors.sources" class="notice critical">
          Die Paketquellen liessen sich nicht ermitteln: {{ props.errors.sources }}
        </p>

        <template v-else-if="props.sources">
          <p class="section-note wide">
            Was apt tatsächlich benutzt, steht als Zahl neben jedem Eintrag.
            Eine Quelle ohne Index ist nicht abgeschaltet — apt hat sie nur nicht
            geholt, weil sie neu ist oder das Holen scheitert.
          </p>

          <div class="scrolls">
            <table class="stacks">
              <thead>
                <!--
                  **„Zustand" steht vorn und nicht am Ende.** Er ist die
                  Antwort dieser Tabelle; Adresse, Suiten und Schlüssel sind
                  die Erläuterung dazu. In der ersten Fassung stand er als
                  letzte Spalte — bei 1440 px lag er ausserhalb des Bildes, und
                  die Tabelle rollte für genau die Auskunft, deretwegen es sie
                  gibt.

                    Eine Spalte, die man wegrollen muss, ist keine Antwort.
                -->
                <tr>
                  <th>Datei</th>
                  <th>Zustand</th>
                  <th>Adresse</th>
                  <th>Suiten</th>
                  <th>Schlüssel</th>
                </tr>
              </thead>

              <tbody>
                <template v-for="datei in props.sources.files" :key="datei.path">
                  <tr v-for="eintrag in datei.entries" :key="`${datei.path}:${eintrag.stanza}`">
                    <!--
                      **Die Zahl hinter dem Doppelpunkt ist eine Stanza und
                      keine Zeile.** apt schreibt sie so, und dieselbe
                      Schreibweise bedeutet überall sonst eine Zeilennummer —
                      deshalb steht sie hier als „Eintrag n" und nicht als
                      `datei:n`.
                    -->
                    <td data-column="Datei" class="ident">
                      {{ datei.path }}
                      <span v-if="datei.entries.length > 1" class="quiet">· Eintrag {{ eintrag.stanza }}</span>
                    </td>

                    <td data-column="Zustand">
                      <Badge :kind="zustand(eintrag).kind">{{ zustand(eintrag).text }}</Badge>
                    </td>

                    <td data-column="Adresse" class="ident">{{ eintrag.uris }}</td>
                    <td data-column="Suiten" class="ident">{{ eintrag.suites }}</td>
                    <td data-column="Schlüssel" class="ident">{{ schluessel(eintrag) }}</td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>

          <!--
            Eine Datei ohne Einträge ist keine leere Liste, sondern eine
            Auskunft: `/etc/apt/sources.list` ist auf einem heutigen Ubuntu nur
            noch ein Hinweistext.
          -->
          <p
            v-if="props.sources.files.every((d) => d.entries.length === 0)"
            class="empty"
          >
            In keiner dieser Dateien steht ein Eintrag.
          </p>
        </template>
      </Section>
    </div>
  </PanelLayout>
</template>
