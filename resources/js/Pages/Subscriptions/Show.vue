<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

const props = defineProps<{
  subscription: {
    id: number
    name: string
    customer: string | null
    customer_id: number
    plan: string | null
    system_user: string | null
    root: string
    status: string
    status_label: string
    suspended_at: string | null
  }
  usage: {
    used_mb: number | null
    limit_mb: number | null
    percent: number | null
    measured_at: string | null
  }
  quotas: { key: string; label: string; value: string; differs: boolean }[]
  features: { label: string; granted: boolean }[]
  domains: {
    id: number
    name: string
    type_label: string
    status: string
    status_label: string
    php_version: string | null
    is_redirect: boolean
  }[]
  mayAddDomain: boolean
  operations: { id: number; task: string | null; status_label: string; created_at: string | null }[]
}>()

/*
 * Der Balken wird bei 100 abgeschnitten, die Zahl daneben nicht.
 *
 * Eine Quota lässt sich überschreiten — sie wird gesenkt, während Daten
 * liegen, oder ein Prozess schreibt mit root-Rechten daran vorbei. Ein Balken,
 * der über seinen Rahmen hinausläuft, ist ein Darstellungsfehler; „118 %"
 * daneben ist die Auskunft. Beides zusammen ist die Wahrheit.
 */
const balken = computed(() => Math.min(100, props.usage.percent ?? 0))

function suspend(): void {
  if (!window.confirm(`${props.subscription.name} sperren? Webseiten und Zugänge sind danach aus, die Daten bleiben.`)) return
  router.post(`/subscriptions/${props.subscription.id}/suspend`)
}

function resume(): void {
  router.post(`/subscriptions/${props.subscription.id}/resume`)
}

/*
 * Zwei Rückfragen und nicht eine.
 *
 * Der Rückbau löscht als root einen Verzeichnisbaum, und es gibt noch keine
 * Sicherungen. Ein einzelnes „Wirklich?" beantwortet man im Vorbeigehen; den
 * Namen abzutippen ist die kleinste Hürde, die eine bewusste Handlung
 * verlangt.
 */
function remove(): void {
  const eingabe = window.prompt(
    `Rückbau von ${props.subscription.name}: Verzeichnis, Systembenutzer und Quota werden entfernt. `
    + 'Es gibt keine Sicherung. Zum Bestätigen den Namen eintippen:',
  )

  if (eingabe !== props.subscription.name) return

  router.delete(`/subscriptions/${props.subscription.id}`)
}
</script>

<template>
  <Head :title="props.subscription.name" />

  <PanelLayout :title="props.subscription.name" :subline="props.subscription.status_label">
    <section class="block">
      <h2 class="section">Stammdaten</h2>
      <dl>
        <dt>Kunde</dt>
        <dd><Link :href="`/customers/${props.subscription.customer_id}`">{{ props.subscription.customer ?? '—' }}</Link></dd>
        <dt>Plan</dt>
        <dd>{{ props.subscription.plan ?? '—' }}</dd>
        <dt>Systembenutzer</dt>
        <dd class="fest">{{ props.subscription.system_user ?? '—' }}</dd>
        <dt>Verzeichnis</dt>
        <dd class="fest">{{ props.subscription.root }}</dd>
        <dt v-if="props.subscription.suspended_at">Gesperrt seit</dt>
        <dd v-if="props.subscription.suspended_at">{{ props.subscription.suspended_at }}</dd>
      </dl>

      <div class="knopfreihe">
        <Link class="knopf" :href="`/subscriptions/${props.subscription.id}/edit`">Bearbeiten</Link>
        <button v-if="props.subscription.status === 'active'" type="button" class="knopf" @click="suspend">Sperren</button>
        <button v-if="props.subscription.status === 'suspended'" type="button" class="knopf" @click="resume">Entsperren</button>
        <button
          v-if="props.subscription.status !== 'provisioning'"
          type="button"
          class="knopf gefahr"
          @click="remove"
        >
          Zurückbauen
        </button>
      </div>
    </section>

    <!--
      Der Speicher steht über den Kontingenten und nicht darin: Er ist das
      einzige Kontingent, zu dem es einen gemessenen Stand gibt, und die
      Tabelle darunter zeigt Vereinbartes. Beides in einer Zeile hiesse, zwei
      verschiedene Dinge gleich aussehen zu lassen.
    -->
    <section class="block">
      <h2 class="section">Speicher</h2>

      <p v-if="props.usage.used_mb === null" class="ungemessen">
        Noch nicht gemessen. Die Messung läuft im Viertelstundentakt
        (<code>srvpanel-usage.timer</code>) und braucht eine Dateisystem-Quota
        auf dem Mount von /var/www/vhosts.
      </p>

      <template v-else>
        <p class="verbrauch">
          <strong>{{ props.usage.used_mb.toLocaleString('de-DE') }} MB</strong>
          <span v-if="props.usage.limit_mb !== null">
            von {{ props.usage.limit_mb.toLocaleString('de-DE') }} MB
            <template v-if="props.usage.percent !== null">· {{ props.usage.percent }} %</template>
          </span>
          <span v-else>ohne Grenze</span>
        </p>

        <div v-if="props.usage.percent !== null" class="balken" :data-voll="props.usage.percent >= 90">
          <div class="fuellung" :style="{ width: `${balken}%` }" />
        </div>

        <p class="gemessen">Gemessen am {{ props.usage.measured_at ?? '—' }}</p>
      </template>
    </section>

    <!--
      Die Domains stehen über den Kontingenten: Sie sind das, wofür ein
      Abonnement da ist. Die Zahlen darunter sagen, wie viel davon noch geht.
    -->
    <section class="block">
      <h2 class="section">Domains</h2>

      <div class="rollt">
        <table class="stapelt">
          <thead>
            <tr><th>Domain</th><th>Sorte</th><th>PHP</th><th>Zustand</th></tr>
          </thead>
          <tbody>
            <tr v-for="d in props.domains" :key="d.id">
              <td data-spalte="Domain"><Link :href="`/domains/${d.id}`">{{ d.name }}</Link></td>
              <td data-spalte="Sorte">{{ d.type_label }}</td>
              <td data-spalte="PHP">
                <template v-if="d.is_redirect">leitet weiter</template>
                <template v-else>{{ d.php_version ?? '—' }}</template>
              </td>
              <td data-spalte="Zustand" :data-status="d.status">{{ d.status_label }}</td>
            </tr>
            <tr v-if="props.domains.length === 0">
              <td colspan="4">Noch keine Domain.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="props.mayAddDomain" class="knopfreihe">
        <Link class="knopf" :href="`/subscriptions/${props.subscription.id}/domains/create`">Domain anlegen</Link>
      </div>
    </section>

    <section class="block">
      <h2 class="section">Kontingente</h2>
      <div class="rollt">
        <table>
          <thead>
            <tr><th>Kontingent</th><th>Stand</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="q in props.quotas" :key="q.key">
              <td>{{ q.label }}</td>
              <td class="zahl">{{ q.value }}</td>
              <td><span v-if="q.differs" class="marke">abweichend vom Plan</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="block">
      <h2 class="section">Freigaben</h2>
      <ul class="freigaben">
        <li v-for="f in props.features" :key="f.label" :data-frei="f.granted">
          {{ f.granted ? '✓' : '✗' }} {{ f.label }}
        </li>
      </ul>
    </section>

    <section class="block">
      <h2 class="section">Vorgänge</h2>
      <table class="stapelt">
        <thead>
          <tr><th>Nummer</th><th>Aufgabe</th><th>Zustand</th><th>Angelegt</th></tr>
        </thead>
        <tbody>
          <tr v-for="o in props.operations" :key="o.id">
            <td data-spalte="Nummer"><Link :href="`/operations/${o.id}`">{{ o.id }}</Link></td>
            <td data-spalte="Aufgabe" class="fest">{{ o.task ?? '—' }}</td>
            <td data-spalte="Zustand">{{ o.status_label }}</td>
            <td data-spalte="Angelegt">{{ o.created_at ?? '—' }}</td>
          </tr>
          <tr v-if="props.operations.length === 0">
            <td colspan="4">Noch kein Vorgang für dieses Abonnement.</td>
          </tr>
        </tbody>
      </table>
    </section>
  </PanelLayout>
</template>

<style scoped>
.block { margin-top: var(--block-gap); }
.block:first-child { margin-top: 0; }
.section { font-size: var(--block-heading-size); font-weight: 600; letter-spacing: -0.01em; color: var(--text-strong); margin: 0 0 var(--block-heading-gap); }
dl { display: grid; grid-template-columns: auto 1fr; gap: 4px 14px; margin: 0; font-size: var(--text-table); }
dt { color: var(--text-muted); }
dd { margin: 0; color: var(--text); }
.fest { font-family: var(--font-mono); }
table { width: 100%; border-collapse: collapse; font-size: var(--text-table); }
th { text-align: left; color: var(--text-muted); font-weight: 600; }
th, td { padding: 6px 8px; border-bottom: 1px solid var(--line); }
.ungemessen { margin: 0; font-size: var(--text-table); color: var(--text-muted); line-height: 1.5; }
code { font-family: var(--font-mono); }
.verbrauch { margin: 0 0 6px; font-size: var(--text-table); color: var(--text-muted); }
.verbrauch strong { font-family: var(--font-mono); font-variant-numeric: tabular-nums; font-size: var(--text-body); color: var(--text-strong); }
.balken { height: 6px; max-width: 420px; background: var(--line); border-radius: 3px; overflow: hidden; }
.fuellung { height: 100%; background: var(--accent); }
.balken[data-voll='true'] .fuellung { background: var(--warn); }
.gemessen { margin: 6px 0 0; font-size: var(--text-label); color: var(--text-faint); }
.knopfreihe { margin-top: var(--gap); }
td[data-status='suspended'] { color: var(--warn); }
td[data-status='provisioning'], td[data-status='removing'] { color: var(--accent); }
.marke { padding: 1px 5px; font-size: var(--text-label); color: var(--warn); background: var(--warn-surface); border-radius: 3px; }
.freigaben { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 3px; font-size: var(--text-table); }
.freigaben li[data-frei='true'] { color: var(--ok); }
.freigaben li[data-frei='false'] { color: var(--text-faint); }
</style>
