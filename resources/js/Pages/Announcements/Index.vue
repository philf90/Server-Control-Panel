<script setup lang="ts">
/*
 * Die Ankündigungen des Betreibers (A14, `docs/103 §5`).
 *
 * **Zugleich der Ort des vollen Textes.** Der Streifen ganz oben zeigt zwei
 * Zeilen (`docs/81 §2.3q` M8); wer mehr will, kommt hierher. Der Text steht
 * deshalb hier **ungekürzt** und nicht noch einmal geklammert.
 */
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import Bands from '../../Components/Bands.vue'
import AnnouncementForm from '../../Components/AnnouncementForm.vue'
import Section from '../../Components/Section.vue'

const props = defineProps<{
  rows: {
    id: number
    category: string
    rank: string
    badge: string
    body: string
    from: string | null
    until: string | null
    audiences: string[]
    state: string
  }[]
  zone: string
  categories: { value: string; label: string }[]
  audiences: { value: string; label: string }[]
}>()

/*
 * **Die Vorschau, und warum sie da ist.**
 *
 * Wer für später ankündigt, sah bisher gar nicht, wie es aussehen wird — der
 * Streifen zeigt nur, was gerade gilt, und das ist richtig so. Entschieden vom
 * Betreiber am 6. September, während des Abnahmelaufs.
 *
 * **Gezeigt wird mit derselben Komponente wie oben** und nicht mit
 * nachgebautem Markup. Eine zweite Fassung des Bandes wäre die, die beim
 * nächsten Umbau stehenbleibt.
 *
 * > **Eine Vorschau, die ihren Gegenstand nachbaut, zeigt irgendwann etwas
 * > anderes als das Original.**
 */
const vorschau = ref<number | null>(null)

const zeigen = (id: number): void => {
  vorschau.value = vorschau.value === id ? null : id
}

/**
 * Der leere Stand für das Anlegeformular.
 *
 * **Alle Publika angehakt und nicht keines.** Eine Ankündigung ohne Publikum
 * sieht niemand; die Voreinstellung ist deshalb die, die etwas bewirkt.
 */
const leer = {
  category: 'info',
  body: '',
  visible_from_date: '',
  visible_from_time: '',
  visible_until_date: '',
  visible_until_time: '',
  audiences: props.audiences.map((a) => a.value),
}

/*
 * **`router.delete` und kein `form.delete`.** Die Zeile trägt kein Formular;
 * was hier reist, ist die Kennung in der Adresse und sonst nichts.
 */
function entfernen(id: number): void {
  router.delete(`/announcements/${id}`, { preserveScroll: true })
}
</script>

<template>
  <PanelLayout title="Ankündigungen" subline="Was im Panel ganz oben steht">
    <div class="sections">
      <!--
        **`full` und nicht die halbe Reihe.** Die Tabelle trägt sechs Spalten,
        darunter den Text; in einer halben Reihe blieb der Behälter bei 1440 px
        auf 548 px, und Fenster, Publikum, Zustand und der Knopf standen
        ausserhalb des Bildes. Gemessen am 5. September 2026.
      -->
      <Section
        full
        title="Angelegt"
        note="Eine Ankündigung verschwindet von selbst, sobald ihr Fenster vorbei ist — gelöscht werden muss nur, was gar nicht mehr gelten soll."
      >
        <!--
          Ohne Ankündigungen steht hier ein Satz und keine leere Tabelle —
          dieselbe Regel wie auf der Bestandsdiagnose.
        -->
        <p v-if="rows.length === 0" class="quiet">
          Es ist nichts angekündigt.
        </p>

        <div v-else class="scrolls">
          <table class="stacks">
            <thead>
              <tr>
                <th>Kategorie</th>
                <th>Text</th>
                <th>Sichtbar</th>
                <th>Publikum</th>
                <th>Zustand</th>
                <th />
              </tr>
            </thead>
            <tbody>
              <!--
                `<template v-for>` und nicht zwei getrennte Schleifen: Die
                Vorschauzeile gehört zu ihrer Zeile und muss deren `zeile`
                sehen.
              -->
              <template v-for="zeile in rows" :key="zeile.id">
              <tr>
                <td data-column="Kategorie">
                  <span class="badge" :class="zeile.badge">{{ zeile.rank }}</span>
                </td>

                <!--
                  Ungekürzt. Der Streifen klammert auf zwei Zeilen; diese Seite
                  ist der Ort, an dem der ganze Satz steht.
                -->
                <td class="text" data-column="Text">{{ zeile.body }}</td>

                <td data-column="Sichtbar">
                  <template v-if="zeile.from || zeile.until">
                    {{ zeile.from ?? 'sofort' }} bis {{ zeile.until ?? 'auf Weiteres' }}
                    <span class="quiet">({{ zone }})</span>
                  </template>
                  <template v-else>ohne Fenster</template>
                </td>

                <td data-column="Publikum">{{ zeile.audiences.join(' · ') }}</td>
                <td data-column="Zustand">{{ zeile.state }}</td>

                <!--
                  **Zwei Knöpfe gehören in eine `.button-row`.** Ohne sie
                  kleben sie aneinander — die Regel in `app.css` sagt das
                  wörtlich, und ihr Anlass war derselbe Fehler auf der
                  PHP-Seite (`docs/38 §24.2`), auch damals vom Betreiber auf
                  dem Server gefunden. Bis zur Vorschau trug diese Zeile genau
                  einen Knopf, und der Abstand war nie eine Frage.
                -->
                <td class="right">
                  <div class="button-row">
                    <button type="button" class="button small" @click="zeigen(zeile.id)">
                      {{ vorschau === zeile.id ? 'Vorschau zu' : 'Vorschau' }}
                    </button>
                    <!--
                      **Der Weg zum Ändern steht an der Zeile** und nicht in
                      einem Formular weiter unten. Bei zehn Ankündigungen läge
                      es auf dem Telefon Bildschirme entfernt — derselbe Fehler
                      wie der Menüpunkt, den `docs/59` dreimal bezahlt hat.

                      > **Vor jedem neuen Merkmal: Wo sucht jemand diese
                      > Handlung, und steht sie dort?**
                    -->
                    <Link class="button small" :href="`/announcements/${zeile.id}/edit`">
                      Ändern
                    </Link>
                    <button type="button" class="button small danger" @click="entfernen(zeile.id)">
                      Entfernen
                    </button>
                  </div>
                </td>
              </tr>

              <!--
                Die Vorschau steht in einer eigenen Zeile unter ihrer Zeile und
                nicht in einer Zelle daneben: Ein Band ist so breit wie der
                Streifen, und in einer Spalte gequetscht zeigte es eine andere
                Umbruchlage als die, um die es geht.
              -->
              <tr v-if="vorschau === zeile.id">
                <td class="preview" colspan="6" data-column="Vorschau">
                  <div class="bands">
                    <Bands :items="[zeile]" />
                  </div>
                </td>
              </tr>
              </template>
            </tbody>
          </table>
        </div>
      </Section>

      <Section title="Neue Ankündigung">
        <!--
          Die Felder stehen in `AnnouncementForm` und nicht hier: Seit dem
          6. September gibt es sie zweimal in Gebrauch — beim Anlegen und beim
          Ändern —, und zwei Fassungen laufen auseinander.
        -->
        <AnnouncementForm
          :announcement="null"
          :values="leer"
          :zone="zone"
          :categories="categories"
          :audiences="audiences"
        />
      </Section>
    </div>
  </PanelLayout>
</template>
