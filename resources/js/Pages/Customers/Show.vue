<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import Section from '../../Components/Section.vue'
import Badge from '../../Components/Badge.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

const props = defineProps<{
  customer: {
    id: number; number: string; name: string; company: string | null
    email: string; phone: string | null; status: string; status_label: string
  }
  accounts: {
    id: number; name: string; email: string
    type: string; type_label: string; status_label: string; last_login_at: string | null
  }[]
  subscriptions: { id: number; name: string; status: string; status_label: string }[]
}>()

const page = usePage()

function rang(status: string): 'ok' | 'warn' | 'critical' | 'neutral' {
  if (status === 'active') return 'ok'
  if (status === 'suspended') return 'warn'
  if (status === 'cancelled') return 'critical'

  return 'neutral'
}

/*
 * Die Ablehnung kommt als Prüfungsfehler zurück und nicht als Meldung — der
 * Controller weist ab, solange Abonnements laufen. Ohne diese Zeile stünde
 * nach dem Klick nichts da: Die Seite lädt neu, der Knopf ist noch da, und
 * niemand weiss, warum nichts geschah.
 */
const fehler = computed(() => (page.props.errors as Record<string, string> | undefined)?.customer)

/*
 * Eine Rückfrage und nicht zwei.
 *
 * Beim Rückbau eines Abonnements muss man den Namen abtippen — dort löscht
 * root einen Verzeichnisbaum, und es gibt keine Sicherung. Hier wird nichts
 * gelöscht: Die Zeile bleibt mit `deleted_at` stehen, die Kundennummer bleibt
 * vergeben, die Dateien gibt es ohnehin nicht mehr, weil die Abonnements
 * vorher zurückgebaut sein müssen. Eine Hürde, die grösser ist als der
 * Schaden, wird zur Gewohnheit — und Gewohnheiten schützen nicht.
 */
function zurueckziehen(): void {
  if (!window.confirm(
    `Kunde ${props.customer.number} zurückziehen? Die Konten kommen danach nicht mehr herein. `
    + 'Die Kundennummer bleibt vergeben und wird nicht neu ausgegeben.',
  )) return

  router.delete(`/customers/${props.customer.id}`)
}

/*
 * Die Rückfrage nennt, was mitgeht.
 *
 * „Kunde sperren?" allein beantwortet die Frage nicht, die man in dem Moment
 * hat: Gehen die Webseiten aus? Sie gehen aus — und wer das erst hinterher
 * merkt, hat es nicht bestätigt, sondern hingenommen.
 */
function sperren(): void {
  const anzahl = props.subscriptions.filter((s) => s.status === 'active').length

  if (!window.confirm(
    `Kunde ${props.customer.number} sperren?`
    + (anzahl > 0
      ? ` ${anzahl === 1 ? 'Das Abonnement wird' : `${anzahl} Abonnements werden`} mitgesperrt: `
        + 'Webseiten und Zugänge sind danach aus, die Daten bleiben.'
      : ' Es gibt kein aktives Abonnement, das mitgeht.'),
  )) return

  router.post(`/customers/${props.customer.id}/suspend`)
}

/*
 * Freigegeben wird nur, was mit dem Kunden gesperrt wurde. Ein Abonnement, das
 * der Betreiber vorher einzeln gesperrt hat, bleibt gesperrt — deshalb steht
 * hier keine Zahl, die etwas anderes verspricht.
 */
function freigeben(): void {
  router.post(`/customers/${props.customer.id}/resume`)
}
</script>

<template>
  <Head :title="props.customer.name" />

  <PanelLayout :title="props.customer.name">
    <template #breadcrumb>
      <Link href="/customers" class="link">Kunden</Link> · <span class="ident">{{ props.customer.number }}</span>
    </template>

    <template #actions>
      <Badge :kind="rang(props.customer.status)">{{ props.customer.status_label }}</Badge>
      <Link :href="`/customers/${props.customer.id}/edit`" class="button primary">Bearbeiten</Link>
      <button
        v-if="props.customer.status !== 'suspended'"
        type="button"
        class="button"
        @click="sperren"
      >
        Sperren
      </button>
      <button v-else type="button" class="button" @click="freigeben">Freigeben</button>
      <button type="button" class="button danger" @click="zurueckziehen">Zurückziehen</button>
    </template>

    <p v-if="fehler" class="notice critical">{{ fehler }}</p>

    <div class="sections">
      <Section title="Vertragspartner">
        <table class="pairs">
          <tbody>
            <tr><td class="quiet">E-Mail</td><td class="right name">{{ props.customer.email }}</td></tr>
            <tr><td class="quiet">Telefon</td><td class="right name">{{ props.customer.phone ?? '—' }}</td></tr>
            <tr>
              <td class="quiet">Zustand</td>
              <td class="right"><Badge :kind="rang(props.customer.status)">{{ props.customer.status_label }}</Badge></td>
            </tr>
          </tbody>
        </table>
      </Section>

      <Section title="Konten" note="Zugänge zu diesem Kunden. Ein Kunde ohne Konto ist angelegt, aber niemand kommt herein.">
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Anmeldeadresse</th><th>Art</th><th>Zustand</th><th>Zuletzt angemeldet</th></tr>
            </thead>
            <tbody>
              <tr v-for="a in props.accounts" :key="a.id">
                <td data-column="Anmeldeadresse" class="name">{{ a.email }}</td>
                <td data-column="Art" class="quiet">{{ a.type_label }}</td>
                <td data-column="Zustand" class="quiet">{{ a.status_label }}</td>
                <td data-column="Zuletzt angemeldet" class="quiet">
                  {{ a.last_login_at ?? 'noch nie angemeldet' }}
                </td>
              </tr>
              <tr v-if="props.accounts.length === 0">
                <td colspan="4" class="quiet">Kein Konto — dieser Kunde kann sich nicht anmelden.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>

      <Section title="Abonnements" full>
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Name</th><th>Zustand</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="s in props.subscriptions" :key="s.id">
                <td data-column="Name" class="ident name">
                  <Link :href="`/subscriptions/${s.id}`" class="link">{{ s.name }}</Link>
                </td>
                <td data-column="Zustand"><Badge :kind="rang(s.status)">{{ s.status_label }}</Badge></td>
                <td>
                  <Link :href="`/subscriptions/${s.id}/edit`" class="button small">Bearbeiten</Link>
                </td>
              </tr>
              <tr v-if="props.subscriptions.length === 0">
                <td colspan="3" class="quiet">Noch keines angelegt.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>
    </div>
  </PanelLayout>
</template>
