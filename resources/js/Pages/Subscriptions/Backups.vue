<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { computed, onMounted, onUnmounted, watch } from 'vue'
import Badge from '../../Components/Badge.vue'
import FormErrors from '../../Components/FormErrors.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'
import { formatBytes } from '../../bytes'
import { useConfirmation } from '../../Composables/useConfirmation'

const { ask } = useConfirmation()

interface BackupRow {
  id: number
  storage_name: string
  status: string
  status_label: string
  usable: boolean
  bytes: number | null
  files: number | null
  databases: number | null
  system_user: number | null
  last_error: string | null
  created_at: string | null

  /**
   * Ob sich diese Zeile gerade von selbst ändert.
   *
   * **Vom Server und nicht aus einem Vergleich hier.** Zwei Listen zeigen
   * Sicherungen, und beide brauchen denselben Takt; zwei Bedingungen über
   * dieselben Zustandsnamen wären zwei Fassungen derselben Regel.
   * `BackupStatus::running()` ist die eine Stelle.
   */
  running: boolean
}

const props = defineProps<{
  subscription: { id: number; name: string }
  backups: BackupRow[]

  /**
   * Was eine Sicherung **nicht** enthält, mit Grund je Verzeichnis.
   *
   * Sie kommt aus `Packer::SKIPPED` im Agenten und nicht aus einer Liste hier —
   * eine zweite Aufzählung wäre die Fassung, die veraltet.
   */
  skipped: Record<string, string>

  /**
   * Was der Betrachter hier darf.
   *
   * **Eigenes `can` und nicht die geteilte Ablage `abilities`:** Die führt die
   * Adminfähigkeiten und keine Policy über ein Modell. Diese Seite gehört dem
   * Kunden, die Wiederherstellung dem Betreiber — sie legt ein Abonnement an.
   */
  can: { restore: boolean; download: boolean }
}>()

function anlegen(): void {
  router.post(`/subscriptions/${props.subscription.id}/backups`)
}

/**
 * Wie oft nachgefragt wird, solange eine Sicherung läuft.
 *
 * Drei Sekunden, und die Zahl hat einen Grund: Eine Sicherung dauert Sekunden
 * bis Minuten, und der Betrachter steht davor und wartet. Ein langsamerer Takt
 * liesse ihn neu laden, und genau das soll er nicht müssen.
 */
const NACHFRAGE_MS = 3000

let takt: ReturnType<typeof setInterval> | undefined

/**
 * Läuft gerade eine Sicherung?
 *
 * **Am Zustand der Zeilen und nicht an einem eigenen Merker.** Ein Merker, den
 * {@link anlegen} setzt, wüsste nichts von einer Sicherung, die der nächtliche
 * Lauf angelegt hat oder ein zweiter Reiter — und er stünde nach einem
 * Neuladen auf falsch, während die Zeile `wird erstellt` sagt.
 *
 * **Und seit dem 18. September zählt das Entfernen mit.** Hier stand
 * `status === 'pending'`, und damit war nur das Anlegen verfolgt: Beim
 * Entfernen blieb die Zeile stehen, bis jemand von Hand neu lud (`docs/121 §9`,
 * Befund 4). Gefragt wird jetzt `running` — ein Wert, den der Server aus
 * `BackupStatus::running()` schickt, damit die andere Liste dieselbe Antwort
 * bekommt und nicht eine zweite.
 */
const laeuft = computed((): boolean => props.backups.some((zeile) => zeile.running))

/**
 * Nur die Liste, und nur solange sich etwas ändern kann.
 *
 * **Der Befund, der das ausgelöst hat.** Im Abnahmelauf von P8 (17. September
 * 2026) blieb die Zeile nach „Jetzt sichern" auf dem Stand des Seitenaufbaus
 * stehen — der Vorgang war längst fehlgeschlagen, und die Seite zeigte
 * unverändert `wird erstellt`. {@link anlegen} leitet auf dieselbe Seite
 * zurück, und die hatte keinen Takt: Von allen Seiten dieses Panels fragt nur
 * die Übersicht nach.
 *
 * > **Eine Anzeige, die den Zustand vor der Änderung zeigt, verleitet zu der
 * > Handlung, die die Änderung zurücknimmt.** Derselbe Satz wie bei
 * > `form.reset()` auf der Zugangsseite (`docs/84`) — dort war es eine
 * > gelöschte Zeile, hier ein Vorgang, den man ein zweites Mal auslöst.
 *
 * Der Hinweis „Ihr Fortschritt steht unter Vorgänge" bleibt und wird dadurch
 * nicht überflüssig: Er nennt den Ort, an dem die Ausgabe des Agenten steht.
 * Was er nicht ersetzt, ist die Auskunft auf der Seite, auf der man steht.
 */
function nachsehen(): void {
  router.reload({ only: ['backups'] })
}

/**
 * Den Takt an den Zustand hängen — und den alten vorher anhalten.
 *
 * `setInterval` kennt keine Änderung; ohne das Anhalten liefen nach zwei
 * Wechseln drei. Dieselbe Stelle und derselbe Grund wie in `Overview.vue`.
 */
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
 * Entfernen — mit Rückfrage, weil es nicht zurückzunehmen ist.
 *
 * **Der Name steht in der Frage.** „Wirklich entfernen?" beantwortet niemand
 * verlässlich, wenn fünf Zeilen untereinander stehen und der Knopf an jeder
 * gleich aussieht.
 */
function entfernen(backup: BackupRow): void {
  ask(
    'Sicherung entfernen',
    `Die Sicherung ${backup.storage_name} wird vom Datenträger gelöscht. Das lässt sich nicht zurücknehmen.`,
    () => { router.delete(`/subscriptions/${props.subscription.id}/backups/${backup.id}`) },
  )
}

/**
 * Die Farbe zum Zustand.
 *
 * **Jeder Zustand steht hier beim Namen**, und der Rückfall auf `neutral` ist
 * der Fall „kenne ich nicht" und nicht der Sammeltopf. Käme ein fünfter dazu
 * und stünde hier nicht, bekäme er eine graue Marke — richtig aussehend und
 * falsch.
 */
function rang(status: string): 'ok' | 'warn' | 'critical' | 'neutral' {
  if (status === 'ready') return 'ok'
  if (status === 'pending') return 'warn'
  if (status === 'removing') return 'warn'
  if (status === 'failed') return 'critical'

  return 'neutral'
}

/**
 * Die Grösse — und `—` statt `0 B`, solange sie niemand kennt.
 *
 * Eine laufende Sicherung hat noch keine; `0 B` wäre eine Zahl, wo eine
 * Abwesenheit steht.
 *
 * > **Eine Anzeige, die zwei verschiedene Zustände gleich aussehen lässt,
 * > behauptet etwas, das sie nicht weiss.**
 */
function groesse(value: number | null): string {
  return value === null ? '—' : formatBytes(value)
}

/**
 * Was drin ist, in einem Satz.
 *
 * **Die Einzahl wird am Wert entschieden und nicht am Wort** — dieselbe Regel,
 * die `CountedNounTest` für PHP hält. „1 Dateien" liest sich wie ein Fehler,
 * und bei einem Abonnement mit genau einer Datenbank steht es sonst da.
 */
function inhalt(backup: BackupRow): string {
  // Kein `null`-Zweig: Die Vorlage ruft diese Funktion nur, wenn `files`
  // dasteht. Ein Rückfall auf `—` wäre eine Zeile, die nie an die Reihe kommt.
  const dateien = backup.files === 1 ? '1 Datei' : `${backup.files} Dateien`

  if (backup.databases === null || backup.databases === 0) return dateien

  const datenbanken = backup.databases === 1 ? '1 Datenbank' : `${backup.databases} Datenbanken`

  return `${dateien}, ${datenbanken}`
}
</script>

<template>
  <Head title="Sicherungen" />

  <PanelLayout title="Sicherungen" :subline="props.subscription.name">
    <FormErrors />

    <!--
      **Der Behälter ist nicht Zierat.** Ein `<Section>` ohne ihn bekommt keinen
      Abstand zum nächsten — nicht zu wenig, sondern gar keinen; das `gap` sitzt
      am Raster `.sections` und nicht am Bereich selbst.
    -->
    <div class="sections">
      <!--
        **Zwei Zeitpunkte in einer Zeile, und sie gehen um Stunden auseinander.**

        Befund 6 des Nachlaufs zu P8 (`docs/121 §9`), gemessen am 18. September
        2026 auf `cloudsrv24`: Der Ablagename trägt `…-20260918-103020-…`, die
        Spalte daneben sagt `12:30:20`. Beide sind für sich richtig —
        `Backups.php` baut den Namen mit `gmdate()`, also UTC, und die Spalte
        geht über `Clock` in die eingestellte Zone. Nebeneinander sind sie eine
        widersprüchliche Auskunft.

        > **Dieselbe Grösse in zwei Fassungen anzuzeigen ist keine doppelte
        > Auskunft, sondern eine widersprüchliche.** (`docs/91` Befund 5)

        **Beide bleiben, und ein Satz sagt, welcher welcher ist.** Die Spalte
        zu streichen nähme einen geschlossenen Befund zurück — sie gibt es,
        weil den Zeitstempel im Namen niemand liest (gemeldet am 11. August
        2026 an den Dumps). Den Namen zu kürzen nähme dem Betreiber das, was er
        auf dem Server in ein `ls` tippt. Und eine Zonenangabe in der
        Spaltenkopfzeile wäre eine Konvention in genau einer von siebzehn
        Tabellen mit einer Zeitspalte.

        Das Panel hat die Antwort ohnehin schon: `/settings/general` zeigt
        dieselbe Zeit zweimal — „Gespeichert … UTC" und „Angezeigt …" —, jede
        mit ihrem Namen.
      -->
      <Section title="Sicherungen dieses Abonnements" note="Der Ablagename trägt den Zeitpunkt in UTC; die Spalte Erstellt zeigt ihn in der eingestellten Zone." full>
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th>Sicherung</th>
                <th>Erstellt</th>
                <th>Grösse</th>
                <th>Zustand</th>
                <th>Aktion</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="backup in props.backups" :key="backup.id">
                <!--
                  **Was drin ist, steht unter dem Namen und nicht in einer
                  eigenen Spalte.** Gemessen am 16. September: Als sechste
                  Spalte kostete „Inhalt" 234 px, und die Tabelle schob ihre
                  Knöpfe bei 1440 px aus dem Bild. `multiline` dreht die Zelle
                  auf eine Spalte — dafür gibt es die Klasse.

                  **Und `multiline` nur, wenn die zweite Zeile etwas sagt.** Der
                  erste Wurf zeigte bei einer laufenden und einer gescheiterten
                  Sicherung einen nackten Gedankenstrich. Keine Zahl hat sich
                  darüber beschwert; gesehen hat es das Bild.

                  > **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl —
                  > nur einen Betrachter.**
                -->
                <td data-column="Sicherung" class="ident name" :class="{ multiline: backup.files !== null }">
                  {{ backup.storage_name }}
                  <span v-if="backup.files !== null" class="quiet">{{ inhalt(backup) }}</span>
                </td>

                <!--
                  **Der Zeitstempel steckt zwar im Namen** — `…-20260916-113045-…`
                  —, aber als Teil einer Kennung liest ihn niemand. Derselbe
                  Befund wie bei den Dumps am 11. August 2026, gemeldet vom
                  Betreiber.

                  In der eingestellten Anzeigezone; `Clock::display()` ist die
                  eine Stelle, die aus UTC eine Anzeige macht (`docs/40`).
                -->
                <td data-column="Erstellt">{{ backup.created_at ?? '—' }}</td>
                <td data-column="Grösse">{{ groesse(backup.bytes) }}</td>

                <!--
                  `multiline` nur, wenn ein Grund dasteht: Unter 720 px ist eine
                  Zelle eine Flexzeile mit Beschriftung links und Wert rechts, und
                  ein zweiter Wert darin drückte die Marke zusammen.
                -->
                <td data-column="Zustand" :class="{ multiline: !!backup.last_error }">
                  <Badge :kind="rang(backup.status)">{{ backup.status_label }}</Badge>
                  <span v-if="backup.last_error" class="quiet">{{ backup.last_error }}</span>
                </td>

                <td data-column="Aktion">
                  <div class="button-row">
                    <!--
                      **`can.download` und nicht `backup.usable` allein.** Seit
                      die Sicherung den privaten Schlüssel eines hochgeladenen
                      Zertifikats trägt, ist die Route enger als die Seite: Sie
                      lässt den Betreiber und den Kunden durch, den
                      Administrator nicht.
                    -->
                    <a
                      v-if="backup.usable && props.can.download"
                      :href="`/subscriptions/${props.subscription.id}/backups/${backup.id}/download`"
                      class="button"
                    >
                      Herunterladen
                    </a>

                    <!--
                      **Der Weg zurück steht an der Sicherung** — dort sucht ihn,
                      wer ihn braucht. Er hängt an `can.restore` und nicht am
                      Kontotyp: Die Route trägt `can:create,Subscription`, und ein
                      Knopf, der einen 403 gibt, ist schlimmer als keiner.
                    -->
                    <Link
                      v-if="backup.usable && props.can.restore"
                      :href="`/backups/${backup.id}/restore`"
                      class="button"
                    >
                      Zurückspielen
                    </Link>
                    <button type="button" class="button danger" @click="entfernen(backup)">
                      Entfernen
                    </button>
                  </div>
                </td>
              </tr>

              <tr v-if="props.backups.length === 0">
                <td colspan="5" class="quiet">Noch keine Sicherung.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="hint">
          Eine Sicherung enthält die Dateien dieses Abonnements und den Inhalt
          seiner Datenbanken. Sie liegt ausserhalb des Verzeichnisses und ist über
          die Webseite nicht erreichbar.
        </p>

        <div class="button-row">
          <button type="button" class="button" @click="anlegen">Jetzt sichern</button>
        </div>
      </Section>

      <!--
        **Was fehlt, steht auf der Seite und nicht in einer Fussnote.** Ein
        Archiv, das stillschweigend weniger enthält, ist das Problem — und wer
        später etwas vermisst, sucht zuerst hier.
      -->
      <Section title="Was nicht mitgesichert wird" full>
        <!--
          **`stacks` und nicht `pairs`, und das ist im Bild entschieden
          worden.** Als Paar-Tabelle stand der Grund bei 390 px rechts neben dem
          Verzeichnisnamen und war abgeschnitten — „Protokolle rotieren und
          werden nicht zurückge…". Gemessen 44 px Roller, und der Satz war
          unlesbar.

          `docs/24 §5` sagt es: `.pairs` ist für ein Paar aus Beschriftung und
          **Wert**; was man Zeile für Zeile liest, ist `.stacks`. Ein Grund ist
          ein Satz und kein Wert.

          > **Ein Format, das für Bezeichner reicht, reicht nicht für Werte.**
        -->
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Verzeichnis</th><th>Grund</th></tr>
            </thead>
            <tbody>
              <tr v-for="(grund, verzeichnis) in props.skipped" :key="verzeichnis">
                <td data-column="Verzeichnis" class="ident">{{ verzeichnis }}</td>
                <td data-column="Grund">{{ grund }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="hint">
          Datenbankpasswörter werden nicht gesichert — dieses Panel hält sie
          nicht. Nach einer Wiederherstellung bekommt jeder Zugang ein neues.
        </p>
      </Section>
    </div>

  </PanelLayout>
</template>
