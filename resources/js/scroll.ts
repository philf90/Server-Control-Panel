/*
 * Etwas, das die Seite gerade sagt, ins Bild holen.
 *
 * ## Der Fehler, für den es diese Datei gibt
 *
 * Der Betreiber hat am 15. August 2026 auf einem iPhone „Entfernen" an einer
 * Zeile weit unten gedrückt. Die Rückfrage erscheint oben auf der Seite
 * (`docs/19 §6`) — **und sichtbar geschah gar nichts.** Wer nicht von selbst
 * nach oben scrollt, hält den Knopf für kaputt.
 *
 * > **Eine Antwort, die ausserhalb des Bildes steht, ist für den Fragenden
 * > keine.**
 *
 * Das ist derselbe Vorgang wie bei `FormErrors`, nur andersherum. Dort stand
 * die Meldung am Feld und niemand fand sie, weil die Seite nach oben sprang;
 * die Zusammenfassung oben war die Antwort darauf. **Sie hat sich auf dieses
 * Springen verlassen** — und mit `preserveScroll: true` gibt es das nicht: In
 * `Files/Index.vue` allein stehen zehn Griffe, die es setzen, weil eine Liste
 * nach jedem Klick nach oben zu springen unbrauchbar wäre.
 *
 * > **Eine Regel, die sich auf ein Verhalten des Frameworks stützt, gilt nur
 * > dort, wo dieses Verhalten eingeschaltet ist.**
 *
 * ## Warum nur, wenn es nötig ist
 *
 * Ein `scrollIntoView` bei jeder Meldung reisst die Seite auch dann herum, wenn
 * die Meldung längst im Bild steht — auf einem Bildschirm mit 1440px ist das
 * fast immer der Fall. Gescrollt wird deshalb erst, wenn wirklich etwas fehlt.
 */

/**
 * Steht dieses Element ganz im sichtbaren Bereich?
 *
 * Grosszügig gerechnet: Ein paar Pixel Überstand sind kein Grund zu springen.
 */
function fullyVisible(element: HTMLElement): boolean {
    const box = element.getBoundingClientRect()
    const höhe = window.innerHeight || document.documentElement.clientHeight

    return box.top >= 0 && box.bottom <= höhe
}

/**
 * Das Element ins Bild holen — und den Tastaturweg dorthin öffnen.
 *
 * **Der Fokus wandert auf den Block und nicht auf seinen Knopf.** „Entfernen"
 * zu fokussieren hiesse, dass ein Druck auf die Leertaste die Handlung
 * auslöst, die gerade erst erfragt wurde. Der Block selbst nimmt den Fokus
 * (`tabindex="-1"`), und der nächste Tabulator landet darin.
 */
export function bringIntoView(element: HTMLElement | null): void {
    if (element === null) {
        return
    }

    if (! fullyVisible(element)) {
        /*
         * `center` für das Kurze, `start` für das Hohe.
         *
         * `block: 'center'` und nicht `'start'`: Bei einer kurzen Meldung stünde
         * sie sonst unter der Kopfleiste, die auf dem Telefon mitläuft.
         *
         * **Für einen Block, der höher ist als das Fenster, ist genau das
         * falsch.** Zentrieren heisst dort: Die Mitte kommt ins Bild und der
         * Anfang liegt darüber — bei einem Verzeichnisbaum also die Wurzel und
         * die ersten Einträge, und die sind meistens das Ziel. Gemessen am
         * 20. August: `oben: -98` beim Zielbaum des Dateimanagers, und die
         * abgeschnittenen 98 px trugen „Abo-Wurzel", `.ssh`, `conf` und
         * `httpdocs` (`docs/64`, Befund 18).
         *
         * > **Etwas zu zentrieren, das nicht hineinpasst, schneidet oben ab.**
         */
        const höhe = window.innerHeight || document.documentElement.clientHeight
        const zuHoch = element.getBoundingClientRect().height > höhe

        element.scrollIntoView({ block: zuHoch ? 'start' : 'center' })
    }

    element.focus({ preventScroll: true })
}
