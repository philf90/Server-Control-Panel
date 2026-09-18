<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, onMounted, onUnmounted, watch } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'
import { useConfirmation } from '../../Composables/useConfirmation'

const { ask } = useConfirmation()

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
    status_label: string

    /**
     * Ob sich diese Zeile gerade von selbst ändert.
     *
     * **Vom Server und nicht aus einem Vergleich hier** — dieselbe Antwort, die
     * `Subscriptions/Backups.vue` bekommt, aus `BackupStatus::running()`. Zwei
     * Bedingungen über dieselben Zustandsnamen wären zwei Fassungen derselben
     * Regel.
     */
    running: boolean
  }[]
}>()

/**
 * Derselbe Takt wie auf der Sicherungsseite, und aus demselben Grund.
 *
 * **Der Befund** (`docs/121 §9`, Befund 4), gemeldet vom Betreiber beim
 * Benutzen: „/backups aktualisiert sich nicht automatisch wenn das Backup
 * entfernt wurde." Diese Seite hatte **gar keinen** Takt — das Entfernen ist
 * ein Vorgang des Agenten, `destroyOrphan()` leitet hierher zurück, und die
 * Zeile stand danach unverändert da, mitsamt ihrem Knopf.
 *
 * > **Zwei Wege, denselben Zustand zu zeigen — die Seite aktuell halten oder
 * > zum Vorgang führen —, und das Entfernen ging keinen von beiden.**
 *
 * **Am Zustand der Zeilen und nicht an einem Merker**, wie dort: Eine
 * Entfernung aus dem nächtlichen Lauf der Aufbewahrung oder aus einem zweiten
 * Reiter soll denselben Takt auslösen.
 *
 * Drei Sekunden, dieselbe Zahl wie dort — wer davorsteht und wartet, soll nicht
 * neu laden müssen.
 */
const NACHFRAGE_MS = 3000

let takt: ReturnType<typeof setInterval> | undefined

const laeuft = computed((): boolean => props.orphaned.some((zeile) => zeile.running))

function nachsehen(): void {
  router.reload({ only: ['orphaned'] })
}

function stellen(): void {
  clearInterval(takt)
  takt = undefined

  if (laeuft.value) takt = setInterval(nachsehen, NACHFRAGE_MS)
}

watch(laeuft, stellen)
onMounted(stellen)

/**
 * Inertia tauscht die Seite im selben Dokument aus. Ein Takt überlebt das und
 * fragt bis zum Schliessen des Reiters weiter — `TeardownTest` besteht darauf.
 */
onUnmounted((): void => {
  clearInterval(takt)
})

/**
 * Eine Sicherung ohne Abonnement entfernen — mit Rückfrage.
 *
 * **Der Befund, für den es diesen Knopf gibt** (P8, 17. September 2026): Die
 * Zeilen standen hier ohne jede Handlung, und die Adresse von
 * `backups.destroy` verlangt ein Abonnement, das es nicht mehr gibt. Der Griff
 * dahinter war gebaut und unerreichbar.
 *
 * > **Ein Griff, den es gibt und zu dem kein Weg führt, ist von einem, den es
 * > nicht gibt, nicht zu unterscheiden.**
 *
 * **Der Name steht in der Frage**, wie auf der Sicherungsseite: „Wirklich
 * entfernen?" beantwortet niemand verlässlich, wenn vier Zeilen untereinander
 * stehen und der Knopf an jeder gleich aussieht. Und hier wiegt es schwerer —
 * das Abonnement ist fort, die Sicherung ist alles, was bleibt.
 */
function entfernen(sicherung: { id: number; storage_name: string }): void {
  ask(
    'Sicherung entfernen',
    `Die Sicherung ${sicherung.storage_name} wird vom Datenträger gelöscht. `
      + 'Ihr Abonnement gibt es nicht mehr — danach ist dieser Stand fort.',
    () => { router.delete(`/backups/${sicherung.id}`) },
  )
}
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
              <th>Aktion</th>
            </tr>
          </thead>
          <tbody>
            <!--
              **Eine Zeile, die gerade entfernt wird, sagt es — und bietet
              nichts an.**

              Der Verweis fällt weg, weil ein Zurückspielen gegen eine Datei
              liefe, die unter ihm verschwindet; der Knopf fällt weg, weil ein
              zweiter Klick einen zweiten Vorgang für dieselbe Datei einreihte.
              Beides an **einer** Bedingung und nicht an zweien: Zwei
              Bedingungen über denselben Zustand laufen auseinander.
            -->
            <tr v-for="sicherung in props.orphaned" :key="sicherung.id">
              <td data-column="Abonnement" class="cell-name">
                <Link
                  v-if="!sicherung.running"
                  :href="`/backups/${sicherung.id}/restore`"
                  class="link"
                >
                  {{ sicherung.subscription_name }}
                </Link>
                <span v-else>{{ sicherung.subscription_name }}</span>
              </td>
              <td data-column="Stand" class="ident">{{ sicherung.storage_name }}</td>
              <td data-column="Angelegt">{{ sicherung.created_at }}</td>
              <td data-column="Aktion">
                <button
                  v-if="!sicherung.running"
                  type="button"
                  class="button small danger"
                  @click="entfernen(sicherung)"
                >
                  Entfernen
                </button>
                <span v-else>{{ sicherung.status_label }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      </Section>
    </div>
  </PanelLayout>
</template>
