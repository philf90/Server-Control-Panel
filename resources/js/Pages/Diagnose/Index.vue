<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Section from '../../Components/Section.vue'
import { counted } from '../../Composables/useCounted'

/**
 * Ein Befund, wie ihn der Nachtlauf hinterlassen hat.
 *
 * `detail` fehlt beim Administrator **ganz** und ist nicht leer: Der Wortlaut
 * der Werkzeuge gehört dem Betreiber, und was er nicht sehen darf, wird nicht
 * geschickt (`docs/98 §9` Frage 5).
 */
type Finding = {
  id: number
  check: string
  check_label: string
  subject: string
  subject_label: string
  reason: string
  sentence: string
  state: string
  state_label: string
  badge: string
  rank: number
  first_seen_at: string | null
  measured_at: string | null
  detail?: string | null
}

const props = defineProps<{
  findings: Finding[]
  ran_at: string | null
  verbatim: boolean
}>()

const kaputt = computed(() => props.findings.filter((f) => f.state === 'fail').length)
const ungemessen = computed(() => props.findings.filter((f) => f.state === 'unknown').length)
const hinsehen = computed(() => props.findings.filter((f) => f.state === 'warn').length)

/**
 * Ob überhaupt schon einmal gemessen wurde.
 *
 * **Nicht dasselbe wie „nichts gefunden".** Vor dem ersten Lauf ist die Tabelle
 * genauso leer wie danach auf einem heilen Server; ohne diesen Unterschied gäbe
 * die Seite Entwarnung für etwas, das niemand angesehen hat.
 */
const gemessen = computed(() => props.ran_at !== null)
</script>

<template>
  <Head title="Diagnose" />

  <PanelLayout title="Diagnose" subline="Was an diesem Server nicht stimmt — gemessen und nicht geraten">
    <!--
      **Vor dem ersten Lauf schweigt die Seite.** Eine leere Liste bedeutet hier
      zweierlei, und nur eine der beiden Bedeutungen ist eine Entwarnung.
    -->
    <p v-if="!gemessen" class="notice warn">
      Die Bestandsdiagnose ist auf diesem Server noch nie gelaufen. Die leere Liste unten
      heisst deshalb nicht „alles in Ordnung", sondern „noch nicht nachgesehen".
    </p>

    <!--
      **Der ganze Satz in einem `span`.** `.notice` ist eine Flexbox; ein
      Textknoten neben einem Element ergäbe zwei Flexkinder, und bei 390 px
      stünde ein Wort mit fünf Pixeln Breite neben dem Rest des Satzes. Der
      Überlauf ist dabei 0 — die Messung der Bilderrunde findet das nicht.
    -->
    <p v-else-if="kaputt > 0" class="notice critical">
      <span>
        {{ counted(kaputt, 'Befund ist', 'Befunde sind') }} kaputt.
        <template v-if="ungemessen > 0">
          {{ counted(ungemessen, 'weitere Prüfung ist', 'weitere Prüfungen sind') }} nicht durchgelaufen.
        </template>
      </span>
    </p>

    <p v-else-if="ungemessen > 0" class="notice warn">
      {{ counted(ungemessen, 'Prüfung ist', 'Prüfungen sind') }} nicht durchgelaufen. Über
      diese Stellen ist gerade nichts bekannt — weder im Guten noch im Schlechten.
    </p>

    <p v-else-if="hinsehen > 0" class="notice warn">
      {{ counted(hinsehen, 'Befund wartet', 'Befunde warten') }} darauf, dass jemand hinsieht.
    </p>

    <!--
      **Keine grüne Meldung.** Die grüne Marke gehört dem Layout: Erfolg ist eine
      Aussage über einen Vorgang, und hier steht ein Zustand. Derselbe Grund wie
      auf der Dienste-Seite.
    -->
    <p v-else class="notice">Der letzte Lauf hat nichts gefunden.</p>

    <div class="sections">
      <Section
        title="Befunde"
        full
        :note="ran_at ? `Zuletzt gemessen: ${ran_at}. Ein Befund verschwindet von selbst, sobald der nächste Lauf ihn nicht mehr findet.` : undefined"
      >
        <!--
          **Ohne Befunde steht hier ein Satz und keine leere Tabelle.** Eine
          Tabelle mit Kopfzeile und nichts darunter sieht aus, als sei etwas
          schiefgegangen.
        -->
        <p v-if="findings.length === 0" class="quiet">
          {{ gemessen ? 'Keine Befunde.' : 'Noch keine Messung.' }}
        </p>

        <div v-else class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th>Prüfung</th>
                <th>Ort</th>
                <th>Zustand</th>
                <th>Befund</th>
                <th>Steht seit</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="zeile in findings" :key="zeile.id">
                <td data-column="Prüfung">{{ zeile.check_label }}</td>

                <!--
                  Der Ort ist eine Kennung — ein Pfad, ein Unitname, eine Domain.
                  `ident` bricht ihn an jeder Stelle; ohne das schiebt eine
                  Vhost-Datei die Seite auf dem Telefon aus dem Bild.
                -->
                <td data-column="Ort"><span class="ident">{{ zeile.subject }}</span></td>

                <td data-column="Zustand">
                  <span class="badge" :class="zeile.badge">{{ zeile.state_label }}</span>
                </td>

                <!--
                  **`multiline`, und das hat erst das Bild gezeigt.** Eine
                  gestapelte Zelle ist eine Flexbox mit `space-between`; der
                  Satz und der Wortlaut darunter wurden damit zwei Spalten, und
                  bei 390 px brach jede Zeichen für Zeichen um. Der Überlauf war
                  dabei 0 — dieselbe Falle wie ein Textknoten neben einem
                  Element in einer Meldung, eine Zeile weiter.

                  > **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl —
                  > nur einen Betrachter.**
                -->
                <td data-column="Befund" class="multiline finding">
                  {{ zeile.sentence }}

                  <!--
                    **Der Wortlaut des Werkzeugs, unverändert.** Er wird gezeigt
                    und nicht gedeutet: Keiner der drei Prüfer übersetzt, und
                    das ist eine Zusage über die Programme und keine über ihre
                    Fassungen (`docs/98 §11`).
                  -->
                  <pre v-if="zeile.detail" class="output detail">{{ zeile.detail }}</pre>
                </td>

                <td data-column="Steht seit">
                  {{ zeile.first_seen_at ?? '—' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!--
          Dem Administrator sagen, dass es mehr gibt und wo es liegt. Eine
          fehlende Spalte ohne Erklärung liest sich wie ein Fehler.
        -->
        <p v-if="!verbatim && findings.length > 0" class="quiet">
          Den ungekürzten Wortlaut der Werkzeuge sieht der Betreiber.
        </p>
      </Section>
    </div>
  </PanelLayout>
</template>

<style scoped>
/*
 * Form und Farbe der Ausgabe stehen in `app.css` als `.output` — hier steht
 * nur, was diese Seite daran anders braucht. Dasselbe Vorgehen wie auf der
 * Vorgangsseite, und derselbe Grund: Ein zweites `pre` mit eigenen Farben wäre
 * dieselbe Falle wie ein Hexwert in einer Komponente.
 */
.output {
  margin: 8px 0 0;
}

/*
 * **Der Umbruch, und er ist der Grund für diesen Block.** `.output` setzt
 * `pre-wrap` auf sein Kind (`> div`); hier steht der Text unmittelbar im `pre`,
 * und ein `pre` bricht von sich aus nicht. Ohne diese Regel rollt die Zelle bei
 * 390 px waagerecht — der Fehler, den diese Tabellenform in P5c dreimal hatte,
 * und der im Dokument keine Zahl erzeugt:
 *
 *   Eine Zelle, die rollen darf, hat keine Obergrenze — sie hat nur keine Zahl,
 *   die sich beschwert.
 *
 * `overflow-wrap: anywhere` samt `min-width: 0`, weil ein Flexkind sonst nicht
 * unter seine Inhaltsbreite darf — die vierte Fassung derselben Ausnahme in
 * diesem Panel, und jedes Mal aus demselben Grund.
 */
.detail {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  min-width: 0;
}

/*
 * **Zwei Deckel, und sie sind gemessen.**
 *
 * `app.css` gibt einer Tabelle im Rollbehälter `width: max-content` — sie wird
 * so breit wie ihr längster Inhalt und rollt. Das trägt für die neun anderen
 * Tabellen dieses Panels, weil in ihren Zellen kurze Werte stehen; hier stehen
 * ein **Satz** und der Wortlaut eines Werkzeugs.
 *
 * Gemessen am 2. September 2026 im echten Chromium bei 1440 px, Behälter
 * 1140 px:
 *
 *     ohne Deckel                    Tabelle 2405 px, Überstand 1265
 *     nur der Wortlaut gedeckelt     Tabelle 1711 px, Überstand  571
 *     beide Deckel                   Tabelle 1140 px, Überstand    0
 *
 * Zur Einordnung, gemessen am selben Tag: `/services` und `/operations` haben
 * bei 1440 px **Überstand 0**. Keine andere Seite dieses Panels rollt dort —
 * dies wäre die erste gewesen.
 *
 * > **Ein Rollbehälter, den nur eine Seite braucht, ist kein Merkmal des
 * > Bausteins, sondern ein Befund über diese Seite.**
 *
 * **Der Deckel bindet die Breite an die Zeichenzahl und nicht an die Daten.**
 * Ein längerer Satz macht die Tabelle damit nicht breiter, sondern die Zelle
 * höher — der Unterschied zwischen einer Spalte, die man einstellt, und einer,
 * die der nächste Kunde einstellt.
 *
 * **Und die Ort-Spalte bekommt keinen.** Der erste Wurf gab ihr 22ch, und die
 * Messung meldete `schiebt: td.place`: `app.css` setzt `td .ident` auf
 * `white-space: nowrap`, damit eine Kennung lesbar bleibt. Ein Deckel darüber
 * schneidet sie nicht ab, er lässt sie aus der Zelle laufen.
 *
 * > **Ein Deckel über einem Inhalt, der nicht brechen darf, ist keine Grenze —
 * > er ist ein Überlauf.**
 *
 * Bleibt eine Kennung so lang, dass die Tabelle nicht mehr passt, rollt der
 * Behälter. Das ist die Antwort dieses Panels auf breite Inhalte und gilt für
 * jede seiner Tabellen.
 */
.finding {
  max-width: 38ch;
}
</style>
