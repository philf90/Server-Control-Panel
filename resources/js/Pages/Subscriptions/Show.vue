<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import Balken from '../../Components/Balken.vue'
import Bereich from '../../Components/Bereich.vue'
import Marke from '../../Components/Marke.vue'
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

function rang(status: string): 'ok' | 'warn' | 'kritisch' | 'neutral' {
  if (status === 'active') return 'ok'
  if (status === 'suspended' || status === 'provisioning' || status === 'removing') return 'warn'
  if (status === 'cancelled' || status === 'failed') return 'kritisch'

  return 'neutral'
}

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

  <PanelLayout :title="props.subscription.name">
    <template #pfad>
      <Link href="/subscriptions" class="verweis">Abonnements</Link> ·
      <Link :href="`/customers/${props.subscription.customer_id}`" class="verweis">
        {{ props.subscription.customer ?? '—' }}
      </Link>
    </template>

    <template #aktion>
      <Marke :art="rang(props.subscription.status)">{{ props.subscription.status_label }}</Marke>
      <Link class="knopf wichtig" :href="`/subscriptions/${props.subscription.id}/edit`">Bearbeiten</Link>
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
    </template>

    <div class="bereiche">
      <Bereich titel="Stammdaten">
        <table class="paare">
          <tbody>
            <tr>
              <td class="stumm">Kunde</td>
              <td class="rechts">
                <Link :href="`/customers/${props.subscription.customer_id}`" class="verweis">
                  {{ props.subscription.customer ?? '—' }}
                </Link>
              </td>
            </tr>
            <tr><td class="stumm">Plan</td><td class="rechts name">{{ props.subscription.plan ?? '—' }}</td></tr>
            <tr><td class="stumm">Systembenutzer</td><td class="rechts kennung">{{ props.subscription.system_user ?? '—' }}</td></tr>
            <tr><td class="stumm">Verzeichnis</td><td class="rechts kennung">{{ props.subscription.root }}</td></tr>
            <tr v-if="props.subscription.suspended_at">
              <td class="stumm">Gesperrt seit</td>
              <td class="rechts">{{ props.subscription.suspended_at }}</td>
            </tr>
          </tbody>
        </table>
      </Bereich>

      <!--
        Der Speicher steht neben den Kontingenten und nicht darin: Er ist das
        einzige Kontingent, zu dem es einen gemessenen Stand gibt, und die
        Tabelle daneben zeigt Vereinbartes. Beides in einer Zeile hiesse, zwei
        verschiedene Dinge gleich aussehen zu lassen.
      -->
      <Bereich titel="Speicher">
        <p v-if="props.usage.used_mb === null" class="leer">
          Noch nicht gemessen. Die Messung läuft im Viertelstundentakt
          (<span class="kennung">srvpanel-usage.timer</span>) und braucht eine
          Dateisystem-Quota auf dem Mount von /var/www/vhosts.
        </p>

        <template v-else>
          <p class="verbrauch">
            <strong>{{ props.usage.used_mb.toLocaleString('de-DE') }} MB</strong>
            <span v-if="props.usage.limit_mb !== null">
              von {{ props.usage.limit_mb.toLocaleString('de-DE') }} MB
            </span>
            <span v-else>ohne Grenze</span>
          </p>

          <Balken
            v-if="props.usage.percent !== null"
            :prozent="props.usage.percent"
            :eng="props.usage.percent >= 90 && props.usage.percent <= 100"
            :ueber="props.usage.percent > 100"
            breit
          />

          <p class="erklaer">Gemessen am {{ props.usage.measured_at ?? '—' }}</p>
        </template>
      </Bereich>

      <Bereich titel="Kontingente">
        <table class="paare">
          <tbody>
            <tr v-for="q in props.quotas" :key="q.key">
              <td class="stumm">{{ q.label }}</td>
              <td class="rechts name">{{ q.value }}</td>
              <td class="rechts">
                <Marke v-if="q.differs" art="warn">abweichend vom Plan</Marke>
              </td>
            </tr>
          </tbody>
        </table>
      </Bereich>

      <Bereich titel="Freigaben">
        <table class="paare">
          <tbody>
            <tr v-for="f in props.features" :key="f.label">
              <td class="stumm">{{ f.label }}</td>
              <td class="rechts">
                <Marke :art="f.granted ? 'ok' : 'neutral'">{{ f.granted ? 'frei' : 'gesperrt' }}</Marke>
              </td>
            </tr>
          </tbody>
        </table>
      </Bereich>

      <!--
        Die Domains stehen vor den Vorgängen: Sie sind das, wofür ein
        Abonnement da ist.
      -->
      <Bereich titel="Domains" voll>
        <template #aktion>
          <Link
            v-if="props.mayAddDomain"
            class="knopf klein"
            :href="`/subscriptions/${props.subscription.id}/domains/create`"
          >
            Domain anlegen
          </Link>
        </template>

        <div class="rollt">
          <table class="stapelt">
            <thead>
              <tr><th>Domain</th><th>Sorte</th><th>PHP</th><th>Zustand</th></tr>
            </thead>
            <tbody>
              <tr v-for="d in props.domains" :key="d.id">
                <td data-spalte="Domain" class="kennung name">
                  <Link :href="`/domains/${d.id}`" class="verweis">{{ d.name }}</Link>
                </td>
                <td data-spalte="Sorte" class="stumm">{{ d.type_label }}</td>
                <td data-spalte="PHP">
                  <template v-if="d.is_redirect"><span class="stumm">leitet weiter</span></template>
                  <template v-else>{{ d.php_version ?? '—' }}</template>
                </td>
                <td data-spalte="Zustand">
                  <Marke :art="rang(d.status)">{{ d.status_label }}</Marke>
                </td>
              </tr>
              <tr v-if="props.domains.length === 0">
                <td colspan="4" class="stumm">Noch keine Domain.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Bereich>

      <Bereich titel="Vorgänge" voll>
        <div class="rollt">
          <table class="stapelt">
            <thead>
              <tr><th>Nummer</th><th>Aufgabe</th><th>Zustand</th><th>Angelegt</th></tr>
            </thead>
            <tbody>
              <tr v-for="o in props.operations" :key="o.id">
                <td data-spalte="Nummer" class="kennung">
                  <Link :href="`/operations/${o.id}`" class="verweis">{{ o.id }}</Link>
                </td>
                <td data-spalte="Aufgabe" class="kennung name">{{ o.task ?? '—' }}</td>
                <td data-spalte="Zustand" class="stumm">{{ o.status_label }}</td>
                <td data-spalte="Angelegt" class="stumm">{{ o.created_at ?? '—' }}</td>
              </tr>
              <tr v-if="props.operations.length === 0">
                <td colspan="4" class="stumm">Noch kein Vorgang für dieses Abonnement.</td>
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
 * Der gemessene Stand als grosse Zahl — dieselbe Rolle wie auf einer Kachel,
 * deshalb dieselbe Marke. Die Einheit daneben ist kleiner, weil sie mitläuft
 * und nicht gelesen wird.
 */
.verbrauch {
  display: flex;
  align-items: baseline;
  gap: 8px;
  flex-wrap: wrap;
  margin: 16px 0 14px;
  font-size: var(--text-table);
  color: var(--text-muted);
}

.verbrauch strong {
  font-size: var(--text-metric);
  font-weight: 640;
  letter-spacing: -0.03em;
  line-height: 1.05;
  color: var(--text-strong);
}
</style>
