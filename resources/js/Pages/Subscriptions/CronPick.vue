<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import PanelLayout from '../../Layouts/PanelLayout.vue'

/**
 * Welches Abonnement soll seine Zeitsteuerung zeigen?
 *
 * ## Diese Seite ist der Sonderfall und nicht der Normalfall
 *
 * Der Menüpunkt „Cronjobs" führt bei genau **einem** erreichbaren Abonnement
 * direkt hinein; hierher kommt nur, wer mehrere hat.
 *
 * > **Eine Frage, die nur eine mögliche Antwort hat, ist keine Frage.**
 *
 * ## Die dritte Fassung derselben Seite, und das ist Absicht
 *
 * `Files/Pick.vue` und `SftpPick.vue` sind ihre Zwillinge — dieselbe Frage,
 * dieselbe Form, dieselben Wörter. Der Reiz, hier etwas besser zu machen, ist
 * genau der Weg, auf dem drei Seiten für eine Sache entstehen, von denen eine
 * gepflegt wird und zwei veralten. Wer diese hier ändert, sieht dort nach.
 */
defineProps<{
  subscriptions: { id: number; name: string }[]
}>()
</script>

<template>
  <Head title="Cronjobs" />

  <PanelLayout title="Cronjobs" subline="Abonnement wählen">
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
              <Link :href="`/subscriptions/${abo.id}/cron`" class="link">{{ abo.name }}</Link>
            </td>
          </tr>

          <!--
            **Erreichbar ist diese Zeile kaum, und sie steht trotzdem da.** Wer
            die Adresse von Hand aufruft, ohne ein freigegebenes Abonnement,
            bekäme sonst eine leere Tabelle ohne ein Wort dazu.
          -->
          <tr v-if="subscriptions.length === 0">
            <!--
              `colspan` auch bei einer einzigen Spalte: `MobileLayoutTest`
              verlangt je Zelle entweder ein `data-column` oder ein `colspan`.
              Ein `data-column="Abonnement"` wäre hier die falsche der beiden
              Antworten — der Satz **ist** kein Abonnement.
            -->
            <td colspan="1" class="quiet">Für keines Ihrer Abonnements sind Cronjobs freigegeben.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </PanelLayout>
</template>
