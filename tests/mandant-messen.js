/*
 * Der Mandantenübergriff — Punkt 11 des Abnahmekriteriums (`docs/51 §4`).
 *
 * **Was gemessen wird.** Angemeldet als Kunde von Abonnement A wird in jeder
 * der 22 Routen mit `{subscription}` die Kennung von Abonnement B eingesetzt.
 * Keine davon darf durchkommen.
 *
 * **Warum das im Browser läuft und nicht als Test.** Dieselbe Begründung wie
 * bei `srvpanel:acceptance-web`: Ein Test läuft gegen SQLite, einen erfundenen
 * Agenten und eine Sitzung, die es so nicht gibt. Hier zählt die echte Route
 * mit der echten Sitzung des echten Kunden — und die hat der Betreiber schon
 * offen. Ein Schnipsel in der Konsole braucht kein Anmelden, kein
 * Cookie-Kopieren und keine zweite Fassung der Anwendung.
 *
 * **Das Kriterium ist beim Ausschreiben berichtigt worden.** `docs/51 §4`
 * verlangt „403 in allen 22, und zwar aus der Policy und nicht aus einem 404".
 * Der Code kann das nicht liefern: {@see ApplyTenancy} klammert die Abfragen
 * auf die Abonnements des Kontos, **bevor** die Policy gefragt wird — ein
 * fremdes Abonnement ist damit schon für die Auflösung von `{subscription}`
 * unauffindbar, und das ergibt einen 404.
 *
 * Das ist die stärkere Antwort und nicht die schwächere: Ein 403 bestätigt die
 * Existenz, ein 404 nicht.
 *
 * > **Ein Kriterium, das eine Zahl vorschreibt, prüft die Zahl und nicht die
 * > Wand.**
 *
 * Gemessen wird deshalb: **kein 2xx, und der Grund ist benennbar.**
 *
 * **Und ohne die Gegenprobe sagt die Reihe nichts.** Dieselbe Route mit der
 * *eigenen* Kennung muss in den Controller kommen. Sichtbar wird das an einem
 * `422`: Der Rumpf wird absichtlich weggelassen, also scheitert jede
 * verändernde Route an ihrer eigenen Prüfung — **nachdem** die Autorisierung
 * sie durchgelassen hat. Das ist zugleich der Grund, warum dieser Lauf nichts
 * verändert: Er kommt nie bis zur Handlung.
 *
 * So wird er benutzt — in der Konsole des Browsers, angemeldet als der Kunde
 * von EIGEN, mit den beiden Kennungen aus der Abonnementliste:
 *
 *     await mandantMessen({ eigen: 2, fremd: 1 })
 *
 * Für die vier Routen mit einem zweiten Parameter (`{job}`, `{key}`) lassen
 * sich echte Kennungen mitgeben. Ohne sie kann ein 404 auch vom zweiten
 * Parameter kommen, und der Lauf sagt das dann auch:
 *
 *     await mandantMessen({ eigen: 2, fremd: 1, eigenJob: 7, fremdJob: 3 })
 */

async function mandantMessen ({ eigen, fremd, eigenJob, fremdJob, eigenKey, fremdKey }) {
  if (!eigen || !fremd) {
    throw new Error('eigen und fremd sind beide nötig — sonst misst der Lauf sich selbst.')
  }

  const token = decodeURIComponent(
    (document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/) || [])[1] || ''
  )

  if (!token) {
    throw new Error('Kein XSRF-TOKEN im Cookie — ist diese Sitzung angemeldet?')
  }

  // Die 22 Routen aus `routes/web.php`. `zweit` heisst: die Route trägt einen
  // zweiten Parameter, und ohne echte Kennung dafür ist ein 404 nicht eindeutig.
  const routen = [
    ['GET', '/files'],
    ['POST', '/files/tree'],
    ['GET', '/files/edit'],
    ['PUT', '/files'],
    ['POST', '/files/directory'],
    ['DELETE', '/files'],
    ['POST', '/files/rename'],
    ['POST', '/files/move'],
    ['POST', '/files/copy'],
    ['GET', '/files/search'],
    ['POST', '/files/extract'],
    ['POST', '/files/compress'],
    ['POST', '/files/upload'],
    ['POST', '/files/chmod'],
    ['GET', '/sftp'],
    ['POST', '/sftp/keys'],
    ['DELETE', '/sftp/keys/{key}', 'key'],
    ['GET', '/cron'],
    ['POST', '/cron'],
    ['PUT', '/cron/{job}', 'job'],
    ['DELETE', '/cron/{job}', 'job'],
    ['GET', '/cron/{job}/runs', 'job'],
  ]

  const zweiter = (art, eigenesAbo) => {
    if (art === 'job') return (eigenesAbo ? eigenJob : fremdJob) ?? null
    if (art === 'key') return (eigenesAbo ? eigenKey : fremdKey) ?? null

    return undefined
  }

  /**
   * Eine Anfrage, absichtlich ohne Rumpf.
   *
   * Kommt sie bis in den Controller, scheitert sie an dessen Prüfung — `422`.
   * Genau das ist der Beleg, dass die Autorisierung sie durchgelassen hat, und
   * genau deshalb verändert dieser Lauf nichts.
   */
  const anfrage = async (methode, pfad) => {
    const antwort = await fetch(pfad, {
      method: methode,
      headers: {
        'X-XSRF-TOKEN': token,
        'X-Requested-With': 'XMLHttpRequest',
        'X-Inertia': 'true',
        Accept: 'application/json, text/html',
      },
      redirect: 'manual',
      credentials: 'same-origin',
    })

    return antwort.status
  }

  const zeilen = []

  for (const [methode, muster, art] of routen) {
    const bauen = (abo, eigenesAbo) => {
      const zweit = zweiter(art, eigenesAbo)
      const pfad = muster.replace(/\{(job|key)\}/, String(zweit ?? 999999))

      return `/subscriptions/${abo}${pfad}`
    }

    const fremdCode = await anfrage(methode, bauen(fremd, false))
    const eigenCode = await anfrage(methode, bauen(eigen, true))

    // **Ein 2xx auf die fremde Kennung ist der Übergriff.** Alles andere hält
    // — aber der Grund gehört daneben, sonst ist es eine Zahl ohne Ursache.
    const uebergriff = fremdCode >= 200 && fremdCode < 300
    const durchgelassen = !(eigenCode === 403 || eigenCode === 404)

    zeilen.push({
      Route: `${methode} ${muster}`,
      fremd: fremdCode,
      eigen: eigenCode,
      Grund: fremdCode === 404 ? 'Klammer (nicht auffindbar)'
        : fremdCode === 403 ? 'Policy'
          : uebergriff ? 'ÜBERGRIFF' : `unerwartet ${fremdCode}`,
      Gegenprobe: durchgelassen ? 'durchgelassen' : 'BLIEB HÄNGEN',
      eindeutig: art && zweiter(art, false) === null ? 'nein — ohne echten 2. Parameter' : 'ja',
    })
  }

  console.table(zeilen)

  const uebergriffe = zeilen.filter((z) => z.Grund === 'ÜBERGRIFF')
  const haengen = zeilen.filter((z) => z.Gegenprobe === 'BLIEB HÄNGEN')

  console.log(`fremd: ${zeilen.length - uebergriffe.length} von ${zeilen.length} gehalten`)
  console.log(`eigen: ${zeilen.length - haengen.length} von ${zeilen.length} durchgelassen`)

  // **Die Gegenprobe ist die Aussage.** Halten alle 22 fremden Aufrufe, aber
  // kommt keiner der eigenen durch, misst der Lauf eine Anmeldung, die nichts
  // darf — und nicht eine Klammer, die trennt.
  if (haengen.length === zeilen.length) {
    console.error(
      'OHNE MESSUNG: keine einzige eigene Kennung kam durch. '
      + 'Angemeldet als der Kunde von EIGEN? Dann sagt diese Reihe nichts über die Klammer.'
    )
  }

  return { zeilen, uebergriffe: uebergriffe.length, haengen: haengen.length }
}
