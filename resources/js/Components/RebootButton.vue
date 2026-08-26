<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useConfirmation } from '../Composables/useConfirmation'

/*
 * Der Neustart — an der Stelle, an der sein Anlass steht.
 *
 * ## Warum eine Komponente und nicht zweimal derselbe Knopf
 *
 * Weil es **zwei** Anlässe gibt und beide auf verschiedenen Seiten stehen: die
 * Übersicht meldet „ein neuerer Kernel ist installiert" (aus `/boot`), die
 * Updates-Seite „Ein Neustart steht aus" (aus `/run/reboot-required`). Zwei
 * Fragen an zwei Quellen, eine Handlung.
 *
 * > **Ein Knopf, den man sucht, wenn man ihn braucht, steht am falschen Ort.**
 * > (`docs/81 §6`)
 *
 * Der Text der Rückfrage steht damit **einmal** da, und das ist der Teil, auf
 * den es ankommt: Er ist das Einzige, was zwischen einem Klick und zwei Minuten
 * Ausfall jeder Kundenseite steht. Zwei Fassungen davon würden auseinanderlaufen,
 * und die kürzere gewönne.
 *
 * ## Warum die Fähigkeit hier gefragt wird
 *
 * Die Updates-Seite gehört ohnehin dem Betreiber — die **Übersicht** nicht: Sie
 * steht jedem Adminkonto offen, und seit A9 heisst das auch: dem Administrator.
 * Ohne diese Frage sähe er einen Knopf, der ihm einen 403 gibt.
 *
 * > **Wer eine Aktion zeigt, fragt vorher dieselbe Policy, die sie später
 * > abweist.**
 *
 * Gefragt wird die geteilte Ablage `abilities` und nicht `can` — `can` gehört
 * den Seiten, die eine Frage über ein **Objekt** stellen (`docs/84`).
 */
const props = defineProps<{
  /** Der Name, der abgetippt werden muss. Aus `Names::host()`, und nur von dort. */
  hostname: string

  /** Wie viele Sekunden zwischen dem Bestätigen und dem Neustart liegen. */
  delay: number
}>()

const { ask } = useConfirmation()

const page = usePage()

const allowed = computed(
  (): boolean => ((page.props.abilities ?? {}) as Record<string, boolean>)['operate-server'] === true,
)

/*
 * **Die Minute steht nicht im Text, sondern kommt vom Server.**
 *
 * Sie ist eine Zusage des Agenten (`SystemReboot::DELAY_SECONDS`), und eine
 * zweite Fassung davon in diesem Satz wäre genau die, die beim Ändern
 * stehenbleibt — der Betreiber läse „eine Minute" und hätte zehn Sekunden.
 */
function fragen(): void {
  ask(
    'Diesen Server neu starten?\n'
      + 'Alle Websites, Datenbanken und Postfächer dieser Maschine sind währenddessen nicht '
      + 'erreichbar — auch dieses Panel.\n'
      + `Der Neustart läuft ${props.delay} Sekunden nach dem Bestätigen an. Bis dahin lässt er `
      + 'sich auf der Kommandozeile stoppen: systemctl stop srvpanel-reboot.timer',
    'Neustart auslösen',
    (answer) => router.post('/server/reboot', { hostname: answer }),
    true,
    props.hostname,
  )
}
</script>

<template>
  <div v-if="allowed" class="button-row">
    <button type="button" class="button danger" @click="fragen">Server neu starten</button>
  </div>
</template>
