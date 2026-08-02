<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import PanelLayout from '../../Layouts/PanelLayout.vue'

defineProps<{
  customer: {
    id: number; number: string; name: string; company: string | null
    email: string; phone: string | null; status: string; status_label: string
  }
  accounts: {
    id: number; name: string; email: string
    type: string; type_label: string; status_label: string; last_login_at: string | null
  }[]
  subscriptions: { id: number; name: string; status_label: string }[]
}>()
</script>

<template>
  <Head :title="customer.name" />

  <PanelLayout :title="customer.name" :subline="customer.number">
    <div class="spalten">
      <section>
        <h2>Vertragspartner</h2>
        <dl>
          <dt>E-Mail</dt><dd>{{ customer.email }}</dd>
          <dt>Telefon</dt><dd>{{ customer.phone ?? '—' }}</dd>
          <dt>Zustand</dt><dd>{{ customer.status_label }}</dd>
        </dl>
      </section>

      <section>
        <h2>Konten</h2>
        <ul>
          <li v-for="a in accounts" :key="a.id">
            <b>{{ a.email }}</b> · {{ a.type_label }} · {{ a.status_label }}
            <span class="letzte">{{ a.last_login_at ?? 'noch nie angemeldet' }}</span>
          </li>
        </ul>
      </section>

      <section>
        <h2>Abonnements</h2>
        <ul v-if="subscriptions.length > 0">
          <li v-for="s in subscriptions" :key="s.id">{{ s.name }} · {{ s.status_label }}</li>
        </ul>
        <p v-else class="leer">Noch keines angelegt.</p>
      </section>
    </div>
  </PanelLayout>
</template>

<style scoped>
.spalten { display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: var(--gap); }
section { padding: var(--padding); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 8px; }
h2 { margin: 0 0 .5rem; font-size: .85rem; color: var(--text-muted); font-weight: 600; }
dl { display: grid; grid-template-columns: auto 1fr; gap: .2rem .6rem; margin: 0; font-size: .85rem; }
dt { color: var(--text-faint); }
dd { margin: 0; color: var(--text); }
ul { margin: 0; padding-left: 1rem; font-size: .85rem; }
li { margin-bottom: .3rem; }
.letzte { display: block; font-size: .75rem; color: var(--text-faint); }
.leer { margin: 0; font-size: .85rem; color: var(--text-faint); }
</style>
