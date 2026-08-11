<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import Badge from '../../Components/Badge.vue'
import FormErrors from '../../Components/FormErrors.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'
import { formatBytes } from '../../bytes'

/** Ein Netz, aus dem ein PostgreSQL-Zugang hereindarf (docs/38 §14.3). */
interface Network {
  id: number
  cidr: string
}

interface User {
  id: number
  name: string
  host: string
  remote: boolean
  status: string
  status_label: string

  /** Bei MariaDB immer leer — dort steht die Herkunft im Benutzernamen. */
  networks: Network[]
}

interface DumpRow {
  id: number
  name: string
  kind_label: string | null
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
  engine: string
  engine_label: string

  /** Ob der Kunde dieses System über 127.0.0.1 erreicht statt über den Socket. */
  over_tcp: boolean
  collation: string | null
  size_bytes: number | null
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

  /* Die Zugänge des Abonnements, die diese Datenbank nicht erreichen. */
  unlinked: { id: number; name: string; host: string }[]

  dumps: DumpRow[]

  /**
   * Ob der Datenbankserver auf einer erreichbaren Adresse horcht.
   *
   * **Die Auskunft kommt vom Server und nicht aus einer Einstellung.** Sie
   * entscheidet, ob das Feld für eine fremde Adresse überhaupt angeboten wird
   * (docs/36 §12) — und wenn nicht, steht der Grund daneben statt nichts.
   */
  remote: { possible: boolean; bind_address: string | null; reason: string | null }

  /* Wie gross eine hochgeladene Sicherung sein darf — aus ImportLimit. */
  import_limit: string
}>()

const userForm = useForm({ label: '', host: '' })

/*
 * Das Hochladen einer mitgebrachten Sicherung.
 *
 * **`forceFormData`, weil eine Datei mitgeht.** Ohne das schickt Inertia JSON,
 * und die Datei käme als leeres Objekt an — ein Fehler, den man erst am Server
 * sieht.
 */
const importForm = useForm<{ dump: File | null }>({ dump: null })

function chooseDump(event: Event): void {
  const input = event.target as HTMLInputElement
  importForm.dump = input.files?.[0] ?? null
}

function uploadDump(): void {
  importForm.post(`/databases/${props.database.id}/dumps/import`, { forceFormData: true })
}

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

/*
 * Einen vorhandenen Zugang verbinden — die Auswahl steht nur da, wenn es etwas
 * auszuwählen gibt.
 *
 * **Eine Auswahlliste und keine Kästchenspalte.** Gemessen mit dem gebauten
 * Stylesheet bei 390px: Eine Spalte über *alle* Zugänge macht diesen Abschnitt
 * 1109px hoch statt 657px — und stellt neben jeden unbeteiligten Zugang einen
 * Knopf „Entfernen", der ihn ganz löscht, obwohl diese Seite von einer
 * Datenbank handelt (docs/36 §22.3o).
 */
const linkForm = useForm({ granted: true })
const chosenUser = ref<number>(props.unlinked[0]?.id ?? 0)

function link(): void {
  linkForm.put(`/databases/${props.database.id}/users/${chosenUser.value}`, { preserveScroll: true })
}

/*
 * Und die Gegenrichtung mit Rückfrage.
 *
 * Ein entzogenes Recht sperrt eine laufende Anwendung aus, und zwar sofort.
 * Der Unterschied zum „Entfernen" daneben steht in der Frage: Der Zugang
 * bleibt bestehen, nur diese Datenbank erreicht er nicht mehr.
 */
function revoke(user: User): void {
  if (!confirm(`${user.name} den Zugriff auf ${props.database.name} entziehen? Der Zugang bleibt bestehen und erreicht diese Datenbank danach nicht mehr.`)) return

  router.put(`/databases/${props.database.id}/users/${user.id}`, { granted: false }, { preserveScroll: true })
}

function removeUser(user: User): void {
  router.delete(`/databases/${props.database.id}/users/${user.id}`)
}

/*
 * Die Netze eines PostgreSQL-Zugangs.
 *
 * **Ein Formular unter der Tabelle, und „Netz eintragen" wählt den Zugang aus.**
 * Der erste Entwurf legte je Zeile ein eigenes Formular in die Aktionsspalte —
 * die Zuordnung wäre damit ohne ein zweites Feld klar gewesen. Bei 390px ist
 * eine gestapelte Zelle aber eine Flexzeile aus Beschriftung und Inhalt: Das
 * Eingabefeld bekam 180px, und eine Fehlermeldung stand einwortweise über
 * zwanzig Zeilen (gesehen in der Aufnahme, `docs/38 §14`).
 *
 * Die Zuordnung trägt jetzt der Name im Kopf des Formulars — dieselbe Form wie
 * „Vorhandenen Zugang verbinden" darüber.
 */
const networkForm = useForm({ cidr: '' })
const editing = ref<number | null>(null)

/**
 * Der Zugang, für den das Formular gerade offensteht — oder `null`.
 *
 * **Gerechnet und nicht mitgeführt.** Ein zweites `ref` mit dem ganzen Objekt
 * wäre eine Abschrift, die nach dem nächsten Seitenaufruf auf einen Zugang
 * zeigt, den es nicht mehr gibt.
 */
const chosenForNetwork = computed<User | null>(
  () => props.database.users.find((user) => user.id === editing.value) ?? null,
)

function addNetwork(user: User): void {
  networkForm.post(`/databases/${props.database.id}/users/${user.id}/networks`, {
    preserveScroll: true,
    onSuccess: () => {
      networkForm.reset()
      editing.value = null
    },
  })
}

/*
 * Und die Gegenrichtung mit Rückfrage.
 *
 * Anders als beim Eintragen sperrt das eine laufende Anwendung aus, und zwar
 * sofort: Der verwaltete Block wird beim nächsten Reload ohne diese Zeile
 * gelesen. Dieselbe Überlegung wie bei `revoke()` darüber.
 */
function removeNetwork(user: User, network: Network): void {
  if (!confirm(`${network.cidr} für ${user.name} zurücknehmen? Eine Anwendung, die von dort verbindet, kommt danach nicht mehr herein.`)) return

  router.delete(`/databases/${props.database.id}/users/${user.id}/networks/${network.id}`, { preserveScroll: true })
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
  if (!confirm(`Die Sicherung ${dump.name} zurückspielen? Der aktuelle Stand von ${props.database.name} wird dabei überschrieben.`)) return

  router.post(`/databases/${props.database.id}/dumps/${dump.id}/restore`)
}

function removeDump(dump: DumpRow): void {
  router.delete(`/databases/${props.database.id}/dumps/${dump.id}`)
}

/*
 * Die Grösse einer Sicherung — dieselbe Fassung wie die der Datenbank.
 *
 * Hier stand eine dritte Rechnung mit denselben Faktoren, und sie war schon
 * feiner als die der Datenbank darüber: Sie kannte KB. Genau daran sieht man,
 * warum zwei Fassungen einer Regel keine zwei Fassungen bleiben — sie driften,
 * und welche die bessere ist, merkt man erst, wenn jemand die schlechtere liest
 * (docs/36 §22.3j).
 */
function bytes(value: number | null): string {
  if (value === null) return '—'

  return formatBytes(value)
}

function rang(status: string): 'ok' | 'warn' | 'critical' | 'neutral' {
  if (status === 'active' || status === 'ready') return 'ok'
  if (status === 'provisioning' || status === 'removing' || status === 'pending') return 'warn'
  if (status === 'locked' || status === 'failed') return 'critical'

  return 'neutral'
}

function size(): string {
  if (props.database.size_bytes === null) return 'nicht gemessen'

  return formatBytes(props.database.size_bytes)
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
        <span class="ident">srvpanel db --prune</span>.
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
        <!--
          **Die Beschriftung ist ein `td.quiet` und kein `th`.** Hier stand ein
          `<th>` — als einzige Paar-Tabelle des Panels —, und auf 390px war das
          sichtbar falsch: Die schmale Fläche macht aus jeder Zeile eine
          Flexzeile und setzt dafür `table.pairs td` zurück. Ein `th` fällt
          nicht darunter, behielt also seinen Rand aus der Tabellengestaltung —
          und der ist so breit wie die Beschriftung. Unter jeder Zeile standen
          damit zwei Striche verschiedener Länge, versetzt gegeneinander.

          Ein `th` wäre für eine Zeilenbeschriftung das genauere Markup. Aber
          zehn andere Paar-Tabellen schreiben `td.quiet`, und zwei Formen für
          dieselbe Sache heissen: Eine wird gepflegt und die andere nicht. Diese
          hier war die andere. `MobileLayoutTest` besteht jetzt darauf.
        -->
        <table class="pairs">
          <tbody>
            <tr>
              <td class="quiet">Name auf dem Server</td>
              <td class="right ident">{{ props.database.name }}</td>
            </tr>
            <tr>
              <td class="quiet">System</td>
              <td class="right"><Badge kind="neutral">{{ props.database.engine_label }}</Badge></td>
            </tr>

            <!-- Die Sortierung steht für beide Systeme da — seit dem
                 10. August 2026 auch für PostgreSQL.

                 Vorher fehlte die Zeile dort, und das war richtig: Es hätte der
                 Vorgabewert aus P5 dringestanden, eine Angabe über eine
                 Datenbank, die ihn nie gesehen hat. Seit der Agent das
                 Gebietsschema beim Cluster erfragt und zurückmeldet, ist der
                 Wert gemessen — und dann ist Verschweigen schlechter als
                 Zeigen.

                 Die Bedingung bleibt, aber sie hängt an der Angabe und nicht am
                 System: Wo nichts steht, steht keine Zeile. -->
            <tr v-if="props.database.collation !== null">
              <td class="quiet">Sortierung</td>
              <td class="right ident">{{ props.database.collation }}</td>
            </tr>
            <tr>
              <td class="quiet">Zustand</td>
              <td class="right"><Badge :kind="rang(props.database.status)">{{ props.database.status_label }}</Badge></td>
            </tr>
            <tr>
              <td class="quiet">Belegt</td>
              <!-- „nicht gemessen" ist etwas anderes als „0 MB" — ohne den
                   Zeitpunkt daneben sähe eine drei Tage alte Zahl aus wie eine
                   Messung von vorhin (docs/26 §8). -->
              <td class="right" :class="props.database.size_bytes === null ? 'quiet' : ''">
                {{ size() }}
                <span v-if="props.database.size_measured_at" class="quiet">
                  (gemessen {{ new Date(props.database.size_measured_at).toLocaleString('de-DE') }})
                </span>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- **Der Satz steht hier und nicht in der Dokumentation**, weil er
             beim Eintragen der Verbindungsdaten gebraucht wird: Eine Anwendung,
             die auf `localhost` als Socket verbindet, bekommt bei PostgreSQL
             „Peer authentication failed" — eine Meldung, die auf eine
             Authentifizierungsmethode zeigt und nicht auf den Wirt. -->
        <p v-if="props.database.over_tcp" class="hint">
          Anwendungen auf diesem Server verbinden sich über
          <span class="ident">127.0.0.1</span> und den Port des Servers, nicht
          über einen Socket. Erweiterungen — <span class="ident">postgis</span>,
          <span class="ident">pgcrypto</span> und andere — richtet der Betreiber
          ein; ein Zugang dieses Abonnements darf sie nicht selbst anlegen.
        </p>
      </Section>

      <!--
        **`full` und nicht der Grundriss.** Diese Tabelle trägt eine
        Aktionsspalte mit drei Knöpfen; ihre Breite ist damit die Summe der
        Beschriftungen und nicht eine Frage der Schriftgrösse. Gemessen am
        gebauten Stylesheet braucht sie 755px, „Sicherungen" darunter 923px —
        ein Bereich im Grundriss bekommt bei 1440px aber 548px, und
        `.scrolls > table` hält die Tabelle auf `max-content`. Die Knöpfe
        standen deshalb ausserhalb des Bereichs, und man musste waagerecht
        schieben, um sie zu treffen (docs/36 §22.3s).
      -->
      <Section title="Zugänge" full>
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Benutzer</th><th>Herkunft</th><th>Zustand</th><th v-if="props.can.update">Aktion</th></tr>
            </thead>
            <tbody>
              <tr v-for="user in props.database.users" :key="user.id">
                <td data-column="Benutzer" class="ident name">{{ user.name }}</td>

                <!--
                  **Bei PostgreSQL eine Liste, bei MariaDB ein Wert** — und die
                  Unterscheidung hängt an `over_tcp` und nicht am Namen des
                  Systems. Ein Vergleich mit dem Wert des Systems wäre hier eine
                  Zeichenkette aus dem Enum, und `DatabaseEngineTest` weist sie
                  ab; der Grund dahinter ist derselbe wie bei der Sortierung
                  weiter oben.

                  Der Satz stand hier zuerst mit dem Wert als Beispiel darin —
                  und der Wächter liest Kommentare mit, zu Recht: Er kann nicht
                  wissen, ob eine Zeichenkette gemeint oder zitiert ist.
                  Dieselbe Stelle hat `DatabaseController::row()` schon einmal
                  erwischt.

                  Jedes Netz steht auf einer eigenen Zeile und nicht mit Komma
                  aneinander: `198.51.100.0/24` und `2001:db8::/32` in einer
                  Zeile sind bei 390px genau die Sorte Kennung im Fliesstext,
                  die `v0.4.0-rc.4` gekostet hat.
                -->
                <!--
                  **`multiline`, sobald mehr als ein Wert darin steht.** Bei
                  390px stellt eine gestapelte Zelle Beschriftung und Inhalt
                  *nebeneinander*; zwei Netze mit ihren Knöpfen landen damit
                  rechts in einer Spalte von 180px und werden abgeschnitten —
                  gemessen am gebauten Stylesheet, und der Überlauf steht dabei
                  auf 0, weil er innerhalb von `.scrolls` passiert und nicht am
                  Dokument. `multiline` stellt beides untereinander.
                -->
                <td
                  data-column="Herkunft"
                  class="ident"
                  :class="{ multiline: props.database.over_tcp }"
                >
                  <template v-if="props.database.over_tcp">
                    <div v-for="net in user.networks" :key="net.id" class="button-row">
                      <span class="ident">{{ net.cidr }}</span>
                      <button
                        v-if="props.can.update"
                        type="button"
                        class="button"
                        @click="removeNetwork(user, net)"
                      >
                        Zurücknehmen
                      </button>
                    </div>
                    <span v-if="user.networks.length === 0" class="quiet">nur von diesem Server</span>
                  </template>
                  <template v-else>{{ user.host }}</template>
                </td>

                <td data-column="Zustand">
                  <Badge :kind="rang(user.status)">{{ user.status_label }}</Badge>
                </td>
                <td v-if="props.can.update" data-column="Aktion">
                  <div class="button-row">
                    <button type="button" class="button" @click="resetPassword(user)">
                      Neues Passwort
                    </button>
                    <button
                      v-if="props.database.over_tcp && props.remote.possible"
                      type="button"
                      class="button"
                      @click="editing = editing === user.id ? null : user.id"
                    >
                      Netz eintragen
                    </button>
                    <button type="button" class="button" @click="revoke(user)">
                      Zugriff entziehen
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

        <!--
          **Der Satz, den ein Kunde braucht, der P5 kennt** (docs/38 §14.3).

          In MariaDB sind `p1001_web@localhost` und `p1001_web@203.0.113.5`
          zwei Benutzer mit zwei Passwörtern; wer das eine verliert, verliert
          nicht das andere. In PostgreSQL ist es eine Rolle, ein Passwort,
          mehrere erlaubte Netze — und wer das Gegenteil annimmt, setzt ein
          Passwort zurück und sperrt dabei seine zweite Anwendung aus.

          Er steht bei den Zugängen und nicht in der Dokumentation, weil er
          genau dann gebraucht wird, wenn jemand das zweite Netz einträgt.
        -->
        <p v-if="props.database.over_tcp && props.remote.possible" class="hint">
          Ein Zugang ist hier <b>eine Rolle mit einem Passwort</b> und mehreren
          erlaubten Netzen — anders als in MariaDB, wo zwei Herkünfte zwei
          Zugänge mit eigenen Passwörtern sind. Ein neues Passwort gilt deshalb
          für alle Netze zugleich. Eintragen lässt sich eine Adresse
          (<span class="ident">203.0.113.5/32</span>) oder ein Netz
          (<span class="ident">198.51.100.0/24</span>);
          <span class="ident">0.0.0.0/0</span> wird abgewiesen.
          <b>Die Beschränkung gilt im Datenbankserver und nicht im Paketfilter.</b>
        </p>
        <p v-else-if="props.database.over_tcp" class="hint">{{ props.remote.reason }}</p>

        <!--
          **Das Formular steht unter der Tabelle und nicht in ihrer Zelle.**

          Der erste Entwurf legte es in die Aktionsspalte der Zeile, zu der es
          gehört — die Zuordnung war damit ohne ein zweites Auswahlfeld klar.
          Bei 390px ist eine gestapelte Zelle eine Flexzeile aus Beschriftung
          und Inhalt: Das Feld bekam 180px, und die Fehlermeldung stand
          einwortweise untereinander über zwanzig Zeilen. Gesehen in der
          Aufnahme, nicht im Test — `scrollWidth - clientWidth` stand dabei auf
          0, weil der Überlauf innerhalb von `.scrolls` passiert.

          Unter der Tabelle steht schon „Vorhandenen Zugang verbinden", und die
          Zuordnung trägt hier der Name im Kopf des Formulars.
        -->
        <form
          v-if="chosenForNetwork !== null"
          class="form"
          @submit.prevent="addNetwork(chosenForNetwork)"
        >
          <label class="field">
            <span>Erreichbar von — für <span class="ident">{{ chosenForNetwork.name }}</span></span>
            <input
              v-model="networkForm.cidr"
              type="text"
              placeholder="203.0.113.5/32"
              autocomplete="off"
              required
            >
          </label>
          <p v-if="networkForm.errors.cidr" class="error">{{ networkForm.errors.cidr }}</p>
          <p class="hint">
            Eine Adresse oder ein Netz in der Schreibweise von PostgreSQL. Ohne Präfixlänge
            wird <span class="ident">/32</span> bzw. <span class="ident">/128</span> ergänzt.
          </p>

          <div class="button-row">
            <button type="submit" class="button" :disabled="networkForm.processing">
              Eintragen
            </button>
            <button type="button" class="button" @click="editing = null">
              Abbrechen
            </button>
          </div>
        </form>

        <!--
          Verbinden steht vor Anlegen: Wer schon einen Zugang hat, soll ihn
          nehmen und keinen zweiten erzeugen — das Anlegen weist einen
          vergebenen Namen inzwischen ab (docs/36 §22.3n).
        -->
        <form
          v-if="props.can.update && props.unlinked.length > 0"
          class="form"
          @submit.prevent="link()"
        >
          <label class="field">
            <span>Vorhandenen Zugang verbinden</span>
            <select v-model="chosenUser">
              <option v-for="user in props.unlinked" :key="user.id" :value="user.id">
                {{ user.name }}@{{ user.host }}
              </option>
            </select>
          </label>
          <p class="hint">
            Der Zugang bekommt alle Rechte auf
            <span class="ident">{{ props.database.name }}</span> und behält sein Passwort.
          </p>

          <div class="button-row">
            <button type="submit" class="button" :disabled="linkForm.processing">Verbinden</button>
          </div>
        </form>

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

          <!--
            **Der Wirt steht nur da, wenn er etwas bewirkt.** Ein Feld für eine
            fremde Adresse an einem Server, der nur lokal horcht, verspricht
            einen Zugang, der nie zustande kommt (docs/36 §12). Ausgeblendet
            wird es trotzdem nicht: Darunter steht, warum es fehlt und wer es
            einschalten kann — ein Feld, das ohne Erklärung verschwindet, sieht
            aus wie ein Fehler.
          -->
          <label v-if="props.remote.possible && !props.database.over_tcp" class="field">
            <span>Erreichbar von</span>
            <input v-model="userForm.host" type="text" placeholder="localhost" autocomplete="off">
          </label>
          <p v-if="userForm.errors.host" class="error">{{ userForm.errors.host }}</p>

          <!--
            **Bei PostgreSQL steht hier nichts, und das ist kein Weglassen.**
            Der Wirt gehört dort nicht zum Zugang, sondern zu einer Zeile in
            `pg_hba.conf`, die es erst geben kann, wenn die Rolle da ist und
            eine Datenbank erreicht. Ein Feld im Anlegeformular verspräche das
            Gegenteil — der Weg führt über „Netz eintragen" in der Zeile des
            fertigen Zugangs.
          -->
          <p v-if="props.remote.possible && !props.database.over_tcp" class="hint">
            Leer oder <span class="ident">localhost</span> für den Zugriff vom Server selbst.
            Sonst eine IP-Adresse oder ein Netz in der Schreibweise von MariaDB
            (<span class="ident">203.0.113.0/255.255.255.0</span>). Zwei Adressen sind zwei
            Zugänge mit eigenen Passwörtern, und <span class="ident">%</span> wird abgewiesen.
            <b>Die Beschränkung gilt in MariaDB und nicht im Paketfilter.</b>
          </p>
          <p v-else-if="!props.database.over_tcp" class="hint">{{ props.remote.reason }}</p>

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

      <Section title="Sicherungen" full>
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Sicherung</th><th>Erstellt</th><th>Grösse</th><th>Zustand</th><th v-if="props.can.update">Aktion</th></tr>
            </thead>
            <tbody>
              <tr v-for="dump in props.dumps" :key="dump.id">
                <td data-column="Sicherung" class="ident name">
                  {{ dump.name }}
                  <!--
                    **Woher sie kommt, steht an ihr.** Eine mitgebrachte
                    Sicherung ist etwas anderes als eine, die dieser Server
                    geschrieben hat: Beim Zurückspielen wird die Datenbank
                    geleert, und wer die beiden nicht unterscheiden kann, trifft
                    die Wahl blind (docs/36 §22.3u).
                  -->
                  <Badge v-if="dump.kind_label" kind="neutral">{{ dump.kind_label }}</Badge>
                </td>
                <!--
                  **Der Zeitstempel stand im Payload und nirgends auf der
                  Seite.** Im Namen steckt er zwar — `…-20260811-093136-…` —,
                  aber als Teil einer Kennung liest ihn niemand, und zwei
                  Sicherungen desselben Tages sind daran nicht zu
                  unterscheiden. Gemeldet vom Betreiber am 11. August 2026.

                  In UTC wie jede Zeit dieses Panels; `docs/40` stellt das für
                  alle Stellen zugleich um.
                -->
                <td data-column="Erstellt">{{ dump.created_at ?? '—' }}</td>
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
                      Zurückspielen
                    </button>
                    <button type="button" class="button danger" @click="removeDump(dump)">
                      Entfernen
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="props.dumps.length === 0">
                <td :colspan="props.can.update ? 5 : 4" class="quiet">Noch keine Sicherung.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="hint">
          Eine Sicherung wird gepackt abgelegt und liegt ausserhalb des
          Verzeichnisses dieses Abonnements — sie ist über die Webseite nicht
          erreichbar.
        </p>

        <div v-if="props.can.update" class="button-row">
          <button type="button" class="button" :disabled="props.database.status !== 'active'" @click="exportDump">
            Jetzt sichern
          </button>
        </div>

        <!--
          **Hier stand ein Satz über hochgeladene Dateien, bevor es das
          Hochladen gab** — eine Zusage der Oberfläche an etwas, das nicht
          existierte. Sie ist am 8. August 2026 zurückgenommen worden und kommt
          mit diesem Formular zurück, diesmal mit einer Route dahinter
          (docs/36 §22.3f und §22.3u).
        -->
        <form v-if="props.can.update" class="form" @submit.prevent="uploadDump()">
          <label class="field">
            <span>Sicherung hochladen</span>
            <input type="file" accept=".gz,application/gzip" @change="chooseDump">
          </label>
          <p v-if="importForm.errors.dump" class="error">{{ importForm.errors.dump }}</p>
          <p class="hint">
            Eine gepackte Sicherung (<span class="ident">.sql.gz</span>) bis
            {{ props.import_limit }}. Sie wird übernommen und liegt danach in dieser Liste
            — <b>in die Datenbank kommt sie erst, wenn du sie zurückspielst</b>, und dabei wird
            der jetzige Stand von <span class="ident">{{ props.database.name }}</span>
            überschrieben.
          </p>

          <div class="button-row">
            <button type="submit" class="button" :disabled="importForm.processing || importForm.dump === null">
              Hochladen
            </button>
          </div>
        </form>
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
