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
  <div class="feld">
    <label :for="id">{{ label }}</label>

    <input
      :id="id"
      v-model="value"
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

    <small v-if="hint" class="hinweis">{{ hint }}</small>
    <small v-if="error" class="fehler" role="alert">{{ error }}</small>
  </div>
</template>

<style scoped>
.feld {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

/*
 * Kleine Beschriftung nach §7.2: Versalien mit Sperrung. Sie darf das, weil
 * darunter kein Spaltenkopf steht, mit dem sie sich verwechseln liesse.
 */
label {
  font-size: var(--text-label);
  letter-spacing: 0.09em;
  text-transform: uppercase;
  color: var(--text-faint);
}

input {
  padding: 10px 12px;
  font-family: var(--font-mono);
  font-size: var(--text-metric);
  font-variant-numeric: tabular-nums;

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
  background: var(--bg);
  border: 1px solid var(--line);
  border-radius: 6px;
}

input:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px var(--accent-surface);
}

/*
 * Die Einfärbung des Browsers ist nicht hier abgestellt, sondern in
 * app.css — sie betrifft jedes Feld im Panel und nicht nur dieses. Was hier
 * noch dazukommt, ist die kräftigere Schriftfarbe: Ein Code, den der Browser
 * eingesetzt hat, soll genauso aussehen wie einer, den jemand tippt.
 */
input:-webkit-autofill {
  -webkit-text-fill-color: var(--text-strong);
}

.hinweis {
  font-size: var(--text-label);
  color: var(--text-faint);
}

.fehler {
  font-size: var(--text-small);
  color: var(--critical);
}
</style>
