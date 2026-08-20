/**
 * Ein Ed25519-Schlüsselpaar im Browser erzeugen — in den Formen, die OpenSSH liest.
 *
 * ## Warum der Schlüssel hier entsteht und nicht auf dem Server
 *
 * Ein privater Schlüssel, der auf dem Server entsteht, reist durch zwei
 * Einrichtungen dieses Panels, die **beide auf die Platte schreiben**, und zwar
 * ihrer Bauart nach: die Sitzung (`SESSION_DRIVER=database`) und der Vorgang
 * (`operations.payload` und `operations.result` sind JSON-Spalten, und die
 * Antwort des Agenten wird gespeichert — sie überlebt sogar das Zurückbauen des
 * Abonnements). Beides ist umgehbar, aber nur, indem man das empfindlichste
 * Datum des ganzen Merkmals an genau den Mechanismen vorbeiführt, auf denen
 * dieses Panel sonst überall besteht (`docs/64 §5.2`).
 *
 * > **Ein privater Schlüssel, den der Server nie hatte, kann er nicht
 * > verlieren.**
 *
 * ## Warum hier ein Dateiformat von Hand geschrieben wird
 *
 * Weil gemessen ist, dass der kurze Weg nicht trägt. `crypto.subtle` gibt einen
 * privaten Ed25519-Schlüssel als **PKCS#8** aus; OpenSSH 9.6p1 liest das nicht,
 * in keiner der drei Formen (`docs/64 §5.6`):
 *
 *     ssh-keygen -y -f …            Load key: invalid format
 *     ssh-keygen -l -f …            is not a key file
 *     ssh-keygen -i -m PKCS8 -f …   not a recognised public key format
 *
 * Der Container `openssh-key-v1` ist dagegen reine Serialisierung — **kein
 * Stück Kryptographie**, nur Längenangaben, Zeichenketten und eine Auffüllung.
 * Was hier unten steht, ist gegen ein echtes `ssh-keygen` und einen laufenden
 * `sshd` gemessen; `tests/schluessel-messen.mjs` fährt dieselbe Messung gegen
 * genau diese Datei.
 *
 * ## Die Auffüllung ist die Stelle, an der so etwas still bricht
 *
 * Der innere Teil wird auf ein Vielfaches von acht aufgefüllt, mit 1, 2, 3 …
 * Ist er schon ausgerichtet, kommt **nichts** dazu — und genau dieser Fall
 * tritt nur bei bestimmten Längen der Bemerkung ein. Gemessen sind alle acht
 * Restklassen, jede zweimal, einschliesslich der leeren Bemerkung.
 *
 * > **Ein Rand, der von der Länge einer Beschriftung abhängt, ist keiner, den
 * > man an einem Beispiel prüft.**
 */

/** Der Name des Verfahrens — er steht in beiden Formen als Zeichenkette drin. */
const TYPE = 'ssh-ed25519'

/** Die Blockgrösse, auf die aufgefüllt wird. `none` als Verfahren heisst acht. */
const BLOCK = 8

export interface KeyPair {
  /** Eine Zeile, wie sie in `authorized_keys` steht. */
  publicKey: string
  /** Der Inhalt einer Schlüsseldatei, unverschlüsselt, im Format `openssh-key-v1`. */
  privateKey: string
}

/**
 * Ob dieser Browser hier ein Paar erzeugen kann.
 *
 * **Zwei Gründe, warum nicht, und einer davon sieht aus wie der andere.**
 * `crypto.subtle` gibt es nur im sicheren Kontext; über `file://` oder
 * schlichtes HTTP ist es schlicht `undefined` — nicht „geht nicht", sondern gar
 * nicht da. Das Panel wird über HTTPS ausgeliefert (P0), also trifft das hier
 * niemanden; beim Messen hat es eine Runde gekostet, weil es aussah, als könne
 * der Browser kein Ed25519.
 *
 * > **Ein Merkmal, das nur im sicheren Kontext existiert, fehlt daneben nicht
 * > mit einer Meldung, sondern als `undefined`.**
 *
 * Der zweite Grund ist der echte: ein Browser ohne Ed25519 in WebCrypto. Das
 * lässt sich nicht abfragen, nur versuchen — deshalb ist diese Frage
 * asynchron.
 */
export async function canGenerate(): Promise<boolean> {
  if (typeof crypto === 'undefined' || crypto.subtle === undefined) {
    return false
  }

  try {
    await crypto.subtle.generateKey({ name: 'Ed25519' }, true, ['sign', 'verify'])

    return true
  } catch {
    return false
  }
}

/** Ein Paar erzeugen und in beide Formen bringen. */
export async function generate(comment: string): Promise<KeyPair> {
  const pair = (await crypto.subtle.generateKey(
    { name: 'Ed25519' },
    true,
    ['sign', 'verify'],
  )) as CryptoKeyPair

  const publicRaw = new Uint8Array(await crypto.subtle.exportKey('raw', pair.publicKey))

  /*
   * **Der Same kommt aus dem JWK und nicht aus dem PKCS#8.**
   * Beides enthält ihn; `jwk.d` ist er direkt, im PKCS#8 steckt er hinter
   * einem DER-Rahmen, den man aufschneiden müsste. Ein Rahmen, den man
   * aufschneidet, ist eine Annahme über seine Länge.
   */
  const jwk = await crypto.subtle.exportKey('jwk', pair.privateKey)
  const seed = fromBase64Url(jwk.d ?? '')

  if (seed.length !== 32 || publicRaw.length !== 32) {
    throw new Error('Der Browser hat einen Ed25519-Schlüssel in unerwarteter Grösse geliefert.')
  }

  const wire = publicWire(publicRaw)

  return {
    publicKey: `${TYPE} ${base64(wire)}${comment === '' ? '' : ` ${comment}`}`,
    privateKey: privateFile(wire, publicRaw, seed, comment),
  }
}

/** Die Drahtform des öffentlichen Teils — sie steckt in beiden Dateien. */
function publicWire(publicRaw: Uint8Array): Uint8Array {
  return concat([...sshString(utf8(TYPE)), ...sshString(publicRaw)])
}

/** Die Datei `openssh-key-v1`, unverschlüsselt und mit einem Schlüssel darin. */
function privateFile(
  wire: Uint8Array,
  publicRaw: Uint8Array,
  seed: Uint8Array,
  comment: string,
): string {
  /*
   * Zwei gleiche Zufallszahlen. OpenSSH prüft beim Lesen, dass sie
   * übereinstimmen — bei einer verschlüsselten Datei ist das die Probe, ob das
   * Passwort stimmte. Hier ist nichts verschlüsselt, und die Probe geht
   * trotzdem: Sie muss dastehen, sonst gilt die Datei als kaputt.
   */
  const check = crypto.getRandomValues(new Uint8Array(4))

  // Der „private" Teil von Ed25519 in OpenSSH ist Same **und** öffentlicher
  // Teil hintereinander — 64 Byte.
  const secret = new Uint8Array(64)
  secret.set(seed, 0)
  secret.set(publicRaw, 32)

  let inner = concat([
    check,
    check,
    ...sshString(utf8(TYPE)),
    ...sshString(publicRaw),
    ...sshString(secret),
    ...sshString(utf8(comment)),
  ])

  const rest = inner.length % BLOCK

  if (rest !== 0) {
    inner = concat([inner, new Uint8Array(BLOCK - rest).map((_, i) => i + 1)])
  }

  const file = concat([
    utf8('openssh-key-v1'),
    new Uint8Array([0]),
    ...sshString(utf8('none')), // Verschlüsselung
    ...sshString(utf8('none')), // Ableitung des Schlüssels daraus
    ...sshString(new Uint8Array(0)), // deren Vorgaben
    uint32(1), // ein Schlüssel in dieser Datei
    ...sshString(wire),
    ...sshString(inner),
  ])

  return [
    '-----BEGIN OPENSSH PRIVATE KEY-----',
    ...(base64(file).match(/.{1,70}/g) ?? []),
    '-----END OPENSSH PRIVATE KEY-----',
    '',
  ].join('\n')
}

/** Eine Zeichenkette der Drahtform: Länge als vier Byte, dann die Bytes. */
function sshString(value: Uint8Array): [Uint8Array, Uint8Array] {
  return [uint32(value.length), value]
}

function uint32(value: number): Uint8Array {
  const out = new Uint8Array(4)

  new DataView(out.buffer).setUint32(0, value, false)

  return out
}

function utf8(value: string): Uint8Array {
  return new TextEncoder().encode(value)
}

function concat(parts: Uint8Array[]): Uint8Array {
  const out = new Uint8Array(parts.reduce((sum, part) => sum + part.length, 0))
  let at = 0

  for (const part of parts) {
    out.set(part, at)
    at += part.length
  }

  return out
}

function base64(bytes: Uint8Array): string {
  let text = ''

  // Nicht `String.fromCharCode(...bytes)`: Ein Aufruf mit ein paar hundert
  // Werten geht gut, und irgendwann kommt einer, der den Stapel sprengt.
  for (const byte of bytes) {
    text += String.fromCharCode(byte)
  }

  return btoa(text)
}

function fromBase64Url(value: string): Uint8Array {
  const text = atob(value.replace(/-/g, '+').replace(/_/g, '/'))
  const out = new Uint8Array(text.length)

  for (let i = 0; i < text.length; i++) {
    out[i] = text.charCodeAt(i)
  }

  return out
}
