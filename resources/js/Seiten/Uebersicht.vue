<script setup lang="ts">
import Kachel, { type Verlauf } from '../Komponenten/Kachel.vue'
import Leitstand from '../Layouts/Leitstand.vue'

interface KachelDaten {
  schluessel: string
  label: string
  wert: string
  einheit: string
  unterzeile: string
  verlauf: Verlauf
}

interface Dienst {
  unit: string
  vorhanden: boolean
  aktiv: string
  beschreibung: string
}

const props = defineProps<{
  server: {
    erreichbar: boolean
    hostname?: string
    distribution?: string
    kernel?: string
    uptime_s?: number
    fehler?: string
  }
  kacheln: KachelDaten[]
  dienste: Dienst[]
}>()

function laufzeit(sekunden: number): string {
  const tage = Math.floor(sekunden / 86400)
  const stunden = Math.floor((sekunden % 86400) / 3600)
  if (tage > 0) return `seit ${tage} Tagen`
  if (stunden > 0) return `seit ${stunden} Stunden`
  return `seit ${Math.floor(sekunden / 60)} Minuten`
}

const kopfzeile = props.server.erreichbar
  ? [props.server.hostname, props.server.distribution, laufzeit(props.server.uptime_s ?? 0)]
      .filter(Boolean)
      .join(' · ')
  : 'Agent nicht erreichbar'
</script>

<template>
  <Leitstand titel="Übersicht" :unterzeile="kopfzeile">
    <div v-if="!server.erreichbar" class="stoerung">
      <b>Der Agent antwortet nicht.</b>
      <span>{{ server.fehler }}</span>
      <code>systemctl status cloudsrv-agentd</code>
    </div>

    <div class="kacheln">
      <Kachel
        v-for="kachel in kacheln"
        :key="kachel.schluessel"
        :label="kachel.label"
        :wert="kachel.wert"
        :einheit="kachel.einheit"
        :unterzeile="kachel.unterzeile"
        :verlauf="kachel.verlauf"
      />
    </div>

    <p class="abschnitt">Dienste</p>
    <table>
      <thead>
        <tr>
          <th>Unit</th>
          <th>Zustand</th>
          <th>Beschreibung</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="dienst in dienste" :key="dienst.unit">
          <td class="name">{{ dienst.unit }}</td>
          <td>
            <span :class="['marke', dienst.aktiv === 'active' ? 'ok' : dienst.vorhanden ? 'aus' : 'fehlt']">
              {{ dienst.vorhanden ? dienst.aktiv : 'nicht installiert' }}
            </span>
          </td>
          <td class="ruhig">{{ dienst.beschreibung }}</td>
        </tr>
      </tbody>
    </table>
  </Leitstand>
</template>

<style scoped>
.kacheln {
  display: grid;
  grid-template-columns: repeat(var(--kacheln), minmax(0, 1fr));
  gap: var(--abstand);
  margin-bottom: 18px;
}

@media (max-width: 720px) {
  .kacheln {
    grid-template-columns: 1fr;
  }
}

.stoerung {
  border: 1px solid var(--kritisch);
  background: var(--kritisch-flaeche);
  border-radius: 3px;
  padding: 11px 13px;
  margin-bottom: 14px;
  display: flex;
  flex-direction: column;
  gap: 3px;
  color: var(--text);
}

.stoerung b {
  color: var(--text-stark);
}

.stoerung code {
  font-family: var(--font-mono);
  font-size: 11.5px;
  color: var(--text-ruhig);
}

.abschnitt {
  font-size: 10.5px;
  letter-spacing: 0.11em;
  text-transform: uppercase;
  color: var(--text-ruhig);
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
  color: var(--text-schwach);
  border-bottom: 1px solid var(--bereich-rand);
  padding: 0 10px 7px 0;
}

td {
  border-bottom: 1px solid var(--linie);
  padding: 0 10px 0 0;
  height: var(--zeile);
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--text);
}

td.name {
  color: var(--text-stark);
}

td.ruhig {
  font-family: var(--font-sans);
  color: var(--text-ruhig);
}

.marke {
  display: inline-block;
  font-size: 10.5px;
  padding: 1px 7px;
  border-radius: 999px;
}

.marke.ok {
  background: var(--ok-flaeche);
  color: var(--ok);
}

.marke.aus {
  background: var(--kritisch-flaeche);
  color: var(--kritisch);
}

.marke.fehlt {
  background: var(--bereich-rand);
  color: var(--text-ruhig);
}
</style>
