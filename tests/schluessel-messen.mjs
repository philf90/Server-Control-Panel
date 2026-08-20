/**
 * Der erzeugte Schlüssel — gemessen statt gelesen (`docs/64 §5.6`, Wunsch 2).
 *
 * **Warum das hier steht und nicht als gewöhnlicher Wächter unter `tests/Unit`:**
 * Kein PHPUnit-Fall dieses Projekts kann sagen, ob OpenSSH eine Datei liest.
 * Das beantwortet nur ein echtes `ssh-keygen`.
 *
 * > **Ein Wert, den nur die Dokumentation kennt, ist eine Vermutung mit
 * > Fussnote.**
 *
 * **Es misst den Baustein und keine Abschrift.** `resources/js/ssh/openssh.ts`
 * wird hier eingebunden — dieselbe Datei, die auch die Seite einbindet. Zwei
 * Fassungen derselben Serialisierung wären genau der Fehler, gegen den dieses
 * Repo sonst überall steht.
 *
 * **Jede Messung trägt ihre Gegenprobe.** Ein `ja` ohne ein `NEIN` daneben
 * belegt nichts:
 *
 * > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
 * > steht.**
 *
 * **Es hinterlässt kein Schlüsselmaterial.** Alles entsteht in einem eigenen
 * Verzeichnis unter `os.tmpdir()` und wird am Ende gelöscht — auch wenn eine
 * Messung fehlschlägt.
 *
 *     node tests/schluessel-messen.mjs
 *
 * Braucht `ssh-keygen` (Paket `openssh-client`) und ein Node, das TypeScript
 * einbinden kann (22.18 oder neuer). Ein laufender `sshd` wird nicht angefasst.
 */
import { execFileSync } from 'node:child_process'
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'

const wurzel = fileURLToPath(new URL('..', import.meta.url))
const { generate, canGenerate } = await import(join(wurzel, 'resources/js/ssh/openssh.ts'))

let ok = 0
let fehl = 0

/*
 * Ohne Farben. Dieses Skript hat schon einmal daran gehangen, dass die
 * Steuerzeichen durch drei Schichten Werkzeug mussten — und was eine Messung
 * belegt, hängt nicht daran, ob das Wort grün ist.
 */
function messung(name, erwartet, gemessen) {
  if (String(gemessen) === String(erwartet)) {
    ok++
    process.stdout.write(`  ja   ${name.padEnd(54)} ${gemessen}\n`)
  } else {
    fehl++
    process.stdout.write(`  NEIN ${name.padEnd(54)} ${gemessen} (erwartet: ${erwartet})\n`)
  }
}

function titel(text) {
  process.stdout.write(`\n${text}\n`)
}

/** `ssh-keygen` aufrufen und sagen, ob es gelungen ist — samt Ausgabe. */
function keygen(...args) {
  try {
    return { rc: 0, aus: execFileSync('ssh-keygen', args, { encoding: 'utf8' }).trim() }
  } catch (e) {
    return { rc: e.status ?? 255, aus: String(e.stderr ?? e.message).trim() }
  }
}

const ordner = mkdtempSync(join(tmpdir(), 'schluessel-'))

function schreiben(name, inhalt, modus) {
  const pfad = join(ordner, name)

  writeFileSync(pfad, inhalt, { mode: modus })

  return pfad
}

try {
  titel('Punkt 1 — kann diese Umgebung Ed25519?')
  messung('crypto.subtle kennt Ed25519', 'true', await canGenerate())

  titel('Punkt 2 — der öffentliche Teil')
  const paar = await generate('kunde@srvpanel')
  const pub = schreiben('probe.pub', `${paar.publicKey}\n`, 0o644)
  const gelesen = keygen('-l', '-f', pub)

  messung('ssh-keygen -l liest ihn', 0, gelesen.rc)
  messung('… und nennt ED25519', true, gelesen.aus.includes('(ED25519)'))

  // Gegenprobe: ein verdrehtes Zeichen darin darf nicht durchgehen.
  const kaputtPub = schreiben('kaputt.pub', `${paar.publicKey.replace('AAAAC3', 'AAAAC4')}\n`, 0o644)

  messung('Gegenprobe: verdreht → abgewiesen', true, keygen('-l', '-f', kaputtPub).rc !== 0)

  titel('Punkt 3 — nimmt OpenSSH den privaten Teil?')
  const priv = schreiben('probe', paar.privateKey, 0o600)
  const abgeleitet = keygen('-y', '-f', priv)

  messung('ssh-keygen -y liest die Datei', 0, abgeleitet.rc)
  messung(
    '… und leitet genau diesen öffentlichen Teil ab',
    paar.publicKey.split(' ').slice(0, 2).join(' '),
    abgeleitet.aus.split(' ').slice(0, 2).join(' '),
  )

  // Gegenprobe: ein einziges verdrehtes Byte im inneren Teil.
  const zeilen = paar.privateKey.trim().split('\n')
  const roh = Buffer.from(zeilen.slice(1, -1).join(''), 'base64')

  roh[roh.length - 1] ^= 0xff

  const kaputtPriv = schreiben('kaputt', [
    '-----BEGIN OPENSSH PRIVATE KEY-----',
    ...(roh.toString('base64').match(/.{1,70}/g) ?? []),
    '-----END OPENSSH PRIVATE KEY-----',
    '',
  ].join('\n'), 0o600)

  messung('Gegenprobe: ein Byte verdreht → abgewiesen', true, keygen('-y', '-f', kaputtPriv).rc !== 0)

  titel('Der Rand — die Auffüllung hängt an der Länge der Bemerkung')
  //
  // Acht Restklassen, jede zweimal. Ist der innere Teil schon ausgerichtet,
  // kommt **nichts** dazu, und dieser Fall tritt nur bei bestimmten Längen ein.
  let raender = 0

  for (let n = 0; n < 16; n++) {
    const eines = await generate('x'.repeat(n))
    const datei = schreiben(`rand-${n}`, eines.privateKey, 0o600)
    const aus = keygen('-y', '-f', datei)

    if (aus.rc === 0 && aus.aus.split(' ')[1] === eines.publicKey.split(' ')[1]) {
      raender++
    }
  }

  messung('alle sechzehn Längen lesbar und passend', 16, raender)

  titel('Und die leere Bemerkung trägt keine Fuge')
  const ohne = await generate('')

  messung('kein abschliessendes Leerzeichen', false, ohne.publicKey.endsWith(' '))
  messung('lesbar', 0, keygen('-y', '-f', schreiben('ohne', ohne.privateKey, 0o600)).rc)
} finally {
  rmSync(ordner, { recursive: true, force: true })
}

process.stdout.write(`\n${ok} ja, ${fehl} NEIN\n`)
process.exit(fehl === 0 ? 0 : 1)
