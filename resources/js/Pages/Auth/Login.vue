<script setup lang="ts">
import { useForm, Head, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import EyeIcon from '../../Components/EyeIcon.vue'
import MarkIcon from '../../Components/MarkIcon.vue'

/*
 * Die Anmeldemaske.
 *
 * Bewusst ohne Navigation und ohne Kacheln: Wer hier steht, hat noch kein
 * Konto vorgewiesen und soll nichts über den Server erfahren — nicht den
 * Hostnamen, nicht die Zahl der Kunden, nicht die Namen der Abonnements.
 *
 * **Die Version ist die eine Ausnahme, und sie ist eine bewusste.** Hier stand
 * sie zunächst mit auf der Liste. Der Betreiber hat sie sich gewünscht, und
 * der Grund ist gut: Solange dieses Panel im Aufbau ist, steht man ständig vor
 * der Frage, welcher Stand auf einem Server eigentlich läuft — und die
 * Anmeldemaske ist die einzige Seite, die man ohne Sitzung sieht. Nach einem
 * Update ist sie der erste Beleg dafür, dass das neue Paket auch wirklich
 * ausgeliefert wird.
 *
 * **Was man dafür hergibt:** Wer die Maske aufruft, erfährt die genaue
 * Fassung, und damit auch, welche bekannten Lücken auf sie zutreffen. Das ist
 * kein Zugang, aber es erspart einem Angreifer das Raten. Vertretbar, weil
 * dieses Panel nicht auf Port 443 im offenen Netz steht, sondern auf einem
 * eigenen Port hinter einer Anmeldung mit zweitem Faktor — und weil eine
 * Version, die niemand ablesen kann, auch niemand meldet.
 */

const form = useForm({
  email: '',
  password: '',
  remember: false,
})

const passwortSichtbar = ref(false)

const page = usePage()
const notice = computed(() => (page.props.flash as Record<string, string> | undefined)?.notice)
const version = computed(() => (page.props.source as { version: string } | undefined)?.version)

function submit(): void {
  form.post('/login', {
    onFinish: () => form.reset('password'),
  })
}
</script>

<template>
  <Head title="Anmeldung" />

  <main class="signin">
    <form class="sheet" @submit.prevent="submit">
      <!--
        Zeichen und Name in einer Zeile. Das Zeichen ist hier grösser als in
        der Seitenleiste: Dort steht es neben einer Navigation und ordnet sich
        unter, hier ist es das einzige Bild auf der Seite.
      -->
      <h1><MarkIcon :size="26" /> SrvPanel</h1>

      <p v-if="notice" class="notice warn">
        <span>{{ notice }}</span>
      </p>

      <label class="field">
        <span>Adresse</span>
        <input
          v-model="form.email"
          type="email"
          name="email"
          autocomplete="username"
          required
          autofocus
        >
      </label>

      <!--
        Das Augensymbol ja, die Prüfliste nein.
        Beim Anmelden gilt keine Richtlinie: Das Passwort ist entweder das
        richtige oder nicht, und eine Liste mit Anforderungen daneben würde
        aussehen, als könne man es hier ändern. Sichtbar machen hilft
        trotzdem — auf einem Handy vertippt man sich an einem langen Passwort
        aus dem Passwortspeicher sonst dreimal.
      -->
      <label class="field">
        <span>Passwort</span>
        <div class="with-reveal">
          <input
            v-model="form.password"
            :type="passwortSichtbar ? 'text' : 'password'"
            name="password"
            autocomplete="current-password"
            required
          >
          <button
            type="button"
            class="reveal"
            :aria-label="passwortSichtbar ? 'Passwort verbergen' : 'Passwort anzeigen'"
            :aria-pressed="passwortSichtbar"
            @click.prevent="passwortSichtbar = !passwortSichtbar"
          >
            <EyeIcon :off="passwortSichtbar" />
          </button>
        </div>
      </label>

      <!--
        Eine Meldung für alles: unbekannte Adresse, falsches Passwort,
        deaktiviertes Konto. Wer unterscheidet, verrät, welche Adressen es
        gibt.
      -->
      <p v-if="form.errors.email" class="error" role="alert">{{ form.errors.email }}</p>

      <label class="toggle">
        <input v-model="form.remember" type="checkbox" name="remember">
        <span>Angemeldet bleiben</span>
      </label>

      <div class="button-row">
        <button type="submit" class="button primary" :disabled="form.processing">
          {{ form.processing ? 'Einen Moment …' : 'Anmelden' }}
        </button>
      </div>
    </form>

    <!--
      Unter der Maske und nicht darin: Die Version gehört nicht zum Formular.
      Stünde sie zwischen Knopf und Rand, läse sie sich wie eine Angabe zur
      Anmeldung — dort unten ist sie eine Fussnote zur Seite, und genau das
      ist sie auch.
    -->
    <p v-if="version" class="release">
      <span class="version">{{ version }}</span>
    </p>
  </main>
</template>

<style scoped>
/*
 * **Von dieser Datei ist fast nichts übrig, und das ist der Punkt.**
 *
 * Hier standen einmal 165 Zeilen: die Karte, das Feld, das Auge, das
 * Ankreuzfeld, der Fokusrahmen, zwei Meldungsfarben — jedes davon eine
 * zweite Fassung von etwas, das es schon gab. Der Rand der Felder kam aus
 * `--line` und erreichte im dunklen Theme 1,45:1 gegen den Seitengrund; die
 * Karte nannte `--surface-border`, eine Marke, die es seit dem Umbau nicht
 * mehr gibt, und hatte deshalb überhaupt keinen Rand.
 *
 * Was bleibt, ist die Stelle der Fussnote — das Einzige an dieser Seite, das
 * keine andere hat.
 */
.release {
  margin: 0;
}
</style>
