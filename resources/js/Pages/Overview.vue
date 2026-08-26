<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import ActionIcon from '../Components/ActionIcon.vue'
import Bar from '../Components/Bar.vue'
import Section from '../Components/Section.vue'
import Badge from '../Components/Badge.vue'
import FormErrors from '../Components/FormErrors.vue'
import RebootButton from '../Components/RebootButton.vue'
import Tile, { type Second, type Series } from '../Components/Tile.vue'
import PanelLayout from '../Layouts/PanelLayout.vue'

interface TileData {
  key: string
  label: string
  value: string
  unit: string
  subline: string
  series: Series

  /* Nur das Netz hat sie: die zweite Richtung derselben Kennzahl. */
  second?: Second
}

interface Service {
  unit: string
  present: boolean
  active_state: string
  description: string
}

interface Filesystem {
  mount: string
  device: string
  type: string
  total: string
  free: string
  percent: number
  tight: boolean
}

interface Process {
  pid: number
  name: string
  rss: string
  state: string
  user: number
}

const props = defineProps<{
  server: {
    reachable: boolean
    hostname?: string
    distribution?: string
    kernel?: string

    /* Dreiwertig: `null` heisst „konnte nicht nachsehen", nicht „ist aktuell". */
    kernel_stale?: boolean | null

    /* Drei Werte: `null` heisst „noch nie gemessen". Der Messlauf kommt im
       Viertelstundentakt; vor seinem ersten Lauf schweigt die Seite. */
    disk_quota?: { available: boolean | null; reason: string | null; checked_at: string | null }
    uptime_s?: number
    error?: string
  }
  hosting: {
    customers: { total: number; suspended: number }
    subscriptions: { total: number; active: number; suspended: number; provisioning: number }
    domains: { total: number; active: number; suspended: number; provisioning: number }
    databases: { total: number; active: number; provisioning: number; removing: number }
    storage: { id: number; name: string; used_mb: number; percent: number; measured_at: string | null }[]
  }
  tiles: TileData[]
  services: Service[]
  filesystems: Filesystem[]
  processes: Process[]

  /** Der Rechnername zum Bestätigen und die Wartezeit — `ServerController::prompt()`. */
  reboot: { hostname: string; delay: number }
}>()

function uptimeText(seconds: number): string {
  const days = Math.floor(seconds / 86400)
  const hours = Math.floor((seconds % 86400) / 3600)

  // Die Einzahl ist kein Schönheitsfehler: „seit 1 Stunden" auf der ersten
  // Seite nach der Anmeldung liest sich wie ein Panel, das seine eigenen
  // Zahlen nicht anschaut.
  if (days > 0) return days === 1 ? 'seit 1 Tag' : `seit ${days} Tagen`
  if (hours > 0) return hours === 1 ? 'seit 1 Stunde' : `seit ${hours} Stunden`

  const minutes = Math.floor(seconds / 60)

  return minutes === 1 ? 'seit 1 Minute' : `seit ${minutes} Minuten`
}

/*
 * Ein Dienst hat drei Ausgänge und nicht zwei.
 *
 * „Nicht installiert" ist kein Fehler: Der Agent nennt jeden Dienst, den
 * dieses Panel kennt, und nicht jeder gehört auf jeden Server. Rot dafür
 * schickt jemanden auf die Suche nach etwas, das nie da war.
 */
function dienstRang(service: Service): 'ok' | 'critical' | 'neutral' {
  if (!service.present) return 'neutral'

  return service.active_state === 'active' ? 'ok' : 'critical'
}

/*
 * Der Kernel steht in der Kopfzeile — und der Hinweis nur, wenn er einer ist.
 *
 * **Warum er überhaupt dasteht.** Bis zum 10. August 2026 reichte der Agent ihn
 * durch, der Steuerungscode gab ihn weiter, die Seite erklärte ihn als
 * Eigenschaft — und zeigte ihn nie. Eine Angabe, die den ganzen Weg geht und am
 * Ende in keiner Zeile landet, ist Arbeit ohne Wirkung.
 *
 * **Und `=== true` und nicht `kernel_stale`.** Die Angabe ist dreiwertig:
 * `null` heisst, dass `/boot` sich nicht lesen liess. Ein Hinweis, der auf
 * „nicht nachgesehen" hin erscheint, behauptet etwas über den Server, das
 * niemand gemessen hat.
 */
const kernelText = computed(() => {
  const kernel = props.server.kernel

  if (!kernel) return null

  return props.server.kernel_stale === true ? `${kernel} — ein neuerer ist installiert` : kernel
})

/*
 * ═══════════════════════════════════════════════════════════════════════
 * Der Selbstlauf der Kacheln
 * ═══════════════════════════════════════════════════════════════════════
 *
 * **Der Anlass.** Vom Betreiber am 22. August 2026 gemeldet: „Die Kacheln der
 * Sparklines aktualisieren sich aktuell nicht automatisch." Der Sammler
 * schreibt im **Zehnsekundentakt** (`srvpanel-metrics.service`) — die Zahlen
 * standen also nicht still, die Seite sah bloss nicht mehr hin. Wer die
 * Auslastung beobachten wollte, drückte F5.
 *
 * **Dreissig Sekunden.** Der Sammler liefert in dieser Zeit drei neue
 * Stützstellen; schneller nachzuladen hiesse, dieselbe Kurve noch einmal zu
 * holen.
 *
 * **Nur die Kacheln.** `only: ['tiles']` — Dienste, Dateisysteme und Prozesse
 * bleiben, wie sie sind. Der Steuerungscode übergibt jede Angabe deshalb als
 * Verschluss; ohne das wäre das Sieb wirkungslos (dort steht die Begründung
 * und die gemessene Zahl der Agentenaufrufe).
 */

/**
 * Die Takte, die zur Wahl stehen — Sekunden, `0` heisst „nicht von allein".
 *
 * **Die Auswahl entsteht aus dieser Liste und steht nicht zweimal da.** Ein
 * `<option>` von Hand daneben wäre eine zweite Fassung derselben Angabe, und
 * ein Wert, den die Liste nicht kennt, liesse die Auswahl leer aussehen — der
 * Fehler, den dieses Projekt sechsmal bezahlt hat: eine Zeichenkette, die auf
 * etwas verweist, das niemand nachschlägt.
 *
 * **Die Wörter stehen ausgeschrieben.** „30 s" wäre kürzer und ist eine
 * Abkürzung, die dieses Panel sonst nirgends benutzt; gemessen kostet die volle
 * Schreibweise bei 390 px vierzehn Pixel Höhe.
 */
const TAKTE = [
  { sekunden: 30, wort: 'alle 30 Sekunden' },
  { sekunden: 60, wort: 'alle 60 Sekunden' },
  { sekunden: 0, wort: 'nicht von allein' },
] as const

/** Wo der Browser sich den Takt merkt. */
const STORAGE_KEY = 'srvpanel.overview.cycle'

/**
 * Der Takt in Sekunden, `0` heisst „nicht von allein" — gemerkt im Browser und
 * nicht am Konto.
 *
 * **Warum `localStorage` und nicht das Profil.** Das Thema hell/dunkel gehört
 * dem Menschen und soll ihm auf jedes Gerät folgen. Dieser Schalter gehört dem
 * **Bildschirm**: Auf dem Monitor neben dem Schreibtisch soll die Seite von
 * allein laufen, auf dem Telefon in der Bahn nicht. Ein Wert am Konto wäre für
 * beide derselbe.
 *
 * **Und jeder Zugriff in einem `try`.** In einem privaten Fenster und bei
 * abgeschalteten Seitendaten wirft schon das Lesen — nicht `null`, sondern
 * eine Ausnahme. Ohne die Klammer stünde die Übersicht dort weiß da.
 */
const cycle = ref(gemerkt())

/** Ob gerade nachgeladen wird — der Knopf dreht sich dann. */
const busy = ref(false)

let takt: ReturnType<typeof setInterval> | undefined

/**
 * Der gemerkte Takt — oder die Vorgabe.
 *
 * **Gelesen wird gegen {@link TAKTE} und nicht gegen sich selbst.** Im
 * Speicher des Browsers kann alles stehen: ein Wert aus einer früheren Fassung,
 * etwas von Hand Eingetragenes, Unsinn. Ein Takt, den die Liste nicht kennt,
 * liesse die Auswahl leer aussehen und liefe in einem Rhythmus, den niemand
 * gewählt hat.
 *
 * > **Ein Wert aus fremder Hand gehört gegen die Liste geprüft, die ihn
 * > anbietet.**
 */
function gemerkt(): number {
  try {
    const wert = Number(window.localStorage.getItem(STORAGE_KEY))

    if (TAKTE.some((t) => t.sekunden === wert)) return wert
  } catch {
    // Kein Zugriff auf den Speicher — dann gilt die Vorgabe.
  }

  // Die Vorgabe ist der kürzeste Takt: Wer nichts eingestellt hat, will die
  // Zahlen sehen.
  return TAKTE[0].sekunden
}

function merken(sekunden: number): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, String(sekunden))
  } catch {
    // Ein Schalter, der sich nicht merken lässt, wirkt trotzdem für diese
    // Sitzung. Eine Fehlermeldung dafür wäre eine Störung ohne Handlung.
  }
}

/**
 * Den Takt neu stellen — anhalten und, wenn einer gewählt ist, neu setzen.
 *
 * `setInterval` kennt keine Änderung seiner Länge; wer den Takt umstellt, muss
 * den alten anhalten. Ohne das liefen nach zwei Umstellungen drei.
 */
function stellen(): void {
  clearInterval(takt)
  takt = undefined

  if (cycle.value > 0) takt = setInterval(tick, cycle.value * 1000)
}

/**
 * Die Kacheln neu holen.
 *
 * **Der Riegel ist keine Feinheit.** Ein zweiter Aufruf, während der erste
 * unterwegs ist, macht aus einem Takt zwei — und Inertia bricht den ersten ab,
 * die Seite hängt dann an der Antwort des zweiten.
 */
function refresh(): void {
  if (busy.value) return

  router.reload({
    only: ['tiles'],
    onStart: () => {
      busy.value = true
    },
    onFinish: () => {
      busy.value = false
    },
  })
}

/**
 * Ein Takt — und im Hintergrund geschieht nichts.
 *
 * **`document.hidden` und nicht bloss der Schalter.** Ein vergessener Reiter
 * fragte sonst alle dreissig Sekunden den Agenten, für eine Seite, die niemand
 * ansieht. Bei zwanzig offenen Reitern ist das der Unterschied zwischen einem
 * Aufruf und zwanzig.
 */
/**
 * Der Buchstabe im Zeichen — `A`, solange ein Takt gewählt ist.
 *
 * **Als Berechnung und nicht als Vergleich im Attribut.** `:letter="cycle > 0
 * ? …"` trägt ein `>` mitten in die Vorlage, und jeder Wächter, der ein Tag
 * über `[^>]*` liest, hört genau dort auf. `BlockSpacingTest` hat sich daran
 * am 23. August verzählt und eine Fuge gemeldet, die es nicht gibt.
 *
 * > **Ein `>` in einem Attribut beendet das Tag für jeden, der es mit einem
 * > Ausdruck liest.**
 */
const letter = computed((): string => (cycle.value > 0 ? 'A' : ''))

function tick(): void {
  if (cycle.value === 0 || document.hidden) return

  refresh()
}

/**
 * Zurück im Bild: sofort nachholen.
 *
 * Ohne das zeigte die Seite nach dem Umschalten aus einem anderen Reiter bis
 * zu dreissig Sekunden lang den Stand von vorhin — und zwar genau in dem
 * Augenblick, in dem jemand hinsieht.
 */
function onVisible(): void {
  if (!document.hidden && cycle.value > 0) refresh()
}

watch(cycle, (sekunden: number): void => {
  merken(sekunden)
  stellen()

  // Einschalten heisst „jetzt", nicht „in dreissig Sekunden". Ein Schalter,
  // der sichtbar nichts tut, ist der Befund vom 7. August in anderer Gestalt.
  if (sekunden > 0) refresh()
})

onMounted((): void => {
  stellen()
  document.addEventListener('visibilitychange', onVisible)
})

/*
 * **Ohne das läuft der Takt weiter, wenn die Seite längst weg ist.** Dieses
 * Panel lädt nicht neu — Inertia tauscht die Seite im selben Dokument aus. Ein
 * `setInterval` überlebt das und fragt bis zum Schliessen des Reiters weiter,
 * von jeder Seite aus, auf der man einmal war. `IntervalTest` besteht darauf.
 */
onUnmounted((): void => {
  clearInterval(takt)
  document.removeEventListener('visibilitychange', onVisible)
})

const headline = props.server.reachable
  ? [props.server.hostname, props.server.distribution, kernelText.value, uptimeText(props.server.uptime_s ?? 0)]
      .filter(Boolean)
      .join(' · ')
  : 'Agent nicht erreichbar'
</script>

<template>
  <PanelLayout title="Übersicht" :subline="headline">
    <!--
      **Der Selbstlauf steht im Seitenkopf und nicht bei den Kacheln.**

      Der Betreiber hat beides angeboten — neben der Überschrift oder am
      oberen rechten Rand. Der Seitenkopf ist beides zugleich: `.page-head`
      verteilt Titelblock und Knopfreihe mit `space-between`, also steht die
      Reihe rechts auf derselben Grundlinie wie „Übersicht". Und es ist der
      Ort, an dem jede andere Seite dieses Panels ihre Hauptaktion führt — ein
      zweiter Ort nur für diese eine Seite wäre eine zweite Ordnung.

      **Zwei Bedienelemente und nicht eines.** Der Knopf aktualisiert von Hand,
      die Auswahl schaltet den Selbstlauf. Das `A` im Zeichen sagt, dass er
      läuft — und das Wort daneben sagt, was das heisst: Ein Zeichen trägt
      keine Bedeutung allein (`NavIcon.vue`, `ActionIconTest`).
    -->
    <template #actions>
      <div class="button-row">
        <button
          type="button"
          class="button"
          :aria-busy="busy"
          @click="refresh"
        >
          <!--
            `state`: Das Zeichen trägt hier eine Auskunft und nicht bloss die
            Wiederholung seines Wortes — es bleibt deshalb auf jeder Breite
            stehen (`app.css`).
          -->
          <ActionIcon
            name="refresh"
            class="state"
            :letter="letter"
            :class="{ turns: busy }"
          />
          <span>Aktualisieren</span>
        </button>

        <!--
          **Die Auswahl trägt ihre Beschriftung in ihren eigenen Optionen.**

          Am 7. August hat der Betreiber ein unbeschriftetes Auswahlfeld auf der
          Domainliste gemeldet — „geht unter und wird nicht wirklich
          wahrgenommen". Dort stand ein **Abonnementname** darin, und der sagt
          über die Aufgabe des Feldes nichts; erst „Abonnement" daneben tut das.

          Hier ist es umgekehrt: „alle 30 Sekunden" neben einem Knopf, der
          „Aktualisieren" heisst, **ist** die Beschriftung. Ein Etikett
          „Selbstlauf" davor sagte dasselbe ein zweites Mal und kostete bei
          390 px eine eigene Spalte — der Betreiber hat es am 23. August als
          überflüssig gemeldet.

          > **Eine Beschriftung, die dasselbe sagt wie der Wert darunter, ist
          > keine Auskunft, sondern eine Wiederholung.**

          `aria-label` bleibt: Die Vorlesehilfe liest die Optionen nicht mit,
          sie liest den Namen des Feldes. `FormLabelTest` prüft die Klammer und
          nicht den Text — sein eigener Kopf sagt, dass das Bild über die
          Beschriftung entscheidet.
        -->
        <label class="field inline">
          <select v-model="cycle" aria-label="Selbstlauf">
            <option v-for="t in TAKTE" :key="t.sekunden" :value="t.sekunden">{{ t.wort }}</option>
          </select>
        </label>
      </div>
    </template>

    <p v-if="!server.reachable" class="notice critical">
      <span>
        <b>Der Agent antwortet nicht.</b>
        {{ server.error }}
        Zustand nachsehen mit
        <span class="ident">systemctl status srvpanel-agentd</span>.
      </span>
    </p>

    <!--
      **Die Dateisystem-Quota, wenn sie fehlt.** Sie steht hier oben und nicht
      unter den Kacheln: Ohne sie zeigt jedes Abonnement eine Speichergrenze,
      die nichts begrenzt, und jede gemessene Belegung fehlt.

      **Nur bei `false`.** Ein Hinweis, der immer erscheint, erzieht dazu, die
      Seite nicht zu lesen — bezahlt am 10. August 2026 an einer Warnung, die
      bei jeder Freigabe eines Abonnements kam. `null` heisst „noch nie
      gemessen" und schweigt.
    -->
    <p v-if="server.disk_quota?.available === false" class="notice warn">
      <span>
        <b>Das Dateisystem führt keine Benutzerquota.</b>
        Speichergrenzen stehen im Panel, gelten aber nicht, und der belegte
        Platz wird nicht gemessen; der Weg dorthin steht in
        <span class="ident">docs/41-dateisystem-quota.md</span>.
        <!--
          **Zuletzt, weil sie wörtlich ist.** Eine übernommene Systemmeldung
          endet nicht verlässlich mit einem Punkt — steht ein Satz dahinter,
          klebt er an ihr („…for device Der Weg dorthin…"). Auf dem Bildschirm
          gesehen, nicht im Test.
        -->
        <template v-if="server.disk_quota.reason">
          Das System meldet:
          <span class="ident">{{ server.disk_quota.reason }}</span>
        </template>
      </span>
    </p>

    <!--
      **Der Kernel steht in der Kopfzeile, sein Anlass gehört auf die Seite.**

      `docs/81 §6` verlangt den Neustart-Knopf „an der Kernelzeile der
      Übersicht", und die ist ein Wort in der `subline` — ein Knopf lässt sich
      dort nicht unterbringen, und ein Satz, der zum Handeln auffordert, gehört
      ohnehin nicht in eine Kopfzeile aus vier Angaben.

      **Nur bei `=== true`**, aus demselben Grund wie oben bei der Quota und
      wie bei `kernelText`: `null` heisst, dass `/boot` sich nicht lesen liess.
      Ein Hinweis auf „nicht nachgesehen" hin behauptet etwas über den Server,
      das niemand gemessen hat — und stellte einen Neustart-Knopf daneben.

      **Und hier nur bei dem einen Anlass**, während die Updates-Seite ihn in
      allen drei Zuständen zeigt. Die Übersicht ist die Seite, auf der jedes
      Adminkonto landet; ein stehender Neustart-Knopf auf der Startseite ist
      ein Fehlgriff, der auf seine Gelegenheit wartet.
    -->
    <template v-if="server.kernel_stale === true">
      <p class="notice warn">
        <span>
          <b>Es ist ein neuerer Kernel installiert als der laufende.</b>
          Er wird erst mit einem Neustart wirksam — bis dahin läuft dieser
          Server auf <span class="ident">{{ server.kernel }}</span>.
        </span>
      </p>

      <RebootButton :hostname="props.reboot.hostname" :delay="props.reboot.delay" />
    </template>

    <!--
      **Wozu die Zusammenfassung auf einer Seite ohne Formular.** Der Neustart
      wird abgewiesen, wenn der eingegebene Rechnername nicht stimmt; ohne sie
      käme die Antwort an und niemand sähe sie.
    -->
    <FormErrors />

    <div class="tiles">
      <Tile
        v-for="tile in tiles"
        :key="tile.key"
        :label="tile.label"
        :value="tile.value"
        :unit="tile.unit"
        :subline="tile.subline"
        :series="tile.series"
        :second="tile.second"
      />
    </div>

    <div class="sections after-tiles">
      <!--
        Der Bestand steht über den Diensten und unter den Kacheln.

        Über den Diensten, weil ein Betreiber sein Panel nicht öffnet, um den
        Zustand von nginx zu erfahren, sondern um zu sehen, ob mit dem, was er
        hostet, etwas nicht stimmt. Unter den Kacheln, weil die Kacheln die
        Frage beantworten, ob der Server überhaupt gesund ist — und wenn er es
        nicht ist, ist alles darunter zweitrangig.
      -->
      <Section title="Bestand">
        <!--
          Die Zahlen sind Verweise und keine Kacheln: Wer die Zahl der
          gesperrten Abonnements liest, will als Nächstes wissen, welche das
          sind — und dann soll er sie anklicken können und nicht erst in die
          Navigation greifen.
        -->
        <table class="pairs">
          <tbody>
            <tr>
              <td class="quiet"><Link href="/customers" class="link">Kunden</Link></td>
              <td class="right name">{{ props.hosting.customers.total }}</td>
              <td class="right">
                <!--
                  Gesperrt und „wird angelegt" stehen nur da, wenn es sie gibt.
                  Eine Null neben einer Beschriftung ist eine Angabe, die man
                  jedes Mal liest und nie braucht.
                -->
                <Badge v-if="props.hosting.customers.suspended > 0" kind="warn">
                  {{ props.hosting.customers.suspended }} gesperrt
                </Badge>
              </td>
            </tr>
            <tr>
              <td class="quiet"><Link href="/subscriptions" class="link">Abonnements</Link></td>
              <td class="right name">{{ props.hosting.subscriptions.total }}</td>
              <td class="right">
                <Badge kind="ok">{{ props.hosting.subscriptions.active }} aktiv</Badge>
              </td>
            </tr>
            <tr v-if="props.hosting.subscriptions.suspended > 0">
              <td class="quiet">davon gesperrt</td>
              <td class="right name">{{ props.hosting.subscriptions.suspended }}</td>
              <td class="right"><Badge kind="warn">gesperrt</Badge></td>
            </tr>
            <tr v-if="props.hosting.subscriptions.provisioning > 0">
              <td class="quiet">werden angelegt</td>
              <td class="right name">{{ props.hosting.subscriptions.provisioning }}</td>
              <td class="right"><Badge kind="warn" running>läuft</Badge></td>
            </tr>

            <!--
              Domains und Datenbanken stehen darunter und nicht daneben: Sie
              hängen an einem Abonnement, und die Reihenfolge ist die, in der
              etwas entsteht. Ein Betreiber liest hier von aussen nach innen —
              wer, was gebucht, worunter erreichbar, welche Daten dahinter.
            -->
            <tr>
              <td class="quiet"><Link href="/domains" class="link">Domains</Link></td>
              <td class="right name">{{ props.hosting.domains.total }}</td>
              <td class="right">
                <Badge v-if="props.hosting.domains.active > 0" kind="ok">
                  {{ props.hosting.domains.active }} aktiv
                </Badge>
              </td>
            </tr>
            <tr v-if="props.hosting.domains.suspended > 0">
              <td class="quiet">davon gesperrt</td>
              <td class="right name">{{ props.hosting.domains.suspended }}</td>
              <td class="right"><Badge kind="warn">gesperrt</Badge></td>
            </tr>
            <tr v-if="props.hosting.domains.provisioning > 0">
              <td class="quiet">werden angelegt</td>
              <td class="right name">{{ props.hosting.domains.provisioning }}</td>
              <td class="right"><Badge kind="warn" running>läuft</Badge></td>
            </tr>

            <tr>
              <td class="quiet"><Link href="/databases" class="link">Datenbanken</Link></td>
              <td class="right name">{{ props.hosting.databases.total }}</td>
              <td class="right">
                <Badge v-if="props.hosting.databases.active > 0" kind="ok">
                  {{ props.hosting.databases.active }} aktiv
                </Badge>
              </td>
            </tr>
            <tr v-if="props.hosting.databases.provisioning > 0">
              <td class="quiet">werden angelegt</td>
              <td class="right name">{{ props.hosting.databases.provisioning }}</td>
              <td class="right"><Badge kind="warn" running>läuft</Badge></td>
            </tr>
            <!--
              „Wird entfernt" gehört dazu und ist nicht die Umkehrung von „wird
              angelegt": Ein Rückbau, der hängenbleibt, lässt ein Schema mit
              Kundendaten auf dem Datenträger liegen (docs/36 §5). Genau das ist die
              Zeile, wegen der jemand auf diese Seite sieht.
            -->
            <tr v-if="props.hosting.databases.removing > 0">
              <td class="quiet">werden entfernt</td>
              <td class="right name">{{ props.hosting.databases.removing }}</td>
              <td class="right"><Badge kind="warn" running>läuft</Badge></td>
            </tr>
          </tbody>
        </table>
      </Section>

      <!--
        Nicht die grössten Abonnements, sondern die vollsten: Eines mit 40 GB
        von 200 ist unauffällig, eines mit 4,8 GB von 5 ist der Anruf von
        morgen.
      -->
      <Section
        v-if="props.hosting.storage.length > 0"
        title="Am nächsten an der Speichergrenze"
        weit
      >
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Abonnement</th><th class="right">Belegt</th><th>Anteil</th><th>Gemessen</th></tr>
            </thead>
            <tbody>
              <tr v-for="row in props.hosting.storage" :key="row.id">
                <td data-column="Abonnement" class="name">
                  <Link :href="`/subscriptions/${row.id}`" class="link">{{ row.name }}</Link>
                </td>
                <td data-column="Belegt" class="right">{{ row.used_mb.toLocaleString('de-DE') }} MB</td>
                <td data-column="Anteil">
                  <Bar
                    :percent="row.percent"
                    :tight="row.percent >= 90 && row.percent <= 100"
                    :over="row.percent > 100"
                  />
                </td>
                <td data-column="Gemessen" class="quiet">{{ row.measured_at ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>

      <Section title="Dienste" wide>
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr><th>Unit</th><th>Zustand</th><th>Beschreibung</th></tr>
            </thead>
            <tbody>
              <tr v-for="service in services" :key="service.unit">
                <td data-column="Unit" class="ident name">{{ service.unit }}</td>
                <td data-column="Zustand">
                  <Badge :kind="dienstRang(service)">
                    {{ service.present ? service.active_state : 'nicht installiert' }}
                  </Badge>
                </td>
                <td data-column="Beschreibung" class="quiet">{{ service.description }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>

      <Section title="Dateisysteme" full>
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th>Einhängepunkt</th>
                <th>Gerät</th>
                <th>Art</th>
                <th class="right">Größe</th>
                <th class="right">Frei</th>
                <th>Belegt</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="filesystem in filesystems" :key="filesystem.mount">
                <td data-column="Einhängepunkt" class="ident name">{{ filesystem.mount }}</td>
                <td data-column="Gerät" class="ident quiet">{{ filesystem.device }}</td>
                <td data-column="Art" class="quiet">{{ filesystem.type }}</td>
                <td data-column="Größe" class="right">{{ filesystem.total }}</td>
                <td data-column="Frei" class="right">{{ filesystem.free }}</td>
                <td data-column="Belegt">
                  <!--
                    Der Balken statt nur der Zahl: „87 %" liest man, einen
                    vollen Balken sieht man. Die Schwelle, ab der er warnt,
                    kommt vom Server — sie ist eine Aussage über den Betrieb.
                  -->
                  <Bar :percent="filesystem.percent" :tight="filesystem.tight" />
                </td>
              </tr>
              <tr v-if="filesystems.length === 0">
                <td colspan="6" class="quiet">Keine Angaben — der Agent antwortet nicht.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>

      <Section title="Prozesse nach Speicher" full>
        <div class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th class="right">PID</th>
                <th>Name</th>
                <th>Zustand</th>
                <th class="right">UID</th>
                <th class="right">Speicher</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="process in processes" :key="process.pid">
                <td data-column="PID" class="right ident">{{ process.pid }}</td>
                <td data-column="Name" class="ident name">{{ process.name }}</td>
                <td data-column="Zustand" class="quiet">{{ process.state }}</td>
                <td data-column="UID" class="right ident">{{ process.user }}</td>
                <td data-column="Speicher" class="right">{{ process.rss }}</td>
              </tr>
              <tr v-if="processes.length === 0">
                <td colspan="5" class="quiet">Keine Angaben — der Agent antwortet nicht.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>
    </div>
  </PanelLayout>
</template>

<style scoped>
/*
 * **Von 180 Zeilen sind vier übrig.**
 *
 * Hier standen die Form der Tabelle, der Spaltenkopf, die Zeilenhöhe, ein
 * Balken (`.bar`), eine Zustandsmarke (`.badge`) und ein Kärtchen für den
 * Bestand — jedes davon eine eigene Fassung von etwas, das app.css inzwischen
 * trägt. Drei davon nannten `--surface-border`, eine Marke, die es seit dem
 * Umbau nicht mehr gibt: Der Spaltenkopf hatte damit keine Linie und die
 * Balkenspur keinen Grund, und niemandem ist es aufgefallen.
 *
 * Was bleibt, ist der Abstand zwischen den Kacheln und dem ersten Bereich —
 * die einzige Stelle, an der auf dieser Seite zwei verschiedene Bausteine
 * aufeinandertreffen.
 */
.after-tiles {
  margin-top: var(--block-gap);
}
</style>
