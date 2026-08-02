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
  : 'Agent nicht reachable'
</script>

<template>
  <PanelLayout title="Übersicht" :subline="headline">
    <div v-if="!server.reachable" class="alert">
      <b>Der Agent antwortet nicht.</b>
      <span>{{ server.error }}</span>
      <code>systemctl status cloudsrv-agentd</code>
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

    <p class="section">Dienste</p>
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
          <td class="muted">{{ service.description }}</td>
        </tr>
      </tbody>
    </table>
  </PanelLayout>
</template>

<style scoped>
.tiles {
  display: grid;
  grid-template-columns: repeat(var(--tile-columns), minmax(0, 1fr));
  gap: var(--gap);
  margin-bottom: 18px;
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

.section {
  font-size: 10.5px;
  letter-spacing: 0.11em;
  text-transform: uppercase;
  color: var(--text-muted);
  margin: 0 0 9px;
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

td.ruhig {
  font-family: var(--font-sans);
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

.badge.aus {
  background: var(--critical-surface);
  color: var(--critical);
}

.badge.fehlt {
  background: var(--surface-border);
  color: var(--text-muted);
}
</style>
