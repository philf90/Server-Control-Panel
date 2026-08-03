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
  <div class="passwort">
    <label>
      {{ props.label ?? 'Passwort' }}
      <div class="feld">
        <input
          :value="props.modelValue"
          :type="visible ? 'text' : 'password'"
          autocomplete="new-password"
          spellcheck="false"
          required
          @input="touched = true; emit('update:modelValue', ($event.target as HTMLInputElement).value)"
        >
        <button
          type="button"
          class="auge"
          :aria-label="visible ? 'Passwort verbergen' : 'Passwort anzeigen'"
          :aria-pressed="visible"
          @click="visible = !visible"
        >
          <EyeIcon :off="visible" />
        </button>
      </div>
      <small v-if="props.error" class="fehler">{{ props.error }}</small>
    </label>

    <label>
      Passwort wiederholen
      <div class="feld">
        <input
          :value="props.confirmation"
          :type="visible ? 'text' : 'password'"
          autocomplete="new-password"
          spellcheck="false"
          required
          @input="emit('update:confirmation', ($event.target as HTMLInputElement).value)"
        >
        <button
          type="button"
          class="auge"
          :aria-label="visible ? 'Passwort verbergen' : 'Passwort anzeigen'"
          :aria-pressed="visible"
          @click="visible = !visible"
        >
          <EyeIcon :off="visible" />
        </button>
      </div>
      <small v-if="props.confirmationError" class="fehler">{{ props.confirmationError }}</small>
      <small v-else-if="props.confirmation.length > 0 && !matches" class="fehler">
        Die beiden Eingaben sind nicht gleich.
      </small>
    </label>

    <button type="button" class="erzeugen" @click="generate">Passwort erzeugen</button>

    <!--
      Die Prüfliste steht immer da und erscheint nicht erst beim Fehlschlag.
      Anforderungen, die man erst nach dem Absenden erfährt, sind der Grund,
      warum Leute „Passwort1!" tippen: Sie raten sich an die Regel heran,
      statt sie zu lesen.
    -->
    <ul class="regeln" aria-live="polite">
      <li v-for="entry in checked" :key="entry.key" :class="{ erfuellt: entry.met }">
        <span class="marke" aria-hidden="true">{{ entry.met ? '✓' : '✗' }}</span>
        <span class="sr">{{ entry.met ? 'erfüllt:' : 'offen:' }}</span>
        {{ entry.label }}
      </li>
    </ul>

    <div class="staerke" :data-stufe="strength.step">
      <div class="leiste">
        <span v-for="step in 4" :key="step" :class="{ an: step <= strength.step }" />
      </div>
      <span class="wert">
        {{ strength.label }}<template v-if="strength.bits > 0"> · {{ strength.bits }} Bit</template>
      </span>
    </div>

    <p class="hinweis">
      Die Schätzung rechnet Länge und Zeichenvorrat, kein Wörterbuch. Ein
      Passwort aus einem gebräuchlichen Wort ist schwächer, als hier steht.
    </p>

    <p v-if="touched && allMet && matches" class="fertig">Das Passwort erfüllt die Richtlinie.</p>
  </div>
</template>

<style scoped>
.passwort { display: flex; flex-direction: column; gap: 8px; }
label { display: flex; flex-direction: column; gap: 3px; font-size: var(--text-small); color: var(--text-muted); }
.feld { display: flex; gap: 5px; }
input { flex: 1; min-width: 0; padding: 6px 8px; font: inherit; font-size: var(--text-input); font-family: var(--font-mono); color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 5px; }
.auge { flex: none; display: grid; place-items: center; width: 34px; color: var(--text-muted); background: var(--bg); border: 1px solid var(--line); border-radius: 5px; cursor: pointer; }
.auge:hover { color: var(--text-strong); }
.erzeugen { align-self: flex-start; padding: 5px 11px; font: inherit; font-size: var(--text-small); color: var(--text); background: transparent; border: 1px solid var(--line); border-radius: 5px; cursor: pointer; }
.erzeugen:hover { border-color: var(--accent); color: var(--accent); }

.regeln { display: flex; flex-wrap: wrap; gap: 2px 16px; margin: 3px 0 0; padding: 0; list-style: none; }
.regeln li { display: flex; align-items: baseline; gap: 6px; font-size: var(--text-small); color: var(--text-faint); }
.regeln li.erfuellt { color: var(--ok); }
.marke { font-family: var(--font-mono); color: var(--critical); }
.regeln li.erfuellt .marke { color: var(--ok); }

/* Nur für Vorlesesoftware: Haken und Kreuz allein sagen ihr nichts. */
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }

.staerke { display: flex; align-items: center; gap: 8px; }
.leiste { display: flex; gap: 3px; flex: 1; max-width: 192px; }
.leiste span { flex: 1; height: 4px; border-radius: 999px; background: var(--surface-border); }
.wert { font-size: var(--text-small); color: var(--text-muted); }
[data-stufe='1'] .leiste span.an { background: var(--critical); }
[data-stufe='2'] .leiste span.an { background: var(--warn); }
[data-stufe='3'] .leiste span.an,
[data-stufe='4'] .leiste span.an { background: var(--ok); }

.hinweis { margin: 0; font-size: var(--text-label); color: var(--text-faint); }
.fertig { margin: 0; font-size: var(--text-small); color: var(--ok); }
.fehler { font-size: var(--text-small); color: var(--critical); }
</style>
