/*
 * Der Erfolg eines Vorgangs, für eine Seite, die nicht neu lädt.
 *
 * **`docs/19 §6.3` nennt genau eine Stelle für die grüne Meldung**:
 * `PanelLayout.vue`. Das war richtig, solange jede Änderung eine Inertia-Antwort
 * mit `flash.success` war — und die Konsole aus P5c ist die erste Seite dieses
 * Panels, die über XHR ändert und dabei stehen bleibt. Ein `flash` gibt es dort
 * nicht; es gibt gar keine Antwort, die eine Seite aufbaut.
 *
 * **Der erste Wurf hat deshalb eine eigene `notice ok` in die Konsole gesetzt,
 * und `FieldErrorTest` hat zugebissen** — zu Recht: Damit gäbe es die grüne
 * Meldung an zwei Orten, und der zweite ist der, der veraltet.
 *
 * > **Eine Regel, die einen Ort vorschreibt, braucht einen Weg dorthin — sonst
 * > baut die nächste Seite ihren eigenen.**
 *
 * Hier steht dieser Weg. Er ändert nichts an der Regel: Gerendert wird weiter
 * ausschliesslich in `PanelLayout.vue`, und `FieldErrorTest` prüft das
 * unverändert. Was dazukommt, ist eine **zweite Quelle** für denselben Ort.
 *
 * **Der Wert lebt im Modul und nicht in einer Komponente.** Er soll den Wechsel
 * zwischen Seite und Layout überstehen, und beide sehen dieselbe Zeile; ein
 * `provide`/`inject` wäre dasselbe mit mehr Zeremonie.
 */
import { router } from '@inertiajs/vue3'
import { readonly, ref, type DeepReadonly, type Ref } from 'vue'

const message = ref<string | null>(null)

/*
 * **Beim Seitenwechsel ist die Meldung fällig.** Sie gehört zu dem Vorgang, der
 * sie ausgelöst hat; wer die Konsole verlässt und später zurückkommt, soll nicht
 * lesen, dass eine Zeile angelegt wurde. Ein `flash` verhält sich genauso — er
 * lebt eine Antwort lang.
 *
 * Der Horcher wird einmal beim Laden des Moduls gesetzt und nie wieder; ein
 * Aufräumen gäbe es nur beim Entladen der Seite, und dann ist ohnehin alles fort.
 */
router.on('navigate', () => {
    message.value = null
})

/** Was gerade zu melden ist — für das Layout, das es rendert. */
export function announcement(): DeepReadonly<Ref<string | null>> {
    return readonly(message)
}

/**
 * Einen abgeschlossenen Vorgang melden.
 *
 * **„ist geschehen" und nicht „ist beauftragt"** (`docs/19 §6.3`): Ein Vorgang,
 * der auf dem Server weiterläuft, gehört ins Protokoll und nicht hierher.
 */
export function announce(text: string): void {
    message.value = text
}
