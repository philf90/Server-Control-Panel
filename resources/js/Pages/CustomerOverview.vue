<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import PanelLayout from '../Layouts/PanelLayout.vue'

/*
 * Die Kundenfläche.
 *
 * Sie bekommt die Serverwerte nicht ausgeblendet, sondern gar nicht erst — die
 * Verzweigung steht im Controller. Was hier nicht steht, wurde auch nicht
 * geschickt.
 */

defineProps<{
  subscriptions: {
    id: number
    name: string
    main_domain: string | null
    status: string
    status_label: string
  }[]
}>()
</script>

<template>
  <Head title="Übersicht" />

  <PanelLayout title="Übersicht" subline="Ihre Abonnements">
    <section v-if="subscriptions.length > 0" class="abos">
      <article v-for="abo in subscriptions" :key="abo.id" class="abo">
        <h2>{{ abo.name }}</h2>
        <p class="domain">{{ abo.main_domain ?? 'noch keine Domain' }}</p>
        <p class="status" :data-status="abo.status">{{ abo.status_label }}</p>
      </article>
    </section>

    <!--
      Eine leere Liste mit einem Satz dazu ist eine Auskunft; eine weiße
      Fläche wäre keine.
    -->
    <p v-else class="leer">
      Für Sie ist noch kein Abonnement eingerichtet. Sobald eines angelegt ist,
      erscheint es hier. Ihre bisherigen Anmeldungen stehen im
      <Link href="/audit">Protokoll</Link>.
    </p>
  </PanelLayout>
</template>

<style scoped>
.abos { display: grid; grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr)); gap: var(--gap); }
.abo { padding: var(--padding); background: var(--surface); border: 1px solid var(--surface-border); border-radius: 8px; }
.abo h2 { margin: 0 0 .2rem; font-size: .95rem; color: var(--text-strong); }
.domain { margin: 0 0 .4rem; font-size: .8rem; color: var(--text-muted); }
.status { margin: 0; font-size: .78rem; color: var(--ok); }
.status[data-status='suspended'] { color: var(--warn); }
.status[data-status='cancelled'] { color: var(--critical); }
.leer { max-width: 34rem; font-size: .9rem; color: var(--text-muted); line-height: 1.6; }
</style>
