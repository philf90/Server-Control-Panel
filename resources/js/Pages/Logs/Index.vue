<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import { computed, reactive, watch } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'
import { counted } from '../../Composables/useCounted'
import { formatBytes } from '../../bytes'

/*
 * Die Protokolle des Servers.
 *
 * **Quelle und Filter stehen in der Adresszeile**, nicht im Zustand der Seite
 * — dieselbe Entscheidung wie beim Protokoll (`Audit/Index.vue`): Ein Blick
 * lässt sich damit weitergeben und als Lesezeichen behalten, und das
 * Herunterladen bekommt dieselben Werte mit, ohne sie ein zweites Mal
 * einzusammeln.
 *
 * **Keine Wahrheitswerte in der Adresse.** `router.get` legt seine Werte in
 * die URL, und dort ist alles eine Zeichenkette: Aus `false` wird das Wort
 * `"false"`, und Laravels Regel `boolean` nimmt kein Wort (`docs/66`). Diese
 * Seite hat deshalb einen Textfilter und kein Kästchen.
 */

interface Source {
  key: string
  kind: string
  label: string
  origin: string
  exists: boolean | null
  size: number | null
  modified_display: string | null
}

const props = defineProps<{
  sources: Source[]
  source: string
  lines: number
  filter: string
  result: {
    lines: string[]

    // Die Lage jeder gezeigten Zeile im gelesenen Fenster, nullbasiert.
    // Daraus entsteht die Nummer — siehe `nummer()`.
    offsets: number[]
    exists: boolean
    note: string | null

    // Wie viele Zeilen das Fenster wirklich hatte. Hier stand `window` mit
    // der Konstante 500, und die Fusszeile hat daraus „gelesen wurden die
    // letzten 500 Zeilen" gebaut — auch bei einer Datei mit 118 Zeilen.
    read: number

    // Hat das Fenster den Anfang der Quelle erreicht, und hat der Bytedeckel
    // zugeschlagen. Zwei Felder und nicht eines: Sie sind die beiden Gründe,
    // aus denen ein Fenster unvollständig sein kann, und die Abhilfe für den
    // einen lässt den anderen stehen.
    complete: boolean
    capped: boolean
    matched: number

    // „Es gibt mehr Treffer als gezeigt" — und damit die einzige Bedingung,
    // unter der „Mehr Zeilen" etwas bewirkt.
    truncated: boolean
  }
  error: string | null
}>()

const auswahl = reactive({ source: props.source, filter: props.filter })

let timer: ReturnType<typeof setTimeout> | undefined

watch(auswahl, () => {
  if (timer) clearTimeout(timer)

  // Entprellt, weil der Filter beim Tippen läuft und jede Anfrage den Agenten
  // eine Datei lesen lässt.
  timer = setTimeout(() => {
    router.get('/logs', { ...auswahl, lines: props.lines }, { preserveState: true, replace: true })
  }, 300)
})

const gewaehlt = computed(() => props.sources.find((s) => s.key === props.source))

function mehr(): void {
  router.get('/logs', { ...auswahl, lines: Math.min(500, props.lines * 2) })
}

/**
 * Die Nummer einer gezeigten Zeile.
 *
 * **Zwei Bedeutungen, und die Fusszeile sagt welche.** Hat das Fenster den
 * Anfang der Quelle erreicht, ist `offset + 1` die echte Zeilennummer der
 * Datei. Sonst ist nur der Abstand zum Ende belegbar: `−1` ist die letzte
 * Zeile, `−17` die siebzehnte von hinten. Für das Journal gibt es die erste
 * Bedeutung gar nicht — dort sind es Einträge und keine Zeilen einer Datei.
 *
 * Eine fortlaufende `1..n` über das Angezeigte wäre die dritte Möglichkeit
 * und die einzige, die lügt: Mit gesetztem Filter sind die Zeilen nicht
 * zusammenhängend.
 */
function nummer(i: number): string {
  const offset = props.result.offsets[i]

  if (offset === undefined) return ''

  return props.result.complete ? String(offset + 1) : `−${props.result.read - offset}`
}

function ladeUrl(): string {
  const query = new URLSearchParams({ ...auswahl, lines: String(props.lines) })

  return `/logs/download?${query.toString()}`
}

/*
 * Die Grösse einer Protokolldatei.
 *
 * **`formatBytes` und keine eigene Staffel.** Hier stand beim Bau von A5 eine
 * dritte Fassung derselben Umrechnung — und eine schlechtere: ohne
 * Tausendertrennung, ohne GB, mit `toFixed` statt der deutschen Schreibweise.
 * Eine Datei von 1,2 GB las sich als „1234.6 MB".
 *
 * Gefunden hat es `SizeUnitTest`, der genau dafür existiert. Dass er es erst
 * jetzt gemeldet hat, liegt nicht an ihm: Die CI läuft auf `push` nur für
 * `main`, und auf diesem Zweig ist sie bis heute kein einziges Mal gefahren.
 *
 * > **Ein Wächter, den man nicht fährt, ist von einem, den es nicht gibt, nicht
 * > zu unterscheiden.**
 *
 * `null` heisst „nicht gemessen" — die Unterscheidung trifft der Aufrufer, weil
 * nur er weiss, wie sie an seiner Stelle heisst.
 */
function groesse(bytes: number | null): string {
  return bytes === null ? '—' : formatBytes(bytes)
}
</script>

<template>
  <Head title="Logs" />

  <PanelLayout title="Logs" subline="Die Protokolle dieses Servers">
    <template #actions>
      <!--
        **„Angezeigtes sichern" und nicht „Herunterladen".** Die Antwort des
        Agenten ist auf knapp ein Megabyte begrenzt, ein Zugriffsprotokoll ist
        ein Vielfaches davon. Ein Knopf, der die ganze Datei verspräche, gäbe
        stillschweigend die letzten Zeilen.

          Ein Knopf, der mehr verspricht, als der Weg dahinter trägt, ist eine
          Zusage und keine Bequemlichkeit.
      -->
      <a :href="ladeUrl()" class="button">Angezeigtes sichern</a>
    </template>

    <div class="sections">
      <Section title="Quelle" full>
        <div class="filter">
          <label class="field">
            <span>Protokoll</span>
            <select v-model="auswahl.source">
              <option v-for="s in props.sources" :key="s.key" :value="s.key">{{ s.label }}</option>
            </select>
          </label>

          <label class="field">
            <span>Filter</span>
            <input v-model="auswahl.filter" type="text" placeholder="z. B. error">
          </label>
        </div>

        <!--
          Woher die Zeilen kommen, steht als Kennung da — ein Pfad oder ein
          Unitname. Der Betreiber soll dieselbe Datei über SSH wiederfinden.
        -->
        <p v-if="gewaehlt" class="breadcrumb ident">
          {{ gewaehlt.origin }}
          <template v-if="gewaehlt.kind === 'file' && gewaehlt.exists">
            · {{ groesse(gewaehlt.size) }}
            <template v-if="gewaehlt.modified_display">· zuletzt {{ gewaehlt.modified_display }}</template>
          </template>
        </p>
      </Section>

      <Section title="Zeilen" full>
        <!--
          **Fünf verschiedene Auskünfte, und keine sieht aus wie eine andere.**
          Der Agent antwortet nicht · das Journal gibt es auf diesem Server
          nicht · die Datei gibt es noch nicht · es gibt sie und sie ist leer ·
          der Filter passt auf nichts. Eine leere Liste für alle fünf wäre die
          bequeme Lösung — und sähe im ersten Fall aus, als sei alles in
          Ordnung.

            Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts
            zu tun".
        -->
        <p v-if="props.error" class="notice critical">
          Der Agent antwortet nicht: {{ props.error }}
        </p>

        <template v-else>
          <p v-if="props.result.note" class="notice">{{ props.result.note }}</p>

          <p v-if="!props.result.exists && props.result.lines.length === 0" class="empty">
            <template v-if="gewaehlt?.kind === 'journal'">
              Für diese Unit steht nichts im Journal.
            </template>
            <template v-else>
              Dieses Protokoll gibt es noch nicht. Es entsteht, sobald etwas hineingeschrieben wird.
            </template>
          </p>

          <p v-else-if="props.result.lines.length === 0" class="empty">
            <template v-if="props.filter">Auf diesen Filter passt keine Zeile.</template>
            <template v-else>Das Protokoll ist leer.</template>
          </p>

          <template v-else>
            <!--
              **Eine Zeile ist ein Element und kein Stück Text.** Hier stand
              ein `join('\n')` in einem `<pre>`; eine Nummer daneben ist damit
              nur möglich, indem man sie in den Text schreibt — und dann geht
              sie beim Kopieren mit.

              Ein `<div>` und nicht ein `<pre>`: Vue erhält den Leerraum der
              Vorlage innerhalb eines `<pre>`, und dann steht die Einrückung
              dieser Datei im Protokoll. Die Form kommt ohnehin aus `.output`,
              der Zeilenumbruch aus `.log-text`.
            -->
            <div class="output log">
              <span class="log-body">
                <span v-for="(zeile, i) in props.result.lines" :key="i" class="log-line">
                  <span class="log-number" :data-nummer="nummer(i)" aria-hidden="true" />
                  <span class="log-text">{{ zeile }}</span>
                </span>
              </span>
            </div>

            <div class="button-row footer-row">
              <!--
                Der Satz nennt beide Zahlen, weil eine allein etwas Falsches
                sagt: `matched` sind die Treffer im **gelesenen Fenster** und
                nicht die Zeilen der Datei.

                **`read` und nicht `window`.** Hier stand die Konstante 500,
                und der Satz behauptete sie auch für eine Datei mit 118
                Zeilen (`docs/86`, Befund 14).
              -->
              <p class="quiet">
                {{ counted(props.result.lines.length, 'Zeile', 'Zeilen') }}
                <template v-if="props.result.truncated">
                  von {{ counted(props.result.matched, 'Treffer', 'Treffern') }}
                </template>
                · gelesen wurden die letzten
                {{ counted(props.result.read, 'Zeile', 'Zeilen') }}
              </p>

              <!--
                **`truncated` und nicht `props.lines < 500`.** Das Fenster ist
                immer 500 Zeilen gross; `lines` schneidet nur das Ergebnis.
                Der Knopf liest also nichts nach, er schneidet weniger ab —
                und bewirkt genau dann etwas, wenn es mehr Treffer gibt als
                gezeigte Zeilen. Unter der alten Bedingung stand er auch da,
                wenn schon alles zu sehen war: dreimal drücken, dreimal
                nichts.
              -->
              <button v-if="props.result.truncated" type="button" class="button" @click="mehr">
                Mehr Zeilen ({{ props.lines }} → {{ Math.min(500, props.lines * 2) }})
              </button>
            </div>

            <!--
              Was die Nummern bedeuten, steht dabei — sie bedeuten zweierlei,
              und ohne diesen Satz wüsste der Leser nicht, welches.
            -->
            <p class="quiet log-note">
              <template v-if="props.result.complete">
                Das ist die ganze Quelle; die Nummern sind ihre Zeilen.
              </template>
              <template v-else>
                Die Nummern zählen vom Ende: −1 ist die letzte Zeile.
              </template>
              <template v-if="props.result.capped">
                Weiter zurück wurde nicht gelesen — das Fenster ist auch in
                Bytes begrenzt.
              </template>
            </p>
          </template>
        </template>
      </Section>
    </div>
  </PanelLayout>
</template>

<style scoped>
/*
 * Das Protokoll rollt in beide Richtungen und bricht keine Zeile um.
 *
 * Dieselbe Entscheidung und derselbe Grund wie bei `Domains/Logs.vue`: Eine
 * umgebrochene Zeile eines Protokolls ist unlesbar, weil man nicht mehr
 * erkennt, wo ein Eintrag anfängt. Auf 390 px rollt sie waagerecht — wie die
 * Tabellen unter `.scrolls`.
 *
 * Form und Farbe kommen aus `.output` in app.css; hier steht nur, was dieses
 * eine Protokoll davon unterscheidet.
 */
.log {
  margin: 0;
  max-height: 60dvh;
  overflow: auto;
}

/*
 * **Die Hülle spannt die volle Rollbreite auf, und das ist tragend.**
 *
 * Ein klebendes Element kann seinen eigenen Kasten nicht verlassen. Ohne diese
 * Hülle ist jede Zeile nur so breit wie der Sichtbereich; rollt man nach
 * rechts, wandert ihr Kasten mit hinaus, und die Nummer geht mit. Gemessen am
 * 13. September 2026 an der echten Seite: nach `scrollLeft = 3000` stand die
 * Nummer bei **−1908 px**, also weit ausserhalb (`docs/914 §13`).
 *
 * `max-content` macht die Hülle so breit wie die längste Zeile, `min-width`
 * hält sie bei kurzem Inhalt auf voller Breite — sonst endete der Streifen vor
 * dem rechten Rand.
 *
 * > **Ein Wächter, der die Angabe prüft, hat über die Wirkung nichts gesagt.**
 * > `position: sticky` und `left: 0` standen die ganze Zeit da.
 */
.log-body {
  display: block;
  width: max-content;
  min-width: 100%;
}

/*
 * **Eine Zeile ist eine Flexreihe aus Nummer und Text.** Der Leerraum der
 * Vorlage zwischen den beiden fällt damit weg, ohne dass er in der Vorlage
 * vermieden werden müsste — Flex verwirft Kinder, die nur aus Leerraum
 * bestehen.
 */
.log-line {
  display: flex;
  align-items: flex-start;
}

/*
 * **Die Nummer bleibt beim waagerechten Rollen stehen.** Ohne `sticky` ist
 * sie bei einer langen Zeile ausserhalb des Sichtbaren — also genau dann
 * fort, wenn man sie braucht. Der Grund ist derselbe wie beim Rinnstein eines
 * Editors, und `docs/56` hat für den Dateieditor schon entschieden, dass
 * Rollen hier richtig ist und Umbrechen falsch.
 *
 * **Die Fläche ist nicht Zierde.** Ohne sie rollt der Text der Zeile sichtbar
 * unter der Nummer hindurch.
 *
 * **Die Nummer ist erzeugter Inhalt und kein Text — und das ist gemessen.**
 * Der Plan sah `user-select: none` dafür vor. Am 13. September 2026 an der
 * echten Seite gemessen, mit der Maus über drei Zeilen gezogen: Die Auswahl
 * enthielt `⏎ 20 ⏎ … ⏎ 21 ⏎`, also die Nummern. `user-select: none` hält den
 * Cursor ab, eine Auswahl, die über das Element **hinweggeht**, nicht.
 *
 * > **Eine Regel, die das Auswählen verbietet, verbietet nicht das
 * > Ausgewähltwerden.**
 *
 * Was trägt, ist `content: attr(…)`: Erzeugter Inhalt steht nicht im
 * Dokument und wird deshalb nicht kopiert. `user-select: none` bleibt
 * daneben stehen — es hält die Einfügemarke davon ab, in der Spalte zu
 * landen.
 */
.log-number {
  position: sticky;
  left: 0;
  flex: none;
  min-width: 4ch;
  padding-right: var(--gap);
  text-align: right;
  color: var(--text-muted);
  background: var(--surface);
  user-select: none;
}

.log-number::before {
  content: attr(data-nummer);
}

/*
 * Der Umbruch sitzt hier und nicht am Rahmen: Der Rahmen soll den Leerraum
 * der Vorlage verwerfen, die Zeile ihn erhalten. `flex: none`, damit eine
 * lange Zeile den Rollbehälter benutzt, statt sich zusammenstauchen zu
 * lassen.
 */
.log-text {
  flex: none;
  white-space: pre;
}

.log-note {
  margin-top: 0;
}

.footer-row {
  align-items: baseline;
  justify-content: space-between;
  margin-top: var(--gap);
}
</style>
