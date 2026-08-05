<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import Section from '../Components/Section.vue'
import Badge from '../Components/Badge.vue'
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

function rang(status: string): 'ok' | 'warn' | 'critical' | 'neutral' {
  if (status === 'active') return 'ok'
  if (status === 'suspended' || status === 'provisioning' || status === 'removing') return 'warn'
  if (status === 'cancelled' || status === 'failed') return 'critical'

  return 'neutral'
}
</script>

<template>
  <Head title="Übersicht" />

  <PanelLayout title="Übersicht" subline="Ihre Abonnements">
    <!--
      Ein Bereich je Abonnement und keine Kärtchenwand.

      Die meisten Kunden haben eines, viele zwei, kaum jemand mehr als vier.
      Für diese Zahl ist ein Raster aus Kärtchen die falsche Form: Es macht
      aus drei Zeilen Text drei Kästen und lässt daneben die halbe Seite leer.
      Ein Bereich trägt dieselben Angaben und macht Platz für das, was in P4
      dazukommt — Zertifikat, Speicherstand, Zugänge.
    -->
    <div v-if="subscriptions.length > 0" class="sections">
      <Section v-for="abo in subscriptions" :key="abo.id" :title="abo.name">
        <template #actions>
          <Badge :kind="rang(abo.status)">{{ abo.status_label }}</Badge>
        </template>

        <table class="pairs">
          <tbody>
            <!--
              `.kennung` nur, wenn wirklich eine Domain dasteht. Mit der
              Klasse am ganzen Feld stand „noch keine Domain" in Monospace —
              ein Satz in der Schrift für Bezeichner liest sich wie ein Wert,
              den man irgendwo eintippen soll. Im Browser gesehen.
            -->
            <tr>
              <td class="quiet">Hauptdomain</td>
              <td class="right" :class="{ ident: abo.main_domain !== null }">
                {{ abo.main_domain ?? 'noch keine Domain' }}
              </td>
            </tr>
          </tbody>
        </table>
      </Section>
    </div>

    <!--
      Eine leere Liste mit einem Satz dazu ist eine Auskunft; eine weiße
      Fläche wäre keine.
    -->
    <p v-else class="empty">
      Für Sie ist noch kein Abonnement eingerichtet. Sobald eines angelegt ist,
      erscheint es hier. Ihre bisherigen Anmeldungen stehen im
      <Link href="/audit" class="link">Protokoll</Link>.
    </p>
  </PanelLayout>
</template>
