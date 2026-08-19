/*
 * Die Messung zur Bilderrunde — Überlauf, wer ihn verursacht, und die
 * Gegenprobe.
 *
 * In die Konsole des Browsers einfügen, dann je Lage aufrufen:
 *
 *     bilderMessen()
 *
 * **Warum ein Konsolen-Schnipsel und kein Test.** Was hier gemessen wird,
 * entsteht erst aus echtem Markup, dem gebauten Stylesheet und echten Daten
 * eines echten Abonnements. Der Aufsatz im Container trifft das aufs Pixel
 * (`docs/56` Punkt 5), aber er trifft es an einer Seite, die jemand von Hand
 * zusammengesetzt hat — und `<style scoped>` fehlt dort grundsätzlich, weil
 * Vite die Regeln an ein Attribut bindet, das nur der Übersetzer setzt.
 *
 * ---
 *
 * **Die Gegenprobe ist an das Fenster gebunden, und das ist Befund 22 aus
 * `docs/59`.** Bis zum 17. August war der Prüfkörper ein fester Block von
 * 900 px. Bei 390 px erzeugte er die erwarteten 510; bei 1440 px passte er
 * hinein und die Antwort war `0` — also genau der Wert, den auch eine kaputte
 * Messung liefert.
 *
 * > **Eine Gegenprobe, deren Ausschlag von der Breite abhängt, ist bei der
 * > grösseren Breite keine.**
 *
 * **Und `clientWidth + 200` ist noch nicht genug**, gemessen am 19. August im
 * echten Chromium: Auf einer Seite, die *schon* schiebt, ist der Prüfkörper
 * nicht mehr das Breiteste, und der Ausschlag ist wieder `0` — also
 * ausgerechnet auf der kaputten Seite, auf der die Messung ihre Arbeitsfähigkeit
 * am nötigsten belegen müsste. Er hängt deshalb an `scrollWidth`, nicht an
 * `clientWidth`: 200 px breiter als alles, was ohnehin da ist.
 *
 * > **Ein Prüfkörper, der nur auf der heilen Seite ausschlägt, belegt die
 * > Messung dort, wo sie niemand braucht.**
 *
 * Der erwartete Wert steht daneben: Er *muss* 200 sein. Eine Gegenprobe, deren
 * Ergebnis man vorher kennt, ist die einzige, bei der ein anderer Wert etwas
 * bedeutet.
 *
 * ---
 *
 * **Gemessen wird nicht eine Liste von Selektoren, sondern jedes Element, das
 * waagerecht rollt.** Eine Liste nennt, woran man gerade denkt; ein Fund, der
 * in einem nicht genannten Behälter steckt, fehlt dann in der Zahl und im
 * Bericht. Der Preis dafür ist, dass gewollte Roller mit auftauchen — deshalb
 * steht neben jedem, welcher es ist.
 *
 * > **Eine Prüfung, die nur nachsieht, woran man gerade denkt, prüft das
 * > Erinnerungsvermögen.**
 */

function bilderMessen () {
  const wurzel = document.documentElement

  /*
   * Der Prüfkörper: 200 px breiter als **alles**, was schon da ist, eine Zeile
   * hoch, am Ende des Körpers. Er muss genau 200 ergeben — jeder andere Wert
   * heisst, dass etwas ihn beschneidet, und dann misst der Rest auch nichts.
   */
  const gegenprobe = () => {
    const vorher = wurzel.scrollWidth - wurzel.clientWidth
    const koerper = document.createElement('div')

    koerper.style.cssText = `width:${wurzel.scrollWidth + 200}px;height:1px`
    document.body.append(koerper)

    const nachher = wurzel.scrollWidth - wurzel.clientWidth

    koerper.remove()

    return { ausschlag: nachher - vorher, erwartet: 200 }
  }

  const roller = []

  for (const element of document.querySelectorAll('*')) {
    const ueberlauf = element.scrollWidth - element.clientWidth

    if (ueberlauf <= 0) {
      continue
    }

    // Wer rollen *darf*, hat einen Behälter mit `overflow-x`. Wer schiebt,
    // ohne einen zu haben, ist der Fund — deshalb steht beides in derselben
    // Zeile und wird nicht vorher gefiltert.
    const stil = getComputedStyle(element).overflowX

    roller.push({
      wo: element.tagName.toLowerCase() + (element.className && typeof element.className === 'string'
        ? '.' + element.className.trim().split(/\s+/).join('.')
        : ''),
      ueberlauf,
      darf: stil === 'auto' || stil === 'scroll',
    })
  }

  return {
    breite: wurzel.clientWidth,
    thema: wurzel.getAttribute('data-theme') ?? '(System)',
    dokument: wurzel.scrollWidth - wurzel.clientWidth,
    gegenprobe: gegenprobe(),
    schiebt: roller.filter((r) => !r.darf),
    rollt: roller.filter((r) => r.darf),
  }
}
