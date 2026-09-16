<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
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
  can: { restore: boolean }
}>()

function anlegen(): void {
  router.post(`/subscriptions/${props.subscription.id}/backups`)
}

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

function rang(status: string): 'ok' | 'warn' | 'critical' | 'neutral' {
  if (status === 'ready') return 'ok'
  if (status === 'pending') return 'warn'
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
      <Section title="Sicherungen dieses Abonnements" full>
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
                    <a
                      v-if="backup.usable"
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
