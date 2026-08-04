/*
 * Aufnahmen der Entwürfe 30 — beide Themes, jeder Abschnitt einzeln.
 *
 *   node docs/entwuerfe/30-aufnahmen.mjs          → /tmp/srvpanel-neu
 *   ZIEL=/pfad node docs/entwuerfe/30-aufnahmen.mjs
 *
 * Die Bilder liegen absichtlich nicht im Repo: Sie sind aus der HTML jederzeit
 * wieder herzustellen, und 4 MB PNG neben einer 40 KB Quelle sind eine Kopie,
 * die beim nächsten Entwurf veraltet ist. Dieselbe Entscheidung wie bei
 * 20-stilvorschlaege.html.
 *
 * Playwright liegt in dieser Umgebung global. `playwright install` wird nicht
 * aufgerufen — das Chromium ist vorinstalliert (siehe CLAUDE.md).
 */
import { createRequire } from 'node:module'
import { mkdirSync, readdirSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { execSync } from 'node:child_process'

const hier = dirname(fileURLToPath(import.meta.url))
const entwurf = join(hier, '30-neue-richtungen.html')
const ziel = process.env.ZIEL ?? '/tmp/srvpanel-neu'

// Playwright steht global und nicht in node_modules; gefragt wird npm selbst,
// statt den Pfad einzutragen.
const globalRoot = execSync('npm root -g').toString().trim()
const require = createRequire(pathToFileURL(join(globalRoot, 'x.js')))
const { chromium } = require('playwright')

/*
 * Der Pfad des vorinstallierten Chromium trägt die Revisionsnummer im Namen,
 * und die ändert sich mit jeder Playwright-Fassung. Fest eingetragen wäre er
 * beim nächsten Bild falsch — also gesucht.
 */
const browsers = '/opt/pw-browsers'
const revision = readdirSync(browsers).find(name => /^chromium-\d+$/.test(name))

if (!revision) {
  throw new Error(`Kein Chromium unter ${browsers}. Niemals „playwright install" aufrufen, siehe CLAUDE.md.`)
}

mkdirSync(ziel, { recursive: true })

const browser = await chromium.launch({
  executablePath: join(browsers, revision, 'chrome-linux', 'chrome'),
})

/*
 * Breit genug, dass die Bereiche nebeneinander stehen.
 *
 * Bei 1600px tun sie es im Rahmen dieses Dokuments nicht — abzüglich Rand und
 * Seitenleiste bleiben dem Inhalt 1160px, und zwei Bereiche brauchen mehr.
 * Die erste Aufnahme zeigte deshalb das Gegenteil dessen, was der Entwurf
 * behauptet: alles untereinander.
 */
const page = await browser.newPage({
  viewport: { width: 1980, height: 1200 },
  deviceScaleFactor: 2,
})

await page.goto(pathToFileURL(resolve(entwurf)).href)

const abschnitte = ['werkbank', 'kontor', 'vergleich']

for (const theme of ['dark', 'light']) {
  await page.evaluate(wert => document.documentElement.setAttribute('data-theme', wert), theme)

  // Kurz warten: Die Marken wechseln über CSS, und der erste Frame danach
  // trägt gemischte Farben.
  await page.waitForTimeout(150)

  for (const [index, name] of abschnitte.entries()) {
    await page.locator('.abschnitt').nth(index).screenshot({ path: join(ziel, `${name}-${theme}.png`) })
  }
}

await browser.close()
console.log(`${abschnitte.length * 2} Aufnahmen in ${ziel}`)
