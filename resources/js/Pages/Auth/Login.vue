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

  <main class="anmeldung">
    <form class="maske" @submit.prevent="submit">
      <!--
        Zeichen und Name in einer Zeile. Das Zeichen ist hier grösser als in
        der Seitenleiste: Dort steht es neben einer Navigation und ordnet sich
        unter, hier ist es das einzige Bild auf der Seite.
      -->
      <h1><MarkIcon :size="26" /> SrvPanel</h1>

      <p v-if="notice" class="hinweis">{{ notice }}</p>

      <!--
        Beschriftung und Feld stehen zusammen in einer Gruppe.
        Vorher hingen sie als Geschwister in derselben Flex-Spalte mit
        gleichmässigem Abstand — die Beschriftung stand dann genauso weit vom
        eigenen Feld entfernt wie vom fremden darüber, und das Auge ordnet sie
        beim Überfliegen dem falschen zu.
      -->
      <div class="feld">
        <label for="email">Adresse</label>
        <input
          id="email"
          v-model="form.email"
          type="email"
          name="email"
          autocomplete="username"
          required
          autofocus
        >
      </div>

      <!--
        Das Augensymbol ja, die Prüfliste nein.
        Beim Anmelden gilt keine Richtlinie: Das Passwort ist entweder das
        richtige oder nicht, und eine Liste mit Anforderungen daneben würde
        aussehen, als könne man es hier ändern. Sichtbar machen hilft
        trotzdem — auf einem Handy vertippt man sich an einem langen Passwort
        aus dem Passwortspeicher sonst dreimal.
      -->
      <div class="feld">
        <label for="password">Passwort</label>
        <div class="mit-auge">
          <input
            id="password"
            v-model="form.password"
            :type="passwortSichtbar ? 'text' : 'password'"
            name="password"
            autocomplete="current-password"
            required
          >
          <button
            type="button"
            class="auge"
            :aria-label="passwortSichtbar ? 'Passwort verbergen' : 'Passwort anzeigen'"
            :aria-pressed="passwortSichtbar"
            @click="passwortSichtbar = !passwortSichtbar"
          >
            <EyeIcon :off="passwortSichtbar" />
          </button>
        </div>
      </div>

      <!--
        Eine Meldung für alles: unbekannte Adresse, falsches Passwort,
        deaktiviertes Konto. Wer unterscheidet, verrät, welche Adressen es
        gibt.
      -->
      <p v-if="form.errors.email" class="fehler" role="alert">{{ form.errors.email }}</p>

      <label class="merken">
        <input v-model="form.remember" type="checkbox" name="remember">
        <span>Angemeldet bleiben</span>
      </label>

      <button type="submit" class="knopf wichtig" :disabled="form.processing">
        {{ form.processing ? 'Einen Moment …' : 'Anmelden' }}
      </button>
    </form>

    <!--
      Unter der Maske und nicht darin: Die Version gehört nicht zum Formular.
      Stünde sie zwischen Knopf und Rand, läse sie sich wie eine Angabe zur
      Anmeldung — dort unten ist sie eine Fussnote zur Seite, und genau das
      ist sie auch.
    -->
    <p v-if="version" class="stand">
      <span class="version">{{ version }}</span>
    </p>
  </main>
</template>

<style scoped>
/*
 * Alle Masse in px, nicht in rem.
 *
 * Der Grund ist gemessen und nicht Geschmack: Die Grundgrösse des Panels steht
 * mit 13px am `body`, `rem` rechnet aber gegen das Wurzelelement — und das
 * steht auf der Browservorgabe von 16px. Jeder rem-Wert hier war damit 23 %
 * zu gross, und die Anmeldemaske trug eine Überschrift (18,4px), die grösser
 * war als die Seitenüberschrift im angemeldeten Panel (16px).
 *
 * Die Zahlen unten sind dieselben wie im Gerüst (§7.2): 16px für die
 * Überschrift, 13px für Inhalt, 34px Zeilenhöhe für alles, was man anfasst.
 */
/*
 * Flex statt Grid, seit die Version unter der Maske steht.
 *
 * `display: grid; place-items: center` zentrierte, solange es ein Kind gab.
 * Mit zweien entstehen zwei implizite Zeilen, die sich über die volle Höhe
 * strecken — die Maske sässe in der Mitte der oberen Hälfte und die Version in
 * der Mitte der unteren. Eine Flex-Spalte zentriert den Stapel als Ganzes.
 */
.anmeldung {
  min-height: 100dvh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 16px;
  background: var(--bg);
}

.stand {
  margin: 0;
}

.maske {
  width: min(320px, 100%);
  display: flex;
  flex-direction: column;
  padding: 20px 22px 22px;
  background: var(--surface);
  border: 1px solid var(--surface-border);
  border-radius: 8px;
}

h1 {
  display: flex;
  align-items: center;
  gap: 9px;
  margin: 0 0 18px;
  font-size: var(--text-heading);
  font-weight: 600;
  letter-spacing: -0.01em;
  color: var(--text-strong);
}

.feld {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 12px;
}

label {
  font-size: var(--text-small);
  color: var(--text-muted);
}

/*
 * `input[type='text']` gehört dazu, seit das Passwortfeld sichtbar geschaltet
 * werden kann: Der Typ wechselt beim Klick auf das Auge, und ohne diese Zeile
 * verlöre das Feld genau dann seine Gestaltung — beim Umschalten sprang es
 * auf die Vorgabe des Browsers.
 */
input[type='email'],
input[type='password'],
.mit-auge input[type='text'] {
  height: var(--row-height);
  padding: 0 9px;
  font: inherit;
  font-size: var(--text-input);
  color: var(--text);
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 5px;
}

.mit-auge {
  display: flex;
  gap: 5px;
}

.mit-auge input {
  flex: 1;
  min-width: 0;
}

/* Der Knopf für das Auge ist kein Knopf im Sinne von app.css: Er trägt kein
   `.knopf`, sondern zeigt einen Zustand am Feld daneben. Hier stand, er
   brauche eine Ausnahme von „der Regel unten, die jeden button in Bernstein
   färbt" — die Regel gibt es nicht mehr, seit die Knöpfe aus app.css kommen. */
.auge {
  flex: none;
  display: grid;
  place-items: center;
  width: 34px;
  color: var(--text-muted);
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 5px;
  cursor: pointer;
}

/*
 * Kein amberfarbener Rand im gedrückten Zustand mehr.
 *
 * Amber bedeutet in diesem System Signal, Zustand oder primäre Aktion (§7.2).
 * Ein sichtbar geschaltetes Passwort ist keines davon — der Rahmen zog den
 * Blick auf den Knopf, obwohl daneben das Feld steht, um das es geht. Den
 * Zustand trägt das Zeichen selbst: Auge mit oder ohne Strich.
 */
.auge:hover {
  color: var(--text-strong);
}

input:focus-visible,
button:focus-visible {
  outline: 2px solid var(--focus);
  outline-offset: 1px;
}

.merken {
  display: flex;
  align-items: center;
  gap: 7px;
  margin: 2px 0 16px;
  font-size: var(--text-table);
  color: var(--text);
  cursor: pointer;
}

.merken input {
  width: 13px;
  height: 13px;
  margin: 0;
  accent-color: var(--accent);
}

.fehler,
.hinweis {
  margin: 0 0 12px;
  padding: 7px 9px;
  font-size: var(--text-table);
  border-radius: 5px;
}

.fehler {
  color: var(--critical);
  background: var(--critical-surface);
}

.hinweis {
  color: var(--warn);
  background: var(--warn-surface);
}
</style>
