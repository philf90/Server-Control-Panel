<script setup lang="ts">
import { computed } from 'vue'

/**
 * Die Rechte einer Datei — geführt statt geraten.
 *
 * ## Was hier ersetzt wird
 *
 * Bis zum 14. August 2026 fragte ein `window.prompt` nach einer Oktalzahl. Der
 * Betreiber hat es in der Zwischenabnahme gemeldet (`docs/53`, Befund 8), und
 * drei Dinge waren daran falsch:
 *
 * 1. **Ein Browserdialog.** Ein schwarzer Kasten mit runden Systemknöpfen
 *    mitten in einem hellen Panel — keine Farbe aus `app.css`, keine Schrift,
 *    kein Abstand. Ein Hexwert in einer Komponente ist in diesem Projekt ein
 *    Fehler; ein Dialog, der das ganze Gestaltungssystem umgeht, ist derselbe
 *    Fehler eine Stufe grösser.
 * 2. **Es verlangte Oktal.** `644` ist eine Zahl, die man wissen muss. Wer sie
 *    nicht weiss, rät — und `777` ist die Zahl, die man rät.
 * 3. **Es erklärte nichts.** Genau die Auskunft, die der Kunde braucht — *was
 *    passiert, wenn ich das tue* —, stand nirgends.
 *
 * > **Eine Eingabe, die eine Zahl verlangt, die man auswendig können muss, ist
 * > keine Bedienung, sondern eine Prüfung.**
 *
 * ## Warum kein Dialog, sondern ein Bereich
 *
 * Dieses Panel hat keine Modalen, und es bekommt auch für diesen einen Fall
 * keine. Eine zweite Bauform für Formulare wäre eine zweite Fassung der
 * Gestaltung, und die zweite ist die, die veraltet.
 *
 * ## Der Satz ist der eigentliche Punkt
 *
 * Neun Kästchen und eine Zahl daneben sind bequemer als ein `prompt` und
 * erklären genauso wenig. Was erklärt, ist {@link explanation} — in
 * gewöhnlichem Deutsch, für **diesen** Eintrag, und mit dem Unterschied
 * zwischen Datei und Verzeichnis darin. Er wird an *einer* Stelle gebildet:
 * Zwei Fassungen wären zwei Gelegenheiten, sie auseinanderlaufen zu lassen.
 */
const props = defineProps<{
  modelValue: number
  isDirectory: boolean

  /**
   * Liegt der Eintrag in einem Verzeichnis, das der Webserver ausliefert?
   *
   * Der Server entscheidet das und nicht diese Komponente: Welche
   * Verzeichnisse DocumentRoot sind, steht an den Domains des Abonnements.
   * `httpdocs` hier hineinzuschreiben wäre für jede zweite Domain falsch.
   */
  served: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [number] }>()

/**
 * Die neun Bits.
 *
 * Die Reihenfolge ist die von `ls -l` und die der Oktalzahl — Eigentümer,
 * Gruppe, Andere; lesen, schreiben, ausführen. Eine andere wäre in jeder
 * Anleitung im Netz eine andere.
 */
const WHO = [
  { key: 'owner', label: 'Eigentümer', shift: 6 },
  { key: 'group', label: 'Gruppe', shift: 3 },
  { key: 'other', label: 'Andere', shift: 0 },
] as const

const WHAT = [
  { key: 'read', label: 'Lesen', bit: 4 },
  { key: 'write', label: 'Schreiben', bit: 2 },
  { key: 'execute', label: 'Ausführen', bit: 1 },
] as const

function has(shift: number, bit: number): boolean {
  return ((props.modelValue >> shift) & bit) !== 0
}

function toggle(shift: number, bit: number): void {
  emit('update:modelValue', props.modelValue ^ (bit << shift))
}

/** `644` — die Schreibweise aus jeder Anleitung. */
const octal = computed(() => (props.modelValue & 0o777).toString(8).padStart(3, '0'))

/** `rw-r--r--` — die Schreibweise aus jedem `ls -l`. */
const symbolic = computed(() =>
  WHO.map((who) => WHAT.map((what) => (has(who.shift, what.bit) ? what.key[0] : '-')).join('')).join(''),
)

/**
 * Was die Zahl bedeutet — für diesen Eintrag.
 *
 * **Datei und Verzeichnis werden unterschieden, weil dasselbe Bit dort etwas
 * anderes heisst.** Bei einer Datei ist `x` „ausführbar", bei einem Verzeichnis
 * „betretbar" — und ein Verzeichnis ohne `x` ist der häufigste selbstgemachte
 * Fehler dieser Art. Er sieht aus wie ein Serverfehler: Wer `755` auf `644`
 * setzt, sperrt sich aus seinem eigenen Ordner aus, und nichts sagt ihm, warum.
 */
const explanation = computed<string[]>(() => {
  const sätze: string[] = []
  const ding = props.isDirectory ? 'diesen Ordner' : 'diese Datei'

  for (const who of WHO) {
    const r = has(who.shift, 4)
    const w = has(who.shift, 2)
    const x = has(who.shift, 1)

    const wer = who.key === 'owner' ? 'Der Eigentümer' : who.key === 'group' ? 'Die Gruppe' : 'Alle anderen'
    const darf = who.key === 'other' ? 'dürfen' : 'darf'

    const kann: string[] = []

    if (props.isDirectory) {
      if (r) kann.push('den Inhalt auflisten')
      if (w && x) kann.push('darin anlegen und löschen')
      if (x) kann.push('ihn betreten')
    } else {
      if (r) kann.push('sie lesen')
      if (w) kann.push('sie ändern')
      if (x) kann.push('sie ausführen')
    }

    sätze.push(kann.length === 0 ? `${wer} ${darf} nichts.` : `${wer} ${darf} ${satzreihe(kann)}.`)
  }

  /*
   * **Der Ordner, der sich nicht öffnen lässt.**
   *
   * `w` ohne `x` ist an einem Verzeichnis wirkungslos: Wer nicht hineinkommt,
   * legt darin auch nichts an. Das steht als eigener Satz da, weil die neun
   * Kästchen es nicht zeigen — sie zeigen zwei gesetzte Haken und verschweigen,
   * dass der eine den anderen braucht.
   */
  if (props.isDirectory && !has(6, 1)) {
    sätze.push('Achtung: Ohne „Ausführen" lässt sich der Ordner nicht öffnen — auch nicht vom Eigentümer.')
  }

  /*
   * **Und der Satz, wegen dem `served` überhaupt übergeben wird.**
   *
   * Er hängt am Gruppenbit und nicht am Weltbit, seit `httpdocs` setgid trägt
   * (`docs/51`, Schritt 6c): Alles, was dort entsteht, gehört der Gruppe
   * `www-data`, und der Webserver liest darüber. Vorher hing er am Weltbit, und
   * das war der Grund für Befund 3 — zwei Dateien nebeneinander mit derselben
   * Rechteangabe und verschiedenem Verhalten.
   */
  if (props.served) {
    sätze.push(
      has(3, 4)
        ? `Der Webserver kann ${ding} ausliefern.`
        : `Der Webserver kann ${ding} nicht lesen — Besucher bekommen einen Fehler 403.`,
    )
  }

  return sätze
})

function satzreihe(teile: string[]): string {
  if (teile.length === 1) return teile[0]

  return `${teile.slice(0, -1).join(', ')} und ${teile[teile.length - 1]}`
}

/**
 * Was Kunden tatsächlich brauchen, mit ihrem Grund statt nur ihrer Zahl.
 *
 * **`setuid`, `setgid` und das Sticky-Bit stehen nicht dabei**, und das ist
 * eine Entscheidung: Ihre Wirkung lässt sich in einer Zeile nicht ehrlich
 * erklären, und ein `setuid` auf eine Kundendatei ist nichts, wozu eine
 * Oberfläche einladen soll. Wer sie braucht, hat SFTP.
 */
const PRESETS = [
  { mode: 0o644, label: 'Übliche Datei', forDirectory: false },
  { mode: 0o600, label: 'Nur für mich', forDirectory: false },
  { mode: 0o755, label: 'Üblicher Ordner', forDirectory: true },
  { mode: 0o700, label: 'Nur für mich', forDirectory: true },
] as const

const presets = computed(() => PRESETS.filter((p) => p.forDirectory === props.isDirectory))

/**
 * Die Zahl darf auch getippt werden — als **zweiter** Weg.
 *
 * `parseInt(x, 8)` und nicht `Number(x)`: „644" als Dezimalzahl wäre 644 und
 * damit ausserhalb der neun Bits. Der Agent wiese es ab, und der Kunde läse
 * eine Meldung über eine Zahl, die er so nie gemeint hat.
 */
function typed(value: string): void {
  if (!/^[0-7]{3}$/.test(value)) return

  emit('update:modelValue', parseInt(value, 8))
}
</script>

<template>
  <div class="permissions">
    <!--
      **Drei Gruppen und keine Tabelle.** Eine 4-spaltige Kopfzeile mit
      „Lesen / Schreiben / Ausführen" passt bei 390px nicht, und eine Tabelle,
      die dort rollt, ist für neun Kästchen die falsche Form: Man liest sie
      nicht Zeile für Zeile, man setzt drei Haken.
    -->
    <fieldset v-for="who in WHO" :key="who.key" class="who">
      <legend>{{ who.label }}</legend>

      <label v-for="what in WHAT" :key="what.key" class="toggle">
        <input
          type="checkbox"
          :checked="has(who.shift, what.bit)"
          @change="toggle(who.shift, what.bit)"
        />
        <span>{{ props.isDirectory && what.key === 'execute' ? 'Betreten' : what.label }}</span>
      </label>
    </fieldset>

    <!--
      Beide Schreibweisen, und keine davon allein: Die Zahl steht in jeder
      Anleitung im Netz, die Buchstaben in jedem `ls -l`. Wer die eine kennt,
      findet über sie die andere.
    -->
    <div class="readout">
      <label class="field inline">
        <span>Oktal</span>
        <input
          class="ident"
          type="text"
          inputmode="numeric"
          maxlength="3"
          autocomplete="off"
          :value="octal"
          @input="typed(($event.target as HTMLInputElement).value)"
        />
      </label>
      <span class="ident quiet">{{ symbolic }}</span>
    </div>

    <div class="button-row">
      <button
        v-for="preset in presets"
        :key="preset.mode"
        type="button"
        class="button"
        @click="emit('update:modelValue', preset.mode)"
      >{{ preset.label }} · {{ preset.mode.toString(8) }}</button>
    </div>

    <ul class="explains">
      <li v-for="satz in explanation" :key="satz">{{ satz }}</li>
    </ul>
  </div>
</template>
