<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref } from 'vue'
import FormErrors from '../../Components/FormErrors.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

interface Entry {
  name: string
  path: string
  type: 'file' | 'directory' | 'link'
  size: number
  readable: boolean
}

interface Hit {
  entry: Entry
  match: 'name' | 'content'
  line: { number: number; text: string } | null
}

const props = defineProps<{
  subscription: { id: number; name: string }
  path: string
  query: string
  inContent: boolean
  hits: Hit[]
  visited: number
  truncated: boolean
}>()

const begriff = ref(props.query)
const imInhalt = ref(props.inContent)

/*
 * **`1` und `0`, nicht `true` und `false`** (`docs/66`, Befund 5).
 * `router.get` legt die Werte in die Adresse, und eine Adresse kennt keine
 * Wahrheitswerte: `false` reist als das Wort `"false"`. Laravels Regel
 * `boolean` nimmt `true, false, 1, 0, "1", "0"` — und kein Wort. Die Suche
 * ist deshalb seit P6 Schritt 5 an keinem Tag durchgekommen, in beiden
 * Zuständen des Kästchens.
 */
function suchen(): void {
  router.get(`/subscriptions/${props.subscription.id}/files/search`, {
    query: begriff.value,
    path: props.path,
    content: imInhalt.value ? 1 : 0,
  })
}
</script>

<template>
  <Head :title="`Suche — ${props.subscription.name}`" />

  <PanelLayout title="Suche" :subline="props.subscription.name">
    <FormErrors />

    <form class="button-row" @submit.prevent="suchen">
      <label class="field inline">
        <span>Suchbegriff</span>
        <input v-model="begriff" type="search" autocomplete="off" required />
      </label>
      <!--
        **`toggle` und nicht `field inline`.** Hier stand ein `.field`, und
        `.field input` gibt jedem Feld volle Breite und `--tap` Höhe — für ein
        Textfeld richtig, für ein Kästchen nicht. Bei 390 px stand daraus ein
        grosser leerer Kasten mitten in der Leiste (`docs/64`, Befund 1); der
        Dokumentüberlauf war dabei **0 px**.

        > **Ein Baustein, der die Regel eines anderen erbt, sieht aus wie der
        > andere.**

        `.toggle` nimmt beides ausdrücklich zurück und ist die Form, die fünf
        andere Seiten für ein Kästchen benutzen.
      -->
      <label class="toggle">
        <input v-model="imInhalt" type="checkbox" />
        <span>auch im Inhalt</span>
      </label>
      <button type="submit" class="button primary">Suchen</button>
    </form>

    <p class="quiet">
      Gesucht unter <span class="ident">{{ props.path }}</span> — angesehene Einträge: {{ props.visited }}.
    </p>

    <!--
      Ohne diesen Hinweis behauptet eine kurze Liste, es gebe nicht mehr —
      wo „nicht zu Ende gesucht" richtig wäre.
    -->
    <p v-if="props.truncated" class="notice warn">
      Der Suchlauf ist nicht zu Ende gelaufen. Angezeigt wird, was bis dahin gefunden wurde;
      ein engerer Startpfad findet den Rest.
    </p>

    <div class="scrolls">
      <table class="stacks">
        <thead>
          <tr><th>Datei</th><th>Fundstelle</th></tr>
        </thead>
        <tbody>
          <tr v-for="hit in props.hits" :key="hit.entry.path">
            <td data-column="Datei" class="cell-name">
              <Link
                v-if="hit.entry.type === 'file' && hit.entry.readable"
                :href="`/subscriptions/${props.subscription.id}/files/edit?path=${encodeURIComponent(hit.entry.path)}`"
                class="link"
              >
                {{ hit.entry.path }}
              </Link>
              <span v-else>{{ hit.entry.path }}</span>
            </td>
            <td data-column="Fundstelle" class="cell-name">
              <span v-if="hit.match === 'name'" class="quiet">im Namen</span>
              <template v-else-if="hit.line">
                <span class="quiet">Zeile {{ hit.line.number }}:</span> {{ hit.line.text }}
              </template>
              <span v-else class="quiet">im Inhalt</span>
            </td>
          </tr>
          <tr v-if="props.hits.length === 0">
            <td colspan="2" class="quiet">Nichts gefunden.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </PanelLayout>
</template>
