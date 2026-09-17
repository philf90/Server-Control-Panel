<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'

/**
 * Welches Abonnement soll seine Sicherungen zeigen?
 *
 * ## Diese Seite ist der Sonderfall und nicht der Normalfall
 *
 * Der Menüpunkt „Sicherungen" führt bei genau **einem** erreichbaren Abonnement
 * direkt hinein; hierher kommt nur, wer mehrere hat. Eine Auswahlseite auch für
 * den Normalfall wäre ein Klick, der nie eine Frage beantwortet.
 *
 * > **Eine Frage, die nur eine mögliche Antwort hat, ist keine Frage.**
 *
 * ## Und sie ist absichtlich die Zwillingsseite von `SftpPick.vue`
 *
 * Dieselbe Frage, dieselbe Form, dieselben Wörter — wie schon `CronPick.vue`
 * und `Files/Pick.vue`. Der Reiz, hier etwas besser zu machen, ist genau der
 * Weg, auf dem vier Seiten für eine Sache entstehen; drei werden gepflegt, die
 * vierte veraltet. Wer diese hier ändert, sieht dort nach.
 */
const props = defineProps<{
  subscriptions: { id: number; name: string }[]
  orphaned: {
    id: number
    subscription_name: string
    storage_name: string
    created_at: string
  }[]
}>()
</script>

<template>
  <Head title="Sicherungen" />

  <PanelLayout title="Sicherungen" subline="Abonnement wählen">
    <!--
      **Beide Bereiche stehen in `.sections`, und das ist die Hausform.**

      `main` ist ein `block` ohne `gap`; zwei Geschwister darin stehen mit
      **null** Pixel Abstand aneinander — gemessen, an beiden Breiten. Die
      Überschrift „Ohne Abonnement" klebte damit an der letzten Zeile der
      Tabelle darüber und las sich, als gehörte sie zu ihr.

      > **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
      > Betrachter.**

      Den Abstand gibt `.sections` über sein `gap`, und ausgezählt steht in
      diesem Baum **keine** `.scrolls` ausserhalb eines `<Section>`.

      Damit weicht diese Seite von ihren Zwillingen `SftpPick.vue` und
      `CronPick.vue` ab — die haben **einen** Block und brauchen keinen Abstand.
      Der geteilte Teil bleibt Wort für Wort derselbe.
    -->
    <div class="sections">
      <Section title="Ihre Abonnements">
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
                  <Link :href="`/subscriptions/${abo.id}/backups`" class="link">{{ abo.name }}</Link>
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
                <td colspan="1" class="quiet">Für keines Ihrer Abonnements sind Sicherungen freigegeben.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>

      <!--
      **Die Sicherungen ohne Abonnement, und sie stehen sonst nirgends.**

      Jede andere Liste dieses Panels führt über ein Abonnement. Eine Sicherung,
      die ihren Rückbau überlebt hat — und genau die legt Schritt 10 **vor** dem
      Rückbau an —, wäre damit nur über eine Adresse erreichbar, deren Kennung
      niemand kennt.

      > **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht sie
      > dort?**

      Der Bereich steht nur da, wenn es solche Sicherungen gibt: Eine leere
      Überschrift über einer leeren Tabelle beantwortet eine Frage, die niemand
      gestellt hat.
    -->
      <Section v-if="props.orphaned.length" title="Ohne Abonnement" full>
      <p class="hint">
        Ihr Abonnement ist zurückgebaut; die Sicherung hat es überlebt. Beim
        Zurückspielen entsteht ein <strong>neues</strong> Abonnement mit einem
        neuen Systembenutzer — was sich dabei ändert, sagt die Seite danach.
      </p>

      <div class="scrolls">
        <table class="stacks">
          <thead>
            <tr>
              <th>Abonnement</th>
              <th>Stand</th>
              <th>Angelegt</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="sicherung in props.orphaned" :key="sicherung.id">
              <td data-column="Abonnement" class="cell-name">
                <Link :href="`/backups/${sicherung.id}/restore`" class="link">
                  {{ sicherung.subscription_name }}
                </Link>
              </td>
              <td data-column="Stand" class="ident">{{ sicherung.storage_name }}</td>
              <td data-column="Angelegt">{{ sicherung.created_at }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      </Section>
    </div>
  </PanelLayout>
</template>
