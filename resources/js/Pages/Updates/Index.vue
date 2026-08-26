<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'
import Badge from '../../Components/Badge.vue'
import FormErrors from '../../Components/FormErrors.vue'
import RebootButton from '../../Components/RebootButton.vue'
import { counted } from '../../Composables/useCounted'
import { useConfirmation } from '../../Composables/useConfirmation'

/*
 * Der Paketstand dieses Servers und die Quellen, aus denen er kommt.
 *
 * **Warum beides auf einer Seite steht:** Die zweite Liste erklärt die erste.
 * „0 Aktualisierungen" hat zwei sehr verschiedene Gründe — der Server ist
 * aktuell, oder apt kommt an seine Quellen nicht heran —, und die Zahl allein
 * sieht in beiden Fällen gleich aus.
 *
 *   Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu tun".
 *
 * **Diese Seite liest — bis auf zwei Griffe.** Der Schalter je eigener
 * Paketquelle und der Neustart; der Knopf, der *aktualisiert*, kommt in
 * Schritt 6 und braucht `systemd-run`, damit ein Neustart des Panels den Lauf
 * nicht mitnimmt.
 */

interface Package {
  name: string
  old: string | null
  new: string
  origins: string[]
  architecture: string | null
  security: boolean
}

interface Entry {
  stanza: number
  enabled: boolean
  targets: number
  types: string
  uris: string
  suites: string
  components: string
  owned: boolean
  key: {
    kind: string
    path: string | null
    readable: boolean
    keys: { fingerprint: string | null; uid: string | null; expires: number | null; state: string }[]
  }
}

const props = defineProps<{
  packages: {
    upgradable: Package[]
    removals: string[]
    held: string[]
    security: number
    fresh: number
    reboot: { required: boolean | null; packages: string[] }
    leftovers: string[]

    /**
     * Der **wirksame** Zustand der Automatik, nicht der unserer Datei.
     *
     * `readable: false` heisst „apt-config liess sich nicht fragen" — etwas
     * anderes als „die Automatik ist aus", und deshalb ein eigener Fall.
     */
    unattended:
      | { readable: false; error: string }
      | {
          readable: true
          installed: boolean
          enabled: boolean
          lists_days: number
          upgrade_days: number
          automatic_reboot: boolean
          origins: string[]
          managed: boolean
          setters: string[]
          /** Schon als Text — {@see Clock::display()} im Controller, nicht im Browser. */
          last: { lists: string | null; upgrade: string | null }
        }
  } | null

  sources: {
    targets: { file: string | null; stanza: number | null; fields: Record<string, string> }[]
    files: { path: string; format: string; entries: Entry[] }[]
  } | null

  errors: Record<string, string>

  /** Der Rechnername zum Bestätigen und die Wartezeit — {@see ServerController::prompt()}. */
  reboot: { hostname: string; delay: number }
}>()

/*
 * **Die Kacheln stehen auch dann da, wenn sie null sagen.** Eine Reihe, deren
 * Kacheln je nach Bestand erscheinen und verschwinden, ist zweimal dieselbe
 * Reihe: Wer sie kennt, muss sie neu lesen. Dieselbe Entscheidung wie bei der
 * Spalte „System" in der Datenbankliste.
 */
const kacheln = computed(() => {
  const p = props.packages

  if (p === null) return []

  return [
    { key: 'upgradable', label: 'Aktualisierbar', value: String(p.upgradable.length) },
    { key: 'security', label: 'davon Sicherheit', value: String(p.security) },

    /*
     * **Diese Kachel hat beim ersten Wurf gefehlt**, und `fresh` war damit ein
     * Feld, das der Agent rechnet und niemand liest — genau der Fehler, den
     * `docs/66` an `context` im Protokoll beschreibt und den dieselbe Stufe
     * einen Commit vorher noch zitiert hat.
     *
     *   Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht
     *   von einem zu unterscheiden, das es nicht gibt.
     *
     * Und die Zahl gehört gezeigt: Wer „145 Aktualisierungen" liest und
     * hinterher neue Pakete auf der Platte hat, ist belogen worden.
     */
    { key: 'fresh', label: 'davon neu', value: String(p.fresh) },

    { key: 'held', label: 'Zurückgehalten', value: String(p.held.length) },
    { key: 'removals', label: 'Würde entfernt', value: String(p.removals.length) },
  ]
})

/*
 * **Filtern, und erst danach blättern.** Ein Betreiber, der diese Seite auf dem
 * Telefon öffnet, will nicht 145 Zeilen durchsehen — er will wissen, ob etwas
 * Sicherheitsrelevantes ansteht, und dann genau das sehen.
 *
 * **Der Zustand bleibt lokal und reist nicht in der Adresse**, anders als bei
 * der Logs-Seite. Dort muss der Filter zum Agenten, weil er eine Datei liest;
 * hier liegt der ganze Bestand schon in der Antwort, und ein Umlauf über den
 * Server hiesse, `apt-get -s dist-upgrade` und `-s upgrade` ein zweites Mal zu
 * fahren — Sekunden für eine Auswahl, die im Browser eine Millisekunde kostet.
 */
const auswahl = ref<'alle' | 'sicherheit' | 'neu'>('alle')
const herkunft = ref('')
const suche = ref('')

/**
 * Die Herkünfte, die im Bestand wirklich vorkommen.
 *
 * **Aus den Daten und nicht aus einer Liste.** Eine gepflegte Aufzählung wäre
 * die zweite Fassung dessen, was der Agent ohnehin schickt — und sie wäre in
 * dem Moment falsch, in dem jemand eine Quelle hinzufügt.
 */
const herkuenfte = computed(() => {
  const gesehen = new Set<string>()

  for (const paket of props.packages?.upgradable ?? []) {
    for (const o of paket.origins) gesehen.add(o)
  }

  return [...gesehen].sort()
})

const gefiltert = computed(() => {
  const wort = suche.value.trim().toLowerCase()

  return (props.packages?.upgradable ?? []).filter((paket) => {
    if (auswahl.value === 'sicherheit' && !paket.security) return false
    if (auswahl.value === 'neu' && paket.old !== null) return false
    if (herkunft.value !== '' && !paket.origins.includes(herkunft.value)) return false

    return wort === '' || paket.name.toLowerCase().includes(wort)
  })
})

/*
 * **Geblättert wird, und die Seitengrösse ist gemessen und nicht geerbt.**
 *
 * Die erste Fassung zeigte alle 145 Zeilen; bei 390 px war die Seite
 * **29 412 px** hoch. Die Messung war dabei grün — `dokument=0`, Gegenprobe
 * 200/200, `schiebt=0`.
 *
 *   Eine Messung, die nur waagerecht misst, sagt über die Höhe nichts.
 *
 * **Die zweite Fassung erbte `Page::SIZE` (50), und auch das war falsch.**
 * Gemessen am 26. August 2026 an der echten Seite: Eine Kärtchenzeile kostet
 * bei 390 px **179 px**, bei 1440 px **41 px** — das **4,4-fache**. Dieselbe
 * Seitenzahl ergibt damit drei Bildschirme am Schreibtisch und vierzehn auf
 * dem Telefon.
 *
 *   Eine Seitengrösse, die für eine einzeilige Tabelle stimmt, stimmt nicht
 *   für ein Kärtchen mit vier Feldern.
 *
 * Zwanzig ist die Zahl, die aus der Messung folgt: 20 × 179 px sind rund
 * viereinhalb Telefonschirme und bei 1440 px knapp einer. Sie steht deshalb
 * **hier** und nicht in `Page::SIZE` — die gilt für die blätternden Tabellen
 * des Panels, und eine Zahl an zwei Orten zu benutzen, weil sie zufällig
 * dieselbe war, ist keine Gemeinsamkeit.
 *
 * **Und die Grösse hängt nicht an der Fensterbreite.** Ein Betreiber, der beim
 * Drehen des Telefons eine andere Seite vor sich hat, sucht die Zeile wieder,
 * die er gerade gelesen hat.
 */
const SEITE = 20

const seite = ref(1)

const seiten = computed(() => Math.max(1, Math.ceil(gefiltert.value.length / SEITE)))

const sichtbar = computed(() => gefiltert.value.slice((seite.value - 1) * SEITE, seite.value * SEITE))

/*
 * **Die Beschriftung nennt den Ausschnitt und nicht die Seitenzahl.** „1–20 von
 * 124" beantwortet, was jemand wissen will; „Seite 1 von 7" lässt ihn rechnen.
 * Und sie zählt das **Gefilterte**: Stünde dort die Gesamtzahl, behauptete sie
 * einen Bestand, den die Liste darunter nicht zeigt.
 */
const stand = computed(() => {
  const gesamt = gefiltert.value.length

  if (gesamt === 0) return ''

  const von = (seite.value - 1) * SEITE + 1

  return `${von}–${Math.min(von + SEITE - 1, gesamt)} von ${gesamt}`
})

/*
 * **Jede Änderung am Filter geht auf Seite 1 zurück.** Sonst steht der
 * Betreiber nach dem Umschalten auf Seite 5 einer Liste, die nur noch zwei
 * hat — und sieht nichts, obwohl Treffer da sind.
 */
watch([auswahl, herkunft, suche], () => {
  seite.value = 1
})

// Und die Gegenrichtung, falls sich der Bestand unter der Blätterung ändert.
watch(seiten, (jetzt) => {
  if (seite.value > jetzt) seite.value = jetzt
})

/**
 * Was der dritte Zustand einer Quelle bedeutet.
 *
 * **Drei und nicht zwei.** „Kein Ziel" heisst nicht „abgeschaltet": Eine
 * Quelle, für die apt keinen Index geholt hat — weil sie neu ist oder weil das
 * Holen scheitert —, fehlt bei den Zielen genauso wie eine abgeschaltete. Von
 * den Zielen allein sind die beiden nicht zu unterscheiden, und deshalb liest
 * der Agent `Enabled:` aus der Datei.
 *
 *   Zwei Zustände, die von einer Seite gleich aussehen, brauchen die zweite
 *   Seite — nicht eine Vermutung.
 */
function zustand(eintrag: Entry): { kind: 'ok' | 'warn' | 'neutral'; text: string } {
  if (!eintrag.enabled) return { kind: 'neutral', text: 'abgeschaltet' }
  if (eintrag.targets === 0) return { kind: 'warn', text: 'kein Index' }

  return { kind: 'ok', text: counted(eintrag.targets, 'Ziel', 'Ziele') }
}

/*
 * **Geschaltet wird nur, was das Panel angelegt hat** — und das entscheidet der
 * Agent, nicht diese Seite. `eintrag.owned` kommt aus
 * `SrvPanel\Agent\Sources::owned()`; eine eigene Liste hier wäre deren zweite
 * Fassung und stünde vor der Stelle mit den Systemrechten.
 *
 * **Der Wahrheitswert reist im Rumpf und nicht in der Adresse.** `router.get`
 * legt seine Werte in die URL, und dort wird aus `false` das Wort `"false"`
 * (`docs/66`).
 */
function schalten(datei: string, eintrag: Entry): void {
  router.put('/updates/sources', {
    path: datei,
    stanza: eintrag.stanza,
    enabled: !eintrag.enabled,
  })
}

/** Woher der Schlüssel kommt — als Satz und nicht als Kennung. */
function schluessel(eintrag: Entry): string {
  if (!eintrag.key.readable) return 'nicht lesbar'
  if (eintrag.key.kind === 'path') return eintrag.key.path ?? ''
  if (eintrag.key.kind === 'embedded') return 'in der Datei'

  return 'keiner'
}

/**
 * Der Fingerabdruck, in Vierergruppen.
 *
 * Vierzig Hexziffern am Stück liest niemand ab. Gruppiert wird wie in jedem
 * Werkzeug, das Fingerabdrücke zeigt — so lässt er sich mit dem vergleichen,
 * den ein Anbieter auf seiner Seite nennt.
 */
function abdruck(roh: string | null): string {
  return roh === null ? '' : (roh.match(/.{1,4}/g) ?? []).join(' ')
}

/**
 * Die Schlüssel, die eine Meldung wert sind.
 *
 * **Das ist Abnahmepunkt 4 aus `docs/81 §4`** — ein Schlüssel, der bald
 * abläuft, wird gemeldet, **bevor** ein Lauf an ihm scheitert. Eine Spalte
 * allein tut das nicht: Sie steht da und wartet, dass jemand hinsieht. Ein
 * abgelaufener Schlüssel bricht `apt-get update`, und weil das mit `0` endet,
 * meldet der Server danach „nichts zu tun".
 */
const faellig = computed(() => {
  const treffer: { datei: string; stanza: number; state: string; uid: string | null; expires: number | null }[] = []

  for (const datei of props.sources?.files ?? []) {
    for (const eintrag of datei.entries) {
      for (const k of eintrag.key.keys) {
        if (k.state === 'soon' || k.state === 'expired') {
          treffer.push({ datei: datei.path, stanza: eintrag.stanza, state: k.state, uid: k.uid, expires: k.expires })
        }
      }
    }
  }

  return treffer
})

/** Quellen, deren Schlüssel sich nicht lesen liess — etwas anderes als „keiner". */
const unlesbar = computed(() =>
  (props.sources?.files ?? []).flatMap((datei) =>
    datei.entries.filter((e) => !e.key.readable).map((e) => `${datei.path}:${e.stanza}`),
  ),
)

/*
 * ═══════════════════════════════════════════════════════════════════════
 * Auffrischen und Einspielen
 * ═══════════════════════════════════════════════════════════════════════
 */

const { ask } = useConfirmation()

/**
 * Die angehakten Pakete — über Filter und Seiten hinweg.
 *
 * **Ein Set und keine Eigenschaft an der Zeile.** Die Zeilen entstehen aus
 * `sichtbar` neu, sobald jemand filtert oder blättert; eine Marke an der Zeile
 * wäre beim nächsten Rendern fort. Und die Auswahl **soll** einen Filterwechsel
 * überleben: Wer erst nach `libssl` sucht, drei Zeilen anhakt und dann nach
 * `perl` sucht, will beide Gruppen installieren.
 *
 * Damit kann die Zahl grösser sein als das, was gerade zu sehen ist — deshalb
 * steht sie am Knopf.
 */
const gewaehlt = ref(new Set<string>())

function umschalten(name: string): void {
  // Ein neues Set und kein `add`/`delete` am alten: Vue verfolgt die Identität,
  // nicht den Inhalt — eine Mutation an Ort und Stelle löste kein Rendern aus.
  const naechstes = new Set(gewaehlt.value)

  if (naechstes.has(name)) naechstes.delete(name)
  else naechstes.add(name)

  gewaehlt.value = naechstes
}

/** Sind alle sichtbaren Zeilen angehakt? */
const alleSichtbarGewaehlt = computed(
  () => sichtbar.value.length > 0 && sichtbar.value.every((p) => gewaehlt.value.has(p.name)),
)

function alleUmschalten(): void {
  const naechstes = new Set(gewaehlt.value)

  for (const paket of sichtbar.value) {
    if (alleSichtbarGewaehlt.value) naechstes.delete(paket.name)
    else naechstes.add(paket.name)
  }

  gewaehlt.value = naechstes
}

function auffrischen(): void {
  ask(
    'Die Paketlisten jetzt auffrischen?\n'
      + 'Das dauert auf einem Server, der lange nicht nachgesehen hat, über eine Minute.',
    'Nachsehen',
    () => router.post('/updates/refresh'),
    false,
  )
}

/**
 * Installieren — mit einer Rückfrage, die sagt, was genau geschieht.
 *
 * **Der Satz nennt die Zahl und nicht die Handlung.** „Aktualisierungen
 * installieren?" beantwortet niemand falsch; „142 Pakete installieren, davon 124
 * mit Sicherheitsbezug?" schon.
 */
function installieren(modus: 'all' | 'security' | 'packages'): void {
  const namen = modus === 'packages' ? [...gewaehlt.value] : []

  const was = modus === 'all'
    ? counted(props.packages?.upgradable.length ?? 0, 'Paket', 'Pakete')
    : modus === 'security'
      ? counted(props.packages?.security ?? 0, 'Sicherheitsupdate', 'Sicherheitsupdates')
      : counted(namen.length, 'ausgewähltes Paket', 'ausgewählte Pakete')

  ask(
    `${was} installieren?\n`
      + 'Der Lauf läuft weiter, auch wenn diese Seite geschlossen wird — er liegt in einer eigenen '
      + 'systemd-Unit und überlebt einen Neustart des Panels.\n'
      + 'Konfigurationsdateien, die von Hand geändert wurden, bleiben stehen; die neue Version legt '
      + 'sich als .dpkg-dist daneben und erscheint danach auf dieser Seite.',
    'Installieren',
    () => router.post('/updates/install', { mode: modus, packages: namen }),

    /*
     * **Nicht rot, und das ist eine Aussage.** `.button.danger` heisst in
     * diesem Panel „lässt sich nicht zurücknehmen" (Plan §7.2), und
     * `DangerRankTest` hält Knopf und Rückfrage zusammen. Eine Aktualisierung
     * einzuspielen ist die Handlung, für die es diese Seite gibt — der rote
     * Knopf dieser Stufe ist der Neustart, und wenn beides rot wäre, sagte die
     * Farbe nichts mehr.
     */
    false,
  )
}

/*
 * ═══════════════════════════════════════════════════════════════════════
 * Die Automatik
 * ═══════════════════════════════════════════════════════════════════════
 */

/** Der wirksame Zustand, oder `null`, wenn apt sich nicht fragen liess. */
const automatik = computed(() => {
  const u = props.packages?.unattended

  return u !== undefined && u.readable ? u : null
})

/**
 * Was die Automatik gerade tut — in einem Satz und nicht in vier Zahlen.
 *
 * **Drei Zustände, und der dritte ist der, den es ohne M8 nicht gäbe:** Die
 * beiden Teilschalter können auf „an" stehen und die Automatik trotzdem aus
 * sein, weil eine fremde Datei den Hauptschalter auf `0` gesetzt hat. Genau
 * so steht dieser Container da.
 */
const automatikSatz = computed((): { kind: 'ok' | 'warn' | 'neutral'; text: string } | null => {
  const u = automatik.value

  if (u === null) return null

  if (!u.installed) {
    return {
      kind: 'neutral',
      text: 'Das Paket unattended-upgrades ist nicht installiert — unbeaufsichtigt läuft nichts.',
    }
  }

  if (!u.enabled) {
    return {
      kind: 'warn',
      text: 'Der Hauptschalter von apt steht auf aus. Daran ändert keine andere Einstellung etwas.',
    }
  }

  if (u.upgrade_days === 0) {
    return {
      kind: 'neutral',
      text: 'Die Paketlisten werden aufgefrischt; installiert wird nichts von selbst.',
    }
  }

  /*
   * **Ein ganzer Satz und kein `counted()`.** Der Abstand steht mitten im
   * Satz („alle 3 Tage") und nicht davor — `counted()` setzt die Zahl an den
   * Anfang und kann das nicht. `CountedNounTest` lässt einen Satz, der sich
   * als Ganzes ändert, ausdrücklich zu; was es nicht zulässt, sind zwei
   * einzelne Wörter hinter einer Eins.
   */
  return {
    kind: 'ok',
    text: u.upgrade_days === 1
      ? 'Es wird täglich unbeaufsichtigt installiert.'
      : `Es wird alle ${u.upgrade_days} Tage unbeaufsichtigt installiert.`,
  }
})

/**
 * Ein Abstand in Tagen als Wort.
 *
 * **`0` heisst nie, und das steht als Wort da.** `apt.systemd.daily` vergleicht
 * die Zahl mit dem Alter eines Zeitstempels; eine `0` schaltet den Teil ab. Wer
 * „0 Tage" schriebe, läse sich wie „ständig".
 */
function tage(wert: number): string {
  if (wert === 0) return 'nie'
  if (wert === 1) return 'täglich'

  return `alle ${wert} Tage`
}

/**
 * Wann etwas zuletzt geschah — oder dass es noch nie geschah.
 *
 * **`null` ist keine fehlende Angabe, sondern eine.** `apt.systemd.daily` legt
 * seine Zeitstempel erst beim ersten Lauf an; auf einem frisch aufgesetzten
 * Server gibt es sie zu Recht nicht.
 *
 * **Und gerechnet wird hier nichts.** Der Text kommt fertig aus dem
 * Controller, über `Clock::display()` in der eingestellten Anzeigezone; ein
 * `toLocaleString()` an dieser Stelle rechnete in der Zone des Betrachters
 * und stünde neben Zeiten in einer anderen (`docs/40`).
 */
function stempel(wert: string | null): string {
  return wert === null ? 'noch nie' : wert
}

function schaltenAutomatik(an: boolean): void {
  ask(
    an
      ? 'Sicherheitsupdates künftig unbeaufsichtigt installieren?\n'
        + 'Das Paket unattended-upgrades wird dafür installiert, wenn es fehlt. '
        + 'Neu gestartet wird dabei nie von selbst — der Neustart bleibt ein Knopf.\n'
        + 'Welche Herkünfte dabei gelten, entscheidet die Distribution; sie stehen unten.'
      : 'Unbeaufsichtigtes Installieren abschalten?\n'
        + 'Die Paketlisten werden weiter täglich aufgefrischt — ohne das wäre die Zahl auf '
        + 'dieser Seite irgendwann drei Wochen alt.',
    an ? 'Einschalten' : 'Abschalten',
    () => router.put('/updates/unattended', { enabled: an }),
    false,
  )
}

/*
 * **Drei Zustände und nicht zwei.** `null` heisst „nicht feststellbar" — auf
 * diesem Server ist `update-notifier-common` nicht installiert, und dann fehlt
 * `/run/reboot-required`, weil niemand sie schreibt. Das ist etwas anderes als
 * „kein Neustart nötig", und wer beides gleich anzeigt, beruhigt ohne Grund.
 */
const neustart = computed(() => {
  const r = props.packages?.reboot

  if (r === undefined) return null
  if (r.required === true) return { kind: 'warn' as const, text: 'Ein Neustart steht aus' }
  if (r.required === false) return { kind: 'ok' as const, text: 'Kein Neustart nötig' }

  return {
    kind: 'neutral' as const,
    text: 'Nicht feststellbar — das Paket update-notifier-common ist nicht installiert',
  }
})
</script>

<template>
  <Head title="Updates" />

  <PanelLayout title="Updates" subline="Der Paketstand dieses Servers und seine Quellen">
    <!--
      **„Jetzt nachsehen" steht im Seitenkopf und nicht bei den Paketen.**

      Es frischt die **Grundlage** der ganzen Seite auf — auch die Quellen
      darunter zeigen danach andere Zahlen. Ein Knopf im Bereich „Pakete" sähe
      aus, als beträfe er nur die Liste daneben. Und es ist der Ort, an dem jede
      andere Seite dieses Panels ihre Hauptaktion führt.
    -->
    <template #actions>
      <div class="button-row">
        <button type="button" class="button" @click="auffrischen">Jetzt nachsehen</button>
      </div>
    </template>

    <!--
      Kein Zusatz für „steht unter dem Seitenkopf": Das erledigt
      `.page-head + .tiles` in app.css, und eine eigene Klasse dafür wäre
      deren zweite Fassung.
    -->
    <div class="tiles">
      <div v-for="k in kacheln" :key="k.key" class="tile">
        <span class="tile-label">{{ k.label }}</span>
        <span class="tile-value">{{ k.value }}</span>
      </div>
    </div>

    <!--
      **Wozu die Zusammenfassung auf einer Seite ohne Formular.** Zwei Griffe
      dieser Seite können abgewiesen werden — der Schalter einer fremden Quelle
      und der Neustart mit dem falschen Rechnernamen. Ohne sie käme die Antwort
      des Servers an und niemand sähe sie.

      > **Eine Auskunft, die entsteht und die niemand weitergibt, ist so gut wie
      > keine.**
    -->
    <FormErrors />

    <div class="sections">
      <Section title="Pakete" full>
        <p v-if="props.errors.packages" class="notice critical">
          Der Paketstand liess sich nicht ermitteln: {{ props.errors.packages }}
        </p>

        <template v-else-if="props.packages">
          <!--
            **Der ganze Satz steht in einem `<span>`.** `.notice` ist eine
            Flexbox; ein Textknoten neben einem Element ergäbe zwei Flexkinder,
            und bei 390 px stünde dann ein Wort von fünf Pixeln Breite neben
            dem Rest des Satzes. Der Überlauf dabei ist 0 — die Messung der
            Bilderrunde findet das nicht, `NoticeChildrenTest` schon.
          -->
          <p v-if="neustart" class="notice" :class="neustart.kind">
            <span>
              {{ neustart.text }}
              <template v-if="props.packages.reboot.packages.length > 0">
                — {{ props.packages.reboot.packages.join(', ') }}
              </template>
            </span>
          </p>

          <!--
            **Der Knopf steht unter der Meldung und nicht darin.** `.notice` ist
            eine Flexbox mit `align-items: flex-start`; ein Knopf als zweites
            Kind nähme bei 390 px etwa ein Drittel der Zeile, und der Satz
            daneben bräche in fünf.

            **Und er steht in allen drei Zuständen da**, nicht nur bei „steht
            aus". Wer einen Server aus einem anderen Grund neu starten will,
            sucht die Handlung dort, wo der Zustand der Maschine steht — und
            findet einen Knopf, der nur bei einem von drei Zuständen erscheint,
            genau dann nicht.

            > **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und
            > steht sie dort?**
          -->
          <RebootButton :hostname="props.reboot.hostname" :delay="props.reboot.delay" />

          <!--
            **Zurückgehalten und „würde entfernt" stehen als Satz da und nicht
            nur als Zahl in der Kachel.** Beides sind Namen, die der Betreiber
            braucht, um zu entscheiden; eine Zahl allein sagt ihm, dass etwas
            ist, und nicht was.
          -->
          <p v-if="props.packages.held.length > 0" class="notice warn">
            <span>
              {{ counted(props.packages.held.length, 'Paket wird', 'Pakete werden') }} zurückgehalten:
              <span class="ident">{{ props.packages.held.join(', ') }}</span>
            </span>
          </p>

          <p v-if="props.packages.removals.length > 0" class="notice warn">
            <span>
              Ein vollständiges Update würde
              {{ counted(props.packages.removals.length, 'ein Paket', 'Pakete') }} entfernen:
              <span class="ident">{{ props.packages.removals.join(', ') }}</span>
            </span>
          </p>

          <!--
            **Auch die Einzahl entscheidet `counted()` und nicht diese Vorlage.**
            Ein `? 'wartet' : 'warten'` hier wäre die zweite Fassung derselben
            Regel, und die zweite ist die, die beim Nachziehen vergessen wird.
          -->
          <p v-if="props.packages.leftovers.length > 0" class="notice warn">
            <span>
              {{ counted(props.packages.leftovers.length, 'Eine Konfigurationsdatei unter /etc wartet', 'Konfigurationsdateien unter /etc warten') }}
              auf eine Entscheidung:
              <span class="ident">{{ props.packages.leftovers.join(', ') }}</span>
            </span>
          </p>

          <p v-if="props.packages.upgradable.length === 0" class="empty">
            Es steht keine Aktualisierung an.
          </p>

          <template v-else>
            <!--
              **Die drei Griffe stehen über dem Filter und nicht darunter.**

              Sie beziehen sich auf den **Bestand** und nicht auf das, was der
              Filter gerade zeigt — „Alle installieren" nimmt die 142 aus der
              Kachel, auch wenn darunter drei Zeilen zu sehen sind. Stünden sie
              unter dem Filter, läse sich das als „alle davon".

              Der dritte trägt seine Zahl im Wort: Er ist der einzige, dessen
              Umfang von einer Auswahl abhängt, die man beim Lesen nicht sieht.
            -->
            <div class="button-row">
              <button type="button" class="button primary" @click="installieren('all')">
                Alle installieren
              </button>

              <button
                v-if="props.packages.security > 0"
                type="button"
                class="button"
                @click="installieren('security')"
              >
                Nur Sicherheit installieren
              </button>

              <button
                type="button"
                class="button"
                :disabled="gewaehlt.size === 0"
                @click="installieren('packages')"
              >
                {{ counted(gewaehlt.size, 'ausgewähltes installieren', 'ausgewählte installieren') }}
              </button>
            </div>

            <!--
              **Filtern vor Blättern.** Auf dem Telefon kostet eine Zeile
              179 px; wer dort etwas sucht, soll es finden und nicht durch
              vierzehn Bildschirme rollen.
            -->
            <div class="filter">
              <label class="field">
                <span>Zeigen</span>
                <select v-model="auswahl">
                  <option value="alle">Alle</option>
                  <option value="sicherheit">Nur Sicherheit</option>
                  <option value="neu">Nur neue Pakete</option>
                </select>
              </label>

              <label class="field">
                <span>Herkunft</span>
                <select v-model="herkunft">
                  <option value="">Alle Herkünfte</option>
                  <option v-for="o in herkuenfte" :key="o" :value="o">{{ o }}</option>
                </select>
              </label>

              <label class="field">
                <span>Name</span>
                <input v-model="suche" type="text" placeholder="z. B. libssl">
              </label>
            </div>

            <!--
              **Zwei leere Zustände und nicht einer.** „Es steht keine
              Aktualisierung an" ist eine Auskunft über den Server; „auf diesen
              Filter passt nichts" eine über die Auswahl. Wer beide gleich
              anzeigt, meldet einen aktuellen Server für eine Sucheingabe, die
              danebenging.
            -->
            <p v-if="gefiltert.length === 0" class="empty">
              Auf diese Auswahl passt kein Paket.
            </p>

            <div v-else class="scrolls">
            <table class="stacks">
              <thead>
                <tr>
                  <!--
                    **Das Kästchen im Kopf hakt die sichtbare Seite an und nicht
                    den Bestand.** „Alles" hiesse bei 142 Paketen über sieben
                    Seiten etwas anderes als das, was man vor sich sieht — und
                    dafür gibt es den Knopf „Alle installieren" daneben.
                  -->
                  <th>
                    <input
                      type="checkbox"
                      class="check"
                      :checked="alleSichtbarGewaehlt"
                      aria-label="Alle sichtbaren Pakete auswählen"
                      @change="alleUmschalten"
                    >
                  </th>
                  <th>Paket</th>
                  <th>Installiert</th>
                  <th>Neu</th>
                  <th>Herkunft</th>
                </tr>
              </thead>

              <tbody>
                <tr v-for="paket in sichtbar" :key="paket.name">
                  <!--
                    **`.check` und nicht `.toggle`** — dieselbe Form wie im
                    Dateimanager. Der `.toggle` bringt `margin-top: 14px` und
                    ein `<span>` mit Beschriftung mit; beides ist in einer Zelle
                    falsch, in der der Name schon danebensteht.
                  -->
                  <td data-column="Auswahl">
                    <input
                      type="checkbox"
                      class="check"
                      :checked="gewaehlt.has(paket.name)"
                      :aria-label="paket.name + ' auswählen'"
                      @change="umschalten(paket.name)"
                    >
                  </td>

                  <td data-column="Paket" class="ident">
                    {{ paket.name }}
                    <Badge v-if="paket.security" kind="warn">Sicherheit</Badge>
                  </td>

                  <!--
                    **Eine Neuinstallation hat keine alte Fassung**, und das
                    steht als Wort da. Ein leeres Feld läse sich wie ein
                    fehlender Wert; hier ist die Abwesenheit die Auskunft.
                  -->
                  <td data-column="Installiert" class="ident">
                    <span v-if="paket.old !== null">{{ paket.old }}</span>
                    <span v-else class="quiet">kommt neu dazu</span>
                  </td>

                  <td data-column="Neu" class="ident">{{ paket.new }}</td>

                  <!--
                    **Alle Herkünfte und nicht die erste.** apt nennt die
                    Aktualisierungssuite zuerst und die Sicherheitssuite danach;
                    wer nur die erste zeigt, verschweigt genau die, deretwegen
                    die Marke danebensteht.
                  -->
                  <td data-column="Herkunft" class="ident">
                    <span v-if="paket.origins.length > 0">{{ paket.origins.join(', ') }}</span>
                    <span v-else class="quiet">keine</span>
                  </td>
                </tr>
              </tbody>
              </table>
            </div>

            <div v-if="seiten > 1" class="pager">
              <div>
                <button type="button" class="button" :disabled="seite === 1" @click="seite--">
                  Zurück
                </button>
              </div>

              <p class="pager-state">{{ stand }}</p>

              <div class="right">
                <button type="button" class="button" :disabled="seite === seiten" @click="seite++">
                  Weiter
                </button>
              </div>
            </div>
          </template>
        </template>
      </Section>

      <Section title="Paketquellen" full>
        <p v-if="props.errors.sources" class="notice critical">
          Die Paketquellen liessen sich nicht ermitteln: {{ props.errors.sources }}
        </p>

        <template v-else-if="props.sources">
          <!--
            **Die Meldung, nicht die Spalte, ist Abnahmepunkt 4.** Eine Spalte
            wartet, dass jemand hinsieht; ein abgelaufener Schlüssel bricht
            `apt-get update`, und weil das mit `0` endet, meldet der Server
            danach „nichts zu tun".
          -->
          <p v-if="faellig.length > 0" class="notice warn">
            <span>
              {{ counted(faellig.length, 'Ein Signaturschlüssel', 'Signaturschlüssel') }}
              {{ faellig.some((f) => f.state === 'expired') ? 'ist abgelaufen oder läuft bald ab' : 'läuft in weniger als dreissig Tagen ab' }}:
              <span class="ident">{{ faellig.map((f) => `${f.datei}:${f.stanza}`).join(', ') }}</span>
            </span>
          </p>

          <p v-if="unlesbar.length > 0" class="notice warn">
            <span>
              {{ counted(unlesbar.length, 'Ein Signaturschlüssel liess', 'Signaturschlüssel liessen') }}
              sich nicht lesen — das ist etwas anderes, als hätte die Quelle keinen:
              <span class="ident">{{ unlesbar.join(', ') }}</span>
            </span>
          </p>

          <p class="section-note wide">
            Was apt tatsächlich benutzt, steht als Zahl neben jedem Eintrag.
            Eine Quelle ohne Index ist nicht abgeschaltet — apt hat sie nur nicht
            geholt, weil sie neu ist oder das Holen scheitert.
          </p>

          <div class="scrolls">
            <table class="stacks">
              <thead>
                <!--
                  **„Zustand" steht vorn und nicht am Ende.** Er ist die
                  Antwort dieser Tabelle; Adresse, Suiten und Schlüssel sind
                  die Erläuterung dazu. In der ersten Fassung stand er als
                  letzte Spalte — bei 1440 px lag er ausserhalb des Bildes, und
                  die Tabelle rollte für genau die Auskunft, deretwegen es sie
                  gibt.

                    Eine Spalte, die man wegrollen muss, ist keine Antwort.
                -->
                <tr>
                  <th>Datei</th>
                  <th>Zustand</th>
                  <th>Adresse</th>
                  <th>Suiten</th>
                  <th>Schlüssel</th>
                  <th>Fingerabdruck</th>
                  <th>Schalten</th>
                </tr>
              </thead>

              <tbody>
                <template v-for="datei in props.sources.files" :key="datei.path">
                  <tr v-for="eintrag in datei.entries" :key="`${datei.path}:${eintrag.stanza}`">
                    <!--
                      **Die Zahl hinter dem Doppelpunkt ist eine Stanza und
                      keine Zeile.** apt schreibt sie so, und dieselbe
                      Schreibweise bedeutet überall sonst eine Zeilennummer —
                      deshalb steht sie hier als „Eintrag n" und nicht als
                      `datei:n`.
                    -->
                    <td data-column="Datei" class="ident">
                      {{ datei.path }}
                      <span v-if="datei.entries.length > 1" class="quiet">· Eintrag {{ eintrag.stanza }}</span>
                    </td>

                    <td data-column="Zustand">
                      <Badge :kind="zustand(eintrag).kind">{{ zustand(eintrag).text }}</Badge>
                    </td>

                    <td data-column="Adresse" class="ident">{{ eintrag.uris }}</td>
                    <td data-column="Suiten" class="ident">{{ eintrag.suites }}</td>
                    <!--
                      **Herkunft und Fingerabdruck stehen in zwei Spalten und
                      nicht in einer Zelle.** Der erste Wurf legte beide
                      übereinander — bei 390 px ist `.stacks td` eine Flexbox,
                      und aus „in der Datei" neben vierzig Hexziffern wurden
                      drei Zeilen mit je einem Wort. Ein `flex-direction` in
                      dieser Komponente wäre die Gestaltung einer Tabelle am
                      Gestaltungssystem vorbei; zwei Spalten sind dieselbe
                      Auskunft ohne eigene Regel.
                    -->
                    <td data-column="Schlüssel" class="ident">{{ schluessel(eintrag) }}</td>

                    <td data-column="Fingerabdruck" class="ident">
                      <span v-if="eintrag.key.keys.length === 0" class="quiet">—</span>

                      <template v-for="k in eintrag.key.keys" :key="k.fingerprint ?? k.uid ?? k.expires ?? 0">
                        <Badge v-if="k.state === 'expired'" kind="critical">abgelaufen</Badge>
                        <Badge v-else-if="k.state === 'soon'" kind="warn">läuft bald ab</Badge>
                        <span class="quiet">{{ abdruck(k.fingerprint) }}</span>
                      </template>
                    </td>

                    <!--
                      **Kein Knopf, wo er nicht gedrückt werden darf**, und
                      daneben der Grund — ein fehlendes Bedienelement ist keine
                      Auskunft (`docs/46 §4`, Kriterium 5): Wer die Quelle
                      abschalten will, sucht sonst weiter.
                    -->
                    <td data-column="Schalten">
                      <button
                        v-if="eintrag.owned"
                        type="button"
                        class="button small"
                        @click="schalten(datei.path, eintrag)"
                      >
                        {{ eintrag.enabled ? 'Ausschalten' : 'Einschalten' }}
                      </button>

                      <span v-else class="quiet">nicht vom Panel angelegt</span>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>

          <!--
            Eine Datei ohne Einträge ist keine leere Liste, sondern eine
            Auskunft: `/etc/apt/sources.list` ist auf einem heutigen Ubuntu nur
            noch ein Hinweistext.
          -->
          <p
            v-if="props.sources.files.every((d) => d.entries.length === 0)"
            class="empty"
          >
            In keiner dieser Dateien steht ein Eintrag.
          </p>
        </template>
      </Section>

      <!--
        **Der vierte Bereich zeigt den wirksamen Zustand und nicht unsere
        Datei** (`docs/81 §6` Punkt 4, `§7` Falle 7).

        Gemessen am 26. August 2026 in diesem Container: `20auto-upgrades` sagt
        für beide Teilschalter `1`, und die Automatik ist trotzdem **aus** —
        `docker-disable-periodic-update` setzt den Hauptschalter auf `0`.
        Deshalb steht hier der Satz zuerst und die Zahlen darunter.
      -->
      <Section title="Unbeaufsichtigte Updates" full>
        <p v-if="props.packages === null" class="empty">
          Ohne den Paketstand ist über die Automatik nichts zu sagen.
        </p>

        <p v-else-if="!props.packages.unattended.readable" class="notice warn">
          <span>
            Der Zustand der Automatik liess sich nicht lesen:
            {{ props.packages.unattended.error }}
          </span>
        </p>

        <template v-else-if="automatik">
          <p v-if="automatikSatz" class="notice" :class="automatikSatz.kind">
            <span>{{ automatikSatz.text }}</span>
          </p>

          <!--
            **Die Dateien, die den Hauptschalter setzen — als Erklärung.**

            Sie stehen nur da, wenn die Automatik aus ist: Dann ist die Frage
            „wer hat das getan?" die einzige, die weiterhilft. Die **letzte**
            gewinnt, weil apt nach ASCII sortiert liest — und Ziffern stehen
            vor Buchstaben, eine `99`-Datei verliert also gegen jede, die mit
            einem Buchstaben beginnt.
          -->
          <p v-if="!automatik.enabled && automatik.setters.length > 0" class="notice neutral">
            <!--
              **Ein ganzer Satz und kein `counted()`.** Die Zahl steht hier
              nicht vor dem Wort, sondern im Satzbau — „setzt eine Datei" gegen
              „setzen zwei Dateien". `counted()` setzt die Zahl an den Anfang
              und ergäbe „setzt 1 diese Datei"; genau so stand es beim ersten
              Wurf auf dem Bild.
            -->
            <span>
              <template v-if="automatik.setters.length === 1">
                Den Hauptschalter setzt diese Datei:
              </template>
              <template v-else>
                Den Hauptschalter setzen {{ automatik.setters.length }} Dateien, und die letzte gewinnt:
              </template>
              <span class="ident">{{ automatik.setters.join(', ') }}</span>
            </span>
          </p>

          <div class="button-row">
            <button
              v-if="automatik.upgrade_days === 0 || !automatik.enabled || !automatik.installed"
              type="button"
              class="button"
              @click="schaltenAutomatik(true)"
            >
              Unbeaufsichtigt installieren einschalten
            </button>

            <button
              v-else
              type="button"
              class="button"
              @click="schaltenAutomatik(false)"
            >
              Unbeaufsichtigt installieren abschalten
            </button>
          </div>

          <div class="scrolls">
            <table class="stacks">
              <thead>
                <tr>
                  <th>Angabe</th>
                  <th>Wirksam</th>
                </tr>
              </thead>

              <tbody>
                <tr>
                  <td data-column="Angabe">Paket unattended-upgrades</td>
                  <td data-column="Wirksam">{{ automatik.installed ? 'installiert' : 'fehlt' }}</td>
                </tr>
                <tr>
                  <td data-column="Angabe">Hauptschalter von apt</td>
                  <td data-column="Wirksam">{{ automatik.enabled ? 'ein' : 'aus' }}</td>
                </tr>
                <tr>
                  <td data-column="Angabe">Paketlisten auffrischen</td>
                  <td data-column="Wirksam">{{ tage(automatik.lists_days) }}</td>
                </tr>
                <tr>
                  <td data-column="Angabe">Unbeaufsichtigt installieren</td>
                  <td data-column="Wirksam">{{ tage(automatik.upgrade_days) }}</td>
                </tr>
                <tr>
                  <td data-column="Angabe">Neustart von selbst</td>
                  <td data-column="Wirksam">{{ automatik.automatic_reboot ? 'ja' : 'nein' }}</td>
                </tr>
                <tr>
                  <td data-column="Angabe">Zuletzt aufgefrischt</td>
                  <td data-column="Wirksam">{{ stempel(automatik.last.lists) }}</td>
                </tr>
                <tr>
                  <td data-column="Angabe">Zuletzt unbeaufsichtigt installiert</td>
                  <td data-column="Wirksam">{{ stempel(automatik.last.upgrade) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!--
            **Die Herkünfte stehen da, weil „unbeaufsichtigt" ohne sie nichts
            heisst.** Gemessen: Ubuntus Vorgabe umfasst nicht nur `-security`,
            sondern auch die Release-Tasche und zwei ESM-Herkünfte. Das Panel
            setzt sie nicht — es betreibt die Automatik nicht, es konfiguriert
            die der Distribution.
          -->
          <p v-if="automatik.origins.length > 0" class="notice neutral">
            <span>
              Unbeaufsichtigt kommt nur, was aus diesen Herkünften stammt:
              <span class="ident">{{ automatik.origins.join(', ') }}</span>
            </span>
          </p>
        </template>
      </Section>
    </div>
  </PanelLayout>
</template>
