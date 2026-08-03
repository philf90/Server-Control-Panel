<script setup lang="ts">
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

const headline = props.server.reachable
  ? [props.server.hostname, props.server.distribution, uptimeText(props.server.uptime_s ?? 0)]
      .filter(Boolean)
      .join(' · ')
  : 'Agent nicht erreichbar'
</script>

<template>
  <PanelLayout title="Übersicht" :subline="headline">
    <div v-if="!server.reachable" class="alert">
      <b>Der Agent antwortet nicht.</b>
      <span>{{ server.error }}</span>
      <code>systemctl status srvpanel-agentd</code>
    </div>

    <div class="tiles">
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

    <!--
      Jeder Bereich steht in einem eigenen <section> mit seiner Überschrift
      darin. Vorher lagen Überschrift und Tabelle als Geschwister nebeneinander,
      und der Abstand entstand nur aus dem Rand der Überschrift — nach oben
      null. Damit stand jede Überschrift dichter an der Tabelle darüber als an
      ihrer eigenen. Die Klammer macht die Zugehörigkeit auch dann richtig,
      wenn später jemand an den Rändern dreht.
    -->
    <section class="block">
      <h2 class="section">Dienste</h2>
      <table>
        <thead>
          <tr>
            <th>Unit</th>
            <th>Zustand</th>
            <th>Beschreibung</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="service in services" :key="service.unit">
            <td class="name">{{ service.unit }}</td>
            <td>
              <span :class="['badge', service.active_state === 'active' ? 'ok' : service.present ? 'stopped' : 'missing']">
                {{ service.present ? service.active_state : 'nicht installiert' }}
              </span>
            </td>
            <td class="quiet">{{ service.description }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="block">
      <h2 class="section">Dateisysteme</h2>
      <table>
        <thead>
          <tr>
            <th>Einhängepunkt</th>
            <th>Gerät</th>
            <th>Art</th>
            <th>Größe</th>
            <th>Frei</th>
            <th>Belegt</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="filesystem in filesystems" :key="filesystem.mount">
            <td class="name">{{ filesystem.mount }}</td>
            <td class="quiet">{{ filesystem.device }}</td>
            <td class="quiet">{{ filesystem.type }}</td>
            <td>{{ filesystem.total }}</td>
            <td>{{ filesystem.free }}</td>
            <td>
              <!--
                Der Balken statt nur der Zahl: „87 %" liest man, ein voller
                Balken sieht man. Die Schwelle, ab der er warnt, kommt vom
                Server — sie ist eine Aussage über den Betrieb.
              -->
              <div class="bar" :class="{ tight: filesystem.tight }">
                <span :style="{ width: `${Math.min(100, filesystem.percent)}%` }" />
              </div>
              <span class="percent">{{ filesystem.percent }} %</span>
            </td>
          </tr>
          <tr v-if="filesystems.length === 0">
            <td colspan="6" class="quiet">Keine Angaben — der Agent antwortet nicht.</td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="block">
      <h2 class="section">Prozesse nach Speicher</h2>
      <table>
        <thead>
          <tr>
            <th>PID</th>
            <th>Name</th>
            <th>Zustand</th>
            <th>UID</th>
            <th>Speicher</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="process in processes" :key="process.pid">
            <td>{{ process.pid }}</td>
            <td class="name">{{ process.name }}</td>
            <td class="quiet">{{ process.state }}</td>
            <td>{{ process.user }}</td>
            <td>{{ process.rss }}</td>
          </tr>
          <tr v-if="processes.length === 0">
            <td colspan="5" class="quiet">Keine Angaben — der Agent antwortet nicht.</td>
          </tr>
        </tbody>
      </table>
    </section>
  </PanelLayout>
</template>

<style scoped>
.tiles {
  display: grid;
  grid-template-columns: repeat(var(--tile-columns), minmax(0, 1fr));
  gap: var(--gap);
}

.block {
  margin-top: var(--block-gap);
}

@media (max-width: 720px) {
  .tiles {
    grid-template-columns: 1fr;
  }
}

.alert {
  border: 1px solid var(--critical);
  background: var(--critical-surface);
  border-radius: 3px;
  padding: 11px 13px;
  margin-bottom: 14px;
  display: flex;
  flex-direction: column;
  gap: 3px;
  color: var(--text);
}

.alert b {
  color: var(--text-strong);
}

.alert code {
  font-family: var(--font-mono);
  font-size: 11.5px;
  color: var(--text-muted);
}

/*
 * Die Abschnittsüberschrift ist eine Überschrift und keine kleine
 * Beschriftung.
 *
 * Hier stand `10.5px`, Versalien, Sperrung `.11em`, `--text-muted` — also
 * genau die Behandlung, die §7.2 für *kleine Beschriftungen* vorsieht und die
 * zwölf Pixel weiter unten der Spaltenkopf trägt. Zwei Zeilen mit derselben
 * Größe, derselben Schreibweise und fast derselben Farbe: Das Auge sieht
 * daneben keine Rangfolge, sondern eine Wiederholung.
 *
 * §7.2 sagt es selbst: „Kleine Beschriftungen in Versalien mit Sperrung
 * (.09em), **sonst keine Versalien**." Die Überschrift unterscheidet sich
 * jetzt auf drei Achsen gleichzeitig — Größe, Schreibweise, Farbe — und der
 * Spaltenkopf bleibt, was er ist.
 */
.section {
  font-size: var(--block-heading-size);
  font-weight: 600;
  letter-spacing: -0.01em;
  color: var(--text-strong);
  margin: 0 0 var(--block-heading-gap);
}

table {
  width: 100%;
  border-collapse: collapse;
}

th {
  font-size: 10.5px;
  letter-spacing: 0.09em;
  text-transform: uppercase;
  text-align: left;
  font-weight: 600;
  color: var(--text-faint);
  border-bottom: 1px solid var(--surface-border);
  padding: 0 10px 7px 0;
}

td {
  border-bottom: 1px solid var(--line);
  padding: 0 10px 0 0;
  height: var(--row-height);
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--text);
}

td.name {
  color: var(--text-strong);
}

td.quiet {
  font-family: var(--font-sans);
  color: var(--text-muted);
}

.bar {
  display: inline-block;
  vertical-align: middle;
  width: 78px;
  height: 6px;
  margin-right: 7px;
  border-radius: 999px;
  background: var(--surface-border);
  overflow: hidden;
}

.bar span {
  display: block;
  height: 100%;
  background: var(--accent);
}

.bar.tight span {
  background: var(--warn);
}

.percent {
  font-size: 11px;
  color: var(--text-muted);
}

.badge {
  display: inline-block;
  font-size: 10.5px;
  padding: 1px 7px;
  border-radius: 999px;
}

.badge.ok {
  background: var(--ok-surface);
  color: var(--ok);
}

.badge.stopped {
  background: var(--critical-surface);
  color: var(--critical);
}

.badge.missing {
  background: var(--surface-border);
  color: var(--text-muted);
}
</style>
