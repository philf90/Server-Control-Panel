<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import PanelLayout from '../../Layouts/PanelLayout.vue'

/**
 * Welches Abonnement soll der Dateimanager zeigen?
 *
 * ## Diese Seite ist der Sonderfall und nicht der Normalfall
 *
 * Der Menüpunkt „Dateien" führt bei genau **einem** erreichbaren Abonnement
 * direkt hinein; hierher kommt nur, wer mehrere hat. Eine Auswahlseite auch für
 * den Normalfall wäre ein Klick, der nie eine Frage beantwortet.
 *
 * > **Eine Frage, die nur eine mögliche Antwort hat, ist keine Frage.**
 *
 * ## Und sie ist absichtlich schmal
 *
 * Hier steht der Name und sonst nichts — kein Zustand, kein Plan, keine
 * Kennzahl. Das alles steht unter „Abonnements", und eine zweite Liste mit
 * denselben Spalten wäre eine zweite Fassung derselben Seite: Die eine wird
 * gepflegt, die andere veraltet.
 */
defineProps<{
  subscriptions: { id: number; name: string }[]
}>()
</script>

<template>
  <Head title="Dateien" />

  <PanelLayout title="Dateien" subline="Abonnement wählen">
    <!--
      Ein Verzeichnis von Namen, die man Zeile für Zeile liest — genau das
      Muster, für das `.stacks` in `docs/24 §5` gedacht ist.
    -->
    <div class="scrolls">
      <table class="stacks">
        <thead>
          <tr><th>Abonnement</th></tr>
        </thead>
        <tbody>
          <tr v-for="abo in subscriptions" :key="abo.id">
            <td data-column="Abonnement" class="cell-name">
              <Link :href="`/subscriptions/${abo.id}/files`" class="link">{{ abo.name }}</Link>
            </td>
          </tr>

          <!--
            **Erreichbar ist diese Zeile kaum, und sie steht trotzdem da.** Der
            Menüpunkt erscheint nur bei einem aktiven Abonnement; wer die
            Adresse von Hand aufruft, ohne eines zu haben, bekäme sonst eine
            leere Tabelle ohne ein Wort dazu.
          -->
          <tr v-if="subscriptions.length === 0">
            <!--
              `colspan` auch bei einer einzigen Spalte: `MobileLayoutTest`
              verlangt je Zelle entweder ein `data-column` oder ein `colspan` —
              eine Zelle ohne beides steht auf dem Telefon ohne Beschriftung da.
              Ein `data-column="Abonnement"` wäre hier die falsche der beiden
              Antworten: Der Satz **ist** kein Abonnement.
            -->
            <td colspan="1" class="quiet">Für keines Ihrer Abonnements sind Dateien freigegeben.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </PanelLayout>
</template>
