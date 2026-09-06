/*
 * Die Messung zu Punkt 3 des A14-Abnahmelaufs: Ist ein Band so hoch wie eines
 * mit dem zehnfachen Text?
 *
 * In die Konsole des Browsers einfügen, dann je Lage aufrufen:
 *
 *     baenderMessen()
 *
 * **Warum ein eigenes Messmittel neben `bilder-messen.js`.** Jenes misst
 * Überlauf — welches Element waagerecht schiebt und wer daran schuld ist.
 * Punkt 3 fragt nach einer **Höhe**, und zwar nach ihrer Unabhängigkeit von
 * der Textlänge. Beides in ein Werkzeug zu legen hiesse, jede Bilderrunde mit
 * Feldern zu belasten, die es nur auf drei Seiten gibt.
 *
 * ---
 *
 * **Gemessen wird eine Eigenschaft und nicht eine Zahl.** Was die
 * Zeilenklammer aus `docs/103 §4.3` zusagt, ist eine **Obergrenze** — der
 * Streifen kann nicht mit dem Text wachsen. Die Zahl steht daneben, damit ein
 * Ausreisser auffällt; sie hängt an der Umgebung, die Gleichheit nicht.
 *
 * > **Eine Differenz zweier Messungen unter denselben Bedingungen trägt, auch
 * > wenn die absoluten Werte an der Umgebung hängen.**
 *
 * **Und die Eigenschaft gilt oberhalb der Umbruchschwelle.** Am 6. September
 * 2026 an der echten Seite gemessen, 390 px: 23 und 40 Zeichen (mitsamt dem
 * Rangwort) ergeben **41 px**, 65 und mehr ergeben **62 px**. Ein Prüfkörper
 * unter der Schwelle ist also zu Recht kürzer, und `gleich=false` heisst dann
 * nicht, dass die Klammer nicht hält.
 *
 * > **Ein Prüfkörper, der dicht an einer Schwelle liegt, misst die Schwelle
 * > und nicht die Eigenschaft.**
 *
 * Bei 1440 px liegt dieselbe Schwelle bei rund 160 Zeichen je Zeile — dort
 * sind kurze Bänder 41 px und lange 62 px, und auch das ist kein Befund.
 * Punkt 3 wird deshalb bei 390 px gemessen und nicht breit.
 *
 * ---
 *
 * **Die Gegenprobe ist dieselbe wie in `bilder-messen.js`** und aus demselben
 * Grund an `scrollWidth` gebunden statt an `clientWidth` (`docs/59` Befund 22):
 * Auf einer Seite, die schon schiebt, wäre ein Prüfkörper von
 * `clientWidth + 200` nicht mehr das Breiteste, und der Ausschlag fiele auf 0
 * — ausgerechnet dort, wo die Messung ihre Arbeitsfähigkeit belegen müsste.
 * Er muss **200** ergeben.
 *
 * **Und derselbe Prüfkörper hat eine zweite Falle** (`docs/96 §8`): Beim
 * zweiten Aufruf ohne Neuladen ist sein eigener Block von eben schon Teil des
 * Masses, und heraus kommen 400. Dieses Messmittel weigert sich deshalb, statt
 * den Menschen um ein Neuladen zu bitten.
 *
 * > **Ein Prüfmittel, das seine eigene Falle nur beschreibt, überlässt sie
 * > dem, der sie am wenigsten sehen kann — dem Leser des Ergebnisses.**
 */

/** Der Tag, an dem dieses Messmittel zuletzt geändert wurde. */
const BAENDER_STAND = '2026-09-06'

/** Ob in dieser geladenen Seite schon gemessen wurde. */
let baenderGelaufen = false

function baenderMessen () {
  const wurzel = document.documentElement

  if (baenderGelaufen) {
    throw new Error('Schon gemessen. Seite neu laden — sonst misst die Gegenprobe ihren eigenen Block von eben.')
  }

  baenderGelaufen = true

  const huelle = document.querySelector('.bands')
  const baender = [...document.querySelectorAll('.band')]

  /*
   * Gezählt wird der **sichtbare** Text mitsamt dem Rangwort, weil genau der
   * umbricht. Die Zahl aus der Verwaltungstabelle wäre eine andere und stünde
   * hier neben einer Höhe, zu der sie nicht gehört.
   */
  const zeilen = baender.map((band, i) => ({
    nr: i + 1,
    rang: (band.querySelector('.rank')?.textContent ?? '').trim(),
    zeichen: (band.textContent ?? '').trim().length,
    hoehe: band.offsetHeight,
  }))

  const hoehen = [...new Set(zeilen.map((z) => z.hoehe))]

  // Der Prüfkörper: 200 px breiter als **alles**, was schon da ist.
  const koerper = document.createElement('div')

  koerper.style.cssText = `width:${wurzel.scrollWidth + 200}px;height:1px`
  document.body.append(koerper)

  const gegenprobe = wurzel.scrollWidth - wurzel.clientWidth

  koerper.remove()

  const schiebt = wurzel.scrollWidth - wurzel.clientWidth

  /*
   * Als **eine Zeile** und nicht als Objekt: Die Konsole klappt ein Objekt auf
   * fünf Schlüssel zusammen, und was jemand daraus abschreibt, ist dann eine
   * Auswahl, die niemand getroffen hat.
   */
  console.log(
    `stand=${BAENDER_STAND} breite=${wurzel.clientWidth} ` +
    `thema=${wurzel.getAttribute('data-theme') ?? '(System)'} ` +
    `baender=${zeilen.length} huelle=${huelle ? huelle.offsetHeight : '(keine)'} ` +
    `hoehen=[${hoehen.join(',')}] gleich=${hoehen.length === 1} ` +
    `schiebt=${schiebt} gegenprobe=${gegenprobe} (soll 200)`
  )
  console.table(zeilen)

  return {
    stand: BAENDER_STAND,
    breite: wurzel.clientWidth,
    thema: wurzel.getAttribute('data-theme') ?? '(System)',
    huelle: huelle ? huelle.offsetHeight : null,
    hoehen,
    gleich: hoehen.length === 1,
    schiebt,
    gegenprobe,
    zeilen,
  }
}
