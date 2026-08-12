<script setup lang="ts">
/*
 * Die Eingabe eines Bestätigungscodes.
 *
 * **Warum eine eigene Komponente für ein Textfeld.** Der Code wird an drei
 * Stellen abgefragt — bei der Anmeldung, beim Einrichten des zweiten Faktors
 * und beim Abschalten — und sah an allen dreien aus wie ein Feld für eine
 * E-Mail-Adresse. Ein sechsstelliger Code ist aber kein Fliesstext: Man liest
 * ihn von einem Telefon ab und vergleicht ihn Ziffer für Ziffer mit dem, was
 * im Feld steht. Dafür müssen die Ziffern gleich breit sein und auseinander
 * stehen.
 *
 * **Und die Einfärbung des Browsers musste weg.** Chrome malt ein Feld, das
 * es selbst ausgefüllt hat, mit einem kräftigen Blau aus — auf hellem Grund
 * fällt das kaum auf, auf dunklem verschluckt es das Feld vollständig. Es ist
 * die eine Farbe im Panel, die nicht aus app.css kommt, und sie sitzt
 * ausgerechnet an der Stelle, an der jemand einen Code ablesen soll. Die
 * `box-shadow`-Zeile weiter unten überschreibt sie: Ein Schatten nach innen
 * ist das Einzige, was der Browser an dieser Stelle noch annimmt, `background`
 * ignoriert er.
 */
import { useId } from 'vue'

withDefaults(defineProps<{
  label?: string
  hint?: string
  error?: string
  autofocus?: boolean
}>(), {
  label: 'Code',
  hint: undefined,
  error: undefined,
  autofocus: false,
})

const value = defineModel<string>({ required: true })

/*
 * Eine eigene Kennung je Einbau. Auf der Einrichtungsseite steht die
 * Komponente zweimal — einmal zum Einschalten, einmal zum Abschalten. Mit
 * einer festen Kennung zeigte die zweite Beschriftung auf das erste Feld,
 * und ein Klick darauf setzte den Blinker in das falsche.
 */
const id = useId()
</script>

<template>
  <div class="field">
    <span><label :for="id">{{ label }}</label></span>

    <input
      :id="id"
      v-model="value"
      :aria-invalid="Boolean(error)"
      type="text"
      name="code"
      inputmode="text"
      autocomplete="one-time-code"
      autocapitalize="characters"
      autocorrect="off"
      spellcheck="false"
      :autofocus="autofocus"
      maxlength="16"
      required
    >

    <p v-if="hint" class="hint">{{ hint }}</p>
  </div>
</template>

<style scoped>
/*
 * **Form und Farbe des Feldes stehen nicht mehr hier.**
 *
 * Bis zum Rework brachte diese Komponente ihr eigenes Feld mit — Innenabstand,
 * Grund, Rahmen, Radius —, und der Rahmen kam aus `--line`. Das ist die
 * Haarlinie zum Trennen und erreicht gegen den Seitengrund 1,09:1 im hellen
 * und 1,13:1 im dunklen Theme: ein Eingabefeld ohne sichtbare Grenze, und das
 * ausgerechnet an der Stelle, an der jemand einen Code ablesen und eintippen
 * soll. Elf Seiten trugen dieselbe Zeile.
 *
 * `.field` aus app.css trägt das jetzt, samt `--control-line` mit 4,15:1 hell
 * und 4,95:1 dunkel. Hier bleibt nur, was **diesen** Code betrifft und kein
 * anderes Feld: Ein sechsstelliger Code ist kein Fliesstext. Man liest ihn von
 * einem Telefon ab und vergleicht ihn Ziffer für Ziffer; dafür müssen die
 * Ziffern gleich breit sein und auseinander stehen.
 */
/*
 * Die Breite gehört zu diesem Feld und nicht zum Gestaltungssystem: Sie folgt
 * daraus, dass in ihm sechs Zeichen stehen. Ohne diese Zeile war es 540px
 * breit — die Grenze für ein Feld mit Fließtext — und sah aus, als erwarte es
 * einen Satz. Im Browser gesehen.
 */
.field {
  max-width: 280px;
}

.field input {
  font-family: var(--font-mono);
  font-size: var(--text-metric);

  /*
   * Die Sperrung sitzt hinter jedem Zeichen, auch hinter dem letzten. Ohne
   * den Einzug stünde der Text deshalb um eine halbe Lücke nach links
   * versetzt — sichtbar genau dann, wenn sechs Ziffern drin stehen und
   * jemand sie mittig erwartet.
   */
  letter-spacing: 0.32em;
  text-indent: 0.32em;
  text-align: center;

  color: var(--text-strong);
}

/*
 * Die Einfärbung des Browsers ist nicht hier abgestellt, sondern in app.css —
 * sie betrifft jedes Feld im Panel und nicht nur dieses. Was hier dazukommt,
 * ist die kräftigere Schriftfarbe: Ein Code, den der Browser eingesetzt hat,
 * soll genauso aussehen wie einer, den jemand tippt.
 */
.field input:-webkit-autofill {
  -webkit-text-fill-color: var(--text-strong);
}
</style>
