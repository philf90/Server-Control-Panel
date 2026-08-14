<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import CodeEditor from '../../Components/CodeEditor.vue'
import FormErrors from '../../Components/FormErrors.vue'
import PanelLayout from '../../Layouts/PanelLayout.vue'

interface Entry {
  name: string
  path: string
  size: number
  mode: number
  modified_at: number
  writable: boolean
}

const props = defineProps<{
  subscription: { id: number; name: string }
  path: string
  entry: Entry | null
  content: string | null

  /**
   * Zwei getrennte Angaben, weil sie zwei verschiedene Sätze verlangen.
   *
   * „Zu gross zum Öffnen" und „keine Textdatei" sind für den Kunden zwei
   * verschiedene Auskünfte und zwei verschiedene nächste Schritte. Eine
   * gemeinsame Fahne hiesse „geht nicht" und liesse ihn raten.
   */
  binary: boolean
  tooLarge: boolean
  can: { edit: boolean }
}>()

const form = useForm({ path: props.path, content: props.content ?? '' })

const directory = computed(() => {
  const parts = props.path.split('/').filter(Boolean)
  parts.pop()

  return '/' + parts.join('/')
})

/*
 * Der Editor steht in `CodeEditor.vue` und wird dort nachgeladen.
 *
 * **Diese Seite ist die einzige, die ihn einbindet** — Auflage 1 aus
 * `docs/51 §8.1`, und `FrontendDependencyTest` rechnet nach, dass es dabei
 * bleibt. Wer nie eine Datei bearbeitet, lädt keine Zeile davon.
 */
function save(): void {
  form.put(`/subscriptions/${props.subscription.id}/files`, { preserveScroll: true })
}

function back(): void {
  router.get(`/subscriptions/${props.subscription.id}/files`, { path: directory.value })
}
</script>

<template>
  <Head :title="`${props.entry?.name ?? 'Datei'} — ${props.subscription.name}`" />

  <PanelLayout title="Datei bearbeiten" :subline="props.subscription.name">
    <!--
      Der Pfad steht als eigene Zeile und nicht in der Überschrift. `docs/46
      §20.11` hat gemessen, was ein Bereichstitel mit einem 63 Zeichen langen
      Namen anrichtet: 99px Überlauf bei 390px. Ein Dateiname darf 255.
    -->
    <FormErrors />

    <p class="path-line ident">{{ props.path }}</p>

    <p v-if="props.tooLarge" class="notice warn">
      Diese Datei ist zu gross für den Editor. Sie lässt sich über SFTP herunterladen und ersetzen.
    </p>

    <p v-else-if="props.binary" class="notice warn">
      Diese Datei ist keine Textdatei — sie enthält Zeichen, die sich nicht als Text lesen lassen.
      Der Editor öffnet sie nicht, damit ihr Inhalt beim Speichern nicht beschädigt wird.
    </p>

    <form v-else @submit.prevent="save">
      <!--
        `wide`, weil diese Seite kein Formular ist, sondern ein Editor: Die
        Formularbreite von 540px ist für Fliesstext gedacht, und Quelltext hat
        Zeilen von hundert Zeichen (`docs/53`, Befund 9).
      -->
      <label class="field wide">
        <span>Inhalt</span>
        <CodeEditor
          v-model="form.content"
          :filename="props.entry?.name ?? ''"
          :readonly="!props.can.edit || !(props.entry?.writable ?? false)"
        />
      </label>

      <p v-if="!(props.entry?.writable ?? false)" class="quiet">
        Diese Datei gehört nicht dem Abonnement — sie lässt sich lesen und nicht ändern.
      </p>

      <div class="button-row">
        <button
          type="submit"
          class="button primary"
          :disabled="form.processing || !props.can.edit || !(props.entry?.writable ?? false)"
        >
          Speichern
        </button>
        <button type="button" class="button" @click="back">Zurück zur Liste</button>
      </div>
    </form>
  </PanelLayout>
</template>
