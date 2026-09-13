/*
 * Klebt der Nummernstreifen des Protokolls — und deckt er, was unter ihm
 * durchrollt?
 *
 * In die Konsole des Browsers einfügen, dann auf einer frisch geladenen
 * `/logs`-Seite:
 *
 *     klebenMessen()
 *
 * ---
 *
 * **Warum es dieses Messmittel neben `bilder-messen.js` gibt.** Das andere
 * misst, wer die *Seite* schiebt. Ein Nummernstreifen in einem Rollbehälter
 * hat zwei andere Arten, falsch zu sein, und keine davon erzeugt einen
 * Seitenüberlauf:
 *
 *   1. Er rollt mit dem Text weg, statt stehenzubleiben.
 *   2. Er bleibt stehen und **deckt nicht**, was unter ihm hindurchrollt.
 *
 * **Der zweite Fall hat den Abnahmelauf vom 13. September 2026 gekostet**
 * (`docs/916 §7`). Die erste Fassung der Klebeprobe fragte nur den ersten:
 * gemessen `17 → 17`, und das stimmte. Links neben der Nummer stand trotzdem
 * der Anfang jeder Protokollzeile — die sechzehn Pixel Polster des
 * Rollbehälters gehören dem Rollbereich, und ein klebendes Element klebt am
 * **Inhaltsrand** und nicht am Rahmen.
 *
 * > **Eine Probe, die fragt, ob ein Element stehenbleibt, fragt nicht, ob
 * > daneben etwas durchscheint.**
 *
 * ---
 *
 * **Die Selbstprüfung ist tragend und kein Beiwerk.** „Im Streifen steht
 * nichts" sieht genauso aus, ob dort wirklich nichts steht oder
 * `elementFromPoint` hier gar nichts findet. Die Probe fragt deshalb
 * zusätzlich an einer Stelle, an der der Text mit Sicherheit liegt; kommt dort
 * nicht `log-text` zurück, hat sie nichts gemessen und sagt es.
 *
 * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
 * > steht.**
 *
 * ---
 *
 * **Sie lässt die Seite gerollt stehen, und das ist Absicht.** Wer danach ein
 * Bild macht, soll den gemessenen Zustand fotografieren und nicht den
 * aufgeräumten. Genau daran ist die Bilderrunde zu `docs/914 §13`
 * vorbeigelaufen: Sie hat nach dem Rollen zurückgesetzt und **danach**
 * ausgelöst.
 *
 * > **Ein Bild nach einer Messung zeigt den Zustand danach und nicht den
 * > gemessenen.**
 *
 * **Was sie nicht sagen kann:** ob die Farbe der Nummer gegen ihre Fläche
 * lesbar ist, und ob ein Mensch die Nummer beim Kopieren mitbekommt. Das erste
 * ist eine Kontrastrechnung, das zweite eine Auswahl mit der Maus — beides
 * steht als eigener Punkt in `docs/915`.
 *
 * ---
 *
 * **Jede Zeile nennt den Stand des Messmittels, das sie erzeugt hat** — aus
 * demselben Grund wie in `bilder-messen.js`: Dieses Skript kommt nach jedem
 * Neuladen aus der Zwischenablage, und die altert nicht sichtbar. Wer es
 * ändert, setzt den Stand auf das Datum der Änderung.
 */

/** Der Tag, an dem dieses Messmittel zuletzt geändert wurde. */
const KLEBE_STAND = '2026-09-13'

/**
 * Ob in dieser geladenen Seite schon gemessen wurde.
 *
 * Dieselbe Vorkehrung wie in `bilder-messen.js` und aus einem eigenen Grund:
 * Der erste Lauf lässt die Seite **gerollt** stehen. Ein zweiter Aufruf
 * verglich dann einen bereits gerollten Anfangszustand mit sich selbst und
 * fände jedes Kleben in Ordnung.
 *
 * Geworfen und nicht zurückgegeben: Ein Rückgabewert, der eine Weigerung
 * ausdrückt, steht in derselben Spalte wie ein Ergebnis und wird abgeschrieben.
 */
let klebeGelaufen = false

function klebenMessen () {
  if (klebeGelaufen) {
    throw new Error('Schon gemessen. Seite neu laden — der erste Lauf lässt sie gerollt stehen.')
  }

  const rahmen = document.querySelector('.log')

  if (!rahmen) {
    throw new Error('Kein .log auf dieser Seite. Diese Probe gehört auf /logs.')
  }

  const zeilen = document.querySelectorAll('.log-line')

  if (zeilen.length < 4) {
    throw new Error(`Nur ${zeilen.length} Zeile(n) — zu wenig. Eine Quelle mit Inhalt wählen.`)
  }

  klebeGelaufen = true

  // Gemessen wird an einer Zeile aus der Mitte des Sichtbaren und nicht an der
  // ersten: Die erste kann von der Kopfzeile des Behälters überlagert sein.
  const zeile = zeilen[3]
  const nummer = zeile.querySelector('.log-number')
  const text = zeile.querySelector('.log-text')

  const kasten = () => rahmen.getBoundingClientRect()
  const links = (e) => Math.round(e.getBoundingClientRect().left - kasten().left)

  const rollweg = rahmen.scrollWidth - rahmen.clientWidth
  const vorher = links(nummer)

  rahmen.scrollLeft = rollweg
  const nachher = links(nummer)
  const textDanach = links(text)

  // **Wer malt im Streifen zwischen Rahmenkante und Nummer?** Abgetastet in
  // Dreierschritten; ein einzelner Punkt träfe womöglich eine Lücke zwischen
  // zwei Zeichen.
  const mitte = nummer.getBoundingClientRect().top + nummer.getBoundingClientRect().height / 2
  const streifen = []

  for (let x = kasten().left + 2; x < nummer.getBoundingClientRect().left; x += 3) {
    const e = document.elementFromPoint(x, mitte)
    const name = e && typeof e.className === 'string' ? e.className.trim() : null

    if (name && !streifen.includes(name)) streifen.push(name)
  }

  // **Die Selbstprüfung.** Findet die Abtastung den Text dort nicht, wo er mit
  // Sicherheit liegt, ist ein leerer Streifen keine Auskunft.
  const inDerMitte = document.elementFromPoint(
    Math.min(kasten().right - 4, nummer.getBoundingClientRect().right + 40),
    mitte,
  )
  const sichtDaneben = inDerMitte && typeof inDerMitte.className === 'string'
    ? inDerMitte.className.trim()
    : null
  const misst = sichtDaneben !== null && sichtDaneben.includes('log-text')

  const ergebnis = {
    stand: KLEBE_STAND,
    breite: document.documentElement.clientWidth,
    thema: document.documentElement.getAttribute('data-theme') ?? '(System)',
    rollweg,
    nummerVorher: vorher,
    nummerNachher: nachher,
    klebt: rollweg > 0 && vorher === nachher,
    textNachher: textDanach,
    imStreifen: streifen,
    deckt: streifen.length === 0,
    misst,
    sichtDaneben,
  }

  /*
   * Das Urteil zusätzlich als **eine Zeile** — aus demselben Grund wie in
   * `bilder-messen.js`: Die Konsole klappt ein zurückgegebenes Objekt auf
   * wenige Schlüssel zusammen, und was man abschreibt, ist dann eine Auswahl,
   * die niemand getroffen hat.
   */
  console.log(
    `stand=${ergebnis.stand} breite=${ergebnis.breite} thema=${ergebnis.thema} ` +
    `rollweg=${ergebnis.rollweg} nummer=${vorher}->${nachher} klebt=${ergebnis.klebt} ` +
    `imStreifen=[${streifen.join(', ') || '—'}] deckt=${ergebnis.deckt} ` +
    `misst=${ergebnis.misst} (sieht daneben: ${sichtDaneben ?? '—'}) ` +
    `· die Seite bleibt gerollt stehen`
  )

  if (rollweg === 0) {
    console.warn('rollweg=0 — hier ist nichts zu rollen, und klebt bedeutet nichts. Eine Quelle mit langen Zeilen wählen.')
  }

  if (!misst) {
    console.warn('misst=false — die Abtastung findet den Text nicht einmal dort, wo er liegt. Ein leerer Streifen ist dann keine Auskunft.')
  }

  return ergebnis
}
