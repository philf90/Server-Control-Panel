<script setup lang="ts">
/*
 * Die Ankündigungen des Betreibers (A14, `docs/103 §5`).
 *
 * **Zugleich der Ort des vollen Textes.** Der Streifen ganz oben zeigt zwei
 * Zeilen (`docs/81 §2.3q` M8); wer mehr will, kommt hierher. Der Text steht
 * deshalb hier **ungekürzt** und nicht noch einmal geklammert.
 */
import { router, useForm } from '@inertiajs/vue3'
import PanelLayout from '../../Layouts/PanelLayout.vue'
import FormErrors from '../../Components/FormErrors.vue'
import Section from '../../Components/Section.vue'

const props = defineProps<{
  announcements: {
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

const form = useForm({
  category: 'info',
  body: '',
  visible_from_date: '',
  visible_from_time: '',
  visible_until_date: '',
  visible_until_time: '',
  audiences: props.audiences.map((a) => a.value),
})

/*
 * **`defaults()` vor `reset()`, und das ist keine Zeremonie.**
 *
 * `form.reset()` allein stellt den Stand vom **Seitenaufbau** her — `docs/84`
 * hat das teuer gelernt: Auf der Zugangsseite kam eine gelöschte Zeile zurück,
 * der Betreiber drückte noch einmal Speichern und legte die Beschränkung wieder
 * an, die er gerade aufgehoben hatte. Beide Vorgänge meldeten Erfolg.
 *
 * > **Eine Anzeige, die den Zustand vor der Änderung zeigt, verleitet zu der
 * > Handlung, die die Änderung zurücknimmt.**
 *
 * Hier ist das Formular ein **Anlegeformular** und nicht eines zum Ändern, also
 * ist der leere Stand der richtige. Die Vorgabe wird trotzdem frisch gesetzt:
 * Sie liest `props`, und was `props` liest, kann veralten — die Regel gilt
 * unabhängig davon, ob es heute schon zutrifft.
 */
function anlegen(): void {
  form.post('/announcements', {
    preserveScroll: true,
    onSuccess: () => {
      form.defaults({
        category: 'info',
        body: '',
        visible_from_date: '',
        visible_from_time: '',
        visible_until_date: '',
        visible_until_time: '',
        audiences: props.audiences.map((a) => a.value),
      })
      form.reset()
    },
  })
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
        <p v-if="announcements.length === 0" class="quiet">
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
              <tr v-for="zeile in announcements" :key="zeile.id">
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

                <td class="right">
                  <button type="button" class="button small danger" @click="entfernen(zeile.id)">
                    Entfernen
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Section>

      <Section title="Neue Ankündigung">
        <!--
          **Die Zusammenfassung steht oben und der Satz nur hier**
          (`docs/19 §6`): Das Feld trägt `aria-invalid` und sonst nichts. Ein
          roter Rand ohne Wort behauptet, das Feld sei falsch, und sagt nicht
          warum.
        -->
        <FormErrors />

        <form @submit.prevent="anlegen">
          <label class="field">
            <span>Kategorie</span>
            <select v-model="form.category" :aria-invalid="Boolean(form.errors.category)">
              <option v-for="k in categories" :key="k.value" :value="k.value">{{ k.label }}</option>
            </select>
          </label>

          <label class="field">
            <span>Text</span>
            <textarea
              v-model="form.body"
              rows="3"
              :maxlength="500"
              :aria-invalid="Boolean(form.errors.body)"
            />
          </label>

          <!--
            **Zwei Felder je Zeitpunkt, und das ist bezahlt** (`docs/102 §2`):
            Ein Textfeld für `Y-m-d H:i` mit `inputmode="numeric"` war auf dem
            iPhone nicht ausfüllbar — die Zifferntastatur gibt weder Bindestrich
            noch Doppelpunkt noch Leerzeichen her.
          -->
          <div class="field-row">
            <label class="field narrow">
              <span>Sichtbar ab</span>
              <input v-model="form.visible_from_date" type="date" :aria-invalid="Boolean(form.errors.visible_from_date)">
            </label>
            <label class="field narrow">
              <span>Uhrzeit</span>
              <input v-model="form.visible_from_time" type="time" :aria-invalid="Boolean(form.errors.visible_from_time)">
            </label>
          </div>

          <div class="field-row">
            <label class="field narrow">
              <span>Sichtbar bis</span>
              <input v-model="form.visible_until_date" type="date" :aria-invalid="Boolean(form.errors.visible_until_date)">
            </label>
            <label class="field narrow">
              <span>Uhrzeit</span>
              <input v-model="form.visible_until_time" type="time" :aria-invalid="Boolean(form.errors.visible_until_time)">
            </label>
          </div>

          <p class="hint">
            Beide Enden dürfen leer bleiben. Die Zeiten gelten in der Anzeigezeitzone ({{ zone }}).
          </p>

          <!--
            **`.choices` mit `.toggle` und keine eigene Klasse.** Der erste Wurf
            schrieb `.choice`, und die gibt es in `app.css` nicht — die Kästchen
            streckten sich dann über die ganze Breite, weil `.field` eine
            Flexspalte ist. Ein Baustein, den man erfindet, statt nachzusehen,
            ist derselbe Fehler wie ein Hexwert in einer Komponente.
          -->
          <div class="field">
            <span>Publikum</span>
            <div class="choices">
              <label v-for="p in audiences" :key="p.value" class="toggle">
                <input v-model="form.audiences" type="checkbox" :value="p.value">
                <span>{{ p.label }}</span>
              </label>
            </div>
          </div>

          <div class="button-row">
            <button type="submit" class="button primary" :disabled="form.processing">
              Ankündigen
            </button>
          </div>
        </form>
      </Section>
    </div>
  </PanelLayout>
</template>
