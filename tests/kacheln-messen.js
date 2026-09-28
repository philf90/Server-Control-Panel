/*
 * Was die Kachelreihe einer Abonnement- oder Domainseite zeigt — je Kachel eine
 * Zeile, dazu die Ablesung an der Kurve.
 *
 * In die Konsole des Browsers einfügen, dann auf einer frisch geladenen Seite:
 *
 *     kachelnMessen()     die Reihe, wie sie dasteht
 *     ablesungMessen()    die Ablesung am ersten und am letzten Punkt
 *
 * Geschrieben für den Abnahmelauf von B4 (`docs/139`). Der Lauf hält jede Zeile
 * gegen das, was der Server für dieselbe Seite rechnet (`docs/139` Block 2).
 *
 * ---
 *
 * **Warum es dieses Messmittel neben `bilder-messen.js` gibt.** Das andere
 * misst, wer die Seite schiebt. Eine Kachelreihe kann heil aussehen und
 * trotzdem etwas anderes zeigen, als in der Tabelle steht — eine Zahl mit der
 * falschen Einheit, eine Kurve aus einem Punkt, eine leere Kachel, die kürzer
 * ist als ihre Nachbarn. Das sieht man auf einem Bild nur, wenn man es sucht.
 *
 * > **Ein Bild, das man auf eine Frage hin ansieht, beantwortet die Frage —
 * > und verdeckt alles, was daneben steht.**
 *
 * **Gezählt werden die Stützstellen am Pfad und nicht an der Ablage.** Ein
 * `M` und je Punkt danach ein `L` — so baut `Tile.vue` die Linie. Eine Zahl
 * aus der Inertia-Ablage sagte, was der Server geschickt hat; der Pfad sagt,
 * was gezeichnet ist.
 *
 * ---
 *
 * **Der Ladebeleg steht in der Kopfzeile: `reihe=flex`.** Ohne Stylesheet ist
 * die Reihe ein gewöhnlicher Block, und jede Kachel steht so hoch da, wie ihr
 * Inhalt es will. Gemessen am 28. September 2026 im Container: ohne
 * Stylesheet `reihe=block` und jede Kachel 503 px hoch, mit ihm `flex` und
 * 196 px.
 *
 * > **Ein Ladebeleg gehört in die Messung und nicht in die Erinnerung.**
 *
 * **Und eine Kachel, die weder Kurve noch Leerzustand trägt, heisst hier
 * `UNKLAR`** und nicht „leer": Eine Zeile, die aus einem nicht gefundenen
 * Element einen Zustand macht, sieht aus wie eine Messung.
 *
 * ---
 *
 * **Jede Zeile nennt den Stand des Messmittels, das sie erzeugt hat** — aus
 * demselben Grund wie in `bilder-messen.js`: Dieses Skript kommt nach jedem
 * Neuladen aus der Zwischenablage, und die altert nicht sichtbar. Wer es
 * ändert, setzt den Stand auf das Datum der Änderung. Die Namen sind andere als
 * dort, damit beide Skripte in dieselbe Seite passen.
 *
 * **Beide Aufrufe geben ihr Ergebnis auch als Objekt zurück, mit dem Stand
 * darin.** Gelesen wird die gedruckte Zeile: Die Konsole klappt ein Objekt auf
 * wenige Schlüssel zusammen, und was man abschreibt, ist dann eine Auswahl,
 * die niemand getroffen hat.
 *
 * **Was es nicht kann:** den Finger. `ablesungMessen()` schickt ein
 * `pointermove` aus der Konsole; ob ein Telefon beim Streichen abliest statt zu
 * rollen, sagt nur ein Telefon.
 */

/** Der Tag, an dem dieses Messmittel zuletzt geändert wurde. */
const KACHEL_STAND = '2026-09-28'

/*
 * Ob in dieser geladenen Seite schon gemessen und schon abgelesen wurde.
 *
 * Dieselbe Vorkehrung wie in `bilder-messen.js`, aus zwei eigenen Gründen.
 *
 * **Ein zweites `kachelnMessen()` richtet selbst keinen Schaden an** — es liest
 * nur, und `Tile.vue` misst seine Grösse bei jedem Zeigerereignis neu. Die
 * Sperre ist hier der Beleg: Der Lauf verlangt je Lage eine frisch geladene
 * Seite, und wer ohne Neuladen weitermisst, bekommt einen Fehler statt einer
 * Zeile. Dass jede Lage eine eigene Seite hatte, steht dann in den Zahlen
 * selbst.
 *
 * **Nach der Ablesung wirft auch `kachelnMessen()`.** Die Ablesung streicht mit
 * dem Zeiger über jede Kurve. Findet eine Kachel danach nicht in ihren
 * Ruhezustand zurück, läse die Reihe den Zustand danach und hielte ihn für die
 * Seite. Die Messung kommt deshalb zuerst, die Ablesung danach.
 *
 * Geworfen und nicht zurückgegeben: Ein Rückgabewert, der eine Weigerung
 * ausdrückt, steht in derselben Spalte wie ein Ergebnis und wird abgeschrieben.
 */
let kachelnGelaufen = false
let ablesungGelaufen = false

function kachelnMessen () {
  if (ablesungGelaufen) {
    throw new Error('Die Ablesung ist in dieser Seite schon gelaufen. Seite neu laden — sonst misst die Reihe den Zustand, den der Zeiger hinterlassen hat.')
  }

  if (kachelnGelaufen) {
    throw new Error('Schon gemessen. Seite neu laden — jede Lage bekommt ihre eigene geladene Seite.')
  }

  kachelnGelaufen = true

  const wurzel = document.documentElement
  const reihe = document.querySelector('.tiles')
  const kacheln = [...(reihe?.querySelectorAll(':scope > .tile') ?? [])].map((kachel) => {
    const wert = kachel.querySelector('.tile-value')
    const linie = kachel.querySelector('.trend .line:not(.second)')?.getAttribute('d') ?? ''
    const punkte = (linie.match(/[ML]/g) ?? []).length
    const kurve = kachel.querySelector('.trend svg') !== null
    const leer = kachel.querySelector('.trend.blank') !== null

    return {
      name: kachel.querySelector('.tile-label')?.textContent.trim() ?? '?',
      zahl: [...(wert?.childNodes ?? [])]
        .filter((knoten) => knoten.nodeType === Node.TEXT_NODE)
        .map((knoten) => knoten.textContent)
        .join('')
        .trim(),
      einheit: wert?.querySelector('small')?.textContent.trim() ?? '',
      punkte,
      zustand: kurve && punkte >= 2 ? 'Kurve' : leer && !kurve ? 'leer' : 'UNKLAR',
      warnt: kachel.querySelector('.trend.tight') !== null,
      hoehe: Math.round(kachel.getBoundingClientRect().height),
      unterzeile: (kachel.querySelector('.tile-sub')?.textContent ?? '').replace(/\s+/g, ' ').trim(),
    }
  })

  // Der Satz unter der Reihe gehört zur Domainseite: Die Zahl des Providers
  // liegt höher, und das steht neben der Zahl und nicht in einer Fussnote.
  const danach = reihe?.nextElementSibling ?? null

  const ergebnis = {
    stand: KACHEL_STAND,
    pfad: location.pathname,
    breite: wurzel.clientWidth,
    thema: wurzel.getAttribute('data-theme') ?? '(System)',
    // Der Ladebeleg: Ohne Stylesheet steht hier `block`.
    reihe: reihe === null ? null : getComputedStyle(reihe).display,
    hoehe: reihe === null ? null : Math.round(reihe.getBoundingClientRect().height),
    kacheln,
    darunter: danach !== null && danach.matches('p.quiet')
      ? danach.textContent.replace(/\s+/g, ' ').trim()
      : null,
  }

  const kopf = `stand=${ergebnis.stand} ${ergebnis.pfad}  breite=${ergebnis.breite}  thema=${ergebnis.thema}`

  if (reihe === null) {
    console.log(`${kopf}  keine Kachelreihe`)

    return ergebnis
  }

  const zeilen = kacheln.map((kachel) => [
    kachel.name.padEnd(14),
    `${kachel.zahl} ${kachel.einheit}`.trim().padEnd(12),
    `${String(kachel.punkte).padStart(2)} Punkte`,
    kachel.zustand.padEnd(5),
    kachel.warnt ? 'warnt' : '-    ',
    `${kachel.hoehe} px`,
    `| ${kachel.unterzeile}`,
  ].join('  '))

  console.log(
    `${kopf}  reihe=${ergebnis.reihe}  höhe=${ergebnis.hoehe} px  kacheln=${kacheln.length}\n  ${zeilen.join('\n  ')}` +
    (ergebnis.darunter === null ? '' : `\n  darunter: ${ergebnis.darunter}`)
  )

  return ergebnis
}

/*
 * Die Ablesung am ersten und am letzten Punkt jeder Kurve.
 *
 * Gezeigt wird dabei derselbe Weg, den eine Maus nimmt: `pointermove` auf dem
 * Feld, danach `pointerleave`. Die Zeile „danach" belegt, dass die Kachel in
 * ihren Ruhezustand zurückfindet — eine Ablesung, die stehenbleibt, verdeckt
 * die Einordnung, für die die Zeile sonst da ist.
 *
 * Bei zwei Kurven in einem Feld liest die Probe die obere; welche das ist,
 * entscheidet der Wert und nicht die Reihenfolge.
 */
async function ablesungMessen () {
  if (ablesungGelaufen) {
    throw new Error('Schon abgelesen. Seite neu laden — der erste Lauf hat mit dem Zeiger über jede Kurve gestrichen.')
  }

  ablesungGelaufen = true

  const warten = () => new Promise((fertig) => setTimeout(fertig, 30))
  const ablesungen = []

  for (const kachel of document.querySelectorAll('.tiles > .tile')) {
    const name = kachel.querySelector('.tile-label')?.textContent.trim() ?? '?'
    const feld = kachel.querySelector('.trend svg')
    const unterzeile = () => (kachel.querySelector('.tile-sub')?.textContent ?? '').replace(/\s+/g, ' ').trim()

    if (feld === null) {
      ablesungen.push({ name, kurve: false })
      continue
    }

    const kasten = feld.getBoundingClientRect()
    const lesen = async (x) => {
      feld.dispatchEvent(new PointerEvent('pointermove', { clientX: x, clientY: kasten.top + 1, bubbles: true }))
      await warten()

      return unterzeile()
    }
    const erster = await lesen(kasten.left + 1)
    const letzter = await lesen(kasten.right - 1)

    feld.dispatchEvent(new PointerEvent('pointerleave'))
    await warten()
    ablesungen.push({ name, kurve: true, erster, letzter, danach: unterzeile() })
  }

  const ergebnis = { stand: KACHEL_STAND, pfad: location.pathname, ablesungen }
  const zeilen = ablesungen.map((ablesung) => ablesung.kurve
    ? `${ablesung.name.padEnd(14)} ${ablesung.erster}  …  ${ablesung.letzter}  | danach: ${ablesung.danach}`
    : `${ablesung.name.padEnd(14)} keine Kurve`)

  console.log(`stand=${ergebnis.stand} ${ergebnis.pfad}  Ablesung am ersten und am letzten Punkt\n  ${zeilen.join('\n  ')}`)

  return ergebnis
}
