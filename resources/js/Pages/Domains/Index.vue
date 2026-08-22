<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import Badge from '../../Components/Badge.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Pager from '../../Components/Pager.vue'

interface Row {
  id: number
  name: string
  type_label: string
  status: string
  status_label: string
  php_version: string | null
  is_redirect: boolean
  subscription: string | null
  subscription_id: number

  /**
   * Der DNS-Abgleich, auf eine Marke zusammengezogen.
   *
   * **Rang und Wortlaut kommen vom Server** (`DnsHealth`) und werden hier
   * nicht abgeleitet. Eine Zuordnung im Template wäre eine zweite Fassung
   * derselben Regel — dieselbe Entscheidung wie bei den fünf Zuständen im
   * Bereich an der Domain.
   */
  dns: string
  dns_label: string
  dns_badge: 'ok' | 'warn' | 'critical' | 'neutral'
}

const props = defineProps<{
  domains: { data: Row[]; current_page: number; last_page: number; total: number }

  /**
   * Die Abonnements, in denen der Betrachter eine Domain anlegen darf.
   *
   * **Leer beim Betreiber, und das ist Absicht.** Die Abkürzung führt in ein
   * bestimmtes Abonnement; er hat davon Hunderte, und eine Auswahl über alle
   * wäre kein kurzer Weg. Seine Wege bleiben, wie sie waren.
   */
  creatable: { id: number; name: string }[]
}>()

/*
 * Bei genau einem Abonnement führt der Knopf direkt hin; bei mehreren steht
 * eine Auswahl davor.
 *
 * Die Auswahl **immer** zu zeigen wäre die einfachere Fassung und die
 * schlechtere: Wer ein Abonnement hat — der Normalfall —, müsste erst das
 * einzige auswählen, das es gibt.
 */
const chosen = ref<number | null>(props.creatable[0]?.id ?? null)

function createDomain(): void {
  if (chosen.value !== null) router.visit(`/subscriptions/${chosen.value}/domains/create`)
}

function rang(status: string): 'ok' | 'warn' | 'critical' | 'neutral' {
  if (status === 'active') return 'ok'
  if (status === 'provisioning' || status === 'removing') return 'warn'
  if (status === 'failed') return 'critical'

  return 'neutral'
}
</script>

<template>
  <Head title="Domains" />

  <PanelLayout title="Domains" :subline="`${props.domains.total} insgesamt`">
    <template #actions>
      <Link
        v-if="props.creatable.length === 1"
        :href="`/subscriptions/${props.creatable[0].id}/domains/create`"
        class="button primary"
      >
        Domain anlegen
      </Link>

      <!-- Mehrere Abonnements: erst wohin, dann anlegen. Ein `form` und kein
           Knopf mit Zustand — so trägt die Eingabetaste im Auswahlfeld.

           **Die Beschriftung steht sichtbar dabei und nicht nur als
           `aria-label`.** Vorher war es ein Feld mit einem Domainnamen darin,
           neben einem Knopf — für einen sehenden Betrachter unbeschriftet. Wer
           es übersieht, legt die Domain im falschen Abonnement an, und das
           merkt man erst am Verzeichnisbaum. Am 7. August 2026 vom Betreiber
           gemeldet. -->
      <form v-else-if="props.creatable.length > 1" class="button-row" @submit.prevent="createDomain">
        <label class="field inline">
          <span>Abonnement</span>
          <select v-model="chosen">
            <option v-for="s in props.creatable" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </label>
        <button type="submit" class="button primary">Domain anlegen</button>
      </form>
    </template>

    <div class="scrolls">
      <table class="stacks">
        <thead>
          <tr>
            <th>Domain</th><th>Sorte</th><th>Abonnement</th><th>PHP</th><th>DNS</th><th>Zustand</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in props.domains.data" :key="row.id">
            <td data-column="Domain" class="ident name">
              <Link :href="`/domains/${row.id}`" class="link">{{ row.name }}</Link>
            </td>
            <td data-column="Sorte" class="quiet">{{ row.type_label }}</td>
            <td data-column="Abonnement">
              <Link :href="`/subscriptions/${row.subscription_id}`" class="link">
                {{ row.subscription ?? '—' }}
              </Link>
            </td>

            <!--
              Eine Weiterleitung hat keinen Handler und braucht keinen: nginx
              antwortet selbst. „—" wäre hier dieselbe Anzeige wie bei einer
              Domain, der die PHP-Version fehlt, und das sind zwei verschiedene
              Dinge.
            -->
            <td data-column="PHP">
              <template v-if="row.is_redirect">
                <span class="quiet">leitet weiter</span>
              </template>
              <template v-else>{{ row.php_version ?? '—' }}</template>
            </td>

            <!--
              **Der DNS-Abgleich steht vor dem Zustand der Domain**, weil er
              die Frage beantwortet, die eine Liste stellt: Welche Zeile muss
              ich aufschlagen? Der Zustand daneben sagt, ob die Domain
              eingerichtet ist — zwei verschiedene Auskünfte, und die
              Reihenfolge ist die, in der man sie liest.
            -->
            <td data-column="DNS">
              <Badge :kind="row.dns_badge">{{ row.dns_label }}</Badge>
            </td>

            <td data-column="Zustand">
              <Badge :kind="rang(row.status)">{{ row.status_label }}</Badge>
            </td>
          </tr>
          <tr v-if="props.domains.data.length === 0">
            <td colspan="6" class="quiet">Noch keine Domain.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <Pager :page="props.domains.current_page" :pages="props.domains.last_page" />
  </PanelLayout>
</template>
