<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import Balken from '../Components/Balken.vue'
import Bereich from '../Components/Bereich.vue'
import Marke from '../Components/Marke.vue'
import Tile, { type Series } from '../Components/Tile.vue'
import PanelLayout from '../Layouts/PanelLayout.vue'

interface TileData {
  key: string
  label: string
  value: string
  unit: string
  subline: string
  series: Series
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
    uptime_s?: number
    error?: string
  }
  hosting: {
    customers: { total: number; suspended: number }
    subscriptions: { total: number; active: number; suspended: number; provisioning: number }
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
function dienstRang(service: Service): 'ok' | 'kritisch' | 'neutral' {
  if (!service.present) return 'neutral'

  return service.active_state === 'active' ? 'ok' : 'kritisch'
}

const headline = props.server.reachable
  ? [props.server.hostname, props.server.distribution, uptimeText(props.server.uptime_s ?? 0)]
      .filter(Boolean)
      .join(' · ')
  : 'Agent nicht erreichbar'
</script>

<template>
  <PanelLayout title="Übersicht" :subline="headline">
    <p v-if="!server.reachable" class="meldung kritisch">
      <span>
        <b>Der Agent antwortet nicht.</b>
        {{ server.error }}
        Zustand nachsehen mit
        <span class="kennung">systemctl status srvpanel-agentd</span>.
      </span>
    </p>

    <div class="kacheln">
      <Tile
        v-for="tile in tiles"
        :key="tile.key"
        :label="tile.label"
        :value="tile.value"
        :unit="tile.unit"
        :subline="tile.subline"
        :series="tile.series"
      />
    </div>

    <div class="bereiche nach-kacheln">
      <!--
        Der Bestand steht über den Diensten und unter den Kacheln.

        Über den Diensten, weil ein Betreiber sein Panel nicht öffnet, um den
        Zustand von nginx zu erfahren, sondern um zu sehen, ob mit dem, was er
        hostet, etwas nicht stimmt. Unter den Kacheln, weil die Kacheln die
        Frage beantworten, ob der Server überhaupt gesund ist — und wenn er es
        nicht ist, ist alles darunter zweitrangig.
      -->
      <Bereich titel="Bestand">
        <!--
          Die Zahlen sind Verweise und keine Kacheln: Wer die Zahl der
          gesperrten Abonnements liest, will als Nächstes wissen, welche das
          sind — und dann soll er sie anklicken können und nicht erst in die
          Navigation greifen.
        -->
        <table class="paare">
          <tbody>
            <tr>
              <td class="stumm"><Link href="/customers" class="verweis">Kunden</Link></td>
              <td class="rechts name">{{ props.hosting.customers.total }}</td>
              <td class="rechts">
                <!--
                  Gesperrt und „wird angelegt" stehen nur da, wenn es sie gibt.
                  Eine Null neben einer Beschriftung ist eine Angabe, die man
                  jedes Mal liest und nie braucht.
                -->
                <Marke v-if="props.hosting.customers.suspended > 0" art="warn">
                  {{ props.hosting.customers.suspended }} gesperrt
                </Marke>
              </td>
            </tr>
            <tr>
              <td class="stumm"><Link href="/subscriptions" class="verweis">Abonnements</Link></td>
              <td class="rechts name">{{ props.hosting.subscriptions.total }}</td>
              <td class="rechts">
                <Marke art="ok">{{ props.hosting.subscriptions.active }} aktiv</Marke>
              </td>
            </tr>
            <tr v-if="props.hosting.subscriptions.suspended > 0">
              <td class="stumm">davon gesperrt</td>
              <td class="rechts name">{{ props.hosting.subscriptions.suspended }}</td>
              <td class="rechts"><Marke art="warn">gesperrt</Marke></td>
            </tr>
            <tr v-if="props.hosting.subscriptions.provisioning > 0">
              <td class="stumm">werden angelegt</td>
              <td class="rechts name">{{ props.hosting.subscriptions.provisioning }}</td>
              <td class="rechts"><Marke art="warn" laeuft>läuft</Marke></td>
            </tr>
          </tbody>
        </table>
      </Bereich>

      <!--
        Nicht die grössten Abonnements, sondern die vollsten: Eines mit 40 GB
        von 200 ist unauffällig, eines mit 4,8 GB von 5 ist der Anruf von
        morgen.
      -->
      <Bereich
        v-if="props.hosting.storage.length > 0"
        titel="Am nächsten an der Speichergrenze"
        weit
      >
        <div class="rollt">
          <table class="stapelt">
            <thead>
              <tr><th>Abonnement</th><th class="rechts">Belegt</th><th>Anteil</th><th>Gemessen</th></tr>
            </thead>
            <tbody>
              <tr v-for="row in props.hosting.storage" :key="row.id">
                <td data-spalte="Abonnement" class="name">
                  <Link :href="`/subscriptions/${row.id}`" class="verweis">{{ row.name }}</Link>
                </td>
                <td data-spalte="Belegt" class="rechts">{{ row.used_mb.toLocaleString('de-DE') }} MB</td>
                <td data-spalte="Anteil">
                  <Balken
                    :prozent="row.percent"
                    :eng="row.percent >= 90 && row.percent <= 100"
                    :ueber="row.percent > 100"
                  />
                </td>
                <td data-spalte="Gemessen" class="stumm">{{ row.measured_at ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Bereich>

      <Bereich titel="Dienste" weit>
        <div class="rollt">
          <table class="stapelt">
            <thead>
              <tr><th>Unit</th><th>Zustand</th><th>Beschreibung</th></tr>
            </thead>
            <tbody>
              <tr v-for="service in services" :key="service.unit">
                <td data-spalte="Unit" class="kennung name">{{ service.unit }}</td>
                <td data-spalte="Zustand">
                  <Marke :art="dienstRang(service)">
                    {{ service.present ? service.active_state : 'nicht installiert' }}
                  </Marke>
                </td>
                <td data-spalte="Beschreibung" class="stumm">{{ service.description }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Bereich>

      <Bereich titel="Dateisysteme" voll>
        <div class="rollt">
          <table class="stapelt">
            <thead>
              <tr>
                <th>Einhängepunkt</th>
                <th>Gerät</th>
                <th>Art</th>
                <th class="rechts">Größe</th>
                <th class="rechts">Frei</th>
                <th>Belegt</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="filesystem in filesystems" :key="filesystem.mount">
                <td data-spalte="Einhängepunkt" class="kennung name">{{ filesystem.mount }}</td>
                <td data-spalte="Gerät" class="kennung stumm">{{ filesystem.device }}</td>
                <td data-spalte="Art" class="stumm">{{ filesystem.type }}</td>
                <td data-spalte="Größe" class="rechts">{{ filesystem.total }}</td>
                <td data-spalte="Frei" class="rechts">{{ filesystem.free }}</td>
                <td data-spalte="Belegt">
                  <!--
                    Der Balken statt nur der Zahl: „87 %" liest man, einen
                    vollen Balken sieht man. Die Schwelle, ab der er warnt,
                    kommt vom Server — sie ist eine Aussage über den Betrieb.
                  -->
                  <Balken :prozent="filesystem.percent" :eng="filesystem.tight" />
                </td>
              </tr>
              <tr v-if="filesystems.length === 0">
                <td colspan="6" class="stumm">Keine Angaben — der Agent antwortet nicht.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Bereich>

      <Bereich titel="Prozesse nach Speicher" voll>
        <div class="rollt">
          <table class="stapelt">
            <thead>
              <tr>
                <th class="rechts">PID</th>
                <th>Name</th>
                <th>Zustand</th>
                <th class="rechts">UID</th>
                <th class="rechts">Speicher</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="process in processes" :key="process.pid">
                <td data-spalte="PID" class="rechts kennung">{{ process.pid }}</td>
                <td data-spalte="Name" class="kennung name">{{ process.name }}</td>
                <td data-spalte="Zustand" class="stumm">{{ process.state }}</td>
                <td data-spalte="UID" class="rechts kennung">{{ process.user }}</td>
                <td data-spalte="Speicher" class="rechts">{{ process.rss }}</td>
              </tr>
              <tr v-if="processes.length === 0">
                <td colspan="5" class="stumm">Keine Angaben — der Agent antwortet nicht.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Bereich>
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
.nach-kacheln {
  margin-top: var(--block-gap);
}
</style>
