<script setup lang="ts">
import { usePage } from '@inertiajs/vue3'
import { computed, nextTick, ref, watch } from 'vue'
import { bringIntoView } from '../scroll'

/*
 * Was an einem Formular gerade nicht stimmt — an einer Stelle, die man sieht.
 *
 * **Der Fall, der diese Komponente ausgelöst hat.** Ein Kunde liess sich nicht
 * anlegen, weil die Anmeldeadresse eines zurückgezogenen Kunden sie noch
 * belegte. Die Meldung dazu stand als kleine rote Zeile unter dem Feld — mitten
 * in einem langen Formular. Inertia setzt die Scrollposition nach einer Antwort
 * zurück, der Betreiber landete also oben, sah dieselben ausgefüllten Felder
 * wie vorher und schloss daraus: „Der Knopf tut nichts." Das aufzuklären hat
 * einen halben Tag gekostet, und die Zeile am Feld war die ganze Zeit da.
 *
 * **Deshalb steht die Zusammenfassung oben und nicht am Feld.** Die Zeile am
 * Feld bleibt — sie sagt, *welches* Feld —, aber sie ist nicht mehr der
 * einzige Ort, an dem ein Fehlschlag überhaupt sichtbar wird. Und weil die
 * Seite nach der Antwort ohnehin nach oben springt, steht sie damit genau dort,
 * wo der Blick landet.
 *
 * **Gelesen wird der Fehlersatz der Seite und nicht der eines Formulars.**
 * `useForm().errors` füllt sich nur bei der Anfrage *dieser* Instanz; auf einer
 * Seite mit drei Formularen — die Domainseite hat drei — bliebe die
 * Zusammenfassung bei zweien von ihnen stumm. `page.props.errors` trägt, was
 * die letzte Antwort gemeldet hat, gleich von welchem Formular sie kam.
 */
const page = usePage()

/*
 * Die Meldungen in der Reihenfolge, in der sie ankommen.
 *
 * Nicht sortiert: Laravel liefert sie in der Reihenfolge der Regeln, und die
 * ist die des Formulars. Alphabetisch stünde „E-Mail" vor „Vorname", und die
 * Zusammenfassung läse sich gegen die Seite.
 */
const messages = computed((): string[] =>
  // `Errors` von Inertia ist ein Wörterbuch mit Zeichenketten; die Typangabe
  // dort lässt aber auch verschachtelte Sätze zu, und ein Typprädikat darauf
  // wäre gelogen. Geprüft wird deshalb zur Laufzeit.
  Object.values(page.props.errors ?? {}).flatMap((message) =>
    /*
     * **Am Zeilenumbruch geteilt**, und das ist kein Schmuck.
     *
     * Ein Griff, der mehrere Einträge anfasst, meldet die Zahl der gelungenen
     * und dann je Fehlschlag eine Zeile. Bis zum 15. August 2026 kam davon nur
     * die Zahl an: Inertias Anbindung bildete den Fehlerbeutel auf „Feld =>
     * erste Meldung" ab (`docs/55`, Befund 12). `HandleInertiaRequests`
     * verbindet sie jetzt mit `\n` — hier werden sie wieder zu Zeilen.
     *
     * > **Eine Meldung, die der Controller schreibt, ist damit noch keine, die
     * > jemand liest.**
     */
    typeof message === 'string' && message !== ''
      ? message.split('\n').filter((zeile) => zeile !== '')
      : [],
  ),
)

const block = ref<HTMLElement | null>(null)

/*
 * **Und die Zusammenfassung holt sich ebenfalls ins Bild.**
 *
 * Der Kommentar oben stützt sich darauf, dass „die Seite nach der Antwort
 * ohnehin nach oben springt". Das stimmt — **ausser bei `preserveScroll:
 * true`**, und allein `Files/Index.vue` setzt es an zehn Griffen, weil eine
 * Liste, die nach jedem Klick nach oben springt, unbrauchbar wäre. Dort stand
 * die Meldung also wieder ausserhalb des Bildes, genau wie die Zeile am Feld,
 * gegen die diese Komponente gebaut wurde.
 *
 * > **Eine Regel, die sich auf ein Verhalten des Frameworks stützt, gilt nur
 * > dort, wo dieses Verhalten eingeschaltet ist.**
 */
watch(() => messages.value.length, (anzahl, vorher) => {
  if (anzahl > 0 && anzahl !== vorher) {
    void nextTick(() => bringIntoView(block.value))
  }
})
</script>

<template>
  <p v-if="messages.length > 0" ref="block" class="notice critical" role="alert" tabindex="-1">
    <span>
      <b>Das Formular wurde nicht gespeichert.</b>
      <template v-for="(message, index) in messages" :key="index">
        <br>{{ message }}
      </template>
    </span>
  </p>
</template>
