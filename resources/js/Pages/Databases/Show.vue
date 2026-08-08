<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import Badge from '../../Components/Badge.vue'
import FormErrors from '../../Components/FormErrors.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'

interface User {
  id: number
  name: string
  host: string
  remote: boolean
  status: string
  status_label: string
}

interface DumpRow {
  id: number
  name: string
  kind: string
  status: string
  status_label: string
  usable: boolean
  bytes: number | null
  created_at: string | null
  last_error: string | null
}

interface Row {
  id: number
  name: string
  label: string
  status: string
  status_label: string
  collation: string
  size_mb: number | null
  size_measured_at: string | null
  subscription: string | null
  subscription_id: number | null
  orphaned: boolean
  users: User[]
}

const props = defineProps<{
  database: Row
  subscription: { id: number; name: string; prefix: string } | null
  can: { update: boolean; delete: boolean }

  /**
   * Das Passwort eines gerade angelegten oder zurückgesetzten Zugangs.
   *
   * **Es kommt aus der Sitzung und steht genau einmal da.** Beim nächsten
   * Seitenaufruf ist es fort — nicht ausgeblendet, sondern nicht mehr
   * vorhanden: Es wird nirgends gespeichert (docs/36 §4).
   */
  secret: { user: string; password: string } | null

  dumps: DumpRow[]
  import_limit_mb: number
}>()

const userForm = useForm({ label: '' })

/*
 * Die Rückfrage verlangt den Namen zum Abtippen.
 *
 * Dieselbe Form wie beim Rückbau eines Abonnements (docs/26 §6), und aus
 * demselben Grund: Ein einzelnes „Wirklich?" beantwortet man im Vorbeigehen.
 * Hier geht es um die Daten einer Anwendung, und es gibt keine Sicherung davor.
 */
const confirmation = ref('')

function remove(): void {
  if (confirmation.value !== props.database.name) return

  router.delete(`/databases/${props.database.id}`)
}

function removeUser(user: User): void {
  router.delete(`/databases/${props.database.id}/users/${user.id}`)
}

function resetPassword(user: User): void {
  router.post(`/databases/${props.database.id}/users/${user.id}/password`)
}

function exportDump(): void {
  router.post(`/databases/${props.database.id}/dumps`)
}

/*
 * Das Zurückspielen überschreibt Daten und fragt deshalb nach.
 *
 * Ein `confirm()` und keine Seite mit Eingabefeld: Anders als beim Entfernen
 * der Datenbank gibt es hier einen Weg zurück — die Sicherung, die man gerade
 * einspielt, bleibt liegen. Was verlorengeht, ist der Stand von jetzt.
 */
function restoreDump(dump: DumpRow): void {
  if (!confirm(`Die Sicherung ${dump.name} einspielen? Der aktuelle Stand von ${props.database.name} wird dabei überschrieben.`)) return

  router.post(`/databases/${props.database.id}/dumps/${dump.id}/restore`)
}

function removeDump(dump: DumpRow): void {
  router.delete(`/databases/${props.database.id}/dumps/${dump.id}`)
}

function bytes(value: number | null): string {
  if (value === null) return '—'
  if (value < 1024 * 1024) return `${Math.round(value / 1024)} KB`

  return `${(value / 1048576).toFixed(1)} MB`
}

function rang(status: string): 'ok' | 'warn' | 'critical' | 'neutral' {
  if (status === 'active' || status === 'ready') return 'ok'
  if (status === 'provisioning' || status === 'removing' || status === 'pending') return 'warn'
  if (status === 'locked' || status === 'failed') return 'critical'

  return 'neutral'
}

function size(): string {
  if (props.database.size_mb === null) return 'nicht gemessen'
  if (props.database.size_mb < 1024) return `${props.database.size_mb} MB`

  return `${(props.database.size_mb / 1024).toFixed(1)} GB`
}
</script>

<template>
  <Head :title="props.database.name" />

  <PanelLayout :title="props.database.name" :subline="props.subscription?.name ?? 'ohne Abonnement'">
    <template #breadcrumb>
      <Link href="/databases" class="link">Datenbanken</Link>
      <template v-if="props.subscription">
        ·
        <Link :href="`/subscriptions/${props.subscription.id}`" class="link">
          {{ props.subscription.name }}
        </Link>
      </template>
    </template>

    <!--
      Das Passwort, genau einmal.

      **Eine eigene Meldung und keine Zeile in der Tabelle.** Ein Wert, der
      neben zwölf anderen Zeilen steht, wird überscrollt — und der zweite
      Aufruf dieser Seite zeigt ihn nicht mehr, weil es ihn dann nicht mehr
      gibt. Deshalb steht hier auch, was das bedeutet, und nicht nur der Wert.
    -->
    <p v-if="props.secret" class="notice warn">
      <span>
        Zugang <span class="ident">{{ props.secret.user }}</span> angelegt. Das Passwort lautet
        <b class="ident">{{ props.secret.password }}</b> — es steht hier zum
        <b>einzigen Mal</b> und wird nirgends gespeichert. Wer es verliert,
        setzt unten ein neues.
      </span>
    </p>

    <p v-if="props.database.orphaned" class="notice critical">
      <span>
        Zu dieser Datenbank gibt es kein Abonnement mehr — sie ist der Rest
        eines Rückbaus, der nicht durchgelaufen ist. Das Schema liegt weiter auf
        dem Server. Aufgeräumt wird sie über
        <span class="ident">srvpanel db prune</span>.
      </span>
    </p>

    <FormErrors />

    <!--
      **Der Behälter trägt den Abstand, nicht der Bereich** (`SectionSpacingTest`).
      In Kontor hat ein Bereich keinen eigenen Aussenabstand: Bereiche stehen in
      einem Flexfluss, und die Spaltenlücke unterscheidet sich von der
      Zeilenlücke. Ohne `.sections` bekämen die drei Bereiche unten *gar keinen*
      Abstand — sie sähen nicht knapp aus, sondern kaputt, und keine einzelne
      Regel in app.css wäre dabei falsch.
    -->
    <div class="sections">
      <Section title="Datenbank">
        <!--
          **Eine Bezeichnungstabelle steht in keinem Rollbehälter.** Sie war
          hier zuerst in `<div class="scrolls">` — und damit hatte sie Raum,
          breiter zu werden als ihr Bereich: `p123456789_aaaaaaaaaaaaaaaa`
          schob sie bei 390px um 52px hinaus, statt umzubrechen. Genau dagegen
          steht `table.pairs td.ident { white-space: normal }` in app.css, und
          der Rollbehälter hat die Regel wirkungslos gemacht. Keine andere Seite
          in diesem Panel wickelt eine `pairs` in `scrolls`; die `stacks`
          darunter dagegen schon, und das ist richtig — dort kann man schieben.

          Gefunden beim Nachbau mit dem gebauten Stylesheet bei 390px, nicht von
          einem Test.
        -->
        <table class="pairs">
          <tbody>
            <tr>
              <th>Name auf dem Server</th>
              <td class="ident">{{ props.database.name }}</td>
            </tr>
            <tr>
              <th>Sortierung</th>
              <td class="ident">{{ props.database.collation }}</td>
            </tr>
            <tr>
              <th>Zustand</th>
              <td><Badge :kind="rang(props.database.status)">{{ props.database.status_label }}</Badge></td>
            </tr>
            <tr>
              <th>Belegt</th>
              <!-- „nicht gemessen" ist etwas anderes als „0 MB" — ohne den
                   Zeitpunkt daneben sähe eine drei Tage alte Zahl aus wie eine
                   Messung von vorhin (docs/26 §8). -->
              <td :class="props.database.size_mb === null ? 'quiet' : ''">
                {{ size() }}
                <span v-if="props.database.size_measured_at" class="quiet">
                  (gemessen {{ new Date(props.database.size_measured_at).toLocaleString('de-DE') }})
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </Section>

      <Section title="Zugänge">
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Benutzer</th><th>Herkunft</th><th>Zustand</th><th v-if="props.can.update">Aktion</th></tr>
            </thead>
            <tbody>
              <tr v-for="user in props.database.users" :key="user.id">
                <td data-column="Benutzer" class="ident name">{{ user.name }}</td>
                <td data-column="Herkunft" class="ident">{{ user.host }}</td>
                <td data-column="Zustand">
                  <Badge :kind="rang(user.status)">{{ user.status_label }}</Badge>
                </td>
                <td v-if="props.can.update" data-column="Aktion">
                  <div class="button-row">
                    <button type="button" class="button" @click="resetPassword(user)">
                      Neues Passwort
                    </button>
                    <button type="button" class="button danger" @click="removeUser(user)">
                      Entfernen
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="props.database.users.length === 0">
                <td :colspan="props.can.update ? 4 : 3" class="quiet">
                  Kein Zugang — in diese Datenbank kommt gerade niemand hinein.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <form
          v-if="props.can.update"
          class="form"
          @submit.prevent="userForm.post(`/databases/${props.database.id}/users`, { onSuccess: () => userForm.reset() })"
        >
          <label class="field">
            <span>Weiterer Zugang</span>
            <input v-model="userForm.label" type="text" placeholder="user2" autocomplete="off" required>
          </label>
          <p v-if="userForm.errors.label" class="error">{{ userForm.errors.label }}</p>
          <p class="hint">
            Heisst auf dem Server
            <span class="ident">{{ props.subscription?.prefix ?? '…' }}_{{ userForm.label || '…' }}</span>.
            Das Passwort wird erzeugt und genau einmal angezeigt.
          </p>

          <div class="button-row">
            <button type="submit" class="button" :disabled="userForm.processing">Zugang anlegen</button>
          </div>
        </form>
      </Section>

      <Section title="Sicherungen">
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Sicherung</th><th>Grösse</th><th>Zustand</th><th v-if="props.can.update">Aktion</th></tr>
            </thead>
            <tbody>
              <tr v-for="dump in props.dumps" :key="dump.id">
                <td data-column="Sicherung" class="ident name">{{ dump.name }}</td>
                <td data-column="Grösse">{{ bytes(dump.bytes) }}</td>
                <!--
                  `multiline` nur, wenn ein Grund dasteht: Unter 720px ist eine
                  Zelle eine Flexzeile mit Beschriftung links und Wert rechts,
                  und ein zweiter Wert darin würde die Marke zusammendrücken.
                  Die Klasse dreht die Zelle auf eine Spalte — dafür gibt es sie.
                -->
                <td data-column="Zustand" :class="{ multiline: !!dump.last_error }">
                  <Badge :kind="rang(dump.status)">{{ dump.status_label }}</Badge>
                  <!-- Der Grund steht unter dem Zustand und nicht nur im
                       Vorgang: Wer die Liste ansieht, fragt genau hier. -->
                  <span v-if="dump.last_error" class="quiet">{{ dump.last_error }}</span>
                </td>
                <td v-if="props.can.update" data-column="Aktion">
                  <div class="button-row">
                    <a v-if="dump.usable" :href="`/databases/${props.database.id}/dumps/${dump.id}`" class="button">
                      Herunterladen
                    </a>
                    <button v-if="dump.usable" type="button" class="button" @click="restoreDump(dump)">
                      Einspielen
                    </button>
                    <button type="button" class="button danger" @click="removeDump(dump)">
                      Entfernen
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="props.dumps.length === 0">
                <td :colspan="props.can.update ? 4 : 3" class="quiet">Noch keine Sicherung.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="hint">
          Eine Sicherung wird gepackt abgelegt und liegt ausserhalb des
          Verzeichnisses dieses Abonnements — sie ist über die Webseite nicht
          erreichbar. Hochgeladene Dateien dürfen bis
          {{ props.import_limit_mb }} MB gross sein.
        </p>

        <div v-if="props.can.update" class="button-row">
          <button type="button" class="button" :disabled="props.database.status !== 'active'" @click="exportDump">
            Jetzt sichern
          </button>
        </div>
      </Section>

      <Section v-if="props.can.delete" title="Entfernen">
        <p class="notice critical">
          <span>
            Die Datenbank und ihre Daten werden gelöscht. <b>Es gibt keine
            Sicherung davor</b> — sie kommt mit einer späteren Ausbaustufe.
            Zugänge, die an keiner weiteren Datenbank hängen, gehen mit.
          </span>
        </p>

        <form class="form" @submit.prevent="remove">
          <label class="field">
            <span>Zum Bestätigen den Namen eintippen</span>
            <input v-model="confirmation" type="text" autocomplete="off" :placeholder="props.database.name">
          </label>

          <div class="button-row">
            <button
              type="submit"
              class="button danger"
              :disabled="confirmation !== props.database.name || props.database.status !== 'active'"
            >
              Datenbank entfernen
            </button>
          </div>
        </form>
      </Section>
    </div>

  </PanelLayout>
</template>
