<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import Badge from '../../Components/Badge.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'

/*
 * Der Datenbankserver — eine Seite zum Nachsehen.
 *
 * **Ohne Schalter, und das ist der Inhalt der Seite und nicht ihre Lücke.**
 * Den Fernzugriff umzulegen heisst, den Datenbankserver neu zu starten, und
 * der trägt auch dieses Panel: Die Anfrage, die den Vorgang anstösst, verlöre
 * ihre Verbindung mitten im Lauf. Deshalb steht hier der Befehl statt eines
 * Knopfes — abgedruckt und nicht beschrieben, damit man ihn nimmt und nicht
 * nachschlägt.
 */
const props = defineProps<{
  server: {
    reachable: boolean
    error: string | null
    flavour_label: string
    version: string | null
    usable: boolean
    reason: string | null
    bind_address: string | null
    remote: boolean
  }
  postgresql: {
    offered: boolean
    reachable: boolean
    error: string | null
    state: string
    version: string | null
    cluster: string | null
    port: number | null
    usable: boolean
    reason: string | null
    handover: string
    handed_over: boolean | null
    databases: number
    can_install: boolean

    /** Worauf PostgreSQL horcht — `null` heisst „nicht nachgesehen". */
    listen_addresses: string | null
    remote: boolean
  }
  remote_users: {
    total: number
    hosts: { host: string; count: number }[]
  }

  /** Die Netze der PostgreSQL-Zugänge — nicht mit remote_users addierbar. */
  remote_networks: {
    total: number
    networks: { cidr: string; count: number }[]
  }
  commands: { on: string; off: string; postgresql_on: string; postgresql_off: string }
}>()

/*
 * Was der Zustand des PostgreSQL bedeutet — ein Satz je Wert.
 *
 * **Die Namen kommen aus `Pg\Server::describe()` und werden hier nur
 * übersetzt.** Entschieden wird nichts: Ob der Knopf erscheint, hat der
 * Steuerungscode aus `PgServerInstall::ACTIONABLE` beantwortet. Diese Ablage
 * ist Text und keine zweite Fassung der Regel — verglichen wird nirgends.
 */
const zustandstexte: Record<string, string> = {
  absent: 'nicht installiert',
  no_cluster: 'installiert, kein Cluster',
  stopped: 'läuft nicht',
  ambiguous: 'mehrere Cluster',
  not_handed_over: 'Rolle fehlt',
  unusable: 'nicht nutzbar',
  ready: 'bereit',
}

const pgZustand = computed(() => zustandstexte[props.postgresql.state] ?? 'unbekannt')

const pgMarke = computed<'ok' | 'warn' | 'neutral'>(() => {
  if (props.postgresql.state === 'ready') return 'ok'
  if (props.postgresql.state === 'absent') return 'neutral'

  return 'warn'
})

/*
 * Netze an einem PostgreSQL, das nur lokal horcht.
 *
 * Dasselbe wie `gestrandet` weiter unten, für das andere System: Zeilen, die
 * im Panel richtig aussehen und niemanden hereinlassen. Sie entstehen, wenn
 * der Fernzugriff nachträglich abgeschaltet wird — eintragen lassen sie sich
 * in diesem Zustand nicht.
 */
const pgGestrandet = computed(
  () => !props.postgresql.remote && props.remote_networks.total > 0,
)

const installiert = ref(false)

function installiere(): void {
  if (installiert.value) return
  if (
    !window.confirm(
      'PostgreSQL installieren?\n\nDie Pakete kommen aus der Distribution. Ein vorhandener Cluster wird benutzt und nicht umgebaut, pg_hba.conf bleibt unangetastet.',
    )
  ) {
    return
  }

  installiert.value = true
  router.post(
    '/operations',
    { task: 'pg.server.install' },
    {
      onFinish: () => {
        installiert.value = false
      },
    },
  )
}

/*
 * Der Zustand, der beide Zahlen zusammenbringt.
 *
 * Zugänge für fremde Adressen an einem Server, der nur lokal horcht, sind
 * Konten, die niemand benutzen kann — und niemand sucht sie, weil sie im Panel
 * ganz normal aussehen. Sie entstehen, wenn der Fernzugriff nachträglich
 * abgeschaltet wird; anlegen lassen sie sich in diesem Zustand nicht.
 */
const gestrandet = computed(() => !props.server.remote && props.remote_users.total > 0)

const marke = computed<'ok' | 'warn' | 'neutral'>(() => {
  if (!props.server.reachable) return 'warn'

  return props.server.remote ? 'ok' : 'neutral'
})

const zustand = computed(() => {
  if (!props.server.reachable) return 'unbekannt'

  return props.server.remote ? 'Fernzugriff möglich' : 'nur lokal'
})

// Der fertige Text kommt aus dem Panel, nicht der Wert — siehe
// DatabaseSettingsController::flavourLabel().
const bezeichnung = computed(() => props.server.flavour_label)
</script>

<template>
  <Head title="Datenbankserver" />

  <PanelLayout title="Datenbankserver" subline="Was hier läuft und von wo aus es erreichbar ist">
    <template #actions>
      <Badge :kind="marke">{{ zustand }}</Badge>
    </template>

    <p v-if="props.server.error" class="notice warn">
      <span>
        Der Agent antwortet nicht: {{ props.server.error }} Solange das so ist,
        steht auf dieser Seite nichts über den Datenbankserver — auch nicht,
        dass alles in Ordnung wäre.
      </span>
    </p>

    <p v-else-if="!props.server.reachable" class="notice critical">
      <span>
        Es ist kein Datenbankserver erreichbar.
        <template v-if="props.server.reason">{{ props.server.reason }}</template>
        Datenbanken lassen sich damit weder anlegen noch benutzen.
      </span>
    </p>

    <p v-else-if="!props.server.usable" class="notice critical">
      <span>
        Auf diesem Datenbankserver arbeitet srvpanel nicht:
        {{ props.server.reason ?? 'kein Grund genannt' }}
      </span>
    </p>

    <!--
      Der gestrandete Zustand steht oben und nicht unten bei den Zahlen: Er ist
      der einzige, aus dem etwas folgt.
    -->
    <p v-if="gestrandet" class="notice warn">
      <span>
        {{ props.remote_users.total }} Zugang/Zugänge lauten auf eine fremde
        Adresse, aber der Server horcht nur lokal — diese Konten kommen nie
        zustande. Sie bleiben bestehen und werden wieder benutzbar, sobald der
        Fernzugriff eingeschaltet ist.
      </span>
    </p>

    <div class="sections">
      <Section title="Server">
        <table class="pairs">
          <tbody>
            <tr>
              <!-- „Art" und nicht „Ausgabe": Auf der PHP-Seite heisst
                   „Ausgabe" die Release-Angabe einer Version, und dasselbe
                   Wort zweimal für zwei Dinge ist schlechter als ein
                   blasseres. -->
              <td class="quiet">Art</td>
              <td class="right">{{ bezeichnung }}</td>
            </tr>
            <tr>
              <td class="quiet">Version</td>
              <td class="right ident">{{ props.server.version ?? '—' }}</td>
            </tr>
            <tr>
              <td class="quiet">Horchadresse</td>
              <td class="right ident">{{ props.server.bind_address ?? '—' }}</td>
            </tr>
            <tr>
              <td class="quiet">Fernzugriff</td>
              <td class="right">
                <Badge :kind="props.server.remote ? 'ok' : 'neutral'">
                  {{ props.server.remote ? 'an' : 'aus' }}
                </Badge>
              </td>
            </tr>
          </tbody>
        </table>
      </Section>

      <!--
        **PostgreSQL steht neben MariaDB und nicht auf einer eigenen Seite.**
        Die Frage, die jemand hierher führt, heisst „was für Datenbanken kann
        dieser Server?" — und zwei Seiten dafür wären zwei Orte, an denen die
        Antwort halb ist.
      -->
      <Section
        title="PostgreSQL"
        note="Ein eigenständiges zweites Datenbanksystem. Kunden verbinden sich darauf über 127.0.0.1 und nicht über den Socket."
      >
        <p v-if="props.postgresql.error" class="notice warn">
          <span>Der Agent antwortet nicht: {{ props.postgresql.error }}</span>
        </p>

        <!--
          Die fehlende Rolle steht oben: Sie ist der Zustand, aus dem für den
          Betreiber unmittelbar etwas folgt. **Der Befehl selbst steht unten in
          der Tabelle und nicht in diesem Satz** — bei 390px gemessen bricht
          eine 52 Zeichen lange Kommandozeile mitten im Fliesstext um, und dann
          sieht niemand mehr, wo sie anfängt und wo sie aufhört. In einer
          Wertzelle bricht sie auch, aber sie hat die Zeile für sich. Dieselbe
          Entscheidung wie im Bereich „Umschalten" darunter.
        -->
        <p v-else-if="props.postgresql.state === 'not_handed_over'" class="notice warn">
          <span>
            PostgreSQL läuft, aber die Rolle, unter der sich das Panel anmeldet,
            gibt es noch nicht. Sie wird einmal auf dem Server angelegt — der
            Befehl steht unten.
          </span>
        </p>

        <!--
          **Der Rang kommt aus derselben Quelle wie die Marke daneben.** Hier
          stand `class="notice warn"` fest, und im Browser stritt die Seite mit
          sich selbst: Die Marke sagte grau „nicht installiert", der Balken
          darüber warnte gelb. `Settings/Php.vue` hält den Satz dazu schon fest
          — *eine Warnung schickt jemanden auf die Suche nach einem Problem,
          das keines ist* —, und „PostgreSQL ist nicht installiert" ist eine
          Auskunft. Gemerkt hat es kein Test, sondern der erste Blick auf die
          echte Seite.
        -->
        <p
          v-else-if="props.postgresql.reason && props.postgresql.state !== 'ready'"
          class="notice"
          :class="pgMarke === 'neutral' ? 'neutral' : 'warn'"
        >
          <span>{{ props.postgresql.reason }}</span>
        </p>

        <table class="pairs">
          <tbody>
            <tr>
              <td class="quiet">Zustand</td>
              <td class="right">
                <Badge :kind="pgMarke">{{ pgZustand }}</Badge>
              </td>
            </tr>
            <tr>
              <td class="quiet">Version</td>
              <td class="right ident">{{ props.postgresql.version ?? '—' }}</td>
            </tr>
            <tr>
              <td class="quiet">Cluster</td>
              <td class="right ident">{{ props.postgresql.cluster ?? '—' }}</td>
            </tr>
            <tr>
              <td class="quiet">Port</td>
              <td class="right ident">{{ props.postgresql.port ?? '—' }}</td>
            </tr>

            <!--
              **Die Horchadresse des richtigen Servers.** Bis zum 11. August
              2026 stand auf dieser Seite nur die von MariaDB — wer
              `srvpanel db --remote=on` gefahren hatte und hier nachsah, bekam
              die Auskunft des anderen Systems. Beide Antworten haben dieselbe
              Form, und genau deshalb wäre es niemandem aufgefallen.
            -->
            <tr>
              <td class="quiet">Horcht auf</td>
              <td class="right ident">{{ props.postgresql.listen_addresses ?? '—' }}</td>
            </tr>
            <tr>
              <td class="quiet">Fernzugriff</td>
              <td class="right">
                <Badge :kind="props.postgresql.remote ? 'ok' : 'neutral'">
                  {{ props.postgresql.remote ? 'an' : 'aus' }}
                </Badge>
              </td>
            </tr>
            <tr>
              <td class="quiet">Wird angeboten</td>
              <td class="right">
                <Badge :kind="props.postgresql.offered ? 'ok' : 'neutral'">
                  {{ props.postgresql.offered ? 'ja' : 'nein' }}
                </Badge>
              </td>
            </tr>
            <tr>
              <td class="quiet">Datenbanken</td>
              <td class="right">{{ props.postgresql.databases }}</td>
            </tr>
            <tr>
              <td class="quiet">Erlaubte Netze</td>
              <td class="right">{{ props.remote_networks.total }}</td>
            </tr>
          </tbody>
        </table>

        <!--
          Dieselbe Warnung wie für MariaDB weiter unten, für das andere System.
          Sie steht hier und nicht dort, weil die beiden Zahlen nicht dasselbe
          zählen: ein Zugang mit fremdem Wirt gegen ein Netz neben einer Rolle.
        -->
        <p v-if="pgGestrandet" class="notice warn">
          <span>
            {{ props.remote_networks.total }} Netz(e) sind für PostgreSQL-Zugänge
            eingetragen, aber der Server horcht nur lokal. Die Zeilen stehen in
            <span class="ident">pg_hba.conf</span> und lassen niemanden herein,
            solange der Fernzugriff aus ist.
          </span>
        </p>

        <div v-if="props.remote_networks.networks.length > 0" class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Netz</th><th class="right">Zugänge</th></tr>
            </thead>
            <tbody>
              <tr v-for="netz in props.remote_networks.networks" :key="netz.cidr">
                <td data-column="Netz" class="ident">{{ netz.cidr }}</td>
                <td data-column="Zugänge" class="right">{{ netz.count }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!--
          **„Zugänge" und nicht „Rollen".** Eine Rolle kann mehrere Netze haben
          — eine Rolle, ein Passwort, mehrere erlaubte Netze (docs/38 §14.3) —,
          und deshalb ist die Summe dieser Spalte nicht die Zahl der Zugänge,
          die von aussen hereinkommen. Der Satz steht hier, weil die Tabelle
          sonst genau das behauptet.
        -->
        <p v-if="props.remote_networks.total > 0" class="section-note">
          Ein Zugang kann mehrere Netze haben; die Summe ist deshalb die Zahl
          der Einträge und nicht die der Zugänge. Welcher Zugang welches Netz
          hat, steht auf der Seite seiner Datenbank.
        </p>

        <!--
          **Der Knopf erscheint nur, wenn die Operation etwas tut.** Welche
          Zustände das sind, steht in `PgServerInstall::ACTIONABLE` und wird von
          dort durchgereicht — hier wird nichts verglichen, sonst gäbe es die
          Regel zweimal.
        -->
        <div v-if="props.postgresql.can_install" class="button-row">
          <button type="button" class="button small" :disabled="installiert" @click="installiere">
            {{ installiert ? 'wird angelegt …' : props.postgresql.state === 'stopped' ? 'Starten' : 'Installieren' }}
          </button>
        </div>

        <p class="section-note">
          Ob Kunden PostgreSQL-Datenbanken anlegen dürfen, ist eine eigene
          Entscheidung und wird auf dem Server geschaltet. Vorhandene
          Datenbanken bleiben dabei, wo sie sind.
        </p>

        <table class="pairs">
          <tbody>
            <!--
              **`=== false` und nicht `!handed_over`.** Die Angabe ist
              dreiwertig: `null` heisst „konnte nicht nachsehen" — bei
              gestopptem oder mehrdeutigem Cluster erreicht der Agent niemanden,
              der die Frage beantworten könnte. Die alte Bedingung las das als
              „nein" und zeigte den Befehl bei stehendem Cluster an, wo er
              nicht laufen *kann*. Gefunden am 10. August 2026 auf einem Bild.
            -->
            <tr v-if="props.postgresql.handed_over === false">
              <td class="quiet">Rolle anlegen</td>
              <td class="right ident">{{ props.postgresql.handover }}</td>
            </tr>
            <tr>
              <td class="quiet">Anbieten einschalten</td>
              <td class="right ident">{{ props.commands.postgresql_on }}</td>
            </tr>
            <tr>
              <td class="quiet">Anbieten abschalten</td>
              <td class="right ident">{{ props.commands.postgresql_off }}</td>
            </tr>
          </tbody>
        </table>
      </Section>

      <Section title="Zugänge von aussen" note="Aus dem Bestand dieses Panels — von Hand angelegte Konten stehen hier nicht.">
        <p v-if="props.remote_users.total === 0" class="empty">
          Alle Zugänge lauten auf <span class="ident">localhost</span>.
        </p>

        <table v-else class="pairs">
          <tbody>
            <tr v-for="eintrag in props.remote_users.hosts" :key="eintrag.host">
              <td class="ident">{{ eintrag.host }}</td>
              <td class="right">{{ eintrag.count }}</td>
            </tr>
          </tbody>
        </table>
      </Section>

      <!--
        **Kein `full`, und das ist gemessen.** Ein Befehl, der mitten in der
        Option umbricht, ist keine Zeile zum Abtippen mehr — in einer
        Bezeichnungstabelle bricht `.ident` ausdrücklich um, statt den Nachbarn
        zu überschreiben (app.css). Der schmalste Zustand dieses Bereichs auf
        dem Schreibtisch ist `--bereich-min`, also 400px; bei 1600px
        Fensterbreite steht er genau dort. Gemessen im Chromium dieses
        Containers: Bereich 404px, Wertzelle 295px, der Befehl 236px — eine
        Zeile, 59px Luft. Darunter stapeln die Bereiche ohnehin auf volle
        Breite.
      -->
      <Section title="Fernzugriff umschalten">
        <!--
          **Der Befehl steht abgedruckt und nicht beschrieben.** „Schalte es auf
          dem Server ein" ist keine Auskunft — wer hier steht, will die Zeile,
          die er einfügt.
        -->
        <p class="notice neutral">
          <span>
            Der Fernzugriff ist eine Eigenschaft des Servers und wird auf ihm
            geschaltet. Der Datenbankserver wird dabei neu gestartet, und weil
            dieses Panel auf ihm arbeitet, geschieht das nicht hinter einem
            Klick.
          </span>
        </p>

        <!--
          **Ein Schalter für beide Systeme, und das gehört gesagt.** „Der
          Datenbankserver ist von aussen erreichbar" ist eine Aussage über den
          Rechner; wer den Befehl für MariaDB liest und PostgreSQL vergisst,
          hat eine Fläche offen, von der er nichts weiss — oder eine
          geschlossen, die er gerade aufmachen wollte.
        -->
        <p class="section-note">
          Der Befehl schaltet <b>beide</b> Systeme: MariaDBs Horchadresse und,
          sofern das Panel PostgreSQL anbietet, dessen
          <span class="ident">listen_addresses</span> samt den Zugangsregeln in
          <span class="ident">pg_hba.conf</span>. Was er auslässt, sagt er in
          seiner Ausgabe.
        </p>

        <table class="pairs">
          <tbody>
            <tr>
              <td class="quiet">Einschalten</td>
              <td class="right ident">{{ props.commands.on }}</td>
            </tr>
            <tr>
              <td class="quiet">Abschalten</td>
              <td class="right ident">{{ props.commands.off }}</td>
            </tr>
          </tbody>
        </table>

        <p class="section-note">
          Nach dem Umschalten liest der Befehl selbst nach, worauf der Server
          danach horcht, und meldet einen Fehler, wenn es etwas anderes ist als
          bestellt. Was er dabei nach <span class="ident">/etc</span> schreibt,
          nimmt das Paket beim Entfernen wieder mit.
        </p>
      </Section>
    </div>
  </PanelLayout>
</template>
