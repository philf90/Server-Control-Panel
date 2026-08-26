/*
 * Die Rückfrage vor einer Handlung, die etwas kostet.
 *
 * ## Warum es diese Datei gibt
 *
 * Bis zum 15. August 2026 fragte dieses Panel an achtzehn Stellen über
 * `window.confirm`. Auf einem iPhone kam **keine einzige** dieser Rückfragen an
 * (`docs/55`, Befund 16): Safari darf die Dialoge einer Seite abschalten,
 * nachdem sie mehrere gezeigt hat, und `confirm()` gibt danach ohne ein Zeichen
 * `false` zurück. Jede so geschützte Aktion ist damit **tot** — Sperren,
 * Zurückbauen, Löschen, Zurückspielen, einen Vorgang abbrechen.
 *
 * Die Richtung ist die sichere: Es geschieht nichts. Eine Auskunft ist es
 * trotzdem nicht, und ein Panel, dessen Knöpfe auf dem Telefon reihenweise
 * nichts tun, ist auf dem Telefon nicht bedienbar.
 *
 * > **Ein Knopf, dessen Wirkung in einem Dialog steckt, den der Browser
 * > abschalten darf, ist ein Knopf, der nichts tut.**
 *
 * ## Warum im Modul und nicht in einer Komponente
 *
 * Dieselbe Überlegung wie bei {@see useAnnounce}: Die Frage stellt eine Seite,
 * gezeichnet wird sie im Layout, und beide sollen dieselbe Zeile sehen. Ein
 * `provide`/`inject` wäre dasselbe mit mehr Zeremonie.
 *
 * ## Und keine Modale
 *
 * `docs/53` Befund 8 hat für den Rechte-Editor entschieden, dass dieses Panel
 * keine Modalen bekommt. Die Rückfrage ist deshalb ein Block auf der Seite, an
 * derselben Stelle wie die grüne Meldung und die Fehlerzusammenfassung
 * (`docs/19 §6`) — nicht etwas, das über dem Inhalt schwebt.
 */
import { router } from '@inertiajs/vue3'
import { readonly, ref, type DeepReadonly, type Ref } from 'vue'

/** Eine gestellte Frage mit dem, was auf ein Ja folgt. */
export type Pending = {
    /**
     * Die Frage, in Zeilen zerlegt.
     *
     * `window.confirm` nahm `\n\n` und setzte daraus Absätze; dieselbe Form
     * bleibt erhalten, damit die Aufrufer nicht umgeschrieben werden müssen.
     */
    lines: string[]

    /** Die Beschriftung des zustimmenden Knopfes — das Verb der Handlung. */
    verb: string

    /** Ob die Handlung zerstört; entscheidet über die rote Fläche. */
    destructive: boolean

    /**
     * Das Wort, das abgetippt werden muss, bevor der Knopf gedrückt werden kann.
     *
     * **Für die Handlungen, die man nicht aus Versehen tun darf.** Ein Ja/Nein
     * kostet einen Klick, und ein Klick ist genau das, was ein Fehlgriff ist.
     * Wer den Rechnernamen abschreibt, hat den Satz darüber gelesen.
     *
     * `null` heisst „ein Ja genügt" — so wie an allen achtzehn Stellen, die es
     * vor dem 26. August 2026 gab.
     */
    challenge: string | null

    go: (answer: string) => void
}

const pending = ref<Pending | null>(null)

/*
 * **Beim Seitenwechsel fällt eine offene Frage weg.** Sie gehört zu dem, was auf
 * der verlassenen Seite stand; eine Frage nach „Entfernen", die eine Navigation
 * überlebt, bezieht sich auf einen Eintrag, den niemand mehr sieht — derselbe
 * Fall wie die Auswahl im Dateimanager.
 */
router.on('navigate', () => {
    pending.value = null
})

export function useConfirmation(): {
    pending: DeepReadonly<Ref<Pending | null>>
    ask: (
        question: string,
        verb: string,
        go: (answer: string) => void,
        destructive?: boolean,
        challenge?: string,
    ) => void
    accept: (answer?: string) => void
    dismiss: () => void
} {
    /**
     * Fragen — und die Handlung bis zur Antwort zurückhalten.
     *
     * @param question Die Frage. Leerzeilen trennen Absätze, wie bei `confirm`.
     * @param verb Was der zustimmende Knopf sagt („Entfernen", „Sperren").
     * @param go Was bei Zustimmung geschieht.
     * @param destructive Ob dabei etwas verlorengeht. Entscheidet die Farbe.
     * @param challenge Was abgetippt werden muss, bevor der Knopf drückbar ist.
     */
    function ask(
        question: string,
        verb: string,
        go: (answer: string) => void,
        destructive = true,
        challenge?: string,
    ): void {
        pending.value = {
            lines: question.split('\n').filter((zeile) => zeile.trim() !== ''),
            verb,
            destructive,
            challenge: challenge ?? null,
            go,
        }
    }

    /*
     * **Erst leeren, dann ausführen.** Andersherum stünde die Frage noch da,
     * während die Antwort schon läuft — und ein zweiter Klick schickte den
     * Vorgang ein zweites Mal los.
     */
    function accept(answer = ''): void {
        const offen = pending.value

        pending.value = null
        offen?.go(answer)
    }

    function dismiss(): void {
        pending.value = null
    }

    return { pending: readonly(pending), ask, accept, dismiss }
}
