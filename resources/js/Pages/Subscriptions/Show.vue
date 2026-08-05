<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import Bar from '../../Components/Bar.vue'
import Section from '../../Components/Section.vue'
import Badge from '../../Components/Badge.vue'
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

function rang(status: string): 'ok' | 'warn' | 'critical' | 'neutral' {
  if (status === 'active') return 'ok'
  if (status === 'suspended' || status === 'provisioning' || status === 'removing') return 'warn'
  if (status === 'cancelled' || status === 'failed') return 'critical'

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
    <template #breadcrumb>
      <Link href="/subscriptions" class="link">Abonnements</Link> ·
      <Link :href="`/customers/${props.subscription.customer_id}`" class="link">
        {{ props.subscription.customer ?? '—' }}
      </Link>
    </template>

    <template #actions>
      <Badge :kind="rang(props.subscription.status)">{{ props.subscription.status_label }}</Badge>
      <Link class="button primary" :href="`/subscriptions/${props.subscription.id}/edit`">Bearbeiten</Link>
      <button v-if="props.subscription.status === 'active'" type="button" class="button" @click="suspend">Sperren</button>
      <button v-if="props.subscription.status === 'suspended'" type="button" class="button" @click="resume">Entsperren</button>
      <button
        v-if="props.subscription.status !== 'provisioning'"
        type="button"
        class="button danger"
        @click="remove"
      >
        Zurückbauen
      </button>
    </template>

    <div class="sections">
      <Section title="Stammdaten">
        <table class="pairs">
          <tbody>
            <tr>
              <td class="quiet">Kunde</td>
              <td class="right">
                <Link :href="`/customers/${props.subscription.customer_id}`" class="link">
                  {{ props.subscription.customer ?? '—' }}
                </Link>
              </td>
            </tr>
            <tr><td class="quiet">Plan</td><td class="right name">{{ props.subscription.plan ?? '—' }}</td></tr>
            <tr><td class="quiet">Systembenutzer</td><td class="right ident">{{ props.subscription.system_user ?? '—' }}</td></tr>
            <tr><td class="quiet">Verzeichnis</td><td class="right ident">{{ props.subscription.root }}</td></tr>
            <tr v-if="props.subscription.suspended_at">
              <td class="quiet">Gesperrt seit</td>
              <td class="right">{{ props.subscription.suspended_at }}</td>
            </tr>
          </tbody>
        </table>
      </Section>

      <!--
        Der Speicher steht neben den Kontingenten und nicht darin: Er ist das
        einzige Kontingent, zu dem es einen gemessenen Stand gibt, und die
        Tabelle daneben zeigt Vereinbartes. Beides in einer Zeile hiesse, zwei
        verschiedene Dinge gleich aussehen zu lassen.
      -->
      <Section title="Speicher">
        <p v-if="props.usage.used_mb === null" class="empty">
          Noch nicht gemessen. Die Messung läuft im Viertelstundentakt
          (<span class="ident">srvpanel-usage.timer</span>) und braucht eine
          Dateisystem-Quota auf dem Mount von /var/www/vhosts.
        </p>

        <template v-else>
          <p class="usage">
            <strong>{{ props.usage.used_mb.toLocaleString('de-DE') }} MB</strong>
            <span v-if="props.usage.limit_mb !== null">
              von {{ props.usage.limit_mb.toLocaleString('de-DE') }} MB
            </span>
            <span v-else>ohne Grenze</span>
          </p>

          <Bar
            v-if="props.usage.percent !== null"
            :percent="props.usage.percent"
            :tight="props.usage.percent >= 90 && props.usage.percent <= 100"
            :over="props.usage.percent > 100"
            breit
          />

          <p class="section-note">Gemessen am {{ props.usage.measured_at ?? '—' }}</p>
        </template>
      </Section>

      <Section title="Kontingente">
        <table class="pairs">
          <tbody>
            <tr v-for="q in props.quotas" :key="q.key">
              <td class="quiet">{{ q.label }}</td>
              <td class="right name">{{ q.value }}</td>
              <td class="right">
                <Badge v-if="q.differs" kind="warn">abweichend vom Plan</Badge>
              </td>
            </tr>
          </tbody>
        </table>
      </Section>

      <Section title="Freigaben">
        <table class="pairs">
          <tbody>
            <tr v-for="f in props.features" :key="f.label">
              <td class="quiet">{{ f.label }}</td>
              <td class="right">
                <Badge :kind="f.granted ? 'ok' : 'neutral'">{{ f.granted ? 'frei' : 'gesperrt' }}</Badge>
              </td>
            </tr>
          </tbody>
        </table>
      </Section>

      <!--
        Die Domains stehen vor den Vorgängen: Sie sind das, wofür ein
        Abonnement da ist.
      -->
      <Section title="Domains" full>
        <template #actions>
          <Link
            v-if="props.mayAddDomain"
            class="button small"
            :href="`/subscriptions/${props.subscription.id}/domains/create`"
          >
            Domain anlegen
          </Link>
        </template>

        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Domain</th><th>Sorte</th><th>PHP</th><th>Zustand</th></tr>
            </thead>
            <tbody>
              <tr v-for="d in props.domains" :key="d.id">
                <td data-column="Domain" class="ident name">
                  <Link :href="`/domains/${d.id}`" class="link">{{ d.name }}</Link>
                </td>
                <td data-column="Sorte" class="quiet">{{ d.type_label }}</td>
                <td data-column="PHP">
                  <template v-if="d.is_redirect"><span class="quiet">leitet weiter</span></template>
                  <template v-else>{{ d.php_version ?? '—' }}</template>
                </td>
                <td data-column="Zustand">
                  <Badge :kind="rang(d.status)">{{ d.status_label }}</Badge>
                </td>
              </tr>
              <tr v-if="props.domains.length === 0">
                <td colspan="4" class="quiet">Noch keine Domain.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>

      <Section title="Vorgänge" full>
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Nummer</th><th>Aufgabe</th><th>Zustand</th><th>Angelegt</th></tr>
            </thead>
            <tbody>
              <tr v-for="o in props.operations" :key="o.id">
                <td data-column="Nummer" class="ident">
                  <Link :href="`/operations/${o.id}`" class="link">{{ o.id }}</Link>
                </td>
                <td data-column="Aufgabe" class="ident name">{{ o.task ?? '—' }}</td>
                <td data-column="Zustand" class="quiet">{{ o.status_label }}</td>
                <td data-column="Angelegt" class="quiet">{{ o.created_at ?? '—' }}</td>
              </tr>
              <tr v-if="props.operations.length === 0">
                <td colspan="4" class="quiet">Noch kein Vorgang für dieses Abonnement.</td>
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
 * Der gemessene Stand als grosse Zahl — dieselbe Rolle wie auf einer Kachel,
 * deshalb dieselbe Marke. Die Einheit daneben ist kleiner, weil sie mitläuft
 * und nicht gelesen wird.
 */
.usage {
  display: flex;
  align-items: baseline;
  gap: 8px;
  flex-wrap: wrap;
  margin: 16px 0 14px;
  font-size: var(--text-table);
  color: var(--text-muted);
}

.usage strong {
  font-size: var(--text-metric);
  font-weight: 640;
  letter-spacing: -0.03em;
  line-height: 1.05;
  color: var(--text-strong);
}
</style>
