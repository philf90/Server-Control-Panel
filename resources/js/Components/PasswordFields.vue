<script setup lang="ts">
/*
 * Passwort und Wiederholung — überall im Panel dieselben.
 *
 * **Warum das eine Komponente ist und kein Muster zum Abschreiben.** Ein
 * Passwortfeld taucht beim Anlegen eines Kunden auf, beim Ändern des eigenen
 * Passworts, beim Zurücksetzen. Schreibt jede Seite ihre eigene Prüfliste, hat
 * das Panel nach drei Modulen drei Richtlinien — und der Benutzer bekommt je
 * nach Seite eine andere Auskunft darüber, was ein gültiges Passwort ist.
 *
 * **Die Anforderungen kommen vom Server**, nicht aus dieser Datei. Sie stehen
 * in App\Support\Passwords\Policy und werden über Inertia geteilt. Hier steht
 * nur, wie ein Schlüssel im Browser geprüft wird. Damit kann die Prüfliste
 * nicht mehr etwas anderes behaupten als die Validierung — was sie tat, solange
 * unter dem Feld „Mindestens zwölf Zeichen" stand und daneben `min:12` in einem
 * Controller, den niemand mitändert.
 *
 * **Erzeugt wird im Browser.** Ein Passwort, das der Server erzeugt und
 * ausliefert, steht in jedem Puffer auf dem Weg — im Zugriffslog eines
 * Reverse-Proxys, im Speicher des Browsers, in der Antwort. `crypto
 * .getRandomValues` bleibt auf dem Gerät.
 */
import { computed, ref } from 'vue'
import EyeIcon from './EyeIcon.vue'

interface Requirement {
  key: string
  label: string
}

const props = defineProps<{
  modelValue: string
  confirmation: string
  requirements: Requirement[]
  minimum: number
  error?: string
  confirmationError?: string
  label?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string]
  'update:confirmation': [value: string]
}>()

const visible = ref(false)
const touched = ref(false)

/*
 * Die Prüfungen je Schlüssel.
 *
 * Der Schlüssel ist der Vertrag zur Policy-Klasse auf dem Server. Kommt dort
 * einer dazu, den es hier nicht gibt, schlägt PasswordPolicyTest an — sonst
 * stünde in der Prüfliste eine Anforderung, die sich nie erfüllen lässt, weil
 * niemand sie prüft.
 */
const CHECKS: Record<string, (value: string, minimum: number) => boolean> = {
  length: (value, minimum) => [...value].length >= minimum,
  lowercase: (value) => /\p{Ll}/u.test(value),
  uppercase: (value) => /\p{Lu}/u.test(value),
  digit: (value) => /\p{Nd}/u.test(value),
  symbol: (value) => /[^\p{L}\p{Nd}]/u.test(value),
}

const checked = computed(() =>
  props.requirements.map((requirement) => ({
    ...requirement,
    met: (CHECKS[requirement.key] ?? (() => false))(props.modelValue, props.minimum),
  })),
)

const allMet = computed(() => checked.value.every((entry) => entry.met))

const matches = computed(
  () => props.confirmation.length > 0 && props.modelValue === props.confirmation,
)

/*
 * Die Stärke — als Schätzung und nicht als Note.
 *
 * Gerechnet wird die Entropie, die sich aus Länge und benutztem Zeichenvorrat
 * ergibt: log2(Vorrat) * Länge. Das ist die Untergrenze für einen Angreifer,
 * der nur die Zeichenklassen kennt — und die Obergrenze für die Aussagekraft
 * dieser Anzeige. „Sommer2024!" bekommt hier 72 Bit und fällt gegen eine
 * Wörterbuchliste in Sekunden.
 *
 * Eine ehrliche Anzeige braucht dafür eine Wortliste (zxcvbn und Verwandte,
 * rund 400 KiB) — das ist eine Abhängigkeit mit Ansage und keine, die
 * nebenbei hereinkommt. Deshalb steht unter der Leiste, worauf sie beruht.
 */
const strength = computed(() => {
  const value = props.modelValue

  if (value.length === 0) return { bits: 0, step: 0, label: '—' }

  let pool = 0
  if (/\p{Ll}/u.test(value)) pool += 26
  if (/\p{Lu}/u.test(value)) pool += 26
  if (/\p{Nd}/u.test(value)) pool += 10
  if (/[^\p{L}\p{Nd}]/u.test(value)) pool += 33

  const unique = new Set([...value]).size
  const length = [...value].length

  // Wiederholung zählt weniger: „aaaaaaaaaaaa" hat zwölf Zeichen und einen
  // Zeichenvorrat von 26 — ohne diesen Abschlag stünde es bei 56 Bit.
  const effective = length * Math.min(1, (unique + 1) / length)
  const bits = Math.round(Math.log2(Math.max(pool, 2)) * effective)

  if (bits < 45) return { bits, step: 1, label: 'schwach' }
  if (bits < 65) return { bits, step: 2, label: 'brauchbar' }
  if (bits < 90) return { bits, step: 3, label: 'stark' }

  return { bits, step: 4, label: 'sehr stark' }
})

function generate(): void {
  const groups = [
    'abcdefghijkmnopqrstuvwxyz',
    'ABCDEFGHJKLMNPQRSTUVWXYZ',
    '23456789',
    '!@#$%^&*-_=+?',
  ]

  const length = Math.max(props.minimum, 20)
  const pool = groups.join('')
  const characters: string[] = []

  // Aus jeder Gruppe eines, damit das Erzeugte die eigene Richtlinie erfüllt.
  for (const group of groups) characters.push(pick(group))
  while (characters.length < length) characters.push(pick(pool))

  // Ohne Mischen stünden die vier Pflichtzeichen immer vorn.
  for (let i = characters.length - 1; i > 0; i--) {
    const j = randomBelow(i + 1)
    ;[characters[i], characters[j]] = [characters[j], characters[i]]
  }

  const generated = characters.join('')

  visible.value = true
  touched.value = true
  emit('update:modelValue', generated)
  emit('update:confirmation', generated)
}

function pick(alphabet: string): string {
  return alphabet[randomBelow(alphabet.length)]
}

/*
 * Gleichverteilt, nicht `% n`.
 *
 * Ein Modulo auf einen 32-Bit-Wert bevorzugt die niedrigen Reste, sobald die
 * Länge des Alphabets keine Zweierpotenz ist — bei 26 Buchstaben sind die
 * ersten messbar häufiger. Für ein Passwort ist das kein theoretischer Einwand.
 */
function randomBelow(bound: number): number {
  const limit = Math.floor(0xffffffff / bound) * bound
  const buffer = new Uint32Array(1)

  let value = 0
  do {
    crypto.getRandomValues(buffer)
    value = buffer[0]
  } while (value >= limit)

  return value % bound
}
</script>

<template>
  <div class="password">
    <label class="field">
      <span>{{ props.label ?? 'Passwort' }}</span>
      <div class="with-reveal">
        <input
          :value="props.modelValue"
          :type="visible ? 'text' : 'password'"
          :aria-invalid="Boolean(props.error)"
          autocomplete="new-password"
          spellcheck="false"
          required
          @input="touched = true; emit('update:modelValue', ($event.target as HTMLInputElement).value)"
        >
        <button
          type="button"
          class="reveal"
          :aria-label="visible ? 'Passwort verbergen' : 'Passwort anzeigen'"
          :aria-pressed="visible"
          @click="visible = !visible"
        >
          <EyeIcon :off="visible" />
        </button>
      </div>
    </label>

    <label class="field">
      <span>Passwort wiederholen</span>
      <div class="with-reveal">
        <input
          :value="props.confirmation"
          :type="visible ? 'text' : 'password'"
          :aria-invalid="Boolean(props.confirmationError) || (props.confirmation.length > 0 && !matches)"
          autocomplete="new-password"
          spellcheck="false"
          required
          @input="emit('update:confirmation', ($event.target as HTMLInputElement).value)"
        >
        <button
          type="button"
          class="reveal"
          :aria-label="visible ? 'Passwort verbergen' : 'Passwort anzeigen'"
          :aria-pressed="visible"
          @click="visible = !visible"
        >
          <EyeIcon :off="visible" />
        </button>
      </div>
    </label>
    <!--
      **Diese Meldung bleibt am Feld, und das ist kein Rest.** Alles andere hier
      kommt aus einer Antwort und steht damit oben in der Zusammenfassung; diese
      entsteht beim Tippen und geht nie über den Draht. Der Banner kann sie
      nicht tragen, also trägt sie das Feld.
    -->
    <p v-if="props.confirmation.length > 0 && !matches" class="error">
      Die beiden Eingaben sind nicht gleich.
    </p>

    <div class="button-row">
      <button type="button" class="button small" @click="generate">Passwort erzeugen</button>
    </div>

    <!--
      Die Prüfliste steht immer da und erscheint nicht erst beim Fehlschlag.
      Anforderungen, die man erst nach dem Absenden erfährt, sind der Grund,
      warum Leute „Passwort1!" tippen: Sie raten sich an die Regel heran,
      statt sie zu lesen.
    -->
    <ul class="rules" aria-live="polite">
      <li v-for="entry in checked" :key="entry.key" :class="{ met: entry.met }">
        <!--
          `.haken` und nicht `.marke`: Seit „Kontor" ist `.marke` die
          Zustandsmarke aus app.css — eine Pille mit farbigem Punkt davor. Ein
          ✓ mit dieser Klasse hätte sich stillschweigend in eine Pille
          verwandelt, und in der Prüfliste stünden acht davon nebeneinander.
          Ein Name, der in zwei Bedeutungen benutzt wird, ist beim ersten Umbau
          ein Fehler.
        -->
        <span class="check" aria-hidden="true">{{ entry.met ? '✓' : '✗' }}</span>
        <span class="sr">{{ entry.met ? 'erfüllt:' : 'offen:' }}</span>
        {{ entry.label }}
      </li>
    </ul>

    <div class="strength" :data-step="strength.step">
      <div class="meter">
        <span v-for="step in 4" :key="step" :class="{ on: step <= strength.step }" />
      </div>
      <span class="value">
        {{ strength.label }}<template v-if="strength.bits > 0"> · {{ strength.bits }} Bit</template>
      </span>
    </div>

    <p class="hint">
      Die Schätzung rechnet Länge und Zeichenvorrat, kein Wörterbuch. Ein
      Passwort aus einem gebräuchlichen Wort ist schwächer, als hier steht.
    </p>

    <p v-if="touched && allMet && matches" class="done">Das Passwort erfüllt die Richtlinie.</p>
  </div>
</template>

<style scoped>
/*
 * **Form und Farbe des Feldes stehen nicht mehr hier.**
 *
 * Diese Komponente brachte ihr eigenes Feld mit — und mit ihm einen Rahmen
 * aus `--line`, der Haarlinie zum Trennen: 1,09:1 hell, 1,13:1 dunkel gegen
 * den Seitengrund. Elf Seiten trugen dieselbe abgeschriebene Zeile. `.field`
 * aus app.css trägt es jetzt, samt `--control-line`.
 *
 * Das Auge daneben ist inzwischen ebenfalls umgezogen: Die Anmeldemaske
 * hatte ihre eigene Fassung davon, 34px breit und mit einem Rand aus `--line`
 * — 1,45:1 im dunklen Theme. Zwei Fassungen desselben Bedienelements, eine
 * davon ohne sichtbare Grenze.
 *
 * Was hier bleibt, ist die Prüfliste und die Stärkeschätzung — Dinge, die es
 * nur in einem Passwortfeld gibt.
 */
.password {
  display: flex;
  flex-direction: column;
  gap: var(--gap);
}

/*
 * Die Prüfliste steht immer da und erscheint nicht erst beim Fehlschlag.
 * Anforderungen, die man erst nach dem Absenden erfährt, sind der Grund,
 * warum Leute „Passwort1!" tippen.
 */
.rules {
  display: flex;
  flex-wrap: wrap;
  gap: 3px 18px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.rules li {
  display: flex;
  align-items: baseline;
  gap: 7px;
  font-size: var(--text-small);
  color: var(--text-muted);
}

.rules li.met {
  color: var(--ok);
}

.check {
  font-family: var(--font-mono);
  color: var(--critical);
}

.rules li.met .check {
  color: var(--ok);
}

/* Nur für Vorlesesoftware: Haken und Kreuz allein sagen ihr nichts. */
.sr {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

.strength {
  display: flex;
  align-items: center;
  gap: 10px;
}

.meter {
  display: flex;
  gap: 3px;
  flex: 1;
  max-width: 192px;
}

.meter span {
  flex: 1;
  height: 5px;
  border-radius: 999px;
  background: var(--line);
}

.value {
  font-size: var(--text-small);
  color: var(--text-muted);
}

[data-step='1'] .meter span.on { background: var(--critical); }
[data-step='2'] .meter span.on { background: var(--warn); }

[data-step='3'] .meter span.on,
[data-step='4'] .meter span.on { background: var(--ok); }

.done {
  margin: 0;
  font-size: var(--text-small);
  color: var(--ok);
}
</style>
