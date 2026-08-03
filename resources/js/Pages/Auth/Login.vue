<script setup lang="ts">
import { useForm, Head, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

/*
 * Die Anmeldemaske.
 *
 * Bewusst ohne Navigation und ohne Kacheln: Wer hier steht, hat noch kein
 * Konto vorgewiesen und soll nichts über den Server erfahren — nicht die
 * Version, nicht den Hostnamen, nicht die Zahl der Kunden.
 */

const form = useForm({
  email: '',
  password: '',
  remember: false,
})

const passwortSichtbar = ref(false)

const page = usePage()
const notice = computed(() => (page.props.flash as Record<string, string> | undefined)?.notice)

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
      <h1>SrvPanel</h1>

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
            <span aria-hidden="true">{{ passwortSichtbar ? '🙈' : '👁' }}</span>
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

      <button type="submit" :disabled="form.processing">
        {{ form.processing ? 'Einen Moment …' : 'Anmelden' }}
      </button>
    </form>
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
.anmeldung {
  min-height: 100dvh;
  display: grid;
  place-items: center;
  padding: 16px;
  background: var(--bg);
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
  margin: 0 0 18px;
  font-size: 16px;
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
  font-size: 11px;
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
  font-size: 13px;
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

/* Der Knopf für das Auge ist kein Absendeknopf — die Regel unten färbt jeden
   button in Bernstein, und ohne diese Ausnahme stünde neben dem Feld eine
   zweite, gleich aussehende Schaltfläche. */
.auge {
  flex: none;
  width: 34px;
  font-size: 13px;
  color: var(--text-muted);
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 5px;
  cursor: pointer;
}

.auge[aria-pressed='true'] {
  border-color: var(--accent);
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
  font-size: 12.5px;
  color: var(--text);
  cursor: pointer;
}

.merken input {
  width: 13px;
  height: 13px;
  margin: 0;
  accent-color: var(--accent);
}

button[type='submit'] {
  height: var(--row-height);
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  color: var(--accent-on);
  background: var(--accent);
  border: 0;
  border-radius: 5px;
  cursor: pointer;
}

button[type='submit']:disabled {
  opacity: 0.6;
  cursor: default;
}

.fehler,
.hinweis {
  margin: 0 0 12px;
  padding: 7px 9px;
  font-size: 12.5px;
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
