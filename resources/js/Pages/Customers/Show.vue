<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
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

  <PanelLayout :title="props.customer.name" :subline="props.customer.number">
    <p v-if="fehler" class="fehler-block">{{ fehler }}</p>

    <header class="kopf knopfreihe">
      <Link :href="`/customers/${props.customer.id}/edit`" class="knopf">Bearbeiten</Link>
      <button
        v-if="props.customer.status !== 'suspended'"
        type="button"
        class="knopf"
        @click="sperren"
      >
        Sperren
      </button>
      <button v-else type="button" class="knopf" @click="freigeben">Freigeben</button>
      <button type="button" class="knopf gefahr" @click="zurueckziehen">Zurückziehen</button>
    </header>

    <div class="spalten">
      <section>
        <h2>Vertragspartner</h2>
        <dl>
          <dt>E-Mail</dt><dd>{{ props.customer.email }}</dd>
          <dt>Telefon</dt><dd>{{ props.customer.phone ?? '—' }}</dd>
          <dt>Zustand</dt><dd>{{ props.customer.status_label }}</dd>
        </dl>
      </section>

      <section>
        <h2>Konten</h2>
        <ul>
          <li v-for="a in props.accounts" :key="a.id">
            <b>{{ a.email }}</b> · {{ a.type_label }} · {{ a.status_label }}
            <span class="letzte">{{ a.last_login_at ?? 'noch nie angemeldet' }}</span>
          </li>
        </ul>
      </section>

      <section>
        <h2>Abonnements</h2>
        <ul v-if="props.subscriptions.length > 0">
          <li v-for="s in props.subscriptions" :key="s.id" :data-status="s.status">
            {{ s.name }} · {{ s.status_label }}
          </li>
        </ul>
        <p v-else class="leer">Noch keines angelegt.</p>
      </section>
    </div>
  </PanelLayout>
</template>

<style scoped>
.kopf { justify-content: flex-end; margin-bottom: var(--gap); }
.fehler-block { margin: 0 0 var(--gap); padding: 8px 11px; font-size: var(--text-table); color: var(--critical); background: var(--critical-surface); border-radius: 6px; }
.spalten { display: grid; grid-template-columns: repeat(auto-fit, minmax(256px, 1fr)); gap: var(--gap); }
section { padding: var(--padding); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 8px; }
h2 { margin: 0 0 8px; font-size: var(--text-table); color: var(--text-muted); font-weight: 600; }
dl { display: grid; grid-template-columns: auto 1fr; gap: 3px 10px; margin: 0; font-size: var(--text-table); }
dt { color: var(--text-faint); }
dd { margin: 0; color: var(--text); }
ul { margin: 0; padding-left: 16px; font-size: var(--text-table); }
li { margin-bottom: 5px; }
.letzte { display: block; font-size: var(--text-small); color: var(--text-faint); }
li[data-status='suspended'] { color: var(--warn); }
li[data-status='provisioning'] { color: var(--text-faint); }
.leer { margin: 0; font-size: var(--text-table); color: var(--text-faint); }
</style>
