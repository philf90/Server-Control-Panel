/*
 * Bei welchen Breiten fällt ein Umbruch **innerhalb** eines Wertes?
 *
 *     node tests/umbruch-messen.mjs <app.css> <faelle.json>
 *
 * ## Warum es diese Vorschrift gibt
 *
 * `docs/76` Befund 1 und 2 sind mit einer Umbruchgelegenheit nach jedem `:`
 * und `.` behoben worden (`Idents.vue`). Auf dem Server hat sich am 23. August
 * gezeigt, dass die Behebung an anderer Stelle Schaden anrichtet: In einem
 * **Satz** gewinnt so eine Gelegenheit gegen das Leerzeichen daneben, und die
 * Zeile bricht mitten durch eine IPv4.
 *
 * > **Eine Umbruchgelegenheit bricht, sobald es passt. `overflow-wrap:
 * > anywhere` bricht nur, wenn es sein muss.**
 *
 * **Zwei Breiten beantworten diese Frage nicht.** Ob eine Gelegenheit gegen ein
 * Leerzeichen gewinnt, hängt daran, wo die Zeile gerade endet — also an der
 * Breite, und zwar an jeder einzelnen. Gemessen wird deshalb der ganze Bereich
 * von 320 bis 1600 px in Vierer-Schritten, je Fall und je Fassung.
 *
 * ## Was es misst
 *
 * Je Fall und Fassung (mit und ohne `<wbr>`): bei wie vielen der 321 Breiten
 * mindestens ein Wert über zwei Zeilen geht.
 *
 * **Gemessen wird an den Zeichen und nicht an den Rechtecken des Elements.**
 * Ein `<wbr>` ist ein Element und zerteilt die Rechteckliste seines
 * Elternspans — die erste Fassung dieser Sonde meldete damit für **jede**
 * Breite einen Bruch, auch für 1600, und war keine Messung, sondern eine
 * Konstante.
 *
 * > **Eine Sonde, die für jede Eingabe dasselbe sagt, hat nichts gemessen.**
 *
 * ## Die Gegenprobe
 *
 * Ein Fall mit dem Namen `kontrolle` muss in **beiden** Fassungen brechen — er
 * trägt einen Wert, der auf keine Zeile passt. Tut er es nicht, sieht die Sonde
 * keinen Bruch, und alle Nullen daneben bedeuten nichts. Das Skript endet dann
 * mit Rückgabewert 1.
 *
 * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
 * > steht.**
 */
import { createRequire } from 'node:module'
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'

/**
 * Chromium holen, egal wo Playwright in dieser Umgebung liegt.
 *
 * **Warum das nicht ein `import 'playwright-core'` ist.** Dieses Projekt hat
 * Playwright nicht als Abhängigkeit — es steht in der CI und in diesem
 * Container **global**, und ein ESM-`import` sucht nur neben der Datei. Eine
 * Abhängigkeit dafür aufzunehmen hiesse, jedem `npm ci` einen Browser
 * anzuhängen, den nur diese eine Messung braucht.
 *
 * Gesucht wird deshalb der Reihe nach: neben dem Projekt, unter
 * `PLAYWRIGHT_MODULE`, und im globalen Wurzelverzeichnis von npm.
 */
const ladeChromium = () => {
  const require = createRequire(import.meta.url)
  const orte = ['playwright-core', 'playwright']

  if (process.env.PLAYWRIGHT_MODULE) orte.push(process.env.PLAYWRIGHT_MODULE)

  try {
    const global = execFileSync('npm', ['root', '-g'], { encoding: 'utf8' }).trim()

    orte.push(`${global}/playwright-core`, `${global}/playwright`)
  } catch {
    // Kein npm im Pfad — dann bleiben die Orte davor.
  }

  for (const ort of orte) {
    try {
      return require(ort).chromium
    } catch {
      // Weiter zum nächsten Ort.
    }
  }

  console.error(
    'Playwright ist nicht auffindbar. Gesucht wurde:\n  '
    + orte.join('\n  ')
    + '\n\nEntweder PLAYWRIGHT_MODULE auf das Verzeichnis setzen oder das Skript dort fahren,'
    + '\nwo playwright-core liegt.',
  )
  process.exit(1)
}

const chromium = ladeChromium()

const VON = 320
const BIS = 1600
const SCHRITT = 4

const css = readFileSync(process.argv[2], 'utf8')
const faelle = JSON.parse(readFileSync(process.argv[3], 'utf8'))

/** Ein Wert, zerlegt an seinen eigenen Trennzeichen — wie `Idents.vue`. */
const stuecke = (wert) => {
  const aus = []
  let rest = ''

  for (const zeichen of wert) {
    rest += zeichen

    if (zeichen === '.' || zeichen === ':') {
      aus.push(rest)
      rest = ''
    }
  }

  if (rest) aus.push(rest)

  return aus
}

const mitWbr = (werte) => werte
  .map((w) => `<span data-wert>${stuecke(w).map((s, i) => (i ? '<wbr>' : '') + s).join('')}</span>`)
  .join(', ')

const ohneWbr = (werte) => werte.map((w) => `<span data-wert>${w}</span>`).join(', ')

/** Geht ein Wert über mehr als eine Zeile? */
const getrennt = () =>
  [...document.querySelectorAll('[data-wert]')].some((element) => {
    const lauf = document.createTreeWalker(element, NodeFilter.SHOW_TEXT)
    const oben = new Set()

    for (let text = lauf.nextNode(); text; text = lauf.nextNode()) {
      for (let i = 0; i < text.length; i++) {
        const bereich = document.createRange()

        bereich.setStart(text, i)
        bereich.setEnd(text, i + 1)
        oben.add(Math.round(bereich.getBoundingClientRect().top))
      }
    }

    return oben.size > 1
  })

/*
 * `PLAYWRIGHT_BROWSERS_PATH` setzt dieser Container auf `/opt/pw-browsers`;
 * dort liegt ein vorinstalliertes Chromium, und `playwright install` ist
 * ausdrücklich nicht der Weg (CLAUDE.md). Anderswo entscheidet Playwright
 * selbst — deshalb der Pfad nur, wenn es ihn gibt.
 */
const browser = await chromium.launch(
  existsSync('/opt/pw-browsers/chromium') ? { executablePath: '/opt/pw-browsers/chromium' } : {},
)
const page = await browser.newPage({ viewport: { width: BIS, height: 900 } })
const ergebnisse = []

for (const fall of faelle) {
  const zeile = { fall: fall.name, breiten: (BIS - VON) / SCHRITT + 1 }

  for (const [fassung, bau] of [['ohne', ohneWbr], ['mit', mitWbr]]) {
    await page.setContent(
      `<!doctype html><meta charset="utf-8"><style>${css}\nbody{margin:0}.probe{padding:0 16px}</style>`
      + `<div class="content probe" data-theme="light">${fall.markup.replace('%s', bau(fall.werte))}</div>`,
    )

    const breiten = []

    for (let breite = VON; breite <= BIS; breite += SCHRITT) {
      await page.setViewportSize({ width: breite, height: 900 })

      if (await page.evaluate(getrennt)) breiten.push(breite)
    }

    zeile[fassung] = { getrennt: breiten.length, von: breiten[0] ?? null, bis: breiten.at(-1) ?? null }
  }

  ergebnisse.push(zeile)
  console.log(JSON.stringify(zeile))
}

await browser.close()

const kontrolle = ergebnisse.find((z) => z.fall === 'kontrolle')

if (!kontrolle) {
  console.error('Es gibt keinen Fall `kontrolle` — ohne ihn ist keine Null in dieser Ausgabe eine Messung.')
  process.exit(1)
}

if (kontrolle.ohne.getrennt === 0 || kontrolle.mit.getrennt === 0) {
  console.error('Die Gegenprobe `kontrolle` bricht nicht — die Sonde sieht keinen Bruch, und alle Nullen daneben bedeuten nichts.')
  process.exit(1)
}
