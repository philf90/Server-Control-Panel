<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import Bar from '../Components/Bar.vue'
import Section from '../Components/Section.vue'
import Badge from '../Components/Badge.vue'
import Tile, { type Second, type Series } from '../Components/Tile.vue'
import PanelLayout from '../Layouts/PanelLayout.vue'

interface TileData {
  key: string
  label: string
  value: string
  unit: string
  subline: string
  series: Series

  /* Nur das Netz hat sie: die zweite Richtung derselben Kennzahl. */
  second?: Second
}

interface Service {
  unit: string
  present: boolean
  active_state: string
  description: string
}

interface Filesystem {
  mount: string
  device: string
  type: string
  total: string
  free: string
  percent: number
  tight: boolean
}

interface Process {
  pid: number
  name: string
  rss: string
  state: string
  user: number
}

const props = defineProps<{
  server: {
    reachable: boolean
    hostname?: string
    distribution?: string
    kernel?: string

    /* Dreiwertig: `null` heisst „konnte nicht nachsehen", nicht „ist aktuell". */
    kernel_stale?: boolean | null
    uptime_s?: number
    error?: string
  }
  hosting: {
    customers: { total: number; suspended: number }
    subscriptions: { total: number; active: number; suspended: number; provisioning: number }
    domains: { total: number; active: number; suspended: number; provisioning: number }
    databases: { total: number; active: number; provisioning: number; removing: number }
    storage: { id: number; name: string; used_mb: number; percent: number; measured_at: string | null }[]
  }
  tiles: TileData[]
  services: Service[]
  filesystems: Filesystem[]
  processes: Process[]
}>()

function uptimeText(seconds: number): string {
  const days = Math.floor(seconds / 86400)
  const hours = Math.floor((seconds % 86400) / 3600)

  // Die Einzahl ist kein Schönheitsfehler: „seit 1 Stunden" auf der ersten
  // Seite nach der Anmeldung liest sich wie ein Panel, das seine eigenen
  // Zahlen nicht anschaut.
  if (days > 0) return days === 1 ? 'seit 1 Tag' : `seit ${days} Tagen`
  if (hours > 0) return hours === 1 ? 'seit 1 Stunde' : `seit ${hours} Stunden`

  const minutes = Math.floor(seconds / 60)

  return minutes === 1 ? 'seit 1 Minute' : `seit ${minutes} Minuten`
}

/*
 * Ein Dienst hat drei Ausgänge und nicht zwei.
 *
 * „Nicht installiert" ist kein Fehler: Der Agent nennt jeden Dienst, den
 * dieses Panel kennt, und nicht jeder gehört auf jeden Server. Rot dafür
 * schickt jemanden auf die Suche nach etwas, das nie da war.
 */
function dienstRang(service: Service): 'ok' | 'critical' | 'neutral' {
  if (!service.present) return 'neutral'

  return service.active_state === 'active' ? 'ok' : 'critical'
}

/*
 * Der Kernel steht in der Kopfzeile — und der Hinweis nur, wenn er einer ist.
 *
 * **Warum er überhaupt dasteht.** Bis zum 10. August 2026 reichte der Agent ihn
 * durch, der Steuerungscode gab ihn weiter, die Seite erklärte ihn als
 * Eigenschaft — und zeigte ihn nie. Eine Angabe, die den ganzen Weg geht und am
 * Ende in keiner Zeile landet, ist Arbeit ohne Wirkung.
 *
 * **Und `=== true` und nicht `kernel_stale`.** Die Angabe ist dreiwertig:
 * `null` heisst, dass `/boot` sich nicht lesen liess. Ein Hinweis, der auf
 * „nicht nachgesehen" hin erscheint, behauptet etwas über den Server, das
 * niemand gemessen hat.
 */
const kernelText = computed(() => {
  const kernel = props.server.kernel

  if (!kernel) return null

  return props.server.kernel_stale === true ? `${kernel} — ein neuerer ist installiert` : kernel
})

const headline = props.server.reachable
  ? [props.server.hostname, props.server.distribution, kernelText.value, uptimeText(props.server.uptime_s ?? 0)]
      .filter(Boolean)
      .join(' · ')
  : 'Agent nicht erreichbar'
</script>

<template>
  <PanelLayout title="Übersicht" :subline="headline">
    <p v-if="!server.reachable" class="notice critical">
      <span>
        <b>Der Agent antwortet nicht.</b>
        {{ server.error }}
        Zustand nachsehen mit
        <span class="ident">systemctl status srvpanel-agentd</span>.
      </span>
    </p>

    <div class="tiles">
      <Tile
        v-for="tile in tiles"
        :key="tile.key"
        :label="tile.label"
        :value="tile.value"
        :unit="tile.unit"
        :subline="tile.subline"
        :series="tile.series"
        :second="tile.second"
      />
    </div>

    <div class="sections after-tiles">
      <!--
        Der Bestand steht über den Diensten und unter den Kacheln.

        Über den Diensten, weil ein Betreiber sein Panel nicht öffnet, um den
        Zustand von nginx zu erfahren, sondern um zu sehen, ob mit dem, was er
        hostet, etwas nicht stimmt. Unter den Kacheln, weil die Kacheln die
        Frage beantworten, ob der Server überhaupt gesund ist — und wenn er es
        nicht ist, ist alles darunter zweitrangig.
      -->
      <Section title="Bestand">
        <!--
          Die Zahlen sind Verweise und keine Kacheln: Wer die Zahl der
          gesperrten Abonnements liest, will als Nächstes wissen, welche das
          sind — und dann soll er sie anklicken können und nicht erst in die
          Navigation greifen.
        -->
        <table class="pairs">
          <tbody>
            <tr>
              <td class="quiet"><Link href="/customers" class="link">Kunden</Link></td>
              <td class="right name">{{ props.hosting.customers.total }}</td>
              <td class="right">
                <!--
                  Gesperrt und „wird angelegt" stehen nur da, wenn es sie gibt.
                  Eine Null neben einer Beschriftung ist eine Angabe, die man
                  jedes Mal liest und nie braucht.
                -->
                <Badge v-if="props.hosting.customers.suspended > 0" kind="warn">
                  {{ props.hosting.customers.suspended }} gesperrt
                </Badge>
              </td>
            </tr>
            <tr>
              <td class="quiet"><Link href="/subscriptions" class="link">Abonnements</Link></td>
              <td class="right name">{{ props.hosting.subscriptions.total }}</td>
              <td class="right">
                <Badge kind="ok">{{ props.hosting.subscriptions.active }} aktiv</Badge>
              </td>
            </tr>
            <tr v-if="props.hosting.subscriptions.suspended > 0">
              <td class="quiet">davon gesperrt</td>
              <td class="right name">{{ props.hosting.subscriptions.suspended }}</td>
              <td class="right"><Badge kind="warn">gesperrt</Badge></td>
            </tr>
            <tr v-if="props.hosting.subscriptions.provisioning > 0">
              <td class="quiet">werden angelegt</td>
              <td class="right name">{{ props.hosting.subscriptions.provisioning }}</td>
              <td class="right"><Badge kind="warn" running>läuft</Badge></td>
            </tr>

            <!--
              Domains und Datenbanken stehen darunter und nicht daneben: Sie
              hängen an einem Abonnement, und die Reihenfolge ist die, in der
              etwas entsteht. Ein Betreiber liest hier von aussen nach innen —
              wer, was gebucht, worunter erreichbar, welche Daten dahinter.
            -->
            <tr>
              <td class="quiet"><Link href="/domains" class="link">Domains</Link></td>
              <td class="right name">{{ props.hosting.domains.total }}</td>
              <td class="right">
                <Badge v-if="props.hosting.domains.active > 0" kind="ok">
                  {{ props.hosting.domains.active }} aktiv
                </Badge>
              </td>
            </tr>
            <tr v-if="props.hosting.domains.suspended > 0">
              <td class="quiet">davon gesperrt</td>
              <td class="right name">{{ props.hosting.domains.suspended }}</td>
              <td class="right"><Badge kind="warn">gesperrt</Badge></td>
            </tr>
            <tr v-if="props.hosting.domains.provisioning > 0">
              <td class="quiet">werden angelegt</td>
              <td class="right name">{{ props.hosting.domains.provisioning }}</td>
              <td class="right"><Badge kind="warn" running>läuft</Badge></td>
            </tr>

            <tr>
              <td class="quiet"><Link href="/databases" class="link">Datenbanken</Link></td>
              <td class="right name">{{ props.hosting.databases.total }}</td>
              <td class="right">
                <Badge v-if="props.hosting.databases.active > 0" kind="ok">
                  {{ props.hosting.databases.active }} aktiv
                </Badge>
              </td>
            </tr>
            <tr v-if="props.hosting.databases.provisioning > 0">
              <td class="quiet">werden angelegt</td>
              <td class="right name">{{ props.hosting.databases.provisioning }}</td>
              <td class="right"><Badge kind="warn" running>läuft</Badge></td>
            </tr>
            <!--
              „Wird entfernt" gehört dazu und ist nicht die Umkehrung von „wird
              angelegt": Ein Rückbau, der hängenbleibt, lässt ein Schema mit
              Kundendaten auf dem Datenträger liegen (docs/36 §5). Genau das ist die
              Zeile, wegen der jemand auf diese Seite sieht.
            -->
            <tr v-if="props.hosting.databases.removing > 0">
              <td class="quiet">werden entfernt</td>
              <td class="right name">{{ props.hosting.databases.removing }}</td>
              <td class="right"><Badge kind="warn" running>läuft</Badge></td>
            </tr>
          </tbody>
        </table>
      </Section>

      <!--
        Nicht die grössten Abonnements, sondern die vollsten: Eines mit 40 GB
        von 200 ist unauffällig, eines mit 4,8 GB von 5 ist der Anruf von
        morgen.
      -->
      <Section
        v-if="props.hosting.storage.length > 0"
        title="Am nächsten an der Speichergrenze"
        weit
      >
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Abonnement</th><th class="right">Belegt</th><th>Anteil</th><th>Gemessen</th></tr>
            </thead>
            <tbody>
              <tr v-for="row in props.hosting.storage" :key="row.id">
                <td data-column="Abonnement" class="name">
                  <Link :href="`/subscriptions/${row.id}`" class="link">{{ row.name }}</Link>
                </td>
                <td data-column="Belegt" class="right">{{ row.used_mb.toLocaleString('de-DE') }} MB</td>
                <td data-column="Anteil">
                  <Bar
                    :percent="row.percent"
                    :tight="row.percent >= 90 && row.percent <= 100"
                    :over="row.percent > 100"
                  />
                </td>
                <td data-column="Gemessen" class="quiet">{{ row.measured_at ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>

      <Section title="Dienste" wide>
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Unit</th><th>Zustand</th><th>Beschreibung</th></tr>
            </thead>
            <tbody>
              <tr v-for="service in services" :key="service.unit">
                <td data-column="Unit" class="ident name">{{ service.unit }}</td>
                <td data-column="Zustand">
                  <Badge :kind="dienstRang(service)">
                    {{ service.present ? service.active_state : 'nicht installiert' }}
                  </Badge>
                </td>
                <td data-column="Beschreibung" class="quiet">{{ service.description }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>

      <Section title="Dateisysteme" full>
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th>Einhängepunkt</th>
                <th>Gerät</th>
                <th>Art</th>
                <th class="right">Größe</th>
                <th class="right">Frei</th>
                <th>Belegt</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="filesystem in filesystems" :key="filesystem.mount">
                <td data-column="Einhängepunkt" class="ident name">{{ filesystem.mount }}</td>
                <td data-column="Gerät" class="ident quiet">{{ filesystem.device }}</td>
                <td data-column="Art" class="quiet">{{ filesystem.type }}</td>
                <td data-column="Größe" class="right">{{ filesystem.total }}</td>
                <td data-column="Frei" class="right">{{ filesystem.free }}</td>
                <td data-column="Belegt">
                  <!--
                    Der Balken statt nur der Zahl: „87 %" liest man, einen
                    vollen Balken sieht man. Die Schwelle, ab der er warnt,
                    kommt vom Server — sie ist eine Aussage über den Betrieb.
                  -->
                  <Bar :percent="filesystem.percent" :tight="filesystem.tight" />
                </td>
              </tr>
              <tr v-if="filesystems.length === 0">
                <td colspan="6" class="quiet">Keine Angaben — der Agent antwortet nicht.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>

      <Section title="Prozesse nach Speicher" full>
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th class="right">PID</th>
                <th>Name</th>
                <th>Zustand</th>
                <th class="right">UID</th>
                <th class="right">Speicher</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="process in processes" :key="process.pid">
                <td data-column="PID" class="right ident">{{ process.pid }}</td>
                <td data-column="Name" class="ident name">{{ process.name }}</td>
                <td data-column="Zustand" class="quiet">{{ process.state }}</td>
                <td data-column="UID" class="right ident">{{ process.user }}</td>
                <td data-column="Speicher" class="right">{{ process.rss }}</td>
              </tr>
              <tr v-if="processes.length === 0">
                <td colspan="5" class="quiet">Keine Angaben — der Agent antwortet nicht.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>
    </div>
  </PanelLayout>
</template>

<style scoped>
/*
 * **Von 180 Zeilen sind vier übrig.**
 *
 * Hier standen die Form der Tabelle, der Spaltenkopf, die Zeilenhöhe, ein
 * Balken (`.bar`), eine Zustandsmarke (`.badge`) und ein Kärtchen für den
 * Bestand — jedes davon eine eigene Fassung von etwas, das app.css inzwischen
 * trägt. Drei davon nannten `--surface-border`, eine Marke, die es seit dem
 * Umbau nicht mehr gibt: Der Spaltenkopf hatte damit keine Linie und die
 * Balkenspur keinen Grund, und niemandem ist es aufgefallen.
 *
 * Was bleibt, ist der Abstand zwischen den Kacheln und dem ersten Bereich —
 * die einzige Stelle, an der auf dieser Seite zwei verschiedene Bausteine
 * aufeinandertreffen.
 */
.after-tiles {
  margin-top: var(--block-gap);
}
</style>
