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
 *
 * ---
 *
 * **Und jeder Fund nennt seinen Ort, nicht seine Bauart.** Bis zum 19. August
 * stand als Kennzeichen nur `Marke.Klassen` da. Für einen Baustein mit Klasse
 * genügt das; ein `div` ohne jede Klasse heisst dann aber `div`, und dieselbe
 * Zeile stand in der Bilderrunde viermal in vier Ansichten, ohne dass sie
 * irgendwohin zeigte. Der Ort ist deshalb der Weg von `body` herab und die
 * ersten Zeichen des Markups.
 *
 * > **Eine Zahl, die nicht sagt, welche, zwingt zum Suchen.**
 *
 * ---
 *
 * **Jede Zeile nennt den Stand des Messmittels, das sie erzeugt hat.** Dieses
 * Skript lebt in der Konsole und verschwindet bei jedem Neuladen — es kommt
 * also aus der Zwischenablage zurück, und die altert nicht sichtbar. Am
 * 19. August ist genau das passiert: Die Messung kam mit den alten Feldern
 * wieder, und der Ausdruck sah aus wie ein Ergebnis.
 *
 * > **Ein Werkzeug, das nach jedem Neuladen aus der Zwischenablage kommt, ist
 * > so alt wie die Zwischenablage und sagt es nicht.**
 *
 * Der Stand steht deshalb im Ergebnis, neben Breite und Thema und aus demselben
 * Grund: damit keine Zeile ihre Herkunft verliert. Wer dieses Skript ändert,
 * setzt ihn auf das Datum der Änderung.
 */

/** Der Tag, an dem dieses Messmittel zuletzt geändert wurde. */
const STAND = '2026-08-21'

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

  /*
   * Der Weg von `body` herab, damit ein Fund ohne Klasse auffindbar ist. Je
   * Stufe die Marke, die erste Klasse (mehr macht die Zeile unlesbar) und der
   * Platz unter den Geschwistern derselben Marke.
   */
  const pfad = (element) => {
    const stufen = []

    for (let n = element; n && n !== document.body; n = n.parentElement) {
      const marke = n.tagName.toLowerCase()
      const klasse = typeof n.className === 'string' && n.className.trim() !== ''
        ? '.' + n.className.trim().split(/\s+/)[0]
        : ''
      const gleiche = [...(n.parentElement?.children ?? [])].filter((k) => k.tagName === n.tagName)

      stufen.unshift(marke + klasse + (gleiche.length > 1 ? `:${gleiche.indexOf(n) + 1}` : ''))
    }

    // `body` selbst und alles darüber hätte sonst einen leeren Weg.
    return stufen.join(' > ') || element.tagName.toLowerCase()
  }

  /*
   * Ein Kasten, der nur für die Vorlesesoftware da ist — und alles darin.
   *
   * **Das ist Befund 2 aus `docs/66`.** Die übliche Technik dafür ist
   * `width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%)`; ein
   * solcher Kasten hat **immer** `scrollWidth > clientWidth`, und `hidden`
   * steht nicht in der Liste der erlaubten Roller. Auf jeder Seite mit
   * Passwortfeldern standen so fünf Geisterzeilen in `schiebt`, auf jeder mit
   * einer Kärtchentabelle zwei — und wer sie dreimal überliest, überliest beim
   * vierten Mal den echten Fund.
   *
   * > **Eine Liste, die auch das Gewollte nennt, ist ein Hinweis und kein
   * > Urteil.**
   *
   * **Die Vorfahren gehören dazu.** Bei `.stacks thead` trägt der Kopf die
   * Klippung, und in `schiebt` stand trotzdem auch das `tr` darin: Es ist
   * 1 px breit, weil sein Behälter es ist, klippt aber selbst nicht.
   *
   * **Eng gefasst, und zwar mit Absicht.** Ein Filter über `overflow: hidden`
   * allein nähme die halbe Messung mit; verlangt sind beide Merkmale
   * zusammen — geklippt **und** auf einen Punkt zusammengezogen.
   */
  const nurFuerVorlesen = (element) => {
    for (let n = element; n && n !== document.body; n = n.parentElement) {
      const stil = getComputedStyle(n)
      const geklippt = stil.clipPath !== 'none' || (stil.clip !== 'auto' && stil.clip !== '')

      if (geklippt && n.clientWidth <= 1 && n.clientHeight <= 1) {
        return true
      }
    }

    return false
  }

  const roller = []

  /*
   * **Gezählt und nicht verschwiegen.** Eine Messung, die etwas weglässt, sagt
   * daneben, wie viel — sonst liest sich eine kurze Liste wie eine heile Seite.
   *
   * > **Kein stiller Deckel: Wer die Sicht begrenzt, nennt die Zahl dazu.**
   */
  let versteckt = 0

  for (const element of document.querySelectorAll('*')) {
    const ueberlauf = element.scrollWidth - element.clientWidth

    if (ueberlauf <= 0) {
      continue
    }

    if (nurFuerVorlesen(element)) {
      versteckt++
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
      pfad: pfad(element),
      anfang: element.outerHTML.slice(0, 120),
      ueberlauf,
      darf: stil === 'auto' || stil === 'scroll',
    })
  }

  return {
    stand: STAND,
    breite: wurzel.clientWidth,
    thema: wurzel.getAttribute('data-theme') ?? '(System)',
    dokument: wurzel.scrollWidth - wurzel.clientWidth,
    gegenprobe: gegenprobe(),
    schiebt: roller.filter((r) => !r.darf),
    rollt: roller.filter((r) => r.darf),
    versteckt,
  }
}
